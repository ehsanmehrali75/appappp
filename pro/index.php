<?php
/**
 * Standalone Proposal & Numeric Planning Builder Module
 * Route: /pro
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

$conn = getConn();

// Database migration / initialization fallback just in case
if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS proposals (
        id VARCHAR(128) PRIMARY KEY, 
        project_id VARCHAR(128), 
        type VARCHAR(50), 
        title VARCHAR(255), 
        logo_url TEXT, 
        status VARCHAR(50), 
        content LONGTEXT, 
        created_at INT, 
        updated_at INT
    )");
}

// Check if viewing a public link
$publicId = $_GET['public_id'] ?? $_GET['id'] ?? '';
$isPublicView = false;
$proposalData = null;

if (!empty($publicId)) {
    // Attempt fetching the proposal bypass auth
    if ($conn) {
        $stmt = $conn->prepare("SELECT * FROM proposals WHERE id = ?");
        $stmt->bind_param("s", $publicId);
        $stmt->execute();
        $res = $stmt->get_result();
        $proposalData = $res ? $res->fetch_assoc() : null;
    }
    if ($proposalData && $proposalData['status'] === 'published') {
        $isPublicView = true;
    }
}

// User authentication check for non-public workspace
if (!$isPublicView) {
    if (!isUser()) {
        redirect('../index.php');
    }
    if (!hasPermission('catalog') && !hasPermission('settings') && !hasPermission('products')) {
        die('<div style="font-family:Tahoma; text-align:center; padding: 100px; direction: rtl; background-color: #f8fafc; height: 100vh;">
            <div style="background: white; max-width: 500px; margin: auto; padding: 40px; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                <h2 style="color:#ef4444; font-size: 24px; margin-bottom: 16px;">⚠️ عدم دسترسی کافی</h2>
                <p style="color:#64748b; font-size: 14px; line-height: 2;">کاربر گرامی، شما مجوزهای لازم جهت دسترسی به ماژول مدیریت پروپوزال و برنامه‌ها را ندارید.</p>
                <hr style="margin: 24px 0; border: 0; border-top: 1px solid #f1f5f9;">
                <a href="../admin.php" style="display: inline-block; background: #4f46e5; color: white; padding: 12px 24px; border-radius: 12px; font-weight: bold; text-decoration: none; font-size: 13px;">← بازگشت به پنل اصلی</a>
            </div>
        </div>');
    }
}

// Handle Ajax endpoints
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $ajax = $_POST['ajax_action'];
    
    if ($ajax === 'generate_ai') {
        $prompt = trim($_POST['prompt'] ?? '');
        $type = $_POST['type'] ?? 'proposal';
        
        if (empty($prompt)) {
            echo json_encode(['success' => false, 'error' => 'لطفاً شرح یا اطلاعات کلی پروژه را کادر مربوطه بنویسید.']);
            exit;
        }
        
        $systemInstructions = "تو یک طراح پروپوزال نویس و برنامه‌ریز ارشد و حرفه‌ای در حوزه سئو، دیجیتال مارکتینگ و فضای مجازی هستی. شما باید خروجی را دقیقاً و صرفاً به صورت یک آبجکت معتبر JSON طبق ساختار مشخص شده تولید و برگردانید. از فرمت‌های پاسخ دیگر خودداری کنید. زبان پاسخ نیز کاملاً ادبی، تخصصی، برندینگ، مجاب‌کننده و فارسی روان و حرفه‌ای باشد.";
        
        if ($type === 'plan') {
            $systemInstructions .= " ساختار خروجی برنامه (plan) باید دقیقاً منطبق بر این قالب JSON باشد:
            {
              \"logo\": \"\",
              \"client_name\": \"نام شرکت یا کارفرما\",
              \"title\": \"برنامه برنامه‌ریزی عددی هدف و تارگت‌های سئو\",
              \"subtitle\": \"دوره ۳ ماهه ارزیابی و رشد\",
              \"variables\": [
                {\"id\": \"monthly_sales\", \"label\": \"فروش ماهانه فعلی (تومان)\", \"type\": \"number\", \"value\": 120000000},
                {\"id\": \"target_sales\", \"label\": \"تارگت فروش ماهانه هدف (تومان)\", \"type\": \"number\", \"value\": 250000000},
                {\"id\": \"growth_rate\", \"label\": \"نرخ رشد پیش‌بینی شده درصد\",\"type\": \"formula\", \"formula\": \"(([target_sales] - [monthly_sales]) / [monthly_sales]) * 100\", \"value\": 108},
                {\"id\": \"daily_sales_avg\", \"label\": \"میانگین فروش روزانه پایه (تومان)\", \"type\": \"formula\", \"formula\": \"[monthly_sales] / 30\", \"value\": 4000000},
                {\"id\": \"daily_target_avg\", \"label\": \"میانگین فروش روزانه هدف (تومان)\", \"type\": \"formula\", \"formula\": \"[target_sales] / 30\", \"value\": 8333333}
              ],
              \"layout\": [
                {
                  \"section_title\": \"بخش اول: وضعیت عددی کنونی و هزینه‌کرد کمپین\",
                  \"fields\": [\"monthly_sales\", \"daily_sales_avg\"]
                },
                {
                  \"section_title\": \"بخش دوم: اهداف درآمدی و رشد کوین‌ها\",
                  \"fields\": [\"target_sales\", \"growth_rate\", \"daily_target_avg\"]
                }
              ],
              \"current_status\": \"تحلیل کامل و فنی فارسی درباره ترافیک آلی پایین لندینگ‌ها، میزان کلمات با رنک خوب اما ایمپرشن کم و جای خالی ابزار زمان‌بندی منظم روبیکا و بله.\",
              \"seo_targets_text\": \"۱. بهینه‌سازی ۱۲ صفحه کانونی بر اساس کیوردهای استخراجی\\n۲. ارتقای رتبه ۵۰ کوئری به صفحه اول گوگل در ۹۰ روز\",
              \"social_targets_text\": \"۱. فعال‌سازی جریان ارسال اتوماتیک سناریوهای مصلحتی به کانال‌های روبیکا و بله\\n۲. رشد ۳۰ درصدی جذب لید خالص\"
            }";
        } else {
            $systemInstructions .= " ساختار خروجی پروپوزال (proposal) باید دقیقاً منطبق بر این قالب JSON باشد:
            {
              \"logo\": \"\",
              \"client_name\": \"نام کارفرما یا برند مخاطب\",
              \"title\": \"پروپوزال فنی توسعه کسب‌وکار و بازاریابی هوشمند\",
              \"subtitle\": \"تهیه و ارائه شده توسط مارولی جریان دیجیتال\",
              \"introduction\": \"مقدمه شیک و توام با کلمات تاثیرگذار بازاریابی درباره رشد فروش و برندینگ با متدهای مدرن اتوماسیون محتوا.\",
              \"about_us\": \"مختصری شیک پیرامون استفاده از ابزار مارک‌شو، ربات‌های استخراج خودکار کاتالوگ محصول، سیستم‌های زمان‌بندی و پایش مداوم سایت به صورت زنده.\",
              \"strategy_seo\": \"توضیح متمایز کننده در مورد استراتژی سئو هدفمند مبتنی بر کوئری‌های پرورودی، سئو تکنیکال و ردیابی مستمر کلمات سئو.\",
              \"strategy_social\": \"استراتژی توزیع محتوا در شبکه‌های بله، روبیکا و تلگرام با کمک پنل انتشار زمان‌بندی شده برای پوشش صددرصدی مخاطبان هدف.\",
              \"slides\": [
                {
                  \"title\": \"۱. فاز برنامه‌ریزی و تدوین سبد واژگان خرید\",
                  \"content\": \"پایش و رصد کوئری‌های هدفمند و تحلیل عمیق و اتوماتیک عملکرد ترافیکی رقبا.\"
                },
                {
                  \"title\": \"۲. فاز توسعه لندینگ پیج‌ها و کاتالوگ پویای کالا\",
                  \"content\": \"تولید هوشمند کاتالوگ آنلاین پیشرفته و سازگار با تلفن همراه جهت جذب سریع‌تر خریداران.\"
                },
                {
                  \"title\": \"۳. فاز رصد و بهبود مستمر رنکینگ سئو\",
                  \"content\": \"به‌کارگیری ردیاب پیشگیری از سقوط رتبه و سنجه‌های لایو آنالیتیکس در پورتال جریان.\"
                }
              ],
              \"packages\": [
                {
                  \"name\": \"طرح نقره‌ای استارتر رقابتی\",
                  \"price\": \"۸,۰۰۰,۰۰۰ تومان / ماه\",
                  \"features\": [\"پایش ۳۰ کلمه کلیدی سئو\", \"تولید کاتالوگ پویای هوشمند\", \"یکپارچه‌سازی وب‌هوک روبیکا و تفکیک جریان\"]
                },
                {
                  \"name\": \"طرح VIP شتاب‌دهنده تجاری (کاملترین)\",
                  \"price\": \"۱۸,۰۰۰,۰۰۰ تومان / ماه\",
                  \"features\": [\"ردیاب کوئری روزانه نامحدود\", \"اتصال کامل سیستم استخراج هوشمند و ارسال به بله، تلگرام و روبیکا\", \"پنل چت زنده یکپارچه ادمین\", \"سئو تکنیکال لندینگ‌ها و بهینه‌سازی سرعت\"]
                }
              ]
            }";
        }
        
        $systemInstructions .= " فقط و فقط آبجکت حاصل از پردازش JSON را برگردانید و به هیچ وجه توضیح اضافی قبل یا بعد از کد ندهید. به هیچ وجه نباید متن پاسخ شما با کلماتی خارج از بلوک JSON شروع شود تا سیستم خطا ندهد. پاسخ مستقیم شما بدون تگ مارک‌داون پردازش می‌شود.";
        
        $res = geminiGenerateResponse($prompt, $systemInstructions);
        if (isset($res['error'])) {
            echo json_encode(['success' => false, 'error' => $res['error']]);
        } else {
            $rawText = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (empty($rawText)) {
                $rawText = $res['text'] ?? '';
            }
            
            $rawText = trim($rawText);
            if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $rawText, $matches)) {
                $rawText = $matches[1];
            }
            $rawText = trim($rawText);
            
            $decoded = json_decode($rawText, true);
            if ($decoded === null) {
                echo json_encode(['success' => false, 'error' => 'کیفیت پاسخ هوش مصنوعی مناسب نبود یا فرمت JSON صحیح نبود. متن خام تولید شده: ' . substr($rawText, 0, 150) . '...']);
            } else {
                echo json_encode(['success' => true, 'data' => $decoded]);
            }
        }
        exit;
    }
    
    if ($ajax === 'save') {
        $id = trim($_POST['id'] ?? '');
        $pid = trim($_POST['project_id'] ?? '');
        $type = $_POST['type'] ?? 'proposal';
        $title = trim($_POST['title'] ?? '');
        $logo_url = trim($_POST['logo_url'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        $content = $_POST['content'] ?? '{}';
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'error' => 'لطفاً یک عنوان مناسب برای سند وارد کنید.']);
            exit;
        }
        
        if (empty($id)) {
            $id = uniqid('pr_');
            $now = time();
            $stmt = $conn->prepare("INSERT INTO proposals (id, project_id, type, title, logo_url, status, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssiii", $id, $pid, $type, $title, $logo_url, $status, $content, $now, $now);
        } else {
            $now = time();
            $stmt = $conn->prepare("UPDATE proposals SET title = ?, logo_url = ?, status = ?, content = ?, updated_at = ? WHERE id = ?");
            $stmt->bind_param("sssisi", $title, $logo_url, $status, $content, $now, $id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $id]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit;
    }
    
    if ($ajax === 'delete') {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'شناسه نامعتبر است.']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM proposals WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit;
    }
    
    if ($ajax === 'upload_logo') {
        if (!empty($_FILES['logo_file']) && $_FILES['logo_file']['error'] == 0) {
            $dir = __DIR__ . '/../uploads/';
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            $ext = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
            $fileName = 'logo_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dir . $fileName)) {
                // Return dynamic absolute site path
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                echo json_encode(['success' => true, 'url' => '../uploads/' . $fileName]);
            } else {
                echo json_encode(['success' => false, 'error' => 'خطا در جابجایی فایل آپلود شده.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'فایلی جهت آپلود یافت نشد یا خطا رخ داد.']);
        }
        exit;
    }
}

// Manage project contexts
if (isset($_GET['project'])) {
    $swPid = $_GET['project'];
    $_SESSION['project_id'] = $swPid;
} else {
    $current_pid = $_SESSION['project_id'] ?? null;
    if (!$current_pid) {
        $db = getDB();
        if (!empty($db['projects'])) {
            $first_p = reset($db['projects']);
            $current_pid = $first_p['id'] ?? null;
        }
    }
    if ($current_pid) {
        $_SESSION['project_id'] = $current_pid;
        redirect('index.php?project=' . urlencode($current_pid));
    }
}

$allProjects = [];
$db = getDB();
if (isSuperAdmin()) {
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

$project = getCurrentProject();
if (!$project) {
    die('پروژه فعال یافت نشد.');
}
if (empty($allProjects)) {
    $allProjects[] = $project;
}

// Fetch existing documents for the active project
$allDocs = [];
if ($conn && !$isPublicView) {
    $project_esc = $project['id'];
    $stmt = $conn->prepare("SELECT * FROM proposals WHERE project_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $project_esc);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $allDocs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isPublicView ? htmlspecialchars($proposalData['title']) : 'استودیو طراحی پروپوزال و برنامه‌ریزی مارک‌شو'; ?></title>
    <!-- Tailwind CSS -->
    <script src="../assets/tailwindcss.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/vazirmatn@30.1.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <style>
        body {
            font-family: 'Vazirmatn', 'Tahoma', sans-serif;
            background-color: #fafbfd;
            color: #1e293b;
        }
        .premium-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 20px -2px rgba(51, 65, 85, 0.05);
            transition: all 0.3s ease;
        }
        .premium-card:hover {
            box-shadow: 0 10px 30px -4px rgba(51, 65, 85, 0.1);
        }
        /* Custom print styling to have perfect PDF exports */
        @media print {
            body { 
                background: white !important; 
                color: black !important;
            }
            .sidebar-control, .prompt-board-wrapper, .normal-header, .no-print-element {
                display: none !important;
            }
            .print-full-width {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }
            .page-break-before {
                page-break-before: always;
            }
            .package-card, .layout-col {
                break-inside: avoid;
            }
        }
        .ltr-input {
            direction: ltr !important;
            text-align: left !important;
            font-family: monospace;
        }
    </style>
</head>
<body class="bg-slate-50/50 min-h-screen">

<!-- MAIN LAYOUT SWITCHER -->
<?php if ($isPublicView): ?>
    <!-- PUBLIC PRESENTATION READ-ONLY VIEW (GORGEOUSLY CUSTOMIZED) -->
    <?php 
        $doc = json_decode($proposalData['content'], true) ?: []; 
        $logo = !empty($proposalData['logo_url']) ? $proposalData['logo_url'] : ($doc['logo'] ?? '');
    ?>
    <div class="max-w-4xl mx-auto px-4 py-8 md:py-12 print-full-width" id="public-pdf-container">
        <!-- Action bar at the top -->
        <div class="mb-6 flex justify-between items-center no-print-element">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-700 text-xs font-black rounded-lg">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                لینک نمایش زنده مشتری
            </span>
            <button onclick="window.print();" class="px-5 py-2.5 bg-slate-900 text-white font-extrabold text-xs rounded-xl shadow-md hover:bg-slate-800 transition-all flex items-center gap-1.5 cursor-pointer">
                <span>🖨️</span> چاپ سند / ذخیره به عنوان PDF
            </button>
        </div>

        <div class="premium-card p-8 md:p-12 rounded-3xl border border-slate-100 shadow-xl bg-white print-full-width space-y-8">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-6 border-b border-slate-100 pb-8">
                <div class="text-right space-y-2">
                    <h1 class="text-2xl font-black text-slate-800 mb-1 leading-tight"><?php echo htmlspecialchars($proposalData['title']); ?></h1>
                    <p class="text-xs text-slate-400 font-extrabold flex items-center gap-1">
                        <span>مشتری محترم:</span>
                        <strong class="text-indigo-600 font-black"><?php echo htmlspecialchars($doc['client_name'] ?? 'مشتری گرامی'); ?></strong>
                        <span class="mx-1">•</span>
                        <span>تاریخ ارائه: <?php echo jdate('Y/m/d', $proposalData['created_at']); ?></span>
                    </p>
                </div>
                <?php if (!empty($logo)): ?>
                    <img id="public-logo-preview" src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="max-h-16 max-w-[180px] object-contain rounded-xl shadow-sm border border-slate-55 p-1.5 bg-slate-50">
                <?php else: ?>
                    <div class="p-3 bg-indigo-50 text-indigo-600 text-3xl font-black rounded-2xl">M</div>
                <?php endif; ?>
            </div>

            <!-- TYPES CONDITIONAL: PROPOSAL VS PLAN -->
            <?php if ($proposalData['type'] === 'plan'): ?>
                <!-- PLAN (برنامه‌ریزی و ارقام عددی) -->
                <div class="space-y-8">
                    <!-- Current Status and introductory summary -->
                    <div class="space-y-2">
                        <h3 class="text-md font-black text-slate-800 flex items-center gap-2">🔍 تحلیل وضعیت و ارزیابی ترافیکی</h3>
                        <div class="bg-indigo-50/20 text-slate-700 p-5 rounded-2xl leading-relaxed text-xs font-medium border border-indigo-50">
                            <?php echo nl2br(htmlspecialchars($doc['current_status'] ?? '')); ?>
                        </div>
                    </div>

                    <!-- BENTO GRID FOR METRICS AND CALCULATIONS -->
                    <div>
                        <h3 class="text-md font-black text-slate-800 mb-4 flex items-center gap-2">📊 سنجه‌های عملکردی (KPIs) و اهداف عددی</h3>
                        
                        <!-- Reactive Formula Workspace Board -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php 
                            $layout = $doc['layout'] ?? [];
                            $variables = $doc['variables'] ?? [];
                            foreach ($layout as $secIdx => $sec): 
                            ?>
                                <div class="bg-slate-50/60 p-5 rounded-2xl border border-slate-100 space-y-4 layout-col">
                                    <h4 class="text-xs font-black text-slate-700 border-b pb-2 flex items-center gap-1.5">
                                        <span class="w-1.5 h-3 bg-indigo-500 rounded-sm"></span>
                                        <?php echo htmlspecialchars($sec['section_title'] ?? ''); ?>
                                    </h4>
                                    <div class="space-y-3">
                                        <?php 
                                        foreach ($sec['fields'] as $fId) {
                                            $currVar = null;
                                            foreach ($variables as $v) {
                                                if ($v['id'] === $fId) { $currVar = $v; break; }
                                            }
                                            if ($currVar):
                                            ?>
                                                <div class="flex items-center justify-between p-3 bg-white border border-slate-100 rounded-xl shadow-sm">
                                                    <span class="text-xs text-slate-600 font-bold"><?php echo htmlspecialchars($currVar['label']); ?>:</span>
                                                    <div class="text-left font-sans">
                                                        <strong class="font-extrabold text-sm text-indigo-700 f-val-display" data-vid="<?php echo htmlspecialchars($currVar['id']); ?>" data-vtype="<?php echo $currVar['type']; ?>" data-formula="<?php echo htmlspecialchars($currVar['formula'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars(number_format($currVar['value'])); ?>
                                                        </strong>
                                                        <span class="text-[10px] text-slate-400 font-black mr-0.5"><?php echo $currVar['type'] === 'formula' ? '🔄' : ''; ?></span>
                                                    </div>
                                                </div>
                                            <?php 
                                            endif;
                                        } 
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Targets Block in columns -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 page-break-before">
                        <div class="p-6 bg-emerald-50/20 border border-emerald-100/60 rounded-3xl space-y-3">
                            <h4 class="text-xs font-black text-emerald-800 flex items-center gap-1.5">
                                <span>🎯</span> برنامه و تارگت‌های فرآیندی سئو (Search Engine Optimization)
                            </h4>
                            <div class="text-slate-700 text-xs leading-relaxed space-y-1 font-medium whitespace-pre-line">
                                <?php echo htmlspecialchars($doc['seo_targets_text'] ?? "باهدف ارتقای رتبه‌بندی..."); ?>
                            </div>
                        </div>

                        <div class="p-6 bg-pink-50/20 border border-pink-100/60 rounded-3xl space-y-3">
                            <h4 class="text-xs font-black text-pink-800 flex items-center gap-1.5">
                                <span>📢</span> برنامه شبکه‌های اجتماعی و جریان اتوماتیک (Social Target)
                            </h4>
                            <div class="text-slate-700 text-xs leading-relaxed space-y-1 font-medium whitespace-pre-line">
                                <?php echo htmlspecialchars($doc['social_targets_text'] ?? "انتشار منظم محتوای زمان‌بندی شده..."); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- PROPOSAL (طرح شراکت و کاتالوگ خدمات) -->
                <div class="space-y-8">
                    <!-- Intro Summary -->
                    <div class="space-y-3">
                        <h3 class="text-md font-black text-slate-800 flex items-center gap-2">🤝 مقدمه و شرح کلی طرح ارتقا</h3>
                        <div class="text-slate-600 text-xs leading-relaxed font-semibold bg-indigo-50/10 p-5 rounded-2xl border border-dotted border-indigo-150">
                            <?php echo nl2br(htmlspecialchars($doc['introduction'] ?? '')); ?>
                        </div>
                    </div>

                    <!-- Services Block (About automation & catalog) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100 space-y-2">
                            <h4 class="text-xs font-black text-slate-700 flex items-center gap-1.5">🚀 تکنولوژی پایش هوشمند و اتوماسیون محتوا</h4>
                            <p class="text-slate-500 font-medium text-xs leading-relaxed"><?php echo htmlspecialchars($doc['about_us'] ?? ''); ?></p>
                        </div>
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100 space-y-2">
                            <h4 class="text-xs font-black text-slate-700 flex items-center gap-1.5">📊 استراتژی سئو و ردیابی کوئری‌ها</h4>
                            <p class="text-slate-500 font-medium text-xs leading-relaxed"><?php echo htmlspecialchars($doc['strategy_seo'] ?? ''); ?></p>
                        </div>
                    </div>

                    <!-- Custom Slide Pages -->
                    <div class="space-y-4 page-break-before">
                        <h3 class="text-sm font-black text-slate-700 pb-2 border-b">📋 فازها و سرفصل‌های اجرایی پروژه</h3>
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($doc['slides'] ?? [] as $slide): ?>
                                <div class="p-5 bg-white border border-slate-100 rounded-2xl shadow-sm space-y-1">
                                    <h4 class="text-xs font-extrabold text-indigo-700 flex items-center gap-1.5">
                                        <span class="w-1.5 h-2 bg-indigo-600 rounded-sm"></span>
                                        <?php echo htmlspecialchars($slide['title'] ?? ''); ?>
                                    </h4>
                                    <p class="text-slate-550 font-medium text-xs leading-relaxed"><?php echo htmlspecialchars($slide['content'] ?? ''); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Strategy / Packages pricing -->
                    <div class="space-y-4 page-break-before">
                        <h3 class="text-sm font-black text-slate-700 pb-2 border-b">💰 تعرفه‌ها، پکیج‌ها و هزینه‌های سرمایه‌گذاری</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php foreach ($doc['packages'] ?? [] as $pkg): ?>
                                <div class="bg-gradient-to-b from-indigo-50/20 to-white hover:from-white border border-indigo-100 rounded-3xl p-6 shadow-sm space-y-4 package-card">
                                    <div class="text-center space-y-1.5">
                                        <h4 class="font-black text-indigo-900 text-xs"><?php echo htmlspecialchars($pkg['name'] ?? ''); ?></h4>
                                        <div class="text-emerald-700 text-sm font-black tracking-wider"><?php echo htmlspecialchars($pkg['price'] ?? ''); ?></div>
                                    </div>
                                    <hr class="border-indigo-100/30">
                                    <ul class="space-y-2 text-xs font-medium text-slate-600">
                                        <?php foreach ($pkg['features'] ?? [] as $feat): ?>
                                            <li class="flex items-center gap-2">
                                                <span class="text-indigo-500 font-black">✓</span>
                                                <span><?php echo htmlspecialchars($feat); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Footer Details -->
            <div class="border-t border-slate-100 pt-8 flex flex-col md:flex-row items-center justify-between text-slate-400 text-[10px] font-black uppercase">
                <span>تولید شده به وسیله پلتفرم هوشمند جریان مارک‌شو</span>
                <span>سامانه مدیریت متمرکز پروپوزال</span>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- ADMIN INTEGRATED PANEL & SANDBOX CONTROLLER -->
    <!-- Top premium navigation header -->
    <header class="bg-white border-b border-slate-200/60 sticky top-0 z-40 normal-header">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-indigo-650 text-white font-black rounded-2xl shadow-md text-xl">📋</div>
                <div>
                    <h1 class="font-extrabold text-sm text-slate-900">استودیو طراحی پروپوزال و برنامه‌ریزی مارک‌شو</h1>
                    <p class="text-[10px] text-slate-400 font-bold">طراحی تخصصی اوراق پروپوزال و محاسبات عددی سئو هوش مصنوعی</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                <form method="GET" class="flex items-center gap-2">
                    <label class="text-[10px] font-black text-slate-400 whitespace-nowrap">انتخاب پروژه:</label>
                    <select name="project" onchange="this.form.submit()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:border-indigo-500 outline-none cursor-pointer">
                        <?php foreach($allProjects as $p_item): ?>
                            <option value="<?php echo htmlspecialchars($p_item['id']); ?>" <?php echo $p_item['id'] === $project['id'] ? 'selected' : ''; ?>>
                                🚀 <?php echo htmlspecialchars($p_item['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="../admin.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs rounded-xl border border-slate-200 transition-all">← پنل ادمین</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- LEFT COLUMN: EXISTING DOCUMENTS & ACTIONS -->
        <div class="lg:col-span-4 space-y-6">
            <!-- New Document Creator Card -->
            <div class="p-6 bg-white border border-slate-200/50 rounded-3xl shadow-sm space-y-4">
                <h3 class="font-black text-xs text-slate-800 pb-2 border-b flex items-center justify-between">
                    <span>➕ ایجاد سند جدید</span>
                    <span class="text-[9px] text-slate-400">سریع و حرفه‌ای</span>
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <button onclick="createNewDoc('proposal')" class="p-4 bg-indigo-50/50 hover:bg-indigo-50 border border-indigo-150 rounded-2xl text-center cursor-pointer transition-all space-y-2 group">
                        <span class="text-2xl block group-hover:scale-110 transition-transform">📄</span>
                        <span class="text-[11px] font-black text-indigo-900 block">پروپوزال جدید</span>
                    </button>
                    <button onclick="createNewDoc('plan')" class="p-4 bg-emerald-50/50 hover:bg-emerald-50 border border-emerald-115 rounded-2xl text-center cursor-pointer transition-all space-y-2 group">
                        <span class="text-2xl block group-hover:scale-110 transition-transform">📊</span>
                        <span class="text-[11px] font-black text-emerald-990 block">برنامه / تارگت جدید</span>
                    </button>
                </div>
            </div>

            <!-- Existing Documents List -->
            <div class="p-6 bg-white border border-slate-200/50 rounded-3xl shadow-sm space-y-4">
                <h3 class="font-black text-xs text-slate-800 pb-2 border-b">📂 اسناد ساخته شده این پروژه</h3>
                <?php if (empty($allDocs)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs font-bold leading-5">
                        <span class="text-4xl block mb-2">📥</span>
                        هنوز هیچ پروپوزال یا برنامه عددی برای این پروژه ثبت نشده است. همین حالا دکمه ایجاد سند را بفشارید.
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-h-[380px] overflow-y-auto pr-1">
                        <?php foreach ($allDocs as $docRow): ?>
                            <div class="p-3 bg-slate-50 hover:bg-white border hover:border-indigo-205 rounded-2xl shadow-sm transition-all space-y-2.5 relative group">
                                <div class="flex items-start justify-between">
                                    <div class="text-right">
                                        <h4 class="font-black text-xs text-slate-800 truncate max-w-[170px]" title="<?php echo htmlspecialchars($docRow['title']); ?>"><?php echo htmlspecialchars($docRow['title']); ?></h4>
                                        <span class="text-[9px] font-bold text-slate-400 block mt-0.5"><?php echo $docRow['type'] === 'plan' ? '📈 برنامه محاسباتی سئو' : '💼 پروپوزال طراحی خدمات'; ?></span>
                                    </div>
                                    <span class="text-[9px] font-black px-2 py-1 rounded-lg <?php echo $docRow['status'] === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200/60 text-slate-500'; ?>">
                                        <?php echo $docRow['status'] === 'published' ? 'منتشر شده' : 'پیش‌نویس'; ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5 pt-1.5 border-t border-slate-200/40">
                                    <button onclick='loadDoc(<?php echo json_encode($docRow); ?>)' class="px-2.5 py-1.5 bg-slate-900 text-white font-extrabold text-[10px] rounded-lg hover:bg-slate-800 transition-all cursor-pointer">📂 ویرایش</button>
                                    <button onclick="deleteDoc('<?php echo $docRow['id']; ?>')" class="px-2.5 py-1.5 bg-rose-50 text-rose-600 border border-rose-100 font-extrabold text-[10px] rounded-lg hover:bg-rose-100 transition-all cursor-pointer">✕ حذف</button>
                                    <?php if ($docRow['status'] === 'published'): ?>
                                        <a href="?public_id=<?php echo $docRow['id']; ?>" target="_blank" class="px-2.5 py-1.5 bg-indigo-50 text-indigo-700 font-extrabold text-[10px] rounded-lg hover:bg-indigo-100 transition-all">👓 لینک</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PROMPT GENERATOR BOARD (ASK GEMINI AI) -->
            <div class="p-6 bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 text-white rounded-3xl shadow-lg border border-indigo-800/20 space-y-4">
                <h3 class="font-black text-sm text-indigo-200 flex items-center gap-1.5">
                    <span>✨</span> دستیار هوش مصنوعی و پرامپت‌میکر
                </h3>
                <p class="text-[10px] text-slate-300 leading-relaxed font-bold">
                    مشخصات یا نیازمندی‌های مشتری را وارد کنید؛ هوش مصنوعی ساختار کاملی از پروپوزال یا برنامه سئو را تولید کرده و به عنوان الگو بارگذاری می‌کند.
                </p>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block mb-1 font-black text-slate-300">نوع سند تولیدی:</label>
                        <select id="ai-generate-type" class="w-full p-2.5 bg-slate-800/80 border border-slate-700 rounded-xl outline-none focus:border-indigo-400 text-white font-bold cursor-pointer">
                            <option value="proposal" selected>💼 پروپوزال جامع و طرح شراکت</option>
                            <option value="plan">📈 برنامه، تارگت عددی و سئو</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1 font-black text-slate-300">متن پرامپت یا نیازمندی‌های مشتری:</label>
                        <textarea id="ai-generate-prompt" rows="3" placeholder="مثال: من میخوام یک پروپوزال برای فروشگاه اینترنتی پوشاک چرم تهیه کنم که شامل تارگت های سئو و رشد ترافیک باشد..." class="w-full p-3 bg-slate-800/80 border border-slate-700 rounded-xl text-white outline-none focus:border-indigo-400 leading-relaxed placeholder:text-slate-500 font-medium"></textarea>
                    </div>

                    <!-- Predefined Prompt Tags -->
                    <div class="space-y-1">
                        <span class="text-[9px] text-slate-400 font-black block">پیشنهادهای آماده:</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button onclick="setAiPrompt('پروپوزال بهینه‌سازی سئو برای فروشگاه اینترنتی لوازم تحریر مهرآیین')" class="bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-[9px] font-black cursor-pointer text-slate-300">📝 لوازم‌تحریر</button>
                            <button onclick="setAiPrompt('برنامه‌ریزی عددی رشد ترافیک و فروش ماهانه برای پلتفرم املاک نوین')" class="bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-[9px] font-black cursor-pointer text-slate-300">📊 املاک نوین</button>
                            <button onclick="setAiPrompt('پروپوزال کمپین اتوماسیون پستهای روبیکا و بله برای کلینیک زیبایی شفا')" class="bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-[9px] font-black cursor-pointer text-slate-300">📢 کلینیک زیبایی</button>
                        </div>
                    </div>

                    <button id="ai-generate-btn" onclick="generateDocWithAi()" class="w-full py-3 bg-indigo-650 hover:bg-indigo-700 text-white font-black text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>⚡ ارسال به اتمسفر جریان AI</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: LIVE EDIT WORKSPACE & LIVE VIEW -->
        <div class="lg:col-span-8 space-y-6" id="editor-main-panel">
            <!-- Doc Config Controls Header (Title, Logo upload, Status) -->
            <div class="p-6 bg-white border border-slate-200/50 rounded-3xl shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b pb-4">
                    <h2 class="font-black text-sm text-slate-800 flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 bg-indigo-600 rounded-full animate-ping"></span>
                        <span>میز کار زنده (Live Editing Lab)</span>
                    </h2>
                    <div class="flex items-center gap-2">
                        <select id="doc-save-status" class="px-3 py-1.8 bg-slate-100 border border-slate-200 rounded-xl text-xs font-bold outline-none cursor-pointer">
                            <option value="draft">📁 پیش‌نویس (خصوصی)</option>
                            <option value="published">🌐 منتشر شده (لینک عمومی فعال)</option>
                        </select>
                        <button onclick="saveActiveDocument()" class="px-5 py-2.5 bg-indigo-650 hover:bg-indigo-700 text-white text-xs font-black rounded-xl shadow-md transition-all cursor-pointer">
                            💾 ذخیره سند
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-semibold">
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">عنوان رسمی سند (در کاتالوگ):</label>
                        <input id="doc-title-input" type="text" placeholder="مثال: پروپوزال دیجیتال مارکتینگ و سئو کفش ملی" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-bold text-slate-800">
                    </div>
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">مشتری یا کارفرمای هدف:</label>
                        <input id="doc-client-input" type="text" placeholder="مثال: جناب آقای مهندس عباسی" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-bold text-slate-800">
                    </div>
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">آدرس فابل لوگو سفارشی پروژه متبوع:</label>
                        <div class="flex gap-2">
                            <input id="doc-logo-input" type="text" placeholder="../assets/brand_logo.png" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-sans text-xs ltr-input">
                            <button onclick="document.getElementById('uploader-file-selector').click()" class="px-3 bg-slate-100 hover:bg-slate-200 text-slate-705 border border-slate-300 rounded-xl font-bold cursor-pointer transition-all">آپلود</button>
                            <input type="file" id="uploader-file-selector" onchange="uploadLogoFileViaAjax(this)" class="hidden">
                        </div>
                    </div>
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">مدیریت درون‌ریزی و برون‌بری JSON ساختاریافته:</label>
                        <div class="flex gap-2">
                            <button onclick="exportDocJson()" class="w-1/2 py-2.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 font-extrabold rounded-xl cursor-pointer">📥 کپی و خروجی زنده JSON</button>
                            <button onclick="importDocJson()" class="w-1/2 py-2.5 bg-slate-90 bg-slate-10 hover:bg-slate-50 text-slate-700 border border-slate-200 font-extrabold rounded-xl cursor-pointer">📤 ایمپورت دستی JSON</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PROPOSAL DYNAMIC BUILDER FORM (VISIBLE IF ACTIVE DOC IS PROPOSAL) -->
            <div id="proposal-builder-box" class="p-6 bg-white border border-slate-200/50 rounded-3xl shadow-sm space-y-6">
                <h3 class="font-black text-xs text-slate-800 pb-2 border-b flex items-center gap-1.5 text-indigo-700">
                    <span>✍️</span> جزئیات محتوا و متن‌های شیک پروپوزال
                </h3>
                
                <div class="space-y-4 text-xs font-semibold">
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">مقدمه پروپوزال (شراکت و همکاری):</label>
                        <textarea id="prop-intro" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-medium"></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-slate-500 block mb-1 font-black">درباره اتوماسیون و ابزار پایش مارک‌شو:</label>
                            <textarea id="prop-about" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-medium"></textarea>
                        </div>
                        <div>
                            <label class="text-slate-500 block mb-1 font-black">استراتژی کلیدواژه‌ها و پایش سئو:</label>
                            <textarea id="prop-seo" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-medium"></textarea>
                        </div>
                    </div>

                    <!-- Slides Dynamic Cards Area -->
                    <div class="space-y-3">
                        <div class="flex justify-between items-center pb-2 border-b">
                            <span class="text-xs font-black text-slate-700">📋 فازها و بخش‌های اجرایی پروپوزال</span>
                            <button onclick="addProposalSlide()" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-black cursor-pointer">➕ افزودن فاز جدید</button>
                        </div>
                        <div id="prop-slides-container" class="space-y-3">
                            <!-- Injected Javascipt -->
                        </div>
                    </div>

                    <!-- Packages Price Grid Area -->
                    <div class="space-y-3 pt-2">
                        <div class="flex justify-between items-center pb-2 border-b">
                            <span class="text-xs font-black text-slate-700">💰 پکیج‌ها و پلن‌های سرمایه‌گذاری طرح</span>
                            <button onclick="addProposalPackage()" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-black cursor-pointer">➕ افزودن پلن قیمت</button>
                        </div>
                        <div id="prop-packages-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Injected Javascript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- PLAN DYNAMIC BUILDER FORM (VISIBLE IF ACTIVE DOC IS PLAN) -->
            <div id="plan-builder-box" class="hidden p-6 bg-white border border-slate-200/50 rounded-3xl shadow-sm space-y-6">
                <h3 class="font-black text-xs text-slate-800 pb-2 border-b flex items-center gap-1.5 text-emerald-800">
                    <span>🧮</span> تعریف متغیرها، اهداف عددی و فرمول‌نویسی ریاضی
                </h3>
                
                <div class="space-y-4 text-xs font-semibold">
                    <div>
                        <label class="text-slate-500 block mb-1 font-black">تحلیل مختصر و ارزیابی وضعیت فعلی ترافیک کلاینت:</label>
                        <textarea id="plan-curr-status" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-medium"></textarea>
                    </div>

                    <!-- Variables dynamic table list -->
                    <div class="space-y-3">
                        <div class="flex justify-between items-center pb-2 border-b">
                            <div>
                                <span class="text-xs font-black text-slate-700">🧮 لیست کل متغیرها و سنجه‌های عددی</span>
                                <p class="text-[9px] text-slate-400 mt-0.5 leading-normal">می‌توانید برای فیلد فرمول، از شناسه‌های متغیرهای عددی دیگر داخل کروشه مثل <code class="bg-indigo-55 px-1 rounded text-indigo-700 font-mono font-bold">[monthly_sales] / 30</code> استفاده کنید.</p>
                            </div>
                            <button onclick="addPlanVariable()" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-[10px] font-black cursor-pointer">➕ افزودن سنجه جدید</button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-right outline-none text-xs border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-black">
                                        <th class="p-2">شناسه فیلد (ID)</th>
                                        <th class="p-2">برچسب سنجه (فارسی)</th>
                                        <th class="p-2">نوع فیلد</th>
                                        <th class="p-2">مقدار عددی / فرمول</th>
                                        <th class="p-2">عرضه خروجی</th>
                                        <th class="p-2 text-center">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="plan-vars-table-body">
                                    <!-- Injected JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Grouped Grid layout customization -->
                    <div class="space-y-3 pt-2">
                        <div class="flex justify-between items-center pb-2 border-b">
                            <div>
                                <span class="text-xs font-black text-slate-700">🧱 تفکیک و چیدمان بصری سنجه‌ها (Grid Panels Layout)</span>
                                <p class="text-[9px] text-slate-400 mt-0.5">سنجه‌ها را در گروه‌های نمایشی جذاب تقسیم و چیدمان کنید.</p>
                            </div>
                            <button onclick="addPlanLayoutGroup()" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-black cursor-pointer">➕ افزودن پنل گرید جدید</button>
                        </div>
                        <div id="plan-layout-groups-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Injected JS -->
                        </div>
                    </div>

                    <!-- SEO & Social targets text content -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                        <div>
                            <label class="text-slate-500 block mb-1 font-black">🚀 اهداف و تارگت‌های تفصیلی حوزه سئو (SEO):</label>
                            <textarea id="plan-seo-targets" rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-sans placeholder:text-slate-350" placeholder="هر تارگت را در یک خط بنویسید..."></textarea>
                        </div>
                        <div>
                            <label class="text-slate-500 block mb-1 font-black">📢 اهداف و تارگت‌های تفصیلی شبکه‌های اجتماعی:</label>
                            <textarea id="plan-social-targets" rows="4" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 leading-relaxed font-sans placeholder:text-slate-350" placeholder="هر تارگت را در یک خط بنویسید..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- INTERACTIVE REAL-TIME PREVIEW BOARD -->
            <div class="p-6 bg-slate-100 border border-slate-200 rounded-3xl space-y-4">
                <div class="flex justify-between items-center pb-2 border-b">
                    <span class="text-xs font-black text-slate-600 flex items-center gap-1">
                        <span class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></span>
                        پیش‌نمایش سند خروجی (Live Preview Map)
                    </span>
                    <button onclick="openActiveLivePreviewTab()" class="px-3 py-1.5 bg-white hover:bg-slate-50 border text-[10px] font-black rounded-lg text-slate-705 shadow-sm transition-all cursor-pointer">👓 مشاهده در تب مجزای پابلیک</button>
                </div>
                
                <!-- Inside Sandbox Frame Simulator -->
                <div class="p-6 bg-white border border-slate-150 rounded-2xl shadow-sm space-y-4" id="live-render-box">
                    <!-- Dynamic live javascript renderer takes over of this segment -->
                    <div class="text-center py-10 text-slate-400 font-bold">برای رونمایی از پیش‌نمایش، فولدری ایجاد یا سندی انتخاب نمایید.</div>
                </div>
            </div>
        </div>
    </main>

    <!-- FLOATING LIGHTBOX / MODAL TO IMPORT EXPORT RAW DATA & CO-PILOT JSON -->
    <div id="json-sandbox-modal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl border border-slate-200 p-8 max-w-2xl w-full space-y-4 shadow-2xl relative">
            <button onclick="closeJsonModal()" class="absolute left-4 top-4 text-slate-400 hover:text-rose-600 text-lg font-black transition-all">✕</button>
            <h3 class="font-black text-sm text-slate-800 flex items-center gap-2">🔄 مدیریت داده‌های ساختاریافته (JSON Sandbox)</h3>
            <p class="text-[10px] text-slate-400 leading-relaxed font-bold">
                شما می‌توانید کل دیتای سند خود را با چسباندن مجدد یا با کپی کردن ساختار متنی JSON به راحتی به دیگر فروشگاه‌ها یا حساب‌ها منتقل تمایید.
            </p>
            <textarea id="json-sandbox-textarea" rows="10" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl font-mono text-xs ltr-input leading-relaxed outline-none focus:border-indigo-500" placeholder="Paste or Copy raw JSON database mapping details here..."></textarea>
            
            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeJsonModal()" class="px-5 py-2.5 bg-slate-150 text-slate-700 hover:bg-slate-200 font-extrabold text-xs rounded-xl cursor-pointer">انصراف</button>
                <button id="json-action-confirm-btn" class="px-5 py-2.5 bg-indigo-650 hover:bg-indigo-750 text-white font-extrabold text-xs rounded-xl cursor-pointer">اعمال تغییرات</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- APP CODE LOGIC SCRIPT -->
<script>
// Master Active state
var activeDoc = {
    id: "",
    project_id: "<?php echo $project['id']; ?>",
    type: "proposal",
    title: "",
    logo_url: "",
    status: "draft",
    content: {}
};

// Default schemas
var DEFAULT_PROPOSAL_JSON = {
    client_name: "",
    introduction: "با سلام و احترام، این سند با تکیه بر ابزار متمایز مارک‌شو، ربات‌های استخراج خودکار و پایش ارگانیک سئو نوشته شده است.",
    about_us: "ما یک سیستم تمام‌اتوماتیک و هوشمند پایش کالا در وب‌سایت‌ها و ارسال زمان‌بندی شده به بله و روبیکا داریم.",
    strategy_seo: "پایش کلمات کلیدی، تعیین کوئری‌های ترافیکی و بررسی ساختار سرفصل‌های لندینگ‌پیج در جهت جذب لید مستقیم.",
    strategy_social: "سناریونویسی خلاق با متدهای نوین مارکتینگ، زمان‌بندی دقیق تقویم انتشار و استفاده از بله، روبیکا و تلگرام.",
    slides: [
        {title: "فاز ۱: بازبینی فنی کوئری‌ها", content: "پایش وضعیت رقبا به صورت لایو و کشف کلمات سئو سودآور."},
        {title: "فاز ۲: طراحی کاتالوگ آنلاین", content: "ساخت کاتالوگ‌های اختصاصی جهت سهولت اشتراک‌گذاری محصولات به صورت چندرسانه‌ای."}
    ],
    packages: [
        {name: "پکیج نقره‌ای ارگانیک", price: "۵,۰۰۰,۰۰۰ تومان / ماهانه", features: ["پایش رتبه کلیدواژه‌ها", "کاتالوگ هوشمند زنده"]},
        {name: "پکیج کامل VIP مارک‌شو", price: "۱۲,۰۰۰,۰۰۰ تومان / ماهانه", features: ["ردیاب کوئری روزانه نامحدود", "ربات زمان‌بندی Rubika و Bale", "چندرسانه اختصاصی"]}
    ]
};

var DEFAULT_PLAN_JSON = {
    client_name: "",
    current_status: "محله استقرار رتبه‌ها پایدار است اما در کوئری‌های آلی نیاز مبرم به ساخت لندینگ برای ورودی مستقیم و ایجاد محتوای حائز ریلز احساس می‌شود.",
    variables: [
        {id: "m_sales", label: "فروش ماهانه فعلی (تومان)", type: "number", value: 100000000},
        {id: "m_target_sales", label: "تارگت فروش ماهانه هدف (تومان)", type: "number", value: 180000000},
        {id: "d_sales_avg", label: "میانگین فروش روزانه فعلی", type: "formula", formula: "[m_sales] / 30", value: 3333333},
        {id: "growth_estimated", label: "درصد افزایش فروش موردانتظار", type: "formula", formula: "(([m_target_sales] - [m_sales]) / [m_sales]) * 100", value: 80}
    ],
    layout: [
        {section_title: "پارامترهای پایه ترافیک و فروش", fields: ["m_sales", "m_target_sales"]},
        {section_title: "شاخص‌های محاسباتی و برآوردها", fields: ["d_sales_avg", "growth_estimated"]}
    ],
    seo_targets_text: "۱. ارتقای ۱۵ کوئری ترافیکی ویژه کفش به رنکینگ های تک رقمی گوگل\n۲. بهبود ساختار لینک‌های داخلی کاتالوگ.",
    social_targets_text: "۱. انتشار هفتگی ۵ پست زمان‌بندی شده در پلتفرم بله و روبیکا با سناریوی درگیرکننده."
};

// Initialize app workspace if not public
window.addEventListener('DOMContentLoaded', function() {
    <?php if (!$isPublicView): ?>
        // If there are existing documents, load the first one, else create a draft proposal
        <?php if (!empty($allDocs)): ?>
            loadDoc(<?php echo json_encode($allDocs[0]); ?>);
        <?php else: ?>
            createNewDoc('proposal');
        <?php endif; ?>
    <?php else: ?>
        // If it is public view, trigger initial reactive formula calculation
        calculatePlanFormulas();
    <?php endif; ?>
});

// Create fresh doc state
function createNewDoc(type) {
    activeDoc.id = "";
    activeDoc.type = type;
    activeDoc.title = type === 'plan' ? "برنامه عددی سئو و فروش " : "پروپوزال کمپین اتوماسیون جریان همکاری " + "<?php echo htmlspecialchars($project['name']); ?>";
    activeDoc.logo_url = "<?php echo !empty($project['settings']['logo_url']) ? htmlspecialchars($project['settings']['logo_url']) : '../assets/brand_logo.png'; ?>";
    activeDoc.status = "draft";
    activeDoc.content = type === 'plan' ? JSON.parse(JSON.stringify(DEFAULT_PLAN_JSON)) : JSON.parse(JSON.stringify(DEFAULT_PROPOSAL_JSON));
    activeDoc.content.client_name = "کارفرمای محترم جریان";
    
    syncStateToInputs();
    renderLivePreview();
}

// Load existing doc details
function loadDoc(row) {
    activeDoc.id = row.id;
    activeDoc.project_id = row.project_id;
    activeDoc.type = row.type;
    activeDoc.title = row.title;
    activeDoc.logo_url = row.logo_url;
    activeDoc.status = row.status;
    
    // safe decode content
    try {
        activeDoc.content = typeof row.content === 'string' ? JSON.parse(row.content) : row.content;
    } catch(e) {
        activeDoc.content = activeDoc.type === 'plan' ? JSON.parse(JSON.stringify(DEFAULT_PLAN_JSON)) : JSON.parse(JSON.stringify(DEFAULT_PROPOSAL_JSON));
    }
    
    syncStateToInputs();
    renderLivePreview();
}

// Write states down to the dashboard input boxes
function syncStateToInputs() {
    document.getElementById('doc-title-input').value = activeDoc.title;
    document.getElementById('doc-client-input').value = activeDoc.content.client_name || "";
    document.getElementById('doc-logo-input').value = activeDoc.logo_url || "";
    document.getElementById('doc-save-status').value = activeDoc.status;
    
    var propBox = document.getElementById('proposal-builder-box');
    var planBox = document.getElementById('plan-builder-box');
    
    if (activeDoc.type === 'plan') {
        propBox.classList.add('hidden');
        planBox.classList.remove('hidden');
        
        document.getElementById('plan-curr-status').value = activeDoc.content.current_status || "";
        document.getElementById('plan-seo-targets').value = activeDoc.content.seo_targets_text || "";
        document.getElementById('plan-social-targets').value = activeDoc.content.social_targets_text || "";
        
        renderPlanVariablesControlTable();
        renderPlanLayoutGroupsControl();
    } else {
        planBox.classList.add('hidden');
        propBox.classList.remove('hidden');
        
        document.getElementById('prop-intro').value = activeDoc.content.introduction || "";
        document.getElementById('prop-about').value = activeDoc.content.about_us || "";
        document.getElementById('prop-seo').value = activeDoc.content.strategy_seo || "";
        
        renderProposalSlidesControl();
        renderProposalPackagesControl();
    }
}

// Collect raw values from inputs back into active state before save
function collectInputsToState() {
    activeDoc.title = document.getElementById('doc-title-input').value;
    activeDoc.logo_url = document.getElementById('doc-logo-input').value;
    activeDoc.status = document.getElementById('doc-save-status').value;
    
    activeDoc.content.client_name = document.getElementById('doc-client-input').value;
    
    if (activeDoc.type === 'plan') {
        activeDoc.content.current_status = document.getElementById('plan-curr-status').value;
        activeDoc.content.seo_targets_text = document.getElementById('plan-seo-targets').value;
        activeDoc.content.social_targets_text = document.getElementById('plan-social-targets').value;
        
        // variables parsed dynamically on input actions
        // layout groups parsed dynamically on input actions
    } else {
        activeDoc.content.introduction = document.getElementById('prop-intro').value;
        activeDoc.content.about_us = document.getElementById('prop-about').value;
        activeDoc.content.strategy_seo = document.getElementById('prop-seo').value;
    }
}

// Upload logo ajax integration
function uploadLogoFileViaAjax(input) {
    if (input.files && input.files[0]) {
        var formData = new FormData();
        formData.append('ajax_action', 'upload_logo');
        formData.append('logo_file', input.files[0]);
        
        var uploaderBtn = input.previousElementSibling;
        var btnOrig = uploaderBtn.innerText;
        uploaderBtn.innerText = "پردازش...";
        uploaderBtn.disabled = true;
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            uploaderBtn.innerText = btnOrig;
            uploaderBtn.disabled = false;
            if (res.success) {
                document.getElementById('doc-logo-input').value = res.url;
                activeDoc.logo_url = res.url;
                renderLivePreview();
                alert('فایل لوگو با موفقیت نهایی و جایگزین گردید.');
            } else {
                alert('خطا در بارگذاری لوگو: ' + res.error);
            }
        })
        .catch(err => {
            uploaderBtn.innerText = btnOrig;
            uploaderBtn.disabled = false;
            alert('خطای اتصال سرور در بارگذاری عکس.');
        });
    }
}

// ---------------- PROPOSAL BUILDER CONTROLS ----------------
function renderProposalSlidesControl() {
    var container = document.getElementById('prop-slides-container');
    container.innerHTML = "";
    
    var slides = activeDoc.content.slides || [];
    slides.forEach(function(s, idx) {
        var html = `
        <div class="p-4 bg-slate-50 border rounded-2xl relative space-y-2">
            <button onclick="removeProposalSlide(${idx})" class="absolute left-3 top-3 text-[10px] font-black text-rose-500 hover:scale-110 transition-transform">✕ حذف</button>
            <div class="grid grid-cols-1 gap-2">
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">عنوان فاز / سناریو:</label>
                    <input type="text" value="${escapeHtml(s.title)}" oninput="updateSlideField(${idx}, 'title', this.value)" class="w-full p-2 bg-white border border-slate-200 rounded-xl outline-none font-bold text-xs">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-450 block text-right">محتوای اجرایی فاز:</label>
                    <textarea oninput="updateSlideField(${idx}, 'content', this.value)" rows="2" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl outline-none text-xs font-semibold leading-relaxed">${escapeHtml(s.content)}</textarea>
                </div>
            </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
}

function addProposalSlide() {
    if (!activeDoc.content.slides) activeDoc.content.slides = [];
    activeDoc.content.slides.push({
        title: "فاز جدید: شرح اقدام عملیاتی",
        content: "در این سرفصل، تیم نسبت به پیاده‌سازی گام‌های هدف به صورت زنده اقدام می‌نماید..."
    });
    renderProposalSlidesControl();
    renderLivePreview();
}

function removeProposalSlide(idx) {
    if (confirm('آیا مایلید این فاز را حذف نمایید؟')) {
        activeDoc.content.slides.splice(idx, 1);
        renderProposalSlidesControl();
        renderLivePreview();
    }
}

function updateSlideField(idx, field, val) {
    activeDoc.content.slides[idx][field] = val;
    renderLivePreview();
}

function renderProposalPackagesControl() {
    var container = document.getElementById('prop-packages-container');
    container.innerHTML = "";
    
    var pkgs = activeDoc.content.packages || [];
    pkgs.forEach(function(p, idx) {
        var featuresText = (p.features || []).join('\n');
        var html = `
        <div class="p-4 bg-slate-50 border rounded-2xl relative space-y-2 print-full-width">
            <button onclick="removeProposalPackage(${idx})" class="absolute left-3 top-3 text-[10px] font-black text-rose-500 hover:scale-110 transition-transform">✕ حذف</button>
            <h4 class="text-[10px] uppercase font-black text-slate-400">پلن شماره ${idx+1}</h4>
            <div class="space-y-2">
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">نام تعرفه:</label>
                    <input type="text" value="${escapeHtml(p.name)}" oninput="updatePackageField(${idx}, 'name', this.value)" class="w-full p-2 bg-white border border-slate-200 rounded-xl font-bold text-xs">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">برآورد سرمایه (قیمت):</label>
                    <input type="text" value="${escapeHtml(p.price)}" oninput="updatePackageField(${idx}, 'price', this.value)" class="w-full p-2 bg-white border border-slate-200 rounded-xl font-bold text-xs text-indigo-700">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">امکانات پکیج (هر کدام در یک خط):</label>
                    <textarea oninput="updatePackageFeatures(${idx}, this.value)" rows="3" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl outline-none text-xs font-semibold leading-normal font-sans">${escapeHtml(featuresText)}</textarea>
                </div>
            </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
}

function addProposalPackage() {
    if (!activeDoc.content.packages) activeDoc.content.packages = [];
    activeDoc.content.packages.push({
        name: "تعرفه برنارد توسعه نوین",
        price: "۱۰,۰۰۰,۰۰۰ تومان / ماهانه",
        features: ["۲ کانال مجزا", "پشتیبانی تلفنی", "ردیابی کلمات"]
    });
    renderProposalPackagesControl();
    renderLivePreview();
}

function removeProposalPackage(idx) {
    if (confirm('آیا مایلید این پکیج را حذف نمایید؟')) {
        activeDoc.content.packages.splice(idx, 1);
        renderProposalPackagesControl();
        renderLivePreview();
    }
}

function updatePackageField(idx, field, val) {
    activeDoc.content.packages[idx][field] = val;
    renderLivePreview();
}

function updatePackageFeatures(idx, val) {
    activeDoc.content.packages[idx].features = val.split('\n').map(x => x.trim()).filter(Boolean);
    renderLivePreview();
}


// ---------------- PLAN NUMERIC BUILDER CONTROLS ----------------
function renderPlanVariablesControlTable() {
    var tbody = document.getElementById('plan-vars-table-body');
    tbody.innerHTML = "";
    
    var vars = activeDoc.content.variables || [];
    vars.forEach(function(v, idx) {
        var isFormula = v.type === 'formula';
        var valOrFormula = isFormula ? (v.formula || "") : (v.value || 0);
        
        var html = `
        <tr class="border-b border-slate-100 font-semibold text-xs text-slate-700 hover:bg-slate-50/50">
            <td class="p-2 font-mono text-[10px] text-slate-500">${escapeHtml(v.id)}</td>
            <td class="p-2">
                <input type="text" value="${escapeHtml(v.label)}" oninput="updateVarField(${idx}, 'label', this.value)" class="p-1.5 bg-white border border-slate-200 rounded-lg outline-none font-bold text-xs w-full">
            </td>
            <td class="p-2">
                <select onchange="updateVarType(${idx}, this.value)" class="p-1.5 bg-white border border-slate-200 rounded-lg outline-none font-bold text-xs">
                    <option value="number" ${!isFormula?'selected':''}>🔢 عدد ثابت</option>
                    <option value="formula" ${isFormula?'selected':''}>🔄 فرمول ریاضی</option>
                </select>
            </td>
            <td class="p-2">
                <input type="text" value="${escapeHtml(valOrFormula)}" oninput="updateVarFormulaVal(${idx}, this.value)" class="p-1.5 bg-white border border-slate-200 rounded-lg outline-none text-xs w-full font-bold ${isFormula?'ltr-input font-mono text-indigo-705':''}">
            </td>
            <td class="p-2 font-sans font-black text-indigo-600 var-calculated-val-preview" data-preview-vid="${v.id}">
                ${v.value}
            </td>
            <td class="p-2 text-center">
                <button onclick="removePlanVariable(${idx})" class="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg text-xs font-black cursor-pointer">حذف</button>
            </td>
        </tr>`;
        tbody.insertAdjacentHTML('beforeend', html);
    });
}

function addPlanVariable() {
    if (!activeDoc.content.variables) activeDoc.content.variables = [];
    var newId = "var_" + Math.random().toString(36).substring(2, 7);
    activeDoc.content.variables.push({
        id: newId,
        label: "برچسب سنجه هدف",
        type: "number",
        value: 5000000
    });
    renderPlanVariablesControlTable();
    updatePlanVariablesCacheMap();
    renderLivePreview();
}

function removePlanVariable(idx) {
    if (confirm('آیا مایلید این سنجه عددی حذف شود؟')) {
        activeDoc.content.variables.splice(idx, 1);
        renderPlanVariablesControlTable();
        updatePlanVariablesCacheMap();
        renderLivePreview();
    }
}

function updateVarField(idx, field, val) {
    activeDoc.content.variables[idx][field] = val;
    renderLivePreview();
}

function updateVarType(idx, val) {
    activeDoc.content.variables[idx].type = val;
    if (val === 'formula') {
        activeDoc.content.variables[idx].formula = activeDoc.content.variables[idx].formula || "[m_sales] / 30";
    } else {
        activeDoc.content.variables[idx].value = Number(activeDoc.content.variables[idx].value) || 0;
    }
    renderPlanVariablesControlTable();
    updatePlanVariablesCacheMap();
    renderLivePreview();
}

function updateVarFormulaVal(idx, val) {
    if (activeDoc.content.variables[idx].type === 'formula') {
        activeDoc.content.variables[idx].formula = val;
    } else {
        // cast cleanly to float/num
        var cleanNum = parseFloat(val.replace(/[^0-9.-]/g, '')) || 0;
        activeDoc.content.variables[idx].value = cleanNum;
    }
    updatePlanVariablesCacheMap();
    renderLivePreview();
}

function renderPlanLayoutGroupsControl() {
    var container = document.getElementById('plan-layout-groups-container');
    container.innerHTML = "";
    
    var lay = activeDoc.content.layout || [];
    var vars = activeDoc.content.variables || [];
    
    lay.forEach(function(g, idx) {
        var fieldsValue = (g.fields || []).join(', ');
        var html = `
        <div class="p-4 bg-slate-50 border border-slate-200/60 rounded-2xl relative space-y-2">
            <button onclick="removePlanLayoutGroup(${idx})" class="absolute left-3 top-3 text-[10px] font-black text-rose-500 hover:scale-110 transition-transform">✕ حذف</button>
            <h4 class="text-[10px] font-black text-slate-400">گروه پنل گرید ${idx+1}</h4>
            <div class="space-y-2">
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">عنوان این پنل گرید:</label>
                    <input type="text" value="${escapeHtml(g.section_title)}" oninput="updateLayoutGroupField(${idx}, 'section_title', this.value)" class="w-full p-2 bg-white border border-slate-200 rounded-xl font-bold text-xs">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-450 block">شناسه‌های متغیرهای درون پنل (با کاما تفکیک کنید):</label>
                    <input type="text" value="${escapeHtml(fieldsValue)}" oninput="updateLayoutGroupFields(${idx}, this.value)" class="w-full p-2 bg-white border border-slate-200 rounded-xl font-mono text-xs ltr-input text-indigo-700">
                    <span class="text-[9px] text-indigo-500 font-extrabold mt-0.5 block">شناسه‌های مجاز این پروژه: ${vars.map(x => x.id).join(', ')}</span>
                </div>
            </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
}

function addPlanLayoutGroup() {
    if (!activeDoc.content.layout) activeDoc.content.layout = [];
    activeDoc.content.layout.push({
        section_title: "پنل گرید کارایی جدید",
        fields: []
    });
    renderPlanLayoutGroupsControl();
    renderLivePreview();
}

function removePlanLayoutGroup(idx) {
    if (confirm('آیا مایلید این گروه پنل حذف گردد؟')) {
        activeDoc.content.layout.splice(idx, 1);
        renderPlanLayoutGroupsControl();
        renderLivePreview();
    }
}

function updateLayoutGroupField(idx, field, val) {
    activeDoc.content.layout[idx][field] = val;
    renderLivePreview();
}

function updateLayoutGroupFields(idx, val) {
    activeDoc.content.layout[idx].fields = val.split(',').map(x => x.trim()).filter(Boolean);
    renderLivePreview();
}

// ---------------- REACTIVE MATHEMATICAL FORMULA RESOLVER ----------------
var variablesCacheMap = {};

function updatePlanVariablesCacheMap() {
    if (activeDoc.type !== 'plan') return;
    variablesCacheMap = {};
    var vars = activeDoc.content.variables || [];
    vars.forEach(function(v) {
        variablesCacheMap[v.id] = v;
    });
}

// Evaluate formula with dependency graph dynamically in pure CSS-safe JS
function calculatePlanFormulas() {
    updatePlanVariablesCacheMap();
    var vars = activeDoc.content.variables || [];
    
    // Perform maximum 3 passes of evaluation to avoid cyclic or order graph dependencies
    for (var pass = 0; pass < 3; pass++) {
        vars.forEach(function(v) {
            if (v.type === 'formula' && v.formula) {
                var formulaStr = v.formula;
                
                // Replace all [vars] with real evaluated numeric values
                var pattern = /\[([a-zA-Z0-9_.-]+)\]/g;
                var safeToEval = formulaStr.replace(pattern, function(match, id) {
                    var dep = variablesCacheMap[id];
                    if (dep) {
                        return Number(dep.value) || 0;
                    }
                    return 0;
                });
                
                // Sanitize and math eval
                try {
                    // restrict injection to primitive math tokens
                    safeToEval = safeToEval.replace(/[^0-9.+\-*/() ]/g, '');
                    var computed = Function("return (" + safeToEval + ")")();
                    if (isFinite(computed) && !isNaN(computed)) {
                        v.value = parseFloat(computed.toFixed(2));
                    } else {
                        v.value = 0;
                    }
                } catch(e) {
                    v.value = 0;
                }
            }
        });
    }
    
    // Update live previews in editing tabular listing elements
    var previewElements = document.querySelectorAll('.var-calculated-val-preview');
    previewElements.forEach(function(el) {
        var vid = el.getAttribute('data-preview-vid');
        var val = variablesCacheMap[vid] ? variablesCacheMap[vid].value : 0;
        el.innerText = formatNumber(val);
    });
}


// ---------------- LIVE SIMULATOR PREVIEW RENDERER ----------------
function renderLivePreview() {
    calculatePlanFormulas(); // ensure clean computations
    
    var container = document.getElementById('live-render-box');
    if (!container) return;
    
    collectInputsToState(); // collect texts
    
    var logo = activeDoc.logo_url;
    var client = activeDoc.content.client_name || "کارفرمای محترم";
    var title = activeDoc.title || "عنوان سند جدید";
    
    var html = "";
    
    // Document layout frame
    html += `
    <div class="p-6 md:p-10 border border-slate-100 rounded-3xl bg-white space-y-6 print-full-width">
        <!-- Header -->
        <div class="flex flex-col md:flex-row items-center justify-between gap-4 border-b border-slate-100 pb-6">
            <div class="text-right space-y-1">
                <h2 class="text-lg font-black text-slate-800 leading-snug">${escapeHtml(title)}</h2>
                <div class="text-[10px] text-slate-400 font-extrabold flex items-center gap-1">
                    <span>مخاطب طرح:</span>
                    <strong class="text-indigo-600 font-black">${escapeHtml(client)}</strong>
                    <span class="mx-1">•</span>
                    <span>تاریخ: هم‌اکنون</span>
                </div>
            </div>
            ${logo ? `<img src="${escapeHtml(logo)}" alt="Logo" class="max-h-12 object-contain bg-slate-50 border p-1 rounded-lg">` : `<div class="p-2 bg-indigo-50 text-indigo-600 text-xl font-black rounded-lg">M</div>`}
        </div>`;
        
    if (activeDoc.type === 'plan') {
        // PLAN TEMPLATE
        var currStatus = activeDoc.content.current_status || "";
        var seoTargets = activeDoc.content.seo_targets_text || "";
        var socialTargets = activeDoc.content.social_targets_text || "";
        
        html += `
        <div class="space-y-6">
            <!-- Summary card -->
            <div class="space-y-1.5">
                <h4 class="text-xs font-black text-slate-700 flex items-center gap-1">🔍 تحلیل و ارزیابی ترافیکی فروشگاه:</h4>
                <div class="bg-indigo-50/20 text-slate-650 p-4 rounded-xl leading-relaxed text-[11px] font-semibold border border-indigo-50/50">
                    ${escapeHtml(currStatus).replace(/\n/g, '<br>')}
                </div>
            </div>
            
            <!-- Bento panel items -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                ${(activeDoc.content.layout || []).map(function(grp, grpIdx) {
                    return `
                    <div class="p-4 bg-slate-50/80 border border-slate-100 rounded-2xl space-y-3 layout-col">
                        <h4 class="text-xs font-black text-slate-700 border-b pb-1.5 flex items-center gap-1">
                            <span class="w-1.5 h-2.5 bg-indigo-500 rounded-sm"></span>
                            ${escapeHtml(grp.section_title)}
                        </h4>
                        <div class="space-y-2">
                            ${(grp.fields || []).map(function(fId) {
                                var dynamicVar = variablesCacheMap[fId];
                                if (!dynamicVar) return "";
                                var isFormulaMarker = dynamicVar.type === 'formula' ? '🔄' : '🔢';
                                return `
                                <div class="flex items-center justify-between p-2.5 bg-white border border-slate-100 rounded-xl shadow-sm">
                                    <span class="text-[11px] text-slate-600 font-bold">${escapeHtml(dynamicVar.label)}:</span>
                                    <div class="font-sans text-[11px] font-black text-slate-800">
                                        <span class="text-indigo-600 font-extrabold text-xs">${formatNumber(dynamicVar.value)}</span>
                                        <span class="text-[10px] text-slate-400 font-medium whitespace-nowrap">${isFormulaMarker}</span>
                                    </div>
                                </div>`;
                            }).join('')}
                        </div>
                    </div>`;
                }).join('')}
            </div>
            
            <!-- Targets split column -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 page-break-before">
                <div class="p-4 bg-emerald-50/20 border border-emerald-100/40 rounded-2xl space-y-2 whitespace-pre-line text-[11px] leading-relaxed font-semibold">
                    <h5 class="font-black text-emerald-800 border-b border-emerald-100/50 pb-1 flex items-center gap-1">🎯 تارگت‌ها و برنامه‌های عملیاتی سئو</h5>
                    <div class="text-slate-700 pt-1 font-medium">${escapeHtml(seoTargets).replace(/\n/g, '<br>')}</div>
                </div>
                <div class="p-4 bg-pink-50/20 border border-pink-100/40 rounded-2xl space-y-2 whitespace-pre-line text-[11px] leading-relaxed font-semibold">
                    <h5 class="font-black text-pink-800 border-b border-pink-100/50 pb-1 flex items-center gap-1">📢 تارگت‌های فرآیندی شبکه‌های اجتماعی</h5>
                    <div class="text-slate-700 pt-1 font-medium">${escapeHtml(socialTargets).replace(/\n/g, '<br>')}</div>
                </div>
            </div>
        </div>`;
    } else {
        // PROPOSAL TEMPLATE
        var pIntro = activeDoc.content.introduction || "";
        var pAbout = activeDoc.content.about_us || "";
        var pSeo = activeDoc.content.strategy_seo || "";
        var pSocial = activeDoc.content.strategy_social || "";
        
        html += `
        <div class="space-y-6">
            <!-- Introduction slide -->
            <div class="space-y-1.5">
                <h4 class="text-xs font-black text-slate-700 flex items-center gap-1">👋 مقدمه و پیشنهاد آغازین:</h4>
                <div class="bg-indigo-50/20 text-slate-650 p-4 rounded-xl leading-relaxed text-[11px] font-semibold border border-indigo-55">
                    ${escapeHtml(pIntro).replace(/\n/g, '<br>')}
                </div>
            </div>
            
            <!-- Brief split boxes -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl space-y-1">
                    <h5 class="text-xs font-black text-slate-700">🚀 تجهیزات استخراج و کاتالوگ آنلاین</h5>
                    <p class="text-[11px] text-slate-550 leading-relaxed font-medium">${escapeHtml(pAbout)}</p>
                </div>
                <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl space-y-1">
                    <h5 class="text-xs font-black text-slate-700">📊 استراتژی پایش سئو و کیوردها</h5>
                    <p class="text-[11px] text-slate-550 leading-relaxed font-medium">${escapeHtml(pSeo)}</p>
                </div>
            </div>
            
            <!-- Slides loop -->
            <div class="space-y-3">
                <h4 class="text-[11px] font-black text-slate-500 border-b pb-1.5">📋 فازبندی‌های گام به گام توسعه همکاری</h4>
                <div class="space-y-2">
                    ${(activeDoc.content.slides || []).map(function(s) {
                        return `
                        <div class="p-4 bg-white border border-slate-100 rounded-xl shadow-sm space-y-1">
                            <h5 class="text-[11px] font-black text-indigo-700 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 bg-indigo-600 rounded-full"></span>
                                ${escapeHtml(s.title)}
                            </h5>
                            <p class="text-[11px] text-slate-500 leading-normal font-medium">${escapeHtml(s.content)}</p>
                        </div>`;
                    }).join('')}
                </div>
            </div>
            
            <!-- Packages loop -->
            <div class="space-y-3 page-break-before">
                <h4 class="text-[11px] font-black text-slate-500 border-b pb-1.5">💰 هزینه‌گذاری و تعرفه‌های پیشنهادی</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    ${(activeDoc.content.packages || []).map(function(p) {
                        return `
                        <div class="p-4 bg-gradient-to-b from-indigo-50/10 to-white hover:from-white border border-indigo-100 rounded-2xl space-y-2.5 package-card">
                            <div class="text-center space-y-1">
                                <h5 class="font-black text-slate-800 text-xs">${escapeHtml(p.name)}</h5>
                                <div class="text-emerald-700 font-extrabold text-[11px]">${escapeHtml(p.price)}</div>
                            </div>
                            <hr class="border-indigo-100/10">
                            <ul class="text-[10px] space-y-1.5 font-semibold text-slate-600">
                                ${(p.features || []).map(f => `
                                    <li class="flex items-center gap-1.5 truncate">
                                        <span class="text-indigo-500">✓</span>
                                        <span>${escapeHtml(f)}</span>
                                    </li>`).join('')}
                            </ul>
                        </div>`;
                    }).join('')}
                </div>
            </div>
        </div>`;
    }
    
    html += `
        <!-- Footer info -->
        <div class="pt-6 border-t border-slate-100 flex items-center justify-between text-slate-400 text-[9px] font-black">
            <span>سامانه طراحی سند زنده مارک‌شو</span>
            <span>بومی جریانی</span>
        </div>
    </div>`;
    
    container.innerHTML = html;
}

// ---------------- EXPORT / IMPORT JSON ----------------
function exportDocJson() {
    collectInputsToState();
    var exported = {
        title: activeDoc.title,
        logo_url: activeDoc.logo_url,
        type: activeDoc.type,
        status: activeDoc.status,
        content: activeDoc.content
    };
    
    var jsonText = JSON.stringify(exported, null, 2);
    document.getElementById('json-sandbox-textarea').value = jsonText;
    
    // Set confirm action
    document.getElementById('json-action-confirm-btn').onclick = function() {
        closeJsonModal();
    };
    document.getElementById('json-action-confirm-btn').innerText = "کپی کردن به کلیپ‌بورد";
    document.getElementById('json-action-confirm-btn').onclick = function() {
        navigator.clipboard.writeText(jsonText).then(() => {
            alert('اطلاعات JSON کل سند کپی شد. می‌توانید آن را برای پشتیبان‌گیری یا انتقال فوری استفاده کنید.');
            closeJsonModal();
        });
    };
    
    document.getElementById('json-sandbox-modal').classList.remove('hidden');
}

function importDocJson() {
    document.getElementById('json-sandbox-textarea').value = "";
    document.getElementById('json-sandbox-textarea').placeholder = "بلوک کپی شده کامل JSON و اطلاعات کاتالوگ را اینجا قرار دهید...";
    
    document.getElementById('json-action-confirm-btn').innerText = "اعمال و ایمپورت در فریم زنده";
    document.getElementById('json-action-confirm-btn').onclick = function() {
        var rawText = document.getElementById('json-sandbox-textarea').value.trim();
        if (!rawText) return;
        
        try {
            var parsed = JSON.parse(rawText);
            if (!parsed.type || !parsed.content) {
                alert('فرمت وارد شده یک سند معتبر پروپوزال یا برنامه مارک‌شو نیست.');
                return;
            }
            
            activeDoc.title = parsed.title || activeDoc.title;
            activeDoc.logo_url = parsed.logo_url || activeDoc.logo_url;
            activeDoc.type = parsed.type;
            activeDoc.status = parsed.status || "draft";
            activeDoc.content = parsed.content;
            
            syncStateToInputs();
            renderLivePreview();
            closeJsonModal();
            alert('درون‌ریزی فایل ساخت‌یافته موفقیت‌آمیز بود! برای ماندگاری دکمه ذخیره سند را کلیک کنید.');
        } catch(e) {
            alert('بروز خطا در خواندن کد JSON. لطفا صحت کاراکترها را بررسی کنید.');
        }
    };
    
    document.getElementById('json-sandbox-modal').classList.remove('hidden');
}

function closeJsonModal() {
    document.getElementById('json-sandbox-modal').classList.add('hidden');
}


// ---------------- GEMINI AI CO-PILOT ----------------
function generateDocWithAi() {
    var promptBox = document.getElementById('ai-generate-prompt');
    var prompt = promptBox.value.trim();
    var type = document.getElementById('ai-generate-type').value;
    
    if (!prompt) {
        alert('لطفاً ابتدا جزئیات کلمه کلیدی یا شرح پروژه را بنویسید.');
        return;
    }
    
    var aiBtn = document.getElementById('ai-generate-btn');
    var btnOrig = aiBtn.innerHTML;
    aiBtn.innerHTML = "🌀 دسترسی به ماهواره جریانی هوش مصنوعی...";
    aiBtn.disabled = true;
    
    var formData = new FormData();
    formData.append('ajax_action', 'generate_ai');
    formData.append('prompt', prompt);
    formData.append('type', type);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        aiBtn.innerHTML = btnOrig;
        aiBtn.disabled = false;
        
        if (res.success) {
            // Apply generated schema to active document
            activeDoc.type = type;
            activeDoc.content = res.data;
            activeDoc.title = type === 'plan' ? (res.data.title || "برنامه عددی سئو") : (res.data.title || "پروپوزال کمپین اتوماسیون");
            
            syncStateToInputs();
            renderLivePreview();
            alert('هوش مصنوعی با موفقیت محتوا را منطبق بر برند و سنجه‌ها چیدمان کرد! می‌توانید اکنون ویرایش لایو را آغاز کنید.');
        } else {
            alert('بروز خطا در برقراری با هوش مصنوعی: ' + res.error);
        }
    })
    .catch(err => {
        aiBtn.innerHTML = btnOrig;
        aiBtn.disabled = false;
        alert('بروز خطای غیرمنتظره در سرور اتصال به هوش مصنوعی.');
    });
}

function setAiPrompt(text) {
    document.getElementById('ai-generate-prompt').value = text;
}


// ---------------- BACKEND ACTIONS (SAVE / DELETE) ----------------
function saveActiveDocument() {
    collectInputsToState();
    
    var formData = new FormData();
    formData.append('ajax_action', 'save');
    formData.append('id', activeDoc.id);
    formData.append('project_id', activeDoc.project_id);
    formData.append('type', activeDoc.type);
    formData.append('title', activeDoc.title);
    formData.append('logo_url', activeDoc.logo_url);
    formData.append('status', activeDoc.status);
    formData.append('content', JSON.stringify(activeDoc.content));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            alert('سند با موفقیت ذخیره و پایدارسازی گردید.');
            // Reload page to reflect lists & new generated IDs
            location.href = "?project=" + encodeURIComponent(activeDoc.project_id);
        } else {
            alert('خطا در ذخیره‌سازی: ' + res.error);
        }
    })
    .catch(err => {
        alert('خطای اتصال سرور برای ذخیره سند.');
    });
}

function deleteDoc(id) {
    if (confirm('⚠️ مبرهن: آیا کاملا مطمئن هستید که می‌خواهید این سند را به طور کامل پاک کنید؟ این عمل غیرقابل بازگشت است.')) {
        var formData = new FormData();
        formData.append('ajax_action', 'delete');
        formData.append('id', id);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                alert('سند با موفقیت حذف گردید.');
                location.reload();
            } else {
                alert('خطا در حذف: ' + res.error);
            }
        })
        .catch(err => {
            alert('خطای سیستمی اتصال سرور.');
        });
    }
}

function openActiveLivePreviewTab() {
    if (!activeDoc.id) {
        alert('ابتدا لطفاً تغییرات این سند را یکبار ذخیره کنید تا لینک منحصربفرد عمومی ساخته شود.');
        return;
    }
    if (activeDoc.status !== 'published') {
        alert('وضعیت سند پیش‌نویس است. برای مشاهده از طریق لینک عمومی ابتدا از کار بالا وضعیت را چسبانده به "منتشر شده (لینک عمومی فعال)" ارتقا دهید و دکمه ذخیره سند را کلیک کنید.');
        return;
    }
    window.open("?public_id=" + encodeURIComponent(activeDoc.id), "_blank");
}


// Little Helpers
function formatNumber(num) {
    return Number(num).toLocaleString('fa-IR');
}

function escapeHtml(text) {
    if (!text) return "";
    return text
         .toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
</script>
</body>
</html>
