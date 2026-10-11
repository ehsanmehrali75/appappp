<?php
require_once 'inc/functions.php';
if (isSqlActive()) {
    $conn = getConn();
    $res = $conn->query("SHOW VARIABLES LIKE 'socket'");
    $row = $res->fetch_assoc();
    echo "SOCKET_PATH:" . $row['Value'] . "\n";
    echo "HOST_INFO:" . $conn->host_info . "\n";
} else {
    echo "NO_SQL_ACTIVE\n";
}
