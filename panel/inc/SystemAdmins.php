<?php
/** Local deployment data, never committed with application source. */
class SystemAdmins {
    public static function file() { return defined('SYSTEM_ADMINS_FILE') ? SYSTEM_ADMINS_FILE : __DIR__ . '/../db/system_admins.php'; }
    public static function all() {
        if (!is_file(self::file())) return [];
        if (!defined('MAARKSHO_LOAD_ADMINS')) define('MAARKSHO_LOAD_ADMINS', true);
        $accounts = require self::file();
        return is_array($accounts) ? $accounts : [];
    }
    public static function create($username, $password, $unrestricted = false, $requireNoOwner = false) {
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $username) || !is_string($password) || strlen($password) < 10) throw new RuntimeException('نام کاربری معتبر و رمز حداقل ۱۰ کاراکتری لازم است.');
        $directory = dirname(self::file());
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('پوشهٔ خصوصی حساب مدیر قابل ساخت نیست.');
        if (!is_writable($directory)) throw new RuntimeException('پوشهٔ خصوصی حساب مدیر روی هاست قابل نوشتن نیست.');
        $lock = fopen(self::file() . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('ذخیره حساب مدیر ممکن نیست.');
        try {
            $accounts = self::all();
            if ($requireNoOwner && self::hasOwner($accounts)) throw new RuntimeException('مدیر کل قبلاً ساخته شده است؛ بازیابی اولیه غیرفعال است.');
            if (isset($accounts[$username])) throw new RuntimeException('حساب مدیر از قبل وجود دارد؛ حساب موجود بازنویسی نشد.');
            $accounts[$username] = ['username'=>$username, 'password'=>password_hash($password, PASSWORD_DEFAULT), 'role'=>$unrestricted?'owner':'main_admin'];
            $code = "<?php\nif (!defined('MAARKSHO_LOAD_ADMINS')) { http_response_code(404); exit; }\nreturn " . var_export($accounts, true) . ";\n";
            $tmp = tempnam(dirname(self::file()), '.admins'); chmod($tmp, 0600);
            if (file_put_contents($tmp, $code) === false || !rename($tmp, self::file())) throw new RuntimeException('ذخیره حساب مدیر ناموفق بود.');
            if (function_exists('opcache_invalidate')) opcache_invalidate(self::file(), true);
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function hasOwner($accounts = null) {
        foreach ($accounts ?? self::all() as $account) if (($account['role'] ?? '') === 'owner') return true;
        return false;
    }
    public static function recoveryFile() { return dirname(self::file()).'/owner-recovery-key.php'; }
    public static function recoveryKeySource($token, $expires) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('کلید نامعتبر است.');
        return "<?php\nif (!defined('MAARKSHO_OWNER_RECOVERY')) { http_response_code(404); exit; }\nreturn " . var_export(['hash'=>hash('sha256',$token),'expires'=>(int)$expires],true) . ";\n";
    }
    public static function recoverFirstOwner($token, $username, $password) {
        if (self::hasOwner()) throw new RuntimeException('مدیر کل قبلاً ساخته شده است؛ بازیابی اولیه غیرفعال است.');
        $path = self::recoveryFile();
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_file($path)) throw new RuntimeException('فایل کلید موقت روی هاست پیدا نشد یا کلید معتبر نیست.');
        if (!defined('MAARKSHO_OWNER_RECOVERY')) define('MAARKSHO_OWNER_RECOVERY', true);
        $key = require $path;
        if (!is_array($key) || !is_string($key['hash']??null) || !hash_equals($key['hash'],hash('sha256',$token)) || (int)($key['expires']??0)<=time() || (int)$key['expires']>time()+3600) throw new RuntimeException('کلید بازیابی نامعتبر یا منقضی است؛ فایل جدید بسازید.');
        // The owner check is repeated under the account write lock to prevent races/replays.
        self::create($username,$password,true,true);
        @unlink($path);
        return self::authenticate($username,$password);
    }
    /** Revalidate stale legacy sessions against global accounts, never project users. */
    public static function authenticateGlobal($username, $password, $superUsers) {
        if (!is_string($username) || !is_string($password)) return null;
        $account = self::authenticate($username, $password);
        if ($account) return $account;
        foreach ($superUsers as $candidate) {
            if (($candidate['username'] ?? '') !== $username) continue;
            $stored = (string)($candidate['password'] ?? '');
            if ($stored === '') continue;
            $valid = password_get_info($stored)['algo'] ? password_verify($password, $stored) : hash_equals($stored, $password);
            if ($valid) return ['username'=>$username, 'role'=>'superadmin'];
        }
        return null;
    }
    public static function authenticate($username, $password) {
        $account = self::all()[$username] ?? null;
        if (!$account || !password_verify($password, $account['password'])) return null;
        return ['username'=>$account['username'], 'role'=>$account['role']];
    }
}
