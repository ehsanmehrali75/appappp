<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Maarksho Flow Emergency Entry & Debug</h1>";

// 1. Check directories & Root Writable
echo "<h2>System Access</h2>";
$dirs = ['db', 'uploads'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0777, true)) {
            echo "<p style='color:green'>Created directory: $dir</p>";
        } else {
            echo "<p style='color:red'>Failed to create directory: $dir</p>";
        }
    } else {
        echo "<p>Directory exists: $dir (Writable: " . (is_writable($dir) ? 'Yes' : 'No') . ")</p>";
    }
}
echo "<p>Root Dir Writable: " . (is_writable(__DIR__) ? 'Yes' : 'No') . "</p>";

// 2. Database Status
$dbPath = __DIR__ . '/db/projects_v2.json';
echo "<h2>Authentication Data</h2>";

$defaultData = [
    'super_users' => [
        ['username' => 'admin', 'password' => '1375']
    ],
    'projects' => []
];

if (!file_exists($dbPath)) {
    if (file_put_contents($dbPath, json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        echo "<p style='color:green'>Database file created with default admin/1375.</p>";
    } else {
        echo "<p style='color:red'>Failed to create database file at $dbPath</p>";
    }
} else {
    $current = json_decode(file_get_contents($dbPath), true);
    echo "<h3>Current Super Users in JSON:</h3><ul>";
    foreach ($current['super_users'] ?? [] as $su) {
        echo "<li>User: <b>{$su['username']}</b> | Pass: <b>{$su['password']}</b></li>";
    }
    echo "</ul>";

    echo "<h3>Project Users in JSON:</h3><ul>";
    foreach ($current['projects'] ?? [] as $p) {
        echo "<li>Project: <b>{$p['name']}</b><ul>";
        foreach ($p['users'] ?? [] as $pu) {
            echo "<li>User: <b>{$pu['username']}</b> | Pass: <b>{$pu['password']}</b> | Role: {$pu['role']}</li>";
        }
        echo "</ul></li>";
    }
    echo "</ul>";
}

// 3. MySQL Check
require_once 'inc/db.php';
echo "<h2>MySQL Engine info</h2>";
echo "<p>SQL Active: " . (isSqlActive() ? 'Yes' : 'No') . "</p>";
if (isSqlActive()) {
    $conn = getConn();
    $res = $conn->query("SELECT * FROM super_users");
    if ($res) {
        echo "<h3>Super Users in MySQL:</h3><ul>";
        while($row = $res->fetch_assoc()) {
            echo "<li>User: <b>{$row['username']}</b> | Pass: <b>{$row['password']}</b></li>";
        }
        echo "</ul>";
    }
    
    // Check Project Users
    $res = $conn->query("SELECT p.name as pname, u.username, u.password, u.role FROM project_users u JOIN projects p ON u.project_id = p.id");
    if ($res) {
        echo "<h3>All Project Users in MySQL:</h3><ul>";
        while($row = $res->fetch_assoc()) {
            echo "<li>Project: <b>{$row['pname']}</b> | User: <b>{$row['username']}</b> | Pass: <b>{$row['password']}</b> | Role: {$row['role']}</li>";
        }
        echo "</ul>";
    }
}

echo "<h2>Actions</h2>";
echo "<form method='POST'><button name='reset_force' style='padding:10px 20px; background:red; color:white; border:none; border-radius:5px; cursor:pointer;'>Force Reset to admin/1375 (DANGEROUS: Wipes SQL users too)</button></form>";

if (isset($_POST['reset_force'])) {
    file_put_contents($dbPath, json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (isSqlActive()) {
        $conn->query("DELETE FROM super_users");
        $conn->query("INSERT INTO super_users (username, password) VALUES ('admin', '1375')");
    }
    echo "<p style='color:blue'>Reset command executed. Try login now.</p>";
}

echo "<hr><p><a href='index.php'>Go to Login (admin / 1375)</a></p>";
