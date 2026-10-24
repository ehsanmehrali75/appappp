<?php
require_once 'inc/functions.php';
require_once 'inc/cors.php';

// Set response header to JSON
header('Content-Type: application/json');

$db = getDB();

// 1. Get Project ID from query parameter
$projectId = $_GET['project'] ?? '';
$platform = $_GET['platform'] ?? ''; // telegram, bale

// Find the target project
$project = null;
$projectIdx = -1;
foreach ($db['projects'] as $idx => $p) {
    if ($p['id'] === $projectId) {
        $project = $p;
        $projectIdx = $idx;
        break;
    }
}


if (!$project) {
    echo json_encode(['ok' => false, 'error' => 'پروژه مربوطه یافت نشد']);
    exit;
}

// 2. Read incoming request content
$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

addDebugLog('WEBHOOK_INCOMING', "Incoming text from platform: $platform for Project: " . $project['name'], $payload);

// INTERCEPT SOCIAL MEDIA STATISTICS SYNC PLATFORM
if ($platform === 'social_sync') {
    http_response_code(410);echo json_encode(['ok'=>false,'error'=>'مسیر قدیمی آمار غیرفعال است؛ داده را با توکن منبع پروژه به integration_ingest.php ارسال کنید.'],JSON_UNESCAPED_UNICODE);exit;
}

if (!$payload) {
    echo json_encode(['ok' => false, 'error' => 'پست اطلاعات نامعتبر است']);
    exit;
}

// 3. Detect message structure (compatible with Telegram / Bale update schema)
$messageData = $payload['message'] ?? $payload['edited_message'] ?? null;
if (!$messageData) {
    // Maybe inline query or callback? For simple chat, we focus on messages
    echo json_encode(['ok' => true, 'description' => 'پیام خالی یا ساختار دیگر صرف‌نظر شد']);
    exit;
}

$chatId = $messageData['chat']['id'] ?? null;
$text = trim($messageData['text'] ?? '');
$firstName = $messageData['from']['first_name'] ?? '';
$lastName = $messageData['from']['last_name'] ?? '';
$username = $messageData['from']['username'] ?? '';

if (!$chatId) {
    echo json_encode(['ok' => false, 'error' => 'شناسه چت یافت نشد']);
    exit;
}

// Check fallback platform if not provided in URL query
if (empty($platform)) {
    $platform = 'telegram';
}

$senderName = trim($firstName . ' ' . $lastName);
if (empty($senderName)) {
    $senderName = !empty($username) ? "@" . $username : "مخاطب " . ucfirst($platform);
}

// 4. Update project chat conversations
if (!isset($project['settings']['chat_conversations'])) {
    $project['settings']['chat_conversations'] = [];
}

$conversations = $project['settings']['chat_conversations'];
$activeChatIdx = -1;
foreach ($conversations as $idx => $c) {
    if ($c['channel'] === $platform && ((isset($c['sender_chat_id']) && $c['sender_chat_id'] == $chatId) || $c['id'] == "chat_{$platform}_{$chatId}")) {
        $activeChatIdx = $idx;
        break;
    }
}

$newMsg = [
    'id' => 'msg_wb_' . uniqid(),
    'sender' => 'client',
    'text' => $text,
    'time' => date('H:i')
];

$chatSettings = $project['settings']['chat'] ?? [];
$chatApiKey = $chatSettings['api_key'] ?? '';
$chatAiActive = $chatSettings['ai_active'] ?? 1;
$chatAiOperatorName = $chatSettings['ai_operator_name'] ?? 'دستیار هوشمند سیستم';
$chatAiInstructions = $chatSettings['ai_instructions'] ?? 'شما یک دستیار هوش مصنوعی مجرب برای پاسخگویی به مشتریان هستید. همیشه پاسخ‌های مفید، مودبانه و خلاصه در قالب ۲ خط به زبان فارسی ارسال فرمایید.';
$chatAutoReplies = $chatSettings['auto_replies'] ?? [];

$responseMsgText = '';
$isAiTriggered = false;

if ($activeChatIdx > -1) {
    // Append to existing
    $project['settings']['chat_conversations'][$activeChatIdx]['messages'][] = $newMsg;
    $project['settings']['chat_conversations'][$activeChatIdx]['unread_count'] += 1;
    $activeChat = $project['settings']['chat_conversations'][$activeChatIdx];
} else {
    // Create brand new chat thread
    $newChatId = "chat_{$platform}_{$chatId}";
    $activeChat = [
        'id' => $newChatId,
        'channel' => $platform,
        'sender_name' => $senderName,
        'sender_chat_id' => $chatId,
        'sender_phone' => '',
        'department' => 'sales',
        'assigned_to' => 'admin',
        'ai_active' => true,
        'is_archived' => false,
        'browser' => ucfirst($platform) . ' Bot Client',
        'unread_count' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'messages' => [$newMsg]
    ];
    $project['settings']['chat_conversations'][] = $activeChat;
    
    // Find index of newly added conversation
    foreach ($project['settings']['chat_conversations'] as $idx => $c) {
        if ($c['id'] === $newChatId) {
            $activeChatIdx = $idx;
            break;
        }
    }
}

// 5. Check auto replies first
$foundAutoreply = null;

// Get approved products list config for matching
$productsList = [];
if (isset($project['products']) && is_array($project['products'])) {
    foreach ($project['products'] as $pr) {
        $st = $pr['status'] ?? 'active';
        $dec1 = $pr['stage1_decision'] ?? '';
        $dec2 = $pr['stage2_decision'] ?? '';
        if ($st !== 'rejected' && ($st === 'approved' || $st === 'active' || empty($pr['status']) || $dec1 === 'approved' || $dec2 === 'approved')) {
            $productsList[] = $pr;
        }
    }
}

// Scan for product matches in text
if (!empty($productsList)) {
    foreach ($productsList as $p) {
        $pName = $p['name'];
        $pSku = $p['sku'] ?? '';
        if (mb_stripos($text, $pName) !== false || (!empty($pSku) && mb_stripos($text, $pSku) !== false)) {
            $pPrice = number_format($p['price'] ?? 0) . ' تومان';
            $pLink = "https://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($p['id'] ?? 'p1');
            $foundAutoreply = "💎 محصول \"{$pName}\" با قیمت فوق‌العاده {$pPrice} در سیستم تایید شده و موجود است! برای سفارش مستقیم از لینک زیر اقدام بفرمایید:\n{$pLink}";
            break;
        }
    }
}

if (!$foundAutoreply && !empty($chatAutoReplies)) {
    foreach ($chatAutoReplies as $rule) {
        if ($rule['match_type'] === 'exact' && strtolower(trim($rule['keyword'])) === strtolower(trim($text))) {
            $foundAutoreply = $rule['reply'];
            break;
        } else if ($rule['match_type'] === 'contains' && mb_stripos($text, $rule['keyword']) !== false) {
            $foundAutoreply = $rule['reply'];
            break;
        }
    }
}

if ($foundAutoreply) {
    $responseMsgText = "⚡ پاسخ خودکار: " . $foundAutoreply;
    
    $project['settings']['chat_conversations'][$activeChatIdx]['messages'][] = [
        'id' => 'msg_bot_' . uniqid(),
        'sender' => 'operator',
        'text' => $responseMsgText,
        'time' => date('H:i'),
        'operator_name' => 'ربات هوشمند سیستم'
    ];
    $project['settings']['chat_conversations'][$activeChatIdx]['unread_count'] = 0;
} else if ($chatAiActive && ($activeChat['ai_active'] ?? true)) {
    // 6. Gemini API response generator
    $realCatalogText = "";
    if (!empty($productsList)) {
        $realCatalogText = "\n\nلیست محصولات تایید شده و موجود در پورتال ما:\n";
        foreach (array_slice($productsList, 0, 5) as $rIdx => $p) {
            $pName = $p['name'];
            $pPrice = number_format($p['price'] ?? 0) . ' تومان';
            $pLink = "https://" . $_SERVER['HTTP_HOST'] . "/store_view.php?prod=" . ($p['id'] ?? 'p1');
            $realCatalogText .= ($rIdx + 1) . "- محصول: {$pName} | قیمت: {$pPrice} | لینک ثبت سفارش سریع: {$pLink}\n";
        }
    }

    $prompt = "مشتری در ربات پیام‌رسان پیام زیر را ارسال کرده است:\n" . $text . "{$realCatalogText}\n\nپاسخ مناسب را برای مشتری به صورت صمیمانه و کوتاه در حد یک یا دو خط برای قالب پیام‌رسان بنویسید. اگر محصولات تایید شده ما مرتبط بود معرفی نموده و ارجاع دهید.";
    $aiRes = geminiGenerateResponse($prompt, $chatAiInstructions, $chatApiKey);
    
    $aiText = $aiRes['text'] ?? '';
    if (empty($aiText)) {
        $aiText = "سلام حضور شما دوست عزیز. پیام شما ثبت شده است و واحد پشتیبانی پورتال حتماً پیگیری خواهد کرد.";
    }
    
    $responseMsgText = "🤖 [" . $chatAiOperatorName . "] " . $aiText;
    
    $project['settings']['chat_conversations'][$activeChatIdx]['messages'][] = [
        'id' => 'msg_gpt_' . uniqid(),
        'sender' => 'operator',
        'text' => $responseMsgText,
        'time' => date('H:i'),
        'operator_name' => $chatAiOperatorName
    ];
    $project['settings']['chat_conversations'][$activeChatIdx]['unread_count'] = 0;
}

// 7. Save to DB
saveProject($project);

// 8. Deliver message back using correct delivery strategy (Direct or NovinHub)
if (!empty($responseMsgText)) {
    $integrationMode = $chatSettings['integration_mode'] ?? 'direct'; // direct or novinhub
    
    if ($integrationMode === 'novinhub') {
        // Method 1: NovinHub direct message delivery
        $novinhubToken = $project['settings']['novinhub_token'] ?? $chatApiKey;
        $novinhubAccountId = $project['settings']['connections'][$platform]['proxy']['novinhub_account_id'] ?? '';
        
        addDebugLog('WEBHOOK_DELIVERY', "Delivering reply through NovinHub Platform: $platform | Account: $novinhubAccountId");
        
        $hubRes = sendChatMessageThroughNovinhub($novinhubToken, $novinhubAccountId, $chatId, $responseMsgText, $platform);
        
        if ($hubRes !== true) {
            addDebugLog('WEBHOOK_DELIVERY_FAIL', "NovinHub failed: " . json_encode($hubRes));
            // Back up method (failover to direct sending using connection settings and proxy)
            deliverDirect($platform, $project, $chatId, $responseMsgText);
        }
    } else {
        // Method 2: Direct delivery with proxies
        addDebugLog('WEBHOOK_DELIVERY', "Delivering reply direct from server on platform: $platform");
        deliverDirect($platform, $project, $chatId, $responseMsgText);
    }
}

echo json_encode(['ok' => true, 'description' => 'پیام با موفقیت دریافت و پردازش گردید']);

/**
 * Direct sending helper with proxies
 */
function deliverDirect($platform, $project, $chatId, $message) {
    $conn = $project['settings']['connections'][$platform] ?? null;
    if (!$conn) {
        addDebugLog('WEBHOOK_DIRECT_ERR', "No connection configured for platform: $platform");
        return;
    }
    
    $token = $conn['token'] ?? '';
    $proxy = $conn['proxy'] ?? null;
    
    if (empty($token)) {
        addDebugLog('WEBHOOK_DIRECT_ERR', "Token is missing for platform: $platform");
        return;
    }
    
    if ($platform === 'telegram') {
        sendToTelegram($token, $chatId, $message, null, $proxy);
    } else if ($platform === 'bale') {
        sendToBale($token, $chatId, $message, null, $proxy);
    }
}
