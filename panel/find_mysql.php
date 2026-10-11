<?php
$conn = new mysqli('localhost', 'maarksho_flow', 'EhSaN._1375', 'maarksho_flow');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$res = $conn->query("SHOW VARIABLES LIKE 'socket'");
$row = $res->fetch_assoc();
echo "SOCKET_PATH=" . $row['Value'] . "\n";
echo "HOST=" . gethostname() . "\n";
echo "IP=" . gethostbyname('localhost') . "\n";
