<?php
require_once __DIR__ . '/inc/db.php';
$conn = getConn();
if ($conn) {
    echo "Connection successful. Socket path should be saved.\n";
    $res = $conn->query("SHOW VARIABLES LIKE 'socket'");
    if ($res) {
        $row = $res->fetch_assoc();
        echo "Socket Path: " . $row['Value'] . "\n";
    }
} else {
    echo "Connection failed.\n";
}
