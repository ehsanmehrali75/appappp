<?php
/**
 * panel/callback_instagram.php
 * OAuth 2.0 Callback handler for Instagram Graph API connection
 */

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/InstagramAdapter.php';

$project = getCurrentProject();
$connSettings = $project['settings']['connections']['instagram'] ?? [];

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';
$errorReason = $_GET['error_reason'] ?? '';
$errorDesc = $_GET['error_description'] ?? '';

$success = false;
$errorMessage = '';
$accountData = [];

// Determine Redirect URI
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
$redirectUri = $protocol . '://' . $host . '/callback/account/Instagram';

// Start OAuth Authorization redirect flow
if (isset($_GET['action']) && $_GET['action'] === 'start_auth') {
    header("Location: /instagram/oauth/start");
    exit;
}

if (!empty($error) || !empty($errorDesc)) {
    $errorMessage = !empty($errorDesc) ? $errorDesc : (!empty($errorReason) ? $errorReason : $error);
} elseif (!empty($code)) {
    $oAuthConfig = InstagramAdapter::getOAuthConfig($project);
    $clientId = $oAuthConfig['client_id'];
    $clientSecret = $oAuthConfig['client_secret'];
    $redirectUri = $oAuthConfig['redirect_uri'];
    $proxy = $connSettings['proxy'] ?? null;

    if (empty($clientId) || empty($clientSecret)) {
        $errorMessage = "تنظیمات کلید کلاینت (Client ID یا Client Secret) اینستاگرام در سرور یافت نشد. لطفاً با مدیر سیستم تماس بگیرید.";
    } else {
        $adapter = new InstagramAdapter();
        $connectRes = $adapter->connect($code, $clientId, $clientSecret, $redirectUri, $proxy);

        if ($connectRes['ok']) {
            $success = true;
            $accountData = $connectRes;

            // Save credentials to project settings
            $project['settings']['connections']['instagram']['access_token'] = $connectRes['access_token'];
            $project['settings']['connections']['instagram']['token'] = $connectRes['access_token'];
            $project['settings']['connections']['instagram']['instagram_account_id'] = $connectRes['instagram_account_id'];
            $project['settings']['connections']['instagram']['chatId'] = $connectRes['instagram_account_id'];
            $project['settings']['connections']['instagram']['username'] = $connectRes['username'];
            $project['settings']['connections']['instagram']['account_type'] = $connectRes['account_type'];
            $project['settings']['connections']['instagram']['token_expiry'] = $connectRes['token_expiry'];
            $project['settings']['connections']['instagram']['connection_status'] = 'connected';
            $project['settings']['connections']['instagram']['connected_at'] = time();

            // Append to audit log
            $project['logs'][] = [
                'at' => time(),
                'type' => 'اتصال حساب اینستاگرام OAuth 2.0',
                'productName' => '@' . $connectRes['username'],
                'platform' => 'instagram'
            ];

            saveProject($project);
        } else {
            $errorMessage = $connectRes['error'] ?? 'خطا در تبادل کد دسترسی با توکن اینستاگرام';
        }
    }
} else {
    $errorMessage = "هیچ کد احراز هویتی (Authorization Code) از طرف اینستاگرام دریافت نشد.";
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $success ? 'اتصال موفق اینستاگرام' : 'خطای اتصال اینستاگرام'; ?> | Maarksho Flow</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="max-w-md w-full bg-white rounded-3xl shadow-xl p-8 border border-slate-100 text-center animate-in zoom-in-95 duration-200">
        
        <?php if ($success): ?>
            <!-- Success State -->
            <div class="w-20 h-20 bg-gradient-to-tr from-emerald-400 to-green-500 rounded-3xl mx-auto flex items-center justify-center text-white text-3xl shadow-lg shadow-green-100 mb-6">
                ✓
            </div>
            
            <h2 class="text-xl font-black text-slate-900 mb-2">اتصال اینستاگرام با موفقیت انجام شد! 🎉</h2>
            <p class="text-xs text-slate-500 mb-6 leading-relaxed">
                اکانت تجاری <b class="text-pink-600 font-black">@<?php echo htmlspecialchars($accountData['username'] ?? 'Instagram'); ?></b> با موفقیت متصل شد و توکن ۶۰ روزه با پرمیشن‌های انتشار محتوا ثبت گردید.
            </p>

            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-6 text-right space-y-2 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">نام کاربری:</span>
                    <span class="font-black text-slate-800 dir-ltr">@<?php echo htmlspecialchars($accountData['username'] ?? ''); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">شناسه اکانت (Account ID):</span>
                    <span class="font-mono text-slate-800 text-[11px]"><?php echo htmlspecialchars($accountData['instagram_account_id'] ?? ''); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">مدت اعتبار توکن:</span>
                    <span class="font-bold text-emerald-600">۶۰ روز (تمدید خودکار)</span>
                </div>
            </div>

            <p class="text-[11px] text-slate-400 mb-6" id="countdown-text">
                این پنجره تا ۲ ثانیه دیگر به طور خودکار بسته می‌شود...
            </p>

            <button onclick="closePopup()" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs rounded-xl shadow-lg shadow-indigo-100 transition-all">
                بستن پنجره و بازگشت به پنل
            </button>

            <script>
                // Post success message to parent window (opener)
                try {
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'INSTAGRAM_AUTH_SUCCESS',
                            username: <?php echo json_encode($accountData['username'] ?? ''); ?>,
                            accountId: <?php echo json_encode($accountData['instagram_account_id'] ?? ''); ?>
                        }, '*');
                    }
                } catch(e) {
                    console.error('PostMessage error:', e);
                }

                function closePopup() {
                    window.close();
                }

                // Auto close popup
                setTimeout(() => {
                    window.close();
                }, 2200);
            </script>

        <?php else: ?>
            <!-- Error State -->
            <div class="w-20 h-20 bg-rose-50 text-rose-500 rounded-3xl mx-auto flex items-center justify-center text-3xl border border-rose-100 shadow-lg shadow-rose-100 mb-6">
                ✕
            </div>
            
            <h2 class="text-xl font-black text-slate-900 mb-2">خطا در اتصال اینستاگرام</h2>
            <p class="text-xs text-rose-600 bg-rose-50 p-4 rounded-2xl border border-rose-100 mb-6 leading-relaxed font-bold">
                <?php echo htmlspecialchars($errorMessage); ?>
            </p>

            <p class="text-[11px] text-slate-400 mb-6">
                لطفاً از صحت Client ID و Client Secret و دسترسی‌های فیسبوک اطمینان حاصل کرده و مجدداً تلاش نمایید.
            </p>

            <div class="flex gap-3">
                <button onclick="window.close()" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition-all">
                    بستن پنجره
                </button>
                <a href="/admin?tab=settings" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition-all flex items-center justify-center">
                    تنظیمات پنل
                </a>
            </div>

            <script>
                try {
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'INSTAGRAM_AUTH_ERROR',
                            error: <?php echo json_encode($errorMessage); ?>
                        }, '*');
                    }
                } catch(e) {
                    console.error('PostMessage error:', e);
                }
            </script>
        <?php endif; ?>

    </div>
</body>
</html>
