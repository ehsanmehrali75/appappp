<?php
/** Bind a browser session to credentials that were actually verified at login. */
class SessionIdentity {
    private static $accounts;

    private static function localRole($role) {
        return in_array($role, ['project_admin', 'manager', 'editor', 'viewer'], true);
    }

    private static function accounts() {
        if (self::$accounts !== null) return self::$accounts;
        $accounts = ['projects' => [], 'global' => []];
        if (isSqlActive()) {
            $conn = getConn();
            $result = $conn->query('SELECT * FROM project_users');
            if ($result) while ($row = $result->fetch_assoc()) {
                $row['permissions'] = is_array($row['permissions'] ?? null) ? $row['permissions'] : (json_decode($row['permissions'] ?? '[]', true) ?: []);
                $accounts['projects'][(string)$row['project_id']][] = $row;
            }
            $result = $conn->query('SELECT * FROM super_users');
            if ($result) while ($row = $result->fetch_assoc()) $accounts['global'][] = $row;
        } else {
            $db = getJsonDB();
            foreach ($db['projects'] ?? [] as $project) $accounts['projects'][(string)$project['id']] = $project['users'] ?? [];
            $accounts['global'] = $db['super_users'] ?? [];
        }
        return self::$accounts = $accounts;
    }

    private static function matches($password, $stored) {
        if (!is_string($stored) || $stored === '') return false;
        return password_get_info($stored)['algo'] ? password_verify($password, $stored) : hash_equals($stored, $password);
    }

    private static function proof($member, $kind) {
        return hash('sha256', ($member['username'] ?? '')."\0".($kind === 'otp' ? trim($member['mobile'] ?? '') : ($member['password'] ?? '')));
    }

    private static function clear() {
        foreach (['user', 'project_id', 'auth_identity', 'portal_user', 'portal_user_icon', 'portal_user_display_name', 'display_name', 'user_icon'] as $key) unset($_SESSION[$key]);
    }

    public static function beginPassword($username, $password, $originProject) {
        self::$accounts = null;
        self::clear();
        $grants = [];
        foreach (self::accounts()['projects'] as $pid => $members) foreach ($members as $member) {
            if (($member['username'] ?? '') === $username && self::localRole($member['role'] ?? '') && self::matches($password, $member['password'] ?? '')) {
                $grants[$pid] = ['username' => $username, 'proof' => self::proof($member, 'password')];
                break;
            }
        }
        $_SESSION['auth_identity'] = ['kind' => 'password', 'origin_project' => (string)$originProject, 'grants' => $grants];
        return self::select(['id' => (string)$originProject]);
    }

    public static function beginOtp($mobile) {
        self::$accounts = null;
        self::clear();
        $grants = [];
        foreach (self::accounts()['projects'] as $pid => $members) foreach ($members as $member) {
            if (trim($member['mobile'] ?? '') === $mobile && self::localRole($member['role'] ?? '')) {
                $grants[$pid] = ['username' => $member['username'], 'proof' => self::proof($member, 'otp')];
                break;
            }
        }
        $_SESSION['auth_identity'] = ['kind' => 'otp', 'mobile' => $mobile, 'grants' => $grants];
        $_SESSION['portal_user'] = $mobile;
        if ($grants) return self::select(['id' => (string)array_keys($grants)[0]]);
        $_SESSION['user'] = ['username' => 'portal-'.$mobile, 'role' => 'portal_viewer', 'permissions' => []];
        return false;
    }

    public static function beginGlobal($username, $source = 'system') {
        self::$accounts = null;
        self::clear();
        require_once __DIR__.'/SystemAdmins.php';
        $account = null;
        if ($source === 'system') $account = SystemAdmins::all()[$username] ?? null;
        else foreach (self::accounts()['global'] as $candidate) if (($candidate['username'] ?? '') === $username) { $account = $candidate; break; }
        if (!$account || empty($account['password'])) return false;
        $role = $source === 'system' ? ($account['role'] ?? '') : 'superadmin';
        if (!in_array($role, ['owner', 'main_admin', 'superadmin'], true)) return false;
        $_SESSION['auth_identity'] = ['kind' => $source === 'system' ? 'system' : 'global', 'username' => $username, 'proof' => self::proof($account, 'password')];
        $_SESSION['user'] = ['username' => $username, 'role' => $role];
        return true;
    }

    /** Fresh persisted member, only within the session's verified login scope. */
    public static function member($project) {
        if (!is_array($project) || !isset($project['id'])) return null;
        $identity = $_SESSION['auth_identity'] ?? null;
        if (!$identity && PHP_SAPI === 'cli') {
            foreach ($project['users'] ?? [] as $candidate) if (($candidate['username'] ?? '') === ($_SESSION['user']['username'] ?? '') && self::localRole($candidate['role'] ?? '')) return $candidate;
            return null;
        }
        if (!is_array($identity) || !in_array($identity['kind'] ?? '', ['password', 'otp'], true)) return null;
        $grant = $identity['grants'][(string)$project['id']] ?? null;
        if (!is_array($grant)) return null;
        foreach (self::accounts()['projects'][(string)$project['id']] ?? [] as $candidate) {
            if (($candidate['username'] ?? '') !== ($grant['username'] ?? '') || !self::localRole($candidate['role'] ?? '')) continue;
            if (!hash_equals($grant['proof'] ?? '', self::proof($candidate, $identity['kind']))) continue;
            if ($identity['kind'] === 'otp' && trim($candidate['mobile'] ?? '') !== ($identity['mobile'] ?? '')) continue;
            return $candidate;
        }
        return null;
    }

    public static function select($project) {
        if (!is_array($project) || !is_string($project['id'] ?? null) || $project['id'] === '') return false;
        if (FeatureFlags::isMainAdmin()) { $_SESSION['project_id'] = $project['id']; return true; }
        $member = self::member($project);
        if (!$member) return false;
        $_SESSION['user'] = $member;
        $_SESSION['project_id'] = $project['id'];
        return true;
    }

    /** A paid project may join a live session only through an already verified matching account. */
    public static function includePurchasedProject($project) {
        if(!is_array($project)||empty($project['id']))return false;
        $identity=$_SESSION['auth_identity']??[];
        if(!in_array($identity['kind']??'', ['password','otp'],true))return false;
        self::$accounts=null;
        $targets=self::accounts()['projects'][$project['id']]??[];
        foreach(array_keys($identity['grants']??[]) as $origin){
            $source=self::member(['id'=>(string)$origin]);if(!$source)continue;
            foreach($targets as $target){
                if(($target['username']??'')!==$source['username'])continue;
                if($identity['kind']==='otp')$same=($identity['mobile']??'')!==''&&($target['mobile']??'')===$identity['mobile'];
                else $same=hash_equals($source['password'],$target['password'])||(!password_get_info($source['password'])['algo']&&password_verify($source['password'],$target['password']));
                if(!$same)continue;
                $_SESSION['auth_identity']['grants'][$project['id']]=['username'=>$target['username'],'proof'=>self::proof($target,$identity['kind'])];
                return self::select($project);
            }
        }
        return false;
    }

    /** Generic support tickets must not merge unrelated equal usernames. */
    public static function supportActor($projectId) {
        $username = $_SESSION['user']['username'] ?? '';
        $identity = $_SESSION['auth_identity'] ?? [];
        if (($identity['kind'] ?? '') === 'otp' && ($projectId === '__support_account__' || ($_SESSION['user']['role'] ?? '') === 'portal_viewer')) return 'portal:'.($identity['mobile'] ?? '');
        if (FeatureFlags::isMainAdmin() || $projectId !== '__support_account__') return $username;
        $origin = $identity['origin_project'] ?? (array_keys($identity['grants'] ?? [])[0] ?? '');
        return 'acct:'.substr(hash('sha256', $origin."\0".$username), 0, 40).':'.mb_substr($username, 0, 50);
    }

    public static function canClaimLegacySupport() {
        if (!in_array($_SESSION['auth_identity']['kind'] ?? '', ['password', 'otp'], true)) return false;
        $username = $_SESSION['user']['username'] ?? '';
        $found = false;
        foreach (self::accounts()['projects'] as $pid => $members) foreach ($members as $member) {
            if (($member['username'] ?? '') !== $username) continue;
            $found = true;
            if (!self::member(['id' => (string)$pid])) return false;
        }
        return $found;
    }

    /** Revoke removed/reset accounts and refresh roles before any controller runs. */
    public static function refresh() {
        if (!isset($_SESSION['user'])) return;
        $identity = $_SESSION['auth_identity'] ?? null;
        if (!$identity) {
            // Old project sessions retained their exact stored credential. Migrate
            // only that project; username equality never proves another membership.
            $pid = $_SESSION['project_id'] ?? '';
            foreach (self::accounts()['projects'][(string)$pid] ?? [] as $member) {
                $old = $_SESSION['user'];
                if (($member['username'] ?? '') === ($old['username'] ?? '') && self::localRole($member['role'] ?? '') && !empty($old['password']) && hash_equals((string)$member['password'], (string)$old['password'])) {
                    $_SESSION['auth_identity'] = ['kind' => 'password', 'origin_project' => (string)$pid, 'grants' => [(string)$pid => ['username' => $member['username'], 'proof' => self::proof($member, 'password')]]];
                    $_SESSION['user'] = $member;
                    unset($_SESSION['portal_user']);
                    return;
                }
            }
            self::clear();
            return;
        }
        if (in_array($identity['kind'] ?? '', ['system', 'global'], true)) {
            require_once __DIR__.'/SystemAdmins.php';
            $account = null;
            if ($identity['kind'] === 'system') $account = SystemAdmins::all()[$identity['username'] ?? ''] ?? null;
            else foreach (self::accounts()['global'] as $candidate) if (($candidate['username'] ?? '') === ($identity['username'] ?? '')) { $account = $candidate; break; }
            $role = $identity['kind'] === 'system' ? ($account['role'] ?? '') : 'superadmin';
            if (!$account || !in_array($role, ['owner', 'main_admin', 'superadmin'], true) || !hash_equals($identity['proof'] ?? '', self::proof($account, 'password'))) { self::clear(); return; }
            $_SESSION['user'] = ['username' => $account['username'], 'role' => $role];
            return;
        }
        if (($identity['kind'] ?? '') === 'otp' && empty($identity['grants'])) return;
        $member = self::member(['id' => (string)($_SESSION['project_id'] ?? '')]);
        if (!$member) { self::clear(); return; }
        $_SESSION['user'] = $member;
    }
}
