<?php
require_once 'inc/functions.php';
require_once 'inc/cors.php';

// Set response header to JSON
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'init';
$projectId = $_GET['project'] ?? 'default';

$db = getDB();
$project = null;
$projectIdx = -1;

foreach ($db['projects'] as $idx => $p) {
    if ($p['id'] === $projectId) {
        $project = $p;
        $projectIdx = $idx;
        break;
    }
}

if (!$project && !empty($db['projects'])) {
    $project = reset($db['projects']);
    foreach ($db['projects'] as $idx => $p) {
        if ($p['id'] === $project['id']) {
            $projectIdx = $idx;
            break;
        }
    }
}

if (!$project) {
    echo json_encode(['ok' => false, 'error' => 'پروژه مربوطه یافت نشد']);
    exit;
}

// Extract Chat settings
$chat_settings = $project['settings']['chat'] ?? [];
$chat_enabled = $chat_settings['enabled'] ?? 1;

if (!$chat_enabled) {
    echo json_encode(['ok' => false, 'error' => 'سیستم چت زنده غیرفعال است.']);
    exit;
}

// -------------------------------------------------------------
// Action: init (Retrieve configuration and existing messages)
// -------------------------------------------------------------
if ($action === 'init') {
    $visitorId = $_GET['visitor_id'] ?? '';
    $chat_widget_theme = $chat_settings['widget_theme_color'] ?? '#4f46e5';
    $chat_widget_welcome = $chat_settings['widget_welcome_text'] ?? 'سلام! خوش آمدید. چطور می‌توانم شما را راهنمایی کنم؟';
    $chat_ai_operator_name = $chat_settings['ai_operator_name'] ?? 'دستیار هوشمند سیستم';
    $chat_ai_avatar = $chat_settings['ai_avatar'] ?? '';
    $chat_collect_leads = $chat_settings['collect_customer_leads'] ?? 1;
    $chat_widget_brand_name = $chat_settings['widget_brand_name'] ?? $project['name'] ?? 'پشتیبانی زنده';
    $chat_widget_brand_logo = $chat_settings['widget_brand_logo'] ?? '';
    $chat_predefined = $chat_settings['predefined_messages'] ?? [];
    $chat_widget_hours_active = $chat_settings['widget_hours_active'] ?? 0;
    $chat_widget_hours_config = $chat_settings['widget_hours_config'] ?? '08:00 - 18:00';

    // Verify service hours
    $is_online = true;
    if ($chat_widget_hours_active) {
        $parts = explode('-', $chat_widget_hours_config);
        if (count($parts) === 2) {
            $start = trim($parts[0]);
            $end = trim($parts[1]);
            $now = date('H:i');
            if ($now < $start || $now > $end) {
                $is_online = false;
            }
        }
    }

    $conversation = null;
    if (!empty($visitorId)) {
        $conversations = $project['settings']['chat_conversations'] ?? [];
        foreach ($conversations as $c) {
            if ($c['id'] === $visitorId || ($c['sender_phone'] === $visitorId && $c['channel'] === 'web')) {
                $conversation = $c;
                break;
            }
        }
    }

    // Auto create guest conversation if lead generation is disabled
    if (!$conversation && !$chat_collect_leads && !empty($visitorId)) {
        $conversation = [
            'id' => $visitorId,
            'channel' => 'web',
            'sender_name' => 'کاربر مهمان',
            'sender_avatar' => '',
            'sender_phone' => '',
            'department' => 'sales',
            'assigned_to' => 'admin',
            'ai_active' => true,
            'is_archived' => false,
            'browser' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser',
            'messages' => []
        ];
        $project['settings']['chat_conversations'][] = $conversation;
        saveProject($project);
    }

    echo json_encode([
        'ok' => true,
        'config' => [
            'theme_color' => $chat_widget_theme,
            'welcome_text' => $chat_widget_welcome,
            'brand_name' => $chat_widget_brand_name,
            'brand_logo' => $chat_widget_brand_logo,
            'operator_name' => $chat_ai_operator_name,
            'operator_avatar' => $chat_ai_avatar,
            'collect_leads' => $chat_collect_leads,
            'predefined_messages' => $chat_predefined,
            'is_online' => $is_online,
            'hours_config' => $chat_widget_hours_config
        ],
        'conversation' => $conversation
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// Action: save_lead (Capture visitor contact details)
// -------------------------------------------------------------
if ($action === 'save_lead') {
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    $name = trim($payload['name'] ?? '');
    $phone = trim($payload['phone'] ?? '');
    $department = trim($payload['department'] ?? 'sales');

    if (empty($name) || empty($phone)) {
        echo json_encode(['ok' => false, 'error' => 'نام و شماره موبایل الزامی است.']);
        exit;
    }

    // Register in customers central bank
    if (!isset($project['settings']['chat_customers'])) {
        $project['settings']['chat_customers'] = [];
    }
    $project['settings']['chat_customers'][] = [
        'name' => $name,
        'phone' => $phone,
        'source' => 'چت آنلاین وب‌سایت',
        'date' => date('Y-m-d H:i'),
        'city' => 'تهران'
    ];

    // Create a new Live Chat conversation room
    $chatId = 'chat_web_' . uniqid();
    $conversation = [
        'id' => $chatId,
        'channel' => 'web',
        'sender_name' => $name . " (گفتگوی زنده)",
        'sender_avatar' => '',
        'sender_phone' => $phone,
        'department' => $department,
        'assigned_to' => 'admin',
        'ai_active' => true,
        'is_archived' => false,
        'browser' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser',
        'messages' => []
    ];

    if (!isset($project['settings']['chat_conversations'])) {
        $project['settings']['chat_conversations'] = [];
    }
    $project['settings']['chat_conversations'][] = $conversation;

    saveProject($project);

    echo json_encode([
        'ok' => true,
        'chat_id' => $chatId,
        'conversation' => $conversation
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// Action: send_message (Post a message and get optional AI response)
// -------------------------------------------------------------
if ($action === 'send_message') {
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    $chatId = trim($payload['chat_id'] ?? '');
    $text = trim($payload['text'] ?? '');

    if (empty($chatId) || empty($text)) {
        echo json_encode(['ok' => false, 'error' => 'پارامترهای ارسالی نامعتبر است.']);
        exit;
    }

    $conversations = $project['settings']['chat_conversations'] ?? [];
    $chat_idx = -1;
    foreach ($conversations as $idx => $c) {
        if ($c['id'] === $chatId) {
            $chat_idx = $idx;
            break;
        }
    }

    if ($chat_idx === -1) {
        echo json_encode(['ok' => false, 'error' => 'گفتگوی مورد نظر یافت نشد.']);
        exit;
    }

    // Append user message
    $newMsg = [
        'id' => 'msg_' . uniqid(),
        'sender' => 'user',
        'text' => $text,
        'time' => date('H:i')
    ];
    $project['settings']['chat_conversations'][$chat_idx]['messages'][] = $newMsg;
    
    // Increment unread count for operator view
    $project['settings']['chat_conversations'][$chat_idx]['unread_count'] = ($project['settings']['chat_conversations'][$chat_idx]['unread_count'] ?? 0) + 1;

    $active_chat = $project['settings']['chat_conversations'][$chat_idx];

    // Trigger AI response if toggled active
    $chat_ai_active = $chat_settings['ai_active'] ?? 1;
    $chat_api_key = $chat_settings['api_key'] ?? '';
    $chat_ai_operator_name = $chat_settings['ai_operator_name'] ?? 'دستیار هوشمند سیستم';
    $chat_ai_instructions = $chat_settings['ai_instructions'] ?? 'شما یک دستیار هوش مصنوعی مجرب برای پاسخگویی به مشتریان هستید. پاسخ‌های مفید و کوتاه در قالب ۲ خط ارسال کنید.';
    $chat_auto_replies = $chat_settings['auto_replies'] ?? [];

    $ai_responded = false;
    $autoreply_text = null;

    // 1. Check for keyword auto-replies
    if (!empty($chat_auto_replies)) {
        foreach ($chat_auto_replies as $rule) {
            if ($rule['match_type'] === 'exact' && strtolower(trim($rule['keyword'])) === strtolower(trim($text))) {
                $autoreply_text = $rule['reply'];
                break;
            } else if ($rule['match_type'] === 'contains' && mb_stripos($text, $rule['keyword']) !== false) {
                $autoreply_text = $rule['reply'];
                break;
            }
        }
    }

    if ($autoreply_text) {
        $aiMsg = [
            'id' => 'msg_' . uniqid(),
            'sender' => 'operator',
            'text' => $autoreply_text,
            'time' => date('H:i'),
            'operator_name' => $chat_ai_operator_name
        ];
        $project['settings']['chat_conversations'][$chat_idx]['messages'][] = $aiMsg;
        $ai_responded = true;
    } else if ($chat_ai_active && ($active_chat['ai_active'] ?? true)) {
        // 2. Fetch approved products list to feed AI context
        $products_context = '';
        if (isset($project['products']) && is_array($project['products'])) {
            $products_context .= "محصولات موجود در فروشگاه:\n";
            $count = 0;
            foreach ($project['products'] as $p) {
                if (($p['status'] ?? '') !== 'rejected' && $count < 10) {
                    $products_context .= "- نام: " . ($p['name'] ?? '') . " | قیمت: " . ($p['price'] ?? '') . " | لینک: https://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($p['id'] ?? '') . "\n";
                    $count++;
                }
            }
        }

        // Build Gemini prompt
        $instructions = $chat_ai_instructions . "\n" . $products_context . "\nزبان پاسخگویی حتما فارسی صمیمانه و کوتاه باشد.";
        $history = "مکالمات گذشته:\n";
        $last_msgs = array_slice($active_chat['messages'], -6);
        foreach ($last_msgs as $m) {
            $history .= ($m['sender'] === 'user' ? "مشتری: " : "پشتیبان: ") . $m['text'] . "\n";
        }

        $prompt = $history . "\nآخرین پیام مشتری: " . $text . "\nپاسخ شما:";

        $ai_res = geminiGenerateResponse($prompt, $instructions, $chat_api_key);
        if (!isset($ai_res['error']) && !empty($ai_res)) {
            // Locate response text in Gemini response format
            $ai_text = '';
            if (isset($ai_res['candidates'][0]['content']['parts'][0]['text'])) {
                $ai_text = trim($ai_res['candidates'][0]['content']['parts'][0]['text']);
            } else if (is_string($ai_res)) {
                $ai_text = trim($ai_res);
            }

            if (!empty($ai_text)) {
                $aiMsg = [
                    'id' => 'msg_' . uniqid(),
                    'sender' => 'operator',
                    'text' => $ai_text,
                    'time' => date('H:i'),
                    'operator_name' => $chat_ai_operator_name
                ];
                $project['settings']['chat_conversations'][$chat_idx]['messages'][] = $aiMsg;
                $ai_responded = true;
            }
        }
    }

    saveProject($project);

    echo json_encode([
        'ok' => true,
        'messages' => $project['settings']['chat_conversations'][$chat_idx]['messages']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// Action: get_messages (Poll for new operator replies)
// -------------------------------------------------------------
if ($action === 'get_messages') {
    $chatId = $_GET['chat_id'] ?? '';

    if (empty($chatId)) {
        echo json_encode(['ok' => false, 'error' => 'گفتگو یافت نشد.']);
        exit;
    }

    $conversations = $project['settings']['chat_conversations'] ?? [];
    $conversation = null;
    foreach ($conversations as $c) {
        if ($c['id'] === $chatId) {
            $conversation = $c;
            break;
        }
    }

    if (!$conversation) {
        echo json_encode(['ok' => false, 'error' => 'گفتگوی مورد نظر یافت نشد.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'messages' => $conversation['messages'] ?? []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
