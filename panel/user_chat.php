<?php
/**
 * Maarksho Flow v3 - سیستم چت و گفتگوی داخلی بین کاربران پروژه
 * طراح و توسعه‌دهنده با رابط کاربری تمیز شبیه به تلگرام
 */

require_once 'inc/functions.php';
require_once 'inc/cors.php';

// بررسی دسترسی کاربر ملحق شده
if (!isUser()) {
    redirect('/?action=login');
}

$currentUser = $_SESSION['user'];
$currentUsername = $currentUser['username'] ?? '';
$currentUserDisplay = $_SESSION['display_name'] ?? $currentUsername;

// پیدا کردن پروژه فعال جاری
$projectId = $_SESSION['project_id'] ?? null;
if (isset($_GET['project'])) {
    $projectId = $_GET['project'];
}

$db = getDB();
$project = null;
$projectIdx = -1;

foreach ($db['projects'] as $idx => $p) {
    if ($p['id'] == $projectId) {
        $project = $p;
        $projectIdx = $idx;
        break;
    }
}

if (!$project && !empty($db['projects'])) {
    $project = reset($db['projects']);
    $projectId = $project['id'];
    $_SESSION['project_id'] = $projectId;
    // یافتن مجدد ایندکس
    foreach ($db['projects'] as $idx => $p) {
        if ($p['id'] == $projectId) {
            $projectIdx = $idx;
            break;
        }
    }
}

if (!$project) {
    die('خطای سیستم: پروژه‌ای تعریف نشده یا دسترسی به آن امکان‌پذیر نیست.');
}

if (!SessionIdentity::select($project)) AccessControl::deny();
$currentUser = $_SESSION['user'];
$currentUsername = $currentUser['username'] ?? '';

// حل مشکل آدرس روت آپلودها برای لود زنده در دامنه اصلی و ساب‌فولدر پانل
$baseUrl = getFullUrl('dummy_file.png');
$baseUrl = substr($baseUrl, 0, -strlen('dummy_file.png'));

if (!function_exists('resolveChatUrl')) {
    function resolveChatUrl($url, $baseUrl) {
        return getFullUrl($url);
    }
}

// دریافت اعضا و کاربران پروژه جاری
$projectUsers = $project['users'] ?? [];
// اضافه کردن ادمین ارشد کل به لیست کاربران چت در این پروژه جهت دسترسی متقابل
$hasAdmin = false;
foreach ($projectUsers as $pu) {
    if ($pu['username'] === 'admin') {
        $hasAdmin = true;
        break;
    }
}
if (!$hasAdmin) {
    $projectUsers[] = [
        'username' => 'admin',
        'role' => 'administrator',
        'permissions' => ['all'],
        'mobile' => '',
        'display_name' => 'مدیر ارشد سیستم'
    ];
}

// استخراج ساختار چت‌ها و پیام‌ها در تنظیمات اختصاصی پروژه جهت امنیت و دیوارهای حریم خصوصی
if (!isset($project['settings']['internal_chat_v3'])) {
    $project['settings']['internal_chat_v3'] = [
        'groups' => [],
        'messages' => []
    ];
}
$chatData = &$project['settings']['internal_chat_v3'];
if (!isset($chatData['groups'])) $chatData['groups'] = [];
if (!isset($chatData['messages'])) $chatData['messages'] = [];

// ==========================================
// هندلر عملیات AJAX زنده پیام‌رسان
// ==========================================
if (isset($_GET['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // ۱. دریافت آخرین بروزرسانی‌های پیام‌ها و گروه‌ها (Live Polling Engine)
    if ($_GET['action'] === 'get_updates') {
        $filteredGroups = [];
        foreach ($chatData['groups'] as $grp) {
            // فقط گروه‌هایی که کاربر جاری عضو آن‌ها است نمایش داده می‌شود
            if (in_array($currentUsername, $grp['members'] ?? [])) {
                $filteredGroups[] = $grp;
            }
        }

        // شمارش یا بهبود کلاینت
        echo json_encode([
            'success' => true,
            'currentUser' => $currentUsername,
            'users' => $projectUsers,
            'groups' => $filteredGroups,
            'messages' => $chatData['messages']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۲. ارسال پیام جدید
    if ($_GET['action'] === 'send_message') {
        $input = json_decode(file_get_contents('php://input'), true);
        $text = trim($input['text'] ?? '');
        $recipient = trim($input['recipient'] ?? '');
        $recipientType = trim($input['recipient_type'] ?? 'user'); // 'user' or 'group'
        
        $fileUrl = trim($input['file_url'] ?? '');
        $fileType = trim($input['file_type'] ?? '');
        $product = isset($input['product']) && is_array($input['product']) ? $input['product'] : null;

        if (empty($text) && empty($fileUrl) && !$product) {
            echo json_encode(['success' => false, 'message' => 'پیام نمی‌تواند کاملاً خالی باشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (empty($recipient)) {
            echo json_encode(['success' => false, 'message' => 'گیرنده پیام نامشخص است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $newMsg = [
            'id' => 'msg_' . uniqid() . '_' . rand(1000, 9999),
            'sender' => $currentUsername,
            'sender_name' => $currentUserDisplay,
            'text' => $text,
            'time' => date('H:i'),
            'timestamp' => time(),
            'recipient' => $recipient,
            'recipient_type' => $recipientType,
            'file_url' => $fileUrl,
            'file_type' => $fileType,
            'product' => $product
        ];

        $chatData['messages'][] = $newMsg;
        
        // ذخیره تغییرات روی پروژه جاری
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        echo json_encode(['success' => true, 'message' => $newMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۳. آپلود امن فایل ضمیمه (تصویر یا PDF)
    if ($_GET['action'] === 'upload_file') {
        if (!isset($_FILES['chat_file'])) {
            echo json_encode(['success' => false, 'message' => 'فایلی یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $file = $_FILES['chat_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'فرمت مجاز نیست. فقط تصاویر و فایل‌های PDF مجاز هستند.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($file['size'] > 12 * 1024 * 1024) { // حداکثر ۱۲ مگابایت
            echo json_encode(['success' => false, 'message' => 'حداکثر حجم مجاز جهت ارسال فایل ۱۲ مگابایت است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $newName = 'chat_asset_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = UPLOADS_DIR . $newName;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            @chmod($dest, 0644);
            $fileUrl = 'uploads/' . $newName;
            $fileType = ($ext === 'pdf') ? 'pdf' : 'image';
            
            echo json_encode([
                'success' => true,
                'file_url' => $fileUrl,
                'resolved_url' => resolveChatUrl($fileUrl, $baseUrl),
                'file_type' => $fileType
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در بارگذاری موقت بر روی دیسک سرور.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ۴. ساخت گروه جدید
    if ($_GET['action'] === 'create_group') {
        $input = json_decode(file_get_contents('php://input'), true);
        $groupName = trim($input['name'] ?? '');
        $members = isset($input['members']) && is_array($input['members']) ? $input['members'] : [];

        if (empty($groupName)) {
            echo json_encode(['success' => false, 'message' => 'لطفاً نام گروه را مشخص کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // تضمین حضور سازنده گروه در لیست اعضا
        if (!in_array($currentUsername, $members)) {
            $members[] = $currentUsername;
        }

        $newGroupId = 'grp_' . uniqid() . '_' . rand(100, 999);
        $newGroup = [
            'id' => $newGroupId,
            'name' => $groupName,
            'members' => $members,
            'created_by' => $currentUsername,
            'created_at' => time()
        ];

        $chatData['groups'][] = $newGroup;

        // ذخیره فیزیکی روی دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        echo json_encode(['success' => true, 'group' => $newGroup], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// دریافت محصولات تایید شده پروژه جاری جهت پیوست یا اشتراک محصول درون چت
$products_list = [];
if (isset($project['products']) && is_array($project['products'])) {
    foreach ($project['products'] as $pr) {
        $st = $pr['status'] ?? 'active';
        $dec1 = $pr['stage1_decision'] ?? '';
        $dec2 = $pr['stage2_decision'] ?? '';
        if ($st !== 'rejected' && ($st === 'approved' || $st === 'active' || empty($pr['status']) || $dec1 === 'approved' || $dec2 === 'approved')) {
            $products_list[] = $pr;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پیام‌رسان و گفتگوی داخلی همکاران - <?php echo htmlspecialchars($project['name']); ?></title>
    <script src="assets/tailwindcss.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f1f5f9;
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.15);
            border-radius: 99px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.3);
        }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden bg-slate-100">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 shrink-0 px-6 py-4 flex items-center justify-between shadow-sm z-10">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-indigo-600 flex items-center justify-center text-white font-black text-xl shadow-md shadow-indigo-600/20">
                💬
            </div>
            <div>
                <h1 class="text-xs sm:text-sm font-black text-slate-800">گفتمان و تعامل داخلی همکاران</h1>
                <p class="text-[10px] text-slate-400 font-bold mt-0.5">پروژه: <?php echo htmlspecialchars($project['name']); ?></p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 text-emerald-700 text-[10px] font-black rounded-lg border border-emerald-100">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                سیستم چت فعال
            </span>
            <a href="portal.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5">
                <span>🏠</span>
                <span>بازگشت به پورتال</span>
            </a>
        </div>
    </header>

    <!-- Main Live Messenger Stage -->
    <div class="flex-1 flex overflow-hidden relative" id="messenger-app-container">

        <!-- Sidebar (User & Group Lists) -->
        <aside id="chat-sidebar" class="w-full md:w-80 border-l border-slate-200 bg-white flex flex-col h-full shrink-0 z-20 absolute md:static transition-all duration-300 transform translate-x-0">
            <!-- Navigation tab & Group creator button -->
            <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-2">
                <div class="relative w-full">
                    <input type="text" id="chat-search" oninput="filterSidebarList()" placeholder="جستجو در گفتگوها..." class="w-full text-xs font-bold bg-slate-50 border border-slate-200/80 rounded-xl pr-8 pl-3 py-2.5 outline-none focus:border-indigo-500 focus:bg-white transition-all text-slate-800">
                    <span class="absolute right-3 top-3 text-slate-400 text-xs text-right">🔍</span>
                </div>
                <button onclick="openCreateGroupModal()" class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white p-2.5 rounded-xl shadow-md transition-all font-black text-xs" title="ساخت گروه جدید">
                    👥 +
                </button>
            </div>

            <!-- Contacts Navigation Panel -->
            <div class="flex-1 overflow-y-auto" id="sidebar-contacts-box">
                <div class="px-4 py-3 text-[10px] font-black text-slate-400 bg-slate-50 border-b border-slate-100/50">گروه‌ها و کانال‌های تعاملی</div>
                <div id="groups-list-container" class="divide-y divide-slate-100/60 font-bold text-xs">
                    <!-- Dynamic Loading -->
                </div>

                <div class="px-4 py-3 text-[10px] font-black text-slate-400 bg-slate-50 border-y border-slate-100/50">اعضای تیم و همکاران سیستم</div>
                <div id="users-list-container" class="divide-y divide-slate-100/60 font-bold text-xs">
                    <!-- Dynamic Loading -->
                </div>
            </div>

            <!-- Self Info Footer inside sidebar -->
            <div class="p-4 bg-slate-50 border-t border-slate-100 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center font-black text-xs select-none">
                    <?php echo mb_substr($currentUserDisplay, 0, 1, 'utf-8'); ?>
                </div>
                <div class="overflow-hidden">
                    <div class="text-xs font-black text-slate-800 truncate"><?php echo htmlspecialchars($currentUserDisplay); ?></div>
                    <div class="text-[9px] font-bold text-indigo-600 mt-0.5 truncate">نام حساب: @<?php echo htmlspecialchars($currentUsername); ?></div>
                </div>
            </div>
        </aside>

        <!-- Message Box Panel -->
        <main class="flex-1 flex flex-col bg-slate-100/50 relative h-full">
            
            <!-- Dynamic Active Contact Header -->
            <div id="chat-header" class="px-6 py-3.5 bg-white border-b border-slate-200 shadow-sm shrink-0 flex items-center justify-between hidden">
                <div class="flex items-center gap-3">
                    <button onclick="toggleSidebarMobile(true)" class="md:hidden text-slate-500 hover:text-slate-800 text-sm ml-2">
                        ← گفتگوها
                    </button>
                    <div id="active-chat-avatar" class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-700 flex items-center justify-center font-black text-lg">
                        
                    </div>
                    <div>
                        <div id="active-chat-title" class="text-xs sm:text-sm font-black text-slate-800"></div>
                        <div id="active-chat-subtitle" class="text-[9px] font-semibold text-slate-400 mt-1"></div>
                    </div>
                </div>
                <div id="active-chat-info-btn">
                     <!-- Context buttons or status indicators -->
                </div>
            </div>

            <!-- Empty Conversation Background Placeholder -->
            <div id="chat-empty-view" class="flex-1 flex flex-col items-center justify-center p-8 text-center select-none">
                <div class="text-6xl mb-4 animate-bounce">💬</div>
                <h3 class="text-sm font-black text-slate-700">یک گفتگو را برای مانیتور و تعامل انتخاب نمایید</h3>
                <p class="text-xs text-slate-400 font-bold max-w-sm mt-2 leading-relaxed">
                    شما می‌توانید پیام‌ها، تصاویر باکیفیت، اسناد هماهنگی PDF یا محصولی لوکس را از منوی محصولات برای همکاران ارسال فرمایید
                </p>
                <button onclick="toggleSidebarMobile(false)" class="md:hidden mt-4 bg-indigo-600 hover:bg-slate-800 text-white text-xs font-black px-6 py-2.5 rounded-xl transition-all">
                    بازکردن لیست اعضا و گروه‌ها
                </button>
            </div>

            <!-- Messages Stream -->
            <div id="chat-messages-container" class="flex-1 overflow-y-auto px-6 py-6 space-y-4 hidden" style="background-image: radial-gradient(rgba(0,0,0,0.03) 1px, transparent 1px); background-size: 16px 16px;">
                <!-- Chat Bubbles Load Here -->
            </div>

            <!-- Active Input Footer Form -->
            <div id="chat-input-area" class="p-4 bg-white border-t border-slate-200 shrink-0 hidden">
                <!-- Selected File Preview Bar -->
                <div id="file-attachment-preview-bar" class="hidden mb-3 p-3 bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between gap-3 text-xs font-bold text-slate-600">
                    <span id="attached-file-info-label" class="truncate">فایل آماده ارسال</span>
                    <button onclick="clearAttachedFile()" class="text-rose-500 hover:text-rose-700">❌ انصراف</button>
                </div>

                <!-- Input box and tools layout -->
                <div class="flex items-end gap-3">
                    <div class="flex-1 flex flex-col bg-slate-50 hover:bg-slate-100/55 rounded-xl border border-slate-200/90 focus-within:border-indigo-500 focus-within:bg-white transition-all p-1.5 min-h-[45px]">
                        <textarea id="message-text-input" onkeydown="handleInputKeyPress(event)" placeholder="پیام خود را بنویسید..." rows="1" class="w-full text-xs font-bold leading-relaxed pr-3 pl-3 py-1 bg-transparent border-0 outline-none resize-none text-slate-800"></textarea>
                    </div>

                    <div class="flex gap-2 shrink-0">
                        <!-- Upload attachment button -->
                        <button onclick="triggerFileSelect()" class="p-3 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-xl transition-all border border-slate-200/90 cursor-pointer" title="ارسال عکس یا فایل PDF">
                            📎
                        </button>
                        <!-- Add product button -->
                        <button onclick="openProductSelectorModal()" class="p-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-xl transition-all border border-indigo-100 cursor-pointer text-xs font-black" title="اشتراک و ارسال کالا">
                            👕 پیوست کالا
                        </button>
                        <!-- Send action button -->
                        <button onclick="sendMessage()" class="p-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition-all shadow-md shadow-indigo-600/10 cursor-pointer text-xs font-black">
                            ارسال 🚀
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Hidden inputs -->
            <input type="file" id="file-input-field" accept="image/*,application/pdf" onchange="uploadAttachedFile()" class="hidden">
        </main>
    </div>

    <!-- Group Creator Modal -->
    <div id="create-group-modal" class="fixed inset-0 bg-slate-900/60 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-3xl w-full max-w-md p-6 shadow-xl border border-slate-100">
            <div class="flex justify-between items-center pb-4 border-b border-slate-100">
                <h3 class="text-xs sm:text-sm font-black text-slate-800">ساخت و تعریف گروه تعاملی جدید</h3>
                <button onclick="closeCreateGroupModal()" class="text-slate-400 hover:text-slate-600 text-lg font-bold">✕</button>
            </div>
            <div class="py-4 space-y-4">
                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">نام گروه</label>
                    <input type="text" id="new-group-name" placeholder="مثال: تیم طراحی کاتالوگ یا هماهنگی شعبه..." class="w-full text-xs font-bold border border-slate-200 rounded-xl p-3 bg-slate-50 focus:bg-white outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all">
                </div>
                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">انتخاب اعضای مجاز گروه (اعضا)</label>
                    <div class="max-h-52 overflow-y-auto border border-slate-150 rounded-2xl p-3 bg-slate-50/50 space-y-2.5">
                        <?php foreach ($projectUsers as $pu): if ($pu['username'] === $currentUsername) continue; ?>
                            <label class="flex items-center gap-3 p-2 hover:bg-white rounded-xl cursor-pointer transition-all border border-transparent hover:border-slate-100">
                                <input type="checkbox" name="group_members[]" value="<?php echo htmlspecialchars($pu['username']); ?>" class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded">
                                <span class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($pu['display_name'] ?? $pu['username']); ?> (@<?php echo htmlspecialchars($pu['username']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-100 flex justify-end gap-2">
                <button onclick="closeCreateGroupModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black rounded-xl transition-all">انصراف</button>
                <button onclick="submitCreateGroup()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all shadow-md">ساخت و آغاز گفتگو</button>
            </div>
        </div>
    </div>

    <!-- Product Attachment Selector Modal -->
    <div id="product-selector-modal" class="fixed inset-0 bg-slate-900/60 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-3xl w-full max-w-3xl max-h-[85vh] p-6 shadow-xl border border-slate-100 flex flex-col overflow-hidden">
            <div class="flex justify-between items-center pb-4 border-b border-slate-200">
                <h3 class="text-xs sm:text-sm font-black text-slate-800">انتخاب کالا جهت ارسال همزمان به همکاران</h3>
                <button onclick="closeProductSelectorModal()" class="text-slate-400 hover:text-slate-600 text-lg font-bold">✕</button>
            </div>
            
            <div class="py-3 border-b border-slate-100">
                <input type="text" id="product-search-input" oninput="filterProductGrid()" placeholder="جستجو بر اساس نام کالا یا مدل مپ..." class="w-full text-xs font-bold border border-slate-200/80 rounded-xl px-4 py-2.5 bg-slate-50 outline-none focus:border-indigo-500 focus:bg-white transition-all">
            </div>

            <!-- Products Grid Scrollable Container -->
            <div class="flex-1 overflow-y-auto py-4">
                <?php if (empty($products_list)): ?>
                    <div class="text-center py-12 text-slate-400 font-bold text-xs">
                        هیچ کالای تایید شده‌ای برای نمایش یافت نشد. کارهای تایید شده به کاتالوگ منتقل می‌شوند.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="products-grid-catalog">
                        <?php foreach ($products_list as $p): 
                            $pic = getPrioritizedProductImage($p); 
                            $price = $p['price'] ?? 'نامشخص';
                        ?>
                            <div class="product-item-card bg-slate-50 hover:bg-white hover:shadow-md border border-slate-150 rounded-2xl p-3 flex flex-col justify-between transition-all cursor-pointer relative group" 
                                 data-name="<?php echo htmlspecialchars($p['name']); ?>" 
                                 data-id="<?php echo htmlspecialchars($p['id']); ?>"
                                 data-price="<?php echo htmlspecialchars($price); ?>"
                                 data-pic="<?php echo htmlspecialchars(resolveChatUrl($pic, $baseUrl)); ?>">
                                <div class="h-28 w-full rounded-xl overflow-hidden bg-white border border-slate-100 flex items-center justify-center mb-2 resize-none relative">
                                    <img src="<?php echo htmlspecialchars(resolveChatUrl($pic, $baseUrl)); ?>" class="max-h-full max-w-full object-contain">
                                </div>
                                <div class="text-[10px] font-black text-slate-800 line-clamp-2 h-7 leading-relaxed overflow-hidden" title="<?php echo htmlspecialchars($p['name']); ?>">
                                    <?php echo htmlspecialchars($p['name']); ?>
                                </div>
                                <div class="text-[9px] font-black text-indigo-600 mt-2">
                                    قیمت: <?php echo htmlspecialchars($price); ?>
                                </div>
                                <button onclick="selectProductToChat('<?php echo htmlspecialchars($p['id']); ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>', '<?php echo htmlspecialchars($price); ?>', '<?php echo htmlspecialchars(resolveChatUrl($pic, $baseUrl)); ?>')" class="mt-3 w-full bg-slate-850 hover:bg-indigo-600 hover:text-white border border-slate-300 hover:border-indigo-500 text-slate-700 text-[9px] font-black py-2 rounded-xl transition-all">
                                    انتخاب و ارسال
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="pt-4 border-t border-slate-200/80 flex justify-end">
                <button onclick="closeProductSelectorModal()" class="px-6 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black rounded-xl transition-all">بستن پنجره</button>
            </div>
        </div>
    </div>


    <script>
        // دیتای بارگذاری شده سیستم
        let currentUsername = "<?php echo $currentUsername; ?>";
        let currentUserDisplay = "<?php echo $currentUserDisplay; ?>";
        let baseUrl = "<?php echo $baseUrl; ?>";

        let currentActiveRecipient = null;
        let currentRecipientType = 'user'; // 'user' or 'group'

        // لیست چت‌ها از Polling
        let allUsers = [];
        let allGroups = [];
        let allMessages = [];

        // اطلاعات فایل ضمیمه در صف ارسال
        let attachedFileUrl = '';
        let attachedFileType = ''; // 'image' or 'pdf'

        // تابع کمکی حل لینک‌ها
        function resolveImageUrl(url) {
            if (!url) return '';
            if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:') || url.startsWith('//')) {
                return url;
            }
            let cleanUrl = url;
            if (cleanUrl.startsWith('/')) {
                cleanUrl = cleanUrl.substring(1);
            }
            let base = baseUrl;
            if (base && !base.endsWith('/')) {
                base += '/';
            }
            return base + cleanUrl;
        }

        // بررسی و بازخوانی مداوم پیام‌ها (Polling Engine - 3 seconds)
        function startSyncLoop() {
            syncData();
            setInterval(syncData, 3000);
        }

        function syncData() {
            fetch('?action=get_updates')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        allUsers = data.users || [];
                        allGroups = data.groups || [];
                        allMessages = data.messages || [];

                        renderSidebarLive();
                        
                        if (currentActiveRecipient) {
                            renderActiveMessages();
                        }
                    }
                })
                .catch(err => console.error('Error syncing live chat updates:', err));
        }

        // رندر کردن اعضا و گروه‌ها در سایدبار چت
        function renderSidebarLive() {
            const groupsContainer = document.getElementById('groups-list-container');
            const usersContainer = document.getElementById('users-list-container');

            // ذخیره وضعیت اسکرول هولدرها به صورت ملایم
            let groupsHtml = '';
            let usersHtml = '';

            // الف. رندر گروه‌ها
            if (allGroups.length === 0) {
                groupsHtml = `<div class="p-4 text-center text-[10px] text-slate-400 font-bold">هیچ گروه مکالمه دو جانبه‌ای تعریف نشده است</div>`;
            } else {
                allGroups.forEach(grp => {
                    const isSelected = (currentActiveRecipient === grp.id && currentRecipientType === 'group');
                    const selectedClass = isSelected ? 'bg-indigo-50 border-r-4 border-indigo-600' : 'hover:bg-slate-50';
                    
                    // بدست آوردن آخرین پیام این چت
                    const lastMsg = getLastMessageFor('group', grp.id);
                    const lastMsgText = lastMsg ? (lastMsg.text || '📷 تصویر یا فایل ضمیمه') : 'شروع گفتگوی گروهی جدید...';
                    const lastMsgTime = lastMsg ? lastMsg.time : '';

                    groupsHtml += `
                        <div onclick="selectChat('${grp.id}', 'group', '${escapeHtml(grp.name)}')" class="p-3.5 flex items-center justify-between gap-3 cursor-pointer transition-all border-b border-slate-100 ${selectedClass}">
                            <div class="flex items-center gap-3 overflow-hidden">
                                <div class="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-700 flex items-center justify-center font-black text-sm shrink-0">
                                    👥
                                </div>
                                <div class="overflow-hidden">
                                    <div class="text-slate-800 text-xs font-black truncate">${grp.name}</div>
                                    <div class="text-[10px] text-slate-400 font-medium truncate mt-1">${lastMsgText}</div>
                                </div>
                            </div>
                            <div class="text-[9px] text-slate-400 shrink-0 font-bold">${lastMsgTime}</div>
                        </div>
                    `;
                });
            }

            // ب. رندر کاربران
            const otherUsers = allUsers.filter(u => u.username !== currentUsername);
            if (otherUsers.length === 0) {
                usersHtml = `<div class="p-4 text-center text-[10px] text-slate-400 font-bold">کاربر دیگری در این پروژه عضو نیست</div>`;
            } else {
                otherUsers.forEach(u => {
                    const isSelected = (currentActiveRecipient === u.username && currentRecipientType === 'user');
                    const selectedClass = isSelected ? 'bg-indigo-50 border-r-4 border-indigo-600' : 'hover:bg-slate-50';
                    
                    const name = u.display_name || u.username;
                    const lastMsg = getLastMessageFor('user', u.username);
                    const lastMsgText = lastMsg ? (lastMsg.text || '📷 تصویر یا فایل ضمیمه') : 'شروع گفتگوی خصوصی...';
                    const lastMsgTime = lastMsg ? lastMsg.time : '';

                    usersHtml += `
                        <div onclick="selectChat('${u.username}', 'user', '${escapeHtml(name)}')" class="p-3.5 flex items-center justify-between gap-3 cursor-pointer transition-all border-b border-slate-100 ${selectedClass}">
                            <div class="flex items-center gap-3 overflow-hidden">
                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0 select-none">
                                    ${name.substring(0, 1)}
                                </div>
                                <div class="overflow-hidden">
                                    <div class="text-slate-800 text-xs font-black truncate">${name}</div>
                                    <div class="text-[10px] text-slate-400 font-medium truncate mt-1">${lastMsgText}</div>
                                </div>
                            </div>
                            <div class="text-[9px] text-slate-400 shrink-0 font-bold">${lastMsgTime}</div>
                        </div>
                    `;
                });
            }

            groupsContainer.innerHTML = groupsHtml;
            usersContainer.innerHTML = usersHtml;
        }

        // یافتن آخرین پیام برای یک مکالمه خاص
        function getLastMessageFor(type, id) {
            let matches = [];
            if (type === 'group') {
                matches = allMessages.filter(m => m.recipient_type === 'group' && m.recipient === id);
            } else {
                matches = allMessages.filter(m => 
                    m.recipient_type === 'user' && 
                    ((m.sender === currentUsername && m.recipient === id) || (m.sender === id && m.recipient === currentUsername))
                );
            }
            if (matches.length > 0) {
                // مرتب‌سازی موقت بر حسب زمان
                return matches[matches.length - 1];
            }
            return null;
        }

        // جابجایی صفحه در دستگاه‌های موبایل
        function toggleSidebarMobile(hideSidebar) {
            const sidebar = document.getElementById('chat-sidebar');
            if (hideSidebar) {
                sidebar.classList.add('translate-x-full');
                sidebar.classList.add('hidden');
            } else {
                sidebar.classList.remove('translate-x-full');
                sidebar.classList.remove('hidden');
            }
        }

        // انتخاب و لود مکالمه جاری
        function selectChat(id, type, name) {
            currentActiveRecipient = id;
            currentRecipientType = type;

            document.getElementById('chat-empty-view').classList.add('hidden');
            document.getElementById('chat-header').classList.remove('hidden');
            document.getElementById('chat-messages-container').classList.remove('hidden');
            document.getElementById('chat-input-area').classList.remove('hidden');

            // آپدیت هدر چت
            const headerAvatar = document.getElementById('active-chat-avatar');
            headerAvatar.innerHTML = type === 'group' ? '👥' : name.substring(0, 1);
            
            document.getElementById('active-chat-title').innerText = name;
            document.getElementById('active-chat-subtitle').innerText = type === 'group' ? 'مکالمه گروهی همکاران پروژه جاری' : `گفتگوی مستقیم امن با @${id}`;
            
            // مخفی کردن سایدبار روی موبایل
            if (window.innerWidth < 768) {
                toggleSidebarMobile(true);
            }

            renderSidebarLive();
            renderActiveMessages(true); // لود پیام‌ها با اسکرول اجباری به انتها
        }

        // مانیتور اسکرولر جهت بالا نیامدن اذیت‌کننده دیزاینر در حین چت مداوم
        let previousMessagesCount = 0;

        // رندر پیام‌های مربوط به گفتگو انتخاب شده
        function renderActiveMessages(forceScroll = false) {
            const container = document.getElementById('chat-messages-container');
            
            // فیلتر کردن پیام‌ها بر اساس فرستنده و گیرنده
            let messagesToShow = [];
            if (currentRecipientType === 'group') {
                messagesToShow = allMessages.filter(m => m.recipient_type === 'group' && m.recipient === currentActiveRecipient);
            } else {
                messagesToShow = allMessages.filter(m => 
                    m.recipient_type === 'user' && 
                    ((m.sender === currentUsername && m.recipient === currentActiveRecipient) || (m.sender === currentActiveRecipient && m.recipient === currentUsername))
                );
            }

            // بهبود و رندر فیزیکی
            let bubblesHtml = '';
            
            if (messagesToShow.length === 0) {
                bubblesHtml = `
                    <div class="flex flex-col items-center justify-center p-8 text-center select-none opacity-40">
                        <span class="text-4xl mb-2">👋</span>
                        <div class="text-[11px] font-black text-slate-600">هنوز صحبتی ثبت نشده است. اولین پیام را ارسال کنید!</div>
                    </div>
                `;
            } else {
                messagesToShow.forEach(m => {
                    const isMe = (m.sender === currentUsername);
                    const bubbleBg = isMe ? 'bg-indigo-600 text-white rounded-tr-none' : 'bg-white text-slate-800 rounded-tl-none';
                    const mainLayout = isMe ? 'justify-start flex-row-reverse' : 'justify-start';
                    const timeColor = isMe ? 'text-indigo-200' : 'text-slate-400';

                    // ساختار رندر ضمیمه‌ها
                    let attachmentHtml = '';
                    if (m.file_url) {
                        const fileResolvedUrl = resolveImageUrl(m.file_url);
                        if (m.file_type === 'image') {
                            attachmentHtml = `
                                <div class="my-2 rounded-xl border border-black/5 overflow-hidden max-w-xs bg-slate-100 flex items-center justify-center">
                                    <a href="${fileResolvedUrl}" target="_blank">
                                        <img src="${fileResolvedUrl}" class="max-h-56 max-w-full object-contain hover:scale-[1.02] transition-all">
                                    </a>
                                </div>
                            `;
                        } else if (m.file_type === 'pdf') {
                            attachmentHtml = `
                                <a href="${fileResolvedUrl}" target="_blank" class="my-2 p-3 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 flex items-center gap-2.5 text-slate-700 transition-all font-black text-[10px]">
                                    <span class="text-xl">📁</span>
                                    <div class="truncate text-right">
                                        <div class="truncate">مشاهده و دانلود فابل PDF</div>
                                        <div class="text-[8px] font-bold text-slate-400 mt-0.5">کلیک جهت دریافت فیزیکی</div>
                                    </div>
                                </a>
                            `;
                        }
                    }

                    // ساختار رندر ضمیمه کالا
                    if (m.product) {
                        const prPic = resolveImageUrl(m.product.image);
                        attachmentHtml += `
                            <div class="my-2 border border-slate-150 rounded-2xl p-3 bg-slate-50 shadow-sm max-w-[200px] text-right flex flex-col justify-between">
                                <div class="h-20 w-fit bg-white border rounded-xl overflow-hidden flex items-center justify-center mb-2 mx-auto">
                                    <img src="${prPic}" class="max-h-full max-w-full object-contain">
                                </div>
                                <div class="text-[9px] font-black text-slate-700 line-clamp-2 h-7 leading-relaxed">${m.product.name}</div>
                                <div class="text-[8px] font-bold text-indigo-600 mt-1.5">قیمت: ${m.product.price}</div>
                                <a href="portal.php" target="_blank" class="mt-2.5 block text-center w-full py-1.5 bg-slate-850 hover:bg-slate-750 text-[8px] font-bold text-slate-800 rounded-lg border border-slate-200 bg-white">
                                    📦 مشاهده کالا
                                </a>
                            </div>
                        `;
                    }

                    // حباب اصلی
                    bubblesHtml += `
                        <div class="flex items-start gap-2.5 ${mainLayout}">
                            <div class="w-8 h-8 rounded-full bg-slate-200 select-none flex items-center justify-center text-xs shrink-0 font-bold text-slate-700">
                                ${m.sender_name.substring(0,1)}
                            </div>
                            <div class="max-w-[70%]">
                                <div class="text-[9px] text-slate-405 font-black mb-1 px-1">${isMe ? 'شما (همکار)' : m.sender_name}</div>
                                <div class="p-3 rounded-2xl shadow-sm text-right ${bubbleBg}">
                                    ${attachmentHtml}
                                    ${m.text ? `<p class="whitespace-pre-wrap leading-relaxed text-xs font-bold leading-normal">${escapeHtml(m.text)}</p>` : ''}
                                    <div class="text-[8px] text-left mt-1.5 font-bold ${timeColor}">${m.time}</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            container.innerHTML = bubblesHtml;

            // اسکرول زنده در صورت رد شدن از تعداد پیام قبل یا بالا رفتن آن‌ها
            if (forceScroll || messagesToShow.length > previousMessagesCount) {
                container.scrollTop = container.scrollHeight;
            }
            previousMessagesCount = messagesToShow.length;
        }

        // ثبت پیام با فشردن کلید اینتر
        function handleInputKeyPress(e) {
            if (e.keyCode === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        // ارسال پیام نهایی
        function sendMessage() {
            const inputField = document.getElementById('message-text-input');
            const text = inputField.value.trim();

            if (!text && !attachedFileUrl) {
                return; // پیامی برای ارسال نیست
            }

            const payload = {
                text: text,
                recipient: currentActiveRecipient,
                recipient_type: currentRecipientType,
                file_url: attachedFileUrl,
                file_type: attachedFileType
            };

            fetch('?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    inputField.value = '';
                    clearAttachedFile();
                    syncData();
                } else {
                    alert('خطا در ارسال پیام: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('ارتباط با سرور پیام‌رسان قطع است.');
            });
        }

        // عملکرد ضمیمه و آپلود فایل زنده
        function triggerFileSelect() {
            document.getElementById('file-input-field').click();
        }

        function uploadAttachedFile() {
            const fileField = document.getElementById('file-input-field');
            if (fileField.files.length === 0) return;

            const fd = new FormData();
            fd.append('chat_file', fileField.files[0]);

            const previewBar = document.getElementById('file-attachment-preview-bar');
            const label = document.getElementById('attached-file-info-label');
            previewBar.classList.remove('hidden');
            label.innerText = '⏳ در حال آپلود و رمزگذاری فایل روی سرور...';

            fetch('?action=upload_file', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    attachedFileUrl = data.file_url;
                    attachedFileType = data.file_type;
                    label.innerText = `📎 هماهنگ شد: ${data.file_type === 'pdf' ? 'فایل PDF سند محصول' : 'تصویر ارسال مستقیم کالا'}`;
                } else {
                    alert('آپلود ناموفق بود: ' + data.message);
                    clearAttachedFile();
                }
            })
            .catch(err => {
                console.error(err);
                alert('خطای هماهنگی اتصال با هسته آپلود.');
                clearAttachedFile();
            });
        }

        function clearAttachedFile() {
            attachedFileUrl = '';
            attachedFileType = '';
            document.getElementById('file-input-field').value = '';
            document.getElementById('file-attachment-preview-bar').classList.add('hidden');
        }

        // انتخاب و ارسال مستقیم پیوند کالا به چت
        function openProductSelectorModal() {
            document.getElementById('product-selector-modal').classList.remove('hidden');
        }

        function closeProductSelectorModal() {
            document.getElementById('product-selector-modal').classList.add('hidden');
        }

        function selectProductToChat(id, name, price, picUrl) {
            const payload = {
                text: `پیشنهاد محصول از کارتابل کاتالوگ: "${name}"`,
                recipient: currentActiveRecipient,
                recipient_type: currentRecipientType,
                product: {
                    id: id,
                    name: name,
                    price: price,
                    image: picUrl
                }
            };

            fetch('?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeProductSelectorModal();
                    syncData();
                } else {
                    alert('امتناع در ارسال پیوست کالا.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('سرور متصل نمی‌باشد.');
            });
        }

        // فیلتر کردن تصاویر در بخش انتخاب کالا
        function filterProductGrid() {
            const query = document.getElementById('product-search-input').value.toLowerCase();
            const cards = document.querySelectorAll('.product-item-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase();
                if (name.includes(query)) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        // هماهنگی ساخت گروه تعاملی همکاران
        function openCreateGroupModal() {
            document.getElementById('create-group-modal').classList.remove('hidden');
        }

        function closeCreateGroupModal() {
            document.getElementById('create-group-modal').classList.add('hidden');
        }

        function submitCreateGroup() {
            const groupNameInput = document.getElementById('new-group-name');
            const name = groupNameInput.value.trim();

            if (!name) {
                alert('لطفاً عنوان درستی برای گروه فیلتر شده قرار دهید.');
                return;
            }

            const checkboxes = document.querySelectorAll('input[name="group_members[]"]:checked');
            const members = [];
            checkboxes.forEach(cb => {
                members.push(cb.value);
            });

            const payload = {
                name: name,
                members: members
            };

            fetch('?action=create_group', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    groupNameInput.value = '';
                    checkboxes.forEach(cb => cb.checked = false);
                    closeCreateGroupModal();
                    
                    // لود مستقیم به گروه ساخته شده
                    selectChat(data.group.id, 'group', data.group.name);
                    syncData();
                } else {
                    alert('خطا در تعریف ساخت گروه: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('اختلال در برقراری شبکه با سرویس تعریف گروه.');
            });
        }

        // عملکرد سرچ در سایدبار همکاران
        function filterSidebarList() {
            const query = document.getElementById('chat-search').value.toLowerCase();
            const grpItems = document.querySelectorAll('#groups-list-container > div');
            const usrItems = document.querySelectorAll('#users-list-container > div');

            grpItems.forEach(item => {
                const text = item.innerText.toLowerCase();
                item.style.display = text.includes(query) ? '' : 'none';
            });

            usrItems.forEach(item => {
                const text = item.innerText.toLowerCase();
                item.style.display = text.includes(query) ? '' : 'none';
            });
        }

        // توابع تبدیل رشته امن
        function escapeHtml(text) {
            if (!text) return '';
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // راه‌اندازی پس از بارگذاری کل داکیومنت
        document.addEventListener('DOMContentLoaded', () => {
            startSyncLoop();
        });
    </script>
</body>
</html>
