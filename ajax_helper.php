<?php
require_once 'inc/functions.php';

if (!isUser()) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'clear_debug_log') {
    $file = __DIR__ . '/scraper/scraper_debug.log';
    if (file_exists($file)) {
        file_put_contents($file, "");
    }
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'msg' => 'Invalid action']);
