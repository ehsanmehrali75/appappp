<?php
header('Content-Type: application/json');
$logFile = __DIR__ . '/scraper/scraper_debug.log';

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    // The log file is multiple JSON objects or text?
    // Based on addDebugLog: file_put_contents($file, "[" . date('Y-m-d H:i:s') . "] [$type] " . $msg . ($data ? " | " . json_encode($data, JSON_UNESCAPED_UNICODE) : "") . PHP_EOL, FILE_APPEND);
    
    $lines = file($logFile);
    $lastLines = array_slice($lines, -100);
    echo json_encode(['status' => 'success', 'logs' => implode("", $lastLines)]);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'No debug logs found.']);
}
