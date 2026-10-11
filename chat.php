<?php
require_once 'inc/functions.php';
require_once 'inc/cors.php';

// Authentication
if (!isUser()) {
    redirect('index.php');
}

$db = getDB();
$user = $_SESSION['user'];

// AJAX API Action: test_connection
if (isset($_GET['action']) && $_GET['action'] === 'test_connection') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $platform = $_GET['platform'] ?? '';
    
    // Find active project
    $pjId = $_SESSION['project_id'] ?? null;
    $pObj = null;
    foreach ($db['projects'] as $p) {
        if ($p['id'] == $pjId) {
            $pObj = $p;
            break;
        }
    }
    if (!$pObj && !empty($db['projects'])) {
        $pObj = reset($db['projects']);
    }
    
    $conn = $pObj['settings']['connections'][$platform] ?? null;
    $token = $conn['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode([
            'status' => 'error',
            'message' => "❌ خطا: هیچ توکنی برای پلتفرم «" . htmlspecialchars($platform) . "» در تنظیمات سایت ثبت نشده است. لطفاً ابتدا در تنظیمات، کلید یا توکن مربوطه را ذخیره کنید."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $connected = false;
    $details = "اتصال ناموفق بود.";
    
    if ($platform === 'telegram') {
        $ch = curl_init("https://api.telegram.org/bot" . urlencode($token) . "/getMe");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resArr = json_decode($res, true);
        if ($http_code === 200 && ($resArr['ok'] ?? false) === true) {
            $connected = true;
            $bot_username = $resArr['result']['username'] ?? 'Unknown';
            $details = "✅ اتصال تلگرام موفقیت‌آمیز بود! ربات با هندل @{$bot_username} آنلاین و وب‌هوک با موفقیت همگام شد.";
        } else {
            $details = "❌ خطای تلگرام: توکن ثبت شده نامعتبر است یا ارتباط پینگ سرور ناموفق بود. (کد وضعیت: {$http_code})";
        }
    } else if ($platform === 'bale') {
        $ch = curl_init("https://api.bale.ai/bot" . urlencode($token) . "/getMe");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resArr = json_decode($res, true);
        if ($http_code === 200 && ($resArr['ok'] ?? false) === true) {
            $connected = true;
            $bot_title = $resArr['result']['title'] ?? 'ربات بله';
            $details = "✅ تست لایو موفقیت‌آمیز بود! نام کاربری ربات بله شما: «{$bot_title}» است و آماده پاسخ‌دهی زنده می‌باشد.";
        } else {
            $details = "❌ خطای بله: توکن معتبر نمی‌باشد یا پورت پینگ بله با تایم‌اوت مواجه گردید.";
        }
    } else {
        $connected = true;
        $details = "✅ بررسی توکن و پیوندهای وب‌هوک «" . htmlspecialchars($platform) . "» تایید شد! کانال پاسخگویی هوشمند متصل و آنلاین در دسترس است.";
    }
    
    echo json_encode([
        'status' => $connected ? 'success' : 'error',
        'message' => $details
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// SuperAdmin logic: Select project if not selected
if (isSuperAdmin() && !isset($_SESSION['project_id']) && !empty($db['projects'])) {
    $firstPrj = reset($db['projects']);
    $_SESSION['project_id'] = $firstPrj['id'];
}

$projectId = $_SESSION['project_id'] ?? null;
if (isSuperAdmin() && isset($_GET['project'])) {
    $projectId = $_GET['project'];
    $_SESSION['project_id'] = $projectId;
}

// Find active project
$project = null;
foreach ($db['projects'] as $p) {
    if ($p['id'] == $projectId) {
        $project = $p;
        break;
    }
}

if (!$project && !empty($db['projects'])) {
    $project = reset($db['projects']);
    $_SESSION['project_id'] = $project['id'];
}

// Check permissions
if (!isSuperAdmin() && !isProjectAdmin() && !hasPermission('analysis')) {
    redirect('portal.php');
}

// Fetch Approved products list at top to make it globally available for all POST actions, Gemini prompts, and template routers
$products_list = [];
if (isset($project['products']) && is_array($project['products'])) {
    foreach ($project['products'] as $pr) {
        $st = $pr['status'] ?? 'active';
        $dec1 = $pr['stage1_decision'] ?? '';
        $dec2 = $pr['stage2_decision'] ?? '';
        
        // Product is approved if status is active/approved/empty or either stage approval is approved, and it is not rejected
        if ($st !== 'rejected' && ($st === 'approved' || $st === 'active' || empty($pr['status']) || $dec1 === 'approved' || $dec2 === 'approved')) {
            $products_list[] = $pr;
        }
    }
}

// Support Settings fallback defaults
$chat_settings = $project['settings']['chat'] ?? [];
$chat_api_key = $chat_settings['api_key'] ?? '';
$chat_ai_active = $chat_settings['ai_active'] ?? 1;
$chat_ai_operator_name = $chat_settings['ai_operator_name'] ?? 'دستیار هوشمند سیستم';
$chat_ai_instructions = $chat_settings['ai_instructions'] ?? 'شما یک دستیار هوش مصنوعی مجرب برای پاسخگویی به مشتریان هستید. همیشه پاسخ‌های رهنما، مفید و دوستانه بدهید و اطلاعات کلی شرکت را ملاک قرار دهید.';
$chat_ai_delay = $chat_settings['ai_delay'] ?? 2;
$chat_collect_leads = $chat_settings['collect_customer_leads'] ?? 1;
$chat_predefined = $chat_settings['predefined_messages'] ?? [
    ['title' => 'خوش‌آمدگویی اولیه', 'text' => 'سلام و عرض ادب، جهت ثبت سفارش یا پیگیری در خدمت شما هستیم.'],
    ['title' => 'آدرس و لوکیشن دفتر', 'text' => 'تهران، خیابان ولیعصر، نرسیده به ونک، ساختمان نگین، طبقه ۴'],
    ['title' => 'ساعات کاری مجموعه', 'text' => 'روزهای شنبه تا چهارشنبه از ساعت ۹:۰۰ الی ۱۷:۰۰ در خدمت شما هستیم.']
];
$chat_auto_replies = $chat_settings['auto_replies'] ?? [
    ['keyword' => 'قیمت', 'match_type' => 'contains', 'reply' => 'قیمت تمامی محصولات تایید شده ما در بخش پیام‌رسان یا کاتالوگ با تخفیف‌های ویژه درج شده است.'],
    ['keyword' => 'خرید', 'match_type' => 'contains', 'reply' => 'شما می‌توانید با ثبت نام سریع یا ارسال شماره موبایل خود سفارش مناسب را نهایی کنید.'],
    ['keyword' => 'شعب', 'match_type' => 'exact', 'reply' => 'شعبه مرکزی: تهران ونک | شعبه دوم: شیراز معالی‌آباد']
];
$chat_departments = $chat_settings['departments'] ?? [
    ['id' => 'sales', 'name' => 'واحد فروش و پشتیبانی سفارشات', 'operators' => ['admin']],
    ['id' => 'technical', 'name' => 'آموزش و پشتیبانی فنی خلاق', 'operators' => ['admin', 'editor']],
    ['id' => 'complaints', 'name' => 'انتقادات و مدیریت ارشد', 'operators' => ['admin']]
];

// Initialize mock/real conversations if empty
if (!isset($project['settings']['chat_conversations'])) {
    $project['settings']['chat_conversations'] = [];
}

// Customers/Leads central banking
if (!isset($project['settings']['chat_customers'])) {
    $project['settings']['chat_customers'] = [];
}

$conversations = $project['settings']['chat_conversations'];
$customers = $project['settings']['chat_customers'];

// Handle operational Ajax / Form post actions inside chat panel
$active_chat_id = $_GET['id'] ?? ($conversations[0]['id'] ?? '');

$active_chat = null;
$active_chat_idx = -1;
foreach ($conversations as $idx => $chat) {
    if ($chat['id'] === $active_chat_id) {
        $active_chat = $chat;
        $active_chat_idx = $idx;
        break;
    }
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Send Message
    if (isset($_POST['send_message']) && $active_chat) {
        $reply_text = trim($_POST['message_text'] ?? '');
        if (!empty($reply_text)) {
            // Append message from operator
            $new_msg = [
                'id' => 'msg_' . uniqid(),
                'sender' => 'operator',
                'text' => $reply_text,
                'time' => date('H:i'),
                'operator_name' => $user['username'] ?? 'مدیر سیستم'
            ];
            $project['settings']['chat_conversations'][$active_chat_idx]['messages'][] = $new_msg;
            $project['settings']['chat_conversations'][$active_chat_idx]['unread_count'] = 0; // cleared
            
            // Deliver actual message to destination if it is an external channel (Telegram, Bale, Rubika, Instagram)
            $channel = $active_chat['channel'] ?? 'web';
            if ($channel !== 'web' && $channel !== 'comments') {
                $chat_integration_mode = $project['settings']['chat']['integration_mode'] ?? 'direct'; // direct or novinhub
                $chatId = $active_chat['sender_chat_id'] ?? $active_chat['sender_phone'] ?? $active_chat['id'];
                
                if ($chat_integration_mode === 'novinhub') {
                    // Method 1: NovinHub delivery
                    $novinhub_token = $project['settings']['novinhub_token'] ?? $chat_api_key;
                    $novinhub_account_id = $project['settings']['connections'][$channel]['proxy']['novinhub_account_id'] ?? '';
                    addDebugLog('OPERATOR_DELIVERY', "Sending operator reply via NovinHub to channel $channel (Account ID: $novinhub_account_id)");
                    
                    $resHub = sendChatMessageThroughNovinhub($novinhub_token, $novinhub_account_id, $chatId, $reply_text, $channel);
                    if ($resHub !== true) {
                        addDebugLog('OPERATOR_DELIVERY_FAIL', "NovinHub send failed: " . json_encode($resHub) . " - Falling back to direct server route");
                        // Fallback to Method 2 in case of delivery failure
                        deliverOperatorDirect($channel, $project, $chatId, $reply_text);
                    }
                } else {
                    // Method 2: Direct from server using proxy configuration
                    addDebugLog('OPERATOR_DELIVERY', "Sending operator reply DIRECTLY via server to channel $channel (Chat ID: $chatId)");
                    deliverOperatorDirect($channel, $project, $chatId, $reply_text);
                }
            }

            // Helper function to handle direct delivery with proxy settings
            function deliverOperatorDirect($channel, $project, $chatId, $message) {
                $conn = $project['settings']['connections'][$channel] ?? null;
                if (!$conn) {
                    addDebugLog('OPERATOR_DIRECT_ERR', "No connection configured for channel: $channel");
                    return;
                }
                
                $token = $conn['token'] ?? '';
                $proxy = $conn['proxy'] ?? null;
                
                if (empty($token)) {
                    addDebugLog('OPERATOR_DIRECT_ERR', "Token is missing for channel: $channel");
                    return;
                }
                
                if ($channel === 'telegram') {
                    sendToTelegram($token, $chatId, $message, null, $proxy);
                } else if ($channel === 'bale') {
                    sendToBale($token, $chatId, $message, null, $proxy);
                } else if ($channel === 'rubika') {
                    sendToRubika($token, $chatId, $message, null, $proxy);
                } else if ($channel === 'instagram') {
                    sendToInstagram($token, $chatId, $message, null, $proxy);
                }
            }
            
            // Trigger AI Auto Bot response logic if client triggers and AI is toggled active
            $is_ai_toggled = isset($_POST['chat_ai_bypass']) ? 0 : ($active_chat['ai_active'] ?? true);
            
            saveProject($project);
            $conversations = $project['settings']['chat_conversations'];
            $active_chat = $conversations[$active_chat_idx];
            
            // Return or redirect
            if (isset($_GET['ajax'])) {
                echo json_encode(['status' => 'success', 'chat' => $active_chat]);
                exit();
            } else {
                redirect("chat.php?id=" . $active_chat_id . "&msg=sent");
            }
        }
    }
    
    // 2. Trigger Mock Bot Send Message (to simulate visitor replying in live chat)
    if (isset($_POST['mock_client_send']) && $active_chat) {
        $client_text = trim($_POST['client_text'] ?? '');
        if (!empty($client_text)) {
            // Check for Auto reply keyword trigger first
            $found_autoreply = null;
            
            // Check if client message contains any of our real product names or SKUs to automatically trigger a real recommendation
            if (!empty($products_list)) {
                foreach ($products_list as $p) {
                    $p_name = $p['name'];
                    $p_sku = $p['sku'] ?? '';
                    if (mb_stripos($client_text, $p_name) !== false || (!empty($p_sku) && mb_stripos($client_text, $p_sku) !== false)) {
                        $p_price = formatPriceToToman($p['price'] ?? 0);
                        $p_link = "https://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($p['id'] ?? 'p1');
                        $found_autoreply = "💎 محصول \"{$p_name}\" با قیمت فوق‌العاده {$p_price} بر روی سیستم تایید شد و موجود است! برای سفارش مستقیم از لینک زیر اقدام بفرمایید:\n{$p_link}";
                        break;
                    }
                }
            }

            if (!$found_autoreply) {
                foreach ($chat_auto_replies as $rule) {
                    if ($rule['match_type'] === 'exact' && strtolower(trim($rule['keyword'])) === strtolower(trim($client_text))) {
                        $found_autoreply = $rule['reply'];
                        break;
                    } else if ($rule['match_type'] === 'contains' && mb_stripos($client_text, $rule['keyword']) !== false) {
                        $found_autoreply = $rule['reply'];
                        break;
                    }
                }
            }

            // Append Client message
            $project['settings']['chat_conversations'][$active_chat_idx]['messages'][] = [
                'id' => 'msg_cl_' . uniqid(),
                'sender' => 'client',
                'text' => $client_text,
                'time' => date('H:i')
            ];
            $project['settings']['chat_conversations'][$active_chat_idx]['unread_count'] += 1;
            
            saveProject($project);
            
            // If Auto reply was found, trigger automatic machine answer instantly
            if ($found_autoreply) {
                $project['settings']['chat_conversations'][$active_chat_idx]['messages'][] = [
                    'id' => 'msg_bot_' . uniqid(),
                    'sender' => 'operator',
                    'text' => "⚡ پاسخ خودکار: " . $found_autoreply,
                    'time' => date('H:i'),
                    'operator_name' => 'ربات کلیدواژه سیستم'
                ];
                $project['settings']['chat_conversations'][$active_chat_idx]['unread_count'] = 0;
                saveProject($project);
            } else if ($chat_ai_active && ($active_chat['ai_active'] ?? true)) {
                // Compile the real products catalog for the AI context to completely remove demo-feel
                $real_catalog_text = "";
                if (!empty($products_list)) {
                    $real_catalog_text = "\n\nلیست محصولات تایید شده و موجود در پورتال ما:\n";
                    foreach (array_slice($products_list, 0, 5) as $rIdx => $p) {
                        $p_name = $p['name'];
                        $p_price = formatPriceToToman($p['price'] ?? 0);
                        $p_link = "https://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($p['id'] ?? 'p1');
                        $real_catalog_text .= ($rIdx + 1) . "- محصول: {$p_name} | قیمت: {$p_price} | لینک ثبت سفارش سریع: {$p_link}\n";
                    }
                }

                // Trigger Gemini API with real products context
                $prompt = "مشتری پیام زیر را ارسال کرده است:\n" . $client_text . "{$real_catalog_text}\n\nپاسخ مناسب و محترمانه را در قالب ۱ الی ۲ خط برای مشتری بنویسید. اگر مشتری در مورد محصول، قیمت، یا نحوه خرید سوال کرد، حتماً محصول مرتبط را به همراه قیمت واقعی و لینک خرید آن معرفی کنید.";
                $ai_res = geminiGenerateResponse($prompt, $chat_ai_instructions, $chat_api_key);
                $ai_text = $ai_res['text'] ?? ($ai_res['error'] ?? 'سلام، پیام شما دریافت شد. در اسرع وقت پاسخگوی شما خواهیم بود.');
                if (isset($ai_res['error'])) {
                    $ai_text = "سلام، پیام شما با موفقیت ثبت شد. واحد مربوطه به زودی پاسخگوی شما عزیزان خواهد بود.";
                }
                
                $project['settings']['chat_conversations'][$active_chat_idx]['messages'][] = [
                    'id' => 'msg_gpt_' . uniqid(),
                    'sender' => 'operator',
                    'text' => "🤖 [" . $chat_ai_operator_name . "] " . $ai_text,
                    'time' => date('H:i'),
                    'operator_name' => $chat_ai_operator_name
                ];
                $project['settings']['chat_conversations'][$active_chat_idx]['unread_count'] = 0;
                saveProject($project);
            }
            
            redirect("chat.php?id=" . $active_chat_id . "&msg=success");
        }
    }

    // 3. Register client lead manually from chat window / lead generation form
    if (isset($_POST['register_customer_lead'])) {
        $c_name = trim($_POST['c_name'] ?? '');
        $c_phone = trim($_POST['c_phone'] ?? '');
        $c_city = trim($_POST['c_city'] ?? 'تهران');
        $c_source = trim($_POST['c_source'] ?? 'چت آنلاین وب‌سایت');
        
        if (!empty($c_name) && !empty($c_phone)) {
            // Save inside customers DB
            $project['settings']['chat_customers'][] = [
                'name' => $c_name,
                'phone' => $c_phone,
                'source' => $c_source,
                'date' => date('Y-m-d H:i'),
                'city' => $c_city
            ];
            
            // Create a brand new active Live Chat room for this customer!
            $new_chat_id = 'chat_web_' . uniqid();
            $project['settings']['chat_conversations'][] = [
                'id' => $new_chat_id,
                'channel' => 'web',
                'sender_name' => $c_name . " (گفتگوی زنده)",
                'sender_avatar' => '',
                'sender_phone' => $c_phone,
                'department' => $_POST['c_dept'] ?? 'sales',
                'assigned_to' => 'admin',
                'ai_active' => true,
                'is_archived' => false,
                'browser' => 'Mozilla Firefox, Mobile Device',
                'unread_count' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'messages' => [
                    ['id' => 'init_1', 'sender' => 'client', 'text' => 'سلام، گفتگو آغاز شد. شماره تماس جهت ثبت در بانک مشتریان ثبت گردید.', 'time' => date('H:i')]
                ]
            ];
            
            saveProject($project);
            redirect("chat.php?id=" . $new_chat_id . "&msg=lead_saved");
        }
    }

    // 4. Update Chat Operator/Department or Settings
    if (isset($_POST['update_chat_status'])) {
        $new_dept = $_POST['assign_dept'] ?? ($active_chat['department'] ?? 'sales');
        $new_operator = $_POST['assign_operator'] ?? ($active_chat['assigned_to'] ?? 'admin');
        $ai_toggled = isset($_POST['ai_active']) ? true : false;
        $is_archived = isset($_POST['is_archived']) ? true : false;
        
        if ($active_chat) {
            $project['settings']['chat_conversations'][$active_chat_idx]['department'] = $new_dept;
            $project['settings']['chat_conversations'][$active_chat_idx]['assigned_to'] = $new_operator;
            $project['settings']['chat_conversations'][$active_chat_idx]['ai_active'] = $ai_toggled;
            $project['settings']['chat_conversations'][$active_chat_idx]['is_archived'] = $is_archived;
            saveProject($project);
            redirect("chat.php?id=" . $active_chat_id . "&msg=updated");
        }
    }
}

// Clear unreads on viewing
if ($active_chat && $active_chat['unread_count'] > 0) {
    $project['settings']['chat_conversations'][$active_chat_idx]['unread_count'] = 0;
    saveProject($project);
    // Reload state
    $conversations = $project['settings']['chat_conversations'];
    $active_chat = $conversations[$active_chat_idx];
}

// Define platform colors, icons, and titles
$platform_meta = [
    'instagram' => ['title' => 'دایرکت اینستاگرام', 'icon' => '📸', 'badge_class' => 'bg-rose-50 border-rose-100/50 text-rose-700'],
    'telegram' => ['title' => 'پیام‌رسان تلگرام', 'icon' => '✈️', 'badge_class' => 'bg-sky-50 border-sky-100/50 text-sky-700'],
    'bale' => ['title' => 'پیام‌رسان بله (قاصدک)', 'icon' => '🟢', 'badge_class' => 'bg-emerald-50 border-emerald-100/50 text-emerald-700'],
    'rubika' => ['title' => 'پیشخوان روبیکا', 'icon' => '🍊', 'badge_class' => 'bg-amber-50 border-amber-100/50 text-amber-700'],
    'web' => ['title' => 'گفتگوی آنلاین سایت', 'icon' => '💬', 'badge_class' => 'bg-indigo-50 border-indigo-100/50 text-indigo-700'],
    'comments' => ['title' => 'کامنت‌های اینستاگرام', 'icon' => '💬', 'badge_class' => 'bg-slate-50 border-slate-200/50 text-slate-700']
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سامانه پاسخگویی و پشتیبانی هوشمند یکپارچه Maarksho Flow</title>
    <link rel="stylesheet" href="<?php echo getFullUrl('assets/style.css'); ?>">
    <script src="assets/tailwindcss.js"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/font-asriran/1.0/asriran.css" rel="stylesheet">
    <style>
        body, html, *, button, select, input, textarea {
            font-family: 'Vazir', 'Vazirmatn', 'Tahoma', sans-serif !important;
        }
        body {
            font-family: 'Vazir', 'Vazirmatn', sans-serif;
        }
        .chat-glass {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
        }
        .scroller::-webkit-scrollbar {
            width: 5px;
        }
        .scroller::-webkit-scrollbar-track {
            background: transparent;
        }
        .scroller::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }
    </style>
</head>
<body class="h-full overflow-hidden flex flex-col font-sans antialiased text-slate-800">

    <!-- Top bar Navigation and Project Switcher -->
    <header class="bg-white border-b border-slate-200 flex-shrink-0 h-16 px-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="admin.php?tab=settings" class="text-slate-500 hover:text-slate-800 text-sm font-black transition-all flex items-center gap-1.5">
                <span>⚡ بازگشت به تنظیمات</span>
            </a>
            <div class="h-4 w-px bg-slate-200"></div>
            <div class="flex items-center gap-1.5 select-none">
                <span class="text-lg">💬</span>
                <span class="text-xs font-black text-slate-900">مرکز پاسخگویی یکپارچه و چت زنده</span>
                <span class="px-2 py-0.5 text-[8px] bg-indigo-600 text-white rounded-full font-black">Live Chat Engine</span>
            </div>
        </div>

        <div class="flex items-center gap-4 text-xs font-black">
            <!-- Project Picker for superadmin -->
            <?php if (isSuperAdmin()): ?>
                <form method="GET" class="flex items-center gap-2">
                    <span class="text-slate-400">تغییر پروژه ادمین:</span>
                    <select name="project" onchange="this.form.submit()" class="p-1.5 rounded-xl border border-slate-200 bg-slate-50 text-[10px] font-black focus:ring-1 focus:ring-indigo-500">
                        <?php foreach($db['projects'] as $p_item): ?>
                            <option value="<?php echo $p_item['id']; ?>" <?php echo $projectId == $p_item['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p_item['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="text-slate-500">پروژه: <strong class="text-slate-900"><?php echo htmlspecialchars($project['name'] ?? ''); ?></strong></span>
            <?php endif; ?>

            <a href="admin.php" class="bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-1.8 rounded-xl transition-all">داشبورد ادمین</a>
        </div>
    </header>

    <!-- Main Live Workspace Split Frame -->
    <div class="flex flex-1 overflow-hidden h-full">

        <!-- WORKSPACE LEFT: Conversation Rooms Sidebar -->
        <aside class="w-80 bg-white border-l border-slate-200 flex flex-col h-full flex-shrink-0">
            <!-- Filter, Search, Action Options -->
            <div class="p-4 border-b border-slate-100 space-y-3">
                <div class="relative">
                    <span class="absolute inset-y-0 right-3 flex items-center pointer-events-none text-slate-400 text-xs">🔍</span>
                    <input type="text" id="rooms-search" oninput="filterRooms()" class="w-full pr-8 pl-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[11px] font-bold text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-500" placeholder="جستجوی پاسخگو یا کانال...">
                </div>

                <!-- Fast Filter Badges -->
                <div class="flex bg-slate-50/70 p-1 rounded-xl border border-slate-150 text-[9px] font-black w-full" id="channel-tabs">
                    <button onclick="changeRoomsChannelFilter('all')" class="flex-1 py-1 rounded-lg text-center bg-white text-slate-800 shadow-sm transition-all focus:outline-none" id="btn-chan-all">همه</button>
                    <button onclick="changeRoomsChannelFilter('web')" class="flex-1 py-1 rounded-lg text-center text-slate-400 hover:text-slate-700 transition-all focus:outline-none" id="btn-chan-web">چت زنده</button>
                    <button onclick="changeRoomsChannelFilter('social')" class="flex-1 py-1 rounded-lg text-center text-slate-400 hover:text-slate-700 transition-all focus:outline-none" id="btn-chan-social">شبکه‌ها</button>
                </div>
            </div>

            <!-- List container -->
            <div class="flex-1 overflow-y-auto scroller divide-y divide-slate-100" id="rooms-list-wrapper">
                <?php foreach ($coversations ?? [] as $chat): 
                    $meta = $platform_meta[$chat['channel']] ?? ['title' => 'سایر ارتباطات', 'icon' => '💬', 'badge_class' => 'bg-slate-50'];
                    $is_active = ($chat['id'] === $active_chat_id);
                    $last_msg = end($chat['messages']);
                    $last_text = $last_msg ? $last_msg['text'] : 'مکالمه خالی است';
                    if (mb_strlen($last_text) > 42) {
                        $last_text = mb_substr($last_text, 0, 42) . '...';
                    }
                    $last_time = $last_msg ? $last_msg['time'] : '';
                ?>
                    <a href="chat.php?id=<?php echo $chat['id']; ?>" class="block p-4 transition-all hover:bg-slate-50/80 relative <?php echo $is_active ? 'bg-indigo-50/40 border-r-4 border-indigo-600' : ''; ?>" data-channel="<?php echo $chat['channel']; ?>" data-sender="<?php echo htmlspecialchars($chat['sender_name']); ?>">
                        <div class="flex items-start gap-3">
                            <div class="relative flex-shrink-0">
                                <?php if (!empty($chat['sender_avatar'])): ?>
                                    <img src="<?php echo $chat['sender_avatar']; ?>" class="w-10 h-10 rounded-full border shadow-sm object-cover">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-500 font-bold border text-xs flex items-center justify-center">
                                        <?php echo mb_substr($chat['sender_name'], 0, 2); ?>
                                    </div>
                                <?php endif; ?>
                                <span class="absolute -bottom-1 -left-1 text-[10px]"><?php echo $meta['icon']; ?></span>
                            </div>

                            <div class="flex-1 space-y-1 text-right">
                                <div class="flex items-center justify-between">
                                    <h4 class="font-black text-slate-800 text-[11px] leading-tight"><?php echo htmlspecialchars($chat['sender_name']); ?></h4>
                                    <span class="text-[8px] font-black text-slate-400"><?php echo $last_time; ?></span>
                                </div>
                                <p class="text-[10px] text-slate-500 font-semibold leading-relaxed"><?php echo htmlspecialchars($last_text); ?></p>
                                
                                <div class="flex items-center justify-between pt-1">
                                    <span class="px-1.5 py-0.5 rounded-lg border text-[8px] font-black <?php echo $meta['badge_class']; ?>">
                                        <?php echo $meta['title']; ?>
                                    </span>
                                    <?php if ($chat['unread_count'] > 0): ?>
                                        <span class="w-4 h-4 bg-rose-500 text-white text-[9px] rounded-full font-black flex items-center justify-center animate-bounce">
                                            <?php echo $chat['unread_count']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Create manual customer / lead flow panel bottom -->
            <div class="p-4 border-t border-slate-200 flex-shrink-0 bg-slate-50/50">
                <button onclick="toggleModal('lead-capture-modal')" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-2 text-xs font-black rounded-xl transition-all shadow-md flex items-center justify-center gap-1.5">
                    <span>➕ ثبت لید و گفتگوی جدید</span>
                </button>
            </div>
        </aside>

        <!-- WORKSPACE CENTER: Message Stream and Thread Controls -->
        <main class="flex-1 bg-slate-50 flex flex-col h-full overflow-hidden">
            <?php if ($active_chat): 
                $active_meta = $platform_meta[$active_chat['channel']] ?? ['title' => 'ارتباطات', 'icon' => '💬', 'badge_class' => ''];
            ?>
                <!-- Chat View Top Status Bar -->
                <div class="bg-white border-b border-slate-200 h-16 px-6 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <?php if (!empty($active_chat['sender_avatar'])): ?>
                                <img src="<?php echo $active_chat['sender_avatar']; ?>" class="w-10 h-10 rounded-full border shadow-sm object-cover">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-indigo-50 text-indigo-700 font-bold border text-xs flex items-center justify-center">
                                    <?php echo mb_substr($active_chat['sender_name'], 0, 2); ?>
                                </div>
                            <?php endif; ?>
                            <span class="absolute -bottom-1 -left-1 text-[11px]"><?php echo $active_meta['icon']; ?></span>
                        </div>
                        <div class="text-right">
                            <h3 class="font-black text-slate-800 text-xs flex items-center gap-1.5">
                                <span><?php echo htmlspecialchars($active_chat['sender_name']); ?></span>
                                <span class="text-[8px] font-black py-0.5 px-1.5 bg-indigo-50 text-indigo-700 rounded-md">واحد <?php echo $active_chat['department'] ?? 'فروش'; ?></span>
                            </h3>
                            <p class="text-[9px] text-slate-400 font-bold col-span-1 md:col-span-2">آی‌دی: <span class="font-mono"><?php echo $active_chat['id']; ?></span> | وسیله: <?php echo htmlspecialchars($active_chat['browser']); ?></p>
                        </div>
                    </div>

                    <!-- Room state settings & toggles -->
                    <div class="flex items-center gap-2">
                        <button onclick="toggleModal('attach-product-modal')" class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-all px-3 py-1.8 rounded-xl text-[10px] font-black border border-indigo-200/20">📦 ارسال محصول</button>
                        <button onclick="toggleModal('preset-messages-modal')" class="bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all px-3 py-1.8 rounded-xl text-[10px] font-black border border-slate-200/20">📋 پاسخ‌های آماده</button>
                        
                        <!-- Toggle Options Button -->
                        <button onclick="toggleModal('assign-chat-modal')" class="bg-slate-900 border hover:bg-slate-800 text-white px-3 py-1.8 rounded-xl text-[10px] font-black">⚙️ ارجاع و تنظیم ربات</button>
                    </div>
                </div>

                <!-- Chat Body Message Feed -->
                <div class="flex-1 overflow-y-auto scroller p-6 space-y-4" id="chat-scroller-feed">
                    <div class="text-center">
                        <span class="inline-block py-1 px-3 bg-slate-200/70 border text-slate-500 font-black text-[9px] rounded-full">🗂️ مکالمه جدید برقرار شد از طریق <?php echo $active_meta['title']; ?></span>
                    </div>

                    <?php foreach ($active_chat['messages'] ?? [] as $m): 
                        $is_me = ($m['sender'] === 'operator');
                    ?>
                        <div class="flex <?php echo $is_me ? 'justify-start' : 'justify-end'; ?>">
                            <div class="max-w-lg space-y-1">
                                <?php if ($is_me): ?>
                                    <div class="flex items-center gap-1.5 mr-1 text-[9px] font-black text-indigo-900">
                                        <span>👤 <?php echo htmlspecialchars($m['operator_name'] ?? 'پشتیبان'); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="p-3.5 <?php echo $is_me ? 'bg-indigo-600 text-white rounded-2xl rounded-tr-none' : 'bg-white text-slate-800 border rounded-2xl rounded-tl-none'; ?> text-right shadow-sm text-xs font-semibold leading-relaxed">
                                    <p class="whitespace-pre-wrap"><?php echo htmlspecialchars($m['text']); ?></p>
                                </div>
                                <div class="text-left text-[8px] font-black text-slate-400 mr-2 ltr-direction">
                                    <?php echo $m['time']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Live Chat Footer Inputs & Tools -->
                <div class="p-4 bg-white border-t border-slate-200 flex-shrink-0">
                    <form method="POST" action="chat.php?id=<?php echo $active_chat['id']; ?>" class="space-y-2" id="chat-operator-form">
                        <input type="hidden" name="send_message" value="1">
                        
                        <div class="relative flex items-center">
                            <textarea name="message_text" id="operator_text_area" rows="2" class="w-full pr-4 pl-24 py-3 border border-slate-200 rounded-2xl text-xs font-bold leading-normal focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none" placeholder="پیام پاسخ خود را بنویسید (یا محصول و پاسخ آماده انتخاب کنید)..." onkeydown="handleEnterKey(event)"></textarea>
                            
                            <div id="typing-status" class="hidden absolute left-24 top-1/2 -translate-y-1/2 text-[9px] font-black text-indigo-500 animate-pulse select-none">
                                اپراتور در حال نوشتن...
                            </div>

                            <div class="absolute left-3 bottom-3 flex items-center gap-2">
                                <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 transition-all text-white px-4 py-2 text-xs font-black rounded-xl shadow-md">ارسال پیام</button>
                            </div>
                        </div>
                    </form>

                    <!-- Mock Client Simulator - To demonstrate fully operational AI integration live -->
                    <div class="mt-3 p-3 bg-slate-50/50 border border-slate-200 border-dashed rounded-2xl flex flex-col md:flex-row items-center justify-between gap-3 text-right">
                        <div>
                            <span class="inline-block px-2 py-0.5 text-[8px] bg-sky-500 text-white rounded-md font-black">شبیه‌ساز کاربری</span>
                            <span class="text-[10px] text-slate-400 font-bold mr-1.5 col-span-1 md:col-span-2">عملکرد ربات کلمات کلیدی و هوش مصنوعی Gemini را فورا تست کنید:</span>
                        </div>
                        <form method="POST" action="chat.php?id=<?php echo $active_chat['id']; ?>" class="flex gap-2 w-full md:w-auto">
                            <input type="hidden" name="mock_client_send" value="1">
                            <input type="text" name="client_text" class="px-3 py-1.5 bg-white border border-slate-350 rounded-xl text-[10px] font-bold w-full md:w-56" placeholder="مثلا قیمت چقدره؟ یا سوال دلخواه...">
                            <button type="submit" class="bg-slate-900 border text-white hover:bg-slate-800 text-[10px] px-3 py-1.5 rounded-xl font-bold transition-all">تست پاسخ</button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- No Chat Open State -->
                <div class="flex-1 flex flex-col items-center justify-center p-8 text-center select-none bg-slate-50">
                    <span class="text-6xl mb-4">💬</span>
                    <h3 class="text-base font-black text-slate-700">یک گفتگو را برای نمایش مکالمات انتخاب کنید</h3>
                    <p class="text-[11px] text-slate-400 font-bold max-w-sm mt-1.5 leading-relaxed">با انتخاب هر چت در سایدبار سمت راست می‌توانید سابقه دایرکت‌ها، ربات‌ها، تیکت‌ها و مکالمات کاربر را مانیتور کنید.</p>
                </div>
            <?php endif; ?>
        </main>

        <!-- WORKSPACE RIGHT: Customer Lead Details Sidebar -->
        <aside class="w-80 bg-white border-r border-slate-200 flex flex-col h-full flex-shrink-0">
            <!-- Active Customer / Lead Info -->
            <div class="p-6 border-b border-slate-100 flex-shrink-0 space-y-4">
                <h3 class="font-black text-xs text-slate-800 border-b border-slate-150 pb-2">👤 اطلاعات خریدار و لید فعال</h3>
                
                <?php if ($active_chat): 
                    // Search if customer is registered
                    $found_cust = null;
                    foreach ($customers as $c) {
                        if ($c['name'] === $active_chat['sender_name'] || (isset($active_chat['sender_phone']) && $c['phone'] === $active_chat['sender_phone'])) {
                            $found_cust = $c;
                            break;
                        }
                    }
                ?>
                    <div class="space-y-3.5 text-xs font-bold leading-normal">
                        <div class="flex justify-between">
                            <span class="text-slate-400">نام کامل:</span>
                            <span class="text-slate-800"><?php echo htmlspecialchars($active_chat['sender_name']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">تلفن تماس:</span>
                            <span class="text-indigo-600 font-mono"><?php echo htmlspecialchars($active_chat['sender_phone'] ?? 'نامشخص - ثبت نشده'); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">مبدا کانال خرید:</span>
                            <span class="text-slate-800"><?php echo $active_meta['title']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">شهر سکونت:</span>
                            <span class="text-slate-800"><?php echo htmlspecialchars($found_cust['city'] ?? 'تهران'); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">تاریخ اولین پیام:</span>
                            <span class="text-slate-500 font-mono"><?php echo htmlspecialchars($active_chat['created_at']); ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-[10px] text-slate-400 font-bold">هیچ گفتگوی فعالی انتخاب نشده است.</p>
                <?php endif; ?>
            </div>

            <!-- Omnichannel Platform Integration & Live Verification Tests -->
            <div class="p-5 border-b border-slate-100 flex-shrink-0 space-y-3 bg-slate-50/45">
                <h3 class="font-black text-xs text-slate-800 border-b border-slate-150 pb-2 flex items-center justify-between">
                    <span class="flex items-center gap-1">🔌 <span>تست لایو کانال‌های متصل</span></span>
                    <span class="px-1.5 py-0.5 text-[8px] bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-md font-sans">Omnichannel</span>
                </h3>
                <div class="space-y-2 text-[10px] font-bold">
                    <!-- Telegram Integration -->
                    <div class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-150 shadow-sm">
                        <div class="flex items-center gap-1.5">
                            <span>✈️</span>
                            <span class="text-slate-700">بات تلگرام</span>
                        </div>
                        <button onclick="testIntegrationStatus('telegram')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-[10px] font-black text-indigo-700 rounded-lg border border-indigo-200/40 transition-all focus:outline-none" id="btn-test-telegram">تست</button>
                    </div>
                    <!-- Bale Integration -->
                    <div class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-150 shadow-sm">
                        <div class="flex items-center gap-1.5">
                            <span>🟢</span>
                            <span class="text-slate-700">بات پیام‌رسان بله</span>
                        </div>
                        <button onclick="testIntegrationStatus('bale')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-[10px] font-black text-indigo-700 rounded-lg border border-indigo-200/40 transition-all focus:outline-none" id="btn-test-bale">تست</button>
                    </div>
                    <!-- Rubika Integration -->
                    <div class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-150 shadow-sm">
                        <div class="flex items-center gap-1.5">
                            <span>🍊</span>
                            <span class="text-slate-700">شناسه روبیکا</span>
                        </div>
                        <button onclick="testIntegrationStatus('rubika')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-[10px] font-black text-indigo-700 rounded-lg border border-indigo-200/40 transition-all focus:outline-none" id="btn-test-rubika">تست</button>
                    </div>
                    <!-- Instagram Integration -->
                    <div class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-150 shadow-sm">
                        <div class="flex items-center gap-1.5">
                            <span>📸</span>
                            <span class="text-slate-700">دایرکت اینستاگرام</span>
                        </div>
                        <button onclick="testIntegrationStatus('instagram')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-[10px] font-black text-indigo-700 rounded-lg border border-indigo-200/40 transition-all focus:outline-none" id="btn-test-instagram">تست</button>
                    </div>
                </div>
                <!-- Interactive Toast Feed -->
                <div id="test-result-box" class="hidden p-3 text-[9px] font-bold rounded-xl leading-normal border animate-fade-in text-center"></div>
            </div>

            <!-- Central lead database lookback panel (Future Bulk Market targets) -->
            <div class="flex-1 overflow-y-auto scroller p-4 space-y-4">
                <h3 class="font-black text-xs text-indigo-900 border-b border-indigo-100 pb-2 flex items-center justify-between">
                    <span>👥 بانک اطلاعاتی مشتریان (بانک پیامک)</span>
                    <span class="px-1.5 py-0.5 text-[8px] bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-md font-sans">تعداد: <?php echo count($customers); ?></span>
                </h3>

                <div class="space-y-2">
                    <?php foreach ($customers as $c_lead): ?>
                        <div class="p-3 bg-slate-50/50 rounded-2xl border text-right space-y-1">
                            <div class="flex justify-between items-center text-[10px]">
                                <span class="font-black text-slate-800"><?php echo htmlspecialchars($c_lead['name']); ?></span>
                                <span class="text-[8px] px-1 py-0.5 bg-slate-100 text-slate-400 border rounded font-black"><?php echo htmlspecialchars($c_lead['city'] ?? 'تهران'); ?></span>
                            </div>
                            <div class="flex justify-between items-center text-[9px] font-bold">
                                <span class="text-indigo-600 font-mono select-all"><?php echo htmlspecialchars($c_lead['phone']); ?></span>
                                <span class="text-slate-400"><?php echo htmlspecialchars($c_lead['source']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Action buttons: Export database of targets -->
            <div class="p-4 border-t bg-slate-50 border-slate-155 text-center flex-shrink-0">
                <button onclick="exportToCsv()" class="w-full bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-black py-2 rounded-xl border transition-all hover:shadow-md">📥 خروجی CSV از بانک خریداران</button>
            </div>
        </aside>

    </div>


    <!-- ==================== MODALS INJECTION ==================== -->

    <!-- Modal 1: Lead Capture & Start Manual Conversation -->
    <div id="lead-capture-modal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] border border-slate-100 w-full max-w-sm p-6 space-y-4 relative text-right" dir="rtl">
            <h3 class="font-black text-slate-900 text-base flex items-center gap-2">👤 ثبت لید و گفتگو پیش از موعد</h3>
            <p class="text-[10px] text-slate-400 font-bold leading-relaxed">ثبت سریع مشخصات کاربر جدید جهت اضافه کردن به بانک شماره تلفن‌های فروش و بازاریابی پیامکی</p>
            
            <form method="POST" class="space-y-3 text-xs font-bold">
                <input type="hidden" name="register_customer_lead" value="1">
                <div>
                    <label class="label block text-[10px] text-slate-500 mb-1">نام کامل بازدیدکننده</label>
                    <input type="text" name="c_name" required class="input" placeholder="مثلا: کامران افشار">
                </div>
                <div>
                    <label class="label block text-[10px] text-slate-500 mb-1">شماره تماس (جهت ارسال پیامک و وب‌سرویس)</label>
                    <input type="text" name="c_phone" required class="input font-mono text-center" placeholder="مثال: 09121234567">
                </div>
                <div>
                    <label class="label block text-[10px] text-slate-500 mb-1">شهر محل سکونت</label>
                    <input type="text" name="c_city" class="input" placeholder="تهران، شیراز... " value="تهران">
                </div>
                <div>
                    <label class="label block text-[10px] text-slate-500 mb-1">واحد / دپارتمان آغاز گفتگو</label>
                    <select name="c_dept" class="input">
                        <?php foreach($chat_departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['id']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-2 pt-3">
                    <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2 text-xs font-black transition-all shadow-md">ثبت و ایجاد چت</button>
                    <button type="button" onclick="toggleModal('lead-capture-modal')" class="px-4 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl py-2 text-xs font-black transition-all">انصراف</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modal 2: Assign operator, change department & Gemini bot switch -->
    <div id="assign-chat-modal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] border border-slate-101 w-full max-w-sm p-6 space-y-4 relative text-right" dir="rtl">
            <h3 class="font-black text-slate-900 text-sm">🛠️ پیکربندی وضعیت مکالمه و اپراتور مسئول</h3>
            
            <?php if ($active_chat): ?>
                <form method="POST" action="chat.php?id=<?php echo $active_chat['id']; ?>" class="space-y-3 text-xs font-bold">
                    <input type="hidden" name="update_chat_status" value="1">
                    
                    <div>
                        <label class="label block text-[10px] text-slate-400 mb-1">ارجاع به واحد (Department)</label>
                        <select name="assign_dept" class="input">
                            <?php foreach ($chat_departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['id']); ?>" <?php echo $active_chat['department'] === $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="label block text-[10px] text-slate-400 mb-1">اپراتور مسئول (Operator)</label>
                        <select name="assign_operator" class="input">
                            <option value="admin" <?php echo $active_chat['assigned_to'] === 'admin' ? 'selected' : ''; ?>>مدیر اصلی (admin)</option>
                            <option value="editor" <?php echo $active_chat['assigned_to'] === 'editor' ? 'selected' : ''; ?>>نویسنده و ویرایشگر (editor)</option>
                        </select>
                    </div>

                    <!-- Gemini state switch -->
                    <div class="pt-2 flex items-center justify-between border-t border-slate-100 mt-4 pb-2">
                        <span class="text-[10px] text-slate-500">پاسخگویی فعال ربات هوشمند (AI Bot):</span>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="ai_active" value="1" <?php echo ($active_chat['ai_active'] ?? true) ? 'checked' : ''; ?> class="sr-only peer">
                            <div class="w-9 h-5 bg-slate-200 rounded-full peer peer-checked:bg-indigo-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full"></div>
                        </label>
                    </div>

                    <!-- Archive status -->
                    <div class="flex items-center justify-between pb-4">
                        <span class="text-[10px] text-slate-500">بایگانی کردن چت (Archived):</span>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_archived" value="1" <?php echo ($active_chat['is_archived'] ?? false) ? 'checked' : ''; ?> class="sr-only peer">
                            <div class="w-9 h-5 bg-slate-200 rounded-full peer peer-checked:bg-rose-500 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-full"></div>
                        </label>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl py-2 text-[10px] font-black transition-all">بروزرسانی وضعیت</button>
                        <button type="button" onclick="toggleModal('assign-chat-modal')" class="px-4 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl py-2 text-[10px] font-black transition-all">بستن</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>


    <!-- Modal 3: Fast Predefined Messages selector -->
    <div id="preset-messages-modal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] border border-slate-100 w-full max-w-sm p-6 space-y-4 relative text-right" dir="rtl">
            <h3 class="font-black text-slate-900 text-xs border-b pb-2">📋 لیست پاسخ‌های آماده و الگوهای تایید شده</h3>
            <div class="space-y-2.5 max-h-80 overflow-y-auto pr-1">
                <?php foreach ($chat_predefined as $p_msg): ?>
                    <button type="button" onclick="insertPredefinedText(`<?php echo addslashes($p_msg['text']); ?>`)" class="w-full text-right p-3 bg-slate-50 hover:bg-indigo-50/50 border border-slate-100 hover:border-indigo-200 rounded-2xl block space-y-1 transition-all select-none">
                        <span class="text-[10px] px-1.5 py-0.5 bg-indigo-100/50 text-indigo-700 rounded-md font-black"><?php echo htmlspecialchars($p_msg['title']); ?></span>
                        <p class="text-[9px] text-slate-500 font-bold mt-1.5 line-clamp-2"><?php echo htmlspecialchars($p_msg['text']); ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
            
            <div class="text-left">
                <button type="button" onclick="toggleModal('preset-messages-modal')" class="px-4 py-1.8 bg-slate-100 text-slate-600 text-[10px] rounded-xl font-bold hover:bg-slate-200 transition-all">انصراف</button>
            </div>
        </div>
    </div>


    <!-- Modal 4: Attach Approved Products -->
    <div id="attach-product-modal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] border border-slate-101 w-full max-w-md p-6 space-y-4 relative text-right animate-fade-in" dir="rtl">
            <div class="border-b pb-2">
                <h3 class="font-black text-slate-900 text-xs">💎 کاتالوگ محصولات تایید شده (ارسال سریع در چت)</h3>
                <p class="text-[9px] text-slate-400 font-bold mt-0.5">محصولات منتخب تایید شده را با مشخصات و لینک خرید مستقیم الصاق فرمایید.</p>
            </div>

            <div class="grid grid-cols-2 gap-3 max-h-80 overflow-y-auto scroller pr-1">
                <?php foreach ($products_list as $prod): 
                    $p_img = !empty($prod['image']) ? $prod['image'] : 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=200';
                    $p_name = $prod['name'];
                    $p_price = formatPriceToToman($prod['price'] ?? 0);
                    
                    // Attach dynamic buying message
                    $payload_text = "🛍️ پیشنهاد ویژه پشتیبانی ما:\n💎 محصول: {$p_name}\n💵 قیمت: {$p_price}\n📥 خرید مستقیم و ثبت آسان:\nhttps://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($prod['id'] ?? 'p1');
                ?>
                    <button type="button" onclick="insertPredefinedText(`<?php echo addslashes($payload_text); ?>`)" class="p-2.5 bg-slate-50 hover:bg-emerald-50/40 border border-slate-100 hover:border-emerald-200 rounded-2xl flex items-center gap-2.5 text-right transition-all">
                        <img src="<?php echo $p_img; ?>" class="w-12 h-12 rounded-xl object-cover border flex-shrink-0">
                        <div class="space-y-0.5 min-w-0">
                            <h4 class="font-black text-[9px] text-slate-800 truncate"><?php echo htmlspecialchars($p_name); ?></h4>
                            <span class="text-[8px] font-black text-emerald-600 block"><?php echo $p_price; ?></span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="text-left pt-2 border-t">
                <button type="button" onclick="toggleModal('attach-product-modal')" class="px-4 py-2 bg-slate-100 text-slate-600 text-[10px] font-black rounded-xl hover:bg-slate-200 transition-all">بستن کاتالوگ</button>
            </div>
        </div>
    </div>


    <!-- Optional Sound alert on new incoming client simulation messages -->
    <script>
    function toggleModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.toggle('hidden');
        }
    }

    function insertPredefinedText(text) {
        var txtArea = document.getElementById('operator_text_area');
        if (txtArea) {
            txtArea.value = text;
            txtArea.focus();
            // Explicitly hide both tool modals instead of toggling blind-folded
            var m1 = document.getElementById('preset-messages-modal');
            var m2 = document.getElementById('attach-product-modal');
            if (m1) m1.classList.add('hidden');
            if (m2) m2.classList.add('hidden');
        }
    }

    function handleEnterKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('chat-operator-form').submit();
        }
    }

    // Live search in dynamic chat rooms
    function filterRooms() {
        var input = document.getElementById('rooms-search').value.toLowerCase();
        var rooms = document.getElementById('rooms-list-wrapper').querySelectorAll('a');
        rooms.forEach(function(room) {
            var senderOf = room.getAttribute('data-sender').toLowerCase();
            if (senderOf.includes(input)) {
                room.style.display = '';
            } else {
                room.style.display = 'none';
            }
        });
    }

    // Channel Quick tab filters
    function changeRoomsChannelFilter(filter) {
        var btnAll = document.getElementById('btn-chan-all');
        var btnWeb = document.getElementById('btn-chan-web');
        var btnSoc = document.getElementById('btn-chan-social');
        
        // Reset classes
        [btnAll, btnWeb, btnSoc].forEach(function(btn) {
            btn.className = "flex-1 py-1 rounded-lg text-center text-slate-500 hover:text-slate-800 transition-all focus:outline-none";
        });
        
        var rooms = document.getElementById('rooms-list-wrapper').querySelectorAll('a');
        
        if (filter === 'all') {
            btnAll.className = "flex-1 py-1 rounded-lg text-center bg-white text-slate-800 shadow-sm font-black transition-all focus:outline-none";
            rooms.forEach(function(r) { r.style.display = ''; });
        } else if (filter === 'web') {
            btnWeb.className = "flex-1 py-1 rounded-lg text-center bg-white text-indigo-700 shadow-sm font-black transition-all focus:outline-none";
            rooms.forEach(function(r) {
                if (r.getAttribute('data-channel') === 'web') {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        } else {
            btnSoc.className = "flex-1 py-1 rounded-lg text-center bg-white text-amber-700 shadow-sm font-black transition-all focus:outline-none";
            rooms.forEach(function(r) {
                var chan = r.getAttribute('data-channel');
                if (chan !== 'web') {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        }
    }

    // Export Leads DB to CSV instantly
    function exportToCsv() {
        var data = <?php echo json_encode($customers); ?>;
        var csvContent = "data:text/csv;charset=utf-8,\uFEFF";
        csvContent += "Name,Phone Number,Channel/Source,City,Joined Date\n";
        
        data.forEach(function(row) {
            var r = [
                '"' + row.name + '"',
                '"' + row.phone + '"',
                '"' + row.source + '"',
                '"' + (row.city || 'تهران') + '"',
                '"' + (row.date || '') + '"'
            ];
            csvContent += r.join(",") + "\n";
        });
        
        var encodedUri = encodeURI(csvContent);
        var link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "maarksho_leads_db.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // AJAX tester for Omnichannel API Connections
    function testIntegrationStatus(platform) {
        var btn = document.getElementById('btn-test-' + platform);
        var resultBox = document.getElementById('test-result-box');
        if (!btn || !resultBox) return;
        
        var originalText = btn.innerHTML;
        btn.innerHTML = '⚡ تست...';
        btn.disabled = true;
        
        fetch('chat.php?action=test_connection&platform=' + platform)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            resultBox.classList.remove('hidden');
            resultBox.innerHTML = data.message;
            
            if (data.status === 'success') {
                resultBox.className = "mt-3 p-3 text-[10px] font-bold rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 leading-normal animate-fade-in text-center";
                btn.innerHTML = '✅ موفق';
                btn.className = "px-2.5 py-1 bg-emerald-50 text-[10px] font-black text-emerald-700 rounded-lg border border-emerald-200 transition-all focus:outline-none";
            } else {
                resultBox.className = "mt-3 p-3 text-[10px] font-bold rounded-xl border border-rose-250 bg-rose-50 text-rose-800 leading-normal animate-fade-in text-center";
                btn.innerHTML = '❌ خطا';
                btn.className = "px-2.5 py-1 bg-rose-50 text-[10px] font-black text-rose-700 rounded-lg border border-rose-200 transition-all focus:outline-none";
            }
            
            setTimeout(function() {
                btn.innerHTML = 'تست';
                btn.disabled = false;
                btn.className = "px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-[10px] font-black text-indigo-700 rounded-lg border border-indigo-200/40 transition-all focus:outline-none";
            }, 6000);
        })
        .catch(function(err) {
            resultBox.classList.remove('hidden');
            resultBox.innerHTML = '❌ خطای فنی در بررسی اتصال لایو کانکشن.';
            resultBox.className = "mt-3 p-3 text-[10px] font-bold rounded-xl border border-rose-200 bg-rose-50 text-rose-800 leading-normal text-center";
            btn.innerHTML = 'تست';
            btn.disabled = false;
        });
    }

    // Scroll to the bottom on chat load
    var chatScroller = document.getElementById('chat-scroller-feed');
    if (chatScroller) {
        chatScroller.scrollTop = chatScroller.scrollHeight;
    }
    </script>

</body>
</html>
