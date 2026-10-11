<?php
/**
 * Global CORS Handler
 */
function send_cors_headers() {
    if (headers_sent()) return;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    if ($origin !== 'null') {
        header("Access-Control-Allow-Origin: $origin", true);
        header('Access-Control-Allow-Credentials: true', true);
    } else {
        header("Access-Control-Allow-Origin: *", true);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Project-ID, Authorization, Origin, Accept', true);
    header('Access-Control-Max-Age: 86400', true);
}

// Ensure headers are sent immediately
send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
