<?php
/**
 * Maarksho Flow v3 - سیستم مدیریت تسک مرکزی و کارتابل زمان‌بندی گروهی
 * طراح و توسعه‌دهنده با رابط کاربری لوکس شبیه به بورد تلگرام/ترلو هماهنگ
 */

require_once 'inc/functions.php';
require_once 'inc/cors.php';

// بررسی دسترسی کاربر ملحق شده
if (!isUser()) {
    redirect('index.php');
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

// Project membership comes from the verified login, never username equality.
if (!SessionIdentity::select($project)) AccessControl::deny();
$currentUser = $_SESSION['user'];
$currentUsername = $currentUser['username'] ?? '';
$user_allowed_projects = array_values(array_filter($db['projects'], static function($candidate) {
    return isMainAdmin() || SessionIdentity::member($candidate);
}));

// ساخت خانه ملزومات تسک‌ها در تنظیمات اختصاصی پروژه اگر وجود نداشته باشد
if (!isset($project['settings']['task_manager_v3'])) {
    $project['settings']['task_manager_v3'] = [
        'tasks' => []
    ];
}
$taskData = &$project['settings']['task_manager_v3'];
if (!isset($taskData['tasks'])) $taskData['tasks'] = [];

// استخراج کاربران پروژه جهت نمایش در فرم انتساب تسک‌ها
$projectUsers = $project['users'] ?? [];
// افزودن ادمین سیستم به کاربرها در صورت لزوم
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

// ایجاد مپ کاربر نام نمایش برای بهینه‌سازی فرانت‌اند
$userNamesMap = [];
foreach ($projectUsers as $pu) {
    $userNamesMap[$pu['username']] = $pu['display_name'] ?? $pu['username'];
}

// ==========================================
// توابع کمکی تبدیل وضعیت و مانیتور اعلانات سیستم
// ==========================================
function getPriorityLabel($priority) {
    switch ($priority) {
        case 'high': return '🔴 بالا';
        case 'medium': return '🟡 متوسط';
        case 'low': return '🟢 پایین';
        default: return '🟡 متوسط';
    }
}

function getStatusLabel($status) {
    switch ($status) {
        case 'not_started': return '📝 شروع نشده';
        case 'in_progress': return '⏳ در حال انجام';
        case 'pending_review': return '🔍 در انتظار تایید';
        case 'completed': return '✅ تکمیل شده';
        default: return '📝 شروع نشده';
    }
}

function notifyAssignees($task, $project, $type = 'created') {
    $tgToken = $project['settings']['connections']['telegram']['token'] ?? '';
    $tgProxy = $project['settings']['connections']['telegram']['proxy'] ?? null;
    $baleToken = $project['settings']['connections']['bale']['token'] ?? '';
    $baleProxy = $project['settings']['connections']['bale']['proxy'] ?? null;
    
    // استخراج فرستنده
    $creatorName = $task['created_by_name'] ?? 'سیستم';
    
    // تنظیم پیغامی متنی و رسمی
    if ($type === 'created') {
        $msg = "📋 تسک جدید محول شد:\n";
        $msg .= "🔹 عنوان: " . $task['title'] . "\n";
        $msg .= "🔹 اولویت: " . getPriorityLabel($task['priority']) . "\n";
        $msg .= "🔹 زمانبندی: " . ($task['due_date'] ? $task['due_date'] . ' ' . ($task['due_time'] ?? '') : 'بدون زمانبندی') . "\n";
        if (!empty($task['group'])) {
            $msg .= "🔹 بخش/گروه: " . $task['group'] . "\n";
        }
        $msg .= "🔸 فرستنده: " . $creatorName . "\n";
        $msg .= "🔗 پنل مدیریت تسک مارک‌شو";
    } else if ($type === 'status_updated') {
        $msg = "🔄 بروزرسانی وضعیت تسک:\n";
        $msg .= "🔹 عنوان: " . $task['title'] . "\n";
        $msg .= "🔹 وضعیت جدید: " . getStatusLabel($task['status']) . "\n";
        $msg .= "🔸 تغییردهنده: " . $creatorName . "\n";
        $msg .= "🔗 پنل مدیریت تسک مارک‌شو";
    } else if ($type === 'subtask_progress') {
        $msg = "✅ پیشرفت در کارها:\n";
        $msg .= "🔹 تسک اصلی: " . $task['title'] . "\n";
        $msg .= "🔹 آخرین بررسی‌ها انجام شد.\n";
        $msg .= "🔗 پنل مدیریت تسک مارک‌شو";
    } else {
        $msg = "📋 اطلاعیه کارتابل تسک:\n" . $task['title'];
    }

    $assignees = $task['assignees'] ?? [];
    if (empty($assignees)) return;

    $project_users = $project['users'] ?? [];
    
    foreach ($project_users as $pu) {
        if (in_array($pu['username'], $assignees)) {
            // ۱. سامانه پیامکی
            $mobile = trim($pu['mobile'] ?? '');
            if (!empty($mobile)) {
                // ارسال اتوماتیگ از پترن ثبت شده
                @sendSmsByPattern($mobile, [$task['title'], $creatorName, $project['name'] ?? 'مارک‌شو'], $project['settings'], 'task_notification_sms');
            }

            // ۲. ربات تلگرام
            $tgChatId = trim($pu['telegram_chat_id'] ?? '');
            if (!empty($tgChatId) && !empty($tgToken)) {
                @sendToTelegram($tgToken, $tgChatId, $msg, null, $tgProxy);
            }

            // ۳. ربات بله
            $baleChatId = trim($pu['bale_chat_id'] ?? '');
            if (!empty($baleChatId) && !empty($baleToken)) {
                @sendToBale($baleToken, $baleChatId, $msg, null, $baleProxy);
            }
        }
    }
}

// ==========================================
// پردازش عملیات AJAX مدیریت تسک‌ها
// ==========================================
if (isset($_GET['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // ۱. دریافت آخرین لیست تسک‌ها
    if ($_GET['action'] === 'get_tasks') {
        echo json_encode([
            'success' => true,
            'tasks' => $taskData['tasks'],
            'users' => $projectUsers,
            'currentUser' => $currentUsername
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۲. ساخت تسک جدید
    if ($_GET['action'] === 'create_task') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $priority = trim($input['priority'] ?? 'medium');
        $group = trim($input['group'] ?? '');
        $due_date = trim($input['due_date'] ?? '');
        $due_time = trim($input['due_time'] ?? '');
        $assignees = isset($input['assignees']) && is_array($input['assignees']) ? $input['assignees'] : [];
        $subtasks_input = isset($input['subtasks']) && is_array($input['subtasks']) ? $input['subtasks'] : [];

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'عنوان تسک الزامی است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // پردازش زیرتسک‌ها
        $subtasks = [];
        foreach ($subtasks_input as $subTitle) {
            $subTitle = trim($subTitle);
            if (!empty($subTitle)) {
                $subtasks[] = [
                    'id' => 'sb_' . uniqid() . '_' . rand(100, 999),
                    'title' => $subTitle,
                    'completed' => false,
                    'assignee' => ''
                ];
            }
        }

        $newTaskId = 'tsk_' . uniqid() . '_' . rand(1000, 9999);
        $newRef = [
            'id' => $newTaskId,
            'title' => $title,
            'description' => $description,
            'created_by' => $currentUsername,
            'created_by_name' => $currentUserDisplay,
            'created_at' => time(),
            'due_date' => $due_date,
            'due_time' => $due_time,
            'group' => $group,
            'assignees' => $assignees,
            'status' => 'not_started', // default
            'priority' => $priority,
            'subtasks' => $subtasks,
            'comments' => []
        ];

        $taskData['tasks'][] = $newRef;

        // ذخیره فیزیکی دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        // ارسال اعلانات امن به کلیه افراد منتسب شده
        notifyAssignees($newRef, $project, 'created');

        echo json_encode(['success' => true, 'task' => $newRef], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۳. آپدیت وضعیت تسک
    if ($_GET['action'] === 'update_task_status') {
        $input = json_decode(file_get_contents('php://input'), true);
        $taskId = trim($input['task_id'] ?? '');
        $newStatus = trim($input['status'] ?? '');

        $foundIdx = -1;
        foreach ($taskData['tasks'] as $idx => $t) {
            if ($t['id'] === $taskId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            echo json_encode(['success' => false, 'message' => 'تسک مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $taskData['tasks'][$foundIdx]['status'] = $newStatus;
        
        // ذخیره دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        // ارسال اعلان بروزرسانی
        $updatedTask = $taskData['tasks'][$foundIdx];
        notifyAssignees($updatedTask, $project, 'status_updated');

        echo json_encode(['success' => true, 'task' => $updatedTask], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۴. تغییر وضعیت تیک زیرتسک
    if ($_GET['action'] === 'toggle_subtask') {
        $input = json_decode(file_get_contents('php://input'), true);
        $taskId = trim($input['task_id'] ?? '');
        $subtaskId = trim($input['subtask_id'] ?? '');
        $completed = (bool)($input['completed'] ?? false);

        $foundIdx = -1;
        foreach ($taskData['tasks'] as $idx => $t) {
            if ($t['id'] === $taskId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            echo json_encode(['success' => false, 'message' => 'تسک مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $subFound = false;
        if (isset($taskData['tasks'][$foundIdx]['subtasks']) && is_array($taskData['tasks'][$foundIdx]['subtasks'])) {
            foreach ($taskData['tasks'][$foundIdx]['subtasks'] as &$sub) {
                if ($sub['id'] === $subtaskId) {
                    $sub['completed'] = $completed;
                    $subFound = true;
                    break;
                }
            }
            unset($sub);
        }

        if (!$subFound) {
            echo json_encode(['success' => false, 'message' => 'کار فرعی تابعه پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ذخیره دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        // ارسال نوتیفیکیشن پیشرفت
        $updatedTask = $taskData['tasks'][$foundIdx];
        notifyAssignees($updatedTask, $project, 'subtask_progress');

        echo json_encode(['success' => true, 'task' => $updatedTask], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۵. افزودن نظر یا کامنت جدید روی کارت تسک
    if ($_GET['action'] === 'add_comment') {
        $input = json_decode(file_get_contents('php://input'), true);
        $taskId = trim($input['task_id'] ?? '');
        $commentText = trim($input['text'] ?? '');

        if (empty($commentText)) {
            echo json_encode(['success' => false, 'message' => 'متن نظر نمی‌تواند خالی باشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $foundIdx = -1;
        foreach ($taskData['tasks'] as $idx => $t) {
            if ($t['id'] === $taskId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            echo json_encode(['success' => false, 'message' => 'تسک مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!isset($taskData['tasks'][$foundIdx]['comments'])) {
            $taskData['tasks'][$foundIdx]['comments'] = [];
        }

        $newCmt = [
            'id' => 'cmt_' . uniqid() . '_' . rand(100, 999),
            'user' => $currentUsername,
            'user_display' => $currentUserDisplay,
            'text' => $commentText,
            'at' => time()
        ];

        $taskData['tasks'][$foundIdx]['comments'][] = $newCmt;

        // ذخیره دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        echo json_encode(['success' => true, 'comment' => $newCmt, 'comments' => $taskData['tasks'][$foundIdx]['comments']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۶. حذف تسک به طور کامل
    if ($_GET['action'] === 'delete_task') {
        $input = json_decode(file_get_contents('php://input'), true);
        $taskId = trim($input['task_id'] ?? '');

        $foundIdx = -1;
        foreach ($taskData['tasks'] as $idx => $t) {
            if ($t['id'] === $taskId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            echo json_encode(['success' => false, 'message' => 'تسک مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // حذف فیزیکی تسک از آرایه
        array_splice($taskData['tasks'], $foundIdx, 1);

        // ذخیره دیتابیس
        $db['projects'][$projectIdx] = $project;
        saveDB($db);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت زمانبندی و کارتابل مرکزی متمرکز - <?php echo htmlspecialchars($project['name']); ?></title>
    <script src="assets/tailwindcss.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f8fafc;
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.03);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.12);
            border-radius: 99px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.25);
        }
    </style>
<?php if (($_GET['design'] ?? '') !== 'legacy'): ?>
<link rel="stylesheet" href="/panel/assets/neo-tokens.css?v=1">
<link rel="stylesheet" href="/panel/assets/neo-modules.css?v=<?php echo filemtime(__DIR__.'/assets/neo-modules.css'); ?>">
<script src="/panel/assets/neo-modules.js?v=<?php echo filemtime(__DIR__.'/assets/neo-modules.js'); ?>" defer></script>
<?php endif; ?>
</head>
<body class="<?php echo ($_GET['design'] ?? '') !== 'legacy' ? 'neo-module neo-tasks ' : ''; ?>min-h-screen flex flex-col bg-slate-50 text-slate-800">

    <!-- Header layout matching other app sections -->
    <header class="bg-white border-b border-slate-200 shrink-0 px-6 py-4 flex items-center justify-between shadow-xs sticky top-0 z-40">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-indigo-600 flex items-center justify-center text-white font-black text-xl shadow-md shadow-indigo-600/20">
                📋
            </div>
            <div>
                <h1 class="text-xs sm:text-sm font-black text-slate-800">سیستم مدیریت تسک و تخصیص منابع</h1>
                <?php if (count($user_allowed_projects) > 1): ?>
                    <div class="flex items-center gap-1 mt-0.5">
                        <span class="text-[9px] text-slate-400 font-bold">پروژه فعال:</span>
                        <select onchange="window.location.href='?project=' + this.value" class="bg-transparent text-[10px] border-0 text-indigo-600 font-black outline-none cursor-pointer py-0 px-1 leading-none">
                            <?php foreach ($user_allowed_projects as $p_item): ?>
                                <option value="<?php echo htmlspecialchars($p_item['id']); ?>" <?php echo $p_item['id'] === $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p_item['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">پروژه فعال: <?php echo htmlspecialchars($project['name']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="openCreateTaskModal()" class="px-4 py-2 bg-indigo-600 hover:bg-slate-900 text-white text-xs font-black rounded-xl transition-all flex items-center gap-1.5 shadow-sm">
                <span>➕</span>
                <span>تسک جدید</span>
            </button>
            <a href="portal.php?project=<?php echo urlencode($project['id']); ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5">
                <span>🏠</span>
                <span>پورتال</span>
            </a>
        </div>
    </header>

    <!-- App Grid and Layout Workspace -->
    <div class="flex-1 w-full max-w-7xl mx-auto p-4 md:p-6 lg:p-8 grid grid-cols-1 lg:grid-cols-4 gap-6">
        
        <!-- Sidebar Filter controls -->
        <aside class="space-y-4 lg:col-span-1">
            
            <!-- Quick stats widgets -->
            <div class="bg-white rounded-3xl border border-slate-200/80 p-5 shadow-xs space-y-4">
                <h3 class="text-xs font-black text-slate-550 border-b border-slate-100 pb-2 flex items-center gap-2">
                    <span>📊</span>
                    <span>آمار کاتالوگ کارها</span>
                </h3>
                
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="bg-indigo-50/50 rounded-2xl p-3 border border-indigo-100/50">
                        <div id="stat-total" class="text-lg font-black text-indigo-700">0</div>
                        <div class="text-[8px] font-black text-indigo-500 mt-1">کل تسک‌ها</div>
                    </div>
                    <div class="bg-emerald-50/50 rounded-2xl p-3 border border-emerald-100/50">
                        <div id="stat-completed" class="text-lg font-black text-emerald-700">0</div>
                        <div class="text-[8px] font-black text-emerald-500 mt-1">انجام شده</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="bg-amber-50/50 rounded-2xl p-3 border border-amber-100/50">
                        <div id="stat-active" class="text-lg font-black text-amber-700">0</div>
                        <div class="text-[8px] font-black text-amber-500 mt-1">در حال انجام</div>
                    </div>
                    <div class="bg-slate-50 rounded-2xl p-3 border border-slate-200/50">
                        <div id="stat-pending" class="text-lg font-black text-slate-700">0</div>
                        <div class="text-[8px] font-black text-slate-500 mt-1">در انتظار تایید</div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Scope Filter Options -->
            <div class="bg-white rounded-3xl border border-slate-200/80 p-4 shadow-xs space-y-2">
                <h3 class="text-xs font-black text-slate-500 px-2 mb-3">نمایش بورد</h3>
                
                <button onclick="setWorkspaceScope('all')" id="scope-btn-all" class="w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 bg-indigo-600 text-white">
                    <span>📋</span>
                    <span>کل تسک‌های پروژه</span>
                </button>
                
                <button onclick="setWorkspaceScope('personal')" id="scope-btn-personal" class="w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 hover:bg-slate-50 text-slate-700">
                    <span>👤</span>
                    <span>تسک‌های واگذار شده به من</span>
                    <span id="personal-tasks-badge" class="mr-auto text-[8px] bg-red-500 text-white px-2 py-0.5 rounded-full hidden">۰</span>
                </button>
            </div>

            <!-- Quick Priority Filters -->
            <div class="bg-white rounded-3xl border border-slate-200/80 p-4 shadow-xs space-y-2">
                <h3 class="text-xs font-black text-slate-500 px-2 mb-3">فیلتر اولویت</h3>
                <div class="flex flex-wrap gap-1.5 p-1">
                    <button onclick="setPriorityFilter('all')" class="priority-btn px-2.5 py-1.5 rounded-xl text-[9px] font-black border border-slate-200 bg-slate-100 text-slate-800 transition-all">همه</button>
                    <button onclick="setPriorityFilter('high')" class="priority-btn px-2.5 py-1.5 rounded-xl text-[9px] font-black border border-transparent bg-rose-50 text-rose-750 hover:bg-rose-100 transition-all">🔴 بالا</button>
                    <button onclick="setPriorityFilter('medium')" class="priority-btn px-2.5 py-1.5 rounded-xl text-[9px] font-black border border-transparent bg-amber-50 text-amber-700 hover:bg-amber-100 transition-all">🟡 متوسط</button>
                    <button onclick="setPriorityFilter('low')" class="priority-btn px-2.5 py-1.5 rounded-xl text-[9px] font-black border border-transparent bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-all">🟢 پایین</button>
                </div>
            </div>

            <!-- Team Members directory reference -->
            <div class="bg-white rounded-3xl border border-slate-200/80 p-4 shadow-xs">
                <h3 class="text-xs font-black text-slate-500 px-2 mb-3">اعضای تیم همکار در پروژه</h3>
                <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                    <?php foreach ($projectUsers as $u): ?>
                        <div class="flex items-center gap-2.5 p-2 bg-slate-50/50 rounded-xl hover:bg-slate-100/50 transition-all border border-slate-100">
                            <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center font-black text-[9px] select-none shrink-0">
                                <?php echo mb_substr($u['display_name'] ?? $u['username'], 0, 1, 'utf-8'); ?>
                            </div>
                            <div class="overflow-hidden">
                                <p class="text-[10px] font-black text-slate-700 truncate"><?php echo htmlspecialchars($u['display_name'] ?? $u['username']); ?></p>
                                <p class="text-[8px] text-slate-400 font-bold truncate">@<?php echo htmlspecialchars($u['username']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <!-- Dynamic Boards Columns Stage -->
        <main class="lg:col-span-3 space-y-6">
            
            <!-- Quick text filter & grouping bar -->
            <div class="bg-white rounded-3xl border border-slate-200/80 p-4 shadow-xs flex flex-col md:flex-row items-center gap-3">
                <div class="relative w-full">
                    <input type="text" id="task-search" oninput="renderBoardsDirect()" placeholder="جستجو تسک‌ها بر اساس عنوان، گروه یا توصیفات..." class="w-full text-xs font-bold bg-slate-50 border border-slate-200 rounded-2xl pr-8 pl-3 py-2.5 outline-none focus:border-indigo-500 focus:bg-white transition-all text-slate-800">
                    <span class="absolute right-3 top-3 text-slate-400 text-xs">🔍</span>
                </div>
                <div class="flex gap-2 w-full md:w-auto shrink-0">
                    <select id="group-filter-select" onchange="renderBoardsDirect()" class="text-xs font-black bg-slate-50 border border-slate-200 rounded-2xl px-4 py-2.5 outline-none text-slate-750">
                        <option value="all">همه گروه‌ها / بخش‌ها</option>
                    </select>
                </div>
            </div>

            <!-- Columns Layout -->
            <div class="neo-task-board grid grid-cols-1 md:grid-cols-4 gap-4 h-full align-start">
                
                <!-- Column 1: Not Started -->
                <div class="bg-slate-100/80 p-3.5 rounded-3xl border border-slate-200/50 flex flex-col h-full min-h-[500px]">
                    <div class="flex items-center justify-between mb-3.5 px-1 shrink-0">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                            <span class="text-xs font-black text-slate-700">شروع نشده</span>
                        </div>
                        <span id="badge-not_started" class="bg-slate-200 text-slate-605 text-[9px] font-black px-2 py-0.5 rounded-full">0</span>
                    </div>
                    <div id="col-not_started" class="space-y-3 flex-1 overflow-y-auto">
                        <!-- Tasks go here -->
                    </div>
                </div>

                <!-- Column 2: In Progress -->
                <div class="bg-slate-100/80 p-3.5 rounded-3xl border border-slate-200/50 flex flex-col h-full min-h-[500px]">
                    <div class="flex items-center justify-between mb-3.5 px-1 shrink-0">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                            <span class="text-xs font-black text-slate-700">در حال انجام</span>
                        </div>
                        <span id="badge-in_progress" class="bg-amber-100 text-amber-700 text-[9px] font-black px-2 py-0.5 rounded-full">0</span>
                    </div>
                    <div id="col-in_progress" class="space-y-3 flex-1 overflow-y-auto">
                        <!-- Tasks go here -->
                    </div>
                </div>

                <!-- Column 3: Pending Review -->
                <div class="bg-slate-100/80 p-3.5 rounded-3xl border border-slate-200/50 flex flex-col h-full min-h-[500px]">
                    <div class="flex items-center justify-between mb-3.5 px-1 shrink-0">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                            <span class="text-xs font-black text-slate-700">در انتظار تایید</span>
                        </div>
                        <span id="badge-pending_review" class="bg-sky-100 text-sky-700 text-[9px] font-black px-2 py-0.5 rounded-full">0</span>
                    </div>
                    <div id="col-pending_review" class="space-y-3 flex-1 overflow-y-auto">
                        <!-- Tasks go here -->
                    </div>
                </div>

                <!-- Column 4: Completed -->
                <div class="bg-slate-100/80 p-3.5 rounded-3xl border border-slate-200/50 flex flex-col h-full min-h-[500px]">
                    <div class="flex items-center justify-between mb-3.5 px-1 shrink-0">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span class="text-xs font-black text-slate-700">کامل شده</span>
                        </div>
                        <span id="badge-completed" class="bg-emerald-100 text-emerald-700 text-[9px] font-black px-2 py-0.5 rounded-full">0</span>
                    </div>
                    <div id="col-completed" class="space-y-3 flex-1 overflow-y-auto">
                        <!-- Tasks go here -->
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- Create Task Modal -->
    <div id="create-task-modal" class="fixed inset-0 bg-slate-950/60 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-3xl w-full max-w-xl max-h-[90vh] p-6 shadow-xl border border-slate-100 flex flex-col overflow-hidden">
            
            <div class="flex justify-between items-center pb-4 border-b border-slate-100 shrink-0">
                <h3 class="text-xs sm:text-sm font-black text-slate-800">تعریف و واگذاری تسک گروهی جدید</h3>
                <button onclick="closeCreateTaskModal()" class="text-slate-400 hover:text-slate-600 text-lg font-bold">✕</button>
            </div>
            
            <div class="flex-1 overflow-y-auto py-4 space-y-4 pr-1">
                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">عنوان تسک کار *</label>
                    <input type="text" id="new-task-title" placeholder="مثال: طراحی مجدد فرم‌های سایز یا فلو اسکراپینگ..." class="w-full text-xs font-bold border border-slate-200 rounded-2xl p-3 bg-slate-50 focus:bg-white outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all">
                </div>

                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">توضیحات و دستورالعمل پیگیری</label>
                    <textarea id="new-task-description" rows="3" placeholder="مراحل انجام و جزئیات چگونگی پیاده‌سازی..." class="w-full text-xs font-bold border border-slate-200 rounded-2xl p-3 bg-slate-50 focus:bg-white outline-none resize-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-black text-slate-500 mb-2">اولویت</label>
                        <select id="new-task-priority" class="w-full text-xs font-black border border-slate-200 rounded-xl p-2.5 bg-slate-50 outline-none">
                            <option value="low">🟢 پایین</option>
                            <option value="medium" selected>🟡 متوسط</option>
                            <option value="high">🔴 بالا</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-500 mb-2">بخش / گروه کاری</label>
                        <input type="text" id="new-task-group" placeholder="مثال: تولید محتوا، برنامه نویسی..." class="w-full text-xs font-bold border border-slate-200 rounded-xl p-2.5 bg-slate-50 outline-none">
                    </div>
                </div>

                <!-- Scheduling Block (With/Without timing toggler) -->
                <div class="border border-slate-200/60 rounded-2xl p-4 bg-slate-50/50 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-[11px] font-black text-slate-600 flex items-center gap-1.5">
                            📅 زمانبندی و مهلت تحویل کار
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer text-[10px] font-black text-slate-500 select-none">
                            <input type="checkbox" id="no-deadline-toggle" onchange="toggleDeadlineFields()" class="rounded border-slate-300 text-indigo-600">
                            بدون زمانبندی ارسالی
                        </label>
                    </div>
                    <div id="deadline-fields-grid" class="grid grid-cols-2 gap-3 transition-opacity">
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 mb-1 block">تاریخ سررسید (میلادی/شمسی)</span>
                            <input type="date" id="new-task-due-date" class="w-full text-xs font-bold border border-slate-200 rounded-xl p-2 bg-white outline-none">
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 mb-1 block">ساعت سررسید</span>
                            <input type="time" id="new-task-due-time" class="w-full text-xs font-bold border border-slate-200 rounded-xl p-2 bg-white outline-none">
                        </div>
                    </div>
                </div>

                <!-- Assignees select -->
                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">انتساب کار به همکاران در پروژه</label>
                    <div class="max-h-36 overflow-y-auto border border-slate-200 rounded-2xl p-3 bg-slate-50/50 space-y-2">
                        <?php foreach ($projectUsers as $u): ?>
                            <label class="flex items-center gap-2.5 p-1.5 hover:bg-white rounded-xl cursor-pointer transition-all">
                                <input type="checkbox" name="assignees[]" value="<?php echo htmlspecialchars($u['username']); ?>" class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded">
                                <span class="text-xs font-bold text-slate-705"><?php echo htmlspecialchars($u['display_name'] ?? $u['username']); ?> (@<?php echo htmlspecialchars($u['username']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Sub-tasks / Split items during creation -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="text-[11px] font-black text-slate-500">تقسیم به خرده‌کارها (زیرتسک‌ها)</label>
                        <button type="button" onclick="addNewSubtaskField()" class="text-[10px] font-black text-indigo-600 hover:text-indigo-850">➕ بخش جدید</button>
                    </div>
                    <div id="subtasks-creation-container" class="space-y-2">
                        <!-- Dynamic inputs -->
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 shrink-0 flex justify-end gap-2">
                <button onclick="closeCreateTaskModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black rounded-xl transition-all">انصراف</button>
                <button onclick="submitCreateTask()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all shadow-md">تایید و ارسال کارتابل</button>
            </div>

        </div>
    </div>

    <!-- View & Manage Task Detail Drawer/Modal -->
    <div id="task-detail-modal" class="fixed inset-0 bg-slate-950/60 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] p-6 shadow-xl border border-slate-100 flex flex-col overflow-hidden">
            
            <div class="flex justify-between items-center pb-4 border-b border-slate-1.5 shrink-0">
                <div class="flex items-center gap-2">
                    <span id="detail-task-priority-badge" class="text-[8px] font-black px-2 py-0.5 rounded-md"></span>
                    <h3 id="detail-task-title" class="text-xs sm:text-sm font-black text-slate-800">ویرایش تسک</h3>
                </div>
                <button onclick="closeTaskDetailModal()" class="text-slate-400 hover:text-slate-600 text-lg font-bold">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto py-5 space-y-4 pr-1">
                
                <!-- Desc box -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-150">
                    <p class="text-[10px] font-bold text-slate-400 mb-1">توضیحات و راهنما</p>
                    <p id="detail-task-desc" class="text-xs font-bold text-slate-700 leading-relaxed whitespace-pre-wrap"></p>
                </div>

                <!-- Assignment Metadata grid -->
                <div class="grid grid-cols-2 gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 mb-1.5">واگذارشده به همکاران:</p>
                        <div id="detail-task-assignees" class="flex flex-wrap gap-1">
                            <!-- assignees pills -->
                        </div>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 mb-1.5">اطلاعات زمانبندی:</p>
                        <div id="detail-task-deadline-badge" class="text-[10px] font-medium text-slate-60) bg-slate-100 h-fit w-fit px-2.5 py-1 rounded-xl">
                            
                        </div>
                    </div>
                </div>

                <!-- Live Status action tools -->
                <div>
                    <label class="block text-[11px] font-black text-slate-500 mb-2">بروزرسانی وضعیت کار</label>
                    <div class="grid grid-cols-4 gap-2">
                        <button onclick="updateActiveTaskStatus('not_started')" id="status-opt-not_started" class="text-[9px] font-black py-2 rounded-xl transition-all border border-slate-200 hover:bg-slate-50">📝 شروع نشده</button>
                        <button onclick="updateActiveTaskStatus('in_progress')" id="status-opt-in_progress" class="text-[9px] font-black py-2 rounded-xl transition-all border border-slate-200 hover:bg-slate-50">⏳ در حال انجام</button>
                        <button onclick="updateActiveTaskStatus('pending_review')" id="status-opt-pending_review" class="text-[9px] font-black py-2 rounded-xl transition-all border border-slate-200 hover:bg-slate-50">🔍 انتظار تایید</button>
                        <button onclick="updateActiveTaskStatus('completed')" id="status-opt-completed" class="text-[9px] font-black py-2 rounded-xl transition-all border border-slate-200 hover:bg-slate-50">✅ انجام شد</button>
                    </div>
                </div>

                <!-- Interactive Subtasks List -->
                <div>
                    <h4 class="text-xs font-black text-slate-800 mb-2.5 flex items-center justify-between">
                        <span>🧩 شکستن کار (زیرتسک‌ها):</span>
                        <span id="detail-subtask-progress-label" class="text-[10px] text-slate-400 font-bold">۰ از ۰</span>
                    </h4>
                    <div id="detail-subtasks-container" class="space-y-2 max-h-40 overflow-y-auto">
                        <!-- Dynamic subtask list -->
                    </div>
                </div>

                <!-- Comments & Action History System -->
                <div class="border-t border-slate-100 pt-4 space-y-3.5">
                    <h4 class="text-xs font-black text-slate-800 flex items-center gap-1">
                        <span>💬</span>
                        <span>مکالمه و لاگ تغییرات تسک</span>
                    </h4>
                    
                    <!-- Comments scroller -->
                    <div id="detail-comments-container" class="space-y-2 max-h-52 overflow-y-auto pr-1">
                        <!-- Comments bubbles -->
                    </div>

                    <!-- Input for new comment -->
                    <div class="flex gap-2">
                        <input type="text" id="new-comment-input" placeholder="نوشتن یادداشت یا لاگ هماهنگی بر روی تسک..." class="flex-1 text-xs font-bold border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:bg-white outline-none">
                        <button onclick="submitNewComment()" class="px-4 py-2 bg-indigo-650 hover:bg-indigo-700 bg-indigo-600 text-white font-black text-xs rounded-xl transition-all shadow-xs">ارسال نظر</button>
                    </div>
                </div>

            </div>

            <div class="pt-4 border-t border-slate-150 shrink-0 flex justify-between items-center">
                <button onclick="deleteActiveTask()" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-black rounded-xl transition-all flex items-center gap-1.5 border border-rose-200">
                    <span>🗑️</span>
                    <span>حذف کامل تسک</span>
                </button>
                <button onclick="closeTaskDetailModal()" class="px-6 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black rounded-xl transition-all">بستن کارتابل</button>
            </div>
            
        </div>
    </div>


    <script>
        // دیتای لود شده سراسری پروژه
        let currentUsername = "<?php echo $currentUsername; ?>";
        let userDisplayMap = <?php echo json_encode($userNamesMap, JSON_UNESCAPED_UNICODE); ?>;

        let allTasks = [];
        let allUsers = [];
        
        let workspaceScope = 'all'; // 'all' or 'personal'
        let priorityFilter = 'all'; // 'all', 'high', 'medium', 'low'
        
        let activeTaskId = null; // تسک انتخاب شده جهت ویرایش و لاگ

        // اجرای اولیه پولینگ سیستم
        window.onload = function() {
            syncTasks();
            setInterval(syncTasks, 4000);
        };

        // بازخوانی زنده داده‌ها
        function syncTasks() {
            fetch('?action=get_tasks')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        allTasks = data.tasks || [];
                        allUsers = data.users || [];
                        
                        populateFiltersGroupMenu();
                        renderBoardsDirect();
                        updateAggregatedStats();
                        
                        if (activeTaskId) {
                            // بازخوانی جزئیات فرعی تسک باز شده به صورت زنده
                            const freshTask = allTasks.find(t => t.id === activeTaskId);
                            if (freshTask) {
                                renderTaskDetailContent(freshTask);
                            }
                        }
                    }
                })
                .catch(err => console.error("Error Polling Task Manager:", err));
        }

        // پر کردن فیلترهای گروه‌بندی بر مبنای مقادیر موجود در لوپ
        function populateFiltersGroupMenu() {
            const selectEl = document.getElementById('group-filter-select');
            const savedSelectedValue = selectEl.value;

            // استخراج گروه‌ها
            let uniqueGroups = new Set();
            allTasks.forEach(t => {
                if (t.group && t.group.trim()) uniqueGroups.add(t.group.trim());
            });

            let html = '<option value="all">همه گروه‌ها / بخش‌ها</option>';
            uniqueGroups.forEach(g => {
                html += `<option value="${g}">${g}</option>`;
            });

            selectEl.innerHTML = html;
            selectEl.value = savedSelectedValue;
        }

        // تغییر افق دید بورد (تسک‌های من یا کل پروژه)
        function setWorkspaceScope(scope) {
            workspaceScope = scope;
            
            const btnAll = document.getElementById('scope-btn-all');
            const btnPersonal = document.getElementById('scope-btn-personal');

            if (scope === 'all') {
                btnAll.className = "w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 bg-indigo-600 text-white";
                btnPersonal.className = "w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 hover:bg-slate-50 text-slate-700";
            } else {
                btnAll.className = "w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 hover:bg-slate-50 text-slate-700";
                btnPersonal.className = "w-full text-right px-4 py-2.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 bg-indigo-600 text-white";
            }

            renderBoardsDirect();
        }

        function setPriorityFilter(priority) {
            priorityFilter = priority;
            
            document.querySelectorAll('.priority-btn').forEach(btn => {
                btn.classList.add('border-transparent');
                btn.classList.remove('border-slate-300', 'shadow-xs');
            });
            event.currentTarget.classList.remove('border-transparent');
            event.currentTarget.classList.add('border-slate-300', 'shadow-xs');

            renderBoardsDirect();
        }

        // رندر و چینش زنده تسک‌ها بر روی ستون‌ها
        function renderBoardsDirect() {
            const searchVal = document.getElementById('task-search').value.toLowerCase().trim();
            const groupVal = document.getElementById('group-filter-select').value;

            // لیست‌های ستون‌ها
            const columns = {
                'not_started': document.getElementById('col-not_started'),
                'in_progress': document.getElementById('col-in_progress'),
                'pending_review': document.getElementById('col-pending_review'),
                'completed': document.getElementById('col-completed')
            };

            const badges = {
                'not_started': document.getElementById('badge-not_started'),
                'in_progress': document.getElementById('badge-in_progress'),
                'pending_review': document.getElementById('badge-pending_review'),
                'completed': document.getElementById('badge-completed')
            };

            // پاکسازی اولیه کل ستون‌ها
            let colHtmls = { 'not_started': '', 'in_progress': '', 'pending_review': '', 'completed': '' };
            let colCounts = { 'not_started': 0, 'in_progress': 0, 'pending_review': 0, 'completed': 0 };

            allTasks.forEach(t => {
                // ۱. فیلتر نمایش شخصی vs همه
                if (workspaceScope === 'personal') {
                    const assignees = t.assignees || [];
                    if (!assignees.includes(currentUsername)) return;
                }

                // ۲. فیلتر اولویت
                if (priorityFilter !== 'all' && t.priority !== priorityFilter) return;

                // ۳. فیلتر متنی جستجو
                if (searchVal) {
                    const titleMatch = t.title ? t.title.toLowerCase().includes(searchVal) : false;
                    const descMatch = t.description ? t.description.toLowerCase().includes(searchVal) : false;
                    const grpMatch = t.group ? t.group.toLowerCase().includes(searchVal) : false;
                    if (!titleMatch && !descMatch && !grpMatch) return;
                }

                // ۴. فیلتر گروه انتخابی
                if (groupVal !== 'all' && t.group !== groupVal) return;

                // محاسبه خرده تسک‌ها
                const subsArr = t.subtasks || [];
                const doneSubs = subsArr.filter(s => s.completed).length;
                const totalSubs = subsArr.length;
                
                // اولویت یاب رنگی
                let pBadgeColor = 'bg-slate-100 text-slate-700';
                let pLabel = 'متوسط';
                if (t.priority === 'high') { pBadgeColor = 'bg-rose-50 text-rose-750'; pLabel = '🔴 بالا'; }
                else if (t.priority === 'medium') { pBadgeColor = 'bg-amber-50 text-amber-705'; pLabel = '🟡 متوسط'; }
                else if (t.priority === 'low') { pBadgeColor = 'bg-emerald-50 text-emerald-705'; pLabel = '🟢 پایین'; }

                // فرم پرداز تاریخ مهلت تحویل
                let deadlineHtml = '';
                if (t.due_date) {
                    deadlineHtml = `
                        <div class="flex items-center gap-1 text-[8px] font-black text-rose-500 bg-rose-50/50 px-2 py-1 rounded-md">
                            <span>⏱️:</span>
                            <span>${t.due_date} ${t.due_time || ''}</span>
                        </div>
                    `;
                } else {
                    deadlineHtml = `
                        <div class="text-[8px] font-black text-slate-400 bg-slate-50 px-2 py-1 rounded-md">بدون زمانبندی</div>
                    `;
                }

                // انتساب دهنده ها
                let assigneesHtml = '';
                if (t.assignees && t.assignees.length > 0) {
                    t.assignees.forEach(userUid => {
                        const displayName = userDisplayMap[userUid] || userUid;
                        assigneesHtml += `
                            <span class="w-5 h-5 rounded-full bg-indigo-50 border border-indigo-150 text-[8px] text-indigo-700 font-black flex items-center justify-center shrink-0 select-none" title="${displayName} (@${userUid})">
                                ${displayName.substring(0, 1)}
                            </span>
                        `;
                    });
                }

                let subProgressHtml = '';
                if (totalSubs > 0) {
                    subProgressHtml = `
                        <div class="mt-2.5 space-y-1">
                            <div class="flex justify-between items-center text-[8px] font-black text-slate-400">
                                <span>زیرتسک‌ها و بخش‌ها</span>
                                <span>${doneSubs} از ${totalSubs}</span>
                            </div>
                            <div class="w-full bg-slate-100 h-1 rounded-full overflow-hidden">
                                <div class="bg-indigo-600 h-1 rounded-full transition-all" style="width: ${(doneSubs / totalSubs) * 100}%"></div>
                            </div>
                        </div>
                    `;
                }

                const statusVal = t.status || 'not_started';
                
                colHtmls[statusVal] += `
                    <div onclick="openTaskDetailModal('${t.id}')" class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs hover:shadow-sm hover:scale-[1.01] transition-all duration-300 cursor-pointer space-y-3 relative group">
                        
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[8px] font-black px-2 py-0.5 rounded-lg ${pBadgeColor}">${pLabel}</span>
                            ${t.group ? `<span class="text-[8px] font-black text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded-md truncate max-w-[100px]">${t.group}</span>` : ''}
                        </div>

                        <div>
                            <h4 class="text-[11px] font-black text-slate-800 leading-normal line-clamp-2">${escapeHtml(t.title)}</h4>
                            <p class="text-[9px] font-bold text-slate-400 line-clamp-2 mt-1.5 leading-relaxed">${escapeHtml(t.description || 'بدون توضیحات اضافی...')}</p>
                        </div>

                        ${subProgressHtml}

                        <hr class="border-slate-100 my-1">

                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1 overflow-hidden">
                                ${assigneesHtml}
                            </div>
                            
                            ${deadlineHtml}
                        </div>
                    </div>
                `;
                
                colCounts[statusVal]++;
            });

            // بروزرسانی فیزیکی HTML بوردها
            Object.keys(columns).forEach(status => {
                if (colCounts[status] === 0) {
                    columns[status].innerHTML = `
                        <div class="text-center py-10 opacity-30 select-none">
                            <span class="text-2xl block mb-1">📭</span>
                            <span class="text-[9px] font-black text-slate-500">کاری تعریف نگردیده</span>
                        </div>
                    `;
                } else {
                    columns[status].innerHTML = colHtmls[status];
                }
                badges[status].innerText = colCounts[status];
            });
        }

        // بروزرسانی آمارهای تجمعی در پنل سمت راست سایدبار
        function updateAggregatedStats() {
            document.getElementById('stat-total').innerText = allTasks.length;
            
            const completed = allTasks.filter(t => t.status === 'completed').length;
            const inProgress = allTasks.filter(t => t.status === 'in_progress').length;
            const pending = allTasks.filter(t => t.status === 'pending_review').length;
            
            document.getElementById('stat-completed').innerText = completed;
            document.getElementById('stat-active').innerText = inProgress;
            document.getElementById('stat-pending').innerText = pending;

            // تسک‌های شخصی من
            const personalCount = allTasks.filter(t => (t.assignees || []).includes(currentUsername)).length;
            const b = document.getElementById('personal-tasks-badge');
            if (personalCount > 0) {
                b.innerText = personalCount;
                b.classList.remove('hidden');
            } else {
                b.classList.add('hidden');
            }
        }

        // عملکرد ساخت تسک جدید
        function openCreateTaskModal() {
            document.getElementById('create-task-modal').classList.remove('hidden');
            document.getElementById('subtasks-creation-container').innerHTML = ''; // ریست
            addNewSubtaskField(); // بخش اول به صورت پیش‌فرض
        }

        function closeCreateTaskModal() {
            document.getElementById('create-task-modal').classList.add('hidden');
            // پاکسازی فیلدها
            document.getElementById('new-task-title').value = '';
            document.getElementById('new-task-description').value = '';
            document.getElementById('new-task-priority').value = 'medium';
            document.getElementById('new-task-group').value = '';
            document.getElementById('new-task-due-date').value = '';
            document.getElementById('new-task-due-time').value = '';
            document.getElementById('no-deadline-toggle').checked = false;
            toggleDeadlineFields();
            
            document.querySelectorAll('input[name="assignees[]"]').forEach(cb => cb.checked = false);
        }

        function toggleDeadlineFields() {
            const val = document.getElementById('no-deadline-toggle').checked;
            const div = document.getElementById('deadline-fields-grid');
            if (val) {
                div.style.opacity = '0.4';
                div.style.pointerEvents = 'none';
                document.getElementById('new-task-due-date').value = '';
                document.getElementById('new-task-due-time').value = '';
            } else {
                div.style.opacity = '1';
                div.style.pointerEvents = 'auto';
            }
        }

        // بخش جدید به خرده‌تسک‌ها اضافه کن
        function addNewSubtaskField() {
            const container = document.getElementById('subtasks-creation-container');
            const placeholder = `مثال: پیگیری هماهنگی شعبه یا بازنویسی...`;
            
            const div = document.createElement('div');
            div.className = "flex gap-2 items-center";
            div.innerHTML = `
                <input type="text" name="subtasks_desc[]" placeholder="${placeholder}" class="flex-1 text-xs font-bold border border-slate-200 rounded-xl px-3 py-1.5 bg-slate-50/50 outline-none">
                <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 font-bold p-1">✕</button>
            `;
            container.appendChild(div);
        }

        // ساب میت نهایی فرم تسک جدید
        function submitCreateTask() {
            const title = document.getElementById('new-task-title').value.trim();
            const description = document.getElementById('new-task-description').value.trim();
            const priority = document.getElementById('new-task-priority').value;
            const group = document.getElementById('new-task-group').value.trim();
            const due_date = document.getElementById('new-task-due-date').value;
            const due_time = document.getElementById('new-task-due-time').value;

            if (!title) {
                alert('لطفاً عنوان تسک را بنویسید.');
                return;
            }

            // افراد انتساب شده
            const assignees = [];
            document.querySelectorAll('input[name="assignees[]"]:checked').forEach(cb => {
                assignees.push(cb.value);
            });

            // خرده تسک‌ها
            const subtasks = [];
            document.querySelectorAll('input[name="subtasks_desc[]"]').forEach(inp => {
                const text = inp.value.trim();
                if (text) subtasks.push(text);
            });

            const payload = {
                title: title,
                description: description,
                priority: priority,
                group: group,
                due_date: due_date,
                due_time: due_time,
                assignees: assignees,
                subtasks: subtasks
            };

            const btn = document.querySelector('button[onclick="submitCreateTask()"]');
            btn.innerText = '⏱️ در حال بررسی و تخصیص...';
            btn.disabled = true;

            fetch('?action=create_task', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btn.innerText = 'تایید و ارسال کارتابل';
                btn.disabled = false;
                
                if (data.success) {
                    closeCreateTaskModal();
                    syncTasks();
                    alert('✅ تسک با موفقیت تخصیص یافت و اعلانات با کانال‌های انتخابی هماهنگ گردید.');
                } else {
                    alert('مشکلی وجود دارد: ' + data.message);
                }
            })
            .catch(err => {
                btn.innerText = 'تایید و ارسال کارتابل';
                btn.disabled = false;
                console.error(err);
                alert('بروز خطا در برقراری ارتباط با پورت تسک پایگاه داده.');
            });
        }

        // عملکرد لود و نمایش کامل جزئیات تسک در دراور
        function openTaskDetailModal(id) {
            activeTaskId = id;
            document.getElementById('task-detail-modal').classList.remove('hidden');
            
            const task = allTasks.find(t => t.id === id);
            if (task) {
                renderTaskDetailContent(task);
            }
        }

        function closeTaskDetailModal() {
            activeTaskId = null;
            document.getElementById('task-detail-modal').classList.add('hidden');
            document.getElementById('new-comment-input').value = '';
        }

        // رندر کامل داخلی محتوای تسک در مودال ویرایش
        function renderTaskDetailContent(t) {
            document.getElementById('detail-task-title').innerText = t.title;
            document.getElementById('detail-task-desc').innerText = t.description || 'توضیحات و راهنمایی برای این تسک ثبت نشده است.';
            
            // اولویت
            const badge = document.getElementById('detail-task-priority-badge');
            let pLabel = 'متوسط';
            if (t.priority === 'high') {
                badge.innerText = '🔴 بالا';
                badge.className = 'text-[8px] font-black px-2 py-0.5 rounded-md bg-rose-50 text-rose-750';
            } else if (t.priority === 'medium') {
                badge.innerText = '🟡 متوسط';
                badge.className = 'text-[8px] font-black px-2 py-0.5 rounded-md bg-amber-50 text-amber-705';
            } else if (t.priority === 'low') {
                badge.innerText = '🟢 پایین';
                badge.className = 'text-[8px] font-black px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-705';
            }

            // زمانبندی
            const deadlineBadge = document.getElementById('detail-task-deadline-badge');
            if (t.due_date) {
                deadlineBadge.innerHTML = `<span>📅 مهلت تحویل:</span> <span class="font-black text-rose-500">${t.due_date} ${t.due_time || ''}</span>`;
            } else {
                deadlineBadge.innerHTML = 'بدون مهلت تحویل و زمانبندی';
            }

            // انتساب‌ها
            const assigneesCont = document.getElementById('detail-task-assignees');
            let assignPills = '';
            if (t.assignees && t.assignees.length > 0) {
                t.assignees.forEach(uid => {
                    const disp = userDisplayMap[uid] || uid;
                    assignPills += `<span class="bg-indigo-50 border border-indigo-150 text-[10px] text-indigo-700 font-bold px-2 py-1 rounded-xl shrink-0">@${uid} (${disp})</span>`;
                });
            } else {
                assignPills = '<span class="text-[10px] font-medium text-slate-400">به هیچ مجری منتسب نگردیده</span>';
            }
            assigneesCont.innerHTML = assignPills;

            // اکتیو نگه داشتن دکمه‌های وضعیت تسک
            const optsStatus = ['not_started', 'in_progress', 'pending_review', 'completed'];
            optsStatus.forEach(st => {
                const btn = document.getElementById(`status-opt-${st}`);
                if (t.status === st) {
                    btn.className = "text-[9px] font-black py-2 rounded-xl transition-all border border-indigo-500 bg-indigo-50 text-indigo-700 shadow-xs";
                } else {
                    btn.className = "text-[9px] font-black py-2 rounded-xl transition-all border border-slate-200 hover:bg-slate-50 text-slate-600 bg-white";
                }
            });

            // ساب‌تسک‌ها
            const subsContainer = document.getElementById('detail-subtasks-container');
            const subtasksArr = t.subtasks || [];
            
            let subsHtml = '';
            let checkedCount = 0;
            
            if (subtasksArr.length === 0) {
                subsHtml = '<div class="text-right py-4 text-[10px] font-bold text-slate-400">هیچ خرده تسک فرعی تعریف نگشته است.</div>';
            } else {
                subtasksArr.forEach(sub => {
                    if (sub.completed) checkedCount++;
                    const isChecked = sub.completed ? 'checked' : '';
                    const strikeClass = sub.completed ? 'line-through text-slate-400 font-medium' : 'text-slate-700 font-bold';
                    
                    subsHtml += `
                        <label class="flex items-center gap-3 p-2 bg-slate-50/50 border border-slate-100/60 rounded-xl hover:bg-slate-50 cursor-pointer">
                            <input type="checkbox" onchange="toggleSubtaskStatus('${t.id}', '${sub.id}', this.checked)" ${isChecked} class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-200 rounded">
                            <span class="text-xs transition-all ${strikeClass}">${escapeHtml(sub.title)}</span>
                        </label>
                    `;
                });
            }
            subsContainer.innerHTML = subsHtml;
            document.getElementById('detail-subtask-progress-label').innerText = `${checkedCount} از ${subtasksArr.length} مورد انجام شد`;

            // کامنت‌ها
            const commentsContainer = document.getElementById('detail-comments-container');
            const commentsArr = t.comments || [];
            let cmtsHtml = '';
            
            if (commentsArr.length === 0) {
                cmtsHtml = '<div class="text-right py-4 text-[10px] font-bold text-slate-300">هیچ بازخورد یا یادداشتی ثبت نگردیده. اولین مورد را آغاز فرمایید.</div>';
            } else {
                commentsArr.forEach(c => {
                    const isMe = (c.user === currentUsername);
                    const alignment = isMe ? 'text-left' : 'text-right';
                    const wrapClass = isMe ? 'bg-indigo-50 border-indigo-100 mr-auto' : 'bg-slate-100/65 border-slate-150 ml-auto';
                    
                    // قالب زمان
                    const formattedTime = new Date(c.at * 1000).toLocaleTimeString('fa-IR', {hour: '2-digit', minute:'2-digit'});

                    cmtsHtml += `
                        <div class="p-2.5 border rounded-2xl max-w-[85%] text-right space-y-1 ${wrapClass}">
                            <div class="flex items-center justify-between text-[8px] font-black text-slate-500 gap-6">
                                <span>${c.user_display} (@${c.user})</span>
                                <span>${formattedTime}</span>
                            </div>
                            <p class="text-[10px] hover:text-slate-900 font-medium text-slate-700 leading-normal">${escapeHtml(c.text)}</p>
                        </div>
                    `;
                });
            }
            commentsContainer.innerHTML = cmtsHtml;
            // اسکرول کامنت‌ها به ته‌ترین حالت ممکن
            commentsContainer.scrollTop = commentsContainer.scrollHeight;
        }

        // عملکرد تغییر آنلاین وضعیت تسک مادر
        function updateActiveTaskStatus(status) {
            if (!activeTaskId) return;

            const payload = {
                task_id: activeTaskId,
                status: status
            };

            fetch('?action=update_task_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    syncTasks();
                } else {
                    alert('تلاش ناموفق: ' + data.message);
                }
            })
            .catch(err => console.error(err));
        }

        // عملکرد تغییر تیک خرده تسک‌ها
        function toggleSubtaskStatus(taskId, subtaskId, completed) {
            const payload = {
                task_id: taskId,
                subtask_id: subtaskId,
                completed: completed
            };

            fetch('?action=toggle_subtask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    syncTasks();
                } else {
                    alert('خطا در ارتقای لاگ زیرتسک: ' + data.message);
                }
            })
            .catch(err => console.error(err));
        }

        // کامنت جدید ارسال کن
        function submitNewComment() {
            if (!activeTaskId) return;
            const textInput = document.getElementById('new-comment-input');
            const txt = textInput.value.trim();

            if (!txt) return;

            const payload = {
                task_id: activeTaskId,
                text: txt
            };

            fetch('?action=add_comment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    textInput.value = '';
                    syncTasks();
                } else {
                    alert('ثبت نظر ناموفق بود.');
                }
            })
            .catch(err => console.error(err));
        }

        // حذف تسک جاری به طور فیزیکی
        function deleteActiveTask() {
            if (!activeTaskId) return;
            if (!confirm('آیا مایل به حذف دائم این کارت تسک و سوابق آن هستید؟ این فرآیند برگشت‌ناپذیر است.')) return;

            const payload = {
                task_id: activeTaskId
            };

            fetch('?action=delete_task', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeTaskDetailModal();
                    syncTasks();
                    alert('🗑️ کارت تسک با موفقیت حذف گردید.');
                } else {
                    alert('مشکل در حذف: ' + data.message);
                }
            })
            .catch(err => console.error(err));
        }

        // اسکیپ کردن تگ‌های HTML جهت جلوگیری از XSS در پورتال چت و تعامل کاربری
        function escapeHtml(text) {
            if (!text) return '';
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
