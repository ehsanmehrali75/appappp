<?php
require_once __DIR__.'/inc/functions.php';
if(!isMainAdmin())AccessControl::deny();
header('Cache-Control: no-store');
header('Content-Type: text/plain');
$logFile = __DIR__ . '/scraper/worker.log';

if (file_exists($logFile)) {
    // Read last 50 lines
    $lines = file($logFile);
    $lastLines = array_slice($lines, -50);
    echo implode("", $lastLines);
    

} else {
    echo "No logs found yet. System idle.";
}
