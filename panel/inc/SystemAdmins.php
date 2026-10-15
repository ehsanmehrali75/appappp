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
    public static function create($username, $password, $unrestricted = false) {
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $username) || !is_string($password) || strlen($password) < 10) throw new RuntimeException('نام کاربری معتبر و رمز حداقل ۱۰ کاراکتری لازم است.');
        $directory = dirname(self::file());
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('پوشهٔ خصوصی حساب مدیر قابل ساخت نیست.');
        if (!is_writable($directory)) throw new RuntimeException('پوشهٔ خصوصی حساب مدیر روی هاست قابل نوشتن نیست.');
        $lock = fopen(self::file() . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('ذخیره حساب مدیر ممکن نیست.');
        try {
            $accounts = self::all();
            if (isset($accounts[$username])) throw new RuntimeException('حساب مدیر از قبل وجود دارد؛ حساب موجود بازنویسی نشد.');
            $accounts[$username] = ['username'=>$username, 'password'=>password_hash($password, PASSWORD_DEFAULT), 'role'=>$unrestricted?'owner':'main_admin'];
            $code = "<?php\nif (!defined('MAARKSHO_LOAD_ADMINS')) { http_response_code(404); exit; }\nreturn " . var_export($accounts, true) . ";\n";
            $tmp = tempnam(dirname(self::file()), '.admins'); chmod($tmp, 0600);
            if (file_put_contents($tmp, $code) === false || !rename($tmp, self::file())) throw new RuntimeException('ذخیره حساب مدیر ناموفق بود.');
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function authenticate($username, $password) {
        $account = self::all()[$username] ?? null;
        if (!$account || !password_verify($password, $account['password'])) return null;
        return ['username'=>$account['username'], 'role'=>$account['role']];
    }
}
