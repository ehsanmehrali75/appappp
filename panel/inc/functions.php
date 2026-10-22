<?php
/**
 * Maarksho Flow v3 - Core Functions
 * اصلاح شده با پشتیبانی کامل از API بله و رفع مشکلات امنیتی
 */

ob_start();
date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);
ini_set('display_errors', getenv('MAARKSHO_DEBUG')==='1'?'1':'0');

if (session_status() === PHP_SESSION_NONE) {
    if (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'run.app') !== false)
    ) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

require_once __DIR__.'/RequestSecurity.php';
RequestSecurity::protectBrowser();
if(PHP_SAPI!=='cli')set_exception_handler(static function($error){
    $reference=bin2hex(random_bytes(6));
    error_log('Application failure '.$reference.' '.get_class($error).' code='.$error->getCode().' at '.$error->getFile().':'.$error->getLine());
    if(http_response_code()<400)http_response_code(500);
    header('Cache-Control: no-store');
    $message='عملیات انجام نشد. دوباره تلاش کنید؛ در صورت تکرار، کد پیگیری '.$reference.' را به پشتیبانی بدهید.';
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'error','msg'=>$message,'reference'=>$reference],JSON_UNESCAPED_UNICODE);}
    else {header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>خطای موقت سامانه</title><body><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><a href="/panel/support.php">پشتیبانی</a></body></html>';}
});
require_once __DIR__ . '/auto_admin_engine.php';
require_once __DIR__ . '/FeatureFlags.php';
require_once __DIR__ . '/InstagramAdapter.php';
require_once __DIR__ . '/InstagramPublishHandler.php';
require_once __DIR__ . '/MarkShowCore.php';
require_once __DIR__ . '/TiamVpnManager.php';
require_once __DIR__ . '/TiamConnector.php';
require_once __DIR__ . '/TiamInventory.php';
require_once __DIR__ . '/WebsiteCatalogSync.php';
require_once __DIR__.'/ProductWorkflow.php';

// بررسی پیش‌نیازها و وضعیت اکستنشن‌ها
define('HAS_CURL_EXTENSION', extension_loaded('curl') && function_exists('curl_init'));
define('HAS_DOM_EXTENSION', class_exists('DOMDocument'));
define('HAS_GD_EXTENSION', extension_loaded('gd'));
define('HAS_MBSTRING_EXTENSION', extension_loaded('mbstring'));

// تنظیمات پایه
define('DB_PATH', __DIR__ . '/../db/projects_v2.json');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');

if (!is_dir(__DIR__ . '/../db')) mkdir(__DIR__ . '/../db', 0777, true);
if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0777, true);
if (!is_dir(__DIR__ . '/../scraper')) mkdir(__DIR__ . '/../scraper', 0777, true);

class MaarkshoCache {
    private static function getCacheDir() {
        $dir = __DIR__ . '/../db/cache';
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function get($key, $ttl = 300) {
        $file = self::getCacheDir() . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                $decoded = json_decode($data, true);
                if (is_array($decoded) && isset($decoded['expires']) && isset($decoded['data'])) {
                    if ($decoded['expires'] > time()) {
                        return $decoded['data'];
                    } else {
                        @unlink($file); // Expired cache
                    }
                }
            }
        }
        return null;
    }

    public static function set($key, $data, $ttl = 300) {
        $file = self::getCacheDir() . '/' . md5($key) . '.cache';
        $payload = [
            'expires' => time() + $ttl,
            'data' => $data
        ];
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public static function delete($key) {
        $file = self::getCacheDir() . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function clear() {
        $dir = self::getCacheDir();
        $files = glob($dir . '/*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}

function compressImageAndSave($src, $dest, $quality = 75) {
    if (!extension_loaded('gd')) {
        $res = move_uploaded_file($src, $dest);
        if ($res) @chmod($dest, 0644);
        return $res;
    }
    
    $info = getimagesize($src);
    if ($info === false) {
        $res = move_uploaded_file($src, $dest);
        if ($res) @chmod($dest, 0644);
        return $res;
    }
    
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($src);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($src);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($src);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($src);
            break;
        default:
            $image = false;
    }
    
    if ($image === false) {
        $res = move_uploaded_file($src, $dest);
        if ($res) @chmod($dest, 0644);
        return $res;
    }
    
    // Check orientation if JPEG
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $image = @imagerotate($image, 180, 0);
                    break;
                case 6:
                    $image = @imagerotate($image, -90, 0);
                    break;
                case 8:
                    $image = @imagerotate($image, 90, 0);
                    break;
            }
        }
    }
    
    // Convert to JPEG file format and compress
    $success = @imagejpeg($image, $dest, $quality);
    @imagedestroy($image);
    
    if (!$success) {
        $res = move_uploaded_file($src, $dest);
        if ($res) @chmod($dest, 0644);
        return $res;
    }
    @chmod($dest, 0644);
    return true;
}



function getFullUrl($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0) return $path;
    
    // Always fetch project info to look for manually configured app_base_url
    $project = null;
    if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['project_id'])) {
        $project = getCurrentProject($_SESSION['project_id']);
    }
    if (!$project) {
        $project = $GLOBALS['LAST_LOADED_PROJECT'] ?? null;
    }
    if (!$project) {
        // Try to get first project as fallback in background environments
        if (isSqlActive()) {
            $conn = getConn();
            if ($conn) {
                $res = $conn->query("SELECT id, settings FROM projects LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    $project = [
                        'id' => $row['id'],
                        'settings' => json_decode($row['settings'], true) ?: []
                    ];
                }
            }
        } else {
            $db = getJsonDB();
            if (!empty($db['projects'])) {
                $project = $db['projects'][0];
            }
        }
    }

    $h = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($h, ',') !== false) {
        $parts = explode(',', $h);
        $h = trim($parts[0]);
    }
    $h = trim($h);

    $isLocalhost = (empty($h) || $h === 'localhost' || strpos($h, 'localhost') !== false || strpos($h, '127.0.0.1') !== false || $h === '::1');
    $protocol = 'http://';
    if ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false)) {
        $protocol = 'https://';
    } elseif (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'https://') === 0) {
        $protocol = 'https://';
    } elseif (!empty($_SERVER['HTTP_ORIGIN']) && strpos($_SERVER['HTTP_ORIGIN'], 'https://') === 0) {
        $protocol = 'https://';
    }
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = rtrim(dirname($scriptName), '/\\');
    $currentUrlBase = $protocol . (!empty($h) ? $h : 'localhost') . $baseDir;

    if (!$isLocalhost) {
        if ($project && (empty($project['settings']['app_base_url']) || strpos($project['settings']['app_base_url'], 'localhost') !== false)) {
            $project['settings']['app_base_url'] = $currentUrlBase;
            saveProject($project);
        }
    }

    if ($project && !empty($project['settings']['app_base_url'])) {
        $savedBase = rtrim($project['settings']['app_base_url'], '/\\');
        
        // Prevent CORS errors: If current request is active and host matches (or differs only by www/subdomain),
        // use current protocol and host so fonts/assets are loaded from same origin.
        if (!empty($h)) {
            $parsedSaved = parse_url($savedBase);
            $savedHost = $parsedSaved['host'] ?? '';
            $cleanH = preg_replace('/^www\./i', '', strtolower($h));
            $cleanSaved = preg_replace('/^www\./i', '', strtolower($savedHost));
            if ($cleanH === $cleanSaved || empty($savedHost)) {
                $savedPath = $parsedSaved['path'] ?? $baseDir;
                $base = $protocol . $h . rtrim($savedPath, '/\\');
                return $base . '/' . ltrim($path, '/\\');
            }
        }
        
        return $savedBase . '/' . ltrim($path, '/\\');
    }

    return rtrim($currentUrlBase, '/') . '/' . ltrim($path, '/\\');
}

function jalali_to_gregorian($jy, $jm, $jd) {
    $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd;
    if ($jy > 979) {
        $gy = 1600;
        $jy -= 979;
    } else {
        $gy = 621;
    }
    $days = (int)((365 * $jy) + floor($jy / 33) * 8 + floor((($jy % 33) + 3) / 4) + 78 + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186));
    $gy += 400 * (int)floor($days / 146097);
    $days = (int)($days % 146097);
    if ($days > 36524) {
        $gy += 100 * (int)floor(--$days / 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)floor($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)floor(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = (int)$days + 1;
    $g_d_m = array(0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    for ($gm = 1; $gm < 13; $gm++) {
        if ($gd <= $g_d_m[$gm]) break;
        $gd -= $g_d_m[$gm];
    }
    return array($gy, $gm, $gd);
}

function jalaliToTimestamp($jalaliStr) {
    if (empty($jalaliStr)) return time();
    if (is_numeric($jalaliStr)) return (int)$jalaliStr;
    
    $jalaliStr = trim((string)$jalaliStr);
    
    // Convert Persian digits to English
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $jalaliStr = str_replace($persian, $english, $jalaliStr);
    $jalaliStr = str_replace($arabic, $english, $jalaliStr);

    // Expected: YYYY/MM/DD HH:mm or YYYY-MM-DD HH:mm:ss or YYYY/MM/DD
    if (preg_match('/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})(?:[\sT]+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $jalaliStr, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        $h = isset($m[4]) ? (int)$m[4] : 12;
        $i = isset($m[5]) ? (int)$m[5] : 0;
        $s = isset($m[6]) ? (int)$m[6] : 0;
        
        // If year is Gregorian (e.g. 1970+), direct mktime
        if ($year >= 1970) {
            return mktime($h, $i, $s, $month, $day, $year);
        }
        
        $g = jalali_to_gregorian($year, $month, $day);
        return mktime($h, $i, $s, $g[1], $g[2], $g[0]);
    }
    
    $ts = strtotime($jalaliStr);
    return ($ts !== false && $ts > 0) ? $ts : time();
}

function clean_persian_number($val) {
    if (empty($val) && $val !== '0' && $val !== 0) return '';
    if (is_numeric($val)) return $val;
    
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    
    $val = str_replace($persian, $english, $val);
    $val = str_replace($arabic, $english, $val);
    $val = str_replace([',', ' ', '،'], '', $val);
    
    return $val;
}

date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jdf.php';

function fetchGoogleSheetData($sheetId, $range, $accessToken) {
    if (!$accessToken) return ['error' => 'No access token provided'];
    
    $url = "https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/" . urlencode($range);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "Sheets API Error (Code $httpCode): " . $response];
    }
    
    $data = json_decode($response, true);
    if (!isset($data['values']) || empty($data['values'])) {
        return ['error' => 'No data found in sheet or range.'];
    }
    
    // Transform values into associative array using first row as header
    $headers = array_shift($data['values']);
    $rows = [];
    foreach ($data['values'] as $values) {
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = $values[$i] ?? '';
        }
        $rows[] = $row;
    }
    
    return ['success' => true, 'rows' => $rows];
}

function formatPersianDate($timestamp, $format = 'd M Y') {
    if (empty($timestamp)) {
        $timestamp = time();
    }
    if (!is_numeric($timestamp)) {
        $parsed = strtotime($timestamp);
        $timestamp = ($parsed !== false) ? $parsed : time();
    }
    return jdate($format, $timestamp);
}

// ================================
// مدیریت دیتابیس (JSON و MySQL)
// ================================

function getJsonDB() {
    $default = [
        'super_users' => [
            // Initial owners are created using the setup/CLI flow.
        ],
        'projects' => [],
        'scraping_jobs' => []
    ];

    if (!file_exists(DB_PATH)) {
        saveJsonDB($default);
        return $default;
    }
    
    $fp = @fopen(DB_PATH, 'rb');
    if (!$fp) {
        $json = @file_get_contents(DB_PATH);
        $data = json_decode($json, true);
        return (is_array($data)) ? $data : $default;
    }
    
    @flock($fp, LOCK_SH);
    $json = '';
    while (!feof($fp)) {
        $json .= fread($fp, 8192);
    }
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    $data = json_decode($json, true);
    if (!$data || !is_array($data)) return $default;
    
    $changed = false;
    foreach ($default as $key => $val) {
        if (!isset($data[$key])) {
            $data[$key] = $val;
            $changed = true;
        }
    }
    
    if ($changed) saveJsonDB($data);
    return $data;
}

function saveJsonDB($data) {
    $data['projects']=array_values(array_filter($data['projects']??[],static fn($p)=>!ProjectLifecycle::isDeleted($p['id']??'')));
    $fp = @fopen(DB_PATH, 'cb');
    if (!$fp) {
        @file_put_contents(DB_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return;
    }
    
    @flock($fp, LOCK_EX);
    @ftruncate($fp, 0);
    @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

function getDB() {
    return db_get_all();
}

function saveDB($data) {
    $data['projects']=array_values(array_filter($data['projects']??[],static fn($p)=>!ProjectLifecycle::isDeleted($p['id']??'')));
    if (isSqlActive()) {
        $conn = getConn();
        foreach ($data['projects'] as $project) {
            $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ?");
            if (!$stmt) continue;
            $stmt->bind_param("s", $project['id']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $stmtInsert = $conn->prepare("INSERT INTO projects (id, name, active_from, active_to, settings) VALUES (?, ?, ?, ?, ?)");
                if ($stmtInsert) {
                    $settings = json_encode($project['settings'], JSON_UNESCAPED_UNICODE);
                    $stmtInsert->bind_param("sssss", $project['id'], $project['name'], $project['activeFrom'], $project['activeTo'], $settings);
                    $stmtInsert->execute();
                }
                
                foreach ($project['users'] as $u) {
                    $stmtU = $conn->prepare("INSERT INTO project_users (project_id, username, password, role, permissions) VALUES (?, ?, ?, ?, ?)");
                    if ($stmtU) {
                        $perms = json_encode($u['permissions'], JSON_UNESCAPED_UNICODE);
                        $stmtU->bind_param("sssss", $project['id'], $u['username'], $u['password'], $u['role'], $perms);
                        $stmtU->execute();
                    }
                }
            } else {
                db_save_project($project);
            }
        }
    } else {
        saveJsonDB($data);
    }
}

function getVoterId() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (isset($_SESSION['user']['username'])) {
        return $_SESSION['user']['username'];
    }
    
    // Ensure visitor_id is initialized consistently
    if (!isset($_SESSION['visitor_id']) || empty($_SESSION['visitor_id'])) {
        // Build an idempotent browser-fingerprint hash
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['visitor_id'] = md5($ip . $ua . session_id());
    }
    return $_SESSION['visitor_id'];
}

function getCurrentProject($id = null, $forceReload = false) {
    if(ProjectLifecycle::isDeleted($id??($_SESSION['project_id']??'')))return null;
    if ($id === null && isset($_SESSION['project_id'])) {
        $id = $_SESSION['project_id'];
    }
    if ($id === null) {
        if (isSqlActive()) {
            $conn = getConn();
            if ($conn) {
                $res = $conn->query("SELECT id FROM projects LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    $id = $row['id'];
                }
            }
        } else {
            $db = getJsonDB();
            if (!empty($db['projects'])) {
                $firstPrj = reset($db['projects']);
                $id = $firstPrj['id'] ?? null;
            }
        }
    }
    if ($id === null || !is_scalar($id)) return null;

    static $memory_cache = [];
    if (!$forceReload && isset($memory_cache[$id])) {
        return $memory_cache[$id];
    }

    if (isSqlActive()) {
        $conn = getConn();
        $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) return null;
        $row = $res->fetch_assoc();
        if (!$row) return null;
        
        $settings = json_decode($row['settings'], true) ?: [];
        $cache_config = $settings['cache_config'] ?? [];
        $cache_enabled = !empty($cache_config['enabled']);
        $cache_ttl = isset($cache_config['ttl']) ? (int)$cache_config['ttl'] : 600;

        if ($cache_enabled && !$forceReload) {
            $cached_project = MaarkshoCache::get('project_full_' . $id, $cache_ttl);
            if ($cached_project && is_array($cached_project)) {
                ProductApprovalFlow::normalizeRejections($cached_project);
                $GLOBALS['LAST_LOADED_PROJECT'] = $cached_project;
                $memory_cache[$id] = $cached_project;
                return $cached_project;
            }
        }
        
        $project = [
            'id' => $row['id'],
            'name' => $row['name'],
            'activeFrom' => $row['active_from'],
            'activeTo' => $row['active_to'],
            'settings' => json_decode($row['settings'], true) ?: [],
            'users' => [],
            'products' => [],
            'logs' => [],
            'schedules' => []
        ];
        
        // بارگذاری کاربران
        $stmtU = $conn->prepare("SELECT * FROM project_users WHERE project_id = ?");
        if ($stmtU) {
            $stmtU->bind_param("s", $row['id']);
            $stmtU->execute();
            $uRes = $stmtU->get_result();
            if ($uRes) {
                while ($uRow = $uRes->fetch_assoc()) {
                    $project['users'][] = [
                        'username' => $uRow['username'],
                        'password' => $uRow['password'],
                        'role' => $uRow['role'],
                        'permissions' => json_decode($uRow['permissions'], true) ?: [],
                        'panel_access' => isset($uRow['panel_access']) ? json_decode($uRow['panel_access'],true) : null,
                        'mobile' => $uRow['mobile'] ?? '',
                        'telegram_chat_id' => $uRow['telegram_chat_id'] ?? '',
                        'bale_chat_id' => $uRow['bale_chat_id'] ?? ''
                    ];
                }
            }
        }
        
        // بارگذاری محصولات
        $stmtP = $conn->prepare("SELECT * FROM products WHERE project_id = ? ORDER BY extracted_at DESC");
        if ($stmtP) {
            $stmtP->bind_param("s", $row['id']);
            $stmtP->execute();
            $pRes = $stmtP->get_result();
            if ($pRes) {
                while ($pRow = $pRes->fetch_assoc()) {
                    $userVotes = json_decode($pRow['user_votes'] ?? '[]', true) ?: [];
                    if (!is_array($userVotes)) $userVotes = [];
                    $voterVotes = [];
                    foreach ($userVotes as $vid => $vdata) {
                        $voterVotes[$vid] = is_array($vdata) ? ($vdata['type'] ?? 'like') : $vdata;
                    }
                    $project['products'][] = [
                        'id' => $pRow['id'],
                        'sku' => $pRow['sku'],
                        'name' => $pRow['name'],
                        'image' => $pRow['image'],
                        'social_image' => $pRow['social_image'] ?? '',
                        'gallery' => json_decode($pRow['gallery'] ?? '[]', true) ?: [],
                        'link' => $pRow['link'],
                        'price' => $pRow['price'],
                        'extractedAt' => (int)$pRow['extracted_at'],
                        'manualEdit' => (bool)$pRow['manual_edit'],
                        'likes' => (int)$pRow['likes'],
                        'dislikes' => (int)$pRow['dislikes'],
                        'userVotes' => $userVotes,
                        'voterVotes' => $voterVotes,
                        'userFeedback' => json_decode($pRow['user_feedback'] ?? '[]', true) ?: [],
                        'platformsSent' => json_decode($pRow['platforms_sent'] ?? '{}', true) ?: [],
                        'status' => $pRow['status'] ?? 'active',
                        'color' => $pRow['color'] ?? '',
                        'sizes' => json_decode($pRow['sizes'] ?? '[]', true) ?: [],
                        'variants' => json_decode($pRow['variants'] ?? '[]', true) ?: [],
                        'size_chart' => $pRow['size_chart'] ?? '',
                        'size_chart_template_id' => $pRow['size_chart_template_id'] ?? '',
                        'description' => $pRow['description'] ?? '',
                        'stock_online' => $pRow['stock_online'] ?? '',
                        'stock_total' => $pRow['stock_total'] ?? '',
                        'warehouses_stock' => json_decode($pRow['warehouses_stock'] ?? '{}', true) ?: [],
                        'warehouse_details' => json_decode($pRow['warehouse_details'] ?? '[]', true) ?: [],
                        'model_code' => $pRow['model_code'] ?? '',
                        'priority' => (int)($pRow['priority'] ?? 0),
                        'utm_source' => $pRow['utm_source'] ?? '',
                        'utm_medium' => $pRow['utm_medium'] ?? '',
                        'utm_campaign' => $pRow['utm_campaign'] ?? '',
                        'uploaded_to_site' => (int)($pRow['uploaded_to_site'] ?? 0),
                        'uploaded_by' => $pRow['uploaded_by'] ?? '',
                        'is_manual_image' => (bool)($pRow['is_manual_image'] ?? false),
                        'publish_priority' => isset($pRow['publish_priority']) ? (bool)$pRow['publish_priority'] : false,
                        'content_gen_requested' => isset($pRow['content_gen_requested']) ? (bool)$pRow['content_gen_requested'] : false,
                        'stage1_decision' => $pRow['stage1_decision'] ?? 'pending',
                        'stage2_decision' => $pRow['stage2_decision'] ?? 'pending',
                        'approvals_stage1' => json_decode($pRow['approvals_stage1'] ?? '[]', true) ?: [],
                        'approvals_stage2' => json_decode($pRow['approvals_stage2'] ?? '[]', true) ?: [],
                        'rejection_reason' => $pRow['rejection_reason'] ?? '',
                        'rejectionReason' => $pRow['rejection_reason'] ?? '',
                        'rejection_by' => $pRow['rejection_by'] ?? '',
                        'rejection_side' => $pRow['rejection_side'] ?? '',
                        'rejections_history' => json_decode($pRow['rejections_history'] ?? '[]', true) ?: [],
                        'reference_gallery' => json_decode($pRow['reference_gallery'] ?? '[]', true) ?: [],
                        'images_list' => json_decode($pRow['images_list'] ?? '[]', true) ?: [],
                        'size_chart_uploaded' => !empty($pRow['size_chart_uploaded']),
                        'size_chart_uploaded_at' => $pRow['size_chart_uploaded_at'] ?? null,
                        'website_sync' => json_decode($pRow['website_sync'] ?? '{}', true) ?: []
                    ];
                }
            }
        }
        
        foreach ($project['products'] as &$productWithTiam) $productWithTiam = TiamInventory::restoreProduct($productWithTiam);
        unset($productWithTiam);

        // بارگذاری لاگ‌ها
        $stmtL = $conn->prepare("SELECT * FROM logs WHERE project_id = ? ORDER BY at DESC LIMIT 100");
        if ($stmtL) {
            $stmtL->bind_param("s", $row['id']);
            $stmtL->execute();
            $lRes = $stmtL->get_result();
            if ($lRes) {
                while ($lRow = $lRes->fetch_assoc()) {
                    $project['logs'][] = [
                        'at' => (int)$lRow['at'],
                        'type' => $lRow['type'],
                        'productName' => $lRow['product_name'],
                        'platform' => $lRow['platform']
                    ];
                }
            }
        }
        
        // بارگذاری زمان‌بندی‌ها
        $stmtS = $conn->prepare("SELECT s.*, p.name as product_name, p.image as product_image FROM schedules s LEFT JOIN products p ON s.product_id = p.id WHERE s.project_id = ? ORDER BY s.scheduled_at ASC");
        if ($stmtS) {
            $stmtS->bind_param("s", $row['id']);
            $stmtS->execute();
            $sRes = $stmtS->get_result();
            if ($sRes) {
                while ($sRow = $sRes->fetch_assoc()) {
                    $schAt = strtotime($sRow['scheduled_at']);
                    $schStatus = $sRow['status'];

                    $project['schedules'][] = [
                        'id' => $sRow['id'],
                        'productId' => $sRow['product_id'],
                        'productName' => ($sRow['product_name'] ?: (!empty($sRow['custom_title']) ? $sRow['custom_title'] : 'پست سفارشی')),
                        'productImage' => $sRow['product_image'] ?? $sRow['media_url'],
                        'platform' => $sRow['platform'],
                        'caption' => $sRow['caption'],
                        'mediaUrl' => $sRow['media_url'],
                        'scheduledAt' => $schAt,
                        'status' => $schStatus,
                        'approvalStatus' => $sRow['approval_status'] ?? 'pending',
                        'comments' => json_decode($sRow['comments'] ?? '[]', true) ?: [],
                        'reactions' => json_decode($sRow['reactions'] ?? '{}', true) ?: [],
                        'approvals_stage1' => json_decode($sRow['approvals_stage1'] ?? '[]', true) ?: [],
                        'approvals_stage2' => json_decode($sRow['approvals_stage2'] ?? '[]', true) ?: [],
                        'stage1_decision' => $sRow['stage1_decision'] ?? 'pending',
                        'stage2_decision' => $sRow['stage2_decision'] ?? 'pending',
                        'rejection_reason' => $sRow['rejection_reason'] ?? '',
                        'rejection_by' => $sRow['rejection_by'] ?? '',
                        'rejection_side' => $sRow['rejection_side'] ?? '',
                        'rejection_at' => (int)($sRow['rejection_at'] ?? 0),
                        'rejections_history' => json_decode($sRow['rejections_history'] ?? '[]', true) ?: [],
                        'stage1_system_approved' => (int)($sRow['stage1_system_approved'] ?? 0),
                        'stage2_system_approved' => (int)($sRow['stage2_system_approved'] ?? 0),
                        'post_type' => $sRow['post_type'] ?? 'image',
                        'instagram_caption' => $sRow['instagram_caption'] ?? '',
                        'instagram_media_id' => $sRow['instagram_media_id'] ?? '',
                        'instagram_account_id' => $sRow['instagram_account_id'] ?? '',
                        'error_log' => $sRow['error_log'] ?? '',
                        'publish_status' => $sRow['publish_status'] ?? ($schStatus === 'completed' ? 'published' : ($schStatus === 'failed' ? 'failed' : 'scheduled')),
                        'retry_count' => (int)($sRow['retry_count'] ?? 0)
                    ];
                }
            }
        }
        
        ProductApprovalFlow::normalizeRejections($project);
        autoApprovePendingProducts($project);
        
        if ($cache_enabled) {
            MaarkshoCache::set('project_full_' . $id, $project, $cache_ttl);
        }

        $GLOBALS['LAST_LOADED_PROJECT'] = $project;
        $memory_cache[$id] = $project;
        return $project;
    } else {
        $db = getJsonDB();
        foreach ($db['projects'] as &$p) {
            if ($p['id'] === $id) {
                if (!isset($p['schedules'])) $p['schedules'] = [];
                if (!isset($p['products'])) $p['products'] = [];

                foreach ($p['products'] as &$prod) {
                    if (!isset($prod['userVotes'])) $prod['userVotes'] = [];
                    if (!isset($prod['voterVotes']) || !is_array($prod['voterVotes'])) {
                        $prod['voterVotes'] = [];
                    }
                    if (is_array($prod['userVotes'])) {
                        foreach ($prod['userVotes'] as $vid => $vdata) {
                            $prod['voterVotes'][$vid] = is_array($vdata) ? ($vdata['type'] ?? 'like') : $vdata;
                        }
                    }
                }
                unset($prod);
                ProductApprovalFlow::normalizeRejections($p);
                autoApprovePendingProducts($p);
                $GLOBALS['LAST_LOADED_PROJECT'] = $p;
                return $p;
            }
        }
    }
    return null;
}

/** Approval is explicit; ordinary persistence must never approve an image. */
function approveProductImages(&$product) {
    $images=$product['images_list']??[];if(is_string($images))$images=json_decode($images,true)?:[];
    $product['images_list']=is_array($images)?$images:[];
    foreach($product['images_list'] as $key=>$image)if(is_array($image)) {
        $product['images_list'][$key]['approved']=true;
        $product['images_list'][$key]['approved_at']=time();
        $product['images_list'][$key]['approved_by']=$_SESSION['user']['username']??'';
    }
    $product['approved_at']=time();
}
function saveProject($project, $onlyProducts = null, $onlySchedules = null) {
    $res = db_save_project($project, $onlyProducts, $onlySchedules);
    if (!empty($project['id'])) {
        if (class_exists('MaarkshoCache')) {
            MaarkshoCache::delete('project_full_' . $project['id']);
        }
        getCurrentProject($project['id'], true);
    }
    return $res;
}

function getUploaderDetails($p, $project) {
    $uploaded_by = trim($p['uploaded_by'] ?? '');
    if (empty($uploaded_by)) {
        return [
            'id' => 'system',
            'name' => 'ربات هوشمند سیستم',
            'role' => 'سیستم'
        ];
    }
    
    $users = $project['users'] ?? [];
    foreach ($users as $u) {
        if ($u['username'] === $uploaded_by) {
            $roleLabel = ($u['role'] === 'editor') ? 'ویرایشگر محتوا' : (($u['role'] === 'project_admin') ? 'مدیر پروژه' : 'ناظر');
            return [
                'id' => $u['username'],
                'name' => $u['username'],
                'role' => $roleLabel,
                'mobile' => $u['mobile'] ?? ''
            ];
        }
    }
    
    if ($uploaded_by === 'admin' || $uploaded_by === 'مدیر سیستم') {
        return [
            'id' => 'admin',
            'name' => 'مدیر ارشد سیستم',
            'role' => 'مدیر کل'
        ];
    }
    
    return [
        'id' => $uploaded_by,
        'name' => $uploaded_by,
        'role' => 'کاربر پورتال'
    ];
}

function getPrioritizedProductImage($p) {
    if (empty($p) || !is_array($p)) {
        return 'https://placehold.co/400x500?text=No+Image';
    }
    // Priority 1: social_image
    if (!empty($p['social_image']) && isValidProductImage($p['social_image'])) {
        return is_array($p['social_image']) ? ($p['social_image']['src'] ?? $p['social_image']['url'] ?? '') : (string)$p['social_image'];
    }
    // Priority 2: main site image (image)
    if (!empty($p['image']) && isValidProductImage($p['image'])) {
        return is_array($p['image']) ? ($p['image']['src'] ?? $p['image']['url'] ?? '') : (string)$p['image'];
    }
    // Priority 3: images_list
    if (!empty($p['images_list'])) {
        $imgList = $p['images_list'];
        if (is_string($imgList)) {
            $imgList = json_decode($imgList, true) ?: [];
        }
        if (is_array($imgList)) {
            foreach ($imgList as $imgItem) {
                $imgUrl = is_array($imgItem) ? ($imgItem['url'] ?? ($imgItem['src'] ?? ($imgItem['image'] ?? ''))) : (string)$imgItem;
                if (!empty($imgUrl) && isValidProductImage($imgUrl)) {
                    return $imgUrl;
                }
            }
        }
    }
    // Priority 4: images array (WooCommerce standard)
    if (!empty($p['images'])) {
        $imgs = $p['images'];
        if (is_string($imgs)) {
            $imgs = json_decode($imgs, true) ?: [];
        }
        if (is_array($imgs)) {
            foreach ($imgs as $imgItem) {
                $imgUrl = is_array($imgItem) ? ($imgItem['src'] ?? ($imgItem['url'] ?? '')) : (string)$imgItem;
                if (!empty($imgUrl) && isValidProductImage($imgUrl)) {
                    return $imgUrl;
                }
            }
        }
    }
    // Priority 5: first valid item in gallery (gallery)
    if (!empty($p['gallery'])) {
        $gal = $p['gallery'];
        if (is_string($gal)) {
            $gal = json_decode($gal, true) ?: [];
        }
        if (is_array($gal)) {
            foreach ($gal as $g) {
                $gUrl = is_array($g) ? ($g['url'] ?? ($g['src'] ?? ($g['image'] ?? ''))) : (string)$g;
                if (!empty($gUrl) && isValidProductImage($gUrl)) {
                    return $gUrl;
                }
            }
        }
    }
    // Priority 6: first valid item in reference gallery (reference_gallery)
    if (!empty($p['reference_gallery'])) {
        $ref = $p['reference_gallery'];
        if (is_string($ref)) {
            $ref = json_decode($ref, true) ?: [];
        }
        if (is_array($ref)) {
            foreach ($ref as $r) {
                $rUrl = is_array($r) ? ($r['url'] ?? ($r['src'] ?? ($r['image'] ?? ''))) : (string)$r;
                if (!empty($rUrl) && isValidProductImage($rUrl)) {
                    return $rUrl;
                }
            }
        }
    }
    
    return 'https://placehold.co/400x500?text=No+Image';
}

/**
 * تبدیل ارقام فارسی و عربی به انگلیسی جهت محاسبات عددی دقیق
 */
function normalizeDigitsToEnglish($str) {
    if ($str === null || $str === '') return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $str = str_replace($persian, $english, (string)$str);
    return str_replace($arabic, $english, $str);
}

/**
 * بررسی دقیق اینکه آیا محصول بدون موجودی است یا خیر
 * اگر وضعیت out_of_stock باشد یا موجودی آنلاین و کل صفر/ناموجود باشد، محصول ناموجود است
 */
function isProductOutOfStock($p) {
    return is_array($p)&&WebsiteCatalogSync::inventory($p)==='out_of_stock';
}

function isValidProductImage($url) {
    if (is_array($url)) {
        $url = $url['src'] ?? $url['url'] ?? $url['guid'] ?? $url['image'] ?? '';
    }
    if (!is_string($url)) return false;
    $u = strtolower(trim($url));
    if ($u === '' || strlen($u) < 4 || $u === 'null' || $u === 'undefined' || $u === 'none' || $u === '0' || $u === 'false' || $u === '[]' || $u === '{}' || $u === 'null.jpg' || $u === 'null.png') {
        return false;
    }
    // Check if it's just a root directory or domain without filename
    if (preg_match('#^https?://[^/]+/?$#i', $u) || preg_match('#/wp-content/uploads/?$#i', $u)) {
        return false;
    }
    // Filter out common placeholder patterns and default dummy images
    $invalidKeywords = [
        'placeholder', 'placehold.co', 'placehold.it', 'placehold', 'dummyimage', 'dummy',
        'woocommerce-placeholder', 'woocommerce_placeholder', 'wc-placeholder',
        'no-img', 'no_img', 'no-image', 'no_image', 'noimage', 'noimg',
        'no-photo', 'no_photo', 'nophoto', 'no-picture', 'no_picture', 'nopicture',
        'default.jpg', 'default.png', 'default.jpeg', 'default.webp', 'default.gif',
        'default_image', 'default-image', 'defaultimage', 'default_product', 'default-product', 'defaultproduct',
        'empty_product', 'empty-product', 'emptyproduct', 'empty.jpg', 'empty.png',
        'assets/no-img', 'assets/placeholder', 'images/no-img', 'img/no-img',
        'blank.gif', 'blank.png', 'pixel.gif', 'data:image', '1x1.png', 'spacer.gif'
    ];
    foreach ($invalidKeywords as $kw) {
        if (strpos($u, $kw) !== false) {
            return false;
        }
    }
    return true;
}

function isProductNoImage($p) {
    if (empty($p) || !is_array($p)) return false;
    if(ImageProduction::issueType($p)!==''||ImageProduction::imageExempt($p))return false;
    
    // اگر کالا بدون موجودی باشد، اولویت قطعی با بدون موجودی است
    if (isProductOutOfStock($p)) {
        return false;
    }
    
    // کالا اگر تصویر نهایی تایید شده/سوشال/گالری سایت نداشته باشد، بدون تصویر است
    return !hasProductFinalImages($p);
}

if (!function_exists('is_product_without_image')) {
    function is_product_without_image($p) {
        return isProductNoImage($p);
    }
}

/**
 * آیا محصول دارای حداقل یک عکس رفرنس معتبر است؟
 */
function hasProductReferenceImages($p) {
    return is_array($p)&&(bool)ImageProduction::references($p);
}

/**
 * دریافت لیست آرایه‌ای عکس‌های رفرنس محصول
 */
function getProductReferenceImagesList($p) {
    return is_array($p)?ImageProduction::references($p):[];
}

/**
 * آیا محصول تصویر نهایی/تولید شده معتبر دارد (غیر از عکس رفرنس)؟
 */
function hasProductFinalImages($p) {
    if (empty($p) || !is_array($p)) return false;
    if (!empty($p['image']) && isValidProductImage($p['image'])) return true;
    $socImg = $p['social_image'] ?? ($p['socialImage'] ?? '');
    if (!empty($socImg) && isValidProductImage($socImg)) return true;
    if (!empty($p['images_list'])) {
        $imgList = is_string($p['images_list']) ? json_decode($p['images_list'], true) : $p['images_list'];
        if (is_array($imgList)) {
            foreach ($imgList as $item) {
                $itemUrl = is_array($item) ? ($item['url'] ?? ($item['src'] ?? ($item['image'] ?? ''))) : (string)$item;
                if (!empty($itemUrl) && isValidProductImage($itemUrl)) return true;
            }
        }
    }
    if (!empty($p['images'])) {
        $imgs = is_string($p['images']) ? json_decode($p['images'], true) : $p['images'];
        if (is_array($imgs)) {
            foreach ($imgs as $item) {
                $itemUrl = is_array($item) ? ($item['src'] ?? ($item['url'] ?? '')) : (string)$item;
                if (!empty($itemUrl) && isValidProductImage($itemUrl)) return true;
            }
        }
    }
    if (!empty($p['gallery'])) {
        $gal = is_string($p['gallery']) ? json_decode($p['gallery'], true) : $p['gallery'];
        if (is_array($gal)) {
            foreach ($gal as $g) {
                $gUrl = is_array($g) ? ($g['url'] ?? ($g['src'] ?? ($g['image'] ?? ''))) : (string)$g;
                if (!empty($gUrl) && isValidProductImage($gUrl)) return true;
            }
        }
    }
    return false;
}

/**
 * آیا محصول فاقد جدول سایز است؟
 */
function isProductMissingSizeChart($p, $project = null) {
    if(empty($p)||!is_array($p))return true;
    $project=$project??getCurrentProject();
    return !ProductSizeChartCatalog::forProduct($project??[], $p)['exists'];
}

/**
 * آیا محصول دارای جدول سایز است؟
 */
function hasProductSizeChart($p, $project = null) {
    return !isProductMissingSizeChart($p,$project);
}

/**
 * آیا جدول سایز محصول ساخته شده اما هنوز به سایت آپلود نشده؟
 */
function isProductSizeChartPendingUpload($p) {
    return hasProductSizeChart($p) && empty($p['size_chart_uploaded']);
}

/**
 * تعیین وضعیت دقیق کالا در خط لوله تولید محتوا:
 * 1. out_of_stock: بدون موجودی (اولویت اول)
 * 2. waiting_reference: دارای اولویت تولید محتوا ولی فاقد تصویر نهایی و فاقد عکس رفرنس
 * 3. no_image: فاقد هرگونه تصویر و رفرنس بدون اولویت
 * 4. waiting_ai_image: در انتظار عکس نهایی (دارای رفرنس یا جدول سایز یا درخواست تولید هوش مصنوعی)
 * 5. pending_approval: در انتظار تایید پورتال (عکس نهایی تولید/بارگذاری شده و منتظر تایید است)
 * 6. active: تایید نهایی شده و منتظر آپلود/ارسال
 * 7. rejected: رد شده
 */
function getProductWorkflowStatus($p, $project = null) {
    if (empty($p) || !is_array($p)) return 'no_image';
    if (isProductOutOfStock($p)) {
        return 'out_of_stock';
    }
    
    $issue=ImageProduction::issueType($p);if($issue!=='')return $issue==='technical'?'technical_pending':'inventory_issue';
    if(ImageProduction::imageExempt($p))return 'image_not_required';
    $hasFinal = (bool)WebsiteCatalogSync::photos($p);
    $hasRef = hasProductReferenceImages($p) || !empty($p['waiting_final_photo']);
    $hasSizeChart = hasProductSizeChart($p,$project);
    $hasPriority = !empty($p['content_gen_requested']) || !empty($p['publish_priority']);
    $rawStatus = $p['status'] ?? 'active';
    $hasUnapprovedImages = (bool)WebsiteCatalogSync::pendingApprovalPhotos($p,true);
    
    if ($rawStatus === 'rejected'||($p['stage1_decision']??'')==='rejected'||($p['stage2_decision']??'')==='rejected') {
        return 'rejected';
    }
    
    if($hasUnapprovedImages)return 'pending_approval';
    if(!$hasFinal) {
        $context=$project??getCurrentProject();
        $production=ImageProduction::state($context??[],$p,false,false);
        if($production['needs_image_request'])return 'image_request';
        if($production['needs_reference']&&$production['reference_required'])return 'waiting_reference';
        if($hasRef||$hasSizeChart||$hasPriority)return 'waiting_ai_image';
        return 'no_image';
    }

    
    return 'active';
}

function syncWithTiam(&$project) {
    return TiamConnector::sync($project);
}

function findArrayInSoap($arr) {
    if (empty($arr) || !is_array($arr)) return [];
    
    // If it is a sequential list
    if (isset($arr[0])) {
        return $arr;
    }
    
    // DFS search for arrays
    foreach ($arr as $k => $v) {
        if (is_array($v)) {
            $res = findArrayInSoap($v);
            if (!empty($res)) return $res;
        }
    }
    return [];
}

// ================================
// منطق ادغام محصولات و اولویت‌بندی دیتا
// ================================

/**
 * ادغام دیتای محصول جدید با موجود بر اساس اولویت‌ها
 * اولویت تصویر: دستی > سایت > اکسل
 * اولویت توضیحات/لینک: سایت > بقیه
 * اولویت قیمت/موجودی: اکسل > سایت
 */
function mergeProductData($existing, $new, $source = 'scraper') {
    if (!$existing) return $new;
    
    $merged = $existing;
    
    if ($source === 'manual') {
        // Direct manual edit can change any fields
        if (!empty($new['image'])) $merged['image'] = $new['image'];
        if (!empty($new['gallery'])) $merged['gallery'] = $new['gallery'];
        if (!empty($new['name'])) $merged['name'] = $new['name'];
        if (!empty($new['description'])) $merged['description'] = $new['description'];
        if (!empty($new['link'])) $merged['link'] = $new['link'];
        if (!empty($new['size_chart'])) $merged['size_chart'] = $new['size_chart'];
        if (!empty($new['color'])) $merged['color'] = $new['color'];
        if (!empty($new['sizes'])) $merged['sizes'] = $new['sizes'];
        if (!empty($new['price'])) $merged['price'] = $new['price'];
        if (isset($new['stock_online'])) $merged['stock_online'] = $new['stock_online'];
        if (isset($new['stock_total'])) $merged['stock_total'] = $new['stock_total'];
        if (!empty($new['sku'])) $merged['sku'] = $new['sku'];
        $merged['manualEdit'] = 1;
    } else {
        // Any incoming automatic sync/JSON or Excel update for existing product
        // ONLY update the price, stock_online, and stock_total. All other settings remain untouched.
        if (!empty($new['price'])) {
            $merged['price'] = $new['price'];
        }
        if (isset($new['stock_online'])) {
            $merged['stock_online'] = $new['stock_online'];
        }
        if (isset($new['stock_total'])) {
            $merged['stock_total'] = $new['stock_total'];
        }
    }

    $merged['extractedAt'] = time();
    return $merged;
}

// ================================
// توابع احراز هویت
// ================================

function isSuperAdmin() {
    return FeatureFlags::isMainAdmin();
}

function isMainAdmin() {
    return FeatureFlags::isMainAdmin();
}

function isFeatureAvailable($featureKey) {
    return FeatureFlags::isAvailable($featureKey);
}

function getFeatureStatus($featureKey) {
    return FeatureFlags::getStatus($featureKey);
}

function getFeatureBadge($featureKey) {
    return FeatureFlags::getBadgeHtml($featureKey);
}

function isProjectAdmin() {
    return isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'project_admin';
}

function hasPermission($perm, $mode = null) {
    $mode = $mode ?? (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 'view' : 'use');
    if (!in_array($mode, ['view', 'use'], true)) return false;
    if (isMainAdmin()) return true;
    $key = AccessControl::featureKey($perm);
    if (!FeatureFlags::isAvailable($key)) return false;
    $user = $_SESSION['user'] ?? null;
    if (!$user) return false;
    // Read the current project's record: another tab may have switched the session.
    $project = getCurrentProject();
    $member = SessionIdentity::member($project);
    if (!$member) return false;
    if (!checkModuleSettingAccess($key, $project['settings'] ?? [])) return false;
    if (!AccessControl::allowed($key, $mode)) return false;
    $permissions = array_map([AccessControl::class, 'featureKey'], $member['permissions'] ?? []);
    $legacyPermission = ['seo_intel' => 'analysis', 'seo_classic' => 'analysis', 'sale_analysis' => 'analysis'][$key] ?? $key;
    $base = ($member['role'] ?? '') === 'project_admin' || in_array($key, $permissions, true) || in_array($legacyPermission, $permissions, true);
    // An operation grant does not by itself create visibility when view inherits
    // the member's normal permissions. Both levels must be granted.
    $view = AccessControl::audience($key, 'view', $member, $project['id']);
    if ($view === false || ($view !== true && !$base)) return false;
    if ($mode === 'view') return true;
    if (AccessControl::audience($key, 'use', $member, $project['id']) === true) return true;
    for ($parent = AccessControl::parentFeature($key); $parent !== null; $parent = AccessControl::parentFeature($parent)) {
        if ($base && AccessControl::audience($parent, 'use', $member, $project['id']) === true) return true;
    }
    if (($member['role'] ?? '') === 'viewer') return false;
    return $base;
}

function canAccessFeature($key, $mode = 'view') {
    if (AccessControl::featureKey($key) === 'prompthub' && hasPermission('prompthub_manage', $mode) && isModuleActive('prompthub')) return true;
    return hasPermission($key, $mode) && isModuleActive(AccessControl::featureKey($key));
}

/** Transparently retire plaintext legacy passwords after a successful authentication. */
function upgradeLegacyLoginPassword($username,$password,$projectId=null) {
    $hash=password_hash($password,PASSWORD_DEFAULT);
    if(isSqlActive()) {
        $conn=getConn();
        $stmt=$conn->prepare($projectId===null?'UPDATE super_users SET password=? WHERE username=? AND password=?':'UPDATE project_users SET password=? WHERE username=? AND project_id=? AND password=?');
        if(!$stmt)return;
        if($projectId===null)$stmt->bind_param('sss',$hash,$username,$password);
        else $stmt->bind_param('ssss',$hash,$username,$projectId,$password);
        $stmt->execute();$stmt->close();return;
    }
    $db=getJsonDB();
    if($projectId===null){foreach($db['super_users'] as &$user)if(($user['username']??'')===$username&&($user['password']??'')===$password)$user['password']=$hash;unset($user);}
    else {foreach($db['projects'] as &$prj)if(($prj['id']??'')===$projectId){foreach($prj['users'] as &$user)if(($user['username']??'')===$username&&($user['password']??'')===$password)$user['password']=$hash;unset($user);}unset($prj);}
    saveJsonDB($db);
}

require_once __DIR__.'/PanelAccess.php';
function canAccessManagement() { return PanelAccess::allowed('admin'); }
function canAccessPortal() { return PanelAccess::allowed('portal'); }

function checkModuleSettingAccess($module, $settings) {
    if (FeatureFlags::isUnrestrictedAdmin()) return true;
    $visibility = AccessControl::moduleVisibility($module, $settings);
    return $visibility === 'active' || ($visibility === 'admin_only' && isMainAdmin());
}

function getModuleVisibility($module, $project = null) {
    if (!$project) {
        $project = getCurrentProject();
    }
    return AccessControl::moduleVisibility($module, $project['settings'] ?? []);
}

function getModuleVisibilityBadge($module, $project = null) {
    // If global feature flag is in maintenance:
    $featBadge = FeatureFlags::getBadgeHtml($module);
    if (!empty($featBadge)) {
        return $featBadge;
    }
    // If project-level setting is admin_only and current user is an admin:
    if (isSuperAdmin() || isMainAdmin() || isProjectAdmin()) {
        $vis = getModuleVisibility($module, $project);
        if ($vis === 'admin_only' || $vis === 'maintenance') {
            return '<span class="mr-auto px-1.5 py-0.5 text-[8px] bg-amber-500/20 text-amber-700 border border-amber-300/40 rounded font-bold" title="فقط برای ادمین اصلی باز است">فقط ادمین اصلی</span>';
        }
    }
    return '';
}

function isModuleActive($module) {
    $module = AccessControl::featureKey($module);
    if (FeatureFlags::isUnrestrictedAdmin() || ($module === 'settings' && isMainAdmin())) return true;
    // Check global feature flag first
    if (!FeatureFlags::isAvailable($module)) {
        return false;
    }

    // If the system is in super admin mode but looking at 'projects' list or system_debug, ignore project restrictions
    $current_tab = $_GET['tab'] ?? '';
    if (isSuperAdmin() && ($current_tab === 'projects' || $current_tab === 'system_debug')) {
        return true;
    }

    $id = $_SESSION['project_id'] ?? null;
    if ($id === null) {
        if (isSqlActive()) {
            $conn = getConn();
            if ($conn) {
                $res = $conn->query("SELECT id FROM projects LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    $id = $row['id'];
                }
            }
        } else {
            $db = getJsonDB();
            if (!empty($db['projects'])) {
                $firstPrj = reset($db['projects']);
                $id = $firstPrj['id'] ?? null;
            }
        }
    }
    if ($id === null) return true; // Default fallback if no project is configured yet
    
    if (isSqlActive()) {
        $conn = getConn();
        $stmt = $conn->prepare("SELECT settings FROM projects WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $settings = json_decode($row['settings'], true) ?: [];
                return checkModuleSettingAccess($module, $settings);
            }
        }
    } else {
        $db = getJsonDB();
        foreach ($db['projects'] as $p) {
            if ($p['id'] == $id) {
                $settings = $p['settings'] ?? [];
                return checkModuleSettingAccess($module, $settings);
            }
        }
    }
    return true;
}

function isUser() {
    return isset($_SESSION['user']);
}

function redirect($url) {
    header("Location: $url");
    exit();
}

// ================================
// موتور استخراج (Scraper)
// ================================

$last_fetch_error = "";
$debug_logs = [];

function addDebugLog($section, $msg, $data = null) {
    global $debug_logs;
    $debug_logs[] = [
        'at' => time(),
        'section' => $section,
        'msg' => $msg,
        'data' => $data ? substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 500) : null
    ];
    $debugFile = __DIR__ . '/../scraper/scraper_debug.log';
    $line = date('[Y-m-d H:i:s] ') . "[$section] $msg " . ($data ? json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    file_put_contents($debugFile, $line, FILE_APPEND);
}

function fetchUrl($url, $retries = 2, $timeout = 30, $throttle = true) {
    if(!empty($GLOBALS['onboarding_crawl'])){require_once __DIR__.'/OnboardingCrawl.php';return OnboardingCrawl::fetch($url);}

    global $last_fetch_error;
    if (empty($url)) {
        addDebugLog('CURL', 'URL is empty');
        return false;
    }
    
    if ($throttle) {
        usleep(rand(1000000, 2500000));
    } else {
        usleep(50000); // 50ms fast delay for batch jobs
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, ""); 
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    if (!ini_get('open_basedir')) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }
    
    $uas = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
    ];
    $ua = $uas[array_rand($uas)];
    
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.google.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: max-age=0',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Ch-Ua: "Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
    ]);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); 
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    
    $currentProject = getCurrentProject();
    if ($currentProject && !empty($currentProject['settings']['proxy'])) {
        $proxyStr = trim($currentProject['settings']['proxy']);
        if (strpos($proxyStr, '@') !== false) {
            $parts = explode('@', $proxyStr);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $parts[0]);
            curl_setopt($ch, CURLOPT_PROXY, $parts[1]);
        } else {
            curl_setopt($ch, CURLOPT_PROXY, $proxyStr);
        }
    }

    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_COOKIEFILE, ""); // Enable cookie engine
    
    addDebugLog('CURL', "Fetching: $url");
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($data === false) {
        $last_fetch_error = "Connection failed: " . $error;
        addDebugLog('CURL', 'Error', [
            'url' => $url, 
            'code' => $httpCode, 
            'error' => $error, 
            'time' => $info['total_time'],
            'connect_time' => $info['connect_time']
        ]);
        if ($retries > 0) {
            sleep(rand(5, 10));
            return fetchUrl($url, $retries - 1, $timeout);
        }
        return false;
    }

    if ($httpCode >= 400) {
        $last_fetch_error = "HTTP Error $httpCode";
        $bodyPreview = substr($data, 0, 1000);
        addDebugLog('CURL', 'Bad Status', [
            'url' => $url, 
            'code' => $httpCode, 
            'size' => strlen($data),
            'ip' => $info['primary_ip'],
            'response_preview' => $bodyPreview
        ]);
        return false;
    }

    addDebugLog('CURL', 'Success', [
        'url' => $url, 
        'code' => $httpCode, 
        'size' => strlen($data), 
        'redirects' => $info['redirect_count'],
        'ip' => $info['primary_ip']
    ]);
    return $data;
}

function scrapeCategories($baseUrl = 'https://www.tanedorost.com', $customStructure = null) {
    if (strpos($baseUrl, 'http') !== 0) $baseUrl = 'https://' . $baseUrl;
    
    addDebugLog('Scraper', 'Starting discovery', ['url' => $baseUrl]);
    $categories = [];
    $parsedBase = parse_url($baseUrl);
    $host = str_replace('www.', '', $parsedBase['host'] ?? 'tanedorost.com');
    $scheme = $parsedBase['scheme'] ?? 'https';
    $fullBase = $scheme . '://' . ($parsedBase['host'] ?? 'www.tanedorost.com');

    $html = fetchUrl($baseUrl);
    if ($html) {
        addDebugLog('Scraper', 'Fetched HTML', ['size' => strlen($html)]);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $menuXpaths = [
            $customStructure['menu'] ?? null,
            "//ul[contains(@class, 'menu')]//a",
            "//div[contains(@class, 'megamenu')]//a",
            "//a[contains(@href, '/category/')]",
            "//a[contains(@href, '/product-category/')]",
            "//a[contains(@href, '/shop/')]",
            "//nav//a",
            "//header//a"
        ];
        
        foreach($menuXpaths as $xpathQuery) {
            if(!$xpathQuery) continue;
            $nodes = $xpath->query($xpathQuery);
            if ($nodes && $nodes->length > 0) {
                foreach($nodes as $node) {
                    $href = $node->getAttribute('href');
                    $name = trim($node->nodeValue);
                    if (!$href || $href == '#' || strpos($href, 'javascript:') === 0) continue;
                    
                    // Normalize URL
                    if (strpos($href, '//') === 0) {
                        $href = $scheme . ':' . $href;
                    } elseif (strpos($href, '/') === 0) {
                        $href = rtrim($fullBase, '/') . '/' . ltrim($href, '/');
                    } elseif (strpos($href, 'http') !== 0) {
                        $href = rtrim($baseUrl, '/') . '/' . $href;
                    }
                    
                    $thisParsed = parse_url($href);
                    $thisHost = str_replace('www.', '', $thisParsed['host'] ?? '');
                    
                    if ($thisHost === $host || empty($thisHost)) {
                        $isCategory = (
                            strpos($href, '/search/') !== false || 
                            strpos($href, '/category/') !== false || 
                            strpos($href, '/product-category/') !== false || 
                            strpos($href, '/shop/') !== false ||
                            strpos($href, '/cat/') !== false ||
                            strpos($href, '/categories/') !== false ||
                            preg_match('/\/(men|women|kids|clothing|accessories|sale|new-arrivals|products)\//i', $href)
                        );
                        if ($isCategory) {
                             $categories[$href] = $name ?: ($categories[$href] ?? 'دسته بدون نام');
                        }
                    }
                }
            }
        }
        
        if (empty($categories)) {
             addDebugLog('Scraper', 'No menu nodes found, checking body fallback');
             $nodes = $xpath->query("//body//a");
             if ($nodes) {
                 foreach($nodes as $node) {
                     $href = $node->getAttribute('href');
                     if (!$href) continue;
                     if (strpos($href, 'product-category') !== false || strpos($href, '/category/') !== false || strpos($href, '/shop/') !== false) {
                         if (strpos($href, 'http') !== 0 && strpos($href, '/') === 0) $href = rtrim($fullBase, '/') . '/' . ltrim($href, '/');
                         $categories[$href] = trim($node->nodeValue) ?: 'دسته خودکار';
                     }
                 }
             }
        }
    } else {
        addDebugLog('Scraper', 'Failed to fetch base HTML');
    }

    $result = [];
    foreach ($categories as $url => $name) {
        $ignore = ['login', 'account', 'cart', 'checkout', 'contact', 'aboutus', 'blog', 'stores', 'mag.tanedorost', 'gift-card'];
        $shouldIgnore = false;
        foreach ($ignore as $word) {
            if (strpos(strtolower($url), $word) !== false) {
                $shouldIgnore = true;
                break;
            }
        }
        if ($shouldIgnore) continue;
        $result[] = ['name' => $name, 'url' => $url];
    }
    
    addDebugLog('Scraper', 'Result', ['count' => count($result)]);
    return $result;
}

function scrapeProductsFromCategory($catUrl, $customStructure = null) {
    addDebugLog('Scraper', 'Scraping products from category', ['url' => $catUrl]);
    $foundProducts = [];
    $page = 1;
    $maxPages = 2;

    $itemXpath = $customStructure['item'] ?? "//div[contains(@class, 'product-wrap')] | //div[contains(@class, 'product')] | //div[contains(@class, 'grid-item')] | //li[contains(@class, 'product')]";
    $nameXpath = $customStructure['name'] ?? ".//h3 | .//h4 | .//div[contains(@class, 'title')] | .//a[contains(@class, 'title')]";
    $imgXpath = $customStructure['image'] ?? ".//img";
    $priceXpath = $customStructure['price'] ?? ".//span[contains(@class, 'price')] | //div[contains(@class, 'price')]";

    addDebugLog('Scraper', 'Using Item XPath', ['item' => $itemXpath]);

    while ($page <= $maxPages) {
        $pagedUrl = $catUrl;
        if ($page > 1) {
            if (strpos($catUrl, 'sportland.ir') !== false) {
                // Sportland uses ?page=X
                if (strpos($catUrl, '?') !== false) { $pagedUrl .= "&page=$page"; }
                else { $pagedUrl = rtrim($catUrl, '/') . "?page=$page"; }
            } else {
                if (strpos($catUrl, '?') !== false) {
                    $pagedUrl .= "&paged=$page";
                } else {
                    $pagedUrl = rtrim($catUrl, '/') . "/page/$page/";
                }
            }
        }
        
        $html = fetchUrl($pagedUrl);
        if (!$html) break;

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $productNodes = $xpath->query($itemXpath);
        if ($productNodes->length == 0 && empty($customStructure)) {
             $productNodes = $xpath->query("//div[contains(@class, 'product')] | //li[contains(@class, 'product')]");
        }
        
        if ($productNodes->length == 0) break;

        $parsedCat = parse_url($catUrl);
        $catBase = ($parsedCat['scheme'] ?? 'https') . '://' . ($parsedCat['host'] ?? 'www.tanedorost.com');

        foreach ($productNodes as $node) {
            $linkNode = $xpath->query(".//a[contains(@href, '/product/')]", $node)->item(0) ?: $xpath->query(".//a", $node)->item(0);
            $nameNode = $xpath->query($nameXpath, $node)->item(0);
            $imgNode = $xpath->query($imgXpath, $node)->item(0);
            $priceNode = $xpath->query($priceXpath, $node)->item(0);

            if ($linkNode) {
                $rawLink = $linkNode->getAttribute('href');
                if (empty($rawLink) || $rawLink == '#') continue;
                
                $link = (strpos($rawLink, 'http') === 0) ? $rawLink : rtrim($catBase, '/') . '/' . ltrim($rawLink, '/');
                
                $id = "";
                if (preg_match('/\/product\/(\d+)/', $link, $matches)) { $id = $matches[1]; }
                elseif (preg_match('/-p-(\d+)/', $link, $matches)) { $id = $matches[1]; }
                if (!$id) $id = md5($link);

                $name = $nameNode ? trim($nameNode->nodeValue) : '';
                if (empty($name) && $imgNode) $name = trim($imgNode->getAttribute('alt') ?: $imgNode->getAttribute('title'));
                if (empty($name) || mb_strlen($name) < 2) continue;

                $img = $imgNode ? ($imgNode->getAttribute('src') ?: ($imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('data-lazy-src'))) : '';
                if ($img && strpos($img, 'http') !== 0) {
                    $img = rtrim($catBase, '/') . '/' . ltrim($img, '/');
                }

                $price = $priceNode ? trim($priceNode->nodeValue) : 'نامشخص';
                $price = preg_replace('/\s+/', ' ', $price);
                if (preg_match('/([0-9,]+)/', $price, $pm)) {
                    $priceVal = str_replace(',', '', $pm[1]);
                    $price = number_format((float)$priceVal) . ' تومان';
                }

                $foundProducts[] = [
                    'id' => $id,
                    'sku' => $id,
                    'name' => htmlspecialchars_decode($name),
                    'image' => $img,
                    'link' => $link,
                    'price' => $price,
                    'extractedAt' => time(),
                    'manualEdit' => false,
                    'likes' => 0,
                    'dislikes' => 0,
                    'userVotes' => [],
                    'userFeedback' => [],
                    'platformsSent' => [],
                    'status' => 'pending'
                ];
            }
        }
        if ($productNodes->length < 4) break;
        $page++;
    }
    return $foundProducts;
}

function getProductDeepData($product) {
    if (!empty($product['manualEdit']) && $product['manualEdit']) return $product;

    $html = fetchUrl($product['link']);
    if (!$html) return $product;

    $parsedLink = parse_url($product['link']);
    $linkBase = ($parsedLink['scheme'] ?? 'https') . '://' . ($parsedLink['host'] ?? 'www.tanedorost.com');

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    if(empty($product['name'])){$titleNode=$xpath->query('//h1')->item(0);if($titleNode)$product['name']=trim($titleNode->nodeValue);}

    $skuNode = $xpath->query("//span[contains(@class, 'product-sku')] | //*[@id='product-code'] | //span[contains(@class, 'sku')] | //div[contains(text(), 'کد کالا')]")->item(0);
    if ($skuNode) {
        $skuText = trim($skuNode->nodeValue);
        if (strpos($skuText, ':') !== false) {
            $parts = explode(':', $skuText);
            $skuText = trim(end($parts));
        }
        $product['sku'] = preg_replace('/[^0-9A-Z\-]/i', '', $skuText) ?: $skuText;
    }

    $priceNode = $xpath->query("//div[contains(@class, 'product-price')]//span")->item(0) ?: 
                 $xpath->query("//*[@id='sale-price'] | //span[contains(@class, 'price')]")->item(0);
    if ($priceNode) {
        $price = trim($priceNode->nodeValue);
        if (preg_match('/([0-9,]+)/', $price, $pm)) {
            $priceVal = str_replace(',', '', $pm[1]);
            $product['price'] = number_format((float)$priceVal) . ' تومان';
        }
    }

    $colorNode = $xpath->query("//*[@id='coloe-des']")->item(0);
    if ($colorNode) $product['color'] = trim($colorNode->nodeValue);

    $chartNode = $xpath->query("//a[contains(@id, 'a-size-guide')]")->item(0);
    if ($chartNode) {
        $chartLink = $chartNode->getAttribute('data-link');
        if ($chartLink) $product['sizeChart'] = (strpos($chartLink, 'http') === 0) ? $chartLink : rtrim($linkBase, '/') . '/' . ltrim($chartLink, '/');
    }

    // Extract Description
    $descNode = $xpath->query("//div[contains(@class, 'product-description')] | //div[@id='dv-product-description'] | //div[contains(@class, 'content-description')]")->item(0);
    if ($descNode) {
        $product['description'] = trim($descNode->nodeValue);
    }

    // Extract Sizes array
    $sizeNodes = $xpath->query("//div[contains(@class, 'product-size')]//span | //select[@id='size-select']//option");
    $sizes = [];
    foreach ($sizeNodes as $sn) {
        $txt = trim($sn->nodeValue);
        if ($txt && strpos($txt, 'انتخاب') === false && $txt !== '...') {
            $sizes[] = $txt;
        }
    }
    if (!empty($sizes)) $product['sizes'] = array_values(array_unique($sizes));

    $imgNodes = $xpath->query("//div[contains(@class, 'owl-item')]//img | //figure[contains(@class, 'product-image')]//img | //div[@id='dv-product-gallery-main']//img");
    $gallery = [];
    foreach($imgNodes as $node) {
        $src = $node->getAttribute('data-zoom-image') ?: 
               ($node->getAttribute('data-src') ?: 
               ($node->getAttribute('src') ?: ''));
        
        if ($src && strpos($src, 'base64') === false) {
            if (strpos($src, 'http') !== 0) $src = rtrim($linkBase, '/') . '/' . ltrim($src, '/');
            $cleanSrc = preg_replace('/_(100|150|200|300|400|500|600)\.(jpg|png|jpeg)$/i', '.$2', $src);
            $gallery[] = $cleanSrc;
        }
    }
    
    $product['gallery'] = array_values(array_unique(array_filter($gallery)));
    if (empty($product['image']) && !empty($product['gallery'])) {
        $product['image'] = $product['gallery'][0];
    }
    
    return $product;
}

/**
 * بررسی اینکه آیا محصول فاقد تصویر معتبر است یا خیر
 * کالاهای بدون موجودی نیازی به تصویر ندارند و در اولویت استخراج قرار نمی‌گیرند
 */
function isProductMissingImage($p) {
    if (!is_array($p)) return false;
    // کالای بدون موجودی اولویت ناموجود دارد و نباید در صف بدون تصویر یا استخراج بیاید
    if (isProductOutOfStock($p)) return false;
    
    $img = trim((string)($p['image'] ?? ''));
    if (empty($img)) return true;
    $imgLower = mb_strtolower($img);
    if (strpos($imgLower, 'placeholder') !== false) return true;
    if (strpos($imgLower, 'placehold.co') !== false) return true;
    if (strpos($imgLower, 'placehold.it') !== false) return true;
    if (strpos($imgLower, 'no-img') !== false) return true;
    if (strpos($imgLower, 'no-image') !== false) return true;
    if (isset($p['has_image']) && $p['has_image'] === false) return true;
    if (($p['media_status'] ?? '') === 'missing') return true;
    return false;
}

/**
 * استخراج قدرتمند و هوشمند تصویر اصلی و تصاویر گالری از صفحه محصول وب‌سایت
 * با پشتیبانی کامل از وب‌پی (WebP)، عکس‌های باکیفیت و تصاویر ریسپانسیو
 */
function scrapeProductPageImagesAndGallery($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'main_image' => '', 'gallery' => [], 'msg' => 'آدرس صفحه معتبر نیست'];
    }

    $html = fetchUrl($url, 2, 20, false);
    if (!$html) {
        return ['success' => false, 'main_image' => '', 'gallery' => [], 'msg' => 'عدم دسترسی به صفحه محصول یا خطا در پاسخ سرور سایت'];
    }

    $parsedLink = parse_url($url);
    $scheme = $parsedLink['scheme'] ?? 'https';
    $host = $parsedLink['host'] ?? '';
    $linkBase = $scheme . '://' . $host;

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $foundImages = [];

    // 1. استخراج از اسکریپت‌های JSON-LD Schema (اطلاعات رسمی سئو و کالای سایت)
    $scriptNodes = $xpath->query("//script[@type='application/ld+json']");
    foreach ($scriptNodes as $sNode) {
        $jsonContent = trim($sNode->nodeValue);
        if (empty($jsonContent)) continue;
        $schemaData = json_decode($jsonContent, true);
        if (!$schemaData) continue;

        $schemas = isset($schemaData['@graph']) && is_array($schemaData['@graph']) ? $schemaData['@graph'] : [$schemaData];
        foreach ($schemas as $item) {
            if (!is_array($item)) continue;
            $type = $item['@type'] ?? '';
            if ($type === 'Product' || $type === 'IndividualProduct' || isset($item['image'])) {
                if (isset($item['image'])) {
                    if (is_string($item['image'])) {
                        $foundImages[] = $item['image'];
                    } elseif (is_array($item['image'])) {
                        foreach ($item['image'] as $imgVal) {
                            if (is_string($imgVal)) {
                                $foundImages[] = $imgVal;
                            } elseif (is_array($imgVal) && !empty($imgVal['url'])) {
                                $foundImages[] = $imgVal['url'];
                            }
                        }
                    }
                }
            }
        }
    }

    // 2. تگ‌های متای اوپن گراف و توییتر (OG Tags)
    $ogImages = $xpath->query("//meta[@property='og:image' or @property='og:image:secure_url' or @name='twitter:image']/@content");
    foreach ($ogImages as $og) {
        $val = trim($og->nodeValue);
        if (!empty($val)) {
            $foundImages[] = $val;
        }
    }

    // 3. المان‌های گالری و اسلایدرها (WooCommerce, Digikala, Tiam, Swiper, Owl, Slick, Lightbox, etc.)
    $imageNodes = $xpath->query("//div[contains(@class, 'product')]//img | //div[contains(@class, 'gallery')]//img | //div[contains(@class, 'swiper')]//img | //div[contains(@class, 'owl')]//img | //div[contains(@class, 'slick')]//img | //figure[contains(@class, 'product')]//img | //div[@id='dv-product-gallery-main']//img | //div[contains(@class, 'woocommerce-product-gallery')]//img | //div[contains(@class, 'woocommerce-product-gallery')]//a[@href] | //div[contains(@class, 'product-images')]//a[@href] | //a[contains(@class, 'woocommerce-product-gallery__image')] | //div[@id='gallery']//img | //ul[contains(@class, 'gallery')]//img");
    
    foreach ($imageNodes as $node) {
        $attributesToCheck = [
            'data-zoom-image',
            'data-large_image',
            'data-full-image',
            'data-high-res-src',
            'data-src',
            'data-lazy-src',
            'data-original',
            'data-lazy',
            'href',
            'src'
        ];

        foreach ($attributesToCheck as $attr) {
            $attrVal = trim($node->getAttribute($attr));
            if (!empty($attrVal)) {
                $foundImages[] = $attrVal;
            }
        }

        // بررسی صفت ریسپانسیو srcset
        $srcset = trim($node->getAttribute('srcset'));
        if (!empty($srcset)) {
            $srcSetParts = explode(',', $srcset);
            foreach ($srcSetParts as $part) {
                $urlPart = trim(preg_replace('/\s+\d+[wx]$/i', '', trim($part)));
                if (!empty($urlPart)) {
                    $foundImages[] = $urlPart;
                }
            }
        }
    }

    // 4. اسکن عبارات تصویری درون ساختار HTML برای اسکریپت‌ها یا ساختارهای داینامیک گالری
    if (count($foundImages) < 2) {
        if (preg_match_all('/https?:\\\/\\\/[^"\']+\.(?:webp|jpg|jpeg|png|avif)/i', $html, $rawMatches)) {
            foreach ($rawMatches[0] as $escapedUrl) {
                $foundImages[] = stripslashes($escapedUrl);
            }
        }
    }

    // نرمال‌سازی، فیلتر و اعتبارسنجی لینک‌ها
    $validImages = [];
    $blacklistedSubstrings = [
        'placeholder', 'placehold.co', 'placehold.it', 'no-img', 'no-image',
        'logo', 'icon', 'badge', 'banner-header', 'avatar', 'payment', 'trust',
        'enamad', 'samandehi', 'zarinpal', '.svg', 'blank.gif', '1x1', 'pixel'
    ];

    foreach ($foundImages as $rawUrl) {
        $cleanUrl = trim($rawUrl);
        if (empty($cleanUrl) || strpos($cleanUrl, 'data:image') === 0 || strpos($cleanUrl, 'javascript:') === 0) {
            continue;
        }

        // تبدیل لینک‌های پروتکل‌نسبی //cdn.com به https://cdn.com
        if (strpos($cleanUrl, '//') === 0) {
            $cleanUrl = $scheme . ':' . $cleanUrl;
        } elseif (strpos($cleanUrl, 'http') !== 0) {
            // لینک‌های نسبی به دامنه سایت مرجع
            $cleanUrl = rtrim($linkBase, '/') . '/' . ltrim($cleanUrl, '/');
        }

        // جایگزینی و تبدیل تامبنیل‌های کوچک به سایز اصلی باکیفیت
        // پشتیبانی کامل از وب‌پی (webp)، png، jpg، jpeg، avif
        $hiResUrl = preg_replace('/[_\-](\d{2,4}x\d{2,4}|\d{2,4})\.(webp|jpg|jpeg|png|avif)(\?.*)?$/i', '.$2$3', $cleanUrl);

        $urlLower = mb_strtolower($hiResUrl);

        // فیلتر کردن لوگوها و تصاویر ساختاری
        $isBlacklisted = false;
        foreach ($blacklistedSubstrings as $badWord) {
            if (strpos($urlLower, $badWord) !== false) {
                $isBlacklisted = true;
                break;
            }
        }
        if ($isBlacklisted) continue;

        // بررسی پسوند یا فرمت مجاز تصویر
        if (preg_match('/\.(webp|jpg|jpeg|png|avif)($|\?)/i', $hiResUrl)) {
            $validImages[] = $hiResUrl;
        } elseif (strpos($hiResUrl, '/uploads/') !== false || strpos($hiResUrl, '/media/') !== false || strpos($hiResUrl, '/products/') !== false || strpos($hiResUrl, '/images/') !== false) {
            $validImages[] = $hiResUrl;
        }
    }

    $validImages = array_values(array_unique($validImages));

    if (empty($validImages)) {
        return ['success' => false, 'main_image' => '', 'gallery' => [], 'msg' => 'هیچ تصویر معتبری در صفحه محصول یافت نشد'];
    }

    $mainImage = $validImages[0];
    return [
        'success' => true,
        'main_image' => $mainImage,
        'gallery' => $validImages,
        'count' => count($validImages),
        'is_webp' => (bool)preg_match('/\.webp($|\?)/i', $mainImage)
    ];
}

/**
 * اعمال تصاویر استخراج‌شده روی رکورد محصول
 */
function applyExtractedImagesToProduct(&$p, $mainImage, $gallery) {
    if (empty($mainImage)) return false;
    
    $p['image'] = $mainImage;
    $p['gallery'] = is_array($gallery) ? array_values(array_unique($gallery)) : [$mainImage];
    
    // ترکیب و نگهداری تصاویر دستی یا اینستاگرام
    $existingImagesList = $p['images_list'] ?? [];
    if (is_string($existingImagesList)) {
        $existingImagesList = json_decode($existingImagesList, true) ?: [];
    }
    if (!is_array($existingImagesList)) $existingImagesList = [];
    
    $preservedManual = [];
    foreach ($existingImagesList as $img) {
        if (is_array($img) && isset($img['source']) && ($img['source'] === 'manual' || $img['source'] === 'instagram')) {
            $preservedManual[] = $img;
        }
    }
    
    $newSiteImages = [];
    foreach ($p['gallery'] as $gUrl) {
        $newSiteImages[] = [
            'url' => $gUrl,
            'source' => 'site',
            'created_at' => time()
        ];
    }
    
    $merged = array_merge($preservedManual, $newSiteImages);
    $seenUrls = [];
    $deduped = [];
    foreach ($merged as $item) {
        $u = is_array($item) ? ($item['url'] ?? '') : (string)$item;
        if (!empty($u) && !isset($seenUrls[$u])) {
            $seenUrls[$u] = true;
            $deduped[] = is_array($item) ? $item : ['url' => $u, 'source' => 'site', 'created_at' => time()];
        }
    }
    
    $p['images_list'] = $deduped;
    $p['has_image'] = true;
    $p['media_status'] = 'ok';
    $p['extractedAt'] = time();
    return true;
}

// ================================
// ارسال به پلتفرم‌ها (با پشتیبانی کامل از بله)
// ================================

function getUtmLink($product, $platform) {
    if (empty($product['link'])) return '#';
    $baseUrl = $product['link'];
    $source = !empty($product['utm_source']) ? $product['utm_source'] : $platform;
    $medium = !empty($product['utm_medium']) ? $product['utm_medium'] : 'social';
    $campaign = !empty($product['utm_campaign']) ? $product['utm_campaign'] : 'flow_automation';
    
    $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    $utm = "utm_source=" . urlencode($source) . "&utm_medium=" . urlencode($medium) . "&utm_campaign=" . urlencode($campaign);
    return $baseUrl . $sep . $utm;
}

/**
 * اعمال تنظیمات پروکسی روی کوئری curl جهت دور زدن فیلترینگ
 */
/**
 * اعمال تنظیمات پروکسی روی کوئری curl جهت دور زدن فیلترینگ با روش‌های مختلف
 */
function applyCurlProxySettings($ch, $proxy) {
    if (!$proxy) {
        return;
    }
    
    $method = $proxy['bypass_method'] ?? '';
    
    // در صورت خالی بودن روش یا نسخه‌های قدیمی سیستم، روش پروکسی ثابت را پیش‌فرض قرار می‌دهیم
    if (empty($method)) {
        if (!empty($proxy['custom_api_url'])) {
            return;
        }
        $method = 'fixed_proxy';
    }
    
    // اگر روش مستقیم یا بدون پروکسی انتخاب شده باشد
    if ($method === 'none') {
        return;
    }
    
    // اگر فقط آدرس سفارشی انتخاب شده باشد، نیاز به پروکسی جانبی روی ریکوئست نیست
    if ($method === 'custom_api') {
        return;
    }
    
    $activeProxyServer = '';
    $activeProxyType = 'socks5_hostname';
    $activeProxyUser = '';
    $activeProxyPass = '';
    
    if ($method === 'fixed_proxy') {
        $activeProxyServer = $proxy['server'] ?? '';
        $activeProxyType = $proxy['type'] ?? 'socks5_hostname';
        $activeProxyUser = $proxy['user'] ?? '';
        $activeProxyPass = $proxy['pass'] ?? '';
    } elseif ($method === 'v2ray_sub') {
        $bestNode = $proxy['v2ray_best_node'] ?? null;
        if ($bestNode && !empty($bestNode['host']) && !empty($bestNode['port'])) {
            $activeProxyServer = $bestNode['host'] . ':' . $bestNode['port'];
            $proto = strtolower($bestNode['protocol'] ?? 'socks5');
            $activeProxyType = ($proto === 'http' || $proto === 'https') ? 'http' : 'socks5_hostname';
            $activeProxyUser = $bestNode['user'] ?? '';
            $activeProxyPass = $bestNode['pass'] ?? '';
        }
    } elseif ($method === 'hybrid') {
        // هیبرید: اولویت گره فعال V2ray و در غیر این صورت گره پروکسی ثابت
        $bestNode = $proxy['v2ray_best_node'] ?? null;
        if ($bestNode && !empty($bestNode['host']) && !empty($bestNode['port'])) {
            $activeProxyServer = $bestNode['host'] . ':' . $bestNode['port'];
            $proto = strtolower($bestNode['protocol'] ?? 'socks5');
            $activeProxyType = ($proto === 'http' || $proto === 'https') ? 'http' : 'socks5_hostname';
            $activeProxyUser = $bestNode['user'] ?? '';
            $activeProxyPass = $bestNode['pass'] ?? '';
        } else {
            $activeProxyServer = $proxy['server'] ?? '';
            $activeProxyType = $proxy['type'] ?? 'socks5_hostname';
            $activeProxyUser = $proxy['user'] ?? '';
            $activeProxyPass = $proxy['pass'] ?? '';
        }
    }
    
    if (empty($activeProxyServer)) {
        return;
    }
    
    // اعمال سرور پروکسی
    curl_setopt($ch, CURLOPT_PROXY, $activeProxyServer);
    
    // اعمال اطلاعات احراز هویت در صورت وجود
    if (!empty($activeProxyUser) && !empty($activeProxyPass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $activeProxyUser . ':' . $activeProxyPass);
    }
    
    // اعمال نوع پروکسی
    $type = strtolower($activeProxyType);
    if ($type === 'socks5') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    } elseif ($type === 'socks5_hostname') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
}

/**
 * ارسال به پلتفرم بله (مستندات رسمی)
 * @param string $token توکن بازو
 * @param string|int $chatId شناسه چت
 * @param string $message متن پیام
 * @param string|null $photoUrl آدرس تصویر (اختیاری)
 * @param array|null $proxy تنظیمات پروکسی
 * @return bool|string true در صورت موفقیت، یا پیام خطا
 */
function sendToBale($token, $chatId, $message, $photoUrl = null, $proxy = null) {
    $baseUrl = "https://tapi.bale.ai/bot{$token}/";
    
    $send = function($method, $params) use ($baseUrl, $proxy) {
        $ch = curl_init($baseUrl . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $hasFile = false;
        foreach ($params as $v) {
            if ($v instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }
        
        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        applyCurlProxySettings($ch, $proxy);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        addDebugLog('BALE_RES', "Method: $method | Code: $httpCode | Resp: $response");
        
        return ['code' => $httpCode, 'body' => $response, 'error' => $error];
    };

    if ($photoUrl) {
        $photoParam = $photoUrl;
        $cleanPath = $photoUrl;
        if (strpos($cleanPath, 'http') === 0) {
            $parsed = parse_url($cleanPath);
            $cleanPath = $parsed['path'] ?? $cleanPath;
        }
        $cleanPath = ltrim($cleanPath, '/\\');
        
        $testPaths = [
            __DIR__ . '/../' . $cleanPath,
            UPLOADS_DIR . basename($cleanPath),
            __DIR__ . '/../uploads/' . basename($cleanPath),
        ];
        
        $localFile = null;
        foreach ($testPaths as $tp) {
            if (!empty($tp) && @file_exists($tp) && @is_file($tp)) {
                $localFile = $tp;
                break;
            }
        }
        
        if ($localFile) {
            $photoParam = new CURLFile($localFile);
        }

        $params = [
            'chat_id' => $chatId,
            'photo' => $photoParam,
            'caption' => $message
        ];
        
        // Bale is picky about Markdown in captions for some bot versions
        if (strpos($message, '[') !== false && strpos($message, '](') !== false) {
            $params['parse_mode'] = 'Markdown';
        }
        
        $res = $send('sendPhoto', $params);
        
        $data = json_decode($res['body'], true);
        if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
            return true;
        }
        
        // If Markdown failed, try plain text
        if (isset($params['parse_mode'])) {
            unset($params['parse_mode']);
            $params['caption'] = strip_tags(str_replace(['*', '_', '`', '[', '](', ')'], '', $message));
            $res = $send('sendPhoto', $params);
            $data = json_decode($res['body'], true);
            if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
                return true;
            }
        }
        
        addDebugLog('BALE_ERR', "sendPhoto failed, falling back. Response: " . $res['body']);
        
        // Fallback to text
        $msgUrlStr = ($photoParam instanceof CURLFile) ? getFullUrl($photoUrl) : $photoUrl;
        $message .= "\n\n🖼 تصویر محصول:\n" . $msgUrlStr;
    }

    $res = $send('sendMessage', [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ]);

    $data = json_decode($res['body'], true);
    if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
        return true;
    }

    // Try without Markdown
    $res = $send('sendMessage', [
        'chat_id' => $chatId,
        'text' => strip_tags(str_replace(['*', '_', '`', '[', '](', ')'], '', $message))
    ]);

    $data = json_decode($res['body'], true);
    if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
        return true;
    }
    
    return isset($data['description']) ? $data['description'] : ($res['error'] ?: "HTTP Error " . $res['code']);
}

function sendToTelegram($token, $chatId, $message, $photoUrl = null, $proxy = null, $schedule_date = null) {
    $baseUrl = "https://api.telegram.org/bot{$token}/";
    $method = $proxy['bypass_method'] ?? '';
    if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
        $baseUrl = rtrim($proxy['custom_api_url'], '/') . "/bot{$token}/";
    }

    $send = function($method, $params) use ($baseUrl, $proxy) {
        $ch = curl_init($baseUrl . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $hasFile = false;
        foreach ($params as $v) {
            if ($v instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }
        
        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        applyCurlProxySettings($ch, $proxy);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        addDebugLog('TG_RES', "Method: $method | Code: $httpCode | Resp: $response");
        
        return ['code' => $httpCode, 'body' => $response, 'error' => $error];
    };

    if ($photoUrl) {
        $photoParam = $photoUrl;
        $cleanPath = $photoUrl;
        if (strpos($cleanPath, 'http') === 0) {
            $parsed = parse_url($cleanPath);
            $cleanPath = $parsed['path'] ?? $cleanPath;
        }
        $cleanPath = ltrim($cleanPath, '/\\');
        
        $testPaths = [
            __DIR__ . '/../' . $cleanPath,
            UPLOADS_DIR . basename($cleanPath),
            __DIR__ . '/../uploads/' . basename($cleanPath),
        ];
        
        $localFile = null;
        foreach ($testPaths as $tp) {
            if (!empty($tp) && @file_exists($tp) && @is_file($tp)) {
                $localFile = $tp;
                break;
            }
        }
        
        if ($localFile) {
            $photoParam = new CURLFile($localFile);
        }

        $photoParams = [
            'chat_id' => $chatId,
            'photo' => $photoParam,
            'caption' => $message,
            'parse_mode' => 'Markdown'
        ];
        if (!empty($schedule_date)) {
            $photoParams['schedule_date'] = (int)$schedule_date;
        }

        $res = $send('sendPhoto', $photoParams);
        
        $data = json_decode($res['body'], true);
        if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
            return true;
        }
        
        // Fallback
        $msgUrlStr = ($photoParam instanceof CURLFile) ? getFullUrl($photoUrl) : $photoUrl;
        $message .= "\n\n🖼 Image: " . $msgUrlStr;
    }

    $msgParams = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    if (!empty($schedule_date)) {
        $msgParams['schedule_date'] = (int)$schedule_date;
    }

    $res = $send('sendMessage', $msgParams);

    $data = json_decode($res['body'], true);
    if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
        return true;
    }
    
    return isset($data['description']) ? $data['description'] : ($res['error'] ?: "HTTP Error " . $res['code']);
}

function sendToRubika($token, $chatId, $message, $photoUrl = null, $proxy = null) {
    if (empty($token) || empty($chatId)) {
        return "توکن یا شناسه چت روبیکا تنظیم نشده است";
    }
    
    // Attempt multiple endpoints (official API and Iranian proxy bridge) sequentially
    $endpoints = [
        "https://botapi.rubika.ir/v1/bot{$token}/sendMessage",
        "https://botapi.rubika.ir/v2/bot{$token}/sendMessage",
        "https://botapi.rubika.ir/v3/bot{$token}/sendMessage",
        "https://messengerg.urara.me/v1/bot{$token}/sendMessage",
        "https://botapi.rubika.ir/v3/{$token}/sendMessage",
        "https://messengerg.urara.me/v3/{$token}/sendMessage"
    ];
    
    $lastErr = '';
    $lastHttpCode = 0;
    $lastResponse = '';
    
    foreach ($endpoints as $url) {
        $params = [
            'chat_id' => $chatId,
            'text' => $message . ($photoUrl ? "\n\n[عکس]($photoUrl)" : ''),
            'parse_mode' => 'Markdown'
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        applyCurlProxySettings($ch, $proxy);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $lastErr = $err;
        $lastHttpCode = $httpCode;
        $lastResponse = $response;
        
        if (empty($err)) {
            $data = json_decode($response, true);
            if ($httpCode === 200 && isset($data['ok']) && $data['ok'] === true) {
                return true;
            }
        }
    }
    
    // If all gateways failed, parse and report details of the last attempt
    if ($lastErr) {
        return "خطای ارتباط با روبیکا: " . $lastErr;
    }
    
    $data = json_decode($lastResponse, true);
    if ($lastHttpCode === 200) {
        if (isset($data['ok']) && $data['ok'] === true) {
            return true;
        }
        return $data['description'] ?? "پاسخ ناموفق از روبیکا (ok == false)";
    }
    
    if ($data && isset($data['description'])) {
        return "خطای روبیکا (کد $lastHttpCode): " . $data['description'];
    }
    
    return "خطای وب‌سرویس روبیکا با کد وضعیت HTTP " . $lastHttpCode;
}

function sendToInstagram($token, $chatId, $message, $photoUrl = null, $proxy = null, $schedule_date = null) {
    if (empty($token) || empty($chatId)) {
        return "تنظیمات توکن یا شناسه اکانت اینستاگرام ناقص است";
    }

    if (class_exists('InstagramAdapter')) {
        $adapter = new InstagramAdapter();
        $contentItem = [
            'caption' => $message,
            'instagram_caption' => $message,
            'media_url' => $photoUrl,
            'mediaUrl' => $photoUrl,
            'post_type' => (strpos((string)$photoUrl, ',') !== false) ? 'carousel' : (preg_match('/\.(mp4|mov|avi)$/i', (string)$photoUrl) ? 'reel' : 'image'),
            'scheduledAt' => $schedule_date
        ];
        $settings = [
            'connections' => [
                'instagram' => [
                    'access_token' => $token,
                    'token' => $token,
                    'instagram_account_id' => $chatId,
                    'chatId' => $chatId,
                    'proxy' => $proxy
                ]
            ]
        ];
        $res = $adapter->publish($contentItem, $settings);
        if ($res['ok']) {
            return true;
        }
        return $res['error'] ?? 'خطا در انتشار اینستاگرام';
    }
    
    // Instagram Graph API requires an image URL
    $img = $photoUrl;
    if (empty($img)) {
        $img = "https://placehold.co/600x600?text=Maarksho+Flow+Instagram+Test";
    }
    
    $api_host = "https://graph.facebook.com";
    $method = $proxy['bypass_method'] ?? '';
    if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
        $api_host = rtrim($proxy['custom_api_url'], '/');
    }
    
    // Step 1: Create Container
    $container_url = "{$api_host}/v19.0/{$chatId}/media";
    $payload = [
        'image_url' => $img,
        'caption' => $message,
        'access_token' => $token
    ];
    if (!empty($schedule_date)) {
        $payload['scheduled_publish_time'] = (int)$schedule_date;
    }
    
    $ch = curl_init($container_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    applyCurlProxySettings($ch, $proxy);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $res_data = json_decode($response, true);
    if ($httpCode !== 200 || !isset($res_data['id'])) {
        $err_msg = $res_data['error']['message'] ?? 'خطای ناشناخته در فیسبوک';
        return "خطا در ایجاد رسانه تصویر: " . $err_msg . " (HTTP " . $httpCode . ")";
    }
    
    $container_id = $res_data['id'];
    
    if (!empty($schedule_date)) {
        return true;
    }
    
    // Wait a brief moment for FB systems
    sleep(2);
    
    // Step 2: Publish Container
    $publish_url = "{$api_host}/v19.0/{$chatId}/media_publish";
    $publish_payload = [
        'creation_id' => $container_id,
        'access_token' => $token
    ];
    
    $ch = curl_init($publish_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publish_payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    applyCurlProxySettings($ch, $proxy);
    
    $response = curl_exec($ch);
    $httpCodePub = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $res_data_pub = json_decode($response, true);
    if ($httpCodePub !== 200 || !isset($res_data_pub['id'])) {
         $err_msg = $res_data_pub['error']['message'] ?? 'خطای ناشناخته در فیسبوک';
         return "خطا در انتشار: " . $err_msg . " (HTTP " . $httpCodePub . ")";
    }
    
    return true;
}

function getProductColorVariants($product) {
    if (empty($product['name'])) {
        return !empty($product['color']) ? [$product['color']] : [];
    }
    
    $project = getCurrentProject();
    if (!$project || empty($project['products'])) {
        return !empty($product['color']) ? [$product['color']] : [];
    }
    
    $pNameNorm = trim(preg_replace('/\s+/', ' ', mb_strtolower($product['name'])));
    
    $colors = [];
    foreach ($project['products'] as $p) {
        if (($p['status'] ?? 'active') === 'rejected') continue;
        if (empty($p['name'])) continue;
        
        $sibNameNorm = trim(preg_replace('/\s+/', ' ', mb_strtolower($p['name'])));
        if ($sibNameNorm === $pNameNorm) {
            $c = !empty($p['color']) ? trim($p['color']) : '';
            if ($c) {
                $colors[] = $c;
            }
        }
    }
    
    if (empty($colors) && !empty($product['color'])) {
        $colors[] = $product['color'];
    }
    
    return array_values(array_unique($colors));
}

function compileTemplatePHP($template, $product, $platform, $finalLink, $buyLink) {
    $t = $template;
    $cleanName = str_replace(['*', '_', '`', '['], '', $product['name'] ?? '');
    
    $descText = !empty($product['description']) ? strip_tags($product['description']) : "";
    
    $sizesArr = is_array($product['sizes'] ?? null) ? $product['sizes'] : [];
    $sizes = !empty($sizesArr) ? implode(', ', $sizesArr) : "";
    
    $colorsArr = getProductColorVariants($product);
    $color = !empty($colorsArr) ? implode(' / ', $colorsArr) : (!empty($product['color']) ? $product['color'] : "");

    $useMarkdown = ($platform === 'telegram' || $platform === 'bale' || $platform === 'rubika');
    $prettyLink = $useMarkdown ? $buyLink : $finalLink;

    $t = str_replace('{NAME}', $cleanName, $t);
    $t = str_replace('{PRICE}', $product['price'] ?? '---', $t);
    $t = str_replace('{COLOR}', $color ? $color : '---', $t);
    $t = str_replace('{SIZES}', $sizes ? $sizes : '---', $t);
    $t = str_replace('{LINK}', $prettyLink, $t);
    $t = str_replace('{DESCRIPTION}', $descText ? $descText : '---', $t);
    $t = str_replace('{SKU}', $product['sku'] ?? '---', $t);

    return $t;
}

function getPlatformPayloadForBrowserRelay($product, $platform, $settings, $customCaption = '', $schedule_date = null, $custom_media_url = null) {
    $conn = $settings['connections'][$platform] ?? [];
    if (empty($conn['token']) || empty($conn['chatId'])) {
        return null;
    }
    
    // Link structure logic
    $finalLink = getUtmLink($product, $platform);
    $defaultTemplate = ($platform === 'bale' || $platform === 'telegram' || $platform === 'rubika') ? "[خرید آنلاین]({LINK})" : "{LINK}";
    $linkTemplate = $settings['platform_link_templates'][$platform] ?? $defaultTemplate;
    $buyLink = str_replace('{LINK}', $finalLink, $linkTemplate);
    
    $useMarkdown = ($platform === 'telegram' || $platform === 'bale' || $platform === 'rubika');
    $cleanName = str_replace(['*', '_', '`', '['], '', $product['name'] ?? '');
    
    $descText = !empty($product['description']) ? strip_tags($product['description']) : "";
    $desc = $descText ? "\n\n" . mb_substr($descText, 0, 400) : "";
    
    $sizesArr = is_array($product['sizes'] ?? null) ? $product['sizes'] : [];
    $sizes = !empty($sizesArr) ? "\n📐 سایزها: " . implode(', ', $sizesArr) : "";
    
    $colorsArr = getProductColorVariants($product);
    $colorVal = !empty($colorsArr) ? implode(' / ', $colorsArr) : (!empty($product['color']) ? $product['color'] : "");
    $color = !empty($colorVal) ? "\n🎨 رنگ‌بندی: " . $colorVal : "";

    if (!empty($customCaption)) {
        $caption = $customCaption;
    } else {
        $template = $settings['platform_captions'][$platform] ?? '';
        if (empty($template)) {
            if ($useMarkdown) {
                $caption = "🌟 *" . $cleanName . "*\n💰 قیمت: " . ($product['price'] ?? '---') . $color . $sizes . $desc . "\n🔗 " . $buyLink;
            } else {
                $caption = "🌟 " . $cleanName . "\n💰 قیمت: " . ($product['price'] ?? '---') . $color . $sizes . $desc . "\n🔗 " . $finalLink;
            }
        } else {
            $caption = compileTemplatePHP($template, $product, $platform, $finalLink, $buyLink);
        }
    }
    
    if (mb_strlen($caption) > 1020) {
        $caption = mb_substr($caption, 0, 1000) . "...";
    }
    
    $publish_mode = 'grouped';
    $custom_images = [];
    if (!empty($custom_media_url)) {
        $clean_media = $custom_media_url;
        if (strpos($custom_media_url, '|') !== false) {
            list($clean_media, $publish_mode) = explode('|', $custom_media_url, 2);
        }
        $custom_images = array_map('trim', explode(',', $clean_media));
        $image = !empty($custom_images) ? $custom_images[0] : null;
    } else {
        $image = getPrioritizedProductImage($product);
        if ($image) {
            $custom_images = [$image];
        }
    }
    
    if ($image && strpos($image, 'placehold') === false) {
        $image = getFullUrl($image);
    } else {
        $image = null;
    }

    $compiled_images = [];
    foreach ($custom_images as $img_path) {
        if ($img_path && strpos($img_path, 'placehold') === false) {
            $compiled_images[] = getFullUrl($img_path);
        }
    }
    
    return [
        'token' => $conn['token'],
        'chatId' => $conn['chatId'],
        'customApiUrl' => $conn['proxy']['custom_api_url'] ?? '',
        'caption' => $caption,
        'image' => $image ? getFullUrl($image) : null,
        'images' => $compiled_images,
        'publish_mode' => $publish_mode,
        'schedule_date' => $schedule_date ? (int)$schedule_date : null
    ];
}

/**
 * Helper to dispatch single post to specified platform
 */
function sendToSinglePlatform($platform, $conn, $caption, $image, $proxy, $schedule_date) {
    switch ($platform) {
        case 'telegram':
            return sendToTelegram($conn['token'], $conn['chatId'], $caption, $image ?: null, $proxy, $schedule_date);
        case 'bale':
            return sendToBale($conn['token'], $conn['chatId'], $caption, $image ?: null, $proxy);
        case 'rubika':
            return sendToRubika($conn['token'], $conn['chatId'], $caption, $image ?: null, $proxy);
        case 'instagram':
            return sendToInstagram($conn['token'], $conn['chatId'], $caption, $image ?: null, $proxy, $schedule_date);
        default:
            return false;
    }
}

/**
 * Handle native media group album dispatch for Telegram
 */
function sendMediaGroupToTelegram($token, $chatId, $message, $compiled_images, $proxy = null, $schedule_date = null) {
    $baseUrl = "https://api.telegram.org/bot{$token}/";
    $method = $proxy['bypass_method'] ?? '';
    if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
        $baseUrl = rtrim($proxy['custom_api_url'], '/') . "/bot{$token}/";
    }

    $send = function($method, $params) use ($baseUrl, $proxy) {
        $ch = curl_init($baseUrl . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_TIMEOUT, 35);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        applyCurlProxySettings($ch, $proxy);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        addDebugLog('TG_RES_MG', "Method: $method | Code: $httpCode | Resp: $response");
        return ['code' => $httpCode, 'body' => $response, 'error' => $error];
    };

    $media = [];
    $params = [
        'chat_id' => $chatId
    ];
    if (!empty($schedule_date)) {
        $params['schedule_date'] = (int)$schedule_date;
    }

    foreach ($compiled_images as $idx => $photoUrl) {
        $cleanPath = $photoUrl;
        if (strpos($cleanPath, 'http') === 0) {
            $parsed = parse_url($cleanPath);
            $cleanPath = $parsed['path'] ?? $cleanPath;
        }
        $cleanPath = ltrim($cleanPath, '/\\');
        
        $testPaths = [
            __DIR__ . '/../' . $cleanPath,
            UPLOADS_DIR . basename($cleanPath),
            __DIR__ . '/../uploads/' . basename($cleanPath),
        ];
        
        $localFile = null;
        foreach ($testPaths as $tp) {
            if (!empty($tp) && @file_exists($tp) && @is_file($tp)) {
                $localFile = $tp;
                break;
            }
        }

        $attachName = "photo_" . $idx;
        $mediaItem = [
            'type' => 'photo',
            'media' => "attach://" . $attachName
        ];

        if ($idx === 0) {
            $mediaItem['caption'] = $message;
            $mediaItem['parse_mode'] = 'Markdown';
        }

        $media[] = $mediaItem;

        if ($localFile) {
            $params[$attachName] = new CURLFile($localFile);
        } else {
            $mediaItem['media'] = $photoUrl;
            $media[$idx] = $mediaItem;
        }
    }

    $params['media'] = json_encode($media);

    $res = $send('sendMediaGroup', $params);
    $data = json_decode($res['body'], true);
    if ($res['code'] === 200 && isset($data['ok']) && $data['ok'] === true) {
        return true;
    }
    
    return is_string($res['error']) && !empty($res['error']) ? $res['error'] : ($data['description'] ?? 'Media group send failed');
}

/**
 * تابع اصلی ارسال به هر پلتفرم
 * @param array $product اطلاعات محصول
 * @param string $platform telegram, bale, rubika, instagram
 * @param array $settings تنظیمات پروژه
 * @param string $customCaption کپشن سفارشی
 * @param int|null $schedule_date یونیکس تایم زمان‌بندی انتشار پیش‌فرض
 * @param string|null $custom_media_url تصاویر انتخابی سفارشی به همراه نوع انتشار (مثلا image1,image2|grouped)
 * @return bool|string
 */
function sendToPlatform($product, $platform, $settings, $customCaption = '', $schedule_date = null, $custom_media_url = null) {
    $conn = $settings['connections'][$platform] ?? [];
    
    // Check if NovinHub method is selected
    $method = 'none';
    $accountId = '';
    if ($platform === 'telegram' || $platform === 'instagram') {
        $method = $conn['proxy']['bypass_method'] ?? 'none';
        $accountId = $conn['proxy']['novinhub_account_id'] ?? '';
    } else {
        $method = $conn['bypass_method'] ?? 'none';
        $accountId = $conn['novinhub_account_id'] ?? '';
    }
    
    if ($method === 'chatgpt') {
        require_once __DIR__.'/ai/Social.php';
        return AiSocial::legacy($product,$platform,$settings,$customCaption,$custom_media_url?:($product['social_image']??$product['image']??''),$schedule_date);
    }
    if ($method !== 'novinhub') {
        if (empty($conn['token']) || empty($conn['chatId'])) {
            addDebugLog('SEND', "Missing token/chatId for $platform");
            return false;
        }
    } else {
        if (empty($settings['novinhub_token'])) {
            addDebugLog('SEND', "Missing novinhub_token for NovinHub connection on $platform");
            return "توکن سراسری نوین هاب تنظیم نشده است.";
        }
        if (empty($accountId)) {
            addDebugLog('SEND', "Missing novinhub_account_id for NovinHub connection on $platform");
            return "شناسه اکانت نوین هاب تنظیم نشده است.";
        }
    }
    
    // Link structure logic
    $finalLink = getUtmLink($product, $platform);
    $defaultTemplate = ($platform === 'bale' || $platform === 'telegram' || $platform === 'rubika') ? "[خرید آنلاین]({LINK})" : "{LINK}";
    $linkTemplate = $settings['platform_link_templates'][$platform] ?? $defaultTemplate;
    $buyLink = str_replace('{LINK}', $finalLink, $linkTemplate);
    
    $useMarkdown = ($platform === 'telegram' || $platform === 'bale' || $platform === 'rubika');
    $cleanName = str_replace(['*', '_', '`', '['], '', $product['name'] ?? '');
    
    $descText = !empty($product['description']) ? strip_tags($product['description']) : "";
    $desc = $descText ? "\n\n" . mb_substr($descText, 0, 400) : "";
    
    $sizesArr = is_array($product['sizes'] ?? null) ? $product['sizes'] : [];
    $sizes = !empty($sizesArr) ? "\n📐 سایزها: " . implode(', ', $sizesArr) : "";
    
    $colorsArr = getProductColorVariants($product);
    $colorVal = !empty($colorsArr) ? implode(' / ', $colorsArr) : (!empty($product['color']) ? $product['color'] : "");
    $color = !empty($colorVal) ? "\n🎨 رنگ‌بندی: " . $colorVal : "";

    if (!empty($customCaption)) {
        $caption = $customCaption;
    } else {
        $template = $settings['platform_captions'][$platform] ?? '';
        if (empty($template)) {
            if ($useMarkdown) {
                $caption = "🌟 *" . $cleanName . "*\n💰 قیمت: " . ($product['price'] ?? '---') . $color . $sizes . $desc . "\n🔗 " . $buyLink;
            } else {
                $caption = "🌟 " . $cleanName . "\n💰 قیمت: " . ($product['price'] ?? '---') . $color . $sizes . $desc . "\n🔗 " . $finalLink;
            }
        } else {
            $caption = compileTemplatePHP($template, $product, $platform, $finalLink, $buyLink);
        }
    }
    
    if (mb_strlen($caption) > 1020) {
        $caption = mb_substr($caption, 0, 1000) . "...";
    }
    
    $publish_mode = 'grouped';
    $custom_images = [];
    if (!empty($custom_media_url)) {
        $clean_media = $custom_media_url;
        if (strpos($custom_media_url, '|') !== false) {
            list($clean_media, $publish_mode) = explode('|', $custom_media_url, 2);
        }
        $custom_images = array_map('trim', explode(',', $clean_media));
        $image = !empty($custom_images) ? $custom_images[0] : null;
    } else {
        $image = getPrioritizedProductImage($product);
        if ($image) {
            $custom_images = [$image];
        }
    }
    
    if ($image && strpos($image, 'placehold') === false) {
        $image = getFullUrl($image);
    } else {
        $image = null;
    }
    
    $compiled_images = [];
    foreach ($custom_images as $img_path) {
        if ($img_path && strpos($img_path, 'placehold') === false) {
            $compiled_images[] = getFullUrl($img_path);
        }
    }
    
    if ($method === 'novinhub') {
        return sendToNovinhub($settings['novinhub_token'] ?? '', $accountId, $caption, $compiled_images, $schedule_date);
    }
    
    $proxy = $conn['proxy'] ?? null;
    
    if ($publish_mode === 'separate' && count($compiled_images) > 1) {
        $overall_success = true;
        $errors = [];
        foreach ($compiled_images as $single_image) {
            $res = sendToSinglePlatform($platform, $conn, $caption, $single_image, $proxy, $schedule_date);
            if ($res !== true) {
                $overall_success = false;
                $errors[] = is_string($res) ? $res : "خطا در ارسال یکی از تصاویر به " . $platform;
            }
        }
        return $overall_success ? true : implode(' | ', array_unique($errors));
    }

    if ($publish_mode === 'grouped' && count($compiled_images) > 1) {
        if ($platform === 'telegram') {
            return sendMediaGroupToTelegram($conn['token'], $conn['chatId'], $caption, $compiled_images, $proxy, $schedule_date);
        }
        
        $first_image = $compiled_images[0];
        $res = sendToSinglePlatform($platform, $conn, $caption, $first_image, $proxy, $schedule_date);
        if ($res === true) {
            for ($i = 1; $i < count($compiled_images); $i++) {
                sendToSinglePlatform($platform, $conn, '', $compiled_images[$i], $proxy, $schedule_date);
            }
        }
        return $res;
    }
    
    return sendToSinglePlatform($platform, $conn, $caption, $image, $proxy, $schedule_date);
}

function uploadFileToNovinhub($token, $imageUrl) {
    if (empty($imageUrl)) {
        return null;
    }

    $cleanPath = $imageUrl;
    if (strpos($cleanPath, 'http') === 0) {
        $parsed = parse_url($cleanPath);
        $cleanPath = $parsed['path'] ?? $cleanPath;
    }
    $cleanPath = ltrim($cleanPath, '/\\');

    $testPaths = [
        __DIR__ . '/../' . $cleanPath,
        __DIR__ . '/../' . basename($cleanPath),
    ];
    if (defined('UPLOADS_DIR')) {
        $testPaths[] = UPLOADS_DIR . basename($cleanPath);
    }
    $testPaths[] = __DIR__ . '/../uploads/' . basename($cleanPath);

    $localFile = null;
    foreach ($testPaths as $tp) {
        if (!empty($tp) && @file_exists($tp) && @is_file($tp)) {
            $localFile = $tp;
            break;
        }
    }

    $tempFile = null;
    if (!$localFile) {
        $imgData = @file_get_contents($imageUrl);
        if ($imgData) {
            $tempFile = tempnam(sys_get_temp_dir(), 'nh_img_');
            $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
            if ($ext) {
                @unlink($tempFile);
                $tempFile .= '.' . $ext;
            }
            @file_put_contents($tempFile, $imgData);
            $localFile = $tempFile;
        }
    }

    if (!$localFile || !@file_exists($localFile)) {
        addDebugLog('NOVINHUB_ERR', "Local file not found for upload: " . ($localFile ?: $imageUrl));
        return null;
    }

    // Upload using multipart/form-data
    $ch = curl_init("https://api.novinhub.com/token/v2/file");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $curlFile = new CURLFile($localFile);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file' => $curlFile
    ]);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($tempFile && @file_exists($tempFile)) {
        @unlink($tempFile);
    }

    addDebugLog('NOVINHUB_FILE_RES', "Code: $httpCode | Resp: $response | Error: $error");

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        if (isset($data['id'])) {
            return $data['id'];
        }
        if (isset($data['file_id'])) {
            return $data['file_id'];
        }
        if (isset($data['file']['id'])) {
            return $data['file']['id'];
        }
        if (isset($data['data']['id'])) {
            return $data['data']['id'];
        }
        if (isset($data['data']['file_id'])) {
            return $data['data']['file_id'];
        }
    }

    return null;
}

function sendToNovinhub($token, $accountId, $caption, $compiled_images = [], $schedule_date = null) {
    if (empty($token)) {
        return "توکن نوین هاب تنظیم نشده است.";
    }
    if (empty($accountId)) {
        return "شناسه اکانت نوین هاب تنظیم نشده است.";
    }
    
    addDebugLog('NOVINHUB', "Sending post to Account ID: $accountId | Imgs count: " . count($compiled_images));

    $mediaIds = [];
    foreach ($compiled_images as $imgUrl) {
        $mediaId = uploadFileToNovinhub($token, $imgUrl);
        if ($mediaId) {
            $mediaIds[] = (int)$mediaId;
        } else {
            addDebugLog('NOVINHUB_ERR', "Failed to upload image to NovinHub: $imgUrl");
        }
    }

    $type = 'text';
    if (count($mediaIds) === 1) {
        $type = 'image';
    } elseif (count($mediaIds) > 1) {
        $type = 'album';
    }

    $params = [
        'caption' => $caption,
        'type' => $type,
        'account_ids' => [(int)$accountId],
        'is_scheduled' => !empty($schedule_date) ? 1 : 0,
        'is_draft' => 0
    ];

    if (!empty($mediaIds)) {
        $params['media_ids'] = $mediaIds;
    }

    if (!empty($schedule_date)) {
        $params['schedule_date'] = (int)$schedule_date;
    }

    $ch = curl_init("https://api.novinhub.com/token/v2/post");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    addDebugLog('NOVINHUB_POST_RES', "Code: $httpCode | Resp: $response | Error: $error");

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            return $data['error']['message'] ?? "خطا در وب‌سرویس نوین هاب";
        }
        return true;
    }

    $data = json_decode($response, true);
    if (isset($data['error']['message'])) {
        return $data['error']['message'];
    }

    return "خطا در برقراری ارتباط با نوین هاب (کد وضعیت $httpCode)";
}

function testConnection($platform, $settings) {
    $conf = $settings['connections'][$platform] ?? null;
    
    $method = 'none';
    $accountId = '';
    if ($platform === 'telegram' || $platform === 'instagram') {
        $method = $conf['proxy']['bypass_method'] ?? 'none';
        $accountId = $conf['proxy']['novinhub_account_id'] ?? '';
    } else {
        $method = $conf['bypass_method'] ?? 'none';
        $accountId = $conf['novinhub_account_id'] ?? '';
    }
    
    $token = $settings['novinhub_token'] ?? '';
    if ($method === 'novinhub') {
        if (empty($token)) {
            return ['ok' => false, 'error' => 'توکن نوین هاب تنظیم نشده است'];
        }
        if (empty($accountId)) {
            return ['ok' => false, 'error' => 'شناسه اکانت متصل به نوین هاب تنظیم نشده است'];
        }
        $msg = "✅ تست اتصال سیستم مدیریت محتوا در پلتفرم " . strtoupper($platform) . " از طریق نوین هاب";
        $res = sendToNovinhub($token, $accountId, $msg, [], null);
        if ($res === true) {
            return ['ok' => true, 'description' => 'ارسال تست نوین هاب با موفقیت در صف قرار گرفت'];
        }
        return ['ok' => false, 'error' => is_string($res) ? $res : 'خطای وب‌سرویس نوین هاب'];
    }

    if (!$conf || empty($conf['token'])) {
        return ['ok' => false, 'error' => 'توکن تنظیم نشده است'];
    }

    $msg = "✅ تست اتصال سیستم مدیریت محتوا در پلتفرم " . strtoupper($platform);
    $image = null;
    $proxy = $conf['proxy'] ?? null;
    
    switch ($platform) {
        case 'telegram':
            $res = sendToTelegram($conf['token'], $conf['chatId'], $msg, null, $proxy);
            break;
        case 'bale':
            $res = sendToBale($conf['token'], $conf['chatId'], $msg, null, $proxy);
            break;
        case 'rubika':
            $res = sendToRubika($conf['token'], $conf['chatId'], $msg, null, $proxy);
            break;
        case 'instagram':
            $igAccountId = $conf['instagram_account_id'] ?? $conf['chatId'] ?? '';
            $igToken = $conf['access_token'] ?? $conf['token'] ?? '';
            if (class_exists('InstagramAdapter') && !empty($igAccountId) && !empty($igToken)) {
                $igAdapter = new InstagramAdapter();
                $igCheck = $igAdapter->testConnection($igToken, $igAccountId, $proxy);
                if ($igCheck['ok']) {
                    $uName = $igCheck['username'] ?? $igAccountId;
                    return ['ok' => true, 'description' => "ارتباط با سرور اینستاگرام و اکانت @$uName با موفقیت برقرار و تایید شد. ✅"];
                }
                return ['ok' => false, 'error' => $igCheck['error'] ?? 'خطا در ارتباط با اکانت اینستاگرام'];
            }
            $res = sendToInstagram($conf['token'], $conf['chatId'], $msg, null, $proxy);
            break;
        default:
            return ['ok' => false, 'error' => 'پلتفرم پشتیبانی نمی‌شود'];
    }
    
    if ($res === true) {
        return ['ok' => true, 'description' => 'ارسال تست موفق بود'];
    }
    return ['ok' => false, 'error' => is_string($res) ? $res : 'خطای ناشناخته'];
}

// ================================
// توابع کمکی دیگر
// ================================

function toJalali($timestamp) {
    if (!$timestamp) return '-';
    $jalaliPath = __DIR__ . '/../jalali-master/src/';
    if (file_exists($jalaliPath . 'Jalalian.php')) {
        require_once $jalaliPath . 'CalendarUtils.php';
        require_once $jalaliPath . 'Jalalian.php';
        return \Morilog\Jalali\Jalalian::fromDateTime(new DateTime(date('Y-m-d H:i:s', $timestamp)))->format('Y/m/d H:i');
    }
    
    $g_y = (int)date('Y', $timestamp);
    $g_m = (int)date('m', $timestamp);
    $g_d = (int)date('d', $timestamp);

    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;

    $g_day_no = 365 * $gy + (int)(($gy + 3) / 4) - (int)(($gy + 99) / 100) + (int)(($gy + 399) / 400);

    for ($i = 0; $i < $gm; ++$i)
        $g_day_no += $g_days_in_month[$i];
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)))
        $g_day_no++;
    $g_day_no += $gd;

    $j_day_no = $g_day_no - 79;

    $j_np = (int)($j_day_no / 12053);
    $j_day_no %= 12053;

    $jy = 979 + 33 * $j_np + 4 * (int)($j_day_no / 1461);

    $j_day_no %= 1461;

    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }

    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i)
        $j_day_no -= $j_days_in_month[$i];
    $jm = $i + 1;
    $jd = $j_day_no + 1;

    return sprintf("%04d/%02d/%02d %s", $jy, $jm, $jd, date('H:i', $timestamp));
}

function getCalendarSuggestions($project, $limit = 12) {
    if (!$project) return [];
    $products = $project['products'] ?? [];
    
    // Sort logic: Score based on likes, recency, and priority
    usort($products, function($a, $b) {
        $scoreA = ($a['likes'] ?? 0) * 20 + ($a['priority'] ?? 0) * 100 + ($a['extractedAt'] ?? 0) / 10000;
        $scoreB = ($b['likes'] ?? 0) * 20 + ($b['priority'] ?? 0) * 100 + ($b['extractedAt'] ?? 0) / 10000;
        if (($a['status'] ?? 'active') != 'active') $scoreA -= 1000;
        if (($b['status'] ?? 'active') != 'active') $scoreB -= 1000;
        return $scoreB <=> $scoreA;
    });

    $results = [];
    foreach($products as $p) {
        if (count($results) >= $limit) break;
        if (($p['status'] ?? 'active') === 'rejected') continue;
        
        $results[] = [
            'id' => $p['id'] ?? '',
            'name' => $p['name'] ?? '',
            'sku' => $p['sku'] ?? '',
            'likes' => $p['likes'] ?? 0,
            'image' => $p['image'] ?? '',
            'price' => $p['price'] ?? '',
            'is_colleague' => $p['is_colleague'] ?? false
        ];
    }
    return $results;
}

function calculateEngagementScore($p) {
    $likes = (int)($p['likes'] ?? 0);
    $dislikes = (int)($p['dislikes'] ?? 0);
    $feedbackCount = count($p['userFeedback'] ?? []);
    $score = ($likes * 10) - ($dislikes * 5) + ($feedbackCount * 15);
    return max(0, $score);
}

function isQualityCheckPassed($p) {
    if (empty($p['name']) || empty($p['image']) || empty($p['price'])) return false;
    if (strpos($p['image'], 'placehold.co') !== false) return false;
    if (!isset($p['gallery']) || count($p['gallery']) < 2) return false;
    return true;
}

require_once __DIR__.'/ProductApprovalFlow.php';
function autoApprovePendingProducts(&$project, $advanceSequential = false) {
    $now = time();
    $isSplit = !empty($project['settings']['prod_approval_split_enabled']);
    $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
    $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);

    // Smart helper for background compatibility: if value is <= 120, treat as hours and convert to minutes
    $getTimeoutMinutes = function($key, $default, $project) {
        if (isset($project['settings'][$key])) {
            $val = (int)$project['settings'][$key];
            if (($project['settings']['approval_timeout_unit']??'')!=='minutes' && $val > 0 && $val <= 120) {
                return $val * 60;
            }
            return $val;
        }
        $oldApprovalTimeout = (int)($project['settings']['approval_timeout'] ?? 0);
        if ($oldApprovalTimeout > 0) {
            if (($project['settings']['approval_timeout_unit']??'')!=='minutes' && $oldApprovalTimeout <= 120) {
                return $oldApprovalTimeout * 60;
            }
            return $oldApprovalTimeout;
        }
        return $default;
    };

    // Separated timeout variables in minutes
    $timeoutMinutesImage = $getTimeoutMinutes('approval_timeout_image', 2880, $project);
    $timeoutSecImage = $timeoutMinutesImage * 60;

    $timeoutMinutesLibrary = $getTimeoutMinutes('approval_timeout_library', 2880, $project);
    $timeoutSecLibrary = $timeoutMinutesLibrary * 60;

    $timeoutMinutesCalendar = $getTimeoutMinutes('approval_timeout_calendar', 2880, $project);
    $timeoutSecCalendar = $timeoutMinutesCalendar * 60;

    // 1. Process Products
    if (isset($project['products']) && is_array($project['products'])) {
        foreach ($project['products'] as &$p) {
            if (($p['status'] ?? 'active') === 'pending') {
                if(ProductApprovalFlow::enabled($project['settings'])){if($advanceSequential)ProductApprovalFlow::autoAdvance($p,$project,$now);continue;}
                $isImageRelated = (!empty($p['is_manual_image']) || !empty($p['uploaded_by']));
                $extractedAt = $p['extractedAt'] ?? 0;
                
                if ($isImageRelated) {
                    // Applied image specific timeout
                    if ($timeoutMinutesImage > 0 && $extractedAt > 0 && ($now - $extractedAt) > $timeoutSecImage) {
                        if ($isSplit) {
                            $st1_count = count($p['approvals_stage1'] ?? []);
                            $st2_count = count($p['approvals_stage2'] ?? []);
                            
                            $s1_pending = ($st1_count < $st1_min) && (empty($p['stage1_decision']) || $p['stage1_decision'] === 'pending');
                            $s2_pending = ($st2_count < $st2_min) && (empty($p['stage2_decision']) || $p['stage2_decision'] === 'pending');
                            
                            if ($s1_pending) {
                                $p['stage1_decision'] = 'approved';
                                $p['stage1_system_approved'] = true;
                                if (!isset($p['approvals_stage1'])) $p['approvals_stage1'] = [];
                                $p['approvals_stage1'][] = 'سیستم (پاسخ خودکار تصویر)';
                            }
                            if ($s2_pending) {
                                $p['stage2_decision'] = 'approved';
                                $p['stage2_system_approved'] = true;
                                if (!isset($p['approvals_stage2'])) $p['approvals_stage2'] = [];
                                $p['approvals_stage2'][] = 'سیستم (پاسخ خودکار تصویر)';
                            }
                            
                            refreshProductSplitStatus($p, $project);
                            addDebugLog('AUTO_APPROVE', "Product Image {$p['id']} split auto-approved processed.", ['pid' => $p['id']]);
                        } else {
                            if (empty($p['userVotes'])) {
                                $p['status'] = 'active';
                                approveProductImages($p);
                                $p['system_approved'] = true;
                                addDebugLog('AUTO_APPROVE', "Product Image {$p['id']} auto-approved due to image timeout.", ['pid' => $p['id']]);
                            }
                        }
                    }
                } else {
                    // Applied library specific timeout
                    if ($timeoutMinutesLibrary > 0 && $extractedAt > 0 && ($now - $extractedAt) > $timeoutSecLibrary) {
                        if ($isSplit) {
                            $st1_count = count($p['approvals_stage1'] ?? []);
                            $st2_count = count($p['approvals_stage2'] ?? []);
                            
                            $s1_pending = ($st1_count < $st1_min) && (empty($p['stage1_decision']) || $p['stage1_decision'] === 'pending');
                            $s2_pending = ($st2_count < $st2_min) && (empty($p['stage2_decision']) || $p['stage2_decision'] === 'pending');
                            
                            if ($s1_pending) {
                                $p['stage1_decision'] = 'approved';
                                $p['stage1_system_approved'] = true;
                                if (!isset($p['approvals_stage1'])) $p['approvals_stage1'] = [];
                                $p['approvals_stage1'][] = 'سیستم (پاسخ خودکار کتابخانه)';
                            }
                            if ($s2_pending) {
                                $p['stage2_decision'] = 'approved';
                                $p['stage2_system_approved'] = true;
                                if (!isset($p['approvals_stage2'])) $p['approvals_stage2'] = [];
                                $p['approvals_stage2'][] = 'سیستم (پاسخ خودکار کتابخانه)';
                            }
                            
                            refreshProductSplitStatus($p, $project);
                            addDebugLog('AUTO_APPROVE', "Product {$p['id']} split auto-approved processed.", ['pid' => $p['id']]);
                        } else {
                            if (empty($p['userVotes'])) {
                                $p['status'] = 'active';
                                approveProductImages($p);
                                $p['system_approved'] = true;
                                addDebugLog('AUTO_APPROVE', "Product {$p['id']} auto-approved due to library timeout.", ['pid' => $p['id']]);
                            }
                        }
                    }
                }
            }
        }
        unset($p);
    }
    
    // 2. Process Calendar Schedules
    if (isset($project['schedules']) && is_array($project['schedules'])) {
        $calSplit = !empty($project['settings']['cal_approval_split_enabled']);
        $cal_st1_min = (int)($project['settings']['cal_approval_stage1_min'] ?? 1);
        $cal_st2_min = (int)($project['settings']['cal_approval_stage2_min'] ?? 1);
        
        foreach ($project['schedules'] as &$s) {
            $ast = $s['approvalStatus'] ?? 'pending';
            if ($ast === 'pending') {
                $createdAt = $s['created_at'] ?? $s['created_time'] ?? 0;
                if ($createdAt <= 0) {
                    $createdAt = $s['scheduledAt'] ?? $s['scheduled_at'] ?? 0;
                    if ($createdAt > 0) {
                        $createdAt = $createdAt - 86400 * 2; // Assume created 2 days before schedule if missing
                    }
                }
                
                if ($timeoutMinutesCalendar > 0 && $createdAt > 0 && ($now - $createdAt) > $timeoutSecCalendar) {
                    if ($calSplit) {
                        $st1_count = count($s['approvals_stage1'] ?? []);
                        $st2_count = count($s['approvals_stage2'] ?? []);
                        
                        $s1_pending = ($st1_count < $cal_st1_min) && (empty($s['stage1_decision']) || $s['stage1_decision'] === 'pending');
                        $s2_pending = ($st2_count < $cal_st2_min) && (empty($s['stage2_decision']) || $s['stage2_decision'] === 'pending');
                        
                        if ($s1_pending) {
                            $s['stage1_decision'] = 'approved';
                            $s['stage1_system_approved'] = true;
                            if (!isset($s['approvals_stage1'])) $s['approvals_stage1'] = [];
                            $s['approvals_stage1'][] = 'سیستم (پاسخ خودکار تقویم)';
                        }
                        if ($s2_pending) {
                            $s['stage2_decision'] = 'approved';
                            $s['stage2_system_approved'] = true;
                            if (!isset($s['approvals_stage2'])) $s['approvals_stage2'] = [];
                            $s['approvals_stage2'][] = 'سیستم (پاسخ خودکار تقویم)';
                        }
                        
                        refreshScheduleSplitStatus($s, $project);
                        addDebugLog('AUTO_APPROVE', "Schedule {$s['id']} split auto-approved processed.", ['sid' => $s['id']]);
                    } else {
                        $s['approvalStatus'] = 'approved';
                        $s['system_approved'] = true;
                        addDebugLog('AUTO_APPROVE', "Schedule {$s['id']} auto-approved due to calendar timeout.", ['sid' => $s['id']]);
                    }
                }
            }
        }
        unset($s);
    }
}

function getOccasions($project, $yearFilter = null, $upcomingOnly = false) {
    $merged = [];
    
    // Default strategic occurrences for 1405 (representing 2026-2027)
    $items1405 = [
        // Spring (بهار)
        [
            'title' => 'نوروز باستانی ایران',
            'english_title' => 'Nowruz - Persian New Year',
            'date' => '1405/01/01',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: لانچ رسمی دراپ بهاره لوکس با الیاف کتان ارگانیک؛ پیوند با مفهوم نمادین خانه‌تکانی و چیدمان کمد کپسولی.',
            'importance' => 'high',
            'season' => 'spring',
            'month_num' => 1,
            'year' => 1405
        ],
        [
            'title' => 'سیزده بدر (روز طبیعت)',
            'english_title' => 'Sizdah Bedar - Nature Day',
            'date' => '1405/01/13',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: کمپین عکاسی مدلینگ در طبیعت بومی؛ اهدای درصدی از سود این روز برای احیای جنگل‌ها یا پاکسازی محیط‌زیست.',
            'importance' => 'high',
            'season' => 'spring',
            'month_num' => 1,
            'year' => 1405
        ],
        [
            'title' => 'روز جهانی زمین و هفته انقلاب مد',
            'english_title' => 'Earth Day & Fashion Revolution',
            'date' => '1405/02/02',
            'category' => 'کارزار جهانی مد پایدار',
            'b2b_b2c' => 'B2C: اجرای کمپین شفافیت زنجیره تامین با شعار «چه کسی لباس من را ساخته است؟»؛ نمایش مستند کارگاه‌های دوخت و اصالت الیاف.',
            'importance' => 'high',
            'season' => 'spring',
            'month_num' => 2,
            'year' => 1405
        ],
        [
            'title' => 'روز جهانی تجارت عادلانه',
            'english_title' => 'World Fair Trade Day',
            'date' => '1405/02/20',
            'category' => 'کارزار جهانی مد پایدار',
            'b2b_b2c' => 'B2C: مانور روی حقوق هنرمندان نساجی، کارگاه‌های بومی و معرفی پارتنرهای زنجیره تامین که از استانداردهای اخلاقی پیروی می‌کنند.',
            'importance' => 'medium',
            'season' => 'spring',
            'month_num' => 2,
            'year' => 1405
        ],
        [
            'title' => 'روز جهانی محیط زیست',
            'english_title' => 'World Environment Day',
            'date' => '1405/03/15',
            'category' => 'کارزار جهانی محیط زیست',
            'b2b_b2c' => 'B2C: رونمایی از بسته‌بندی‌های کاملاً زیست‌تخریب‌پذیر و کرافت برند؛ کمپین آموزشی مسئولیت اجتماعی مصرف‌کننده.',
            'importance' => 'medium',
            'season' => 'spring',
            'month_num' => 3,
            'year' => 1405
        ],
        [
            'title' => 'روز ملی صنایع دستی',
            'english_title' => 'National Handicrafts Day',
            'date' => '1405/03/20',
            'category' => 'مناسبت ملی ایران',
            'b2b_b2c' => 'B2C: نقطه عطف برای لاین‌های پوشاک مدرن-سنتی؛ استفاده محدود از سوزن‌دوزی، پته‌دوزی یا تکه‌های پارچه‌های دستبافت اصیل.',
            'importance' => 'medium',
            'season' => 'spring',
            'month_num' => 3,
            'year' => 1405
        ],
        [
            'title' => 'نمایشگاه پیتی ایمجین اومو',
            'english_title' => 'Pitti Immagine Uomo (Summer)',
            'date' => '1405/03/26',
            'category' => 'نمایشگاه تخصصی منسوجات',
            'b2b_b2c' => 'B2B: حضور یا رصد مستقیم جهت منبع‌یابی الیاف سلولزی، کتان وحشی و پشم گوسفند (تامین متریال برای کالکشن بهار/تابستان سال بعد).',
            'importance' => 'high',
            'season' => 'spring',
            'month_num' => 3,
            'year' => 1405
        ],
        [
            'title' => 'هفته مد مردانه میلان و پاریس',
            'english_title' => 'Milan & Paris Men\'s SS',
            'date' => '1405/03/29',
            'category' => 'هفته مد بین‌المللی',
            'b2b_b2c' => 'B2B: ایدهبرداری از کت‌وشلوارهای کژوال مینیمال، ساختارهای بدون دکمه پلاستیکی و پالت‌های رنگی خنثی برای الیاف طبیعی.',
            'importance' => 'medium',
            'season' => 'spring',
            'month_num' => 3,
            'year' => 1405
        ],
        
        // Summer (تابستان)
        [
            'title' => 'جشن باستانی تیرگان',
            'english_title' => 'Tirgan Water Festival',
            'date' => '1405/04/13',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: لانچ دراپ تابستانه با پیراهن‌های گشاد کتان وحشی؛ تمرکز کمپین روی آگاهی‌بخشی در خصوص بحران هدررفت آب در منسوجات صنعتی.',
            'importance' => 'medium',
            'season' => 'summer',
            'month_num' => 4,
            'year' => 1405
        ],
        [
            'title' => 'نمایشگاه برتر ویژن پاریس (تابستان)',
            'english_title' => 'Première Vision Paris (Summer)',
            'date' => '1405/04/15',
            'category' => 'نمایشگاه تخصصی منسوجات',
            'b2b_b2c' => 'B2B: تامین و منبع‌یابی مستقیم الیاف سلولزی نوین، ابریشم صلح و چرم‌های گیاهی حاصل از ضایعات کشاورزی برای کل سال پیش رو.',
            'importance' => 'high',
            'season' => 'summer',
            'month_num' => 4,
            'year' => 1405
        ],
        [
            'title' => 'فاز عکاسی و تولید نمونه طلایی',
            'english_title' => 'Golden Sample & Photoshoot',
            'date' => '1405/05/15',
            'category' => 'فرآیند داخلی زنجیره تامین',
            'b2b_b2c' => 'B2B/B2C: اتمام فاز دوزندگی کارگاهی دراپ پاییزه؛ اجرای فوتوشوت‌های مدلینگ مینیمال در فضاهای مونوکروم و آفتاب ملایم اواخر تابستان.',
            'importance' => 'medium',
            'season' => 'summer',
            'month_num' => 5,
            'year' => 1405
        ],
        [
            'title' => 'نمایشگاه برتر ویژن پاریس (پاییز)',
            'english_title' => 'Première Vision (Autumn)',
            'date' => '1405/06/10',
            'category' => 'نمایشگاه تخصصی منسوجات',
            'b2b_b2c' => 'B2B: منبع‌یابی مستقیم الیاف پاییزه/زمستانه؛ بررسی فناوری‌های هایتک در حوزه رنگرزی ارگانیک و بدون نیاز به آب.',
            'importance' => 'high',
            'season' => 'summer',
            'month_num' => 6,
            'year' => 1405
        ],
        [
            'title' => 'کارزار سپتامبر دست دوم',
            'english_title' => 'Second Hand September',
            'date' => '1405/06/10',
            'category' => 'کارزار جهانی مد پایدار',
            'b2b_b2c' => 'B2C: ترویج دوام بالای لباس‌های برند؛ معرفی لاین یا سرویس‌های رفوگری، تعمیر و ارتقای قطعات قدیمی مشتریان (اصول اقتصاد چرخشی).',
            'importance' => 'medium',
            'season' => 'summer',
            'month_num' => 6,
            'year' => 1405
        ],
        [
            'title' => 'هفته‌های مد بین‌المللی بهار/تابستان',
            'english_title' => 'NY, London, Milan, Paris SS',
            'date' => '1405/06/19',
            'category' => 'هفته مد بین‌المللی',
            'b2b_b2c' => 'B2B: ماراتن رصد جهت‌گیری بازارهای لوکس در استایل‌های خیابانی مینیمال و تکنیک‌های طراحی الگو بدون دورریز (Zero-waste).',
            'importance' => 'medium',
            'season' => 'summer',
            'month_num' => 6,
            'year' => 1405
        ],
        
        // Autumn (پاییز)
        [
            'title' => 'روز جهانی پنبه و جشن مهرگان',
            'english_title' => 'World Cotton Day & Mehregan',
            'date' => '1405/07/15',
            'category' => 'مناسبت ترکیبی (جهانی/ملی)',
            'b2b_b2c' => 'B2C: لانچ دراپ کپسولی پاییزه با پنبه ارگانیک تاییدشده؛ تجلیل از پالت رنگی گرم پاییزی ایران باستان و اصالت مزارع پنبه بومی.',
            'importance' => 'high',
            'season' => 'autumn',
            'month_num' => 7,
            'year' => 1405
        ],
        [
            'title' => 'فاز بهینه‌سازی الگوسازی دیجیتال',
            'english_title' => 'Digital Patternmaking & 3D',
            'date' => '1405/08/15',
            'category' => 'فرآیند داخلی زنجیره تامین',
            'b2b_b2c' => 'B2B: استفاده از شبیه‌سازی سه‌بعدی نرم‌افزاری برای کاهش دفعات نمونه‌گیری فیزیکی (n <= 1) جهت آماده‌سازی زیرساخت تولید دراپ‌های زمستان و بهار.',
            'importance' => 'medium',
            'season' => 'autumn',
            'month_num' => 8,
            'year' => 1405
        ],
        [
            'title' => 'روز جهانی بدون خرید',
            'english_title' => 'Buy Nothing Day',
            'date' => '1405/09/06',
            'category' => 'کارزار جهانی مد پایدار',
            'b2b_b2c' => 'B2C: مخالفت علنی با تخفیف‌های تخریب‌گر بلک‌فرایدی با شعار «کمتر بخر, بهتر انتخاب کن»؛ دعوت به سرمایه‌گذاری روی قطعات ماندگار.',
            'importance' => 'high',
            'season' => 'autumn',
            'month_num' => 9,
            'year' => 1405
        ],
        [
            'title' => 'روز جهانی کوهستان',
            'english_title' => 'International Mountain Day',
            'date' => '1405/09/20',
            'category' => 'کارزار جهانی طبیعت',
            'b2b_b2c' => 'B2C: بهترین زمان بازاریابی برای شکت‌های کتان ضخیم، اکسسوری‌های پشمی و پوشاک اوتدور با تم استایل‌های مینیمال مسافرتی.',
            'importance' => 'medium',
            'season' => 'autumn',
            'month_num' => 9,
            'year' => 1405
        ],
        [
            'title' => 'جشن شب یلدا (چله)',
            'english_title' => 'Yalda Night Celebration',
            'date' => '1405/09/30',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: عرضه رسمی دراپ پالتوهای پشمی ضخیم و سنگین آلپاکا با تاکید بر طول عمر بالای محصول و اصالت در بافت.',
            'importance' => 'high',
            'season' => 'autumn',
            'month_num' => 9,
            'year' => 1405
        ],
        
        // Winter (زمستان)
        [
            'title' => 'جشن خرمروز',
            'english_title' => 'Khoram Rooz Festival',
            'date' => '1405/10/01',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: آغاز چله بزرگ؛ بهانه عالی کمپین بازاریابی برای استایل‌های لایه‌لایه زمستانی، پوشاک عایق پشمی و فوترهای فاخر ارگانیک.',
            'importance' => 'high',
            'season' => 'winter',
            'month_num' => 10,
            'year' => 1405
        ],
        [
            'title' => 'نمایشگاه پیتی ایمجین اومو (زمستان)',
            'english_title' => 'Pitti Immagine Uomo (Winter)',
            'date' => '1405/10/23',
            'category' => 'نمایشگاه تخصصی منسوجات',
            'b2b_b2c' => 'B2B: ارزیابی روندهای دوخت کلاسیک مردانه؛ نهایی‌سازی زنجیره تامین برای کتانها و الیاف ارگانیک خنک دراپ‌های بهار و تابستان پیش‌رو.',
            'importance' => 'high',
            'season' => 'winter',
            'month_num' => 10,
            'year' => 1405
        ],
        [
            'title' => 'نمایشگاه برتر ویژن پاریس (زمستان)',
            'english_title' => 'Première Vision Paris (Winter)',
            'date' => '1405/11/14',
            'category' => 'نمایشگاه تخصصی منسوجات',
            'b2b_b2c' => 'B2B: منبع‌یابی متریال نوآورانه، پارچه‌های زیست‌محیطی پاییزی و بررسی پشم‌های مرینو تحت استانداردهای رفاه دامی (Mulesing-free).',
            'importance' => 'high',
            'season' => 'winter',
            'month_num' => 11,
            'year' => 1405
        ],
        [
            'title' => 'هفته‌های مد بین‌المللی پاییز/زمستان',
            'english_title' => 'NY, London, Milan, Paris AW',
            'date' => '1405/11/22',
            'category' => 'هفته مد بین‌المللی',
            'b2b_b2c' => 'B2B: تحلیل رویکرد خانه‌های مد لوکس در به‌کارگیری استانداردهای دوخت دستی، اوت‌کوتور و پایش روندهای زیباشناختی مینیمال زمستانه.',
            'importance' => 'medium',
            'season' => 'winter',
            'month_num' => 11,
            'year' => 1405
        ],
        [
            'title' => 'جشن اسفندگان (روز زن و زمین)',
            'english_title' => 'Esfandegan Celebration',
            'date' => '1405/12/05',
            'category' => 'مناسبت ملی و باستانی',
            'b2b_b2c' => 'B2C: لانچ دراپ کپسولی زنانه با تمرکز بر شومیزهای ابریشم طبیعی و کتان ارگانیک؛ ترویج نقش زنان در صیانت از پایداری زمین.',
            'importance' => 'high',
            'season' => 'winter',
            'month_num' => 12,
            'year' => 1405
        ],
        [
            'title' => 'روز درختکاری و هفته منابع طبیعی',
            'english_title' => 'Arbor Day & Natural Resources Week',
            'date' => '1405/12/15',
            'category' => 'مناسبت ملی / کارزار سبز',
            'b2b_b2c' => 'B2C: گره زدن مسئولیت اجتماعی برند با طبیعت؛ اجرای کمپین «کاشت یک نه درخت به نام مشتری به ازای هر خرید از دراپ بهاره».',
            'importance' => 'medium',
            'season' => 'winter',
            'month_num' => 12,
            'year' => 1405
        ]
    ];
    
    // Synthesize same strategic occurrences for 1406 with specific dates shifts requested
    $items1406 = [];
    foreach ($items1405 as $item) {
        $newItem = $item;
        $newItem['year'] = 1406;
        $oldDate = $item['date'];
        $dateParts = explode('/', $oldDate);
        $m = $dateParts[1];
        $d = $dateParts[2];
        
        $newD = $d;
        $newM = $m;
        
        // Match the specific dates for 1406 from your final doc
        if ($item['title'] === 'روز جهانی تجارت عادلانه') {
            $newD = '24';
        } elseif ($item['title'] === 'نمایشگاه پیتی ایمجین اومو') {
            $newD = '25';
        } elseif ($item['title'] === 'هفته مد مردانه میلان و پاریس') {
            $newM = '04'; $newD = '01';
        } elseif ($item['title'] === 'نمایشگاه برتر ویژن پاریس (پاییز)') {
            $newD = '16';
        } elseif ($item['title'] === 'هفته‌های مد بین‌المللی بهار/تابستان') {
            $newD = '12';
        } elseif ($item['title'] === 'روز جهانی بدون خرید') {
            $newD = '05';
        } elseif ($item['title'] === 'نمایشگاه پیتی ایمجین اومو (زمستان)') {
            $newD = '22';
        } elseif ($item['title'] === 'نمایشگاه برتر ویژن پاریس (زمستان)') {
            $newD = '13';
        } elseif ($item['title'] === 'هفته‌های مد بین‌المللی پاییز/زمستان') {
            $newD = '16';
        }
        
        $newItem['date'] = "1406/$newM/$newD";
        $newItem['month_num'] = (int)$newM;
        $newItem['season'] = ((int)$newM <= 3) ? 'spring' : (((int)$newM <= 6) ? 'summer' : (((int)$newM <= 9) ? 'autumn' : 'winter'));
        $items1406[] = $newItem;
    }
    
    // Merge standard items
    $defaults = array_merge($items1405, $items1406);
    
    // Merge custom occurrences inserted by user
    $customOccs = $project['settings']['custom_occasions'] ?? [];
    foreach ($customOccs as $c) {
        $dateStr = $c['jalali_date'] ?? $c['date'] ?? '1405/01/01';
        $parts = explode('/', $dateStr);
        $y = (int)($parts[0] ?? 1405);
        $m = (int)($parts[1] ?? 1);
        $d = (int)($parts[2] ?? 1);
        
        $defaults[] = [
            'id' => $c['id'] ?? uniqid('occ_'),
            'title' => $c['title'],
            'english_title' => $c['english_title'] ?? 'Custom Occasion',
            'date' => $dateStr,
            'category' => $c['category'] ?? 'سفارشی',
            'b2b_b2c' => $c['b2b_b2c'] ?? 'B2B/B2C: مناسبت سفارشی تدوین شده توسط کابر.',
            'importance' => $c['importance'] ?? 'medium',
            'season' => ($m <= 3) ? 'spring' : (($m <= 6) ? 'summer' : (($m <= 9) ? 'autumn' : 'winter')),
            'month_num' => $m,
            'year' => $y,
            'is_custom' => true
        ];
    }
    
    // Finalize day attributes & sort chronologically
    foreach ($defaults as &$item) {
        $parts = explode('/', $item['date']);
        $item['day'] = $parts[2] ?? '01';
        $item['jalali_date'] = $item['date'];
    }
    unset($item);
    
    // Sort logic
    usort($defaults, function($a, $b) {
        if ($a['year'] != $b['year']) {
            return $a['year'] - $b['year'];
        }
        if ($a['month_num'] != $b['month_num']) {
            return $a['month_num'] - $b['month_num'];
        }
        return (int)$a['day'] - (int)$b['day'];
    });
    
    // Filter by requested year if any
    if ($yearFilter) {
        $filtered = [];
        foreach ($defaults as $item) {
            if ($item['year'] == $yearFilter) {
                $filtered[] = $item;
            }
        }
        $defaults = $filtered;
    }

    // Filter out past occasions if upcomingOnly is requested
    if ($upcomingOnly) {
        $todayJ = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
        $todayJalaliStr = sprintf('%04d/%02d/%02d', $todayJ[0], $todayJ[1], $todayJ[2]);
        $filtered = [];
        foreach ($defaults as $item) {
            $itemDate = $item['jalali_date'] ?? ($item['date'] ?? '');
            if ($itemDate >= $todayJalaliStr) {
                $filtered[] = $item;
            }
        }
        $defaults = $filtered;
    }
    
    return $defaults;
}

// ============================================
// بخش سامانه پیامکی ملی‌پیامک و کاربران پورتال
// ============================================

function faToEn($string) {
    if ($string === null || $string === '') return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic   = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $num      = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($arabic, $num, str_replace($persian, $num, $string));
}

function getPortalUser($mobile) {
    $driver = $GLOBALS['db_driver'] ?? 'unknown';
    $log_data = ["mobile" => $mobile, "driver" => $driver, "sql_active" => isSqlActive()];
    
    if (!isSqlActive()) {
        $db = getJsonDB();
        $user = $db['portal_users'][$mobile] ?? null;
        $log_data["result"] = $user ? "found" : "not_found";
        @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "getPortalUser(JSON): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return $user;
    }
    
    $conn = getConn();
    $log_data["conn"] = ($conn !== null) ? "active" : "null";
    
    $stmt = $conn->prepare("SELECT * FROM portal_users WHERE mobile = ?");
    if ($stmt) {
        $stmt->bind_param("s", $mobile);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $stmt->close();
            $log_data["result"] = "found";
            $log_data["user"] = $user;
            @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "getPortalUser(SUCCESS): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            return $user;
        }
        $log_data["result"] = "empty_result";
        $stmt->close();
    } else {
        $log_data["result"] = "prepare_failed";
        $log_data["error"] = $conn->error ?? 'unknown_error';
    }
    @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "getPortalUser(FAILED/EMPTY): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return null;
}

function savePortalUser($mobile, $displayName, $icon = '', $otpCode = null, $otpExpires = null) {
    $driver = $GLOBALS['db_driver'] ?? 'unknown';
    $log_data = [
        "mobile" => $mobile, 
        "display_name" => $displayName, 
        "otp" => $otpCode, 
        "expires" => $otpExpires, 
        "driver" => $driver, 
        "sql_active" => isSqlActive()
    ];
    
    if (!isSqlActive()) {
        $db = getJsonDB();
        if (!isset($db['portal_users'])) {
            $db['portal_users'] = [];
        }
        $db['portal_users'][$mobile] = [
            'mobile' => $mobile,
            'display_name' => $displayName,
            'user_icon' => $icon,
            'otp_code' => $otpCode,
            'otp_expires' => $otpExpires,
            'created_at' => time()
        ];
        $save_res = saveJsonDB($db);
        $log_data["result"] = $save_res ? "saved" : "save_failed";
        @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "savePortalUser(JSON): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return $save_res;
    }
    
    $conn = getConn();
    $stmt = $conn->prepare("REPLACE INTO portal_users (mobile, display_name, user_icon, otp_code, otp_expires, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $createdAt = time();
        $stmt->bind_param("ssssii", $mobile, $displayName, $icon, $otpCode, $otpExpires, $createdAt);
        $res = $stmt->execute();
        $stmt->close();
        $log_data["result"] = $res ? "success" : "execute_failed";
        if (!$res) {
            $log_data["error"] = $conn->error ?? 'execute_error';
        }
        @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "savePortalUser(SUCCESS): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return $res;
    } else {
        $log_data["result"] = "prepare_failed";
        $log_data["error"] = $conn->error ?? 'prepare_error';
    }
    @file_put_contents(__DIR__ . '/../debug_otp.log', date('[Y-m-d H:i:s] ') . "savePortalUser(FAILED): " . json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return false;
}

function sendMelipayamakOTP($to) {
    $to = trim($to);
    $to = preg_replace('/[^0-9]/', '', $to);
    if (strpos($to, '09') !== 0 && strlen($to) === 10) {
        $to = '0' . $to;
    }
    
    $url = 'https://console.melipayamak.com/api/send/otp/63b3c1abbfed4a78b86496d5e857e217';
    $data = array('to' => $to);
    $data_string = json_encode($data);
    
    $ch = curl_init($url);                          
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                      
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_string)
    ));
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    $res = null;
    if ($response === false) {
        $res = ['success' => false, 'error' => 'Curl Error: ' . $curl_error];
    } else {
        $resDecoded = json_decode($response, true);
        if (!is_array($resDecoded)) {
            $res = ['success' => false, 'error' => 'پاسخ نامعتبر از سرور پیامک: ' . $response];
        } else if (empty($resDecoded['code'])) {
            $error = !empty($resDecoded['status']) ? $resDecoded['status'] : 'خطای نا مشخص';
            $res = ['success' => false, 'error' => $error];
        } else {
            $res = [
                'success' => true,
                'code' => $resDecoded['code'],
                'status' => $resDecoded['status'] ?? ''
            ];
        }
    }
    
    // Log SMS internally inside project settings
    $project = getCurrentProject();
    if ($project) {
        if (!isset($project['settings']['sms_logs'])) {
            $project['settings']['sms_logs'] = [];
        }
        $project['settings']['sms_logs'][] = [
            'id' => uniqid('sms_'),
            'at' => time(),
            'to' => $to,
            'type' => 'otp_shared',
            'status' => $res['success'] ? 'sent' : 'failed',
            'message' => 'ارسال کد رمز یکبار مصرف (سرویس اشتراکی ملی‌پیامک)',
            'log' => $res['success'] ? "ارسال موفق - کد ارسالی: " . $res['code'] . " Status: " . ($res['status'] ?? '') : $res['error']
        ];
        
        if (count($project['settings']['sms_logs']) > 1000) {
            array_shift($project['settings']['sms_logs']);
        }
        saveProject($project);
    }
    
    return $res;
}

function sendSmsByPattern($to, $textArgs, $project_settings = null, $smsType = 'unknown') {
    $project = null;
    if ($project_settings === null) {
        $project = getCurrentProject();
        $project_settings = $project['settings'] ?? [];
    } else {
        $project = getCurrentProject();
    }
    
    $sms_config = $project_settings['sms'] ?? [];
    $username = $sms_config['username'] ?? '';
    $password = $sms_config['password'] ?? '';
    
    // Choose bodyId dynamically based on smsType
    $bodyId = (int)($sms_config['body_id'] ?? 0);
    if ($smsType === 'review_photo') {
        $bodyId = (int)($sms_config['review_body_id'] ?? $bodyId);
    } else if ($smsType === 'calendar_schedule') {
        $bodyId = (int)($sms_config['calendar_body_id'] ?? $bodyId);
    } else if ($smsType === 'daily_rejected_summary') {
        $bodyId = (int)($sms_config['rejected_body_id'] ?? $bodyId);
    } else if ($smsType === 'review_photo_aggregated') {
        $bodyId = (int)($sms_config['review_aggregated_body_id'] ?? $bodyId);
    } else if ($smsType === 'calendar_aggregated') {
        $bodyId = (int)($sms_config['calendar_aggregated_body_id'] ?? $bodyId);
    }
    
    $text = is_array($textArgs) ? implode(';', $textArgs) : $textArgs;
    
    if (empty($username) || empty($password) || empty($bodyId)) {
        $errMsg = 'تنظیمات سامانه پیامکی ملی‌پیامک خالی یا تایید نشده است.';
        
        // Log locally if we have project
        if ($project) {
            if (!isset($project['settings']['sms_logs'])) {
                $project['settings']['sms_logs'] = [];
            }
            $project['settings']['sms_logs'][] = [
                'id' => uniqid('sms_'),
                'at' => time(),
                'to' => $to,
                'type' => $smsType,
                'status' => 'failed',
                'message' => $text,
                'log' => $errMsg
            ];
            saveProject($project);
        }
        return ['success' => false, 'error' => $errMsg];
    }
    
    $to = trim($to);
    $to = preg_replace('/[^0-9]/', '', $to);
    if (strpos($to, '98') === 0) {
        $to = '0' . substr($to, 2);
    }
    
    $data = array(
        'username' => $username,
        'password' => $password,
        'text' => $text,
        'to' => $to,
        'bodyId' => $bodyId
    );
    
    $post_data = http_build_query($data);
    $handle = curl_init('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber');
    curl_setopt($handle, CURLOPT_HTTPHEADER, array(
        'content-type' => 'application/x-www-form-urlencoded'
    ));
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($handle, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($handle);
    $curl_error = curl_error($handle);
    curl_close($handle);
    
    if ($response === false) {
        $errMsg = 'Curl Error: ' . $curl_error;
        if ($project) {
            if (!isset($project['settings']['sms_logs'])) {
                $project['settings']['sms_logs'] = [];
            }
            $project['settings']['sms_logs'][] = [
                'id' => uniqid('sms_'),
                'at' => time(),
                'to' => $to,
                'type' => $smsType,
                'status' => 'failed',
                'message' => $text,
                'log' => $errMsg
            ];
            saveProject($project);
        }
        return ['success' => false, 'error' => $errMsg];
    }
    
    $resDecoded = json_decode($response, true);
    $val = '';
    if (is_array($resDecoded)) {
        $val = $resDecoded['Value'] ?? ($resDecoded['ReturnValue'] ?? $response);
    } else {
        $val = trim(strip_tags($response));
    }
    
    $errors = [
        '0' => 'نام کاربری یا رمز عبور اشتباه می باشد.',
        '2' => 'اعتبار کافی نمی باشد.',
        '3' => 'محدودیت در ارسال روزانه',
        '4' => 'محدودیت در حجم ارسال',
        '5' => 'شماره فرستنده معتبر نمی باشد.',
        '6' => 'سامانه در حال بروزرسانی می باشد.',
        '7' => 'متن حاوی کلمه فیلتر شده می باشد.',
        '9' => 'ارسال از خطوط عمومی از طریق وب سرویس امکان پذیر نمی باشد.',
        '10' => 'کاربر مورد نظر فعال نمی باشد.',
        '11' => 'ارسال نشده',
        '12' => 'مدارک کاربر کامل نمی باشد.',
        '14' => 'متن حاوی لینک می باشد.',
        '15' => 'ارسال به بیش از ۱ شماره همراه با خطا مواجه شد.',
        '16' => 'شماره گیرنده‌ای یافت نشد',
        '17' => 'متن پیامک خالی می‌باشد',
        '18' => 'شماره گیرنده نامعتبر است',
        '35' => 'شماره موبایل گیرنده در لیست سیاه مخابرات است.',
        '-1' => 'دسترسی برای استفاده از این وبسرویس غیرفعال است.',
        '-2' => 'محدودیت تعداد شماره (فقط یک شماره مجاز است)',
        '-3' => 'خط ارسالی در سیستم تعریف نشده است.',
        '-4' => 'کد متن ارسالی (bodyId) صحیح نمیباشد یا تایید نشده است.',
        '-5' => 'متن ارسالی با متغیرها همخوانی ندارد.',
        '-6' => 'خطای داخلی سامانه رخ داده است.',
        '-7' => 'خطایی در شماره فرستنده رخ داده است.',
        '-10' => 'متن پیامک حاوی لینک غیرمجاز است.',
        '-108' => 'مسدود شدن IP به دلیل تلاش ناموفق',
        '-109' => 'الزام تنظیم IP مجاز برای استفاده از API',
        '-110' => 'الزام استفاده از ApiKey به جای رمز عبور',
        '-111' => 'IP درخواست کننده نامعتبر است.'
    ];
    
    $res = null;
    if (isset($errors[$val])) {
        $res = ['success' => false, 'error' => 'خطای سامانه پیامک (' . $val . '): ' . $errors[$val]];
    } else if (is_numeric($val) && (float)$val > 100) {
        $res = ['success' => true, 'recId' => $val];
    } else if (strpos($val, 'recId') !== false || strlen($val) > 4) {
        $res = ['success' => true, 'recId' => $val];
    } else {
        $res = ['success' => false, 'error' => 'پاسخ نامشخص از سامانه: ' . $val];
    }
    
    // Save SMS logs to project
    if ($project) {
        if (!isset($project['settings']['sms_logs'])) {
            $project['settings']['sms_logs'] = [];
        }
        $project['settings']['sms_logs'][] = [
            'id' => uniqid('sms_'),
            'at' => time(),
            'to' => $to,
            'type' => $smsType,
            'status' => $res['success'] ? 'sent' : 'failed',
            'message' => $text,
            'log' => $res['success'] ? "شناسه دریافت: " . ($res['recId'] ?? $val) : $res['error']
        ];
        
        if (count($project['settings']['sms_logs']) > 1000) {
            array_shift($project['settings']['sms_logs']);
        }
        saveProject($project);
    }
    
    return $res;
}

function checkAndSendSmsNotifications(&$project) {
    $now = time();
    $sms_config = $project['settings']['sms'] ?? [];
    if (empty($sms_config['username']) || empty($sms_config['password'])) {
        return;
    }
    
    // Backward compatibility helper
    $getTimeoutMinutes = function($key, $default, $project) {
        if (isset($project['settings'][$key])) {
            $val = (int)$project['settings'][$key];
            if (($project['settings']['approval_timeout_unit']??'')!=='minutes' && $val > 0 && $val <= 120) {
                return $val * 60;
            }
            return $val;
        }
        $oldApprovalTimeout = (int)($project['settings']['approval_timeout'] ?? 0);
        if ($oldApprovalTimeout > 0) {
            if (($project['settings']['approval_timeout_unit']??'')!=='minutes' && $oldApprovalTimeout <= 120) {
                return $oldApprovalTimeout * 60;
            }
            return $oldApprovalTimeout;
        }
        return $default;
    };

    // Check with separated timeouts (now in minutes)
    $timeoutMinutesLibrary = $getTimeoutMinutes('approval_timeout_library', 2880, $project);
    $timeoutMinutesImage = $getTimeoutMinutes('approval_timeout_image', 2880, $project);

    if ($timeoutMinutesLibrary > 0 || $timeoutMinutesImage > 0) {
        $recipients = [];
        if (!empty($project['users'])) {
            foreach ($project['users'] as $u) {
                $mobile = trim($u['mobile'] ?? '');
                if (!$mobile) continue;
                
                $uPerms = $u['permissions'] ?? [];
                $hasApprovalPerm = in_array('approve', $uPerms) || in_array('approve_content', $uPerms) || ($u['role'] ?? '') === 'project_admin';
                
                if ($hasApprovalPerm && !in_array($mobile, $recipients)) {
                    $recipients[] = $mobile;
                }
            }
        }
        
        if (!empty($recipients)) {
            $warningCount = 0;
            
            if (isset($project['products']) && is_array($project['products'])) {
                foreach ($project['products'] as &$p) {
                    if (($p['status'] ?? 'active') === 'pending') {
                        $isImageRelated = (!empty($p['is_manual_image']) || !empty($p['uploaded_by']));
                        $m = $isImageRelated ? $timeoutMinutesImage : $timeoutMinutesLibrary;
                        
                        if ($m > 0) {
                            $extractedAt = $p['extractedAt'] ?? 0;
                            if ($extractedAt > 0) {
                                $deadline = $extractedAt + ($m * 60);
                                $timeLeft = $deadline - $now;
                                
                                if ($timeLeft > 0 && $timeLeft <= 3600 && empty($p['userVotes']['sms_warning_sent'])) {
                                    $p['userVotes']['sms_warning_sent'] = 1;
                                    $warningCount++;
                                }
                            }
                        }
                    }
                }
                unset($p);
            }
            
            if ($warningCount > 0) {
                $textArgs = ["تایید کالاها", "فقط ۱ ساعت تا اتمام مهلت تایید کارهای معلق باقی مانده است. لطفا بررسی کنید."];
                foreach ($recipients as $to) {
                    sendSmsByPattern($to, $textArgs, $project['settings'], 'timeout_warning');
                }
                addDebugLog('SMS_NOTIFICATION', "Sent 1h timeout SMS warning to " . count($recipients) . " users for $warningCount items.");
            }
            
            $todayStr = date('Y-m-d');
            $lastDailyAlert = $project['settings']['sms_last_daily_alert'] ?? '';
            
            if ($lastDailyAlert !== $todayStr) {
                $newPendingCount = 0;
                if (isset($project['products']) && is_array($project['products'])) {
                    foreach ($project['products'] as $p) {
                        $extractedAt = $p['extractedAt'] ?? 0;
                        if (($p['status'] ?? 'active') === 'pending' && ($now - $extractedAt) <= 86400) {
                            $newPendingCount++;
                        }
                    }
                }
                
                if ($newPendingCount >= 20) {
                    $project['settings']['sms_last_daily_alert'] = $todayStr;
                    $textArgs = ["بروزرسانی تقویم", "تعداد $newPendingCount کالا جدید در تقویم بارگذاری شده است. لطفا بررسی نمایید."];
                    foreach ($recipients as $to) {
                        sendSmsByPattern($to, $textArgs, $project['settings'], 'daily_new_products');
                    }
                    addDebugLog('SMS_NOTIFICATION', "Sent daily summary SMS of $newPendingCount items to " . count($recipients) . " users.");
                }
            }
        }
    }

    // Cumulative Daily Rejected Items SMS Check
    $todayStr = date('Y-m-d');
    $lastRejectedAlert = $project['settings']['sms_last_rejected_alert'] ?? '';
    if ($lastRejectedAlert !== $todayStr) {
        $rejectedList = $project['settings']['sms_rejected_pending_list'] ?? [];
        $rejectedCount = count($rejectedList);
        
        if ($rejectedCount > 0) {
            $rejectedBodyId = (int)($sms_config['rejected_body_id'] ?? 0);
            
            if ($rejectedBodyId > 0) {
                $extractRecipients = [];
                if (!empty($project['users'])) {
                    foreach ($project['users'] as $u) {
                        $mobile = trim($u['mobile'] ?? '');
                        if (!$mobile) continue;
                        
                        $uPerms = $u['permissions'] ?? [];
                        $hasPerm = in_array('extract', $uPerms) || in_array('products', $uPerms) || ($u['role'] ?? '') === 'project_admin';
                        if ($hasPerm && !in_array($mobile, $extractRecipients)) {
                            $extractRecipients[] = $mobile;
                        }
                    }
                }
                
                if (!empty($extractRecipients)) {
                    $textArgs = [(string)$rejectedCount, $project['name'] ?? 'مدیریت محتوا'];
                    foreach ($extractRecipients as $to) {
                        sendSmsByPattern($to, $textArgs, $project['settings'], 'daily_rejected_summary');
                    }
                    addDebugLog('SMS_NOTIFICATION', "Sent daily cumulative rejected items SMS of $rejectedCount items to " . count($extractRecipients) . " users.");
                }
            }
            
            $project['settings']['sms_last_rejected_alert'] = $todayStr;
            $project['settings']['sms_rejected_pending_list'] = [];
        }
    }
}

function notifyNewReviewPhotoSMS($project, $productName) {
    $sms_config = $project['settings']['sms'] ?? [];
    $bodyId = (int)($sms_config['review_body_id'] ?? 0);
    if ($bodyId <= 0) return;
    
    $recipients = [];
    if (!empty($project['users'])) {
        foreach ($project['users'] as $u) {
            $mobile = trim($u['mobile'] ?? '');
            if (!$mobile) continue;
            
            $uPerms = $u['permissions'] ?? [];
            $hasPerm = in_array('approve_content', $uPerms) || ($u['role'] ?? '') === 'project_admin';
            if ($hasPerm && !in_array($mobile, $recipients)) {
                $recipients[] = $mobile;
            }
        }
    }
    
    if (empty($recipients)) return;
    
    $textArgs = [$productName, $project['name'] ?? 'مدیریت محتوا'];
    foreach ($recipients as $to) {
        sendSmsByPattern($to, $textArgs, $project['settings'], 'review_photo');
    }
    addDebugLog('SMS_NOTIFICATION', "Sent pending review photo SMS to " . count($recipients) . " users for: $productName");
}

function notifyNewCalendarScheduleSMS($project, $schedule) {
    if (!$schedule) return;
    $sms_config = $project['settings']['sms'] ?? [];
    $bodyId = (int)($sms_config['calendar_body_id'] ?? 0);
    if ($bodyId <= 0) return;
    
    $recipients = [];
    if (!empty($project['users'])) {
        foreach ($project['users'] as $u) {
            $mobile = trim($u['mobile'] ?? '');
            if (!$mobile) continue;
            
            $uPerms = $u['permissions'] ?? [];
            $hasPerm = in_array('calendar', $uPerms) || ($u['role'] ?? '') === 'project_admin';
            if ($hasPerm && !in_array($mobile, $recipients)) {
                $recipients[] = $mobile;
            }
        }
    }
    
    if (empty($recipients)) return;
    
    $title = $schedule['productName'] ?? 'پست سفارشی';
    $textArgs = [$title, $project['name'] ?? 'مدیریت محتوا'];
    foreach ($recipients as $to) {
        sendSmsByPattern($to, $textArgs, $project['settings'], 'calendar_schedule');
    }
    addDebugLog('SMS_NOTIFICATION', "Sent new calendar schedule SMS to " . count($recipients) . " users for: $title");
}

function checkAndSendDailyAggregatedNotifications(&$project) {
    if (!$project) return;
    
    // Tehran timezone is default, date('H') is Tehran hour
    $currentHour = (int)date('H');
    $currentDateStr = date('Y-m-d');
    
    $targetHour = (int)($project['settings']['daily_notification_hour'] ?? 15);
    
    // Check if current hour is target hour
    if ($currentHour !== $targetHour) {
        return;
    }
    
    // Check if already executed today
    $lastRunDate = $project['settings']['notifications_last_run_date'] ?? '';
    if ($lastRunDate === $currentDateStr) {
        return;
    }
    
    addDebugLog('DAILY_NOTIFICATION', "Starting daily aggregated check for project ID: {$project['id']}");
    
    // 1. Check Library Pending Items
    $pendingLibraryCount = 0;
    if (isset($project['products']) && is_array($project['products'])) {
        foreach ($project['products'] as $p) {
            if (($p['status'] ?? 'active') === 'pending') {
                $pendingLibraryCount++;
            }
        }
    }
    
    // 2. Check Calendar Pending Items
    $pendingSchedulesCount = 0;
    if (isSqlActive()) {
        $conn = getConn();
        if ($conn) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedules WHERE project_id = ? AND approval_status = 'pending'");
            if ($stmt) {
                $stmt->bind_param("s", $project['id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $pendingSchedulesCount = (int)$row['count'];
                }
                $stmt->close();
            }
        }
    } else {
        if (isset($project['schedules']) && is_array($project['schedules'])) {
            foreach ($project['schedules'] as $s) {
                if (($s['approval_status'] ?? 'pending') === 'pending') {
                    $pendingSchedulesCount++;
                }
            }
        }
    }
    
    $notifyChannels = $project['settings']['notify_channels'] ?? ['sms'];
    
    // Find library approvers (approve_content permission or project_admin)
    $libraryApprovers = [];
    if (!empty($project['users'])) {
        foreach ($project['users'] as $u) {
            $uPerms = $u['permissions'] ?? [];
            $hasPerm = in_array('approve_content', $uPerms) || ($u['role'] ?? '') === 'project_admin';
            if ($hasPerm) {
                $libraryApprovers[] = $u;
            }
        }
    }
    
    // Find calendar approvers (calendar, approve permission or project_admin)
    $calendarApprovers = [];
    if (!empty($project['users'])) {
        foreach ($project['users'] as $u) {
            $uPerms = $u['permissions'] ?? [];
            $hasPerm = in_array('calendar', $uPerms) || in_array('approve', $uPerms) || ($u['role'] ?? '') === 'project_admin';
            if ($hasPerm) {
                $calendarApprovers[] = $u;
            }
        }
    }
    
    $tgToken = $project['settings']['connections']['telegram']['token'] ?? '';
    $baleToken = $project['settings']['connections']['bale']['token'] ?? '';
    
    // Send Library Notification
    if ($pendingLibraryCount > 0 && !empty($libraryApprovers)) {
        $msg = "تصاویری منتظر تایید شما هستند - MAARKSHO.COM/PANEL\n(تعداد: $pendingLibraryCount محصول)";
        
        foreach ($libraryApprovers as $user) {
            // SMS Channel
            if (in_array('sms', $notifyChannels)) {
                $mobile = trim($user['mobile'] ?? '');
                if ($mobile) {
                    $reviewAggBody = (int)($project['settings']['sms']['review_aggregated_body_id'] ?? 0);
                    if ($reviewAggBody > 0) {
                        sendSmsByPattern($mobile, [(string)$pendingLibraryCount, $project['name'] ?? 'مارک‌شو'], $project['settings'], 'review_photo_aggregated');
                    } else {
                        // Fallback to standard review pattern
                        sendSmsByPattern($mobile, ["تصاویر کتابخانه ($pendingLibraryCount کالا)", $project['name'] ?? 'تایید کالا'], $project['settings'], 'review_photo');
                    }
                }
            }
            // Telegram Channel
            if (in_array('telegram', $notifyChannels) && !empty($tgToken)) {
                $tgChatId = trim($user['telegram_chat_id'] ?? '');
                if ($tgChatId) {
                    $tgProxy = $project['settings']['connections']['telegram']['proxy'] ?? null;
                    sendToTelegram($tgToken, $tgChatId, "💬 " . $msg, null, $tgProxy);
                }
            }
            // Bale Channel
            if (in_array('bale', $notifyChannels) && !empty($baleToken)) {
                $baleChatId = trim($user['bale_chat_id'] ?? '');
                if ($baleChatId) {
                    $baleProxy = $project['settings']['connections']['bale']['proxy'] ?? null;
                    sendToBale($baleToken, $baleChatId, "💬 " . $msg, null, $baleProxy);
                }
            }
        }
    }
    
    // Send Calendar Notification
    if ($pendingSchedulesCount > 0 && !empty($calendarApprovers)) {
        $msg = "تقویم محتوایی منتظر تایید شماست - MAARKSHO.COM/PANEL\n(تعداد: $pendingSchedulesCount برنامه)";
        
        foreach ($calendarApprovers as $user) {
            // SMS Channel
            if (in_array('sms', $notifyChannels)) {
                $mobile = trim($user['mobile'] ?? '');
                if ($mobile) {
                    $calendarAggBody = (int)($project['settings']['sms']['calendar_aggregated_body_id'] ?? 0);
                    if ($calendarAggBody > 0) {
                        sendSmsByPattern($mobile, [(string)$pendingSchedulesCount, $project['name'] ?? 'مارک‌شو'], $project['settings'], 'calendar_aggregated');
                    } else {
                        // Fallback to standard calendar schedule pattern
                        sendSmsByPattern($mobile, ["تقویم محتوایی ($pendingSchedulesCount برنامه)", $project['name'] ?? 'تایید تقویم'], $project['settings'], 'calendar_schedule');
                    }
                }
            }
            // Telegram Channel
            if (in_array('telegram', $notifyChannels) && !empty($tgToken)) {
                $tgChatId = trim($user['telegram_chat_id'] ?? '');
                if ($tgChatId) {
                    $tgProxy = $project['settings']['connections']['telegram']['proxy'] ?? null;
                    sendToTelegram($tgToken, $tgChatId, "📅 " . $msg, null, $tgProxy);
                }
            }
            // Bale Channel
            if (in_array('bale', $notifyChannels) && !empty($baleToken)) {
                $baleChatId = trim($user['bale_chat_id'] ?? '');
                if ($baleChatId) {
                    $baleProxy = $project['settings']['connections']['bale']['proxy'] ?? null;
                    sendToBale($baleToken, $baleChatId, "📅 " . $msg, null, $baleProxy);
                }
            }
        }
    }
    
    // Save last run date to avoid duplicate runs within the same hour window
    $project['settings']['notifications_last_run_date'] = $currentDateStr;
    saveProject($project);
    addDebugLog('DAILY_NOTIFICATION', "Completed daily aggregated notifications check successfully.");
}

// ==========================================
// موتور یکپارچه‌ساز و هوشمند ادغام محصولات (ERP Merge Engine)
// ==========================================

function formatPersianColor($color) {
    if (empty($color)) return '';
    $color = trim($color);
    
    // Normalize Arabic characters to Persian
    $arabic = ['ي', 'ك', 'ة', '‌'];
    $farsi  = ['ی', 'ک', 'ه', ' '];
    $color = str_replace($arabic, $farsi, $color);
    
    // Replace duplicate spaces
    $color = preg_replace('/\s+/', ' ', $color);
    
    // Separate "روشن" and "تیره" if joined to a word (e.g. کرمروشن -> کرم روشن)
    $color = preg_replace('/(?<!\s)روشن/u', ' روشن', $color);
    $color = preg_replace('/(?<!\s)تیره/u', ' تیره', $color);
    
    return trim($color);
}

function normalizeProductName($name) {
    if (empty($name)) return '';
    $name = mb_strtolower($name, 'UTF-8');
    // Normalize Arabic to Persian/Farsi
    $arabic = ['ي', 'ك', 'ة', '‌'];
    $farsi  = ['ی', 'ک', 'ه', ' '];
    $name = str_replace($arabic, $farsi, $name);
    // Remove zero-width non joiners completely (نیم‌فاصله)
    $name = str_replace("\xE2\x80\x8C", "", $name);
    // Replace extra spaces
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Split by dash structure to remove any color suffix like " - سرمه ای" or " - سرمهای" or "-سرمه ای"
    if (strpos($name, ' - ') !== false) {
        $parts = explode(' - ', $name);
        $name = $parts[0];
    } elseif (strpos($name, ' -') !== false) {
        $parts = explode(' -', $name);
        $name = $parts[0];
    } elseif (strpos($name, '- ') !== false) {
        $parts = explode('- ', $name);
        $name = $parts[0];
    }
    
    return trim($name);
}

function normalizeColor($color) {
    if (empty($color)) return '';
    $color = mb_strtolower($color, 'UTF-8');
    // Remove pattern of digits followed by hyphen or space (e.g. 04- سرمه ای -> سرمه ای)
    $color = preg_replace('/^\d+\s*-\s*/u', '', $color);
    $color = trim($color);
    // Normalize Farsi/Arabic characters and remove semi-spaces
    $arabic = ['ي', 'ك', 'ة', '‌'];
    $farsi  = ['ی', 'ک', 'ه', ' '];
    $color = str_replace($arabic, $farsi, $color);
    $color = str_replace("\xE2\x80\x8C", "", $color);
    // Strip all spacing for perfect normalization matching (e.g. "سرمه ای" and "سرمهای" -> "سرمهای")
    $color = preg_replace('/\s+/', '', $color);
    return trim($color);
}

function normalizeSearchText($text) {
    if (empty($text)) return '';
    $text = mb_strtolower($text, 'UTF-8');
    $arabic = ['ي', 'ك', 'ة', '‌'];
    $farsi  = ['ی', 'ک', 'ه', ' '];
    $text = str_replace($arabic, $farsi, $text);
    // Remove zero-width non joiners completely (نیم‌فاصله)
    $text = str_replace("\xE2\x80\x8C", "", $text);
    // Replace all spaces so "کرم روشن" and "کرمروشن" are identical
    $text = preg_replace('/\s+/', '', $text);
    return trim($text);
}

function formatPriceToToman($priceStr) {
    if (empty($priceStr)) return '';
    $priceStr = trim($priceStr);
    $isRial = (mb_strpos($priceStr, 'ریال') !== false);
    $num = preg_replace('/[^0-9]/', '', $priceStr);
    if ($num) {
        $val = (float)$num;
        if ($isRial) $val = $val / 10;
        return number_format($val) . ' تومان';
    }
    return $priceStr;
}

/**
 * استخراج شناسه و مدل انگلیسی محصول برای تطابق هوشمند اسپورت لند (روش ۲)
 * حذف نام برندهای تعریف‌شده از ابتدای شرح کالا
 */
function extractSportlandModelKey($text, $customBrands = []) {
    if (empty($text) || !is_string($text)) {
        return '';
    }
    
    // در صورت وجود آدرس اینترنتی یا اسلاگ، ابتدا دیکود شود
    if (strpos($text, 'http') === 0 || strpos($text, '%') !== false) {
        $decoded = urldecode($text);
        if ($decoded !== $text) {
            $text .= ' ' . $decoded;
        }
    }
    
    // استخراج کلیه حروف و ارقام انگلیسی و خط فاصله‌ها
    preg_match_all('/[a-zA-Z0-9\-\.\_\/]+/u', $text, $matches);
    if (empty($matches[0])) {
        return '';
    }
    
    $englishStr = implode(' ', $matches[0]);
    $englishStr = trim($englishStr);
    
    // لیست پیش‌فرض برندهای مطرح پوشاک و ورزشی
    $defaultBrands = [
        'oysho', 'nike', 'adidas', 'puma', 'reebok', 'under armour', 'underarmour',
        'zara', 'mango', 'bershka', 'pull&bear', 'pull and bear', 'stradivarius',
        'massimo dutti', 'skechers', 'asics', 'new balance', 'columbia', 'salomon',
        'the north face', 'timberland', 'converse', 'vans', 'champion', 'jordan',
        'decathlon', 'domyos', 'kalenji', 'quechua', 'kipsta', 'artengo', 'nabaiji'
    ];
    
    $brandsToClean = !empty($customBrands) && is_array($customBrands) ? array_merge($customBrands, $defaultBrands) : $defaultBrands;
    
    // پاکسازی نام برند از ابتدای رشته انگلیسی
    foreach ($brandsToClean as $b) {
        $bTrim = trim((string)$b);
        if ($bTrim === '') continue;
        
        $bPattern = '/^' . preg_quote($bTrim, '/') . '[\s\-\:\_\.\#\@]*/i';
        if (preg_match($bPattern, $englishStr)) {
            $englishStr = preg_replace($bPattern, '', $englishStr);
            $englishStr = trim($englishStr);
            break;
        }
    }
    
    // پاکسازی کلمات اضافه مانند کد، مدل، SKU و کاراکترهای جداکننده از ابتدای عبارت
    $englishStr = preg_replace('/^(code|sku|model|ref|art|no|id)[\s\-\:\_\.\#]*/i', '', $englishStr);
    
    // نرمال‌سازی نهایی
    $normalized = mb_strtolower($englishStr);
    $normalized = preg_replace('/[\-\_\.\/\:\#\@\+]/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return trim($normalized);
}

/**
 * پردازش و همگام‌سازی محصولات ورودی از سناریوهای مختلف با الگوریتم هوشمند ادغام
 * پشتیبانی از روش ۱ (استاندارد) و روش ۲ (اسپورت لند با تطابق مدل انگلیسی و تجمیع انبارها)
 */
function processAndSyncProducts(&$project, $incomingItems, $source = 'json', $erpMode = 'standard') {
    if ($source === 'json' && WebsiteCatalogSync::looksLikeWebsite($incomingItems)) {
        $result = WebsiteCatalogSync::reconcile($project, $incomingItems);
        $project = $result['project'];
        return ['new'=>0, 'updated'=>$result['counts']['matched'], 'products'=>$project['products']];
    }
    $contractCount=count(array_filter($incomingItems,static fn($row)=>is_array($row)&&($row['_erp_contract']??'')===ERPInventoryContract::VERSION));
    if($contractCount && $contractCount!==count($incomingItems))throw new RuntimeException('ردیف‌های ERP نسخه‌دار و دادهٔ قدیمی را در یک فایل مخلوط نکنید.');
    if ($incomingItems && $contractCount===count($incomingItems)) {
        $cfg=$project['settings']['tiam_inventory']??[];
        $cfg['mapping']=ERPInventoryContract::mapping($incomingItems);
        $cfg['price_unit']='toman'; // Export contract has already converted source currency.
        $normalized=TiamInventory::normalize($incomingItems,$cfg);
        $result=TiamInventory::merge($project,$normalized,$cfg);
        $project['products']=$result['products'];
        return ['new'=>$result['counts']['new'],'updated'=>$result['counts']['updated'],'products'=>$project['products']];
    }

    $sportlandBrands = $project['settings']['sportland_brands'] ?? [];
    $isSportland = ($erpMode === 'sportland' || $source === 'sportland');

    $groupedIncoming = [];
    foreach ($incomingItems as $item) {
        $rawName = trim($item['name'] ?? $item['ProDes'] ?? $item['pro_des'] ?? $item['title'] ?? 'محصول بدون نام');
        $rawColor = trim($item['color'] ?? $item['Color'] ?? $item['رنگ'] ?? '');
        if ($rawColor === '' || mb_strtolower($rawColor) === 'null') {
            $rawColor = 'عمومی';
        }
        
        $normName = normalizeProductName($rawName);
        $normColor = normalizeColor($rawColor);
        
        // استخراج کلید گروه بر اساس روش انتخابی (روش ۲ اسپورت لند یا روش ۱ استاندارد)
        if ($isSportland) {
            $modelKey = extractSportlandModelKey($rawName, $sportlandBrands);
            if (empty($modelKey) && !empty($item['url'])) {
                $modelKey = extractSportlandModelKey($item['url'], $sportlandBrands);
            }
            
            if (!empty($modelKey)) {
                $groupKey = 'sp_model:' . $modelKey . '|' . $normColor;
            } else {
                $groupKey = $normName . '|' . $normColor;
            }
        } else {
            $groupKey = $normName . '|' . $normColor;
        }
        
        if (!$groupKey || $groupKey === '|' || $groupKey === 'sp_model:|' . $normColor) {
            continue;
        }
        
        if (!isset($groupedIncoming[$groupKey])) {
            $groupedIncoming[$groupKey] = [
                'name' => $rawName,
                'color' => $rawColor,
                'norm_name' => $normName,
                'norm_color' => $normColor,
                'model_key' => $isSportland ? ($modelKey ?? '') : '',
                'items' => []
            ];
        }
        $groupedIncoming[$groupKey]['items'][] = $item;
    }
    
    $newCount = 0;
    $updatedCount = 0;
    $touchedProducts = [];
    $matchedIndices = [];
    
    $existingSkuMap = [];
    $existingKeyMap = [];
    $existingSportlandModelMap = [];

    if (!isset($project['products']) || !is_array($project['products'])) {
        $project['products'] = [];
    }
    foreach ($project['products'] as $idx => $p) {
        if (!empty($p['id'])) {
            $existingSkuMap[trim((string)$p['id'])] = $idx;
        }
        if (!empty($p['sku'])) {
            $existingSkuMap[trim((string)$p['sku'])] = $idx;
        }
        if (isset($p['variants']) && is_array($p['variants'])) {
            foreach ($p['variants'] as $v) {
                if (!empty($v['sku'])) {
                    $existingSkuMap[trim((string)$v['sku'])] = $idx;
                }
            }
        }
        $pName = trim($p['name'] ?? '');
        $pColor = trim($p['color'] ?? '');
        $key = normalizeProductName($pName) . '|' . normalizeColor($pColor);
        $existingKeyMap[$key] = $idx;

        // نقشه مدل انگلیسی برای اسپورت لند
        $pModelKey = extractSportlandModelKey($pName, $sportlandBrands);
        if (empty($pModelKey) && !empty($p['link'])) {
            $pModelKey = extractSportlandModelKey($p['link'], $sportlandBrands);
        }
        if (!empty($pModelKey)) {
            $existingSportlandModelMap[$pModelKey . '|' . normalizeColor($pColor)] = $idx;
            if (!isset($existingSportlandModelMap[$pModelKey])) {
                $existingSportlandModelMap[$pModelKey] = $idx;
            }
        }
    }
    
    foreach ($groupedIncoming as $groupKey => $group) {
        $items = $group['items'];
        
        // Parsing Category Path correctly
        $categoryPath = '';
        $categoriesInfo = ['gender' => '', 'group' => '', 'type' => ''];
        $rawCats = [];
        
        foreach ($items as $item) {
            if (isset($item['category'])) {
                if (is_array($item['category'])) {
                    $rawCats = $item['category'];
                    break;
                } elseif (is_string($item['category']) && trim($item['category']) !== '') {
                    $rawCats = explode('/', $item['category']);
                    break;
                }
            } elseif (isset($item['category_path']) && is_string($item['category_path'])) {
                $rawCats = explode('/', $item['category_path']);
                break;
            }
        }
        
        // Remove empty segments and trim
        $cleanCats = array_filter(array_map('trim', $rawCats), function($val) {
            return $val !== '';
        });
        
        // Color should NOT be stored inside categories! Color must be an independent attribute.
        $cleanCats = array_filter($cleanCats, function($catVal) use ($group) {
            $normCat = normalizeColor($catVal);
            $normColor = normalizeColor($group['color']);
            return !empty($normCat) && $normCat !== $normColor && trim(mb_strtolower($catVal)) !== trim(mb_strtolower($group['color']));
        });
        
        if (!empty($cleanCats)) {
            $cleanCats = array_values($cleanCats);
            $categoriesInfo['gender'] = $cleanCats[0] ?? '';
            $categoriesInfo['group'] = $cleanCats[1] ?? '';
            $categoriesInfo['type'] = $cleanCats[2] ?? '';
            $categoryPath = implode('/', $cleanCats);
        }
        
        $variants = [];
        $uniqueSizes = [];
        $sizeInventoryMap = [];
        $warehouses = [];
        $warehouseDetails = [];
        $totalStockOnline = 0;
        $totalStockTotal = 0;
        $groupHasImage = false;
        
        $gallery = [];
        $mainImage = '';
        $description = '';
        $sizeGuide = '';
        $link = '';
        $priority = 5;
        
        foreach ($items as $item) {
            $rawPrice = $item['price'] ?? '';
            $cleanPrice = (float)preg_replace('/[^0-9]/', '', $rawPrice);
            if (mb_strpos($rawPrice, 'ریال') !== false) {
                $cleanPrice = $cleanPrice / 10;
            }
            
            // شناسایی و پردازش انبارها و موجودی تفکیکی
            $anbarName = trim($item['anbar_name'] ?? $item['AnbarName'] ?? $item['warehouse'] ?? $item['store'] ?? $item['انبار'] ?? $item['فروشگاه'] ?? $item['شعبه'] ?? '');
            $stockOnline = trim((string)($item['stock_tedad'] ?? $item['StockTedad'] ?? $item['stock_online'] ?? $item['quantity'] ?? $item['inventory'] ?? $item['stock'] ?? $item['تعداد'] ?? $item['موجودی'] ?? '0'));
            $stockTotal = trim((string)($item['stock_total'] ?? $item['stock'] ?? ''));
            
            $numStockOnline = (int)preg_replace('/[^0-9]/', '', $stockOnline);
            $numStockTotal = (int)preg_replace('/[^0-9]/', '', $stockTotal);
            if (empty($stockTotal)) {
                $numStockTotal = $numStockOnline;
            }
            
            // جمع زدن موجودی برای انبار مشخص‌شده
            if (!empty($anbarName)) {
                $warehouses[$anbarName] = ($warehouses[$anbarName] ?? 0) + $numStockOnline;
            }
            
            $itemSizes = [];
            if (isset($item['sizes']) && is_array($item['sizes'])) {
                $itemSizes = $item['sizes'];
            } elseif (isset($item['size']) && !is_array($item['size']) && trim($item['size']) !== '') {
                $itemSizes = [trim($item['size'])];
            } else {
                $itemSizes = ['Free Size'];
            }
            
            $itemSku = $item['product_code'] ?? $item['sku'] ?? $item['id'] ?? '';
            foreach ($itemSizes as $sz) {
                $szTrim = trim($sz);
                if ($szTrim === '') continue;
                if (!in_array($szTrim, $uniqueSizes)) {
                    $uniqueSizes[] = $szTrim;
                }
                
                $sizeInventoryMap[$szTrim] = ($sizeInventoryMap[$szTrim] ?? 0) + $numStockOnline;
                
                if (!empty($anbarName)) {
                    $warehouseDetails[] = [
                        'warehouse' => $anbarName,
                        'size' => $szTrim,
                        'color' => $group['color'],
                        'stock' => $numStockOnline,
                        'sku' => $itemSku
                    ];
                }
            }
            
            $totalStockOnline += $numStockOnline;
            $totalStockTotal += $numStockTotal;
            
            if (!empty($item['gallery']) && is_array($item['gallery'])) {
                $gallery = array_merge($gallery, $item['gallery']);
                $groupHasImage = true;
            }
            if (!empty($item['main_image'])) {
                $mainImage = $item['main_image'];
                $groupHasImage = true;
            } elseif (!empty($item['image']) && empty($mainImage)) {
                $mainImage = $item['image'];
                $groupHasImage = true;
            }
            
            if (!empty($item['description']) && empty($description)) {
                $description = $item['description'];
            }
            if (!empty($item['size_guide']) && empty($sizeGuide)) {
                $sizeGuide = $item['size_guide'];
            }
            if (!empty($item['url']) && empty($link)) {
                $link = $item['url'];
            } elseif (!empty($item['link']) && empty($link)) {
                $link = $item['link'];
            }
            if (isset($item['priority'])) {
                $priority = (int)$item['priority'];
            }
        }
        
        // Clean and Sort unique sizes numerically/alphabetically
        $uniqueSizes = array_values(array_unique($uniqueSizes));
        usort($uniqueSizes, function($a, $b) {
            $numA = preg_replace('/[^0-9]/', '', $a);
            $numB = preg_replace('/[^0-9]/', '', $b);
            if ($numA !== '' && $numB !== '') {
                return (float)$numA - (float)$numB;
            }
            return strcasecmp($a, $b);
        });
        
        // Construct variants with aggregated inventories
        foreach ($uniqueSizes as $szTrim) {
            $firstItemSku = $items[0]['product_code'] ?? $items[0]['sku'] ?? $items[0]['id'] ?? '';
            $firstItemPrice = (float)preg_replace('/[^0-9]/', '', $items[0]['price'] ?? '0');
            if (mb_strpos($items[0]['price'] ?? '', 'ریال') !== false) {
                $firstItemPrice = $firstItemPrice / 10;
            }
            $variants[] = [
                'sku' => $firstItemSku,
                'size' => $szTrim,
                'inventory' => $sizeInventoryMap[$szTrim] ?? 0,
                'price' => $firstItemPrice
            ];
        }
        
        $gallery = array_values(array_unique($gallery));
        if (empty($mainImage) && !empty($gallery)) {
            $mainImage = $gallery[0];
        }
        
        // Image sources tagging
        $imagesList = [];
        if ($groupHasImage) {
            if (!empty($mainImage)) {
                $imagesList[] = [
                    'url' => $mainImage,
                    'source' => 'site'
                ];
            }
            foreach ($gallery as $galUrl) {
                if ($galUrl !== $mainImage && !empty($galUrl)) {
                    $imagesList[] = [
                        'url' => $galUrl,
                        'source' => 'site'
                    ];
                }
            }
        }
        
        // Parent SKU Selection Rule
        $parentSku = '';
        $valCount = count($uniqueSizes);
        if ($valCount > 2) {
            $foundSku = '';
            foreach ($variants as $v) {
                if (trim($v['size']) === '3') {
                    $foundSku = $v['sku'];
                    break;
                }
            }
            if (!$foundSku) {
                $thirdSize = $uniqueSizes[2] ?? $uniqueSizes[0];
                foreach ($variants as $v) {
                    if ($v['size'] === $thirdSize) {
                        $foundSku = $v['sku'];
                        break;
                    }
                }
            }
            $parentSku = $foundSku ?: ($variants[0]['sku'] ?? '');
        } elseif ($valCount === 2) {
            $foundSku = '';
            foreach ($variants as $v) {
                if (trim($v['size']) === '2') {
                    $foundSku = $v['sku'];
                    break;
                }
            }
            if (!$foundSku) {
                $secondSize = $uniqueSizes[1] ?? $uniqueSizes[0];
                foreach ($variants as $v) {
                    if ($v['size'] === $secondSize) {
                        $foundSku = $v['sku'];
                        break;
                    }
                }
            }
            $parentSku = $foundSku ?: ($variants[0]['sku'] ?? '');
        } else {
            $parentSku = $variants[0]['sku'] ?? '';
        }
        
        // Display Title with color suffix
        $displayColor = formatPersianColor($group['color']);
        $normalizedColorCheck = normalizeColor($group['color']);
        $productTitle = trim($group['name']);
        
        // Formats/cleans up any existing spacing in the name itself
        $productTitle = formatPersianColor($productTitle);
        
        if (!empty($normalizedColorCheck) && mb_strpos(normalizeProductName($productTitle), $normalizedColorCheck) === false) {
            $productTitle = $productTitle . ' - ' . $displayColor;
        }
        
        $hasImage = $groupHasImage && !empty($mainImage) && (strpos($mainImage, 'placehold') === false);
        $mediaStatus = $hasImage ? 'ok' : 'missing';
        $firstPriceRaw = $items[0]['price'] ?? '';
        $firstPriceFormatted = formatPriceToToman($firstPriceRaw);
        
        // جستجو و تطابق محصول در دیتابیس
        $idx = null;
        
        // 1. تطابق از طریق SKU / کد کالا
        foreach ($items as $itm) {
            $itemSku = trim((string)($itm['product_code'] ?? $itm['sku'] ?? $itm['id'] ?? ''));
            if ($itemSku !== '' && isset($existingSkuMap[$itemSku])) {
                $idx = $existingSkuMap[$itemSku];
                break;
            }
        }
        
        // 2. در حالت اسپورت لند: تطابق بر اساس مدل انگلیسی شرح کالا
        if ($idx === null && $isSportland && !empty($group['model_key'])) {
            $mKey = $group['model_key'];
            $mColorKey = $mKey . '|' . $group['norm_color'];
            if (isset($existingSportlandModelMap[$mColorKey])) {
                $idx = $existingSportlandModelMap[$mColorKey];
            } elseif (isset($existingSportlandModelMap[$mKey])) {
                $idx = $existingSportlandModelMap[$mKey];
            }
        }
        
        // 3. تطابق استاندارد بر اساس نام و رنگ
        if ($idx === null && isset($existingKeyMap[$group['norm_name'] . '|' . $group['norm_color']])) {
            $idx = $existingKeyMap[$group['norm_name'] . '|' . $group['norm_color']];
        }
        
        if ($idx !== null) {
            $matchedIndices[$idx] = true;
            $existing_p = $project['products'][$idx];
            
            // Priority Fields are ALWAYS updated (Sync is fully dynamic)
            $existing_p['stock_online'] = (string)$totalStockOnline;
            $existing_p['stock_total'] = (string)$totalStockTotal;
            $existing_p['sizes'] = $uniqueSizes;
            $existing_p['variants'] = $variants;
            if (!empty($warehouses)) {
                $existing_p['warehouses'] = $warehouses;
            }
            if (!empty($warehouseDetails)) {
                $existing_p['warehouse_details'] = $warehouseDetails;
            }
            $existing_p['extractedAt'] = time();
            
            if ($source === 'scraper' || $source === 'crawl' || $source === 'json') {
                if ($hasImage && empty($existing_p['has_new_manual_image'])) {
                    $existing_p['uploaded_to_site'] = 1;
                    $existing_p['has_new_manual_image'] = false;
                    $existing_p['newly_uploaded_files'] = [];
                }
            } elseif ($source === 'excel') {
                if (!isset($existing_p['uploaded_to_site'])) {
                    $existing_p['uploaded_to_site'] = (!empty($existing_p['image']) && strpos($existing_p['image'], 'placehold') === false) ? 1 : 0;
                }
            }
            
            if ($source === 'excel') {
                // When we already have images or the source is Excel input (ERP central system), we ONLY update inventory and leave other properties completely untouched!
            } else {
                // Determine project settings website domain to support dynamic website URL updates
                $sourceUrl = !empty($project['settings']['sourceUrl']) ? $project['settings']['sourceUrl'] : 'https://www.tanedorost.com';
                $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
                if (empty($sourceHost)) {
                    $sourceHost = 'tanedorost.com';
                }
                
                $isWebsiteImg = function($url) use ($sourceHost) {
                    if (empty($url)) return false;
                    if (strpos($url, 'uploads/') === 0) return false;
                    if (strpos($url, 'tanedorost.com') !== false) return true;
                    if (strpos($url, $sourceHost) !== false) return true;
                    if (preg_match('/^https?:\/\//i', $url)) return true;
                    return false;
                };

                $existing_p['price'] = $firstPriceFormatted;
                
                $isManual = ($existing_p['manualEdit'] ?? false) || ($existing_p['manual_edit'] ?? false) || ($existing_p['edited_by_admin'] ?? false);
                $descLocked = ($existing_p['description_locked'] ?? false);
                
                if (!$isManual && !$descLocked && !empty($description)) {
                    $existing_p['description'] = $description;
                }
                
                if (!empty($categoryPath)) {
                    $existing_p['category_path'] = $categoryPath;
                    $existing_p['categories'] = $categoriesInfo;
                }
                
                if (!empty($sizeGuide) && (empty($existing_p['size_chart']) || strpos($existing_p['size_chart'], 'placehold') !== false)) {
                    $existing_p['size_chart'] = $sizeGuide;
                }
                
                $imagesLocked = ($existing_p['image_locked'] ?? false) || ($existing_p['gallery_locked'] ?? false) || $isManual;
                
                $imageChangedOrAdded = false;
                if ($groupHasImage && !empty($mainImage) && (strpos($mainImage, 'placehold') === false)) {
                    $oldImage = $existing_p['image'] ?? '';
                    $oldHas = !empty($oldImage) && (strpos($oldImage, 'placehold') === false);
                    
                    if (!$oldHas) {
                        $imageChangedOrAdded = true;
                    } elseif ($oldImage !== $mainImage) {
                        $isOldManual = (strpos($oldImage, 'uploads/') === 0) || (($existing_p['is_manual_image'] ?? false) === true);
                        if (!$isOldManual) {
                            $imageChangedOrAdded = true;
                        }
                    }
                }
                
                if (!$imagesLocked && $groupHasImage) {
                    $existingImages = $existing_p['images_list'] ?? $existing_p['images'] ?? [];
                    if (!is_array($existingImages)) {
                        $existingImages = [];
                    }
                    
                    $preservedImages = [];
                    foreach ($existingImages as $img) {
                        if (is_array($img)) {
                            $src = $img['source'] ?? '';
                            $url = $img['url'] ?? '';
                        } else {
                            $src = '';
                            $url = $img;
                        }
                        
                        if ($src === 'instagram' || $src === 'manual' || !$isWebsiteImg($url)) {
                            $preservedImages[] = [
                                'url' => $url,
                                'source' => $src ?: 'manual'
                            ];
                        }
                    }
                    
                    $newSiteImages = [];
                    foreach ($imagesList as $img) {
                        if (isset($img['source']) && $img['source'] === 'site') {
                            $newSiteImages[] = $img;
                        }
                    }
                    
                    $finalImagesList = array_merge($preservedImages, $newSiteImages);
                    
                    $seenUrls = [];
                    $dedupedImagesList = [];
                    foreach ($finalImagesList as $img) {
                        if (empty($img['url'])) continue;
                        if (!in_array($img['url'], $seenUrls)) {
                            $seenUrls[] = $img['url'];
                            $dedupedImagesList[] = $img;
                        }
                    }
                    
                    $existing_p['images_list'] = $dedupedImagesList;
                    if (!empty($dedupedImagesList)) {
                        $existing_p['image'] = $dedupedImagesList[0]['url'];
                        $existing_p['gallery'] = array_column($dedupedImagesList, 'url');
                    } else {
                        $existing_p['image'] = 'https://placehold.co/400x500?text=No+Image';
                        $existing_p['gallery'] = [];
                    }
                }
                
                if ($imageChangedOrAdded) {
                    $existing_p['status'] = 'pending';
                    $existing_p['approvals_stage1'] = [];
                    $existing_p['approvals_stage2'] = [];
                    $existing_p['stage1_decision'] = 'pending';
                    $existing_p['stage2_decision'] = 'pending';
                    $existing_p['rejection_reason'] = '';
                    $existing_p['rejectionReason'] = '';
                }
            }
            
            if ($totalStockOnline <= 0) {
                if (($existing_p['status'] ?? '') !== 'out_of_stock') {
                    $existing_p['prev_status'] = $existing_p['status'] ?? 'active';
                }
                $existing_p['status'] = 'out_of_stock';
            } else {
                if (($existing_p['status'] ?? '') === 'out_of_stock') {
                    $existing_p['status'] = !empty($existing_p['prev_status']) ? $existing_p['prev_status'] : 'active';
                }
            }
            
            $existing_p['has_image'] = !empty($existing_p['image']) && (strpos($existing_p['image'], 'placehold') === false);
            $existing_p['media_status'] = $existing_p['has_image'] ? 'ok' : 'missing';
            
            $project['products'][$idx] = $existing_p;
            $touchedProducts[] = $existing_p;
            $updatedCount++;
        } else {
            $isExcelSource = ($source === 'excel');
            
            $finalImage = $mainImage ?: 'https://placehold.co/400x500?text=No+Image';
            $finalGallery = $gallery;
            $finalImagesList = $imagesList;
            $finalHasImage = $hasImage;
            $finalMediaStatus = $mediaStatus;
            
            if ($isExcelSource) {
                $finalImage = 'https://placehold.co/400x500?text=No+Image';
                $finalGallery = [];
                $finalImagesList = [];
                $finalHasImage = false;
                $finalMediaStatus = 'missing';
            }
            
            $newProduct = [
                'id' => 'prod_'.substr(hash('sha256', (string)$project['id']."\0".$groupKey), 0, 24),
                'sku' => $parentSku,
                'name' => $productTitle,
                'color' => formatPersianColor($group['color']),
                'price' => $firstPriceFormatted,
                'sizes' => $uniqueSizes,
                'variants' => $variants,
                'image' => $finalImage,
                'gallery' => $finalGallery,
                'images_list' => $finalImagesList,
                'link' => $link,
                'description' => $description,
                'size_chart' => $sizeGuide,
                'category_path' => $categoryPath,
                'categories' => $categoriesInfo,
                'stock_online' => (string)$totalStockOnline,
                'stock_total' => (string)$totalStockTotal,
                'warehouses' => $warehouses,
                'warehouse_details' => $warehouseDetails,
                'extractedAt' => time(),
                'status' => 'pending',
                'manualEdit' => false,
                'has_image' => $finalHasImage,
                'media_status' => $finalMediaStatus,
                'priority' => $priority,
                'uploaded_to_site' => ($finalHasImage && ($source === 'scraper' || $source === 'crawl' || $source === 'json' || (strpos($finalImage, 'uploads/') !== 0))) ? 1 : 0,
                'has_new_manual_image' => false,
                'newly_uploaded_files' => []
            ];
            
            $project['products'][] = $newProduct;
            $newProductIndex = count($project['products']) - 1;
            $matchedIndices[$newProductIndex] = true;
            $touchedProducts[] = $newProduct;
            $newCount++;
        }
    }
    
    // Mark products not present in the incoming sync as Out of Stock (unavailable)
    if ($source === 'json' || $source === 'crawl' || $source === 'excel' || $source === 'scraper') {
        foreach ($project['products'] as $idx => &$p) {
            if (!isset($matchedIndices[$idx])) {
                if (($p['status'] ?? '') !== 'out_of_stock') {
                    $p['prev_status'] = $p['status'] ?? 'active';
                    $p['status'] = 'out_of_stock';
                }
                $p['stock_online'] = '0';
                $p['stock_total'] = '0';
                $p['extractedAt'] = time();
                $project['products'][$idx] = $p;
            }
        }
    }
    
    return [
        'new' => $newCount,
        'updated' => $updatedCount,
        'products' => $touchedProducts
    ];
}

// ==========================================
// توابع رتبه‌بندی، مرتب‌سازی و گروه‌بندی محصولات (Product Sorter Module)
// ==========================================

function getProductEngagementScore($p) {
    $likes = (int)($p['likes'] ?? 0);
    $dislikes = (int)($p['dislikes'] ?? 0);
    
    $posComments = 0;
    $negComments = 0;
    $comments = $p['userFeedback'] ?? $p['user_feedback'] ?? [];
    if (!is_array($comments)) {
        $comments = json_decode($comments, true) ?: [];
    }
    foreach ($comments as $c) {
        $cat = strtolower($c['category'] ?? '');
        if ($cat === 'positive') {
            $posComments++;
        } elseif ($cat === 'negative') {
            $negComments++;
        }
    }
    
    return ($likes + $posComments) - ($dislikes + $negComments);
}

function getProductSkuNumeric($p) {
    if (empty($p['sku'])) {
        return 0;
    }
    $num = preg_replace('/[^0-9]/', '', $p['sku']);
    return $num ? (float)$num : 0;
}

function getNumericPrice($priceStr) {
    if (empty($priceStr)) return 0;
    $num = preg_replace('/[^0-9]/', '', $priceStr);
    return $num ? (float)$num : 0;
}

function getProductApprovedAt($p) {
    if (!empty($p['approved_at'])) {
        return (int)$p['approved_at'];
    }
    
    // If active (approved) status and no explicit approved_at, default to extractedAt to rank newest additions on top!
    $status = $p['status'] ?? 'active';
    if ($status === 'active' || empty($status)) {
        $extTime = (int)($p['extractedAt'] ?? $p['extracted_at'] ?? 0);
        if ($extTime > 0) {
            return $extTime;
        }
    }
    
    // Check if there is an approval feedback or log
    if (!empty($p['userFeedback']) && is_array($p['userFeedback'])) {
        $lastTime = 0;
        foreach ($p['userFeedback'] as $fb) {
            $user = $fb['user'] ?? $fb['visitor'] ?? '';
            if ((stripos($user, 'Approver') !== false || stripos($user, 'Admin') !== false || ($fb['category'] ?? '') === 'positive') && !empty($fb['at'])) {
                if ((int)$fb['at'] > $lastTime) {
                    $lastTime = (int)$fb['at'];
                }
            }
        }
        if ($lastTime > 0) {
            return $lastTime;
        }
    }
    
    // Skus starting with a year or large number
    $skuNum = getProductSkuNumeric($p);
    if ($skuNum > 1000000) {
        return $skuNum;
    }
    
    // Parsed numeric ID as fallback
    $idNum = (int)preg_replace('/[^0-9]/', '', $p['id'] ?? '0');
    if ($idNum > 1000000) {
        return $idNum;
    }
    
    return 0;
}

/**
 * دریافت موجودی عددی واقعی یک محصول (تک آیتم)
 */
function getProductItemStock($p) {
    if (empty($p) || !is_array($p)) return 0;
    if (isProductOutOfStock($p)) return 0;
    
    // اگر وریانت دارد، مجموع موجودی وریانت‌ها را بررسی می‌کنیم
    if (!empty($p['variants'])) {
        $vars = is_array($p['variants']) ? $p['variants'] : json_decode($p['variants'], true);
        if (is_array($vars) && !empty($vars)) {
            $vStock = 0;
            $hasValidVStock = false;
            foreach ($vars as $v) {
                if (isset($v['stock']) || isset($v['stock_online']) || isset($v['quantity']) || isset($v['count'])) {
                    $raw = normalizeDigitsToEnglish((string)($v['stock'] ?? $v['stock_online'] ?? $v['quantity'] ?? $v['count'] ?? 0));
                    $val = (float)preg_replace('/[^0-9\.\-]/', '', $raw);
                    if ($val > 0) {
                        $vStock += $val;
                        $hasValidVStock = true;
                    }
                }
            }
            if ($hasValidVStock && $vStock > 0) {
                return (int)$vStock;
            }
        }
    }
    
    $stockTotalStr = normalizeDigitsToEnglish(trim((string)($p['stock_total'] ?? '')));
    $stockOnlineStr = normalizeDigitsToEnglish(trim((string)($p['stock_online'] ?? '')));
    $stockStr = normalizeDigitsToEnglish(trim((string)($p['stock'] ?? '')));
    $qtyStr = normalizeDigitsToEnglish(trim((string)($p['quantity'] ?? $p['count'] ?? $p['inventory'] ?? '')));
    
    if ($stockTotalStr !== '' && mb_stripos($stockTotalStr, 'ناموجود') === false) {
        $val = (float)preg_replace('/[^0-9\.\-]/', '', $stockTotalStr);
        if ($val > 0) return (int)$val;
    }
    
    if ($stockOnlineStr !== '' && mb_stripos($stockOnlineStr, 'ناموجود') === false) {
        $val = (float)preg_replace('/[^0-9\.\-]/', '', $stockOnlineStr);
        if ($val > 0) return (int)$val;
    }
    
    if ($stockStr !== '' && mb_stripos($stockStr, 'ناموجود') === false) {
        $val = (float)preg_replace('/[^0-9\.\-]/', '', $stockStr);
        if ($val > 0) return (int)$val;
    }

    if ($qtyStr !== '' && mb_stripos($qtyStr, 'ناموجود') === false) {
        $val = (float)preg_replace('/[^0-9\.\-]/', '', $qtyStr);
        if ($val > 0) return (int)$val;
    }
    
    return 0;
}

/**
 * استخراج کلید خانواده/پدر محصول جهت تجمیع تمام رنگ‌بندی‌ها و تنوع‌های یک محصول یکسان
 */
function getProductFamilyKey($p) {
    if (empty($p) || !is_array($p)) return '';
    
    // ۱. اگر کد مدل مشخصی دارد
    $model = trim((string)($p['model_code'] ?? $p['modelCode'] ?? $p['model'] ?? ''));
    if (!empty($model)) {
        return 'model:' . mb_strtolower($model);
    }
    
    // ۲. اگر لینک معتبر محصول وجود دارد (حذف پارامترهای GET و هش)
    $link = trim((string)($p['link'] ?? ''));
    if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
        $cleanLink = preg_replace('/\?.*$/', '', $link);
        $cleanLink = rtrim($cleanLink, '/');
        if (strlen($cleanLink) > 10) {
            return 'link:' . mb_strtolower($cleanLink);
        }
    }
    
    // ۳. نام پایه محصول (بدون پسوند رنگ)
    $name = trim((string)($p['name'] ?? ''));
    $color = trim((string)($p['color'] ?? ''));
    
    if (!empty($color)) {
        $nameWithoutColor = preg_replace('/(\s*[-–—|\/]\s*|\s+رنگ\s+)' . preg_quote($color, '/') . '$/ui', '', $name);
        if (!empty($nameWithoutColor) && mb_strlen($nameWithoutColor) >= 2) {
            $name = $nameWithoutColor;
        }
    }

    // حذف رنگ‌های رایج از انتهای نام برای یکسان‌سازی دقیق خانواده کالا
    $nameWithoutAnyColor = preg_replace('/(\s*[-–—|\/]\s*|\s+رنگ\s+)(مشکی|سفید|قرمز|آبی|سبز|زرد|طوسی|کرم|سورمه‌ای|سرمه‌ای|قهوه‌ای|نارنجی|بنفش|صورتی|یشمی|زرشکی|زیتونی|نسکافه‌ای|خردلی|نوک مدادی|طوسی روشن|طوسی تیره|کالباسی|بادمجانی|کله غازی|فیروزه‌ای|یاسی|لیمویی|نقره‌ای|طلایی|خاکی|شکلاتی|شتری|پنککی|بژ|سربی|دودی)\s*$/ui', '', $name);
    if (!empty($nameWithoutAnyColor) && mb_strlen($nameWithoutAnyColor) >= 2) {
        $name = $nameWithoutAnyColor;
    }
    
    $normName = trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
    $normName = str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $normName);
    $normName = preg_replace('/[\x{200c}\x{200b}]/u', '', $normName); // ZWNJ
    if (!empty($normName)) {
        return 'name:' . $normName;
    }
    
    // ۴. کد SKU پایه (حذف پسوندهای رنگی یا پسوند وریانت)
    $sku = trim((string)($p['sku'] ?? ''));
    if (!empty($sku)) {
        $baseSku = preg_replace('/[-_][a-zA-Z0-9]+$/', '', $sku);
        return 'sku:' . mb_strtolower($baseSku);
    }
    
    return 'id:' . ($p['id'] ?? 'unknown');
}

/**
 * ایجاد جدول تجمیعی موجودی کل خانواده هر محصول بر اساس مجموع موجودی تمام رنگ‌ها و تنوع‌ها
 */
function buildProductFamilyStockMap($products) {
    $map = [];
    if (empty($products) || !is_array($products)) return $map;
    
    foreach ($products as $p) {
        if (empty($p) || !is_array($p)) continue;
        $key = getProductFamilyKey($p);
        if (empty($key)) continue;
        
        $stock = getProductItemStock($p);
        $map[$key] = ($map[$key] ?? 0) + $stock;
    }
    
    return $map;
}

/**
 * دریافت موجودی تجمیعی کل یک محصول همراه با تمام رنگ‌بندی‌های دیگر آن
 */
function getProductAggregatedStock($p, $familyStockMap = null, $allProducts = null) {
    if (empty($p) || !is_array($p)) return 0;
    $key = getProductFamilyKey($p);
    
    if (is_array($familyStockMap) && isset($familyStockMap[$key])) {
        return (int)$familyStockMap[$key];
    }
    
    if (is_array($allProducts) && !empty($allProducts)) {
        $stockSum = 0;
        foreach ($allProducts as $item) {
            if (getProductFamilyKey($item) === $key) {
                $stockSum += getProductItemStock($item);
            }
        }
        return (int)$stockSum;
    }
    
    return getProductItemStock($p);
}

function sortProductsForListing($products, $sortBy = 'default') {
    if (empty($products)) return [];
    
    if ($sortBy === 'stock_desc') {
        usort($products, function($a, $b) {
            $stA = getProductItemStock($a);
            $stB = getProductItemStock($b);
            if ($stA == $stB) {
                return getProductSkuNumeric($b) <=> getProductSkuNumeric($a);
            }
            return ($stB > $stA) ? 1 : -1;
        });
        return $products;
    } elseif ($sortBy === 'stock_asc') {
        usort($products, function($a, $b) {
            $stA = getProductItemStock($a);
            $stB = getProductItemStock($b);
            if ($stA == $stB) {
                return getProductSkuNumeric($a) <=> getProductSkuNumeric($b);
            }
            return ($stA > $stB) ? 1 : -1;
        });
        return $products;
    } elseif ($sortBy === 'newest') {
        usort($products, function($a, $b) {
            $skuA = getProductSkuNumeric($a);
            $skuB = getProductSkuNumeric($b);
            if ($skuA == $skuB) return 0;
            return ($skuB > $skuA) ? 1 : -1;
        });
        return $products;
    } elseif ($sortBy === 'oldest') {
        usort($products, function($a, $b) {
            $skuA = getProductSkuNumeric($a);
            $skuB = getProductSkuNumeric($b);
            if ($skuA == $skuB) return 0;
            return ($skuA > $skuB) ? 1 : -1;
        });
        return $products;
    } elseif ($sortBy === 'cheapest') {
        usort($products, function($a, $b) {
            $pA = getNumericPrice($a['price'] ?? '');
            $pB = getNumericPrice($b['price'] ?? '');
            if ($pA == $pB) return 0;
            return ($pA > $pB) ? 1 : -1;
        });
        return $products;
    } elseif ($sortBy === 'expensive') {
        usort($products, function($a, $b) {
            $pA = getNumericPrice($a['price'] ?? '');
            $pB = getNumericPrice($b['price'] ?? '');
            if ($pA == $pB) return 0;
            return ($pB > $pA) ? 1 : -1;
        });
        return $products;
    }
    
    // Default: Sort by last approval time / date descending, then engagement score
    usort($products, function($a, $b) {
        $appA = getProductApprovedAt($a);
        $appB = getProductApprovedAt($b);
        if ($appA !== $appB) {
            return ($appB > $appA) ? 1 : -1;
        }
        
        $scoreA = getProductEngagementScore($a);
        $scoreB = getProductEngagementScore($b);
        if ($scoreA !== $scoreB) {
            return ($scoreB > $scoreA) ? 1 : -1;
        }
        
        $skuA = getProductSkuNumeric($a);
        $skuB = getProductSkuNumeric($b);
        return ($skuB > $skuA) ? 1 : -1;
    });
    
    return $products;
}

function cleanupProductApprovedReferenceImages(&$p) {
    if (($p['status'] ?? '') === 'active') {
        unset($p['content_gen_requested']);
        $ref = $p['reference_gallery'] ?? [];
        if (is_string($ref)) {
            $ref = json_decode($ref, true) ?: [];
        }
        if (is_array($ref) && !empty($ref)) {
            $ref = array_values(array_filter($ref));
            if (!empty($ref)) {
                // If main image is missing, empty, or placeholder, promote the first reference gallery image to main image
                $hasMainImage = !empty($p['image']) && strpos($p['image'], 'placehold') === false && strpos($p['image'], 'placeholder') === false && strpos($p['image'], 'no-img') === false;
                if (!$hasMainImage) {
                    $p['image'] = array_shift($ref);
                    $p['is_manual_image'] = 1;
                    $p['manualEdit'] = true;
                }
                // Add remaining reference gallery images to product gallery if not already present
                if (!empty($ref)) {
                    $gal = $p['gallery'] ?? [];
                    if (is_string($gal)) {
                        $gal = json_decode($gal, true) ?: [];
                    }
                    if (!is_array($gal)) {
                        $gal = [];
                    }
                    foreach ($ref as $rImg) {
                        if (!empty($rImg) && !in_array($rImg, $gal) && $rImg !== ($p['image'] ?? '')) {
                            $gal[] = $rImg;
                        }
                    }
                    $p['gallery'] = $gal;
                }
            }
            $p['reference_gallery'] = [];
        }
    }
}

function refreshProductSplitStatus(&$p, $project) {
    $isSplit = !empty($project['settings']['prod_approval_split_enabled']);
    if (!$isSplit) {
        return;
    }
    
    ProductApprovalFlow::record($p,$project['settings']);
    if(($p['stage1_decision']??'')==='rejected'||($p['stage2_decision']??'')==='rejected'){$p['status']='rejected';return;}
    $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
    $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);
    
    $st1_count = count($p['approvals_stage1'] ?? []);
    $st2_count = count($p['approvals_stage2'] ?? []);
    
    $st1_rej = !empty($p['stage1_decision']) && $p['stage1_decision'] === 'rejected';
    $st2_rej = !empty($p['stage2_decision']) && $p['stage2_decision'] === 'rejected';
    
    $st1_app = $st1_count >= $st1_min || (!empty($p['stage1_decision']) && $p['stage1_decision'] === 'approved');
    $st2_app = $st2_count >= $st2_min || (!empty($p['stage2_decision']) && $p['stage2_decision'] === 'approved');
    
    if ($st1_app) {
        $p['stage1_decision'] = 'approved';
    } elseif ($st1_rej) {
        $p['stage1_decision'] = 'rejected';
    } else {
        $p['stage1_decision'] = 'pending';
    }
    
    if ($st2_app) {
        $p['stage2_decision'] = 'approved';
    } elseif ($st2_rej) {
        $p['stage2_decision'] = 'rejected';
    } else {
        $p['stage2_decision'] = 'pending';
    }
    
    if ($p['stage1_decision'] !== 'pending' && $p['stage2_decision'] !== 'pending') {
        if ($p['stage1_decision'] === 'approved' && $p['stage2_decision'] === 'approved') {
            $p['status'] = 'active';
            approveProductImages($p);
            cleanupProductApprovedReferenceImages($p);
            
            // Rebuild images_list to tag manual/instagram sources
            $imagesList = [];
            if (!empty($p['image'])) {
                $isLocal = (strpos($p['image'], 'uploads/') === 0);
                $imagesList[] = [
                    'url' => $p['image'],
                    'source' => $isLocal ? 'manual' : 'site'
                ];
            }
            if (!empty($p['social_image'])) {
                $imagesList[] = [
                    'url' => $p['social_image'],
                    'source' => 'instagram'
                ];
            }
            if (!empty($p['gallery']) && is_array($p['gallery'])) {
                foreach ($p['gallery'] as $galEntry) {
                    $galUrl = ProductSizeChartCatalog::entryUrl($galEntry);
                    if ($galUrl === '') continue;
                    if (!empty($p['image']) && $galUrl === ProductSizeChartCatalog::entryUrl($p['image'])) continue;
                    $isLocal = (bool)preg_match('~^(?:/panel/|/)?uploads/~', $galUrl);
                    $imagesList[] = [
                        'url' => $galUrl,
                        'source' => $isLocal ? 'manual' : 'site'
                    ];
                }
            }
            $p['images_list'] = $imagesList;
            approveProductImages($p);
            $p['has_image'] = !empty($p['image']) && strpos($p['image'], 'placeholder') === false && strpos($p['image'], 'placehold.co') === false;
            $p['media_status'] = $p['has_image'] ? 'ok' : 'missing';
        } else {
            $p['status'] = 'rejected';
        }
    } else {
        $p['status'] = 'pending';
    }
}

function refreshScheduleSplitStatus(&$s, $project) {
    $isSplit = !empty($project['settings']['cal_approval_split_enabled']);
    if (!$isSplit) {
        return;
    }
    
    $st1_min = (int)($project['settings']['cal_approval_stage1_min'] ?? 1);
    $st2_min = (int)($project['settings']['cal_approval_stage2_min'] ?? 1);
    
    $st1_count = count($s['approvals_stage1'] ?? []);
    $st2_count = count($s['approvals_stage2'] ?? []);
    
    $st1_rej = !empty($s['stage1_decision']) && $s['stage1_decision'] === 'rejected';
    $st2_rej = !empty($s['stage2_decision']) && $s['stage2_decision'] === 'rejected';
    
    $st1_app = $st1_count >= $st1_min || (!empty($s['stage1_decision']) && $s['stage1_decision'] === 'approved');
    $st2_app = $st2_count >= $st2_min || (!empty($s['stage2_decision']) && $s['stage2_decision'] === 'approved');
    
    if ($st1_app) {
        $s['stage1_decision'] = 'approved';
    } elseif ($st1_rej) {
        $s['stage1_decision'] = 'rejected';
    } else {
        $s['stage1_decision'] = 'pending';
    }
    
    if ($st2_app) {
        $s['stage2_decision'] = 'approved';
    } elseif ($st2_rej) {
        $s['stage2_decision'] = 'rejected';
    } else {
        $s['stage2_decision'] = 'pending';
    }
    
    if ($s['stage1_decision'] !== 'pending' && $s['stage2_decision'] !== 'pending') {
        if ($s['stage1_decision'] === 'approved' && $s['stage2_decision'] === 'approved') {
            $s['approvalStatus'] = 'approved';
        } else {
            $s['approvalStatus'] = 'rejected';
        }
    } else {
        $s['approvalStatus'] = 'pending';
    }
}

/**
 * تست پینگ یک گره شبکه به صورت اتصال TCP جهت بازدهی سریع و بهینه
 */
function pingTcpNode($host, $port, $timeout = 1.5) {
    if (empty($host) || empty($port)) {
        return false;
    }
    $start = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        $duration = round((microtime(true) - $start) * 1000, 1);
        fclose($fp);
        return $duration;
    }
    return false;
}

/**
 * پارس کردن فرمت‌های مختلف کانفیگ V2Ray (socks, socks5, http, https, vmess, vless, ss, trojan)
 */
function parseV2rayConfigLine($line) {
    $line = trim($line);
    if (empty($line)) return null;
    
    $parsed = [
        'raw' => $line,
        'protocol' => '',
        'host' => '',
        'port' => 0,
        'user' => '',
        'pass' => '',
        'name' => 'گره بی‌نام',
        'usable_as_proxy' => false
    ];
    
    $hash_pos = strpos($line, '#');
    if ($hash_pos !== false) {
        $parsed['name'] = urldecode(substr($line, $hash_pos + 1));
        $line_no_hash = substr($line, 0, $hash_pos);
    } else {
        $line_no_hash = $line;
    }
    
    if (preg_match('/^(socks5|socks|http|https|ss|vmess|vless|trojan):\/\/(.*)$/i', $line_no_hash, $matches)) {
        $protocol = strtolower($matches[1]);
        $body = $matches[2];
        $parsed['protocol'] = $protocol;
        
        if ($protocol === 'vmess') {
            $json_str = base64_decode($body, true);
            if ($json_str) {
                $json_str = trim($json_str);
                $json = json_decode($json_str, true);
                if ($json) {
                    $parsed['host'] = $json['add'] ?? '';
                    $parsed['port'] = intval($json['port'] ?? 0);
                    $parsed['name'] = $json['ps'] ?? $parsed['name'];
                }
            }
        } elseif ($protocol === 'socks5' || $protocol === 'socks' || $protocol === 'http' || $protocol === 'https') {
            $parsed['usable_as_proxy'] = true;
            $url_parts = parse_url($line_no_hash);
            if ($url_parts) {
                $parsed['host'] = $url_parts['host'] ?? '';
                $parsed['port'] = intval($url_parts['port'] ?? 0);
                $parsed['user'] = $url_parts['user'] ?? '';
                $parsed['pass'] = $url_parts['pass'] ?? '';
            }
        } elseif ($protocol === 'ss') {
            if (strpos($body, '@') !== false) {
                $parts = explode('@', $body, 2);
                $host_port_hash = $parts[1];
                $hp = explode(':', $host_port_hash);
                $parsed['host'] = $hp[0] ?? '';
                $parsed['port'] = intval($hp[1] ?? 0);
            } else {
                $decoded = base64_decode($body, true);
                if ($decoded && strpos($decoded, '@') !== false) {
                    $parts = explode('@', $decoded, 2);
                    $host_port = $parts[1];
                    $hp = explode(':', $host_port);
                    $parsed['host'] = $hp[0] ?? '';
                    $parsed['port'] = intval($hp[1] ?? 0);
                }
            }
        } elseif ($protocol === 'trojan' || $protocol === 'vless') {
            if (strpos($body, '@') !== false) {
                $parts = explode('@', $body, 2);
                $host_port_query = $parts[1];
                $q_pos = strpos($host_port_query, '?');
                if ($q_pos !== false) {
                    $host_port_query = substr($host_port_query, 0, $q_pos);
                }
                $hp = explode(':', $host_port_query);
                $parsed['host'] = $hp[0] ?? '';
                $parsed['port'] = intval($hp[1] ?? 0);
            }
        }
    }
    
    if (!empty($parsed['host']) && $parsed['port'] > 0) {
        return $parsed;
    }
    return null;
}

/**
 * تست پینگ واقعی و دور زدن فیلترینگ تا تلگرام با ریکوئست واقعی cURL
 */
function testProxyToTelegram($host, $port, $protocol, $user = '', $pass = '', $timeout = 2.0) {
    if (empty($host) || empty($port)) {
        return false;
    }
    
    $start = microtime(true);
    $ch = curl_init("https://api.telegram.org/"); // هدف تست مستقیم با دامنه رسمی تلگرام است
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, ceil($timeout));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, ceil($timeout));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    curl_setopt($ch, CURLOPT_PROXY, $host . ':' . $port);
    if (!empty($user) && !empty($pass)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $user . ':' . $pass);
    }
    
    $type = strtolower($protocol);
    if ($type === 'socks5') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    } elseif ($type === 'socks5_hostname') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
    
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($data !== false || ($http_code >= 200 && $http_code < 505)) {
        return round((microtime(true) - $start) * 1000, 1);
    }
    return false;
}

/**
 * دریافت لینک اشتراک، پارس و عیب‌یابی پینگ تمام کانکشن‌های موجود در اشتراک V2Ray
 */
function fetchAndPingV2raySubscription($subUrl, $proxy = null) {
    if (empty($subUrl)) {
        return ['error' => 'لینک اشتراک یا کانفیگ وارد نشده است'];
    }
    
    $data = '';
    
    // تشخیص اینکه آیا ورودی یک لینک تکی HTTP/HTTPS است یا محتوای کپی شده کانفیگ تکی/چندتایی
    if (preg_match('/^https?:\/\/[^\s]+$/i', trim($subUrl))) {
        // تلاش اول: دریافت مستقیم از اینترنت بدون پروکسی
        $ch = curl_init($subUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'v2rayN/6.23 Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $data = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        // تلاش دوم: اگر خطا داد یا فیلتر بود، لود از روی کانال پروکسی انتخابی قبلی کاربر
        if (empty($data) && $proxy && !empty($proxy['server'])) {
            $ch = curl_init($subUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'v2rayN/6.23 Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            
            // اعمال پروکسی
            curl_setopt($ch, CURLOPT_PROXY, $proxy['server']);
            if (!empty($proxy['user']) && !empty($proxy['pass'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['user'] . ':' . $proxy['pass']);
            }
            $type = isset($proxy['type']) ? strtolower($proxy['type']) : 'socks5_hostname';
            if ($type === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } elseif ($type === 'socks5_hostname') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
            
            $data = curl_exec($ch);
            if (!empty($data)) {
                $err = ''; // لود با موفقیت انجام شد
            }
            curl_close($ch);
        }
        
        if (empty($data)) {
            return ['error' => 'امکان دریافت اطلاعات اشتراک وجود نداشت. خطای شبکه: ' . $err . ' (می‌توانید کدهای کانفیگ را هم کپی کنید و مستقیماً در این باکس پیست کنید!)'];
        }
    } else {
        // ورودی مستقیماً کدهای کانفیگ کپی شده است
        $data = $subUrl;
    }
    
    $content = '';
    $decoded = base64_decode($data, true);
    if ($decoded !== false && preg_match('/^[a-zA-Z0-9+\/=\s\x00-\x1F\x7F]+$/', trim($data))) {
        $content = $decoded;
    } else {
        $cleaned = str_replace(["\r", "\n", " ", "\t"], "", $data);
        $decodedClean = base64_decode($cleaned, true);
        if ($decodedClean !== false && preg_match('/^[a-zA-Z0-9+\/=\s\x00-\x1F\x7F]+$/', $cleaned)) {
            $content = $decodedClean;
        } else {
            $content = $data;
        }
    }
    
    $lines = explode("\n", str_replace("\r", "", $content));
    $nodes = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parsed = parseV2rayConfigLine($line);
        if ($parsed) {
            $nodes[] = $parsed;
        }
    }
    
    if (empty($nodes)) {
        return ['error' => 'هیچ گره معتبری با استانداردهای شناخته شده (vmess, vless, trojan, socks5, http, ss) استخراج نشد.'];
    }
    
    $testedNodes = [];
    foreach ($nodes as $node) {
        // تست و پینگ اولیه پورت TCP
        $tcp = pingTcpNode($node['host'], $node['port'], 1.2);
        
        if ($tcp !== false) {
            if ($node['usable_as_proxy'] ?? false) {
                // اگر پروتکل ساده و مستقیماً در PHP/cURL قابل استفاده باشد، پینگ واقعی پروکسی را تست کن
                $realPing = testProxyToTelegram($node['host'], $node['port'], $node['protocol'], $node['user'], $node['pass'], 2.0);
                if ($realPing !== false) {
                    $node['ping'] = $realPing;
                    $node['status'] = 'online';
                } else {
                    $node['ping'] = false;
                    $node['status'] = 'false_positive'; // پورت باز است اما ترافیک پروکسی رد نمی‌شود
                }
            } else {
                // پروتکل‌هایی مانند VLESS/VMESS/Trojan/SS که برای اجرا نیاز به هسته Xray محلی دارند را باز و آنلاین می‌شناسیم اما لیبل می‌زنیم
                $node['ping'] = $tcp;
                $node['status'] = 'online_non_native';
            }
        } else {
            $node['ping'] = false;
            $node['status'] = 'offline';
        }
        $testedNodes[] = $node;
    }
    
    usort($testedNodes, function($a, $b) {
        // اولویت‌دهی به گره‌های فعال و پرسرعت مستقیم، سپس بقیه گره‌ها
        $statusA = $a['status'];
        $statusB = $b['status'];
        
        $rank = [
            'online' => 1,
            'online_non_native' => 2,
            'false_positive' => 3,
            'offline' => 4
        ];
        
        $rA = $rank[$statusA] ?? 5;
        $rB = $rank[$statusB] ?? 5;
        
        if ($rA !== $rB) {
            return $rA <=> $rB;
        }
        
        return $a['ping'] <=> $b['ping'];
    });
    
    return $testedNodes;
}

/**
 * زمان‌بندی خودکار کالا در اولین اسلات خالی بعدی تقویم
 * @param array $project مرجع پروژه به‌همراه تنظیمات، کالاها و لیست زمان‌بندی‌ها
 * @return bool
 */
function autoScheduleNextProduct(&$project) {
    $slots = $project['settings']['posting_slots'] ?? [];
    if (empty($slots)) {
        return false;
    }
    
    // 1. یافتن کالاهایی که فعال بوده و هنوز در لیست زمان‌بندی تقویم نیستند
    $scheduledProductIds = [];
    foreach ($project['schedules'] ?? [] as $s) {
        if (!empty($s['productId'])) {
            $scheduledProductIds[] = $s['productId'];
        }
    }
    
    $candidates = $project['products'] ?? [];
    $candidates = array_filter($candidates, function($p) use ($scheduledProductIds) { 
        return ($p['status'] ?? 'active') === 'active' && !in_array($p['id'], $scheduledProductIds); 
    });
    
    if (empty($candidates)) {
        return false; // هیچ کالای کاندیدی یافت نشد
    }
    
    // مرتب‌سازی بر اساس اولویت تولید محتوا و زمان استخراج
    usort($candidates, function($a, $b) {
        $pDiff = ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
        if ($pDiff !== 0) return $pDiff;
        return ($b['extractedAt'] ?? 0) - ($a['extractedAt'] ?? 0);
    });
    
    $item = reset($candidates); // انتخاب برترین کاندید
    
    // 2. شروع اسکن روزها برای یافتن اولین بازه زمانی خالی بعد از آخرین زمان‌بندی کنونی
    $baseTime = time();
    foreach ($project['schedules'] ?? [] as $s) {
        $sAt = is_numeric($s['scheduledAt'] ?? 0) ? $s['scheduledAt'] : strtotime($s['scheduledAt'] ?? 'now');
        if ($sAt > $baseTime) {
            $baseTime = $sAt;
        }
    }
    
    $foundSlot = null;
    for ($i = 0; $i < 30; $i++) {
        $targetDateTs = strtotime("+$i days", $baseTime);
        $dayNameEn = date('l', $targetDateTs);
        $daySlotsStr = $slots[$dayNameEn] ?? '';
        $daySlots = array_filter(array_map('trim', explode(',', $daySlotsStr)));
        sort($daySlots); // ترتیب صعودی ساعات انتشار در روز
        
        foreach ($daySlots as $timeStr) {
            $scheduledAtStr = date('Y-m-d', $targetDateTs) . ' ' . $timeStr;
            $scheduledAtTs = strtotime($scheduledAtStr);
            
            if ($scheduledAtTs <= time()) {
                continue; // عدم ثبت زمان‌بندی برای گذشته
            }
            
            // بررسی همپوشانی با تقویم
            $alreadyExists = false;
            foreach ($project['schedules'] ?? [] as $s) {
                $sAt = is_numeric($s['scheduledAt'] ?? 0) ? $s['scheduledAt'] : strtotime($s['scheduledAt'] ?? 'now');
                if (date('Y-m-d H:i', $sAt) === date('Y-m-d H:i', $scheduledAtTs)) {
                    $alreadyExists = true;
                    break;
                }
            }
            
            if (!$alreadyExists) {
                $foundSlot = $scheduledAtStr;
                break 2;
            }
        }
    }
    
    if ($foundSlot) {
        $newSchId = uniqid('auto_');
        $pImg = getPrioritizedProductImage($item);
        $newSch = [
            'id' => $newSchId,
            'productId' => $item['id'],
            'productName' => $item['name'] ?? 'Custom',
            'productImage' => $pImg,
            'platform' => 'telegram', // پلتفرم پیش‌فرض انتشار
            'caption' => '',
            'mediaUrl' => $pImg,
            'scheduledAt' => strtotime($foundSlot),
            'status' => 'pending',
            'approvalStatus' => 'pending', // شروع در حالت پیش‌نویس در انتظار تایید
            'comments' => [],
            'reactions' => [],
            'approvals_stage1' => [],
            'approvals_stage2' => [],
            'stage1_decision' => 'pending',
            'stage2_decision' => 'pending'
        ];
        
        $project['schedules'][] = $newSch;
        
        // ثبت در لاگ سیستم
        $project['logs'][] = [
            'at' => time(),
            'type' => 'زمان‌بندی خودکار تقویم',
            'productName' => $item['name'] ?? '',
            'platform' => 'SYSTEM'
        ];
        
        return true;
    }
    
    return false;
}

/**
 * دپارتمان اتصال هوشمند و واقعی به APIهای گوگل و نوین هاب
 */

function fetchLiveNovinhubSocialStats($token) {
    if (empty($token)) {
        return ['error' => 'توکن نوین هاب تنظیم نشده است.'];
    }
    
    $ch = curl_init("https://api.novinhub.com/token/v2/account");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "خطای نوین هاب (کد $httpCode): " . $response];
    }
    
    $data = json_decode($response, true);
    if (!isset($data['data']) || !is_array($data['data'])) {
        return ['error' => 'ساختار پاسخ نوین هاب صحیح نیست.'];
    }
    
    $stats = [
        'instagram' => 0,
        'telegram' => 0,
        'rubika' => 0,
        'bale' => 0
    ];
    
    foreach ($data['data'] as $acc) {
        $platform = strtolower($acc['channel'] ?? '');
        $followers = intval($acc['stats']['followers'] ?? $acc['followers_count'] ?? $acc['members_count'] ?? $acc['subscribers'] ?? 0);
        
        if (strpos($platform, 'instagram') !== false) {
            $stats['instagram'] += $followers;
        } elseif (strpos($platform, 'telegram') !== false) {
            $stats['telegram'] += $followers;
        } elseif (strpos($platform, 'rubika') !== false) {
            $stats['rubika'] += $followers;
        } elseif (strpos($platform, 'bale') !== false) {
            $stats['bale'] += $followers;
        }
    }
    
    return $stats;
}

function getAccessTokenFromServiceAccount($serviceAccountJson) {
    $sa = json_decode($serviceAccountJson, true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
        return ['error' => 'فایل JSON حساب خدماتی (Service Account) معتبر نیست.'];
    }
    
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $assert = json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);
    
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($assert));
    
    $signature = '';
    $success = openssl_sign(
        $base64UrlHeader . "." . $base64UrlPayload,
        $signature,
        $sa['private_key'],
        OPENSSL_ALGO_SHA256
    );
    
    if (!$success) {
        return ['error' => 'خطا در امضای توکن امنیتی حساب خدماتی گوگل (OpenSSL Fail).'];
    }
    
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    
    // Exchange JWT for access token
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "خطا در تبادل توکن حساب خدماتی (کد $httpCode): " . $response];
    }
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? ['error' => 'توکن اکسس یافت نشد.'];
}

function getAccessTokenFromRefreshToken($clientId, $clientSecret, $refreshToken) {
    if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
        return ['error' => 'اطلاعات کلاینت آی‌دی، کلاینت سکرت یا ریفرش توکن خالی است.'];
    }
    
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "خطا در تمدید توکن ریفرش (کد $httpCode): " . $response];
    }
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? ['error' => 'اکسس توکن در پاسخ گوگل وجود ندارد.'];
}

function fetchRealGoogleSearchConsole($siteUrl, $accessToken, $startDate, $endDate) {
    if (empty($accessToken) || empty($siteUrl)) {
        return ['error' => 'کلید گوگل یا دامنه سایت تنظیم نشده است.'];
    }
    
    $url = "https://www.googleapis.com/webmasters/v3/sites/" . urlencode($siteUrl) . "/searchAnalytics/query";
    $body = json_encode([
        "startDate" => $startDate,
        "endDate" => $endDate,
        "dimensions" => ["date"],
        "rowLimit" => 30
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "خطای سرچ کنسول (کد $httpCode): " . $response];
    }
    
    $result = json_decode($response, true);
    return $result;
}

function fetchRealGoogleAnalytics4($propertyId, $accessToken, $startDate, $endDate) {
    if (empty($accessToken) || empty($propertyId)) {
        return ['error' => 'کلید گوگل یا شناسه پرپرتی آنالیتیکس تنظیم نشده است.'];
    }
    
    $url = "https://analyticsdata.googleapis.com/v1beta/properties/" . trim($propertyId) . ":runReport";
    $body = json_encode([
        "dateRanges" => [["startDate" => $startDate, "endDate" => $endDate]],
        "dimensions" => [["name" => "date"]],
        "metrics" => [["name" => "activeUsers"], ["name" => "screenPageViews"]],
        "limit" => 30
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "خطای گوگل آنالیتیکس (کد $httpCode): " . $response];
    }
    
    $result = json_decode($response, true);
    return $result;
}

/**
 * Generates generative content using Google Gemini API server-side
 */
function geminiGenerateResponse($prompt, $systemInstruction = '', $customApiKey = '') {
    $apiKey = trim($customApiKey);
    if (empty($apiKey)) {
        $apiKey = getenv('GEMINI_API_KEY') ?: '';
    }
    
    if (empty($apiKey)) {
        return ['error' => 'کلید API هوش مصنوعی در تنظیمات یا متغیرهای سیستم تنظیم نشده است.'];
    }
    
    $model = 'gemini-3.5-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;
    
    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.7,
            "maxOutputTokens" => 1000
        ]
    ];
    
    if (!empty($systemInstruction)) {
        $payload["systemInstruction"] = [
            "parts" => [
                ["text" => $systemInstruction]
            ]
        ];
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $errMsg = json_decode($response, true);
        $errText = $errMsg['error']['message'] ?? $response;
        return ['error' => "خطای سرور گوگل (کد $httpCode): " . $errText];
    }
    
    $data = json_decode($response, true);
    $textResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    if (empty($textResponse)) {
        return ['error' => 'پاسخ سفید از سرور گوگل دریافت شد.'];
    }
    
    return ['text' => trim($textResponse)];
}

function setTelegramWebhook($token, $webhookUrl, $proxy = null) {
    if (empty($token)) {
        return ['ok' => false, 'error' => 'توکن ربات تلگرام تنظیم نشده است.'];
    }
    
    $method = $proxy['bypass_method'] ?? '';
    if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
        $baseUrl = rtrim($proxy['custom_api_url'], '/') . "/bot{$token}/";
    } else {
        $baseUrl = "https://api.telegram.org/bot{$token}/";
    }
    
    $url = $baseUrl . "setWebhook?url=" . urlencode($webhookUrl);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    applyCurlProxySettings($ch, $proxy);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    addDebugLog('TG_SET_WEBHOOK', "Set webhook to: $webhookUrl | Code: $httpCode | Resp: $response | Err: $error");
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['ok']) && $data['ok'] === true) {
            return ['ok' => true, 'description' => $data['description'] ?? 'وب‌هوک تلگرام با موفقیت ثبت شد.'];
        }
    }
    
    return ['ok' => false, 'error' => "خطا در ست کردن وب‌هوک تلگرام: " . ($response ?: $error)];
}

function setBaleWebhook($token, $webhookUrl, $proxy = null) {
    if (empty($token)) {
        return ['ok' => false, 'error' => 'توکن ربات بله تنظیم نشده است.'];
    }
    
    $method = $proxy['bypass_method'] ?? '';
    if (($method === 'custom_api' || $method === 'hybrid' || empty($method)) && !empty($proxy['custom_api_url'])) {
        $baseUrl = rtrim($proxy['custom_api_url'], '/') . "/bot{$token}/";
    } else {
        $baseUrl = "https://bg.bale.ai/bot{$token}/";
    }
    
    $url = $baseUrl . "setWebhook?url=" . urlencode($webhookUrl);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    applyCurlProxySettings($ch, $proxy);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    addDebugLog('BALE_SET_WEBHOOK', "Set webhook to: $webhookUrl | Code: $httpCode | Resp: $response | Err: $error");
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['ok']) && $data['ok'] === true) {
            return ['ok' => true, 'description' => $data['description'] ?? 'وب‌هوک بله با موفقیت ثبت شد.'];
        }
    }
    
    return ['ok' => false, 'error' => "خطا در ست کردن وب‌هوک بله: " . ($response ?: $error)];
}

function sendChatMessageThroughNovinhub($token, $accountId, $chatId, $message, $channel = 'telegram') {
    if (empty($token)) {
        return "توکن نوین هاب تنظیم نشده است.";
    }
    if (empty($accountId)) {
        return "شناسه اکانت متصل به نوین هاب تنظیم نشده است.";
    }

    addDebugLog('NOVINHUB_CHAT_SEND', "Sending chat reply to chat ID: $chatId | Account ID: $accountId | Channel: $channel");

    $params = [
        'account_id' => (int)$accountId,
        'chat_id' => $chatId,
        'text' => $message,
    ];

    $ch = curl_init("https://api.novinhub.com/token/v2/message/reply");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    addDebugLog('NOVINHUB_CHAT_SEND_RES', "Code: $httpCode | Resp: $response | Error: $error");

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            return $data['error']['message'] ?? "خطا در وب‌سرویس نوین هاب";
        }
        return true;
    }

    return "خطا در ارسال پیام نوین هاب (کد وضعیت $httpCode)";
}

/**
 * Checks if a specific occasion's program has been approved by the required number of approvers,
 * or if it has been auto-approved upon reaching the deadline (timeout).
 */
function isOccasionApproved($project, $occ_key) {
    if (!isset($project['settings']['occasions_plans'][$occ_key])) {
        return false;
    }
    $plan = $project['settings']['occasions_plans'][$occ_key];
    if (empty($plan['pdf_path'])) {
        return false;
    }
    
    // Check timeout / deadline (calendar approval timeout in hours)
    $timeout_hours = (int)($project['settings']['approval_timeout_calendar'] ?? 48);
    $uploaded_at = $plan['pdf_uploaded_at'] ?? 0;
    if ($uploaded_at > 0) {
        $deadline_ts = $uploaded_at + ($timeout_hours * (($project['settings']['approval_timeout_unit']??'')==='minutes'?60:3600));
        if (time() >= $deadline_ts) {
            return true; // Auto-approved on timeout!
        }
    }
    
    $is_split = !empty($project['settings']['cal_approval_split_enabled']);
    $st1_min = (int)($project['settings']['cal_approval_stage1_min'] ?? 1);
    $st2_min = (int)($project['settings']['cal_approval_stage2_min'] ?? 1);
    
    $approvals1 = $plan['approvals_stage1'] ?? [];
    $approvals2 = $plan['approvals_stage2'] ?? [];
    
    if ($is_split) {
        return (count($approvals1) >= $st1_min) && (count($approvals2) >= $st2_min);
    } else {
        return count($approvals1) >= $st1_min;
    }
}

// ==========================================
// Maarksho Landing, Settings & Orders Core
// ==========================================

function getSiteSettings() {
    $db = getJsonDB();
    $default = [
        'site_title' => 'مارکشو فلو | Maarksho Flow',
        'site_tagline' => 'پلتفرم جامع مدیریت محتوا، هوش مصنوعی، کاتالوگ آنلاین و CRM فروش',
        'logo_text' => 'Maarksho',
        'logo_icon' => 'M',
        'hero_title' => 'تمامی ابزارهای فروش و هوش مصنوعی کسب‌وکار شما در یک پنل',
        'hero_subtitle' => 'با مارکشو فلو کاتالوگ آنلاین، مدیریت مشتریان (CRM)، ویجت چت هوشمند، پرامپت هاب و ابزارهای تولید محتوا را یکجا در اختیار داشته باشید.',
        'hero_cta_text' => 'شروع رایگان و مشاهده دمو',
        'hero_cta_secondary' => 'مشاهده تعرفه‌ها و خرید اشتراک',
        'phone' => '۰۲۱-۹۱۰۰۱۲۳۴',
        'mobile' => '۰۹۱۲۰۰۰۰۰۰۰',
        'email' => 'info@maarksho.com',
        'address' => 'تهران، خیابان آزادی، ناحیه نوآوری شریف، برج فناوری مارکشو، طبقه ۴',
        'telegram' => 'maarksho_support',
        'instagram' => 'maarksho.official',
        'whatsapp' => '989120000000',
        'enamad_code' => '12345678',
        'enamad_url' => 'https://enamad.ir',
        'enamad_enabled' => true,
        'about_title' => 'درباره مارکشو فلو',
        'about_text' => 'مارکشو فلو یک اکوسیستم پیشرفته ابزارهای دیجیتال، مدیریت محتوا و هوش مصنوعی برای کسب‌وکارهای آنلاین، فروشگاه‌ها و تیم‌های مارکتینگ است. ما با هدف آسان‌سازی فرآیندهای فروش، تولید محتوا و ارتباط با مشتری، پیشرفته‌ترین ابزارها را در اختیارتان قرار می‌دهیم.',
        'terms_text' => 'با خرید هر یک از اشتراک‌ها، دسترسی کامل آنلاین به پنل ابزارها بر اساس مدت زمان انتخاب شده برای شما فعال می‌گردد. تمامی سفارشات دارای ۵ روز ضمانت بازگشت وجه کامل می‌باشند.',
        // ZarinPal Payment Gateway Settings
        'zarinpal_enabled' => true,
        'zarinpal_merchant_id' => '',
        'zarinpal_access_token' => '',
        'zarinpal_terminal_id' => '',
        'zarinpal_sandbox' => false,
        'zarinpal_currency' => 'IRT',
        'zarinpal_description_prefix' => 'خرید اشتراک مارکشو فلو - سفارش '
    ];
    if (!isset($db['site_settings']) || !is_array($db['site_settings'])) {
        return $default;
    }
    return array_merge($default, $db['site_settings']);
}

function saveSiteSettings($settings) {
    $db = getJsonDB();
    $db['site_settings'] = $settings;
    saveJsonDB($db);
    return true;
}

function getSubscriptionPlans() {
    $db = getJsonDB();
    $default = [
        [
            'id' => 'plan_starter',
            'name' => 'پلن برنز (پایه)',
            'price_monthly' => 390000,
            'price_yearly' => 3900000,
            'badge' => 'مناسب کسب‌وکارهای نوپا',
            'featured' => false,
            'features' => [
                'دسترسی به کاتالوگ هوشمند محصولات (تا ۵۰ محصول)',
                'پرامپت هاب و هوش مصنوعی تولید متن پایه',
                'ویجت چت آنلاین سایت (۱ اپراتور)',
                'استودیو جدول سایز اختصاصی',
                'پشتیبانی تیکتی آنلاین'
            ]
        ],
        [
            'id' => 'plan_pro',
            'name' => 'پلن نقره‌ای (حرفه‌ای)',
            'price_monthly' => 790000,
            'price_yearly' => 7900000,
            'badge' => '🔥 محبوب‌ترین انتخاب',
            'featured' => true,
            'features' => [
                'کاتالوگ نامحدود محصولات و دسته‌بندی‌ها',
                'پرامپت هاب پیشرفته با دستیار ایده و محتوا',
                'CRM هوشمند و مدیریت خط فروش مشتریان (WP Sync)',
                'ویجت چت زنده هوشمند + اتصال به پیام‌رسان بله و تلگرام',
                'آنالیزور سئو و بررسی کلمات کلیدی محصولات',
                'اتوماسیون استخراج و تقویم انتشار محتوا',
                'پشتیبانی ویژه تلفنی و واتس‌اپ'
            ]
        ],
        [
            'id' => 'plan_enterprise',
            'name' => 'پلن طلایی (سازمانی)',
            'price_monthly' => 1490000,
            'price_yearly' => 14900000,
            'badge' => 'برای برندها و تیم‌های بزرگ',
            'featured' => false,
            'features' => [
                'تمامی امکانات پلن حرفه‌ای بدون هیچ محدودیتی',
                'چند کاربره (تا ۱۰ اپراتور و مدیر با سطوح دسترسی)',
                'اتصال مستقیم به ربات بله، تلگرام و اینستاگرام',
                'دامنه اختصاصی و برندینگ سفید (White Label)',
                'توسعه امکانات سفارشی بر اساس نیاز برند شما',
                'مدیر پشتیبانی اختصاصی ۲۴ ساعته'
            ]
        ]
    ];
    if (!isset($db['subscription_plans']) || !is_array($db['subscription_plans']) || empty($db['subscription_plans'])) {
        return $default;
    }
    return $db['subscription_plans'];
}

function saveSubscriptionPlans($plans) {
    $db = getJsonDB();
    $db['subscription_plans'] = $plans;
    saveJsonDB($db);
    return true;
}

function getOrders() {
    $db = getJsonDB();
    if (!isset($db['orders']) || !is_array($db['orders'])) {
        return [];
    }
    return $db['orders'];
}

function saveOrder($orderData) {
    $db = getJsonDB();
    if (!isset($db['orders']) || !is_array($db['orders'])) {
        $db['orders'] = [];
    }
    
    $found = false;
    foreach ($db['orders'] as &$ord) {
        if (isset($ord['order_id']) && $ord['order_id'] === $orderData['order_id']) {
            $ord = array_merge($ord, $orderData);
            $found = true;
            break;
        }
    }
    if (!$found) {
        array_unshift($db['orders'], $orderData);
    }
    
    saveJsonDB($db);
    return true;
}

function updateOrderStatus($orderId, $status) {
    $db = getJsonDB();
    if (isset($db['orders']) && is_array($db['orders'])) {
        foreach ($db['orders'] as &$ord) {
            if ($ord['order_id'] === $orderId) {
                $ord['status'] = $status;
                $ord['updated_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        saveJsonDB($db);
        return true;
    }
    return false;
}

function getOrderById($orderId) {
    $orders = getOrders();
    foreach ($orders as $ord) {
        if (isset($ord['order_id']) && $ord['order_id'] === $orderId) {
            return $ord;
        }
    }
    return null;
}

function getOrderByAuthority($authority) {
    if (empty($authority)) return null;
    $orders = getOrders();
    foreach ($orders as $ord) {
        if (isset($ord['authority']) && $ord['authority'] === $authority) {
            return $ord;
        }
    }
    return null;
}

// ==========================================
// ZarinPal Payment Gateway & GraphQL Integration
// ==========================================

function zarinpalGetErrorMessage($code) {
    $errors = [
        -9 => 'خطای اعتبارسنجی مقادیر ارسالی',
        -10 => 'آی‌پی یا مرچنت کد پذیرنده صحیح نیست',
        -11 => 'مرچنت کد فعال نیست، پذیرنده با پشتیبانی تماس بگیرد',
        -12 => 'تلاش بیش از حد مجاز در یک بازه زمانی کوتاه',
        -15 => 'درگاه پرداخت به حالت تعلیق درآمده است',
        -16 => 'سطح تایید پذیرنده پایین‌تر از سطح نقره‌ای است',
        -17 => 'محدودیت سوییچ روی این درگاه اعمال شده است',
        -30 => 'پذیرنده اجازه دسترسی به سرویس تسویه اشتراکی شناور را ندارد',
        -31 => 'حساب بانکی تسویه حساب غیرفعال است',
        -32 => 'مجموع مبالغ تسویه تسهیم شده با کل مبلغ مطابقت ندارد',
        -33 => 'درصد تسهیم مشخص شده صحیح نیست',
        -34 => 'مبلغ تسهیم از حد مجاز بالاتر است',
        -35 => 'تعداد افراد تسهیم شده از حد مجاز فراتر است',
        -40 => 'پارامترهای اضافی ارسالی نامعتبر است',
        -50 => 'مبلغ پرداخت شده با مقدار وریفای مطابقت ندارد',
        -51 => 'پرداخت ناموفق بوده است (توسط کاربر لغو شده یا تراکنش ناموفق بانکی)',
        -52 => 'خطای غیرمنتظره در سرور شاپرک / زرین‌پال',
        -53 => 'اتوریتی متعلق به این مرچنت کد نیست',
        -54 => 'اتوریتی نامعتبر است یا منقضی شده است',
        100 => 'عملیات با موفقیت انجام شد',
        101 => 'عملیات پرداخت موفق بوده و قبلاً تایید (Verify) شده است'
    ];
    return $errors[$code] ?? ('کد وضعیت زرین‌پال: ' . $code);
}

/**
 * ایجاد درخواست پرداخت در زرین‌پال (REST v4)
 */
function zarinpalRequestPayment($amount, $description, $callbackUrl, $mobile = '', $email = '', $orderId = '', $customSettings = null) {
    $settings = $customSettings ?: getSiteSettings();
    $merchantId = trim($settings['zarinpal_merchant_id'] ?? '');
    $isSandbox = !empty($settings['zarinpal_sandbox']);
    $currency = strtoupper(trim($settings['zarinpal_currency'] ?? 'IRT'));
    if ($currency !== 'IRR' && $currency !== 'IRT') {
        $currency = 'IRT';
    }

    if (empty($merchantId)) {
        return [
            'success' => false,
            'error' => 'مرچنت کد زرین‌پال (Merchant ID) در تنظیمات وب‌سایت وارد نشده است.',
            'code' => -1
        ];
    }

    $amount = (int)$amount;
    if ($amount <= 0) {
        return [
            'success' => false,
            'error' => 'مبلغ سفارش نامعتبر است.',
            'code' => -2
        ];
    }

    $url = $isSandbox 
        ? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
        : 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    $payload = [
        'merchant_id' => $merchantId,
        'amount' => $amount,
        'currency' => $currency,
        'description' => $description ?: 'پرداخت سفارش آنلاین در مارکشو فلو',
        'callback_url' => $callbackUrl,
        'metadata' => array_filter([
            'mobile' => $mobile ?: null,
            'email' => $email ?: null,
            'order_id' => $orderId ?: null
        ])
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MaarkshoFlow-Zarinpal/4.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'error' => 'خطا در ارتباط با سرور زرین‌پال: ' . $curlError,
            'code' => -3
        ];
    }

    $resData = json_decode($response, true);
    if (!is_array($resData)) {
        return [
            'success' => false,
            'error' => 'پاسخ نامعتبر از سرور زرین‌پال دریافت شد (HTTP ' . $httpCode . ')',
            'code' => -4,
            'raw' => $response
        ];
    }

    $data = $resData['data'] ?? [];
    $code = $data['code'] ?? null;
    $authority = $data['authority'] ?? null;

    if ($code == 100 && !empty($authority)) {
        $startPayBase = $isSandbox 
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://payment.zarinpal.com/pg/StartPay/';

        return [
            'success' => true,
            'authority' => $authority,
            'payment_url' => $startPayBase . $authority,
            'fee' => $data['fee'] ?? 0,
            'fee_type' => $data['fee_type'] ?? '',
            'code' => $code,
            'data' => $data
        ];
    }

    $errors = $resData['errors'] ?? [];
    $errorMsg = 'خطا در ایجاد تراکنش درگاه زرین‌پال.';
    if (!empty($errors['message'])) {
        $errorMsg = $errors['message'];
    } elseif (!empty($errors['code'])) {
        $errorMsg = zarinpalGetErrorMessage((int)$errors['code']);
    } elseif (!empty($errors) && is_array($errors)) {
        $errorMsg = json_encode($errors, JSON_UNESCAPED_UNICODE);
    }

    return [
        'success' => false,
        'error' => $errorMsg,
        'code' => $code ?? ($errors['code'] ?? -5),
        'raw' => $resData
    ];
}

/**
 * اعتبارسنجی و تایید نهایی پرداخت از زرین‌پال (Verify)
 */
function zarinpalVerifyPayment($authority, $amount, $customSettings = null) {
    $settings = $customSettings ?: getSiteSettings();
    $merchantId = trim($settings['zarinpal_merchant_id'] ?? '');
    $isSandbox = !empty($settings['zarinpal_sandbox']);

    if (empty($merchantId)) {
        return [
            'success' => false,
            'error' => 'مرچنت کد زرین‌پال در تنظیمات مشخص نشده است.',
            'code' => -1
        ];
    }

    $url = $isSandbox 
        ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
        : 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    $payload = [
        'merchant_id' => $merchantId,
        'amount' => (int)$amount,
        'authority' => trim($authority)
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MaarkshoFlow-Zarinpal/4.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'error' => 'خطا در ارتباط با سرور وریفای زرین‌پال: ' . $curlError,
            'code' => -3
        ];
    }

    $resData = json_decode($response, true);
    if (!is_array($resData)) {
        return [
            'success' => false,
            'error' => 'پاسخ نامعتبر از سرور تایید زرین‌پال دریافت شد.',
            'code' => -4,
            'raw' => $response
        ];
    }

    $data = $resData['data'] ?? [];
    $code = $data['code'] ?? null;

    if ($code == 100 || $code == 101) {
        return [
            'success' => true,
            'code' => $code,
            'ref_id' => $data['ref_id'] ?? ($data['refId'] ?? null),
            'card_pan' => $data['card_pan'] ?? ($data['cardPan'] ?? null),
            'card_hash' => $data['card_hash'] ?? null,
            'fee' => $data['fee'] ?? 0,
            'is_already_verified' => ($code == 101),
            'message' => ($code == 100) ? 'پرداخت با موفقیت انجام و تایید شد.' : 'این تراکنش قبلاً با موفقیت تایید شده است.',
            'data' => $data
        ];
    }

    $errors = $resData['errors'] ?? [];
    $errorMsg = 'تراکنش پرداخت توسط درگاه تایید نشد.';
    if (!empty($errors['message'])) {
        $errorMsg = $errors['message'];
    } elseif (!empty($errors['code'])) {
        $errorMsg = zarinpalGetErrorMessage((int)$errors['code']);
    }

    return [
        'success' => false,
        'error' => $errorMsg,
        'code' => $code ?? ($errors['code'] ?? -5),
        'raw' => $resData
    ];
}

/**
 * ارسال کوئری به سرور کارگزاری GraphQL زرین‌پال
 * https://next.zarinpal.com/api/v4/graphql
 */
function zarinpalGraphqlQuery($query, $variables = null, $accessToken = null) {
    $settings = getSiteSettings();
    $token = $accessToken ?: trim($settings['zarinpal_access_token'] ?? '');
    
    $url = 'https://next.zarinpal.com/api/v4/graphql/';
    $payload = [
        'query' => $query,
        'variables' => $variables
    ];

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if (!empty($token)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'خطای ارتباط با سرور GraphQL زرین‌پال: ' . $curlError];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'پاسخ نامعتبر از سرور GraphQL زرین‌پال (HTTP ' . $httpCode . ')', 'raw' => $response];
    }

    if (!empty($data['errors'])) {
        $errText = '';
        foreach ($data['errors'] as $err) {
            $errText .= ($err['message'] ?? 'خطا') . ' | ';
        }
        return ['success' => false, 'error' => rtrim($errText, ' | '), 'errors' => $data['errors']];
    }

    return ['success' => true, 'data' => $data['data'] ?? []];
}

/**
 * دریافت لیست درگاه‌ها از GraphQL زرین‌پال
 */
function zarinpalGetTerminals($accessToken = null) {
    $query = 'query { Terminals { id status preferred_bank_account_id domain support_phone key name logo created_at updated_at } }';
    $res = zarinpalGraphqlQuery($query, null, $accessToken);
    if ($res['success'] && isset($res['data']['Terminals'])) {
        return $res['data']['Terminals'];
    }
    return [];
}

/**
 * تست جامع اتصال به درگاه زرین‌پال و GraphQL
 */
function zarinpalTestConnection($merchantId = null, $accessToken = null, $sandbox = false) {
    $settings = getSiteSettings();
    $mId = ($merchantId !== null) ? trim($merchantId) : trim($settings['zarinpal_merchant_id'] ?? '');
    $token = ($accessToken !== null) ? trim($accessToken) : trim($settings['zarinpal_access_token'] ?? '');
    $isSand = ($sandbox !== null) ? (bool)$sandbox : !empty($settings['zarinpal_sandbox']);

    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'merchant_id_configured' => !empty($mId),
        'access_token_configured' => !empty($token),
        'sandbox_mode' => $isSand,
        'rest_gateway_status' => 'unknown',
        'graphql_status' => 'unknown',
        'terminals' => [],
        'messages' => []
    ];

    if (empty($mId) && empty($token)) {
        $report['messages'][] = '⚠️ هیچ مرچنت کد یا توکن دسترسی برای زرین‌پال تنظیم نشده است.';
        return $report;
    }

    // 1. Test Gateway Payment Request with 1000 Tomans probe
    if (!empty($mId)) {
        $probeRes = zarinpalRequestPayment(1000, 'تست ارتباط آزمایشی درگاه مارکشو فلو', 'https://example.com/callback', '09120000000', '', 'TEST-PROBE', [
            'zarinpal_merchant_id' => $mId,
            'zarinpal_sandbox' => $isSand,
            'zarinpal_currency' => 'IRT'
        ]);

        if ($probeRes['success']) {
            $report['rest_gateway_status'] = 'connected';
            $report['messages'][] = '✅ درگاه پرداخت متصل است. مجوز تراکنش (Authority) دریافت شد: ' . substr($probeRes['authority'], 0, 15) . '...';
        } else {
            $report['rest_gateway_status'] = 'error';
            $report['messages'][] = '❌ نتیجه تست درگاه شاپرک/زرین‌پال: ' . ($probeRes['error'] ?? 'خطای نامشخص') . ' (کد: ' . ($probeRes['code'] ?? '') . ')';
        }
    }

    // 2. Test GraphQL API if Access Token provided
    if (!empty($token)) {
        $gqlRes = zarinpalGraphqlQuery('query { Terminals { id status domain name key support_phone created_at } }', null, $token);
        if ($gqlRes['success'] && isset($gqlRes['data']['Terminals'])) {
            $report['graphql_status'] = 'connected';
            $report['terminals'] = $gqlRes['data']['Terminals'];
            $report['messages'][] = '✅ ارتباط با کارگزاری GraphQL زرین‌پال با موفقیت برقرار شد. تعداد پایانه‌های فعال: ' . count($report['terminals']);
        } else {
            $report['graphql_status'] = 'error';
            $report['messages'][] = '⚠️ نتیجه تست GraphQL: ' . ($gqlRes['error'] ?? 'توکن نامعتبر است یا دسترسی مجاز نیست.');
        }
    }

    return $report;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getDirSizeRecursive($dir) {
    $size = 0;
    if (!is_dir($dir)) return 0;
    $files = @scandir($dir);
    if (!$files) return 0;
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            $size += filesize($path);
        } elseif (is_dir($path)) {
            $size += getDirSizeRecursive($path);
        }
    }
    return $size;
}

function getProjectStorageUsage($projectOrId) {
    $project = is_array($projectOrId) ? $projectOrId : getCurrentProject($projectOrId);
    if (!$project) {
        return [
            'used_bytes' => 0,
            'used_mb' => 0,
            'formatted_used' => '0 مگابایت',
            'quota_mb' => 1000,
            'quota_bytes' => 1000 * 1024 * 1024,
            'formatted_quota' => '1 گیگابایت',
            'usage_percent' => 0,
            'is_warning' => false,
            'is_full' => false,
            'status_class' => 'emerald',
            'status_label' => 'عادی'
        ];
    }

    $pid = $project['id'];
    $usedBytes = 0;

    // 1. Check dedicated project directory in uploads if exists
    $prjUploadDir = UPLOADS_DIR . $pid;
    if (is_dir($prjUploadDir)) {
        $usedBytes += getDirSizeRecursive($prjUploadDir);
    }

    // 2. Scan files in UPLOADS_DIR that contain project ID or are referenced by project products
    if (is_dir(UPLOADS_DIR)) {
        $allUploads = @scandir(UPLOADS_DIR) ?: [];
        $referencedFiles = [];
        $addReferencedFile = static function($entry) use (&$referencedFiles) {
            $url = ProductSizeChartCatalog::entryUrl($entry);
            if ($url === '') return;
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') $referencedFiles[] = basename($path);
        };
        
        // Collect filenames referenced in products / schedules / settings
        if (!empty($project['products']) && is_array($project['products'])) {
            foreach ($project['products'] as $p) {
                $addReferencedFile($p['image'] ?? '');
                $addReferencedFile($p['social_image'] ?? $p['socialImage'] ?? '');
                $gallery = $p['gallery'] ?? [];
                if (is_string($gallery)) $gallery = json_decode($gallery, true);
                if (is_array($gallery)) foreach ($gallery as $g) $addReferencedFile($g);
            }
        }
        if (!empty($project['schedules']) && is_array($project['schedules'])) {
            foreach ($project['schedules'] as $s) {
                $addReferencedFile($s['mediaUrl'] ?? '');
            }
        }
        
        $referencedFiles = array_unique(array_filter($referencedFiles));

        foreach ($allUploads as $fn) {
            if ($fn === '.' || $fn === '..') continue;
            $filePath = UPLOADS_DIR . $fn;
            if (is_file($filePath)) {
                if (strpos($fn, $pid) !== false || in_array($fn, $referencedFiles)) {
                    $usedBytes += filesize($filePath);
                }
            }
        }
    }

    // 3. Database footprint estimation
    $dbPayload = json_encode($project, JSON_UNESCAPED_UNICODE);
    $usedBytes += strlen($dbPayload);

    // Minimum baseline representation so empty projects show realistic small baseline (e.g. 15-45 MB)
    $minBaseline = 15 * 1024 * 1024;
    if ($usedBytes < $minBaseline && !empty($project['products'])) {
        $usedBytes += count($project['products']) * 800 * 1024; // avg 800KB per product image
    }

    // Determine Quota
    $settings = $project['settings'] ?? [];
    $customQuota = (int)($settings['storage_quota_mb'] ?? 0);

    if ($customQuota > 0) {
        $quotaMB = $customQuota;
    } else {
        $tier = strtolower($project['billing_tier'] ?? 'standard');
        if ($tier === 'gold' || $tier === 'enterprise' || $tier === 'plan_enterprise') {
            $quotaMB = 20000; // 20 GB
        } elseif ($tier === 'silver' || $tier === 'pro' || $tier === 'plan_pro') {
            $quotaMB = 5000; // 5 GB
        } else {
            $quotaMB = 1000; // 1 GB
        }
    }

    $quotaBytes = $quotaMB * 1024 * 1024;
    $usagePercent = min(100, round(($usedBytes / max(1, $quotaBytes)) * 100, 1));
    $isWarning = $usagePercent >= 80 && $usagePercent < 98;
    $isFull = $usagePercent >= 98;

    $statusClass = 'emerald';
    $statusLabel = 'عادی و مطلوب';
    if ($isFull) {
        $statusClass = 'rose';
        $statusLabel = '🚨 تکمیل فضا (نیاز به ارتقا)';
    } elseif ($isWarning) {
        $statusClass = 'amber';
        $statusLabel = '⚠️ رو به اتمام (هشدار)';
    }

    return [
        'used_bytes' => $usedBytes,
        'used_mb' => round($usedBytes / (1024 * 1024), 2),
        'formatted_used' => formatBytes($usedBytes),
        'quota_mb' => $quotaMB,
        'quota_bytes' => $quotaBytes,
        'formatted_quota' => formatBytes($quotaBytes),
        'usage_percent' => $usagePercent,
        'is_warning' => $isWarning,
        'is_full' => $isFull,
        'status_class' => $statusClass,
        'status_label' => $statusLabel
    ];
}

function updateProjectStorageQuota($projectId, $newQuotaMb) {
    $project = getCurrentProject($projectId);
    if (!$project) return false;
    
    if (!isset($project['settings']) || !is_array($project['settings'])) {
        $project['settings'] = [];
    }
    
    $project['settings']['storage_quota_mb'] = (int)$newQuotaMb;
    saveProject($project);
    return true;
}


/** Enforce the same feature restrictions for direct URLs and POST requests. */
function enforceFeatureRequestAccess() {
    if (PHP_SAPI === 'cli') return;
    // These controllers resolve business/store resources to their real project
    // before applying ModuleProjectAccess, including every direct/pretty alias.
    $controller = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    if (in_array($controller, [realpath(__DIR__.'/../ai-connect.php'), realpath(__DIR__.'/../vm/index.php'), realpath(__DIR__.'/../vm/api.php'),
        realpath(__DIR__.'/../seo_intel/index.php'), realpath(__DIR__.'/../seo_intel/api.php'),
        realpath(__DIR__.'/../pro/index.php')], true)) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $relative = preg_replace('#^/panel/#', '/', $path);
    $key = null;
    if (preg_match('#/(?:admin(?:\.php)?)/?$#', $relative)) {
        $key = $_GET['tab'] ?? 'dashboard';
        if ($key === 'feature_flags') {
            if (!isMainAdmin()) AccessControl::deny();
            return;
        }
    } else {
        foreach (FeatureFlags::getCatalog() as $featureKey => $feature) {
            $url = $feature['url'] ?? '';
            if (strpos($url, 'admin.php?') === 0) continue;
            $route = '/' . ltrim($url, '/');
            $pattern = str_replace(preg_quote('{slug}', '#'), '[^/]+', preg_quote(rtrim($route, '/'), '#'));
            if (preg_match('#^' . $pattern . '(?:/index\.php)?/?$#', $relative)) $key = $featureKey;
        }
    }
    if ($key === null && preg_match('#^/(vm|seo_intel)/(?:index|api)\.php$#', $relative, $m)) {
        $key = $m[1] === 'vm' ? 'vm_studio' : 'seo_intel';
    }
    // Support has its own ownership gate and is available to panel users without management permissions.
    if ($key === null || $key === 'support') return;
    if (FeatureFlags::isUnrestrictedAdmin()) return;
    // Main administrators must retain access to repair project settings.
    if ($key === 'settings' && isMainAdmin()) return;
    if (!isUser()) { redirect('/index.php'); exit; }
    if (!isMainAdmin()) {
        $pid = $_GET['switch_project'] ?? ($_GET['project'] ?? ($_POST['project_id'] ?? ($_SESSION['project_id'] ?? null)));
        if (!is_string($pid)) AccessControl::deny();
        $project = getCurrentProject($pid, true);
        $member = SessionIdentity::member($project);
        if (!$member) AccessControl::deny();
        $_SESSION['project_id'] = $pid;
        $_SESSION['user'] = $member;
    }
    if (preg_match('#/(?:admin(?:\.php)?)/?$#', $relative) && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        require_once __DIR__.'/AdminActionAccess.php';
        $operationTab = null;
        foreach (AdminActionAccess::requirements($_POST) as $alternatives) {
            $allowedFeature = null;
            foreach ($alternatives as $feature) {
                $module = AdminActionAccess::module($feature);
                if (hasPermission($feature) && isModuleActive($module) && AccessControl::allowed($module, 'use') && AccessControl::allowed($feature, 'use')) { $allowedFeature = $feature; break; }
            }
            if ($allowedFeature === null) AccessControl::deny();
            if ($operationTab === null) $operationTab = $allowedFeature;
        }
        // Existing clients POST directly to admin.php. They need the operation's
        // grant, not an unrelated dashboard grant, when no tab was provided.
        if (!isset($_GET['tab']) && $operationTab !== null) {
            $_GET['tab'] = $operationTab;
            // All operation grants and parent-module restrictions were checked
            // above, including standalone reference/library operators.
            return;
        }
    }
    if (!isMainAdmin() && preg_match('#/(?:admin(?:\.php)?)/?$#', $relative) && !isset($_GET['tab']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !hasPermission($key)) {
        foreach (array_keys(FeatureFlags::getCatalog()) as $candidate) {
            if (canAccessFeature($candidate)) { $key = $candidate; $_GET['tab'] = $candidate; break; }
        }
    }
    $mode = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 'view' : 'use';
    if (!FeatureFlags::isAvailable($key) || !AccessControl::allowed($key, $mode) || !isModuleActive($key)) AccessControl::deny();
    if (!canAccessFeature($key, $mode)) AccessControl::deny();
}
require_once __DIR__.'/SessionIdentity.php';
if (PHP_SAPI !== 'cli') SessionIdentity::refresh();
enforceFeatureRequestAccess();
