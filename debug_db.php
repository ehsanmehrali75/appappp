<?php
require_once 'inc/functions.php';

$db = fetchDB();
header('Content-Type: application/json');
echo json_encode($db['projects'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
