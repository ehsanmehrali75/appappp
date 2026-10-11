<?php
require_once 'inc/functions.php';

if (!isSuperAdmin()) {
    die('Access Denied. Only SuperAdmins can run the debugger.');
}

$report = [];

// 1. Check Permissions
$dirs = ['uploads', 'db'];
foreach($dirs as $dir) {
    $report['permissions'][$dir] = [
        'exists' => file_exists($dir),
        'writable' => is_writable($dir)
    ];
}

// 2. Database Status
$report['database'] = [
    'type' => isSqlActive() ? 'MySQL' : 'JSON',
    'connected' => false,
    'counts' => []
];

if (isSqlActive()) {
    $conn = getConn();
    if ($conn) {
        $report['database']['connected'] = true;
        $tables = ['projects', 'products', 'project_users', 'logs', 'schedules', 'super_users'];
        foreach($tables as $t) {
            $res = $conn->query("SHOW TABLES LIKE '$t'");
            $exists = $res && $res->num_rows > 0;
            $report['database']['tables'][$t] = $exists;
            if ($exists) {
                $countRes = $conn->query("SELECT COUNT(*) as cnt FROM $t");
                if ($countRes) {
                    $row = $countRes->fetch_assoc();
                    $report['database']['counts'][$t] = $row['cnt'];
                }
            }
        }
    }
} else {
    $db = getJsonDB();
    $report['database']['connected'] = true;
    $report['database']['counts']['projects'] = count($db['projects'] ?? []);
    if (!empty($db['projects'][0]['products'])) {
        $report['database']['counts']['products'] = count($db['projects'][0]['products']);
    }
}

// 3. Test Scraper (Tanedorost connectivity)
$testUrl = 'https://www.tanedorost.com';
$googleUrl = 'https://www.google.com';
$startTime = microtime(true);

function test_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => ($html !== false && $httpCode < 400), 'code' => $httpCode, 'err' => $err, 'len' => strlen($html)];
}

$tanedResult = test_curl($testUrl);
$googleResult = test_curl($googleUrl);

$endTime = microtime(true);
$report['scraper'] = [
    'tanedorost' => $tanedResult,
    'google' => $googleResult,
    'latency_total' => round($endTime - $startTime, 2) . 's'
];

// 4. Check for missing crucial files
$files = ['inc/db.php', 'inc/functions.php', 'assets/style.css', 'admin.php', 'portal.php', 'magtaned_flow.sql'];
foreach ($files as $f) {
    // Better path check
    $fullPath = __DIR__ . '/' . ltrim($f, '/');
    $report['files'][$f] = [
        'exists' => file_exists($fullPath),
        'path' => $fullPath,
        'readable' => is_readable($fullPath)
    ];
}

// 5. DB Test Write
if (isSqlActive()) {
    $conn = getConn();
    $testId = 'test_' . time();
    $sql = "REPLACE INTO logs (project_id, at, type, product_name) VALUES ('no-project', " . time() . ", 'test', 'Debug Check')";
    $report['db_test_write'] = $conn->query($sql) ? 'Success' : 'Failed: ' . $conn->error;
}

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
