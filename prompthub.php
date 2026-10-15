<?php
require_once 'inc/functions.php';
require_once 'inc/cors.php';

// Check system authentication
if (!isUser()) {
    redirect('index.php');
}

// Check and resolve current project
$projectId = $_GET['project'] ?? ($_SESSION['project_id'] ?? null);
$db = getDB();
if (!$projectId && !empty($db['projects'])) {
    $projectId = $db['projects'][0]['id'];
}
$project = getCurrentProject($projectId);

// Set current project context
if ($project) {
    $_SESSION['project_id'] = $project['id'];
}

// Resolve user permission
$can_manage_promp = isSuperAdmin() || isProjectAdmin() || hasPermission('prompthub_manage');

// JSON DB definition
define('PROMPT_HUB_DB', __DIR__ . '/db/prompthub.json');

function loadPromptHubData() {
    if (!file_exists(PROMPT_HUB_DB)) {
        $default = [
            'storagePolicy' => 'shared',
            'categories' => [
                ['id' => 'cat_content', 'name' => 'تولید محتوا', 'parent' => ''],
                ['id' => 'cat_caption', 'name' => 'کپشن نویسی', 'parent' => 'cat_content'],
                ['id' => 'cat_ad', 'name' => 'تبلیغات و کمپین', 'parent' => ''],
                ['id' => 'cat_edu', 'name' => 'آموزشی و راهنما', 'parent' => ''],
                ['id' => 'cat_other', 'name' => 'سایر موضوعات', 'parent' => '']
            ],
            'prompts' => [
                [
                    'id' => 'p_preset_1',
                    'title_fa' => 'کپشن معرفی لباس ورزشی مدرن',
                    'title_en' => 'Modern Activewear Introduction Caption',
                    'mode' => 'text',
                    'kind' => 'templated',
                    'desc_fa' => 'یک کپشن جذاب اینستاگرام برای معرفی لباس ورزشی جدید با هشتگ‌های ترند و ترغیب به دایرکت.',
                    'desc_en' => 'An engaging Instagram caption template on introducing new activewear items with call-to-actions.',
                    'category' => 'cat_caption',
                    'tags' => 'کپشن, ورزشی, اینستاگرام, معرفی',
                    'body_fa' => "🏃‍♂️💪 برای چالش جدید آماده‌ای؟ معرفی {{product_name}}!\n\nاگر به دنبال ترکیبی از راحتی بی‌نظیر و کیفیت درجه یک در تمریناتت هستی، این دقیقاً همون چیزیه که نیاز داری.\n\n🌟 ویژگی‌های برجسته:\n- {{material_type}} با تنفس‌پذیری بالا\n- دوام بسیار بالا در شستشوهای مکرر\n- طراحی ارگونومیک برای آزادی کامل در حرکت\n\n🛍️ برای ثبت سفارش کافیه کلمه «ورزش» رو دایرکت بفرستی یا از سایت خرید کنی!\n💵 قیمت استثنایی: {{price}}",
                    'body_en' => "🏃‍♂️💪 Get ready for your next level with {{product_name}}!\n\nWhether you're gym training or enjoying a casual jog, this premium wear is your best companion.\n\n🌟 Highlights:\n- Premium {{material_type}} with maximum breathability\n- Durable flatlock seams\n- Sleek athletic fit\n\n🛍️ Slide into our DM with \"ACTIVE\" or visit our shop!\n💵 Special Price: {{price}}",
                    'sample_text' => "نمونه خروجی کپشن لباس ورزشی:\n🏃‍♂️💪 برای چالش جدید آماده‌ای؟ معرفی تیشرت ورزشی پرو هیت!\nاگر به دنبال ترکیبی از راحتی بی‌نظیر و کیفیت درجه یک...\nویژگی‌ها: پارچه غواصی میکروفایبر، قیمت: ۲۸۰,۰۰۰ تومان",
                    'sample_file' => '',
                    'uploaded_by' => 'admin',
                    'created_at' => time(),
                    'status' => 'approved'
                ],
                [
                    'id' => 'p_preset_2',
                    'title_fa' => 'پرامپت عکاسی بیوتی شات مدل لباس',
                    'title_en' => 'Beauty Fashion Shot Photo Prompt',
                    'mode' => 'image',
                    'kind' => 'templated',
                    'desc_fa' => 'بهترین دستور تولید عکس مدلینگ لباس و فشن در استودیو برای ابزارهای هوش مصنوعی Midjourney و دالی.',
                    'desc_en' => 'Studio model portrait prompt for high fashion and apparel images, perfect for Midjourney.',
                    'category' => 'cat_content',
                    'tags' => 'آتلیه, تصویر, مدلینگ, فشن',
                    'body_fa' => "پرتره استودیویی مدلینگ عکاسی حرفه‌ای مد و فشن از یک مدل با به تن داشتن لباس {{clothing_item}} به رنگ {{color}}، نورپردازی پرتره چشمگیر و سافت لایت متمرکز، جزئیات بالا، با وضوح ۸کی.",
                    'body_en' => "A professional fashion portrait photography of a model wearing {{clothing_item}} in {{color}} color, dramatic soft studio lighting, atmospheric bokeh backdrops, photorealistic, 8k resolution, cinematic composition.",
                    'sample_text' => 'یک تصویر استودیویی با کیفیت کیهانی از عکاسی حرفه‌ای با رنگ سبز تیره...',
                    'sample_file' => '',
                    'uploaded_by' => 'admin',
                    'created_at' => time() - 7200,
                    'status' => 'approved'
                ]
            ]
        ];
        $dir = dirname(PROMPT_HUB_DB);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(PROMPT_HUB_DB, json_encode($default, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $default;
    }
    $json = file_get_contents(PROMPT_HUB_DB);
    return json_decode($json, true) ?: ['storagePolicy' => 'shared', 'categories' => [], 'prompts' => []];
}

function savePromptHubData($data) {
    $dir = dirname(PROMPT_HUB_DB);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(PROMPT_HUB_DB, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// Handle AJAX actions
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $data = loadPromptHubData();
    $current_username = $_SESSION['user']['username'] ?? 'unknown';

    if ($action === 'get_data') {
        echo json_encode([
            'status' => 'success',
            'storagePolicy' => $data['storagePolicy'] ?? 'shared',
            'categories' => $data['categories'] ?? [],
            'prompts' => $data['prompts'] ?? [],
            'can_manage' => $can_manage_promp,
            'current_user' => $current_username
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'execute_prompt_ai') {
        $promptText = $_POST['prompt'] ?? '';
        if (empty($promptText)) {
            echo json_encode(['status' => 'failed', 'msg' => 'متن پرامپت خالی است.']);
            exit();
        }
        $apiKey = $project['settings']['gemini_api_key'] ?? $project['settings']['connections']['gemini_api_key'] ?? '';
        $res = geminiGenerateResponse($promptText, "شما یک دستیار هوش مصنوعی هوشمند برای دیجیتال مارکتینگ و سئو هستید. پاسخ بسیار کامل، زیبا و ترغیب‌کننده را به زبان فارسی ارائه دهید.", $apiKey);
        if (isset($res['error'])) {
            echo json_encode(['status' => 'failed', 'msg' => $res['error']]);
        } else {
            echo json_encode(['status' => 'success', 'response' => $res['text']]);
        }
        exit();
    }

    if ($action === 'save_prompt') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'شما دسترسی کافی برای اضافه کردن یا ویرایش پرامپت ندارید.']);
            exit();
        }

        $id = $_POST['id'] ?? '';
        $title_fa = $_POST['title_fa'] ?? '';
        $title_en = $_POST['title_en'] ?? '';
        $mode = $_POST['mode'] ?? 'text';
        $kind = $_POST['kind'] ?? 'ready';
        $desc_fa = $_POST['desc_fa'] ?? '';
        $desc_en = $_POST['desc_en'] ?? '';
        $category = $_POST['category'] ?? '';
        $tags = $_POST['tags'] ?? '';
        $body_fa = $_POST['body_fa'] ?? '';
        $body_en = $_POST['body_en'] ?? '';
        $sample_text = $_POST['sample_text'] ?? '';
        
        if (empty($title_fa) || empty($body_fa)) {
            echo json_encode(['status' => 'failed', 'msg' => 'عنوان و بدنه فارسی الزامی هستند.']);
            exit();
        }

        // Handle file upload
        $sample_file_path = $_POST['existing_sample_file'] ?? '';
        if (isset($_FILES['sample_file']) && $_FILES['sample_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/prompts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['sample_file']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('samp_') . '.' . $ext;
            if (move_uploaded_file($_FILES['sample_file']['tmp_name'], $upload_dir . $filename)) {
                $sample_file_path = 'uploads/prompts/' . $filename;
            }
        }

        $is_new = false;
        if (empty($id)) {
            $is_new = true;
            $id = uniqid('p_');
            $prompt = [
                'id' => $id,
                'title_fa' => $title_fa,
                'title_en' => $title_en,
                'mode' => $mode,
                'kind' => $kind,
                'desc_fa' => $desc_fa,
                'desc_en' => $desc_en,
                'category' => $category,
                'tags' => $tags,
                'body_fa' => $body_fa,
                'body_en' => $body_en,
                'sample_text' => $sample_text,
                'sample_file' => $sample_file_path,
                'uploaded_by' => $current_username,
                'created_at' => time(),
                'status' => 'approved' // Automatically auto-approve for admins
            ];
            $data['prompts'][] = $prompt;
        } else {
            // Find and update
            $found = false;
            foreach ($data['prompts'] as &$p) {
                if ($p['id'] === $id) {
                    $p['title_fa'] = $title_fa;
                    $p['title_en'] = $title_en;
                    $p['mode'] = $mode;
                    $p['kind'] = $kind;
                    $p['desc_fa'] = $desc_fa;
                    $p['desc_en'] = $desc_en;
                    $p['category'] = $category;
                    $p['tags'] = $tags;
                    $p['body_fa'] = $body_fa;
                    $p['body_en'] = $body_en;
                    $p['sample_text'] = $sample_text;
                    $p['sample_file'] = $sample_file_path;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo json_encode(['status' => 'failed', 'msg' => 'پرامپت پیدا نشد.']);
                exit();
            }
        }

        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => $is_new ? 'پرامپت با موفقیت ثبت شد.' : 'تغییرات پرامپت با موفقیت ذخیره شد.']);
        exit();
    }

    if ($action === 'delete_prompt') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $id = $_POST['id'] ?? '';
        $initial_count = count($data['prompts'] ?? []);
        $data['prompts'] = array_values(array_filter($data['prompts'] ?? [], function($p) use ($id) {
            return $p['id'] !== $id;
        }));
        if (count($data['prompts']) === $initial_count) {
            echo json_encode(['status' => 'failed', 'msg' => 'پرامپت پیدا نشد.']);
        } else {
            savePromptHubData($data);
            echo json_encode(['status' => 'success', 'msg' => 'پرامپت با موفقیت حذف گردید.']);
        }
        exit();
    }

    if ($action === 'approve_prompt') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $id = $_POST['id'] ?? '';
        foreach ($data['prompts'] as &$p) {
            if ($p['id'] === $id) {
                $p['status'] = 'approved';
                break;
            }
        }
        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => 'پرامپت تایید صلاحیت و منتشر شد.']);
        exit();
    }

    if ($action === 'reject_prompt') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $id = $_POST['id'] ?? '';
        foreach ($data['prompts'] as &$p) {
            if ($p['id'] === $id) {
                $p['status'] = 'rejected';
                break;
            }
        }
        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => 'پرامپت ابطال و رد صلاحیت شد.']);
        exit();
    }

    if ($action === 'save_policy') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $policy = $_POST['policy'] ?? 'shared';
        $data['storagePolicy'] = $policy;
        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => 'سیاست ذخیره‌سازی با موفقیت به‌روزرسانی شد.']);
        exit();
    }

    if ($action === 'save_category') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $name = trim($_POST['name'] ?? '');
        $parent = $_POST['parent'] ?? '';
        if (empty($name)) {
            echo json_encode(['status' => 'failed', 'msg' => 'نام دسته بندی الزامی است.']);
            exit();
        }
        $cat_id = 'cat_' . uniqid();
        $data['categories'][] = [
            'id' => $cat_id,
            'name' => $name,
            'parent' => $parent
        ];
        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => 'دسته جدید با موفقیت اضافه شد.', 'categories' => $data['categories']]);
        exit();
    }

    if ($action === 'delete_category') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $id = $_POST['id'] ?? '';
        $data['categories'] = array_values(array_filter($data['categories'] ?? [], function($c) use ($id) {
            return $c['id'] !== $id;
        }));
        savePromptHubData($data);
        echo json_encode(['status' => 'success', 'msg' => 'دسته مربوطه حذف گردید.', 'categories' => $data['categories']]);
        exit();
    }

    if ($action === 'import_prompts') {
        if (!$can_manage_promp) {
            echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیرمجاز.']);
            exit();
        }
        $import_type = $_POST['type'] ?? 'code';
        $prompts_to_add = [];

        if ($import_type === 'code') {
            $code = trim($_POST['code'] ?? '');
            $code_decoded = base64_decode($code);
            if ($code_decoded) {
                $prompts_to_add = json_decode($code_decoded, true);
            }
        } else {
            $json = trim($_POST['json'] ?? '');
            $prompts_to_add = json_decode($json, true);
        }

        if (!is_array($prompts_to_add)) {
            echo json_encode(['status' => 'failed', 'msg' => 'فرمت داده‌های چسبانده شده صحیح نیست.']);
            exit();
        }

        // Loop and import
        $imported_count = 0;
        foreach ($prompts_to_add as $p_item) {
            if (empty($p_item['title_fa']) || empty($p_item['body_fa'])) continue;
            
            $prompt_id = 'p_imp_' . uniqid();
            $data['prompts'][] = [
                'id' => $prompt_id,
                'title_fa' => $p_item['title_fa'],
                'title_en' => $p_item['title_en'] ?? '',
                'mode' => $p_item['mode'] ?? 'text',
                'kind' => $p_item['kind'] ?? 'ready',
                'desc_fa' => $p_item['desc_fa'] ?? '',
                'desc_en' => $p_item['desc_en'] ?? '',
                'category' => $p_item['category'] ?? 'cat_other',
                'tags' => $p_item['tags'] ?? '',
                'body_fa' => $p_item['body_fa'],
                'body_en' => $p_item['body_en'] ?? '',
                'sample_text' => $p_item['sample_text'] ?? '',
                'sample_file' => $p_item['sample_file'] ?? '',
                'uploaded_by' => $current_username,
                'created_at' => time(),
                'status' => 'approved'
            ];
            $imported_count++;
        }

        if ($imported_count > 0) {
            savePromptHubData($data);
            echo json_encode(['status' => 'success', 'msg' => "تعداد {$imported_count} پرامپت با موفقیت ایمپورت و تایید شد."]);
        } else {
            echo json_encode(['status' => 'failed', 'msg' => 'هیچ پرامپت معتبری یافت نشد.']);
        }
        exit();
    }

    echo json_encode(['status' => 'failed', 'msg' => 'عملیات ناشناخته است.']);
    exit();
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>🧠 مرکز پرامپت‌ها (Prompt Hub)</title>
  
  <link rel="stylesheet" href="<?php echo getFullUrl('assets/style.css'); ?>">
  <script src="<?php echo getFullUrl('assets/tailwindcss.js'); ?>"></script>
  
  <style>
    @import url('https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/github-dark.min.css');
    
    body {
        font-family: 'Vazirmatn', system-ui, -apple-system, sans-serif;
        background-color: #f8fafc;
        color: #1e293b;
    }
    
    /* Loader styles */
    .loader-overlay {
        position: fixed;
        inset: 0;
        background: rgba(30, 41, 59, 0.7);
        backdrop-blur: 8px;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: opacity 0.3s ease;
    }
    .loader {
        width: 48px;
        height: 48px;
        border: 4px solid #fff;
        border-bottom-color: #7c3aed;
        border-radius: 50%;
        display: inline-block;
        box-sizing: border-box;
        animation: rotation 1s linear infinite;
    }
    @keyframes rotation {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Premium components styling */
    .premium-card {
        background: #ffffff;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 20px -2px rgba(148, 163, 184, 0.08), 0 2px 8px -1px rgba(148, 163, 184, 0.04);
        border-radius: 1.5rem;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .premium-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(148, 163, 184, 0.12), 0 8px 16px -6px rgba(148, 163, 184, 0.06);
    }
    
    .pill {
        padding: 0.625rem 1.25rem;
        border-radius: 1rem;
        font-size: 0.75rem;
        font-weight: 800;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }
    .pill.is-active {
        background-color: #7c3aed;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
    }
    .pill:not(.is-active) {
        background-color: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }
    .pill:not(.is-active):hover {
        background-color: #e2e8f0;
        color: #0f172a;
    }

    /* Mobile drawer and filters responsive */
    @media (max-width: 1100px) {
        .layout {
            display: flex;
            flex-direction: column;
        }
        .sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            right: -320px;
            width: 300px;
            z-index: 1000;
            transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            box-shadow: -10px 0 30px rgba(0,0,0,0.1);
            border-radius: 0;
        }
        .filters-open .sidebar {
            right: 0;
        }
        .filters-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 999;
            display: none;
        }
        .filters-open .filters-overlay {
            display: block;
        }
    }
    
    .suggest {
        position: absolute;
        width: 100%;
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        max-height: 200px;
        overflow-y: auto;
        z-index: 100;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }
    .suggest-item {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
        cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .suggest-item:hover {
        background-color: #f1f5f9;
        color: #7c3aed;
    }
    
    .vars {
        background-color: #faf5ff;
        border: 1px dashed #d8b4fe;
        border-radius: 1rem;
        padding: 1rem;
    }
    
    /* Modal styles */
    .modal {
        position: fixed;
        inset: 0;
        z-index: 1050;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .modal-backdrop {
        position: fixed;
        inset: 0;
        background-color: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
    }
    .modal-card {
        background: #ffffff;
        border-radius: 2rem;
        width: 100%;
        max-width: 650px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        position: relative;
        z-index: 1060;
        overflow: hidden;
        animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    
    @keyframes slideUp {
        0% { transform: translateY(20px); opacity: 0; }
        100% { transform: translateY(0); opacity: 1; }
    }
    
    .modal-head {
        padding: 1.5rem 2rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-body {
        padding: 2rem;
        overflow-y: auto;
        flex: 1;
    }
    .modal-foot {
        padding: 1.5rem 2rem;
        border-top: 1px solid #f1f5f9;
        background-color: #f8fafc;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .tab.is-active {
        border-bottom: 2px solid #7c3aed;
        color: #7c3aed;
        font-weight: 800;
    }
  </style>
<?php if (($_GET['design'] ?? '') !== 'legacy'): ?>
<link rel="stylesheet" href="/panel/assets/neo-tokens.css?v=1">
<link rel="stylesheet" href="/panel/assets/neo-modules.css?v=<?php echo filemtime(__DIR__.'/panel/assets/neo-modules.css'); ?>">
<script src="/panel/assets/neo-modules.js?v=<?php echo filemtime(__DIR__.'/panel/assets/neo-modules.js'); ?>" defer></script>
<?php endif; ?>
</head>
<body class="<?php echo ($_GET['design'] ?? '') !== 'legacy' ? 'neo-module neo-prompts ' : ''; ?>bg-slate-50 antialiased p-4 md:p-8" dir="rtl">

    <!-- Screen Loader -->
    <div id="pageLoader" class="loader-overlay">
        <div class="flex flex-col items-center gap-3">
            <div class="loader"></div>
            <span class="text-white text-xs font-bold font-sans">لطفا شکیبا باشید...</span>
        </div>
    </div>

    <!-- Container wrapper -->
    <div class="max-w-7xl mx-auto space-y-6">
        
        <!-- Breadcrumbs/Back To Main Portal -->
        <div class="flex flex-wrap items-center justify-between gap-3 bg-white px-6 py-4 rounded-3xl border border-slate-100 shadow-sm" dir="rtl">
            <div class="flex items-center gap-2">
                <span class="text-violet-650 text-base">🧠</span>
                <span class="text-xs font-black text-slate-800">سامانه هوشمند ماارکشو فلو</span>
                <span class="text-slate-300 text-xs">/</span>
                <span class="text-xs font-black text-violet-650">مرکز پرامپت‌ها</span>
            </div>
            
            <a href="portal.php<?php echo isset($project['id']) ? '?project=' . urlencode($project['id']) : ''; ?>" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-2xl transition-all flex items-center gap-1.5 shadow-sm">
                <span>◀</span>
                <span>بازگشت به پرتال اصلی</span>
            </a>
        </div>

        <!-- Topbar Header -->
        <header class="bg-white border border-slate-100 rounded-[2.2rem] p-6 shadow-sm flex flex-col gap-6" id="topbar">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center text-3xl shadow-sm">🧠</div>
                    <div class="text-right">
                        <h1 class="text-xl font-black text-slate-900 leading-tight">مرکز پرامپت‌ها (Prompt Hub)</h1>
                        <p class="text-[11px] text-slate-400 font-extrabold mt-1">فرمول‌ها، کلیشه‌ها و دستورات طلایی هوش مصنوعی تولید محتوا</p>
                    </div>
                </div>

                <!-- Action Triggers -->
                <div class="flex flex-wrap items-center gap-2.5 w-full lg:w-auto">
                    <button id="btnFilters" class="lg:hidden px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-2xl text-xs font-black transition-all flex items-center gap-2 flex-1 justify-center">
                        <span>⚙️</span>
                        <span>فیلترها و تنظیمات</span>
                    </button>
                    
                    <?php if ($can_manage_promp): ?>
                        <button class="px-5 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-2xl text-xs font-black transition-all flex items-center gap-1.5 shadow-md shadow-violet-900/10 flex-1 lg:flex-none justify-center" id="btnNew">
                            <span>+</span>
                            <span>پرامپت جدید</span>
                        </button>
                        <button class="px-4 py-3 bg-white border border-slate-200 hover:border-slate-350 text-slate-700 rounded-2xl text-xs font-black transition-all flex items-center gap-1.5 shadow-sm flex-1 lg:flex-none justify-center" id="btnImport">
                            <span>📤</span>
                            <span>ایمپورت گروهی</span>
                        </button>
                        <button class="px-4 py-3 bg-white border border-slate-200 hover:border-slate-350 text-slate-705 rounded-2xl text-xs font-black transition-all flex items-center gap-1.5 shadow-sm flex-1 lg:flex-none justify-center" id="btnCats">
                            <span>📁</span>
                            <span>مدیریت دسته‌ها</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admin-only Policy Box & Nav Pills Tabs -->
            <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between border-t border-slate-100 pt-5 gap-4">
                
                <!-- Pills Tabs -->
                <div id="tabs" class="flex flex-wrap gap-2">
                    <button class="pill is-active" data-tab="approved">✨ تأییدشده‌ها</button>
                    <button class="pill" data-tab="mine-pending">⏳ در انتظار من</button>
                    <button class="pill" data-tab="mine">📝 پرامپت‌های من</button>
                    <?php if ($can_manage_promp): ?>
                        <button class="pill" data-tab="pending-all">📥 کل معلق‌ها</button>
                        <button class="pill" data-tab="rejected">❌ ردشده‌ها</button>
                    <?php endif; ?>
                </div>
                
                <!-- Policy box -->
                <?php if ($can_manage_promp): ?>
                <div id="policyBox" class="flex items-center gap-3 bg-slate-50 border border-slate-200/60 px-4 py-2 rounded-2xl text-xs">
                    <span class="font-extrabold text-slate-500">سیاست ذخیره‌سازی:</span>
                    <select id="storagePolicy" class="bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-bold outline-none cursor-pointer focus:border-violet-500">
                        <option value="per-user">خصوصی برای هر کاربر</option>
                        <option value="shared" selected>اشتراکی بین همهٔ کاربران</option>
                    </select>
                    <button id="savePolicy" class="px-4 py-1.5 bg-violet-600 hover:bg-violet-750 text-white rounded-xl font-bold transition-all">ثبت</button>
                </div>
                <?php endif; ?>

            </div>
        </header>

        <!-- Sidebar overlay for mobile drawer -->
        <div id="filtersOverlay" class="filters-overlay" aria-hidden="true"></div>

        <!-- Layout Content -->
        <section class="grid grid-cols-1 lg:grid-cols-4 gap-6 layout">
            
            <!-- Sidebar Panel filters -->
            <aside class="sidebar bg-white border border-slate-100 rounded-[2.2rem] p-6 shadow-sm space-y-6 lg:h-fit">
                <div class="space-y-4">
                    <h3 class="font-black text-sm text-slate-800 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span>🔍</span>
                        <span>فیلتر و مدیریت جستجو</span>
                    </h3>
                    
                    <!-- Search Input -->
                    <div class="space-y-1.5">
                        <label for="q" class="text-[10px] font-black text-slate-400 block uppercase">جستجوی کلیدواژه</label>
                        <input id="q" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:ring-2 focus:ring-violet-500 transition-all text-right" placeholder="عنوان، بدنه، توضیح...">
                    </div>
                    
                    <!-- Category Select -->
                    <div class="space-y-1.5">
                        <label for="cat" class="text-[10px] font-black text-slate-400 block uppercase">دسته بندی موضوعی</label>
                        <select id="cat" class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:ring-2 focus:ring-violet-500 transition-all cursor-pointer">
                            <option value="">همه دسته‌ها</option>
                        </select>
                    </div>
                    
                    <!-- Mode Select -->
                    <div class="space-y-1.5">
                        <label for="mode" class="text-[10px] font-black text-slate-400 block uppercase">نوع ساختار (مود)</label>
                        <select id="mode" class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:ring-2 focus:ring-violet-500 transition-all cursor-pointer">
                            <option value="">همه تکنیک‌ها</option>
                            <option value="text">✍️ متنی و نگارشی</option>
                            <option value="image">🎨 عکاسی و تصویری</option>
                            <option value="video">🎥 ویدیویی و متحرک</option>
                        </select>
                    </div>
                    
                    <!-- Tag Filter with auto suggest -->
                    <div class="space-y-1.5 relative">
                        <label for="tagsFilter" class="text-[10px] font-black text-slate-400 block uppercase">فیلتر با برچسب‌ها</label>
                        <div class="relative">
                            <input id="tagsFilter" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:ring-2 focus:ring-violet-500 transition-all text-right" placeholder="مثال: کپشن، ورزشی">
                            <div id="tagSuggest" class="suggest hide hidden"></div>
                        </div>
                    </div>
                </div>
                
                <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-[10px] text-slate-400 font-bold">اسکرول این بخش مستقل است.</span>
                    <button onclick="resetFilters()" class="text-[10px] text-violet-600 hover:text-violet-800 font-extrabold">پاک کردن فیلترها</button>
                </div>
            </aside>

            <!-- Grid dynamic prompts lists -->
            <main class="lg:col-span-3 space-y-6">
                <!-- Grid list -->
                <div id="list" class="grid grid-cols-1 md:grid-cols-2 gap-6"></div>
                <!-- Empty State -->
                <div id="empty" class="bg-white border border-slate-100 rounded-[2.2rem] p-16 text-center shadow-sm text-slate-420 font-black italic hidden" dir="rtl">
                    <div class="text-4xl mb-3">🕳️</div>
                    <div class="text-xs">هیچ فرمول پرامپتی پیدا نشد. می‌توانید با کلیک بر روی دکمه «پرامپت جدید» اولین مورد را ثبت کنید.</div>
                </div>
            </main>

        </section>
    </div>

    <!-- 1. Prompt Form Edit/Add Modal (#promptModal) -->
    <div class="modal hidden" id="promptModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card max-w-2xl">
            <div class="modal-head">
                <h3 id="pmTitle" class="text-sm font-black text-slate-800 flex items-center gap-1.5">
                    <span>📝</span>
                    <span>ثبت یا اصلاح پرامپت</span>
                </h3>
                <button class="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 font-extrabold text-xs" data-close>✕</button>
            </div>
            
            <form id="promptForm" enctype="multipart/form-data">
                <input type="hidden" id="pId">
                <div class="modal-body space-y-4">
                    
                    <!-- Titles grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="pTitleFa" class="text-[10px] font-black text-slate-400 block uppercase">عنوان پرامپت (فارسی)</label>
                            <input id="pTitleFa" name="title_fa" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500" placeholder="عنوان کوتاه فارسی">
                        </div>
                        <div class="space-y-1.5" dir="ltr">
                            <label for="pTitleEn" class="text-[10px] font-black text-slate-400 block uppercase text-left">Title (English)</label>
                            <input id="pTitleEn" name="title_en" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500 text-left" placeholder="English title">
                        </div>
                    </div>
                    
                    <!-- Moderation and Mode fields -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="pMode" class="text-[10px] font-black text-slate-400 block uppercase">تکنیک خروجی (مود)</label>
                            <select id="pMode" name="mode" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none cursor-pointer focus:border-violet-500">
                                <option value="text">✍️ متنی و نگارشی</option>
                                <option value="image">🎨 عکاسی و تصویری</option>
                                <option value="video">🎥 ویدیویی و متحرک</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label for="pKind" class="text-[10px] font-black text-slate-400 block uppercase">نوع رفتار پرامپت</label>
                            <select id="pKind" name="kind" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none cursor-pointer focus:border-violet-500" onchange="toggleKindVars()">
                                <option value="ready">آماده (ایستا بدون متغیر)</option>
                                <option value="templated" selected>کد قالب‌دار (داینامیک دارای متغیر)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Descriptions -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="pDescFa" class="text-[10px] font-black text-slate-400 block uppercase">توضیح کوتاه کاربرد (فارسی)</label>
                            <input id="pDescFa" name="desc_fa" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500" placeholder="کاربرد، مخاطب هدف، تکنیک...">
                        </div>
                        <div class="space-y-1.5" dir="ltr">
                            <label for="pDescEn" class="text-[10px] font-black text-slate-400 block uppercase text-left">Description (English)</label>
                            <input id="pDescEn" name="desc_en" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500 text-left" placeholder="A brief description...">
                        </div>
                    </div>
                    
                    <!-- Categories and Tags -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="pCat" class="text-[10px] font-black text-slate-400 block uppercase">انتخاب دسته موضوعی</label>
                            <select id="pCat" name="category" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none cursor-pointer focus:border-violet-500">
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label for="pTags" class="text-[10px] font-black text-slate-400 block uppercase">برچسب‌ها (با کاما جدا کنید)</label>
                            <input id="pTags" name="tags" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500" placeholder="مثال: اینستاگرام, هوش_مصنوعی">
                        </div>
                    </div>

                    <!-- Prompt Body Formulas (FA and EN) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="pBodyFa" class="text-[10px] font-black text-slate-400 block uppercase">فرمول بدنه اصلی پرامپت (فارسی)</label>
                            <textarea id="pBodyFa" name="body_fa" required rows="5" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium outline-none focus:border-violet-500 leading-relaxed text-right" placeholder="متن پرامپت خود را بنویسید. برای متغیرها از {{نام_متغیر}} استفاده کنید" oninput="detectVariables('fa')"></textarea>
                        </div>
                        <div class="space-y-1.5" dir="ltr">
                            <label for="pBodyEn" class="text-[10px] font-black text-slate-400 block uppercase text-left">Main Prompt Body Formula (English)</label>
                            <textarea id="pBodyEn" name="body_en" rows="5" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium outline-none focus:border-violet-500 leading-relaxed text-left" placeholder="English version of the prompt if any..." oninput="detectVariables('en')"></textarea>
                        </div>
                    </div>

                    <!-- Interactive Variables Box -->
                    <div id="varsBox" class="vars flex flex-col gap-2 rounded-2xl p-4 bg-violet-50/50 border border-violet-100 hidden">
                        <div class="flex justify-between items-center pb-2 border-b border-violet-200/50">
                            <strong class="text-xs font-black text-violet-850">🧠 متغیرهای شناسایی شده:</strong>
                            <span class="text-[9px] text-slate-400">الگوی {x} و {{x}} به صورت هماهنگ تحلیل می‌شوند.</span>
                        </div>
                        <div id="varsList" class="flex flex-wrap gap-2 pt-1">
                            <span class="text-[10px] text-slate-400 italic">هنوز الگو یا متغیری در بدنه کپی نشده است.</span>
                        </div>
                    </div>

                    <!-- Sample and Media Preview -->
                    <div class="border border-slate-100 rounded-2xl p-4 bg-slate-50/50 space-y-4">
                        <div class="flex justify-between items-center">
                            <strong class="text-xs font-black text-slate-700">شبیه‌ساز و خروجی نمونه (Sample Output)</strong>
                            <small class="text-[10px] text-slate-405">اختیاری: برای درک بهتر خروجی هوش مصنوعی</small>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label for="pSampleText" class="text-[10px] font-black text-slate-400 block uppercase">طرح خروجی فرضی متنی</label>
                                <textarea id="pSampleText" name="sample_text" rows="3" class="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs outline-none focus:border-violet-500" placeholder="برای مثال خروجی کپشن را اینجا قرار دهید"></textarea>
                            </div>
                            <div class="space-y-1.5">
                                <label for="pSampleFile" class="text-[10px] font-black text-slate-400 block uppercase">تصویر نمونه یا خروجی گرافیکی</label>
                                <input id="pSampleFile" name="sample_file" type="file" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs outline-none focus:border-violet-500" accept="image/*,video/*">
                                <span id="samplePreview" class="text-[10px] text-slate-400 mt-1 block">پیش‌نمایش تصویر در صورت بارگذاری فعال می‌شود.</span>
                            </div>
                        </div>
                    </div>

                </div>
                
                <div class="modal-foot">
                    <button class="px-5 py-2.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-black transition-all border border-rose-100 hidden" id="btnDelete" type="button" onclick="deletePromptPrompt()">حذف کامل</button>
                    <div class="flex-grow"></div>
                    <button class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-black transition-all" data-close type="button">انصراف</button>
                    <button class="px-5 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-black transition-all shadow-md shadow-violet-900/10" id="btnSave" type="submit">ثبت و ذخیره سازی</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. Action / Prompt Detail Modal (#actionModal) -->
    <div class="modal hidden" id="actionModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card max-w-4xl">
            <div class="modal-head">
                <h3 id="actTitle" class="text-sm font-black text-slate-800 flex items-center gap-2">
                    <span>🧠</span>
                    <span id="actTitleText">جزئیات کامل فرمول پرامپت</span>
                </h3>
                <button class="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 font-extrabold text-xs" data-close>✕</button>
            </div>
            
            <div class="modal-body space-y-6">
                <!-- Badges -->
                <div class="flex flex-wrap gap-2 items-center bg-slate-50 border border-slate-150 p-4 rounded-2xl text-xs font-sans">
                    <span class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-extrabold">📁 دسته: <b class="text-violet-600" id="actCategory">—</b></span>
                    <span class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-extrabold">📊 مود سازه: <b id="actMode">—</b></span>
                    <span class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-extrabold">⚡ نوع عملکرد: <b id="actKind">—</b></span>
                    <span class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-extrabold">👥 ثبت: <b id="actVisibility">نامشخص</b></span>
                    <span class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-[10px] font-extrabold">📌 وضعیت انتشار: <span id="actStatus">فعال</span></span>
                </div>
                
                <!-- Dynamic tag wrappers -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <span class="text-[10px] text-slate-400 block font-bold">🏷️ برچسب‌ها (تگ):</span>
                        <div id="actTags" class="flex flex-wrap gap-1.5 pt-1"></div>
                    </div>
                </div>
                
                <!-- Descriptions fa vs en -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl">
                        <span class="text-[10px] text-slate-405 block font-bold mb-2">توضیحات و کاربرد (FA)</span>
                        <p id="actDescFa" class="text-xs font-medium text-slate-700 leading-relaxed whitespace-pre-wrap"></p>
                    </div>
                    <div dir="ltr" class="p-4 bg-slate-50 border border-slate-100 rounded-2xl text-left">
                        <span class="text-[10px] text-slate-405 block font-bold mb-2">Description (EN)</span>
                        <p id="actDescEn" class="text-xs font-medium text-slate-700 leading-relaxed whitespace-pre-wrap font-sans"></p>
                    </div>
                </div>
                
                <!-- Language tabs translation -->
                <div class="space-y-3">
                    <div id="langTabsAct" class="flex gap-1.5 border-b border-slate-100 pb-2">
                        <button class="tab px-4 py-2 text-xs font-black transition-all is-active" data-lang="fa" onclick="switchActLang('fa')">✨ بدنه فرمول فارسی</button>
                        <button class="tab px-4 py-2 text-xs font-black transition-all text-slate-400" data-lang="en" onclick="switchActLang('en')">🇬🇧 Body (English)</button>
                    </div>
                    
                    <div id="langPaneAct-fa" class="relative">
                        <pre id="actBodyFa" class="p-4 bg-slate-900 text-slate-100 rounded-2xl font-mono text-xs overflow-x-auto select-all max-h-60 leading-relaxed text-right whitespace-pre-wrap"></pre>
                    </div>
                    <div id="langPaneAct-en" class="relative hidden" dir="ltr">
                        <pre id="actBodyEn" class="p-4 bg-slate-900 text-slate-100 rounded-2xl font-mono text-xs overflow-x-auto select-all max-h-60 leading-relaxed text-left whitespace-pre-wrap"></pre>
                    </div>
                </div>
                
                <!-- Variables in act modal template -->
                <div id="varsBoxAct" class="vars space-y-2 rounded-2xl p-4 bg-violet-50/50 border border-violet-100 hidden">
                    <strong class="text-xs font-black text-violet-850 block">✨ نیازمند متغیر برای ساختار نهایی:</strong>
                    <div id="varsListAct" class="flex flex-wrap gap-2 pt-1"></div>
                </div>
                
                <!-- Sample visuals and texts -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                    <div class="space-y-2">
                        <span class="text-[10px] text-slate-400 font-bold block">📄 نمونه متن تدارک یافته:</span>
                        <div id="actSampleText" class="p-4 bg-slate-50 text-slate-700 rounded-2xl text-xs font-medium whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto"></div>
                    </div>
                    <div class="space-y-2">
                        <span class="text-[10px] text-slate-400 font-bold block">🖼️ نمونه خروجی چندرسانه‌ای:</span>
                        <div id="actSampleMedia" class="relative rounded-2xl overflow-hidden border border-slate-150 flex items-center justify-center bg-slate-50 min-h-[140px] max-h-44"></div>
                    </div>
                </div>
            </div>
            
            <div class="modal-foot flex flex-wrap gap-3 items-center justify-between">
                <!-- Admin Actions -->
                <div id="adminActions" class="hidden flex flex-wrap gap-2">
                    <button class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black transition-all" onclick="editActivePrompt()">✏️ ویرایش</button>
                    <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black transition-all" onclick="approvePrompt()">✅ تایید انتشار</button>
                    <button class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-black transition-all" onclick="rejectPrompt()">❌ رد صلاحیت</button>
                    <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-black transition-all" onclick="deletePromptPrompt()">🗑️ حذف</button>
                </div>
                
                <!-- General Use / Close Actions -->
                <div class="flex flex-wrap gap-2 justify-end ms-auto">
                    <button class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-black transition-all flex items-center gap-1.5" id="actUseFa">
                        <span>✨</span> استفاده از فرمول فارسی
                    </button>
                    <button class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-black transition-all flex items-center gap-1.5" id="actUseEn">
                        <span>🇬🇧</span> Use English Formula
                    </button>
                    <button class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-black transition-all" data-close>بستن دیالوگ</button>
                </div>
            </div>
        </div>
    </div><!-- 3. Dynamic Use Prompt Modal (#useModal) -->
    <div class="modal hidden" id="useModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <div class="modal-head">
                <h3 class="text-sm font-black text-slate-800 flex items-center gap-1.5">
                    <span>⚡</span>
                    <span>شخصی‌سازی و ساخت نهایی پرامپت</span>
                </h3>
                <button class="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 font-extrabold text-xs" data-close>✕</button>
            </div>
            
            <div class="modal-body space-y-5">
                <div class="flex gap-3 bg-slate-50 p-4 border border-slate-150 rounded-2xl">
                    <div class="space-y-1 w-24">
                        <label for="useLang" class="text-[10px] font-black text-slate-400 block uppercase">زبان خروجی</label>
                        <select id="useLang" class="bg-white border border-slate-200 rounded-xl px-2 py-1.5 text-xs font-bold outline-none cursor-pointer w-full focus:border-violet-500" onchange="switchUseLang()">
                            <option value="fa">FA</option>
                            <option value="en">EN</option>
                        </select>
                    </div>
                    <div class="flex-grow flex items-end justify-end gap-2">
                        <button class="px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-black transition-all shadow-sm" id="btnBuild" onclick="compileFinalOutput()">⚡ ساخت خروجی</button>
                        <button class="px-4 py-2.5 bg-white border border-slate-200 text-slate-500 rounded-xl text-xs font-black transition-all" id="btnCopy" onclick="copyFinalPrompt()" disabled>📋 کپی متنی</button>
                        <button class="px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white rounded-xl text-xs font-black transition-all shadow-md" id="btnExecuteAI" onclick="runGeneratedPromptWithGemini()" disabled>🚀 اجرای با هوش مصنوعی</button>
                    </div>
                </div>
                
                <!-- Dynamic variables container -->
                <div class="space-y-2 border-b border-slate-100 pb-4">
                    <span class="text-[10px] text-slate-400 block font-bold">📝 مقادیر متغیرها را وارد کنید:</span>
                    <div id="useVars" class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1"></div>
                </div>
                
                <!-- Final calculated output textarea -->
                <div class="space-y-1.5">
                    <span class="text-[10px] text-slate-400 font-black block">پرامپت نهایی تولید شده:</span>
                    <textarea id="useOutput" class="w-full px-4 py-3 bg-slate-900 text-slate-100 rounded-2xl font-mono text-xs outline-none leading-relaxed text-right whitespace-pre-wrap select-all" rows="8" readonly placeholder="وقتی دکمه ساخت پرامپت نهایی را بزنید، خروجی تکمیل شده اینجا ظاهر خواهد شد..."></textarea>
                </div>

                <!-- AI Execution Live Result Container -->
                <div id="aiExecutionContainer" class="hidden mt-4 pt-4 border-t border-slate-150 space-y-2">
                    <span class="text-[10px] text-violet-600 font-extrabold block flex items-center gap-1">
                        <span class="animate-pulse">✨</span> پاسخ تولید شده توسط هوش مصنوعی (Gemini-3.5-Flash):
                    </span>
                    <div id="aiResponseOutput" class="w-full min-h-[120px] max-h-[300px] overflow-y-auto px-4 py-3 bg-violet-50/65 border border-violet-100 text-slate-800 rounded-2xl text-xs font-semibold leading-relaxed text-right whitespace-pre" dir="rtl">
                    </div>
                    <div class="flex justify-end">
                        <button class="px-3 py-1.5 bg-white border border-violet-200 hover:bg-violet-50 text-violet-700 text-[10px] font-black rounded-lg transition-all" onclick="copyAiResponseText()">📋 کپی پاسخ هوش مصنوعی</button>
                    </div>
                </div>
            </div>
            
            <div class="modal-foot">
                <div class="flex-grow"></div>
                <button class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-750 rounded-xl text-xs font-black" data-close>بستن دیالوگ</button>
            </div>
        </div>
    </div>

    <!-- 4. Categories Management Modal (#catsModal) -->
    <div class="modal hidden" id="catsModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <div class="modal-head">
                <h3 class="text-sm font-black text-slate-800 flex items-center gap-1.5">
                    <span>📁</span>
                    <span>مدیریت ساختار موضوعی دسته‌ها</span>
                </h3>
                <button class="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 font-extrabold text-xs" data-close>✕</button>
            </div>
            
            <div class="modal-body space-y-4">
                <div class="p-4 bg-slate-50 border border-slate-150 rounded-2xl space-y-3">
                    <h4 class="text-xs font-black text-slate-800">ایجاد دسته بندی جدید:</h4>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input id="catInput" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-violet-500 text-right" placeholder="نام دسته (مثال: کپشن ورزشی)">
                        <select id="catParent" class="w-full sm:w-48 bg-white border border-slate-200 rounded-xl px-2 py-2 text-xs font-bold outline-none cursor-pointer focus:border-violet-500">
                            <option value="">(دسته اصلی والد)</option>
                        </select>
                        <button class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-black transition-all flex items-center justify-center shadow-sm" id="catAdd" onclick="addCategory()">افزودن</button>
                    </div>
                </div>
                
                <div class="space-y-1.5">
                    <span class="text-[10px] text-slate-400 font-extrabold">منوی دسته‌های فعال کنونی:</span>
                    <ul id="catsList" class="divide-y divide-slate-100 max-h-64 overflow-y-auto border border-slate-200/60 rounded-2xl bg-white"></ul>
                </div>
            </div>
            
            <div class="modal-foot">
                <div class="flex-grow"></div>
                <button class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-black" data-close>بستن دیالوگ</button>
            </div>
        </div>
    </div>

    <!-- 5. Bulk Import Modal (#importModal) -->
    <div class="modal hidden" id="importModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <div class="modal-head">
                <h3 class="text-sm font-black text-slate-800 flex items-center gap-1.5">
                    <span>📤</span>
                    <span>ورود و ایمپورت گروهی پرامپت‌ها</span>
                </h3>
                <button class="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 font-extrabold text-xs" data-close>✕</button>
            </div>
            
            <div class="modal-body space-y-4">
                <!-- Tabs -->
                <div class="flex border-b border-slate-100">
                    <button class="tab px-4 py-2.5 text-xs font-black border-b-2 is-active" id="btnTabImportCode" onclick="switchImportTab('code')">🧬 کد رمزگذاری شده (Base64)</button>
                    <button class="tab px-4 py-2.5 text-xs font-black border-b-2 text-slate-400" id="btnTabImportJson" onclick="switchImportTab('json')">📋 قطعه کد آرایه JSON</button>
                </div>
                
                <div id="pane-code" class="space-y-2">
                    <p class="text-[11px] text-slate-405 leading-relaxed font-semibold">اگر کدی دریافت کرده‌اید که حاوی فرمت بیس۶۴ از آرایه پرامپت‌ها است، آن را مستقیماً در کادر زیر قرار دهید:</p>
                    <textarea id="importCode" class="w-full p-4 bg-slate-900 text-slate-100 rounded-2xl font-mono text-xs outline-none leading-relaxed text-left" rows="6" placeholder="Paste your Base64 encoded code here..."></textarea>
                </div>
                
                <div id="pane-json" class="space-y-2 hidden">
                    <p class="text-[11px] text-slate-405 leading-relaxed font-semibold">یک آرایه معتبر با پارامترهای ضروری (مانند title_fa, body_fa) به صورت JSON چسبانده شود:</p>
                    <textarea id="importJson" class="w-full p-4 bg-slate-900 text-slate-100 rounded-2xl font-mono text-xs outline-none leading-relaxed text-left" rows="8" placeholder='[{"title_fa": "تست", "body_fa": "کپشن آزمون {{name}}"}]'></textarea>
                </div>
            </div>
            
            <div class="modal-foot">
                <div class="flex-grow"></div>
                <button class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-black" data-close>انصراف</button>
                <button class="px-5 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-xs font-black shadow-md shadow-violet-900/10" id="btnDoImport" onclick="importBulkData()">شروع فرآیند تراکنش</button>
            </div>
        </div>
    </div>

    <!-- Script Application Logic -->
    <script>
        // State management
        let storagePolicy = 'shared';
        let categories = [];
        let prompts = [];
        let activeTab = 'approved';
        let canManage = false;
        let currentUser = 'unknown';
        
        let activePrompt = null;
        let activeUseLang = 'fa';
        let actLang = 'fa';
        let importTab = 'code';
        
        // Initializer
        document.addEventListener('DOMContentLoaded', () => {
            fetchHubData();
            setupGlobalEventListeners();
            setupTriggerDrawer();
        });
        
        function hideLoader() {
            document.getElementById('pageLoader').classList.add('hidden');
        }
        function showLoader() {
            document.getElementById('pageLoader').classList.remove('hidden');
        }
        
        // Fetch All Data
        function fetchHubData() {
            showLoader();
            const fd = new FormData();
            fd.append('ajax_action', 'get_data');
            
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    if (res.status === 'success') {
                        storagePolicy = res.storagePolicy;
                        categories = res.categories;
                        prompts = res.prompts;
                        canManage = res.can_manage;
                        currentUser = res.current_user;
                        
                        renderPolicy();
                        renderFilterCategories();
                        renderFormCategories();
                        renderPrompts();
                        renderCategoryList();
                    } else {
                        alert('خطا در دریافت اطلاعات: ' + res.msg);
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                    alert('خطای سرور در برقراری ارتباط با پورتال پرامپت‌ها.');
                });
        }
        
        function setupGlobalEventListeners() {
            // Modals close button behavior
            document.querySelectorAll('[data-close]').forEach(btn => {
                btn.addEventListener('click', () => {
                    closeAllModals();
                });
            });
            
            // Filters triggers change
            document.getElementById('q').addEventListener('input', () => renderPrompts());
            document.getElementById('cat').addEventListener('change', () => renderPrompts());
            document.getElementById('mode').addEventListener('change', () => renderPrompts());
            document.getElementById('tagsFilter').addEventListener('input', (e) => {
                renderPrompts();
                renderTagsSuggestions(e.target.value);
            });
            
            // Tab Pills buttons
            document.querySelectorAll('#tabs .pill').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    document.querySelectorAll('#tabs .pill').forEach(b => b.classList.remove('is-active'));
                    e.target.classList.add('is-active');
                    activeTab = e.target.getAttribute('data-tab');
                    renderPrompts();
                });
            });
            
            // Prompt New Create button
            const btnNew = document.getElementById('btnNew');
            if (btnNew) {
                btnNew.addEventListener('click', () => {
                    openPromptModal(null);
                });
            }
            
            // Import Bulk trigger button
            const btnImport = document.getElementById('btnImport');
            if (btnImport) {
                btnImport.addEventListener('click', () => {
                    document.getElementById('importCode').value = '';
                    document.getElementById('importJson').value = '';
                    openModal('importModal');
                });
            }
            
            // Categories trigger button
            const btnCats = document.getElementById('btnCats');
            if (btnCats) {
                btnCats.addEventListener('click', () => {
                    openModal('catsModal');
                });
            }
            
            // Form Submission
            const form = document.getElementById('promptForm');
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    savePrompt();
                });
            }
            
            // Policy save
            const savePolicy = document.getElementById('savePolicy');
            if (savePolicy) {
                savePolicy.addEventListener('click', () => {
                    const policySelect = document.getElementById('storagePolicy');
                    const fd = new FormData();
                    fd.append('ajax_action', 'save_policy');
                    fd.append('policy', policySelect.value);
                    
                    showLoader();
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            hideLoader();
                            alert(res.msg);
                            if (res.status === 'success') {
                                storagePolicy = policySelect.value;
                            }
                        })
                        .catch(e => {
                            hideLoader();
                            console.error(e);
                        });
                });
            }
        }
        
        function renderPolicy() {
            const select = document.getElementById('storagePolicy');
            if (select) {
                select.value = storagePolicy;
            }
        }
        
        function renderFilterCategories() {
            const select = document.getElementById('cat');
            if (!select) return;
            
            let html = '<option value="">همه دسته‌ها</option>';
            categories.forEach(c => {
                const parentText = c.parent ? '↳ ' : '● ';
                html += `<option value="${c.id}">${parentText}${c.name}</option>`;
            });
            select.innerHTML = html;
        }
        
        function renderFormCategories() {
            const select = document.getElementById('pCat');
            const parentSelect = document.getElementById('catParent');
            if (!select) return;
            
            let html = '';
            let parentHtml = '<option value="">(دسته اصلی والد)</option>';
            categories.forEach(c => {
                const parentText = c.parent ? '↳ ' : '● ';
                html += `<option value="${c.id}">${parentText}${c.name}</option>`;
                if (!c.parent) {
                    parentHtml += `<option value="${c.id}">${c.name}</option>`;
                }
            });
            select.innerHTML = html;
            if (parentSelect) parentSelect.innerHTML = parentHtml;
        }
        
        // Render Prompt Hub Lists
        function renderPrompts() {
            const listContainer = document.getElementById('list');
            const emptyContainer = document.getElementById('empty');
            if (!listContainer) return;
            
            listContainer.innerHTML = '';
            
            const q = document.getElementById('q').value.trim().toLowerCase();
            const cat = document.getElementById('cat').value;
            const mode = document.getElementById('mode').value;
            const tags = document.getElementById('tagsFilter').value.trim().toLowerCase();
            
            // Filter list based on criteria and active tab
            let filtered = prompts.filter(p => {
                // Tab filtering
                if (activeTab === 'approved') {
                    if (p.status !== 'approved') return false;
                } else if (activeTab === 'mine-pending') {
                    if (p.status !== 'pending' || p.uploaded_by !== currentUser) return false;
                } else if (activeTab === 'mine') {
                    if (p.uploaded_by !== currentUser) return false;
                } else if (activeTab === 'pending-all') {
                    if (p.status !== 'pending') return false;
                } else if (activeTab === 'rejected') {
                    if (p.status !== 'rejected') return false;
                }
                
                // Storage Policy filtering
                if (storagePolicy === 'per-user' && activeTab === 'approved') {
                    if (p.uploaded_by !== currentUser && p.id !== 'p_preset_1' && p.id !== 'p_preset_2') {
                        return false; 
                    }
                }
                
                // Search values
                if (q !== '') {
                    const matchesTitle = p.title_fa.toLowerCase().includes(q) || p.title_en.toLowerCase().includes(q);
                    const matchesBody = p.body_fa.toLowerCase().includes(q) || p.body_en.toLowerCase().includes(q);
                    const matchesDesc = p.desc_fa.toLowerCase().includes(q) || p.desc_en.toLowerCase().includes(q);
                    if (!matchesTitle && !matchesBody && !matchesDesc) return false;
                }
                
                // Category
                if (cat !== '' && p.category !== cat) return false;
                
                // Mode
                if (mode !== '' && p.mode !== mode) return false;
                
                // Tags
                if (tags !== '') {
                    const pTags = p.tags.toLowerCase();
                    if (!pTags.includes(tags)) return false;
                }
                
                return true;
            });
            
            if (filtered.length === 0) {
                emptyContainer.classList.remove('hidden');
            } else {
                emptyContainer.classList.add('hidden');
                
                filtered.forEach(p => {
                    const catName = (categories.find(c => c.id === p.category) || { name: 'سایر موضوعات' }).name;
                    const modeLabel = p.mode === 'text' ? '✍️ متنی' : (p.mode === 'image' ? '🎨 تصویری' : '🎥 ویدیویی');
                    const badges = p.tags.split(',').map(t => t.trim()).filter(Boolean);
                    
                    const el = document.createElement('div');
                    el.className = 'premium-card p-6 flex flex-col justify-between h-full gap-5 cursor-pointer';
                    el.onclick = () => openActionModal(p);
                    
                    let badgesHtml = '';
                    badges.slice(0, 3).forEach(b => {
                        badgesHtml += `<span class="bg-violet-50 text-violet-750 text-[9px] font-black px-2.5 py-0.5 rounded-full border border-violet-100 flex items-center">#${b}</span>`;
                    });
                    
                    let modeBg = p.mode === 'text' ? 'bg-amber-100/50 text-amber-800' : (p.mode === 'image' ? 'bg-indigo-100/50 text-indigo-805' : 'bg-red-105/50 text-red-800');
                    let statusHtml = '';
                    if (p.status === 'pending') {
                        statusHtml = `<span class="text-[9px] bg-amber-500 text-white font-black px-2 py-0.5 rounded-md">⏳ معلق</span>`;
                    } else if (p.status === 'rejected') {
                        statusHtml = `<span class="text-[9px] bg-rose-500 text-white font-black px-2 py-0.5 rounded-md">❌ رد شده</span>`;
                    }
                    
                    el.innerHTML = `
                        <div class="space-y-3 text-right">
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-[10px] bg-slate-100 text-slate-500 border border-slate-205 px-2.5 py-1 rounded-xl font-bold">${catName}</span>
                                <div class="flex items-center gap-1.5">
                                    ${statusHtml}
                                    <span class="text-[10px] ${modeBg} font-black px-2.5 py-1 rounded-full">${modeLabel}</span>
                                </div>
                            </div>
                            <h3 class="font-extrabold text-slate-800 text-xs md:text-sm mt-1 group-hover:text-violet-650 transition-colors leading-tight">${p.title_fa}</h3>
                            <h4 class="font-bold text-slate-400 text-[10px]" dir="ltr">${p.title_en || '—'}</h4>
                            <p class="text-[11px] text-slate-500 leading-relaxed font-medium line-clamp-3">${p.desc_fa || 'بدون توضیحات کاربردی.'}</p>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3 flex-shrink-0">
                            <div class="flex flex-wrap gap-1">${badgesHtml}</div>
                            <span class="text-[10px] text-slate-400 font-mono">طراح: <b class="font-sans text-slate-600">${p.uploaded_by}</b></span>
                        </div>
                    `;
                    listContainer.appendChild(el);
                });
            }
        }
        
        function openModal(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('hidden');
                el.setAttribute('aria-hidden', 'false');
            }
        }
        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(m => {
                m.classList.add('hidden');
                m.setAttribute('aria-hidden', 'true');
            });
        }
        
        // Setup Modal Form
        function openPromptModal(p = null) {
            activePrompt = p;
            const titleEl = document.getElementById('pmTitle');
            const deleteBtn = document.getElementById('btnDelete');
            
            document.getElementById('promptForm').reset();
            document.getElementById('pId').value = '';
            document.getElementById('samplePreview').innerHTML = 'پیش‌نمایش تصویر در صورت بارگذاری فعال می‌شود.';
            
            if (p) {
                titleEl.innerHTML = '<span>✏️</span> <span>ویرایش و ارتقاء پرامپت</span>';
                if (canManage) deleteBtn.classList.remove('hidden');
                
                document.getElementById('pId').value = p.id;
                document.getElementById('pTitleFa').value = p.title_fa;
                document.getElementById('pTitleEn').value = p.title_en;
                document.getElementById('pMode').value = p.mode;
                document.getElementById('pKind').value = p.kind;
                document.getElementById('pDescFa').value = p.desc_fa;
                document.getElementById('pDescEn').value = p.desc_en;
                document.getElementById('pCat').value = p.category;
                document.getElementById('pTags').value = p.tags;
                document.getElementById('pBodyFa').value = p.body_fa;
                document.getElementById('pBodyEn').value = p.body_en;
                document.getElementById('pSampleText').value = p.sample_text;
                
                if (p.sample_file) {
                    document.getElementById('samplePreview').innerHTML = `<img src="${p.sample_file}" class="w-full h-24 object-cover rounded-xl mt-2">`;
                }
                
                detectVariables('fa');
            } else {
                titleEl.innerHTML = '<span>➕</span> <span>افزودن فرمول پرامپت جدید</span>';
                deleteBtn.classList.add('hidden');
                document.getElementById('pKind').value = 'templated';
                detectVariables('fa');
            }
            
            toggleKindVars();
            openModal('promptModal');
        }
        
        function toggleKindVars() {
            const kind = document.getElementById('pKind').value;
            const varsBox = document.getElementById('varsBox');
            if (kind === 'templated') {
                varsBox.style.display = 'flex';
            } else {
                varsBox.style.display = 'none';
            }
        }
        
        function detectVariables(lang) {
            const textarea = lang === 'fa' ? document.getElementById('pBodyFa') : document.getElementById('pBodyEn');
            if (!textarea) return;
            
            const text = textarea.value;
            const regex = /\{\{([^}]+)\}\}|\{([^}]+)\}/g;
            let match;
            const detected = new Set();
            
            while ((match = regex.exec(text)) !== null) {
                const varName = match[1] || match[2];
                if (varName && varName.trim()) {
                    detected.add(varName.trim());
                }
            }
            
            const list = document.getElementById('varsList');
            if (list) {
                list.innerHTML = '';
                if (detected.size === 0) {
                    list.innerHTML = '<span class="text-[10px] text-slate-400 italic">هیچ الگو یا متغیری در کادر وارد نشده است.</span>';
                } else {
                    detected.forEach(v => {
                        const span = document.createElement('span');
                        span.className = 'px-2.5 py-1 bg-violet-100 text-violet-800 text-[10px] font-bold rounded-lg border border-violet-200';
                        span.textContent = v;
                        list.appendChild(span);
                    });
                }
            }
        }
        
        // Save Prompt Handler
        function savePrompt() {
            const form = document.getElementById('promptForm');
            const fd = new FormData(form);
            fd.append('ajax_action', 'save_prompt');
            fd.append('id', document.getElementById('pId').value);
            
            if (activePrompt && activePrompt.sample_file) {
                fd.append('existing_sample_file', activePrompt.sample_file);
            }
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        closeAllModals();
                        fetchHubData();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                    alert('خطای اتصال در حین عملیات ثبت پرامپت.');
                });
        }
        
        // Delete Prompt
        function deletePromptPrompt() {
            if (!activePrompt) return;
            if (!confirm('آیا از حذف کامل این فرمول پرامپت اطمینان دارید؟')) return;
            
            const fd = new FormData();
            fd.append('ajax_action', 'delete_prompt');
            fd.append('id', activePrompt.id);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        closeAllModals();
                        fetchHubData();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                });
        }
        
        // Action View Modal
        function openActionModal(p) {
            activePrompt = p;
            
            document.getElementById('actTitleText').textContent = p.title_fa;
            document.getElementById('actCategory').textContent = (categories.find(c => c.id === p.category) || { name: 'سایر موضوعات' }).name;
            document.getElementById('actMode').textContent = p.mode === 'text' ? '✍️ متنی و نوشتاری' : (p.mode === 'image' ? '🎨 تصویرسازی و دکور' : '🎥 ویدیو و حرکت');
            document.getElementById('actKind').textContent = p.kind === 'templated' ? 'داینامیک دارای قالب متغیر' : 'آماده بدون متغیر';
            document.getElementById('actVisibility').textContent = p.uploaded_by;
            
            let statusBadge = 'فعال';
            if (p.status === 'pending') statusBadge = '⏳ معلق در صف ممیزی';
            else if (p.status === 'rejected') statusBadge = '❌ ابطال صلاحیت';
            document.getElementById('actStatus').textContent = statusBadge;
            
            document.getElementById('actDescFa').textContent = p.desc_fa || 'توضیحات فارسی ندارد.';
            document.getElementById('actDescEn').textContent = p.desc_en || 'No English description.';
            
            document.getElementById('actBodyFa').textContent = p.body_fa;
            document.getElementById('actBodyEn').textContent = p.body_en || 'No English translation.';
            
            // Render act tags
            const actTagsDiv = document.getElementById('actTags');
            actTagsDiv.innerHTML = '';
            if (p.tags) {
                p.tags.split(',').forEach(tag => {
                    const span = document.createElement('span');
                    span.className = 'px-3 py-1 bg-slate-100 text-slate-700 rounded-full text-[9px] font-black border border-slate-205';
                    span.textContent = '#' + tag.trim();
                    actTagsDiv.appendChild(span);
                });
            } else {
                actTagsDiv.innerHTML = '<span class="text-[10px] text-slate-400">بدون تگ</span>';
            }
            
            // Vars in act modal
            const varsBoxAct = document.getElementById('varsBoxAct');
            const varsListAct = document.getElementById('varsListAct');
            varsListAct.innerHTML = '';
            
            const regex = /\{\{([^}]+)\}\}|\{([^}]+)\}/g;
            let match;
            const detected = new Set();
            while ((match = regex.exec(p.body_fa)) !== null) {
                const varName = match[1] || match[2];
                if (varName && varName.trim()) {
                    detected.add(varName.trim());
                }
            }
            
            if (p.kind === 'templated' && detected.size > 0) {
                varsBoxAct.classList.remove('hidden');
                detected.forEach(v => {
                    const span = document.createElement('span');
                    span.className = 'px-2.5 py-1 bg-violet-100 text-violet-800 text-[10px] font-bold rounded-lg border border-violet-200';
                    span.textContent = v;
                    varsListAct.appendChild(span);
                });
            } else {
                varsBoxAct.classList.add('hidden');
            }
            
            // Sample Text
            document.getElementById('actSampleText').textContent = p.sample_text || 'نمونه متنی به همراه این پرامپت تدارک نیافته.';
            
            // Sample Media
            const mediaDiv = document.getElementById('actSampleMedia');
            mediaDiv.innerHTML = '';
            if (p.sample_file) {
                mediaDiv.innerHTML = `<img src="${p.sample_file}" class="max-w-full max-h-40 object-contain rounded-xl">`;
            } else {
                mediaDiv.innerHTML = '<span class="text-[10px] text-slate-400 italic">فایل نمونه آپلود نشده است</span>';
            }
            
            // Use buttons
            document.getElementById('actUseFa').onclick = () => {
                closeAllModals();
                openUseModal(p, 'fa');
            };
            document.getElementById('actUseEn').onclick = () => {
                closeAllModals();
                openUseModal(p, 'en');
            };
            
            // Admin only actions
            const adminActions = document.getElementById('adminActions');
            if (canManage) {
                adminActions.classList.remove('hidden');
            } else {
                adminActions.classList.add('hidden');
            }
            
            switchActLang('fa');
            openModal('actionModal');
        }
        
        function switchActLang(lang) {
            actLang = lang;
            const tabs = document.querySelectorAll('#langTabsAct .tab');
            tabs.forEach(t => {
                if (t.getAttribute('data-lang') === lang) t.classList.add('is-active');
                else t.classList.remove('is-active');
            });
            
            if (lang === 'fa') {
                document.getElementById('langPaneAct-fa').classList.remove('hidden');
                document.getElementById('langPaneAct-en').classList.add('hidden');
            } else {
                document.getElementById('langPaneAct-fa').classList.add('hidden');
                document.getElementById('langPaneAct-en').classList.remove('hidden');
            }
        }
        
        // Use Prompt Modal
        function openUseModal(p, lang) {
            activePrompt = p;
            activeUseLang = lang;
            document.getElementById('useLang').value = lang;
            
            // Clear and hide previous AI executions
            const aiContainer = document.getElementById('aiExecutionContainer');
            if (aiContainer) aiContainer.classList.add('hidden');
            const responseDiv = document.getElementById('aiResponseOutput');
            if (responseDiv) responseDiv.innerHTML = '';
            
            renderUseVariables();
            compileFinalOutput();
            openModal('useModal');
        }
        
        function switchUseLang() {
            activeUseLang = document.getElementById('useLang').value;
            renderUseVariables();
            compileFinalOutput();
        }
        
        function renderUseVariables() {
            const bodyText = activeUseLang === 'fa' ? activePrompt.body_fa : activePrompt.body_en;
            const useVars = document.getElementById('useVars');
            useVars.innerHTML = '';
            
            if (activePrompt.kind !== 'templated') {
                useVars.innerHTML = '<div class="col-span-full text-center text-xs text-slate-400 font-extrabold italic">این پرامپت ایستا است و بدون نیاز به اصلاح متغیر قابل استفاده می‌باشد!</div>';
                return;
            }
            
            const regex = /\{\{([^}]+)\}\}|\{([^}]+)\}/g;
            let match;
            const detected = new Set();
            while ((match = regex.exec(bodyText)) !== null) {
                const varName = match[1] || match[2];
                if (varName && varName.trim()) {
                    detected.add(varName.trim());
                }
            }
            
            if (detected.size === 0) {
                useVars.innerHTML = '<div class="col-span-full text-center text-xs text-slate-400 font-extrabold italic">هیچ متغیری یافت نشد.</div>';
                return;
            }
            
            detected.forEach(v => {
                const label = document.createElement('label');
                label.className = 'flex flex-col gap-1';
                
                const span = document.createElement('span');
                span.className = 'text-[10px] text-slate-500 font-bold';
                span.textContent = v;
                
                const input = document.createElement('input');
                input.className = 'w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-violet-500 var-input';
                input.setAttribute('data-var', v);
                input.placeholder = `مقدار دهی ${v}...`;
                input.addEventListener('input', () => compileFinalOutput());
                
                label.appendChild(span);
                label.appendChild(input);
                useVars.appendChild(label);
            });
        }
        
        function compileFinalOutput() {
            let bodyText = activeUseLang === 'fa' ? activePrompt.body_fa : activePrompt.body_en;
            
            if (activePrompt.kind === 'templated') {
                const inputs = document.querySelectorAll('#useVars .var-input');
                inputs.forEach(inp => {
                    const variableName = inp.getAttribute('data-var');
                    const value = inp.value || `{{${variableName}}}`;
                    
                    // Replace regex for both standard formats
                    const cleanVar = variableName.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
                    const reBraces = new RegExp('\\{\\{' + cleanVar + '\\}\\}', 'g');
                    const reSingle = new RegExp('\\{' + cleanVar + '\\}', 'g');
                    
                    bodyText = bodyText.replace(reBraces, value).replace(reSingle, value);
                });
            }
            
            const output = document.getElementById('useOutput');
            const btnCopy = document.getElementById('btnCopy');
            const btnExecuteAI = document.getElementById('btnExecuteAI');
            
            output.value = bodyText;
            if (btnCopy) btnCopy.disabled = false;
            if (btnExecuteAI) btnExecuteAI.disabled = false;
        }
        
        function copyFinalPrompt() {
            const out = document.getElementById('useOutput');
            out.select();
            document.execCommand('copy');
            alert('متن با موفقیت به حافظه موقت سیستم کپی شد! ✅');
        }

        function runGeneratedPromptWithGemini() {
            const promptStr = document.getElementById('useOutput').value;
            if (!promptStr || promptStr.trim() === '') {
                alert('لطفا ابتدا پرامپت نهایی را بسازید یا کادر خالی را اصلاح کنید.');
                return;
            }
            
            const btnExecuteAI = document.getElementById('btnExecuteAI');
            const container = document.getElementById('aiExecutionContainer');
            const responseDiv = document.getElementById('aiResponseOutput');
            
            if (btnExecuteAI) {
                btnExecuteAI.disabled = true;
                btnExecuteAI.innerHTML = '⏱️ در حال تولید...';
            }
            
            container.classList.remove('hidden');
            responseDiv.innerHTML = '<span class="text-slate-400 animate-pulse font-bold">⏱️ در حال فراخوانی موتور هوش مصنوعی Gemini... لطفا چند ثانیه صبور باشید.</span>';
            
            const fd = new FormData();
            fd.append('ajax_action', 'execute_prompt_ai');
            fd.append('prompt', promptStr);
            
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (btnExecuteAI) {
                        btnExecuteAI.disabled = false;
                        btnExecuteAI.innerHTML = '🚀 اجرای با هوش مصنوعی';
                    }
                    if (res.status === 'success') {
                        responseDiv.textContent = res.response;
                    } else {
                        responseDiv.innerHTML = `<span class="text-rose-650 font-extrabold flex items-center gap-1">❌ خطا در پردازش هوش مصنوعی: ${res.msg}</span>`;
                    }
                })
                .catch(err => {
                    if (btnExecuteAI) {
                        btnExecuteAI.disabled = false;
                        btnExecuteAI.innerHTML = '🚀 اجرای با هوش مصنوعی';
                    }
                    responseDiv.innerHTML = `<span class="text-rose-650 font-extrabold">❌ خطای ارتباطی با سرور در ارسال درخواست.</span>`;
                });
        }
        
        function copyAiResponseText() {
            const el = document.getElementById('aiResponseOutput');
            const text = el.textContent || el.innerText || '';
            if (!text || text.includes('⏱️ در حال فراخوانی') || text.includes('❌ خطا')) {
                alert('پاسخ معتبری وجود ندارد.');
                return;
            }
            
            const tempTextarea = document.createElement('textarea');
            tempTextarea.value = text;
            document.body.appendChild(tempTextarea);
            tempTextarea.select();
            document.execCommand('copy');
            document.body.removeChild(tempTextarea);
            alert('پاسخ هوش مصنوعی با موفقیت به حافظه موقت کپی شد! ✅');
        }
        
        // Admin Approval/Reject functions
        function approvePrompt() {
            if (!activePrompt) return;
            const fd = new FormData();
            fd.append('ajax_action', 'approve_prompt');
            fd.append('id', activePrompt.id);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        closeAllModals();
                        fetchHubData();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                });
        }
        
        function rejectPrompt() {
            if (!activePrompt) return;
            const fd = new FormData();
            fd.append('ajax_action', 'reject_prompt');
            fd.append('id', activePrompt.id);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        closeAllModals();
                        fetchHubData();
                    }
                })
                .catch(e => {
                    hideLoader();
                });
        }
        
        function editActivePrompt() {
            if (!activePrompt) return;
            const p = activePrompt;
            closeAllModals();
            openPromptModal(p);
        }
        
        // Categories list render in modal
        function renderCategoryList() {
            const list = document.getElementById('catsList');
            if (!list) return;
            
            list.innerHTML = '';
            if (categories.length === 0) {
                list.innerHTML = '<li class="p-4 text-center text-xs text-slate-400 italic">هیچ دسته‌ای تعریف نشده است.</li>';
                return;
            }
            
            categories.forEach(c => {
                const isChild = !!c.parent;
                const parentName = isChild ? (categories.find(p => p.id === c.parent) || { name: 'نامشخص' }).name : '';
                
                const li = document.createElement('li');
                li.className = `flex justify-between items-center p-3.5 text-xs text-slate-700 hover:bg-slate-50 transition-all ${isChild ? 'bg-slate-50/50 pr-8' : ''}`;
                
                li.innerHTML = `
                    <div class="flex items-center gap-1.5 font-bold">
                        <span>${isChild ? '↳ ' : '●'}</span>
                        <span>${c.name}</span>
                        ${isChild ? `<span class="text-[9px] bg-slate-200 px-2 py-0.5 rounded text-slate-500 font-extrabold mb-0.5">(والد: ${parentName})</span>` : ''}
                    </div>
                    <button class="text-rose-500 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 p-1.5 rounded-lg font-black" onclick="deleteCategory('${c.id}')">✕ حذف</button>
                `;
                list.appendChild(li);
            });
        }
        
        function addCategory() {
            const input = document.getElementById('catInput');
            const parent = document.getElementById('catParent');
            const name = input.value.trim();
            if (!name) return;
            
            const fd = new FormData();
            fd.append('ajax_action', 'save_category');
            fd.append('name', name);
            fd.append('parent', parent.value);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        categories = res.categories;
                        input.value = '';
                        renderFormCategories();
                        renderFilterCategories();
                        renderCategoryList();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                });
        }
        
        function deleteCategory(id) {
            if (!confirm('آیا از حذف این دسته موضوعی اطمینان کامل دارید؟')) return;
            
            const fd = new FormData();
            fd.append('ajax_action', 'delete_category');
            fd.append('id', id);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        categories = res.categories;
                        renderFormCategories();
                        renderFilterCategories();
                        renderCategoryList();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                });
        }
        
        // Import Bulk 
        function switchImportTab(tab) {
            importTab = tab;
            const btnCode = document.getElementById('btnTabImportCode');
            const btnJson = document.getElementById('btnTabImportJson');
            const paneCode = document.getElementById('pane-code');
            const paneJson = document.getElementById('pane-json');
            
            if (tab === 'code') {
                btnCode.classList.add('is-active');
                btnCode.classList.remove('text-slate-400');
                btnJson.classList.remove('is-active');
                btnJson.classList.add('text-slate-400');
                paneCode.classList.remove('hidden');
                paneJson.classList.add('hidden');
            } else {
                btnJson.classList.add('is-active');
                btnJson.classList.remove('text-slate-400');
                btnCode.classList.remove('is-active');
                btnCode.classList.add('text-slate-400');
                paneJson.classList.remove('hidden');
                paneCode.classList.add('hidden');
            }
        }
        
        function importBulkData() {
            const code = document.getElementById('importCode').value.trim();
            const json = document.getElementById('importJson').value.trim();
            
            if (importTab === 'code' && !code) {
                alert('لطفاً کد دریافتی خود را پیست کنید.');
                return;
            }
            if (importTab === 'json' && !json) {
                alert('لطفاً قطعه کد آرایه JSON را پیست کنید.');
                return;
            }
            
            const fd = new FormData();
            fd.append('ajax_action', 'import_prompts');
            fd.append('type', importTab);
            fd.append('code', code);
            fd.append('json', json);
            
            showLoader();
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoader();
                    alert(res.msg);
                    if (res.status === 'success') {
                        closeAllModals();
                        fetchHubData();
                    }
                })
                .catch(e => {
                    hideLoader();
                    console.error(e);
                    alert('خطای اتصال در ورود نهایی آرایه پرامپت‌ها.');
                });
        }
        
        // Suggest / Tag Suggestions
        function renderTagsSuggestions(val) {
            const suggest = document.getElementById('tagSuggest');
            if (!val || val.trim() === '') {
                suggest.classList.add('hidden');
                return;
            }
            
            const uniqueTags = new Set();
            prompts.forEach(p => {
                if (p.tags) {
                    p.tags.split(',').forEach(tag => {
                        const cleanTag = tag.trim().toLowerCase();
                        if (cleanTag && cleanTag.includes(val.toLowerCase())) {
                            uniqueTags.add(tag.trim());
                        }
                    });
                }
            });
            
            if (uniqueTags.size === 0) {
                suggest.classList.add('hidden');
                return;
            }
            
            suggest.innerHTML = '';
            suggest.classList.remove('hidden');
            
            uniqueTags.forEach(tag => {
                const item = document.createElement('div');
                item.className = 'suggest-item text-right font-bold';
                item.textContent = tag;
                item.onclick = () => {
                    document.getElementById('tagsFilter').value = tag;
                    suggest.classList.add('hidden');
                    renderPrompts();
                };
                suggest.appendChild(item);
            });
        }
        
        function resetFilters() {
            document.getElementById('q').value = '';
            document.getElementById('cat').value = '';
            document.getElementById('mode').value = '';
            document.getElementById('tagsFilter').value = '';
            document.getElementById('tagSuggest').classList.add('hidden');
            renderPrompts();
        }
        
        function setupTriggerDrawer() {
            const btn = document.getElementById('btnFilters');
            const overlay = document.getElementById('filtersOverlay');

            function closeDrawer(){ document.body.classList.remove('filters-open'); }
            function toggleDrawer(){ document.body.classList.toggle('filters-open'); }

            btn && btn.addEventListener('click', toggleDrawer);
            overlay && overlay.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

            let lastW = window.innerWidth;
            window.addEventListener('scroll', () => {
                if (window.innerWidth <= 1100) closeDrawer();
            }, { passive: true });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 1100 && lastW <= 1100) closeDrawer();
                lastW = window.innerWidth;
            });
        }
    </script>
</body>
</html>
