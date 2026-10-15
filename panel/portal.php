<?php
if (!ob_start("ob_gzhandler")) {
    ob_start();
}
/**
 * Maarksho Flow v3 - پورتال نمایش محصولات برای کاربران نهایی
 * 
 * این فایل برای نمایش محصولات پروژه جاری به کاربران عادی طراحی شده است.
 * قابلیتها: نمایش محصولات، لایک/دیسلایک، ثبت بازخورد، مشاهده جزئیات
 */

// فعالسازی نمایش خطاها برای دیباگ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// شروع session با تنظیمات امن
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

// بارگذاری توابع اصلی
$functionsPath = __DIR__ . '/inc/functions.php';
if (!file_exists($functionsPath)) {
    die('خطای سیستم: فایل توابع یافت نشد.');
}
require_once $functionsPath;

// Authentication check
if (!isUser()) {
    redirect('index.php');
}

// دریافت پارامتر تب فعال
$active_tab = $_GET['tab'] ?? 'overview';

// بررسی وجود پروژه (از طریق session یا پارامتر GET)
$projectId = $_GET['project'] ?? ($_SESSION['project_id'] ?? null);
if (!$projectId) {
    // تلاش برای یافتن اولین پروژه در صورت لزوم (اختیاری)
    $db = getDB();
    if (!empty($db['projects'])) {
        $projectId = $db['projects'][0]['id'];
    } else {
        die('پروژه‌ای انتخاب نشده است. لطفاً با مدیر سایت تماس بگیرید.');
    }
}

$project = getCurrentProject($projectId);
if (!$project) {
    die('پروژه مورد نظر یافت نشد.');
}

// Ensure any active project switching parameter is processable
if (isset($_GET['switch_project']) || (isset($_GET['project']) && $_GET['project'] !== ($_SESSION['project_id'] ?? ''))) {
    $target_pid = $_GET['switch_project'] ?? $_GET['project'];
    $allowed = false;
    
    if (isSuperAdmin()) {
        $allowed = true;
    } else {
        $u_username = $_SESSION['user']['username'] ?? '';
        $u_mobile = $_SESSION['portal_user'] ?? '';
        $db_p = getDB();
        foreach ($db_p['projects'] as $p_item) {
            if ($p_item['id'] === $target_pid && isset($p_item['users']) && is_array($p_item['users'])) {
                foreach ($p_item['users'] as $pu) {
                    if (($u_username && $pu['username'] === $u_username) || ($u_mobile && isset($pu['mobile']) && trim($pu['mobile']) === $u_mobile)) {
                        $_SESSION['user'] = $pu; // update active profile permissions!
                        $allowed = true;
                        break 2;
                    }
                }
            }
        }
    }
    
    if ($allowed) {
        $_SESSION['project_id'] = $target_pid;
        redirect('portal.php?project=' . urlencode($target_pid));
    } else {
        die('خطای دسترسی: شما مجاز به مشاهده این پروژه نیستید.');
    }
}

// STRICT PROJECT ACCESS CHECK FOR SECURITY (ACCURACY AT 100%)
$allowed = false;
if (isSuperAdmin()) {
    $allowed = true;
} else {
    $u_username = $_SESSION['user']['username'] ?? '';
    $u_mobile = $_SESSION['portal_user'] ?? '';
    
    if (isset($project['users']) && is_array($project['users'])) {
        foreach ($project['users'] as $pu) {
            if (($u_username && $pu['username'] === $u_username) || ($u_mobile && isset($pu['mobile']) && trim($pu['mobile']) === $u_mobile)) {
                $allowed = true;
                break;
            }
        }
    }
    
    if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'portal_viewer') {
        $allowed = true;
    }
}

if (!$allowed) {
    die('خطای دسترسی: شما به این پروژه دسترسی ندارید.');
}

// Pre-load all allowed projects for quick switching dropdown
$user_allowed_projects = [];
$db_p = getDB();
foreach ($db_p['projects'] as $p_item) {
    if (isSuperAdmin()) {
        $user_allowed_projects[] = $p_item;
    } else {
        $u_username = $_SESSION['user']['username'] ?? '';
        $u_mobile = $_SESSION['portal_user'] ?? '';
        if (isset($p_item['users']) && is_array($p_item['users'])) {
            foreach ($p_item['users'] as $pu) {
                if (($u_username && $pu['username'] === $u_username) || ($u_mobile && isset($pu['mobile']) && trim($pu['mobile']) === $u_mobile)) {
                    $user_allowed_projects[] = $p_item;
                    break;
                }
            }
        }
    }
}

if (!function_exists('is_product_without_image')) {
    function is_product_without_image($p) {
        return isProductNoImage($p);
    }
}

// Enforce array types for project fields
if (!isset($project['products']) || !is_array($project['products'])) $project['products'] = [];
if (!isset($project['schedules']) || !is_array($project['schedules'])) $project['schedules'] = [];
if (!isset($project['settings']) || !is_array($project['settings'])) $project['settings'] = [];

// Apply Active Module restrictions for Clients/Portals
if (!isModuleActive('products')) {
    $products_tabs = ['pending', 'library', 'out-of-stock', 'rejected', 'no-image'];
    if (in_array($active_tab, $products_tabs)) {
        if (isModuleActive('calendar')) {
            $active_tab = 'calendar';
        } else {
            ?>
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <title>پورتال اختصاصی پروژه <?php echo htmlspecialchars($project['name']); ?></title>
                <link rel="stylesheet" href="assets/font.css">
                <script src="<?php echo getFullUrl('assets/tailwindcss.js'); ?>"></script>
            </head>
            <body class="bg-slate-50 min-h-screen flex items-center justify-center font-sans p-6 select-none">
                <div class="max-w-md w-full bg-white border border-slate-100 rounded-[3rem] p-10 text-center shadow-2xl shadow-indigo-500/5">
                    <div class="w-20 h-20 bg-indigo-50 text-indigo-600 rounded-3xl flex items-center justify-center text-4xl mx-auto mb-6">⚙️</div>
                    <h1 class="font-black text-slate-800 text-xl mb-3">پورتال اختصاصی پایش</h1>
                    <p class="text-xs text-slate-500 leading-relaxed font-bold mb-8">
                        پیکربندی اختصاصی پروژه <strong class="text-slate-900"><?php echo htmlspecialchars($project['name']); ?></strong> به صورت بهینه‌سازی شده ثبت شده است و بخش تصویری و تایید کالاها برای این دسته کسب‌وکار مورد نیاز نبوده است. لطفاً جهت پایش سئو یا مالی از پنل اصلی اقدام فرمایید.
                    </p>
                    <a href="admin.php" class="inline-flex items-center justify-center bg-indigo-600 hover:bg-slate-900 text-white font-bold text-xs p-4 w-full rounded-2xl shadow-lg transition-all">
                        <span>📊</span>
                        <span class="mr-1.5">ورود به میز کار کلان (Insight Dashboard)</span>
                    </a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

function isProductImageUrlApproved($p, $imgUrl) {
    if (empty($p['images_list'])) {
        return false;
    }
    $list = $p['images_list'];
    if (is_string($list)) {
        $list = json_decode($list, true) ?: [];
    }
    if (is_array($list)) {
        $cleanImgUrl = strtok($imgUrl, '?');
        foreach ($list as $item) {
            $cleanItemUrl = isset($item['url']) ? strtok($item['url'], '?') : '';
            if ($cleanItemUrl && $cleanItemUrl === $cleanImgUrl) {
                return !empty($item['approved']);
            }
        }
    }
    if (isset($p['status']) && $p['status'] === 'active') {
        return true;
    }
    return false;
}

function renderCardImageCarousel($p) {
    $images = [];
    $isPending = (isset($p['status']) && $p['status'] === 'pending');
    
    // 1. Social Image
    if (!empty($p['social_image']) && strpos($p['social_image'], 'placehold') === false) {
        if (!$isPending || !isProductImageUrlApproved($p, $p['social_image'])) {
            $images[] = $p['social_image'];
        }
    }
    // 2. Gallery Images
    if (!empty($p['gallery'])) {
        $gal = $p['gallery'];
        if (is_string($gal)) {
            $gal = json_decode($gal, true) ?: [];
        }
        if (is_array($gal)) {
            foreach ($gal as $g) {
                if (!empty($g) && strpos($g, 'placehold') === false) {
                    if (!$isPending || !isProductImageUrlApproved($p, $g)) {
                        $images[] = $g;
                    }
                }
            }
        }
    }
    // 3. Website Image
    if (!empty($p['image']) && strpos($p['image'], 'placehold') === false) {
        if (!$isPending || !isProductImageUrlApproved($p, $p['image'])) {
            $images[] = $p['image'];
        }
    }
    // 4. Reference Gallery
    if (!empty($p['reference_gallery'])) {
        $ref = $p['reference_gallery'];
        if (is_string($ref)) {
            $ref = json_decode($ref, true) ?: [];
        }
        if (is_array($ref)) {
            foreach ($ref as $r) {
                if (!empty($r) && strpos($r, 'placehold') === false) {
                    if (!$isPending || !isProductImageUrlApproved($p, $r)) {
                        $images[] = $r;
                    }
                }
            }
        }
    }
    $images = array_values(array_unique($images));
    if (empty($images)) {
        if (!empty($p['social_image']) && strpos($p['social_image'], 'placehold') === false) {
            $images[] = $p['social_image'];
        }
        if (!empty($p['gallery'])) {
            $gal = $p['gallery'];
            if (is_string($gal)) { $gal = json_decode($gal, true) ?: []; }
            if (is_array($gal)) {
                foreach ($gal as $g) {
                    if (!empty($g) && strpos($g, 'placehold') === false) { $images[] = $g; }
                }
            }
        }
        if (!empty($p['image']) && strpos($p['image'], 'placehold') === false) {
            $images[] = $p['image'];
        }
        if (!empty($p['reference_gallery'])) {
            $ref = $p['reference_gallery'];
            if (is_string($ref)) { $ref = json_decode($ref, true) ?: []; }
            if (is_array($ref)) {
                foreach ($ref as $r) {
                    if (!empty($r) && strpos($r, 'placehold') === false) { $images[] = $r; }
                }
            }
        }
        $images = array_values(array_unique($images));
    }
    
    if (empty($images)) {
        if (!empty($p['image'])) {
            $images[] = $p['image'];
        } else {
            $images[] = 'https://placehold.co/400x500?text=Maarksho';
        }
    }
    
    $productId = $p['id'];
    $count = count($images);
    
    if ($count <= 1) {
        $imgUrl = getFullUrl($images[0]);
        echo '<img src="' . $imgUrl . '" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover transition-transform duration-500 hover:scale-105" onerror="this.src=\'https://placehold.co/400x500?text=Maarksho\'">';
        return;
    }
    ?>
    <div class="relative w-full h-full select-none flex overflow-hidden group/carousel z-10" dir="ltr" onclick="event.stopPropagation()">
        <!-- Scroll Track -->
        <div id="card-scroll-<?php echo $productId; ?>" 
             onscroll="updateCardDots('<?php echo $productId; ?>')"
             class="flex w-full h-full overflow-x-auto scroll-smooth snap-x snap-mandatory scrollbar-none">
            <?php foreach ($images as $img): 
                $imgUrl = getFullUrl($img);
            ?>
                <div class="w-full h-full flex-shrink-0 snap-start relative h-full cursor-pointer" onclick="openDetailById('<?php echo $productId; ?>')">
                    <img src="<?php echo $imgUrl; ?>" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover transition-transform duration-500 hover:scale-105" onerror="this.src='https://placehold.co/400x500?text=Maarksho'">
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Navigation Arrows (visible on card hover) -->
        <button onclick="scrollCardCarousel('<?php echo $productId; ?>', -1); event.stopPropagation();" 
                class="absolute left-2.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/40 hover:bg-black/70 text-white flex items-center justify-center font-bold text-sm cursor-pointer z-20 backdrop-blur-sm opacity-0 group-hover/carousel:opacity-100 transition-all duration-300">
             &lt;
        </button>
        <button onclick="scrollCardCarousel('<?php echo $productId; ?>', 1); event.stopPropagation();" 
                class="absolute right-2.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/40 hover:bg-black/70 text-white flex items-center justify-center font-bold text-sm cursor-pointer z-20 backdrop-blur-sm opacity-0 group-hover/carousel:opacity-100 transition-all duration-300">
             &gt;
        </button>

        <!-- Indicators Dots overlay -->
        <div id="card-dots-<?php echo $productId; ?>" class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-1 z-20 bg-black/25 backdrop-blur-md px-2 py-1 rounded-full pointer-events-none">
            <?php for ($i = 0; $i < $count; $i++): ?>
                <span class="w-1.5 h-1.5 rounded-full transition-all duration-300 <?php echo $i === 0 ? 'bg-white' : 'bg-white/40'; ?>"></span>
            <?php endfor; ?>
        </div>
    </div>
    <?php
}

// شناسایی خودکار تنوع رنگی سبک و بهینه بر اساس اسامی مشابه
$productsByName = [];
if (!empty($project['products']) && is_array($project['products'])) {
    foreach ($project['products'] as $prod) {
        if (empty($prod['name'])) continue;
        $normName = trim(preg_replace('/\s+/', ' ', mb_strtolower($prod['name'])));
        if (!isset($productsByName[$normName])) {
            $productsByName[$normName] = [];
        }
        $productsByName[$normName][] = [
            'id' => $prod['id'] ?? '',
            'name' => $prod['name'] ?? '',
            'color' => !empty($prod['color']) ? trim($prod['color']) : 'رنگ فرعی',
            'image' => $prod['image'] ?? ($prod['social_image'] ?? ''),
            'price' => $prod['price'] ?? '',
            'sku' => $prod['sku'] ?? '---',
            'status' => $prod['status'] ?? 'active'
        ];
    }
}

$active_tab = $_GET['tab'] ?? 'overview';

// شناسایی و یکپارچه‌سازی هویت کاربر جهت جلوگیری از تکرار آراء
$voterId = getVoterId();
$visitorId = $_SESSION['visitor_id'] ?? $voterId;

// پروفایل کاربر در سشن
if (!isset($_SESSION['display_name'])) {
    $_SESSION['display_name'] = 'کاربر ' . substr($voterId, 0, 5);
}

$current_p_username = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'guest';
$content_gen_users_raw = $project['settings']['prod_content_gen_users'] ?? '';
$content_gen_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($content_gen_users_raw))));
$can_request_content_gen = empty($content_gen_users) || in_array(strtolower($current_p_username), $content_gen_users);

$couples_users_raw = $project['settings']['prod_couples_users'] ?? '';
$couples_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($couples_users_raw))));
$can_create_couples = empty($couples_users) || in_array(strtolower($current_p_username), $couples_users);

$ref_image_users_raw = $project['settings']['prod_ref_image_users'] ?? '';
$ref_image_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($ref_image_users_raw))));
$can_manage_ref_images = empty($ref_image_users) || in_array(strtolower($current_p_username), $ref_image_users) || (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin');

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
if ($isAjax && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'msg' => 'درخواست نامعتبر'];
    
    // هندلر اختصاصی ثبت پروفایل کاربری
    if ($_POST['action'] === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        if ($displayName) {
            $_SESSION['display_name'] = htmlspecialchars($displayName);
        }
        if (isset($_FILES['user_icon']) && $_FILES['user_icon']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['user_icon']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp', 'gif'];
            if (in_array($ext, $allowed)) {
                $newName = 'profile_' . $visitorId . '_' . time() . '.' . $ext;
                if (!is_dir(UPLOADS_DIR)) {
                    mkdir(UPLOADS_DIR, 0777, true);
                }
                if (move_uploaded_file($_FILES['user_icon']['tmp_name'], UPLOADS_DIR . $newName)) {
                    chmod(UPLOADS_DIR . $newName, 0644);
                    $_SESSION['user_icon'] = 'uploads/' . $newName;
                }
            }
        }
        echo json_encode(['status' => 'success', 'msg' => 'تنظیمات پروفایل شما با موفقیت ذخیره شد ✅']);
        exit;
    }

    if ($_POST['action'] === 'cast_occasion_vote') {
        $occ_key = $_POST['occ_key'] ?? '';
        $stage = $_POST['stage'] ?? '';
        $approve = (int)($_POST['approve'] ?? 1);
        
        if (!$occ_key || ($stage !== 'stage1' && $stage !== 'stage2')) {
            echo json_encode(['status' => 'error', 'msg' => 'شناسه مناسبت یا مرحله ممیزی نامعتبر است.']);
            exit();
        }
        
        $user_lo = strtolower($_SESSION['user']['username'] ?? '');
        if (!$user_lo) {
            echo json_encode(['status' => 'error', 'msg' => 'کاربر شناسایی نشد. مجددا وارد پورتال شوید.']);
            exit();
        }
        
        if (!isset($project['settings']['occasions_plans'])) {
            $project['settings']['occasions_plans'] = [];
        }
        if (!isset($project['settings']['occasions_plans'][$occ_key])) {
            $project['settings']['occasions_plans'][$occ_key] = [
                'pdf_path' => '',
                'tasks' => []
            ];
        }
        
        $plan = &$project['settings']['occasions_plans'][$occ_key];
        $app_key = ($stage === 'stage1') ? 'approvals_stage1' : 'approvals_stage2';
        if (!isset($plan[$app_key]) || !is_array($plan[$app_key])) {
            $plan[$app_key] = [];
        }
        
        $current_approvals = $plan[$app_key];
        $lowercase_approvals = array_map('strtolower', $current_approvals);
        
        if ($approve === 1) {
            if (!in_array($user_lo, $lowercase_approvals)) {
                $plan[$app_key][] = $_SESSION['user']['username'] ?? $user_lo;
            }
        } else {
            $index = array_search($user_lo, $lowercase_approvals);
            if ($index !== false) {
                array_splice($plan[$app_key], $index, 1);
            }
        }
        
        saveProject($project);
        echo json_encode([
            'status' => 'success',
            'msg' => 'تغییر ممیزی شما با موفقیت با هرم نظارتی همگام‌سازی شد! ✅',
            'approvals1' => $plan['approvals_stage1'] ?? [],
            'approvals2' => $plan['approvals_stage2'] ?? []
        ]);
        exit();
    }

    if ($_POST['action'] === 'create_couple_set') {
        if (!$can_create_couples) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه این کار را ندارید.']);
            exit;
        }

        $p1_id = trim($_POST['p1_id'] ?? '');
        $p2_id = trim($_POST['p2_id'] ?? '');
        $set_name = trim($_POST['set_name'] ?? '');
        $set_price = trim($_POST['set_price'] ?? '');
        $set_sizes = trim($_POST['set_sizes'] ?? '');
        $set_description = trim($_POST['set_description'] ?? '');

        if (!$p1_id || !$p2_id || !$set_name) {
            echo json_encode(['status' => 'error', 'msg' => 'اطلاعات ارسالی ناقص است.']);
            exit;
        }

        $all_products = $project['products'] ?? [];
        $p1 = null;
        $p2 = null;
        foreach ($all_products as $p) {
            if ($p['id'] == $p1_id) $p1 = $p;
            if ($p['id'] == $p2_id) $p2 = $p;
        }

        if (!$p1 || !$p2) {
            echo json_encode(['status' => 'error', 'msg' => 'یکی از محصولات انتخاب شده یافت نشد.']);
            exit;
        }

        $new_id = 'set_' . time() . '_' . mt_rand(100, 999);
        $new_sku = 'SET-' . sprintf("%06d", mt_rand(1, 999999));

        $new_product = [
            'id' => $new_id,
            'project_id' => $project['id'],
            'sku' => $new_sku,
            'name' => $set_name,
            'description' => $set_description,
            'image' => 'https://placehold.co/600x600?text=' . urlencode($set_name),
            'social_image' => '',
            'gallery' => json_encode([]),
            'link' => '',
            'price' => $set_price,
            'extracted_at' => time(),
            'manual_edit' => 1,
            'likes' => 0,
            'dislikes' => 0,
            'user_votes' => json_encode([]),
            'user_feedback' => json_encode([]),
            'platforms_sent' => json_encode([]),
            'status' => 'active',
            'color' => '',
            'stock_online' => 10,
            'stock_total' => 10,
            'sizes' => $set_sizes,
            'size_chart' => '',
            'priority' => 0,
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'variants' => json_encode([]),
            'uploaded_to_site' => 0,
            'uploaded_by' => $current_p_username,
            'is_manual_image' => 0,
            'approvals_stage1' => json_encode([]),
            'approvals_stage2' => json_encode([]),
            'rejection_reason' => '',
            'rejection_by' => '',
            'rejection_side' => '',
            'rejections_history' => json_encode([]),
            'content_gen_requested' => 0,
            'publish_priority' => 0,
            'reference_gallery' => json_encode([]),
            'images_list' => json_encode([]),
            'stage1_decision' => '',
            'stage2_decision' => '',
            'is_couple_set' => 1,
            'parent_products' => json_encode([$p1_id, $p2_id])
        ];

        $project['products'][] = $new_product;
        
        if (!isset($project['logs'])) {
            $project['logs'] = [];
        }
        $project['logs'][] = [
            'at' => time(),
            'type' => 'ایجاد ست کاپلی',
            'msg' => "ست کاپلی جدید با نام «{$set_name}» و شناسه {$new_id} از ادغام کد {$p1['sku']} و کد {$p2['sku']} ایجاد شد."
        ];

        if (saveProject($project, [$new_product])) {
            saveProject($project);
            echo json_encode(['status' => 'success', 'msg' => 'محصول کاپل / ست با موفقیت ساخته شد و به عنوان محصول بدون عکس جدید وارد سیستم شد! 🚀👥']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'خطا در ذخیره‌سازی محصول جدید در پایگاه داده.']);
        }
        exit;
    }

    if ($_POST['action'] === 'bulk_request_content_gen') {
        $content_gen_users_raw = $project['settings']['prod_content_gen_users'] ?? '';
        $content_gen_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($content_gen_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        if (!in_array(strtolower($current_user), $content_gen_users)) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه ندارید برای کالاهای بدون تصویر درخواست تولید محتوا ثبت کنید.']);
            exit;
        }

        $product_ids = isset($_POST['product_ids']) ? (array)$_POST['product_ids'] : [];
        if (empty($product_ids)) {
            echo json_encode(['status' => 'error', 'msg' => 'هیچ محصولی انتخاب نشده است.']);
            exit;
        }
        
        $count = 0;
        $updatedProducts = [];
        $skippedCount = 0;
        foreach ($project['products'] ?? [] as &$prod) {
            if (in_array((string)$prod['id'], $product_ids)) {
                if (!empty($prod['content_gen_requested'])) {
                    $skippedCount++;
                    continue;
                }
                $prod['content_gen_requested'] = true;
                $updatedProducts[] = $prod;
                $count++;
            }
        }
        unset($prod);
        
        if ($count > 0) {
            saveProject($project, $updatedProducts);
            $msg = "درخواست تولید تصویر برای {$count} محصول با موفقیت ثبت شد ✅";
            if ($skippedCount > 0) {
                $msg .= " ({$skippedCount} کالا قبلاً در اولویت تولید بودند و نادیده گرفته شدند)";
            }
            echo json_encode(['status' => 'success', 'msg' => $msg]);
            exit;
        } else {
            if ($skippedCount > 0) {
                echo json_encode(['status' => 'error', 'msg' => 'تمامی کالاهای انتخاب شده از قبل دارای وضعیت «اولویت تولید محتوا» می‌باشند ⚠️']);
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'کالای معتبری یافت نشد.']);
            }
            exit;
        }
    }

    if ($_POST['action'] === 'reset_content_gen_priority') {
        $content_gen_users_raw = $project['settings']['prod_content_gen_users'] ?? '';
        $content_gen_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($content_gen_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        if (!in_array(strtolower($current_user), $content_gen_users)) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه ندارید اولویت تولید محتوا را ریست کنید.']);
            exit;
        }

        $count = 0;
        $updatedProducts = [];
        foreach ($project['products'] ?? [] as &$prod) {
            if (!empty($prod['content_gen_requested'])) {
                $prod['content_gen_requested'] = false;
                $updatedProducts[] = $prod;
                $count++;
            }
        }
        unset($prod);

        if ($count > 0) {
            saveProject($project, $updatedProducts);
            echo json_encode(['status' => 'success', 'msg' => 'اولویت تولید محتوا برای ' . $count . ' کالا با موفقیت ریست شد ✅']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'هیچ کالایی در وضعیت اولویت تولید محتوا یافت نشد.']);
            exit;
        }
    }

    if ($_POST['action'] === 'bulk_request_publish_priority') {
        $content_gen_users_raw = $project['settings']['prod_content_gen_users'] ?? '';
        $content_gen_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($content_gen_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        if (!in_array(strtolower($current_user), $content_gen_users)) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه ندارید برای کالاهای تایید شده اولویت انتشار ثبت کنید.']);
            exit;
        }

        $product_ids = isset($_POST['product_ids']) ? (array)$_POST['product_ids'] : [];
        if (empty($product_ids)) {
            echo json_encode(['status' => 'error', 'msg' => 'هیچ محصولی انتخاب نشده است.']);
            exit;
        }
        
        $count = 0;
        $updatedProducts = [];
        $skippedCount = 0;
        foreach ($project['products'] ?? [] as &$prod) {
            if (in_array((string)$prod['id'], $product_ids)) {
                if (!empty($prod['publish_priority'])) {
                    $skippedCount++;
                    continue;
                }
                $prod['publish_priority'] = true;
                $updatedProducts[] = $prod;
                $count++;
            }
        }
        unset($prod);
        
        if ($count > 0) {
            saveProject($project, $updatedProducts);
            $msg = "اولویت برای تقویم و انتشار برای {$count} محصول با موفقیت ثبت شد ✅";
            if ($skippedCount > 0) {
                $msg .= " ({$skippedCount} کالا قبلاً در اولویت انتشار بودند)";
            }
            echo json_encode(['status' => 'success', 'msg' => $msg]);
            exit;
        } else {
            if ($skippedCount > 0) {
                echo json_encode(['status' => 'error', 'msg' => 'تمامی کالاهای انتخاب شده از قبل دارای وضعیت «اولویت انتشار» می‌باشند ⚠️']);
            } else {
                echo json_encode(['status' => 'error', 'msg' => 'کالای معتبری یافت نشد.']);
            }
            exit;
        }
    }

    if ($_POST['action'] === 'reset_publish_priority') {
        $content_gen_users_raw = $project['settings']['prod_content_gen_users'] ?? '';
        $content_gen_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($content_gen_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        if (!in_array(strtolower($current_user), $content_gen_users)) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه ندارید اولویت انتشار را ریست کنید.']);
            exit;
        }

        $count = 0;
        $updatedProducts = [];
        foreach ($project['products'] ?? [] as &$prod) {
            if (!empty($prod['publish_priority'])) {
                $prod['publish_priority'] = false;
                $updatedProducts[] = $prod;
                $count++;
            }
        }
        unset($prod);

        if ($count > 0) {
            saveProject($project, $updatedProducts);
            echo json_encode(['status' => 'success', 'msg' => 'اولویت انتشار برای ' . $count . ' کالا با موفقیت ریست شد ✅']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'هیچ کالایی در وضعیت اولویت انتشار یافت نشد.']);
            exit;
        }
    }

    if ($_POST['action'] === 'upload_product_ref_images') {
        $ref_image_users_raw = $project['settings']['prod_ref_image_users'] ?? '';
        $ref_image_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($ref_image_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        $can_manage = empty($ref_image_users) || in_array(strtolower($current_user), $ref_image_users) || (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin');
        if (!$can_manage) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫 شما اجازه ثبت یا ویرایش عکس‌های رفرنس را ندارید.']);
            exit;
        }

        $pid = trim($_POST['product_id'] ?? '');
        if (!$pid) {
            echo json_encode(['status' => 'error', 'msg' => 'شناسه محصول مشخص نشده است.']);
            exit;
        }

        $target_prod = null;
        foreach ($project['products'] ?? [] as &$p) {
            if ((string)$p['id'] === (string)$pid) {
                $target_prod = &$p;
                break;
            }
        }
        unset($p);

        if (!$target_prod) {
            echo json_encode(['status' => 'error', 'msg' => 'محصول مورد نظر یافت نشد.']);
            exit;
        }

        $currentRefs = getProductReferenceImagesList($target_prod);

        // Process URL inputs
        $ref_urls_raw = $_POST['ref_urls'] ?? '';
        if (is_array($ref_urls_raw)) {
            $urls = $ref_urls_raw;
        } else {
            $urls = preg_split('/[\r\n,]+/', (string)$ref_urls_raw);
        }

        foreach ($urls as $u) {
            $u = trim($u);
            if (!empty($u) && (strpos($u, 'http://') === 0 || strpos($u, 'https://') === 0 || strpos($u, 'uploads/') === 0)) {
                if (!in_array($u, $currentRefs, true) && count($currentRefs) < 3) {
                    $currentRefs[] = $u;
                }
            }
        }

        // Process file uploads
        if (!empty($_FILES['ref_files'])) {
            $files = $_FILES['ref_files'];
            $fileCount = is_array($files['name']) ? count($files['name']) : 1;
            
            if (!is_dir(UPLOADS_DIR)) {
                mkdir(UPLOADS_DIR, 0777, true);
            }

            for ($i = 0; $i < $fileCount; $i++) {
                if (count($currentRefs) >= 3) break;
                
                $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];

                if ($err === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (in_array($ext, $allowed, true)) {
                        $newFilename = 'ref_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $pid) . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                        $dest = UPLOADS_DIR . $newFilename;
                        if (move_uploaded_file($tmp, $dest)) {
                            chmod($dest, 0644);
                            $currentRefs[] = 'uploads/' . $newFilename;
                        }
                    }
                }
            }
        }

        // Keep maximum 3 images
        $currentRefs = array_slice(array_values(array_unique(array_filter($currentRefs))), 0, 3);
        $target_prod['reference_gallery'] = $currentRefs;

        // Update in project
        foreach ($project['products'] as &$p) {
            if ((string)$p['id'] === (string)$pid) {
                $p['reference_gallery'] = $currentRefs;
                break;
            }
        }
        unset($p);

        saveProject($project, [$target_prod]);

        echo json_encode([
            'status' => 'success',
            'msg' => 'تصاویر رفرنس با موفقیت ذخیره شدند ✅ (تعداد: ' . count($currentRefs) . ' از ۳)',
            'reference_gallery' => $currentRefs,
            'count' => count($currentRefs),
            'has_ref' => count($currentRefs) > 0
        ]);
        exit;
    }

    if ($_POST['action'] === 'delete_product_ref_image') {
        $ref_image_users_raw = $project['settings']['prod_ref_image_users'] ?? '';
        $ref_image_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($ref_image_users_raw))));
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        $can_manage = empty($ref_image_users) || in_array(strtolower($current_user), $ref_image_users) || (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin');
        if (!$can_manage) {
            echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز 🚫']);
            exit;
        }

        $pid = trim($_POST['product_id'] ?? '');
        $img_to_remove = trim($_POST['image_url'] ?? '');
        $img_idx = isset($_POST['image_index']) ? (int)$_POST['image_index'] : -1;

        $target_prod = null;
        foreach ($project['products'] ?? [] as &$p) {
            if ((string)$p['id'] === (string)$pid) {
                $target_prod = &$p;
                break;
            }
        }
        unset($p);

        if (!$target_prod) {
            echo json_encode(['status' => 'error', 'msg' => 'محصول یافت نشد.']);
            exit;
        }

        $currentRefs = getProductReferenceImagesList($target_prod);
        if ($img_idx >= 0 && isset($currentRefs[$img_idx])) {
            array_splice($currentRefs, $img_idx, 1);
        } elseif (!empty($img_to_remove)) {
            $currentRefs = array_filter($currentRefs, function($img) use ($img_to_remove) {
                return $img !== $img_to_remove && basename($img) !== basename($img_to_remove);
            });
        }

        $currentRefs = array_slice(array_values(array_unique(array_filter($currentRefs))), 0, 3);
        $target_prod['reference_gallery'] = $currentRefs;

        foreach ($project['products'] as &$p) {
            if ((string)$p['id'] === (string)$pid) {
                $p['reference_gallery'] = $currentRefs;
                break;
            }
        }
        unset($p);

        saveProject($project, [$target_prod]);

        echo json_encode([
            'status' => 'success',
            'msg' => 'تصویر رفرنس با موفقیت حذف شد 🗑️',
            'reference_gallery' => $currentRefs,
            'count' => count($currentRefs),
            'has_ref' => count($currentRefs) > 0
        ]);
        exit;
    }

    if ($_POST['action'] === 'get_pending_browser_relay_posts') {
        $posts = [];
        $now = time();
        $tg_method = $project['settings']['connections']['telegram']['proxy']['bypass_method'] ?? 'none';
        $ig_method = $project['settings']['connections']['instagram']['proxy']['bypass_method'] ?? 'none';
        
        foreach ($project['schedules'] ?? [] as $s) {
            $status = $s['status'] ?? 'pending';
            $approvalStatus = $s['approvalStatus'] ?? 'approved';
            $scheduledAt = $s['scheduledAt'] ?? 0;
            
            if ($status === 'pending' && $approvalStatus === 'approved' && $scheduledAt <= $now) {
                $plat = $s['platform'] ?? '';
                $plat_conf = $project['settings']['connections'][$plat] ?? [];
                $plat_method = 'none';
                if ($plat === 'telegram' || $plat === 'instagram') {
                    $plat_method = $plat_conf['proxy']['bypass_method'] ?? 'none';
                } else {
                    $plat_method = $plat_conf['bypass_method'] ?? 'none';
                }
                $is_relay = ($plat_method === 'browser_relay');
                
                if ($is_relay) {
                    $product = null;
                    if (!empty($s['productId'])) {
                        foreach ($project['products'] ?? [] as $prod) {
                            if ($prod['id'] == $s['productId']) {
                                $product = $prod;
                                break;
                            }
                        }
                    }
                    
                    if (!$product) {
                        $product = [
                            'id' => null,
                            'name' => 'پست سفارشی',
                            'image' => $s['mediaUrl'] ?? '',
                            'gallery' => [],
                            'link' => '',
                            'price' => ''
                        ];
                    }
                    
                    $schedule_date = ($scheduledAt > $now + 10) ? $scheduledAt : null;
                    $payload = getPlatformPayloadForBrowserRelay($product, $plat, $project['settings'], $s['caption'] ?? '', $schedule_date);
                    if ($payload) {
                        $posts[] = [
                            'id' => $s['id'],
                            'platform' => $plat,
                            'payload' => $payload
                        ];
                    }
                }
            }
        }
        echo json_encode(['status' => 'success', 'posts' => $posts]);
        exit();
    }

    if ($_POST['action'] === 'mark_relay_schedule_status') {
        $sid = $_POST['schedule_id'] ?? '';
        $scheduleStatus = $_POST['status'] ?? 'completed';
        $errorMsg = $_POST['error_message'] ?? '';
        
        $found = false;
        foreach ($project['schedules'] ?? [] as $idx => $s) {
            if (($s['id'] ?? '') === $sid) {
                $project['schedules'][$idx]['status'] = $scheduleStatus;
                if ($scheduleStatus === 'completed') {
                    $productName = $s['productName'] ?? 'پست زمان‌بندی شده';
                    $project['logs'][] = [
                        'at' => time(),
                        'type' => 'ارسال زمان‌بندی پل مرورگر',
                        'productName' => $productName,
                        'platform' => $s['platform'] ?? ''
                    ];
                }
                $found = true;
                break;
            }
        }
        
        if ($found) {
            saveProject($project);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'failed', 'msg' => 'سند زمان‌بندی یافت نشد']);
        }
        exit();
    }
    
    $productId = $_POST['product_id'] ?? '';
    $productToUpdate = null;
    if ($productId) {
        foreach ($project['products'] as &$p) {
            if ($p['id'] == $productId) {
                $productToUpdate = &$p;
                break;
            }
        }
    }

    // Interactions for Schedules
    $schedId = $_POST['schedule_id'] ?? '';
    $schedToUpdate = null;
    if ($schedId) {
        foreach ($project['schedules'] as &$s) {
            if ($s['id'] == $schedId) {
                $schedToUpdate = &$s;
                break;
            }
        }
    }

    if ($_POST['action'] === 'vote_schedule' && $schedToUpdate) {
        $type = $_POST['type']; // 'approved' or 'rejected'
        $stage = $_POST['stage'] ?? ''; // 'stage1' or 'stage2'
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'guest';
        $isSplit = !empty($project['settings']['cal_approval_split_enabled']);

        if ($type === 'rejected') {
            $reason = trim($_POST['reason'] ?? '');
            if (empty($reason)) {
                echo json_encode(['status' => 'failed', 'msg' => 'دلیل رد مشخص نشده است. لطفا دلیل رد کردن را وارد کنید.']);
                exit;
            }
            
            $schedToUpdate['rejection_reason'] = $reason;
            $schedToUpdate['rejection_by'] = $current_user;
            $schedToUpdate['rejection_at'] = time();
            
            // Determine side
            $rejection_side = 'عمومی / نامشخص';
            if ($isSplit) {
                $stage1_raw = $project['settings']['cal_approval_stage1_users'] ?? '';
                $stage1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage1_raw))));
                $stage2_raw = $project['settings']['cal_approval_stage2_users'] ?? '';
                $stage2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage2_raw))));
                
                $user_lower = strtolower($current_user);
                $in_s1 = in_array($user_lower, $stage1_users);
                $in_s2 = in_array($user_lower, $stage2_users);
                
                if ($stage === 'stage1') {
                    if (!$in_s1) {
                        echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت رد چیدمان نیست.']);
                        exit;
                    }
                    $rejection_side = 'بخش اول (چیدمان و تقویم)';
                    $schedToUpdate['stage1_decision'] = 'rejected';
                    $schedToUpdate['approvals_stage1'] = [];
                } elseif ($stage === 'stage2') {
                    if (!$in_s2) {
                        echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت رد موجودی و فروش نیست.']);
                        exit;
                    }
                    $rejection_side = 'بخش دوم (موجودی و فروش)';
                    $schedToUpdate['stage2_decision'] = 'rejected';
                    $schedToUpdate['approvals_stage2'] = [];
                } else {
                    if ($in_s1 && $in_s2) {
                        $rejection_side = 'چیدمان و فروش (هر دو بخش)';
                    } elseif ($in_s1) {
                        $rejection_side = 'بخش اول (چیدمان و تقویم)';
                    } elseif ($in_s2) {
                        $rejection_side = 'بخش دوم (موجودی و فروش)';
                    } else {
                        $rejection_side = 'ناظر تقویم';
                    }
                    $schedToUpdate['stage1_decision'] = 'rejected';
                    $schedToUpdate['stage2_decision'] = 'rejected';
                    $schedToUpdate['approvals_stage1'] = [];
                    $schedToUpdate['approvals_stage2'] = [];
                }
            } else {
                $schedToUpdate['stage1_decision'] = 'rejected';
                $schedToUpdate['stage2_decision'] = 'rejected';
                $schedToUpdate['approvals_stage1'] = [];
                $schedToUpdate['approvals_stage2'] = [];
            }
            
            $schedToUpdate['rejection_side'] = $rejection_side;
            if (!isset($schedToUpdate['rejections_history'])) {
                $schedToUpdate['rejections_history'] = [];
            }
            $schedToUpdate['rejections_history'][] = [
                'user' => $current_user,
                'reason' => $reason,
                'side' => $rejection_side,
                'at' => time()
            ];

            // Also append a comment to comments to keep timeline updated
            $schedToUpdate['comments'] = $schedToUpdate['comments'] ?? [];
            $schedToUpdate['comments'][] = [
                'user' => $current_user,
                'text' => '🚨 رد زمان‌بندی: ' . $reason . ' (' . $rejection_side . ')',
                'date' => date('Y-m-d H:i:s')
            ];

            if ($isSplit) {
                refreshScheduleSplitStatus($schedToUpdate, $project);
            } else {
                $schedToUpdate['approvalStatus'] = 'rejected';
            }
            
            $schedToUpdate['reactions'] = $schedToUpdate['reactions'] ?? [];
            $schedToUpdate['reactions'][$current_user] = 'rejected';
        } else {
            if ($isSplit) {
                if (!isset($schedToUpdate['approvals_stage1'])) $schedToUpdate['approvals_stage1'] = [];
                if (!isset($schedToUpdate['approvals_stage2'])) $schedToUpdate['approvals_stage2'] = [];

                $stage1_raw = $project['settings']['cal_approval_stage1_users'] ?? '';
                $stage1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage1_raw))));
                
                $stage2_raw = $project['settings']['cal_approval_stage2_users'] ?? '';
                $stage2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage2_raw))));

                $isSuperOrAdmin = isSuperAdmin() || isProjectAdmin();
                $in_cal_s1 = $isSuperOrAdmin || empty($stage1_users) || in_array(strtolower($current_user), $stage1_users);
                $in_cal_s2 = $isSuperOrAdmin || empty($stage2_users) || in_array(strtolower($current_user), $stage2_users);

                if ($stage === 'stage1') {
                    if ($in_cal_s1) {
                        if (!in_array($current_user, $schedToUpdate['approvals_stage1'])) {
                            $schedToUpdate['approvals_stage1'][] = $current_user;
                        }
                        $schedToUpdate['stage1_decision'] = 'approved';
                    } else {
                        echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت تایید فنی چیدمان نیست.']);
                        exit;
                    }
                } elseif ($stage === 'stage2') {
                    if ($in_cal_s2) {
                        if (!in_array($current_user, $schedToUpdate['approvals_stage2'])) {
                            $schedToUpdate['approvals_stage2'][] = $current_user;
                        }
                        $schedToUpdate['stage2_decision'] = 'approved';
                    } else {
                        echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت تایید زمان‌بندی و برنامه نیست.']);
                        exit;
                    }
                } else {
                    $user_placed = false;
                    if ($in_cal_s1) {
                        if (!in_array($current_user, $schedToUpdate['approvals_stage1'])) {
                            $schedToUpdate['approvals_stage1'][] = $current_user;
                        }
                        $schedToUpdate['stage1_decision'] = 'approved';
                        $user_placed = true;
                    }
                    if ($in_cal_s2) {
                        if (!in_array($current_user, $schedToUpdate['approvals_stage2'])) {
                            $schedToUpdate['approvals_stage2'][] = $current_user;
                        }
                        $schedToUpdate['stage2_decision'] = 'approved';
                        $user_placed = true;
                    }
                    if (!$user_placed) {
                        echo json_encode(['status' => 'failed', 'msg' => 'دسترسی غیر مجاز 🚫 شما مجاز به تایید این زمان‌بندی نیستید.']);
                        exit;
                    }
                }

                refreshScheduleSplitStatus($schedToUpdate, $project);
            } else {
                $schedToUpdate['approvalStatus'] = 'approved';
            }

            $schedToUpdate['reactions'] = $schedToUpdate['reactions'] ?? [];
            $schedToUpdate['reactions'][$current_user] = 'approved';
        }

        if (($schedToUpdate['approvalStatus'] ?? '') === 'approved') {
            autoScheduleNextProduct($project);
        }

        saveProject($project);
        echo json_encode(['status' => 'success', 'msg' => 'بازخورد شما ثبت شد', 'newStatus' => $schedToUpdate['approvalStatus']]);
        exit;
    }

    if ($_POST['action'] === 'comment_schedule' && $schedToUpdate) {
        $text = trim($_POST['text'] ?? '');
        if ($text) {
            $schedToUpdate['comments'] = $schedToUpdate['comments'] ?? [];
            $schedToUpdate['comments'][] = [
                'user' => $_SESSION['display_name'] ?? 'کاربر مهمان',
                'text' => $text,
                'date' => date('Y-m-d H:i:s')
            ];
            saveProject($project);
            echo json_encode(['status' => 'success', 'msg' => 'نظر شما ثبت شد']);
            exit;
        }
    }

    if (!$productToUpdate && $_POST['action'] !== 'update_profile') {
        echo json_encode(['status' => 'error', 'msg' => 'محصول یافت نشد']);
        exit;
    }
    
    if ($_POST['action'] === 'vote') {
        $type = $_POST['type']; // 'like' or 'dislike'
        $voterId = getVoterId();
        
        // همگام‌سازی و بازسازی لحظه‌ای کپی آرای خام جهت پیشگیری از عدم همخوانی
        if (!isset($productToUpdate['userVotes']) || !is_array($productToUpdate['userVotes'])) {
            $productToUpdate['userVotes'] = [];
        }
        
        // استخراج رای قبلی مستقیم از دیتای اصلی ثبت‌شده
        $prevType = null;
        if (isset($productToUpdate['userVotes'][$voterId])) {
            $prevVoteItem = $productToUpdate['userVotes'][$voterId];
            $prevType = is_array($prevVoteItem) ? ($prevVoteItem['type'] ?? 'like') : $prevVoteItem;
        }
        
        if ($prevType === $type) {
            // حالت Toggle off: لغو رای قبلی
            unset($productToUpdate['userVotes'][$voterId]);
            $msg = ($type === 'like' ? 'لایک شما برداشته شد' : 'دیسلایک شما برداشته شد');
        } else {
            // حالت ثبت رای جدید یا تغییر رای (Swap)
            $productToUpdate['userVotes'][$voterId] = [
                'type' => $type,
                'user' => $_SESSION['display_name'] ?? $_SESSION['user']['username'] ?? 'کاربر',
                'icon' => $_SESSION['user_icon'] ?? null,
                'at' => time()
            ];
            
            if ($prevType !== null) {
                $msg = ($type === 'like' ? 'رای شما به لایک تغییر یافت' : 'رای شما به دیسلایک تغییر یافت');
            } else {
                $msg = ($type === 'like' ? 'لایک شما با موفقیت ثبت شد' : 'دیسلایک شما با موفقیت ثبت شد');
            }
        }
        
        // بازسازی همزمان کپی آرای خلاصه
        $productToUpdate['voterVotes'] = [];
        $likesCount = 0;
        $dislikesCount = 0;
        foreach ($productToUpdate['userVotes'] as $vid => $vdata) {
            $vtype = is_array($vdata) ? ($vdata['type'] ?? 'like') : $vdata;
            $productToUpdate['voterVotes'][$vid] = $vtype;
            if ($vtype === 'like') {
                $likesCount++;
            } elseif ($vtype === 'dislike') {
                $dislikesCount++;
            }
        }
        
        // همگام‌سازی نهایی شمارنده‌ها با اصل رکوردها بدون بروز خطا یا افزایش ناصحیح
        $productToUpdate['likes'] = $likesCount;
        $productToUpdate['dislikes'] = $dislikesCount;
        
        saveProject($project, [$productToUpdate]);
        $response = [
            'status' => 'success', 
            'likes' => $likesCount, 
            'dislikes' => $dislikesCount,
            'user_vote' => $productToUpdate['voterVotes'][$voterId] ?? null,
            'msg' => $msg
        ];
    } elseif ($_POST['action'] === 'comment') {
        $text = $_POST['comment'] ?? '';
        if ($text) {
            if (!isset($productToUpdate['userFeedback'])) $productToUpdate['userFeedback'] = [];
            $productToUpdate['userFeedback'][] = [
                'user' => $_SESSION['display_name'],
                'icon' => $_SESSION['user_icon'] ?? null,
                'text' => $text,
                'at' => time()
            ];
            saveProject($project, [$productToUpdate]);
            $response = ['status' => 'success', 'msg' => 'نظر شما با موفقیت ثبت شد'];
        } else {
            // Comment is optional, so we return success even if empty if it was intentional
            $response = ['status' => 'success', 'msg' => 'عملیات با موفقیت انجام شد'];
        }
    } elseif ($_POST['action'] === 'quick_action') {
        $sub = $_POST['sub']; // approve, reject, approve_stage1, approve_stage2
        $current_user = $_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'unknown';
        $current_user_lower = strtolower($current_user);
        $isSplit = !empty($project['settings']['prod_approval_split_enabled']);
        
        $comment = trim($_POST['comment'] ?? '');

        if ($sub === 'reject' || $sub === 'reject_stage1' || $sub === 'reject_stage2') {
            if (empty($comment)) {
                echo json_encode(['status' => 'error', 'msg' => 'جهت رد کردن محصول، وارد کردن علت رد الزامی است.']);
                exit;
            }
            
            // Reset approvals only for the stage being rejected
            if ($isSplit) {
                if ($sub === 'reject_stage1') {
                    $productToUpdate['approvals_stage1'] = [];
                    $productToUpdate['stage1_decision'] = 'rejected';
                } elseif ($sub === 'reject_stage2') {
                    $productToUpdate['approvals_stage2'] = [];
                    $productToUpdate['stage2_decision'] = 'rejected';
                } else {
                    $productToUpdate['approvals_stage1'] = [];
                    $productToUpdate['approvals_stage2'] = [];
                    $productToUpdate['stage1_decision'] = 'rejected';
                    $productToUpdate['stage2_decision'] = 'rejected';
                }
            } else {
                $productToUpdate['approvals_stage1'] = [];
                $productToUpdate['approvals_stage2'] = [];
                $productToUpdate['stage1_decision'] = 'rejected';
                $productToUpdate['stage2_decision'] = 'rejected';
            }
            
            // Determine side
            $rejection_side = 'عمومی / نامشخص';
            if ($isSplit) {
                $stage1_raw = $project['settings']['prod_approval_stage1_users'] ?? '';
                $stage1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage1_raw))));
                $stage2_raw = $project['settings']['prod_approval_stage2_users'] ?? '';
                $stage2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage2_raw))));
                
                $isSuperOrAdmin = isSuperAdmin() || isProjectAdmin();
                $in_s1 = $isSuperOrAdmin || empty($stage1_users) || in_array($current_user_lower, $stage1_users);
                $in_s2 = $isSuperOrAdmin || empty($stage2_users) || in_array($current_user_lower, $stage2_users);
                
                if ($sub === 'reject_stage1') {
                    if (!$in_s1) {
                        echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت رد استایل نیست.']);
                        exit;
                    }
                    $rejection_side = 'بخش اول (استایل و محتوا)';
                } elseif ($sub === 'reject_stage2') {
                    if (!$in_s2) {
                        echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت رد دقت و انطباق نیست.']);
                        exit;
                    }
                    $rejection_side = 'بخش دوم (جزئیات و انطباق)';
                } else {
                    if ($in_s1 && $in_s2) {
                        $rejection_side = 'استایل و انطباق (هر دو بخش)';
                    } elseif ($in_s1) {
                        $rejection_side = 'بخش اول (استایل و محتوا)';
                    } elseif ($in_s2) {
                        $rejection_side = 'بخش دوم (جزئیات و انطباق)';
                    } else {
                        $rejection_side = 'ناظر کتابخانه';
                    }
                }
            }
            
            if ($isSplit) {
                refreshProductSplitStatus($productToUpdate, $project);
            } else {
                $productToUpdate['status'] = 'rejected';
            }
            
            $productToUpdate['rejectionReason'] = $comment;
            $productToUpdate['rejection_reason'] = $comment;
            $productToUpdate['rejection_by'] = $current_user;
            $productToUpdate['rejection_at'] = time();
            $productToUpdate['rejection_side'] = $rejection_side;
            
            if (!isset($productToUpdate['rejections_history'])) {
                $productToUpdate['rejections_history'] = [];
            }
            $productToUpdate['rejections_history'][] = [
                'user' => $current_user,
                'reason' => $comment,
                'side' => $rejection_side,
                'at' => time()
            ];

            if (!isset($productToUpdate['userFeedback'])) $productToUpdate['userFeedback'] = [];
            $productToUpdate['userFeedback'][] = [
                'user' => 'Portal Approver (' . $current_user . ')', 
                'text' => $comment, 
                'at' => time(), 
                'category' => 'negative'
            ];

            if (!isset($project['logs'])) $project['logs'] = [];
            $project['logs'][] = [
                'at' => time(),
                'type' => 'رد محتوا',
                'productName' => mb_substr($productToUpdate['name'] ?? 'محصول', 0, 100),
                'platform' => 'SYSTEM'
            ];
            
            if (!isset($project['settings']['sms_rejected_pending_list'])) {
                $project['settings']['sms_rejected_pending_list'] = [];
            }
            if (!in_array($productToUpdate['id'], $project['settings']['sms_rejected_pending_list'])) {
                $project['settings']['sms_rejected_pending_list'][] = $productToUpdate['id'];
            }
            
            saveProject($project, [$productToUpdate]);
            echo json_encode(['status' => 'success', 'msg' => 'بازخورد رد با موفقیت ثبت شد']);
            exit;
            
        } elseif ($sub === 'approve_stage1' || $sub === 'approve_stage2') {
            if (!$isSplit) {
                echo json_encode(['status' => 'error', 'msg' => 'سیستم تایید دو مرحله‌ای برای این پروژه فعال نیست.']);
                exit;
            }
            
            if (!isset($productToUpdate['approvals_stage1'])) $productToUpdate['approvals_stage1'] = [];
            if (!isset($productToUpdate['approvals_stage2'])) $productToUpdate['approvals_stage2'] = [];
            
            $stage1_raw = $project['settings']['prod_approval_stage1_users'] ?? '';
            $stage1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage1_raw))));
            
            $stage2_raw = $project['settings']['prod_approval_stage2_users'] ?? '';
            $stage2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($stage2_raw))));
            
            $isSuperOrAdmin = isSuperAdmin() || isProjectAdmin();
            $in_s1 = $isSuperOrAdmin || empty($stage1_users) || in_array($current_user_lower, $stage1_users);
            $in_s2 = $isSuperOrAdmin || empty($stage2_users) || in_array($current_user_lower, $stage2_users);
            
            if ($sub === 'approve_stage1') {
                if ($in_s1) {
                    if (!in_array($current_user, $productToUpdate['approvals_stage1'])) {
                        $productToUpdate['approvals_stage1'][] = $current_user;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت تایید فنی استایل نیست.']);
                    exit;
                }
            } elseif ($sub === 'approve_stage2') {
                if ($in_s2) {
                    if (!in_array($current_user, $productToUpdate['approvals_stage2'])) {
                        $productToUpdate['approvals_stage2'][] = $current_user;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیر مجاز 🚫 کاربر مجاز برای ثبت تایید فنی دقت نیست.']);
                    exit;
                }
            }
            
            if (!empty($comment)) {
                if (!isset($productToUpdate['userFeedback'])) $productToUpdate['userFeedback'] = [];
                $productToUpdate['userFeedback'][] = [
                    'user' => 'Portal Approver (' . $current_user . ')', 
                    'text' => $comment, 
                    'at' => time(), 
                    'category' => 'positive'
                ];
            }
            
            $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
            $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);
            
            if ($sub === 'approve_stage1') {
                $productToUpdate['stage1_decision'] = 'approved';
            } elseif ($sub === 'approve_stage2') {
                $productToUpdate['stage2_decision'] = 'approved';
            }
            
            refreshProductSplitStatus($productToUpdate, $project);
            if ($productToUpdate['status'] === 'active') {
                unset($productToUpdate['rejectionReason']);
            }
            
            if (!isset($project['logs'])) $project['logs'] = [];
            $project['logs'][] = [
                'at' => time(),
                'type' => 'تایید جزئی محتوا',
                'productName' => mb_substr($productToUpdate['name'] ?? 'محصول', 0, 100),
                'platform' => 'SYSTEM'
            ];
            
            saveProject($project, [$productToUpdate]);
            echo json_encode(['status' => 'success', 'msg' => 'تایید شما با موفقیت ثبت شد', 'newStatus' => $productToUpdate['status']]);
            exit;
            
        } elseif ($sub === 'approve') {
            if ($isSplit) {
                echo json_encode(['status' => 'error', 'msg' => 'جهت تایید این محصول لطفا از دکمه تایید تخصصی بخش مربوطه استفاده نمایید.']);
                exit;
            }
            
            if (!hasPermission('approve_content')) {
                echo json_encode(['status' => 'error', 'msg' => 'دسترسی غیرمجاز برای تایید نهایی']);
                exit;
            }
            
            $productToUpdate['status'] = 'active';
            unset($productToUpdate['rejectionReason']);
            cleanupProductApprovedReferenceImages($productToUpdate);
            
            // Rebuild images_list to tag manual/instagram sources
            $imagesList = [];
            if (!empty($productToUpdate['image'])) {
                $isLocal = (strpos($productToUpdate['image'], 'uploads/') === 0);
                $imagesList[] = [
                    'url' => $productToUpdate['image'],
                    'source' => $isLocal ? 'manual' : 'site'
                ];
            }
            if (!empty($productToUpdate['social_image'])) {
                $imagesList[] = [
                    'url' => $productToUpdate['social_image'],
                    'source' => 'instagram'
                ];
            }
            if (!empty($productToUpdate['gallery']) && is_array($productToUpdate['gallery'])) {
                foreach ($productToUpdate['gallery'] as $galUrl) {
                    if (!empty($productToUpdate['image']) && $galUrl === $productToUpdate['image']) continue;
                    $isLocal = (strpos($galUrl, 'uploads/') === 0);
                    $imagesList[] = [
                        'url' => $galUrl,
                        'source' => $isLocal ? 'manual' : 'site'
                    ];
                }
            }
            $productToUpdate['images_list'] = $imagesList;
            $productToUpdate['has_image'] = !empty($productToUpdate['image']) && strpos($productToUpdate['image'], 'placeholder') === false && strpos($productToUpdate['image'], 'placehold.co') === false;
            $productToUpdate['media_status'] = $productToUpdate['has_image'] ? 'ok' : 'missing';
            
            if (!empty($comment)) {
                if (!isset($productToUpdate['userFeedback'])) $productToUpdate['userFeedback'] = [];
                $productToUpdate['userFeedback'][] = [
                    'user' => 'Portal Approver (' . $current_user . ')', 
                    'text' => $comment, 
                    'at' => time(), 
                    'category' => 'positive'
                ];
            }
            
            if (!isset($project['logs'])) $project['logs'] = [];
            $project['logs'][] = [
                'at' => time(),
                'type' => 'تایید محتوا',
                'productName' => mb_substr($productToUpdate['name'] ?? 'محصول', 0, 100),
                'platform' => 'SYSTEM'
            ];
            
            saveProject($project, [$productToUpdate]);
            echo json_encode(['status' => 'success', 'msg' => 'محصول با موفقیت تایید نهایی شد.']);
            exit;
        }
        $response = ['status' => 'success', 'msg' => 'وضعیت محصول بروزرسانی شد'];

    
    } elseif ($_POST['action'] === 'feedback') {
        $feedbackText = trim($_POST['feedback']);
        if (empty($feedbackText)) {
            $response = ['status' => 'error', 'msg' => 'لطفاً متن بازخورد را وارد کنید'];
        } else {
            if (!isset($productToUpdate['userFeedback'])) $productToUpdate['userFeedback'] = [];
            
            // محدودیت برای هر بازدیدکننده: حداکثر ۳ بازخورد در هر محصول
            $userFeedbacks = array_filter($productToUpdate['userFeedback'], function($fb) use ($visitorId) {
                return ($fb['visitor'] ?? '') === $visitorId;
            });
            
            if (count($userFeedbacks) >= 3 && !isSuperAdmin()) {
                $response = ['status' => 'error', 'msg' => 'شما بیش از حد مجاز بازخورد ثبت کرده‌اید'];
            } else {
                $productToUpdate['userFeedback'][] = [
                    'visitor' => $visitorId,
                    'text' => htmlspecialchars($feedbackText),
                    'at' => time()
                ];
                saveProject($project, [$productToUpdate]);
                $response = ['status' => 'success', 'msg' => 'بازخورد شما با موفقیت ثبت شد'];
            }
        }
    } elseif ($_POST['action'] === 'approve') {
        $productToUpdate['status'] = 'active';
        saveProject($project, [$productToUpdate]);
        $response = ['status' => 'success', 'msg' => 'با موفقیت تایید شد'];
    } elseif ($_POST['action'] === 'reject') {
        // حذف محصول
        $filtered = [];
        foreach($project['products'] as $p) {
            if ($p['id'] != $productId) $filtered[] = $p;
        }
        $project['products'] = $filtered;
        saveProject($project);
        $response = ['status' => 'success', 'msg' => 'محصول حذف شد'];
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($project['name']); ?> | پورتال اختصاصی</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFullUrl('favicon.svg'); ?>">
    <link rel="alternate icon" href="<?php echo getFullUrl('favicon.ico'); ?>">
    <?php
    $jsBaseUrl = getFullUrl('dummy_file.png');
    $jsBaseUrl = substr($jsBaseUrl, 0, -strlen('dummy_file.png'));
    ?>
    <script>
        (function(){
            var origWarn = console.warn;
            console.warn = function() {
                var str = "";
                for (var i = 0; i < arguments.length; i++) {
                    if (arguments[i]) str += " " + String(arguments[i]);
                }
                if (str.indexOf('tailwindcss') !== -1 && (str.indexOf('production') !== -1 || str.indexOf('cdn.tailwindcss.com') !== -1)) {
                    return;
                }
                if (str.indexOf('cdn.tailwindcss.com') !== -1) {
                    return;
                }
                return origWarn.apply(console, arguments);
            };
        })();
        // Global Network 10s Timeout Guard
        (function() {
            const originalFetch = window.fetch;
            window.fetch = function(resource, init) {
                init = init || {};
                if (init.signal) {
                    return originalFetch.apply(window, arguments);
                }
                const controller = new AbortController();
                const timeoutMs = (typeof init.timeout === 'number') ? init.timeout : 10000;
                const timer = setTimeout(() => {
                    controller.abort(new Error('Network request timed out after ' + (timeoutMs/1000) + 's'));
                }, timeoutMs);

                const newInit = Object.assign({}, init, { signal: controller.signal });
                return originalFetch(resource, newInit).finally(() => {
                    clearTimeout(timer);
                });
            };
        })();

        window.APP_BASE_URL = <?php echo json_encode($jsBaseUrl); ?>;
    </script>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/tailwindcss.js"></script>
    <script>window.tailwind || document.write('<script src="https://cdn.tailwindcss.com"><\/script>')</script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Vazirmatn', 'Tahoma', 'sans-serif'],
                    },
                    colors: {
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
                    boxShadow: {
                        'glass': '0 8px 32px 0 rgba(31, 38, 135, 0.04)',
                        'premium': '0 20px 40px -15px rgba(0,0,0,0.05)',
                        'elevated': '0 25px 50px -12px rgba(0,0,0,0.08)',
                    }
                }
            }
        }
    </script>
    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn'), local('Vazir'), url('assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn-Bold'), local('Vazir-Bold'), url('assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
            font-weight: bold;
            font-style: normal;
            font-display: swap;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        html, body {
            max-width: 100vw;
            overflow-x: hidden;
            position: relative;
        }

        img, video, canvas, svg {
            max-width: 100%;
            height: auto;
        }

        body {
            font-family: 'Vazirmatn', 'Tahoma', sans-serif !important;
            background-color: #f8fafc;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
        }

        /* Custom scrollbars */
        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(124, 58, 237, 0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .pulse-active {
            animation: pulseGlow 2s infinite;
        }

        /* Standard custom classes */
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        /* Active votes */
        .active-like {
            background-color: #ecfdf5 !important;
            color: #059669 !important;
            border-color: #a7f3d0 !important;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15);
        }
        .active-dislike {
            background-color: #fff1f2 !important;
            color: #e11d48 !important;
            border-color: #fecdd3 !important;
            box-shadow: 0 10px 25px -5px rgba(244, 63, 94, 0.15);
        }

        /* Micro-interactions */
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 30px -10px rgba(0,0,0,0.06);
        }

        /* Toast container */
        .toast {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: white;
            padding: 0.85rem 2rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.875rem;
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            z-index: 9999;
            animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Modal classes */
        .p-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 4000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        /* Tab Visibility CSS Fix */
        .tab-content {
            display: none !important;
        }
        .tab-content.active {
            display: block !important;
        }
    </style>
</head>
<body>
<script>
    window.portalProducts = <?php 
        $jsProds = [];
        foreach (($project['products'] ?? []) as $prod) {
            $jsProds[$prod['id']] = $prod;
        }
        echo json_encode($jsProds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); 
    ?> || {};
    window.portalSchedules = {};
    window.projectSettings = <?php echo json_encode($project['settings'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    window.currentUser = <?php echo json_encode($_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'visitor', JSON_UNESCAPED_UNICODE); ?>;
    window.isSuperOrAdmin = <?php echo json_encode(isSuperAdmin() || isProjectAdmin()); ?>;
</script>

<div class="loader fixed inset-0 bg-slate-900/40 backdrop-blur-xl z-[9999] hidden items-center justify-center flex-col text-white" id="loader">
    <div class="relative w-20 h-20 mb-4">
        <div class="absolute inset-0 rounded-full border-4 border-white/20 border-t-brand-500 animate-spin"></div>
        <div class="absolute inset-0 flex items-center justify-center text-2xl">✨</div>
    </div>
    <div class="font-extrabold text-sm tracking-wide">درحال پردازش درخواست هوشمند...</div>
    <button type="button" onclick="showLoader(false)" class="mt-6 px-4 py-1.5 text-xs font-bold text-white/80 bg-white/10 hover:bg-white/20 border border-white/20 rounded-xl transition cursor-pointer">✕ بستن دستی لودر</button>
</div>

<!-- Floating Profile Button -->
<button onclick="document.getElementById('profile-modal').style.display='flex'" class="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 w-12 h-12 sm:w-14 sm:h-14 bg-brand-600 hover:bg-brand-700 text-white rounded-full shadow-elevated hover:scale-105 active:scale-95 transition-all z-50 overflow-hidden flex items-center justify-center border-2 sm:border-4 border-white pulse-active" aria-label="تنظیمات پروفایل">
    <?php if(!empty($_SESSION['user_icon'])): ?>
        <img src="<?php echo getFullUrl($_SESSION['user_icon']); ?>" class="w-full h-full object-cover">
    <?php else: ?>
        <span class="text-lg sm:text-xl">👤</span>
    <?php endif; ?>
</button>

<!-- Profile Modal Dialog -->
<div id="profile-modal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-md z-[6000] hidden items-center justify-center p-3 sm:p-4 animate-fade-in" onclick="if(event.target === this) this.style.display='none'">
    <div class="bg-white rounded-2xl sm:rounded-[2.25rem] w-full max-w-md shadow-elevated border border-slate-100 p-5 sm:p-8 transform transition-transform animate-fade-in max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex justify-between items-center mb-6">
            <h3 class="font-black text-xl text-slate-800 flex items-center gap-2">
                <span>👤</span> تنظیمات پروفایل من
            </h3>
            <button onclick="document.getElementById('profile-modal').style.display='none'" class="w-8 h-8 rounded-full bg-slate-50 hover:bg-slate-100 flex items-center justify-center text-slate-400 text-sm hover:text-slate-600 transition-all">✕</button>
        </div>
        
        <form id="profile-form" onsubmit="updateProfile(event)" class="space-y-6">
            <div class="flex flex-col items-center gap-3">
                <div class="w-24 h-24 rounded-full bg-slate-50 border-2 border-dashed border-slate-200 hover:border-brand-500 flex items-center justify-center overflow-hidden relative group cursor-pointer transition-all" onclick="document.getElementById('user_icon').click()">
                    <?php if(!empty($_SESSION['user_icon'])): ?>
                        <img id="icon-preview" src="<?php echo getFullUrl($_SESSION['user_icon']); ?>" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span id="icon-placeholder" class="text-3xl text-slate-300">📸</span>
                    <?php endif; ?>
                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex items-center justify-center text-white text-[10px] font-bold uppercase transition-all">تعویض آیکون</div>
                </div>
                <input type="file" id="user_icon" name="user_icon" class="hidden" onchange="previewIcon(this)" accept="image/*">
                <p class="text-[10px] text-slate-400 font-bold">برای تغییر فایل آیکون کلیک کنید</p>
            </div>
            
            <div class="space-y-2">
                <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest block">نام نمایشی یا سمت شما</label>
                <input type="text" name="display_name" value="<?php echo htmlspecialchars($_SESSION['display_name'] ?? ''); ?>" required class="w-full p-4 bg-slate-50 border border-slate-100 rounded-2xl text-sm font-bold focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none transition-all" placeholder="به عنوان مثال: سردبیر محتوا">
            </div>
            
            <button type="submit" class="w-full py-4 bg-brand-600 hover:bg-brand-700 text-white rounded-2xl shadow-lg shadow-brand-500/20 font-black text-sm transition-all hover:-translate-y-0.5 active:translate-y-0">💾 ذخیره تغییرات پروفایل</button>
        </form>
    </div>
</div>

<header class="sticky top-0 z-40 bg-white/90 backdrop-blur-xl border-b border-slate-100 px-3 sm:px-6 py-2.5 sm:py-4 shadow-sm">
    <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:justify-between md:items-center gap-3">
        <div class="flex items-center justify-between md:justify-start gap-3 w-full md:w-auto">
            <div class="flex items-center gap-2.5 sm:gap-3">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-brand-500 text-white flex items-center justify-center text-xl sm:text-2xl font-black shadow-md shadow-brand-500/20 shrink-0">🚀</div>
                <div>
                    <h1 class="font-extrabold text-base sm:text-lg text-slate-800 leading-tight line-clamp-1"><?php echo htmlspecialchars($project['name']); ?></h1>
                    <p class="text-[9px] sm:text-[10px] text-brand-600 font-extrabold tracking-widest uppercase">Maarksho Social Portal v3</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <?php if (count($user_allowed_projects) > 1): ?>
                    <div>
                        <select onchange="window.location.href='?project=' + this.value" class="px-2.5 py-1.5 sm:px-3 sm:py-2 bg-slate-50 border border-slate-200/60 rounded-xl text-[10px] font-black text-slate-700 outline-none cursor-pointer focus:border-brand-500 max-w-[140px] sm:max-w-none truncate">
                            <?php foreach($user_allowed_projects as $p_item): ?>
                                <option value="<?php echo htmlspecialchars($p_item['id']); ?>" <?php echo $p_item['id'] === $project['id'] ? 'selected' : ''; ?>>
                                    📁 <?php echo htmlspecialchars($p_item['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Mobile User Badge -->
                <div class="flex md:hidden items-center gap-1 px-2.5 py-1.5 bg-slate-50 border border-slate-100/80 rounded-xl text-[10px] font-black text-slate-600 max-w-[110px] truncate">
                    <span class="text-xs">👤</span>
                    <span class="truncate"><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['user']['username'] ?? 'کاربر'); ?></span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap md:flex-nowrap justify-start sm:justify-end w-full md:w-auto overflow-x-auto pb-0.5 custom-scrollbar">
            <!-- Desktop Username/Display Name Badge -->
            <div class="hidden md:flex items-center gap-2 px-3.5 py-2 bg-slate-50 border border-slate-100/80 rounded-xl text-[11px] font-black text-slate-600 shrink-0">
                <span class="text-xs">👤</span>
                <span><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['user']['username'] ?? 'کاربر مهمان'); ?></span>
            </div>

            <?php if (canAccessManagement()): ?>
                <a href="admin.php" class="px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 text-xs font-bold">پنل مدیریت</a>
            <?php endif; ?>
            <?php if(isSuperAdmin() || isProjectAdmin()): ?>
                <a href="sale.php<?php echo isSuperAdmin() ? '?project='.$project['id'] : ''; ?>" class="px-2.5 sm:px-4 py-1.5 sm:py-2.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-100 text-indigo-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-1.5 shadow-sm shadow-indigo-500/5 shrink-0" title="تحلیل فروش و شعب">
                    <span>💰</span>
                    <span class="hidden sm:inline">تحلیل فروش و شعب</span>
                    <span class="sm:hidden">فروش</span>
                </a>
                <a href="seo.php<?php echo isSuperAdmin() ? '?project='.$project['id'] : ''; ?>" class="px-2.5 sm:px-4 py-1.5 sm:py-2.5 bg-sky-50 hover:bg-sky-100 border border-sky-100 text-sky-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-1.5 shadow-sm shadow-sky-500/5 shrink-0" title="آنالیز و سئو">
                    <span>🔍</span>
                    <span class="hidden sm:inline">آنالیز و سئو</span>
                    <span class="sm:hidden">سئو</span>
                </a>
                <a href="admin.php" class="px-2.5 sm:px-5 py-1.5 sm:py-2.5 bg-slate-50 hover:bg-brand-50 border border-slate-100 hover:border-brand-200 text-slate-600 hover:text-brand-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-2 shrink-0" title="مدیریت">
                    <span>⚙️</span>
                    <span class="hidden sm:inline">مدیریت</span>
                </a>
            <?php endif; ?>

            <!-- Internal User Communication Chat -->
            <a href="user_chat.php" class="px-2.5 sm:px-4 py-1.5 sm:py-2.5 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-1.5 shadow-sm shadow-amber-500/5 shrink-0" title="سیستم گفتگوی داخلی همکاران">
                <span>💬</span>
                <span class="hidden sm:inline">گفتگوی داخلی</span>
                <span class="sm:hidden">چت</span>
            </a>

            <!-- Central Task Manager -->
            <a href="task_manager.php?project=<?php echo urlencode($project['id']); ?>" class="px-2.5 sm:px-4 py-1.5 sm:py-2.5 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-1.5 shadow-sm shadow-emerald-500/5 shrink-0" title="سیستم مدیریت تسک و زمان‌بندی همکاران">
                <span>📋</span>
                <span class="hidden sm:inline">مدیریت تسک‌ها</span>
                <span class="sm:hidden">تسک‌ها</span>
            </a>

            <!-- Logout Button -->
            <a href="logout.php" class="px-2.5 sm:px-5 py-1.5 sm:py-2.5 bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 hover:text-rose-700 text-[11px] sm:text-xs font-black rounded-xl transition-all flex items-center gap-1 sm:gap-1.5 shadow-sm shadow-rose-500/5 shrink-0" title="خروج از حساب کاربری">
                <span>🚪</span>
                <span class="hidden sm:inline">خروج از حساب</span>
                <span class="sm:hidden">خروج</span>
            </a>
        </div>
    </div>
</header>

<?php 
$storageInfo = getProjectStorageUsage($project);
if ($storageInfo['is_warning'] || $storageInfo['is_full']):
?>
    <div class="bg-gradient-to-r <?php echo $storageInfo['is_full'] ? 'from-rose-600 via-rose-700 to-red-800' : 'from-amber-500 via-amber-600 to-orange-600'; ?> text-white px-4 sm:px-6 py-3 shadow-md flex flex-wrap items-center justify-between gap-3 border-b border-black/10">
        <div class="flex items-center gap-3 text-xs font-bold">
            <span class="text-2xl animate-bounce shrink-0"><?php echo $storageInfo['is_full'] ? '🚨' : '⚠️'; ?></span>
            <div>
                <span class="font-black text-xs sm:text-sm"><?php echo $storageInfo['is_full'] ? 'هشدار تکمیل فضای ذخیره‌سازی پروژه!' : 'هشدار نزدیک شدن به سقف حجم مجاز!'; ?></span>
                <span class="block text-[10px] sm:text-[11px] opacity-90 font-medium">
                    میزان حجم اشغال‌شده: <strong class="font-mono"><?php echo $storageInfo['formatted_used']; ?></strong> از <strong class="font-mono"><?php echo $storageInfo['formatted_quota']; ?></strong> (<?php echo $storageInfo['usage_percent']; ?>٪)
                </span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="about.php#pricing" target="_blank" class="px-4 py-2 sm:px-5 sm:py-2.5 bg-white hover:bg-slate-100 text-slate-900 rounded-xl text-xs font-black shadow-lg transition-all hover:scale-105 active:scale-95">
                💎 ارتقای فضا
            </a>
        </div>
    </div>
<?php endif; ?>

<main id="portal-main-container" class="max-w-7xl mx-auto px-3 sm:px-6 py-4 sm:py-8">
<?php
// ============================================
// محاسبه آمار پیشرفته و شخصی‌سازی شده کاربر بر اساس نقش‌ها و عملکردها
// ============================================
$user_lo = strtolower($_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'visitor');

// ۱. شناسایی نقش‌های ناظر تایید تصاویر و تقویم
$prod_s1_raw = $project['settings']['prod_approval_stage1_users'] ?? '';
$prod_s1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($prod_s1_raw))));
$prod_s2_raw = $project['settings']['prod_approval_stage2_users'] ?? '';
$prod_s2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($prod_s2_raw))));

$is_prod_s1 = in_array($user_lo, $prod_s1_users);
$is_prod_s2 = in_array($user_lo, $prod_s2_users);
$is_prod_approver = $is_prod_s1 || $is_prod_s2;

$cal_s1_raw = $project['settings']['cal_approval_stage1_users'] ?? '';
$cal_s1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($cal_s1_raw))));
$cal_s2_raw = $project['settings']['cal_approval_stage2_users'] ?? '';
$cal_s2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($cal_s2_raw))));

$is_cal_s1 = in_array($user_lo, $cal_s1_users);
$is_cal_s2 = in_array($user_lo, $cal_s2_users);
$is_cal_approver = $is_cal_s1 || $is_cal_s2;

// الف) محاسبه آمار تایید تصاویر محصول توسط این کاربر
$my_prod_approved_count = 0;
$my_prod_rejected_count = 0;
$my_prod_pending_count = 0;

foreach (($project['products'] ?? []) as $p) {
    $isNoImg = is_product_without_image($p);
    if ($isNoImg) continue; 

    $app1 = is_array($p['approvals_stage1'] ?? null) ? $p['approvals_stage1'] : json_decode($p['approvals_stage1'] ?? '[]', true);
    if (!is_array($app1)) $app1 = [];
    $app1_lo = array_map('strtolower', $app1);

    $app2 = is_array($p['approvals_stage2'] ?? null) ? $p['approvals_stage2'] : json_decode($p['approvals_stage2'] ?? '[]', true);
    if (!is_array($app2)) $app2 = [];
    $app2_lo = array_map('strtolower', $app2);

    $voted_approved = false;
    if ($is_prod_s1 && in_array($user_lo, $app1_lo)) {
        $voted_approved = true;
    }
    if ($is_prod_s2 && in_array($user_lo, $app2_lo)) {
        $voted_approved = true;
    }

    if ($voted_approved) {
        $my_prod_approved_count++;
    }

    if (strtolower($p['rejection_by'] ?? '') === $user_lo) {
        $my_prod_rejected_count++;
    }

    if (($p['status'] ?? '') === 'pending') {
        if ($is_prod_s1 && !in_array($user_lo, $app1_lo) && ($p['stage1_decision'] ?? '') !== 'rejected') {
            $my_prod_pending_count++;
        } elseif ($is_prod_s2 && ($p['stage1_decision'] ?? '') === 'approved' && !in_array($user_lo, $app2_lo) && ($p['stage2_decision'] ?? '') !== 'rejected') {
            $my_prod_pending_count++;
        }
    }
}
$prod_total_decided = $my_prod_approved_count + $my_prod_rejected_count;
$prod_percentage = $prod_total_decided > 0 ? round(($my_prod_approved_count / $prod_total_decided) * 100) : 100;

// ب) محاسبه آمار تایید برنامه‌های تقویم توسط این کاربر
$my_cal_approved_count = 0;
$my_cal_rejected_count = 0;
$my_cal_pending_count = 0;

foreach (($project['schedules'] ?? []) as $s) {
    $app1 = is_array($s['approvals_stage1'] ?? null) ? $s['approvals_stage1'] : json_decode($s['approvals_stage1'] ?? '[]', true);
    if (!is_array($app1)) $app1 = [];
    $app1_lo = array_map('strtolower', $app1);

    $app2 = is_array($s['approvals_stage2'] ?? null) ? $s['approvals_stage2'] : json_decode($s['approvals_stage2'] ?? '[]', true);
    if (!is_array($app2)) $app2 = [];
    $app2_lo = array_map('strtolower', $app2);

    $voted_approved = false;
    if ($is_cal_s1 && in_array($user_lo, $app1_lo)) {
        $voted_approved = true;
    }
    if ($is_cal_s2 && in_array($user_lo, $app2_lo)) {
        $voted_approved = true;
    }

    if ($voted_approved) {
        $my_cal_approved_count++;
    }

    if (strtolower($s['rejection_by'] ?? '') === $user_lo) {
        $my_cal_rejected_count++;
    }

    $ast = $s['approvalStatus'] ?? 'pending';
    if ($ast === 'pending') {
        if ($is_cal_s1 && !in_array($user_lo, $app1_lo) && ($s['stage1_decision'] ?? '') !== 'rejected') {
            $my_cal_pending_count++;
        } elseif ($is_cal_s2 && ($s['stage1_decision'] ?? '') === 'approved' && !in_array($user_lo, $app2_lo) && ($s['stage2_decision'] ?? '') !== 'rejected') {
            $my_cal_pending_count++;
        }
    }
}
$cal_total_decided = $my_cal_approved_count + $my_cal_rejected_count;
$cal_percentage = $cal_total_decided > 0 ? round(($my_cal_approved_count / $cal_total_decided) * 100) : 100;


// ۲. بارگزاری آمار اختصاصی تصاویر آپلود دستی شده توسط خود کاربر
$user_manually_uploaded = [];
$man_images_count = 0;
foreach (($project['products'] ?? []) as $p) {
    if (strtolower($p['uploaded_by'] ?? '') === $user_lo) {
        $user_manually_uploaded[] = $p;
        // Count images uploaded
        if (!empty($p['image']) && strpos($p['image'], 'placeholder') === false) {
            $man_images_count++;
        }
        if (!empty($p['gallery']) && is_array($p['gallery'])) {
            foreach ($p['gallery'] as $galImg) {
                if (!empty($galImg) && strpos($galImg, 'placeholder') === false) {
                    $man_images_count++;
                }
            }
        }
    }
}
$show_manual_stats = !empty($user_manually_uploaded) || isSuperAdmin() || isProjectAdmin();

$man_total_count = count($user_manually_uploaded);
$man_approved_count = 0;
$man_rejected_count = 0;
$man_pending_count = 0;

foreach ($user_manually_uploaded as $p) {
    $st = $p['status'] ?? '';
    // Let's count as rejected if status is explicitly 'rejected' or any of stage decisions are 'rejected'
    $st_rej = ($st === 'rejected' || ($p['stage1_decision'] ?? '') === 'rejected' || ($p['stage2_decision'] ?? '') === 'rejected');
    
    if ($st === 'active' || (empty($st) && !$st_rej && !empty($p['image']) && strpos($p['image'], 'placeholder') === false)) {
        $man_approved_count++;
    } elseif ($st_rej) {
        $man_rejected_count++;
    } elseif ($st === 'pending') {
        $man_pending_count++;
    } else {
        // Fallback or missing details
        $man_pending_count++;
    }
}
$man_decided_total = $man_approved_count + $man_rejected_count;
$man_percentage = $man_decided_total > 0 ? round(($man_approved_count / $man_decided_total) * 100) : 100;


// ۳. بارگزاری عملکرد زمان‌بندی محتوای کاربر (Calendar Publishers)
$user_scheduled_posts = [];
foreach (($project['schedules'] ?? []) as $s) {
    $cBy = strtolower($s['createdBy'] ?? '');
    if ($cBy === $user_lo || ($user_lo === 'admin' && empty($cBy))) {
        $user_scheduled_posts[] = $s;
    }
}
$show_publisher_stats = !empty($user_scheduled_posts) || isSuperAdmin() || isProjectAdmin();

$pub_total_count = count($user_scheduled_posts);
$pub_approved_count = 0;
$pub_rejected_count = 0;
$pub_pending_count = 0;

foreach ($user_scheduled_posts as $s) {
    $ast = $s['approvalStatus'] ?? 'pending';
    if ($ast === 'approved') {
        $pub_approved_count++;
    } elseif ($ast === 'rejected' || ($s['stage1_decision'] ?? '') === 'rejected' || ($s['stage2_decision'] ?? '') === 'rejected') {
        $pub_rejected_count++;
    } elseif ($ast === 'pending') {
        $pub_pending_count++;
    }
}
$pub_decided_total = $pub_approved_count + $pub_rejected_count;
$pub_percentage = $pub_decided_total > 0 ? round(($pub_approved_count / $pub_decided_total) * 100) : 100;
?>

<!-- Collapsible Premium Integrated Statistics Dashboard -->
<div class="mb-6 sm:mb-8 font-serif" dir="rtl">
    <button onclick="togglePortalStatsDashboard(this)" class="w-full bg-white border border-slate-100 hover:border-indigo-100 hover:shadow-md p-3.5 sm:p-5 rounded-2xl sm:rounded-[2rem] flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm cursor-pointer transition-all duration-300 text-right">
        <div class="flex items-center gap-3 sm:gap-4">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-indigo-50 text-indigo-600 rounded-xl sm:rounded-2xl flex items-center justify-center text-lg sm:text-xl shadow-inner shrink-0">📊</div>
            <div>
                <span class="font-black text-xs sm:text-sm text-slate-800 block">مشاهده داشبورد هوشمند و آمار عملکرد شما</span>
                <span class="text-[9px] sm:text-[10px] text-slate-400 font-extrabold tracking-wider block mt-0.5">جهت ارزیابی میزان فعالیت، نسبت تایید کالاها و جزییات برنامه‌ها کلیک کنید</span>
            </div>
        </div>
        <div class="flex items-center justify-between sm:justify-end gap-2.5 w-full sm:w-auto pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-50">
            <span class="text-[9px] sm:text-[10px] font-black bg-indigo-50 text-indigo-600 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-xl border border-indigo-100/30">نمایش و بررسی عملکرد</span>
            <span id="portal-stats-arrow" class="text-xs text-slate-400 transition-transform duration-300 inline-block font-bold">▼</span>
        </div>
    </button>
    
    <div id="portal-stats-content" class="hidden mt-4 sm:mt-6 animate-in fade-in slide-in-from-top-2 duration-300">
        <div class="grid grid-cols-1 <?php echo ($is_prod_approver || $is_cal_approver) ? 'lg:grid-cols-3' : 'md:grid-cols-2'; ?> gap-4 sm:gap-6 text-right">
            
            <!-- Card A: Verification Desktop (For Approvers with appropriate access roles) -->
            <?php if ($is_prod_approver || $is_cal_approver): ?>
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm relative overflow-hidden flex flex-col justify-between">
                <div class="absolute top-0 left-0 w-32 h-32 bg-violet-50 rounded-full -translate-x-12 -translate-y-12 opacity-40"></div>
                <div class="relative z-10 font-sans">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center text-lg">⚖️</div>
                        <div>
                            <h4 class="font-black text-xs text-slate-800">میز مانیتورینگ عمومی ناظـر</h4>
                            <p class="text-[9px] text-slate-400 font-extrabold tracking-wider">اقدامات نظارتی و داوری شما</p>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <!-- Image approver status -->
                        <?php if ($is_prod_approver): ?>
                        <div class="p-3 bg-violet-50/50 rounded-2xl border border-violet-100/50">
                            <div class="flex justify-between items-center text-[10px] font-black text-violet-800 mb-2">
                                <span>🖼️ نظارت تصاویر کالاها</span>
                                <span class="bg-violet-200 px-1.5 py-0.5 rounded text-[9px]">درصد تایید: <?php echo $prod_percentage; ?>٪</span>
                            </div>
                            <div class="grid grid-cols-3 gap-1.5 text-center">
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">تایید شده</span>
                                    <span class="text-xs font-black text-emerald-600"><?php echo $my_prod_approved_count; ?></span>
                                </div>
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">رد شده</span>
                                    <span class="text-xs font-black text-rose-500"><?php echo $my_prod_rejected_count; ?></span>
                                </div>
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">منـتظر داوری</span>
                                    <span class="text-xs font-black text-amber-500 animate-pulse"><?php echo $my_prod_pending_count; ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Calendar Approver status -->
                        <?php if ($is_cal_approver): ?>
                        <div class="p-3 bg-fuchsia-50/55 rounded-2xl border border-fuchsia-100/50">
                            <div class="flex justify-between items-center text-[10px] font-black text-fuchsia-800 mb-2">
                                <span>📅 ممیزی چیدمان محتوایی</span>
                                <span class="bg-fuchsia-200 px-1.5 py-0.5 rounded text-[9px]">درصد تایید: <?php echo $cal_percentage; ?>٪</span>
                            </div>
                            <div class="grid grid-cols-3 gap-1.5 text-center">
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">تایید شده</span>
                                    <span class="text-xs font-black text-emerald-600"><?php echo $my_cal_approved_count; ?></span>
                                </div>
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">رد شده</span>
                                    <span class="text-xs font-black text-rose-500"><?php echo $my_cal_rejected_count; ?></span>
                                </div>
                                <div class="bg-white/80 p-1.5 rounded-lg border border-slate-100">
                                    <span class="block text-[8px] text-slate-400 font-extrabold">درانتظار داوری</span>
                                    <span class="text-xs font-black text-amber-500 animate-pulse"><?php echo $my_cal_pending_count; ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card B: Manual Upload Performance Statistics (For anyone uploading images) -->
            <?php if ($show_manual_stats): ?>
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm relative overflow-hidden flex flex-col justify-between">
                <div class="absolute top-0 left-0 w-32 h-32 bg-amber-50 rounded-full -translate-x-12 -translate-y-12 opacity-50"></div>
                <div class="relative z-10 flex-1 flex flex-col justify-between font-sans">
                    <div>
                        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg">📸</div>
                                <div>
                                    <h4 class="font-black text-xs text-slate-800">عملکرد ثبت دستی تصاویر شما</h4>
                                    <p class="text-[9px] text-slate-400 font-extrabold tracking-wider">آمارهای پایش تصاویر شما در سیستم</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5 justify-end">
                                <span class="text-[9px] font-black bg-slate-100 text-slate-650 px-2 py-1 rounded-lg border border-slate-200/50">تعداد کالاها: <?php echo $man_total_count; ?></span>
                                <span class="text-[9px] font-black bg-amber-50 border border-amber-200 text-amber-700 px-2 py-1 rounded-lg">کل عکس‌های آپلود شده: <?php echo $man_images_count; ?></span>
                            </div>
                        </div>

                        <div class="grid grid-cols-4 gap-2 text-center mb-5">
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">درحال بررسی</span>
                                <span class="text-xs font-black text-amber-500"><?php echo $man_pending_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">تایید شده</span>
                                <span class="text-xs font-black text-emerald-500">✓ <?php echo $man_approved_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">رد شده</span>
                                <span class="text-xs font-black text-rose-500">✕ <?php echo $man_rejected_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">درصد تایید</span>
                                <span class="text-xs font-black text-slate-700"><?php echo $man_percentage; ?>٪</span>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Premium Progress Bar -->
                    <div>
                        <div class="flex justify-between items-center text-[8px] font-extrabold text-slate-400 uppercase tracking-widest mb-1.5">
                            <span>نسبت پذیرش نهایی تصویر</span>
                            <span><?php echo $man_percentage; ?>٪</span>
                        </div>
                        <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-amber-400 to-teal-500 transition-all duration-500" style="width: <?php echo $man_percentage; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card C: Content Calendar Scheduler Performance (For content publishers) -->
            <?php if ($show_publisher_stats): ?>
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm relative overflow-hidden flex flex-col justify-between">
                <div class="absolute top-0 left-0 w-32 h-32 bg-sky-50 rounded-full -translate-x-12 -translate-y-12 opacity-50"></div>
                <div class="relative z-10 flex-1 flex flex-col justify-between font-sans">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 flex items-center justify-center text-lg">🗓️</div>
                                <div>
                                    <h4 class="font-black text-xs text-slate-800">داشبورد زمان‌بندی و انتشار شما</h4>
                                    <p class="text-[9px] text-slate-400 font-extrabold tracking-wider">وضعیت برنامه‌های زمان‌بندی شده شما در تقویم</p>
                                </div>
                            </div>
                            <span class="text-[10px] font-black bg-slate-100 text-slate-600 px-2.5 py-1 rounded-full">درج شده: <?php echo $pub_total_count; ?></span>
                        </div>

                        <div class="grid grid-cols-4 gap-2 text-center mb-5">
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">درانتظار تایید</span>
                                <span class="text-xs font-black text-sky-500"><?php echo $pub_pending_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">تایید شده</span>
                                <span class="text-xs font-black text-emerald-500">✓ <?php echo $pub_approved_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">رد شده</span>
                                <span class="text-xs font-black text-rose-500">✕ <?php echo $pub_rejected_count; ?></span>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100">
                                <span class="block text-[8px] text-slate-400 font-extrabold">درصد تایید</span>
                                <span class="text-xs font-black text-slate-700"><?php echo $pub_percentage; ?>٪</span>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Premium Progress Bar -->
                    <div>
                        <div class="flex justify-between items-center text-[8px] font-extrabold text-slate-400 uppercase tracking-widest mb-1.5">
                            <span>نسبت تایید برنامه‌های محتوا</span>
                            <span><?php echo $pub_percentage; ?>٪</span>
                        </div>
                        <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-sky-400 to-indigo-500 transition-all duration-500" style="width: <?php echo $pub_percentage; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePortalStatsDashboard(btn) {
    const content = document.getElementById('portal-stats-content');
    const arrow = document.getElementById('portal-stats-arrow');
    if (content && arrow) {
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            arrow.style.transform = 'rotate(180deg)';
        } else {
            content.classList.add('hidden');
            arrow.style.transform = 'rotate(0deg)';
        }
    }
}
</script>

<?php
// ============================================
// فیلترهای متقاطع پیشرفته (Advanced Faceted Search)
// ============================================
$f_q = trim($_GET['f_q'] ?? '');
$f_cat = trim($_GET['f_cat'] ?? '');
$f_price = trim($_GET['f_price'] ?? '');
$f_stock = trim($_GET['f_stock'] ?? '');
$f_stock_min = isset($_GET['f_stock_min']) && is_numeric($_GET['f_stock_min']) ? max(1, (int)$_GET['f_stock_min']) : 5;
$f_likes = (int)($_GET['f_likes'] ?? 0);
$f_creator = trim($_GET['f_creator'] ?? '');
$f_sort = trim($_GET['f_sort'] ?? '');

// داده‌های خام کامل جهت گزارشات وضعیت و آمار جامع
$raw_all_project_products = (isset($project['products']) && is_array($project['products'])) ? $project['products'] : [];
$familyStockMap = buildProductFamilyStockMap($raw_all_project_products);

$catalogAnalyticsData = [];
$all_categories = [];
$all_creators = [];

$count_pending = 0;
$count_library = 0;
$count_out_of_stock = 0;
$count_rejected = 0;
$count_no_image = 0;
$isProdSplit = !empty($project['settings']['prod_approval_split_enabled']);

foreach ($raw_all_project_products as $_p) {
    $catName = trim($_p['color'] ?? '');
    if ($catName !== '' && !in_array($catName, $all_categories, true)) {
        $all_categories[] = $catName;
    }
    $creatorName = trim($_p['uploaded_by'] ?? '');
    if ($creatorName !== '' && !in_array($creatorName, $all_creators, true)) {
        $all_creators[] = $creatorName;
    }

    $pSt = $_p['status'] ?? 'active';
    if (empty($pSt)) $pSt = 'active';
    
    $isRej = ($pSt === 'rejected');
    if ($isProdSplit && !$isRej) {
        $st1_rej = !empty($_p['stage1_decision']) && $_p['stage1_decision'] === 'rejected';
        $st2_rej = !empty($_p['stage2_decision']) && $_p['stage2_decision'] === 'rejected';
        if ($st1_rej || $st2_rej) $isRej = true;
    }

    $isOut = isProductOutOfStock($_p);
    $isNoImg = is_product_without_image($_p);

    if ($isRej) {
        $count_rejected++;
    } elseif ($isOut) {
        $count_out_of_stock++;
    } elseif ($isNoImg) {
        $count_no_image++;
    } elseif ($pSt === 'pending') {
        $count_pending++;
    } else {
        $count_library++;
    }

    if (($active_tab ?? 'overview') === 'library') {
        $itemStock = getProductItemStock($_p);
        $aggStock = getProductAggregatedStock($_p, $familyStockMap);
        $pPriceStr = trim($_p['price'] ?? '');
        $pPriceNum = (float)preg_replace('/[^0-9]/', '', $pPriceStr);
        $pImg = getPrioritizedProductImage($_p);
        if (!empty($pImg) && strpos($pImg, 'http') !== 0) {
            $pImg = getFullUrl($pImg);
        }
        $hasValidImg = isValidProductImage($pImg);
        $galCount = 0;
        if (!empty($_p['gallery'])) {
            $gal = is_string($_p['gallery']) ? json_decode($_p['gallery'], true) : $_p['gallery'];
            if (is_array($gal)) $galCount += count($gal);
        }
        if (!empty($_p['images_list'])) {
            $imgList = is_string($_p['images_list']) ? json_decode($_p['images_list'], true) : $_p['images_list'];
            if (is_array($imgList)) $galCount += count($imgList);
        }
        $variantsCount = isset($_p['variants']) && is_array($_p['variants']) ? count($_p['variants']) : 1;

        $catalogAnalyticsData[] = [
            'id' => (string)($_p['id'] ?? ''),
            'name' => (string)($_p['name'] ?? ''),
            'sku' => (string)($_p['sku'] ?? ''),
            'status' => $pSt,
            'is_out' => $isOut ? 1 : 0,
            'is_no_img' => $isNoImg ? 1 : 0,
            'has_img' => $hasValidImg ? 1 : 0,
            'stock' => (int)$itemStock,
            'agg_stock' => (int)$aggStock,
            'price' => $pPriceStr,
            'price_num' => $pPriceNum,
            'color' => (string)($_p['color'] ?? ''),
            'creator' => (string)($_p['uploaded_by'] ?? ''),
            'image' => $pImg,
            'gallery_count' => $galCount,
            'variants_count' => $variantsCount,
            'likes' => (int)($_p['likes'] ?? 0),
            'dislikes' => (int)($_p['dislikes'] ?? 0)
        ];
    }
}

sort($all_categories);
sort($all_creators);

// اعمال فیلتر هوشمند متقاطع بر روی کالاها
if (!empty($raw_all_project_products)) {
    $project['products'] = array_filter($raw_all_project_products, function($p) use ($f_q, $f_cat, $f_price, $f_stock, $f_stock_min, $f_likes, $f_creator, $familyStockMap) {
        // ۱. فیلتر متنی (جستجوی مستقل یا تلفیقی از عنوان، SKU، رنگ، دسته‌بندی و ثبت‌کننده)
        if ($f_q !== '') {
            $q = normalizeSearchText($f_q);
            $nameNorm = normalizeSearchText($p['name'] ?? '');
            $skuNorm = normalizeSearchText($p['sku'] ?? '');
            $colorNorm = normalizeSearchText($p['color'] ?? '');
            $catNorm = normalizeSearchText($p['category_path'] ?? '');
            $creatorNorm = normalizeSearchText($p['uploaded_by'] ?? '');
            
            $nameMatch = (mb_strpos($nameNorm, $q) !== false);
            $skuMatch = (mb_strpos($skuNorm, $q) !== false);
            $colorMatch = (mb_strpos($colorNorm, $q) !== false);
            $catMatch = (mb_strpos($catNorm, $q) !== false);
            $creatorMatch = (mb_strpos($creatorNorm, $q) !== false);
            if (!$nameMatch && !$skuMatch && !$colorMatch && !$catMatch && !$creatorMatch) return false;
        }
        // ۲. فیلتر دسته‌بندی
        if ($f_cat !== '' && ($p['color'] ?? '') !== $f_cat) {
            return false;
        }
        // ۳. فیلتر لایک‌ها
        if ($f_likes > 0 && ($p['likes'] ?? 0) < $f_likes) {
            return false;
        }
        // ۴. فیلتر موجودی انبار و وضعیت تصویر
        if ($f_stock !== '') {
            if ($f_stock === 'stock_gte_5' || $f_stock === 'stock_agg_gte') {
                $aggStock = getProductAggregatedStock($p, $familyStockMap);
                if ($aggStock < $f_stock_min) {
                    return false;
                }
            } elseif ($f_stock === 'stock_direct_gte') {
                $dirStock = getProductItemStock($p);
                if ($dirStock < $f_stock_min) {
                    return false;
                }
            } elseif ($f_stock === 'stock_low') {
                $dirStock = getProductItemStock($p);
                if ($dirStock < 1 || $dirStock >= $f_stock_min) {
                    return false;
                }
            } elseif ($f_stock === 'in_stock') {
                if (isProductOutOfStock($p)) {
                    return false;
                }
            } elseif ($f_stock === 'out_of_stock') {
                if (!isProductOutOfStock($p)) {
                    return false;
                }
            } elseif ($f_stock === 'no_image') {
                if (!is_product_without_image($p)) {
                    return false;
                }
            } elseif ($f_stock === 'waiting_ref') {
                if (!is_product_without_image($p) || hasProductReferenceImages($p)) {
                    return false;
                }
            } elseif ($f_stock === 'has_ref') {
                if (!hasProductReferenceImages($p)) {
                    return false;
                }
            }
        }
        // ۵. فیلتر قیمت
        if ($f_price !== '') {
            $priceStr = trim($p['price'] ?? '');
            $cleanPrice = (float)preg_replace('/[^0-9]/', '', $priceStr);
            if ($f_price === 'under_1m' && $cleanPrice >= 1000000) return false;
            if ($f_price === '1-2m' && ($cleanPrice < 1000000 || $cleanPrice > 2000000)) return false;
            if ($f_price === '2-3m' && ($cleanPrice < 2000000 || $cleanPrice > 3000000)) return false;
            if ($f_price === '3m_plus' && $cleanPrice < 3000000) return false;
        }
        // ۶. فیلتر تولیدکننده تصویر (آپلود دستی)
        if ($f_creator !== '') {
            $prodCreator = trim($p['uploaded_by'] ?? '');
            if ($prodCreator !== $f_creator) return false;
        }
        return true;
    });
}
?>
<script>
window.allCatalogProducts = <?php echo json_encode($catalogAnalyticsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

    <!-- Faceted Search Filters card (Collapsible for Clean UX) -->
    <div class="bg-white border border-slate-100 rounded-3xl shadow-sm mb-8 overflow-hidden" onclick="event.stopPropagation();">
        <button onclick="toggleFilterAccordion()" class="w-full p-4 px-6 bg-slate-50/70 hover:bg-slate-100/80 flex items-center justify-between text-right cursor-pointer transition-all">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-base font-bold">🔍</span>
                <div>
                    <span class="font-black text-xs text-slate-800 block">فیلترهای پیشرفته و جستجوی متقاطع</span>
                    <span class="text-[10px] text-slate-400 font-bold block">جستجو بر اساس کد، رنگ، دسته، قیمت، لایک و پرسنل</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!empty($f_q) || !empty($f_cat) || !empty($f_price) || !empty($f_stock) || !empty($f_creator)): ?>
                    <span class="px-2.5 py-1 rounded-full bg-brand-500 text-white text-[10px] font-black animate-pulse">فیلتر فعال</span>
                <?php endif; ?>
                <span id="filter-accordion-arrow" class="text-xs text-slate-400 font-bold transition-transform transform <?php echo (!empty($f_q) || !empty($f_cat) || !empty($f_price) || !empty($f_stock) || !empty($f_creator)) ? 'rotate-180' : ''; ?>">▼</span>
            </div>
        </button>
        <div id="filter-accordion-content" class="<?php echo (!empty($f_q) || !empty($f_cat) || !empty($f_price) || !empty($f_stock) || !empty($f_creator)) ? '' : 'hidden'; ?> p-4 sm:p-6 border-t border-slate-100 bg-slate-50/30">
            <form id="filter-form" method="GET" action="portal.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-8 gap-3 sm:gap-4 items-end">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab ?? 'overview'); ?>">
                <input type="hidden" name="p" value="1">
                
                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">جستجوی عنوان / SKU</label>
                    <input type="text" name="f_q" value="<?php echo htmlspecialchars($f_q); ?>" placeholder="کلمه کلیدی یا کد..." class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">دسته بندی/رنگ کالا</label>
                    <select name="f_cat" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="">همه رنگ‌ها / دسته‌ها</option>
                        <?php foreach ($all_categories as $col): ?>
                            <option value="<?php echo htmlspecialchars($col); ?>" <?php echo $f_cat === $col ? 'selected' : ''; ?>><?php echo htmlspecialchars($col); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">محدوده قیمت</label>
                    <select name="f_price" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="">همه قیمت‌ها</option>
                        <option value="under_1m" <?php echo $f_price === 'under_1m' ? 'selected' : ''; ?>>زیر ۱ میلیون</option>
                        <option value="1-2m" <?php echo $f_price === '1-2m' ? 'selected' : ''; ?>>۱ تا ۲ میلیون</option>
                        <option value="2-3m" <?php echo $f_price === '2-3m' ? 'selected' : ''; ?>>۲ تا ۳ میلیون</option>
                        <option value="3m_plus" <?php echo $f_price === '3m_plus' ? 'selected' : ''; ?>>بالای ۳ میلیون</option>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">وضعیت موجودی و تصویر</label>
                    <select name="f_stock" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="">همه محصولات</option>
                        <option value="no_image" <?php echo $f_stock === 'no_image' ? 'selected' : ''; ?>>📷 بدون تصویر نهایی</option>
                        <option value="waiting_ref" <?php echo $f_stock === 'waiting_ref' ? 'selected' : ''; ?>>⏳ در انتظار عکس رفرنس</option>
                        <option value="has_ref" <?php echo $f_stock === 'has_ref' ? 'selected' : ''; ?>>🖼️ دارای عکس رفرنس</option>
                        <option value="stock_agg_gte" <?php echo ($f_stock === 'stock_agg_gte' || $f_stock === 'stock_gte_5') ? 'selected' : ''; ?>>🔥 موجودی تجمیعی بالای <?php echo $f_stock_min; ?> عدد</option>
                        <option value="stock_direct_gte" <?php echo $f_stock === 'stock_direct_gte' ? 'selected' : ''; ?>>📦 موجودی مستقیم بالای <?php echo $f_stock_min; ?> عدد</option>
                        <option value="stock_low" <?php echo $f_stock === 'stock_low' ? 'selected' : ''; ?>>⚠️ کم‌موجودی (۱ تا <?php echo $f_stock_min - 1; ?> عدد)</option>
                        <option value="in_stock" <?php echo $f_stock === 'in_stock' ? 'selected' : ''; ?>>موجود</option>
                        <option value="out_of_stock" <?php echo $f_stock === 'out_of_stock' ? 'selected' : ''; ?>>بدون موجودی</option>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">آستانه موجودی (N)</label>
                    <input type="number" name="f_stock_min" min="1" max="999" value="<?php echo htmlspecialchars((string)$f_stock_min); ?>" placeholder="مثال: 5" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">حداقل لایک</label>
                    <input type="number" name="f_likes" value="<?php echo htmlspecialchars((string)$f_likes ?: ''); ?>" placeholder="مثال: 5" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">پرسنل تصویرساز</label>
                    <select name="f_creator" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="">همه پرسنل</option>
                        <?php foreach ($all_creators as $cr): ?>
                            <option value="<?php echo htmlspecialchars($cr); ?>" <?php echo $f_creator === $cr ? 'selected' : ''; ?>><?php echo htmlspecialchars($cr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">مرتب‌سازی</label>
                    <select name="f_sort" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                        <option value="" <?php echo $f_sort === '' ? 'selected' : ''; ?>>آخرین تأیید شده‌ها (پیش‌فرض)</option>
                        <option value="stock_desc" <?php echo $f_sort === 'stock_desc' ? 'selected' : ''; ?>>📦 بیشترین موجودی</option>
                        <option value="stock_asc" <?php echo $f_sort === 'stock_asc' ? 'selected' : ''; ?>>📦 کمترین موجودی</option>
                        <option value="newest" <?php echo $f_sort === 'newest' ? 'selected' : ''; ?>>جدیدترین کالا (کد/SKU)</option>
                        <option value="oldest" <?php echo $f_sort === 'oldest' ? 'selected' : ''; ?>>قدیمی‌ترین کالا (کد/SKU)</option>
                        <option value="cheapest" <?php echo $f_sort === 'cheapest' ? 'selected' : ''; ?>>ارزان‌ترین</option>
                        <option value="expensive" <?php echo $f_sort === 'expensive' ? 'selected' : ''; ?>>گران‌ترین</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-black shadow-md transition-all flex items-center justify-center flex-1 sm:flex-none cursor-pointer" title="اعمال فیلتر">🔍 اعمال</button>
                    <a href="portal.php?tab=<?php echo htmlspecialchars($active_tab ?? 'overview'); ?>" class="px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-705 border border-slate-300 rounded-xl text-xs font-black transition-all flex items-center justify-center cursor-pointer" title="ریست">♻️</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Primary Navigation Bar -->
    <nav class="bg-white/90 backdrop-blur-md border border-slate-200/60 p-1.5 sm:p-2 rounded-2xl sm:rounded-3xl flex gap-1.5 mb-6 sm:mb-8 overflow-x-auto custom-scrollbar whitespace-nowrap scroll-smooth shadow-sm" style="-webkit-overflow-scrolling: touch;">
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'overview') ? 'bg-indigo-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-overview" onclick="switchTab('overview')">
            <span>📊</span> 
            <span>داشبورد کلی</span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'pending') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-pending" onclick="switchTab('pending')">
            <span>⏳</span> 
            <span>منتظر تایید</span>
            <span class="px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] <?php echo (($active_tab ?? 'overview') === 'pending') ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'; ?>"><?php echo $count_pending; ?></span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'calendar') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-calendar" onclick="switchTab('calendar')">
            <span>🗓️</span> 
            <span>تقویم محتوایی</span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'library') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-library" onclick="switchTab('library')">
            <span>📚</span> 
            <span>محصولات تایید شده</span>
            <span class="px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] <?php echo (($active_tab ?? 'overview') === 'library') ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'; ?>"><?php echo $count_library; ?></span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'out-of-stock') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-out-of-stock" onclick="switchTab('out-of-stock')">
            <span>📦</span> 
            <span>بدون موجودی</span>
            <span class="px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] <?php echo (($active_tab ?? 'overview') === 'out-of-stock') ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'; ?>"><?php echo $count_out_of_stock; ?></span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'rejected') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-rejected" onclick="switchTab('rejected')">
            <span>🚫</span>
            <span>رد شده</span>
            <span class="px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] <?php echo (($active_tab ?? 'overview') === 'rejected') ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'; ?>"><?php echo $count_rejected; ?></span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'no-image') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-no-image" onclick="switchTab('no-image')">
            <span>📷</span>
            <span>بدون تصویر</span>
            <span class="px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] <?php echo (($active_tab ?? 'overview') === 'no-image') ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-600'; ?>"><?php echo $count_no_image; ?></span>
        </button>
        <button class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 <?php echo (($active_tab ?? 'overview') === 'occasions') ? 'bg-brand-600 shadow-md text-white' : 'hover:bg-slate-50'; ?>" id="btn-occasions" onclick="switchTab('occasions')">
            <span>🏮</span> 
            <span>تقویم مناسبتی و سایر</span>
        </button>
        <a href="prompthub.php<?php echo isset($project['id']) ? '?project=' . urlencode($project['id']) : ''; ?>" class="tab-btn px-3 sm:px-4 py-2 sm:py-3 rounded-xl sm:rounded-2xl text-[11px] sm:text-xs font-black transition-all flex items-center gap-1.5 sm:gap-2 shrink-0 text-slate-500 hover:text-slate-800 hover:bg-slate-50" id="btn-prompthub">
            <span>🧠</span> 
            <span>پرامپت هاب</span>
            <span class="px-1.5 py-0.5 text-[8px] bg-violet-600 text-white rounded-md font-black">جدید</span>
        </a>
    </nav>

    <!-- Tab 0: Executive Overview Dashboard -->
    <div id="tab-overview" class="tab-content <?php echo (($active_tab ?? 'overview') === 'overview') ? 'active' : ''; ?>">
        
        <!-- Hero Welcome Banner -->
        <div class="relative overflow-hidden bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-2xl sm:rounded-[2.5rem] p-5 sm:p-8 mb-6 sm:mb-8 text-white shadow-xl shadow-indigo-950/20 border border-slate-800">
            <div class="absolute -left-12 -bottom-12 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -right-12 -top-12 w-64 h-64 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-5 sm:gap-6">
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-xs font-bold text-indigo-300">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse shrink-0"></span>
                        <span class="truncate">سامانه مدیریت متمرکز سوشال و محصول</span>
                        <span class="opacity-40">•</span>
                        <span class="bg-indigo-500/20 px-2.5 py-0.5 rounded-full border border-indigo-400/20 text-indigo-200 shrink-0"><?php echo htmlspecialchars($_SESSION['user']['role'] ?? 'کاربر پورتال'); ?></span>
                    </div>
                    <h2 class="text-xl sm:text-2xl md:text-3xl font-black text-white leading-tight">
                        سلام <?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['user']['username'] ?? 'کاربر گرامی'); ?>، خوش آمدید 👋
                    </h2>
                    <p class="text-xs text-slate-300 font-medium max-w-xl leading-relaxed">
                        میز کار پیشرفته پروژه <strong class="text-white font-bold"><?php echo htmlspecialchars($project['name']); ?></strong>. اینجا می‌توانید وضعیت کالاها، تقویم محتوایی و دسترسی به ابزارهای هوشمند را بررسی و مدیریت کنید.
                    </p>
                </div>
                
                <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 w-full sm:w-auto">
                    <a href="#quick-launchpad" class="px-4 sm:px-5 py-2.5 sm:py-3 bg-white hover:bg-slate-100 text-slate-900 font-black text-xs rounded-xl sm:rounded-2xl shadow-lg transition-all hover:scale-105 active:scale-95 flex items-center justify-center gap-2 w-full sm:w-auto">
                        <span>⚡ دسترسی سریع</span>
                    </a>
                    <button onclick="switchTab('pending')" class="px-5 py-3 bg-indigo-600/80 hover:bg-indigo-600 text-white font-black text-xs rounded-2xl border border-indigo-400/30 backdrop-blur-md transition-all flex items-center gap-2">
                        <span>⏳ کالاهای در انتظار</span>
                        <span class="bg-white/20 px-2 py-0.5 rounded-full text-[10px]"><?php echo $count_pending; ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 4 KPI Modern Metric Cards (Matching Socia/.D UI style) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            
            <!-- Metric Card 1: Products Summary -->
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-md transition-all relative overflow-hidden group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold group-hover:scale-110 transition-transform">🛍️</div>
                    <span class="text-[10px] font-black bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-full border border-emerald-100">
                        <?php 
                        $total_prods = count($raw_all_project_products);
                        echo $total_prods > 0 ? round(($count_library / $total_prods) * 100) . '٪ فعال' : '۱۰۰٪';
                        ?>
                    </span>
                </div>
                <div class="text-2xl font-black text-slate-800 mb-1 font-mono"><?php echo number_format($total_prods); ?></div>
                <div class="text-xs font-bold text-slate-500 mb-3">کل کالاهای ثبت‌شده</div>
                <div class="flex items-center gap-3 text-[11px] font-bold text-slate-400 border-t border-slate-100 pt-3">
                    <span class="text-emerald-600">✓ <?php echo number_format($count_library); ?> تایید</span>
                    <span>•</span>
                    <span class="text-amber-600">⏳ <?php echo number_format($count_pending); ?> در انتظار</span>
                </div>
            </div>

            <!-- Metric Card 2: Calendar Summary -->
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-md transition-all relative overflow-hidden group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-2xl bg-sky-50 text-sky-600 flex items-center justify-center text-xl font-bold group-hover:scale-110 transition-transform">🗓️</div>
                    <span class="text-[10px] font-black bg-sky-50 text-sky-600 px-2.5 py-1 rounded-full border border-sky-100">تقویم انتشار</span>
                </div>
                <?php 
                $total_sched = count($project['schedules'] ?? []);
                $app_sched = count(array_filter((array)($project['schedules']??[]), function($s){ return ($s['approvalStatus']??'')==='approved'; }));
                ?>
                <div class="text-2xl font-black text-slate-800 mb-1 font-mono"><?php echo number_format($total_sched); ?></div>
                <div class="text-xs font-bold text-slate-500 mb-3">پست‌های تقویم محتوا</div>
                <div class="flex items-center gap-3 text-[11px] font-bold text-slate-400 border-t border-slate-100 pt-3">
                    <span class="text-sky-600">🚀 <?php echo number_format($app_sched); ?> آماده انتشار</span>
                    <span>•</span>
                    <span class="text-slate-500">🗓️ مناسبت‌های فعال</span>
                </div>
            </div>

            <!-- Metric Card 3: Quality Index -->
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-md transition-all relative overflow-hidden group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-xl font-bold group-hover:scale-110 transition-transform">⚡</div>
                    <span class="text-[10px] font-black bg-amber-50 text-amber-600 px-2.5 py-1 rounded-full border border-amber-100">ارزیابی ناظر</span>
                </div>
                <div class="text-2xl font-black text-slate-800 mb-1 font-mono"><?php echo $prod_percentage; ?>٪</div>
                <div class="text-xs font-bold text-slate-500 mb-3">نرخ تایید تصویر محصول</div>
                <div class="flex items-center gap-3 text-[11px] font-bold text-slate-400 border-t border-slate-100 pt-3">
                    <span class="text-emerald-600">👍 <?php echo $my_prod_approved_count; ?> تایید شما</span>
                    <span>•</span>
                    <span class="text-rose-500">👎 <?php echo $my_prod_rejected_count; ?> اصلاح</span>
                </div>
            </div>

            <!-- Metric Card 4: Storage Meter Widget -->
            <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-md transition-all relative overflow-hidden group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center text-xl font-bold group-hover:scale-110 transition-transform">💾</div>
                    <span class="text-[10px] font-black <?php echo $storageInfo['is_full'] ? 'bg-rose-50 text-rose-600' : 'bg-violet-50 text-violet-600'; ?> px-2.5 py-1 rounded-full border border-violet-100 font-mono">
                        <?php echo $storageInfo['usage_percent']; ?>٪
                    </span>
                </div>
                <div class="text-xl font-black text-slate-800 mb-1 font-mono"><?php echo $storageInfo['formatted_used']; ?></div>
                <div class="text-xs font-bold text-slate-500 mb-2">فضای مصرف‌شده از <?php echo $storageInfo['formatted_quota']; ?></div>
                
                <!-- Progress Bar -->
                <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden mt-3">
                    <div class="h-full rounded-full transition-all duration-500 <?php echo $storageInfo['is_full'] ? 'bg-rose-600' : ($storageInfo['is_warning'] ? 'bg-amber-500' : 'bg-gradient-to-r from-violet-500 to-indigo-600'); ?>" style="width: <?php echo min(100, $storageInfo['usage_percent']); ?>%"></div>
                </div>
            </div>

        </div>

        <!-- Smart Launchpad / Quick Actions (Dastresi Sarii) -->
        <div id="quick-launchpad" class="mb-10">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-800 flex items-center gap-2">
                    <span>⚡</span>
                    <span>میانبرهای عملیاتی و ابزارهای کلیدی</span>
                </h3>
                <span class="text-xs text-slate-400 font-bold">دسترسی یک‌کلیکه به تمامی امکانات</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                
                <button onclick="switchTab('pending')" class="bg-white border border-slate-100 hover:border-indigo-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group cursor-pointer">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">⏳</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">کالاهای منتظر</span>
                    <span class="text-[10px] text-slate-400 font-bold">بررسی و تایید تصاویر</span>
                </button>

                <button onclick="switchTab('calendar')" class="bg-white border border-slate-100 hover:border-sky-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group cursor-pointer">
                    <div class="w-12 h-12 rounded-2xl bg-sky-50 text-sky-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">🗓️</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">تقویم محتوایی</span>
                    <span class="text-[10px] text-slate-400 font-bold">زمان‌بندی برنامه‌ها</span>
                </button>

                <a href="prompthub.php<?php echo isset($project['id']) ? '?project=' . urlencode($project['id']) : ''; ?>" class="bg-white border border-slate-100 hover:border-violet-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group">
                    <div class="w-12 h-12 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">🧠</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">پرامپت هاب</span>
                    <span class="text-[10px] text-slate-400 font-bold">هوش مصنوعی تصویر</span>
                </a>

                <a href="seo.php<?php echo isSuperAdmin() ? '?project='.$project['id'] : ''; ?>" class="bg-white border border-slate-100 hover:border-emerald-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">🔍</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">آنالیز سئو</span>
                    <span class="text-[10px] text-slate-400 font-bold">پایش کلمات کلیدی</span>
                </a>

                <a href="sale.php<?php echo isSuperAdmin() ? '?project='.$project['id'] : ''; ?>" class="bg-white border border-slate-100 hover:border-amber-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group">
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">💰</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">تحلیل فروش</span>
                    <span class="text-[10px] text-slate-400 font-bold">داشبورد شعب</span>
                </a>

                <a href="user_chat.php" class="bg-white border border-slate-100 hover:border-rose-300 p-5 rounded-3xl shadow-sm hover:shadow-md transition-all text-center flex flex-col items-center justify-center group">
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center text-2xl mb-3 group-hover:scale-110 transition-transform">💬</div>
                    <span class="text-xs font-black text-slate-800 block mb-1">گفتگوی همکاران</span>
                    <span class="text-[10px] text-slate-400 font-bold">ارتباطات درون سازمانی</span>
                </a>

            </div>
        </div>

        <!-- Quick Pending Items Showcase -->
        <?php 
        $top_pending = array_filter((array)($project['products'] ?? []), function($p) {
            $isNoImg = is_product_without_image($p);
            $isOut = isProductOutOfStock($p);
            return ($p['status'] ?? 'active') === 'pending' && !$isNoImg && !$isOut;
        });
        $top_pending = array_slice($top_pending, 0, 4);
        if (!empty($top_pending)):
        ?>
        <div class="mb-10">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-800 flex items-center gap-2">
                    <span>🔥</span>
                    <span>آخرین کالاهای منتظر تایید ناظر</span>
                </h3>
                <button onclick="switchTab('pending')" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 cursor-pointer">
                    <span>مشاهده همه کالاها</span>
                    <span>←</span>
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                <?php foreach($top_pending as $p): ?>
                    <div class="bg-white rounded-[2rem] border border-slate-100 hover:border-indigo-200 shadow-sm hover:shadow-md transition-all overflow-hidden flex flex-col cursor-pointer" onclick="reviewInPendingTab('<?php echo $p['id']; ?>')">
                        <div class="relative aspect-[4/5] bg-slate-50 overflow-hidden">
                            <?php renderCardImageCarousel($p); ?>
                            <div class="absolute top-3 right-3 bg-amber-500/90 backdrop-blur-md text-white text-[9px] font-black px-2.5 py-1 rounded-full shadow-sm">
                                در انتظار تایید
                            </div>
                        </div>
                        <div class="p-4 flex-1 flex flex-col justify-between" onclick="event.stopPropagation()">
                            <div>
                                <h4 class="font-bold text-xs text-slate-800 line-clamp-1 mb-1"><?php echo htmlspecialchars($p['name']); ?></h4>
                                <div class="text-[11px] font-black text-indigo-600 mb-3"><?php echo $p['price']; ?></div>
                            </div>
                            <button onclick="reviewInPendingTab('<?php echo $p['id']; ?>')" class="w-full py-2.5 bg-slate-50 hover:bg-indigo-50 text-slate-700 hover:text-indigo-600 border border-slate-200/60 rounded-xl text-xs font-bold transition-all cursor-pointer">
                                بررسی و اعلام نظر 🔍
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Tab 1: Pending -->
    <div id="tab-pending" class="tab-content <?php echo (($active_tab ?? 'overview') === 'pending') ? 'active' : ''; ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php 
            $pending = array_filter((array)($project['products'] ?? []), function($p) {
                $isNoImg = is_product_without_image($p);
                $isOut = isProductOutOfStock($p);
                return ($p['status'] ?? 'active') === 'pending' && !$isNoImg && !$isOut;
            });
            usort($pending, function($a, $b) { return (int)($b['extractedAt'] ?? 0) - (int)($a['extractedAt'] ?? 0); });
            
            $limit = 12;
            $page = (int)($_GET['p'] ?? 1);

            $total_pending = count($pending);
            $total_pending_pages = ceil($total_pending / $limit);
            if (($active_tab ?? 'pending') === 'pending') {
                $pending = array_slice($pending, ($page - 1) * $limit, $limit);
            } else {
                $pending = array_slice($pending, 0, $limit);
            }
            
            foreach($pending as $p): 
                $image = getPrioritizedProductImage($p);
                if (strpos($image, 'http') !== 0) {
                    $image = getFullUrl($image);
                }
                
                // Get comment count
                $commentCount = count($p['userFeedback'] ?? []);
            ?>
                <?php $p['uploader_info'] = getUploaderDetails($p, $project); ?>
                <script>window.portalProducts['<?php echo $p['id']; ?>'] = <?php echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                <div class="bg-white rounded-[2rem] border border-slate-100 hover:border-brand-300 shadow-premium hover:shadow-elevated hover-lift overflow-hidden flex flex-col cursor-pointer animate-fade-in" onclick="openDetailById('<?php echo $p['id']; ?>')">
                    <div class="relative aspect-[4/5] overflow-hidden bg-slate-50">
                        <?php renderCardImageCarousel($p); ?>
                        
                        <!-- Top Badges -->
                        <div class="absolute top-4 right-4 flex flex-col gap-2 z-20 pointer-events-none">
                            <?php if ($commentCount > 0): ?>
                                <span class="bg-brand-500/90 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full flex items-center gap-1.5 shadow-sm">
                                    <span>💬</span>
                                    <span><?php echo $commentCount; ?> بازخورد</span>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Creator Badge -->
                        <?php if (!empty($p['uploaded_by'])): ?>
                            <div class="absolute bottom-4 left-4 bg-black/60 backdrop-blur-md text-white text-[9px] font-bold px-2.5 py-1 rounded-xl flex items-center gap-1 shadow-sm z-20 border border-white/10 pointer-events-none" title="ثبت‌کننده: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> | آیدی: <?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>">
                                <span>📸</span>
                                <span>ثبت: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> (<?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-5 flex-1 flex flex-col" onclick="event.stopPropagation()">
                        <h4 class="font-bold text-sm text-slate-800 line-clamp-2 h-10 mb-2"><?php echo htmlspecialchars($p['name']); ?></h4>
                        <div class="flex items-center justify-between gap-2 mb-4">
                            <div class="text-xs font-black text-brand-600"><?php echo $p['price']; ?></div>
                            <?php if (!empty($p['variants']) && count($p['variants']) > 1): ?>
                                <span class="text-[9px] font-black text-indigo-650 bg-indigo-50 border border-indigo-100/50 px-2 py-0.5 rounded-full flex items-center gap-1.5 transition-all">
                                    <span>🎨</span>
                                    <span><?php echo count($p['variants']); ?> رنگ</span>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Reaction Controls -->
                        <div class="flex gap-2 mb-4 justify-center">
                            <?php 
                            $myVoteInCard = $p['voterVotes'][$voterId] ?? null;
                            $likeBtnClass = $myVoteInCard === 'like' 
                                ? "flex-1 py-2.5 rounded-xl bg-emerald-500 border-emerald-500 text-white flex items-center justify-center text-xs font-black hover:bg-emerald-600 transition-all gap-1.5 shadow-sm" 
                                : "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all gap-1.5";
                            
                            $dislikeBtnClass = $myVoteInCard === 'dislike' 
                                ? "flex-1 py-2.5 rounded-xl bg-rose-500 border-rose-500 text-white flex items-center justify-center text-xs font-black hover:bg-rose-600 transition-all gap-1.5 shadow-sm" 
                                : "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition-all gap-1.5";
                            ?>
                            <button onclick="vote('like', '<?php echo $p['id']; ?>')" class="<?php echo $likeBtnClass; ?>" id="like-btn-<?php echo $p['id']; ?>">
                                <span>❤️</span>
                                <span id="likes-<?php echo $p['id']; ?>"><?php echo $p['likes'] ?? 0; ?></span>
                            </button>
                            <button onclick="vote('dislike', '<?php echo $p['id']; ?>')" class="<?php echo $dislikeBtnClass; ?>" id="dislike-btn-<?php echo $p['id']; ?>">
                                <span>👎</span>
                                <span id="dislikes-<?php echo $p['id']; ?>"><?php echo $p['dislikes'] ?? 0; ?></span>
                            </button>
                        </div>

                        <!-- Split Approval status on Card -->
                        <?php if (!empty($project['settings']['prod_approval_split_enabled'])): 
                            $st1_count = count($p['approvals_stage1'] ?? []);
                            $st2_count = count($p['approvals_stage2'] ?? []);
                            $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
                            $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);
                            
                            $st1_ok = $st1_count >= $st1_min;
                            $st2_ok = $st2_count >= $st2_min;
                            
                            $st1_text = "⏳ در انتظار";
                            $st1_class = "text-amber-500";
                            if (!empty($p['stage1_decision']) && $p['stage1_decision'] === 'rejected') {
                                $st1_text = "❌ رد شده";
                                $st1_class = "text-rose-500 font-bold";
                            } elseif (!empty($p['stage1_system_approved']) || in_array('سیستم (پاسخ خودکار)', $p['approvals_stage1'] ?? [])) {
                                $st1_text = "⚙️ تایید سیستمی";
                                $st1_class = "text-emerald-600 font-bold";
                            } elseif ($st1_ok) {
                                $st1_text = "✅ تایید شده";
                                $st1_class = "text-emerald-600 font-bold";
                            }

                            $st2_text = "⏳ در انتظار";
                            $st2_class = "text-amber-500";
                            if (!empty($p['stage2_decision']) && $p['stage2_decision'] === 'rejected') {
                                $st2_text = "❌ رد شده";
                                $st2_class = "text-rose-500 font-bold";
                            } elseif (!empty($p['stage2_system_approved']) || in_array('سیستم (پاسخ خودکار)', $p['approvals_stage2'] ?? [])) {
                                $st2_text = "⚙️ تایید سیستمی";
                                $st2_class = "text-emerald-600 font-bold";
                            } elseif ($st2_ok) {
                                $st2_text = "✅ تایید شده";
                                $st2_class = "text-emerald-600 font-bold";
                            }
                        ?>
                            <div class="mb-3 bg-slate-50 p-2.5 border border-slate-100/60 space-y-1.5 text-[10px] font-black rounded-2xl">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">🛡️ استایل و محتوا (تیم ۱):</span>
                                    <span class="<?php echo $st1_class; ?>">
                                        <?php echo "{$st1_text} ({$st1_count}/{$st1_min})"; ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">📐 جزئیات و دقت (تیم ۲):</span>
                                    <span class="<?php echo $st2_class; ?>">
                                        <?php echo "{$st2_text} ({$st2_count}/{$st2_min})"; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Current active Rejection on Card -->
                        <?php if (($p['status'] ?? 'active') === 'rejected' || !empty($p['rejectionReason'])): ?>
                            <div class="mb-3 bg-rose-50 p-2.5 rounded-2xl border border-rose-100 text-[10px] font-semibold text-rose-700">
                                <div class="flex items-center gap-1 mb-1 font-black">
                                    <span>🚫 علت رد شدن:</span>
                                    <span><?php echo htmlspecialchars($p['rejection_side'] ?? 'نامشخص'); ?></span>
                                </div>
                                <p class="text-[11px] leading-relaxed whitespace-pre-wrap font-bold bg-white/70 p-1.5 rounded-lg border border-rose-100"><?php echo htmlspecialchars($p['rejectionReason'] ?? 'توضیحی ثبت نشده است.'); ?></p>
                                <div class="text-[9px] text-rose-500 mt-1.5 text-left font-black">توسط: <?php echo htmlspecialchars($p['rejection_by'] ?? 'مدیر'); ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Feedback Box -->
                        <div class="space-y-3 mt-auto">
                            <textarea id="comment-<?php echo $p['id']; ?>" class="w-full text-xs p-3 border border-slate-100 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none custom-scrollbar bg-slate-50 transition-all placeholder:text-slate-400" rows="2" placeholder="نظر یا اصلاحیه تخصصی محصول..."></textarea>
                            
                            <!-- Preset tags -->
                            <?php if (!empty($project['settings']['feedbackOptions'])): ?>
                                <div class="flex flex-wrap gap-1.5 pt-1 select-none">
                                    <?php foreach($project['settings']['feedbackOptions'] as $f): ?>
                                        <button onclick="document.getElementById('comment-<?php echo $p['id']; ?>').value = '<?php echo addslashes($f['text']); ?>'" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200/60 hover:border-slate-300 text-slate-500 hover:text-brand-600 transition-all">
                                            <?php echo htmlspecialchars($f['text']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <button onclick="portalComment('<?php echo $p['id']; ?>')" class="w-full py-2.5 bg-brand-50 hover:bg-brand-600 text-brand-600 hover:text-white border border-brand-100 rounded-xl text-xs font-black transition-all">ارسال سریع بازخورد 💬</button>
                            <button onclick="openCommentsPopup('<?php echo $p['id']; ?>')" class="w-full mt-2 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-[11px] font-black tracking-wide transition-all flex items-center justify-center gap-1.5 shadow-sm">
                                <span>👁️‍🗨️</span> مشاهده کامنت‌ها و تاریخچه
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Decision Bottom Drawer -->
                    <div class="flex border-t border-slate-100 p-3 bg-slate-50/70 gap-2 flex-col">
                        <?php if (!empty($project['settings']['prod_approval_split_enabled'])): 
                            $st1_users_raw = $project['settings']['prod_approval_stage1_users'] ?? '';
                            $st2_users_raw = $project['settings']['prod_approval_stage2_users'] ?? '';
                            
                            $st1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($st1_users_raw))));
                            $st2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($st2_users_raw))));
                            
                            $p_st1_approvals = $p['approvals_stage1'] ?? [];
                            $p_st01_approvals_lower = array_map('strtolower', $p_st1_approvals);
                            $p_st2_approvals = $p['approvals_stage2'] ?? [];
                            $p_st02_approvals_lower = array_map('strtolower', $p_st2_approvals);
                            
                            $currentUser_lower = strtolower($_SESSION['user']['username'] ?? $_SESSION['display_name'] ?? 'guest');
                            
                            $isSuperOrAdmin = isSuperAdmin() || isProjectAdmin();
                            $canApproveSt1 = $isSuperOrAdmin || empty($st1_users) || in_array($currentUser_lower, $st1_users);
                            $canApproveSt2 = $isSuperOrAdmin || empty($st2_users) || in_array($currentUser_lower, $st2_users);
                            
                            $hasApprovedSt1 = in_array($currentUser_lower, $p_st01_approvals_lower);
                            $hasApprovedSt2 = in_array($currentUser_lower, $p_st02_approvals_lower);
                            
                            $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
                            $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);
                        ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 w-full">
                                <!-- Stage 1 Button -->
                                <?php if ($canApproveSt1): ?>
                                    <?php if ($hasApprovedSt1): ?>
                                        <button disabled class="py-2 px-1 bg-emerald-100 text-emerald-700 rounded-xl font-black text-[10px] cursor-not-allowed opacity-80 flex items-center justify-center gap-1 w-full">
                                            <span>🎨</span> تایید استایل ثبت شده (<?php echo count($p_st1_approvals); ?>/<?php echo $st1_min; ?>)
                                        </button>
                                    <?php else: ?>
                                        <button onclick="advancedAction('approve_stage1', '<?php echo $p['id']; ?>')" class="py-2 px-1 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-black text-[10px] flex items-center justify-center gap-1 shadow-sm hover:shadow transition-all w-full cursor-pointer">
                                            <span>🎨</span> تایید استایل (بخش ۱)
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button disabled class="py-2 px-1 bg-rose-50/50 text-rose-400 border border-rose-100/40 rounded-xl font-bold text-[9px] cursor-not-allowed flex items-center justify-center gap-1 w-full" title="دسترسی غیر مجاز 🚫">
                                        <span>🎨</span> دسترسی غیر مجاز 🚫
                                    </button>
                                <?php endif; ?>

                                <!-- Stage 2 Button -->
                                <?php if ($canApproveSt2): ?>
                                    <?php if ($hasApprovedSt2): ?>
                                        <button disabled class="py-2 px-1 bg-emerald-100 text-emerald-700 rounded-xl font-black text-[10px] cursor-not-allowed opacity-80 flex items-center justify-center gap-1 w-full">
                                            <span>🧐</span> تایید انطباق ثبت شده (<?php echo count($p_st2_approvals); ?>/<?php echo $st2_min; ?>)
                                        </button>
                                    <?php else: ?>
                                        <button onclick="advancedAction('approve_stage2', '<?php echo $p['id']; ?>')" class="py-2 px-1 bg-teal-500 hover:bg-teal-600 text-white rounded-xl font-black text-[10px] flex items-center justify-center gap-1 shadow-sm hover:shadow transition-all w-full cursor-pointer">
                                            <span>🧐</span> تایید انطباق (بخش ۲)
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button disabled class="py-2 px-1 bg-rose-50/50 text-rose-400 border border-rose-100/40 rounded-xl font-bold text-[9px] cursor-not-allowed flex items-center justify-center gap-1 w-full" title="دسترسی غیر مجاز 🚫">
                                        <span>🧐</span> دسترسی غیر مجاز 🚫
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Reject Buttons -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 w-full mt-1">
                                <?php if ($canApproveSt1): ?>
                                    <button class="py-2.5 rounded-xl text-white font-black text-[10px] bg-rose-500 hover:bg-rose-600 hover:shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer w-full" onclick="advancedAction('reject_stage1', '<?php echo $p['id']; ?>')">
                                        <span>❌</span> رد استایل و محتوا (بخش ۱)
                                    </button>
                                <?php else: ?>
                                    <button disabled class="py-2.5 rounded-xl text-rose-350 border border-rose-100/30 bg-rose-50/50 font-bold text-[9px] cursor-not-allowed flex items-center justify-center gap-1 w-full" title="دسترسی غیر مجاز 🚫">
                                        <span>❌</span> رد بخش ۱ (غیر مجاز)
                                    </button>
                                <?php endif; ?>

                                <?php if ($canApproveSt2): ?>
                                    <button class="py-2.5 rounded-xl text-white font-black text-[10px] bg-rose-500 hover:bg-rose-600 hover:shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer w-full" onclick="advancedAction('reject_stage2', '<?php echo $p['id']; ?>')">
                                        <span>❌</span> رد جزئیات و انطباق (بخش ۲)
                                    </button>
                                <?php else: ?>
                                    <button disabled class="py-2.5 rounded-xl text-rose-350 border border-rose-100/30 bg-rose-50/50 font-bold text-[9px] cursor-not-allowed flex items-center justify-center gap-1 w-full" title="دسترسی غیر مجاز 🚫">
                                        <span>❌</span> رد بخش ۲ (غیر مجاز)
                                    </button>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>
                            <!-- Non-split mode -->
                            <div class="flex gap-2 w-full">
                                <button class="flex-1 py-2 rounded-xl text-white font-black text-xs bg-emerald-500 hover:bg-emerald-600 hover:shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer" onclick="advancedAction('approve', '<?php echo $p['id']; ?>')">
                                    <span>✅</span> تایید محصول
                                </button>
                                <button class="flex-1 py-2 rounded-xl text-white font-black text-xs bg-rose-500 hover:bg-rose-600 hover:shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer" onclick="advancedAction('reject', '<?php echo $p['id']; ?>')">
                                    <span>❌</span> رد محصول
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($pending)): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-premium p-16 text-center text-slate-400 font-bold max-w-lg mx-auto flex flex-col items-center gap-3">
                <span class="text-4xl animate-bounce">✨</span>
                <p class="text-sm">موردی برای بررسی و تایید وجود ندارد!</p>
            </div>
        <?php endif; ?>
        
        <?php if ($total_pending_pages > 1): ?>
            <div class="flex justify-center items-center gap-2 mt-12 pb-6">
                <?php for($i=1; $i<=$total_pending_pages; $i++): 
                    $pageUrl = 'portal.php?' . http_build_query(array_merge($_GET, ['tab' => 'pending', 'p' => $i]));
                ?>
                    <a href="<?php echo htmlspecialchars($pageUrl); ?>" class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-black <?php echo ($active_tab == 'pending' && $page == $i) ? 'bg-brand-600 text-white shadow-md' : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'; ?> transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 2: Content Calendar -->
    <div id="tab-calendar" class="tab-content <?php echo (($active_tab ?? 'pending') === 'calendar') ? 'active' : ''; ?>">
        <?php 
            // Current month range for Jalali
            $jy_req = isset($_GET['jy']) ? (int)$_GET['jy'] : 0;
            $jm_req = isset($_GET['jm']) ? (int)$_GET['jm'] : 0;
            
            $nowTs = time();
            $jNow = formatPersianDate($nowTs, 'Y/m/d');
            $p_parts = explode('/', $jNow);
            $jy = $jy_req ?: (int)$p_parts[0]; 
            $jm = $jm_req ?: (int)$p_parts[1];

            $prev_m = $jm - 1; $prev_y = $jy;
            if($prev_m < 1) { $prev_m = 12; $prev_y--; }
            $next_m = $jm + 1; $next_y = $jy;
            if($next_m > 12) { $next_m = 1; $next_y++; }

            $monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        ?>
        <div class="flex flex-col md:flex-row justify-between items-center bg-white border border-slate-100 p-6 rounded-[2rem] shadow-premium mb-8 gap-4 animate-fade-in">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center text-xl">📅</div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-800 leading-tight">تقویم: <?php echo $monthNames[$jm] . " " . $jy; ?></h3>
                    <p class="text-[10px] text-slate-400 font-bold">نمای ماهانه زمانبندی انتشار محتوا</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <div class="flex bg-slate-100 p-1 rounded-xl">
                    <a href="?tab=calendar&jy=<?php echo $prev_y; ?>&jm=<?php echo $prev_m; ?>" class="px-3 py-1.5 hover:bg-white rounded-lg text-xs font-black transition-all text-slate-600">◄ قبلی</a>
                    <a href="?tab=calendar&jy=<?php echo $next_y; ?>&jm=<?php echo $next_m; ?>" class="px-3 py-1.5 hover:bg-white rounded-lg text-xs font-black transition-all text-slate-600">بعدی ►</a>
                </div>
                
                <div class="flex gap-2">
                    <select onchange="filterCalendar(this.value)" class="py-2.5 px-4 bg-slate-50 border border-slate-100 rounded-xl text-xs font-black outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="all">همه وضعیت‌ها</option>
                        <option value="pending">در انتظار</option>
                        <option value="approved">تایید شده</option>
                        <option value="rejected">رد شده</option>
                        <option value="shipped">ارسال شده</option>
                    </select>
                    <select onchange="filterPlatform(this.value)" class="py-2.5 px-4 bg-slate-50 border border-slate-100 rounded-xl text-xs font-black outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="all">همه رسانه‌ها</option>
                        <option value="telegram">تلگرام</option>
                        <option value="bale">بله</option>
                        <option value="rubika">روبیکا</option>
                        <option value="instagram">اینستاگرام</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="calendar-list animate-fade-in">
            <style>
                .cal-v2-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
                .cal-v2-header { background: #f8fafc; padding: 1rem 0.5rem; text-align: center; font-weight: 900; font-size: 0.75rem; color: #64748b; border-radius: 12px; }
                .cal-v2-cell { min-height: 140px; padding: 0.75rem; border: 1px solid #f1f5f9; border-radius: 1.5rem; background: #fff; position: relative; transition: all 0.3s; }
                .cal-v2-cell:hover { border-color: #ddd6fe; box-shadow: 0 10px 15px -3px rgba(124, 58, 237, 0.05); }
                .cal-v2-day { font-weight: 900; font-size: 1.125rem; color: #1e293b; margin-bottom: 0.5rem; }
                .cal-v2-posts { display: flex; flex-direction: column; gap: 4px; }
                .cal-v2-post { font-size: 10px; padding: 6px 8px; border-radius: 10px; font-weight: 800; color: #fff; background: #3b82f6; cursor: pointer; transition: all 0.2s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px; }
                .cal-v2-post:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
                .cal-v2-post.st-pending { border-right: 3px solid #f59e0b; background: #94a3b8; }
                .cal-v2-post.st-approved { background: #7c3aed; }
                .cal-v2-post.st-shipped { opacity: 0.5; background: #475569; }
                .cal-v2-post.st-rejected { background: #f43f5e; text-decoration: line-through; }
                .cal-v2-platform { font-size: 8px; opacity: 0.8; font-weight: bold; background: rgba(0,0,0,0.15); padding: 1px 4px; border-radius: 4px; }
                .cal-v2-today { background: #f5f3ff !important; border: 2px solid #a78bfa !important; }
                @media (max-width: 900px) {
                    .cal-v2-grid { grid-template-columns: repeat(1, 1fr); gap: 8px; }
                    .cal-v2-header { display: none; }
                    .cal-v2-cell { min-height: unset; }
                    .cal-v2-cell:not(.has-posts) { display: none; }
                }
            </style>

            <?php 
                $gStart = jalali_to_gregorian($jy, $jm, 1);
                $tsStart = mktime(0, 0, 0, $gStart[1], $gStart[2], $gStart[0]);
                $daysInMonth = ($jm <= 6) ? 31 : (($jm == 12) ? ((((($jy - 474) % 2820) + 474) % 33) % 4 == 0 ? 30 : 29) : 30);
                $startDayOfWeek = (int)date('w', $tsStart);
                $offset = ($startDayOfWeek + 1) % 7; 
            ?>

            <div class="cal-v2-grid">
                <?php $daysName = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه']; ?>
                <?php foreach($daysName as $d) echo "<div class='cal-v2-header'>$d</div>"; ?>
                
                <?php 
                for($i = 0; $i < 42; $i++): 
                    $dayNum = $i - $offset + 1;
                    $isCurrentMonth = ($dayNum > 0 && $dayNum <= $daysInMonth);
                    $cellTs = 0;
                    if ($isCurrentMonth) {
                        $gCell = jalali_to_gregorian($jy, $jm, $dayNum);
                        $cellTs = mktime(0,0,0, $gCell[1], $gCell[2], $gCell[0]);
                    }
                    $isToday = (date('Y-m-d') == date('Y-m-d', $cellTs));
                    $dayPosts = [];
                    if ($isCurrentMonth) {
                        $dayPosts = array_filter($project['schedules'] ?? [], function($s) use ($cellTs) {
                            $sAt = $s['scheduledAt'] ?? $s['scheduled_at'] ?? 0;
                            $t = is_numeric($sAt) ? $sAt : strtotime($sAt);
                            return $t > 0 && date('Y-m-d', $t) == date('Y-m-d', $cellTs);
                        });
                    }
                ?>
                    <div class="cal-v2-cell <?php echo $isToday ? 'cal-v2-today' : ''; ?> <?php echo !$isCurrentMonth ? 'bg-slate-50 opacity-40 pointers-none' : ''; ?> <?php echo !empty($dayPosts) ? 'has-posts' : ''; ?>">
                        <?php if($isCurrentMonth): ?>
                            <div class="flex justify-between items-center mb-1">
                                <span class="cal-v2-day"><?php echo $dayNum; ?></span>
                                <span class="md:hidden text-[11px] text-slate-400 font-extrabold">(<?php echo $daysName[$i % 7]; ?>)</span>
                            </div>
                            <div class="cal-v2-posts">
                                <?php 
                                foreach($dayPosts as $s):
                                    $st = $s['status'] ?? 'pending';
                                    $ast = $s['approvalStatus'] ?? 'pending';
                                    $cls = "st-$ast";
                                    if ($st === 'shipped') $cls = "st-shipped";
                                    if ($ast === 'rejected') $cls = "st-rejected";
                                    
                                    // Inject product image if needed
                                    $pImg = '';
                                    if (isset($s['productId'])) {
                                        foreach($project['products'] as $prod) {
                                            if($prod['id'] == $s['productId']) { $pImg = $prod['image']; break; }
                                        }
                                    }
                                    $s['pImg'] = $pImg;
                                ?>
                                    <script>window.portalSchedules['<?php echo $s['id']; ?>'] = <?php echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                                    <div class="cal-v2-post <?php echo $cls; ?>" data-status="<?php echo $ast; ?>" data-platform="<?php echo $s['platform']; ?>" onclick="showScheduleModalById('<?php echo $s['id']; ?>')">
                                        <span class="cal-v2-platform"><?php echo strtoupper(substr($s['platform'], 0, 2)); ?></span>
                                        <span class="truncate"><?php echo htmlspecialchars($s['productName'] ?? 'بدون نام'); ?></span>
                                        <?php if (!empty($project['settings']['cal_approval_split_enabled'])): 
                                            $c1 = count($s['approvals_stage1'] ?? []);
                                            $c2 = count($s['approvals_stage2'] ?? []);
                                            $m1 = (int)($project['settings']['cal_approval_stage1_min'] ?? 1);
                                            $m2 = (int)($project['settings']['cal_approval_stage2_min'] ?? 1);
                                        ?>
                                            <span class="text-[8px] bg-black/20 px-1 py-0.5 rounded mr-auto select-none font-bold" style="font-size: 8px;">
                                                (۱:<?php echo "$c1/$m1"; ?>، ۲:<?php echo "$c2/$m2"; ?>)
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="mt-8 pt-6 border-t border-slate-100 flex flex-wrap gap-5 text-[10px] font-black text-slate-400 uppercase tracking-widest justify-center">
            <div class="flex items-center gap-2"><span class="w-3.5 h-3.5 bg-brand-500 rounded-lg"></span> تایید شده</div>
            <div class="flex items-center gap-2"><span class="w-3.5 h-3.5 bg-slate-400 rounded-lg border border-slate-200"></span> در انتظار تایید</div>
            <div class="flex items-center gap-2"><span class="w-3.5 h-3.5 bg-rose-500 rounded-lg"></span> رد شده</div>
            <div class="flex items-center gap-2" style="opacity: 0.5;"><span class="w-3.5 h-3.5 bg-slate-800 rounded-lg"></span> ارسال شده</div>
        </div>
    </div>

    <!-- Tab 3: Library (Approved) -->
    <div id="tab-library" class="tab-content <?php echo (($active_tab ?? 'pending') === 'library') ? 'active' : ''; ?>">
        
        <!-- Status & Inventory Intelligence Panel -->
        <div class="mb-8 p-6 sm:p-8 bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 rounded-[2.5rem] text-white shadow-2xl border border-indigo-500/30 relative overflow-hidden">
            <!-- Ambient Glow -->
            <div class="absolute -right-20 -top-20 w-80 h-80 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-20 -bottom-20 w-80 h-80 bg-purple-500/20 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10">
                <!-- Header & Meta -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <div class="flex items-center gap-3.5">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/20 border border-indigo-400/30 flex items-center justify-center text-2xl shadow-inner backdrop-blur-sm">
                            📊
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base sm:text-lg font-black text-white">وضعیت و گزارش‌های موجودی کاتالوگ</h3>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-indigo-500/30 text-indigo-200 border border-indigo-400/20">تحلیل هوشمند انبار</span>
                            </div>
                            <p class="text-xs text-indigo-200/80 font-normal mt-0.5">محاسبه بلادرنگ تعداد کالاها، تیراژ تجمیعی و گزارشات تفکیکی با آستانه قابل تنظیم</p>
                        </div>
                    </div>

                    <!-- Top Action Buttons -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <button type="button" onclick="copyCatalogStatusReport()" class="px-4 py-2.5 bg-white/10 hover:bg-white/20 text-indigo-100 hover:text-white rounded-2xl text-xs font-bold border border-white/10 transition-all flex items-center gap-2 cursor-pointer active:scale-95 shadow-sm">
                            <span>📋</span>
                            <span>کپی گزارش آماری</span>
                        </button>
                        <button type="button" onclick="openStatusAnalyticsModal()" class="px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-violet-600 hover:from-indigo-600 hover:to-violet-700 text-white rounded-2xl text-xs font-black shadow-lg shadow-indigo-500/30 transition-all flex items-center gap-2 cursor-pointer active:scale-95">
                            <span>🔍</span>
                            <span>داشبورد تحلیلی جامع</span>
                        </button>
                    </div>
                </div>

                <!-- Interactive Threshold & Scope Settings Bar -->
                <div class="flex flex-wrap items-center justify-between gap-4 p-4 bg-white/5 rounded-2xl border border-white/10 mb-6 backdrop-blur-md">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-xs font-bold text-indigo-250 flex items-center gap-1.5">
                            <span>⚙️</span>
                            <span>عدد آستانه موجودی:</span>
                        </span>
                        <div class="flex items-center bg-black/40 rounded-xl border border-white/15 p-1 shadow-inner">
                            <button type="button" onclick="stepThreshold(-1)" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 text-white flex items-center justify-center font-black text-sm transition-all cursor-pointer select-none">-</button>
                            <input type="number" id="status-threshold-input" min="1" max="9999" value="<?php echo htmlspecialchars((string)$f_stock_min); ?>" oninput="updateStatusAnalytics(this.value)" class="w-14 text-center bg-transparent font-mono font-black text-base text-amber-300 outline-none">
                            <button type="button" onclick="stepThreshold(1)" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 text-white flex items-center justify-center font-black text-sm transition-all cursor-pointer select-none">+</button>
                        </div>
                        <!-- Quick Chip Selectors -->
                        <div class="flex items-center gap-1.5 overflow-x-auto custom-scrollbar py-1">
                            <button type="button" onclick="setThreshold(1)" class="threshold-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white/5 hover:bg-white/15 text-slate-300 border border-white/5 transition-all">۱+</button>
                            <button type="button" onclick="setThreshold(3)" class="threshold-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white/5 hover:bg-white/15 text-slate-300 border border-white/5 transition-all">۳+</button>
                            <button type="button" onclick="setThreshold(5)" class="threshold-chip active-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-indigo-500 text-white border border-indigo-400 transition-all">۵+</button>
                            <button type="button" onclick="setThreshold(10)" class="threshold-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white/5 hover:bg-white/15 text-slate-300 border border-white/5 transition-all">۱۰+</button>
                            <button type="button" onclick="setThreshold(20)" class="threshold-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white/5 hover:bg-white/15 text-slate-300 border border-white/5 transition-all">۲۰+</button>
                            <button type="button" onclick="setThreshold(50)" class="threshold-chip px-2.5 py-1 rounded-lg text-[11px] font-bold bg-white/5 hover:bg-white/15 text-slate-300 border border-white/5 transition-all">۵۰+</button>
                        </div>
                    </div>

                    <!-- Scope Switcher -->
                    <div class="flex items-center gap-1 bg-black/40 p-1 rounded-xl border border-white/10 text-xs shadow-inner">
                        <button type="button" id="scope-btn-approved" onclick="setAnalyticsScope('approved')" class="px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm">کاتالوگ تایید شده</button>
                        <button type="button" id="scope-btn-all" onclick="setAnalyticsScope('all')" class="px-3 py-1.5 rounded-lg font-bold transition-all text-slate-400 hover:text-white">کل محصولات پروژه</button>
                    </div>
                </div>

                <!-- 4 Real-time Reactive Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Card 1: Aggregated Stock >= N -->
                    <div onclick="applyStatusFilterToLibrary('stock_agg_gte')" class="bg-white/10 hover:bg-white/15 border border-white/15 hover:border-amber-400/50 p-4 rounded-2xl transition-all cursor-pointer group shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-amber-300 flex items-center gap-1.5">
                                <span>🔥</span>
                                <span>موجودی تجمیعی بالای <span class="threshold-num-display"><?php echo $f_stock_min; ?></span></span>
                            </span>
                            <span class="text-[10px] px-2 py-0.5 bg-amber-500/20 text-amber-300 rounded-full font-black">خانواده کالا</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="stat-agg-count" class="text-2xl font-black text-white font-mono">۰</span>
                            <span class="text-xs text-slate-300 font-bold">کالا</span>
                        </div>
                        <div class="mt-2 text-[11px] text-amber-200/80 flex items-center justify-between border-t border-white/10 pt-2">
                            <span>تیراژ کل قطعات:</span>
                            <span id="stat-agg-sum" class="font-bold font-mono text-white">۰ عدد</span>
                        </div>
                        <div class="mt-2 text-[10px] text-amber-300 font-black group-hover:translate-x-[-2px] transition-transform flex items-center gap-1">
                            <span>اعمال فیلتر در کتابخانه ←</span>
                        </div>
                    </div>

                    <!-- Card 2: Direct Stock >= N -->
                    <div onclick="applyStatusFilterToLibrary('stock_direct_gte')" class="bg-white/10 hover:bg-white/15 border border-white/15 hover:border-emerald-400/50 p-4 rounded-2xl transition-all cursor-pointer group shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-emerald-300 flex items-center gap-1.5">
                                <span>📦</span>
                                <span>موجودی تک‌کالا بالای <span class="threshold-num-display"><?php echo $f_stock_min; ?></span></span>
                            </span>
                            <span class="text-[10px] px-2 py-0.5 bg-emerald-500/20 text-emerald-300 rounded-full font-black">مستقیم</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="stat-direct-count" class="text-2xl font-black text-white font-mono">۰</span>
                            <span class="text-xs text-slate-300 font-bold">کالا</span>
                        </div>
                        <div class="mt-2 text-[11px] text-emerald-200/80 flex items-center justify-between border-t border-white/10 pt-2">
                            <span>مجموع موجودی:</span>
                            <span id="stat-direct-sum" class="font-bold font-mono text-white">۰ عدد</span>
                        </div>
                        <div class="mt-2 text-[10px] text-emerald-300 font-black group-hover:translate-x-[-2px] transition-transform flex items-center gap-1">
                            <span>اعمال فیلتر در کتابخانه ←</span>
                        </div>
                    </div>

                    <!-- Card 3: Low stock (1 to N-1) -->
                    <div onclick="applyStatusFilterToLibrary('stock_low')" class="bg-white/10 hover:bg-white/15 border border-white/15 hover:border-rose-400/50 p-4 rounded-2xl transition-all cursor-pointer group shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-rose-300 flex items-center gap-1.5">
                                <span>⚠️</span>
                                <span>کم‌موجودی (۱ تا <span class="threshold-minus-one-display"><?php echo max(1, $f_stock_min - 1); ?></span>)</span>
                            </span>
                            <span class="text-[10px] px-2 py-0.5 bg-rose-500/20 text-rose-300 rounded-full font-black">هشدار اتمام</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="stat-low-count" class="text-2xl font-black text-white font-mono">۰</span>
                            <span class="text-xs text-slate-300 font-bold">کالا</span>
                        </div>
                        <div class="mt-2 text-[11px] text-rose-200/80 flex items-center justify-between border-t border-white/10 pt-2">
                            <span>باقی‌مانده انبار:</span>
                            <span id="stat-low-sum" class="font-bold font-mono text-white">۰ عدد</span>
                        </div>
                        <div class="mt-2 text-[10px] text-rose-300 font-black group-hover:translate-x-[-2px] transition-transform flex items-center gap-1">
                            <span>مشاهده کم‌موجودی‌ها ←</span>
                        </div>
                    </div>

                    <!-- Card 4: Out of stock (0) & Media breakdown -->
                    <div onclick="switchTab('out-of-stock')" class="bg-white/10 hover:bg-white/15 border border-white/15 hover:border-slate-400/50 p-4 rounded-2xl transition-all cursor-pointer group shadow-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-slate-300 flex items-center gap-1.5">
                                <span>🚫</span>
                                <span>بدون موجودی (۰ عدد)</span>
                            </span>
                            <span class="text-[10px] px-2 py-0.5 bg-slate-500/30 text-slate-300 rounded-full font-black">ناموجود</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="stat-out-count" class="text-2xl font-black text-white font-mono">۰</span>
                            <span class="text-xs text-slate-300 font-bold">کالا</span>
                        </div>
                        <div class="mt-2 text-[11px] text-slate-300 flex items-center justify-between border-t border-white/10 pt-2">
                            <span>عکس‌دار / بدون عکس:</span>
                            <span id="stat-media-ratio" class="font-bold font-mono text-white">۰ / ۰</span>
                        </div>
                        <div class="mt-2 text-[10px] text-slate-300 font-black group-hover:translate-x-[-2px] transition-transform flex items-center gap-1">
                            <span>مشاهده کاتالوگ ناموجود ←</span>
                        </div>
                    </div>
                </div>

                <!-- Visual Stock Distribution Bar -->
                <div class="mt-6 p-4 bg-black/30 rounded-2xl border border-white/10">
                    <div class="flex items-center justify-between text-xs text-slate-300 mb-2 font-bold">
                        <span>توزیع آماری وضعیت محصولات کاتالوگ:</span>
                        <span id="distribution-total-label" class="font-mono text-indigo-300">کل: ۰ کالا</span>
                    </div>
                    <div class="w-full h-3.5 bg-white/10 rounded-full overflow-hidden flex gap-0.5 p-0.5">
                        <div id="bar-agg" class="h-full bg-amber-500 rounded-l-full transition-all duration-500" title="موجودی تجمیعی بالا" style="width: 0%;"></div>
                        <div id="bar-direct" class="h-full bg-emerald-500 transition-all duration-500" title="موجودی مستقیم بالا" style="width: 0%;"></div>
                        <div id="bar-low" class="h-full bg-rose-500 transition-all duration-500" title="کم موجودی" style="width: 0%;"></div>
                        <div id="bar-out" class="h-full bg-slate-600 rounded-r-full transition-all duration-500" title="ناموجود" style="width: 0%;"></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mt-2.5 text-[11px] text-slate-300">
                        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span><span>تجمیعی بالای <span class="threshold-num-display"><?php echo $f_stock_min; ?></span> (<span id="pct-agg">۰٪</span>)</span></div>
                        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span><span>تک‌کالا بالای <span class="threshold-num-display"><?php echo $f_stock_min; ?></span> (<span id="pct-direct">۰٪</span>)</span></div>
                        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span><span>کم‌موجودی (<span id="pct-low">۰٪</span>)</span></div>
                        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-600"></span><span>بدون موجودی (<span id="pct-out">۰٪</span>)</span></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($can_request_content_gen): ?>
        <!-- Bulk Action Header Bar for Publish/Calendar Priority -->
        <div class="flex flex-wrap items-center justify-between gap-4 p-5 bg-gradient-to-r from-indigo-50 to-blue-50 rounded-[2rem] border border-indigo-100/60 mb-6 shadow-sm">
            <div class="flex items-center gap-3">
                <input type="checkbox" id="library-select-all" class="w-5 h-5 cursor-pointer rounded border-indigo-300 text-indigo-600 focus:ring-indigo-500" onclick="toggleSelectAllLibrary(this)">
                <div>
                    <span class="text-xs font-black text-indigo-800 block">انتخاب دسته جمعی کالاها برای اولویت تقویم/انتشار 📌</span>
                    <span class="text-[10px] text-indigo-650 font-semibold block">برای تعیین اولویت انتشار و تقویم کالاها به صورت فله‌ای تیک بزنید.</span>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-2">
                <button onclick="resetPublishPriority()" class="px-6 py-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-2xl text-xs font-black shadow-sm active:translate-y-0.5 transform transition-all flex items-center gap-2">
                    <span>🔄 ریست اولویت انتشار</span>
                </button>
                <button onclick="submitBulkPublishPriorityRequest()" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-xs font-black shadow-lg shadow-indigo-500/20 active:translate-y-0.5 transform transition-all flex items-center gap-2">
                    <span>🚀 ارسال درخواست اولویت انتشار/تقویم</span>
                    <span id="selected-library-count" class="bg-white/25 px-2 py-0.5 rounded-full text-[10px]">۰ کالا</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can_create_couples): ?>
        <!-- Couple/Set Creation Header Bar -->
        <div class="flex flex-wrap items-center justify-between gap-4 p-5 bg-gradient-to-r from-rose-50 to-pink-50 rounded-[2rem] border border-rose-100/60 mb-8 shadow-sm">
            <div class="flex items-center gap-3 font-bold text-slate-800">
                <?php if (!$can_request_content_gen): ?>
                    <!-- If the content gen bar isn't rendered, render the select all checkbox here -->
                    <input type="checkbox" id="library-select-all" class="w-5 h-5 cursor-pointer rounded border-rose-300 text-rose-600 focus:ring-rose-500" onclick="toggleSelectAllLibrary(this)">
                <?php else: ?>
                    <span class="text-xl">👥</span>
                <?php endif; ?>
                <div>
                    <span class="text-xs font-black text-rose-800 block">ساخت محصول کاپل / ست مجزا 👥</span>
                    <span class="text-[10px] text-rose-650 font-semibold block">دو محصول تایید شده را انتخاب کنید و جهت ادغام و ساخت محصول ست دکمه مقابل را کلیک کنید.</span>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-2">
                <button onclick="openCoupleSetModal()" class="px-6 py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl text-xs font-black shadow-lg shadow-rose-500/20 active:translate-y-0.5 transform transition-all flex items-center gap-2">
                    <span>✨ ساخت محصول کاپل / ست (ادغام ۲ کالا)</span>
                    <span id="selected-couples-count-badge" class="bg-white/25 px-2 py-0.5 rounded-full text-[10px]">۰ کالا</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php 
            $active_products = array_filter((array)($project['products'] ?? []), function($p) { $st = $p['status'] ?? 'active'; $isNoImg = is_product_without_image($p); return ($st === 'active' || empty($st)) && !$isNoImg; });
            
            // Pre-calculate group-representative values
            $groupMaxScore = [];
            $groupMinPrice = [];
            $groupMaxPrice = [];
            $groupMaxSku = [];
            $groupMinSku = [];

            foreach ($active_products as $p) {
                $groupKey = trim(preg_replace('/\s+/', ' ', mb_strtolower($p['name'] ?? '')));
                $likes = (int)($p['likes'] ?? 0);
                $dislikes = (int)($p['dislikes'] ?? 0);
                $comments = count($p['userFeedback'] ?? $p['user_feedback'] ?? []);
                $popScore = $likes - $dislikes + $comments;

                $sku = (int)preg_replace('/[^0-9]/', '', $p['sku'] ?? $p['id'] ?? '0');
                
                $priceStr = trim($p['price'] ?? '');
                $price = (float)preg_replace('/[^0-9]/', '', $priceStr);
                if ($price <= 0) $price = 99999999;

                if (!isset($groupMaxScore[$groupKey]) || $popScore > $groupMaxScore[$groupKey]) {
                    $groupMaxScore[$groupKey] = $popScore;
                }
                if (!isset($groupMaxSku[$groupKey]) || $sku > $groupMaxSku[$groupKey]) {
                    $groupMaxSku[$groupKey] = $sku;
                }
                if (!isset($groupMinSku[$groupKey]) || $sku < $groupMinSku[$groupKey]) {
                    $groupMinSku[$groupKey] = $sku;
                }
                if (!isset($groupMinPrice[$groupKey]) || $price < $groupMinPrice[$groupKey]) {
                    $groupMinPrice[$groupKey] = $price;
                }
                if (!isset($groupMaxPrice[$groupKey]) || $price > $groupMaxPrice[$groupKey]) {
                    $groupMaxPrice[$groupKey] = $price;
                }
            }

            usort($active_products, function($a, $b) use ($groupMaxScore, $groupMaxSku, $groupMinSku, $groupMinPrice, $groupMaxPrice, $f_sort) {
                $a_pub = !empty($a['publish_priority']) ? 1 : 0;
                $b_pub = !empty($b['publish_priority']) ? 1 : 0;
                if ($a_pub !== $b_pub) {
                    return $b_pub <=> $a_pub;
                }

                $groupKeyA = trim(preg_replace('/\s+/', ' ', mb_strtolower($a['name'] ?? '')));
                $groupKeyB = trim(preg_replace('/\s+/', ' ', mb_strtolower($b['name'] ?? '')));

                if ($f_sort === 'stock_desc') {
                    $stA = getProductItemStock($a);
                    $stB = getProductItemStock($b);
                    if ($stA != $stB) {
                        return $stB <=> $stA;
                    }
                } elseif ($f_sort === 'stock_asc') {
                    $stA = getProductItemStock($a);
                    $stB = getProductItemStock($b);
                    if ($stA != $stB) {
                        return $stA <=> $stB;
                    }
                } elseif ($f_sort === 'cheapest') {
                    $valA = $groupMinPrice[$groupKeyA] ?? 99999999;
                    $valB = $groupMinPrice[$groupKeyB] ?? 99999999;
                    if ($valA != $valB) {
                        return $valA <=> $valB;
                    }
                } elseif ($f_sort === 'expensive') {
                    $valA = $groupMaxPrice[$groupKeyA] ?? 0;
                    $valB = $groupMaxPrice[$groupKeyB] ?? 0;
                    if ($valA != $valB) {
                        return $valB <=> $valA;
                    }
                } elseif ($f_sort === 'oldest') {
                    $valA = $groupMinSku[$groupKeyA] ?? 0;
                    $valB = $groupMinSku[$groupKeyB] ?? 0;
                    if ($valA != $valB) {
                        return $valA <=> $valB;
                    }
                } elseif ($f_sort === 'newest') {
                    $valA = $groupMaxSku[$groupKeyA] ?? 0;
                    $valB = $groupMaxSku[$groupKeyB] ?? 0;
                    if ($valA != $valB) {
                        return $valB <=> $valA;
                    }
                } else {
                    // Default: Sort by last approval time / date descending
                    $appA = getProductApprovedAt($a);
                    $appB = getProductApprovedAt($b);
                    if ($appA != $appB) {
                        return $appB <=> $appA;
                    }
                }

                // Local nested sorting inside key groups
                if ($groupKeyA === $groupKeyB) {
                    $localSkuA = (int)preg_replace('/[^0-9]/', '', $a['sku'] ?? $a['id'] ?? '0');
                    $localSkuB = (int)preg_replace('/[^0-9]/', '', $b['sku'] ?? $b['id'] ?? '0');
                    if ($f_sort === 'oldest' || $f_sort === 'stock_asc') {
                        return $localSkuA <=> $localSkuB;
                    }
                    return $localSkuB <=> $localSkuA;
                }

                return strcmp($groupKeyA, $groupKeyB);
            });
            
            $total_active = count($active_products);
            $total_active_pages = ceil($total_active / $limit);
            if (($active_tab ?? 'pending') === 'library') {
                $active_products = array_slice($active_products, ($page - 1) * $limit, $limit);
            } else {
                $active_products = array_slice($active_products, 0, $limit);
            }
            
            foreach($active_products as $p):
                $image = getPrioritizedProductImage($p);
                if (strpos($image, 'http') !== 0) {
                    $image = getFullUrl($image);
                }
                
                // Comment count
                $commentCount = count($p['userFeedback'] ?? []);
            ?>
                <?php $p['uploader_info'] = getUploaderDetails($p, $project); ?>
                <script>window.portalProducts['<?php echo $p['id']; ?>'] = <?php echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                <div class="bg-white rounded-[2rem] border border-slate-100 hover:border-brand-300 shadow-premium hover:shadow-elevated hover-lift overflow-hidden flex flex-col cursor-pointer animate-fade-in group" onclick="openDetailById('<?php echo $p['id']; ?>')">
                    <div class="relative aspect-[4/5] overflow-hidden bg-slate-50">
                        <?php renderCardImageCarousel($p); ?>
                        
                        <!-- Bulk Selection Checkbox for Library -->
                        <?php if ($can_request_content_gen || $can_create_couples): ?>
                            <div class="absolute top-4 left-4 z-30" onclick="event.stopPropagation();">
                                <input type="checkbox" name="selected_library_products[]" value="<?php echo $p['id']; ?>" class="w-6 h-6 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500 cursor-pointer shadow-md bg-white/90 backdrop-blur-md transition-all duration-200 transform hover:scale-110" onchange="updateSelectedLibraryCount()">
                            </div>
                        <?php endif; ?>

                        <!-- Top Badges -->
                        <div class="absolute top-4 right-4 flex flex-col gap-2 z-20 pointer-events-none">
                            <span class="bg-emerald-500/90 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1">
                                <span>✅</span>
                                <span>تایید شده</span>
                            </span>
                            <?php if (!empty($p['publish_priority'])): ?>
                                <span class="bg-indigo-650/95 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1.5 animate-pulse">
                                    <span>📌</span>
                                    <span>اولویت انتشار</span>
                                </span>
                            <?php endif; ?>
                            <?php if ($commentCount > 0): ?>
                                <span class="bg-brand-500/90 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full flex items-center gap-1.5 shadow-sm">
                                    <span>💬</span>
                                    <span><?php echo $commentCount; ?> بازخورد</span>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Creator Badge -->
                        <?php if (!empty($p['uploaded_by'])): ?>
                            <div class="absolute bottom-4 left-4 bg-black/60 backdrop-blur-md text-white text-[9px] font-bold px-2.5 py-1 rounded-xl flex items-center gap-1 shadow-sm z-20 border border-white/10 pointer-events-none" title="ثبت‌کننده: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> | آیدی: <?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>">
                                <span>📸</span>
                                <span>ثبت: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> (<?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-5 flex-1 flex flex-col bg-white" onclick="event.stopPropagation()">
                        <div class="font-bold text-sm text-slate-800 line-clamp-2 h-10 mb-2"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="flex items-center justify-between gap-2 mb-4">
                            <div class="text-xs font-black text-brand-600"><?php echo $p['price']; ?></div>
                            <div class="flex items-center gap-1">
                                <?php 
                                $itemStock = getProductItemStock($p);
                                $aggStock = getProductAggregatedStock($p, $familyStockMap ?? null);
                                if ($itemStock > 0): ?>
                                    <span class="text-[9px] font-black <?php echo ($itemStock >= 5 || $aggStock >= 5) ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-slate-600 bg-slate-100 border-slate-200'; ?> border px-2 py-0.5 rounded-full flex items-center gap-1" title="موجودی این قلم: <?php echo $itemStock; ?> عدد<?php echo ($aggStock > $itemStock) ? ' | مجموع تمام رنگ‌ها: ' . $aggStock . ' عدد' : ''; ?>">
                                        <span>📦</span>
                                        <span><?php echo $itemStock; ?></span>
                                        <?php if ($aggStock > $itemStock): ?>
                                            <span class="text-[8px] opacity-75 font-normal">(کل: <?php echo $aggStock; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($p['variants']) && count($p['variants']) > 1): ?>
                                    <span class="text-[9px] font-black text-indigo-650 bg-indigo-50 border border-indigo-100/50 px-2 py-0.5 rounded-full flex items-center gap-1.5 transition-all">
                                        <span>🎨</span>
                                        <span><?php echo count($p['variants']); ?> رنگ</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex gap-2 mb-4 justify-center">
                            <?php 
                            $myVoteInCard = $p['voterVotes'][$voterId] ?? null;
                            $likeBtnClass = $myVoteInCard === 'like' 
                                ? "flex-1 py-2.5 rounded-xl bg-emerald-500 border-emerald-500 text-white flex items-center justify-center text-xs font-black hover:bg-emerald-600 transition-all gap-1.5 shadow-sm" 
                                : "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all gap-1.5";
                            
                            $dislikeBtnClass = $myVoteInCard === 'dislike' 
                                ? "flex-1 py-2.5 rounded-xl bg-rose-500 border-rose-500 text-white flex items-center justify-center text-xs font-black hover:bg-rose-600 transition-all gap-1.5 shadow-sm" 
                                : "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition-all gap-1.5";
                            ?>
                            <button onclick="vote('like', '<?php echo $p['id']; ?>')" class="<?php echo $likeBtnClass; ?>" id="like-btn-<?php echo $p['id']; ?>">
                                <span>❤️</span>
                                <span id="likes-<?php echo $p['id']; ?>"><?php echo $p['likes'] ?? 0; ?></span>
                            </button>
                            <button onclick="vote('dislike', '<?php echo $p['id']; ?>')" class="<?php echo $dislikeBtnClass; ?>" id="dislike-btn-<?php echo $p['id']; ?>">
                                <span>👎</span>
                                <span id="dislikes-<?php echo $p['id']; ?>"><?php echo $p['dislikes'] ?? 0; ?></span>
                            </button>
                        </div>

                        <!-- Split Approval status on Card -->
                        <?php if (!empty($project['settings']['prod_approval_split_enabled'])): 
                            $st1_count = count($p['approvals_stage1'] ?? []);
                            $st2_count = count($p['approvals_stage2'] ?? []);
                            $st1_min = (int)($project['settings']['prod_approval_stage1_min'] ?? 1);
                            $st2_min = (int)($project['settings']['prod_approval_stage2_min'] ?? 1);
                            
                            $st1_ok = $st1_count >= $st1_min;
                            $st2_ok = $st2_count >= $st2_min;
                            
                            $st1_text = "⏳ در انتظار";
                            $st1_class = "text-amber-500";
                            if (!empty($p['stage1_decision']) && $p['stage1_decision'] === 'rejected') {
                                $st1_text = "❌ رد شده";
                                $st1_class = "text-rose-500 font-bold";
                            } elseif (!empty($p['stage1_system_approved']) || in_array('سیستم (پاسخ خودکار)', $p['approvals_stage1'] ?? [])) {
                                $st1_text = "⚙️ تایید سیستمی";
                                $st1_class = "text-emerald-600 font-bold";
                            } elseif ($st1_ok) {
                                $st1_text = "✅ تایید شده";
                                $st1_class = "text-emerald-600 font-bold";
                            }

                            $st2_text = "⏳ در انتظار";
                            $st2_class = "text-amber-500";
                            if (!empty($p['stage2_decision']) && $p['stage2_decision'] === 'rejected') {
                                $st2_text = "❌ رد شده";
                                $st2_class = "text-rose-500 font-bold";
                            } elseif (!empty($p['stage2_system_approved']) || in_array('سیستم (پاسخ خودکار)', $p['approvals_stage2'] ?? [])) {
                                $st2_text = "⚙️ تایید سیستمی";
                                $st2_class = "text-emerald-600 font-bold";
                            } elseif ($st2_ok) {
                                $st2_text = "✅ تایید شده";
                                $st2_class = "text-emerald-600 font-bold";
                            }
                        ?>
                            <div class="mb-3 bg-slate-50 p-2.5 border border-slate-100/60 space-y-1.5 text-[10px] font-black rounded-2xl">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">🛡️ استایل و محتوا (تیم ۱):</span>
                                    <span class="<?php echo $st1_class; ?>">
                                        <?php echo "{$st1_text} ({$st1_count}/{$st1_min})"; ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">📐 جزئیات و دقت (تیم ۲):</span>
                                    <span class="<?php echo $st2_class; ?>">
                                        <?php echo "{$st2_text} ({$st2_count}/{$st2_min})"; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Current active Rejection on Card -->
                        <?php if (($p['status'] ?? 'active') === 'rejected' || !empty($p['rejectionReason'])): ?>
                            <div class="mb-3 bg-rose-50 p-2.5 rounded-2xl border border-rose-100 text-[10px] font-semibold text-rose-700">
                                <div class="flex items-center gap-1 mb-1 font-black">
                                    <span>🚫 علت رد شدن:</span>
                                    <span><?php echo htmlspecialchars($p['rejection_side'] ?? 'نامشخص'); ?></span>
                                </div>
                                <p class="text-[11px] leading-relaxed whitespace-pre-wrap font-bold bg-white/70 p-1.5 rounded-lg border border-rose-100"><?php echo htmlspecialchars($p['rejectionReason'] ?? 'توضیحی ثبت نشده است.'); ?></p>
                                <div class="text-[9px] text-rose-500 mt-1.5 text-left font-black">توسط: <?php echo htmlspecialchars($p['rejection_by'] ?? 'مدیر'); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="space-y-3 mt-auto">
                             <textarea id="comment-<?php echo $p['id']; ?>" class="w-full text-xs p-3 border border-slate-100 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none custom-scrollbar bg-slate-50 transition-all placeholder:text-slate-400" rows="2" placeholder="ارسال پیشنهاد یا اصلاحیه جدید..."></textarea>
                             
                             <?php if (!empty($project['settings']['feedbackOptions'])): ?>
                                 <div class="flex flex-wrap gap-1.5 pt-1 select-none">
                                    <?php foreach($project['settings']['feedbackOptions'] as $f): ?>
                                        <button onclick="document.getElementById('comment-<?php echo $p['id']; ?>').value = '<?php echo addslashes($f['text']); ?>'" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200/60 hover:border-slate-300 text-slate-500 hover:text-brand-600 transition-all">
                                            <?php echo htmlspecialchars($f['text']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                 </div>
                             <?php endif; ?>
                             
                             <button onclick="portalComment('<?php echo $p['id']; ?>')" class="w-full py-2.5 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-black transition-all">ثبت بازخورد و بهینه‌سازی 💬</button>
                             <button onclick="openCommentsPopup('<?php echo $p['id']; ?>')" class="w-full mt-2 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-xl text-[11px] font-black tracking-wide transition-all flex items-center justify-center gap-1.5 shadow-sm">
                                 <span>👁️‍🗨️</span> مشاهده کامنت‌ها و تاریخچه
                             </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($active_products)): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-premium p-16 text-center text-slate-400 font-bold max-w-lg mx-auto flex flex-col items-center gap-3 animate-fade-in">
                <span class="text-4xl">📁</span>
                <p class="text-sm">کتابخانه محصولات خالی است.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($total_active_pages > 1): ?>
            <div class="flex justify-center items-center gap-2 mt-12 pb-6">
                <?php for($i=1; $i<=$total_active_pages; $i++): 
                    $pageUrl = 'portal.php?' . http_build_query(array_merge($_GET, ['tab' => 'library', 'p' => $i]));
                ?>
                    <a href="<?php echo htmlspecialchars($pageUrl); ?>" class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-black <?php echo ($active_tab == 'library' && $page == $i) ? 'bg-brand-600 text-white shadow-md' : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'; ?> transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 4: Out of Stock -->
    <div id="tab-out-of-stock" class="tab-content <?php echo (($active_tab ?? 'pending') === 'out-of-stock') ? 'active' : ''; ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php 
            $oos = array_filter((array)($project['products'] ?? []), 'isProductOutOfStock');
            usort($oos, function($a, $b) { return (int)($b['extractedAt'] ?? 0) - (int)($a['extractedAt'] ?? 0); });
            
            $total_oos = count($oos);
            $total_oos_pages = ceil($total_oos / $limit);
            if (($active_tab ?? 'pending') === 'out-of-stock') {
                $oos = array_slice($oos, ($page - 1) * $limit, $limit);
            } else {
                $oos = array_slice($oos, 0, $limit);
            }
            
            foreach($oos as $p):
                $image = getPrioritizedProductImage($p);
                if (strpos($image, 'http') !== 0) {
                    $image = getFullUrl($image);
                }
            ?>
                <?php $p['uploader_info'] = getUploaderDetails($p, $project); ?>
                <script>window.portalProducts['<?php echo $p['id']; ?>'] = <?php echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                <div class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden flex flex-col opacity-75 grayscale hover:grayscale-0 hover:opacity-100 transition-all duration-300 shadow-premium" onclick="openDetailById('<?php echo $p['id']; ?>')">
                    <div class="relative aspect-[4/5] bg-slate-50 overflow-hidden">
                        <?php renderCardImageCarousel($p); ?>
                        <div class="absolute top-4 right-4 z-20 pointer-events-none">
                            <span class="bg-rose-500/90 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1">
                                <span>⚠️</span>
                                <span>ناموجود در تامین‌کننده</span>
                            </span>
                        </div>

                        <!-- Creator Badge -->
                        <?php if (!empty($p['uploaded_by'])): ?>
                            <div class="absolute bottom-4 left-4 bg-black/60 backdrop-blur-md text-white text-[9px] font-bold px-2.5 py-1 rounded-xl flex items-center gap-1 shadow-sm z-20 border border-white/10 pointer-events-none" title="ثبت‌کننده: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> | آیدی: <?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>">
                                <span>📸</span>
                                <span>ثبت: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> (<?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5 flex-1 flex flex-col bg-slate-50/50">
                        <div class="font-bold text-sm text-slate-800 line-clamp-2 mb-2"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="flex items-center justify-between gap-2 mt-auto">
                            <div class="text-xs font-black text-rose-600"><?php echo $p['price']; ?></div>
                            <?php if (!empty($p['variants']) && count($p['variants']) > 1): ?>
                                <span class="text-[9px] font-black text-indigo-650 bg-indigo-50 border border-indigo-100/50 px-2 py-0.5 rounded-full flex items-center gap-1.5 transition-all">
                                    <span>🎨</span>
                                    <span><?php echo count($p['variants']); ?> رنگ</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($oos)): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-premium p-16 text-center text-slate-400 font-bold max-w-lg mx-auto flex flex-col items-center gap-3 animate-fade-in">
                <span class="text-4xl">☘️</span>
                <p class="text-sm">همه محصولات پروژه در انبار تامین‌کننده موجود هستند.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($total_oos_pages > 1): ?>
            <div class="flex justify-center items-center gap-2 mt-12 pb-6">
                <?php for($i=1; $i<=$total_oos_pages; $i++): 
                    $pageUrl = 'portal.php?' . http_build_query(array_merge($_GET, ['tab' => 'out-of-stock', 'p' => $i]));
                ?>
                    <a href="<?php echo htmlspecialchars($pageUrl); ?>" class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-black <?php echo ($active_tab == 'out-of-stock' && $page == $i) ? 'bg-brand-600 text-white shadow-md' : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'; ?> transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 5: Rejected -->
    <div id="tab-rejected" class="tab-content <?php echo (($active_tab ?? 'pending') === 'rejected') ? 'active' : ''; ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php 
            $isProdSplit = !empty($project['settings']['prod_approval_split_enabled']);
            $rejected = array_filter((array)($project['products'] ?? []), function($p) use ($isProdSplit) {
                if (($p['status'] ?? '') === 'rejected') return true;
                if ($isProdSplit) {
                    $st1_rej = !empty($p['stage1_decision']) && $p['stage1_decision'] === 'rejected';
                    $st2_rej = !empty($p['stage2_decision']) && $p['stage2_decision'] === 'rejected';
                    if ($st1_rej || $st2_rej) return true;
                }
                return false;
            });
            usort($rejected, function($a, $b) { return (int)($b['extractedAt'] ?? 0) - (int)($a['extractedAt'] ?? 0); });
            
            $total_rejected = count($rejected);
            $total_rejected_pages = ceil($total_rejected / $limit);
            if (($active_tab ?? 'pending') === 'rejected') {
                $rejected = array_slice($rejected, ($page - 1) * $limit, $limit);
            } else {
                $rejected = array_slice($rejected, 0, $limit);
            }
            
            foreach($rejected as $p):
                $image = getPrioritizedProductImage($p);
                if (strpos($image, 'http') !== 0) {
                    $image = getFullUrl($image);
                }
            ?>
                <?php $p['uploader_info'] = getUploaderDetails($p, $project); ?>
                <script>window.portalProducts['<?php echo $p['id']; ?>'] = <?php echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                <div class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden flex flex-col opacity-75 shadow-premium cursor-pointer hover:opacity-100 transition-all duration-300 hover-lift" onclick="openDetailById('<?php echo $p['id']; ?>')">
                    <div class="relative aspect-[4/5] bg-slate-50 overflow-hidden">
                        <?php renderCardImageCarousel($p); ?>
                        <div class="absolute top-4 right-4 z-20 pointer-events-none">
                            <span class="bg-rose-500/90 backdrop-blur-md text-white text-[10px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1">
                                <span>🚫</span>
                                <span>رد شده توسط کارفرما</span>
                            </span>
                        </div>

                        <!-- Creator Badge -->
                        <?php if (!empty($p['uploaded_by'])): ?>
                            <div class="absolute bottom-4 left-4 bg-black/60 backdrop-blur-md text-white text-[9px] font-bold px-2.5 py-1 rounded-xl flex items-center gap-1 shadow-sm z-20 border border-white/10 pointer-events-none" title="ثبت‌کننده: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> | آیدی: <?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>">
                                <span>📸</span>
                                <span>ثبت: <?php echo htmlspecialchars($p['uploader_info']['name'] ?? $p['uploaded_by']); ?> (<?php echo htmlspecialchars($p['uploader_info']['id'] ?? $p['uploaded_by']); ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5 flex-1 flex flex-col bg-slate-50/55">
                        <div class="font-bold text-sm text-slate-800 line-clamp-2 mb-2"><?php echo htmlspecialchars($p['name']); ?></div>
                        
                        <!-- Current active Rejection on Card -->
                        <?php if (($p['status'] ?? 'active') === 'rejected' || !empty($p['rejectionReason'])): ?>
                            <div class="mt-2 mb-3 bg-rose-100/50 p-2.5 rounded-xl border border-rose-200 text-[10px] font-semibold text-rose-800">
                                <div class="flex items-center gap-1 mb-1 font-black">
                                    <span>🚫 علت رد شدن:</span>
                                    <span><?php echo htmlspecialchars($p['rejection_side'] ?? 'نامشخص'); ?></span>
                                </div>
                                <p class="text-[11px] leading-relaxed whitespace-pre-wrap font-bold bg-white/70 p-1.5 rounded-lg border border-rose-200/40"><?php echo htmlspecialchars($p['rejectionReason'] ?? 'توضیحی ثبت نشده است.'); ?></p>
                                <div class="text-[9px] text-rose-600 mt-1.5 text-left font-black">توسط: <?php echo htmlspecialchars($p['rejection_by'] ?? 'مدیر'); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between gap-2 mt-auto">
                            <div class="text-xs font-black text-rose-600"><?php echo $p['price']; ?></div>
                            <?php if (!empty($p['variants']) && count($p['variants']) > 1): ?>
                                <span class="text-[9px] font-black text-indigo-650 bg-indigo-50 border border-indigo-100/50 px-2 py-0.5 rounded-full flex items-center gap-1.5 transition-all">
                                    <span>🎨</span>
                                    <span><?php echo count($p['variants']); ?> رنگ</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($rejected)): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-premium p-16 text-center text-slate-400 font-bold max-w-lg mx-auto flex flex-col items-center gap-3 animate-fade-in">
                <span class="text-4xl">🕊️</span>
                <p class="text-sm">هیچ محصولی رد صلاحیت نشده است.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($total_rejected_pages > 1): ?>
            <div class="flex justify-center items-center gap-2 mt-12 pb-6">
                <?php for($i=1; $i<=$total_rejected_pages; $i++): 
                    $pageUrl = 'portal.php?' . http_build_query(array_merge($_GET, ['tab' => 'rejected', 'p' => $i]));
                ?>
                    <a href="<?php echo htmlspecialchars($pageUrl); ?>" class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-black <?php echo ($active_tab == 'rejected' && $page == $i) ? 'bg-brand-600 text-white shadow-md' : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'; ?> transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab 6: No Image Products (Content Gen & Reference Desk) -->
    <div id="tab-no-image" class="tab-content <?php echo (($active_tab ?? 'pending') === 'no-image') ? 'active' : ''; ?>">
        <?php
        $no_image_products = array_filter((array)($project['products'] ?? []), 'is_product_without_image');

        // Ensure products with tag/requested tag come first (higher)
        usort($no_image_products, function($a, $b) {
            $a_requested = !empty($a['content_gen_requested']) ? 1 : 0;
            $b_requested = !empty($b['content_gen_requested']) ? 1 : 0;
            return $b_requested <=> $a_requested;
        });

        $total_no_img = count($no_image_products);
        $total_no_img_pages = ceil($total_no_img / $limit);
        if (($active_tab ?? 'pending') === 'no-image') {
            $no_image_products = array_slice($no_image_products, ($page - 1) * $limit, $limit);
        } else {
            $no_image_products = array_slice($no_image_products, 0, $limit);
        }
        ?>

        <!-- Bulk Action Header Bar -->
        <div class="flex flex-wrap items-center justify-between gap-4 p-5 bg-gradient-to-r from-rose-50 to-amber-50 rounded-[2rem] border border-rose-100/60 mb-8 shadow-sm">
            <div class="flex items-center gap-3">
                <input type="checkbox" id="no-img-select-all" class="w-5 h-5 cursor-pointer rounded border-rose-300 text-rose-600 focus:ring-rose-500" onclick="toggleSelectAllNoImages(this)">
                <div>
                    <span class="text-xs font-black text-rose-800 block">انتخاب دسته جمعی کالاها</span>
                    <span class="text-[10px] text-rose-600 font-semibold block">برای ارسال درخواست گروهی تولید محتوا تیک بزنید.</span>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-2">
                <?php if ($can_request_content_gen): ?>
                    <button onclick="resetContentGenPriority()" class="px-6 py-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-2xl text-xs font-black shadow-sm active:translate-y-0.5 transform transition-all flex items-center gap-2">
                        <span>🔄 ریست اولویت تولید محتوا</span>
                    </button>
                    <button onclick="submitBulkContentGenRequest()" class="px-6 py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl text-xs font-black shadow-lg shadow-rose-500/20 active:translate-y-0.5 transform transition-all flex items-center gap-2">
                        <span>🚀 ارسال درخواست گروهی تولید محتوا</span>
                        <span id="selected-no-img-count" class="bg-white/25 px-2 py-0.5 rounded-full text-[10px]">۰ کالای انتخاب شده</span>
                    </button>
                <?php else: ?>
                    <span class="text-xs font-bold text-slate-400 self-center">مشاهده محصولات بدون تصویر نهایی</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach($no_image_products as $p): 
                $refImages = getProductReferenceImagesList($p);
                $has_ref = count($refImages) > 0;
            ?>
                <script>window.portalProducts['<?php echo $p['id']; ?>'] = <?php echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
                <div class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden flex flex-col shadow-premium relative group transition-all duration-300 hover-lift" id="card-prod-<?php echo $p['id']; ?>">
                    
                    <!-- Selection Checkbox at top-left inside the box -->
                    <div onclick="event.stopPropagation();" class="absolute top-4 left-4 z-40 bg-white/95 backdrop-blur-md p-2 rounded-xl border border-slate-100 shadow-sm flex items-center justify-center">
                        <?php if (!empty($p['content_gen_requested'])): ?>
                            <input type="checkbox" disabled checked class="no-img-item-checkbox w-5 h-5 rounded border-slate-200 text-rose-500 opacity-60 cursor-not-allowed" title="درخواست تولید محتوا قبلاً ثبت شده است (اولویت تولید محتوا)">
                        <?php else: ?>
                            <input type="checkbox" class="no-img-item-checkbox w-5 h-5 cursor-pointer rounded border-slate-305 text-rose-600 focus:ring-rose-500" data-pid="<?php echo $p['id']; ?>" onchange="updateSelectedNoImagesCount()">
                        <?php endif; ?>
                    </div>

                    <!-- Top Tags inside the box -->
                    <div onclick="event.stopPropagation();" class="absolute top-4 right-4 z-40 flex flex-col gap-1.5 items-start">
                        <?php if (!empty($p['content_gen_requested'])): ?>
                            <span class="bg-rose-600/90 backdrop-blur-md text-white text-[10px] font-black px-3 py-1 rounded-full shadow-md flex items-center gap-1.5 animate-pulse" title="اولویت تولید محتوا">
                                <span class="w-1.5 h-1.5 bg-white rounded-full"></span>
                                <span>اولویت تولید محتوا 📌</span>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($has_ref): ?>
                            <span id="ref-tag-<?php echo $p['id']; ?>" class="bg-emerald-600/90 backdrop-blur-md text-white text-[9px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1">
                                <span>🖼️</span>
                                <span>دارای عکس رفرنس (<?php echo count($refImages); ?>)</span>
                            </span>
                        <?php else: ?>
                            <span id="ref-tag-<?php echo $p['id']; ?>" class="bg-amber-500/90 backdrop-blur-md text-white text-[9px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1 animate-pulse">
                                <span>⏳</span>
                                <span>در انتظار عکس رفرنس</span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="relative aspect-[4/5] bg-slate-105 overflow-hidden flex items-center justify-center cursor-pointer" onclick="openDetailById('<?php echo $p['id']; ?>')">
                        <?php if ($has_ref): ?>
                            <img id="ref-thumb-<?php echo $p['id']; ?>" src="<?php echo getFullUrl($refImages[0]); ?>" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                        <?php else: ?>
                            <div id="ref-placeholder-<?php echo $p['id']; ?>" class="text-center p-6 flex flex-col items-center gap-2">
                                <span class="text-3xl filter grayscale opacity-45">📷</span>
                                <span class="text-[10px] font-black text-slate-400">بدون تصویر نهایی</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="p-5 flex-1 flex flex-col bg-slate-50/55">
                        <div class="font-black text-sm text-slate-800 line-clamp-2 mb-2 cursor-pointer" onclick="openDetailById('<?php echo $p['id']; ?>')"><?php echo htmlspecialchars($p['name']); ?></div>
                        
                        <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                            <div class="text-xs font-black text-slate-600"><?php echo !empty($p['price']) ? $p['price'] : 'قیمت وارد نشده'; ?></div>
                            <div class="text-[10px] font-bold text-slate-400 uppercase"><?php echo htmlspecialchars($p['sku'] ?? 'بدون SKU'); ?></div>
                        </div>

                        <?php if ($can_manage_ref_images): ?>
                            <button onclick="openRefPhotoModal('<?php echo $p['id']; ?>', event)" class="mt-3 w-full py-2 px-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-xl text-[11px] font-black transition-all flex items-center justify-center gap-1.5 border border-indigo-200/60 shadow-xs cursor-pointer">
                                <span>📷</span>
                                <span>ثبت / مدیریت عکس رفرنس (۱ تا ۳)</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if(empty($no_image_products)): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-premium p-16 text-center text-slate-400 font-bold max-w-lg mx-auto flex flex-col items-center gap-3 animate-fade-in">
                <span class="text-4xl">🎉</span>
                <p class="text-sm">تمامی کالاها دارای تصویر هستند!</p>
            </div>
        <?php endif; ?>

        <?php if ($total_no_img_pages > 1): ?>
            <div class="flex justify-center items-center gap-2 mt-12 pb-6">
                <?php for($i=1; $i<=$total_no_img_pages; $i++): 
                    $pageUrl = 'portal.php?' . http_build_query(array_merge($_GET, ['tab' => 'no-image', 'p' => $i]));
                ?>
                    <a href="<?php echo htmlspecialchars($pageUrl); ?>" class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-black <?php echo ($active_tab == 'no-image' && $page == $i) ? 'bg-brand-600 text-white shadow-md' : 'bg-white text-slate-500 hover:bg-slate-50 border border-slate-100'; ?> transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>



</main>

    <!-- Product Comments & History Modal -->
    <div id="comments-history-modal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-[9995] hidden items-center justify-center p-4 animate-fade-in" onclick="if(event.target === this) closeCommentsPopup()">
        <div class="bg-white rounded-[2rem] w-full max-w-lg shadow-2xl relative p-6 flex flex-col max-h-[85vh] animate-in fade-in zoom-in-95 duration-200" dir="rtl">
            <!-- Header -->
            <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-4 h-12 flex-shrink-0">
                <div class="text-right">
                    <h3 class="font-extrabold text-base text-slate-800">تـاریـخـچـه نـظـرات و تـصـمـیـمـات</h3>
                    <p id="ch-subtitle" class="text-[10px] text-slate-400 font-extrabold mt-0.5 tracking-wider">SKU: ---</p>
                </div>
                <button onclick="closeCommentsPopup()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center font-bold text-sm transition-all focus:outline-none">✕</button>
            </div>
            
            <!-- Timeline Content -->
            <div id="ch-timeline" class="flex-1 overflow-y-auto space-y-3 pr-1 py-1 custom-scrollbar">
                <!-- Javascript will inject entries here -->
            </div>
        </div>
    </div>

    <!-- Couple / Set Creator Modal -->
    <div id="couple-set-modal" class="fixed inset-0 bg-slate-950/75 backdrop-blur-md z-[9997] hidden items-center justify-center p-3 sm:p-4 animate-fade-in" onclick="if(event.target === this) closeCoupleSetModal()">
        <div class="bg-white rounded-2xl sm:rounded-[2.5rem] w-full max-w-2xl shadow-elevated relative p-4 sm:p-8 flex flex-col max-h-[92vh]" dir="rtl">
            <!-- Header -->
            <div class="flex justify-between items-center pb-5 border-b border-slate-100 mb-6 flex-shrink-0">
                <div class="text-right">
                    <h3 class="font-black text-xl text-slate-800 flex items-center gap-2">
                        <span>👥</span>
                        <span>ایجاد محصول ست / کاپلی جدید</span>
                    </h3>
                    <p class="text-[10px] text-slate-450 font-bold mt-1 uppercase tracking-wider">یکپارچه‌سازی و ادغام ۲ کالای متمایز کتابخانه</p>
                </div>
                <button onclick="closeCoupleSetModal()" class="w-10 h-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center font-bold text-base transition-all focus:outline-none">✕</button>
            </div>
            
            <!-- Body form -->
            <div class="flex-1 overflow-y-auto space-y-5 pr-1 pl-1 custom-scrollbar text-right">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-extrabold text-slate-600 mb-2">نوع یا پیشوند ست (انتخابی):</label>
                        <select id="cs-prefix-select" onchange="updateCSNamePrefix(this.value)" class="w-full p-4 bg-slate-50 border border-slate-150 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-rose-500 focus:bg-white outline-none transition-all">
                            <option value="ست کاپلی">ست کاپلی 👩‍❤️‍👨</option>
                            <option value="ست لباس و شلوار">ست لباس و شلوار 👕👖</option>
                            <option value="ست دوتیکه شیک">ست دوتیکه شیک ✨</option>
                            <option value="ست هودی و اسلش">ست هودی و اسلش 🧥🏋️</option>
                            <option value="ست راحتی خانگی">ست راحتی خانگی 🏡</option>
                            <option value="">-- عنوان سفارشی --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-slate-600 mb-2">قیمت نهایی ست (مجموع یا دلخواه):</label>
                        <input type="text" id="cs-price-input" placeholder="مثال: ۱,۹۸۰,۰۰۰ تومان" class="w-full p-4 bg-slate-50 border border-slate-150 rounded-2xl text-xs font-black focus:ring-2 focus:ring-rose-500 focus:bg-white outline-none transition-all text-left" dir="ltr">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-600 mb-2">نام محصول ست:</label>
                    <input type="text" id="cs-name-input" placeholder="نام ست جدید..." class="w-full p-4 bg-slate-50 border border-slate-150 rounded-2xl text-xs font-black focus:ring-2 focus:ring-rose-500 focus:bg-white outline-none transition-all">
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-600 mb-2">سایزهای موجود در ست (ادغام‌شده):</label>
                    <input type="text" id="cs-sizes-input" placeholder="لارج، ایکس لارج، فری سایز..." class="w-full p-4 bg-slate-50 border border-slate-150 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-rose-500 focus:bg-white outline-none transition-all">
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-600 mb-2">توضیحات تفکیکی و کامل ست:</label>
                    <textarea id="cs-desc-input" rows="8" class="w-full p-4 bg-slate-50 border border-slate-150 rounded-3xl text-xs font-semibold focus:ring-2 focus:ring-rose-500 focus:bg-white outline-none transition-all leading-relaxed custom-scrollbar" placeholder="توضیحات کامل درباره ست جدید..."></textarea>
                </div>
            </div>

            <!-- Footer actions -->
            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100 mt-6 flex-shrink-0">
                <button onclick="closeCoupleSetModal()" class="px-6 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 font-extrabold rounded-2xl text-xs transition-all">انصراف</button>
                <button onclick="submitCoupleSetCreation()" id="cs-submit-btn" class="px-8 py-3 bg-rose-600 hover:bg-rose-700 text-white font-black rounded-2xl text-xs shadow-lg shadow-rose-500/20 active:translate-y-0.5 transition-all flex items-center gap-2">
                    <span>🚀 ثبت نهایی و ساخت محصول ست</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Tab: Occasions -->
    <div id="tab-occasions" class="tab-content <?php echo (($active_tab ?? 'pending') === 'occasions') ? 'active' : ''; ?>">
        <?php
            $search_title = trim($_GET['occ_title_search'] ?? '');
            $search_date = trim($_GET['occ_date_search'] ?? '');
            
            $all_occs = getOccasions($project);
            
            $monthNames = [
                1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
                4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
                7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
                10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
            ];
            
            // Filter
            $filtered_occs = [];
            foreach ($all_occs as $o) {
                $matches_title = true;
                $matches_date = true;
                
                if ($search_title !== '') {
                    $matches_title = (mb_strpos(mb_strtolower($o['title']), mb_strtolower($search_title)) !== false || mb_strpos(mb_strtolower($o['category']), mb_strtolower($search_title)) !== false);
                }
                if ($search_date !== '') {
                    $dateParts = explode('/', $o['jalali_date']);
                    $mName = $monthNames[(int)($dateParts[1] ?? 1)] ?? '';
                    $matches_date = (mb_strpos($o['jalali_date'], $search_date) !== false || mb_strpos($o['day'] ?? '', $search_date) !== false || mb_strpos($mName, $search_date) !== false);
                }
                
                if ($matches_title && $matches_date) {
                    $filtered_occs[] = $o;
                }
            }
        ?>
        
        <!-- Search filter headers -->
        <div class="flex flex-col md:flex-row justify-between items-center bg-white border border-slate-100 p-6 rounded-[2rem] shadow-premium mb-8 gap-4 animate-fade-in" dir="rtl">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center text-xl">🏮</div>
                <div class="text-right">
                    <h3 class="font-extrabold text-lg text-slate-800 leading-tight">تقویم مناسبتی و سایر</h3>
                    <p class="text-[10px] text-slate-400 font-bold">نمای ممیزی، برنامه اجرایی و لوپ نظرات مناسبت‌های ملی و سفارشی</p>
                </div>
            </div>
            
            <!-- Filters Form -->
            <form method="GET" class="flex flex-wrap items-center gap-3 w-full md:w-auto justify-end">
                <input type="hidden" name="tab" value="occasions">
                <?php if (isset($_GET['project'])): ?>
                    <input type="hidden" name="project" value="<?php echo htmlspecialchars($_GET['project']); ?>">
                <?php endif; ?>
                
                <input type="text" name="occ_title_search" value="<?php echo htmlspecialchars($search_title); ?>" placeholder="جستجوی عنوان مناسبت یا دسته..." class="py-2.5 px-4 bg-slate-50 border border-slate-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-brand-500 w-full sm:w-48 text-right">
                <input type="text" name="occ_date_search" value="<?php echo htmlspecialchars($search_date); ?>" placeholder="تاریخ (روز، ماه مانند اردیبهشت)..." class="py-2.5 px-4 bg-slate-50 border border-slate-100 rounded-xl text-xs font-bold outline-none focus:ring-2 focus:ring-brand-500 w-full sm:w-48 text-right">
                
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-black rounded-xl text-xs shadow-md transition-all">اعمال فیلتر</button>
                <a href="?tab=occasions<?php echo isset($_GET['project']) ? '&project='.urlencode($_GET['project']) : ''; ?>" class="px-4 py-2.5 bg-slate-105 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition-all">پاک کردن</a>
            </form>
        </div>
        
        <!-- Occasions grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" dir="rtl">
            <?php
            $seasonStyles = [
                'spring' => [
                    'bg' => 'bg-emerald-50/20 border-teal-100',
                    'icon' => '🌸',
                    'text' => 'text-teal-800',
                    'accent' => 'teal'
                ],
                'summer' => [
                    'bg' => 'bg-emerald-50/10 border-emerald-100',
                    'icon' => '☀️',
                    'text' => 'text-emerald-800',
                    'accent' => 'emerald'
                ],
                'autumn' => [
                    'bg' => 'bg-amber-50/20 border-amber-100',
                    'icon' => '🍁',
                    'text' => 'text-amber-805',
                    'accent' => 'amber'
                ],
                'winter' => [
                    'bg' => 'bg-indigo-50/20 border-indigo-100',
                    'icon' => '❄️',
                    'text' => 'text-indigo-808',
                    'accent' => 'indigo'
                ]
            ];
            
            foreach ($filtered_occs as $o):
                $occ_key = md5($o['title'] . '_' . $o['jalali_date']);
                $plan = $project['settings']['occasions_plans'][$occ_key] ?? null;
                $pdf_path = $plan['pdf_path'] ?? '';
                $platforms = $plan['platforms'] ?? [];
                
                $st = $seasonStyles[$o['season'] ?? 'spring'];
                
                // Approver lists from project settings
                $cal_s1_raw = $project['settings']['cal_approval_stage1_users'] ?? '';
                $s1_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($cal_s1_raw))));
                $cal_s2_raw = $project['settings']['cal_approval_stage2_users'] ?? '';
                $s2_users = array_filter(array_map('trim', preg_split('/[,\x{060c}]+/u', strtolower($cal_s2_raw))));
                
                $st1_min = (int)($project['settings']['cal_approval_stage1_min'] ?? 1);
                $st2_min = (int)($project['settings']['cal_approval_stage2_min'] ?? 1);
                
                $approvals1 = $plan['approvals_stage1'] ?? [];
                $approvals2 = $plan['approvals_stage2'] ?? [];
                
                $is_approved_final = isOccasionApproved($project, $occ_key);
            ?>
                <div class="bg-white rounded-[2rem] border <?php echo $st['bg']; ?> p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition-all gap-5 text-right relative duration-300">
                    
                    <div class="space-y-4">
                        <!-- Top Header info -->
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-2xl bg-white border border-slate-100 flex flex-col items-center justify-center font-black shadow-sm text-slate-800">
                                    <span class="text-[9px] text-slate-400 font-bold leading-none mt-1"><?php echo $st['icon']; ?></span>
                                    <span class="text-base text-slate-900 leading-none mt-1"><?php echo $o['day'] ?? '۰۱'; ?></span>
                                    <span class="text-[8px] text-slate-400 leading-none mb-1"><?php echo $monthNames[(int)explode('/', $o['jalali_date'])[1]]; ?></span>
                                </div>
                                <div>
                                    <h4 class="font-black text-sm text-slate-800"><?php echo htmlspecialchars($o['title']); ?></h4>
                                    <div class="text-[10px] text-slate-400 mt-1 font-bold">
                                        <span class="text-brand-600 bg-brand-50 px-2 py-0.5 rounded-md"><?php echo htmlspecialchars($o['category'] ?? 'مناسبت'); ?></span> • 
                                        <span class="font-mono"><?php echo $o['jalali_date']; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Overall approval badge -->
                            <div>
                                <?php if ($is_approved_final): ?>
                                    <span class="bg-emerald-100 text-emerald-800 text-[10px] font-black px-2.5 py-1 rounded-full border border-emerald-200 flex items-center gap-1">
                                        <span>✓</span> Approved
                                    </span>
                                <?php else: ?>
                                    <span class="bg-amber-105 text-amber-800 text-[10px] font-black px-2.5 py-1 rounded-full border border-amber-200 flex items-center gap-1">
                                        <span>⏳</span> Pending
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Platforms Goal list -->
                        <?php if (!empty($platforms)): ?>
                            <div class="space-y-1">
                                <span class="text-[9px] text-slate-400 block font-bold">📱 پلتفرم‌های انتشار هدف:</span>
                                <div class="flex flex-wrap gap-1.5 pt-0.5">
                                    <?php foreach ($platforms as $platform): ?>
                                        <span class="text-[9px] bg-slate-150 text-slate-650 px-2 py-0.5 rounded-md font-extrabold border border-slate-200">
                                            <?php echo htmlspecialchars($platform == 'instagram_story' ? 'استوری اینستاگرام' : ($platform == 'instagram' ? 'اینستاگرام' : ($platform == 'telegram' ? 'تلگرام' : ($platform == 'bale' ? 'بله' : 'روبیکا')))); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Actions & PDF -->
                        <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-between gap-2.5">
                            <span class="text-[10px] text-slate-500 font-extrabold">برنامه فرآیند اجرایی (PDF):</span>
                            <?php if ($pdf_path): ?>
                                <a href="<?php echo $pdf_path; ?>" download class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-[10px] font-black transition-all flex items-center gap-1.5 shadow-sm shadow-rose-900/10">
                                    <span>📥</span>
                                    <span>مشاهده برنامه</span>
                                </a>
                            <?php else: ?>
                                <span class="text-[9px] text-slate-400 font-bold bg-slate-200/50 px-2.5 py-1 rounded-xl">آپلود نشده است</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Deadline / Wait Time based on setting -->
                        <?php
                            $timeout_hours = (int)($project['settings']['approval_timeout_calendar'] ?? 48);
                            $uploaded_at = $plan['pdf_uploaded_at'] ?? 0;
                            if ($uploaded_at > 0) {
                                $deadline_ts = $uploaded_at + ($timeout_hours * 3600);
                                $now_ts = time();
                                $diff = $deadline_ts - $now_ts;
                                if ($diff > 0) {
                                    $hours_left = ceil($diff / 3600);
                                    $deadline_html = "<span class='text-[10px] bg-amber-50 text-amber-700 border border-amber-100 px-3 py-1 bg-amber-50/50 rounded-xl font-bold flex items-center justify-center gap-1 shadow-sm w-full'>⏰ $hours_left ساعت تا تایید خودکار ممیزی مانده</span>";
                                } else {
                                    $deadline_html = "<span class='text-[10px] bg-slate-50 text-emerald-700 border border-slate-200 px-3 py-1 rounded-xl font-bold flex items-center justify-center gap-1 w-full'>✓ زمانِ انتظار پایان یافته (تصویب سیستمی)</span>";
                                }
                            } else {
                                $deadline_html = "<span class='text-[10px] bg-slate-50 text-slate-400 border border-slate-200/60 px-3 py-1 rounded-xl font-medium block text-center w-full'>⏳ در انتظار بارگذاری برنامه PDF توسط مدیر</span>";
                            }
                        ?>
                        <div class="w-full pt-1">
                            <?php echo $deadline_html; ?>
                        </div>
                        
                        <!-- Approvals workflow box -->
                        <div class="p-4 bg-violet-50/40 border border-violet-100/50 rounded-2xl space-y-3 font-sans">
                            <div class="text-[10px] font-black text-violet-800 border-b border-violet-100/60 pb-1.5">⚖️ وضعیت تصویب و حداقل‌های نظارتی:</div>
                            
                            <!-- Stage 1 Approvers List -->
                            <div class="space-y-1.5">
                                <div class="flex justify-between items-center text-[9px] font-extrabold text-slate-500">
                                    <span>🎨 تیم اول (بررسی استایل و مارکتینگ):</span>
                                    <span>(حداقل تایید مورد نیاز: <b class="text-violet-700"><?php echo $st1_min; ?></b>)</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php if (empty($s1_users)): ?>
                                        <span class="text-[8px] text-slate-400">تنظیم نشده</span>
                                    <?php else: foreach ($s1_users as $u_s1): 
                                        $voted = in_array(strtolower($u_s1), array_map('strtolower', $approvals1));
                                    ?>
                                        <span class="text-[9px] flex items-center gap-1 px-2.5 py-0.5 rounded-full font-extrabold <?php echo $voted ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-slate-100 text-slate-500 border border-slate-200'; ?>">
                                            <span><?php echo $voted ? '✓' : '●'; ?></span>
                                            <span><?php echo htmlspecialchars($u_s1); ?></span>
                                        </span>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                            
                            <!-- Stage 2 Approvers List -->
                            <?php if (!empty($project['settings']['cal_approval_split_enabled'])): ?>
                            <div class="space-y-1.5 pt-2 border-t border-violet-500/10">
                                <div class="flex justify-between items-center text-[9px] font-extrabold text-slate-500">
                                    <span>🧐 تیم دوم (مدیریت و انطباق):</span>
                                    <span>(حداقل تایید مورد نیاز: <b class="text-violet-700"><?php echo $st2_min; ?></b>)</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php if (empty($s2_users)): ?>
                                        <span class="text-[8px] text-slate-400">تنظیم نشده</span>
                                    <?php else: foreach ($s2_users as $u_s2): 
                                        $voted = in_array(strtolower($u_s2), array_map('strtolower', $approvals2));
                                    ?>
                                        <span class="text-[9px] flex items-center gap-1 px-2.5 py-0.5 rounded-full font-extrabold <?php echo $voted ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-slate-100 text-slate-500 border border-slate-200'; ?>">
                                            <span><?php echo $voted ? '✓' : '●'; ?></span>
                                            <span><?php echo htmlspecialchars($u_s2); ?></span>
                                        </span>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Vote actions for the active user if they are listed in s1 or s2 -->
                    <?php if ($pdf_path): ?>
                    <div class="pt-2 border-t border-slate-100 flex gap-2">
                        <?php 
                        $user_lo = strtolower($_SESSION['user']['username'] ?? '');
                        $isSuperOrAdmin = isSuperAdmin() || isProjectAdmin();
                        $is_s1_voter = $isSuperOrAdmin || empty($s1_users) || in_array($user_lo, array_map('strtolower', $s1_users));
                        $is_s2_voter = $isSuperOrAdmin || empty($s2_users) || in_array($user_lo, array_map('strtolower', $s2_users));
                        
                        if ($is_s1_voter): 
                            $has_voted1 = in_array($user_lo, array_map('strtolower', $approvals1));
                        ?>
                            <button type="button" onclick="castOccasionVote('<?php echo $occ_key; ?>', 'stage1', <?php echo $has_voted1 ? '0' : '1'; ?>)" class="flex-1 py-2.5 px-3 rounded-xl font-black text-[10px] transition-all cursor-pointer flex items-center justify-center gap-1.5 <?php echo $has_voted1 ? 'bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-200' : 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm'; ?>">
                                <span>🎨</span>
                                <span><?php echo $has_voted1 ? 'لغو تایید ممیزی ۱' : 'تایید ممیزی ۱'; ?></span>
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($is_s2_voter && !empty($project['settings']['cal_approval_split_enabled'])): 
                            $has_voted2 = in_array($user_lo, array_map('strtolower', $approvals2));
                        ?>
                            <button type="button" onclick="castOccasionVote('<?php echo $occ_key; ?>', 'stage2', <?php echo $has_voted2 ? '0' : '1'; ?>)" class="flex-1 py-2.5 px-3 rounded-xl font-black text-[10px] transition-all cursor-pointer flex items-center justify-center gap-1.5 <?php echo $has_voted2 ? 'bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-200' : 'bg-teal-600 text-white hover:bg-teal-700 shadow-sm'; ?>">
                                <span>🧐</span>
                                <span><?php echo $has_voted2 ? 'لغو تایید ممیزی ۲' : 'تایید ممیزی ۲'; ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; if(empty($filtered_occs)) echo "<div class='col-span-full py-20 text-center font-black text-slate-350 italic' dir='rtl'>هیچ برنامه‌ای یافت نشد.</div>"; ?>
        </div>
    </div>

    <!-- Product Detail Modal -->
    <div id="product-detail-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-xl z-[9990] hidden items-center justify-center p-2 sm:p-4 md:p-6" onclick="if(event.target === this) closePortalDetail()">
        <div class="bg-white rounded-2xl sm:rounded-[2.5rem] w-full max-w-5xl shadow-2xl relative overflow-y-auto md:overflow-hidden grid grid-cols-1 md:grid-cols-2 max-h-[94vh] md:max-h-[85vh] md:h-[85vh] animate-fade-in product-detail-container">
            <!-- Media Section with Sliding Carousel -->
            <div class="relative bg-slate-50 w-full h-[260px] sm:h-[350px] md:h-full overflow-hidden select-none flex flex-col justify-between" dir="ltr">
                <!-- Carousel slides wrapper -->
                <div id="pd-carousel-wrapper" class="absolute inset-0 flex transition-transform duration-500 will-change-transform">
                    <!-- Slides are injected here dynamically -->
                </div>

                <!-- Navigation arrows -->
                <button id="pd-carousel-prev" onclick="movePortalCarousel(-1); event.stopPropagation();" class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/30 hover:bg-black/50 text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-all z-20 cursor-pointer hidden">
                     ⟨
                </button>
                <button id="pd-carousel-next" onclick="movePortalCarousel(1); event.stopPropagation();" class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/30 hover:bg-black/50 text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-all z-20 cursor-pointer hidden">
                     ⟩
                </button>

                <!-- Indicator Dots -->
                <div id="pd-carousel-dots" class="absolute bottom-6 left-1/2 -translate-x-1/2 flex gap-1.5 z-20"></div>

                <button onclick="closePortalDetail()" class="absolute top-4 left-4 sm:top-6 sm:left-6 w-9 h-9 sm:w-11 sm:h-11 bg-white/20 hover:bg-white/40 text-white rounded-full flex items-center justify-center backdrop-blur-md transition-all z-30 shadow-md" aria-label="بستن">✕</button>
            </div>
            
            <!-- Info Section -->
            <div class="p-4 sm:p-6 md:p-10 flex flex-col overflow-y-visible md:overflow-y-auto max-h-none md:max-h-full md:h-full custom-scrollbar">
                <div class="mb-8">
                    <div class="flex justify-between items-start mb-3 gap-4">
                        <h2 id="pd-title" class="font-extrabold text-xl md:text-2xl text-slate-800 leading-tight">---</h2>
                        <button onclick="closePortalDetail()" class="hidden md:flex w-10 h-10 bg-slate-100/80 hover:bg-slate-200 text-slate-500 rounded-full items-center justify-center transition-all">✕</button>
                    </div>
                    <div class="flex gap-4 items-center">
                        <span id="pd-price" class="text-brand-650 font-black text-xl md:text-2xl">---</span>
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-200"></span>
                        <span id="pd-sku" class="text-[10px] font-black text-slate-400 uppercase tracking-widest">---</span>
                    </div>
                </div>

                <!-- Reactions -->
                <div class="grid grid-cols-2 gap-3 mb-6">
                    <div id="pd-like-btn" class="flex items-center justify-center gap-2 py-3.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-2xl font-black text-sm border border-emerald-100 cursor-pointer transition-all">
                        <span>❤️</span>
                        <span id="pd-likes-count">0</span>
                    </div>
                    <div id="pd-dislike-btn" class="flex items-center justify-center gap-2 py-3.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-2xl font-black text-sm border border-rose-100 cursor-pointer transition-all">
                        <span>👎</span>
                        <span id="pd-dislikes-count">0</span>
                    </div>
                </div>
                
                <div id="pd-description" class="mb-6 hidden text-xs leading-relaxed text-slate-500 whitespace-pre-wrap bg-slate-50 p-4 rounded-2xl max-h-[140px] overflow-y-auto custom-scrollbar border border-slate-105/50"></div>

                <!-- Split Approval details container -->
                <div id="pd-split-approval-container" class="mb-6 hidden bg-slate-50 p-4 rounded-2xl border border-slate-100 flex-col gap-2"></div>

                <!-- Creator Attribution -->
                <div id="pd-creator-container" class="mb-6 hidden bg-slate-50 p-4 rounded-2xl border border-slate-200 flex items-center gap-2">
                    <span class="text-xs">📸</span>
                    <span class="text-xs font-bold text-slate-700">تولیدکننده تصویر:</span>
                    <span id="pd-creator-name" class="text-xs font-black text-brand-650">---</span>
                </div>

                <div id="pd-stock-container" class="mb-6 hidden bg-slate-50 p-4 rounded-[1.5rem] border border-slate-100 flex flex-col gap-3">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">📦 وضعیت موجودی انبارها</label>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-white p-3.5 rounded-2xl border border-slate-100/60 shadow-sm text-center">
                            <div class="text-[9px] text-slate-400 font-extrabold pb-1">موجودی اینترنتی</div>
                            <div id="pd-stock-online" class="text-sm font-black text-emerald-600">---</div>
                        </div>
                        <div class="bg-white p-3.5 rounded-2xl border border-slate-100/60 shadow-sm text-center">
                            <div class="text-[9px] text-slate-400 font-extrabold pb-1">موجودی کل</div>
                            <div id="pd-stock-total" class="text-sm font-black text-indigo-650">---</div>
                        </div>
                    </div>
                    <div id="pd-warehouses-list" class="mt-1 pt-3 border-t border-slate-200/80 hidden">
                        <div class="text-[10px] font-black text-slate-500 mb-1.5 flex items-center gap-1">
                            <span>🏢</span> <span>تفکیک موجودی شعب و انبارها:</span>
                        </div>
                        <div id="pd-warehouses-items" class="flex flex-wrap gap-1.5"></div>
                    </div>
                </div>

                <!-- Social Sharing Status -->
                <div id="pd-social-container" class="mb-6 hidden bg-slate-50 p-4 rounded-[1.5rem] border border-slate-100 flex flex-col gap-3">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">📡 وضعیت انتشار در شبکه‌های اجتماعی</label>
                    <div id="pd-social-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-2"></div>
                </div>

                <div id="pd-variants-container" class="mb-6 hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest block">🎨 تنوع‌های رنگی موجود (از همین مدل)</label>
                    <div id="pd-variants" class="flex flex-wrap gap-2"></div>
                </div>

                <div id="pd-sizes-container" class="mb-6 hidden">
                    <label class="text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest block">📐 سایزهای موجود</label>
                    <div id="pd-sizes" class="flex flex-wrap gap-1.5"></div>
                </div>
                
                <!-- Feedback Section -->
                <div class="flex-1 min-h-0 flex flex-col mb-6">
                    <label class="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest block">💬 نظرات و بازخوردهای پروژه</label>
                    <div id="pd-feedback" class="flex-1 space-y-3 overflow-y-auto pr-1 max-h-[180px] custom-scrollbar"></div>
                </div>
                
                <!-- Input Box -->
                <div class="mt-auto space-y-3">
                    <?php if (!empty($project['settings']['feedbackOptions'])): ?>
                        <div class="flex flex-wrap gap-1.5 pt-1 select-none">
                            <?php foreach($project['settings']['feedbackOptions'] as $f): ?>
                                <button onclick="document.getElementById('pd-comment-input').value = '<?php echo addslashes($f['text']); ?>'" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200/60 hover:border-slate-300 text-slate-500 hover:text-brand-600 transition-all">
                                    <?php echo htmlspecialchars($f['text']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <textarea id="pd-comment-input" class="w-full text-xs p-3.5 bg-slate-50 border border-slate-100 focus:border-brand-300 rounded-2xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none custom-scrollbar transition-all" rows="2" placeholder="دیدگاه یا پیشنهاد نهایی خود را وارد کنید..."></textarea>
                    
                    <button id="pd-submit-comment" class="w-full py-4 bg-brand-600 hover:bg-brand-700 text-white rounded-2xl font-black text-xs shadow-md shadow-brand-100 active:translate-y-0 transition-all flex items-center justify-center gap-2">
                        <span>💬</span> ثبت نظر و پیشنهاد نهایی
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule Detail Modal -->
    <div id="schedule-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-xl z-[9990] hidden items-center justify-center p-2 sm:p-4 md:p-6" onclick="if(event.target === this) this.style.display='none'">
        <div class="bg-white rounded-2xl sm:rounded-[2.5rem] w-full max-w-4xl shadow-2xl relative overflow-y-auto md:overflow-hidden grid grid-cols-1 md:grid-cols-2 max-h-[94vh] md:max-h-[80vh] md:h-[80vh] animate-fade-in product-detail-container">
            <!-- Media Section with Sliding Carousel -->
            <div class="relative bg-slate-50 w-full h-[260px] sm:h-[350px] md:h-full overflow-hidden select-none flex flex-col justify-between" dir="ltr">
                <!-- Carousel slides wrapper -->
                <div id="sm-carousel-wrapper" class="absolute inset-0 flex transition-transform duration-500 will-change-transform">
                    <!-- Slides are injected here dynamically -->
                </div>

                <!-- Navigation arrows -->
                <button id="sm-carousel-prev" onclick="moveScheduleCarousel(-1); event.stopPropagation();" class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/30 hover:bg-black/50 text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-all z-20 cursor-pointer hidden">
                     ⟨
                </button>
                <button id="sm-carousel-next" onclick="moveScheduleCarousel(1); event.stopPropagation();" class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/30 hover:bg-black/50 text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-all z-20 cursor-pointer hidden">
                     ⟩
                </button>

                <!-- Indicator Dots -->
                <div id="sm-carousel-dots" class="absolute bottom-6 left-1/2 -translate-x-1/2 flex gap-1.5 z-20"></div>

                <button onclick="document.getElementById('schedule-modal').style.display='none'" class="absolute top-4 left-4 sm:top-6 sm:left-6 w-9 h-9 sm:w-11 sm:h-11 bg-white/20 hover:bg-white/40 text-white rounded-full flex items-center justify-center backdrop-blur-md transition-all z-30 shadow-md" aria-label="بستن">✕</button>
            </div>
            
            <!-- Info block -->
            <div class="p-4 sm:p-6 md:p-10 flex flex-col overflow-y-visible md:overflow-y-auto max-h-none md:max-h-full md:h-full custom-scrollbar">
                <div class="mb-6">
                    <h2 id="sm-title" class="font-extrabold text-xl md:text-2xl text-slate-800 mb-1 leading-tight">---</h2>
                    <div id="sm-meta" class="text-[10px] text-brand-500 font-extrabold uppercase mb-4 tracking-widest">---</div>
                    
                    <div class="bg-slate-50 p-4 border border-slate-100 rounded-2xl relative">
                        <div class="text-[9px] text-slate-400 font-black uppercase mb-1.5 tracking-wider">کپشن انتشار روزانه</div>
                        <div id="sm-caption" class="text-xs leading-relaxed text-slate-600 whitespace-pre-wrap max-h-[110px] overflow-y-auto custom-scrollbar">---</div>
                    </div>
                </div>

                <!-- Schedule Approval Status & History Container -->
                <div id="sm-split-approval-container" class="mb-6 hidden bg-slate-50 p-4 rounded-2xl border border-slate-100 flex-col gap-2"></div>

                <!-- Control Buttons -->
                <div id="schedule-approval-buttons" class="flex gap-2 mb-6 flex-wrap">
                    <button id="sm-approve-btn" class="flex-1 py-3 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-black text-xs transition-all shadow-sm">تایید نهایی ✅</button>
                    <button id="sm-reject-btn" class="flex-1 py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-xs transition-all shadow-sm">نیاز به اصلاح ❌</button>
                </div>

                <!-- Comments & Discussion -->
                <div class="flex-1 flex flex-col min-h-0 space-y-3">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="w-2 h-2 bg-brand-500 rounded-full animate-ping"></span>
                        <span class="text-[10px] font-black text-slate-500 uppercase">نظرات و بازخوردها</span>
                    </div>
                    <div id="sm-comments" class="flex-1 max-h-[160px] overflow-y-auto pr-1 space-y-2 custom-scrollbar"></div>
                    
                    <div class="flex gap-2 pt-2 mt-auto">
                        <input type="text" id="sm-comment-input" class="flex-1 text-xs p-3.5 bg-slate-50 border border-slate-100 focus:bg-white rounded-xl outline-none focus:ring-2 focus:ring-brand-500 transition-all custom-scrollbar" placeholder="افزودن نظر...">
                        <button id="sm-submit-comment" class="w-12 h-12 bg-brand-600 hover:bg-brand-700 text-white rounded-xl flex items-center justify-center text-lg active:scale-95 transition-all">💬</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Reference Images Modal (1 to 3 images) -->
    <div id="ref-photo-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-[9998] hidden items-center justify-center p-3 sm:p-6 animate-fade-in" onclick="if(event.target === this) closeRefPhotoModal()">
        <div class="bg-white rounded-2xl sm:rounded-[2.5rem] w-full max-w-2xl shadow-2xl relative p-4 sm:p-8 flex flex-col max-h-[94vh] sm:max-h-[92vh] border border-slate-100" dir="rtl">
            <!-- Header -->
            <div class="flex justify-between items-start pb-3 sm:pb-4 border-b border-slate-100 mb-4 sm:mb-5 flex-shrink-0">
                <div class="text-right">
                    <div class="flex items-center gap-2">
                        <span class="text-lg sm:text-xl">📷</span>
                        <h3 class="font-black text-base sm:text-lg text-slate-800">مدیریت تصاویر رفرنس کالا</h3>
                        <span id="rpm-count-badge" class="px-2 py-0.5 rounded-full text-[10px] sm:text-[11px] font-black bg-amber-100 text-amber-800">۰ از ۳ تصویر</span>
                    </div>
                    <p id="rpm-product-info" class="text-[11px] sm:text-xs text-slate-500 font-bold mt-1">---</p>
                </div>
                <button onclick="closeRefPhotoModal()" class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center font-bold text-sm sm:text-base transition-all focus:outline-none cursor-pointer" aria-label="بستن">✕</button>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-y-auto space-y-5 sm:space-y-6 pr-1 pl-1 custom-scrollbar text-right">
                
                <!-- Notice Banner -->
                <div class="p-3 sm:p-3.5 bg-amber-50/80 rounded-xl sm:rounded-2xl border border-amber-200/60 text-amber-900 text-[11px] sm:text-xs flex items-center gap-2.5">
                    <span class="text-base shrink-0">💡</span>
                    <span class="leading-relaxed font-semibold">این بخش محیط اختصاصی و خلوت برای بارگذاری ۱ الی ۳ عکس الگو/رفرنس می‌باشد تا تیم طراحی و تولید تصویر بتوانند بر اساس آن تصویر نهایی محصول را خلق کنند.</span>
                </div>

                <!-- Existing Reference Images Gallery (3 Slots) -->
                <div>
                    <label class="block text-xs font-black text-slate-700 mb-2.5 sm:mb-3">🖼️ تصاویر رفرنس ثبت‌شده (حداکثر ۳ تصویر):</label>
                    <div id="rpm-slots-container" class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-3.5">
                        <!-- Dynamic 3 slots rendered by JS -->
                    </div>
                </div>

                <!-- Upload / Add Section -->
                <div id="rpm-upload-section" class="bg-slate-50 p-4 sm:p-5 rounded-xl sm:rounded-2xl border border-slate-200/80 space-y-3 sm:space-y-4">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-black text-slate-800 flex items-center gap-1.5">
                            <span>➕</span>
                            <span>افزودن عکس رفرنس جدید</span>
                        </label>
                        <span id="rpm-remaining-text" class="text-[10px] text-slate-500 font-bold">امکان افزودن ۳ تصویر دیگر</span>
                    </div>

                    <!-- File Dropzone -->
                    <div id="rpm-dropzone" class="border-2 border-dashed border-indigo-200 hover:border-indigo-400 bg-white/80 rounded-2xl p-5 sm:p-6 text-center cursor-pointer transition-all flex flex-col items-center justify-center gap-2 group" onclick="if(event.target.id !== 'rpm-file-input') document.getElementById('rpm-file-input').click()">
                        <input type="file" id="rpm-file-input" multiple accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onclick="event.stopPropagation()" onchange="handleRefPhotoFilesSelect(event)">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg sm:text-xl group-hover:scale-110 transition-transform">
                            📁
                        </div>
                        <div>
                            <span class="text-xs font-black text-indigo-600 block">انتخاب یا رها کردن عکس (JPG, PNG, WebP)</span>
                            <span class="text-[10px] text-slate-400 font-bold block mt-0.5">می‌توانید یکجا ۱ تا ۳ فایل انتخاب کنید</span>
                        </div>
                    </div>

                    <!-- Selected Files Preview List -->
                    <div id="rpm-selected-files-list" class="space-y-1.5 hidden">
                        <!-- Preview of pending upload files -->
                    </div>

                    <!-- Direct URL Option -->
                    <div class="pt-2 border-t border-slate-200/60">
                        <label class="block text-[11px] font-bold text-slate-600 mb-1.5">یا وارد کردن لینک مستقیم تصویر (URL):</label>
                        <input type="url" id="rpm-url-input" placeholder="https://example.com/image.jpg" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-left" dir="ltr">
                    </div>

                    <!-- Upload Button -->
                    <button type="button" id="rpm-submit-btn" onclick="submitRefPhotosUpload()" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-black text-xs shadow-md shadow-indigo-200 transition-all flex items-center justify-center gap-2 cursor-pointer active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span>🚀</span>
                        <span>بارگذاری و ذخیره تصاویر رفرنس</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

<footer class="py-12 text-center text-[10px] text-gray-400 font-black uppercase tracking-[0.3em]">
    &copy; <?php echo date('Y'); ?> Developed by Maarksho Flow Engine
</footer>

<script>
    // Safe lookup handlers for products and schedules
    let currentRpmProductId = null;
    let pendingRpmFiles = [];

    function openRefPhotoModal(pid, event) {
        if (event) event.stopPropagation();
        currentRpmProductId = pid;
        pendingRpmFiles = [];
        const p = window.portalProducts && window.portalProducts[pid] ? window.portalProducts[pid] : null;
        if (!p) {
            console.error('Product not found for ref photo modal:', pid);
            return;
        }
        
        // Set Product Info
        const infoEl = document.getElementById('rpm-product-info');
        if (infoEl) {
            infoEl.innerHTML = `<strong>${p.name || 'محصول بدون عنوان'}</strong> — کد کالا (SKU): <span dir="ltr" class="font-mono text-indigo-600 font-black">${p.sku || '---'}</span> | قیمت: ${p.price || '---'}`;
        }
        
        // Clear inputs
        const fileInput = document.getElementById('rpm-file-input');
        if (fileInput) fileInput.value = '';
        const urlInput = document.getElementById('rpm-url-input');
        if (urlInput) urlInput.value = '';
        const filesList = document.getElementById('rpm-selected-files-list');
        if (filesList) {
            filesList.innerHTML = '';
            filesList.classList.add('hidden');
        }

        renderRefPhotoModalContent(p);
        
        const modal = document.getElementById('ref-photo-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }
    }

    function closeRefPhotoModal() {
        const modal = document.getElementById('ref-photo-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
        currentRpmProductId = null;
        pendingRpmFiles = [];
    }

    function getProductRefImages(p) {
        if (!p) return [];
        let refs = p.reference_gallery || [];
        if (typeof refs === 'string') {
            try { refs = JSON.parse(refs); } catch(e) { refs = []; }
        }
        if (!Array.isArray(refs)) refs = [];
        return refs.filter(r => r && typeof r === 'string' && r.indexOf('placehold') === -1);
    }

    function renderRefPhotoModalContent(p) {
        const refs = getProductRefImages(p);
        const count = refs.length;
        
        // Count Badge
        const badgeEl = document.getElementById('rpm-count-badge');
        if (badgeEl) {
            badgeEl.innerText = `${count} از ۳ تصویر`;
            if (count > 0) {
                badgeEl.className = 'px-2.5 py-0.5 rounded-full text-[11px] font-black bg-emerald-100 text-emerald-800';
            } else {
                badgeEl.className = 'px-2.5 py-0.5 rounded-full text-[11px] font-black bg-amber-100 text-amber-800';
            }
        }

        // Remaining text & Upload section visibility
        const remaining = 3 - count;
        const remainingEl = document.getElementById('rpm-remaining-text');
        if (remainingEl) {
            remainingEl.innerText = remaining > 0 ? `امکان افزودن ${remaining} تصویر دیگر` : 'حداکثر ظرفیت تکمیل شده است';
        }
        
        const uploadSection = document.getElementById('rpm-upload-section');
        const submitBtn = document.getElementById('rpm-submit-btn');
        if (uploadSection && submitBtn) {
            if (remaining <= 0) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'ظرفیت ۳ عکس تکمیل است (ابتدا یک عکس را حذف کنید)';
            } else {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>🚀</span> <span>بارگذاری و ذخیره تصاویر رفرنس</span>';
            }
        }

        // Render 3 slots
        const slotsContainer = document.getElementById('rpm-slots-container');
        if (slotsContainer) {
            let html = '';
            for (let i = 0; i < 3; i++) {
                if (i < count) {
                    const imgUrl = getFullUrl(refs[i]);
                    html += `
                        <div class="relative aspect-square rounded-2xl overflow-hidden border border-slate-200 group bg-slate-100 shadow-sm flex flex-col justify-between">
                            <img src="${imgUrl}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" onerror="this.src='https://placehold.co/300x300?text=Error'">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent sm:from-black/70 sm:to-transparent sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex flex-col justify-between p-2">
                                <span class="text-[10px] font-black text-white bg-black/40 px-2 py-0.5 rounded-md self-start">عکس ${i + 1}</span>
                                <button type="button" onclick="deleteRefPhoto('${p.id}', '${refs[i]}')" class="w-full py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-black transition-all flex items-center justify-center gap-1 shadow-sm cursor-pointer">
                                    <span>🗑️</span>
                                    <span>حذف این عکس</span>
                                </button>
                            </div>
                            <div class="absolute top-2 right-2 bg-emerald-600 text-white text-[9px] font-black px-2 py-0.5 rounded-md shadow sm:group-hover:hidden">
                                رفرنس ${i + 1}
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="aspect-square rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 flex flex-col items-center justify-center gap-1.5 text-slate-350 p-2 text-center select-none">
                            <span class="text-2xl opacity-40">🖼️</span>
                            <span class="text-[10px] font-bold">جایگاه خالی ${i + 1}</span>
                        </div>
                    `;
                }
            }
            slotsContainer.innerHTML = html;
        }
    }

    function handleRefPhotoFilesSelect(e) {
        const files = Array.from(e.target.files || []);
        if (!files.length) return;
        
        const p = window.portalProducts && window.portalProducts[currentRpmProductId];
        const currentCount = p ? getProductRefImages(p).length : 0;
        const remaining = 3 - currentCount;
        
        if (files.length > remaining) {
            if (typeof toast === 'function') toast(`حداکثر می‌توانید ${remaining} تصویر جدید انتخاب کنید ⚠️`);
            pendingRpmFiles = files.slice(0, remaining);
        } else {
            pendingRpmFiles = files;
        }
        
        const listEl = document.getElementById('rpm-selected-files-list');
        if (listEl) {
            listEl.innerHTML = '';
            if (pendingRpmFiles.length > 0) {
                listEl.classList.remove('hidden');
                pendingRpmFiles.forEach((f, idx) => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between p-2 bg-indigo-50/70 border border-indigo-100 rounded-xl text-xs text-indigo-900 font-bold';
                    item.innerHTML = `
                        <span class="truncate max-w-[200px]">📄 ${f.name}</span>
                        <span class="text-[10px] text-indigo-500 font-mono">${(f.size / 1024).toFixed(0)} KB</span>
                    `;
                    listEl.appendChild(item);
                });
            } else {
                listEl.classList.add('hidden');
            }
        }
    }

    async function submitRefPhotosUpload() {
        if (!currentRpmProductId) return;
        const p = window.portalProducts && window.portalProducts[currentRpmProductId];
        if (!p) return;
        
        const urlVal = (document.getElementById('rpm-url-input')?.value || '').trim();
        if (pendingRpmFiles.length === 0 && !urlVal) {
            if (typeof toast === 'function') toast('لطفاً حداقل یک فایل عکس یا آدرس اینترنتی وارد نمایید ⚠️');
            return;
        }
        
        const submitBtn = document.getElementById('rpm-submit-btn');
        const originalText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>⏳</span> <span>در حال بارگذاری و پردازش...</span>';
        }
        showLoader(true);
        
        try {
            const formData = new FormData();
            formData.append('action', 'upload_product_ref_images');
            formData.append('product_id', currentRpmProductId);
            if (urlVal) {
                formData.append('ref_urls', urlVal);
            }
            pendingRpmFiles.forEach(f => {
                formData.append('ref_files[]', f);
            });
            
            const res = await fetch('portal.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                if (typeof toast === 'function') toast(data.msg || 'تصاویر رفرنس با موفقیت ذخیره شدند ✅');
                
                // Update product in registry
                const newRefs = data.reference_gallery || [];
                p.reference_gallery = newRefs;
                
                // Update Card in DOM
                updateProductRefPhotoInDOM(currentRpmProductId, newRefs);
                
                // Reset input and re-render modal
                pendingRpmFiles = [];
                const fileInput = document.getElementById('rpm-file-input');
                if (fileInput) fileInput.value = '';
                const urlInput = document.getElementById('rpm-url-input');
                if (urlInput) urlInput.value = '';
                const filesList = document.getElementById('rpm-selected-files-list');
                if (filesList) {
                    filesList.innerHTML = '';
                    filesList.classList.add('hidden');
                }
                
                renderRefPhotoModalContent(p);
            } else {
                if (typeof toast === 'function') toast(data.msg || 'خطا در ثبت تصاویر رفرنس ❌');
            }
        } catch (err) {
            console.error('Upload ref photos error:', err);
            if (typeof toast === 'function') toast('خطای ارتباط با سرور در هنگام بارگذاری ❌');
        } finally {
            showLoader(false);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        }
    }

    async function deleteRefPhoto(pid, photoUrl) {
        if (!confirm('آیا از حذف این تصویر رفرنس مطمئن هستید؟')) return;
        
        const p = window.portalProducts && window.portalProducts[pid];
        showLoader(true);
        
        try {
            const formData = new FormData();
            formData.append('action', 'delete_product_ref_image');
            formData.append('product_id', pid);
            formData.append('photo_url', photoUrl);
            
            const res = await fetch('portal.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                if (typeof toast === 'function') toast(data.msg || 'تصویر رفرنس حذف شد ✅');
                const newRefs = data.reference_gallery || [];
                if (p) {
                    p.reference_gallery = newRefs;
                    renderRefPhotoModalContent(p);
                }
                updateProductRefPhotoInDOM(pid, newRefs);
            } else {
                if (typeof toast === 'function') toast(data.msg || 'خطا در حذف تصویر رفرنس ❌');
            }
        } catch (err) {
            console.error('Delete ref photo error:', err);
            if (typeof toast === 'function') toast('خطای شبکه در حذف تصویر ❌');
        } finally {
            showLoader(false);
        }
    }

    function updateProductRefPhotoInDOM(pid, refImages) {
        const card = document.getElementById('card-prod-' + pid);
        if (!card) return;
        
        const count = refImages.length;
        const hasRef = count > 0;
        
        // Update Tag
        const tagEl = document.getElementById('ref-tag-' + pid);
        if (tagEl) {
            if (hasRef) {
                tagEl.className = 'bg-emerald-600/90 backdrop-blur-md text-white text-[9px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1';
                tagEl.innerHTML = `<span>🖼️</span> <span>دارای عکس رفرنس (${count})</span>`;
            } else {
                tagEl.className = 'bg-amber-500/90 backdrop-blur-md text-white text-[9px] font-black px-2.5 py-1 rounded-full shadow-sm flex items-center gap-1 animate-pulse';
                tagEl.innerHTML = `<span>⏳</span> <span>در انتظار عکس رفرنس</span>`;
            }
        }
        
        // Update Thumbnail / Placeholder
        const thumbEl = document.getElementById('ref-thumb-' + pid);
        const placeholderEl = document.getElementById('ref-placeholder-' + pid);
        
        if (hasRef) {
            const fullUrl = getFullUrl(refImages[0]);
            if (thumbEl) {
                thumbEl.src = fullUrl;
            } else if (placeholderEl) {
                placeholderEl.parentElement.innerHTML = `<img id="ref-thumb-${pid}" src="${fullUrl}" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover">`;
            }
        } else {
            if (thumbEl) {
                thumbEl.parentElement.innerHTML = `
                    <div id="ref-placeholder-${pid}" class="text-center p-6 flex flex-col items-center gap-2">
                        <span class="text-3xl filter grayscale opacity-45">📷</span>
                        <span class="text-[10px] font-black text-slate-400">بدون تصویر نهایی</span>
                    </div>
                `;
            }
        }
    }
    function reviewInPendingTab(pid) {
        sessionStorage.setItem('reopen_product_id', pid);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'pending');
        url.searchParams.set('p', '1');
        window.location.href = url.toString();
    }

    function openDetailById(id) {
        const p = window.portalProducts[id];
        if (p) {
            openDetail(p);
        } else {
            console.error('Product not found in registry:', id);
        }
    }

    function showScheduleModalById(id) {
        const s = window.portalSchedules[id];
        if (s) {
            showScheduleModal(s);
        } else {
            console.error('Schedule not found in registry:', id);
        }
    }

    // Define helper functions first
    function getFullUrl(path) {
        if (!path) return '';
        if (path.indexOf('http') === 0) return path;
        if (window.APP_BASE_URL) {
            return window.APP_BASE_URL + path.replace(/^\//, '');
        }
        const protocol = window.location.protocol + '//';
        const host = window.location.host;
        const baseDir = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        return protocol + host + baseDir + '/' + path.replace(/^\//, '');
    }

    let portalLoaderTimer = null;
    function showLoader(s, customTimeout = 10000) { 
        const el = document.getElementById('loader');
        if (!el) return;
        if (s) {
            el.style.display = 'flex';
            if (portalLoaderTimer) clearTimeout(portalLoaderTimer);
            portalLoaderTimer = setTimeout(() => {
                showLoader(false);
                console.warn('⚠️ فرآیند بیش از ۱۰ ثانیه طول کشید و لودر به صورت خودکار بسته شد.');
                if (typeof toast === 'function') {
                    toast('پایان زمان انتظار: فرآیند بیش از ۱۰ ثانیه طول کشید');
                }
            }, customTimeout);
        } else {
            if (portalLoaderTimer) {
                clearTimeout(portalLoaderTimer);
                portalLoaderTimer = null;
            }
            el.style.display = 'none';
        }
    }
    
    function toast(m) {
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerText = m;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2500);
    }

    function scrollCardCarousel(productId, direction) {
        const container = document.getElementById(`card-scroll-${productId}`);
        if (container) {
            const width = container.clientWidth;
            container.scrollBy({ left: direction * width, behavior: 'smooth' });
        }
    }

    function toggleFilterAccordion() {
        const content = document.getElementById('filter-accordion-content');
        const arrow = document.getElementById('filter-accordion-arrow');
        if (content && arrow) {
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                arrow.style.transform = 'rotate(180deg)';
            } else {
                content.classList.add('hidden');
                arrow.style.transform = 'rotate(0deg)';
            }
        }
    }

    function updateCardDots(productId) {
        const container = document.getElementById(`card-scroll-${productId}`);
        if (!container) return;
        const scrollLeft = container.scrollLeft;
        const width = container.clientWidth;
        const index = Math.round(scrollLeft / width);
        const dots = document.querySelectorAll(`#card-dots-${productId} span`);
        dots.forEach((dot, idx) => {
            if (idx === index) {
                dot.className = 'w-1.5 h-1.5 rounded-full bg-white transition-all duration-300';
            } else {
                dot.className = 'w-1.5 h-1.5 rounded-full bg-white/40 transition-all duration-300';
            }
        });
    }

    function switchTab(id) {
        console.log('Switching to tab:', id);
        const url = new URL(window.location);
        const currentTab = url.searchParams.get('tab') || 'pending';
        if (currentTab === id) {
            return; // Already on this tab
        }
        url.searchParams.set('tab', id);
        url.searchParams.set('p', '1'); // Safe page reset
        loadUrlAjax(url.toString(), true);
    }

    function loadUrlAjax(url, pushState = true) {
        showLoader(true);
        fetch(url)
            .then(res => res.text())
            .then(html => {
                showLoader(false);
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Swap main container
                const newMain = doc.querySelector('#portal-main-container');
                const oldMain = document.querySelector('#portal-main-container');
                if (newMain && oldMain) {
                    oldMain.innerHTML = newMain.innerHTML;
                }
                
                // Re-initialize any JS variables that PHP rendered in the new content
                const scripts = doc.querySelectorAll('#portal-main-container script');
                scripts.forEach(scr => {
                    try {
                        eval(scr.textContent);
                    } catch(e) {
                        console.error('Error running script:', e);
                    }
                });

                if (pushState) {
                    history.pushState(null, '', url);
                }
                
                // Scroll to top smoothly
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(err => {
                showLoader(false);
                console.error('AJAX load error:', err);
                window.location.href = url;
            });
    }

    // Capture submit events on filter form
    document.addEventListener('submit', function(e) {
        if (e.target && e.target.id === 'filter-form') {
            e.preventDefault();
            applyFiltersAjax(e.target);
        }
    });

    // Capture change events on select filters to filter instantly
    document.addEventListener('change', function(e) {
        const form = document.getElementById('filter-form');
        if (form && form.contains(e.target) && e.target.tagName === 'SELECT') {
            applyFiltersAjax(form);
        }
    });

    // Instantly filter as user types with debounce
    let debounceTimer;
    document.addEventListener('input', function(e) {
        const form = document.getElementById('filter-form');
        if (form && form.contains(e.target) && e.target.name === 'f_q') {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                applyFiltersAjax(form);
            }, 300);
        }
    });

    function applyFiltersAjax(form) {
        const formData = new FormData(form);
        const params = new URLSearchParams();
        
        // Retain current tab
        const url = new URL(window.location);
        const currentTab = url.searchParams.get('tab') || 'pending';
        params.set('tab', currentTab);
        params.set('p', '1'); // Go to first page on new search
        
        for (const [key, value] of formData.entries()) {
            if (value !== '' && key !== 'tab' && key !== 'p') {
                params.set(key, value);
            }
        }
        
        const finalUrl = 'portal.php?' + params.toString();
        loadUrlAjax(finalUrl, true);
    }

    // Intercept clicks on tab and pagination links
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link) {
            const href = link.getAttribute('href');
            if (href && (href.startsWith('?tab=') || href.includes('portal.php?tab='))) {
                e.preventDefault();
                loadUrlAjax(href, true);
            }
        }
    });

    // Monitor back / forward buttons
    window.addEventListener('popstate', function() {
        loadUrlAjax(window.location.href, false);
    });

    function filterCalendar(status) {
        const posts = document.querySelectorAll('.cal-v2-post');
        posts.forEach(p => {
            if(status === 'all' || p.getAttribute('data-status') === status) {
                p.style.display = 'flex';
            } else {
                p.style.display = 'none';
            }
        });
    }

    function filterPlatform(plt) {
        const posts = document.querySelectorAll('.cal-v2-post');
        posts.forEach(p => {
            if(plt === 'all' || p.getAttribute('data-platform') === plt) {
                p.style.display = 'flex';
            } else {
                p.style.display = 'none';
            }
        });
    }

    function showScheduleModal(s) {
        document.getElementById('sm-title').innerText = s.productName || 'پست سفارشی';
        const at = s.scheduledAt || s.scheduled_at;
        const dateStr = isNaN(at) ? at : new Date(at * 1000).toLocaleString('fa-IR');
        document.getElementById('sm-meta').innerText = `${s.platform.toUpperCase()} • ${dateStr}`;
        document.getElementById('sm-caption').innerText = s.caption || 'بدون مشخصات';
        
        // Build Carousel Images list for schedule approval modal
        scheduleCarouselImages = [];
        
        if (s.mediaUrl && !s.mediaUrl.includes('placehold')) {
            let cleanMedia = s.mediaUrl;
            if (s.mediaUrl.includes('|')) {
                cleanMedia = s.mediaUrl.split('|')[0];
            }
            let individualImages = cleanMedia.split(',').map(x => x.trim()).filter(Boolean);
            individualImages.forEach(imgUrl => {
                scheduleCarouselImages.push(imgUrl);
            });
        }
        if (s.pImg && !s.pImg.includes('placehold')) {
            scheduleCarouselImages.push(s.pImg);
        }
        
        // Find associated product in portal products to get the rest of the images
        const p = s.productId ? window.portalProducts[s.productId] : null;
        if (p) {
            if (p.social_image && !p.social_image.includes('placehold')) {
                scheduleCarouselImages.push(p.social_image);
            }
            if (p.image && !p.image.includes('placehold')) {
                scheduleCarouselImages.push(p.image);
            }
            let gal = p.gallery;
            if (gal && typeof gal === 'string') {
                try { gal = JSON.parse(gal); } catch(e) { gal = []; }
            }
            if (gal && Array.isArray(gal)) {
                gal.forEach(img => {
                    if (img && !img.includes('placehold')) {
                        scheduleCarouselImages.push(img);
                    }
                });
            }
            let ref_gal = p.reference_gallery;
            if (ref_gal && typeof ref_gal === 'string') {
                try { ref_gal = JSON.parse(ref_gal); } catch(e) { ref_gal = []; }
            }
            if (ref_gal && Array.isArray(ref_gal)) {
                ref_gal.forEach(img => {
                    if (img && !img.includes('placehold')) {
                        scheduleCarouselImages.push(img);
                    }
                });
            }
        }
        
        scheduleCarouselImages = [...new Set(scheduleCarouselImages)];
        if (scheduleCarouselImages.length === 0) {
            scheduleCarouselImages.push(s.mediaUrl || s.pImg || 'https://placehold.co/400x500?text=No+Image');
        }

        const wrapper = document.getElementById('sm-carousel-wrapper');
        if (wrapper) {
            wrapper.innerHTML = '';
            scheduleCarouselImages.forEach((imgUrl, idx) => {
                const slide = document.createElement('div');
                slide.className = 'w-full h-full flex-shrink-0 relative';
                slide.style.width = '100%';
                slide.style.height = '100%';
                
                const img = document.createElement('img');
                img.src = getFullUrl(imgUrl);
                img.className = 'w-full h-full object-cover';
                img.setAttribute('referrerpolicy', 'no-referrer');
                
                slide.appendChild(img);
                wrapper.appendChild(slide);
            });
        }

        const prevBtn = document.getElementById('sm-carousel-prev');
        const nextBtn = document.getElementById('sm-carousel-next');
        const dotsContainer = document.getElementById('sm-carousel-dots');
        
        if (dotsContainer) {
            dotsContainer.innerHTML = '';
            scheduleCarouselIndex = 0;
            updateScheduleCarousel();
            
            if (scheduleCarouselImages.length > 1) {
                if (prevBtn) prevBtn.classList.remove('hidden');
                if (nextBtn) nextBtn.classList.remove('hidden');
                
                scheduleCarouselImages.forEach((_, idx) => {
                    const dot = document.createElement('button');
                    dot.className = `w-2.5 h-2.5 rounded-full transition-all duration-300 ${idx === 0 ? 'bg-brand-600 scale-125' : 'bg-slate-300 hover:bg-slate-400'}`;
                    dot.onclick = (e) => {
                        e.stopPropagation();
                        goToScheduleCarousel(idx);
                    };
                    dotsContainer.appendChild(dot);
                });
            } else {
                if (prevBtn) prevBtn.classList.add('hidden');
                if (nextBtn) nextBtn.classList.add('hidden');
            }
        }
        
        const commContainer = document.getElementById('sm-comments');
        commContainer.innerHTML = '';
        let comments = s.comments || [];
        if (typeof comments === 'string') {
            try { comments = JSON.parse(comments); } catch(e) { comments = []; }
        }
        
        comments.forEach(c => {
            const div = document.createElement('div');
            div.className = 'bg-slate-50 p-3 rounded-xl mb-2 text-[10px]';
            div.innerHTML = `<span class="font-black text-indigo-600">${c.user}:</span> ${c.text}`;
            commContainer.appendChild(div);
        });

        // Populate Schedule Split Approval & Rejection history details
        const smApprovalContainer = document.getElementById('sm-split-approval-container');
        if (smApprovalContainer) {
            smApprovalContainer.innerHTML = '';
            const settings = window.projectSettings || {};
            const isSplit = !!settings.cal_approval_split_enabled;
            
            if (isSplit || s.rejection_reason || (s.rejections_history && s.rejections_history.length > 0)) {
                smApprovalContainer.classList.remove('hidden');
                smApprovalContainer.style.display = 'flex';
                
                let html = '';
                if (isSplit) {
                    const st1_count = (s.approvals_stage1 || []).length;
                    const st2_count = (s.approvals_stage2 || []).length;
                    const st1_min = parseInt(settings.cal_approval_stage1_min) || 1;
                    const st2_min = parseInt(settings.cal_approval_stage2_min) || 1;
                    
                    const st1_ok = st1_count >= st1_min;
                    const st2_ok = st2_count >= st2_min;
                    
                    let st1_text = '⏳ در انتظار';
                    let st1_color = 'text-amber-500';
                    if (s.stage1_decision === 'rejected') {
                        st1_text = '❌ رد شده';
                        st1_color = 'text-rose-500 font-bold';
                    } else if (s.stage1_system_approved || (s.approvals_stage1 && s.approvals_stage1.includes('سیستم (پاسخ خودکار)'))) {
                        st1_text = '⚙️ تایید سیستمی';
                        st1_color = 'text-emerald-600 font-bold';
                    } else if (st1_ok) {
                        st1_text = '✅ تایید شده';
                        st1_color = 'text-emerald-600 font-bold';
                    }

                    let st2_text = '⏳ در انتظار';
                    let st2_color = 'text-amber-500';
                    if (s.stage2_decision === 'rejected') {
                        st2_text = '❌ رد شده';
                        st2_color = 'text-rose-500 font-bold';
                    } else if (s.stage2_system_approved || (s.approvals_stage2 && s.approvals_stage2.includes('سیستم (پاسخ خودکار)'))) {
                        st2_text = '⚙️ تایید سیستمی';
                        st2_color = 'text-emerald-600 font-bold';
                    } else if (st2_ok) {
                        st2_text = '✅ تایید شده';
                        st2_color = 'text-emerald-600 font-bold';
                    }
                    
                    html += `
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">📋 وضعیت تایید دو مرحله‌ای زمان‌بندی</label>
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px] font-semibold p-2 bg-white rounded-xl border border-slate-100">
                                <span class="text-slate-600">🛡️ بخش اول: چیدمان و تقویم (تیم ۱):</span>
                                <span class="${st1_color}">
                                    ${st1_text} (${st1_count} از ${st1_min})
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-[11px] font-semibold p-2 bg-white rounded-xl border border-slate-100">
                                <span class="text-slate-600">📐 بخش دوم: موجودی و فروش (تیم ۲):</span>
                                <span class="${st2_color}">
                                    ${st2_text} (${st2_count} از ${st2_min})
                                </span>
                            </div>
                        </div>
                    `;
                }
                
                // Show current rejection details if any
                if (s.rejection_reason) {
                    html += `
                        <div class="mt-2 bg-rose-50 text-rose-700 p-3 rounded-xl border border-rose-100/50 text-xs font-semibold">
                            <div class="font-black text-rose-800 flex items-center gap-1 mb-1">
                                <span>🚫 رد شده توسط:</span>
                                <span>${s.rejection_by || 'ناظر'} (${s.rejection_side || 'بخش نامشخص'})</span>
                            </div>
                            <p class="leading-relaxed bg-white/70 p-2 rounded-lg mt-1 border border-rose-100/50 text-[11px]">${s.rejection_reason}</p>
                            <div class="text-[9px] text-rose-500 text-left font-bold mt-1.5">${s.rejection_at ? new Date(s.rejection_at * 1000).toLocaleDateString('fa-IR') : ''}</div>
                        </div>
                    `;
                }
                
                // Show rejection history if any
                if (s.rejections_history && s.rejections_history.length > 0) {
                    html += `
                        <div class="mt-2 text-right" dir="rtl">
                            <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest block mb-1">📜 تاریخچه بازخوردها و اصلاحات رد شده</label>
                            <div class="space-y-1.5 max-h-[120px] overflow-y-auto custom-scrollbar">
                    `;
                    s.rejections_history.forEach(rej => {
                        html += `
                            <div class="p-2 bg-rose-50/20 border border-thin border-rose-100/30 rounded-lg text-[10px] leading-relaxed">
                                <div class="flex justify-between font-black text-rose-900 mb-0.5 flex-wrap gap-1">
                                    <span>👤 توسط: ${rej.user || 'ناظر'} (${rej.side || 'نامشخص'})</span>
                                    <span>📅 ${new Date(rej.at * 1000).toLocaleDateString('fa-IR')}</span>
                                </div>
                                <div class="text-slate-600 font-semibold bg-white/30 p-1 rounded-md">${rej.reason}</div>
                            </div>
                        `;
                    });
                    html += `
                            </div>
                        </div>
                    `;
                }
                
                smApprovalContainer.innerHTML = html;
            } else {
                smApprovalContainer.classList.add('hidden');
                smApprovalContainer.style.display = 'none';
            }
        }

        const btnContainer = document.getElementById('schedule-approval-buttons');
        if (btnContainer) {
            const settings = window.projectSettings || {};
            const isSplit = !!settings.cal_approval_split_enabled;
            const currentUser = (window.currentUser || '').toLowerCase();
            
            if (isSplit) {
                const st1_users = (settings.cal_approval_stage1_users || '').toLowerCase().split(/[,\u060c]+/).map(u => u.trim()).filter(Boolean);
                const st2_users = (settings.cal_approval_stage2_users || '').toLowerCase().split(/[,\u060c]+/).map(u => u.trim()).filter(Boolean);
                
                const s_st1_approvals = s.approvals_stage1 || [];
                const s_st2_approvals = s.approvals_stage2 || [];
                
                const isSuperOrAdmin = !!window.isSuperOrAdmin;
                const canApproveSt1 = isSuperOrAdmin || st1_users.length === 0 || st1_users.includes(currentUser);
                const canApproveSt2 = isSuperOrAdmin || st2_users.length === 0 || st2_users.includes(currentUser);
                
                const hasApprovedSt1 = s_st1_approvals.map(u => (u || '').toLowerCase()).includes(currentUser);
                const hasApprovedSt2 = s_st2_approvals.map(u => (u || '').toLowerCase()).includes(currentUser);
                
                const st1_min = parseInt(settings.cal_approval_stage1_min) || 1;
                const st2_min = parseInt(settings.cal_approval_stage2_min) || 1;
                
                let html = '';
                
                // Stage 1 Button
                if (canApproveSt1) {
                    if (hasApprovedSt1) {
                        html += `<button disabled class="flex-1 py-3 bg-emerald-100 text-emerald-700 rounded-xl font-black text-xs cursor-not-allowed opacity-80">
                            تایید چیدمان ثبت شده (${s_st1_approvals.length} از ${st1_min})
                        </button>`;
                    } else {
                        html += `<button onclick="voteSchedule('${s.id}', 'approved', 'stage1')" class="flex-1 py-3 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-black text-xs transition-transform hover:scale-[1.02] shadow-sm cursor-pointer">
                            تایید چیدمان (بخش ۱)
                        </button>`;
                    }
                } else {
                    html += `<button disabled class="flex-1 py-3 bg-rose-50/50 text-rose-450 border border-rose-100/40 rounded-xl font-bold text-[10px] cursor-not-allowed opacity-60">
                        تایید چیدمان (غیرمجاز 🚫)
                    </button>`;
                }
                
                // Stage 2 Button
                if (canApproveSt2) {
                    if (hasApprovedSt2) {
                        html += `<button disabled class="flex-1 py-3 bg-emerald-100 text-emerald-700 rounded-xl font-black text-xs cursor-not-allowed opacity-80">
                            تایید موجودی ثبت شده (${s_st2_approvals.length} از ${st2_min})
                        </button>`;
                    } else {
                        html += `<button onclick="voteSchedule('${s.id}', 'approved', 'stage2')" class="flex-1 py-3 bg-teal-500 hover:bg-teal-600 text-white rounded-xl font-black text-xs transition-transform hover:scale-[1.02] shadow-sm cursor-pointer">
                            تایید موجودی (بخش ۲)
                        </button>`;
                    }
                } else {
                    html += `<button disabled class="flex-1 py-3 bg-rose-50/50 text-rose-450 border border-rose-100/40 rounded-xl font-bold text-[10px] cursor-not-allowed opacity-60">
                        تایید موجودی (غیرمجاز 🚫)
                    </button>`;
                }
                
                // Row container for Rejections
                html += `<div class="w-full flex gap-2 mt-2">`;
                
                // Stage 1 Rejection Better Output Check
                if (canApproveSt1) {
                    html += `<button onclick="voteSchedule('${s.id}', 'rejected', 'stage1')" class="flex-1 py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-xs transition-transform hover:scale-[1.02] shadow-sm cursor-pointer">
                        ❌ رد چیدمان (بخش ۱)
                    </button>`;
                } else {
                    html += `<button disabled class="flex-1 py-3 bg-rose-50/55 text-rose-400 border border-rose-100/30 rounded-xl font-bold text-[10px] cursor-not-allowed opacity-50">
                        ❌ رد بخش ۱ (غیرمجاز)
                    </button>`;
                }
                
                // Stage 2 Rejection Better Output Check
                if (canApproveSt2) {
                    html += `<button onclick="voteSchedule('${s.id}', 'rejected', 'stage2')" class="flex-1 py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-xs transition-transform hover:scale-[1.02] shadow-sm cursor-pointer">
                        ❌ رد موجودی (بخش ۲)
                    </button>`;
                } else {
                    html += `<button disabled class="flex-1 py-3 bg-rose-50/55 text-rose-400 border border-rose-100/30 rounded-xl font-bold text-[10px] cursor-not-allowed opacity-50">
                        ❌ رد بخش ۲ (غیرمجاز)
                    </button>`;
                }
                
                html += `</div>`;
                
                btnContainer.innerHTML = html;
            } else {
                btnContainer.innerHTML = `
                    <button onclick="voteSchedule('${s.id}', 'approved')" id="sm-approve-btn" class="flex-1 py-3 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-black text-xs transition-all shadow-sm cursor-pointer">تایید نهایی ✅</button>
                    <button onclick="voteSchedule('${s.id}', 'rejected')" id="sm-reject-btn" class="flex-1 py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-xs transition-all shadow-sm cursor-pointer">نیاز به اصلاح ❌</button>
                `;
            }
        }
        document.getElementById('sm-submit-comment').onclick = () => commentSchedule(s.id);

        document.getElementById('schedule-modal').style.display = 'flex';
    }

    async function voteSchedule(sid, type, stageName = '') {
        if (type === 'rejected') {
            const commentInput = document.getElementById('sm-comment-input');
            const reason = commentInput ? commentInput.value.trim() : '';
            if (!reason) {
                alert('جهت رد کردن زمان‌بندی، لطفا ابتدا علت رد را در کادر نظرات پایین پنجره (افزودن نظر...) وارد نمایید و سپس دکمه رد را بزنید.');
                return;
            }
            showLoader(true);
            const fd = new FormData();
            fd.append('action', 'vote_schedule');
            fd.append('schedule_id', sid);
            fd.append('type', type);
            fd.append('reason', reason);
            if (stageName) {
                fd.append('stage', stageName);
            }
            try {
                const r = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const res = await r.json();
                showLoader(false);
                if(res.status === 'success') {
                    toast(res.msg);
                    location.reload();
                } else {
                    toast(res.msg || 'خطایی رخ داد.');
                }
            } catch(e) { showLoader(false); }
        } else {
            showLoader(true);
            const fd = new FormData();
            fd.append('action', 'vote_schedule');
            fd.append('schedule_id', sid);
            fd.append('type', type);
            if (stageName) {
                fd.append('stage', stageName);
            }
            try {
                const r = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const res = await r.json();
                showLoader(false);
                if(res.status === 'success') {
                    toast(res.msg);
                    location.reload();
                } else {
                    toast(res.msg || 'خطایی رخ داد.');
                }
            } catch(e) { showLoader(false); }
        }
    }

    async function commentSchedule(sid) {
        const text = document.getElementById('sm-comment-input').value;
        if(!text) return;
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'comment_schedule');
        fd.append('schedule_id', sid);
        fd.append('text', text);
        try {
            const r = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const res = await r.json();
            showLoader(false);
            if(res.status === 'success') {
                toast(res.msg);
                document.getElementById('sm-comment-input').value = '';
                location.reload();
            }
        } catch(e) { showLoader(false); }
    }

    let portalCarouselIndex = 0;
    let portalCarouselImages = [];

    function movePortalCarousel(direction) {
        portalCarouselIndex += direction;
        if (portalCarouselIndex < 0) {
            portalCarouselIndex = portalCarouselImages.length - 1;
        } else if (portalCarouselIndex >= portalCarouselImages.length) {
            portalCarouselIndex = 0;
        }
        updatePortalCarousel();
    }

    function goToPortalCarousel(idx) {
        portalCarouselIndex = idx;
        updatePortalCarousel();
    }

    function updatePortalCarousel() {
        const wrapper = document.getElementById('pd-carousel-wrapper');
        const offset = -portalCarouselIndex * 100;
        wrapper.style.transform = `translateX(${offset}%)`;
        
        const dots = document.querySelectorAll('#pd-carousel-dots button');
        dots.forEach((dot, idx) => {
            if (idx === portalCarouselIndex) {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-brand-600 scale-125 transition-all duration-300';
            } else {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-slate-300 hover:bg-slate-400 transition-all duration-300';
            }
        });
    }

    let scheduleCarouselIndex = 0;
    let scheduleCarouselImages = [];

    function moveScheduleCarousel(direction) {
        scheduleCarouselIndex += direction;
        if (scheduleCarouselIndex < 0) {
            scheduleCarouselIndex = scheduleCarouselImages.length - 1;
        } else if (scheduleCarouselIndex >= scheduleCarouselImages.length) {
            scheduleCarouselIndex = 0;
        }
        updateScheduleCarousel();
    }

    function goToScheduleCarousel(idx) {
        scheduleCarouselIndex = idx;
        updateScheduleCarousel();
    }

    function updateScheduleCarousel() {
        const wrapper = document.getElementById('sm-carousel-wrapper');
        if (!wrapper) return;
        const offset = -scheduleCarouselIndex * 100;
        wrapper.style.transform = `translateX(${offset}%)`;
        
        const dots = document.querySelectorAll('#sm-carousel-dots button');
        dots.forEach((dot, idx) => {
            if (idx === scheduleCarouselIndex) {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-brand-600 scale-125 transition-all duration-300';
            } else {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-slate-300 hover:bg-slate-400 transition-all duration-300';
            }
        });
    }

    let currentOpenPid = null;
    function openDetail(p) {
        currentOpenPid = p.id;
        document.getElementById('pd-title').innerText = p.name;
        document.getElementById('pd-price').innerText = p.price;
        document.getElementById('pd-sku').innerText = 'SKU: ' + (p.sku || '---');
        
        // Build Carousel Images list based on priority: social_image > gallery > image
        portalCarouselImages = [];
        const isPending = (p.status === 'pending');
        
        const isImgApproved = (imgUrl) => {
            if (!p) return false;
            let list = p.images_list || [];
            if (typeof list === 'string') {
                try { list = JSON.parse(list); } catch(e) { list = []; }
            }
            if (Array.isArray(list)) {
                const cleanImg = imgUrl ? imgUrl.split('?')[0] : '';
                const found = list.find(item => {
                    const cleanItemUrl = item && item.url ? item.url.split('?')[0] : '';
                    return cleanItemUrl && cleanItemUrl === cleanImg;
                });
                if (found) {
                    return !!found.approved;
                }
            }
            if ((p.status || '') === 'active') {
                return true;
            }
            return false;
        };

        if (p.social_image && !p.social_image.includes('placehold')) {
            if (!isPending || !isImgApproved(p.social_image)) {
                portalCarouselImages.push(p.social_image);
            }
        }
        let gal = p.gallery;
        if (gal && typeof gal === 'string') {
            try { gal = JSON.parse(gal); } catch(e) { gal = []; }
        }
        if (gal && Array.isArray(gal)) {
            gal.forEach(img => {
                if (img && !img.includes('placehold')) {
                    if (!isPending || !isImgApproved(img)) {
                        portalCarouselImages.push(img);
                    }
                }
            });
        }
        if (p.image && !p.image.includes('placehold')) {
            if (!isPending || !isImgApproved(p.image)) {
                portalCarouselImages.push(p.image);
            }
        }
        let refGal = p.reference_gallery;
        if (refGal && typeof refGal === 'string') {
            try { refGal = JSON.parse(refGal); } catch(e) { refGal = []; }
        }
        if (refGal && Array.isArray(refGal)) {
            refGal.forEach(img => {
                if (img && !img.includes('placehold')) {
                    if (!isPending || !isImgApproved(img)) {
                        portalCarouselImages.push(img);
                    }
                }
            });
        }
        portalCarouselImages = [...new Set(portalCarouselImages)];
        
        // Fallback: if zero images are left because all were approved, show all of them
        if (portalCarouselImages.length === 0) {
            if (p.social_image && !p.social_image.includes('placehold')) {
                portalCarouselImages.push(p.social_image);
            }
            if (gal && Array.isArray(gal)) {
                gal.forEach(img => {
                    if (img && !img.includes('placehold')) {
                        portalCarouselImages.push(img);
                    }
                });
            }
            if (p.image && !p.image.includes('placehold')) {
                portalCarouselImages.push(p.image);
            }
            if (refGal && Array.isArray(refGal)) {
                refGal.forEach(img => {
                    if (img && !img.includes('placehold')) {
                        portalCarouselImages.push(img);
                    }
                });
            }
            portalCarouselImages = [...new Set(portalCarouselImages)];
        }
        
        if (portalCarouselImages.length === 0) {
            portalCarouselImages.push(p.social_image || p.image || 'https://placehold.co/400x500?text=No+Image');
        }
        
        const wrapper = document.getElementById('pd-carousel-wrapper');
        wrapper.innerHTML = '';
        portalCarouselImages.forEach((imgUrl, idx) => {
            const slide = document.createElement('div');
            slide.className = 'w-full h-full flex-shrink-0 relative';
            slide.style.width = '100%';
            slide.style.height = '100%';
            
            const img = document.createElement('img');
            img.src = getFullUrl(imgUrl);
            img.className = 'w-full h-full object-cover';
            img.setAttribute('referrerpolicy', 'no-referrer');
            
            slide.appendChild(img);
            wrapper.appendChild(slide);
        });
        
        const prevBtn = document.getElementById('pd-carousel-prev');
        const nextBtn = document.getElementById('pd-carousel-next');
        const dotsContainer = document.getElementById('pd-carousel-dots');
        
        dotsContainer.innerHTML = '';
        portalCarouselIndex = 0;
        updatePortalCarousel();
        
        if (portalCarouselImages.length > 1) {
            prevBtn.classList.remove('hidden');
            nextBtn.classList.remove('hidden');
            
            portalCarouselImages.forEach((_, idx) => {
                const dot = document.createElement('button');
                dot.className = `w-2.5 h-2.5 rounded-full transition-all duration-300 ${idx === 0 ? 'bg-brand-600 scale-125' : 'bg-slate-300 hover:bg-slate-400'}`;
                dot.onclick = (e) => {
                    e.stopPropagation();
                    goToPortalCarousel(idx);
                };
                dotsContainer.appendChild(dot);
            });
        } else {
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
        }
        
        document.getElementById('pd-likes-count').innerText = p.likes || 0;
        document.getElementById('pd-dislikes-count').innerText = p.dislikes || 0;

        // Populate Creator
        const creatorContainer = document.getElementById('pd-creator-container');
        const creatorNameEl = document.getElementById('pd-creator-name');
        if (p.uploader_info && p.uploader_info.name) {
            creatorNameEl.innerText = `${p.uploader_info.name} (آیدی: ${p.uploader_info.id}) - [${p.uploader_info.role}]`;
            creatorContainer.classList.remove('hidden');
        } else if (p.uploaded_by) {
            creatorNameEl.innerText = p.uploaded_by;
            creatorContainer.classList.remove('hidden');
        } else {
            creatorContainer.classList.add('hidden');
        }

        // Populate Description
        const descEl = document.getElementById('pd-description');
        if (p.description) {
            descEl.innerText = p.description;
            descEl.classList.remove('hidden');
        } else {
            descEl.classList.add('hidden');
        }

        // Populate Stock Levels & Warehouses
        const stockContainer = document.getElementById('pd-stock-container');
        const stockOnlineEl = document.getElementById('pd-stock-online');
        const stockTotalEl = document.getElementById('pd-stock-total');
        const warehousesListEl = document.getElementById('pd-warehouses-list');
        const warehousesItemsEl = document.getElementById('pd-warehouses-items');

        if (stockContainer && stockOnlineEl && stockTotalEl) {
            if (p.stock_online || p.stock_total || (p.warehouses && Object.keys(p.warehouses).length > 0)) {
                stockOnlineEl.innerText = p.stock_online || '—';
                stockTotalEl.innerText = p.stock_total || '—';
                stockContainer.classList.remove('hidden');
                stockContainer.style.display = 'flex';

                if (p.warehouses && Object.keys(p.warehouses).length > 0 && warehousesListEl && warehousesItemsEl) {
                    warehousesListEl.classList.remove('hidden');
                    let wHtml = '';
                    for (const [wName, wStock] of Object.entries(p.warehouses)) {
                        wHtml += `<span class="px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-[10px] font-bold text-slate-700 shadow-sm flex items-center gap-1">🏢 <span>${wName}:</span> <b class="text-emerald-600">${wStock} عدد</b></span>`;
                    }
                    warehousesItemsEl.innerHTML = wHtml;
                } else if (warehousesListEl) {
                    warehousesListEl.classList.add('hidden');
                }
            } else {
                stockContainer.classList.add('hidden');
                stockContainer.style.display = 'none';
            }
        }

        // Populate Social Shared Data and Publish Priority Badge
        const socialContainer = document.getElementById('pd-social-container');
        const socialStats = document.getElementById('pd-social-stats');
        
        if (socialContainer && socialStats) {
            socialStats.innerHTML = '';
            
            // Map platforms sent (it could be an object/dict or array)
            const sentObj = { telegram: 0, bale: 0, rubika: 0, instagram: 0 };
            const rawSent = p.platformsSent || p.platforms_sent || [];
            
            if (Array.isArray(rawSent)) {
                rawSent.forEach(item => {
                    const plat = item.platform || item;
                    if (sentObj.hasOwnProperty(plat)) {
                        sentObj[plat]++;
                    }
                });
            } else if (rawSent && typeof rawSent === 'object') {
                for (const plat in rawSent) {
                    if (sentObj.hasOwnProperty(plat)) {
                        sentObj[plat] = parseInt(rawSent[plat]) || 0;
                    }
                }
            }
            
            let hasSentStats = false;
            const platformLabels = {
                telegram: 'تلگرام',
                bale: 'بله',
                rubika: 'روبیکا',
                instagram: 'اینستاگرام'
            };
            const platformIcons = {
                telegram: '🔵',
                bale: '🟢',
                rubika: '🟡',
                instagram: '📸'
            };
            
            for (const plat in sentObj) {
                const count = sentObj[plat];
                const card = document.createElement('div');
                if (count > 0) {
                    hasSentStats = true;
                    card.className = "bg-emerald-50/70 border border-emerald-100 p-2.5 rounded-xl text-center flex flex-col items-center justify-center";
                    card.innerHTML = `
                        <div class="text-[9px] font-black text-emerald-800 flex items-center gap-1">
                            <span>${platformIcons[plat]}</span>
                            <span>${platformLabels[plat]}</span>
                        </div>
                        <div class="text-[11px] font-extrabold text-emerald-600 mt-1">✓ ارسال شده (${count} بار)</div>
                    `;
                } else {
                    card.className = "bg-white border border-slate-100 p-2.5 rounded-xl text-center opacity-60 flex flex-col items-center justify-center";
                    card.innerHTML = `
                        <div class="text-[9px] font-black text-slate-400 flex items-center gap-1">
                            <span>${platformIcons[plat]}</span>
                            <span>${platformLabels[plat]}</span>
                        </div>
                        <div class="text-[10px] font-bold text-slate-350 mt-1">عدم ارسال</div>
                    `;
                }
                socialStats.appendChild(card);
            }
            
            // Also append Publish Priority indication inside the social block or under it
            if (p.publish_priority) {
                hasSentStats = true;
                const priorityCard = document.createElement('div');
                priorityCard.className = "col-span-2 sm:col-span-4 bg-indigo-50 border border-indigo-150 p-3 rounded-xl flex items-center justify-between mt-2 shadow-sm";
                priorityCard.innerHTML = `
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 bg-indigo-600 rounded-full animate-pulse"></span>
                        <span class="text-xs font-black text-indigo-800">اولویت تقویم و انتشار 📌</span>
                    </div>
                    <span class="text-[10px] font-extrabold text-indigo-600">در صف انتشار با اولویت بالا</span>
                `;
                socialStats.appendChild(priorityCard);
            }
            
            if (hasSentStats || p.publish_priority) {
                socialContainer.classList.remove('hidden');
                socialContainer.style.display = 'flex';
            } else {
                socialContainer.classList.add('hidden');
                socialContainer.style.display = 'none';
            }
        }

        // Populate Sizes
        const sizesContainer = document.getElementById('pd-sizes-container');
        const sizesEl = document.getElementById('pd-sizes');
        sizesEl.innerHTML = '';
        const sizes = p.sizes || [];
        
        let hasSizingContent = false;
        if (sizes.length > 0) {
            hasSizingContent = true;
            sizes.forEach(s => {
                const badge = document.createElement('span');
                badge.className = 'px-3 py-1 bg-white border-2 border-slate-100 rounded-lg text-[10px] font-black text-slate-700';
                badge.innerText = s;
                sizesEl.appendChild(badge);
            });
        }
        
        // Remove existing size chart button if any
        const existingChartBtn = document.getElementById('pd-size-chart-btn');
        if (existingChartBtn) {
            existingChartBtn.remove();
        }
        
        if (p.size_chart) {
            hasSizingContent = true;
            const btn = document.createElement('button');
            btn.id = 'pd-size-chart-btn';
            btn.type = 'button';
            btn.className = 'mt-3 flex items-center gap-2 px-4 py-2.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-100 rounded-xl text-xs font-black transition-all cursor-pointer shadow-sm w-full justify-center';
            btn.innerHTML = `📐 مشاهده جدول سایزبندی`;
            btn.onclick = (e) => {
                e.stopPropagation();
                openSizingChartModal(getFullUrl(p.size_chart));
            };
            sizesContainer.appendChild(btn);
        }
        
        if (hasSizingContent) {
            sizesContainer.classList.remove('hidden');
        } else {
            sizesContainer.classList.add('hidden');
        }

        // Populate Color Variants
        const variantsContainer = document.getElementById('pd-variants-container');
        const variantsEl = document.getElementById('pd-variants');
        variantsEl.innerHTML = '';
        const variants = p.variants || [];
        if (variants.length > 1) {
            variants.forEach(v => {
                const btn = document.createElement('button');
                const isCurrent = (v.id == p.id);
                btn.className = isCurrent 
                    ? 'flex items-center gap-2 px-3 py-1.5 bg-brand-50 border-2 border-brand-500 rounded-xl text-xs font-black text-brand-700 pointer-events-none'
                    : 'flex items-center gap-2 px-3 py-1.5 bg-white border border-slate-200 hover:border-brand-300 rounded-xl text-xs font-semibold text-slate-600 hover:text-brand-600 transition-all cursor-pointer';
                
                let imgHtml = '';
                if (v.image) {
                    const fullImgUrl = getFullUrl(v.image);
                    imgHtml = `<img src="${fullImgUrl}" class="w-5 h-5 rounded-md object-cover border border-slate-100 flex-shrink-0" onerror="this.src='https://placehold.co/50x50?text=Color'">`;
                } else {
                    imgHtml = `<span class="text-xs flex-shrink-0">🎨</span>`;
                }
                
                btn.innerHTML = `
                    ${imgHtml}
                    <span class="truncate max-w-[100px]">${v.color}</span>
                `;
                
                if (!isCurrent) {
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        openDetailById(v.id);
                    };
                }
                variantsEl.appendChild(btn);
            });
            variantsContainer.classList.remove('hidden');
        } else {
            variantsContainer.classList.add('hidden');
        }
        
        const visitorId = '<?php echo $_SESSION['user']['username'] ?? $_SESSION['visitor_id'] ?? ''; ?>';
        const votes = p.voterVotes || {};
        const myVote = votes[visitorId] || null;
        
        const likeBtn = document.getElementById('pd-like-btn');
        const dislikeBtn = document.getElementById('pd-dislike-btn');
        
        const activeLikeCls = "flex items-center justify-center gap-2 py-3.5 bg-emerald-500 border-emerald-500 text-white rounded-2xl font-black text-sm cursor-pointer transition-all shadow-md shadow-emerald-100 flex-1";
        const inactiveLikeCls = "flex items-center justify-center gap-2 py-3.5 bg-emerald-50 border-emerald-100 text-emerald-600 rounded-2xl font-black text-sm cursor-pointer transition-all hover:bg-emerald-100 flex-1";
        
        const activeDislikeCls = "flex items-center justify-center gap-2 py-3.5 bg-rose-500 border-rose-500 text-white rounded-2xl font-black text-sm cursor-pointer transition-all shadow-md shadow-rose-100 flex-1";
        const inactiveDislikeCls = "flex items-center justify-center gap-2 py-3.5 bg-rose-50 border-rose-100 text-rose-600 rounded-2xl font-black text-sm cursor-pointer transition-all hover:bg-rose-100 flex-1";

        likeBtn.className = myVote === 'like' ? activeLikeCls : inactiveLikeCls;
        dislikeBtn.className = myVote === 'dislike' ? activeDislikeCls : inactiveDislikeCls;
        
        likeBtn.onclick = () => { vote('like', p.id); };
        dislikeBtn.onclick = () => { vote('dislike', p.id); };

        // Populate Split Approval Details
        const approvalContainer = document.getElementById('pd-split-approval-container');
        if (approvalContainer) {
            approvalContainer.innerHTML = '';
            const settings = window.projectSettings || {};
            const isSplit = !!settings.prod_approval_split_enabled;
            
            if (isSplit || p.rejectionReason || (p.rejections_history && p.rejections_history.length > 0)) {
                approvalContainer.classList.remove('hidden');
                approvalContainer.style.display = 'flex';
                
                let html = '';
                if (isSplit) {
                    const currentUser = (window.currentUser || '').toLowerCase();
                    const st1_users = (settings.prod_approval_stage1_users || '').toLowerCase().split(/[,\u060c]+/).map(u => u.trim()).filter(Boolean);
                    const st2_users = (settings.prod_approval_stage2_users || '').toLowerCase().split(/[,\u060c]+/).map(u => u.trim()).filter(Boolean);
                    
                    const isSuperOrAdmin = !!window.isSuperOrAdmin;
                    const canApproveSt1 = isSuperOrAdmin || st1_users.length === 0 || st1_users.includes(currentUser);
                    const canApproveSt2 = isSuperOrAdmin || st2_users.length === 0 || st2_users.includes(currentUser);
                    
                    const p_st1_approvals = p.approvals_stage1 || [];
                    const p_st2_approvals = p.approvals_stage2 || [];
                    const hasApprovedSt1 = p_st1_approvals.map(u => (u || '').toLowerCase()).includes(currentUser);
                    const hasApprovedSt2 = p_st2_approvals.map(u => (u || '').toLowerCase()).includes(currentUser);

                    const st1_count = (p.approvals_stage1 || []).length;
                    const st2_count = (p.approvals_stage2 || []).length;
                    const st1_min = parseInt(settings.prod_approval_stage1_min) || 1;
                    const st2_min = parseInt(settings.prod_approval_stage2_min) || 1;
                    
                    const st1_ok = st1_count >= st1_min;
                    const st2_ok = st2_count >= st2_min;
                    
                    let st1_text = '⏳ در انتظار';
                    let st1_color = 'text-amber-500';
                    if (p.stage1_decision === 'rejected') {
                        st1_text = '❌ رد شده';
                        st1_color = 'text-rose-500 font-bold';
                    } else if (p.stage1_system_approved || (p.approvals_stage1 && p.approvals_stage1.includes('سیستم (پاسخ خودکار)'))) {
                        st1_text = '⚙️ تایید سیستمی';
                        st1_color = 'text-emerald-600 font-bold';
                    } else if (st1_ok) {
                        st1_text = '✅ تایید شده';
                        st1_color = 'text-emerald-600 font-bold';
                    }

                    let st2_text = '⏳ در انتظار';
                    let st2_color = 'text-amber-500';
                    if (p.stage2_decision === 'rejected') {
                        st2_text = '❌ رد شده';
                        st2_color = 'text-rose-500 font-bold';
                    } else if (p.stage2_system_approved || (p.approvals_stage2 && p.approvals_stage2.includes('سیستم (پاسخ خودکار)'))) {
                        st2_text = '⚙️ تایید سیستمی';
                        st2_color = 'text-emerald-600 font-bold';
                    } else if (st2_ok) {
                        st2_text = '✅ تایید شده';
                        st2_color = 'text-emerald-600 font-bold';
                    }
                    
                    html += `
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">📋 تصمیم‌گیری و وضعیت تایید دو مرحله‌ای محصول</label>
                        <div class="space-y-1.5 mb-3">
                            <div class="flex items-center justify-between text-[11px] font-semibold p-2 bg-white rounded-xl border border-slate-100">
                                <span class="text-slate-600">🛡️ بخش اول: استایل و محتوا (تیم ۱):</span>
                                <span class="${st1_color}">${st1_text} (${st1_count} از ${st1_min})</span>
                            </div>
                            <div class="flex items-center justify-between text-[11px] font-semibold p-2 bg-white rounded-xl border border-slate-100">
                                <span class="text-slate-600">📐 بخش دوم: جزئیات و دقت (تیم ۲):</span>
                                <span class="${st2_color}">${st2_text} (${st2_count} از ${st2_min})</span>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-slate-100 flex flex-col gap-2">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                ${canApproveSt1 ? (
                                    hasApprovedSt1 ? 
                                    `<button disabled class="py-2.5 px-2 bg-emerald-100 text-emerald-700 rounded-xl font-black text-[11px] cursor-not-allowed opacity-80 flex items-center justify-center gap-1">
                                        <span>🎨</span> تایید استایل ثبت شده (${st1_count}/${st1_min})
                                    </button>` :
                                    `<button onclick="advancedAction('approve_stage1', '${p.id}')" class="py-2.5 px-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-black text-[11px] flex items-center justify-center gap-1 shadow-sm hover:shadow transition-all cursor-pointer">
                                        <span>🎨</span> تایید استایل (بخش ۱)
                                    </button>`
                                ) : `
                                    <button disabled class="py-2.5 px-2 bg-rose-50/50 text-rose-400 border border-rose-100/40 rounded-xl font-bold text-[10px] cursor-not-allowed flex items-center justify-center gap-1" title="دسترسی غیر مجاز 🚫">
                                        <span>🎨</span> دسترسی غیر مجاز 🚫
                                    </button>
                                `}

                                ${canApproveSt2 ? (
                                    hasApprovedSt2 ?
                                    `<button disabled class="py-2.5 px-2 bg-emerald-100 text-emerald-700 rounded-xl font-black text-[11px] cursor-not-allowed opacity-80 flex items-center justify-center gap-1">
                                        <span>🧐</span> تایید انطباق ثبت شده (${st2_count}/${st2_min})
                                    </button>` :
                                    `<button onclick="advancedAction('approve_stage2', '${p.id}')" class="py-2.5 px-2 bg-teal-500 hover:bg-teal-600 text-white rounded-xl font-black text-[11px] flex items-center justify-center gap-1 shadow-sm hover:shadow transition-all cursor-pointer">
                                        <span>🧐</span> تایید انطباق (بخش ۲)
                                    </button>`
                                ) : `
                                    <button disabled class="py-2.5 px-2 bg-rose-50/50 text-rose-400 border border-rose-100/40 rounded-xl font-bold text-[10px] cursor-not-allowed flex items-center justify-center gap-1" title="دسترسی غیر مجاز 🚫">
                                        <span>🧐</span> دسترسی غیر مجاز 🚫
                                    </button>
                                `}
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                ${canApproveSt1 ? `
                                    <button onclick="advancedAction('reject_stage1', '${p.id}')" class="py-2.5 px-2 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-[11px] transition-all flex items-center justify-center gap-1 cursor-pointer">
                                        <span>❌</span> رد استایل (بخش ۱)
                                    </button>
                                ` : `
                                    <button disabled class="py-2.5 px-2 bg-rose-50/50 text-rose-350 border border-rose-100/30 rounded-xl font-bold text-[10px] cursor-not-allowed flex items-center justify-center gap-1" title="دسترسی غیر مجاز 🚫">
                                        <span>❌</span> رد بخش ۱ (غیر مجاز)
                                    </button>
                                `}

                                ${canApproveSt2 ? `
                                    <button onclick="advancedAction('reject_stage2', '${p.id}')" class="py-2.5 px-2 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-black text-[11px] transition-all flex items-center justify-center gap-1 cursor-pointer">
                                        <span>❌</span> رد انطباق (بخش ۲)
                                    </button>
                                ` : `
                                    <button disabled class="py-2.5 px-2 bg-rose-50/50 text-rose-350 border border-rose-100/30 rounded-xl font-bold text-[10px] cursor-not-allowed flex items-center justify-center gap-1" title="دسترسی غیر مجاز 🚫">
                                        <span>❌</span> رد بخش ۲ (غیر مجاز)
                                    </button>
                                `}
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">⚡ تعیین وضعیت و تصمیم‌گیری محصول</label>
                        <div class="flex gap-2 w-full">
                            <button onclick="advancedAction('approve', '${p.id}')" class="flex-1 py-3 rounded-2xl text-white font-black text-xs bg-emerald-500 hover:bg-emerald-600 shadow-md transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                                <span>✅</span> تایید تصویر و محصول
                            </button>
                            <button onclick="advancedAction('reject', '${p.id}')" class="flex-1 py-3 rounded-2xl text-white font-black text-xs bg-rose-500 hover:bg-rose-600 shadow-md transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                                <span>❌</span> رد محصول و ثبت اصلاحیه
                            </button>
                        </div>
                    `;
                }
                
                // Show current rejection details if any
                if (p.rejectionReason) {
                    html += `
                        <div class="mt-2 bg-rose-50 text-rose-700 p-3 rounded-xl border border-rose-100/50 text-xs font-semibold">
                            <div class="font-black text-rose-800 flex items-center gap-1 mb-1">
                                <span>🚫 رد شده توسط:</span>
                                <span>${p.rejection_by || 'مدیر'} (${p.rejection_side || 'بخش نامشخص'})</span>
                            </div>
                            <p class="leading-relaxed bg-white/70 p-2 rounded-lg mt-1 border border-rose-100/50 text-[11px]">${p.rejectionReason}</p>
                            <div class="text-[9px] text-rose-500 text-left font-bold mt-1.5">${p.rejection_at ? new Date(p.rejection_at * 1000).toLocaleDateString('fa-IR') : ''}</div>
                        </div>
                    `;
                }
                
                // Show rejection history if any
                if (p.rejections_history && p.rejections_history.length > 0) {
                    html += `
                        <div class="mt-2 text-right" dir="rtl">
                            <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest block mb-1">📜 تاریخچه بازخوردها و اصلاحات رد شده</label>
                            <div class="space-y-1.5 max-h-[120px] overflow-y-auto custom-scrollbar">
                    `;
                    p.rejections_history.forEach(rej => {
                        html += `
                            <div class="p-2 bg-rose-50/20 border border-thin border-rose-100/30 rounded-lg text-[10px] leading-relaxed">
                                <div class="flex justify-between font-black text-rose-900 mb-0.5 flex-wrap gap-1">
                                    <span>👤 توسط: ${rej.user || 'مدیر'} (${rej.side || 'نامشخص'})</span>
                                    <span>📅 ${new Date(rej.at * 1000).toLocaleDateString('fa-IR')}</span>
                                </div>
                                <div class="text-slate-600 font-semibold bg-white/30 p-1 rounded-md">${rej.reason}</div>
                            </div>
                        `;
                    });
                    html += `
                            </div>
                        </div>
                    `;
                }
                
                approvalContainer.innerHTML = html;
            } else {
                approvalContainer.classList.add('hidden');
                approvalContainer.style.display = 'none';
            }
        }
        
        const feedbackContainer = document.getElementById('pd-feedback');
        feedbackContainer.innerHTML = '';
        const feedbacks = p.userFeedback || [];
        if (feedbacks.length === 0) {
            feedbackContainer.innerHTML = '<div class="text-center py-10 text-slate-300 italic text-xs font-bold bg-slate-50 border border-dashed rounded-2xl">کامنتی برای این محصول ثبت نشده است</div>';
        } else {
            // Reverse so we see newest first
            [...feedbacks].reverse().forEach(fb => {
                const item = document.createElement('div');
                item.className = 'flex gap-3 items-start p-3 bg-slate-50/70 border border-slate-100/80 rounded-2xl animate-fade-in mb-2';
                const avatar = fb.icon 
                    ? `<img src="${getFullUrl(fb.icon)}" class="w-8 h-8 rounded-full object-cover shadow-sm flex-shrink-0 bg-slate-100">` 
                    : `<div class="w-8 h-8 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-[11px] font-black border border-brand-100 flex-shrink-0 shadow-sm">${(fb.user||'کاربر').charAt(0)}</div>`;
                item.innerHTML = `
                    ${avatar}
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-1 flex-wrap gap-1">
                            <span class="font-extrabold text-[11px] text-slate-700">${fb.user}</span>
                            <span class="text-[9px] text-slate-400 font-bold">${new Date(fb.at * 1000).toLocaleDateString('fa-IR')}</span>
                        </div>
                        <div class="text-xs text-slate-600 leading-relaxed font-semibold whitespace-pre-wrap">${fb.text}</div>
                    </div>
                `;
                feedbackContainer.appendChild(item);
            });
        }
        
        document.getElementById('pd-submit-comment').onclick = () => { portalCommentManual(p.id, 'modal'); };
        document.getElementById('product-detail-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closePortalDetail() {
        document.getElementById('product-detail-modal').style.display = 'none';
        document.body.style.overflow = '';
    }

    async function portalCommentManual(pid, type = 'card') {
        const inputId = type === 'modal' ? 'pd-comment-input' : 'comment-' + pid;
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;
        const text = inputEl.value;
        
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('product_id', pid);
        fd.append('comment', text);
        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            showLoader(false);
            toast(data.msg);
            if(data.status === 'success') {
                inputEl.value = '';
                sessionStorage.setItem('reopen_product_id', pid);
                const url = new URL(window.location.href);
                if (url.searchParams.get('tab') === 'overview') {
                    url.searchParams.set('tab', 'pending');
                    url.searchParams.set('p', '1');
                    window.location.href = url.toString();
                } else {
                    location.reload();
                }
            }
        } catch(e) {
            showLoader(false);
            toast('خطای شبکه');
        }
    }
    // showLoader and toast are already defined globally
    function toast(m) {
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerText = m;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2500);
    }

    function openSizingChartModal(imgUrl) {
        let modal = document.getElementById('lightboxModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'lightboxModal';
            modal.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 backdrop-blur-md transition-all duration-300';
            modal.onclick = function(e) { this.style.display = 'none'; };
            modal.innerHTML = `
                <div class="relative max-w-[90%] max-h-[90%] flex flex-col items-center">
                    <button class="absolute -top-12 right-0 text-white hover:text-rose-450 text-2xl font-bold bg-white/10 w-10 h-10 rounded-full flex items-center justify-center transition-all">✕</button>
                    <img id="lightboxImg" src="" class="max-w-full max-h-[80vh] object-contain rounded-2xl shadow-2xl border-4 border-white">
                    <p class="text-white text-xs font-black mt-4 uppercase tracking-wider">جدول راهنمای سایز کالا</p>
                </div>
            `;
            document.body.appendChild(modal);
        }
        document.getElementById('lightboxImg').src = imgUrl;
        modal.style.display = 'flex';
    }

    window.onload = () => {
        // Safe onload handler: check if we should re-open a details modal because of a reload
        const reopenId = sessionStorage.getItem('reopen_product_id');
        if (reopenId) {
            sessionStorage.removeItem('reopen_product_id');
            openDetailById(reopenId);
        }
    };

    async function advancedAction(action, pid) {
        let commentEl = document.getElementById('comment-' + pid);
        let comment = commentEl ? commentEl.value : '';
        if (!comment || !comment.trim()) {
            let pdInput = document.getElementById('pd-comment-input');
            if (pdInput) comment = pdInput.value;
        }

        if (action === 'reject' || action === 'reject_stage1' || action === 'reject_stage2') {
            if (!comment || !comment.trim()) {
                alert('جهت ثبت رد، لطفا ابتدا علت رد را در کادر نظرات پایین محصول وارد نمایید و سپس دکمه رد مربوطه را بزنید.');
                return;
            }
        }
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'quick_action');
        fd.append('sub', action);
        fd.append('product_id', pid);
        fd.append('comment', comment);
        
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            showLoader(false);
            if(data.status === 'success') {
                sessionStorage.setItem('reopen_product_id', pid);
                const url = new URL(window.location.href);
                if (url.searchParams.get('tab') === 'overview') {
                    url.searchParams.set('tab', 'pending');
                    url.searchParams.set('p', '1');
                    window.location.href = url.toString();
                    return;
                }
                const el = document.querySelector(`.product-card[data-id="${pid}"]`);
                if(el) {
                    el.style.transform = 'scale(0.8)';
                    el.style.opacity = '0';
                    setTimeout(() => { location.reload(); }, 300);
                } else {
                    location.reload();
                }
            } else {
                toast(data.msg || 'خطایی رخ داد');
            }
        } catch (e) {
            showLoader(false);
            toast('خطای شبکه');
        }
    }

    function openCommentsPopup(pid) {
        const p = window.portalProducts ? window.portalProducts[pid] : null;
        if (!p) {
            console.error('Product not found for comments:', pid);
            return;
        }
        
        document.getElementById('ch-subtitle').innerText = 'کد محصول: ' + (p.sku || p.id || '---') + ' | ' + (p.name || '');
        const timeline = document.getElementById('ch-timeline');
        timeline.innerHTML = '';
        
        // Gather all events
        const events = [];
        
        // 1. Regular/Automatic Comments from userFeedback list
        const feedbacks = p.userFeedback || [];
        feedbacks.forEach(fb => {
            events.push({
                type: 'comment',
                user: fb.user || fb.visitor || 'کاربر',
                icon: fb.icon || null,
                text: fb.text,
                at: fb.at || (fb.date ? (new Date(fb.date).getTime() / 1000) : Math.floor(Date.now() / 1000)),
                category: fb.category || 'default'
            });
        });
        
        // 2. Rejection Events from rejections_history list
        const rejections = p.rejections_history || [];
        rejections.forEach(rej => {
            // Check if this event already exists in userFeedback as automatic text to avoid exact duplicates
            const isDuplicate = feedbacks.some(fb => fb.text && fb.text.includes(rej.reason) && (fb.user && fb.user.includes(rej.user) || fb.visitor && fb.visitor.includes(rej.user)));
            if (!isDuplicate) {
                events.push({
                    type: 'rejection',
                    user: rej.user || 'ناظر بخش',
                    icon: null,
                    text: rej.reason || 'توضیحی ثبت نشده است.',
                    at: rej.at || Math.floor(Date.now() / 1000),
                    side: rej.side || 'عمومی'
                });
            }
        });
        
        // Sort events by time decending (newest first)
        events.sort((a, b) => b.at - a.at);
        
        if (events.length === 0) {
            timeline.innerHTML = `
                <div class="text-center py-16 text-slate-300 italic text-xs font-bold bg-slate-50 border border-dashed border-slate-200 rounded-[1.5rem]">
                    <span class="text-3xl block mb-2">💬</span>
                    هنوز هیچ نظر یا سابقه تصمیمی برای این محصول ثبت نشده است.
                </div>`;
        } else {
            events.forEach(ev => {
                const item = document.createElement('div');
                
                let badge = '<span class="text-[9px] bg-sky-50 text-sky-600 px-2 py-0.5 rounded-full font-black border border-sky-100">💬 کـامـنـت</span>';
                let bgClass = 'bg-slate-50/70 border-slate-100';
                
                if (ev.type === 'rejection') {
                    badge = `<span class="text-[9px] bg-rose-50 text-rose-600 px-2 py-0.5 rounded-full font-black border border-rose-100">🚨 رد مـحـصـول (${ev.side})</span>`;
                    bgClass = 'bg-rose-50/40 border-rose-100/60';
                } else if (ev.category === 'negative' || (ev.text && ev.text.includes('رد محتوا'))) {
                    badge = '<span class="text-[9px] bg-rose-50 text-rose-600 px-2 py-0.5 rounded-full font-black border border-rose-100">🚨 رد مـحـصـول</span>';
                    bgClass = 'bg-rose-50/40 border-rose-100/60';
                } else if (ev.category === 'positive') {
                    badge = '<span class="text-[9px] bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded-full font-black border border-emerald-100">✅ تـایـیـد</span>';
                    bgClass = 'bg-emerald-50/30 border-emerald-100/40';
                }
                
                const avatar = ev.icon 
                    ? `<img src="${getFullUrl(ev.icon)}" class="w-8 h-8 rounded-full object-cover shadow-sm flex-shrink-0 bg-slate-100">` 
                    : `<div class="w-8 h-8 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-[10px] font-black border border-brand-100 flex-shrink-0 shadow-sm">${ev.user.charAt(0)}</div>`;
                
                const formattedDate = new Date(ev.at * 1000).toLocaleDateString('fa-IR') + ' ' + new Date(ev.at * 1000).toLocaleTimeString('fa-IR', {hour: '2-digit', minute:'2-digit'});
                
                item.className = `flex gap-3 items-start p-3 border rounded-2xl ${bgClass} transition-all duration-300 transform animate-fade-in hover:scale-[1.01]`;
                item.innerHTML = `
                    ${avatar}
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-1 flex-wrap gap-1">
                            <div class="flex items-center gap-1.5 font-extrabold text-[11px] text-slate-700">
                                <span>${ev.user}</span>
                                ${badge}
                            </div>
                            <span class="text-[9px] text-slate-400 font-bold" dir="ltr">${formattedDate}</span>
                        </div>
                        <div class="text-xs text-slate-600 leading-relaxed font-semibold whitespace-pre-wrap">${ev.text}</div>
                    </div>
                `;
                timeline.appendChild(item);
            });
        }
        
        document.getElementById('comments-history-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function closeCommentsPopup() {
        document.getElementById('comments-history-modal').style.display = 'none';
        const pModal = document.getElementById('product-detail-modal');
        if (!pModal || pModal.style.display !== 'flex') {
            document.body.style.overflow = '';
        }
    }

    async function portalComment(pid) {
        const inputEl = document.getElementById('comment-' + pid);
        if (!inputEl) return;
        const comment = inputEl.value;
        // Comment is optional
        
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('product_id', pid);
        fd.append('comment', comment);
        
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            showLoader(false);
            toast(data.msg);
            if(data.status === 'success') {
                inputEl.value = '';
            }
        } catch(e) {
            showLoader(false);
            toast('خطای شبکه');
        }
    }

    async function vote(type, pid) {
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'vote');
        fd.append('type', type);
        fd.append('product_id', pid);
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            showLoader(false);
            if(data.status === 'success') {
                // Update in memory cache
                const visitorId = '<?php echo $_SESSION['user']['username'] ?? $_SESSION['visitor_id'] ?? ''; ?>';
                if (window.portalProducts && window.portalProducts[pid]) {
                    window.portalProducts[pid].likes = data.likes;
                    window.portalProducts[pid].dislikes = data.dislikes;
                    if (!window.portalProducts[pid].voterVotes) {
                        window.portalProducts[pid].voterVotes = {};
                    }
                    window.portalProducts[pid].voterVotes[visitorId] = data.user_vote;
                }

                // Update counts on cards
                const likesEl = document.getElementById('likes-' + pid);
                const dislikesEl = document.getElementById('dislikes-' + pid);
                if (likesEl) likesEl.innerText = data.likes;
                if (dislikesEl) dislikesEl.innerText = data.dislikes;
                
                // Update button active & inactive classes on cards
                const likeBtn = document.getElementById('like-btn-' + pid);
                const dislikeBtn = document.getElementById('dislike-btn-' + pid);
                
                const activeLikeCardCls = "flex-1 py-2.5 rounded-xl bg-emerald-500 border-emerald-500 text-white flex items-center justify-center text-xs font-black hover:bg-emerald-600 transition-all gap-1.5 shadow-sm";
                const inactiveLikeCardCls = "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all gap-1.5";
                
                const activeDislikeCardCls = "flex-1 py-2.5 rounded-xl bg-rose-500 border-rose-500 text-white flex items-center justify-center text-xs font-black hover:bg-rose-600 transition-all gap-1.5 shadow-sm";
                const inactiveDislikeCardCls = "flex-1 py-2.5 rounded-xl bg-slate-50 border border-slate-100/50 text-slate-600 flex items-center justify-center text-xs font-black hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition-all gap-1.5";
                
                if (likeBtn) {
                    likeBtn.className = data.user_vote === 'like' ? activeLikeCardCls : inactiveLikeCardCls;
                }
                if (dislikeBtn) {
                    dislikeBtn.className = data.user_vote === 'dislike' ? activeDislikeCardCls : inactiveDislikeCardCls;
                }
                
                // Update counts & classes inside the Product Details modal if open for this product
                const pdModal = document.getElementById('product-detail-modal');
                if (pdModal && pdModal.style.display === 'flex') {
                    const pdLikesCount = document.getElementById('pd-likes-count');
                    const pdDislikesCount = document.getElementById('pd-dislikes-count');
                    const pdLikeBtn = document.getElementById('pd-like-btn');
                    const pdDislikeBtn = document.getElementById('pd-dislike-btn');
                    
                    if (pdLikesCount) pdLikesCount.innerText = data.likes;
                    if (pdDislikesCount) pdDislikesCount.innerText = data.dislikes;
                    
                    const activeLikeModalCls = "flex items-center justify-center gap-2 py-3.5 bg-emerald-500 border-emerald-500 text-white rounded-2xl font-black text-sm cursor-pointer transition-all shadow-md shadow-emerald-100 flex-1";
                    const inactiveLikeModalCls = "flex items-center justify-center gap-2 py-3.5 bg-emerald-50 border-emerald-100 text-emerald-600 rounded-2xl font-black text-sm cursor-pointer transition-all hover:bg-emerald-100 flex-1";
                    
                    const activeDislikeModalCls = "flex items-center justify-center gap-2 py-3.5 bg-rose-500 border-rose-500 text-white rounded-2xl font-black text-sm cursor-pointer transition-all shadow-md shadow-rose-100 flex-1";
                    const inactiveDislikeModalCls = "flex items-center justify-center gap-2 py-3.5 bg-rose-50 border-rose-100 text-rose-600 rounded-2xl font-black text-sm cursor-pointer transition-all hover:bg-rose-100 flex-1";
                    
                    if (pdLikeBtn) {
                        pdLikeBtn.className = data.user_vote === 'like' ? activeLikeModalCls : inactiveLikeModalCls;
                    }
                    if (pdDislikeBtn) {
                        pdDislikeBtn.className = data.user_vote === 'dislike' ? activeDislikeModalCls : inactiveDislikeCls;
                    }
                }
                
                toast(data.msg);
                return true;
            } else {
                toast(data.msg);
                return false;
            }
        } catch(e) {
            showLoader(false);
            toast('خطای ارتباط با سرور');
            return false;
        }
    }

    function previewIcon(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const prev = document.getElementById('icon-preview');
                if (prev) prev.src = e.target.result;
                else {
                    const img = document.createElement('img');
                    img.id = 'icon-preview';
                    img.src = e.target.result;
                    img.className = 'w-full h-full object-cover';
                    document.getElementById('icon-placeholder').replaceWith(img);
                }
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    async function updateProfile(e) {
        e.preventDefault();
        showLoader(true);
        const fd = new FormData(e.target);
        fd.append('action', 'update_profile');
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            showLoader(false);
            toast(data.msg);
            if(data.status === 'success') {
                setTimeout(() => location.reload(), 800);
            }
        } catch(e) {
            showLoader(false);
            toast('خطا در بروزرسانی پروفایل');
        }
    }


    function showSchedComments(sid) {
        const text = prompt('نظر خود را برای این آیتم تقویم بنویسید:');
        if (text && text.trim()) {
            submitSchedComment(sid, text);
        }
    }

    async function submitSchedComment(sid, text) {
        showLoader(true);
        const fd = new FormData();
        fd.append('action', 'comment_schedule');
        fd.append('schedule_id', sid);
        fd.append('text', text);
        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            showLoader(false);
            toast(data.msg);
            if(data.status === 'success') {
                location.reload();
            }
        } catch(e) { showLoader(false); }
    }

    function toggleSelectAllNoImages(master) {
        document.querySelectorAll('.no-img-item-checkbox:not(:disabled)').forEach(box => {
            box.checked = master.checked;
        });
        updateSelectedNoImagesCount();
    }

    function updateSelectedNoImagesCount() {
        const checked = document.querySelectorAll('.no-img-item-checkbox:checked:not(:disabled)');
        const countSpan = document.getElementById('selected-no-img-count');
        if (countSpan) {
            countSpan.innerText = checked.length + ' کالای انتخاب شده';
        }
    }

    function submitBulkContentGenRequest() {
        const checkedBoxes = document.querySelectorAll('.no-img-item-checkbox:checked:not(:disabled)');
        if (checkedBoxes.length === 0) {
            alert('لطفاً ابتدا حداقل یک محصول بدون تصویر را انتخاب نمایید.');
            return;
        }

        if (confirm('آیا از ارسال درخواست تولید محتوا برای ' + checkedBoxes.length + ' کالای انتخاب شده اطمینان دارید؟')) {
            const productIds = Array.from(checkedBoxes).map(box => box.getAttribute('data-pid'));
            
            const fd = new FormData();
            fd.append('action', 'bulk_request_content_gen');
            productIds.forEach(id => {
                fd.append('product_ids[]', id);
            });

            if (typeof showLoader === 'function') showLoader(true);

            fetch('portal.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (typeof showLoader === 'function') showLoader(false);
                if (res.status === 'success') {
                    alert(res.msg);
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'no-image');
                    url.searchParams.set('p', '1');
                    loadUrlAjax(url.toString(), true);
                } else {
                    alert(res.msg || 'خطایی رخ داده است.');
                }
            })
            .catch(err => {
                if (typeof showLoader === 'function') showLoader(false);
                alert('خطای سیستمی در ارتباط با سرور: ' + err.message);
            });
        }
    }

    function toggleSelectAllLibrary(master) {
        const boxes = document.querySelectorAll('input[name="selected_library_products[]"]');
        boxes.forEach(box => {
            box.checked = master.checked;
        });
        updateSelectedLibraryCount();
    }

    function updateSelectedLibraryCount() {
        const checked = document.querySelectorAll('input[name="selected_library_products[]"]:checked');
        const selectAll = document.getElementById('library-select-all');
        const boxes = document.querySelectorAll('input[name="selected_library_products[]"]');
        
        if (selectAll && boxes.length > 0) {
            selectAll.checked = (checked.length === boxes.length);
        }

        const countSpan = document.getElementById('selected-library-count');
        if (countSpan) {
            countSpan.innerText = checked.length + ' کالا';
        }
    }

    function submitBulkPublishPriorityRequest() {
        const checkedBoxes = document.querySelectorAll('input[name="selected_library_products[]"]:checked');
        if (checkedBoxes.length === 0) {
            alert('لطفاً ابتدا حداقل یک محصول تایید شده را انتخاب نمایید.');
            return;
        }

        if (confirm('آیا از قرار دادن ' + checkedBoxes.length + ' کالای انتخاب شده در اولویت انتشار تقویم محتوایی اطمینان دارید؟')) {
            const productIds = Array.from(checkedBoxes).map(box => box.value);
            
            const fd = new FormData();
            fd.append('action', 'bulk_request_publish_priority');
            productIds.forEach(id => {
                fd.append('product_ids[]', id);
            });

            if (typeof showLoader === 'function') showLoader(true);

            fetch('portal.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (typeof showLoader === 'function') showLoader(false);
                if (res.status === 'success') {
                    alert(res.msg);
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'library');
                    url.searchParams.set('p', '1');
                    loadUrlAjax(url.toString(), true);
                } else {
                    alert(res.msg || 'خطایی رخ داده است.');
                }
            })
            .catch(err => {
                if (typeof showLoader === 'function') showLoader(false);
                alert('خطای سیستمی در ارتباط با سرور: ' + err.message);
            });
        }
    }

    function resetPublishPriority() {
        if (confirm('آیا از ریست کردن کامل اولویت‌های انتشار برای تمامی کالاهای تایید شده اطمینان دارید؟')) {
            const fd = new FormData();
            fd.append('action', 'reset_publish_priority');

            if (typeof showLoader === 'function') showLoader(true);

            fetch('portal.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (typeof showLoader === 'function') showLoader(false);
                if (res.status === 'success') {
                    alert(res.msg);
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'library');
                    url.searchParams.set('p', '1');
                    loadUrlAjax(url.toString(), true);
                } else {
                    alert(res.msg || 'خطایی رخ داده است.');
                }
            })
            .catch(err => {
                if (typeof showLoader === 'function') showLoader(false);
                alert('خطای سیستمی در ارتباط با سرور: ' + err.message);
            });
        }
    }

    function resetContentGenPriority() {
        if (confirm('آیا از ریست کردن کامل اولویت‌های تولید محتوا برای تمامی کالاهای بدون تصویر اطمینان دارید؟ تمامی کالاها مجدداً به حالت عادی بازگردانده خواهند شد.')) {
            const fd = new FormData();
            fd.append('action', 'reset_content_gen_priority');

            if (typeof showLoader === 'function') showLoader(true);

            fetch('portal.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (typeof showLoader === 'function') showLoader(false);
                if (res.status === 'success') {
                    alert(res.msg);
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'no-image');
                    url.searchParams.set('p', '1');
                    loadUrlAjax(url.toString(), true);
                } else {
                    alert(res.msg || 'خطایی رخ داده است.');
                }
            })
            .catch(err => {
                if (typeof showLoader === 'function') showLoader(false);
                alert('خطای سیستمی در ارتباط با سرور: ' + err.message);
            });
        }
    }

    // === COUPLES / SETS CREATION MODULE ===
    let currentSelectedProductsForCouple = [];

    function openCoupleSetModal() {
        const checked = document.querySelectorAll('input[name="selected_library_products[]"]:checked');
        if (checked.length !== 2) {
            alert('لطفاً دقیقاً ۲ کالا را از لیست تایید شده‌ها انتخاب کنید تا بتوانید آن‌ها را به صورت یک ست / کاپل ادغام نمایید. 👥 (در حال حاضر ' + checked.length + ' کالا انتخاب شده است)');
            return;
        }

        const ids = Array.from(checked).map(c => c.value);
        const p1 = window.portalProducts ? window.portalProducts[ids[0]] : null;
        const p2 = window.portalProducts ? window.portalProducts[ids[1]] : null;

        if (!p1 || !p2) {
            alert('متاسفانه اطلاعات یکی از کالاهای انتخاب شده در کلاینت یافت نشد. لطفاً صفحه را مجدداً بارگزاری کنید.');
            return;
        }

        currentSelectedProductsForCouple = [p1, p2];

        // 1. Calculate Combined Price
        const parsePrice = (pStr) => {
            if (!pStr) return 0;
            const cleanStr = pStr.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                                 .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
            const num = cleanStr.replace(/[^0-9]/g, '');
            return num ? parseInt(num, 10) : 0;
        };

        const price1 = parsePrice(p1.price || p1.original_price);
        const price2 = parsePrice(p2.price || p2.original_price);
        const sumPrice = price1 + price2;

        const formatPriceFarsi = (num) => {
            if (!num) return 'نامشخص';
            const farsiDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            return String(num).replace(/\B(?=(\d{3})+(\d))/g, ",")
                             .replace(/[0-9]/g, d => farsiDigits[d]) + ' تومان';
        };

        document.getElementById('cs-price-input').value = formatPriceFarsi(sumPrice);

        // 2. Set Default Title with current prefix ("ست کاپلی")
        const selectPrefix = document.getElementById('cs-prefix-select');
        selectPrefix.selectedIndex = 0; // Default to 'ست کاپلی'
        updateCSNamePrefix('ست کاپلی');

        // 3. Merge Sizes
        const getSizesArray = (sizeStr) => {
            if (!sizeStr) return [];
            return sizeStr.split(/[،,:\s]+/)
                          .map(s => s.trim())
                          .filter(s => s.length > 0 && s !== '-' && s !== 'نامشخص');
        };

        const sizes1 = getSizesArray(p1.sizes);
        const sizes2 = getSizesArray(p2.sizes);
        const mergedSizes = [...new Set([...sizes1, ...sizes2])];
        document.getElementById('cs-sizes-input').value = mergedSizes.join('، ');

        // 4. Generate Separated Description
        const desc1 = p1.description ? p1.description.trim() : 'توضیحات ثبت نشده است.';
        const desc2 = p2.description ? p2.description.trim() : 'توضیحات ثبت نشده است.';

        const fullDesc = `💎 ست جدید ویژه (ترکیب دو محصول تایید شده)
------------------------------------
👕 بخش اول: ${p1.name} (کد: ${p1.sku || p1.id})
توضیحات محصول اول:
${desc1}

------------------------------------
👖 بخش دوم: ${p2.name} (کد: ${p2.sku || p2.id})
توضیحات محصول دوم:
${desc2}
`;
        document.getElementById('cs-desc-input').value = fullDesc;

        document.getElementById('couple-set-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeCoupleSetModal() {
        document.getElementById('couple-set-modal').style.display = 'none';
        document.body.style.overflow = '';
    }

    function updateCSNamePrefix(prefix) {
        if (currentSelectedProductsForCouple.length !== 2) return;
        const p1 = currentSelectedProductsForCouple[0];
        const p2 = currentSelectedProductsForCouple[1];
        
        let p1Clean = (p1.name || '').replace(/^(ست کاپلی|ست لباس و شلوار|ست دوتیکه شیک|ست هودی و اسلش|ست راحتی خانگی):/i, '').trim();
        let p2Clean = (p2.name || '').replace(/^(ست کاپلی|ست لباس و شلوار|ست دوتیکه شیک|ست هودی و اسلش|ست راحتی خانگی):/i, '').trim();

        if (prefix) {
            document.getElementById('cs-name-input').value = prefix + ': ' + p1Clean + ' + ' + p2Clean;
        } else {
            document.getElementById('cs-name-input').value = p1Clean + ' + ' + p2Clean;
        }
    }

    function submitCoupleSetCreation() {
        if (currentSelectedProductsForCouple.length !== 2) {
            alert('خطا: کالاها انتخاب نشده‌اند.');
            return;
        }

        const p1 = currentSelectedProductsForCouple[0];
        const p2 = currentSelectedProductsForCouple[1];

        const setName = document.getElementById('cs-name-input').value.trim();
        const setPrice = document.getElementById('cs-price-input').value.trim();
        const setSizes = document.getElementById('cs-sizes-input').value.trim();
        const setDesc = document.getElementById('cs-desc-input').value.trim();

        if (!setName) {
            alert('لطفاً نام محصول ست را وارد کنید.');
            return;
        }

        const btn = document.getElementById('cs-submit-btn');
        btn.disabled = true;
        btn.innerHTML = '<span>⏳ در حال ثبت...</span>';

        const fd = new FormData();
        fd.append('action', 'create_couple_set');
        fd.append('p1_id', p1.id);
        fd.append('p2_id', p2.id);
        fd.append('set_name', setName);
        fd.append('set_price', setPrice);
        fd.append('set_sizes', setSizes);
        fd.append('set_description', setDesc);

        if (typeof showLoader === 'function') showLoader(true);

        fetch('portal.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd
        })
        .then(res => res.json())
        .then(res => {
            if (typeof showLoader === 'function') showLoader(false);
            btn.disabled = false;
            btn.innerHTML = '<span>🚀 ثبت نهایی و ساخت محصول ست</span>';

            if (res.status === 'success') {
                alert(res.msg);
                closeCoupleSetModal();
                
                const url = new URL(window.location);
                url.searchParams.set('tab', 'no-image');
                url.searchParams.set('p', '1');
                loadUrlAjax(url.toString(), true);
            } else {
                alert(res.msg || 'خطایی رخ داده است.');
            }
        })
        .catch(err => {
            if (typeof showLoader === 'function') showLoader(false);
            btn.disabled = false;
            btn.innerHTML = '<span>🚀 ثبت نهایی و ساخت محصول ست</span>';
            alert('خطای سیستمی: ' + err.message);
        });
    }

    // Browser Relay Background Engine for Scheduling (زمان‌بندی)
    let isRelayEngineRunning = false;
    let relayedCount = 0;

    function initBrowserRelayScheduler() {
        // ALWAYS initialize browser relay helper to utilize the active VPN and dispatch scheduled posts!
        
        // Show lightweight status dot in portal page
        const dot = document.createElement('div');
        dot.id = 'relay-status-indicator';
        dot.className = 'fixed bottom-24 right-6 bg-slate-900/90 text-white text-[10px] px-3 py-1.5 rounded-full shadow-lg z-55 flex items-center gap-1.5 pointer-events-none transition-all duration-300 backdrop-blur-md border border-white/10';
        dot.innerHTML = `<span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span> پل مرورگر فعال`;
        document.body.appendChild(dot);
        
        // Polling interval - check every 15 seconds
        setInterval(checkAndProcessRelaySchedules, 15000);
        // Run immediately on load too
        setTimeout(checkAndProcessRelaySchedules, 2000);
    }

    async function checkAndProcessRelaySchedules() {
        if (isRelayEngineRunning) return;
        if (document.hidden) return;
        isRelayEngineRunning = true;
        
        const dot = document.getElementById('relay-status-indicator');
        
        try {
            const fd = new FormData();
            fd.append('action', 'get_pending_browser_relay_posts');
            
            const targetUrl = window.location.pathname.split('/').pop() || 'portal.php';
            const req = await fetch(targetUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            if (!req.ok) return;
            const res = await req.json();
            
            if (res.status === 'success' && res.posts && res.posts.length > 0) {
                if (dot) {
                    dot.innerHTML = `<span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span></span> درحال ارسال ${res.posts.length} پست زمان‌بندی...`;
                    dot.classList.add('bg-brand-900');
                }
                
                for (const post of res.posts) {
                    try {
                        await executeBrowserRelaySend(post.platform, post.payload);
                        
                        // Mark as completed!
                        const fdMark = new FormData();
                        fdMark.append('action', 'mark_relay_schedule_status');
                        fdMark.append('schedule_id', post.id);
                        fdMark.append('status', 'completed');
                        
                        await fetch(targetUrl, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: fdMark
                        });
                        
                        relayedCount++;
                        
                        if (typeof toast === 'function') {
                            toast('ارسال زمان‌بندی شد', `پست با موفقیت منتشر شد. (${post.platform.toUpperCase()})`, 'success');
                        }
                    } catch (e) {
                        console.warn(`[Relay Engine] Notice on post ID: ${post.id}:`, e);
                        
                        // Mark as failed
                        const fdMark = new FormData();
                        fdMark.append('action', 'mark_relay_schedule_status');
                        fdMark.append('schedule_id', post.id);
                        fdMark.append('status', 'failed');
                        fdMark.append('error_message', e.message || 'Unknown network error');
                        
                        await fetch(targetUrl, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: fdMark
                        });
                    }
                }
            }
        } catch(err) {
            // Quiet network fallback
        } finally {
            isRelayEngineRunning = false;
            if (dot) {
                dot.innerHTML = `<span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span> پل مرورگر فعال ${relayedCount > 0 ? `(ارسال شده: ${relayedCount})` : ''}`;
                dot.classList.remove('bg-brand-900');
            }
        }
    }

    async function executeBrowserRelaySend(platform, payload) {
        if (platform === 'telegram') {
            let blob = null;
            if (payload.image) {
                try {
                    blob = await fetch(payload.image).then(r => {
                        if (!r.ok) throw new Error("Image load failed");
                        return r.blob();
                    });
                } catch(e) {
                    console.warn("[Relay Engine] Could not fetch local image blob, fallback to remote URL passing", e);
                }
            }
            
            const buildFormData = (isMsg = false) => {
                const fd = new FormData();
                fd.append('chat_id', payload.chatId);
                if (payload.schedule_date) {
                    fd.append('schedule_date', payload.schedule_date);
                }
                if (isMsg) {
                    fd.append('text', payload.caption);
                    fd.append('parse_mode', 'Markdown');
                } else {
                    if (blob) {
                        fd.append('photo', blob, 'image.jpg');
                    } else if (payload.image) {
                        fd.append('photo', payload.image);
                    }
                    fd.append('caption', payload.caption);
                    fd.append('parse_mode', 'Markdown');
                }
                return fd;
            };

            const tryFetch = async (apiMethod, isMsg = false) => {
                let primaryUrl = payload.customApiUrl ? payload.customApiUrl.replace(/\/$/, '') + '/bot' + payload.token + '/' : 'https://api.telegram.org/bot' + payload.token + '/';
                try {
                    const response = await fetch(primaryUrl + apiMethod, { method: 'POST', body: buildFormData(isMsg) });
                    const json = await response.json();
                    if (json.ok) return json;
                    throw new Error(json.description || 'Telegram API response error');
                } catch(err) {
                    console.warn(`[Relay Engine] Primary Telegram API call to ${apiMethod} failed/CORS error. Falling back to api.telegram.org`, err);
                    if (payload.customApiUrl) {
                        let fallbackUrl = 'https://api.telegram.org/bot' + payload.token + '/';
                        const response = await fetch(fallbackUrl + apiMethod, { method: 'POST', body: buildFormData(isMsg) });
                        const json = await response.json();
                        if (json.ok) return json;
                        throw new Error(json.description || 'Telegram official API response error');
                    } else {
                        throw err;
                    }
                }
            };

            if (blob) {
                await tryFetch('sendPhoto', false);
            } else {
                if (payload.image) {
                    try {
                        await tryFetch('sendPhoto', false);
                        return true;
                    } catch(e) {
                        console.warn("[Relay Engine] sendPhoto with remote image URL failed, falling back to sendMessage", e);
                    }
                }
                await tryFetch('sendMessage', true);
            }
            return true;
        } else if (platform === 'instagram') {
            const apiHost = payload.customApiUrl ? payload.customApiUrl.replace(/\/$/, '') : 'https://graph.facebook.com';
            
            // Step 1: Create media container
            const step1Url = `${apiHost}/v19.0/${payload.chatId}/media`;
            const fd1 = new FormData();
            fd1.append('image_url', payload.image || 'https://placehold.co/600x600?text=Maarksho+Flow+Instagram+Test');
            fd1.append('caption', payload.caption);
            fd1.append('access_token', payload.token);
            if (payload.schedule_date) {
                fd1.append('scheduled_publish_time', payload.schedule_date);
            }
            
            const r1 = await fetch(step1Url, { method: 'POST', body: fd1 });
            const res1 = await r1.json();
            if (!res1.id) throw new Error(res1.error?.message || 'Error creating Instagram media container');
            const containerId = res1.id;
            
            // If this is a scheduled post, Instagram schedules it natively from the container
            if (payload.schedule_date) {
                return true;
            }
            
            // Wait 2 seconds for processing
            await new Promise(r => setTimeout(r, 2000));
            
            // Step 2: Publish
            const step2Url = `${apiHost}/v19.0/${payload.chatId}/media_publish`;
            const fd2 = new FormData();
            fd2.append('creation_id', containerId);
            fd2.append('access_token', payload.token);
            
            const r2 = await fetch(step2Url, { method: 'POST', body: fd2 });
            const res2 = await r2.json();
            if (!res2.id) throw new Error(res2.error?.message || 'Error publishing Instagram media container');
            return true;
        } else if (platform === 'rubika') {
            const endpoints = [
                `https://botapi.rubika.ir/v1/bot${payload.token}/sendMessage`,
                `https://botapi.rubika.ir/v2/bot${payload.token}/sendMessage`,
                `https://botapi.rubika.ir/v3/bot${payload.token}/sendMessage`,
                `https://messengerg.urara.me/v1/bot${payload.token}/sendMessage`,
                `https://botapi.rubika.ir/v3/${payload.token}/sendMessage`,
                `https://messengerg.urara.me/v3/${payload.token}/sendMessage`,
                `https://api.rubika.ir/v1/json/`
            ];
            let success = false;
            let lastError = null;
            const textMsg = payload.caption + (payload.image ? `\n\n[عکس](${payload.image})` : '');
            
            for (const url of endpoints) {
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            chat_id: payload.chatId,
                            text: textMsg,
                            parse_mode: 'Markdown'
                        })
                    });
                    const data = await response.json();
                    if (data && (data.ok === true || data.status === 'OK' || data.success === true)) {
                        success = true;
                        break;
                    } else {
                        throw new Error(data.description || data.message || 'Rubika API returns ok == false');
                    }
                } catch(err) {
                    lastError = err;
                    console.warn(`[Relay Engine] Rubika endpoint ${url} failed:`, err);
                }
            }
            if (!success) {
                throw lastError || new Error('All Rubika endpoints failed');
            }
            return true;
        } else if (platform === 'bale') {
            let success = false;
            let lastError = null;
            const tapiBase = `https://tapi.bale.ai/bot${payload.token}/`;
            
            const sendRequest = async (method, bodyParams) => {
                const r = await fetch(tapiBase + method, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(bodyParams)
                });
                const data = await r.json();
                if (data && data.ok === true) {
                    return true;
                }
                throw new Error(data.description || 'Bale API error');
            };

            if (payload.image) {
                try {
                    await sendRequest('sendPhoto', {
                        chat_id: payload.chatId,
                        photo: payload.image,
                        caption: payload.caption,
                        parse_mode: 'Markdown'
                    });
                    success = true;
                } catch(err) {
                    console.warn("[Relay Engine] Bale sendPhoto failed, falling back to sendMessage", err);
                    lastError = err;
                }
            }
            
            if (!success) {
                const textMsg = payload.caption + (payload.image ? `\n\n🖼 عکس محصول:\n${payload.image}` : '');
                await sendRequest('sendMessage', {
                    chat_id: payload.chatId,
                    text: textMsg,
                    parse_mode: 'Markdown'
                });
            }
            return true;
        } else {
            throw new Error("پلتفرم پشتیبانی نمی‌شود");
        }
    }

    // ============================================
    // Status & Inventory Analytics Logic
    // ============================================
    window.currentAnalyticsScope = 'approved';
    window.currentThreshold = <?php echo (int)$f_stock_min; ?> || 5;
    window.currentModalFilter = 'stock_agg_gte';

    function getItemsForScope() {
        if (!Array.isArray(window.allCatalogProducts)) return [];
        if (window.currentAnalyticsScope === 'approved') {
            return window.allCatalogProducts.filter(p => (p.status === 'active' || !p.status) && p.is_no_img !== 1 && p.is_out !== 1);
        }
        return window.allCatalogProducts;
    }

    function updateStatusAnalytics(threshold, skipModalList = false) {
        let val = parseInt(threshold, 10);
        if (isNaN(val) || val < 1) val = 1;
        window.currentThreshold = val;

        const input = document.getElementById('status-threshold-input');
        if (input && document.activeElement !== input) input.value = val;
        const modalInput = document.getElementById('modal-status-threshold-input');
        if (modalInput && document.activeElement !== modalInput) modalInput.value = val;

        // Update displays of N
        document.querySelectorAll('.threshold-num-display').forEach(el => el.textContent = val);
        document.querySelectorAll('.threshold-minus-one-display').forEach(el => el.textContent = Math.max(1, val - 1));

        // Update active quick chip
        document.querySelectorAll('.threshold-chip').forEach(chip => {
            const chipVal = parseInt(chip.textContent, 10);
            if (chipVal === val) {
                chip.classList.add('active-chip', 'bg-indigo-500', 'text-white', 'border-indigo-400');
                chip.classList.remove('bg-white/5', 'text-slate-300', 'border-white/5');
            } else {
                chip.classList.remove('active-chip', 'bg-indigo-500', 'text-white', 'border-indigo-400');
                chip.classList.add('bg-white/5', 'text-slate-300', 'border-white/5');
            }
        });

        const items = getItemsForScope();
        const totalCount = items.length;

        // 1. Aggregated Stock >= N
        const aggItems = items.filter(p => (p.agg_stock || 0) >= val);
        const aggCount = aggItems.length;
        const aggSum = aggItems.reduce((acc, p) => acc + (p.agg_stock || 0), 0);

        // 2. Direct Stock >= N
        const directItems = items.filter(p => (p.stock || 0) >= val);
        const directCount = directItems.length;
        const directSum = directItems.reduce((acc, p) => acc + (p.stock || 0), 0);

        // 3. Low stock: 1 <= stock < N
        const lowItems = items.filter(p => (p.stock || 0) >= 1 && (p.stock || 0) < val);
        const lowCount = lowItems.length;
        const lowSum = lowItems.reduce((acc, p) => acc + (p.stock || 0), 0);

        // 4. Out of stock: stock == 0 or is_out == 1
        const outItems = items.filter(p => (p.stock || 0) <= 0 || p.is_out === 1);
        const outCount = outItems.length;

        // Media counts
        const hasImgCount = items.filter(p => p.has_img === 1 && p.is_no_img !== 1).length;
        const noImgCount = items.filter(p => p.is_no_img === 1).length;

        // Update in-page dashboard card metrics
        const elAggCount = document.getElementById('stat-agg-count');
        if (elAggCount) elAggCount.textContent = aggCount.toLocaleString('fa-IR');
        const elAggSum = document.getElementById('stat-agg-sum');
        if (elAggSum) elAggSum.textContent = aggSum.toLocaleString('fa-IR') + ' عدد';

        const elDirectCount = document.getElementById('stat-direct-count');
        if (elDirectCount) elDirectCount.textContent = directCount.toLocaleString('fa-IR');
        const elDirectSum = document.getElementById('stat-direct-sum');
        if (elDirectSum) elDirectSum.textContent = directSum.toLocaleString('fa-IR') + ' عدد';

        const elLowCount = document.getElementById('stat-low-count');
        if (elLowCount) elLowCount.textContent = lowCount.toLocaleString('fa-IR');
        const elLowSum = document.getElementById('stat-low-sum');
        if (elLowSum) elLowSum.textContent = lowSum.toLocaleString('fa-IR') + ' عدد';

        const elOutCount = document.getElementById('stat-out-count');
        if (elOutCount) elOutCount.textContent = outCount.toLocaleString('fa-IR');
        const elMediaRatio = document.getElementById('stat-media-ratio');
        if (elMediaRatio) elMediaRatio.textContent = hasImgCount.toLocaleString('fa-IR') + ' / ' + noImgCount.toLocaleString('fa-IR');

        // Update distribution bar
        const elDistLabel = document.getElementById('distribution-total-label');
        if (elDistLabel) elDistLabel.textContent = `کل بررسی شده: ${totalCount.toLocaleString('fa-IR')} کالا`;

        const pctAgg = totalCount > 0 ? Math.round((aggCount / totalCount) * 100) : 0;
        const pctDirect = totalCount > 0 ? Math.round((directCount / totalCount) * 100) : 0;
        const pctLow = totalCount > 0 ? Math.round((lowCount / totalCount) * 100) : 0;
        const pctOut = totalCount > 0 ? Math.round((outCount / totalCount) * 100) : 0;

        const barAgg = document.getElementById('bar-agg');
        if (barAgg) barAgg.style.width = pctAgg + '%';
        const barDirect = document.getElementById('bar-direct');
        if (barDirect) barDirect.style.width = pctDirect + '%';
        const barLow = document.getElementById('bar-low');
        if (barLow) barLow.style.width = pctLow + '%';
        const barOut = document.getElementById('bar-out');
        if (barOut) barOut.style.width = pctOut + '%';

        const elPctAgg = document.getElementById('pct-agg');
        if (elPctAgg) elPctAgg.textContent = pctAgg.toLocaleString('fa-IR') + '٪';
        const elPctDirect = document.getElementById('pct-direct');
        if (elPctDirect) elPctDirect.textContent = pctDirect.toLocaleString('fa-IR') + '٪';
        const elPctLow = document.getElementById('pct-low');
        if (elPctLow) elPctLow.textContent = pctLow.toLocaleString('fa-IR') + '٪';
        const elPctOut = document.getElementById('pct-out');
        if (elPctOut) elPctOut.textContent = pctOut.toLocaleString('fa-IR') + '٪';

        // Also update modal metrics if modal is present
        updateModalDashboardStats(items, val);

        if (!skipModalList) {
            renderAnalyticsModalProducts(window.currentModalFilter);
        }
    }

    function updateModalDashboardStats(items, val) {
        const modalAggCount = document.getElementById('m-stat-agg-count');
        if (!modalAggCount) return;

        const total = items.length;
        const aggItems = items.filter(p => (p.agg_stock || 0) >= val);
        const directItems = items.filter(p => (p.stock || 0) >= val);
        const lowItems = items.filter(p => (p.stock || 0) >= 1 && (p.stock || 0) < val);
        const outItems = items.filter(p => (p.stock || 0) <= 0 || p.is_out === 1);

        modalAggCount.textContent = aggItems.length.toLocaleString('fa-IR');
        const mAggSum = document.getElementById('m-stat-agg-sum');
        if (mAggSum) mAggSum.textContent = aggItems.reduce((a, b) => a + (b.agg_stock || 0), 0).toLocaleString('fa-IR');

        const mDirectCount = document.getElementById('m-stat-direct-count');
        if (mDirectCount) mDirectCount.textContent = directItems.length.toLocaleString('fa-IR');
        const mDirectSum = document.getElementById('m-stat-direct-sum');
        if (mDirectSum) mDirectSum.textContent = directItems.reduce((a, b) => a + (b.stock || 0), 0).toLocaleString('fa-IR');

        const mLowCount = document.getElementById('m-stat-low-count');
        if (mLowCount) mLowCount.textContent = lowItems.length.toLocaleString('fa-IR');

        const mOutCount = document.getElementById('m-stat-out-count');
        if (mOutCount) mOutCount.textContent = outItems.length.toLocaleString('fa-IR');

        // Extended insights
        const multiImgCount = items.filter(p => (p.gallery_count || 0) > 1).length;
        const noImgInStock = items.filter(p => p.is_no_img === 1 && p.is_out !== 1 && (p.stock || 0) > 0).length;
        const multiVarCount = items.filter(p => (p.variants_count || 0) > 1).length;

        const prices = items.map(p => p.price_num || 0).filter(n => n > 0);
        const avgPrice = prices.length > 0 ? Math.round(prices.reduce((a, b) => a + b, 0) / prices.length) : 0;

        const elMultiImg = document.getElementById('m-insight-multi-img');
        if (elMultiImg) elMultiImg.textContent = multiImgCount.toLocaleString('fa-IR');
        const elNoImgStock = document.getElementById('m-insight-noimg-stock');
        if (elNoImgStock) elNoImgStock.textContent = noImgInStock.toLocaleString('fa-IR');
        const elMultiVar = document.getElementById('m-insight-multivar');
        if (elMultiVar) elMultiVar.textContent = multiVarCount.toLocaleString('fa-IR');
        const elAvgPrice = document.getElementById('m-insight-avg-price');
        if (elAvgPrice) elAvgPrice.textContent = avgPrice > 0 ? avgPrice.toLocaleString('fa-IR') + ' تومان' : '-';
    }

    function stepThreshold(delta) {
        let val = (window.currentThreshold || 5) + delta;
        if (val < 1) val = 1;
        updateStatusAnalytics(val);
    }

    function setThreshold(val) {
        updateStatusAnalytics(val);
    }

    function setAnalyticsScope(scope) {
        window.currentAnalyticsScope = scope;
        const btnApproved = document.getElementById('scope-btn-approved');
        const btnAll = document.getElementById('scope-btn-all');
        const mBtnApproved = document.getElementById('m-scope-btn-approved');
        const mBtnAll = document.getElementById('m-scope-btn-all');

        if (scope === 'approved') {
            if (btnApproved) { btnApproved.className = 'px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm'; }
            if (btnAll) { btnAll.className = 'px-3 py-1.5 rounded-lg font-bold transition-all text-slate-400 hover:text-white'; }
            if (mBtnApproved) { mBtnApproved.className = 'px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm'; }
            if (mBtnAll) { mBtnAll.className = 'px-3 py-1.5 rounded-lg font-bold transition-all text-slate-400 hover:text-white'; }
        } else {
            if (btnApproved) { btnApproved.className = 'px-3 py-1.5 rounded-lg font-bold transition-all text-slate-400 hover:text-white'; }
            if (btnAll) { btnAll.className = 'px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm'; }
            if (mBtnApproved) { mBtnApproved.className = 'px-3 py-1.5 rounded-lg font-bold transition-all text-slate-400 hover:text-white'; }
            if (mBtnAll) { mBtnAll.className = 'px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm'; }
        }
        updateStatusAnalytics(window.currentThreshold);
    }

    function applyStatusFilterToLibrary(filterType) {
        const threshold = window.currentThreshold || 5;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('tab', 'library');
        currentUrl.searchParams.set('f_stock', filterType);
        currentUrl.searchParams.set('f_stock_min', threshold.toString());
        currentUrl.searchParams.set('p', '1');
        window.location.href = currentUrl.toString();
    }

    function openStatusAnalyticsModal(initialFilter = 'stock_agg_gte') {
        const modal = document.getElementById('statusAnalyticsModal');
        if (!modal) return;
        window.currentModalFilter = initialFilter;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        updateStatusAnalytics(window.currentThreshold);
        renderAnalyticsModalProducts(initialFilter);
    }

    function closeStatusAnalyticsModal() {
        const modal = document.getElementById('statusAnalyticsModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function setModalFilterTab(filterType) {
        window.currentModalFilter = filterType;
        document.querySelectorAll('.modal-filter-tab-btn').forEach(btn => {
            if (btn.getAttribute('data-filter') === filterType) {
                btn.className = 'modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-black bg-indigo-600 text-white shadow-sm transition-all';
            } else {
                btn.className = 'modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all';
            }
        });
        renderAnalyticsModalProducts(filterType);
    }

    function renderAnalyticsModalProducts(filterType) {
        const container = document.getElementById('modal-products-list-container');
        const countBadge = document.getElementById('modal-filtered-count-badge');
        if (!container) return;

        const val = window.currentThreshold || 5;
        let items = getItemsForScope();

        // Apply selected filter
        if (filterType === 'stock_agg_gte') {
            items = items.filter(p => (p.agg_stock || 0) >= val);
        } else if (filterType === 'stock_direct_gte') {
            items = items.filter(p => (p.stock || 0) >= val);
        } else if (filterType === 'stock_low') {
            items = items.filter(p => (p.stock || 0) >= 1 && (p.stock || 0) < val);
        } else if (filterType === 'out_of_stock') {
            items = items.filter(p => (p.stock || 0) <= 0 || p.is_out === 1);
        } else if (filterType === 'no_image_in_stock') {
            items = items.filter(p => p.is_no_img === 1 && p.is_out !== 1 && (p.stock || 0) > 0);
        } else if (filterType === 'multi_photo') {
            items = items.filter(p => (p.gallery_count || 0) > 1);
        }

        // Apply modal search query if any
        const searchInput = document.getElementById('modal-filter-search-input');
        if (searchInput && searchInput.value.trim() !== '') {
            const q = searchInput.value.trim().toLowerCase();
            items = items.filter(p => 
                (p.name && p.name.toLowerCase().includes(q)) ||
                (p.sku && p.sku.toLowerCase().includes(q)) ||
                (p.color && p.color.toLowerCase().includes(q))
            );
        }

        if (countBadge) {
            countBadge.textContent = items.length.toLocaleString('fa-IR') + ' کالا';
        }

        if (items.length === 0) {
            container.innerHTML = `
                <div class="col-span-full py-16 text-center text-slate-400 flex flex-col items-center justify-center">
                    <span class="text-4xl mb-3">🔍</span>
                    <span class="text-sm font-bold text-slate-600">کالایی با این شرایط در محدوده انتخابی یافت نشد</span>
                    <span class="text-xs text-slate-400 mt-1">می‌توانید عدد آستانه را تغییر دهید یا محدوده فیلتر را بازتر کنید.</span>
                </div>
            `;
            return;
        }

        let html = '';
        items.slice(0, 100).forEach(p => {
            const imgUrl = p.image || 'https://via.placeholder.com/150?text=No+Image';
            const statusLabel = p.status === 'pending' ? 'در انتظار' : (p.status === 'rejected' ? 'رد شده' : 'تایید شده');
            const statusBg = p.status === 'pending' ? 'bg-amber-100 text-amber-700' : (p.status === 'rejected' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700');

            html += `
                <div class="bg-white border border-slate-200/80 hover:border-indigo-300 rounded-2xl p-3.5 shadow-sm hover:shadow-md transition-all flex items-center justify-between gap-3 group cursor-pointer" onclick="openDetailById('${p.id}')">
                    <div class="flex items-center gap-3 min-w-0">
                        <img src="${imgUrl}" alt="" class="w-14 h-14 object-cover rounded-xl border border-slate-100 bg-slate-50 flex-shrink-0 group-hover:scale-105 transition-transform" onerror="this.src='https://via.placeholder.com/150?text=No+Image'">
                        <div class="min-w-0">
                            <h4 class="font-bold text-xs text-slate-800 line-clamp-1 group-hover:text-indigo-600 transition-colors">${p.name || 'بدون عنوان'}</h4>
                            <div class="flex items-center gap-2 mt-1 text-[11px] text-slate-500">
                                <span class="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-[10px] text-slate-600">${p.sku || '-'}</span>
                                ${p.color ? `<span class="bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded text-[10px] font-bold">${p.color}</span>` : ''}
                                <span class="text-[10px] px-1.5 py-0.5 rounded ${statusBg} font-bold">${statusLabel}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 flex-shrink-0 text-left">
                        <div>
                            <div class="text-[10px] font-bold text-slate-400">موجودی تجمیعی</div>
                            <div class="text-xs font-black font-mono text-amber-600">${(p.agg_stock || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-slate-400">موجودی تک</div>
                            <div class="text-xs font-black font-mono text-emerald-600">${(p.stock || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-slate-400">قیمت</div>
                            <div class="text-xs font-black text-slate-800">${p.price || '-'}</div>
                        </div>
                    </div>
                </div>
            `;
        });

        if (items.length > 100) {
            html += `
                <div class="col-span-full py-4 text-center text-xs font-bold text-indigo-600 bg-indigo-50/50 rounded-2xl border border-indigo-100">
                    نمایش ۱۰۰ کالای اول از میان ${items.length.toLocaleString('fa-IR')} کالا. برای مشاهده تمام کالاها روی «اعمال فیلتر در کتابخانه» کلیک کنید.
                </div>
            `;
        }

        container.innerHTML = html;
    }

    function copyCatalogStatusReport() {
        const val = window.currentThreshold || 5;
        const items = getItemsForScope();
        const total = items.length;

        const aggItems = items.filter(p => (p.agg_stock || 0) >= val);
        const directItems = items.filter(p => (p.stock || 0) >= val);
        const lowItems = items.filter(p => (p.stock || 0) >= 1 && (p.stock || 0) < val);
        const outItems = items.filter(p => (p.stock || 0) <= 0 || p.is_out === 1);
        const noImgStock = items.filter(p => p.is_no_img === 1 && (p.stock || 0) > 0 && p.is_out !== 1);

        const scopeLabel = window.currentAnalyticsScope === 'approved' ? 'کاتالوگ تایید شده' : 'کل محصولات پروژه';
        const dateStr = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'full', timeStyle: 'short' }).format(new Date());

        const report = `📊 گزارش وضعیت و آمار موجودی کاتالوگ (${scopeLabel})
📅 تاریخ گزارش: ${dateStr}
⚙️ آستانه تعیین‌شده موجودی: ${val} عدد

🔹 کل محصولات بررسی‌شده: ${total.toLocaleString('fa-IR')} کالا
🔥 محصولات دارای موجودی تجمیعی بالای ${val} عدد: ${aggItems.length.toLocaleString('fa-IR')} کالا (مجموع تیراژ: ${aggItems.reduce((a,b)=>a+(b.agg_stock||0),0).toLocaleString('fa-IR')} عدد)
📦 محصولات دارای موجودی مستقیم بالای ${val} عدد: ${directItems.length.toLocaleString('fa-IR')} کالا (مجموع موجودی: ${directItems.reduce((a,b)=>a+(b.stock||0),0).toLocaleString('fa-IR')} عدد)
⚠️ محصولات کم‌موجودی (۱ تا ${Math.max(1, val - 1)} عدد): ${lowItems.length.toLocaleString('fa-IR')} کالا (باقی‌مانده انبار: ${lowItems.reduce((a,b)=>a+(b.stock||0),0).toLocaleString('fa-IR')} عدد)
🚫 محصولات ناموجود (۰ عدد): ${outItems.length.toLocaleString('fa-IR')} کالا
📷 محصولات بدون تصویر ولی دارای موجودی: ${noImgStock.length.toLocaleString('fa-IR')} کالا

🔗 سامانه مدیریت محتوا و بازاریابی مارک شو`;

        navigator.clipboard.writeText(report).then(() => {
            alert('گزارش آماری با موفقیت در کلیپ‌بورد کپی شد!');
        }).catch(() => {
            alert('خطا در کپی گزارش در کلیپ‌بورد.');
        });
    }

    function exportCatalogStatusCSV() {
        const val = window.currentThreshold || 5;
        let items = getItemsForScope();
        if (window.currentModalFilter === 'stock_agg_gte') items = items.filter(p => (p.agg_stock || 0) >= val);
        else if (window.currentModalFilter === 'stock_direct_gte') items = items.filter(p => (p.stock || 0) >= val);
        else if (window.currentModalFilter === 'stock_low') items = items.filter(p => (p.stock || 0) >= 1 && (p.stock || 0) < val);
        else if (window.currentModalFilter === 'out_of_stock') items = items.filter(p => (p.stock || 0) <= 0 || p.is_out === 1);

        let csv = '\uFEFFکد کالا,نام محصول,دسته/رنگ,موجودی مستقیم,موجودی تجمیعی,قیمت,وضعیت\n';
        items.forEach(p => {
            const cleanName = (p.name || '').replace(/,/g, ' ');
            const cleanColor = (p.color || '').replace(/,/g, ' ');
            csv += `"${p.sku || ''}","${cleanName}","${cleanColor}",${p.stock || 0},${p.agg_stock || 0},"${p.price || ''}","${p.status || ''}"\n`;
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `inventory_status_report_${Date.now()}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }

    // Initialize analytics on page load
    document.addEventListener('DOMContentLoaded', () => {
        updateStatusAnalytics(window.currentThreshold, true);
    });

    async function castOccasionVote(occKey, stage, approve) {
        if (!confirm('آیا از ویرایش این تاییدیه ممیزی اطمینان دارید؟')) {
            return;
        }
        
        try {
            const fd = new FormData();
            fd.append('action', 'cast_occasion_vote');
            fd.append('occ_key', occKey);
            fd.append('stage', stage);
            fd.append('approve', approve);
            
            const req = await fetch('portal.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const res = await req.json();
            if (res.status === 'success') {
                alert(res.msg);
                window.location.reload();
            } else {
                alert('خطا: ' + res.msg);
            }
        } catch (e) {
            console.error(e);
            alert('متاسفانه در ارسال تاییدیه مشکلی بوجود آمد.');
        }
    }

    // Auto-start on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBrowserRelayScheduler);
    } else {
        initBrowserRelayScheduler();
    }
</script>

<!-- Comprehensive Status & Inventory Intelligence Modal -->
<div id="statusAnalyticsModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 hidden items-center justify-center p-2 sm:p-6 overflow-y-auto">
    <div class="bg-slate-50 rounded-2xl sm:rounded-[2.5rem] shadow-2xl border border-slate-200/80 max-w-5xl w-full overflow-hidden flex flex-col max-h-[94vh] sm:max-h-[92vh] animate-scale-up" onclick="event.stopPropagation()">
        
        <!-- Modal Top Bar -->
        <div class="p-4 sm:p-6 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white flex flex-wrap items-center justify-between gap-3 sm:gap-4 border-b border-indigo-500/20 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl bg-indigo-500/20 border border-indigo-400/30 flex items-center justify-center text-2xl shadow-inner">
                    📊
                </div>
                <div>
                    <h3 class="text-base sm:text-lg font-black text-white">داشبورد جامع وضعیت و تحلیل موجودی کاتالوگ</h3>
                    <p class="text-xs text-indigo-200 font-normal">گزارش‌گیری دقیق و بلادرنگ بر اساس تیراژ، موجودی تجمیعی، تنوع و رسانه</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="copyCatalogStatusReport()" class="px-4 py-2 bg-white/10 hover:bg-white/20 text-indigo-100 rounded-xl text-xs font-bold border border-white/10 transition-all flex items-center gap-1.5 cursor-pointer">
                    <span>📋</span>
                    <span>کپی گزارش</span>
                </button>
                <button type="button" onclick="closeStatusAnalyticsModal()" class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 text-white flex items-center justify-center font-black text-lg transition-all cursor-pointer">✕</button>
            </div>
        </div>

        <!-- Modal Controls Subheader -->
        <div class="p-4 bg-white border-b border-slate-200/70 flex flex-wrap items-center justify-between gap-4 flex-shrink-0">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs font-bold text-slate-600 flex items-center gap-1.5">
                    <span>⚙️</span>
                    <span>عدد آستانه موجودی (N):</span>
                </span>
                <div class="flex items-center bg-slate-100 rounded-xl border border-slate-200 p-1">
                    <button type="button" onclick="stepThreshold(-1)" class="w-7 h-7 rounded-lg bg-white hover:bg-slate-50 text-slate-800 flex items-center justify-center font-black text-sm transition-all cursor-pointer shadow-sm">-</button>
                    <input type="number" id="modal-status-threshold-input" min="1" max="9999" value="5" oninput="updateStatusAnalytics(this.value)" class="w-12 text-center bg-transparent font-mono font-black text-sm text-indigo-700 outline-none">
                    <button type="button" onclick="stepThreshold(1)" class="w-7 h-7 rounded-lg bg-white hover:bg-slate-50 text-slate-800 flex items-center justify-center font-black text-sm transition-all cursor-pointer shadow-sm">+</button>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="setThreshold(1)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۱+</button>
                    <button type="button" onclick="setThreshold(3)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۳+</button>
                    <button type="button" onclick="setThreshold(5)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۵+</button>
                    <button type="button" onclick="setThreshold(10)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۱۰+</button>
                    <button type="button" onclick="setThreshold(20)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۲۰+</button>
                    <button type="button" onclick="setThreshold(50)" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600">۵۰+</button>
                </div>
            </div>

            <!-- Scope -->
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200 text-xs">
                <button type="button" id="m-scope-btn-approved" onclick="setAnalyticsScope('approved')" class="px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-sm">فقط کاتالوگ تایید شده</button>
                <button type="button" id="m-scope-btn-all" onclick="setAnalyticsScope('all')" class="px-3 py-1.5 rounded-lg font-bold transition-all text-slate-600 hover:text-slate-900">کل محصولات پروژه</button>
            </div>
        </div>

        <!-- Scrollable Modal Content Body -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1 custom-scrollbar">
            
            <!-- Quick Summary Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                    <div class="text-[11px] font-bold text-amber-600 mb-1">🔥 تجمیعی بالای <span class="threshold-num-display">5</span></div>
                    <div class="text-xl font-black text-slate-800 font-mono" id="m-stat-agg-count">۰</div>
                    <div class="text-[10px] text-slate-400 mt-1">تیراژ: <span id="m-stat-agg-sum" class="font-bold text-slate-600 font-mono">۰</span> عدد</div>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                    <div class="text-[11px] font-bold text-emerald-600 mb-1">📦 تک‌کالا بالای <span class="threshold-num-display">5</span></div>
                    <div class="text-xl font-black text-slate-800 font-mono" id="m-stat-direct-count">۰</div>
                    <div class="text-[10px] text-slate-400 mt-1">موجودی: <span id="m-stat-direct-sum" class="font-bold text-slate-600 font-mono">۰</span> عدد</div>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                    <div class="text-[11px] font-bold text-rose-600 mb-1">⚠️ کم‌موجودی (۱ تا <span class="threshold-minus-one-display">4</span>)</div>
                    <div class="text-xl font-black text-slate-800 font-mono" id="m-stat-low-count">۰</div>
                    <div class="text-[10px] text-slate-400 mt-1">نیازمند بازتامین</div>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                    <div class="text-[11px] font-bold text-slate-500 mb-1">🚫 بدون موجودی (۰ عدد)</div>
                    <div class="text-xl font-black text-slate-800 font-mono" id="m-stat-out-count">۰</div>
                    <div class="text-[10px] text-slate-400 mt-1">کالاهای تمام‌شده</div>
                </div>
            </div>

            <!-- Extended Metric Insights -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-indigo-50/60 p-4 rounded-2xl border border-indigo-100 text-xs">
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold">🖼️ چند تصویری (گالری‌دار):</span>
                    <span id="m-insight-multi-img" class="font-black text-indigo-700 font-mono text-sm">۰ کالا</span>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold">📷 بدون عکس دارای موجودی:</span>
                    <span id="m-insight-noimg-stock" class="font-black text-amber-700 font-mono text-sm">۰ کالا</span>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold">🎨 دارای تنوع و چندمدلی:</span>
                    <span id="m-insight-multivar" class="font-black text-indigo-700 font-mono text-sm">۰ کالا</span>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold">💰 میانگین قیمت کاتالوگ:</span>
                    <span id="m-insight-avg-price" class="font-black text-emerald-700 font-mono text-sm">-</span>
                </div>
            </div>

            <!-- Interactive Filter Tab Switcher & Search Bar -->
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="setModalFilterTab('stock_agg_gte')" data-filter="stock_agg_gte" class="modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-black bg-indigo-600 text-white shadow-sm transition-all">🔥 تجمیعی بالای <span class="threshold-num-display">5</span></button>
                        <button type="button" onclick="setModalFilterTab('stock_direct_gte')" data-filter="stock_direct_gte" class="modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all">📦 تک‌کالا بالای <span class="threshold-num-display">5</span></button>
                        <button type="button" onclick="setModalFilterTab('stock_low')" data-filter="stock_low" class="modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all">⚠️ کم‌موجودی</button>
                        <button type="button" onclick="setModalFilterTab('out_of_stock')" data-filter="out_of_stock" class="modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all">🚫 بدون موجودی</button>
                        <button type="button" onclick="setModalFilterTab('no_image_in_stock')" data-filter="no_image_in_stock" class="modal-filter-tab-btn px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all">📷 بدون عکس (دارای موجودی)</button>
                    </div>

                    <div class="flex items-center gap-2">
                        <span id="modal-filtered-count-badge" class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-black border border-indigo-100">۰ کالا</span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <input type="text" id="modal-filter-search-input" oninput="renderAnalyticsModalProducts(window.currentModalFilter)" placeholder="جستجوی عنوان، کد SKU یا رنگ در نتایج..." class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-indigo-500 shadow-inner">
                </div>
            </div>

            <!-- Matching Products List -->
            <div id="modal-products-list-container" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-[360px] overflow-y-auto custom-scrollbar p-1">
                <!-- Dynamically populated via renderAnalyticsModalProducts -->
            </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="p-5 bg-white border-t border-slate-200/80 flex flex-wrap items-center justify-between gap-3 flex-shrink-0">
            <div class="flex items-center gap-2">
                <button type="button" onclick="exportCatalogStatusCSV()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
                    <span>📥</span>
                    <span>خروجی اکسل/CSV</span>
                </button>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeStatusAnalyticsModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition-all cursor-pointer">بستن</button>
                <button type="button" onclick="applyStatusFilterToLibrary(window.currentModalFilter)" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md transition-all flex items-center gap-2 cursor-pointer active:scale-95">
                    <span>🚀</span>
                    <span>اعمال این فیلتر در کتابخانه اصلی</span>
                </button>
            </div>
        </div>

    </div>
</div>
</body>
</html>
