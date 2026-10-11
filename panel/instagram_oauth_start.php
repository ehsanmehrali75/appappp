<?php
/**
 * panel/instagram_oauth_start.php
 * Route: /instagram/oauth/start
 *
 * Dedicated OAuth start route for Instagram SaaS integration:
 * 1. Checks server environment variables (INSTAGRAM_CLIENT_ID, INSTAGRAM_CLIENT_SECRET, INSTAGRAM_REDIRECT_URI)
 *    and Admin Settings (Social API Configuration).
 * 2. If configured, generates OAuth URL with CSRF state and immediately redirects user to Instagram (HTTP 302).
 * 3. Never asks end-users for technical Client ID or Client Secret.
 * 4. Never exposes Client Secret to frontend.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/InstagramAdapter.php';

$project = getCurrentProject();
$config = InstagramAdapter::getOAuthConfig($project);

$isJson = (isset($_GET['format']) && $_GET['format'] === 'json') ||
          (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (!$config['has_client_id'] || empty($config['client_id'])) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'status' => 'error',
            'error' => 'تنظیمات کلید کلاینت اینستاگرام در سرور یافت نشد. لطفاً در پنل ادمین یا فایل .env مقادیر مربوطه را پیکربندی نمایید.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Graceful SaaS-like notice if admin has not configured Meta App yet (NO prompt for Client ID/Secret)
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>سرویس اتصال اینستاگرام | در دسترس نیست</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
        <style>body { font-family: 'Vazirmatn', sans-serif; }</style>
    </head>
    <body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
        <div class="max-w-md w-full bg-white rounded-3xl p-8 border border-slate-100 shadow-xl text-center">
            <div class="w-16 h-16 bg-amber-50 text-amber-600 rounded-2xl mx-auto flex items-center justify-center text-3xl font-black mb-4">
                ⚠️
            </div>
            <h3 class="text-base font-black text-slate-800 mb-2">سرویس اتصال به اینستاگرام در دسترس نیست</h3>
            <p class="text-xs text-slate-500 mb-6 leading-relaxed">
                تنظیمات اتصال به سرویس اینستاگرام در سرور تکمیل نشده است. لطفاً با مدیر سیستم تماس بگیرید.
            </p>
            <button type="button" onclick="window.close()" class="w-full py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all">
                بستن پنجره
            </button>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Generate CSRF protection state
$state = bin2hex(random_bytes(16));
$_SESSION['ig_oauth_state'] = $state;

$authUrl = InstagramAdapter::getAuthUrl($config['client_id'], $config['redirect_uri'], $state);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'status' => 'success',
        'url' => $authUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Direct 302 Redirect to Meta / Instagram OAuth
header("Location: $authUrl", true, 302);
exit;
