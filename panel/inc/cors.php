<?php
/**
 * Global CORS Handler
 */
function send_cors_headers() {
    if (headers_sent()) return;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $scheme=(($_SERVER['HTTPS']??'')==='on'||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https')?'https':'http';
    $allowed=array_filter(array_map('trim',explode(',',getenv('MAARKSHO_ALLOWED_ORIGINS')?:'')));
    $allowed[]=$scheme.'://'.($_SERVER['HTTP_HOST']??'');
    if ($origin!=='' && in_array($origin,$allowed,true)) {
        header("Access-Control-Allow-Origin: $origin", true);
        header('Access-Control-Allow-Credentials: true', true);
    }
    header('Vary: Origin',false);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token, X-Project-ID, Authorization, Origin, Accept', true);
    header('Access-Control-Max-Age: 86400', true);
}

// Ensure headers are sent immediately
send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
