<?php
require_once 'inc/functions.php';

// Silent Database Diagnostic Hook
try {
    $trace = [];
    $trace['time'] = date('Y-m-d H:i:s');
    $trace['sql_active'] = isSqlActive() ? 'yes' : 'no';
    $conn = getConn();
    $trace['conn_status'] = ($conn !== null) ? 'ok' : 'null';
} catch (Exception $e) {
    // silent fallback
}

$site_settings = getSiteSettings();
$subscription_plans = getSubscriptionPlans();

$login_error = '';
$sms_notification = '';
$otp_step = 'enter_mobile'; // 'enter_mobile' or 'verify_otp'
$temp_mobile = '';

// Check order placement and payment state
$order_success = null;
$order_error = null;

// ----------------------------------------------------
// Handle ZarinPal Gateway Return Callback
// ----------------------------------------------------
if (isset($_GET['payment_callback']) && $_GET['payment_callback'] === 'zarinpal') {
    $cb_order_id = trim($_GET['order_id'] ?? '');
    $cb_authority = trim($_GET['Authority'] ?? '');
    $cb_status = trim($_GET['Status'] ?? '');

    $order = null;
    if (!empty($cb_order_id)) {
        $order = getOrderById($cb_order_id);
    }
    if (!$order && !empty($cb_authority)) {
        $order = getOrderByAuthority($cb_authority);
    }

    if ($order) {
        if (strtoupper($cb_status) === 'OK') {
            $verifyRes = zarinpalVerifyPayment($cb_authority ?: ($order['authority'] ?? ''), $order['amount']);
            if ($verifyRes['success']) {
                $order['status'] = 'completed';
                $order['ref_code'] = (string)($verifyRes['ref_id'] ?? ($order['ref_code'] ?? rand(100000, 999999)));
                $order['card_pan'] = $verifyRes['card_pan'] ?? '';
                $order['card_hash'] = $verifyRes['card_hash'] ?? '';
                $order['paid_at'] = date('Y-m-d H:i:s');
                saveOrder($order);

                // Automatically provision / activate user portal account
                savePortalUser($order['customer_mobile'], $order['customer_name'], '', '', 0);

                $order_success = $order;
            } else {
                $order['status'] = 'failed';
                $order['error_message'] = $verifyRes['error'] ?? 'خطا در تایید تراکنش بانکی';
                saveOrder($order);
                $order_error = '❌ تراکنش تایید نشد: ' . ($verifyRes['error'] ?? 'پرداخت ناموفق');
            }
        } else {
            $order['status'] = 'canceled';
            saveOrder($order);
            $order_error = '⚠️ فرآیند پرداخت در درگاه زرین‌پال توسط شما لغو شد یا تراکنش ناموفق بود.';
        }
    } else {
        $order_error = 'سفارش مورد نظر در سیستم یافت نشد یا منقضی شده است.';
    }
}

// ----------------------------------------------------
// Handle Checkout Form Submission
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checkout_order'])) {
    $c_name = trim($_POST['customer_name'] ?? '');
    $c_mobile = trim($_POST['customer_mobile'] ?? '');
    $c_business = trim($_POST['customer_business'] ?? '');
    $c_plan_id = trim($_POST['plan_id'] ?? 'plan_pro');
    $c_cycle = trim($_POST['billing_cycle'] ?? 'yearly');
    $c_gateway = trim($_POST['payment_gateway'] ?? 'zarinpal');
    
    if (empty($c_name) || empty($c_mobile)) {
        $order_error = 'لطفاً نام و شماره همراه معتبر خود را وارد کنید.';
    } else {
        $selected_plan = null;
        foreach ($subscription_plans as $p) {
            if ($p['id'] === $c_plan_id) {
                $selected_plan = $p;
                break;
            }
        }
        if (!$selected_plan) {
            $selected_plan = $subscription_plans[1] ?? reset($subscription_plans);
        }
        
        $price = ($c_cycle === 'yearly') ? ($selected_plan['price_yearly'] ?? 0) : ($selected_plan['price_monthly'] ?? 0);
        $order_id = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
        
        $order_data = [
            'order_id' => $order_id,
            'customer_name' => $c_name,
            'customer_mobile' => $c_mobile,
            'customer_business' => $c_business,
            'plan_id' => $selected_plan['id'],
            'plan_name' => $selected_plan['name'],
            'billing_cycle' => $c_cycle,
            'amount' => $price,
            'payment_gateway' => $c_gateway,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        if ($c_gateway === 'test') {
            // Simulated Test Gateway
            $order_data['status'] = 'completed';
            $order_data['ref_code'] = 'TEST-' . rand(100000, 999999);
            $order_data['paid_at'] = date('Y-m-d H:i:s');
            saveOrder($order_data);
            savePortalUser($c_mobile, $c_name, '', '', 0);
            $order_success = $order_data;
        } else {
            // ZarinPal Payment Gateway
            $merchantId = trim($site_settings['zarinpal_merchant_id'] ?? '');
            $isSandbox = !empty($site_settings['zarinpal_sandbox']);
            
            if (empty($merchantId) && !$isSandbox) {
                // If Merchant ID is not yet configured, complete as demo & advise
                $order_data['status'] = 'completed';
                $order_data['ref_code'] = 'ZP-DEMO-' . rand(100000, 999999);
                $order_data['paid_at'] = date('Y-m-d H:i:s');
                saveOrder($order_data);
                savePortalUser($c_mobile, $c_name, '', '', 0);
                $order_success = $order_data;
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $reqUri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
                $callbackUrl = $protocol . $host . $reqUri . '?payment_callback=zarinpal&order_id=' . urlencode($order_id);
                
                $descPrefix = trim($site_settings['zarinpal_description_prefix'] ?? 'خرید اشتراک مارکشو فلو - سفارش ');
                $description = $descPrefix . $order_id . ' (' . $selected_plan['name'] . ')';
                
                $zarinRes = zarinpalRequestPayment($price, $description, $callbackUrl, $c_mobile, '', $order_id);
                
                if ($zarinRes['success'] && !empty($zarinRes['payment_url'])) {
                    $order_data['authority'] = $zarinRes['authority'];
                    saveOrder($order_data);
                    header('Location: ' . $zarinRes['payment_url']);
                    exit;
                } else {
                    $order_error = '❌ خطا در اتصال به درگاه زرین‌پال: ' . ($zarinRes['error'] ?? 'خطای نامشخص');
                }
            }
        }
    }
}

// Handle OTP Send requests
if (isset($_POST['send_otp'])) {
    $mobile = trim($_POST['mobile_num'] ?? '');
    $mobile = faToEn($mobile);
    $mobile = preg_replace('/[^0-9]/', '', $mobile);
    if (strlen($mobile) < 10) {
        $login_error = 'شماره همراه وارد شده نامعتبر است.';
    } else {
        if (strpos($mobile, '09') !== 0) {
            $mobile = '0' . ltrim($mobile, '0');
        }
        
        $project = getCurrentProject();
        $smsType = ($project && isset($project['settings']['sms']['type'])) ? $project['settings']['sms']['type'] : 'otp';
        
        if ($smsType === 'pattern') {
            $otp = (string)rand(10000, 99999);
            $res = sendSmsByPattern($mobile, [$otp], $project['settings'] ?? null, 'otp');
            if ($res['success']) {
                $expires = time() + 300;
                savePortalUser($mobile, 'کاربر پورتال ' . substr($mobile, -4), '', $otp, $expires);
                $sms_notification = "⚡ کد تایید با موفقیت به شماره " . $mobile . " ارسال شد.";
                $_SESSION['temp_otp_mobile'] = $mobile;
                $_SESSION['temp_otp_code'] = $otp;
                $_SESSION['temp_otp_expires'] = $expires;
                $temp_mobile = $mobile;
                $otp_step = 'verify_otp';
            } else {
                $login_error = "⚠️ خطای درگاه پیامک: " . $res['error'];
                $otp_step = 'enter_mobile';
            }
        } else {
            $res = sendMelipayamakOTP($mobile);
            if ($res['success']) {
                $otp = (string)$res['code'];
                $expires = time() + 300;
                savePortalUser($mobile, 'کاربر پورتال ' . substr($mobile, -4), '', $otp, $expires);
                $sms_notification = "⚡ کد تایید با موفقیت به شماره " . $mobile . " پیامک شد.";
                $_SESSION['temp_otp_mobile'] = $mobile;
                $_SESSION['temp_otp_code'] = $otp;
                $_SESSION['temp_otp_expires'] = $expires;
                $temp_mobile = $mobile;
                $otp_step = 'verify_otp';
            } else {
                $login_error = "⚠️ خطای درگاه پیامک: " . $res['error'];
                $otp_step = 'enter_mobile';
            }
        }
    }
}

// Handle OTP Match checks
if (isset($_POST['verify_code'])) {
    $mobile = $_POST['mobile_num'] ?? $_SESSION['temp_otp_mobile'] ?? '';
    $mobile = faToEn($mobile);
    $mobile = preg_replace('/[^0-9]/', '', $mobile);
    if (strpos($mobile, '09') !== 0 && strlen($mobile) === 10) {
        $mobile = '0' . $mobile;
    }
    $user_code = trim($_POST['verification_num'] ?? '');
    $user_code = faToEn($user_code);
    
    if (empty($mobile) || empty($user_code)) {
        $login_error = 'کد تایید یا شماره تماس یافت نشد.';
    } else {
        $pUser = getPortalUser($mobile);
        if (!$pUser) {
            if (!empty($_SESSION['temp_otp_mobile']) && $_SESSION['temp_otp_mobile'] === $mobile && !empty($_SESSION['temp_otp_code'])) {
                $pUser = [
                    'mobile' => $mobile,
                    'display_name' => 'کاربر پورتال ' . substr($mobile, -4),
                    'user_icon' => '',
                    'otp_code' => $_SESSION['temp_otp_code'],
                    'otp_expires' => $_SESSION['temp_otp_expires'] ?? 0
                ];
            }
        }
        
        if ($pUser && $pUser['otp_code'] === $user_code && (int)$pUser['otp_expires'] >= time()) {
            unset($_SESSION['temp_otp_mobile']);
            unset($_SESSION['temp_otp_code']);
            unset($_SESSION['temp_otp_expires']);
            
            savePortalUser($mobile, $pUser['display_name'], $pUser['user_icon'] ?? '', '', 0);
            
            $_SESSION['portal_user'] = $mobile;
            $_SESSION['portal_user_icon'] = $pUser['user_icon'] ?? '';
            $_SESSION['portal_user_display_name'] = $pUser['display_name'] ?? ('کاربر پورتال ' . substr($mobile, -4));
            
            $db = getDB();
            $matching_member = null;
            $member_project_id = '';
            
            if (!empty($db['projects'])) {
                foreach ($db['projects'] as $prj) {
                    if (isset($prj['users'])) {
                        foreach ($prj['users'] as $u) {
                            if (isset($u['mobile']) && trim($u['mobile']) === $mobile) {
                                $matching_member = $u;
                                $member_project_id = $prj['id'];
                                break 2;
                            }
                        }
                    }
                }
            }
            
            if ($matching_member) {
                $_SESSION['user'] = $matching_member;
                $_SESSION['project_id'] = $member_project_id;
            } else {
                $firstPrjId = '';
                if (!empty($db['projects'])) {
                    $firstPrj = reset($db['projects']);
                    $firstPrjId = $firstPrj['id'];
                }
                $_SESSION['user'] = [
                    'username' => $pUser['display_name'] ?? 'مهمان پورتال (' . substr($mobile, -4) . ')',
                    'role' => 'portal_viewer',
                    'permissions' => ['dashboard']
                ];
                $_SESSION['project_id'] = $firstPrjId;
            }
            
            redirect(canAccessManagement() ? 'panel/admin.php' : 'panel/portal.php');
        } else {
            $login_error = 'کد وارد شده صحیح نیست یا منقضی شده است.';
            $temp_mobile = $mobile;
            $otp_step = 'verify_otp';
        }
    }
}

// Handle Admin password login
if (isset($_POST['login'])) {
    $u = trim($_POST['user'] ?? '');
    $p = trim($_POST['pass'] ?? '');
    
    require_once __DIR__ . '/panel/inc/SystemAdmins.php';
    $systemAdmin = SystemAdmins::authenticate($u, $p);
    if ($systemAdmin) {
        session_regenerate_id(true);
        $_SESSION['user'] = $systemAdmin;
        redirect('panel/admin.php');
        exit;
    }
    $db = getDB();

    foreach ($db['super_users'] as $su) {
        if ($su['username'] === $u) {
            $matched = false;
            if (password_get_info($su['password'])['algo']) {
                 $matched = password_verify($p, $su['password']);
            } else {
                 $matched = ($su['password'] === $p);
            }

            if ($matched) {
                $_SESSION['user'] = ['username' => $u, 'role' => 'superadmin'];
                redirect('panel/admin.php');
            }
        }
    }
    
    foreach ($db['projects'] as $project) {
        if (!isset($project['users'])) continue;
        foreach ($project['users'] as $user) {
            if ($user['username'] === $u) {
                $matched = false;
                if (password_get_info($user['password'])['algo']) {
                     $matched = password_verify($p, $user['password']);
                } else {
                     $matched = ($user['password'] === $p);
                }

                if ($matched) {
                    $_SESSION['user'] = $user;
                    $_SESSION['project_id'] = $project['id'];
                    redirect(canAccessManagement() ? 'panel/admin.php' : 'panel/portal.php');
                }
            }
        }
    }
    
    $login_error = 'نام کاربری یا رمز عبور اشتباه است.';
}

$show_login_modal = ($login_error !== '' || $sms_notification !== '' || isset($_GET['action']) && $_GET['action'] === 'login');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_settings['site_title']); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($site_settings['site_tagline']); ?>">
    <link rel="stylesheet" href="<?php echo getFullUrl('assets/style.css'); ?>">
    <script src="<?php echo getFullUrl('assets/tailwindcss.js'); ?>"></script>
    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Regular.woff2'); ?>') format('woff2');
            font-weight: normal;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Bold.woff2'); ?>') format('woff2');
            font-weight: bold;
        }
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #0b0f19;
            color: #f8fafc;
            overflow-x: hidden;
        }
        .hero-bg {
            background-image: 
                radial-gradient(ellipse at 50% -20%, rgba(99, 102, 241, 0.25) 0%, transparent 70%),
                radial-gradient(ellipse at 80% 60%, rgba(244, 63, 94, 0.15) 0%, transparent 60%);
        }
        .glass-panel {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .glow-border {
            box-shadow: 0 0 40px -10px rgba(99, 102, 241, 0.4);
        }
    </style>
</head>
<body class="antialiased">

    <!-- Header Navigation -->
    <header class="sticky top-0 z-50 glass-panel border-b border-slate-800/80">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-600 text-white rounded-2xl flex items-center justify-center font-black text-xl shadow-lg shadow-indigo-500/30">
                    <?php echo htmlspecialchars($site_settings['logo_icon']); ?>
                </div>
                <div>
                    <span class="font-black text-xl text-white tracking-tight block"><?php echo htmlspecialchars($site_settings['logo_text']); ?></span>
                    <span class="text-[9px] text-indigo-400 font-bold uppercase tracking-widest -mt-1 block">Digital Core Engine</span>
                </div>
            </a>

            <nav class="hidden lg:flex items-center gap-8 text-xs font-bold text-slate-300">
                <a href="#features" class="hover:text-indigo-400 transition-all flex items-center gap-1.5"><span>⚡</span> امکانات و ابزارها</a>
                <a href="#demo" class="hover:text-indigo-400 transition-all flex items-center gap-1.5"><span>🤖</span> دموی آنلاین</a>
                <a href="#pricing" class="hover:text-indigo-400 transition-all flex items-center gap-1.5"><span>💎</span> تعرفه‌ها و خرید</a>
                <a href="about.php" class="hover:text-indigo-400 transition-all flex items-center gap-1.5"><span>🏢</span> درباره ما</a>
                <a href="contact.php" class="hover:text-indigo-400 transition-all flex items-center gap-1.5"><span>📞</span> تماس با ما</a>
            </nav>

            <div class="flex items-center gap-3">
                <?php if (isSuperAdmin()): ?>
                    <a href="panel/admin.php" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md flex items-center gap-2">
                        <span>👑</span>
                        <span>ورود به مدیریت کلان</span>
                    </a>
                <?php elseif (isUser()): ?>
                    <a href="panel/portal.php" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md flex items-center gap-2">
                        <span>👤</span>
                        <span>ورود به پنل کاربری</span>
                    </a>
                <?php else: ?>
                    <button onclick="openLoginModal()" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-black rounded-xl transition-all shadow-sm flex items-center gap-2">
                        <span>🔑</span>
                        <span>ورود به پنل</span>
                    </button>
                <?php endif; ?>
                <a href="#pricing" class="px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-rose-600 hover:from-indigo-500 hover:to-rose-500 text-white text-xs font-black rounded-xl transition-all shadow-lg shadow-indigo-500/20">
                    خرید و فعال‌سازی فوری 🚀
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative pt-20 pb-24 hero-bg">
        <div class="max-w-6xl mx-auto px-6 text-center relative z-10">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-300 text-xs font-black mb-8 animate-pulse">
                <span>✨ نسخه جدید ۳.۰ مارکشو فلو منتشر شد</span>
                <span class="w-1.5 h-1.5 bg-indigo-400 rounded-full"></span>
                <span>هوش مصنوعی + CRM + کاتالوگ</span>
            </div>

            <h1 class="text-3xl md:text-6xl font-black text-white leading-tight mb-8 max-w-4xl mx-auto tracking-tight">
                <?php echo htmlspecialchars($site_settings['hero_title']); ?>
            </h1>

            <p class="text-slate-400 text-sm md:text-lg max-w-2xl mx-auto mb-12 leading-relaxed font-medium">
                <?php echo htmlspecialchars($site_settings['hero_subtitle']); ?>
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-16">
                <a href="#pricing" class="w-full sm:w-auto px-8 py-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-black rounded-2xl transition-all shadow-xl shadow-indigo-600/30 flex items-center justify-center gap-3">
                    <span><?php echo htmlspecialchars($site_settings['hero_cta_secondary']); ?></span>
                    <span class="text-lg">←</span>
                </a>
                <a href="#demo" class="w-full sm:w-auto px-8 py-4 bg-slate-800/80 hover:bg-slate-700 border border-slate-700 text-slate-200 text-sm font-black rounded-2xl transition-all flex items-center justify-center gap-2">
                    <span>📺</span>
                    <span>تست ابزارها و مشاهده دمو</span>
                </a>
            </div>

            <!-- Platform Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-4xl mx-auto">
                <div class="glass-panel p-5 rounded-2xl text-center">
                    <div class="text-2xl font-black text-indigo-400 mb-1">۱,۲۰۰+</div>
                    <div class="text-[11px] text-slate-400 font-bold">کسب‌وکار فعال</div>
                </div>
                <div class="glass-panel p-5 rounded-2xl text-center">
                    <div class="text-2xl font-black text-rose-400 mb-1">۵۰۰,۰۰۰+</div>
                    <div class="text-[11px] text-slate-400 font-bold">محتوا و پیام تولیدشده</div>
                </div>
                <div class="glass-panel p-5 rounded-2xl text-center">
                    <div class="text-2xl font-black text-emerald-400 mb-1">۲۴/۷</div>
                    <div class="text-[11px] text-slate-400 font-bold">پاسخگویی خودکار ربات</div>
                </div>
                <div class="glass-panel p-5 rounded-2xl text-center">
                    <div class="text-2xl font-black text-amber-400 mb-1">۹۹.۹٪</div>
                    <div class="text-[11px] text-slate-400 font-bold">رضایت مشتریان</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Interactive Demo Section -->
    <section id="demo" class="py-20 bg-slate-950 border-y border-slate-800/80">
        <div class="max-w-6xl mx-auto px-6">
            <div class="text-center mb-16">
                <span class="px-4 py-1.5 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-full text-xs font-black inline-block mb-3">
                    تجربه آنلاین قبل از خرید
                </span>
                <h2 class="text-2xl md:text-4xl font-black text-white mb-4">دموی زنده ابزارهای مارکشو فلو</h2>
                <p class="text-slate-400 text-xs md:text-sm max-w-xl mx-auto">
                    در بخش زیر می‌توانید عملکرد واقعی ابزارها را به صورت زنده تست نمایید:
                </p>
            </div>

            <!-- Tool Selector Tabs -->
            <div class="flex flex-wrap items-center justify-center gap-2 mb-8 bg-slate-900/80 p-2 rounded-2xl border border-slate-800">
                <button onclick="switchDemo('prompt')" id="btn-demo-prompt" class="px-5 py-3 rounded-xl text-xs font-black transition-all bg-indigo-600 text-white shadow-lg">
                    🧠 پرامپت هاب و AI
                </button>
                <button onclick="switchDemo('catalog')" id="btn-demo-catalog" class="px-5 py-3 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-white">
                    📖 کاتالوگ و جدول سایز
                </button>
                <button onclick="switchDemo('chat')" id="btn-demo-chat" class="px-5 py-3 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-white">
                    💬 ویجت چت زنده
                </button>
                <button onclick="switchDemo('crm')" id="btn-demo-crm" class="px-5 py-3 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-white">
                    📊 CRM و خط فروش
                </button>
            </div>

            <!-- Demo Container -->
            <div class="glass-panel p-6 md:p-8 rounded-3xl border border-slate-800 glow-border">
                
                <!-- Demo 1: Prompt Hub -->
                <div id="demo-box-prompt" class="space-y-6">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 pb-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-base font-black text-white flex items-center gap-2">
                                <span>🧠</span> دستیار تولید محتوا و پرامپت هوشمند
                            </h3>
                            <p class="text-xs text-slate-400 mt-1">یک پرامپت آماده انتخاب کنید یا موضوع دلخواه را بنویسید تا پاسخ تولید شود.</p>
                        </div>
                        <div class="px-3 py-1 bg-indigo-500/10 text-indigo-400 rounded-lg text-[10px] font-black border border-indigo-500/20">
                            موتور Gemini / OpenAI Active
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <button onclick="runAiDemo('توضیحات جذاب برای کفش ورزشی نایک مدل ۲۲۰')" class="p-3 bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 text-right rounded-xl text-xs font-bold text-slate-200 transition-all">
                            ✨ توضیحات محصول فروشگاهی
                        </button>
                        <button onclick="runAiDemo('کپشن اینستاگرام پیشنهاد تخفیف آخر هفته')" class="p-3 bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 text-right rounded-xl text-xs font-bold text-slate-200 transition-all">
                            📲 کپشن اینستاگرام و هشتگ
                        </button>
                        <button onclick="runAiDemo('پاسخ محترمانه به پیگیری سفارش مشتری')" class="p-3 bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 text-right rounded-xl text-xs font-bold text-slate-200 transition-all">
                            💬 پاسخ هوشمند به پیام مشتری
                        </button>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl space-y-3">
                        <div class="flex items-center gap-2">
                            <input id="demo-ai-input" type="text" value="توضیحات جذاب برای کفش ورزشی نایک مدل ۲۲۰" class="flex-1 bg-slate-800 border border-slate-700 px-4 py-2.5 rounded-xl text-xs text-white font-bold outline-none focus:border-indigo-500">
                            <button onclick="generateAiTextDemo()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md">
                                تولید با AI 🚀
                            </button>
                        </div>
                        <div id="demo-ai-output" class="p-4 bg-slate-950/80 rounded-xl text-xs font-medium text-emerald-300 leading-relaxed border border-slate-800/80 min-h-[100px] flex items-center justify-center">
                            نتیجه خروجی هوش مصنوعی پس از کلیک نمایش داده می‌شود...
                        </div>
                    </div>
                </div>

                <!-- Demo 2: Digital Catalog -->
                <div id="demo-box-catalog" class="hidden space-y-6">
                    <div class="pb-4 border-b border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-black text-white">📖 کاتالوگ دیجیتال و راهنمای سایز هوشمند</h3>
                            <p class="text-xs text-slate-400 mt-1">نمایش محصول با قابلیت تغییر رنگ، انتخاب سایز و مشاهده آنلاین جدول سایزبندی</p>
                        </div>
                        <span class="px-3 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded-lg text-[10px] font-black">Interactive Catalog</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-900 p-6 rounded-2xl border border-slate-800">
                        <div class="space-y-4">
                            <div class="aspect-square bg-slate-800 rounded-2xl overflow-hidden relative border border-slate-700 flex items-center justify-center">
                                <img id="demo-cat-img" src="assets/cubes.png" class="w-full h-full object-cover">
                                <span class="absolute top-3 right-3 px-3 py-1 bg-indigo-600 text-white rounded-full text-[10px] font-black shadow-lg">موجود در انبار</span>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <span class="text-[10px] text-indigo-400 font-bold uppercase tracking-widest">کد کالا: MK-8820</span>
                            <h4 class="text-lg font-black text-white">پیراهن کتان اسپرت مردانه مارکشو</h4>
                            <div class="text-xl font-black text-emerald-400">۷۸۰,۰۰۰ تومان</div>
                            
                            <div>
                                <label class="block text-xs text-slate-400 font-bold mb-2">انتخاب رنگ:</label>
                                <div class="flex gap-2">
                                    <button onclick="changeDemoColor('مشکی', '#1e293b')" class="w-8 h-8 rounded-full bg-slate-800 border-2 border-indigo-500 shadow-md"></button>
                                    <button onclick="changeDemoColor('سورمه‌ای', '#1e3a8a')" class="w-8 h-8 rounded-full bg-blue-900 border-2 border-transparent"></button>
                                    <button onclick="changeDemoColor('خاکی', '#78350f')" class="w-8 h-8 rounded-full bg-amber-900 border-2 border-transparent"></button>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs text-slate-400 font-bold mb-2">انتخاب سایز:</label>
                                <div class="flex gap-2">
                                    <button class="px-3 py-1.5 bg-slate-800 border border-slate-700 text-white rounded-lg text-xs font-bold hover:border-indigo-500">M</button>
                                    <button class="px-3 py-1.5 bg-indigo-600 border border-indigo-500 text-white rounded-lg text-xs font-bold">L</button>
                                    <button class="px-3 py-1.5 bg-slate-800 border border-slate-700 text-white rounded-lg text-xs font-bold hover:border-indigo-500">XL</button>
                                </div>
                            </div>

                            <button onclick="alert('جدول سایزبندی: سایز L مناسب قد ۱۷۵ تا ۱۸۵ و وزن ۷۵ تا ۸۵ کیلوگرم است.')" class="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-indigo-300 text-xs font-bold rounded-xl border border-slate-700 flex items-center justify-center gap-2">
                                <span>📐</span>
                                <span>مشاهده جدول راهنمای سایز اختصاصی</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Demo 3: Live Chat Widget -->
                <div id="demo-box-chat" class="hidden space-y-6">
                    <div class="pb-4 border-b border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-black text-white">💬 ویجت چت زنده و پشتیبانی خودکار</h3>
                            <p class="text-xs text-slate-400 mt-1">تست پاسخگویی ۲۴ ساعته سیستم به سوالات کاربران</p>
                        </div>
                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-lg text-[10px] font-black">AI Live Assistant</span>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 max-w-md mx-auto space-y-3">
                        <div class="flex items-center gap-3 p-3 bg-slate-800/80 rounded-xl border border-slate-700/80">
                            <div class="w-8 h-8 bg-indigo-600 text-white rounded-full flex items-center justify-center font-black text-xs">M</div>
                            <div>
                                <div class="text-xs font-black text-white">پشتیبان آنلاین مارکشو</div>
                                <div class="text-[10px] text-emerald-400 font-bold">● آنلاین - پاسخگویی زیر ۱ ثانیه</div>
                            </div>
                        </div>

                        <div id="demo-chat-messages" class="space-y-3 h-48 overflow-y-auto p-2 bg-slate-950 rounded-xl text-xs font-medium">
                            <div class="bg-slate-800 p-2.5 rounded-xl text-slate-200 max-w-[80%] leading-relaxed">
                                سلام! خوش آمدید. چطور می‌توانم شما را راهنمایی کنم؟
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <input id="demo-chat-input" type="text" placeholder="سوال خود را بنویسید (مثلا: ساعت کاری یا هزینه ارسال)..." class="flex-1 bg-slate-800 border border-slate-700 px-3 py-2 rounded-xl text-xs text-white outline-none">
                            <button onclick="sendDemoChatMessage()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl">ارسال</button>
                        </div>
                    </div>
                </div>

                <!-- Demo 4: CRM Pipeline -->
                <div id="demo-box-crm" class="hidden space-y-6">
                    <div class="pb-4 border-b border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-black text-white">📊 مدیریت مشتریان و قیف فروش (CRM)</h3>
                            <p class="text-xs text-slate-400 mt-1">سازماندهی کامل لیدها و وضعیت سفارشات مشتریان</p>
                        </div>
                        <span class="px-3 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-lg text-[10px] font-black">Sales Pipeline</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl">
                            <div class="text-xs font-black text-indigo-400 mb-3 pb-2 border-b border-slate-800 flex justify-between">
                                <span>مشتری جدید (لید)</span>
                                <span class="bg-indigo-500/20 px-2 py-0.5 rounded text-[10px]">۲ مورد</span>
                            </div>
                            <div class="space-y-2">
                                <div class="bg-slate-800/80 p-3 rounded-xl border border-slate-700/80 text-xs font-bold text-white">
                                    <div>رضا حسینی</div>
                                    <div class="text-[10px] text-slate-400 font-normal mt-1">درخواست پلن حرفه‌ای - ۰۹۱۲۰۰۰۱۱۱</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl">
                            <div class="text-xs font-black text-amber-400 mb-3 pb-2 border-b border-slate-800 flex justify-between">
                                <span>پیش‌فاکتور صادرشده</span>
                                <span class="bg-amber-500/20 px-2 py-0.5 rounded text-[10px]">۱ مورد</span>
                            </div>
                            <div class="space-y-2">
                                <div class="bg-slate-800/80 p-3 rounded-xl border border-slate-700/80 text-xs font-bold text-white">
                                    <div>فروشگاه آراکس</div>
                                    <div class="text-[10px] text-slate-400 font-normal mt-1">مبلغ: ۷,۹۰۰,۰۰۰ تومان</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl">
                            <div class="text-xs font-black text-emerald-400 mb-3 pb-2 border-b border-slate-800 flex justify-between">
                                <span>خرید و فعال‌سازی موفق</span>
                                <span class="bg-emerald-500/20 px-2 py-0.5 rounded text-[10px]">۵ مورد</span>
                            </div>
                            <div class="space-y-2">
                                <div class="bg-slate-800/80 p-3 rounded-xl border border-slate-700/80 text-xs font-bold text-white">
                                    <div>برند پوشاک نیو دی</div>
                                    <div class="text-[10px] text-emerald-400 font-normal mt-1">✅ فعال تا ۱۴۰۶/۰۵/۰۱</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 relative hero-bg">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <span class="px-4 py-1.5 bg-rose-500/10 border border-rose-500/20 text-rose-400 rounded-full text-xs font-black inline-block mb-3">
                    سرمایه‌گذاری هوشمند برای رشد فروش
                </span>
                <h2 class="text-3xl md:text-5xl font-black text-white mb-4">تعرفه‌ها و پلن‌های اشتراک</h2>
                <p class="text-slate-400 text-xs md:text-sm max-w-xl mx-auto">
                    بدون هیچ هزینه پنهان، پلن مناسب کسب‌وکار خود را انتخاب کنید و آنی تحویل بگیرید.
                </p>

                <!-- Billing Cycle Selector -->
                <div class="inline-flex items-center gap-3 bg-slate-900 p-2 rounded-2xl border border-slate-800 mt-8">
                    <button onclick="setBillingCycle('monthly')" id="btn-cycle-monthly" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all text-slate-400">
                        پرداخت ماهانه
                    </button>
                    <button onclick="setBillingCycle('yearly')" id="btn-cycle-yearly" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all bg-indigo-600 text-white shadow-lg flex items-center gap-2">
                        <span>پرداخت سالانه</span>
                        <span class="px-2 py-0.5 bg-emerald-500 text-white rounded-md text-[9px]">۲۰٪ تخفیف</span>
                    </button>
                </div>
            </div>

            <!-- Pricing Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach ($subscription_plans as $plan): ?>
                    <div class="glass-panel p-8 rounded-3xl border flex flex-col justify-between transition-all duration-300 hover:-translate-y-2 <?php echo !empty($plan['featured']) ? 'border-indigo-500 glow-border bg-indigo-950/20 relative' : 'border-slate-800'; ?>">
                        
                        <?php if (!empty($plan['featured'])): ?>
                            <div class="absolute -top-4 right-1/2 translate-x-1/2 px-4 py-1 bg-gradient-to-r from-indigo-500 to-rose-500 text-white rounded-full text-[10px] font-black shadow-lg">
                                <?php echo htmlspecialchars($plan['badge']); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="text-xs text-indigo-400 font-bold mb-2"><?php echo htmlspecialchars($plan['badge']); ?></div>
                            <h3 class="text-xl font-black text-white mb-4"><?php echo htmlspecialchars($plan['name']); ?></h3>
                            
                            <div class="mb-8">
                                <div class="price-monthly hidden">
                                    <span class="text-3xl font-black text-white"><?php echo number_format($plan['price_monthly']); ?></span>
                                    <span class="text-xs text-slate-400 font-bold">تومان / ماهانه</span>
                                </div>
                                <div class="price-yearly">
                                    <span class="text-3xl font-black text-emerald-400"><?php echo number_format($plan['price_yearly']); ?></span>
                                    <span class="text-xs text-slate-400 font-bold">تومان / سالانه</span>
                                    <div class="text-[10px] text-emerald-400/80 font-bold mt-1">معادل ۲ ماه استفاده کاملاً رایگان</div>
                                </div>
                            </div>

                            <div class="space-y-3 pt-6 border-t border-slate-800 mb-8">
                                <?php foreach ($plan['features'] as $feat): ?>
                                    <div class="flex items-start gap-2.5 text-xs font-medium text-slate-300">
                                        <span class="text-emerald-400 font-black">✓</span>
                                        <span><?php echo htmlspecialchars($feat); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button onclick="openCheckoutModal('<?php echo $plan['id']; ?>', '<?php echo htmlspecialchars($plan['name']); ?>')" class="w-full py-4 rounded-2xl text-xs font-black transition-all shadow-lg <?php echo !empty($plan['featured']) ? 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/30' : 'bg-slate-800 hover:bg-slate-700 text-white border border-slate-700'; ?>">
                            سفارش و فعال‌سازی آنلاین 🚀
                        </button>

                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer & E-Trust Section -->
    <footer id="enamad" class="bg-slate-950 border-t border-slate-800 pt-16 pb-12">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10 mb-12">
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-indigo-600 text-white rounded-2xl flex items-center justify-center font-black text-xl">
                            <?php echo htmlspecialchars($site_settings['logo_icon']); ?>
                        </div>
                        <span class="font-black text-lg text-white"><?php echo htmlspecialchars($site_settings['logo_text']); ?></span>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed font-medium">
                        <?php echo htmlspecialchars($site_settings['site_tagline']); ?>
                    </p>
                </div>

                <div>
                    <h4 class="text-xs font-black text-white uppercase tracking-wider mb-4 border-r-2 border-indigo-500 pr-2">دسترسی سریع</h4>
                    <ul class="space-y-2 text-xs text-slate-400 font-medium">
                        <li><a href="#features" class="hover:text-white">امکانات پنل</a></li>
                        <li><a href="#demo" class="hover:text-white">دموی زنده</a></li>
                        <li><a href="#pricing" class="hover:text-white">قیمت‌ها و تعرفه</a></li>
                        <li><a href="about.php" class="hover:text-white">درباره ما</a></li>
                        <li><a href="contact.php" class="hover:text-white">تماس با ما</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-xs font-black text-white uppercase tracking-wider mb-4 border-r-2 border-indigo-500 pr-2">اطلاعات تماس</h4>
                    <ul class="space-y-2 text-xs text-slate-400 font-medium">
                        <li>تلفن: <?php echo htmlspecialchars($site_settings['phone']); ?></li>
                        <li>همراه: <?php echo htmlspecialchars($site_settings['mobile']); ?></li>
                        <li>ایمیل: <?php echo htmlspecialchars($site_settings['email']); ?></li>
                        <li class="leading-relaxed mt-2 text-[11px] text-slate-500"><?php echo htmlspecialchars($site_settings['address']); ?></li>
                    </ul>
                </div>

                <!-- E-Trust Symbol Badge -->
                <div>
                    <h4 class="text-xs font-black text-white uppercase tracking-wider mb-4 border-r-2 border-emerald-500 pr-2">نماد اعتماد الکترونیکی</h4>
                    <div class="glass-panel p-4 rounded-2xl text-center space-y-3">
                        <?php if(!empty($site_settings['enamad_enabled'])): ?>
                            <div class="w-20 h-20 bg-slate-900 border border-slate-800 rounded-2xl mx-auto flex items-center justify-center text-3xl shadow-inner">
                                🛡️
                            </div>
                            <div class="text-[10px] text-emerald-400 font-black">دارای نماد اعتماد رسمی (اینماد)</div>
                            <div class="text-[9px] text-slate-500 font-bold">کد پیگیری: <?php echo htmlspecialchars($site_settings['enamad_code']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="pt-8 border-t border-slate-900 flex flex-col md:flex-row items-center justify-between text-xs text-slate-500 gap-4">
                <div>تمامی حقوق این وب‌سایت برای <?php echo htmlspecialchars($site_settings['site_title']); ?> محفوظ است.</div>
                <div class="flex gap-4">
                    <a href="about.php" class="hover:text-slate-300">قوانین و مقررات</a>
                    <span>•</span>
                    <a href="contact.php" class="hover:text-slate-300">پشتیبانی</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Checkout Modal -->
    <div id="checkout-modal" class="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-md hidden flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 md:p-8 max-w-lg w-full relative shadow-2xl">
            <button onclick="closeCheckoutModal()" class="absolute top-4 left-4 text-slate-400 hover:text-white font-black text-xl">✕</button>

            <h3 class="text-lg font-black text-white mb-2">تکمیل سفارش و فعال‌سازی آنلاین</h3>
            <p id="checkout-plan-title" class="text-xs text-indigo-400 font-bold mb-6"></p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="plan_id" id="checkout-plan-id" value="plan_pro">
                <input type="hidden" name="billing_cycle" id="checkout-cycle-input" value="yearly">

                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">نام و نام خانوادگی <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_name" required placeholder="مثال: علی رضایی" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">شماره همراه (جهت دریافت کد ورود و فاکتور) <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_mobile" required placeholder="مثال: 09121112233" dir="ltr" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white outline-none focus:border-indigo-500 text-right">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">نام فروشگاه / کسب‌وکار</label>
                    <input type="text" name="customer_business" placeholder="مثال: پوشاک آراکس" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">انتخاب درگاه پرداخت:</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="p-3 bg-slate-800 border border-indigo-500/50 rounded-xl flex items-center gap-2 cursor-pointer text-xs text-white font-bold">
                            <input type="radio" name="payment_gateway" value="zarinpal" checked>
                            <span>درگاه زرین‌پال ⚡</span>
                        </label>
                        <label class="p-3 bg-slate-800 border border-slate-700 rounded-xl flex items-center gap-2 cursor-pointer text-xs text-slate-300 font-bold">
                            <input type="radio" name="payment_gateway" value="test">
                            <span>درگاه تست و فعال‌سازی</span>
                        </label>
                    </div>
                </div>

                <button type="submit" name="submit_checkout_order" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-black text-xs rounded-xl shadow-lg mt-4">
                    پرداخت آنلاین و تحویل آنی 💳
                </button>
            </form>
        </div>
    </div>

    <!-- Order Success Receipt Modal -->
    <?php if ($order_success): ?>
    <div id="receipt-modal" class="fixed inset-0 z-[110] bg-slate-950/90 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-emerald-500/40 rounded-3xl p-8 max-w-md w-full text-center space-y-6 shadow-2xl relative">
            <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 rounded-full flex items-center justify-center text-3xl mx-auto border border-emerald-500/30">
                ✅
            </div>

            <div>
                <h3 class="text-xl font-black text-white">پرداخت و فعال‌سازی موفقیت‌آمیز</h3>
                <p class="text-xs text-slate-400 leading-relaxed mt-1">
                    اشتراک شما فعال گردید. اکنون می‌توانید با شماره همراه خود مستقیماً وارد پورتال شوید.
                </p>
            </div>

            <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-2 text-right text-xs">
                <div class="flex justify-between"><span class="text-slate-400">شماره سفارش:</span><span class="font-black text-indigo-400 font-mono" dir="ltr"><?php echo htmlspecialchars($order_success['order_id']); ?></span></div>
                <div class="flex justify-between"><span class="text-slate-400">کد پیگیری بانکی (RefID):</span><span class="font-black text-emerald-400 font-mono" dir="ltr"><?php echo htmlspecialchars($order_success['ref_code'] ?? '-'); ?></span></div>
                <?php if (!empty($order_success['card_pan'])): ?>
                    <div class="flex justify-between"><span class="text-slate-400">شماره کارت پرداخت:</span><span class="font-mono text-slate-300" dir="ltr"><?php echo htmlspecialchars($order_success['card_pan']); ?></span></div>
                <?php endif; ?>
                <div class="flex justify-between"><span class="text-slate-400">نام پلن:</span><span class="font-black text-white"><?php echo htmlspecialchars($order_success['plan_name']); ?></span></div>
                <div class="flex justify-between"><span class="text-slate-400">مبلغ پرداخت شده:</span><span class="font-black text-emerald-400"><?php echo number_format($order_success['amount']); ?> تومان</span></div>
                <div class="flex justify-between"><span class="text-slate-400">شماره همراه خریدار:</span><span class="font-black text-slate-200 font-mono" dir="ltr"><?php echo htmlspecialchars($order_success['customer_mobile']); ?></span></div>
            </div>

            <div class="flex gap-3">
                <a href="index.php?action=login" class="flex-1 py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-black text-xs rounded-xl text-center shadow-lg transition-all">ورود به پورتال 👤</a>
                <button onclick="document.getElementById('receipt-modal').remove()" class="px-5 py-3.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs rounded-xl transition-all">بستن</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Order Error Modal -->
    <?php if ($order_error): ?>
    <div id="error-modal" class="fixed inset-0 z-[110] bg-slate-950/90 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-rose-500/40 rounded-3xl p-8 max-w-md w-full text-center space-y-6 shadow-2xl relative">
            <div class="w-16 h-16 bg-rose-500/20 text-rose-400 rounded-full flex items-center justify-center text-3xl mx-auto border border-rose-500/30">
                ❌
            </div>

            <div>
                <h3 class="text-xl font-black text-white">خطا در پرداخت یا لغو سفارش</h3>
                <p class="text-xs text-rose-300 leading-relaxed mt-2 p-3 bg-rose-500/10 rounded-xl border border-rose-500/20">
                    <?php echo htmlspecialchars($order_error); ?>
                </p>
            </div>

            <div class="flex gap-3">
                <a href="index.php#pricing" onclick="document.getElementById('error-modal').remove()" class="flex-1 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-black text-xs rounded-xl text-center shadow-lg transition-all">تلاش مجدد و پرداخت 💳</a>
                <button onclick="document.getElementById('error-modal').remove()" class="px-5 py-3.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs rounded-xl transition-all">انصراف</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Login Modal -->
    <div id="login-modal" class="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-md <?php echo $show_login_modal ? '' : 'hidden'; ?> flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 max-w-md w-full relative shadow-2xl">
            <button onclick="closeLoginModal()" class="absolute top-4 left-4 text-slate-400 hover:text-white font-black text-xl">✕</button>

            <div class="text-center mb-6">
                <div class="w-12 h-12 bg-indigo-600 text-white rounded-2xl flex items-center justify-center font-black text-2xl mx-auto mb-3 shadow-lg shadow-indigo-500/30">
                    <?php echo htmlspecialchars($site_settings['logo_icon']); ?>
                </div>
                <h3 class="text-xl font-black text-white mb-1">ورود به پنل کاربری</h3>
                <p class="text-xs text-slate-400 font-medium">Maarksho Flow Digital Core v3.0</p>
            </div>

            <?php if($login_error): ?>
                <div class="p-4 mb-6 bg-rose-500/10 text-rose-400 rounded-2xl text-xs font-bold border border-rose-500/20 flex items-center gap-2">
                    <span>⚠️</span>
                    <span><?php echo $login_error; ?></span>
                </div>
            <?php endif; ?>

            <?php if($sms_notification): ?>
                <div class="p-4 mb-6 bg-emerald-500/10 text-emerald-400 rounded-2xl text-xs font-bold border border-emerald-500/20 flex items-center gap-2">
                    <span>⚡</span>
                    <span><?php echo $sms_notification; ?></span>
                </div>
            <?php endif; ?>

            <div class="flex bg-slate-800 p-1.5 rounded-2xl mb-6 gap-2">
                <button onclick="switchLoginTab('otp')" id="login-tab-otp" type="button" class="flex-1 py-2 text-xs font-black rounded-xl bg-indigo-600 text-white transition-all">⚡ ورود پیامکی (OTP)</button>
                <button onclick="switchLoginTab('admin')" id="login-tab-admin" type="button" class="flex-1 py-2 text-xs font-black rounded-xl text-slate-400 hover:text-white transition-all">🔑 نام کاربری و رمز</button>
            </div>

            <!-- OTP Login Form -->
            <div id="login-form-otp">
                <?php if ($otp_step === 'enter_mobile'): ?>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1">شماره همراه</label>
                            <input type="text" name="mobile_num" placeholder="مثال: 09121112233" required class="w-full bg-slate-800 border border-slate-700 px-4 py-3 rounded-xl text-xs text-white text-center font-bold tracking-widest outline-none focus:border-indigo-500" dir="ltr">
                        </div>
                        <button name="send_otp" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-lg">دریافت کد تایید یکبار مصرف ⚡</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="mobile_num" value="<?php echo htmlspecialchars($temp_mobile); ?>">
                        <div class="text-xs text-slate-400 text-right">
                            شماره مقصد: <span class="font-bold text-indigo-400" dir="ltr"><?php echo htmlspecialchars($temp_mobile); ?></span>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1">کد تایید ۵ رقمی</label>
                            <input type="text" name="verification_num" placeholder="•••••" maxlength="5" required autofocus class="w-full bg-slate-800 border border-indigo-500 px-4 py-3 rounded-xl text-lg text-white text-center font-black tracking-[0.5em] outline-none">
                        </div>
                        <button name="verify_code" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-lg">تایید کد و ورود 👤</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Admin Password Login Form -->
            <form id="login-form-admin" method="POST" class="hidden space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">شناسه ورود</label>
                    <input type="text" name="user" placeholder="نام کاربری..." required class="w-full bg-slate-800 border border-slate-700 px-4 py-3 rounded-xl text-xs text-white font-bold outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">رمز عبور</label>
                    <input type="password" name="pass" placeholder="••••••••" required class="w-full bg-slate-800 border border-slate-700 px-4 py-3 rounded-xl text-xs text-white font-bold outline-none focus:border-indigo-500">
                </div>
                <button name="login" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-lg">احراز هویت و ورود 🔑</button>
            </form>
        </div>
    </div>

    <!-- JavaScript Handlers -->
    <script>
    let activeCycle = 'yearly';

    function setBillingCycle(cycle) {
        activeCycle = cycle;
        const btnM = document.getElementById('btn-cycle-monthly');
        const btnY = document.getElementById('btn-cycle-yearly');
        const elM = document.querySelectorAll('.price-monthly');
        const elY = document.querySelectorAll('.price-yearly');

        if (cycle === 'yearly') {
            btnY.className = "px-5 py-2.5 rounded-xl text-xs font-black transition-all bg-indigo-600 text-white shadow-lg flex items-center gap-2";
            btnM.className = "px-5 py-2.5 rounded-xl text-xs font-black transition-all text-slate-400";
            elY.forEach(x => x.classList.remove('hidden'));
            elM.forEach(x => x.classList.add('hidden'));
        } else {
            btnM.className = "px-5 py-2.5 rounded-xl text-xs font-black transition-all bg-indigo-600 text-white shadow-lg";
            btnY.className = "px-5 py-2.5 rounded-xl text-xs font-black transition-all text-slate-400 flex items-center gap-2";
            elM.forEach(x => x.classList.remove('hidden'));
            elY.forEach(x => x.classList.add('hidden'));
        }
    }

    function switchDemo(type) {
        ['prompt', 'catalog', 'chat', 'crm'].forEach(t => {
            const btn = document.getElementById('btn-demo-' + t);
            const box = document.getElementById('demo-box-' + t);
            if (t === type) {
                btn.className = "px-5 py-3 rounded-xl text-xs font-black transition-all bg-indigo-600 text-white shadow-lg";
                box.classList.remove('hidden');
            } else {
                btn.className = "px-5 py-3 rounded-xl text-xs font-black transition-all text-slate-400 hover:text-white";
                box.classList.add('hidden');
            }
        });
    }

    function runAiDemo(prompt) {
        document.getElementById('demo-ai-input').value = prompt;
        generateAiTextDemo();
    }

    function generateAiTextDemo() {
        const val = document.getElementById('demo-ai-input').value;
        const out = document.getElementById('demo-ai-output');
        out.innerHTML = "⏳ در حال تولید پاسخ با هوش مصنوعی مارکشو فلو...";
        
        setTimeout(() => {
            out.innerHTML = `✨ <b>نتیجه هوش مصنوعی:</b><br>"${val} - این محصول با طراحی منحصر‌به‌فرد، کیفیت ساخت بی‌نظیر و ارگونومی استاندارد، انتخابی ایده‌آل برای استفاده روزمره و حرفه‌ای می‌باشد. هم‌اکنون با تخفیف ویژه و ارسال سریع قابل سفارش است!"`;
        }, 800);
    }

    function changeDemoColor(name, hex) {
        alert("رنگ " + name + " انتخاب شد.");
    }

    function sendDemoChatMessage() {
        const input = document.getElementById('demo-chat-input');
        const box = document.getElementById('demo-chat-messages');
        if (!input.value.trim()) return;

        const userMsg = document.createElement('div');
        userMsg.className = "bg-indigo-600/30 p-2.5 rounded-xl text-white max-w-[80%] mr-auto text-left leading-relaxed";
        userMsg.innerText = input.value;
        box.appendChild(userMsg);

        const replyMsg = document.createElement('div');
        replyMsg.className = "bg-slate-800 p-2.5 rounded-xl text-slate-200 max-w-[80%] leading-relaxed";
        replyMsg.innerText = "در حال بررسی... دستیار هوشمند: سفارشات بالای ۵۰۰ هزار تومان شامل ارسال رایگان می‌باشند!";
        
        setTimeout(() => {
            box.appendChild(replyMsg);
            box.scrollTop = box.scrollHeight;
        }, 600);

        input.value = '';
    }

    function openCheckoutModal(planId, planTitle) {
        document.getElementById('checkout-plan-id').value = planId;
        document.getElementById('checkout-plan-title').innerText = 'سفارش ' + planTitle + ' (' + (activeCycle === 'yearly' ? 'سالانه' : 'ماهانه') + ')';
        document.getElementById('checkout-cycle-input').value = activeCycle;
        document.getElementById('checkout-modal').classList.remove('hidden');
    }

    function closeCheckoutModal() {
        document.getElementById('checkout-modal').classList.add('hidden');
    }

    function openLoginModal() {
        document.getElementById('login-modal').classList.remove('hidden');
    }

    function closeLoginModal() {
        document.getElementById('login-modal').classList.add('hidden');
    }

    function switchLoginTab(mode) {
        const btnOtp = document.getElementById('login-tab-otp');
        const btnAdmin = document.getElementById('login-tab-admin');
        const formOtp = document.getElementById('login-form-otp');
        const formAdmin = document.getElementById('login-form-admin');

        if (mode === 'otp') {
            btnOtp.className = "flex-1 py-2 text-xs font-black rounded-xl bg-indigo-600 text-white transition-all";
            btnAdmin.className = "flex-1 py-2 text-xs font-black rounded-xl text-slate-400 hover:text-white transition-all";
            formOtp.classList.remove('hidden');
            formAdmin.classList.add('hidden');
        } else {
            btnAdmin.className = "flex-1 py-2 text-xs font-black rounded-xl bg-indigo-600 text-white transition-all";
            btnOtp.className = "flex-1 py-2 text-xs font-black rounded-xl text-slate-400 hover:text-white transition-all";
            formAdmin.classList.remove('hidden');
            formOtp.classList.add('hidden');
        }
    }
    </script>
</body>
</html>
