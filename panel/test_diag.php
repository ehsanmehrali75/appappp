<?php
require_once 'inc/functions.php';

echo "=== DATABASE DIAGNOSTICS ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SQLite3 Class Exists: " . (class_exists('SQLite3') ? 'YES' : 'NO') . "\n";
echo "MySQLi Class Exists: " . (class_exists('mysqli') ? 'YES' : 'NO') . "\n";
echo "Is SQL Active (isSqlActive): " . (isSqlActive() ? 'YES' : 'NO') . "\n";
echo "DB Driver (db_driver): " . ($GLOBALS['db_driver'] ?? 'not_set') . "\n";

$conn = getConn();
if ($conn === null) {
    echo "Connection is NULL!\n";
    echo "Connection error: " . ($GLOBALS['db_connection_error'] ?? 'none') . "\n";
} else {
    echo "Connection is OK!\n";
    echo "Driver: " . ($GLOBALS['db_driver'] ?? 'unknown') . "\n";
    
    // Check tables presence
    $tables = ['super_users', 'projects', 'project_users', 'portal_users', 'products', 'logs', 'schedules'];
    foreach ($tables as $table) {
        $res = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($res && $res->num_rows > 0) {
            echo "Table $table: EXISTS\n";
        } else {
            // Check in MySQL if not SQLite
            $resM = $conn->query("SHOW TABLES LIKE '$table'");
            if ($resM && $resM->num_rows > 0) {
                echo "Table $table: EXISTS (MySQL)\n";
            } else {
                echo "Table $table: NOT FOUND\n";
            }
        }
    }
    
    // Try a test save and get inside portal_users
    echo "\n=== TEST SAVE AND GET ===\n";
    $test_mobile = '09358359488';
    $save_res = savePortalUser($test_mobile, 'Test User', 'icon', '99999', time() + 300);
    echo "savePortalUser result: " . ($save_res ? "SUCCESS" : "FAILED") . "\n";
    if (!$save_res) {
        echo "Save Error: " . ($conn->error ?? 'unknown') . "\n";
    }
    
    $get_res = getPortalUser($test_mobile);
    if ($get_res) {
        echo "getPortalUser result: SUCCESS\n";
        echo "User Data: " . json_encode($get_res, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "getPortalUser result: FAILED\n";
    }
}
