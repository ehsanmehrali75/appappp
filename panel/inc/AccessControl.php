<?php
/** Explicit feature audiences. Global restrictions always take precedence. */
class AccessControl {
    public static function roles() {
        return ['main_admin' => 'ادمین اصلی', 'project_admin' => 'ادمین پروژه',
            'manager' => 'مدیر با دسترسی انتخابی', 'editor' => 'کارشناس / ویرایشگر',
            'viewer' => 'کاربر پنل'];
    }

    public static function file() {
        return defined('ACCESS_POLICY_FILE') ? ACCESS_POLICY_FILE : __DIR__ . '/../db/access_policy.json';
    }

    public static function policies() {
        if (!is_file(self::file())) return [];
        $data = json_decode(file_get_contents(self::file()), true);
        // An unreadable policy must never silently remove configured restrictions.
        return is_array($data) ? $data : ['__invalid' => true];
    }

    public static function save($input) {
        if (!FeatureFlags::isMainAdmin() || !is_array($input)) return false;
        $clean = [];
        foreach (FeatureFlags::getCatalog() as $key => $feature) {
            foreach (['view', 'use'] as $mode) {
                foreach (['roles', 'users', 'projects'] as $scope) {
                    $value = $input[$key][$mode][$scope] ?? '';
                    if (!is_string($value)) return false;
                    $items = preg_split('/[،,\r\n]+/u', $value);
                    $items = array_values(array_unique(array_filter(array_map('trim', $items), 'strlen')));
                    if ($scope === 'roles' && array_diff($items, array_keys(self::roles()))) return false;
                    $clean[$key][$mode][$scope] = $items;
                }
            }
        }
        return file_put_contents(self::file(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    public static function featureKey($key) {
        return ['size_chart' => 'size_studio', 'vm' => 'vm_studio', 'seo' => 'seo_intel'][$key] ?? $key;
    }

    /** null means inherit existing permissions, false means explicitly denied. */
    public static function audience($key, $mode = 'view', $user = null, $projectId = null) {
        $explicitUser = $user !== null;
        $user = $user ?? ($_SESSION['user'] ?? []);
        $projectId = $projectId ?? ($_SESSION['project_id'] ?? '');
        $policies = self::policies();
        if (isset($policies['__invalid'])) return false;
        $rule = $policies[self::featureKey($key)][$mode] ?? [];
        if (empty($rule['roles']) && empty($rule['users']) && empty($rule['projects'])) return null;
        if (!$explicitUser && function_exists('getCurrentProject') && $projectId !== '') {
            $project = getCurrentProject($projectId);
            $member = null;
            foreach (($project['users'] ?? []) as $candidate) {
                if (($candidate['username'] ?? '') === ($user['username'] ?? '')) { $member = $candidate; break; }
            }
            if (!$member) return false;
            $user = $member;
        }
        $role = $user['role'] ?? '';
        if ($role === 'superadmin') $role = 'main_admin';
        return in_array($role, $rule['roles'] ?? [], true)
            || in_array($user['username'] ?? '', $rule['users'] ?? [], true)
            || in_array((string)$projectId, $rule['projects'] ?? [], true);
    }

    public static function allowed($key, $mode = 'view') {
        if (FeatureFlags::isMainAdmin()) return true;
        if (self::audience($key, 'view') === false) return false;
        return $mode !== 'use' || self::audience($key, 'use') !== false;
    }

    public static function deny() {
        http_response_code(403);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'دسترسی به این بخش یا عملیات مجاز نیست.'], JSON_UNESCAPED_UNICODE);
        } else echo 'دسترسی به این بخش مجاز نیست.';
        exit;
    }
}
