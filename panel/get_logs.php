<?php
header('Content-Type: text/plain');
$logFile = __DIR__ . '/scraper/worker.log';

if (file_exists($logFile)) {
    // Read last 50 lines
    $lines = file($logFile);
    $lastLines = array_slice($lines, -50);
    echo implode("", $lastLines);
    
    // Optional: Auto-clear if too large
    if (count($lines) > 2000) {
        file_put_contents($logFile, implode("", array_slice($lines, -100)));
    }
} else {
    echo "No logs found yet. System idle.";
}
