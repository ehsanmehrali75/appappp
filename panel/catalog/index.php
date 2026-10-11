<?php
/**
 * Standalone Online Catalog Builder Module
 * Route: /catalog
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ]);
    }
    session_start();
}

$functionsPath = __DIR__ . '/../inc/functions.php';
if (!file_exists($functionsPath)) {
    die('خطای سیستم: فایل توابع یافت نشد.');
}
require_once $functionsPath;

// User authentication check
if (!isUser()) {
    redirect('/index.php');
}

// Check Catalog permission, allow administrators or users with permission
if (!hasPermission('catalog') && !hasPermission('settings') && !hasPermission('products')) {
    die('<div style="font-family:Tahoma; text-align:center; padding: 100px; direction: rtl; background-color: #f8fafc; height: 100vh;">
        <div style="background: white; max-width: 500px; margin: auto; padding: 40px; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
            <h2 style="color:#ef4444; font-size: 24px; margin-bottom: 16px;">⚠️ عدم دسترسی کافی</h2>
            <p style="color:#64748b; font-size: 14px; line-height: 2;">کاربر گرامی، شما مجوزهای لازم جهت دسترسی به ماژول سازنده کاتالوگ آنلاین را ندارید. لطفاً با مدیر سیستم یا پروژه تماس بگیرید.</p>
            <hr style="margin: 24px 0; border: 0; border-top: 1px solid #f1f5f9;">
            <a href="../admin.php" style="display: inline-block; background: #4f46e5; color: white; padding: 12px 24px; border-radius: 12px; font-weight: bold; text-decoration: none; font-size: 13px;">← بازگشت به پنل اصلی</a>
        </div>
    </div>');
}

// Project Switcher inside Catalog
if (isset($_GET['switch_project'])) {
    $swPid = $_GET['switch_project'];
    $hasAccess = false;
    if (isSuperAdmin() || isProjectAdmin() || hasPermission('all') || hasPermission('catalog')) {
        $hasAccess = true;
    } else {
        $db = getDB();
        $username = $_SESSION['user']['username'] ?? '';
        foreach (($db['projects'] ?? []) as $p) {
            if ($p['id'] === $swPid) {
                foreach (($p['users'] ?? []) as $u) {
                    if ($u['username'] === $username) {
                        $hasAccess = true;
                        break;
                    }
                }
            }
        }
    }
    if ($hasAccess) {
        $_SESSION['project_id'] = $swPid;
    }
    redirect('index.php?project=' . urlencode($swPid));
}

if (isset($_GET['project'])) {
    $swPid = $_GET['project'];
    $hasAccess = false;
    if (isSuperAdmin() || isProjectAdmin() || hasPermission('all') || hasPermission('catalog')) {
        $hasAccess = true;
    } else {
        $db = getDB();
        $username = $_SESSION['user']['username'] ?? '';
        foreach (($db['projects'] ?? []) as $p) {
            if ($p['id'] === $swPid) {
                foreach (($p['users'] ?? []) as $u) {
                    if ($u['username'] === $username) {
                        $hasAccess = true;
                        break;
                    }
                }
            }
        }
    }
    if ($hasAccess) {
        $_SESSION['project_id'] = $swPid;
    }
} else {
    // If project is not set in URL, find the best project and redirect automatically to make URL project-specific
    $current_pid = $_SESSION['project_id'] ?? null;
    if (!$current_pid) {
        $db = getDB();
        if (isSuperAdmin() || isProjectAdmin()) {
            if (!empty($db['projects'])) {
                $first_p = reset($db['projects']);
                $current_pid = $first_p['id'] ?? null;
            }
        } else {
            $username = $_SESSION['user']['username'] ?? '';
            foreach (($db['projects'] ?? []) as $p) {
                $users = $p['users'] ?? [];
                foreach ($users as $u) {
                    if ($u['username'] === $username) {
                        $current_pid = $p['id'];
                        break 2;
                    }
                }
            }
        }
    }
    if ($current_pid) {
        $_SESSION['project_id'] = $current_pid;
        redirect('index.php?project=' . urlencode($current_pid));
    }
}

$allProjects = [];
$db = getDB();
if (isSuperAdmin() || isProjectAdmin() || hasPermission('all')) {
    $allProjects = $db['projects'] ?? [];
} else {
    $username = $_SESSION['user']['username'] ?? '';
    foreach (($db['projects'] ?? []) as $p) {
        $users = $p['users'] ?? [];
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                $allProjects[] = $p;
                break;
            }
        }
    }
}

require_once __DIR__ . '/../vm/vm_db.php';
$reqProject = $_GET['project'] ?? ($_SESSION['project_id'] ?? null);
$project = getCurrentProject($reqProject);
if (!$project) {
    $swPid = $reqProject ?: '6a00feeee7669';
    $vmPrj = VmDB::getProject($swPid);
    $project = getCurrentProject($swPid);
    if (!$project) {
        $dbProjects = getDB()['projects'] ?? [];
        if (!empty($dbProjects)) {
            $project = reset($dbProjects);
        }
    }
}
if (!$project) {
    die('پروژه فعال یافت نشد.');
}

// Ensure project products are populated from Central Products Library / VmDB if empty
if (empty($project['products'])) {
    $vmProds = VmDB::getProductsByProject($project['id']);
    if (!empty($vmProds)) {
        foreach ($vmProds as $vp) {
            $project['products'][] = [
                'id' => $vp['id'],
                'sku' => $vp['sku'] ?? $vp['id'],
                'name' => $vp['name'],
                'image' => $vp['image'],
                'social_image' => $vp['image'],
                'gallery' => [$vp['image']],
                'price' => $vp['price_formatted'] ?? ($vp['price'] . ' تومان'),
                'stock_online' => (string)($vp['stock'] ?? 50),
                'stock_total' => (string)($vp['stock'] ?? 50),
                'status' => 'active',
                'color' => $vp['category'] ?? 'clothing'
            ];
        }
    }
}

if (empty($allProjects)) {
    $allProjects[] = $project;
}

// Handle Ajax actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ajax: File Upload Handler
if ($action === 'catalog_image_upload') {
    if (!empty($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $dir = __DIR__ . '/../uploads/';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $filename = 'catalog_asset_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) {
            chmod($dir . $filename, 0644);
            echo json_encode(['success' => true, 'url' => 'uploads/' . $filename], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'ارسال فایل با خطا مواجه شد.']);
    exit;
}

// Ajax: Save Catalogs Handler
if ($action === 'save_catalogs') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if ($data !== null) {
        $catalogs = $data['catalogs'] ?? [];
        $catalog_settings = $data['catalog_settings'] ?? [];
        
        $project['settings']['catalogs'] = $catalogs;
        $project['settings']['catalog_settings'] = $catalog_settings;
        
        saveProject($project);
        echo json_encode(['success' => true, 'message' => 'کاتالوگ‌ها با موفقیت ذخیره شدند.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'داده‌های ارسالی نامعتبر است.']);
    }
    exit;
}

// Ajax: AI Catalog content generator
if ($action === 'catalog_generate_ai') {
    $prompt = $_POST['prompt'] ?? '';
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'متن پرامپت ارسالی خالی است.']);
        exit;
    }
    
    $apiKey = $project['settings']['gemini_api_key'] ?? $project['settings']['connections']['gemini_api_key'] ?? '';
    $res = geminiGenerateResponse($prompt, "You are an elite, modern retail and clothing Copywriter. Write engaging, poetic or minimalist Persian/English headings and paragraphs of descriptions directly in JSON. Output only valid raw JSON matching the exact schema requested.", $apiKey);
    
    if (isset($res['error'])) {
        echo json_encode(['success' => false, 'message' => $res['error']]);
    } else {
        echo json_encode(['success' => true, 'response' => $res['text']]);
    }
    exit;
}

// Load existing catalogs
$catalogs = $project['settings']['catalogs'] ?? [];

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$dir = dirname(dirname($script));
if ($dir === '/' || $dir === '\\') {
    $dir = '';
}
$baseUrl = $protocol . $host . $dir . '/';

if (!function_exists('resolveImageUrlPHP')) {
    function resolveImageUrlPHP($url, $baseUrl) {
        if (empty($url)) return '';
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, 'data:') === 0 || strpos($url, '//') === 0) {
            return $url;
        }
        $clean = $url;
        if (strpos($clean, '/') === 0) {
            $clean = substr($clean, 1);
        }
        if (strpos($clean, '../') === 0) {
            $clean = substr($clean, 3);
        }
        return $baseUrl . $clean;
    }
}

$catalog_settings = $project['settings']['catalog_settings'] ?? [
    'brand_title' => $project['settings']['size_chart_settings']['brand_title'] ?? $project['name'] ?? '',
    'brand_subtitle' => $project['settings']['size_chart_settings']['brand_subtitle'] ?? 'کاتالوگ رسمی محصولات',
    'image_logo' => $project['settings']['size_chart_settings']['image_logo'] ?? '',
    'brand_website' => $project['settings']['size_chart_settings']['brand_website'] ?? ''
];

// Clean products array to expose to JS library browser (prevent heavy DOM/data load)
$slimProducts = [];
foreach (($project['products'] ?? []) as $prod) {
    if (empty($prod['id'])) continue;
    $gal = $prod['gallery'] ?? [];
    if (is_string($gal)) {
        $gal = json_decode($gal, true) ?: [];
    }
    $refGal = $prod['reference_gallery'] ?? [];
    if (is_string($refGal)) {
        $refGal = json_decode($refGal, true) ?: [];
    }
    
    // Check approval states
    $images_list = $prod['images_list'] ?? [];
    if (is_string($images_list)) {
        $images_list = json_decode($images_list, true) ?: [];
    }
    
    $mainImg = $prod['image'] ?? ($prod['social_image'] ?? '');
    if (empty($mainImg) && !empty($gal) && is_array($gal)) {
        $mainImg = reset($gal);
    }
    
    $slimProducts[] = [
        'id' => $prod['id'],
        'sku' => $prod['sku'] ?? $prod['id'],
        'name' => $prod['name'] ?? 'بدون نام',
        'image' => $mainImg,
        'social_image' => $prod['social_image'] ?? $mainImg,
        'gallery' => $gal,
        'reference_gallery' => $refGal,
        'images_list' => $images_list,
        'price' => $prod['price'] ?? '',
        'stock' => $prod['stock_total'] ?? ($prod['stock_online'] ?? 0),
        'status' => $prod['status'] ?? 'active'
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سازنده کاتالوگ آنلاین - <?php echo htmlspecialchars($project['name']); ?></title>
    
    <!-- Vazirmatn Persian Font -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    
    <!-- Google Inter Font for LTR -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800;900&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS -->
    <script src="../assets/tailwindcss.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4f46e5',
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95',
                        }
                    },
                    fontFamily: {
                        sans: ['Vazirmatn', 'ui-sans-serif', 'system-ui'],
                        en: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f8fafc;
        }
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .catalog-page-grid {
            page-break-inside: avoid;
        }

        /* Screen Preview Paper Mockup Sizes */
        .paper-a4-portrait {
            width: 210mm !important;
            min-height: 297mm !important;
        }
        .paper-a4-landscape {
            width: 297mm !important;
            min-height: 210mm !important;
        }
        .paper-a5-portrait {
            width: 148mm !important;
            min-height: 210mm !important;
        }
        .paper-a5-landscape {
            width: 210mm !important;
            min-height: 148mm !important;
        }
        .paper-square {
            width: 210mm !important;
            min-height: 210mm !important;
        }

        /* Pure & Professional Print Styling */
        @media print {
            body {
                background: white !important;
                color: #0f172a !important;
                font-family: 'Vazirmatn', 'Tahoma', sans-serif !important;
                font-size: 11pt !important;
                line-height: 1.62 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            /* Hide non-printable panels & UI controls */
            #h-top, 
            #s-menu, 
            header, 
            aside,
            #language-indicator,
            .preview-controls-bar, /* selector control panel wrapper */
            #workspace-tab-edit,
            button,
            select,
            footer,
            iframe,
            .absolute.top-5.left-5.bg-white\/90,
            .ribbon-tag {
                display: none !important;
                visibility: hidden !important;
            }
            
            #workspace-tab-preview {
                display: block !important;
                visibility: visible !important;
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
                width: 100% !important;
                height: auto !important;
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
            }
            
            #showcase-outer-paper {
                display: flex !important;
                visibility: visible !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 10mm 12mm !important;
                background: white !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
                box-sizing: border-box !important;
            }

            .catalog-page-grid {
                page-break-after: always;
                page-break-inside: avoid !important;
                border-bottom: none !important;
                padding-bottom: 0 !important;
                margin-bottom: 15mm !important;
            }

            /* Adjust layouts for direct printing */
            .grid {
                display: grid !important;
                gap: 8mm !important;
            }
            .grid-cols-1 {
                grid-template-columns: repeat(1, minmax(0, 1fr)) !important;
            }
            .grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .grid-cols-3 {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
            
            /* Typography & clean prose details */
            h1 {
                font-size: 20pt !important;
                font-weight: 900 !important;
                color: #5333ed !important;
                margin-top: 15px !important;
                margin-bottom: 8px !important;
                line-height: 1.4 !important;
            }
            h2 {
                font-size: 15pt !important;
                font-weight: 850 !important;
                color: #0f172a !important;
                border-bottom: 1.5px solid #e2e8f0 !important;
                padding-bottom: 4px !important;
                margin-top: 12px !important;
                margin-bottom: 6px !important;
            }
            h3 {
                font-size: 12pt !important;
                font-weight: 800 !important;
                color: #334155 !important;
                margin-top: 10px !important;
                margin-bottom: 5px !important;
            }
            p {
                font-size: 10pt !important;
                font-weight: 500 !important;
                color: #334155 !important;
                margin-bottom: 8px !important;
                text-align: justify !important;
                page-break-inside: avoid !important;
            }
            li {
                font-size: 9.5pt !important;
                color: #475569 !important;
                margin-bottom: 4px !important;
                page-break-inside: avoid !important;
            }
            
            /* Enforce image rendering */
            img {
                max-width: 100% !important;
                page-break-inside: avoid !important;
                border-radius: 8px !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* Header/Footer fix for print */
            #showcase-header {
                border-bottom: 2px solid #5333ed !important;
                margin-bottom: 8mm !important;
                padding-bottom: 12px !important;
            }
            #showcase-footer {
                border-top: 1px solid #e2e8f0 !important;
                padding-top: 8px !important;
                margin-top: 8mm !important;
            }
        }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col">

    <!-- Top Bar -->
    <header id="h-top" class="bg-white border-b border-slate-150 px-6 py-4 flex flex-col md:flex-row items-center justify-between gap-4 shadow-sm shrink-0 z-20">
        <div class="flex items-center gap-3">
            <span class="text-3xl">📖</span>
            <div>
                <h1 id="app-title" class="text-lg font-black text-slate-800">سازنده کاتالوگ آنلاین</h1>
                <p id="app-desc" class="text-xs text-slate-400 font-bold">طراحی چیدمان کاتالوگ با زبان دلخواه، اتصال هوشمند به کتابخانه کالا و هوش مصنوعی</p>
            </div>
        </div>
        
        <div class="flex items-center gap-3 flex-wrap">
            <!-- Project Selection -->
            <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-2xl p-1.5 px-3">
                <span class="text-xs font-black text-slate-500">پروژه:</span>
                <select onchange="window.location.href='?switch_project=' + this.value" class="bg-transparent border-none text-xs font-black text-slate-800 outline-none cursor-pointer">
                    <?php foreach ($allProjects as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['id'] ?? ''); ?>" <?php echo ($p['id'] ?? '') === $project['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name'] ?? 'بدون نام'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button onclick="saveAllToDatabase()" class="bg-primary hover:bg-indigo-700 text-white font-black text-xs px-5 py-3 rounded-2xl flex items-center gap-1.5 transition-all shadow-lg shadow-indigo-600/10 cursor-pointer">
                <span>💾</span>
                <span>ذخیره نهایی کاتالوگ‌ها</span>
            </button>
            
            <a href="../admin.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-black text-xs px-4 py-3 rounded-2xl transition-all">
                بازگشت به پنل اصلی
            </a>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
        
        <!-- Sidebar Controls -->
        <aside id="s-menu" class="w-full lg:w-96 bg-white border-l border-slate-150 p-6 flex flex-col gap-6 overflow-y-auto shrink-0 custom-scroll border-t lg:border-t-0">
            <!-- Section 1: Catalog Selector & Creator -->
            <div class="bg-slate-50 rounded-2xl border border-slate-200 p-4 flex flex-col gap-3">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-black text-slate-600">انتخاب کاتالوگ</span>
                    <button onclick="openNewCatalogModal()" class="text-[10px] font-black bg-emerald-500 hover:bg-emerald-600 text-white px-2.5 py-1.5 rounded-lg transition-all">+ جدید</button>
                </div>
                
                <select id="catalog-selector" onchange="selectCatalog(this.value)" class="w-full border border-slate-200 p-2.5 rounded-xl text-xs font-black bg-white select-none outline-none">
                    <!-- Loaded dynamically -->
                </select>
                
                <div class="flex gap-2 justify-end">
                    <button onclick="openEditCatalogMetaModal()" class="text-[10px] font-black hover:text-indigo-600 text-slate-500 flex items-center gap-1">
                        ✏️ ویرایش مشخصات کاتالوگ
                    </button>
                    <span class="text-slate-300">|</span>
                    <button onclick="deleteCurrentCatalog()" class="text-[10px] font-black hover:text-red-650 text-rose-500 flex items-center gap-1">
                        🗑️ حذف کاتالوگ جاری
                    </button>
                </div>
                
                <hr class="border-slate-200/60 my-1">
                
                <button onclick="saveAllToDatabase()" class="w-full bg-indigo-600 hover:bg-slate-800 active:scale-95 text-white font-black text-xs px-4 py-3 rounded-xl flex items-center justify-center gap-2 transition-all shadow-md shadow-indigo-600/10 cursor-pointer">
                    <span>💾</span>
                    <span>ذخیره تغییرات کاتالوگ</span>
                </button>
            </div>

            <!-- Section 2: Catalog Styling Settings -->
            <div class="border border-slate-150 rounded-2xl p-4 flex flex-col gap-4">
                <h3 class="text-xs font-black text-slate-500 flex items-center gap-1.5 border-b border-slate-100 pb-2">
                    <span>🎨</span> تنظیمات هدر و برندینگ
                </h3>
                
                <div>
                    <label class="block text-[10px] font-black text-slate-500 mb-1">لوگوی سفارشی (URL یا آپلود)</label>
                    <div class="flex gap-2">
                        <input type="text" id="brand-logo" oninput="updateBrandSettings()" class="flex-1 text-[11px] font-mono border border-slate-200 px-3 py-2 rounded-xl text-left" dir="ltr" placeholder="uploads/logo.png">
                        <label class="bg-slate-100 hover:bg-slate-200 hover:text-slate-900 text-slate-700 px-3 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center justify-center">
                            ☁️
                            <input type="file" accept="image/*" class="hidden" onchange="uploadLogoFile(this)">
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 mb-1">عنوان برند</label>
                        <input type="text" id="brand-title" oninput="updateBrandSettings()" class="w-full text-xs font-black border border-slate-200 p-2.5 rounded-xl bg-slate-50 focus:bg-white outline-none">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 mb-1">زیرعنوان کاتالوگ</label>
                        <input type="text" id="brand-subtitle" oninput="updateBrandSettings()" class="w-full text-xs font-black border border-slate-200 p-2.5 rounded-xl bg-slate-50 focus:bg-white outline-none">
                    </div>
                </div>
                
                <div>
                    <label class="block text-[10px] font-black text-slate-500 mb-1">محل درج آدرس وب‌سایت / شبکه اجتماعی</label>
                    <input type="text" id="brand-website" oninput="updateBrandSettings()" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl text-left bg-slate-50 focus:bg-white outline-none" dir="ltr" placeholder="www.yourbrand.com">
                </div>
            </div>

            <!-- Section 3: Prompt AI Generator -->
            <div class="border border-slate-150 bg-indigo-50/40 rounded-2xl p-4 flex flex-col gap-3">
                <h3 class="text-xs font-black text-primary flex items-center gap-1.5 border-b border-indigo-100 pb-2">
                    <span>✨</span> دستیار پرامپت هوشمند مکر (Prompt Maker)
                </h3>
                <p class="text-[10px] text-indigo-900/80 leading-relaxed font-bold">
                    بر اساس تعداد صفحات و چیدمان صفحاتی که تعریف کرده‌اید، یک پرامپت اختصاصی جهت کپی و ارسال به هوش مصنوعی (مانند Gemini) تولید می‌شود. پاسخ JSON دریافتی را می‌توانید مستقیماً ایمپورت کنید.
                </p>
                
                <button onclick="openPromptMakerModal()" class="w-full py-2.5 bg-primary hover:bg-primary/95 text-white rounded-xl text-xs font-black shadow-md block text-center transition-all">
                    🛠️ تولید پرامپت کاتالوگ و ایمپورت خروجی
                </button>
            </div>

            <!-- Section 4: Catalog Page Manager -->
            <div class="flex-1 flex flex-col gap-3 min-h-[250px]">
                <div class="flex items-center justify-between border-b pb-2">
                    <span class="text-xs font-black text-slate-700">مدیریت لایه‌ها و صفحات کاتالوگ</span>
                    <button onclick="addNewPage()" class="text-[10px] font-black bg-primary hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition-all">+ افزودن صفحه</button>
                </div>
                
                <div id="pages-list" class="flex-1 overflow-y-auto space-y-2.5 pr-1 custom-scroll">
                    <!-- Populated dynamically -->
                </div>
            </div>
            
        </aside>

        <!-- Editor and Live Preview Panel -->
        <main class="flex-1 flex flex-col overflow-hidden bg-slate-100 border-r border-slate-200">
            <!-- Tabs Menu -->
            <div class="bg-white border-b border-slate-150 px-6 flex justify-between items-center shrink-0">
                <div class="flex gap-4">
                    <button onclick="switchTab('edit')" id="tab-btn-edit" class="py-4 border-b-2 border-primary text-primary font-black text-xs flex items-center gap-1.5">
                        <span>🛠️</span> ویرایش چیدمان و ستون‌ها
                    </button>
                    <button onclick="switchTab('preview')" id="tab-btn-preview" class="py-4 border-b-2 border-transparent text-slate-500 hover:text-slate-800 font-bold text-xs flex items-center gap-1.5">
                        <span>👁️</span> پیش‌نمایش و چاپ کاتالوگ
                    </button>
                </div>
                <div id="language-indicator" class="text-[10px] bg-slate-100 border border-slate-200 font-black text-slate-600 px-3 py-1.5 rounded-xl select-none">
                    جهت بازنشانی چیدمان، تغییرات را قبل از خروج ذخیره کنید.
                </div>
            </div>

            <!-- Tab Content: Edit Layout Mode -->
            <div id="workspace-tab-edit" class="flex-1 overflow-y-auto p-6 space-y-6 custom-scroll">
                
                <!-- Main Layout Interactive Grid Builder -->
                <div id="catalog-visual-builder-container" class="space-y-8 max-w-4xl mx-auto">
                    <!-- Dynamically populated page bricks -->
                </div>

                <div class="text-center py-10" id="empty-state" style="display:none;">
                    <span class="text-6xl text-slate-300 block mb-3">🕳️</span>
                    <h3 class="text-sm font-black text-slate-500 mb-1">هیچ صفحه‌ای یافت نشد</h3>
                    <p class="text-xs text-slate-400 font-bold mb-4">برای شروع و ساخت کاتالوگ دکمه افزودن صفحه را بزنید.</p>
                    <button onclick="addNewPage()" class="bg-primary hover:bg-indigo-700 text-white font-black text-xs px-5 py-2.5 rounded-xl inline-flex items-center gap-1.5 shadow-md">
                        + افزودن صفحه جدید
                    </button>
                </div>
            </div>

            <!-- Tab Content: Live Showcase Mode -->
            <div id="workspace-tab-preview" class="flex-1 overflow-y-auto p-8 bg-slate-300 custom-scroll hidden" dir="ltr">
                <!-- Outer Envelope mimicking a styled responsive slide catalog binder or printable view -->
                <div class="max-w-4xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4 mb-6 bg-white p-5 rounded-2xl border border-slate-200 shadow-sm preview-controls-bar">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xs font-black text-slate-600">📐 اندازه ورق و ابعاد چاپی کاتالوگ:</span>
                        <select id="print-size-selector" onchange="changePrintSize(this.value)" class="border border-slate-200 px-3 py-2 rounded-xl text-xs font-black bg-slate-50 outline-none cursor-pointer hover:border-slate-300 transition-all">
                            <option value="a4-portrait">A4 عمودی (Portrait)</option>
                            <option value="a4-landscape">A4 افقی (Landscape)</option>
                            <option value="a5-portrait">A5 عمودی (Portrait)</option>
                            <option value="a5-landscape">A5 افقی (Landscape)</option>
                            <option value="square">خشتی / مربعی (Square 21x21)</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2.5 flex-wrap">
                        <button onclick="downloadAsPdf()" class="bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white font-black text-xs px-4.5 py-2.5 rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-1.5 shadow-emerald-600/10">
                            📥 دانلود PDF کل کاتالوگ
                        </button>
                        <button onclick="triggerDefaultPrint()" class="bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white font-black text-xs px-4.5 py-2.5 rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-1.5 shadow-indigo-600/10">
                            🖨️ چاپ مستقیم کاتالوگ
                        </button>
                        <button onclick="switchTab('edit')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-black text-xs px-4 py-2.5 rounded-xl transition-all cursor-pointer">
                            ✏️ بازگشت به ویرایش
                        </button>
                    </div>
                </div>

                <!-- Showcase Canvas Paper with Scroll container to prevent aspect ratios breaking on small screens -->
                <div class="w-full overflow-x-auto pb-4 pt-2 flex justify-center custom-scroll bg-slate-300">
                    <div id="showcase-outer-paper" class="bg-slate-50 shadow-2xl rounded-3xl p-12 flex flex-col gap-12 relative overflow-hidden text-right paper-a4-portrait print-a4-portrait" dir="rtl">
                        <!-- Header Dynamic Area -->
                        <div id="showcase-header" class="flex justify-between items-center border-b border-slate-200 pb-6 shrink-0 h-16">
                            <div class="flex items-center gap-3">
                                <div id="showcase-logo-box" class="h-12 w-12 rounded-xl flex items-center justify-center overflow-hidden bg-white shadow-sm border border-slate-100">
                                    <span class="text-slate-300 text-lg font-black font-en">M</span>
                                </div>
                                <div>
                                    <div id="showcase-brand-title" class="text-lg font-black text-slate-905">عنوان تجاری</div>
                                    <div id="showcase-brand-subtitle" class="text-[10px] font-black text-slate-400">کاتالوگ اصلی کالاها</div>
                                </div>
                            </div>
                            <div id="showcase-brand-website" class="text-xs font-bold text-slate-400 font-en" dir="ltr">yourbrand.com</div>
                        </div>

                        <!-- Sandbox Flow Dynamic Page Elements -->
                        <div id="showcase-pages-container" class="flex-1 space-y-16">
                            <!-- Loaded dynamically based on standard JSON layout structures -->
                        </div>

                        <!-- Footer Area -->
                        <div class="flex justify-between items-center text-[10px] text-slate-300 font-bold border-t border-slate-100 pt-5">
                            <span id="showcase-foot-brand">کاتالوگ هوشمند ماارک‌شو فلو</span>
                            <span id="showcase-foot-copy" class="font-en">Generated via Maarksho.com</span>
                        </div>
                    </div>
                </div>
            </div>

        </main>

    </div>

    <!-- Modals Layer -->
    
    <!-- Modal 1: New Catalog Creator -->
    <div id="newCatalogModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-3xl border border-slate-200 max-w-md w-full p-6 shadow-2xl mx-4">
            <h3 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-2">ایجاد کاتالوگ جدید</h3>
            <h2 class="text-lg font-black text-slate-800 mb-6">ساخت قالب کاتالوگ سفارشی</h2>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-black text-slate-600 mb-1.5">عنوان کاتالوگ</label>
                    <input type="text" id="new-catalog-title" class="w-full border border-slate-200 p-3 rounded-xl text-xs font-bold focus:ring-2 focus:ring-primary/20 outline-none" placeholder="مانند: کالکشن بهاره ۱۴۰۵">
                </div>
                <div>
                    <label class="block text-xs font-black text-slate-600 mb-1.5">زبان و جهت کاتالوگ</label>
                    <select id="new-catalog-lang" class="w-full border border-slate-200 p-3 rounded-xl text-xs font-black outline-none bg-slate-50">
                        <option value="fa">فارسی (راست‌چین - RTL)</option>
                        <option value="en">English (چپ‌چین - LTR)</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-8">
                <button onclick="closeModal('newCatalogModal')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-705 text-xs font-bold rounded-xl transition-all">انصراف</button>
                <button onclick="submitCreateCatalog()" class="px-6 py-2.5 bg-primary hover:bg-indigo-700 text-white text-xs font-black rounded-xl shadow-md transition-all">تایید و ساخت</button>
            </div>
        </div>
    </div>

    <!-- Modal 2: Edit Catalog Metadata -->
    <div id="editCatalogModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-3xl border border-slate-200 max-w-md w-full p-6 shadow-2xl mx-4">
            <h3 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-2">ویرایش مشخصات کاتالوگ</h3>
            <h2 class="text-lg font-black text-slate-800 mb-6 font-black">اصلاح عنوان و ترجیحات زبانی</h2>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-black text-slate-600 mb-1.5">عنوان جدید کاتالوگ</label>
                    <input type="text" id="edit-catalog-title" class="w-full border border-slate-200 p-3 rounded-xl text-xs font-bold focus:ring-2 focus:ring-primary/20 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-black text-slate-600 mb-1.5">زبان و جهت کاتالوگ</label>
                    <select id="edit-catalog-lang" class="w-full border border-slate-200 p-3 rounded-xl text-xs font-black outline-none bg-slate-50">
                        <option value="fa">فارسی (راست‌چین - RTL)</option>
                        <option value="en">English (چپ‌چین - LTR)</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-8">
                <button onclick="closeModal('editCatalogModal')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-705 text-xs font-bold rounded-xl transition-all">انصراف</button>
                <button onclick="submitEditCatalog()" class="px-6 py-2.5 bg-primary hover:bg-indigo-700 text-white text-xs font-black rounded-xl shadow-md transition-all">ذخیره اصلاحات</button>
            </div>
        </div>
    </div>

    <!-- Modal 3: Prompt AI Generator & Importer -->
    <div id="promptMakerModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-3xl border border-slate-250 max-w-3xl w-full p-6 shadow-3xl mx-4 flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center border-b pb-4 shrink-0">
                <div>
                    <h3 class="text-xs font-black text-primary uppercase tracking-wider mb-1">سازنده پرامپت هوشمند</h3>
                    <h2 class="text-lg font-black text-slate-800">تولید ساختار کاتالوگ با هوش مصنوعی</h2>
                </div>
                <button onclick="closeModal('promptMakerModal')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-255 text-slate-500 font-bold flex items-center justify-center">✕</button>
            </div>
            
            <div class="flex-1 overflow-y-auto py-4 space-y-5 custom-scroll text-right">
                
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl">
                    <h4 class="text-xs font-black text-emerald-800 mb-1 flex items-center gap-1.5">
                        <span>💡</span> چطور کار می‌کند؟
                    </h4>
                    <ol class="text-[11px] text-emerald-900/90 leading-relaxed space-y-1.5 list-decimal pr-4 font-bold">
                        <li>پرامپت زیر بر اساس ساختار صفحاتی که اضافه کرده‌اید شکل گرفته است.</li>
                        <li>روی دکمه "کپی پرامپت اختصاصی" کلیک کنید و آن را برای هوش مصنوعی (مانند Gemini یا ChatGPT) ارسال کنید.</li>
                        <li>خروجی متنی دریافتی که کدهای ساختار یافته کاتالوگ است را کپی کرده و در کادر پایین وارد کرده و دکمه "تایید و ایمپورت مستقیم" را بزنید.</li>
                        <li>تمامی متون نوشته شده به طور خودکار در ساختار صفحات چیده شده و فقط باید عکس‌های مربوطه را دستی ست کنید.</li>
                    </ol>
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 mb-1.5">۱. پرامپت تولید شده برای ارسال به هوش مصنوعی</label>
                    <div class="relative">
                        <textarea id="ai-prompt-output" readonly class="w-full h-44 bg-slate-50 border border-slate-205 p-3 rounded-2xl text-[10px] font-mono text-left leading-relaxed text-slate-650 resize-none" dir="ltr"></textarea>
                        <button onclick="copyPromptText()" class="absolute bottom-3 left-3 bg-primary hover:bg-indigo-700 text-white text-[10px] font-black px-4 py-2 rounded-xl transition-all shadow shadow-indigo-600/10">
                            کپی پرامپت اختصاصی 📋
                        </button>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="block text-xs font-black text-slate-700">۲. جایگذاری خروجی دریافتی هوش مصنوعی (فرمت داده‌های کاتالوگ)</label>
                        <span class="text-[10px] bg-slate-100 px-2 py-0.5 rounded text-slate-500 font-black">Format: JSON</span>
                    </div>
                    <textarea id="ai-json-input" class="w-full h-40 border border-slate-205 p-3 rounded-2xl text-[10px] font-mono text-left leading-relaxed focus:ring-2 focus:ring-primary/20 outline-none" dir="ltr" placeholder='{ "pages": [ ... ] }'></textarea>
                </div>

            </div>

            <div class="flex flex-wrap justify-between items-center gap-3 border-t pt-4 shrink-0">
                <div class="flex gap-2">
                    <button id="btn-run-catalog-ai" onclick="runDirectCatalogAI()" class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-xs font-black rounded-xl shadow-md transition-all flex items-center gap-1.5 duration-200">
                        <span id="ai-catalog-spinner" class="hidden animate-spin">⏳</span>
                        <span>🚀</span> تولید خودکار با هوش مصنوعی (Gemini)
                    </button>
                </div>
                <div class="flex gap-2">
                    <button onclick="closeModal('promptMakerModal')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all">بستن پنجره</button>
                    <button onclick="importAICatalogJson()" class="px-6 py-2.5 bg-primary hover:bg-indigo-700 text-white text-xs font-black rounded-xl shadow-md transition-all flex items-center gap-1">
                        <span>✨</span> تایید و ایمپورت مستقیم به کاتالوگ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF Printer Instructions Modal -->
    <div id="pdfGuidanceModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[70] flex items-center justify-center hidden" dir="rtl">
        <div class="bg-white rounded-3xl border border-slate-200 max-w-md w-full p-6 shadow-3xl mx-4 text-right">
            <div class="flex justify-between items-center border-b pb-4 mb-4">
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📥</span>
                    <h3 class="text-sm font-black text-slate-800">راهنمای بارگیری نسخه PDF کاتالوگ</h3>
                </div>
                <button onclick="closeModal('pdfGuidanceModal')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 font-bold flex items-center justify-center">✕</button>
            </div>
            
            <div class="space-y-4 text-xs leading-relaxed text-slate-600 font-bold">
                <p>جهت دریافت خروجی باکیفیت و برداری به صورت PDF، پس از بستن این کادر مراحل زیر را در کادر چاپ نمایشگر تایید کنید:</p>
                
                <div class="bg-indigo-50 border border-indigo-100 p-4 rounded-2xl space-y-2 text-[11px] text-indigo-950">
                    <div class="flex gap-2">
                        <span>1️⃣</span>
                        <span>هدف (Destination) چاپ را روی <strong>Save as PDF</strong> (ذخیره به صورت PDF) تنظیم کنید.</span>
                    </div>
                    <div class="flex gap-2">
                        <span>2️⃣</span>
                        <span>در بخش تنظیمات بیشتر (More settings)، تیک <strong>Background graphics</strong> (گرافیک‌های پس‌زمینه) را فعال کنید تا عکس‌ها و پالت‌های رنگی به درستی چاپ شوند.</span>
                    </div>
                    <div class="flex gap-2">
                        <span>3️⃣</span>
                        <span>تیک گزینه <strong>Headers and footers</strong> (سربرگ و پاورقی مرورگر) را غیرفعال کنید تا آدرس سایت در حاشیه کاغذ چاپ نشود.</span>
                    </div>
                    <div class="flex gap-2">
                        <span>4️⃣</span>
                        <span>جهت داشتن بهترین کیفیت تراز چاپی، چیدمان کاغذ (A4/A5، افقی/عمودی) به صورت خودکار با توجه به انتخابتان هماهنگ شده است.</span>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 border-t pt-4 mt-5">
                <button onclick="closeModal('pdfGuidanceModal')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all">تغییر اندازه و بازگشت</button>
                <button onclick="invokeBrowserPrint()" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-750 text-white text-xs font-black rounded-xl shadow-md transition-all flex items-center gap-1.5 cursor-pointer">
                    📥 شروع دانلود PDF
                </button>
            </div>
        </div>
    </div>

    <!-- Dropdown / Product Library Browser Modal -->
    <div id="productLibraryModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] flex items-center justify-center hidden">
        <div class="bg-white rounded-3xl border border-slate-250 max-w-xl w-full p-6 shadow-3xl mx-4 flex flex-col max-h-[80vh]">
            <div class="flex justify-between items-center border-b pb-4 shrink-0">
                <div>
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-1">کتابخانه محتوا و محصولات</h3>
                    <h2 class="text-lg font-black text-slate-800">انتخاب تصویر از کالاها</h2>
                </div>
                <button onclick="closeModal('productLibraryModal')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-255 text-slate-500 font-bold flex items-center justify-center">✕</button>
            </div>
            
            <div class="py-3 shrink-0">
                <input type="text" id="lib-search-input" oninput="searchProducts(this.value)" class="w-full border border-slate-200 p-3 rounded-2xl text-xs font-black outline-none focus:ring-2 focus:ring-primary/20" placeholder="🔍 جستجوی کالا بر اساس نام یا کد...">
            </div>

            <div id="lib-products-grid" class="flex-1 overflow-y-auto py-2 grid grid-cols-2 gap-4 custom-scroll text-right">
                <!-- Dynamically loaded products -->
            </div>
            
            <div id="lib-images-panel" class="flex-1 overflow-y-auto py-2 space-y-4 custom-scroll text-right hidden">
                <button onclick="backToProductList()" class="text-xs font-black text-primary hover:underline flex items-center gap-1.5 mb-2">
                    ← بازگشت به لیست محصولات
                </button>
                <h4 id="lib-selected-product-title" class="text-xs font-black text-slate-800">کالای انتخاب شده</h4>
                <div id="lib-resolved-images-grid" class="grid grid-cols-3 gap-3">
                    <!-- Images will list here -->
                </div>
            </div>

            <div class="flex justify-end pt-4 border-t mt-4 shrink-0">
                <button onclick="closeModal('productLibraryModal')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-705 text-xs font-bold rounded-xl transition-all">انصراف</button>
            </div>
        </div>
    </div>


    <script>
        window.rootUrl = "<?php echo $baseUrl; ?>";
        
        function resolveImageUrl(url) {
            if (!url) return '';
            if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:') || url.startsWith('//')) {
                return url;
            }
            let cleanUrl = url;
            if (cleanUrl.startsWith('/')) {
                cleanUrl = cleanUrl.substring(1);
            }
            if (cleanUrl.startsWith('../')) {
                cleanUrl = cleanUrl.substring(3);
            }
            return (window.rootUrl || '') + cleanUrl;
        }

        // System wide constants loaded from server php properties
        const loadedCatalogs = <?php echo json_encode($catalogs, JSON_UNESCAPED_UNICODE); ?>;
        const brandSettings = <?php echo json_encode($catalog_settings, JSON_UNESCAPED_UNICODE); ?>;
        const projectProducts = <?php echo json_encode($slimProducts, JSON_UNESCAPED_UNICODE); ?>;
        const projectIdForSwitching = "<?php echo htmlspecialchars($project['id']); ?>";
        const projectLogo = "<?php echo htmlspecialchars($project['settings']['size_chart_settings']['image_logo'] ?? ''); ?>";

        let currentCatalogsList = Array.isArray(loadedCatalogs) ? loadedCatalogs : [];
        let activeCatalogId = null;
        let activeEditTab = 'edit';
        
        let targetPageIdx = null;
        let targetColIdx = null;

        // Startup triggers
        window.addEventListener('load', () => {
            initBrandSettingsValues();
            initCatalogSelector();
            renderBuilderWorkspace();
        });

        function initBrandSettingsValues() {
            document.getElementById('brand-logo').value = brandSettings.image_logo || '';
            document.getElementById('brand-title').value = brandSettings.brand_title || '';
            document.getElementById('brand-subtitle').value = brandSettings.brand_subtitle || '';
            document.getElementById('brand-website').value = brandSettings.brand_website || '';
        }

        function initCatalogSelector() {
            const selector = document.getElementById('catalog-selector');
            selector.innerHTML = '';
            
            if (currentCatalogsList.length === 0) {
                // Seed at least one default
                currentCatalogsList.push({
                    id: 'cat_' + Date.now() + '_' + Math.floor(Math.random()*1000),
                    title: 'کاتالوگ عمومی پروژه',
                    language: 'fa',
                    pages: [
                        {
                            id: 'p_' + Math.random().toString(36).substr(2, 9),
                            layout: 1,
                            columns: [
                                {
                                    type: 'text',
                                    content: '### خوش آمدید به کاتالوگ جدید ما\nدر این کاتالوگ گزیده‌ای از بهترین محصولات و دستاوردها را مشاهده می‌کنید. برای ویرایش این بخش از نوار تنظیمات کمک بگیرید.'
                                }
                            ]
                        }
                    ]
                });
            }
            
            currentCatalogsList.forEach((cat) => {
                const opt = document.createElement('option');
                opt.value = cat.id;
                opt.textContent = `${cat.title} (${cat.language === 'fa' ? 'فارسی RTL' : 'English LTR'})`;
                selector.appendChild(opt);
            });
            
            if (!activeCatalogId && currentCatalogsList.length > 0) {
                activeCatalogId = currentCatalogsList[0].id;
            }
            selector.value = activeCatalogId;
        }

        function getActiveCatalog() {
            return currentCatalogsList.find(c => c.id === activeCatalogId);
        }

        function selectCatalog(id) {
            activeCatalogId = id;
            renderBuilderWorkspace();
        }

        // Meta managers
        function openNewCatalogModal() {
            document.getElementById('new-catalog-title').value = '';
            document.getElementById('newCatalogModal').classList.remove('hidden');
        }

        function submitCreateCatalog() {
            const title = document.getElementById('new-catalog-title').value.trim();
            const lang = document.getElementById('new-catalog-lang').value;
            if (!title) {
                alert('لطفا عنوان کاتالوگ را وارد نمایید.');
                return;
            }
            
            const newCat = {
                id: 'cat_' + Date.now() + '_' + Math.floor(Math.random()*1000),
                title: title,
                language: lang,
                pages: [
                    {
                        id: 'p_' + Math.random().toString(36).substr(2, 9),
                        layout: 1,
                        columns: [
                            {
                                type: 'text',
                                content: lang === 'fa' ? '### عنوان کاتالوگ محتوا\nتوضیحات معرفی ستون اول' : '### Campaign Heading\nSample description text goes here in this column block.'
                            }
                        ]
                    }
                ]
            };
            
            currentCatalogsList.push(newCat);
            activeCatalogId = newCat.id;
            
            initCatalogSelector();
            renderBuilderWorkspace();
            closeModal('newCatalogModal');
        }

        function openEditCatalogMetaModal() {
            const cat = getActiveCatalog();
            if (!cat) return;
            document.getElementById('edit-catalog-title').value = cat.title;
            document.getElementById('edit-catalog-lang').value = cat.language;
            document.getElementById('editCatalogModal').classList.remove('hidden');
        }

        function submitEditCatalog() {
            const title = document.getElementById('edit-catalog-title').value.trim();
            const lang = document.getElementById('edit-catalog-lang').value;
            if (!title) {
                alert('لطفا عنوان جدید را مشخص کنید.');
                return;
            }
            
            const cat = getActiveCatalog();
            if (cat) {
                cat.title = title;
                cat.language = lang;
            }
            
            initCatalogSelector();
            renderBuilderWorkspace();
            closeModal('editCatalogModal');
        }

        function deleteCurrentCatalog() {
            if (currentCatalogsList.length <= 1) {
                alert('حداقل وجود یک کاتالوگ در سیستم الزامیست.');
                return;
            }
            if (!confirm('آیا مایل به حذف این کاتالوگ به طور کل هستید؟ (غیرقابل بازگشت)')) return;
            
            currentCatalogsList = currentCatalogsList.filter(c => c.id !== activeCatalogId);
            activeCatalogId = currentCatalogsList[0]?.id || null;
            
            initCatalogSelector();
            renderBuilderWorkspace();
        }

        // Brand synchronization
        function updateBrandSettings() {
            brandSettings.image_logo = document.getElementById('brand-logo').value.trim();
            brandSettings.brand_title = document.getElementById('brand-title').value.trim();
            brandSettings.brand_subtitle = document.getElementById('brand-subtitle').value.trim();
            brandSettings.brand_website = document.getElementById('brand-website').value.trim();
            
            syncShowcaseHeader();
        }

        function uploadLogoFile(input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const fd = new FormData();
            fd.append('file', file);
            fd.append('action', 'catalog_image_upload');
            
            input.parentElement.style.opacity = '0.5';
            
            const searchParams = new URLSearchParams(window.location.search);
            searchParams.set('action', 'catalog_image_upload');
            
            fetch('?' + searchParams.toString(), {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                input.parentElement.style.opacity = '1';
                if (data.success) {
                    document.getElementById('brand-logo').value = data.url;
                    updateBrandSettings();
                } else {
                    alert('خطا در بارگذاری: ' + data.message);
                }
            })
            .catch(err => {
                input.parentElement.style.opacity = '1';
                alert('ارتباط با سرور برقرار نشد.');
            });
        }

        // Ajax upload for Column manually placed images
        function uploadColumnImage(input, pageIdx, colIdx) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const fd = new FormData();
            fd.append('file', file);
            fd.append('action', 'catalog_image_upload');
            
            const loader = document.getElementById(`loader-${pageIdx}-${colIdx}`);
            if (loader) loader.style.display = 'inline-block';
            
            const searchParams = new URLSearchParams(window.location.search);
            searchParams.set('action', 'catalog_image_upload');
            
            fetch('?' + searchParams.toString(), {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (loader) loader.style.display = 'none';
                if (data.success) {
                    // Update model
                    const cat = getActiveCatalog();
                    if (cat && cat.pages[pageIdx]) {
                        const col = cat.pages[pageIdx].columns[colIdx];
                        col.manual_image_url = data.url;
                        col.content = data.url; // sync actual image URL
                        
                        // Update visual Input field value
                        const inputField = document.getElementById(`img-url-${pageIdx}-${colIdx}`);
                        if (inputField) inputField.value = data.url;
                        
                        // Refresh Visual State
                        renderBuilderWorkspace();
                    }
                } else {
                    alert('خطا در بارگذاری: ' + data.message);
                }
            })
            .catch(err => {
                if (loader) loader.style.display = 'none';
                alert('خطا در برقراری ارتباط با سرور.');
            });
        }

        // Workspace tabs
        function switchTab(tabId) {
            activeEditTab = tabId;
            document.getElementById('workspace-tab-edit').classList.add('hidden');
            document.getElementById('workspace-tab-preview').classList.add('hidden');
            
            document.getElementById('tab-btn-edit').className = 'py-4 border-b-2 text-xs font-bold flex items-center gap-1.5 transition-all ' + 
                (tabId === 'edit' ? 'border-primary text-primary font-black' : 'border-transparent text-slate-500 hover:text-slate-800');
                
            document.getElementById('tab-btn-preview').className = 'py-4 border-b-2 text-xs font-bold flex items-center gap-1.5 transition-all ' + 
                (tabId === 'preview' ? 'border-primary text-primary font-black' : 'border-transparent text-slate-500 hover:text-slate-800');

            document.getElementById(`workspace-tab-${tabId}`).classList.remove('hidden');
            
            if (tabId === 'preview') {
                renderShowcasePreview();
            }
        }

        // Page manipulation
        function addNewPage() {
            const cat = getActiveCatalog();
            if (!cat) return;
            
            cat.pages.push({
                id: 'p_' + Math.random().toString(36).substr(2, 9),
                layout: 1,
                columns: [
                    {
                        type: 'text',
                        content: cat.language === 'fa' ? 'توضیحات ستون جدید...' : 'New column text content...'
                    }
                ]
            });
            renderBuilderWorkspace();
        }

        function deletePage(pageIdx) {
            const cat = getActiveCatalog();
            if(!cat) return;
            
            if (cat.pages.length <= 1) {
                alert('وجود حداقل یک صفحه در کاتالوگ الزامیست.');
                return;
            }
            if(!confirm('آیا مایل به حذف کل صفحه به انضمام محتویات آن هستید؟')) return;
            
            cat.pages.splice(pageIdx, 1);
            renderBuilderWorkspace();
        }

        function changePageLayout(pageIdx, layoutVal) {
            const cat = getActiveCatalog();
            if (!cat || !cat.pages[pageIdx]) return;
            
            const page = cat.pages[pageIdx];
            layoutVal = parseInt(layoutVal);
            page.layout = layoutVal;
            
            // Re-map columns list based on new layouts count
            const prevCols = [...page.columns];
            page.columns = [];
            
            for (let i = 0; i < layoutVal; i++) {
                if (prevCols[i]) {
                    page.columns.push(prevCols[i]);
                } else {
                    page.columns.push({
                        type: 'text',
                        content: cat.language === 'fa' ? 'محتوای متنی ستون جدید' : 'New column text description block.'
                    });
                }
            }
            
            renderBuilderWorkspace();
        }

        function changeColType(pageIdx, colIdx, typeVal) {
            const cat = getActiveCatalog();
            if (!cat || !cat.pages[pageIdx] || !cat.pages[pageIdx].columns[colIdx]) return;
            
            const col = cat.pages[pageIdx].columns[colIdx];
            col.type = typeVal;
            
            if (typeVal === 'image') {
                col.image_source = col.image_source || 'manual';
                col.manual_image_url = col.manual_image_url || '';
                col.product_id = col.product_id || '';
                col.product_image_type = col.product_image_type || 'image';
                col.content = col.manual_image_url || 'https://placehold.co/600x400?text=No+Image';
            } else {
                col.content = cat.language === 'fa' ? 'متن جدید کاربر...' : 'Your custom text...';
            }
            
            renderBuilderWorkspace();
        }

        function updateColTextContent(pageIdx, colIdx, textVal) {
            const cat = getActiveCatalog();
            if (cat && cat.pages[pageIdx] && cat.pages[pageIdx].columns[colIdx]) {
                cat.pages[pageIdx].columns[colIdx].content = textVal;
            }
        }

        function updateColImgUrl(pageIdx, colIdx, imgVal) {
            const cat = getActiveCatalog();
            if (cat && cat.pages[pageIdx] && cat.pages[pageIdx].columns[colIdx]) {
                const col = cat.pages[pageIdx].columns[colIdx];
                col.manual_image_url = imgVal.trim();
                col.content = imgVal.trim();
            }
        }

        function movePageUp(pageIdx) {
            if (pageIdx === 0) return;
            const cat = getActiveCatalog();
            if (!cat) return;
            
            const temp = cat.pages[pageIdx];
            cat.pages[pageIdx] = cat.pages[pageIdx - 1];
            cat.pages[pageIdx - 1] = temp;
            
            renderBuilderWorkspace();
        }

        function movePageDown(pageIdx) {
            const cat = getActiveCatalog();
            if (!cat) return;
            if (pageIdx === cat.pages.length - 1) return;
            
            const temp = cat.pages[pageIdx];
            cat.pages[pageIdx] = cat.pages[pageIdx + 1];
            cat.pages[pageIdx + 1] = temp;
            
            renderBuilderWorkspace();
        }

        // Visual Builder Workspace Renderer
        function renderBuilderWorkspace() {
            const cat = getActiveCatalog();
            const container = document.getElementById('catalog-visual-builder-container');
            const pageMenu = document.getElementById('pages-list');
            const empState = document.getElementById('empty-state');
            
            container.innerHTML = '';
            pageMenu.innerHTML = '';
            
            if (!cat) {
                empState.style.display = 'block';
                return;
            } else {
                empState.style.display = 'none';
            }

            const isRTL = cat.language === 'fa';
            
            // Build Left pages manager indices
            cat.pages.forEach((page, idx) => {
                // Left small checklist item
                const item = document.createElement('div');
                item.className = "flex items-center justify-between p-3.5 bg-slate-50 hover:bg-slate-100 rounded-xl border border-slate-200 shadow-sm";
                item.innerHTML = `
                    <div class="flex items-center gap-2.5">
                        <span class="text-xs font-black text-indigo-650 bg-indigo-100/60 px-2 py-1 rounded-lg">#${idx + 1}</span>
                        <div>
                            <div class="text-xs font-black text-slate-800">صفحه با چیدمان ستونی</div>
                            <div class="text-[10px] text-slate-400 font-bold">طرح: ${page.layout} ستونه</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button onclick="movePageUp(${idx})" class="w-6 h-6 hover:bg-slate-200 text-slate-600 rounded flex items-center justify-center font-bold text-xs" title="انتقال به بالا">▲</button>
                        <button onclick="movePageDown(${idx})" class="w-6 h-6 hover:bg-slate-200 text-slate-600 rounded flex items-center justify-center font-bold text-xs" title="انتقال به پایین">▼</button>
                        <button onclick="deletePage(${idx})" class="w-6 h-6 hover:bg-rose-100 text-rose-605 rounded flex items-center justify-center text-xs ml-1" title="حذف">✕</button>
                    </div>
                `;
                pageMenu.appendChild(item);

                // Main visual editor page structure Brick
                const pgBrick = document.createElement('div');
                pgBrick.className = "bg-white border border-slate-150 rounded-3xl p-6 shadow-md transition-all relative block group";
                pgBrick.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
                
                // Helper functions to populate specific columns inside brick
                let columnsHtml = '';
                page.columns.forEach((col, colIdx) => {
                    const isText = col.type === 'text';
                    const isImage = col.type === 'image';
                    
                    columnsHtml += `
                        <div class="border border-slate-100 rounded-2xl bg-slate-50/50 p-4 flex flex-col gap-3 min-h-[220px]">
                            <!-- Column Header configuration -->
                            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                                <span class="text-[11px] font-black text-slate-600">ستون شماره ${colIdx + 1}</span>
                                
                                <div class="flex gap-1.5 bg-white border rounded-lg p-0.5" dir="rtl">
                                    <button onclick="changeColType(${idx}, ${colIdx}, 'text')" class="text-[9px] font-bold px-2 py-1 rounded-md transition-all ${isText ? 'bg-indigo-600 text-white font-black' : 'hover:bg-slate-100 text-slate-500'}" type="button">📝 متن</button>
                                    <button onclick="changeColType(${idx}, ${colIdx}, 'image')" class="text-[9px] font-bold px-2 py-1 rounded-md transition-all ${isImage ? 'bg-indigo-600 text-white font-black' : 'hover:bg-slate-100 text-slate-500'}" type="button">🖼️ تصویر</button>
                                </div>
                            </div>
                            
                            <!-- Content Box depending on selected style -->
                            ${isText ? `
                                <div class="flex-1 flex flex-col">
                                    <label class="text-[9px] font-black text-slate-400 mb-1">محتوای متنی (با قابلیت Markdown)</label>
                                    <textarea oninput="updateColTextContent(${idx}, ${colIdx}, this.value)" class="flex-1 w-full border border-slate-150 p-3 rounded-xl text-xs font-bold focus:ring-1 focus:ring-indigo-500 bg-white outline-none min-h-[140px] resize-y" placeholder="مثال:\n### شاهکار جدید پیرامید\nپارچه نخ‌پنبه اصل ژاپن خنک و با دوام عالي">${col.content || ''}</textarea>
                                </div>
                            ` : `
                                <div class="flex-1 flex flex-col gap-2">
                                    <div class="flex items-center justify-between" dir="rtl">
                                        <label class="text-[9px] font-black text-slate-400">منبع تصویر ستون</label>
                                        <div class="flex gap-2">
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" name="src-type-${idx}-${colIdx}" onchange="changeImageSource(${idx}, ${colIdx}, 'manual')" ${col.image_source==='manual'?'checked':''} class="w-3.5 h-3.5 text-indigo-650 rounded-full">
                                                <span class="text-[10px] font-bold text-slate-500">لینک دستی</span>
                                            </label>
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" name="src-type-${idx}-${colIdx}" onchange="changeImageSource(${idx}, ${colIdx}, 'product')" ${col.image_source==='product'?'checked':''} class="w-3.5 h-3.5 text-indigo-650 rounded-full">
                                                <span class="text-[10px] font-bold text-slate-500">کتابخانه محصول</span>
                                            </label>
                                        </div>
                                    </div>

                                    ${col.image_source === 'manual' ? `
                                        <!-- Manual URL option -->
                                        <div class="space-y-1.5">
                                            <div class="flex gap-2">
                                                <input id="img-url-${idx}-${colIdx}" type="text" oninput="updateColImgUrl(${idx}, ${colIdx}, this.value)" value="${col.manual_image_url || ''}" placeholder="http://example.com/item.jpeg" class="flex-1 text-[10px] font-mono border border-slate-150 p-2 rounded-xl bg-white text-left select-all" dir="ltr">
                                                
                                                <label class="bg-indigo-50 hover:bg-indigo-100 text-indigo-750 px-3 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center justify-center shrink-0">
                                                    ☁️ قرار دادن عکس
                                                    <input type="file" accept="image/*" class="hidden" onchange="uploadColumnImage(this, ${idx}, ${colIdx})">
                                                </label>
                                            </div>
                                            <span id="loader-${idx}-${colIdx}" style="display:none;" class="text-[9px] font-black text-indigo-600 animate-pulse">در حال آپلود عکس... پیاده سازی امن</span>
                                        </div>
                                    ` : `
                                        <!-- Product Selection option -->
                                        <div class="space-y-2">
                                            <button type="button" onclick="openProductSelectorModal(${idx}, ${colIdx})" class="w-full py-2 bg-indigo-50 hover:bg-indigo-100 border border-dashed border-indigo-200 text-indigo-750 rounded-xl text-[10px] font-black flex items-center justify-center gap-1 transition-all">
                                                <span>📦</span>
                                                <span>${col.product_id ? 'تعویض کالا یا تصویر انتخابی' : 'انتخاب کالای هدف از کتابخانه'}</span>
                                            </button>
                                            
                                            ${col.product_id ? `
                                                <div class="p-2 bg-white rounded-xl border border-slate-150 flex items-center justify-between gap-2">
                                                    <div class="flex items-center gap-2 overflow-hidden">
                                                        <img src="${col.content || 'https://placehold.co/400x500?text=No+Image'}" referrerpolicy="no-referrer" class="w-10 h-10 object-cover rounded-lg bg-slate-50 border shrink-0">
                                                        <div class="overflow-hidden">
                                                            <div class="text-[9px] font-black text-slate-800 truncate line-clamp-1">${getProductTitleById(col.product_id)}</div>
                                                            <div class="text-[8px] text-slate-400 font-mono">ID: ${col.product_id}</div>
                                                        </div>
                                                    </div>
                                                    <span class="text-[8px] bg-emerald-500 text-white rounded px-1 py-0.5 font-bold">بسته به انطباق</span>
                                                </div>
                                            ` : `
                                                <div class="text-center py-2 text-[9px] text-slate-400 font-bold bg-white rounded-xl border border-dashed">هنوز محصولی انتخاب نشده است.</div>
                                            `}
                                        </div>
                                    `}

                                    <!-- Mini Image preview -->
                                    <div class="mt-auto pt-2 flex justify-center bg-white rounded-xl border p-2 h-20 items-center overflow-hidden">
                                        <img src="${col.content || 'https://placehold.co/600x400?text=No+Image'}" referrerpolicy="no-referrer" class="max-h-full max-w-full object-contain rounded-lg">
                                    </div>
                                </div>
                            `}
                        </div>
                    `;
                });

                // Assemble structural brick
                pgBrick.innerHTML = `
                    <!-- Ribbon -->
                    <div class="absolute -top-3 right-5 bg-indigo-600 text-white text-[10px] font-black px-3 py-1 rounded-lg shadow-sm flex items-center gap-1 z-10 font-sans" dir="rtl">
                        <span>صفحه شماره</span>
                        <span>${idx + 1}</span>
                    </div>

                    <!-- Toolbar actions inside head border -->
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5 mt-1">
                        <!-- Selectable columns density -->
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black text-slate-400">تعداد ستون‌های چیدمان:</span>
                            <div class="flex border rounded-xl overflow-hidden p-0.5 bg-slate-50" dir="ltr">
                                <button onclick="changePageLayout(${idx}, 1)" class="px-3.5 py-1 text-[10px] font-bold rounded-lg transition-all ${page.layout === 1 ? 'bg-white text-indigo-700 shadow-sm font-black':'text-slate-500 hover:bg-slate-100'}">۱ ستونه</button>
                                <button onclick="changePageLayout(${idx}, 2)" class="px-3.5 py-1 text-[10px] font-bold rounded-lg transition-all ${page.layout === 2 ? 'bg-white text-indigo-700 shadow-sm font-black':'text-slate-500 hover:bg-slate-100'}">۲ ستونه</button>
                                <button onclick="changePageLayout(${idx}, 3)" class="px-3.5 py-1 text-[10px] font-bold rounded-lg transition-all ${page.layout === 3 ? 'bg-white text-indigo-700 shadow-sm font-black':'text-slate-500 hover:bg-slate-100'}">۳ ستونه</button>
                            </div>
                        </div>

                        <!-- Secondary utils actions -->
                        <div class="flex items-center gap-2">
                            <button onclick="movePageUp(${idx})" class="w-7 h-7 hover:bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center border font-bold text-xs" title="انتقال به بالا">▲</button>
                            <button onclick="movePageDown(${idx})" class="w-7 h-7 hover:bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center border font-bold text-xs" title="انتقال به پایین">▼</button>
                            <button onclick="deletePage(${idx})" class="bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 transition-all">
                                🗑️ حذف صفحه
                            </button>
                        </div>
                    </div>

                    <!-- Visual Grid -->
                    <div class="grid grid-cols-1 ${page.layout === 2 ? 'md:grid-cols-2' : ''} ${page.layout === 3 ? 'md:grid-cols-3' : ''} gap-5">
                        ${columnsHtml}
                    </div>
                `;
                
                container.appendChild(pgBrick);
            });
            
            syncShowcaseHeader();
        }

        function changeImageSource(pageIdx, colIdx, source) {
            const cat = getActiveCatalog();
            if (cat && cat.pages[pageIdx] && cat.pages[pageIdx].columns[colIdx]) {
                const col = cat.pages[pageIdx].columns[colIdx];
                col.image_source = source;
                
                if (source === 'manual') {
                    col.content = col.manual_image_url || 'https://placehold.co/600x400?text=No+Image';
                } else {
                    col.content = 'https://placehold.co/600x400?text=Click+to+Select+Product';
                }
                
                renderBuilderWorkspace();
            }
        }

        // Showcase visual preview renderer
        function renderShowcasePreview() {
            const cat = getActiveCatalog();
            const container = document.getElementById('showcase-pages-container');
            container.innerHTML = '';
            
            if (!cat) return;
            
            const isRTL = cat.language === 'fa';
            
            // Adjust body direction of outer sheet
            const showcasePaper = document.getElementById('showcase-outer-paper');
            showcasePaper.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
            showcasePaper.style.textAlign = isRTL ? 'right' : 'left';
            
            if (isRTL) {
                showcasePaper.style.fontFamily = 'Vazirmatn, sans-serif';
                document.getElementById('showcase-header').className = "flex justify-between items-center border-b border-slate-205 pb-6 shrink-0 h-16 flex-row";
            } else {
                showcasePaper.style.fontFamily = "'Inter', sans-serif";
                document.getElementById('showcase-header').className = "flex justify-between items-center border-b border-slate-205 pb-6 shrink-0 h-16 flex-row-reverse";
            }
            
            syncShowcaseHeader();

            cat.pages.forEach((page, pIdx) => {
                const pgGrid = document.createElement('div');
                pgGrid.className = `catalog-page-grid grid ${page.layout === 2 ? 'grid-cols-2' : (page.layout === 3 ? 'grid-cols-3' : 'grid-cols-1')} gap-8 items-stretch pt-2 border-b border-slate-100/50 pb-12`;
                
                let innerColumnsHtml = '';
                page.columns.forEach((col, colIdx) => {
                    const isText = (col.type === 'text');
                    
                    if (isText) {
                        const parsedMarkdown = parseMarkdown(col.content || '');
                        innerColumnsHtml += `
                            <div class="flex flex-col justify-center py-4 text-slate-800 leading-relaxed max-w-full overflow-hidden prose prose-sm">
                                ${parsedMarkdown}
                            </div>
                        `;
                    } else {
                        // resolve direct URL on render
                        const imgUrl = col.content ? resolveImageUrl(col.content) : 'https://placehold.co/600x800?text=No+Image';
                        innerColumnsHtml += `
                            <div class="flex items-center justify-center bg-slate-101 border border-slate-200 shadow-sm p-3 rounded-2xl h-80 overflow-hidden relative group">
                                <img src="${imgUrl}" referrerpolicy="no-referrer" class="max-h-full max-w-full object-contain rounded-xl hover:scale-105 transition-all duration-300">
                            </div>
                        `;
                    }
                });
                
                pgGrid.innerHTML = innerColumnsHtml;
                container.appendChild(pgGrid);
            });
        }

        // Header brand specs alignment
        function syncShowcaseHeader() {
            const logoBox = document.getElementById('showcase-logo-box');
            if (brandSettings.image_logo) {
                logoBox.innerHTML = `<img src="${resolveImageUrl(brandSettings.image_logo)}" class="max-h-full max-w-full object-contain">`;
            } else {
                logoBox.innerHTML = `<span class="text-primary text-lg font-black font-en">M</span>`;
            }
            
            document.getElementById('showcase-brand-title').textContent = brandSettings.brand_title || 'عنوان تجاری';
            document.getElementById('showcase-brand-subtitle').textContent = brandSettings.brand_subtitle || 'کاتالوگ کالاها';
            document.getElementById('showcase-brand-website').textContent = brandSettings.brand_website || 'yourbrand.com';
            
            document.getElementById('showcase-foot-brand').textContent = brandSettings.brand_title || 'کاتالوگ رسمی برند';
            document.getElementById('showcase-foot-copy').textContent = brandSettings.brand_website ? 'visit at: ' + brandSettings.brand_website : 'Generated via Maarksho.com';
        }

        // Simulating robust Markdown compilation for rich presentation styles on Preview
        function parseMarkdown(mdText) {
            if (!mdText) return '';
            let html = mdText;
            
            // Avoid HTML injection
            html = html.replace(/</g, "&lt;").replace(/>/g, "&gt;");
            
            // Headings
            html = html.replace(/^### (.*$)/gim, '<h3 class="text-lg font-black text-slate-900 mt-3 mb-2">$1</h3>');
            html = html.replace(/^## (.*$)/gim, '<h2 class="text-xl font-black text-slate-950 mt-4 mb-2 border-b border-slate-100 pb-1">$1</h2>');
            html = html.replace(/^# (.*$)/gim, '<h1 class="text-2xl font-black text-primary mt-6 mb-3">$1</h1>');
            
            // Bold & Italics
            html = html.replace(/\*\*(.*)\*\*/gm, "<strong>$1</strong>");
            html = html.replace(/\*(.*)\*/gm, "<em>$1</em>");
            
            // Unordered list
            html = html.replace(/^\s*\-\s+(.*$)/gim, '<li class="text-xs text-slate-705 list-disc mr-4 py-0.5">$1</li>');
            
            // Linebreaks
            html = html.replace(/\n$/gim, '<br>');
            html = html.replace(/\n/gim, '<br>');
            
            return html;
        }

        // Modal triggers
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Product Selector library logic
        function openProductSelectorModal(pageIdx, colIdx) {
            targetPageIdx = pageIdx;
            targetColIdx = colIdx;
            
            // Reset to product listing view
            document.getElementById('lib-search-input').value = '';
            document.getElementById('productLibraryModal').classList.remove('hidden');
            searchProducts('');
            
            document.getElementById('lib-products-grid').classList.remove('hidden');
            document.getElementById('lib-images-panel').classList.add('hidden');
        }

        function searchProducts(term) {
            const grid = document.getElementById('lib-products-grid');
            grid.innerHTML = '';
            
            const matches = projectProducts.filter(p => {
                const nameMatch = p.name.toLowerCase().includes(term.toLowerCase());
                const idMatch = p.id.toLowerCase().includes(term.toLowerCase());
                return nameMatch || idMatch;
            });
            
            if (matches.length === 0) {
                grid.innerHTML = `<div class="col-span-2 text-center text-xs font-bold text-slate-400 py-8">هیچ کالایی متناسب با جستجو یافت نشد.</div>`;
                return;
            }
            
            matches.forEach(p => {
                const mainImg = p.image || p.social_image || 'https://placehold.co/150x150?text=No+Img';
                const card = document.createElement('div');
                card.className = 'bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-2xl p-3 flex flex-col items-center gap-2 cursor-pointer transition-all hover:scale-[1.02]';
                card.setAttribute('onclick', `selectProductForImages('${p.id}')`);
                
                card.innerHTML = `
                    <div class="w-24 h-24 rounded-lg bg-white border flex items-center justify-center overflow-hidden shrink-0">
                        <img src="${resolveImageUrl(mainImg)}" referrerpolicy="no-referrer" class="max-h-full max-w-full object-cover">
                    </div>
                    <div class="text-center w-full">
                        <div class="text-[10px] font-black text-slate-800 line-clamp-2 leading-relaxed h-8 overflow-hidden">${p.name}</div>
                        <div class="text-[8px] text-slate-400 font-mono mt-1">ID: ${p.id}</div>
                    </div>
                `;
                grid.appendChild(card);
            });
        }

        function getProductTitleById(id) {
            const p = projectProducts.find(x => x.id === id);
            return p ? p.name : 'کالای نامشخص';
        }

        // Drill down inside selected product to see all its available images
        function selectProductForImages(id) {
            const p = projectProducts.find(x => x.id === id);
            if (!p) return;
            
            document.getElementById('lib-products-grid').classList.add('hidden');
            document.getElementById('lib-images-panel').classList.remove('hidden');
            
            document.getElementById('lib-selected-product-title').textContent = `کالای انتخاب شده: ${p.name}`;
            
            const grid = document.getElementById('lib-resolved-images-grid');
            grid.innerHTML = '';
            
            // Collect all unique images of the product
            const gatheredImages = [];
            
            const isApproved = (imgUrl) => {
                if (!p.images_list) return (p.status === 'active');
                const cleanImg = imgUrl ? imgUrl.split('?')[0] : '';
                const found = p.images_list.find(item => {
                    const cleanItemUrl = item && item.url ? item.url.split('?')[0] : '';
                    return cleanItemUrl && cleanItemUrl === cleanImg;
                });
                return found ? !!found.approved : (p.status === 'active');
            };

            const addImg = (url, label) => {
                if (!url || url.includes('placehold')) return;
                const exists = gatheredImages.find(x => x.url === url);
                if (!exists) gatheredImages.push({ url, label, approved: isApproved(url) });
            };
            
            addImg(p.image, 'تصویر اصلی سایت');
            addImg(p.social_image, 'تصویر سوشال');
            
            if (Array.isArray(p.gallery)) {
                p.gallery.forEach((g, idx) => addImg(g, `گالری ${idx + 1}`));
            }
            if (Array.isArray(p.reference_gallery)) {
                p.reference_gallery.forEach((r, idx) => addImg(r, `مرجع ${idx + 1}`));
            }
            
            if (gatheredImages.length === 0) {
                grid.innerHTML = `<div class="col-span-3 text-center text-xs font-bold text-slate-400 py-8">تصویر معتبری برای این محصول یافت نشد.</div>`;
                return;
            }
            
            gatheredImages.forEach(img => {
                const div = document.createElement('div');
                div.className = 'border border-slate-200 hover:border-indigo-500 rounded-xl p-2 bg-slate-50 cursor-pointer flex flex-col items-center gap-1.5 transition-all text-center group relative';
                div.setAttribute('onclick', `applyProductImageToColumn('${id}', '${encodeURIComponent(img.url)}')`);
                
                div.innerHTML = `
                    <div class="h-20 w-full rounded flex items-center justify-center bg-white overflow-hidden shrink-0">
                        <img src="${resolveImageUrl(img.url)}" referrerpolicy="no-referrer" class="max-h-full max-w-full object-contain">
                    </div>
                    <div class="text-[8px] font-black text-slate-500 leading-tight">${img.label}</div>
                    <span class="absolute top-1 left-1 border ${img.approved ? 'bg-emerald-500 border-emerald-400' : 'bg-amber-500 border-amber-400'} text-white text-[7px] font-black px-1 rounded shadow pointer-events-none">
                        ${img.approved ? 'تایید شده' : 'در انتظار'}
                    </span>
                `;
                grid.appendChild(div);
            });
        }

        function backToProductList() {
            document.getElementById('lib-products-grid').classList.remove('hidden');
            document.getElementById('lib-images-panel').classList.add('hidden');
        }

        function applyProductImageToColumn(prodId, encImgUrl) {
            const url = decodeURIComponent(encImgUrl);
            const cat = getActiveCatalog();
            
            if (cat && cat.pages[targetPageIdx]) {
                const col = cat.pages[targetPageIdx].columns[targetColIdx];
                col.product_id = prodId;
                col.content = url;
                
                renderBuilderWorkspace();
                closeModal('productLibraryModal');
            }
        }

        // ==========================================
        // AI PROMPT GENERATOR & IMPORTER LOGIC
        // ==========================================
        function openPromptMakerModal() {
            const cat = getActiveCatalog();
            if(!cat) return;
            
            // Build simple schema outline representing active page structures
            const pagesOutline = cat.pages.map((p, idx) => {
                return {
                    page_number: idx + 1,
                    columns_count: p.layout,
                    columns_types: p.columns.map(c => c.type)
                };
            });
            
            // Auto generate rich target prompt containing correct guidelines
            const brandTitleLabel = brandSettings.brand_title || 'برند خلاق ماارک‌شو فلو';
            const brandSubtitleLabel = brandSettings.brand_subtitle || 'کالکشن جدید لباس';
            
            const promptText = `Please act as a professional copywriter and branding expert for high-end catalog creation. We are preparing an offline-online brochure for a project called "${brandTitleLabel}" (${brandSubtitleLabel}).
Below is a structured arrangement of pages with columns. You MUST output ONLY valid JSON following this exact structure, with NO extra letters, markdown decoration, or warnings outside of the JSON block:

{
  "pages": [
    ${pagesOutline.map((p) => {
        return `    {
      "layout": ${p.columns_count},
      "columns": [
        ${p.columns_types.map((type) => {
            if (type === 'text') {
                return `        { "type": "text", "content": "### Heading\\nWrite rich engaging promotional copy, specifications, fabric/sizing info, or brand stories here." }`;
            } else {
                return `        { "type": "image", "content": "" }`;
            }
        }).join(',\n')}
      ]
    }`;
    }).join(',\n')}
  ]
}

Instructions:
1. Since the language chosen is "${cat.language}", please craft all heading slogan elements and descriptions in ${cat.language === 'fa' ? 'High-end elite Persian (فارسی خلاقانه و فاخر)' : 'Elite fluent creative English'}.
2. Use professional HTML-like markdown styling (like ### or list items with '-') in "content" texts.
3. For "image" columns, leave "content" as an empty string ("") or fill it with placeholder or product name so we can link product graphics manually.
4. Output ONLY the JSON block. Start with "{" and end with "}".`;

            document.getElementById('ai-prompt-output').value = promptText;
            document.getElementById('ai-json-input').value = '';
            document.getElementById('promptMakerModal').classList.remove('hidden');
        }

        function copyPromptText() {
            const textarea = document.getElementById('ai-prompt-output');
            textarea.select();
            document.execCommand('copy');
            alert('پرامپت با موفقیت در کلیپ‌بورد کپی شد.');
        }

        function runDirectCatalogAI() {
            const promptStr = document.getElementById('ai-prompt-output').value;
            const btn = document.getElementById('btn-run-catalog-ai');
            const spinner = document.getElementById('ai-catalog-spinner');
            const jsonField = document.getElementById('ai-json-input');
            
            if (!promptStr) {
                alert('ساختار نامعتبر است.');
                return;
            }
            
            btn.disabled = true;
            if (spinner) spinner.classList.remove('hidden');
            btn.classList.add('opacity-75');
            jsonField.placeholder = '⌛ هوش مصنوعی در حال تولید کدهای ساختاری کاتالوگ شماست... لطفاً منتظر بمانید...';
            jsonField.value = '';
            
            const fd = new FormData();
            fd.append('action', 'catalog_generate_ai');
            fd.append('prompt', promptStr);
            
            const searchParams = new URLSearchParams(window.location.search);
            const ajaxUrl = 'index.php?' + searchParams.toString();
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    if (spinner) spinner.classList.add('hidden');
                    btn.classList.remove('opacity-75');
                    
                    if (res.success) {
                        let text = res.response.trim();
                        // Clean up markdown block wraps if present
                        if (text.startsWith('```')) {
                            // strip leading e.g. ```json or ```
                            text = text.substring(3);
                            if (text.startsWith('json')) {
                                text = text.substring(4);
                            }
                            if (text.endsWith('```')) {
                                text = text.substring(0, text.length - 3);
                            }
                            text = text.trim();
                        }
                        jsonField.value = text;
                        // auto import
                        importAICatalogJson();
                        alert('✨ ساختار و کپی‌رایتینگ کاتالوگ شما با موفقیت توسط هوش مصنوعی تکمیل و اعمال شد!');
                    } else {
                        alert('❌ خطا در ارتباط با هوش مصنوعی: ' + res.message);
                        jsonField.placeholder = '{ "pages": [ ... ] }';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    if (spinner) spinner.classList.add('hidden');
                    btn.classList.remove('opacity-75');
                    alert('❌ خطای غیرمنتظره در ارتباط با سرور.');
                    jsonField.placeholder = '{ "pages": [ ... ] }';
                });
        }

        function importAICatalogJson() {
            const rawJson = document.getElementById('ai-json-input').value.trim();
            if (!rawJson) {
                alert('لطفا متن پاسخ JSON هوش مصنوعی را در کادر وارد نمایید.');
                return;
            }
            
            try {
                // Clear out potential markdown wrap wrappers e.g. ```json ... ```
                let cleanedJson = rawJson;
                if (cleanedJson.startsWith('```')) {
                    cleanedJson = cleanedJson.replace(/^```[a-zA-Z]*\s*/, '').replace(/\s*```$/, '');
                }
                
                const parsed = JSON.parse(cleanedJson);
                if (!parsed || !Array.isArray(parsed.pages)) {
                    alert('قالب JSON نامعتبر است. ساختار باید دارای آرایه‌ای به نام pages باشد.');
                    return;
                }
                
                const cat = getActiveCatalog();
                if (!cat) return;
                
                // Merge/apply structure directly, conserving page IDs, layout arrays
                cat.pages = parsed.pages.map((p, idx) => {
                    const currentLayout = parseInt(p.layout) || 1;
                    const pageCols = Array.isArray(p.columns) ? p.columns : [];
                    
                    const normalizedCols = [];
                    for (let i = 0; i < currentLayout; i++) {
                        const colItem = pageCols[i] || { type: 'text', content: '' };
                        normalizedCols.push({
                            type: colItem.type || 'text',
                            content: colItem.content || '',
                            image_source: colItem.type === 'image' ? 'manual' : undefined,
                            manual_image_url: colItem.type === 'image' ? (colItem.content || '') : undefined
                        });
                    }
                    
                    return {
                        id: 'p_' + Math.random().toString(36).substr(2, 9),
                        layout: currentLayout,
                        columns: normalizedCols
                    };
                });
                
                renderBuilderWorkspace();
                closeModal('promptMakerModal');
                alert('🎉 ایمپورت داده‌های هوشمند کاتالوگ با موفقیت به پایان رسید! اکنون می‌توانید بخش عکس‌ها را دستی بارگذاری کنید.');
                
            } catch (e) {
                alert('خطا در تحلیل JSON ارسالی: ' + e.message + '\nلطفا بررسی کنید که خروجی حتما JSON معتبر و بدون نقص باشد.');
            }
        }

        // Write Catalogs Array permanently to persistence database in Server Project settings
        function saveAllToDatabase() {
            const payload = {
                action: 'save_catalogs',
                catalogs: currentCatalogsList,
                catalog_settings: brandSettings
            };
            
            const btns = document.querySelectorAll('button[onclick="saveAllToDatabase()"]');
            const origTexts = [];
            btns.forEach((b, idx) => {
                origTexts[idx] = b.innerHTML;
                b.innerHTML = '<span>⏳</span> <span>درحال ذخیره‌سازی...</span>';
                b.disabled = true;
            });

            const searchParams = new URLSearchParams(window.location.search);
            searchParams.set('action', 'save_catalogs');

            fetch('?' + searchParams.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btns.forEach((b, idx) => {
                    b.innerHTML = origTexts[idx];
                    b.disabled = false;
                });
                if (data.success) {
                    alert('✅ کاتالوگ‌ها با موفقیت ذخیره شدند.');
                } else {
                    alert('خطا در ذخیره‌سازی مراجع: ' + data.message);
                }
            })
            .catch(err => {
                btns.forEach((b, idx) => {
                    b.innerHTML = origTexts[idx] || '<span>💾</span> <span>ذخیره تغییرات</span>';
                    b.disabled = false;
                });
                alert('خطادر ذخیره قالب‌ها بر روی پروژه.');
            });
        }

        // Dynamic printing size & guidance handlers
        function changePrintSize(size) {
            const paper = document.getElementById('showcase-outer-paper');
            if (!paper) return;
            
            // Remove previous classes
            paper.classList.remove('paper-a4-portrait', 'paper-a4-landscape', 'paper-a5-portrait', 'paper-a5-landscape', 'paper-square');
            paper.classList.remove('print-a4-portrait', 'print-a4-landscape', 'print-a5-portrait', 'print-a5-landscape', 'print-square');
            
            let printStyle = '';
            if (size === 'a4-portrait') {
                paper.classList.add('paper-a4-portrait', 'print-a4-portrait');
                printStyle = '@page { size: A4 portrait; margin: 10mm; }';
            } else if (size === 'a4-landscape') {
                paper.classList.add('paper-a4-landscape', 'print-a4-landscape');
                printStyle = '@page { size: A4 landscape; margin: 10mm; }';
            } else if (size === 'a5-portrait') {
                paper.classList.add('paper-a5-portrait', 'print-a5-portrait');
                printStyle = '@page { size: A5 portrait; margin: 10mm; }';
            } else if (size === 'a5-landscape') {
                paper.classList.add('paper-a5-landscape', 'print-a5-landscape');
                printStyle = '@page { size: A5 landscape; margin: 10mm; }';
            } else if (size === 'square') {
                paper.classList.add('paper-square', 'print-square');
                printStyle = '@page { size: 210mm 210mm; margin: 10mm; }';
            }
            
            // Set dynamic @page style in head
            let styleTag = document.getElementById('print-dynamic-size-style');
            if (!styleTag) {
                styleTag = document.createElement('style');
                styleTag.id = 'print-dynamic-size-style';
                document.head.appendChild(styleTag);
            }
            styleTag.innerHTML = `@media print { ${printStyle} }`;
        }

        function triggerDefaultPrint() {
            window.print();
        }

        function downloadAsPdf() {
            const m = document.getElementById('pdfGuidanceModal');
            if (m) m.classList.remove('hidden');
        }

        function invokeBrowserPrint() {
            closeModal('pdfGuidanceModal');
            setTimeout(() => {
                window.print();
            }, 300);
        }
    </script>
</body>
</html>
