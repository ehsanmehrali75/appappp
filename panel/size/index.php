<?php
/**
 * Tanedorost Size Chart Studio - Standalone Client/Server Module
 * Route: /size
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
    redirect('../index.php');
}

// Check Size Chart permission, allow administrators or users with permission
if (!hasPermission('size_chart') && !hasPermission('settings') && !hasPermission('products')) {
    die('<div style="font-family:Tahoma; text-align:center; padding: 100px; direction: rtl; background-color: #f8fafc; height: 100vh;">
        <div style="background: white; max-width: 500px; margin: auto; padding: 40px; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
            <h2 style="color:#ef4444; font-size: 24px; margin-bottom: 16px;">⚠️ عدم دسترسی کافی</h2>
            <p style="color:#64748b; font-size: 14px; line-height: 2;">کاربر گرامی، شما مجوزهای لازم جهت دسترسی به ماژول مدیریت و استدیو جدول سایز را ندارید. لطفاً با مدیر سیستم یا پروژه تماس بگیرید.</p>
            <hr style="margin: 24px 0; border: 0; border-top: 1px solid #f1f5f9;">
            <a href="../admin.php" style="display: inline-block; background: #4f46e5; color: white; padding: 12px 24px; border-radius: 12px; font-weight: bold; text-decoration: none; font-size: 13px;">← بازگشت به پنل اصلی</a>
        </div>
    </div>');
}

// Project Switcher inside Size Chart Studio
if (isset($_GET['switch_project'])) {
    $swPid = $_GET['switch_project'];
    $hasAccess = false;
    if (isSuperAdmin()) {
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
    if (isSuperAdmin()) {
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
        if (isSuperAdmin()) {
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

// Bind branding requests to the project displayed in this studio, not a
// shared session that another browser tab may have switched.
$studioProjectId = $_GET['project'] ?? ($_SESSION['project_id'] ?? null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'td_scs_save_branding_settings') {
    $studioProjectId = $_POST['project_id'] ?? ($_GET['project'] ?? null);
}
$allowedProjectIds = array_column($allProjects, 'id');
if (!is_string($studioProjectId) || !in_array($studioProjectId, $allowedProjectIds, true)) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'پروژه معتبر و مجاز انتخاب نشده است.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'پروژه معتبر و مجاز انتخاب نشده است.';
    }
    exit;
}
$project = getCurrentProject($studioProjectId, true);
if (!$project) {
    die('پروژه فعال یافت نشد.');
}

if (empty($allProjects)) {
    $allProjects[] = $project;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$dir = dirname(dirname($script));
if ($dir === '/' || $dir === '\\') {
    $dir = '';
}
$baseUrl = $protocol . $host . $dir . '/';

$missingSizeChartProducts = [];
$pendingUploadSizeChartProducts = [];

foreach (($project['products'] ?? []) as $p) {
    if (isProductMissingSizeChart($p)) {
        $missingSizeChartProducts[] = $p;
    } elseif (isProductSizeChartPendingUpload($p)) {
        $pendingUploadSizeChartProducts[] = $p;
    }
}
$missingChartsCount = count($missingSizeChartProducts);
$pendingUploadChartsCount = count($pendingUploadSizeChartProducts);

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

$default_block_positions = [
    'header' => ['top' => 36, 'left' => 36, 'width' => 728, 'height' => 'auto', 'zIndex' => 10],
    'meta' => ['top' => 125, 'left' => 36, 'width' => 728, 'height' => 'auto', 'zIndex' => 9],
    'table' => ['top' => 185, 'left' => 36, 'width' => 480, 'height' => 'auto', 'zIndex' => 8],
    'illus' => ['top' => 185, 'left' => 536, 'width' => 228, 'height' => 'auto', 'zIndex' => 7],
    'notes' => ['top' => 610, 'left' => 36, 'width' => 728, 'height' => 'auto', 'zIndex' => 6],
    'specs' => ['top' => 710, 'left' => 36, 'width' => 728, 'height' => 'auto', 'zIndex' => 5],
    'footer' => ['top' => 920, 'left' => 36, 'width' => 728, 'height' => 'auto', 'zIndex' => 4]
];

$default_scs_settings = [
    'brand_title' => 'تَـنْ دُرُسْـتْ',
    'brand_subtitle' => 'پوشاک الیاف طبیعی از ۱۳۸۲',
    'brand_tagline' => 'تولید ملی ایران زمین - اصالت و ماندگاری الیاف گویای هویت ماست',
    'brand_website' => 'www.tanedorost.com',
    'image_logo' => '',
    
    // Font Typography Management
    'font_family' => 'Vazirmatn',
    'header_font_family' => '',
    'font_scale' => 100,
    'font_weight' => 'normal',
    'custom_font_url' => '',
    'custom_font_name' => '',
    'font_digits' => 'persian',

    // Background & Canvas Dimensions
    'bg_image' => '',
    'bg_pattern' => 'none',
    'canvas_preset' => 'catalog_standard',
    'canvas_width' => 800,
    'canvas_height' => 1000,
    'bg_fit' => 'cover',
    'bg_overlay_mode' => 'none',
    'bg_overlay_opacity' => 0,

    // Guide/Illustration Image Sizing & Control
    'illus_max_height' => 160,
    'illus_object_fit' => 'contain',
    
    // Dynamic Layout & Content Structure
    'positioning_mode' => 'flow', // 'flow' (جریان خودکار) or 'freeform' (بوم آزاد المنتور و فتوشاپ)
    'block_positions' => $default_block_positions,
    'layout_mode' => 'standard_split',
    'content_padding_top' => 36,
    'content_padding_bottom' => 28,
    'content_padding_x' => 36,
    'content_gap' => 24,
    'block_order' => ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'],
    'show_header_brand' => 1,
    'show_meta_pills' => 1,
    'show_table' => 1,
    'show_illustration' => 1,
    'show_specs' => 1,
    'show_notes' => 1,
    'show_footer_brand' => 1,
    
    // Style Mode (Default Base Software vs Custom Style)
    'style_mode' => 'default',
    'custom_theme_preset' => 'custom',
    'card_bg_color' => '#ffffff',
    'card_bg_opacity' => 100,
    'card_border_color' => '#e2e8f0',
    'card_border_width' => 1,
    'card_border_radius' => 16,
    'card_shadow' => 'sm',
    'text_primary_color' => '#0f172a',
    'text_muted_color' => '#64748b',
    'accent_color' => '#4f46e5',
    'table_header_bg' => '#f8fafc',
    'table_header_text' => '#1e293b',
    'table_cell_text' => '#1e293b',
    'table_border_color' => '#e2e8f0',
    'table_border_width' => 1,
    'table_row_alt_bg' => '#fcfdfe',
    'table_bg_transparent' => 0,
    'table_bg_color' => '#ffffff',
    'table_bg_opacity' => 100,
    'table_font_size' => 11,
    'table_cell_padding' => 8,
    'table_text_align' => 'center',
    'table_pure_data_overlay' => 0,
    'table_show_header' => 1,
    'table_row_gap' => 0,
    'table_split_rows' => 0,
    'table_split_cells' => 0,
    'table_auto_scale' => 1,
    'table_scale_mode' => 'smart_fit',
    'table_target_height' => 360,
    'table_min_row_height' => 22,
    'table_max_row_height' => 65,
    'table_scale' => 100,
    'table_lock_ratio' => 1
];
$raw_saved_settings = $project['settings']['size_chart_settings'] ?? [];
// Self-heal: if all blocks were saved as 0 due to missing checkboxes, restore to 1
if (isset($raw_saved_settings['show_table']) && empty($raw_saved_settings['show_table']) && empty($raw_saved_settings['show_header_brand']) && empty($raw_saved_settings['show_meta_pills'])) {
    $raw_saved_settings['show_header_brand'] = 1;
    $raw_saved_settings['show_meta_pills'] = 1;
    $raw_saved_settings['show_table'] = 1;
    $raw_saved_settings['show_illustration'] = 1;
    $raw_saved_settings['show_specs'] = 1;
    $raw_saved_settings['show_notes'] = 1;
    $raw_saved_settings['show_footer_brand'] = 1;
}
$scs_settings = array_merge($default_scs_settings, $raw_saved_settings);

// -------------------------------------------------------------
// SECURE DIRECT BACKEND AJAX HANDLERS (Intercepted at load time)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'request_manual_size_chart') {
        $product_id = $_POST['product_id'] ?? '';
        if (empty($product_id)) {
            echo json_encode(['success' => false, 'message' => 'شناسه محصول نامعتبر است.']);
            exit;
        }
        foreach ($project['products'] as &$p) {
            if ($p['id'] === $product_id) {
                $p['manual_size_chart_requested'] = 1;
                break;
            }
        }
        saveProject($project);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Category Presets list Action
    if ($action === 'td_scs_presets_list') {
        $presets = $project['settings']['size_chart_presets'] ?? [];
        if (empty($presets)) {
            // Default built-in presets
            $presets = [
                [
                    'id' => 'pants_simple',
                    'name' => 'شلوار (ساده)',
                    'category' => 'شلوار',
                    'columns' => ['سایز محصول','قد شلوار','1/2دور کمر','1/2دور ران','دمپا'],
                    'show_model' => 0,
                    'show_waist' => 0,
                    'illus_url' => '',
                    'icon_url' => '',
                ],
                [
                    'id' => 'pants_extended',
                    'name' => 'شلوار/دامن (مدل + نوع کمر)',
                    'category' => 'شلوار',
                    'columns' => ['سایز محصول','قد شلوار/دامن','1/2دور ران','1/2دور کمر','دمپا','مدل محصول','نوع کمر'],
                    'show_model' => 1,
                    'show_waist' => 1,
                    'illus_url' => '',
                    'icon_url' => '',
                ],
                [
                    'id' => 'tops_extended',
                    'name' => 'بلوز/تونیک (کامل)',
                    'category' => 'بلوز/تونیک',
                    'columns' => ['سایز محصول','قد محصول','قد آستین','شانه','عرض لباس (آستین سرخود)','عرض دمپا (آستین سرخود)','1/2دورسینه','مدل محصول'],
                    'show_model' => 1,
                    'show_waist' => 0,
                    'illus_url' => '',
                    'icon_url' => '',
                ],
            ];
            $project['settings']['size_chart_presets'] = $presets;
            saveProject($project);
        }
        echo json_encode(['success' => true, 'data' => ['items' => $presets]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Preset Save Action
    if ($action === 'td_scs_preset_save') {
        $presets = $project['settings']['size_chart_presets'] ?? [];
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $columns_raw = $_POST['columns'] ?? '[]';
        
        $columns = json_decode($columns_raw, true) ?: [];
        if (empty($columns) && is_string($columns_raw)) {
            $columns = array_filter(array_map('trim', explode("\n", $columns_raw)));
        }
        
        $show_model = !empty($_POST['show_model']) ? 1 : 0;
        $show_waist = !empty($_POST['show_waist']) ? 1 : 0;
        $illus_url = trim($_POST['illus_url'] ?? '');
        $icon_url = trim($_POST['icon_url'] ?? '');
        
        if (empty($name) || empty($category)) {
            echo json_encode(['success' => false, 'message' => 'نام پریست و دسته‌بندی اجباری هستند.']);
            exit;
        }
        
        if (!$id) {
            $id = 'p_' . uniqid();
            $presets[] = [
                'id' => $id,
                'name' => $name,
                'category' => $category,
                'columns' => array_values($columns),
                'show_model' => $show_model,
                'show_waist' => $show_waist,
                'illus_url' => $illus_url,
                'icon_url' => $icon_url
            ];
        } else {
            foreach ($presets as &$p) {
                if ($p['id'] === $id) {
                    $p['name'] = $name;
                    $p['category'] = $category;
                    $p['columns'] = array_values($columns);
                    $p['show_model'] = $show_model;
                    $p['show_waist'] = $show_waist;
                    $p['illus_url'] = $illus_url;
                    $p['icon_url'] = $icon_url;
                }
            }
        }
        $project['settings']['size_chart_presets'] = $presets;
        saveProject($project);
        
        echo json_encode(['success' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Preset Delete Action
    if ($action === 'td_scs_preset_delete') {
         $presets = $project['settings']['size_chart_presets'] ?? [];
         $id = $_POST['id'] ?? '';
         $presets = array_values(array_filter($presets, function($p) use ($id){
             return $p['id'] !== $id;
         }));
         $project['settings']['size_chart_presets'] = $presets;
         saveProject($project);
         echo json_encode(['success' => true]);
         exit;
    }

    // Reset All Size Charts (List Reset)
    if ($action === 'td_scs_reset_all_charts') {
         $project['settings']['size_charts'] = [];
         if (!empty($project['products'])) {
             foreach ($project['products'] as &$p) {
                 $p['size_chart_template_id'] = '';
                 $p['size_chart'] = '';
             }
         }
         saveProject($project);
         echo json_encode(['success' => true]);
         exit;
    }

    // Reset All Presets (Standardization Reset)
    if ($action === 'td_scs_reset_all_presets') {
         $default_presets = [
             [
                 'id' => 'pants_simple',
                 'name' => 'شلوار (ساده)',
                 'category' => 'شلوار',
                 'columns' => ['سایز محصول','قد شلوار','1/2دور کمر','1/2دور ران','دمپا'],
                 'show_model' => 0,
                 'show_waist' => 0,
                 'illus_url' => '',
                 'icon_url' => '',
             ],
             [
                 'id' => 'pants_extended',
                 'name' => 'شلوار/دامن (مدل + نوع کمر)',
                 'category' => 'شلوار',
                 'columns' => ['سایز محصول','قد شلوار/دامن','1/2دور ران','1/2دور کمر','دمپا','مدل محصول','نوع کمر'],
                 'show_model' => 1,
                 'show_waist' => 1,
                 'illus_url' => '',
                 'icon_url' => '',
             ],
             [
                 'id' => 'tops_extended',
                 'name' => 'بلوز/تونیک (کامل)',
                 'category' => 'بلوز/تونیک',
                 'columns' => ['سایز محصول','قد محصول','قد آستین','شانه','عرض لباس (آستین سرخود)','عرض دمپا (آستین سرخود)','1/2دورسینه','مدل محصول'],
                 'show_model' => 1,
                 'show_waist' => 0,
                 'illus_url' => '',
                 'icon_url' => '',
             ],
         ];
         $project['settings']['size_chart_presets'] = $default_presets;
         $project['settings']['size_chart_desc_presets'] = [];
         saveProject($project);
         echo json_encode(['success' => true]);
         exit;
    }
    
    // Description Presets list Action
    if ($action === 'td_scs_desc_presets_list') {
         $desc_presets = $project['settings']['size_chart_desc_presets'] ?? [];
         echo json_encode(['success' => true, 'data' => ['items' => $desc_presets]], JSON_UNESCAPED_UNICODE);
         exit;
    }
    
    // Description Preset Save Action
    if ($action === 'td_scs_desc_preset_save') {
         $desc_presets = $project['settings']['size_chart_desc_presets'] ?? [];
         $id = $_POST['id'] ?? '';
         $title = trim($_POST['title'] ?? '');
         $fields = json_decode($_POST['fields'] ?? '[]', true) ?: [];
         
         if (empty($title)) {
             echo json_encode(['success' => false, 'message' => 'عنوان پریست توضیحات اجباری است']);
             exit;
         }
         
         if (!$id) {
             $id = 'dp_' . uniqid();
             $desc_presets[] = [
                 'id' => $id,
                 'title' => $title,
                 'fields' => $fields
             ];
         } else {
             foreach ($desc_presets as &$p) {
                 if ($p['id'] === $id) {
                     $p['title'] = $title;
                     $p['fields'] = $fields;
                 }
             }
         }
         $project['settings']['size_chart_desc_presets'] = $desc_presets;
         saveProject($project);
         
         echo json_encode(['success' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
         exit;
    }
    
    // Description Preset Delete Action
    if ($action === 'td_scs_desc_preset_delete') {
         $desc_presets = $project['settings']['size_chart_desc_presets'] ?? [];
         $id = $_POST['id'] ?? '';
         $desc_presets = array_values(array_filter($desc_presets, function($p) use ($id){
             return $p['id'] !== $id;
         }));
         $project['settings']['size_chart_desc_presets'] = $desc_presets;
         saveProject($project);
         echo json_encode(['success' => true]);
         exit;
    }
    
    // Size Charts List Action
    if ($action === 'td_scs_charts_list') {
         $charts = $project['settings']['size_charts'] ?? [];
         $category = $_POST['category'] ?? '';
         $filtered = [];
         foreach ($charts as $c) {
             if ($category === '' || ($c['category'] ?? '') === $category) {
                 $filtered[] = $c;
             }
         }
         echo json_encode(['success' => true, 'data' => ['items' => $filtered]], JSON_UNESCAPED_UNICODE);
         exit;
    }
    
    // Size Chart Load Action
    if ($action === 'td_scs_chart_load') {
         $charts = $project['settings']['size_charts'] ?? [];
         $id = $_POST['id'] ?? '';
         $found = null;
         foreach ($charts as $c) {
             if (($c['id'] ?? '') === $id) {
                 $found = $c;
                 break;
             }
         }
         if ($found) {
             echo json_encode(['success' => true, 'data' => ['data' => $found]], JSON_UNESCAPED_UNICODE);
         } else {
             echo json_encode(['success' => false, 'message' => 'جدول یافت نشد.']);
         }
         exit;
    }
    
    // Size Chart Save Action (Metadata)
    if ($action === 'td_scs_chart_save') {
         $charts = $project['settings']['size_charts'] ?? [];
         $id = $_POST['id'] ?? '';
         $title = trim($_POST['title'] ?? '');
         $product_desc = trim($_POST['product_desc'] ?? '');
         $category = trim($_POST['category'] ?? '');
         $preset_id = trim($_POST['preset_id'] ?? '');
         $model = trim($_POST['model'] ?? '');
         $waist_type = trim($_POST['waist_type'] ?? '');
         $desc_preset_id = trim($_POST['desc_preset_id'] ?? '');
         $desc_values = json_decode($_POST['desc_values'] ?? '[]', true) ?: [];
         $columns = json_decode($_POST['columns'] ?? '[]', true) ?: [];
         $rows = json_decode($_POST['rows'] ?? '[]', true) ?: [];
         $image_path = trim($_POST['image_path'] ?? '');
         $product_notes = trim($_POST['product_notes'] ?? '');
         
         if (empty($product_desc) || empty($category)) {
              echo json_encode(['success' => false, 'message' => 'توضیحات محصول و دسته‌بندی الزامی هستند.']);
              exit;
         }
         
         if (!$id) {
             $id = 'c_' . uniqid();
             $charts[] = [
                 'id' => $id,
                 'title' => $title ?: $product_desc,
                 'product_desc' => $product_desc,
                 'category' => $category,
                 'preset_id' => $preset_id,
                 'model' => $model,
                 'waist_type' => $waist_type,
                 'desc_preset_id' => $desc_preset_id,
                 'desc_values' => $desc_values,
                 'columns' => $columns,
                 'rows' => $rows,
                 'image_path' => $image_path,
                 'product_notes' => $product_notes
             ];
         } else {
             foreach ($charts as &$c) {
                 if ($c['id'] === $id) {
                     $c['title'] = $title ?: $product_desc;
                     $c['product_desc'] = $product_desc;
                     $c['category'] = $category;
                     $c['preset_id'] = $preset_id;
                     $c['model'] = $model;
                     $c['waist_type'] = $waist_type;
                     $c['desc_preset_id'] = $desc_preset_id;
                     $c['desc_values'] = $desc_values;
                     $c['columns'] = $columns;
                     $c['rows'] = $rows;
                     $c['product_notes'] = $product_notes;
                     if (!empty($image_path)) {
                         $c['image_path'] = $image_path;
                     }
                 }
             }
         }
         
         // Atomic Product Linking
         $image_path_to_use = $image_path;
         if (empty($image_path_to_use)) {
             foreach ($charts as $c) {
                 if ($c['id'] === $id) {
                     $image_path_to_use = $c['image_path'] ?? '';
                     break;
                 }
             }
         }
         
         $linked_product_id = trim($_POST['linked_product_id'] ?? '');
         if (!empty($linked_product_id)) {
             $found_product = null;
             foreach (($project['products'] ?? []) as $prod) {
                 if (($prod['id'] ?? '') === $linked_product_id) {
                     $found_product = $prod;
                     break;
                 }
             }
             if ($found_product) {
                 $base_name = '';
                 if (!empty($found_product['name'])) {
                     $parts = explode('-', $found_product['name']);
                     $base_name = trim($parts[0]);
                 }
                 
                 foreach ($project['products'] as &$p) {
                     $p_base_name = '';
                     if (!empty($p['name'])) {
                         $p_parts = explode('-', $p['name']);
                         $p_base_name = trim($p_parts[0]);
                     }
                     if ($p['id'] === $linked_product_id || (!empty($base_name) && !empty($p_base_name) && mb_strtolower($p_base_name) === mb_strtolower($base_name))) {
                         $p['size_chart_template_id'] = $id;
                         if (!empty($image_path_to_use)) {
                             $p['size_chart'] = $image_path_to_use;
                         }
                     } else {
                         if (($p['size_chart_template_id'] ?? '') === $id) {
                             $p['size_chart_template_id'] = '';
                             $p['size_chart'] = '';
                         }
                     }
                 }
             }
         }
         
         $project['settings']['size_charts'] = $charts;
         saveProject($project);
         
         echo json_encode(['success' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
         exit;
    }

    // Save Branding Settings Action
    if ($action === 'td_scs_save_branding_settings') {
         $block_order_raw = $_POST['block_order'] ?? '';
         if (is_array($block_order_raw)) {
             $block_order = $block_order_raw;
         } else {
             $block_order = json_decode($block_order_raw, true);
         }
         if (!is_array($block_order) || empty($block_order)) {
             $block_order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
         }

         $block_positions_raw = $_POST['block_positions'] ?? '';
         if (is_array($block_positions_raw)) {
             $block_positions = $block_positions_raw;
         } else {
             $block_positions = json_decode($block_positions_raw, true);
         }
         if (!is_array($block_positions) || empty($block_positions)) {
             $block_positions = $default_block_positions;
         }

         $settings = [
             'brand_title' => trim($_POST['brand_title'] ?? ''),
             'brand_subtitle' => trim($_POST['brand_subtitle'] ?? ''),
             'brand_tagline' => trim($_POST['brand_tagline'] ?? ''),
             'brand_website' => trim($_POST['brand_website'] ?? ''),
             'image_logo' => trim($_POST['image_logo'] ?? ''),
             
             // Font Typography Management
             'font_family' => trim($_POST['font_family'] ?? 'Vazirmatn'),
             'header_font_family' => trim($_POST['header_font_family'] ?? ''),
             'font_scale' => max(70, min(160, (int)($_POST['font_scale'] ?? 100))),
             'font_weight' => trim($_POST['font_weight'] ?? 'normal'),
             'custom_font_url' => trim($_POST['custom_font_url'] ?? ''),
             'custom_font_name' => trim($_POST['custom_font_name'] ?? ''),
             'font_digits' => trim($_POST['font_digits'] ?? 'persian'),

             // Background & Canvas Dimensions
             'bg_image' => trim($_POST['bg_image'] ?? ''),
             'bg_pattern' => trim($_POST['bg_pattern'] ?? 'none'),
             'canvas_preset' => trim($_POST['canvas_preset'] ?? 'catalog_standard'),
             'canvas_width' => max(400, min(2400, (int)($_POST['canvas_width'] ?? 800))),
             'canvas_height' => max(400, min(3200, (int)($_POST['canvas_height'] ?? 1000))),
             'bg_fit' => trim($_POST['bg_fit'] ?? 'cover'),
             'bg_overlay_mode' => trim($_POST['bg_overlay_mode'] ?? 'none'),
             'bg_overlay_opacity' => max(0, min(100, (int)($_POST['bg_overlay_opacity'] ?? 0))),
             
             // Guide/Illustration Image Sizing & Control
             'illus_max_height' => max(50, min(500, (int)($_POST['illus_max_height'] ?? 160))),
             'illus_object_fit' => trim($_POST['illus_object_fit'] ?? 'contain'),

             // Dynamic Layout & Content Structure
             'positioning_mode' => trim($_POST['positioning_mode'] ?? 'flow'),
             'block_positions' => $block_positions,
             'layout_mode' => trim($_POST['layout_mode'] ?? 'standard_split'),
             'content_padding_top' => max(0, min(300, (int)($_POST['content_padding_top'] ?? 36))),
             'content_padding_bottom' => max(0, min(300, (int)($_POST['content_padding_bottom'] ?? 28))),
             'content_padding_x' => max(0, min(200, (int)($_POST['content_padding_x'] ?? 36))),
             'content_gap' => max(8, min(80, (int)($_POST['content_gap'] ?? 24))),
             'block_order' => array_values($block_order),
             'show_header_brand' => isset($_POST['show_header_brand']) ? (int)$_POST['show_header_brand'] : 1,
             'show_meta_pills' => isset($_POST['show_meta_pills']) ? (int)$_POST['show_meta_pills'] : 1,
             'show_table' => isset($_POST['show_table']) ? (int)$_POST['show_table'] : 1,
             'show_illustration' => isset($_POST['show_illustration']) ? (int)$_POST['show_illustration'] : 1,
             'show_specs' => isset($_POST['show_specs']) ? (int)$_POST['show_specs'] : 1,
             'show_notes' => isset($_POST['show_notes']) ? (int)$_POST['show_notes'] : 1,
             'show_footer_brand' => isset($_POST['show_footer_brand']) ? (int)$_POST['show_footer_brand'] : 1,
             
             // Style Mode (Default Base Software vs Custom Style)
             'style_mode' => trim($_POST['style_mode'] ?? 'default'),
             'custom_theme_preset' => trim($_POST['custom_theme_preset'] ?? 'custom'),
             'card_bg_color' => trim($_POST['card_bg_color'] ?? '#ffffff'),
             'card_bg_opacity' => max(0, min(100, (int)($_POST['card_bg_opacity'] ?? 100))),
             'card_border_color' => trim($_POST['card_border_color'] ?? '#e2e8f0'),
             'card_border_width' => max(0, min(10, (int)($_POST['card_border_width'] ?? 1))),
             'card_border_radius' => max(0, min(40, (int)($_POST['card_border_radius'] ?? 16))),
             'card_shadow' => trim($_POST['card_shadow'] ?? 'sm'),
             'text_primary_color' => trim($_POST['text_primary_color'] ?? '#0f172a'),
             'text_muted_color' => trim($_POST['text_muted_color'] ?? '#64748b'),
             'accent_color' => trim($_POST['accent_color'] ?? '#4f46e5'),
             'table_header_bg' => trim($_POST['table_header_bg'] ?? '#f8fafc'),
             'table_header_text' => trim($_POST['table_header_text'] ?? '#1e293b'),
             'table_cell_text' => trim($_POST['table_cell_text'] ?? '#1e293b'),
             'table_border_color' => trim($_POST['table_border_color'] ?? '#e2e8f0'),
             'table_border_width' => max(0, min(10, (int)($_POST['table_border_width'] ?? 1))),
             'table_row_alt_bg' => trim($_POST['table_row_alt_bg'] ?? '#fcfdfe'),
             'table_bg_transparent' => isset($_POST['table_bg_transparent']) ? (int)$_POST['table_bg_transparent'] : 0,
             'table_bg_color' => trim($_POST['table_bg_color'] ?? '#ffffff'),
             'table_bg_opacity' => max(0, min(100, (int)($_POST['table_bg_opacity'] ?? 100))),
             'table_font_size' => max(8, min(24, (int)($_POST['table_font_size'] ?? 11))),
             'table_cell_padding' => max(2, min(30, (int)($_POST['table_cell_padding'] ?? 8))),
             'table_text_align' => trim($_POST['table_text_align'] ?? 'center'),
             'table_pure_data_overlay' => isset($_POST['table_pure_data_overlay']) ? (int)$_POST['table_pure_data_overlay'] : 0,
             'table_show_header' => isset($_POST['table_show_header']) ? (int)$_POST['table_show_header'] : 1,
             'table_row_gap' => max(0, min(80, (int)($_POST['table_row_gap'] ?? 0))),
             'table_split_rows' => isset($_POST['table_split_rows']) ? (int)$_POST['table_split_rows'] : 0,
             'table_split_cells' => isset($_POST['table_split_cells']) ? (int)$_POST['table_split_cells'] : 0,
             'table_auto_scale' => isset($_POST['table_auto_scale']) ? (int)$_POST['table_auto_scale'] : 1,
             'table_scale_mode' => trim($_POST['table_scale_mode'] ?? 'smart_fit'),
             'table_target_height' => max(150, min(1200, (int)($_POST['table_target_height'] ?? 360))),
             'table_min_row_height' => max(16, min(50, (int)($_POST['table_min_row_height'] ?? 22))),
             'table_max_row_height' => max(35, min(120, (int)($_POST['table_max_row_height'] ?? 65))),
             'table_scale' => max(40, min(500, (int)($_POST['table_scale'] ?? 100))),
             'table_lock_ratio' => isset($_POST['table_lock_ratio']) ? (int)$_POST['table_lock_ratio'] : 1
         ];

         $project['settings']['size_chart_settings'] = $settings;
         $saved = saveProject($project);
         echo json_encode(['success' => (bool)$saved, 'project_id' => $project['id'], 'settings' => $settings], JSON_UNESCAPED_UNICODE);
         exit;
    }
    
    // Base64 Client Canvas Img Upload Action
    if ($action === 'td_scs_chart_image_upload') { $existing_image_path = trim($_POST['existing_image_path'] ?? '');
         $image_data = $_POST['image_data'] ?? '';
         if ($image_data) {
             $image_data = str_replace('data:image/png;base64,', '', $image_data);
             $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
             $image_data = str_replace(' ', '+', $image_data);
             $decoded = base64_decode($image_data);
             
             if ($decoded) {
                 $dir = __DIR__ . '/../uploads/';
                 if (!file_exists($dir)) {
                     mkdir($dir, 0755, true);
                 }
                 $filename = '';
                  if (!empty($existing_image_path)) {
                      $base = basename($existing_image_path);
                      if (preg_match('/^(psizechart_|psizechart_g_|scs_asset_)[0-9a-zA-Z_\.-]+\.(jpg|png|jpeg|gif)$/i', $base)) {
                          $filename = $base;
                      }
                  }
                  if (empty($filename)) {
                      $filename = 'psizechart_g_' . time() . '_' . uniqid() . '.jpg';
                  }
                 file_put_contents($dir . $filename, $decoded);
                 chmod($dir . $filename, 0644);
                 
                 echo json_encode(['success' => true, 'data' => ['url' => 'uploads/' . $filename]], JSON_UNESCAPED_UNICODE);
                 exit;
             }
         }
         echo json_encode(['success' => false, 'message' => 'بارگذاری تصویر ناموفق بود.']);
          exit;
     }

     // Link Size Chart to Product and its sister variants
     if ($action === 'td_scs_link_product_chart') {
          $product_id = $_POST['product_id'] ?? '';
          $chart_id = $_POST['chart_id'] ?? '';

          // Find product
          $found_product = null;
          $products = $project['products'] ?? [];
          foreach ($products as $p) {
              if (($p['id'] ?? '') === $product_id) {
                  $found_product = $p;
                  break;
              }
          }

          if (!$found_product) {
              echo json_encode(['success' => false, 'message' => 'محصول یافت نشد.']);
              exit;
          }

          $base_name = '';
          if (!empty($found_product['name'])) {
              $parts = explode('-', $found_product['name']);
              $base_name = trim($parts[0]);
          }

          // Find size chart image path
          $image_path = '';
          if (!empty($chart_id)) {
              $charts = $project['settings']['size_charts'] ?? [];
              foreach ($charts as $c) {
                  if (($c['id'] ?? '') === $chart_id) {
                      $image_path = $c['image_path'] ?? '';
                      break;
                  }
              }
          }

          // Update all products sharing the same base name
          $updated_count = 0;
          foreach ($project['products'] as &$p) {
              $p_base_name = '';
              if (!empty($p['name'])) {
                  $p_parts = explode('-', $p['name']);
                  $p_base_name = trim($p_parts[0]);
              }

              if ($p['id'] === $product_id || (!empty($base_name) && !empty($p_base_name) && mb_strtolower($p_base_name) === mb_strtolower($base_name))) {
                  $p['size_chart_template_id'] = $chart_id;
                  unset($p['manual_size_chart_requested']);
                  if (!empty($image_path)) {
                      $p['size_chart'] = $image_path;
                  } elseif ($chart_id === '') {
                      $p['size_chart'] = '';
                      unset($p['size_chart_uploaded']);
                  }
                  if ($chart_id !== '') {
                      $p['size_chart_uploaded'] = false;
                      if (isProductNoImage($p)) {
                          $p['status'] = 'pending';
                          $p['waiting_final_photo'] = true;
                      }
                  }
                  if (function_exists('saveProduct')) {
                      saveProduct($p, $project['id']);
                  }
                  $updated_count++;
              }
          }

          saveProject($project);
          echo json_encode(['success' => true, 'updated_count' => $updated_count]);
          exit;
     }

     // Toggle size chart uploaded status
     if ($action === 'td_scs_toggle_uploaded') {
         $product_id = trim($_POST['product_id'] ?? '');
         if (empty($product_id)) {
             echo json_encode(['success' => false, 'message' => 'شناسه کالا ارسال نشده است.']);
             exit;
         }

         // Locate target product (matching id or sku loosely as string)
         $target_product = null;
         foreach ($project['products'] as $p) {
             $pId = (string)($p['id'] ?? '');
             $pSku = (string)($p['sku'] ?? '');
             if ($pId === $product_id || (!empty($pSku) && $pSku === $product_id)) {
                 $target_product = $p;
                 break;
             }
         }

         if (!$target_product) {
             echo json_encode(['success' => false, 'message' => 'محصول یافت نشد.']);
             exit;
         }

         // If explicit mark_uploaded is passed, use it; otherwise toggle
         if (isset($_POST['mark_uploaded'])) {
             $newStatus = (!empty($_POST['mark_uploaded']) && $_POST['mark_uploaded'] !== 'false' && $_POST['mark_uploaded'] !== '0');
         } else {
             $newStatus = empty($target_product['size_chart_uploaded']);
         }

         // Find base name if available to sync sibling variants sharing the chart
         $target_base_name = '';
         if (!empty($target_product['name'])) {
             $parts = explode('-', $target_product['name']);
             $target_base_name = mb_strtolower(trim($parts[0]));
         }
         $target_chart_id = (string)($target_product['size_chart_template_id'] ?? '');
         $target_chart_img = (string)($target_product['size_chart'] ?? '');

         $updated_ids = [];
         foreach ($project['products'] as &$p) {
             $pId = (string)($p['id'] ?? '');
             $pSku = (string)($p['sku'] ?? '');
             $is_direct = ($pId === $product_id || (!empty($pSku) && $pSku === $product_id));

             $is_variant = false;
             if (!$is_direct && !empty($target_base_name) && !empty($p['name'])) {
                 $parts = explode('-', $p['name']);
                 $pBase = mb_strtolower(trim($parts[0]));
                 if ($pBase === $target_base_name) {
                     $pChartId = (string)($p['size_chart_template_id'] ?? '');
                     $pChartImg = (string)($p['size_chart'] ?? '');
                     if ((!empty($target_chart_id) && $pChartId === $target_chart_id) ||
                         (!empty($target_chart_img) && $pChartImg === $target_chart_img)) {
                         $is_variant = true;
                     }
                 }
             }

             if ($is_direct || $is_variant) {
                 $p['size_chart_uploaded'] = $newStatus;
                 $p['size_chart_uploaded_at'] = $newStatus ? date('Y-m-d H:i:s') : null;
                 $p['manualEdit'] = true;
                 if (function_exists('saveProduct')) {
                     saveProduct($p, $project['id']);
                 }
                 $updated_ids[] = $pId;
             }
         }

         saveProject($project);
         echo json_encode([
             'success' => true,
             'is_uploaded' => $newStatus,
             'size_chart_uploaded' => $newStatus,
             'updated_ids' => $updated_ids,
             'product_id' => $product_id,
             'message' => $newStatus ? 'جدول سایز به عنوان آپلود شده در سایت ثبت شد. ✓' : 'وضعیت به منتظر آپلود تغییر کرد.'
         ]);
         exit;
     }

     // Bulk mark size chart uploaded
     if ($action === 'td_scs_bulk_mark_uploaded') {
         $product_ids = $_POST['product_ids'] ?? [];
         if (is_string($product_ids)) {
             $product_ids = json_decode($product_ids, true) ?: [];
         }
         $product_ids_map = [];
         foreach ((array)$product_ids as $pid) {
             $product_ids_map[(string)$pid] = true;
         }

         $is_uploaded = true;
         if (isset($_POST['mark_uploaded'])) {
             $is_uploaded = (!empty($_POST['mark_uploaded']) && $_POST['mark_uploaded'] !== 'false' && $_POST['mark_uploaded'] !== '0');
         }

         $count = 0;
         $updated_ids = [];
         foreach ($project['products'] as &$p) {
             $pId = (string)($p['id'] ?? '');
             $pSku = (string)($p['sku'] ?? '');
             if (empty($product_ids_map) || isset($product_ids_map[$pId]) || (!empty($pSku) && isset($product_ids_map[$pSku]))) {
                 if (hasProductSizeChart($p)) {
                     $p['size_chart_uploaded'] = $is_uploaded;
                     $p['size_chart_uploaded_at'] = $is_uploaded ? date('Y-m-d H:i:s') : null;
                     $p['manualEdit'] = true;
                     if (function_exists('saveProduct')) {
                         saveProduct($p, $project['id']);
                     }
                     $count++;
                     $updated_ids[] = $pId;
                 }
             }
         }
         if ($count > 0) {
             saveProject($project);
         }
         echo json_encode([
             'success' => true,
             'updated_count' => $count,
             'updated_ids' => $updated_ids,
             'is_uploaded' => $is_uploaded,
             'size_chart_uploaded' => $is_uploaded,
             'message' => $is_uploaded 
                 ? "تعداد $count جدول سایز با موفقیت به عنوان آپلود شده به وب‌سایت ثبت شدند."
                 : "تعداد $count مورد به وضعیت منتظر آپلود بازگردانده شدند."
         ]);
         exit;
     }

     // Mark single or batch products size chart as uploaded
     if ($action === 'td_scs_mark_chart_uploaded') {
         $product_id = trim($_POST['product_id'] ?? '');
         $chart_id = trim($_POST['chart_id'] ?? '');
         $is_uploaded = !isset($_POST['is_uploaded']) || (!empty($_POST['is_uploaded']) && $_POST['is_uploaded'] !== 'false' && $_POST['is_uploaded'] !== '0');
         
         $updated_count = 0;
         $updated_ids = [];
         foreach ($project['products'] as &$p) {
             $pId = (string)($p['id'] ?? '');
             $pSku = (string)($p['sku'] ?? '');
             $pChartId = (string)($p['size_chart_template_id'] ?? '');
             
             $match = false;
             if (!empty($product_id) && ($pId === $product_id || $pSku === $product_id)) {
                 $match = true;
             } elseif (!empty($chart_id) && $pChartId === $chart_id) {
                 $match = true;
             } elseif (empty($product_id) && empty($chart_id)) {
                 if (hasProductSizeChart($p)) {
                     $match = true;
                 }
             }
             
             if ($match) {
                 $p['size_chart_uploaded'] = $is_uploaded;
                 $p['size_chart_uploaded_at'] = $is_uploaded ? date('Y-m-d H:i:s') : null;
                 $p['manualEdit'] = true;
                 if (function_exists('saveProduct')) {
                     saveProduct($p, $project['id']);
                 }
                 $updated_count++;
                 $updated_ids[] = $pId;
             }
         }
         
         if ($updated_count > 0) {
             saveProject($project);
         }
         
         echo json_encode([
             'success' => true,
             'updated_count' => $updated_count,
             'updated_ids' => $updated_ids,
             'size_chart_uploaded' => $is_uploaded,
             'message' => "وضعیت آپلود برای $updated_count محصول با موفقیت ثبت شد."
         ], JSON_UNESCAPED_UNICODE);
         exit;
     }

     // Direct upload size chart image for a product
     if ($action === 'td_scs_direct_upload_chart') {
         $product_id = trim($_POST['product_id'] ?? '');
         if (empty($product_id)) {
             echo json_encode(['success' => false, 'message' => 'شناسه محصول مشخص نشده است.']);
             exit;
         }
         if (empty($_FILES['chart_file']) || $_FILES['chart_file']['error'] != 0) {
             echo json_encode(['success' => false, 'message' => 'خطا در بارگذاری فایل تصویر.']);
             exit;
         }
         $ext = strtolower(pathinfo($_FILES['chart_file']['name'], PATHINFO_EXTENSION));
         if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
             echo json_encode(['success' => false, 'message' => 'فرمت تصویر مجاز نیست (فقط jpg, png, webp).']);
             exit;
         }
         $dir = __DIR__ . '/../uploads/';
         if (!file_exists($dir)) mkdir($dir, 0755, true);
         $fname = 'size_chart_' . time() . '_' . uniqid() . '.' . $ext;
         if (move_uploaded_file($_FILES['chart_file']['tmp_name'], $dir . $fname)) {
             chmod($dir . $fname, 0644);
             $rel = 'uploads/' . $fname;
             $updated = 0;
             foreach ($project['products'] as &$p) {
                 if ($p['id'] === $product_id || (!empty($p['sku']) && $p['sku'] === $product_id)) {
                     $p['size_chart'] = $rel;
                     $p['size_chart_uploaded'] = false;
                     if (isProductNoImage($p)) {
                         $p['status'] = 'pending';
                         $p['waiting_final_photo'] = true;
                     }
                     if (function_exists('saveProduct')) {
                         saveProduct($p, $project['id']);
                     }
                     $updated++;
                 }
             }
             saveProject($project);
             echo json_encode([
                 'success' => true,
                 'image_path' => $rel,
                 'message' => 'تصویر جدول سایز با موفقیت متصل شد و محصول به وضعیت «در انتظار عکس نهایی» منتقل گردید.'
             ]);
             exit;
         }
         echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی فایل روی سرور.']);
         exit;
     }

     // Bulk link chart to multiple products
     if ($action === 'td_scs_bulk_link_chart') {
         $chart_id = trim($_POST['chart_id'] ?? '');
         $product_ids = $_POST['product_ids'] ?? [];
         if (is_string($product_ids)) {
             $product_ids = json_decode($product_ids, true) ?: [];
         }
         if (empty($chart_id) || empty($product_ids)) {
             echo json_encode(['success' => false, 'message' => 'جدول و حداقل یک محصول باید انتخاب شوند.']);
             exit;
         }
         $chartImage = '';
         foreach (($project['settings']['size_charts'] ?? []) as $c) {
             if (($c['id'] ?? '') === $chart_id) {
                 $chartImage = $c['image_path'] ?? '';
                 break;
             }
         }
         $count = 0;
         foreach ($project['products'] as &$p) {
             if (in_array($p['id'], $product_ids) || (!empty($p['sku']) && in_array($p['sku'], $product_ids))) {
                 $p['size_chart_template_id'] = $chart_id;
                 if (!empty($chartImage)) {
                     $p['size_chart'] = $chartImage;
                 }
                 $p['size_chart_uploaded'] = false;
                 if (isProductNoImage($p)) {
                     $p['status'] = 'pending';
                     $p['waiting_final_photo'] = true;
                 }
                 if (function_exists('saveProduct')) {
                     saveProduct($p, $project['id']);
                 }
                 $count++;
             }
         }
         if ($count > 0) {
             saveProject($project);
         }
         echo json_encode(['success' => true, 'updated_count' => $count, 'message' => "تعداد $count محصول به جدول سایز متصل شدند و به وضعیت «در انتظار عکس نهایی» انتقال یافتند."]);
         exit;
     }

     // Multi-assign size chart to selected products (with optional source product / chart)
     if ($action === 'td_scs_multi_assign_chart') {
         $chart_id = trim($_POST['chart_id'] ?? '');
         $source_product_id = trim($_POST['source_product_id'] ?? '');
         $product_ids = $_POST['product_ids'] ?? [];
         $apply_to_variants = !empty($_POST['apply_to_variants']);

         if (is_string($product_ids)) {
             $product_ids = json_decode($product_ids, true) ?: [];
         }

         if (empty($product_ids)) {
             echo json_encode(['success' => false, 'message' => 'حداقل یک محصول برای انتساب باید انتخاب شود.']);
             exit;
         }

         $chart_image_direct = '';
         // If chart_id is empty but source_product_id is provided, look up chart from source product
         if (empty($chart_id) && !empty($source_product_id)) {
             foreach (($project['products'] ?? []) as $prod) {
                 if (($prod['id'] ?? '') === $source_product_id || (!empty($prod['sku']) && $prod['sku'] === $source_product_id)) {
                     $chart_id = $prod['size_chart_template_id'] ?? '';
                     if (!empty($prod['size_chart'])) {
                         $chart_image_direct = $prod['size_chart'];
                     }
                     break;
                 }
             }
         }

         if (empty($chart_id) && empty($chart_image_direct)) {
             echo json_encode(['success' => false, 'message' => 'جدول سایز یا محصول مبدأ انتخاب نشده است یا فاقد جدول سایز می‌باشد.']);
             exit;
         }

         $chartImage = $chart_image_direct;
         if (!empty($chart_id)) {
             foreach (($project['settings']['size_charts'] ?? []) as $c) {
                 if (($c['id'] ?? '') === $chart_id) {
                     if (!empty($c['image_path'])) {
                         $chartImage = $c['image_path'];
                     }
                     break;
                 }
             }
         }

         // Collect target base names if apply_to_variants is enabled
         $target_base_names = [];
         if ($apply_to_variants) {
             foreach ($project['products'] as $p) {
                 if (in_array($p['id'], $product_ids) || (!empty($p['sku']) && in_array($p['sku'], $product_ids))) {
                     if (!empty($p['name'])) {
                         $parts = explode('-', $p['name']);
                         $bName = trim($parts[0]);
                         if (!empty($bName)) {
                             $target_base_names[mb_strtolower($bName)] = true;
                         }
                     }
                 }
             }
         }

         $count = 0;
         $updated_ids = [];
         foreach ($project['products'] as &$p) {
             $match = false;
             if (in_array($p['id'], $product_ids) || (!empty($p['sku']) && in_array($p['sku'], $product_ids))) {
                 $match = true;
             } elseif ($apply_to_variants && !empty($p['name'])) {
                 $parts = explode('-', $p['name']);
                 $bName = trim($parts[0]);
                 if (!empty($bName) && isset($target_base_names[mb_strtolower($bName)])) {
                     $match = true;
                 }
             }

             if ($match) {
                 $p['size_chart_template_id'] = $chart_id;
                 if (!empty($chartImage)) {
                     $p['size_chart'] = $chartImage;
                 }
                 $p['size_chart_uploaded'] = false;
                 unset($p['manual_size_chart_requested']);

                 if (isProductNoImage($p)) {
                     $p['status'] = 'pending';
                     $p['waiting_final_photo'] = true;
                 }

                 $p['manualEdit'] = true;
                 if (function_exists('saveProduct')) {
                     saveProduct($p, $project['id']);
                 }
                 $updated_ids[] = $p['id'];
                 $count++;
             }
         }
         unset($p);

         if ($count > 0) {
             saveProject($project);
         }

         echo json_encode([
             'success' => true,
             'updated_count' => $count,
             'updated_ids' => $updated_ids,
             'chart_id' => $chart_id,
             'chart_image' => $chartImage,
             'message' => "جدول سایز با موفقیت به تعداد $count محصول اساین شد. مشخصات اختصاصی هر محصول محفوظ ماند."
         ], JSON_UNESCAPED_UNICODE);
         exit;
     }

     // Multi-unassign size chart from selected products
     if ($action === 'td_scs_multi_unassign_chart') {
         $product_ids = $_POST['product_ids'] ?? [];
         $apply_to_variants = !empty($_POST['apply_to_variants']);

         if (is_string($product_ids)) {
             $product_ids = json_decode($product_ids, true) ?: [];
         }

         if (empty($product_ids)) {
             echo json_encode(['success' => false, 'message' => 'حداقل یک محصول باید انتخاب شود.']);
             exit;
         }

         $target_base_names = [];
         if ($apply_to_variants) {
             foreach ($project['products'] as $p) {
                 if (in_array($p['id'], $product_ids) || (!empty($p['sku']) && in_array($p['sku'], $product_ids))) {
                     if (!empty($p['name'])) {
                         $parts = explode('-', $p['name']);
                         $bName = trim($parts[0]);
                         if (!empty($bName)) {
                             $target_base_names[mb_strtolower($bName)] = true;
                         }
                     }
                 }
             }
         }

         $count = 0;
         $updated_ids = [];
         foreach ($project['products'] as &$p) {
             $match = false;
             if (in_array($p['id'], $product_ids) || (!empty($p['sku']) && in_array($p['sku'], $product_ids))) {
                 $match = true;
             } elseif ($apply_to_variants && !empty($p['name'])) {
                 $parts = explode('-', $p['name']);
                 $bName = trim($parts[0]);
                 if (!empty($bName) && isset($target_base_names[mb_strtolower($bName)])) {
                     $match = true;
                 }
             }

             if ($match) {
                 $p['size_chart_template_id'] = '';
                 $p['size_chart'] = '';
                 unset($p['size_chart_uploaded']);
                 $p['manualEdit'] = true;
                 if (function_exists('saveProduct')) {
                     saveProduct($p, $project['id']);
                 }
                 $updated_ids[] = $p['id'];
                 $count++;
             }
         }
         unset($p);

         if ($count > 0) {
             saveProject($project);
         }

         echo json_encode([
             'success' => true,
             'updated_count' => $count,
             'updated_ids' => $updated_ids,
             'message' => "انتساب جدول سایز از $count محصول با موفقیت حذف گردید."
         ], JSON_UNESCAPED_UNICODE);
         exit;
     }

     // Delete Size Chart Action
     if ($action === 'td_scs_chart_delete') {
          $chart_id = $_POST['id'] ?? '';
          if (empty($chart_id)) {
              echo json_encode(['success' => false, 'message' => 'شناسه جدول ارسال نشده است.']);
              exit;
          }

          $charts = $project['settings']['size_charts'] ?? [];
          $new_charts = [];
          $found = false;
          foreach ($charts as $c) {
              if (($c['id'] ?? '') === $chart_id) {
                  $found = true;
              } else {
                  $new_charts[] = $c;
              }
          }

          if ($found) {
              // Also unlink from all products
               if (!empty($project['products'])) {
                   foreach ($project['products'] as &$p) {
                       if (($p['size_chart_template_id'] ?? '') === $chart_id) {
                           $p['size_chart_template_id'] = '';
                           $p['size_chart'] = '';
                       }
                   }
               }
               $project['settings']['size_charts'] = $new_charts;
               saveProject($project);
               echo json_encode(['success' => true]);
           } else {
               echo json_encode(['success' => false, 'message' => 'جدول یافت نشد.']);
           }
           exit;
      }

      // Helper function to dynamically map, find or create custom presets based on imported columns
     function getOrCreatePresetFromColumns(&$project, $category, $columns, $legacy_preset_id = '') {
         $presets = $project['settings']['size_chart_presets'] ?? [];
         if (empty($presets)) {
              $presets = [
                  [
                      'id' => 'pants_simple',
                      'name' => 'شلوار (ساده)',
                      'category' => 'شلوار',
                      'columns' => ['سایز محصول','قد شلوار','1/2دور کمر','1/2دور ران','دمپا'],
                      'show_model' => 0,
                      'show_waist' => 0,
                      'illus_url' => '',
                      'icon_url' => '',
                  ],
                  [
                      'id' => 'pants_extended',
                      'name' => 'شلوار/دامن (مدل + نوع کمر)',
                      'category' => 'شلوار',
                      'columns' => ['سایز محصول','قد شلوار/دامن','1/2دور ران','1/2دور کمر','دمپا','مدل محصول','نوع کمر'],
                      'show_model' => 1,
                      'show_waist' => 1,
                      'illus_url' => '',
                      'icon_url' => '',
                  ],
                  [
                      'id' => 'tops_extended',
                      'name' => 'بلوز/تونیک (کامل)',
                      'category' => 'بلوز/تونیک',
                      'columns' => ['سایز محصول','قد محصول','قد آستین','شانه','عرض لباس (آستین سرخود)','عرض دمپا (آستین سرخود)','1/2دورسینه','مدل محصول'],
                      'show_model' => 1,
                      'show_waist' => 0,
                      'illus_url' => '',
                      'icon_url' => '',
                  ],
              ];
         }

         $norm_cols = array_map('mb_strtolower', array_map('trim', $columns));

         // 1. Try to find by ID
         if (!empty($legacy_preset_id)) {
             foreach ($presets as $p) {
                 if (($p['id'] ?? '') === $legacy_preset_id) {
                     return $p['id'];
                 }
             }
         }

         // 2. Try to find by identical columns
         foreach ($presets as $p) {
             $p_cols = array_map('mb_strtolower', array_map('trim', $p['columns'] ?? []));
             if ($p_cols === $norm_cols) {
                 return $p['id'];
             }
         }

         // 3. Create active new preset
         $new_preset_id = !empty($legacy_preset_id) ? $legacy_preset_id : ('p_auto_' . uniqid());
         $preset_name = 'قالب خودکار ' . $category . ' (' . count($columns) . ' ستونه)';
         
         $show_model = 0;
         $show_waist = 0;
         foreach ($columns as $c) {
             if (mb_strpos($c, 'مدل') !== false || mb_strpos($c, 'شانه') !== false || mb_strpos($c, 'آستین') !== false || mb_strpos($c, 'سرشانه') !== false) {
                 $show_model = 1;
             }
             if (mb_strpos($c, 'کمر') !== false || mb_strpos($c, 'فاق') !== false || mb_strpos($c, 'باسن') !== false) {
                 $show_waist = 1;
             }
         }

         $new_preset = [
             'id' => $new_preset_id,
             'name' => $preset_name,
             'category' => $category,
             'columns' => $columns,
             'show_model' => $show_model,
             'show_waist' => $show_waist,
             'illus_url' => '',
             'icon_url' => '',
         ];

         $presets[] = $new_preset;
         $project['settings']['size_chart_presets'] = $presets;
         
         return $new_preset_id;
     }

     // Helper function to retrieve or build the standard specification description presets
     function getOrCreateDefaultDescPreset(&$project) {
         $desc_presets = $project['settings']['size_chart_desc_presets'] ?? [];
         foreach ($desc_presets as $dp) {
             if (($dp['title'] ?? '') === 'پارامترهای فنی کالا' || ($dp['id'] ?? '') === 'dp_import_standard') {
                 return $dp['id'];
              }
          }

          $id = 'dp_import_standard';
          $new_dp = [
              'id' => $id,
              'title' => 'پارامترهای فنی کالا',
              'fields' => [
                  ['key' => 'fabric', 'label' => 'جنس پارچه'],
                  ['key' => 'description', 'label' => 'مشخصات فرعی / جزئیات']
              ]
          ];
          $desc_presets[] = $new_dp;
          $project['settings']['size_chart_desc_presets'] = $desc_presets;
          return $id;
      }

      // Bulk Import Size Charts via JSON from Previous System
      if ($action === 'td_scs_import_charts_json') {
           $json_data = $_POST['json_data'] ?? '';
           $data = json_decode($json_data, true);

           if (!is_array($data)) {
               echo json_encode(['success' => false, 'message' => 'فرمت JSON ارسالی نامعتبر است. باید یک آرایه معتبر باشد.']);
               exit;
           }

           $charts = $project['settings']['size_charts'] ?? [];
           $imported_count = 0;

           // Dictionary maps dynamic legacy hex-keys to standard property keys
           $legacy_desc_map = [
               'k794b5e955da9d19c8e4fb834' => 'fabric',
               'k04c4d133820f619b98d9369c' => 'fabric',
               'k4a9ede810a19a19c6b4e0876' => 'fabric',
               'k4fe97f1beb895819c8e5fd88b' => 'description',
               'k53d9b1430f31a19b98d93973' => 'description',
               'k7bf638eafb217819c6b4e7389' => 'description',
           ];

           foreach ($data as $item) {
               $product_desc = trim($item['product_desc'] ?? $item['title'] ?? '');
               if (empty($product_desc)) {
                   continue;
               }

               $title = trim($item['title'] ?? $product_desc);
               $category = trim($item['category'] ?? 'عمومی');
               $legacy_preset_id = trim($item['preset_id'] ?? '');
               $model = trim($item['model'] ?? '');
               $waist_type = trim($item['waist_type'] ?? '');
               $image_path = trim($item['image_path'] ?? '');
               $rendered_url = trim($item['rendered_image_data_url'] ?? $item['image_data'] ?? '');
               if (empty($image_path) && !empty($rendered_url) && strpos($rendered_url, 'data:image') === 0) {
                   $img_clean = preg_replace('/^data:image\/(jpeg|jpg|png);base64,/', '', $rendered_url);
                   $img_clean = str_replace(' ', '+', $img_clean);
                   $decoded = base64_decode($img_clean);
                   if ($decoded) {
                       $dir = __DIR__ . '/../uploads/';
                       if (!file_exists($dir)) {
                           mkdir($dir, 0755, true);
                       }
                       $fn = 'psizechart_b_' . time() . '_' . uniqid() . '.jpg';
                       file_put_contents($dir . $fn, $decoded);
                       chmod($dir . $fn, 0644);
                       $image_path = 'uploads/' . $fn;
                   }
               }
               $product_notes = trim($item['product_notes'] ?? '');

               // Unpack columns safely
               $columns = $item['columns'] ?? [];
               if (is_string($columns)) {
                   $columns = json_decode($columns, true) ?: [];
               }
               if (empty($columns) || !is_array($columns)) {
                   // Fallback list of default columns if completely omitted
                   $columns = ['سایز محصول', 'قد محصول', 'قد آستین', 'عرض شانه', 'دورسینه'];
               }

               // 1. Resolve preset mapping and create as needed
               $preset_id = getOrCreatePresetFromColumns($project, $category, $columns, $legacy_preset_id);

               // 2. Map legacy description key-value hashes
               $desc_values = $item['desc_values'] ?? [];
               if (is_string($desc_values)) {
                   $desc_values = json_decode($desc_values, true) ?: [];
               }
               $mapped_desc_values = [];
               if (is_array($desc_values)) {
                   foreach ($desc_values as $k => $v) {
                       $mapped_key = $legacy_desc_map[$k] ?? $k;
                       $mapped_desc_values[$mapped_key] = trim((string)$v);
                   }
               }

               // Apply standard sub-specification desc preset if specifications values are provided
               if (!empty($mapped_desc_values)) {
                   $desc_preset_id = getOrCreateDefaultDescPreset($project);
               } else {
                   $desc_preset_id = '';
               }

               // 3. Structure rows properly, crop/pad to clean column counts
               $rows = $item['rows'] ?? [];
               if (is_string($rows)) {
                   $rows = json_decode($rows, true) ?: [];
               }
               $cleaned_rows = [];
               $countColumns = count($columns);
               if (is_array($rows)) {
                   foreach ($rows as $row) {
                       $new_row = [];
                       for ($i = 0; $i < $countColumns; $i++) {
                           $colName = $columns[$i] ?? '';
                           $cell_val = $row['c' . $i] ?? ($row[$colName] ?? ($row[$i] ?? ($row['values'][$colName] ?? ($i === 0 ? ($row['size'] ?? '') : ''))));
                           if (is_array($cell_val)) {
                               $cell_val = implode(' ', $cell_val);
                           }
                           $new_row['c' . $i] = trim((string)$cell_val);
                       }
                       $cleaned_rows[] = $new_row;
                   }
               }

               // Check if size chart with same title or product description already exists to update instead of insert
               $existing_index = -1;
               foreach ($charts as $idx => $c) {
                   $ex_title = trim($c['title'] ?? '');
                   $ex_desc = trim($c['product_desc'] ?? '');
                   if ((!empty($title) && mb_strtolower($ex_title) === mb_strtolower($title)) ||
                       (!empty($product_desc) && mb_strtolower($ex_desc) === mb_strtolower($product_desc))) {
                       $existing_index = $idx;
                       break;
                   }
               }

               if ($existing_index !== -1) {
                   $existing_chart = $charts[$existing_index];
                   $charts[$existing_index] = [
                       'id' => $existing_chart['id'],
                       'title' => $title,
                       'product_desc' => $product_desc,
                       'category' => $category,
                       'preset_id' => $preset_id,
                       'model' => $model,
                       'waist_type' => $waist_type,
                       'desc_preset_id' => $desc_preset_id,
                       'desc_values' => $mapped_desc_values,
                       'columns' => $columns,
                       'rows' => $cleaned_rows,
                       'product_notes' => !empty($product_notes) ? $product_notes : ($existing_chart['product_notes'] ?? ''),
                       'image_path' => !empty($image_path) ? $image_path : ($existing_chart['image_path'] ?? '')
                   ];
               } else {
                   $new_id = 'c_' . uniqid();
                   $charts[] = [
                       'id' => $new_id,
                       'title' => $title,
                       'product_desc' => $product_desc,
                       'category' => $category,
                       'preset_id' => $preset_id,
                       'model' => $model,
                       'waist_type' => $waist_type,
                       'desc_preset_id' => $desc_preset_id,
                       'desc_values' => $mapped_desc_values,
                       'columns' => $columns,
                       'rows' => $cleaned_rows,
                       'product_notes' => $product_notes,
                       'image_path' => $image_path
                   ];
               }
               $imported_count++;
          }

          $project['settings']['size_charts'] = $charts;

          // Auto-match imported charts to products and transition status to waiting_final_photo
          $matched_products_count = 0;
          foreach ($charts as $ch) {
              $chId = $ch['id'] ?? '';
              $chTitle = trim($ch['title'] ?? '');
              $chDesc = trim($ch['product_desc'] ?? '');
              $chImg = $ch['image_path'] ?? '';
              if (empty($chId) || empty($chTitle)) continue;

              foreach ($project['products'] as &$p) {
                  $pName = trim($p['name'] ?? '');
                  $pSku = trim($p['sku'] ?? '');
                  $match = false;
                  if (!empty($pSku) && ($pSku === $chTitle || $pSku === $chDesc || mb_stripos($chTitle, $pSku) !== false)) {
                      $match = true;
                  } elseif (!empty($pName) && ($pName === $chTitle || mb_stripos($pName, $chTitle) === 0 || mb_stripos($chTitle, $pName) === 0)) {
                      $match = true;
                  }

                  if ($match) {
                      $p['size_chart_template_id'] = $chId;
                      if (!empty($chImg)) {
                          $p['size_chart'] = $chImg;
                          $p['size_chart_uploaded'] = true;
                          $p['size_chart_uploaded_at'] = date('Y-m-d H:i:s');
                      } else {
                          $p['size_chart_uploaded'] = false;
                      }
                      $p['manualEdit'] = true;
                      if (isProductNoImage($p)) {
                          $p['status'] = 'pending';
                          $p['waiting_final_photo'] = true;
                      }
                      if (function_exists('saveProduct')) {
                          saveProduct($p, $project['id']);
                      }
                      $matched_products_count++;
                  }
              }
          }

          saveProject($project);

          echo json_encode([
              'success' => true, 
              'imported_count' => $imported_count,
              'matched_products_count' => $matched_products_count,
              'message' => "تعداد $imported_count جدول سایز با موفقیت ساخته/به‌روزرسانی شد" . ($matched_products_count > 0 ? " و $matched_products_count محصول به وضعیت «در انتظار عکس نهایی» منتقل گردید." : ".")
          ], JSON_UNESCAPED_UNICODE);
          exit;
     }

    
    // File handler for standard upload
    if ($action === 'td_scs_illus_upload' || $action === 'td_scs_icon_upload' || $action === 'td_scs_bg_upload' || $action === 'td_scs_logo_upload') {
         if (!empty($_FILES['file']) && $_FILES['file']['error'] == 0) {
              $dir = __DIR__ . '/../uploads/';
              if (!file_exists($dir)) {
                  mkdir($dir, 0755, true);
              }
              $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
              $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
              if (!in_array($ext, $allowed_exts)) {
                  $ext = 'jpg';
              }
              $filename = 'scs_asset_' . time() . '_' . uniqid() . '.' . $ext;
              if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) {
                   chmod($dir . $filename, 0644);
                   echo json_encode(['success' => true, 'data' => ['url' => 'uploads/' . $filename, 'id' => time()]], JSON_UNESCAPED_UNICODE);
                   exit;
              }
         }
         echo json_encode(['success' => false, 'message' => 'ارسال فایل با خطا مواجه شد.']);
         exit;
    }

    // Custom Font File Upload Handler
    if ($action === 'td_scs_font_upload') {
         if (!empty($_FILES['file']) && $_FILES['file']['error'] == 0) {
              $dir = __DIR__ . '/../uploads/fonts/';
              if (!file_exists($dir)) {
                  mkdir($dir, 0755, true);
              }
              $origName = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
              $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
              $allowed_exts = ['ttf', 'otf', 'woff', 'woff2', 'eot'];
              if (!in_array($ext, $allowed_exts)) {
                  echo json_encode(['success' => false, 'message' => 'فرمت فونت مجاز نیست. لطفاً فرمت‌های ttf، otf، woff یا woff2 را انتخاب کنید.']);
                  exit;
              }
              $safeFontName = preg_replace('/[^a-zA-Z0-9_-]/', '', $origName) ?: 'CustomFont';
              $filename = 'font_' . time() . '_' . $safeFontName . '.' . $ext;
              if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) {
                   chmod($dir . $filename, 0644);
                   echo json_encode([
                       'success' => true, 
                       'data' => [
                           'url' => 'uploads/fonts/' . $filename, 
                           'font_name' => $safeFontName,
                           'format' => $ext
                       ]
                   ], JSON_UNESCAPED_UNICODE);
                   exit;
              }
         }
         echo json_encode(['success' => false, 'message' => 'آپلود فایل فونت با خطا مواجه شد.']);
         exit;
    }
    
    // Fallback error
    echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر است.']);
    exit;
}

// Ensure default presets are available
$active_presets = $project['settings']['size_chart_presets'] ?? [];
if (empty($active_presets)) {
    $active_presets = [
        [
            'id' => 'pants_simple',
            'name' => 'شلوار (ساده)',
            'category' => 'شلوار',
            'columns' => ['سایز محصول','قد شلوار','1/2دور کمر','1/2دور ران','دمپا'],
            'show_model' => 0,
            'show_waist' => 0,
            'illus_url' => '',
            'icon_url' => '',
        ],
        [
            'id' => 'pants_extended',
            'name' => 'شلوار/دامن (مدل + نوع کمر)',
            'category' => 'شلوار',
            'columns' => ['سایز محصول','قد شلوار/دامن','1/2دور ران','1/2دور کمر','دمپا','مدل محصول','نوع کمر'],
            'show_model' => 1,
            'show_waist' => 1,
            'illus_url' => '',
            'icon_url' => '',
        ],
        [
            'id' => 'tops_extended',
            'name' => 'بلوز/تونیک (کامل)',
            'category' => 'بلوز/تونیک',
            'columns' => ['سایز محصول','قد محصول','قد آستین','شانه','عرض لباس (آستین سرخود)','عرض دمپا (آستین سرخود)','1/2دورسینه','مدل محصول'],
            'show_model' => 1,
            'show_waist' => 0,
            'illus_url' => '',
            'icon_url' => '',
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Size Chart Studio | استودیو هوشمند طراحی جداول سایزبندی</title>
    <script>
        // Suppress Tailwind production warning in console
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

        // Early global definition of template download functions
        window.downloadSampleExcelTemplate = function() {
            if (typeof window._realDownloadSampleExcelTemplate === 'function') {
                return window._realDownloadSampleExcelTemplate();
            }
            console.warn('Excel template generator still initializing, please wait a moment...');
        };
        window.downloadSampleJsonTemplate = function() {
            if (typeof window._realDownloadSampleJsonTemplate === 'function') {
                return window._realDownloadSampleJsonTemplate();
            }
            console.warn('JSON template generator still initializing, please wait a moment...');
        };
        window.downloadSampleExcel = window.downloadSampleExcelTemplate;
        window.downloadSampleJSON = window.downloadSampleJsonTemplate;
        window.downloadSampleJson = window.downloadSampleJsonTemplate;
    </script>
    <!-- TailWind CSS (Local & Syntax Error Fixed) -->
    <script src="../assets/tailwindcss.js"></script>
    <!-- Local Core Libraries with Fallback -->
    <script src="../assets/jquery.min.js"></script>
    <script>window.jQuery || document.write('<script src="https://code.jquery.com/jquery-3.6.0.min.js"><\/script>')</script>
    <script src="../assets/html2canvas.min.js"></script>
    <script>window.html2canvas || document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"><\/script>')</script>
    <script src="../assets/xlsx.full.min.js"></script>
    <script>window.XLSX || document.write('<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"><\/script>')</script>
    <script src="../assets/jszip.min.js"></script>
    <script>window.JSZip || document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"><\/script>')</script>
    <script src="../assets/FileSaver.min.js"></script>
    <script>window.saveAs || document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"><\/script>')</script>
    
    <style id="scs-custom-font-style">
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700;800;900&display=swap');
        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn'), local('Vazir'), url('/assets/fonts/Vazirmatn-Regular.woff2') format('woff2'), url('/panel/assets/fonts/Vazirmatn-Regular.woff2') format('woff2'), url('../assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn-Bold'), local('Vazir-Bold'), url('/assets/fonts/Vazirmatn-Bold.woff2') format('woff2'), url('/panel/assets/fonts/Vazirmatn-Bold.woff2') format('woff2'), url('../assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
            font-weight: bold;
            font-style: normal;
            font-display: swap;
        }
        body {
            font-family: 'Vazirmatn', 'Tahoma', 'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif !important;
            background-color: #f8fafc;
        }
        /* Ensure html2canvas rendering has correct direction and layout fidelity */
        #td-preview, #scs-batch-canvas {
            direction: rtl;
            text-align: right;
            box-sizing: border-box;
            transform-origin: top center;
        }
        .scs-canvas * {
            box-sizing: border-box;
        }
        /* Custom scrollbar for preview area */
        .preview-scroll-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .preview-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 8px;
        }
        .preview-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }
        .preview-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Freeform Photoshop/Elementor Drag-and-Drop Canvas Styles */
        .scs-canvas-grid-active {
            background-image: linear-gradient(to right, rgba(99, 102, 241, 0.08) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(99, 102, 241, 0.08) 1px, transparent 1px) !important;
            background-size: 20px 20px !important;
        }
        .scs-freeform-block {
            position: absolute !important;
            cursor: grab;
            user-select: none;
            touch-action: none;
            box-sizing: border-box;
            transition: box-shadow 0.15s, outline 0.15s !important;
        }
        .scs-freeform-block:hover {
            outline: 1.5px dashed rgba(99, 102, 241, 0.6) !important;
            outline-offset: 2px !important;
        }
        .scs-freeform-block:active,
        .scs-freeform-block.is-dragging {
            cursor: grabbing !important;
            transition: none !important;
            opacity: 0.95;
        }
        .scs-freeform-block.is-resizing {
            transition: none !important;
        }
        .scs-freeform-block.is-selected {
            outline: 2px solid #4f46e5 !important;
            outline-offset: 3px !important;
            box-shadow: 0 0 0 5px rgba(79, 70, 229, 0.25), 0 12px 24px -6px rgba(0, 0, 0, 0.2) !important;
            z-index: 999 !important;
        }
        .scs-resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #ffffff;
            border: 2px solid #4f46e5;
            border-radius: 2px;
            z-index: 1001;
            display: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            box-sizing: border-box;
        }
        .scs-freeform-block.is-selected .scs-resize-handle {
            display: block;
        }
        .scs-handle-nw { top: -5px; left: -5px; cursor: nwse-resize; }
        .scs-handle-n  { top: -5px; left: calc(50% - 5px); cursor: ns-resize; }
        .scs-handle-ne { top: -5px; right: -5px; cursor: nesw-resize; }
        .scs-handle-w  { top: calc(50% - 5px); left: -5px; cursor: ew-resize; }
        .scs-handle-e  { top: calc(50% - 5px); right: -5px; cursor: ew-resize; }
        .scs-handle-sw { bottom: -5px; left: -5px; cursor: nesw-resize; }
        .scs-handle-s  { bottom: -5px; left: calc(50% - 5px); cursor: ns-resize; }
        .scs-handle-se { bottom: -5px; right: -5px; cursor: nwse-resize; }

        /* Floating Element Toolbar */
        .scs-quick-toolbar {
            position: absolute;
            bottom: calc(100% + 8px);
            right: 0;
            background: #1e1b4b;
            color: #ffffff;
            border-radius: 10px;
            padding: 4px 10px;
            display: none;
            align-items: center;
            gap: 8px;
            font-size: 10px;
            font-weight: 800;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.4);
            z-index: 1002;
            white-space: nowrap;
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
        }
        .scs-freeform-block.is-selected .scs-quick-toolbar {
            display: flex;
        }

        /* Freeform Split Cell & Matrix Styles */
        .scs-split-cell-block {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: move;
            box-sizing: border-box !important;
            user-select: none;
            transition: outline 0.15s, box-shadow 0.15s;
        }
        .scs-split-cell-block:hover {
            outline: 1.5px dashed #8b5cf6 !important;
        }
        .scs-split-cell-block.is-selected {
            outline: 2px solid #8b5cf6 !important;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.25) !important;
            z-index: 1000 !important;
        }
        .scs-coord-badge {
            position: absolute;
            top: -16px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 8px;
            font-family: monospace;
            font-weight: 900;
            background: rgba(30, 27, 75, 0.9);
            color: #a7f3d0;
            padding: 0 4px;
            border-radius: 4px;
            pointer-events: none;
            white-space: nowrap;
            z-index: 1010;
            display: none;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .scs-show-coord-badges .scs-coord-badge {
            display: block !important;
        }

        /* Dynamic Size Chart Card & Table Auto-Scale Styles */
        .scs-dynamic-table-card:not(.hidden) {
            display: block !important;
            box-sizing: border-box !important;
            border-radius: 1.25rem !important;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.03) !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .scs-dynamic-table-card.hidden,
        #preview-table-card.hidden,
        #bp-table-card.hidden,
        .scs-table-split-active #preview-table-card,
        .scs-table-split-active #bp-table-card,
        .scs-table-split-active [data-scs-block="table"] {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        .scs-canvas #preview-table-container,
        .scs-dynamic-table-card #preview-table-container,
        #preview-table-card #preview-table-container,
        #bp-table-card #preview-table-container {
            display: block !important;
            width: 100% !important;
            overflow-x: auto !important;
            box-sizing: border-box !important;
        }
        .scs-canvas table,
        .scs-dynamic-table-card table,
        #preview-table-card table,
        #preview-table-container table,
        #bp-table-card table,
        #scs-batch-canvas table {
            display: table !important;
            width: 100% !important;
            table-layout: auto !important;
            margin: 0 !important;
            letter-spacing: 0 !important;
            font-feature-settings: normal !important;
            box-sizing: border-box !important;
            direction: rtl !important;
        }
        .scs-canvas thead,
        .scs-dynamic-table-card thead,
        #preview-table-card thead,
        #preview-table-container thead,
        #scs-batch-canvas thead {
            display: table-header-group !important;
            width: 100% !important;
        }
        .scs-canvas tbody,
        .scs-dynamic-table-card tbody,
        #preview-table-card tbody,
        #preview-table-container tbody,
        #scs-batch-canvas tbody {
            display: table-row-group !important;
            width: 100% !important;
        }
        .scs-canvas tr,
        .scs-dynamic-table-card tr,
        #preview-table-card tr,
        #preview-table-container tr,
        #scs-batch-canvas tr {
            display: table-row !important;
        }
        .scs-canvas thead th,
        .scs-dynamic-table-card thead th,
        #preview-table-card thead th,
        #preview-table-container thead th,
        #scs-batch-canvas thead th {
            display: table-cell !important;
            vertical-align: middle !important;
            text-align: center !important;
            box-sizing: border-box !important;
            font-weight: 800 !important;
            word-break: keep-all !important;
            white-space: nowrap !important;
            letter-spacing: 0 !important;
            font-feature-settings: normal !important;
            line-height: 1.35 !important;
            overflow-wrap: normal !important;
        }
        .scs-canvas tbody tr,
        .scs-dynamic-table-card tbody tr,
        #preview-table-card tbody tr,
        #preview-table-container tbody tr,
        #scs-batch-canvas tbody tr {
            box-sizing: border-box !important;
            transition: background-color 0.15s ease !important;
        }
        .scs-canvas tbody td,
        .scs-dynamic-table-card tbody td,
        #preview-table-card tbody td,
        #preview-table-container tbody td,
        #scs-batch-canvas tbody td {
            display: table-cell !important;
            vertical-align: middle !important;
            text-align: center !important;
            box-sizing: border-box !important;
            font-weight: 700 !important;
            word-break: keep-all !important;
            white-space: nowrap !important;
            letter-spacing: 0 !important;
            font-feature-settings: normal !important;
            line-height: 1.35 !important;
        }
        .scs-canvas tbody td:first-child,
        .scs-canvas thead th:first-child,
        .scs-dynamic-table-card tbody td:first-child,
        .scs-dynamic-table-card thead th:first-child,
        #preview-table-card tbody td:first-child,
        #preview-table-card thead th:first-child,
        #scs-batch-canvas tbody td:first-child,
        #scs-batch-canvas thead th:first-child {
            font-weight: 900 !important;
        }
        .scs-canvas tbody tr:nth-child(even),
        .scs-dynamic-table-card tbody tr:nth-child(even),
        #preview-table-card tbody tr:nth-child(even),
        #preview-table-container tbody tr:nth-child(even),
        #scs-batch-canvas tbody tr:nth-child(even) {
            background-color: rgba(248, 250, 252, 0.85) !important;
        }

        /* 2-Column Split Layout Engine & Grid Wrappers */
        .scs-grid-wrapper {
            box-sizing: border-box !important;
            width: 100% !important;
        }
        .scs-split-grid,
        .scs-batch-grid-split {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
            justify-content: space-between !important;
            width: 100% !important;
            gap: 1rem !important;
            box-sizing: border-box !important;
        }
        .scs-split-grid > #preview-table-card,
        .scs-split-grid > #bp-table-card {
            flex: 8 1 0% !important;
            width: 66.6666% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .scs-split-grid > #preview-illus-card,
        .scs-split-grid > #bp-illus-card {
            flex: 4 1 0% !important;
            width: 33.3333% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .scs-batch-grid-split > div:first-child {
            flex: 8 1 0% !important;
            width: 66.6666% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .scs-batch-grid-split > div:last-child {
            flex: 4 1 0% !important;
            width: 33.3333% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .scs-batch-grid-split #preview-table-card,
        .scs-batch-grid-split #preview-illus-card {
            width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .scs-stacked-grid {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            gap: 1rem !important;
            box-sizing: border-box !important;
        }
        .scs-stacked-grid > #preview-table-card,
        .scs-stacked-grid > #preview-illus-card,
        .scs-stacked-grid > #bp-table-card,
        .scs-stacked-grid > #bp-illus-card {
            flex: 1 1 auto !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Smart Alignment Guides */
        .scs-snap-guide-x, .scs-snap-guide-y {
            position: absolute;
            pointer-events: none;
            z-index: 998;
            display: none;
        }
        .scs-snap-guide-x {
            top: 0;
            bottom: 0;
            width: 1px;
            border-left: 1.5px dashed #a855f7;
        }
        .scs-snap-guide-y {
            left: 0;
            right: 0;
            height: 1px;
            border-top: 1.5px dashed #a855f7;
        }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen">

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Main Top Bar -->
        <header class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 flex flex-col lg:flex-row justify-between items-center gap-4 mb-8">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-indigo-50 rounded-2xl flex items-center justify-center text-indigo-600 font-black text-2xl shadow-inner">
                    📐
                </div>
                <div>
                    <h1 class="text-lg font-black text-slate-900 leading-tight">Size Chart Studio</h1>
                    <p class="text-xs text-slate-400 mt-1">مدیریت، پیکربندی، استانداردسازی و خروجی هوشمند تصاویر جداول سایز محصولات</p>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
                <div class="flex items-center gap-2 w-full sm:w-auto bg-slate-50 border border-slate-105 px-3 py-1.5 rounded-2xl">
                    <span class="text-xs font-bold text-slate-400 whitespace-nowrap">📂 پروژه فعال:</span>
                    <select onchange="window.location.href='?switch_project=' + this.value" class="w-full sm:w-48 text-xs font-black bg-transparent outline-none cursor-pointer focus:ring-0 text-indigo-700">
                        <?php foreach ($allProjects as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['id']); ?>" <?php echo $p['id'] === $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a href="../admin.php" class="w-full sm:w-auto text-center px-5 py-2.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-100/30 text-indigo-700 text-xs font-black rounded-xl transition-all shadow-sm whitespace-nowrap">
                    ← بازگشت به پنل اصلی
                </a>
            </div>
        </header>

        <!-- Studio Main Tabs -->
        <div class="bg-white rounded-3xl p-4 shadow-sm border border-slate-100 flex flex-wrap gap-2 mb-8">
            <button onclick="switchTab('charts')" id="btn-tab-charts" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all bg-indigo-600 text-white shadow-md shadow-indigo-600/10">
                ⭐ مدیریت جداول سایزبندی
            </button>
            <button onclick="switchTab('missing_charts')" id="btn-tab-missing_charts" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-amber-700 bg-amber-50 hover:bg-amber-100/80 flex items-center gap-2 border border-amber-200/80 shadow-xs">
                <span class="text-amber-600 font-black text-sm">📐</span>
                <span>منتظر جدول سایز (محصولات بدون جدول)</span>
                <span id="badge-missing-charts-count" class="px-2 py-0.5 bg-amber-200 text-amber-900 rounded-full text-[10px] font-black"><?php echo $missingChartsCount; ?></span>
            </button>
            <button onclick="switchTab('pending_upload')" id="btn-tab-pending_upload" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-indigo-700 bg-indigo-50 hover:bg-indigo-100/80 flex items-center gap-2 border border-indigo-200/80 shadow-xs">
                <span class="text-indigo-600 font-black text-sm">☁️</span>
                <span>منتظر آپلود جدول سایز</span>
                <span id="badge-pending-upload-count" class="px-2 py-0.5 bg-indigo-200 text-indigo-900 rounded-full text-[10px] font-black"><?php echo $pendingUploadChartsCount; ?></span>
            </button>
            <button onclick="switchTab('quick_batch')" id="btn-tab-quick_batch" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-slate-500 hover:bg-slate-50 flex items-center gap-1.5">
                <span class="text-amber-500 font-black text-sm">⚡</span>
                <span>ساخت جدول سایز سریع (اکسل / JSON)</span>
            </button>
            <button onclick="switchTab('multi_assign')" id="btn-tab-multi_assign" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-indigo-700 bg-indigo-50 hover:bg-indigo-100/80 flex items-center gap-2 border border-indigo-200/80 shadow-xs">
                <span class="text-indigo-600 font-black text-sm">🎯</span>
                <span>اساین گروهی جدول سایز به محصولات</span>
                <span class="px-2 py-0.5 bg-indigo-200 text-indigo-900 rounded-full text-[10px] font-black">جدید</span>
            </button>
            <button onclick="switchTab('presets')" id="btn-tab-presets" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-slate-500 hover:bg-slate-50">
                ⚙️ استانداردسازی دسته‌بندی و توضیحات (Presets)
            </button>
            <button onclick="switchTab('branding')" id="btn-tab-branding" class="tab-btn px-5 py-3 rounded-2xl text-xs font-black transition-all text-slate-500 hover:bg-slate-50">
                🎨 تنظیمات برند و تصویر (Branding)
            </button>
        </div>

        <!-- Tab 1: Size Charts Managing Studio -->
        <div id="tabcontent-charts" class="tab-pane grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Sidebar list -->
            <aside class="lg:col-span-4 bg-white rounded-3xl p-6 shadow-sm border border-slate-100 flex flex-col gap-5 h-fit">
                <div class="flex justify-between items-center pb-2 border-b border-slate-100">
                    <h3 class="text-sm font-black text-slate-900">لیست جداول طراحی شده</h3>
                    <div class="flex gap-1.5">
                        <button onclick="$('#import-json-modal').removeClass('hidden')" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-[9.5px] font-black rounded-lg transition-all shadow-sm" title="ایمپورت گروهی جداول از فایل یا JSON">
                            📥 ایمپورت
                        </button>
                        <button onclick="exportAllChartsJSON()" class="px-2 py-1 bg-violet-600 hover:bg-violet-700 text-white text-[9.5px] font-black rounded-lg transition-all shadow-sm" title="دریافت فایل پشتیبان (خروجی گروهی جداول) کل پروژه">
                            📤 اکسپورت
                        </button>
                        <button onclick="initNewChart()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-black rounded-lg transition-all shadow-sm">
                            + جدید
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">فیلتر بر اساس دسته‌بندی</label>
                    <select id="filter-category" onchange="loadChartsList()" class="w-full text-xs font-bold bg-slate-50 border border-slate-200 p-3 rounded-xl outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">همه دسته‌بندی‌ها</option>
                    </select>
                </div>

                <div id="charts-list-container" class="space-y-2 max-h-[400px] overflow-y-auto pr-1">
                    <!-- Loaded dynamically -->
                    <p class="text-xs text-slate-400 text-center py-6">درحال بارگذاری جداول...</p>
                </div>

                <!-- Custom Advanced Reset Utilities -->
                <div class="pt-4 border-t border-slate-100 space-y-2.5">
                    <span class="text-[10px] font-black text-slate-400 block pb-0.5">⚠️ ابزارهای بازنشانی و ریست کلی:</span>
                    <div class="grid grid-cols-2 gap-2.5">
                        <button onclick="confirmResetAllCharts()" class="w-full text-center py-2.5 px-3 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200/40 rounded-xl text-[10px] font-black transition-all" title="ریست کامل جداول سایز">
                            🗑️ حذف همگی جداول
                        </button>
                        <button onclick="confirmResetAllPresets()" class="w-full text-center py-2.5 px-3 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200/40 rounded-xl text-[10px] font-black transition-all" title="ریست کامل قالب‌های استاندارد">
                            🔄 ریست استانداردهای فیلد
                        </button>
                    </div>
                </div>
            </aside>

            <!-- Main Editor -->
            <main class="lg:col-span-8 space-y-8">
                <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100 relative">
                    <!-- Current State indicator -->
                    <div class="flex justify-between items-center mb-6 border-b border-slate-150 pb-4">
                        <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
                            <span>✏️</span>
                            <span id="editor-title-text">در حال ایجاد جدول جدید</span>
                        </h2>
                        <input type="hidden" id="editor-chart-id" value="">
                    </div>

                    <!-- Auto-link Approved Product Section -->
                    <div class="space-y-2 mb-6 bg-slate-50 border border-slate-200/80 p-5 rounded-2xl">
                        <label class="text-[10.5px] font-black text-slate-600 block flex items-center gap-1.5">
                            <span>🔗 اتصال سریع به یکی از محصولات تایید شده پروژه</span>
                            <span class="text-[9px] bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 px-2 py-0.5 rounded-full font-black">جدید و یکپارچه</span>
                        </label>
                        
                        <!-- Search Bar for Products -->
                        <div class="relative">
                            <input type="text" id="chart-linked-product-search" oninput="populateApprovedProductsDropdown(this.value)" placeholder="🔍 جستجو در نام محصول یا کد کالا (SKU)..." class="w-full text-xs font-bold border border-slate-200 bg-white p-2.5 pr-8 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/10 mb-2" title="برای جستجوی سریع محصول، اینجا تایپ کنید">
                        </div>

                        <select id="chart-linked-product-select" onchange="onLinkedProductChange(this.value)" class="w-full text-xs font-bold border border-slate-200 bg-white p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/10">
                            <option value="">-- عدم اتصال خودکار به محصول --</option>
                        </select>
                        <p class="text-[10px] text-slate-400 font-bold leading-relaxed mt-1">
                            💡 با انتخاب محصول از این منو، اطلاعات شرح محصول به طور خودکار کامل شده و پس از ذخیره نهایی جدول سایز، این سند به طور آنلاین به کل رنگ‌بندی‌های فرعی آن کالای تایید شده پیوند داده خواهد شد.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase block">عنوان داخلی جدول (جهت انتخاب در پنل)</label>
                            <input type="text" id="chart-title" placeholder="مثلا: شلوار بهاره کتان یاراش" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-indigo-700 uppercase block">شرح و نام محصول در کادر خروجی تصویر *</label>
                            <input type="text" id="chart-product-desc" oninput="updateLivePreview()" placeholder="مثلا: شلوار یاراش" class="w-full text-xs font-bold border border-indigo-200 p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase block font-black text-slate-500">انتخاب پریست و ستون‌های استاندارد *</label>
                            <select id="chart-preset-select" onchange="applyPresetTemplate(this.value)" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">-- انتخاب کنید --</option>
                            </select>
                        </div>
                        <div class="space-y-2 edit-field-model">
                            <label class="text-[10px] font-black text-slate-400 uppercase block">مدل محصول (اختیاری)</label>
                            <input type="text" id="chart-model" oninput="updateLivePreview()" placeholder="مثلا: راسته آزاد" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none" disabled>
                        </div>
                        <div class="space-y-2 edit-field-waist">
                            <label class="text-[10px] font-black text-slate-400 uppercase block">نوع کمر (اختیاری)</label>
                            <input type="text" id="chart-waist-type" oninput="updateLivePreview()" placeholder="مثلا: تمام کش" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none" disabled>
                        </div>
                    </div>

                    <!-- Dynamic Description Presets linking -->
                    <div class="bg-indigo-50/50 p-6 rounded-2xl border border-indigo-100/50 mb-6 space-y-4">
                        <div class="flex justify-between items-center">
                            <div>
                                <h4 class="text-xs font-black text-indigo-900 leading-tight">پریست فیلدهای توضیحی خطی (مشخصات و متریال)</h4>
                                <p class="text-[10px] text-indigo-500 mt-0.5">برای نمایش خطی مواردی مانند نوع پارچه، نوع دوخت و... یک پریست متناظر انتخاب کرده و پر کنید.</p>
                            </div>
                            <select id="chart-desc-preset-select" onchange="buildDescriptionFields(this.value)" class="text-xs font-black border border-indigo-200 p-2.5 rounded-xl bg-white outline-none">
                                <option value="">-- بدون پریست توضیحات --</option>
                            </select>
                        </div>
                        <!-- Container of dynamically selected specification fields -->
                        <div id="description-fields-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Populated on change -->
                        </div>
                    </div>

                    <!-- Product Notes / Size Advice Field -->
                    <div class="space-y-2 mb-6 bg-amber-50/20 p-5 rounded-2xl border border-amber-100/45">
                        <label class="text-[10px] font-black text-amber-800 uppercase block flex items-center gap-1.5 mb-1">
                            <span>💡</span>
                            <span>توضیحات تکمیلی، مشاوره انتخاب سایز یا یادداشت ویژگی خاص کالا (روی کادر تصویر خروجی نیز چاپ می‌شود)</span>
                        </label>
                        <textarea id="chart-product-notes" oninput="updateLivePreview()" placeholder="مثلا: تن‌خور کار آزاد و کلاسیک است. به علت الیاف ۱۰۰٪ طبیعی در اولین شستشو با آب دمای سرد آب‌رفت بسیار جزئی خواهد داشت." rows="2" class="w-full text-xs font-bold border border-amber-250 p-3 rounded-xl outline-none focus:ring-2 focus:ring-amber-500/10 focus:border-amber-350 bg-white shadow-inner"></textarea>
                    </div>

                    <!-- Size Chart Grid Editor -->
                    <div class="space-y-4 mb-8">
                        <div class="flex flex-wrap justify-between items-center gap-2">
                            <h4 class="text-xs font-black text-slate-800">ورود داده‌های جدول سایزبندی</h4>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" onclick="resetAllBlocksAndLayout()" class="px-3.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-black border border-rose-200 rounded-lg transition-all flex items-center gap-1 shadow-2xs" title="بازگردانی کلیه کادرها و نمایش مجدد جدول">
                                    <span>🔄</span>
                                    <span>ریست و نمایش مجدد جدول</span>
                                </button>
                                <button type="button" onclick="loadSampleChartData()" class="px-3.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-black border border-indigo-200 rounded-lg transition-all flex items-center gap-1" title="بارگذاری ردیف‌های نمونه جهت تست و نمایش سریع جدول">
                                    <span>📋</span>
                                    <span>بارگذاری سطرهای نمونه</span>
                                </button>
                                <button type="button" onclick="addChartRow()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-250 text-slate-900 text-xs font-black border border-slate-200 rounded-lg transition-all">
                                    ➕ افزودن ردیف سایز جدید
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-slate-150">
                            <table class="w-full text-right border-collapse text-xs">
                                <thead class="bg-slate-50 border-b border-slate-150 text-slate-500 font-black">
                                    <tr id="grid-header-row">
                                        <!-- Columns headers populate from preset -->
                                        <th class="p-3 text-center">ردیف فاقد ستون است. ابتدا یک پریست انتخاب کنید</th>
                                    </tr>
                                </thead>
                                <tbody id="grid-body">
                                    <!-- Dynamic Rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 items-center pt-4 border-t border-slate-100">
                        <button onclick="saveChartProcess()" class="px-8 py-4 bg-emerald-600 hover:bg-emerald-500 hover:scale-102 hover:shadow-lg hover:shadow-emerald-500/10 active:scale-95 text-white text-xs font-black rounded-xl transition-all">
                            💾 ذخیره نهایی و ایجاد تصویر کتابخانه
                        </button>
                        <button onclick="downloadChartAsJpg()" class="px-6 py-4 bg-indigo-600 hover:bg-indigo-500 hover:scale-102 hover:shadow-lg hover:shadow-indigo-500/10 active:scale-95 text-white text-xs font-black rounded-xl transition-all flex items-center gap-1.5">
                            📥 دانلود مستقیم به صورت تصویر JPG
                        </button>
                        <button id="btn-delete-chart" onclick="deleteCurrentChart()" class="hidden px-6 py-4 bg-rose-600 hover:bg-rose-500 hover:scale-102 hover:shadow-lg hover:shadow-rose-500/10 active:scale-95 text-white text-xs font-black rounded-xl transition-all flex items-center gap-1.5 mr-auto">
                            🗑️ حذف این جدول سایز
                        </button>
                    </div>
                </div>

                <!-- Live Image Preview Block (Will be rendered into image by html2canvas) -->
                <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100 space-y-4">
                    <div class="flex flex-wrap justify-between items-center gap-3">
                        <div>
                            <h3 class="text-xs font-black text-slate-700 uppercase tracking-widest flex items-center gap-2">
                                <span>🖼️</span>
                                <span>پیش‌نمایش تصویر خروجی (رندر زنده بوم)</span>
                            </h3>
                            <p class="text-[10px] text-slate-400 mt-0.5">طبق تنظیمات پس‌زمینه، ساختار جایگاه و استایل انتخابی رندر می‌شود</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span id="preview-mode-badge" class="px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg text-[10px] font-black border border-slate-200">
                                استایل: پایه نرم‌افزار
                            </span>
                            <span id="preview-positioning-badge" class="px-2.5 py-1 bg-purple-50 text-purple-700 rounded-lg text-[10px] font-black border border-purple-200">
                                حالت بوم: خودکار
                            </span>
                            <span id="preview-size-badge" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 rounded-lg text-[10px] font-black border border-indigo-100">
                                800 × 1000 px
                            </span>
                        </div>
                    </div>

                    <!-- Live Product Selector Bar for Instant Previewing -->
                    <div class="p-3.5 bg-gradient-to-r from-indigo-50/90 via-purple-50/70 to-slate-50 border border-indigo-150 rounded-2xl flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 shadow-2xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-black text-sm shadow-sm shrink-0">
                                🏷️
                            </div>
                            <div class="min-w-0">
                                <span class="text-xs font-black text-indigo-950 block">انتخاب محصول جهت پیش‌نمایش در بوم:</span>
                                <span class="text-[10px] text-indigo-700 font-bold block truncate" id="preview-active-product-label">یک محصول را انتخاب کنید تا نام و جدول سایز آن در پیش‌نمایش بارگذاری شود</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <div class="relative flex-1 md:w-72">
                                <select id="preview-product-quick-select" onchange="selectProductForLivePreview(this.value)" class="w-full text-xs font-black bg-white border border-indigo-200 text-slate-800 p-2.5 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-2xs cursor-pointer">
                                    <option value="">-- انتخاب محصول برای پیش‌نمایش --</option>
                                </select>
                            </div>
                            
                            <button type="button" onclick="selectRandomProductForLivePreview()" class="px-3 py-2.5 bg-white hover:bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-xl text-[11px] font-black flex items-center gap-1.5 transition-all shadow-2xs shrink-0" title="انتخاب تصادفی یک محصول از لیست جهت آزمایش چیدمان و ظاهر جدول">
                                <span>🎲</span>
                                <span>محصول تصادفی</span>
                            </button>

                            <button type="button" onclick="resetAllBlocksAndLayout()" class="px-3 py-2.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-[11px] font-black flex items-center gap-1.5 transition-all shadow-2xs shrink-0" title="بازگردانی کادرها، جدول سایز و نمایش کامل پیش‌نمایش">
                                <span>🔄</span>
                                <span>ریست جدول</span>
                            </button>

                            <button type="button" onclick="clearLivePreviewProduct()" class="px-2.5 py-2.5 bg-white hover:bg-slate-100 text-slate-500 border border-slate-200 rounded-xl text-[11px] font-black transition-all shadow-2xs shrink-0" title="پاکسازی محصول انتخابی">
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Interactive Canvas Toolbar (Zoom, Grid, Snapping, Match Background) -->
                    <div class="flex flex-wrap items-center justify-between gap-3 p-3 bg-slate-50 border border-slate-200/80 rounded-2xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Zoom Controls -->
                            <div class="flex items-center bg-white border border-slate-200 rounded-xl p-0.5 shadow-2xs">
                                <button type="button" onclick="zoomCanvas(-0.1)" class="w-7 h-7 flex items-center justify-center text-slate-600 hover:bg-slate-100 rounded-lg text-xs font-black" title="کوچک‌نمایی">−</button>
                                <span id="canvas-zoom-label" class="px-2 text-[10px] font-black text-slate-700 select-none">100%</span>
                                <button type="button" onclick="zoomCanvas(0.1)" class="w-7 h-7 flex items-center justify-center text-slate-600 hover:bg-slate-100 rounded-lg text-xs font-black" title="بزرگ‌نمایی">+</button>
                                <button type="button" onclick="resetCanvasZoom()" class="px-2 h-7 text-[10px] font-black text-indigo-600 hover:bg-indigo-50 border-r border-slate-200 rounded-l-lg transition-colors">100%</button>
                                <button type="button" onclick="fitCanvasToScreen()" class="px-2 h-7 text-[10px] font-black text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="تنظیم با صفحه نمایش">Fit</button>
                            </div>

                            <!-- Grid Toggle -->
                            <button type="button" id="btn-toggle-grid" onclick="toggleCanvasGrid()" class="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-xl text-[10px] font-black flex items-center gap-1.5 transition-all shadow-2xs">
                                <span>🏁</span>
                                <span>خطوط شطرنجی</span>
                            </button>

                            <!-- Magnet Snap Toggle -->
                            <button type="button" id="btn-toggle-snap" onclick="toggleCanvasSnap()" class="px-3 py-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-xl text-[10px] font-black flex items-center gap-1.5 transition-all shadow-2xs">
                                <span>🧲</span>
                                <span>چسبندگی هوشمند (Snap)</span>
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Reset Canvas & Blocks Button -->
                            <button type="button" onclick="resetAllBlocksAndLayout()" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-[10px] font-black flex items-center gap-1.5 transition-all shadow-2xs" title="بازگردانی کلیه کادرها، جدول سایز و چیدمان پیش‌فرض">
                                <span>🔄</span>
                                <span>بازگردانی کادرها و جدول (Reset)</span>
                            </button>

                            <!-- Match Canvas size to Background Image -->
                            <button type="button" onclick="matchCanvasToBackgroundImage()" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-xl text-[10px] font-black flex items-center gap-1.5 transition-all shadow-2xs" title="تنظیم طول و عرض بوم دقیقا برابر با تصویر پس‌زمینه آپلود شده">
                                <span>🖼️</span>
                                <span>انطباق بوم با سایز پس‌زمینه</span>
                            </button>

                            <!-- Mode Switcher Quick Button -->
                            <button type="button" onclick="togglePositioningModeQuick()" id="btn-quick-toggle-pos" class="px-3.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-[10px] font-black flex items-center gap-1.5 shadow-sm transition-all">
                                <span>🎨</span>
                                <span id="btn-quick-toggle-pos-text">فعال‌سازی بوم آزاد المنتور</span>
                            </button>
                        </div>
                    </div>

                    <!-- Freeform Instructions Banner (Shows when in freeform mode) -->
                    <div id="scs-freeform-tip-banner" class="hidden p-3 bg-purple-50 border border-purple-200 rounded-2xl flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2 text-purple-900 text-xs font-bold">
                            <span class="text-base">💡</span>
                            <span><strong>حالت بوم آزاد فعال است:</strong> روی هر کادر (از جمله جدول سایز) کلیک کنید تا انتخاب شود، سپس آن را با ماوس جابجا کنید یا با ۸ دستگیره گوشه‌ها اندازه آن را تغییر دهید.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="autoArrangeAllFreeformBlocks()" class="shrink-0 px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-[11px] font-black rounded-xl shadow-sm flex items-center gap-1 transition-all">
                                <span>🪄</span>
                                <span>چینش هوشمند اولیه</span>
                            </button>
                            <button type="button" onclick="saveBrandingSettings()" class="shrink-0 px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-black rounded-xl shadow-sm flex items-center gap-1 transition-all">
                                <span>💾</span>
                                <span>ذخیره چیدمان بوم</span>
                            </button>
                        </div>
                    </div>
                    
                    <div id="scs-canvas-viewport" class="bg-slate-100/80 p-6 sm:p-10 rounded-2xl flex justify-center overflow-x-auto min-h-[520px] transition-all">
                        <!-- Dynamic sized-box with flexible layout, background & styling -->
                        <?php $isTableSplitInitial = !empty($scs_settings['table_split_rows']) || !empty($scs_settings['table_split_cells']); ?>
                        <div id="td-preview" class="scs-canvas relative shrink-0 leading-normal overflow-hidden transition-all duration-300 <?php echo $isTableSplitInitial ? 'scs-table-split-active' : ''; ?>" dir="rtl" style="font-family: 'Vazirmatn', sans-serif; width: 800px; min-height: 1000px; background-color: #ffffff; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);">
                            
                            <!-- Smart Snap Guideline Elements -->
                            <div id="scs-snap-x" class="scs-snap-guide-x"></div>
                            <div id="scs-snap-y" class="scs-snap-guide-y"></div>

                            <!-- Background Tint/Overlay Layer -->
                            <div id="preview-bg-overlay" class="absolute inset-0 pointer-events-none transition-opacity duration-300"></div>

                            <!-- Content Wrapper with Dynamic Padding and Zones -->
                            <div id="preview-content-container" class="relative z-10 flex flex-col h-full space-y-4" style="padding: 36px;">
                                
                                <!-- 1. Header Bar -->
                                <div id="preview-header-section" data-scs-block="header" class="scs-card relative group flex justify-between items-center border-b-2 pb-5 transition-all w-full">
                                    <button type="button" onclick="toggleBlockVisibility('header')" class="hidden group-hover:flex absolute -top-3 -left-3 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف هدر برند از این چیدمان">✕</button>
                                    <div id="preview-brand-header-area">
                                        <?php if (!empty($scs_settings['image_logo'])): ?>
                                            <img src="<?php echo htmlspecialchars(resolveImageUrlPHP($scs_settings['image_logo'], $baseUrl)); ?>" class="max-h-12 object-contain" alt="Logo">
                                        <?php else: ?>
                                            <div class="text-2xl font-black text-slate-900" id="preview-html-brand-title"><?php echo htmlspecialchars($scs_settings['brand_title']); ?></div>
                                            <div class="text-[10px] font-black text-slate-400 mt-1" id="preview-html-brand-subtitle"><?php echo htmlspecialchars($scs_settings['brand_subtitle']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-left">
                                        <h4 id="preview-title" class="text-xl font-black text-slate-900">—</h4>
                                        <p class="text-[10px] text-slate-400 mt-1" id="preview-title-sub">جدول رسمی اصالت سایزبندی محصول</p>
                                    </div>
                                </div>

                                <!-- 2. Meta Pills row -->
                                <div id="preview-meta-pills-section" data-scs-block="meta" class="relative group flex flex-wrap gap-2.5 w-full">
                                    <button type="button" onclick="toggleBlockVisibility('meta')" class="hidden group-hover:flex absolute -top-3 -left-3 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف نشانگرها از این چیدمان">✕</button>
                                    <div id="preview-cat-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black flex items-center gap-1.5">
                                        <span class="text-slate-400">دسته‌بندی:</span>
                                        <span id="preview-cat" class="text-slate-800 font-black">—</span>
                                    </div>
                                    <div id="preview-model-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black hidden items-center gap-1.5">
                                        <span class="text-slate-400">مدل:</span>
                                        <span id="preview-model" class="text-slate-800 font-black">—</span>
                                    </div>
                                    <div id="preview-waist-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black hidden items-center gap-1.5">
                                        <span class="text-slate-400">نوع کمر:</span>
                                        <span id="preview-waist" class="text-slate-800 font-black">—</span>
                                    </div>
                                </div>

                                <!-- 3 & 4. Grid Wrapper for Table and Illustration (Supports 2-Column Split & Stacked) -->
                                <div id="preview-grid-wrapper" class="scs-grid-wrapper scs-split-grid w-full flex flex-row items-stretch gap-4 transition-all" style="box-sizing: border-box; width: 100%;">
                                    <!-- 3. Size Table Area -->
                                    <div id="preview-table-card" data-scs-block="table" class="scs-card scs-dynamic-table-card relative group overflow-hidden border border-slate-200 rounded-2xl bg-white shadow-sm transition-all <?php echo $isTableSplitInitial ? 'hidden' : ''; ?>" <?php echo $isTableSplitInitial ? 'style="display: none !important;"' : ''; ?>>
                                        <button type="button" onclick="toggleBlockVisibility('table')" class="hidden group-hover:flex absolute top-2 left-2 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف جدول اندازه‌ها از این چیدمان">✕</button>
                                        <div id="preview-table-container" class="w-full overflow-hidden">
                                            <table class="w-full text-right text-xs" style="table-layout: auto;">
                                                <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200">
                                                    <tr><th class="p-3 text-center">فاقد داده</th></tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- 4. Illustration / Measuring Guide Box -->
                                    <div id="preview-illus-card" data-scs-block="illus" class="scs-card relative group bg-slate-50 rounded-2xl border border-slate-200 p-5 flex flex-col items-center justify-center text-center shadow-sm transition-all">
                                        <button type="button" onclick="toggleBlockVisibility('illus')" class="hidden group-hover:flex absolute top-2 left-2 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف راهنمای تصویر از این چیدمان">✕</button>
                                        <div id="preview-illus-box" class="w-full flex items-center justify-center overflow-hidden mb-3 opacity-80" style="max-height: <?php echo htmlspecialchars($scs_settings['illus_max_height'] ?? 160); ?>px;">
                                            <span class="text-4xl">📏</span>
                                        </div>
                                        <div class="text-[9px] text-slate-400 leading-relaxed font-black" id="preview-illus-text">
                                            ابعاد اندازه‌گیری شده محصول مربوط به اندازه تکه‌ای کالا در حالتِ آزاد و غیرکشیده روی سطح است.
                                        </div>
                                    </div>
                                </div>

                                <!-- 5. Product Custom Note / Guide area inside template -->
                                <div id="preview-notes-box" data-scs-block="notes" class="scs-card relative group w-full bg-amber-50/50 p-4 rounded-2xl border border-amber-200/60 hidden text-right shadow-sm transition-all">
                                    <button type="button" onclick="toggleBlockVisibility('notes')" class="hidden group-hover:flex absolute top-2 left-2 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف یادداشت مشاوره سایز">✕</button>
                                    <div class="flex items-start gap-2.5">
                                        <span class="text-sm shrink-0">💡</span>
                                        <div>
                                            <h5 class="text-[10px] font-black text-amber-900 mb-1" id="preview-notes-heading">توضیحات تکمیلی و مشاوره سایز:</h5>
                                            <p id="preview-notes-text" class="text-[10px] text-amber-800 leading-relaxed font-bold"></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- 6. Linear specification details (Description Preset specs list) -->
                                <div id="preview-specs-box" data-scs-block="specs" class="scs-card relative group w-full bg-indigo-50/40 p-4 rounded-2xl border border-indigo-200/60 hidden shadow-sm transition-all">
                                    <button type="button" onclick="toggleBlockVisibility('specs')" class="hidden group-hover:flex absolute top-2 left-2 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف مشخصات کالا از این چیدمان">✕</button>
                                    <h5 class="text-[11px] font-black text-indigo-900 mb-2.5 border-b border-indigo-100 pb-1.5 flex items-center gap-1.5" id="preview-specs-heading">
                                        <span>🔬</span>
                                        <span>مشخصات، الیاف و متریال محصول</span>
                                    </h5>
                                    <div id="preview-specs-list" class="grid grid-cols-2 gap-x-6 gap-y-2 text-[10px]">
                                        <!-- Dynamic spec items -->
                                    </div>
                                </div>

                                <!-- 7. Footer Bar -->
                                <div id="preview-footer-section" data-scs-block="footer" class="scs-card relative group flex justify-between items-center pt-4 border-t border-slate-200 text-[10px] text-slate-400 font-black transition-all w-full">
                                    <button type="button" onclick="toggleBlockVisibility('footer')" class="hidden group-hover:flex absolute -top-3 -left-3 w-6 h-6 bg-rose-500 hover:bg-rose-600 text-white rounded-full items-center justify-center text-[11px] font-black shadow-md transition-all z-20" title="حذف پانویس از این چیدمان">✕</button>
                                    <div id="preview-html-brand-tagline"><?php echo htmlspecialchars($scs_settings['brand_tagline']); ?></div>
                                    <div id="preview-html-brand-website" dir="ltr"><?php echo htmlspecialchars($scs_settings['brand_website']); ?></div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Standalone SEO-Optimized HTML Output -->
                <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-sm font-black text-slate-900">کد سئو شده HTML جدول سایز</h3>
                            <p class="text-xs text-slate-400 mt-0.5">برای کپی کردن مستقیم در محتوای کدهای سورس وب‌سایت یا پنل ادمین</p>
                        </div>
                        <button onclick="copySeoHtml()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-lg transition-all shadow-md">
                            📋 کپی کد HTML سئو
                        </button>
                    </div>
                    <textarea id="seo-html-output" readonly rows="8" class="w-full p-4 bg-slate-950 font-mono text-[10px] text-emerald-400 border border-slate-800 rounded-xl outline-none resize-none" dir="ltr"></textarea>
                </div>
            </main>
        </div>

        <!-- Tab 2: Missing Size Charts (محصولات بدون جدول سایز) -->
        <div id="tabcontent-missing_charts" class="tab-pane hidden space-y-6">
            <!-- Top Banner -->
            <div class="bg-gradient-to-r from-amber-600 via-amber-700 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-80 h-80 bg-amber-400/15 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute bottom-0 left-0 w-80 h-80 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>
                
                <div class="relative z-10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
                    <div class="space-y-2 max-w-2xl">
                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-amber-400/20 text-amber-200 border border-amber-300/30 rounded-full text-xs font-black">
                            <span>📐 کنترل کیفیت محتوا</span>
                            <span>•</span>
                            <span>محصولات در انتظار ساخت یا اتصال جدول سایز</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black text-white leading-tight">
                            محصولات فاقد جدول سایزبندی
                        </h2>
                        <p class="text-xs sm:text-sm text-amber-100/90 leading-relaxed font-medium">
                            تمام کالاهایی که جدول سایز ندارند در اینجا گردآوری شده‌اند. شما می‌توانید با زدن دکمه <strong>«ساخت سریع (اکسل/JSON)»</strong>، <strong>«اتصال به جدول موجود»</strong> یا <strong>«آپلود مستقیم عکس»</strong> جدول سایز کالا را تکمیل فرمایید. پس از اتصال، کالا به وضعیت <strong>«در انتظار عکس نهایی»</strong> ارتقا می‌یابد.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3 w-full lg:w-auto">
                        <button type="button" onclick="switchTab('quick_batch')" class="flex-1 sm:flex-initial px-5 py-3 bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-black rounded-xl transition-all shadow-lg shadow-amber-950/20 flex items-center justify-center gap-2">
                            <span>⚡</span>
                            <span>ساخت سریع با اکسل یا JSON</span>
                        </button>
                        <button type="button" onclick="initNewChart(); switchTab('charts');" class="flex-1 sm:flex-initial px-5 py-3 bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-black rounded-xl transition-all flex items-center justify-center gap-2">
                            <span>+</span>
                            <span>طراحی دستی در استودیو</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Toolbar & Filter -->
            <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100 flex flex-col md:flex-row justify-between items-stretch md:items-center gap-4">
                <div class="flex flex-wrap items-center gap-3 flex-1">
                    <div class="relative flex-1 min-w-[220px]">
                        <input type="text" id="mc-search-input" oninput="filterMissingChartsList()" placeholder="جستجو در نام، SKU، رنگ یا دسته‌بندی..." class="w-full text-xs font-bold bg-slate-50 border border-slate-200 py-3 pr-10 pl-4 rounded-xl outline-none focus:bg-white focus:ring-2 focus:ring-amber-500/20">
                        <span class="absolute right-3.5 top-3.5 text-slate-400">🔍</span>
                    </div>

                    <select id="mc-category-filter" onchange="filterMissingChartsList()" class="text-xs font-bold bg-slate-50 border border-slate-200 py-3 px-3 rounded-xl outline-none focus:bg-white">
                        <option value="">همه دسته‌بندی‌ها</option>
                    </select>

                    <select id="mc-stock-filter" onchange="filterMissingChartsList()" class="text-xs font-bold bg-slate-50 border border-slate-200 py-3 px-3 rounded-xl outline-none focus:bg-white">
                        <option value="all" selected>همه موجودی‌ها (کلیه کالاهای فاقد جدول)</option>
                        <option value="in_stock">فقط کالاهای دارای موجودی</option>
                        <option value="out_of_stock">فقط کالاهای بدون موجودی</option>
                    </select>
                </div>

                <div class="flex items-center gap-3 self-end md:self-auto shrink-0">
                    <label class="flex items-center gap-1.5 text-xs font-black text-slate-700 cursor-pointer bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200">
                        <input type="checkbox" id="mc-select-all" onchange="toggleSelectAllMissingCharts(this.checked)" class="w-4 h-4 text-amber-600 rounded border-slate-300">
                        <span>انتخاب همه</span>
                    </label>

                    <button type="button" onclick="openBulkLinkModal()" id="btn-mc-bulk-link" class="hidden px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition shadow-sm flex items-center gap-1.5">
                        <span>🔗</span>
                        <span>اتصال انتخابی‌ها (<span id="mc-selected-count">0</span>) به جدول سایز</span>
                    </button>
                </div>
            </div>

            <!-- Cards Grid Container -->
            <div id="missing-charts-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <!-- Dynamically populated by JS -->
            </div>
        </div>

        <!-- Tab 3: Size Charts Pending Website Upload (منتظر آپلود جدول سایز به وب‌سایت) -->
        <div id="tabcontent-pending_upload" class="tab-pane hidden space-y-6">
            <!-- Top Banner -->
            <div class="bg-gradient-to-r from-indigo-900 via-indigo-950 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-80 h-80 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute bottom-0 left-0 w-80 h-80 bg-emerald-500/15 rounded-full blur-3xl pointer-events-none"></div>
                
                <div class="relative z-10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
                    <div class="space-y-2 max-w-2xl">
                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-indigo-500/20 text-indigo-300 border border-indigo-400/30 rounded-full text-xs font-black">
                            <span>☁️ هماهنگ با خروجی وب‌سایت</span>
                            <span>•</span>
                            <span>جدول‌های سایز آماده بارگذاری</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black text-white leading-tight">
                            جدول‌های سایز در انتظار آپلود در وب‌سایت
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-300 leading-relaxed font-medium">
                            جدول سایز این محصولات ساخته شده است اما هنوز در فروشگاه اینترنتی بارگذاری نشده است. شما می‌توانید فایل‌های تصویر را به‌صورت تک‌به‌تک یا زیپ یکجا با نام‌گذاری دقیق دانلود کنید و پس از آپلود، دکمه <strong>«ثبت به عنوان آپلود شد»</strong> را بزنید تا وضعیت در کل پنل همگام گردد.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3 w-full lg:w-auto">
                        <button type="button" onclick="downloadAllPendingSizeChartsZip()" id="btn-pu-download-all-zip" class="flex-1 sm:flex-initial px-5 py-3 bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-black rounded-xl transition-all shadow-lg shadow-amber-950/20 flex items-center justify-center gap-2">
                            <span>📦</span>
                            <span>دانلود زیپ تمام جدول‌های سایز آماده</span>
                        </button>
                        <button type="button" onclick="bulkMarkAllPendingUploaded()" class="flex-1 sm:flex-initial px-5 py-3 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-black rounded-xl transition-all shadow-md flex items-center justify-center gap-2">
                            <span>✓</span>
                            <span>ثبت همه به عنوان آپلود شده</span>
                        </button>
                        <a href="../admin.php?tab=website_export&subtab=tab3" class="px-4 py-3 bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-black rounded-xl transition flex items-center justify-center gap-1.5 whitespace-nowrap">
                            <span>🌐</span>
                            <span>خروجی وب‌سایت</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Toolbar & Filter -->
            <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100 flex flex-col md:flex-row justify-between items-stretch md:items-center gap-4">
                <div class="flex flex-wrap items-center gap-3 flex-1">
                    <div class="relative flex-1 min-w-[220px]">
                        <input type="text" id="pu-search-input" oninput="filterPendingUploadList()" placeholder="جستجو در نام، SKU، رنگ یا دسته‌بندی..." class="w-full text-xs font-bold bg-slate-50 border border-slate-200 py-3 pr-10 pl-4 rounded-xl outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500/20">
                        <span class="absolute right-3.5 top-3.5 text-slate-400">🔍</span>
                    </div>

                    <select id="pu-category-filter" onchange="filterPendingUploadList()" class="text-xs font-bold bg-slate-50 border border-slate-200 py-3 px-3 rounded-xl outline-none focus:bg-white">
                        <option value="">همه دسته‌بندی‌ها</option>
                    </select>
                </div>

                <div class="flex items-center gap-3 self-end md:self-auto shrink-0">
                    <label class="flex items-center gap-1.5 text-xs font-black text-slate-700 cursor-pointer bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200">
                        <input type="checkbox" id="pu-select-all" onchange="toggleSelectAllPendingUpload(this.checked)" class="w-4 h-4 text-indigo-600 rounded border-slate-300">
                        <span>انتخاب همه</span>
                    </label>

                    <button type="button" onclick="bulkMarkSelectedPendingUploaded()" id="btn-pu-mark-selected" class="hidden px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black rounded-xl transition shadow-sm flex items-center gap-1.5">
                        <span>✓</span>
                        <span>ثبت آپلود موارد انتخابی (<span id="pu-selected-count">0</span>)</span>
                    </button>

                    <button type="button" onclick="downloadSelectedPendingZip()" id="btn-pu-download-selected-zip" class="hidden px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition shadow-sm flex items-center gap-1.5">
                        <span>📦</span>
                        <span>دانلود زیپ انتخابی‌ها</span>
                    </button>
                </div>
            </div>

            <!-- Pending Cards Grid Container -->
            <div id="pending-upload-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <!-- Dynamically populated by JS -->
            </div>
        </div>

        <!-- Hidden input for direct chart image upload -->
        <input type="file" id="mc-direct-upload-input" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="handleDirectChartUploadFile(this)">

        <!-- Bulk Link Modal -->
        <div id="bulk-link-modal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl space-y-5 border border-slate-100">
                <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                    <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                        <span>🔗</span>
                        <span>اتصال دسته‌جمعی به جدول سایز</span>
                    </h3>
                    <button type="button" onclick="$('#bulk-link-modal').addClass('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 text-slate-500 hover:bg-slate-200 text-xs font-bold flex items-center justify-center">✕</button>
                </div>

                <div class="text-xs text-slate-600 leading-relaxed">
                    شما <span id="modal-bulk-link-count" class="font-black text-indigo-600 font-mono">0</span> محصول را انتخاب کرده‌اید. لطفاً جدول سایزی که مایلید به همه این محصولات متصل شود را انتخاب فرمایید:
                </div>

                <div class="space-y-2">
                    <label class="text-[11px] font-black text-slate-500 block">انتخاب جدول سایز طراحی شده:</label>
                    <select id="modal-bulk-link-chart-select" class="w-full text-xs font-bold bg-slate-50 border border-slate-200 p-3 rounded-xl outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500/20">
                        <!-- Populated dynamically -->
                    </select>
                </div>

                <div class="p-3.5 bg-amber-50 rounded-xl border border-amber-200/70 text-[11px] font-bold text-amber-900 leading-relaxed">
                    💡 با اعمال این اتصال، تمامی این محصولات به جدول متصل شده و به صورت خودکار به وضعیت «در انتظار عکس نهایی» منتقل خواهند شد.
                </div>

                <div class="flex gap-2.5 pt-2">
                    <button type="button" onclick="executeBulkLinkToChart()" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition shadow-md flex items-center justify-center gap-1.5">
                        <span>✓</span>
                        <span>اعمال و اتصال به محصولات</span>
                    </button>
                    <button type="button" onclick="$('#bulk-link-modal').addClass('hidden')" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition">
                        انصراف
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab 3: Multi-Assign Size Chart to Products -->
        <div id="tabcontent-multi_assign" class="tab-pane hidden space-y-6">
            <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-6 mb-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="text-xl">🎯</span>
                            <h2 class="text-base font-black text-slate-900">اساین و انتساب گروهی جدول سایز به چندین محصول</h2>
                            <span class="px-2.5 py-0.5 bg-indigo-100 text-indigo-700 font-black text-[10px] rounded-full border border-indigo-200">سیستم نوین انتساب چندگانه</span>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed max-w-4xl">
                            یک جدول سایز (یا یک محصول مرجع که دارای جدول سایز است) را انتخاب کنید؛ سپس می‌توانید هر تعداد محصول دلخواه از لیست کالاها را تیک بزنید تا همان جدول سایز برای همه آنها تنظیم گردد.
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" onclick="initNewChart(); switchTab('charts');" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5 shadow-xs">
                            <span>➕ ساخت جدول سایز جدید</span>
                        </button>
                    </div>
                </div>

                <!-- Independent Data Guarantee Box -->
                <div class="bg-gradient-to-r from-indigo-50/70 via-indigo-50/40 to-slate-50 border border-indigo-100/80 rounded-2xl p-4 mb-8 flex items-start gap-3.5">
                    <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-black text-sm shrink-0 shadow-sm">
                        🛡️
                    </div>
                    <div class="text-xs leading-relaxed">
                        <div class="font-black text-indigo-950 mb-0.5">تضمین کامل استقلال و تفکیک دیتای محصولات:</div>
                        <div class="text-slate-600 font-medium">
                            تمامی اطلاعات هر کالا اعم از <strong class="text-indigo-700 font-black">نام، کد کالا (SKU)، رنگ، قیمت، موجودی، عکس‌ها و توضیحات</strong> کاملاً یونیک، مستقل و بدون دستکاری حفظ می‌شوند و در آینده نیز به طور کاملاً مجزا در پنل محصولات یا کاتالوگ قابل ویرایش خواهند بود. فقط مرجع جدول سایز به صورت اشتراکی به محصولات مقصد متصل می‌گردد.
                        </div>
                    </div>
                </div>

                <!-- Step 1: Choose Source (Size Chart or Source Product) -->
                <div class="bg-slate-50/80 border border-slate-200/80 rounded-2xl p-5 mb-8">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-200/60">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-xs font-black">۱</span>
                            <h3 class="text-xs font-black text-slate-800">گام اول: انتخاب جدول سایز سورس (مبدأ)</h3>
                        </div>
                        
                        <!-- Dual Selector Mode Toggle -->
                        <div class="flex items-center bg-white p-1 rounded-xl border border-slate-200 shadow-xs">
                            <button type="button" id="btn-ma-source-chart" onclick="setMultiAssignSourceType('chart')" class="px-3 py-1.5 rounded-lg text-xs font-black transition-all bg-indigo-600 text-white shadow-xs flex items-center gap-1.5">
                                <span>📋 جداول طراحی‌شده پروژه</span>
                                <span id="ma-charts-count-badge" class="px-1.5 py-0.2 bg-white/20 text-white rounded-md text-[10px]"></span>
                            </button>
                            <button type="button" id="btn-ma-source-product" onclick="setMultiAssignSourceType('product')" class="px-3 py-1.5 rounded-lg text-xs font-black transition-all text-slate-600 hover:text-slate-900 flex items-center gap-1.5">
                                <span>👟 بر اساس یک محصول مرجع</span>
                            </button>
                        </div>
                    </div>

                    <!-- Picker Area -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        <div class="lg:col-span-6 space-y-3">
                            <!-- Chart Select Mode -->
                            <div id="ma-chart-picker-container" class="space-y-2">
                                <label class="text-[11px] font-black text-slate-600 block">انتخاب جدول سایز از لیست:</label>
                                <select id="ma-source-chart-select" onchange="onMultiAssignChartSelect(this.value)" class="w-full text-xs font-bold border border-slate-300 bg-white p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    <!-- Populated via JS -->
                                </select>
                            </div>

                            <!-- Product Select Mode -->
                            <div id="ma-product-picker-container" class="space-y-2 hidden">
                                <label class="text-[11px] font-black text-slate-600 block">جستجو و انتخاب محصول مرجع دارای جدول سایز:</label>
                                <input type="text" id="ma-source-product-search" oninput="filterMultiAssignSourceProductDropdown(this.value)" placeholder="🔍 جستجو در نام یا کد محصول مرجع..." class="w-full text-xs font-bold border border-slate-300 bg-white p-2.5 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 mb-1">
                                <select id="ma-source-product-select" onchange="onMultiAssignSourceProductSelect(this.value)" class="w-full text-xs font-bold border border-slate-300 bg-white p-3 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    <!-- Populated via JS -->
                                </select>
                            </div>

                            <div class="flex items-center gap-2 pt-1 flex-wrap">
                                <button type="button" onclick="viewCurrentSourceChartDetails()" class="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 text-xs font-black rounded-lg transition-all flex items-center gap-1 shadow-xs">
                                    <span>👁️ مشاهده جدول اندازه‌ها و مقادیر</span>
                                </button>
                                <button type="button" onclick="editCurrentSourceChartInStudio()" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-black rounded-lg transition-all flex items-center gap-1 shadow-xs">
                                    <span>✏️ ویرایش این جدول در استودیو</span>
                                </button>
                            </div>
                        </div>

                        <!-- Active Source Card Preview -->
                        <div class="lg:col-span-6 bg-white border border-slate-200/90 rounded-2xl p-4 shadow-xs flex items-center gap-4">
                            <div class="w-20 h-24 bg-slate-100 border border-slate-200 rounded-xl overflow-hidden flex items-center justify-center shrink-0 cursor-pointer group relative" onclick="openSourceChartLightbox()" title="کلیک برای مشاهده تصویر بزرگتر">
                                <img id="ma-source-thumb-img" src="" class="w-full h-full object-cover hidden" alt="Size Chart Thumb">
                                <span id="ma-source-thumb-icon" class="text-2xl text-slate-300 select-none">📐</span>
                                <div class="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white text-xs font-black">
                                    🔍
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span id="ma-source-category-badge" class="px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-md text-[10px] font-black border border-indigo-100">دسته‌بندی: -</span>
                                    <span id="ma-source-connected-count-badge" class="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded-md text-[10px] font-black border border-emerald-100">۰ محصول متصل</span>
                                </div>
                                <h4 id="ma-source-title-text" class="text-xs font-black text-slate-900 truncate mb-1">جدول سایزی انتخاب نشده است</h4>
                                <p id="ma-source-desc-text" class="text-[11px] text-slate-400 truncate mb-1.5">-</p>
                                <div id="ma-source-extra-tags" class="flex items-center gap-1.5 flex-wrap text-[10px] text-slate-500 font-bold">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Filter and Select Target Products -->
                <div class="space-y-4 mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-xs font-black">۲</span>
                            <h3 class="text-xs font-black text-slate-800">گام دوم: انتخاب محصولات مقصد با تیک‌زدن (تک یا گروهی)</h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="ma-status-stats" class="text-[11px] text-slate-500 font-bold"></span>
                        </div>
                    </div>

                    <!-- Search and Filters Bar -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200/80">
                        <div class="lg:col-span-5 relative">
                            <input type="text" id="ma-filter-search" oninput="onMultiAssignFilterChange()" placeholder="🔍 جستجو در نام محصول، کد فنی کالا (SKU) یا رنگ..." class="w-full bg-white border border-slate-300 rounded-xl px-4 py-2.5 text-xs font-bold focus:ring-2 focus:ring-indigo-500/20 outline-none">
                        </div>

                        <div class="lg:col-span-3">
                            <select id="ma-filter-category" onchange="onMultiAssignFilterChange()" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2.5 text-xs font-bold focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                <option value="">همه دسته‌بندی‌ها</option>
                            </select>
                        </div>

                        <div class="lg:col-span-2">
                            <select id="ma-filter-status" onchange="onMultiAssignFilterChange()" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2.5 text-xs font-bold focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                <option value="all">همه وضعیت‌ها</option>
                                <option value="missing" selected>🛑 فقط بدون جدول سایز</option>
                                <option value="assigned_this">✅ متصل به همین جدول</option>
                                <option value="assigned_other">⚠️ دارای جدول دیگر</option>
                            </select>
                        </div>

                        <div class="lg:col-span-2">
                            <select id="ma-filter-stock" onchange="onMultiAssignFilterChange()" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2.5 text-xs font-bold focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                <option value="all">همه موجودی‌ها</option>
                                <option value="in_stock">فقط موجود (>۰)</option>
                                <option value="out_of_stock">ناموجود (۰)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Selection Controls Toolbar -->
                    <div class="flex flex-wrap items-center justify-between gap-3 bg-indigo-50/50 border border-indigo-100 p-3.5 rounded-2xl">
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="ma-select-all-checkbox" onchange="toggleMultiAssignSelectAll(this.checked)" class="w-4 h-4 text-indigo-600 rounded cursor-pointer">
                                <span class="text-xs font-black text-slate-800">انتخاب تمام موارد فیلتر شده</span>
                            </label>
                            <span class="text-slate-300">|</span>
                            <div class="text-xs font-black text-indigo-900 flex items-center gap-1.5">
                                <span>تعداد انتخاب شده:</span>
                                <span id="ma-selected-badge" class="px-2.5 py-0.5 bg-indigo-600 text-white rounded-full text-xs font-black">۰</span>
                                <span id="ma-filtered-total-text" class="text-slate-400 text-[11px] font-bold">(از ۰ مورد نمایان)</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" onclick="multiAssignSelectSameCategory()" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-indigo-700 border border-indigo-200 text-xs font-black rounded-xl transition-all shadow-xs flex items-center gap-1" title="انتخاب همه محصولاتی که دسته‌بندی آنها با دسته‌بندی جدول سورس یکی است">
                                <span>✨ انتخاب خودکار همه محصولات هم‌دسته</span>
                            </button>
                            <button type="button" onclick="clearMultiAssignSelection()" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-rose-600 border border-rose-200 text-xs font-black rounded-xl transition-all shadow-xs flex items-center gap-1">
                                <span>❌ لغو انتخاب‌ها</span>
                            </button>
                        </div>
                    </div>

                    <!-- Products Cards Grid -->
                    <div id="ma-products-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 min-h-[250px]">
                        <!-- Populated via JS -->
                    </div>

                    <!-- Pagination / Bottom Controls -->
                    <div id="ma-pagination-container" class="flex items-center justify-between gap-4 pt-4 border-t border-slate-100">
                        <!-- Populated via JS -->
                    </div>
                </div>

                <!-- Step 3: Sticky / Prominent Action Bar -->
                <div class="sticky bottom-4 z-20 bg-white/95 backdrop-blur-md rounded-2xl p-4 sm:p-5 border-2 border-indigo-500 shadow-xl shadow-indigo-900/10 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-3 w-full md:w-auto">
                        <label class="flex items-center gap-2 cursor-pointer bg-slate-50 hover:bg-slate-100 p-2.5 rounded-xl border border-slate-200 transition-all select-none">
                            <input type="checkbox" id="multi-assign-variants-toggle" checked class="w-4 h-4 text-indigo-600 rounded cursor-pointer">
                            <span class="text-xs font-black text-slate-800">
                                اعمال همزمان به تمام رنگ‌ها و تنوع‌های هم‌نام این کالاها (پیشنهادی)
                            </span>
                        </label>
                    </div>

                    <div class="flex items-center gap-3 w-full md:w-auto justify-end">
                        <button type="button" id="btn-ma-unassign" onclick="submitMultiUnassign()" class="px-4 py-3 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-black rounded-xl transition-all flex items-center gap-1.5 shadow-xs disabled:opacity-50 disabled:cursor-not-allowed">
                            <span>❌ قطع انتساب جدول از موارد انتخابی</span>
                        </button>
                        <button type="button" id="btn-ma-submit" onclick="submitMultiAssign()" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all shadow-lg shadow-indigo-600/20 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span id="btn-ma-submit-icon">⚡</span>
                            <span id="btn-ma-submit-text">اعمال و اساین جدول سایز به موارد انتخاب شده</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: View Source Chart Measurements & Details -->
        <div id="modal-source-chart-preview" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
            <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-2xl border border-slate-100 max-h-[90vh] flex flex-col">
                <div class="flex justify-between items-center pb-4 border-b border-slate-150 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">📐</span>
                        <h3 id="modal-sc-title" class="text-base font-black text-slate-900">مشخصات و ابعاد جدول سایز</h3>
                    </div>
                    <button onclick="$('#modal-source-chart-preview').addClass('hidden')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 font-black flex items-center justify-center">✕</button>
                </div>
                
                <div id="modal-sc-content" class="overflow-y-auto flex-1 space-y-4 pr-1">
                    <!-- Populated dynamically via JS -->
                </div>

                <div class="pt-4 border-t border-slate-150 flex justify-end gap-2">
                    <button onclick="$('#modal-source-chart-preview').addClass('hidden')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all">
                        بستن
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab 2: Standardizations (Presets & Specifications Creator) -->
        <div id="tabcontent-presets" class="tab-pane hidden space-y-8">
            <!-- Category Presets Standardizer -->
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100">
                <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-6">
                    <div>
                        <h2 class="text-base font-black text-slate-900">استانداردسازی پریست دسته‌‌ها</h2>
                        <p class="text-xs text-slate-400 mt-0.5">ساختار ستون‌های اندازه‌گیری رسمی و ویژگی‌های الزامی هر دسته از پوشاک</p>
                    </div>
                    <button onclick="initNewPreset()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all shadow-md">
                        + ایجاد پریست دسته جدید
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    <!-- Category Presets list sidebar -->
                    <div class="lg:col-span-4 border-l border-slate-100 pl-4 space-y-2" id="presets-list-container">
                        <!-- Populated dynamically -->
                    </div>

                    <!-- Category Preset Form -->
                    <div class="lg:col-span-8" id="category-presets-form">
                        <input type="hidden" id="p-id" value="">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase">عنوان پریست</label>
                                <input type="text" id="p-name" placeholder="مثلا: بلوز کامل" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase">نام دسته‌بندی انطباقی (مثلا: "بلوز" یا "شلوار")</label>
                                <input type="text" id="p-category" placeholder="مثلا: بلوز" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-150 rounded-2xl cursor-pointer">
                                <input type="checkbox" id="p-show-model" class="w-4 h-4 text-indigo-600 rounded">
                                <span class="text-xs font-bold text-slate-700">امکان ورود فیلد "مدل محصول"</span>
                            </label>
                            <label class="flex items-center gap-3 p-4 bg-slate-50 border border-slate-150 rounded-2xl cursor-pointer">
                                <input type="checkbox" id="p-show-waist" class="w-4 h-4 text-indigo-600 rounded">
                                <span class="text-xs font-bold text-slate-700">امکان ورود فیلد "نوع کمر"</span>
                            </label>
                        </div>

                        <div class="space-y-2 mb-6">
                            <label class="text-[10px] font-black text-slate-400 uppercase block">ستون‌های اندازه‌گیری استاندارد (هر خط، یک ستون)</label>
                            <textarea id="p-columns" rows="5" placeholder="سایز محصول&#10;قد محصول&#10;عرض سینه" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none resize-none"></textarea>
                        </div>

                        <!-- Asset URLs upload -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase block">آدرس مستقیم تصویر فیگور/راهنما (Illustration URL)</label>
                                <div class="flex gap-2">
                                    <input type="text" id="p-illus-url" placeholder="https://..." class="flex-1 text-xs border border-slate-200 p-3 rounded-xl outline-none">
                                    <input type="file" id="p-illus-file" accept="image/*" class="hidden" onchange="uploadAssetFile('illus')">
                                    <button onclick="$('#p-illus-file').click()" class="px-4 bg-indigo-50 border border-indigo-100 hover:bg-indigo-100 text-indigo-700 text-xs font-black rounded-xl">آپلود</button>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase block">آدرس مستقیم آیکن کوچک دسته (PNG Icon URL)</label>
                                <div class="flex gap-2">
                                    <input type="text" id="p-icon-url" placeholder="https://..." class="flex-1 text-xs border border-slate-200 p-3 rounded-xl outline-none">
                                    <input type="file" id="p-icon-file" accept="image/*" class="hidden" onchange="uploadAssetFile('icon')">
                                    <button onclick="$('#p-icon-file').click()" class="px-4 bg-indigo-50 border border-indigo-100 hover:bg-indigo-100 text-indigo-700 text-xs font-black rounded-xl">آپلود</button>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-2 pb-4 border-b border-rose-100">
                            <button onclick="saveCategoryPreset()" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl transition-all shadow-md">ذخیره پریست دسته</button>
                            <button id="btn-delete-p" onclick="deleteCategoryPreset()" class="px-6 py-3 bg-rose-50 border border-rose-100 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-xl h-fit hidden">حذف پریست</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description/Specifications Presets Builder -->
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100">
                <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-6 font-semibold">
                    <div>
                        <h2 class="text-base font-black text-slate-900">پریست‌های فیلدهای مشخصات و الیاف (توضیحات زنده)</h2>
                        <p class="text-xs text-slate-400 mt-0.5">ایجاد بِلک‌های فیلدِ داینامیک مشخصات اختصاصی کالا مشابه نوع پارچه، ضخامت، یقه و الیاف گوسفندی</p>
                    </div>
                    <button onclick="initNewDescPreset()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all shadow-md">
                        + ایجاد پریست توضیحی جدید
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    <!-- Description list sidebar -->
                    <div class="lg:col-span-4 border-l border-slate-100 pl-4 space-y-2" id="desc-presets-list-container">
                        <!-- Loaded dynamically -->
                    </div>

                    <!-- Description Form -->
                    <div class="lg:col-span-8" id="desc-preset-form">
                        <input type="hidden" id="dp-id" value="">
                        
                        <div class="flex flex-col md:flex-row gap-6 mb-6">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase">نام پریست توضیحات</label>
                                <input type="text" id="dp-title" placeholder="مثلا: ویژگی‌های کتان بهاره" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl outline-none">
                            </div>
                            <div class="flex items-end">
                                <button onclick="addDescPresetField()" class="px-5 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-900 border border-slate-300 text-xs font-black rounded-xl">
                                    ➕ افزودن ردیف فیلد جدید
                                </button>
                            </div>
                        </div>

                        <!-- Specification fields list -->
                        <div id="dp-fields-container" class="space-y-3 mb-8">
                            <!-- Populated with elements dynamic -->
                        </div>

                        <div class="flex gap-2">
                            <button onclick="saveDescPreset()" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl transition-all shadow-md">ذخیره پریست توضیحات</button>
                            <button id="btn-delete-dp" onclick="deleteDescPreset()" class="px-6 py-3 bg-rose-50 border border-rose-100 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-xl h-fit hidden">حذف پریست</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 4: Branding, Background and Styling Studio -->
        <div id="tabcontent-branding" class="tab-pane hidden space-y-6">
            
            <!-- Top Control Bar -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100 flex flex-wrap justify-between items-center gap-4">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">🎨</span>
                        <h2 class="text-base font-black text-slate-900">استودیو برند، پس‌زمینه و استایل خروجی</h2>
                    </div>
                    <p class="text-xs text-slate-400">
                        انتخاب بک‌گراند دلخواه، مشخص کردن جایگاه محتوای داینامیک بر اساس بیشترین فضای مورد نیاز، و سوئیچ بین استایل پایه نرم‌افزار یا استایل سفارشی
                    </p>
                </div>
                
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" onclick="resetBrandingToDefault()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5">
                        <span>🔄</span>
                        <span>بازنشانی پیش‌فرض</span>
                    </button>
                    <button type="button" onclick="saveBrandingSettings()" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 hover:scale-102 text-white text-xs font-black rounded-xl transition-all shadow-md shadow-indigo-600/20 flex items-center gap-2">
                        <span>💾</span>
                        <span>ذخیره تمامی تنظیمات</span>
                    </button>
                </div>
            </div>

            <!-- Style Mode Switcher Card -->
            <div class="bg-gradient-to-r from-indigo-900 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-lg">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="space-y-2">
                        <span class="px-3 py-1 bg-indigo-500/30 text-indigo-200 text-[10px] font-black rounded-full uppercase tracking-wider inline-block">
                            انتخاب حالت استایل (Style Mode)
                        </span>
                        <h3 class="text-lg font-black text-white">شیوه نمایش و تم خروجی تصویر را مشخص فرمایید:</h3>
                        <p class="text-xs text-indigo-200/80 leading-relaxed max-w-2xl">
                            می‌توانید از استایل استاندارد و تمیز نرم‌افزار استفاده کنید، یا با انتخاب «استایل دلخواه»، تمامی رنگ‌ها، شفافیت کارت‌ها، خطوط، پس‌زمینه و حاشیه‌ها را به دلخواه شخصی‌سازی نمایید.
                        </p>
                    </div>

                    <div class="bg-white/10 p-1.5 rounded-2xl flex items-center gap-2 shrink-0 border border-white/10">
                        <button type="button" id="btn-mode-default" onclick="setStyleMode('default')" class="px-5 py-3 rounded-xl text-xs font-black transition-all flex items-center gap-2 <?php echo ($scs_settings['style_mode'] ?? 'default') === 'default' ? 'bg-white text-slate-900 shadow-md' : 'text-white/80 hover:text-white hover:bg-white/5'; ?>">
                            <span>🏢</span>
                            <span>استایل پایه نرم‌افزار</span>
                        </button>
                        <button type="button" id="btn-mode-custom" onclick="setStyleMode('custom')" class="px-5 py-3 rounded-xl text-xs font-black transition-all flex items-center gap-2 <?php echo ($scs_settings['style_mode'] ?? 'default') === 'custom' ? 'bg-indigo-500 text-white shadow-md' : 'text-white/80 hover:text-white hover:bg-white/5'; ?>">
                            <span>✨</span>
                            <span>استایل دلخواه و سفارشی</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Two-Column Settings Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                
                <!-- Left/Main Column: Settings Accordions -->
                <div class="lg:col-span-7 space-y-6">
                    
                    <!-- Section 1: Background & Canvas Sizing -->
                    <div class="bg-white rounded-3xl p-6 sm:p-7 shadow-sm border border-slate-100 space-y-6">
                        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span class="p-2 bg-indigo-50 text-indigo-600 rounded-xl text-xs">🖼️</span>
                                <span>۱. انتخاب بک‌گراند و ابعاد بوم (Background & Canvas)</span>
                            </h3>
                            <span class="text-[10px] font-bold text-slate-400">آپلود و پترن‌های آماده</span>
                        </div>

                        <!-- Upload Custom Background Image -->
                        <div class="space-y-3">
                            <label class="block text-xs font-black text-slate-700">تصویر پس‌زمینه اختصاصی (آپلود فایل یا آدرس تصویر)</label>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                                <div class="sm:col-span-8 relative">
                                    <input type="text" id="setting-bg-image" value="<?php echo htmlspecialchars($scs_settings['bg_image'] ?? ''); ?>" oninput="onBgImageChanged(this.value)" placeholder="uploads/my-background.jpg یا لینک تصویر..." class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all" dir="ltr">
                                </div>
                                <div class="sm:col-span-4 flex items-center gap-2">
                                    <label class="cursor-pointer flex-1 px-3 py-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-black rounded-xl transition-all text-center border border-indigo-200/50 flex items-center justify-center gap-1.5">
                                        <span>📤</span>
                                        <span>آپلود فایل</span>
                                        <input type="file" id="bg-file-input" accept="image/*" class="hidden" onchange="uploadBgFile(this)">
                                    </label>
                                    <button type="button" onclick="removeBgImage()" title="حذف بک‌گراند" class="p-3 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-xl transition-all">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <!-- BG Thumbnail Preview & Natural Dimensions Sync -->
                            <div id="bg-thumb-box" class="<?php echo empty($scs_settings['bg_image']) ? 'hidden' : ''; ?> flex flex-wrap items-center justify-between gap-3 p-3.5 bg-slate-50 rounded-2xl border border-slate-200">
                                <div class="flex items-center gap-3">
                                    <img id="bg-thumb-img" src="<?php echo htmlspecialchars(resolveImageUrlPHP($scs_settings['bg_image'] ?? '', $baseUrl)); ?>" class="w-16 h-12 object-cover rounded-lg border border-slate-200 shadow-sm" alt="Background preview">
                                    <div class="text-[10px] text-slate-500 leading-tight">
                                        <div class="font-black text-slate-800">تصویر پس‌زمینه اختصاصی</div>
                                        <div class="text-slate-400 truncate max-w-[200px]" id="bg-thumb-filename"><?php echo htmlspecialchars($scs_settings['bg_image'] ?? ''); ?></div>
                                        <div class="font-bold text-indigo-600 mt-1" id="bg-thumb-dim-info">ابعاد تصویر پس‌زمینه</div>
                                    </div>
                                </div>
                                <button type="button" onclick="autoFitCanvasToBg(false)" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-sm flex items-center gap-1.5 transition-all">
                                    <span>🎯</span>
                                    <span>انطباق ۱۰۰٪ اندازه بوم با این تصویر</span>
                                </button>
                            </div>
                        </div>

                        <!-- Ready Made Natural Patterns -->
                        <div class="space-y-2.5">
                            <label class="block text-xs font-black text-slate-700">یا انتخاب پترن‌ها و بافت‌های نچرال آماده:</label>
                            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2" id="bg-patterns-group">
                                <button type="button" onclick="setBgPattern('none')" data-pattern="none" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? 'none') === 'none' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded bg-white border border-slate-200 mb-1.5"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">سفید ساده</span>
                                </button>

                                <button type="button" onclick="setBgPattern('linen')" data-pattern="linen" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? '') === 'linen' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded border border-slate-200 mb-1.5" style="background-color: #faf7f2; background-image: radial-gradient(#d6c7b2 0.75px, transparent 0.75px); background-size: 8px 8px;"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">کتان طبیعی</span>
                                </button>

                                <button type="button" onclick="setBgPattern('grid')" data-pattern="grid" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? '') === 'grid' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded border border-slate-200 mb-1.5" style="background-color: #f8fafc; background-image: linear-gradient(#e2e8f0 1px, transparent 1px), linear-gradient(90deg, #e2e8f0 1px, transparent 1px); background-size: 10px 10px;"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">گرید مدرن</span>
                                </button>

                                <button type="button" onclick="setBgPattern('gradient')" data-pattern="gradient" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? '') === 'gradient' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded border border-slate-200 mb-1.5" style="background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">گرادیانت ملایم</span>
                                </button>

                                <button type="button" onclick="setBgPattern('craft')" data-pattern="craft" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? '') === 'craft' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded border border-slate-200 mb-1.5" style="background-color: #f4efe6;"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">کرم ارگانیک</span>
                                </button>

                                <button type="button" onclick="setBgPattern('dark')" data-pattern="dark" class="pattern-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['bg_pattern'] ?? '') === 'dark' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="w-full h-7 rounded border border-slate-700 mb-1.5" style="background-color: #0f172a;"></div>
                                    <span class="text-[10px] font-black text-slate-700 block">لوکس تیره</span>
                                </button>
                            </div>
                        </div>

                        <!-- Canvas Size Preset & Custom Dimensions -->
                        <div class="space-y-3 pt-3 border-t border-slate-100">
                            <div class="flex flex-wrap justify-between items-center gap-2">
                                <label class="block text-xs font-black text-slate-700">ابعاد و نسبت بوم خروجی (Canvas Sizing)</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="autoFitCanvasToBg()" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-[10px] font-black rounded-xl transition-all flex items-center gap-1 border border-emerald-200/80 shadow-xs" title="تنظیم خودکار ابعاد بوم برابر با ابعاد واقعی تصویر پس‌زمینه">
                                        <span>🎯</span>
                                        <span>انطباق با سایز تصویر پس‌زمینه</span>
                                    </button>
                                    <span class="text-[10px] font-black text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg" id="current-canvas-dim-label"><?php echo htmlspecialchars(($scs_settings['canvas_width'] ?? 800) . ' × ' . ($scs_settings['canvas_height'] ?? 1000) . ' px'); ?></span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2" id="canvas-presets-group">
                                <button type="button" onclick="setCanvasPreset('catalog_standard', 800, 1000)" data-preset="catalog_standard" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? 'catalog_standard') === 'catalog_standard' ? 'border-indigo-600 bg-indigo-50 text-indigo-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black">کاتالوگ کالا</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">800 × 1000 px</div>
                                </button>

                                <button type="button" onclick="setCanvasPreset('persepolis_sport', 1440, 1080)" data-preset="persepolis_sport" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? '') === 'persepolis_sport' ? 'border-rose-600 bg-rose-50 text-rose-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black text-rose-600">🔴 ورزشی / پرسپولیس</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">1440 × 1080 px</div>
                                </button>

                                <button type="button" onclick="setCanvasPreset('wide_16_9', 1920, 1080)" data-preset="wide_16_9" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? '') === 'wide_16_9' ? 'border-indigo-600 bg-indigo-50 text-indigo-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black">عریض ۱۶:۹</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">1920 × 1080 px</div>
                                </button>

                                <button type="button" onclick="setCanvasPreset('square', 1080, 1080)" data-preset="square" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? '') === 'square' ? 'border-indigo-600 bg-indigo-50 text-indigo-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black">مربعی ۱:۱</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">1080 × 1080 px</div>
                                </button>

                                <button type="button" onclick="setCanvasPreset('long', 800, 1200)" data-preset="long" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? '') === 'long' ? 'border-indigo-600 bg-indigo-50 text-indigo-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black">عمودی بلند</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">800 × 1200 px</div>
                                </button>

                                <button type="button" onclick="setCanvasPreset('story', 1080, 1920)" data-preset="story" class="canvas-preset-btn p-2.5 rounded-xl border text-center transition-all <?php echo ($scs_settings['canvas_preset'] ?? '') === 'story' ? 'border-indigo-600 bg-indigo-50 text-indigo-900 font-black' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
                                    <div class="text-xs font-black">استوری ۹:۱۶</div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">1080 × 1920 px</div>
                                </button>
                            </div>

                            <div class="grid grid-cols-2 gap-3 pt-1">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">عرض بوم (Width px)</label>
                                    <input type="number" id="setting-canvas-width" value="<?php echo htmlspecialchars($scs_settings['canvas_width'] ?? 800); ?>" oninput="onCustomDimensionsChanged()" min="400" max="2400" step="10" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 focus:bg-white outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">حداقل ارتفاع (Min Height px)</label>
                                    <input type="number" id="setting-canvas-height" value="<?php echo htmlspecialchars($scs_settings['canvas_height'] ?? 1000); ?>" oninput="onCustomDimensionsChanged()" min="400" max="3200" step="10" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 focus:bg-white outline-none">
                                </div>
                            </div>
                        </div>

                        <!-- Illustration & Size Chart Image Scaling Controls -->
                        <div class="space-y-3 pt-3 border-t border-slate-100">
                            <div class="flex justify-between items-center">
                                <label class="block text-xs font-black text-slate-700">تنظیم و اصلاح سایز تصویر راهنمای اندازه‌گیری (Illustration Image)</label>
                                <span class="text-[10px] font-mono text-indigo-600" id="illus-height-label"><?php echo htmlspecialchars($scs_settings['illus_max_height'] ?? 160); ?> px</span>
                            </div>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                                <div class="sm:col-span-8">
                                    <div class="flex items-center gap-3">
                                        <input type="range" id="setting-illus-max-height" min="60" max="360" step="5" value="<?php echo htmlspecialchars($scs_settings['illus_max_height'] ?? 160); ?>" oninput="$('#illus-height-label').text(this.value + ' px'); $('#setting-illus-max-height-num').val(this.value); applyBrandingChanges();" class="flex-1 accent-indigo-600">
                                        <input type="number" id="setting-illus-max-height-num" min="60" max="360" value="<?php echo htmlspecialchars($scs_settings['illus_max_height'] ?? 160); ?>" oninput="$('#setting-illus-max-height').val(this.value); $('#illus-height-label').text(this.value + ' px'); applyBrandingChanges();" class="w-16 text-center text-xs font-mono border border-slate-200 p-1.5 rounded-lg bg-slate-50">
                                    </div>
                                </div>
                                <div class="sm:col-span-4">
                                    <select id="setting-illus-object-fit" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                        <option value="contain" <?php echo ($scs_settings['illus_object_fit'] ?? 'contain') === 'contain' ? 'selected' : ''; ?>>Contain (تناسب کامل)</option>
                                        <option value="cover" <?php echo ($scs_settings['illus_object_fit'] ?? '') === 'cover' ? 'selected' : ''; ?>>Cover (پر کردن کادر)</option>
                                        <option value="scale-down" <?php echo ($scs_settings['illus_object_fit'] ?? '') === 'scale-down' ? 'selected' : ''; ?>>Scale Down (اندازه طبیعی)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- BG Fit & Overlay Controls -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                            <div>
                                <label class="block text-xs font-black text-slate-700 mb-1.5">نحوه پوشش بک‌گراند (Fit)</label>
                                <select id="setting-bg-fit" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 outline-none">
                                    <option value="cover" <?php echo ($scs_settings['bg_fit'] ?? 'cover') === 'cover' ? 'selected' : ''; ?>>Cover (پوشش کامل کل بوم)</option>
                                    <option value="100% 100%" <?php echo ($scs_settings['bg_fit'] ?? '') === '100% 100%' ? 'selected' : ''; ?>>100% Fit (انطباق ۱۰۰٪ بر ابعاد بوم)</option>
                                    <option value="contain" <?php echo ($scs_settings['bg_fit'] ?? '') === 'contain' ? 'selected' : ''; ?>>Contain (نمایش کامل تصویر)</option>
                                    <option value="repeat" <?php echo ($scs_settings['bg_fit'] ?? '') === 'repeat' ? 'selected' : ''; ?>>Repeat (تکرار شوندگی کاشی‌وار)</option>
                                    <option value="center" <?php echo ($scs_settings['bg_fit'] ?? '') === 'center' ? 'selected' : ''; ?>>Center (مرکز تصویر)</option>
                                </select>
                            </div>

                            <div>
                                <div class="flex justify-between items-center mb-1.5">
                                    <label class="block text-xs font-black text-slate-700">لایه پوشش و تاری (Overlay)</label>
                                    <span class="text-[10px] font-bold text-indigo-600" id="overlay-opacity-label"><?php echo htmlspecialchars($scs_settings['bg_overlay_opacity'] ?? 0); ?>%</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select id="setting-bg-overlay-mode" onchange="applyBrandingChanges()" class="text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 outline-none shrink-0">
                                        <option value="none" <?php echo ($scs_settings['bg_overlay_mode'] ?? 'none') === 'none' ? 'selected' : ''; ?>>بدون لایه</option>
                                        <option value="white" <?php echo ($scs_settings['bg_overlay_mode'] ?? '') === 'white' ? 'selected' : ''; ?>>لایه روشن (سفید)</option>
                                        <option value="dark" <?php echo ($scs_settings['bg_overlay_mode'] ?? '') === 'dark' ? 'selected' : ''; ?>>لایه تیره (مشکی)</option>
                                        <option value="tint" <?php echo ($scs_settings['bg_overlay_mode'] ?? '') === 'tint' ? 'selected' : ''; ?>>لایه شاخص برند</option>
                                    </select>
                                    <input type="range" id="setting-bg-overlay-opacity" min="0" max="90" step="5" value="<?php echo htmlspecialchars($scs_settings['bg_overlay_opacity'] ?? 0); ?>" oninput="$('#overlay-opacity-label').text(this.value + '%'); applyBrandingChanges();" class="flex-1 accent-indigo-600">
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Section 2: Font Typography & Custom Font Uploader -->
                    <div class="bg-white rounded-3xl p-6 sm:p-7 shadow-sm border border-slate-100 space-y-6">
                        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span class="p-2 bg-purple-50 text-purple-600 rounded-xl text-xs">🔤</span>
                                <span>۲. تایپوگرافی، انتخاب و آپلود فونت دلخواه (Font & Typography)</span>
                            </h3>
                            <span class="text-[10px] font-bold text-purple-600 bg-purple-50 px-2.5 py-1 rounded-lg">فارسی و انگلیسی</span>
                        </div>

                        <!-- Font Selection Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Main Persian Font -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">فونت اصلی متن و جدول (فارسی)</label>
                                <select id="setting-font-family" onchange="onFontFamilyChanged(this.value)" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none">
                                    <option value="Vazirmatn" <?php echo ($scs_settings['font_family'] ?? 'Vazirmatn') === 'Vazirmatn' ? 'selected' : ''; ?>>وزیرمتن (Vazirmatn - استاندارد و مدرن)</option>
                                    <option value="IranYekan" <?php echo ($scs_settings['font_family'] ?? '') === 'IranYekan' ? 'selected' : ''; ?>>ایران‌یکان (IranYekan - تجاری و وب)</option>
                                    <option value="Estehdad" <?php echo ($scs_settings['font_family'] ?? '') === 'Estehdad' ? 'selected' : ''; ?>>استعداد (Estehdad - خوانا و شفاف)</option>
                                    <option value="Shabnam" <?php echo ($scs_settings['font_family'] ?? '') === 'Shabnam' ? 'selected' : ''; ?>>شبنم (Shabnam - رسمی و تمیز)</option>
                                    <option value="Sahel" <?php echo ($scs_settings['font_family'] ?? '') === 'Sahel' ? 'selected' : ''; ?>>ساحل (Sahel - هندسی و پویا)</option>
                                    <option value="Samim" <?php echo ($scs_settings['font_family'] ?? '') === 'Samim' ? 'selected' : ''; ?>>صمیم (Samim - نرم و روان)</option>
                                    <option value="Lalezar" <?php echo ($scs_settings['font_family'] ?? '') === 'Lalezar' ? 'selected' : ''; ?>>لاله‌زار (Lalezar - تیتر فانتزی و نمایشی)</option>
                                    <option value="Dana" <?php echo ($scs_settings['font_family'] ?? '') === 'Dana' ? 'selected' : ''; ?>>دانا (Dana - مدرن دیزاین)</option>
                                    <option value="B Titr" <?php echo ($scs_settings['font_family'] ?? '') === 'B Titr' ? 'selected' : ''; ?>>بی‌تیتر (B Titr - کلاسیک برجسته)</option>
                                    <option value="B Yekan" <?php echo ($scs_settings['font_family'] ?? '') === 'B Yekan' ? 'selected' : ''; ?>>بی‌یکان (B Yekan - سنتی دیجیتال)</option>
                                    <option value="Pasta" <?php echo ($scs_settings['font_family'] ?? '') === 'Pasta' ? 'selected' : ''; ?>>پاستا (Pasta - ورزشی و بولد)</option>
                                    <option value="custom" <?php echo ($scs_settings['font_family'] ?? '') === 'custom' ? 'selected' : ''; ?>>✨ فونت آپلود شده اختصاصی</option>
                                </select>
                            </div>

                            <!-- English Header Display Font -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">فونت هدر انگلیسی / تایتل ورزشی (Display Header)</label>
                                <select id="setting-header-font-family" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none">
                                    <option value="" <?php echo empty($scs_settings['header_font_family']) ? 'selected' : ''; ?>>همانند فونت اصلی (Default)</option>
                                    <option value="Bebas Neue" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Bebas Neue' ? 'selected' : ''; ?>>Bebas Neue (طرح ورزشی، باریک و کشیده)</option>
                                    <option value="Montserrat" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Montserrat' ? 'selected' : ''; ?>>Montserrat (لوکس، هندسی و بولد)</option>
                                    <option value="Oswald" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Oswald' ? 'selected' : ''; ?>>Oswald (مستطیلی، پرقدرت و تیتر)</option>
                                    <option value="Anton" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Anton' ? 'selected' : ''; ?>>Anton (ضخیم و چشم‌گیر)</option>
                                    <option value="Outfit" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Outfit' ? 'selected' : ''; ?>>Outfit (مینیمال و مدرن)</option>
                                    <option value="Poppins" <?php echo ($scs_settings['header_font_family'] ?? '') === 'Poppins' ? 'selected' : ''; ?>>Poppins (گرد و خوانا)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Upload Custom Font Box -->
                        <div class="p-4 bg-purple-50/50 rounded-2xl border border-purple-100 space-y-3">
                            <div class="flex justify-between items-center">
                                <label class="block text-xs font-black text-purple-900">آپلود مستقیم فونت دلخواه (Custom Font File)</label>
                                <span class="text-[10px] text-purple-700 font-bold">فرمت‌های TTF, OTF, WOFF, WOFF2</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                                <div class="sm:col-span-8">
                                    <input type="text" id="setting-custom-font-url" value="<?php echo htmlspecialchars($scs_settings['custom_font_url'] ?? ''); ?>" placeholder="آدرس فایل فونت..." class="w-full text-xs font-mono border border-purple-200 p-2.5 rounded-xl bg-white outline-none" dir="ltr" readonly>
                                    <input type="hidden" id="setting-custom-font-name" value="<?php echo htmlspecialchars($scs_settings['custom_font_name'] ?? ''); ?>">
                                </div>
                                <div class="sm:col-span-4 flex items-center gap-2">
                                    <label class="cursor-pointer flex-1 px-3 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-black rounded-xl transition-all text-center flex items-center justify-center gap-1.5 shadow-sm shadow-purple-600/20">
                                        <span>📁</span>
                                        <span>انتخاب فایل فونت</span>
                                        <input type="file" id="font-file-input" accept=".ttf,.otf,.woff,.woff2" class="hidden" onchange="uploadCustomFontFile(this)">
                                    </label>
                                    <button type="button" onclick="removeCustomFont()" title="حذف فونت آپلودی" class="p-2.5 bg-white hover:bg-rose-50 hover:text-rose-600 text-slate-400 border border-purple-200 rounded-xl transition-all">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <!-- Live Font Tester Preview Box -->
                            <div id="font-tester-box" class="p-3 bg-white rounded-xl border border-purple-200 flex items-center justify-between gap-3">
                                <div>
                                    <span class="text-[10px] text-slate-400 block">تستر زنده‌ی قلم فعال:</span>
                                    <span id="font-tester-text" class="text-sm font-black text-slate-800" style="font-family: '<?php echo htmlspecialchars($scs_settings['font_family'] ?? 'Vazirmatn'); ?>', sans-serif;">
                                        جدول اندازه‌گیری و سایزبندی پیراهن کتان ۱۲۳۴۵۶۷۸۹۰ - SIZE CHART 2026
                                    </span>
                                </div>
                                <span id="font-active-badge" class="px-2.5 py-1 bg-purple-100 text-purple-800 rounded-lg text-[10px] font-black shrink-0">
                                    <?php echo htmlspecialchars($scs_settings['font_family'] ?? 'Vazirmatn'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Font Scaling, Weight & Digits -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <label class="block text-xs font-black text-slate-700">مقیاس اندازه قلم (Font Scale)</label>
                                    <span class="text-[10px] font-mono text-indigo-600" id="font-scale-label"><?php echo htmlspecialchars($scs_settings['font_scale'] ?? 100); ?>%</span>
                                </div>
                                <input type="range" id="setting-font-scale" min="75" max="140" step="5" value="<?php echo htmlspecialchars($scs_settings['font_scale'] ?? 100); ?>" oninput="$('#font-scale-label').text(this.value + '%'); applyBrandingChanges();" class="w-full accent-purple-600 mt-2">
                            </div>

                            <div>
                                <label class="block text-xs font-black text-slate-700 mb-1">ضخامت پیش‌فرض قلم (Weight)</label>
                                <select id="setting-font-weight" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 outline-none">
                                    <option value="normal" <?php echo ($scs_settings['font_weight'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>عادی (Normal)</option>
                                    <option value="bold" <?php echo ($scs_settings['font_weight'] ?? '') === 'bold' ? 'selected' : ''; ?>>ضخیم (Bold)</option>
                                    <option value="900" <?php echo ($scs_settings['font_weight'] ?? '') === '900' ? 'selected' : ''; ?>>بسیار ضخیم (Black / Extra Bold)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-black text-slate-700 mb-1">قالب ارقام و اعداد (Digits Format)</label>
                                <select id="setting-font-digits" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-slate-50 outline-none">
                                    <option value="persian" <?php echo ($scs_settings['font_digits'] ?? 'persian') === 'persian' ? 'selected' : ''; ?>>اعداد فارسی (۱۲۳۴۵۶۷۸۹۰)</option>
                                    <option value="english" <?php echo ($scs_settings['font_digits'] ?? '') === 'english' ? 'selected' : ''; ?>>اعداد لاتین / انگلیسی (1234567890)</option>
                                </select>
                            </div>
                        </div>

                    </div>

                    <!-- Section 2: Dynamic Content Placement & Layout Structure -->
                    <div class="bg-white rounded-3xl p-6 sm:p-7 shadow-sm border border-slate-100 space-y-6">
                        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span class="p-2 bg-emerald-50 text-emerald-600 rounded-xl text-xs">📐</span>
                                <span>۲. موتور چیدمان و جایگاه عناصر روی بوم (Layout Engine & Placement)</span>
                            </h3>
                            <button type="button" onclick="resetBlockOrderToDefault()" class="text-[10px] font-black text-slate-500 hover:text-indigo-600 bg-slate-50 hover:bg-indigo-50 px-2.5 py-1 rounded-lg border border-slate-200 transition-all flex items-center gap-1">
                                <span>🔄</span>
                                <span>بازنشانی چیدمان اولیه</span>
                            </button>
                        </div>

                        <!-- Layout Engine Mode Switcher -->
                        <div class="space-y-3 p-4 bg-slate-50 rounded-2xl border border-slate-200/80">
                            <label class="block text-xs font-black text-slate-800 flex items-center justify-between">
                                <span class="flex items-center gap-1.5">
                                    <span>🎯</span>
                                    <span>حالت قرارگیری عناصر روی بوم (Positioning Mode):</span>
                                </span>
                                <span id="pos-mode-indicator" class="text-[10px] font-black px-2 py-0.5 rounded-md <?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'freeform' ? 'bg-purple-100 text-purple-800' : 'bg-emerald-100 text-emerald-800'; ?>">
                                    <?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'freeform' ? 'بوم آزاد المنتور / فتوشاپ' : 'چیدمان خودکار و ستونی'; ?>
                                </span>
                            </label>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <button type="button" onclick="setPositioningMode('flow')" id="pos-mode-btn-flow" class="p-3.5 rounded-xl border text-right transition-all <?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'flow' ? 'border-emerald-600 bg-emerald-50/50 ring-2 ring-emerald-500/20 font-black' : 'border-slate-200 bg-white hover:bg-slate-50'; ?>">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-base">📑</span>
                                        <span class="text-xs font-black text-slate-900">۱. چیدمان ساختار خودکار (Flow Layout)</span>
                                    </div>
                                    <p class="text-[10px] text-slate-500 leading-relaxed">
                                        بلوک‌ها به صورت خودکار و ستونی زیر هم یا کنار هم در کادرهای شبکه‌ای قرار می‌گیرند.
                                    </p>
                                </button>

                                <button type="button" onclick="setPositioningMode('freeform')" id="pos-mode-btn-freeform" class="p-3.5 rounded-xl border text-right transition-all <?php echo ($scs_settings['positioning_mode'] ?? '') === 'freeform' ? 'border-purple-600 bg-purple-50/50 ring-2 ring-purple-500/20 font-black' : 'border-slate-200 bg-white hover:bg-slate-50'; ?>">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-base">🎨</span>
                                        <span class="text-xs font-black text-slate-900">۲. بوم آزاد فتوشاپ / المنتور (Free-form Canvas)</span>
                                    </div>
                                    <p class="text-[10px] text-slate-500 leading-relaxed">
                                        امکان جابجایی آزادانه هر بلوک با ماوس به هر نقطه از بوم، تغییر اندازه، مگنت راهنما و تنظیم مختصات دقیق (X, Y).
                                    </p>
                                </button>
                            </div>
                        </div>

                        <!-- Inspector Panel for Freeform Coordinates (Visible in freeform mode) -->
                        <div id="scs-freeform-inspector-section" class="<?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'freeform' ? '' : 'hidden'; ?> space-y-4 p-4.5 bg-gradient-to-r from-purple-50/70 to-indigo-50/70 rounded-2xl border border-purple-200/80">
                            <div class="flex justify-between items-center border-b border-purple-200/60 pb-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm">📍</span>
                                    <h4 class="text-xs font-black text-purple-950">تنظیم دقیق مختصات و ابعاد کادر انتخابی (Inspector)</h4>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select id="scs-inspector-select-block" onchange="onInspectorBlockChanged(this.value)" class="text-xs font-black bg-white border border-purple-300 rounded-xl px-2.5 py-1 text-purple-900 outline-none">
                                        <option value="header">هدر و تایتل برند</option>
                                        <option value="meta">نشانگرهای متادیتا (Pills)</option>
                                        <option value="table" selected>کارت جدول اندازه‌ها</option>
                                        <option value="illus">تصویر و راهنمای اندازه‌گیری</option>
                                        <option value="notes">یادداشت و مشاوره سایز</option>
                                        <option value="specs">مشخصات فنی و الیاف</option>
                                        <option value="footer">پانویس و کپی‌رایت</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Numeric Inputs X, Y, W, H, Z, Font Size -->
                            <div class="grid grid-cols-2 sm:grid-cols-6 gap-2.5">
                                <div class="space-y-1">
                                    <label class="block text-[10px] font-black text-purple-900">موقعیت افقی X (Left px)</label>
                                    <input type="number" id="scs-insp-x" oninput="onInspectorInputUpdated()" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-[10px] font-black text-purple-900">موقعیت عمودی Y (Top px)</label>
                                    <input type="number" id="scs-insp-y" oninput="onInspectorInputUpdated()" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                </div>
                                <div class="space-y-1">
                                    <div class="flex justify-between items-center">
                                        <label class="block text-[10px] font-black text-purple-900">عرض Width (px)</label>
                                    </div>
                                    <input type="number" id="scs-insp-w" oninput="onInspectorInputUpdated('w')" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                </div>
                                <div class="space-y-1">
                                    <div class="flex justify-between items-center">
                                        <label class="block text-[10px] font-black text-purple-900">ارتفاع Height (px)</label>
                                        <button type="button" id="scs-insp-lock-ratio-btn" onclick="toggleTableAspectLock()" class="text-[9px] px-1.5 py-0.5 rounded font-black <?php echo ($scs_settings['table_lock_ratio'] ?? 1) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'; ?>" title="قفل تناسب ابعاد"><?php echo ($scs_settings['table_lock_ratio'] ?? 1) ? '🔒 قفل نسبت' : '🔓 نسبت آزاد'; ?></button>
                                    </div>
                                    <input type="number" id="scs-insp-h" oninput="onInspectorInputUpdated('h')" placeholder="خودکار" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                </div>
                                <div class="space-y-1">
                                    <div class="flex justify-between items-center">
                                        <label class="block text-[10px] font-black text-purple-900">اندازه قلم (Font)</label>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" onclick="stepInspectorFontSize(-1)" class="w-6 h-8 flex items-center justify-center bg-white hover:bg-purple-600 hover:text-white rounded-lg border border-purple-200 text-xs font-black cursor-pointer shadow-2xs transition-colors" title="کوچک‌تر">-</button>
                                        <input type="number" id="scs-insp-font-size" oninput="onInspectorFontSizeChanged(this.value)" placeholder="11" min="6" max="48" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                        <button type="button" onclick="stepInspectorFontSize(1)" class="w-6 h-8 flex items-center justify-center bg-white hover:bg-purple-600 hover:text-white rounded-lg border border-purple-200 text-xs font-black cursor-pointer shadow-2xs transition-colors" title="بزرگ‌تر">+</button>
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-[10px] font-black text-purple-900">ترتیب لایه (Z-Index)</label>
                                    <input type="number" id="scs-insp-z" oninput="onInspectorInputUpdated()" min="1" max="100" class="w-full text-xs font-black bg-white border border-purple-200 p-2 rounded-xl text-center outline-none">
                                </div>
                            </div>

                            <!-- Quick Scale Multiplier Row for Selected Block -->
                            <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-purple-100/70">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] font-black text-purple-950">مقیاس کادر انتخابی:</span>
                                    <button type="button" onclick="scaleSelectedBlock(0.8)" class="px-2 py-0.5 bg-white hover:bg-purple-100 rounded-lg border border-purple-200 text-[10px] font-black text-purple-900 shadow-2xs">0.8x</button>
                                    <button type="button" onclick="scaleSelectedBlock(1.0)" class="px-2 py-0.5 bg-white hover:bg-purple-100 rounded-lg border border-purple-200 text-[10px] font-black text-purple-900 shadow-2xs">1.0x (اصلی)</button>
                                    <button type="button" onclick="scaleSelectedBlock(1.2)" class="px-2 py-0.5 bg-white hover:bg-purple-600 hover:text-white rounded-lg border border-purple-200 text-[10px] font-black text-purple-900 shadow-2xs">1.2x</button>
                                    <button type="button" onclick="scaleSelectedBlock(1.5)" class="px-2 py-0.5 bg-white hover:bg-purple-600 hover:text-white rounded-lg border border-purple-200 text-[10px] font-black text-purple-900 shadow-2xs font-black text-amber-700">1.5x</button>
                                    <button type="button" onclick="scaleSelectedBlock(2.0)" class="px-2 py-0.5 bg-white hover:bg-purple-600 hover:text-white rounded-lg border border-purple-200 text-[10px] font-black text-purple-900 shadow-2xs font-black text-emerald-700">2.0x (دوبل)</button>
                                </div>
                                <div class="text-[9px] text-purple-700 font-bold">
                                    (تغییر همگام طول و عرض با حفظ تناسب و مقیاس محتوا)
                                </div>
                            </div>

                            <!-- Quick Alignment & Auto Arrange Buttons -->
                            <div class="flex flex-wrap gap-2 pt-2 border-t border-purple-200/50 items-center justify-between">
                                <div class="flex flex-wrap gap-1.5 items-center">
                                    <button type="button" onclick="alignSelectedBlock('left')" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="چسباندن به راست">⇤ راست‌چین</button>
                                    <button type="button" onclick="alignSelectedBlock('center_h')" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="مرکز افقی">↔️ وسط‌چین افقی</button>
                                    <button type="button" onclick="alignSelectedBlock('right')" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="چسباندن به چپ">⇥ چپ‌چین</button>
                                    <button type="button" onclick="alignSelectedBlock('full_width')" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="عرض کامل">↕️ تمام‌عرض</button>
                                    <button type="button" onclick="setBlockAutoHeight()" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="ارتفاع خودکار مطابق محتوا">↕️ ارتفاع خودکار</button>
                                    <button type="button" onclick="alignSelectedBlock('top')" class="px-2.5 py-1 bg-white hover:bg-purple-100 text-purple-900 text-[10px] font-black rounded-lg border border-purple-200 shadow-2xs" title="چسباندن به بالا">⤒ بالا</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="autoArrangeAllFreeformBlocks()" class="px-3 py-1 bg-purple-600 hover:bg-purple-700 text-white text-[10px] font-black rounded-lg shadow-sm flex items-center gap-1">
                                        <span>🪄</span>
                                        <span>چینش هوشمند متناسب با بوم</span>
                                    </button>
                                    <button type="button" onclick="saveBrandingSettings()" class="px-3.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-black rounded-lg shadow-sm flex items-center gap-1">
                                        <span>💾</span>
                                        <span>ذخیره چیدمان</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Layout Structure Selector (Visible in flow mode) -->
                        <div id="scs-flow-structures-section" class="<?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'freeform' ? 'hidden' : ''; ?> space-y-2.5">
                            <label class="block text-xs font-black text-slate-700">۱. ساختار کلی کادرها روی بوم (Presets):</label>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="layout-modes-group">
                                
                                <button type="button" onclick="setLayoutMode('standard_split')" data-layout="standard_split" class="layout-mode-btn p-3.5 rounded-2xl border text-right transition-all <?php echo ($scs_settings['layout_mode'] ?? 'standard_split') === 'standard_split' ? 'border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-base">📊</span>
                                        <span class="text-xs font-black text-slate-900">چیدمان دو ستونه استاندارد</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">
                                        جدول در ۸ ستون و کادر راهنمای اندازه‌گیری در ۴ ستون کنار آن قرار می‌گیرد.
                                    </p>
                                </button>

                                <button type="button" onclick="setLayoutMode('stacked_wide')" data-layout="stacked_wide" class="layout-mode-btn p-3.5 rounded-2xl border text-right transition-all <?php echo ($scs_settings['layout_mode'] ?? '') === 'stacked_wide' ? 'border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-base">📐</span>
                                        <span class="text-xs font-black text-slate-900">چیدمان عریض (بیشترین فضای جدول)</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">
                                        جدول سایز تمام‌عرض ۱۲ ستون در بالا؛ مناسب جداول با ستون‌های زیاد و خوانایی حداکثری.
                                    </p>
                                </button>

                                <button type="button" onclick="setLayoutMode('table_first')" data-layout="table_first" class="layout-mode-btn p-3.5 rounded-2xl border text-right transition-all <?php echo ($scs_settings['layout_mode'] ?? '') === 'table_first' ? 'border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-base">⚡</span>
                                        <span class="text-xs font-black text-slate-900">چیدمان متمرکز بر جدول</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">
                                        حداکثر فضای عمودی و افقی به جدول داده شده و کارت راهنما به شکل مینیاتوری نمایش داده می‌شود.
                                    </p>
                                </button>

                                <button type="button" onclick="setLayoutMode('modern_bento')" data-layout="modern_bento" class="layout-mode-btn p-3.5 rounded-2xl border text-right transition-all <?php echo ($scs_settings['layout_mode'] ?? '') === 'modern_bento' ? 'border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50 hover:bg-white'; ?>">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-base">🗂️</span>
                                        <span class="text-xs font-black text-slate-900">چیدمان مدرن بنتو (Bento Grid)</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 leading-relaxed">
                                        کارت‌های مجزا با سایه‌های شناور و تفکیک بصری عالی برای معرفی دقیق مشخصات و جدول کالا.
                                    </p>
                                </button>

                            </div>
                        </div>

                        <!-- Drag-and-Drop Visual Block Reordering Builder -->
                        <div class="space-y-3 pt-3 border-t border-slate-100">
                            <div class="flex justify-between items-center">
                                <div>
                                    <label class="block text-xs font-black text-slate-700">ترتیب لایه‌ها و پنهان/آشکارسازی بلوک‌ها</label>
                                    <p class="text-[10px] text-slate-400 mt-0.5">آیتم‌ها را بکشید یا با دکمه‌های ⬆️ ⬇️ و ✕ وضعیت نمایش را تغییر دهید</p>
                                </div>
                                <button type="button" onclick="resetAllBlocksAndLayout()" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-[11px] font-black flex items-center gap-1.5 transition-all shadow-2xs" title="بازنشانی تمامی بلوک‌ها و ترتیب پیش‌فرض">
                                    <span>🔄</span>
                                    <span>بازنشانی پیش‌فرض</span>
                                </button>
                            </div>

                            <!-- Interactive Draggable Block List Container -->
                            <div id="scs-drag-builder-list" class="space-y-2">
                                <!-- Dynamically populated by JS renderDragAndDropBuilder() -->
                            </div>

                            <!-- Hidden / Removed Blocks Drawer / Quick Restore Tray -->
                            <div id="scs-removed-blocks-tray" class="hidden p-3.5 bg-amber-50/70 rounded-2xl border border-dashed border-amber-300 space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="text-[10px] font-black text-amber-900 flex items-center gap-1.5">
                                        <span>👁️‍🗨️</span>
                                        <span>عناصر حذف‌شده از چیدمان (جهت بازگردانی کلیک کنید):</span>
                                    </div>
                                    <button type="button" onclick="resetAllBlocksAndLayout()" class="text-[10px] font-black text-rose-700 hover:text-rose-900 underline flex items-center gap-1">
                                        <span>🔄</span>
                                        <span>بازگردانی همه بخش‌ها</span>
                                    </button>
                                </div>
                                <div id="scs-removed-blocks-chips" class="flex flex-wrap gap-2">
                                    <!-- Restorable Chips -->
                                </div>
                            </div>
                        </div>

                        <!-- Padding & Margin Fine Positioning Controls -->
                        <div id="scs-padding-controls-row" class="<?php echo ($scs_settings['positioning_mode'] ?? 'flow') === 'freeform' ? 'hidden' : ''; ?> space-y-3 pt-3 border-t border-slate-100">
                            <label class="block text-xs font-black text-slate-700">۳. حاشیه امن و فواصل پدینگ محتوا روی پس‌زمینه (Padding & Spacing)</label>
                            
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">پدینگ بالا (Top px)</label>
                                    <input type="number" id="setting-padding-top" value="<?php echo htmlspecialchars($scs_settings['content_padding_top'] ?? 36); ?>" oninput="applyBrandingChanges()" min="0" max="300" step="4" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">پدینگ پایین (Bottom px)</label>
                                    <input type="number" id="setting-padding-bottom" value="<?php echo htmlspecialchars($scs_settings['content_padding_bottom'] ?? 28); ?>" oninput="applyBrandingChanges()" min="0" max="300" step="4" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">پدینگ طرفین (X px)</label>
                                    <input type="number" id="setting-padding-x" value="<?php echo htmlspecialchars($scs_settings['content_padding_x'] ?? 36); ?>" oninput="applyBrandingChanges()" min="0" max="200" step="4" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-1">فاصله بین بلوک‌ها (Gap px)</label>
                                    <input type="number" id="setting-content-gap" value="<?php echo htmlspecialchars($scs_settings['content_gap'] ?? 24); ?>" oninput="applyBrandingChanges()" min="8" max="80" step="2" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Section 3: Custom Theme & Colors (Visible only in custom mode) -->
                    <div id="custom-styling-panel" class="bg-white rounded-3xl p-6 sm:p-7 shadow-sm border border-slate-100 space-y-6 <?php echo ($scs_settings['style_mode'] ?? 'default') === 'default' ? 'opacity-50 pointer-events-none' : ''; ?>">
                        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span class="p-2 bg-amber-50 text-amber-600 rounded-xl text-xs">✨</span>
                                <span>۳. شخصی‌سازی رنگ‌ها، شفافیت و استایل دلخواه</span>
                            </h3>
                            <span class="text-[10px] font-black text-amber-600">حالت دلخواه</span>
                        </div>

                        <!-- Theme Presets -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-700">پریست‌های آماده تم:</label>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2">
                                <button type="button" onclick="applyCustomThemePreset('persepolis')" class="p-2.5 rounded-xl border border-rose-300 hover:border-rose-600 text-center bg-rose-50/50 hover:bg-rose-100/60 transition-all group">
                                    <span class="text-xs group-hover:scale-110 inline-block transition-transform">🔴</span>
                                    <div class="text-[10px] font-black text-rose-800 mt-1">پرسپولیس / اسپرت قرمز</div>
                                </button>
                                <button type="button" onclick="applyCustomThemePreset('glass')" class="p-2.5 rounded-xl border border-slate-200 hover:border-indigo-500 text-center bg-slate-50 transition-all">
                                    <span class="text-xs">💎</span>
                                    <div class="text-[10px] font-black text-slate-700 mt-1">شیشه‌ای مدرن</div>
                                </button>
                                <button type="button" onclick="applyCustomThemePreset('minimal')" class="p-2.5 rounded-xl border border-slate-200 hover:border-indigo-500 text-center bg-slate-50 transition-all">
                                    <span class="text-xs">🤍</span>
                                    <div class="text-[10px] font-black text-slate-700 mt-1">مینیمال روشن</div>
                                </button>
                                <button type="button" onclick="applyCustomThemePreset('dark_luxury')" class="p-2.5 rounded-xl border border-slate-200 hover:border-indigo-500 text-center bg-slate-50 transition-all">
                                    <span class="text-xs">🖤</span>
                                    <div class="text-[10px] font-black text-slate-700 mt-1">تیره لوکس</div>
                                </button>
                                <button type="button" onclick="applyCustomThemePreset('navy')" class="p-2.5 rounded-xl border border-slate-200 hover:border-indigo-500 text-center bg-slate-50 transition-all">
                                    <span class="text-xs">👔</span>
                                    <div class="text-[10px] font-black text-slate-700 mt-1">سرمه‌ای شرکتی</div>
                                </button>
                                <button type="button" onclick="applyCustomThemePreset('transparent')" class="p-2.5 rounded-xl border border-slate-200 hover:border-indigo-500 text-center bg-slate-50 transition-all">
                                    <span class="text-xs">✨</span>
                                    <div class="text-[10px] font-black text-slate-700 mt-1">ترنسپرنت (روی عکس)</div>
                                </button>
                            </div>
                        </div>

                        <!-- Card Styling Properties -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ کارت‌ها (Card BG)</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" id="setting-card-bg-color" value="<?php echo htmlspecialchars($scs_settings['card_bg_color'] ?? '#ffffff'); ?>" oninput="applyBrandingChanges()" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer">
                                    <input type="text" id="setting-card-bg-color-text" value="<?php echo htmlspecialchars($scs_settings['card_bg_color'] ?? '#ffffff'); ?>" oninput="$('#setting-card-bg-color').val(this.value); applyBrandingChanges();" class="w-full text-xs font-mono border border-slate-200 p-2 rounded-lg bg-slate-50" dir="ltr">
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <label class="block text-[10px] font-black text-slate-600">شفافیت کارت‌ها</label>
                                    <span class="text-[10px] font-mono text-indigo-600" id="card-opacity-label"><?php echo htmlspecialchars($scs_settings['card_bg_opacity'] ?? 100); ?>%</span>
                                </div>
                                <input type="range" id="setting-card-bg-opacity" min="0" max="100" step="5" value="<?php echo htmlspecialchars($scs_settings['card_bg_opacity'] ?? 100); ?>" oninput="$('#card-opacity-label').text(this.value + '%'); applyBrandingChanges();" class="w-full accent-indigo-600 mt-2">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">گردی گوشه‌ها (Radius)</label>
                                <select id="setting-card-border-radius" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-2 rounded-xl bg-slate-50 outline-none">
                                    <option value="0" <?php echo ($scs_settings['card_border_radius'] ?? 16) == 0 ? 'selected' : ''; ?>>تیز (0px)</option>
                                    <option value="8" <?php echo ($scs_settings['card_border_radius'] ?? 16) == 8 ? 'selected' : ''; ?>>ملایم (8px)</option>
                                    <option value="16" <?php echo ($scs_settings['card_border_radius'] ?? 16) == 16 ? 'selected' : ''; ?>>مدرن (16px)</option>
                                    <option value="24" <?php echo ($scs_settings['card_border_radius'] ?? 16) == 24 ? 'selected' : ''; ?>>بسیار گرد (24px)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Border & Typography Colors -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ کادر و خطوط</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="color" id="setting-card-border-color" value="<?php echo htmlspecialchars($scs_settings['card_border_color'] ?? '#e2e8f0'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                    <input type="text" id="setting-card-border-color-text" value="<?php echo htmlspecialchars($scs_settings['card_border_color'] ?? '#e2e8f0'); ?>" oninput="$('#setting-card-border-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">متن اصلی (Headings)</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="color" id="setting-text-primary-color" value="<?php echo htmlspecialchars($scs_settings['text_primary_color'] ?? '#0f172a'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                    <input type="text" id="setting-text-primary-color-text" value="<?php echo htmlspecialchars($scs_settings['text_primary_color'] ?? '#0f172a'); ?>" oninput="$('#setting-text-primary-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">متن فرعی (Muted)</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="color" id="setting-text-muted-color" value="<?php echo htmlspecialchars($scs_settings['text_muted_color'] ?? '#64748b'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                    <input type="text" id="setting-text-muted-color-text" value="<?php echo htmlspecialchars($scs_settings['text_muted_color'] ?? '#64748b'); ?>" oninput="$('#setting-text-muted-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ شاخص (Accent)</label>
                                <div class="flex items-center gap-1.5">
                                    <input type="color" id="setting-accent-color" value="<?php echo htmlspecialchars($scs_settings['accent_color'] ?? '#4f46e5'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                    <input type="text" id="setting-accent-color-text" value="<?php echo htmlspecialchars($scs_settings['accent_color'] ?? '#4f46e5'); ?>" oninput="$('#setting-accent-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Table Styling Customization -->
                        <div class="pt-4 border-t border-slate-100 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">📊</span>
                                    <h4 class="text-xs font-black text-slate-800">شخصی‌سازی استایل و رنگ‌بندی جدول سایز</h4>
                                </div>
                                <span class="text-[10px] text-slate-400 font-bold">تغییرات آنی در پیش‌نمایش</span>
                            </div>

                            <!-- Specialized Poster Overlay & Split Freeform Rows Engine -->
                            <div class="p-4 bg-gradient-to-br from-amber-50/90 via-orange-50/50 to-indigo-50/60 rounded-2xl border-2 border-amber-300/80 space-y-4 shadow-xs">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <span class="p-1.5 bg-amber-500 text-white rounded-lg text-xs shadow-2xs">🎯</span>
                                        <div>
                                            <h5 class="text-xs font-black text-amber-950">انطباق دقیق با باکس‌ها و طرح پوستر زمینه (Pure Data & Split Rows)</h5>
                                            <p class="text-[10px] text-amber-800 font-bold">برای زمانی که قالب یا پوستر زمینه از قبل کادرها و خطوط طراحی‌شده دارد و می‌خواهید فقط داده‌ها روی کادرها بنشینند.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" onclick="enablePureDataPreset()" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white text-[10px] font-black rounded-xl shadow-2xs transition-all flex items-center gap-1 cursor-pointer">
                                            <span>⚡ ۱-کلیک: فعال‌سازی حالت دیتای خالص</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Quick Switchers: Pure Data Overlay, Hide Header, Split Rows -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                                    <label class="flex items-start gap-2.5 p-2.5 bg-white/90 rounded-xl border border-amber-200 cursor-pointer hover:bg-white select-none transition-all shadow-2xs">
                                        <input type="checkbox" id="setting-table-pure-data-overlay" value="1" <?php echo !empty($scs_settings['table_pure_data_overlay']) ? 'checked' : ''; ?> onchange="toggleTablePureDataOverlay(this.checked)" class="mt-0.5 w-4 h-4 text-amber-600 rounded border-slate-300">
                                        <div>
                                            <span class="text-xs font-black text-slate-800 block">✨ دیتای بدون بک‌گراند و خطوط</span>
                                            <span class="text-[9px] text-slate-500 font-bold">حذف کامل پس‌زمینه کادر، بوردرها و سایه</span>
                                        </div>
                                    </label>

                                    <label class="flex items-start gap-2.5 p-2.5 bg-white/90 rounded-xl border border-amber-200 cursor-pointer hover:bg-white select-none transition-all shadow-2xs">
                                        <input type="checkbox" id="setting-table-show-header" value="1" <?php echo ($scs_settings['table_show_header'] ?? 1) ? 'checked' : ''; ?> onchange="applyBrandingChanges()" class="mt-0.5 w-4 h-4 text-amber-600 rounded border-slate-300">
                                        <div>
                                            <span class="text-xs font-black text-slate-800 block">🏷️ نمایش هدر و عنوان ستون‌ها</span>
                                            <span class="text-[9px] text-slate-500 font-bold">خاموش کنید اگر هدر روی عکس چاپ شده</span>
                                        </div>
                                    </label>

                                    <label class="flex items-start gap-2.5 p-2.5 bg-white/90 rounded-xl border border-indigo-200 cursor-pointer hover:bg-white select-none transition-all shadow-2xs">
                                        <input type="checkbox" id="setting-table-split-rows" value="1" <?php echo !empty($scs_settings['table_split_rows']) ? 'checked' : ''; ?> onchange="toggleTableSplitRows(this.checked)" class="mt-0.5 w-4 h-4 text-indigo-600 rounded border-slate-300">
                                        <div>
                                            <span class="text-xs font-black text-indigo-950 block">⚡ تفکیک ردیف‌ها به سطرهای آزاد</span>
                                            <span class="text-[9px] text-indigo-600 font-bold">چیدمان و درگ هر ردیف روی باکس متناظر</span>
                                        </div>
                                    </label>

                                    <label class="flex items-start gap-2.5 p-2.5 bg-white/90 rounded-xl border border-violet-300 cursor-pointer hover:bg-white select-none transition-all shadow-2xs">
                                        <input type="checkbox" id="setting-table-split-cells" value="1" <?php echo !empty($scs_settings['table_split_cells']) ? 'checked' : ''; ?> onchange="toggleTableSplitCells(this.checked)" class="mt-0.5 w-4 h-4 text-violet-600 rounded border-slate-300">
                                        <div>
                                            <span class="text-xs font-black text-violet-950 block">⚡⚡ تفکیک سلول‌ها (ماتریس آزاد سلول‌به‌سلول)</span>
                                            <span class="text-[9px] text-violet-700 font-bold">تعیین دقیق جایگاه ستون A1-سطر ۱ و کل ساختار</span>
                                        </div>
                                    </label>
                                </div>

                                <!-- Row Gap / Spacing Slider (For Unified Table Mode) -->
                                <div class="p-3 bg-white/80 rounded-xl border border-amber-200 space-y-2">
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs">↕️</span>
                                            <label class="text-[10px] font-black text-slate-700">فاصله آزاد بین سطرها (Row Gap / Spacing)</label>
                                        </div>
                                        <span class="text-[10px] font-mono font-bold text-amber-700" id="table-row-gap-label"><?php echo htmlspecialchars($scs_settings['table_row_gap'] ?? 0); ?>px</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="range" id="setting-table-row-gap" min="0" max="60" step="2" value="<?php echo htmlspecialchars($scs_settings['table_row_gap'] ?? 0); ?>" oninput="$('#table-row-gap-label').text(this.value + 'px'); applyBrandingChanges();" class="w-full accent-amber-600 cursor-pointer">
                                        <div class="flex gap-1 shrink-0">
                                            <button type="button" onclick="$('#setting-table-row-gap').val(0).trigger('input')" class="px-1.5 py-0.5 text-[9px] bg-slate-100 hover:bg-amber-100 text-slate-700 rounded font-bold cursor-pointer">0</button>
                                            <button type="button" onclick="$('#setting-table-row-gap').val(10).trigger('input')" class="px-1.5 py-0.5 text-[9px] bg-slate-100 hover:bg-amber-100 text-slate-700 rounded font-bold cursor-pointer">10px</button>
                                            <button type="button" onclick="$('#setting-table-row-gap').val(18).trigger('input')" class="px-1.5 py-0.5 text-[9px] bg-slate-100 hover:bg-amber-100 text-slate-700 rounded font-bold cursor-pointer">18px</button>
                                            <button type="button" onclick="$('#setting-table-row-gap').val(28).trigger('input')" class="px-1.5 py-0.5 text-[9px] bg-slate-100 hover:bg-amber-100 text-slate-700 rounded font-bold cursor-pointer">28px</button>
                                        </div>
                                    </div>
                                    <p class="text-[9px] text-slate-500 font-bold">فاصله عمودی بین سطرهای جدول را باز کنید تا هر سطر دقیقاً روی کادر یا نوار افقی متناظر خود در پوستر بنشیند.</p>
                                </div>

                                <!-- Quick Align Tools for Split Rows Mode -->
                                <div id="split-rows-tools-panel" class="<?php echo !empty($scs_settings['table_split_rows']) ? '' : 'hidden'; ?> p-3 bg-indigo-50/80 rounded-xl border border-indigo-200 flex flex-wrap items-center justify-between gap-2.5">
                                    <div class="text-[10px] font-black text-indigo-950 flex items-center gap-1.5">
                                        <span>🛠️ ابزارهای سریع چیدمان سطرهای آزاد:</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <!-- Quick Font Size for Split Rows -->
                                        <div class="flex items-center gap-1 bg-white px-2.5 py-1 rounded-lg border border-indigo-200 shadow-2xs">
                                            <span class="text-[10px] font-black text-indigo-950 flex items-center gap-1"><span>🔤</span><span>قلم:</span></span>
                                            <button type="button" onclick="adjustSplitRowsFontSize(-1)" class="w-6 h-6 flex items-center justify-center bg-slate-100 hover:bg-indigo-600 hover:text-white rounded text-xs font-black transition-colors cursor-pointer" title="کاهش اندازه قلم">A-</button>
                                            <input type="number" id="split-rows-font-size-input" value="<?php echo htmlspecialchars($scs_settings['table_font_size'] ?? 11); ?>" min="6" max="48" oninput="setSplitRowsFontSize(this.value)" class="w-11 text-center text-xs font-black border border-indigo-200 rounded px-1 py-0.5 outline-none">
                                            <span class="text-[9px] text-slate-500 font-bold">px</span>
                                            <button type="button" onclick="adjustSplitRowsFontSize(1)" class="w-6 h-6 flex items-center justify-center bg-slate-100 hover:bg-indigo-600 hover:text-white rounded text-xs font-black transition-colors cursor-pointer" title="افزایش اندازه قلم">A+</button>
                                        </div>
                                        <button type="button" onclick="distributeSplitRows(12)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs cursor-pointer">📐 توزیع منظم عمودی (فاصله ۱۲px)</button>
                                        <button type="button" onclick="distributeSplitRows(20)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs cursor-pointer">📐 توزیع بازتر (۲۰px)</button>
                                        <button type="button" onclick="alignSplitRows()" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs cursor-pointer">↔️ هم‌راستا کردن چپ و عرض ردیف‌ها</button>
                                        <button type="button" onclick="resetSplitRowsPositions()" class="px-2 py-1 bg-white hover:bg-rose-600 hover:text-white text-rose-800 text-[10px] font-black rounded-lg border border-rose-200 transition-colors shadow-2xs cursor-pointer">↺ بازنشانی موقعیت ردیف‌ها</button>
                                    </div>
                                </div>

                                <!-- Matrix Structure & Cell-by-Cell Layout Controller Panel -->
                                <div id="matrix-structure-panel" class="<?php echo !empty($scs_settings['table_split_cells']) ? '' : 'hidden'; ?> p-4 bg-gradient-to-br from-violet-50/95 via-indigo-50/50 to-slate-50 rounded-2xl border-2 border-violet-300 shadow-md space-y-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-violet-200 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xl">🗂️</span>
                                            <div>
                                                <h4 class="text-xs font-black text-violet-950">میز کار و چیدمان ساختار ماتریسی تک‌تک سلول‌ها (Matrix Coordinates)</h4>
                                                <p class="text-[10px] text-violet-700 font-bold">موقعیت دقیق ستون A1، سطر A1، ستون B1 و سایر مقادیر را مشخص کنید یا مستقیماً روی بوم درگ نمایید.</p>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <!-- Quick Font Size for Matrix Cells -->
                                            <div class="flex items-center gap-1 bg-white px-2.5 py-1 rounded-xl border border-violet-200 shadow-2xs">
                                                <span class="text-[10px] font-black text-violet-950 flex items-center gap-1"><span>🔤</span><span>قلم سلول‌ها:</span></span>
                                                <button type="button" onclick="adjustSplitRowsFontSize(-1)" class="w-6 h-6 flex items-center justify-center bg-slate-100 hover:bg-violet-600 hover:text-white rounded text-xs font-black transition-colors cursor-pointer" title="کاهش اندازه قلم">A-</button>
                                                <input type="number" id="matrix-font-size-input" value="<?php echo htmlspecialchars($scs_settings['table_font_size'] ?? 11); ?>" min="6" max="48" oninput="setSplitRowsFontSize(this.value)" class="w-11 text-center text-xs font-black border border-violet-200 rounded px-1 py-0.5 outline-none">
                                                <span class="text-[9px] text-slate-500 font-bold">px</span>
                                                <button type="button" onclick="adjustSplitRowsFontSize(1)" class="w-6 h-6 flex items-center justify-center bg-slate-100 hover:bg-violet-600 hover:text-white rounded text-xs font-black transition-colors cursor-pointer" title="افزایش اندازه قلم">A+</button>
                                            </div>
                                            <button type="button" onclick="toggleCellCoordBadges()" id="btn-toggle-cell-badges" class="px-2.5 py-1.5 bg-white hover:bg-violet-600 hover:text-white text-violet-900 text-[10px] font-black rounded-xl border border-violet-200 transition-all shadow-2xs cursor-pointer flex items-center gap-1">
                                                <span>🏷️</span>
                                                <span id="label-cell-badges">نمایش برچسب مختصات روی بوم</span>
                                            </button>
                                            <button type="button" onclick="resetMatrixToTable()" class="px-2.5 py-1.5 bg-white hover:bg-rose-600 hover:text-white text-rose-800 text-[10px] font-black rounded-xl border border-rose-200 transition-all shadow-2xs cursor-pointer flex items-center gap-1">
                                                <span>↺</span>
                                                <span>بازنشانی به شبکه منظم</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Section 1: Column X Coordinates & Widths -->
                                    <div class="bg-white/95 p-3.5 rounded-xl border border-violet-200 shadow-2xs space-y-2.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm">↔️</span>
                                                <span class="text-xs font-black text-slate-800">موقعیت افقی ستون‌ها (Columns X & Width):</span>
                                                <span class="text-[10px] text-slate-500 font-bold">(تعیین جایگاه X برای ستون A1, B1, C1...)</span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <button type="button" onclick="distributeMatrixCols()" class="px-2 py-1 bg-violet-100 hover:bg-violet-600 hover:text-white text-violet-900 text-[10px] font-bold rounded-lg transition-colors cursor-pointer">📐 تنظیم فواصل مساوی بین ستون‌ها</button>
                                            </div>
                                        </div>
                                        <div id="matrix-columns-controls" class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
                                            <!-- Populated dynamically by renderMatrixStructurePanel() -->
                                        </div>
                                    </div>

                                    <!-- Section 2: Row Y Coordinates & Heights -->
                                    <div class="bg-white/95 p-3.5 rounded-xl border border-violet-200 shadow-2xs space-y-2.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm">↕️</span>
                                                <span class="text-xs font-black text-slate-800">موقعیت عمودی سطرها (Rows Y & Height):</span>
                                                <span class="text-[10px] text-slate-500 font-bold">(تعیین جایگاه Y برای سطر ۱, ۲, ۳, ۴...)</span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <button type="button" onclick="distributeMatrixRows()" class="px-2 py-1 bg-violet-100 hover:bg-violet-600 hover:text-white text-violet-900 text-[10px] font-bold rounded-lg transition-colors cursor-pointer">📐 تنظیم فواصل مساوی بین سطرها</button>
                                            </div>
                                        </div>
                                        <div id="matrix-rows-controls" class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
                                            <!-- Populated dynamically by renderMatrixStructurePanel() -->
                                        </div>
                                    </div>

                                    <!-- Big Action Button: Apply Structure -->
                                    <div class="flex flex-wrap items-center justify-between gap-2 p-3 bg-gradient-to-r from-violet-600 to-indigo-600 text-white rounded-xl shadow-sm">
                                        <div class="text-[11px] font-bold flex items-center gap-2">
                                            <span>⚡</span>
                                            <span>با کلیک روی این دکمه، تمام سلول‌ها دقیقاً در تقاطع ستون‌ها و سطرهای تعریف‌شده قرار می‌گیرند:</span>
                                        </div>
                                        <button type="button" onclick="applyMatrixGridStructure()" class="px-4 py-2 bg-white text-violet-900 hover:bg-amber-400 hover:text-slate-900 text-xs font-black rounded-lg shadow-md transition-all cursor-pointer flex items-center gap-1.5">
                                            <span>🚀</span>
                                            <span>اعمال و چیدمان کل ساختار روی بوم</span>
                                        </button>
                                    </div>

                                    <!-- Section 3: Full 2D Interactive Matrix Table -->
                                    <div class="bg-white/95 p-3.5 rounded-xl border border-violet-200 shadow-2xs space-y-2">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-sm">🎯</span>
                                                <span class="text-xs font-black text-slate-800">ماتریس تعاملی و ویرایش تک‌تک سلول‌ها:</span>
                                                <span class="text-[10px] text-slate-500 font-bold">(برای کادرهای نامتقارن پوستر یا ویرایش مستقل یک سلول)</span>
                                            </div>
                                            <span class="text-[10px] text-violet-600 font-bold">با کلیک روی دکمه 🎯، سلول مربوطه روی بوم پیش‌نمایش به حالت انتخاب درمی‌آید</span>
                                        </div>
                                        <div class="overflow-x-auto max-h-72 overflow-y-auto border border-slate-200 rounded-xl" id="matrix-cells-grid-wrapper">
                                            <table class="w-full text-xs text-center border-collapse" id="matrix-cells-grid-table">
                                                <!-- Dynamically rendered by renderMatrixStructurePanel() -->
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Row 1: Transparency & Table Container -->
                            <div class="p-3.5 bg-slate-50/80 rounded-2xl border border-slate-200/70 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox" id="setting-table-bg-transparent" value="1" <?php echo !empty($scs_settings['table_bg_transparent']) ? 'checked' : ''; ?> onchange="applyBrandingChanges()" class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
                                        <span class="text-xs font-black text-slate-800">✨ جدول شیشه‌ای / ترنسپرنت (بدون رنگ پس‌زمینه، مناسب پوستر و عکس)</span>
                                    </label>
                                    <span class="text-[10px] text-slate-400">کادر جدول را روی عکس پس‌زمینه شفاف می‌کند</span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ پس‌زمینه کادر جدول</label>
                                        <div class="flex items-center gap-1.5">
                                            <input type="color" id="setting-table-bg-color" value="<?php echo htmlspecialchars($scs_settings['table_bg_color'] ?? '#ffffff'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                            <input type="text" id="setting-table-bg-color-text" value="<?php echo htmlspecialchars($scs_settings['table_bg_color'] ?? '#ffffff'); ?>" oninput="$('#setting-table-bg-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-white" dir="ltr">
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] font-black text-slate-600">شفافیت پس‌زمینه جدول</label>
                                            <span class="text-[10px] font-mono text-indigo-600" id="table-bg-opacity-label"><?php echo htmlspecialchars($scs_settings['table_bg_opacity'] ?? 100); ?>%</span>
                                        </div>
                                        <input type="range" id="setting-table-bg-opacity" min="0" max="100" step="5" value="<?php echo htmlspecialchars($scs_settings['table_bg_opacity'] ?? 100); ?>" oninput="$('#table-bg-opacity-label').text(this.value + '%'); applyBrandingChanges();" class="w-full accent-indigo-600">
                                    </div>
                                </div>
                            </div>

                            <!-- Row 2: Header & Cell Colors -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">پس‌زمینه هدر جدول</label>
                                    <div class="flex items-center gap-1.5">
                                        <input type="color" id="setting-table-header-bg" value="<?php echo htmlspecialchars($scs_settings['table_header_bg'] ?? '#f8fafc'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                        <input type="text" id="setting-table-header-bg-text" value="<?php echo htmlspecialchars($scs_settings['table_header_bg'] ?? '#f8fafc'); ?>" oninput="$('#setting-table-header-bg').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ متن هدر جدول</label>
                                    <div class="flex items-center gap-1.5">
                                        <input type="color" id="setting-table-header-text" value="<?php echo htmlspecialchars($scs_settings['table_header_text'] ?? '#1e293b'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                        <input type="text" id="setting-table-header-text-text" value="<?php echo htmlspecialchars($scs_settings['table_header_text'] ?? '#1e293b'); ?>" oninput="$('#setting-table-header-text').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ ارقام و متن سلول‌ها</label>
                                    <div class="flex items-center gap-1.5">
                                        <input type="color" id="setting-table-cell-text" value="<?php echo htmlspecialchars($scs_settings['table_cell_text'] ?? $scs_settings['text_primary_color'] ?? '#1e293b'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                        <input type="text" id="setting-table-cell-text-text" value="<?php echo htmlspecialchars($scs_settings['table_cell_text'] ?? $scs_settings['text_primary_color'] ?? '#1e293b'); ?>" oninput="$('#setting-table-cell-text').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">سطرهای متناوب جدول</label>
                                    <div class="flex items-center gap-1.5">
                                        <input type="color" id="setting-table-row-alt-bg" value="<?php echo htmlspecialchars($scs_settings['table_row_alt_bg'] ?? '#fcfdfe'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                        <input type="text" id="setting-table-row-alt-bg-text" value="<?php echo htmlspecialchars($scs_settings['table_row_alt_bg'] ?? '#fcfdfe'); ?>" oninput="$('#setting-table-row-alt-bg').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                    </div>
                                </div>
                            </div>

                            <!-- Row 3: Borders, Padding, Font-Size & Alignment -->
                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">رنگ خطوط و مرزها</label>
                                    <div class="flex items-center gap-1.5">
                                        <input type="color" id="setting-table-border-color" value="<?php echo htmlspecialchars($scs_settings['table_border_color'] ?? '#e2e8f0'); ?>" oninput="applyBrandingChanges()" class="w-7 h-7 rounded border border-slate-200 cursor-pointer">
                                        <input type="text" id="setting-table-border-color-text" value="<?php echo htmlspecialchars($scs_settings['table_border_color'] ?? '#e2e8f0'); ?>" oninput="$('#setting-table-border-color').val(this.value); applyBrandingChanges();" class="w-full text-[11px] font-mono border border-slate-200 p-1.5 rounded bg-slate-50" dir="ltr">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">ضخامت خطوط جدول</label>
                                    <select id="setting-table-border-width" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-1.5 rounded-lg bg-slate-50 outline-none">
                                        <option value="0" <?php echo ($scs_settings['table_border_width'] ?? 1) == 0 ? 'selected' : ''; ?>>بدون خط (0px)</option>
                                        <option value="1" <?php echo ($scs_settings['table_border_width'] ?? 1) == 1 ? 'selected' : ''; ?>>خط باریک (1px)</option>
                                        <option value="2" <?php echo ($scs_settings['table_border_width'] ?? 1) == 2 ? 'selected' : ''; ?>>خط ضخیم (2px)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">فاصله سلول‌ها (ارتفاع سطر)</label>
                                    <select id="setting-table-cell-padding" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-1.5 rounded-lg bg-slate-50 outline-none">
                                        <option value="4" <?php echo ($scs_settings['table_cell_padding'] ?? 8) == 4 ? 'selected' : ''; ?>>بسیار فشرده (4px)</option>
                                        <option value="6" <?php echo ($scs_settings['table_cell_padding'] ?? 8) == 6 ? 'selected' : ''; ?>>فشرده (6px)</option>
                                        <option value="8" <?php echo ($scs_settings['table_cell_padding'] ?? 8) == 8 ? 'selected' : ''; ?>>استاندارد (8px)</option>
                                        <option value="12" <?php echo ($scs_settings['table_cell_padding'] ?? 8) == 12 ? 'selected' : ''; ?>>راحت (12px)</option>
                                        <option value="16" <?php echo ($scs_settings['table_cell_padding'] ?? 8) == 16 ? 'selected' : ''; ?>>جادار (16px)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">اندازه فونت ارقام جدول</label>
                                    <div class="flex items-center gap-1">
                                        <button type="button" onclick="adjustSplitRowsFontSize(-1)" class="w-7 h-7 flex items-center justify-center bg-slate-100 hover:bg-indigo-600 hover:text-white rounded-lg border border-slate-200 text-xs font-black cursor-pointer transition-colors" title="کوچک‌تر">-</button>
                                        <select id="setting-table-font-size" onchange="setSplitRowsFontSize(this.value)" class="w-full text-xs font-bold border border-slate-200 p-1.5 rounded-lg bg-slate-50 outline-none">
                                            <option value="8" <?php echo ($scs_settings['table_font_size'] ?? 11) == 8 ? 'selected' : ''; ?>>خیلی ریز (8px)</option>
                                            <option value="9" <?php echo ($scs_settings['table_font_size'] ?? 11) == 9 ? 'selected' : ''; ?>>کوچک (9px)</option>
                                            <option value="10" <?php echo ($scs_settings['table_font_size'] ?? 11) == 10 ? 'selected' : ''; ?>>متوسط (10px)</option>
                                            <option value="11" <?php echo ($scs_settings['table_font_size'] ?? 11) == 11 ? 'selected' : ''; ?>>استاندارد (11px)</option>
                                            <option value="12" <?php echo ($scs_settings['table_font_size'] ?? 11) == 12 ? 'selected' : ''; ?>>بزرگ (12px)</option>
                                            <option value="13" <?php echo ($scs_settings['table_font_size'] ?? 11) == 13 ? 'selected' : ''; ?>>بزرگ‌تر (13px)</option>
                                            <option value="14" <?php echo ($scs_settings['table_font_size'] ?? 11) == 14 ? 'selected' : ''; ?>>خیلی بزرگ (14px)</option>
                                            <option value="16" <?php echo ($scs_settings['table_font_size'] ?? 11) == 16 ? 'selected' : ''; ?>>درشت (16px)</option>
                                            <option value="18" <?php echo ($scs_settings['table_font_size'] ?? 11) == 18 ? 'selected' : ''; ?>>بسیار درشت (18px)</option>
                                            <option value="20" <?php echo ($scs_settings['table_font_size'] ?? 11) == 20 ? 'selected' : ''; ?>>عنوان سایز (20px)</option>
                                            <option value="24" <?php echo ($scs_settings['table_font_size'] ?? 11) == 24 ? 'selected' : ''; ?>>بزرگ ویژه (24px)</option>
                                            <option value="28" <?php echo ($scs_settings['table_font_size'] ?? 11) == 28 ? 'selected' : ''; ?>>ماکزیمم (28px)</option>
                                        </select>
                                        <button type="button" onclick="adjustSplitRowsFontSize(1)" class="w-7 h-7 flex items-center justify-center bg-slate-100 hover:bg-indigo-600 hover:text-white rounded-lg border border-slate-200 text-xs font-black cursor-pointer transition-colors" title="بزرگ‌تر">+</button>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 mb-1">تراز افقی ارقام</label>
                                    <select id="setting-table-text-align" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-1.5 rounded-lg bg-slate-50 outline-none">
                                        <option value="center" <?php echo ($scs_settings['table_text_align'] ?? 'center') === 'center' ? 'selected' : ''; ?>>وسط‌چین (Center)</option>
                                        <option value="right" <?php echo ($scs_settings['table_text_align'] ?? 'center') === 'right' ? 'selected' : ''; ?>>راست‌چین (Right)</option>
                                        <option value="left" <?php echo ($scs_settings['table_text_align'] ?? 'center') === 'left' ? 'selected' : ''; ?>>چپ‌چین (Left)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Row 4: Dynamic Auto-Scale & Row Fitting Engine -->
                            <div class="p-4 bg-gradient-to-br from-indigo-50/90 via-purple-50/50 to-slate-50/80 rounded-2xl border-2 border-indigo-200 space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox" id="setting-table-auto-scale" value="1" <?php echo ($scs_settings['table_auto_scale'] ?? 1) ? 'checked' : ''; ?> onchange="applyBrandingChanges()" class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
                                        <span class="text-xs font-black text-indigo-950">⚡ مقیاس‌بندی و انطباق داینامیک ابعاد جدول با بک‌گراند (Dynamic Auto-Scale)</span>
                                    </label>
                                    <span class="px-2.5 py-0.5 bg-indigo-600 text-white text-[10px] font-black rounded-full shadow-2xs">هوشمند و خودکار</span>
                                </div>
                                <p class="text-[11px] text-slate-600 leading-relaxed font-bold">
                                    ابعاد کارت و ارتفاع ردیف‌های جدول متناسب با تعداد سایزهای هر محصول به‌صورت داینامیک محاسبه می‌شود. برای محصولات با سایز کمتر (۱ الی ۳ سایز)، ارتفاع سطرها افزایش می‌یابد تا فضای کارت پر شده و سفید خالی نماند؛ و برای محصولات با سایز زیاد (۶ الی ۱۰ سایز)، ارتفاع سطرها خودکار کاهش می‌یابد تا کل جدول دقیقاً درون محدوده مشخص‌شده بک‌گراند باقی بماند.
                                </p>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-700 mb-1">الگوی انطباق (Scaling Mode)</label>
                                        <select id="setting-table-scale-mode" onchange="applyBrandingChanges()" class="w-full text-xs font-bold border border-indigo-200 p-2 rounded-xl bg-white outline-none">
                                            <option value="smart_fit" <?php echo ($scs_settings['table_scale_mode'] ?? 'smart_fit') === 'smart_fit' ? 'selected' : ''; ?>>انطباق هوشمند سطر و کادر (پیشنهادی)</option>
                                            <option value="fixed_frame" <?php echo ($scs_settings['table_scale_mode'] ?? 'smart_fit') === 'fixed_frame' ? 'selected' : ''; ?>>کادر ثابت ۱۰۰٪ (توزیع کامل در کل ارتفاع محدوده)</option>
                                            <option value="proportional" <?php echo ($scs_settings['table_scale_mode'] ?? 'smart_fit') === 'proportional' ? 'selected' : ''; ?>>مقیاس گام‌به‌گام استاندارد</option>
                                        </select>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] font-black text-slate-700">محدوده ارتفاع کادر در بک‌گراند</label>
                                            <span class="text-[10px] font-mono font-bold text-indigo-700" id="table-target-height-label"><?php echo htmlspecialchars($scs_settings['table_target_height'] ?? 360); ?>px</span>
                                        </div>
                                        <input type="range" id="setting-table-target-height" min="200" max="600" step="10" value="<?php echo htmlspecialchars($scs_settings['table_target_height'] ?? 360); ?>" oninput="$('#table-target-height-label').text(this.value + 'px'); applyBrandingChanges();" class="w-full accent-indigo-600">
                                        <div class="flex justify-between text-[9px] text-slate-400 mt-0.5">
                                            <button type="button" onclick="$('#setting-table-target-height').val(300).trigger('input');" class="hover:text-indigo-600">۳۰۰px</button>
                                            <button type="button" onclick="$('#setting-table-target-height').val(360).trigger('input');" class="hover:text-indigo-600 font-bold text-indigo-600">۳۶۰px (استاندارد)</button>
                                            <button type="button" onclick="$('#setting-table-target-height').val(420).trigger('input');" class="hover:text-indigo-600">۴۲۰px</button>
                                            <button type="button" onclick="$('#setting-table-target-height').val(480).trigger('input');" class="hover:text-indigo-600">۴۸۰px</button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-700 mb-1">تست زنده تعداد سایز در پیش‌نمایش</label>
                                        <div class="grid grid-cols-4 gap-1 pt-1">
                                            <button type="button" onclick="testTableScaleRowCount(1)" class="px-1.5 py-1.5 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs text-center" title="تست حالت ۱ سایز (فری سایز)">۱ سایز</button>
                                            <button type="button" onclick="testTableScaleRowCount(2)" class="px-1.5 py-1.5 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs text-center" title="تست حالت ۲ سایز (M, L)">۲ سایز</button>
                                            <button type="button" onclick="testTableScaleRowCount(4)" class="px-1.5 py-1.5 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs text-center" title="تست حالت ۴ سایز (S تا XL)">۴ سایز</button>
                                            <button type="button" onclick="testTableScaleRowCount(7)" class="px-1.5 py-1.5 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs text-center" title="تست حالت ۷ سایز (سویشرت ست)">۷ سایز</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 5: Proportional Scale & Multiplier Engine (مقیاس کلی و چند برابر کردن با حفظ نسبت طول و عرض) -->
                                <div class="mt-3 p-4 bg-white/90 rounded-2xl border-2 border-indigo-200 space-y-3 shadow-xs">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span class="p-1.5 bg-indigo-600 text-white rounded-lg text-xs">📐</span>
                                            <div>
                                                <span class="text-xs font-black text-indigo-950">مقیاس کلی و بزرگ‌نمایی جدول (Scale & Aspect Ratio Multiplier)</span>
                                                <p class="text-[10px] text-slate-500 font-bold">بزرگتر کردن جدول با حفظ دقیق نسبت طول و عرض چیده شده؛ فونت‌ها، ارتفاع سطرها و فواصل همگام بزرگ می‌شوند.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <label class="flex items-center gap-1.5 cursor-pointer bg-slate-50 hover:bg-white px-2.5 py-1 rounded-xl border border-indigo-200 select-none shadow-2xs transition-colors">
                                                <input type="checkbox" id="setting-table-lock-ratio" value="1" <?php echo ($scs_settings['table_lock_ratio'] ?? 1) ? 'checked' : ''; ?> onchange="toggleTableAspectLock(this.checked)" class="w-3.5 h-3.5 text-indigo-600 rounded border-slate-300">
                                                <span class="text-[10px] font-black text-indigo-900">🔒 قفل نسبت طول و عرض</span>
                                            </label>
                                            <span class="px-2.5 py-1 bg-indigo-600 text-white text-[11px] font-mono font-black rounded-xl shadow-2xs" id="table-scale-label"><?php echo htmlspecialchars($scs_settings['table_scale'] ?? 100); ?>% (<?php echo number_format(($scs_settings['table_scale'] ?? 100)/100, 2); ?>x)</span>
                                        </div>
                                    </div>

                                    <!-- Slider + Quick Preset Multipliers -->
                                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center pt-1">
                                        <div class="sm:col-span-7 flex items-center gap-2">
                                            <button type="button" onclick="scaleSelectedBlock(0.9)" class="px-2.5 py-1.5 bg-slate-50 hover:bg-indigo-50 text-indigo-900 text-xs font-black rounded-xl border border-indigo-200 shrink-0 shadow-2xs" title="کوچکتر کردن ۱۰٪">🔍-</button>
                                            <input type="range" id="setting-table-scale" min="50" max="300" step="5" value="<?php echo htmlspecialchars($scs_settings['table_scale'] ?? 100); ?>" oninput="setTableScale(this.value)" class="w-full accent-indigo-600 cursor-pointer">
                                            <button type="button" onclick="scaleSelectedBlock(1.1)" class="px-2.5 py-1.5 bg-slate-50 hover:bg-indigo-50 text-indigo-900 text-xs font-black rounded-xl border border-indigo-200 shrink-0 shadow-2xs" title="بزرگتر کردن ۱۰٪">🔍+</button>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <input type="number" id="setting-table-scale-num" min="50" max="300" step="5" value="<?php echo htmlspecialchars($scs_settings['table_scale'] ?? 100); ?>" oninput="setTableScale(this.value)" class="w-16 text-center text-xs font-black bg-slate-50 border border-indigo-200 p-1.5 rounded-xl outline-none">
                                                <span class="text-xs font-black text-indigo-900">%</span>
                                            </div>
                                        </div>

                                        <div class="sm:col-span-5 flex flex-wrap gap-1 justify-end">
                                            <button type="button" onclick="setTableScale(80)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs">0.8x</button>
                                            <button type="button" onclick="setTableScale(100)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs font-bold">1.0x (اصلی)</button>
                                            <button type="button" onclick="setTableScale(125)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs">1.25x</button>
                                            <button type="button" onclick="setTableScale(150)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs font-black text-amber-700">1.5x</button>
                                            <button type="button" onclick="setTableScale(200)" class="px-2 py-1 bg-white hover:bg-indigo-600 hover:text-white text-indigo-900 text-[10px] font-black rounded-lg border border-indigo-200 transition-colors shadow-2xs font-black text-emerald-700">2.0x (دوبل)</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Section 4: Brand Identity & Logo -->
                    <div class="bg-white rounded-3xl p-6 sm:p-7 shadow-sm border border-slate-100 space-y-6">
                        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span class="p-2 bg-purple-50 text-purple-600 rounded-xl text-xs">🏷️</span>
                                <span>۴. هویت و مشخصات متنی برند (Brand Identity)</span>
                            </h3>
                            <span class="text-[10px] font-bold text-slate-400">هدر و پانویس</span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">نام تجاری برند (هدر تصویر)</label>
                                <input type="text" id="setting-brand-title" value="<?php echo htmlspecialchars($scs_settings['brand_title']); ?>" oninput="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none">
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">توضیح هدر برند (زیرعنوان)</label>
                                <input type="text" id="setting-brand-subtitle" value="<?php echo htmlspecialchars($scs_settings['brand_subtitle']); ?>" oninput="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none">
                            </div>

                            <div class="space-y-1.5 md:col-span-2">
                                <label class="block text-xs font-black text-slate-700">شعار برند (پانویس تصویر)</label>
                                <input type="text" id="setting-brand-tagline" value="<?php echo htmlspecialchars($scs_settings['brand_tagline']); ?>" oninput="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none">
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">آدرس وب‌سایت (پانویس چپ)</label>
                                <input type="text" id="setting-brand-website" value="<?php echo htmlspecialchars($scs_settings['brand_website']); ?>" oninput="applyBrandingChanges()" class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none" dir="ltr">
                            </div>

                            <!-- Logo upload or URL -->
                            <div class="space-y-1.5">
                                <label class="block text-xs font-black text-slate-700">لوگوی اختصاصی برند (اختیاری)</label>
                                <div class="flex items-center gap-2">
                                    <input type="text" id="setting-image-logo" value="<?php echo htmlspecialchars($scs_settings['image_logo']); ?>" oninput="onLogoUrlChanged(this.value)" placeholder="uploads/logo.png..." class="w-full text-xs font-bold border border-slate-200 p-3 rounded-xl bg-slate-50 focus:bg-white outline-none" dir="ltr">
                                    <label class="cursor-pointer px-3 py-3 bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-black rounded-xl transition-all text-center border border-purple-200/50 shrink-0">
                                        <span>📤</span>
                                        <input type="file" id="logo-file-input" accept="image/*" class="hidden" onchange="uploadLogoFile(this)">
                                    </label>
                                    <button type="button" onclick="removeLogoImage()" title="حذف لوگو" class="p-3 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 rounded-xl transition-all shrink-0">
                                        🗑️
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Live Responsive Studio Preview Pane -->
                <div class="lg:col-span-5 space-y-6">
                    <div class="sticky top-6 bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
                        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                            <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <span>👁️</span>
                                <span>پیش‌نمایش زنده در استودیو</span>
                            </h3>
                            <button type="button" onclick="switchTab('editor')" class="text-[10px] font-black text-indigo-600 hover:text-indigo-800 bg-indigo-50 px-2.5 py-1 rounded-lg">
                                مشاهده در ویرایشگر اصلی ↗
                            </button>
                        </div>

                        <!-- Mini Interactive Canvas Preview in Tab 4 -->
                        <div id="branding-preview-viewport" class="bg-slate-100 p-3 sm:p-4 rounded-2xl flex justify-center items-start overflow-hidden min-h-[500px] relative">
                            <div id="branding-preview-scaler" class="origin-top transition-transform flex justify-center">
                                <div id="branding-live-preview" class="scs-canvas relative bg-white rounded-2xl overflow-hidden shadow-lg border border-slate-200 transition-all <?php echo $isTableSplitInitial ? 'scs-table-split-active' : ''; ?>" dir="rtl" style="font-family: 'Vazirmatn', sans-serif;">
                                    <div id="scs-snap-x-branding" class="scs-snap-guide-x"></div>
                                    <div id="scs-snap-y-branding" class="scs-snap-guide-y"></div>
                                    <!-- Dynamic overlay layer -->
                                    <div id="branding-preview-overlay" class="absolute inset-0 pointer-events-none transition-opacity"></div>

                                    <!-- Content -->
                                    <div id="branding-preview-content" class="relative z-10 p-6 flex flex-col justify-between h-full space-y-4">
                                        
                                        <!-- Header -->
                                        <!-- 1. Header -->
                                        <div id="bp-header" data-scs-block="header" class="scs-card flex justify-between items-center border-b-2 pb-3 w-full">
                                            <div id="bp-brand-header-area">
                                                <div class="text-lg font-black text-slate-900" id="bp-brand-title"><?php echo htmlspecialchars($scs_settings['brand_title']); ?></div>
                                                <div class="text-[9px] font-black text-slate-400" id="bp-brand-subtitle"><?php echo htmlspecialchars($scs_settings['brand_subtitle']); ?></div>
                                            </div>
                                            <div class="text-left">
                                                <div class="text-xs font-black text-slate-900">پیراهن کتان الیاف طبیعی</div>
                                                <div class="text-[9px] text-slate-400">جدول راهنمای اندازه‌گیری</div>
                                            </div>
                                        </div>

                                        <!-- 2. Meta pills -->
                                        <div id="bp-meta" data-scs-block="meta" class="flex flex-wrap gap-2 w-full">
                                            <span class="scs-pill px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-lg text-[9px] font-black text-slate-700">دسته‌بندی: پیراهن مردانه</span>
                                            <span class="scs-pill px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-lg text-[9px] font-black text-slate-700">مدل: کلاسیک آزاد</span>
                                        </div>

                                        <!-- 3 & 4. Table & Illustration Grid Wrapper -->
                                        <div id="bp-grid-wrapper" class="scs-grid-wrapper scs-split-grid w-full flex flex-row items-stretch gap-3 transition-all" style="box-sizing: border-box; width: 100%;">
                                            <!-- 3. Table Preview -->
                                            <div id="bp-table-card" data-scs-block="table" class="scs-card scs-dynamic-table-card bg-white border border-slate-200 rounded-xl overflow-hidden <?php echo $isTableSplitInitial ? 'hidden' : ''; ?>" <?php echo $isTableSplitInitial ? 'style="display: none !important;"' : ''; ?>>
                                                <table class="w-full text-right text-[10px]">
                                                    <thead class="bg-slate-50 text-slate-800 font-black border-b border-slate-200">
                                                        <tr>
                                                            <th class="p-2 text-center border-l border-slate-200">سایز</th>
                                                            <th class="p-2 text-center border-l border-slate-200">عرض سینه</th>
                                                            <th class="p-2 text-center border-l border-slate-200">قد لباس</th>
                                                            <th class="p-2 text-center">سرشانه</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr class="border-b border-slate-100">
                                                            <td class="p-2 text-center font-black border-l border-slate-100">M</td>
                                                            <td class="p-2 text-center border-l border-slate-100">54</td>
                                                            <td class="p-2 text-center border-l border-slate-100">74</td>
                                                            <td class="p-2 text-center">46</td>
                                                        </tr>
                                                        <tr class="border-b border-slate-100 bg-slate-50/50">
                                                            <td class="p-2 text-center font-black border-l border-slate-100">L</td>
                                                            <td class="p-2 text-center border-l border-slate-100">57</td>
                                                            <td class="p-2 text-center border-l border-slate-100">76</td>
                                                            <td class="p-2 text-center">48</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="p-2 text-center font-black border-l border-slate-100">XL</td>
                                                            <td class="p-2 text-center border-l border-slate-100">60</td>
                                                            <td class="p-2 text-center border-l border-slate-100">78</td>
                                                            <td class="p-2 text-center">50</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- 4. Illustration Preview -->
                                            <div id="bp-illus-card" data-scs-block="illus" class="scs-card bg-slate-50 p-2.5 rounded-xl border border-slate-200 text-center text-[9px] flex items-center justify-center gap-2">
                                                <span class="text-lg">📏</span>
                                                <span class="text-slate-500 font-black">اندازه‌گیری بدون کشش</span>
                                            </div>
                                        </div>

                                        <!-- 5. Notes Preview -->
                                        <div id="bp-notes-box" data-scs-block="notes" class="scs-card bg-amber-50/50 p-2.5 rounded-xl border border-amber-200/60 text-[9px] w-full">
                                            <div class="font-black text-amber-900 mb-1">💡 مشاوره سایز:</div>
                                            <div class="text-amber-800">قواره استاندارد و آزاد</div>
                                        </div>

                                        <!-- 6. Specs Preview -->
                                        <div id="bp-specs-box" data-scs-block="specs" class="scs-card bg-indigo-50/40 p-2.5 rounded-xl border border-indigo-200/60 text-[9px] w-full">
                                            <div class="font-black text-indigo-900 mb-1">🔬 مشخصات کالا:</div>
                                            <div class="text-slate-600">۱۰۰٪ الیاف طبیعی پنبه‌ای</div>
                                        </div>

                                        <!-- 7. Footer -->
                                        <div id="bp-footer" data-scs-block="footer" class="scs-card flex justify-between items-center pt-3 border-t border-slate-200 text-[9px] text-slate-400 font-black mt-auto w-full">
                                            <span id="bp-brand-tagline"><?php echo htmlspecialchars($scs_settings['brand_tagline']); ?></span>
                                            <span id="bp-brand-website" dir="ltr"><?php echo htmlspecialchars($scs_settings['brand_website']); ?></span>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Bar below Preview -->
                        <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                            <span class="text-[10px] text-slate-400">تغییرات شما به صورت آنی در این پیش‌نمایش اعمال می‌شود.</span>
                            <button type="button" onclick="saveBrandingSettings()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black rounded-xl transition-all shadow-md shadow-emerald-600/10">
                                ✔️ ثبت و ذخیره
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- Tab 5: Quick Batch Size Chart Studio (ساخت جدول سایز سریع) -->
        <div id="tabcontent-quick_batch" class="tab-pane hidden space-y-8">
            
            <!-- Top Intro & Sample Templates Banner -->
            <div class="bg-gradient-to-r from-indigo-900 via-slate-900 to-slate-800 text-white rounded-3xl p-8 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute bottom-0 left-0 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
                
                <div class="relative z-10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
                    <div class="space-y-2 max-w-2xl">
                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-amber-400/20 text-amber-300 border border-amber-400/30 rounded-full text-xs font-black">
                            <span>⚡ ماژول ساخت دسته‌ای و سریع</span>
                            <span>•</span>
                            <span>Excel & JSON Batch Engine</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black text-white leading-tight">
                            ساخت، رندر و دریافت گروهی جداول سایزبندی
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">
                            فایل اکسل یا JSON حاوی داده‌های تمام جداول سایز را بارگذاری نمایید. سیستم تمامی آن‌ها را پردازش کرده، تصاویر باکیفیت را بر اساس برندینگ شما تولید می‌کند و خروجی کل آن‌ها را به صورت یک فایل زیپ (ZIP) آماده دانلود می‌سازد.
                        </p>
                    </div>

                    <!-- Sample Download Action Box -->
                    <div class="bg-white/10 backdrop-blur-md border border-white/15 p-5 rounded-2xl flex flex-col gap-3 w-full lg:w-auto shrink-0">
                        <span class="text-[11px] font-black text-amber-300 flex items-center gap-1.5">
                            <span>📥</span>
                            <span>دانلود فایل‌های قالب و نمونه استاندارد:</span>
                        </span>
                        <div class="flex flex-wrap sm:flex-nowrap gap-2.5">
                            <button type="button" onclick="downloadSampleExcelTemplate()" class="w-full sm:w-auto px-4 py-2.5 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 text-xs font-black rounded-xl transition-all shadow-lg flex items-center justify-center gap-2">
                                <span>📊</span>
                                <span>دانلود قالب اکسل (Excel .xlsx)</span>
                            </button>
                            <button type="button" onclick="downloadSampleJsonTemplate()" class="w-full sm:w-auto px-4 py-2.5 bg-indigo-500 hover:bg-indigo-400 active:scale-95 text-white text-xs font-black rounded-xl transition-all shadow-lg flex items-center justify-center gap-2">
                                <span>📋</span>
                                <span>دانلود نمونه JSON (.json)</span>
                            </button>
                        </div>
                        <span class="text-[10px] text-slate-300 font-bold flex items-center gap-1">
                            <span>✅</span>
                            <span>پشتیبانی ۱۰۰٪ تضمینی از کاراکترها، اعداد و ستون‌های فارسی (UTF-8)</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Main Input & Configuration Section -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                
                <!-- Upload Dropzone (7 Cols) -->
                <div class="lg:col-span-7 bg-white rounded-3xl p-8 shadow-sm border border-slate-100 flex flex-col gap-6">
                    <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                        <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                            <span>📂</span>
                            <span>بارگذاری فایل داده‌ها (اکسل یا JSON)</span>
                        </h3>
                        <button type="button" onclick="toggleDirectPasteArea()" id="btn-toggle-paste" class="text-[11px] font-black text-indigo-600 hover:text-indigo-800 transition-all flex items-center gap-1">
                            <span>📝</span>
                            <span>درج مستقیم متن JSON</span>
                        </button>
                    </div>

                    <!-- File Dropzone -->
                    <input type="file" id="quick-file-input" onchange="handleFileInputChange(this)" accept=".xlsx, .xls, .csv, .json" class="hidden">
                    <div id="quick-dropzone" onclick="triggerQuickFileInput(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleFileDrop(event)" class="border-2 border-dashed border-slate-200 hover:border-indigo-500 bg-slate-50/70 hover:bg-indigo-50/30 p-8 sm:p-12 rounded-3xl text-center cursor-pointer transition-all duration-200 flex flex-col items-center justify-center gap-4 group">
                        
                        <div class="w-16 h-16 bg-white rounded-2xl shadow-sm border border-slate-150 flex items-center justify-center text-3xl group-hover:scale-110 transition-transform">
                            📥
                        </div>
                        
                        <div>
                            <p class="text-sm font-black text-slate-800">
                                فایل اکسل (<span class="text-emerald-600">.XLSX</span>، <span class="text-emerald-600">.CSV</span>) یا فایل <span class="text-indigo-600">JSON</span> خود را اینجا رها کنید
                            </p>
                            <p class="text-xs text-slate-400 mt-1.5">یا برای انتخاب فایل از سیستم خود، اینجا کلیک نمایید</p>
                        </div>

                        <div class="flex flex-wrap justify-center gap-2 pt-2">
                            <span class="px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-[10px] font-black text-slate-600">Microsoft Excel (.xlsx)</span>
                            <span class="px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-[10px] font-black text-slate-600">Comma-Separated (.csv)</span>
                            <span class="px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-[10px] font-black text-slate-600">JSON Structure (.json)</span>
                        </div>
                    </div>

                    <!-- Selected File Info Box (Initially hidden) -->
                    <div id="quick-file-info-box" class="hidden bg-emerald-50 border border-emerald-200/80 p-4 rounded-2xl flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-emerald-600 text-white rounded-xl flex items-center justify-center text-lg font-black shrink-0">
                                📄
                            </div>
                            <div>
                                <span id="quick-file-name" class="text-xs font-black text-emerald-950 block">نام فایل</span>
                                <span id="quick-file-meta" class="text-[10px] text-emerald-700 font-bold block mt-0.5">حجم و فرمت فایل</span>
                            </div>
                        </div>
                        <button type="button" onclick="clearSelectedQuickFile()" class="p-2 text-rose-600 hover:bg-rose-100 rounded-xl transition-all text-xs font-black" title="حذف این فایل">
                            ✕ حذف
                        </button>
                    </div>

                    <!-- Direct JSON Textarea Paste Box (Initially hidden) -->
                    <div id="quick-paste-box" class="hidden space-y-2">
                        <label class="text-[11px] font-black text-slate-700 block">درج یا ویرایش دستی محتوای JSON:</label>
                        <textarea id="quick-json-textarea" rows="6" placeholder="[ { &quot;title&quot;: &quot;شلوار کتان&quot;, &quot;columns&quot;: [...], &quot;rows&quot;: [...] } ]" class="w-full text-[11px] font-mono p-4 border border-slate-200 rounded-2xl bg-slate-50 outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500/20" dir="ltr"></textarea>
                    </div>
                </div>

                <!-- Processing Configuration & Integration Question (5 Cols) -->
                <div class="lg:col-span-5 bg-white rounded-3xl p-8 shadow-sm border border-slate-100 flex flex-col justify-between gap-6">
                    <div class="space-y-6">
                        <div class="pb-3 border-b border-slate-100">
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span>⚙️</span>
                                <span>تنظیمات و نحوه خروجی‌گیری</span>
                            </h3>
                        </div>

                        <!-- Integration Question: Add to Project or Temp Preview? -->
                        <div class="space-y-3">
                            <label class="text-xs font-black text-slate-800 block flex items-center gap-1.5">
                                <span>❓ آیا مایلید این جداول به پروژه ذخیره/اضافه شوند؟</span>
                            </label>
                            
                            <div class="grid grid-cols-1 gap-3">
                                <!-- Option 1: Yes, save to Project -->
                                <label id="label-storage-save" class="relative p-4 rounded-2xl border-2 border-indigo-600 bg-indigo-50/40 cursor-pointer flex items-start gap-3 transition-all">
                                    <input type="radio" name="quick_storage_choice" id="storage-choice-save" value="save" checked onchange="updateStorageChoiceUI()" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                                    <div>
                                        <span class="text-xs font-black text-slate-900 block">✅ بله، در لیست جداول پروژه ذخیره گردد</span>
                                        <span class="text-[10px] text-slate-500 leading-relaxed block mt-1">
                                            جداول در پایگاه‌داده پروژه ثبت می‌شوند و علاوه بر خروجی ZIP، در بخش «مدیریت جداول» و «اتصال به محصولات» نیز در دسترس خواهند بود.
                                        </span>
                                    </div>
                                </label>

                                <!-- Option 2: No, temporary preview and ZIP download only -->
                                <label id="label-storage-temp" class="relative p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:bg-slate-100/50 cursor-pointer flex items-start gap-3 transition-all">
                                    <input type="radio" name="quick_storage_choice" id="storage-choice-temp" value="temp" onchange="updateStorageChoiceUI()" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                                    <div>
                                        <span class="text-xs font-black text-slate-900 block">👁️ خیر، فقط ساخت و خروجی سریع در همین بخش (بدون ذخیره در پروژه)</span>
                                        <span class="text-[10px] text-slate-500 leading-relaxed block mt-1">
                                            جداول به پایگاه‌داده پروژه افزوده نمی‌شوند؛ صرفاً در همین بخش نمایش داده شده و آرشیو زیپ آن‌ها تولید و دانلود می‌گردد.
                                        </span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Render Options -->
                        <div class="space-y-3 pt-2 border-t border-slate-100">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="quick-opt-auto-render" checked class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
                                <span class="text-xs font-bold text-slate-700">تولید و رندر خودکار تصاویر باکیفیت به محض پردازش</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="quick-opt-apply-branding" checked class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
                                <span class="text-xs font-bold text-slate-700">اعمال هوشمند استایل برند، لوگو و بک‌گراند فعلی پروژه</span>
                            </label>
                        </div>
                    </div>

                    <!-- Trigger Actions -->
                    <div class="pt-4 border-t border-slate-100 flex flex-col gap-3">
                        <button type="button" onclick="startQuickBatchProcessing()" id="btn-start-quick-batch" class="w-full py-4 px-6 bg-gradient-to-r from-emerald-600 to-indigo-600 hover:from-emerald-500 hover:to-indigo-500 active:scale-98 text-white font-black text-sm rounded-2xl transition-all shadow-lg shadow-indigo-600/20 flex items-center justify-center gap-2">
                            <span>🚀</span>
                            <span>شروع پردازش و ساخت جداول سایز</span>
                        </button>
                        <button type="button" onclick="resetQuickBatchForm()" class="w-full py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs rounded-xl transition-all">
                            🔄 پاکسازی فرم و شروع مجدد
                        </button>
                    </div>
                </div>

            </div>

            <!-- Live Progress Bar Section (Shown while rendering) -->
            <div id="quick-progress-container" class="hidden bg-white rounded-3xl p-8 shadow-sm border border-slate-100 space-y-4">
                <div class="flex justify-between items-center">
                    <span id="quick-progress-title" class="text-xs font-black text-slate-800 flex items-center gap-2">
                        <span class="animate-spin inline-block">⏳</span>
                        <span id="quick-progress-status-text">در حال پردازش داده‌ها...</span>
                    </span>
                    <span id="quick-progress-percent" class="text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full">
                        0%
                    </span>
                </div>
                <div class="w-full bg-slate-100 h-3.5 rounded-full overflow-hidden p-0.5">
                    <div id="quick-progress-bar" class="bg-gradient-to-r from-indigo-500 to-emerald-500 h-full rounded-full transition-all duration-300" style="width: 0%;"></div>
                </div>
                <p id="quick-progress-subtext" class="text-[11px] text-slate-400">لطفاً تا پایان عملیات رندر بوم‌ها منتظر بمانید...</p>
            </div>

            <!-- Results & Batch Gallery Container (Shown after generation) -->
            <div id="quick-results-container" class="hidden space-y-6">
                
                <!-- Results Toolbar -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-xl font-black">
                            📦
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                                <span>نتایج ساخت جداول سایز</span>
                                <span id="quick-results-count-badge" class="px-2.5 py-0.5 bg-indigo-50 text-indigo-700 rounded-full text-xs font-black">0 جدول</span>
                            </h3>
                            <p id="quick-results-storage-status" class="text-[10px] text-slate-400 mt-0.5">
                                وضعیت ذخیره: در حال بررسی...
                            </p>
                        </div>
                    </div>

                    <!-- Main Action Group -->
                    <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                        <!-- Search in batch -->
                        <input type="text" id="quick-batch-search" oninput="filterQuickBatchGallery(this.value)" placeholder="🔍 جستجو در موارد این دسته..." class="text-xs font-bold bg-slate-50 border border-slate-200 px-3.5 py-2.5 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 w-full sm:w-56">
                        
                        <!-- Save to project button (if temp mode) -->
                        <button type="button" id="btn-save-temp-to-project" onclick="saveQuickBatchToProjectPermanently()" class="hidden px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-black rounded-xl transition-all shadow-md flex items-center gap-1.5">
                            <span>💾</span>
                            <span>ذخیره همه در پروژه</span>
                        </button>

                        <!-- Sync uploaded status button -->
                        <button type="button" id="btn-sync-quick-products-upload" onclick="syncQuickBatchProductsUploaded()" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-black rounded-xl transition-all shadow-md flex items-center gap-1.5" title="ثبت و اتصال وضعیت آپلود جداول برای محصولات پروژه">
                            <span>🚀</span>
                            <span>ثبت وضعیت آپلود در پروژه</span>
                        </button>

                        <!-- ZIP Download Button -->
                        <button type="button" onclick="downloadAllQuickBatchAsZip()" id="btn-download-all-zip" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white text-xs font-black rounded-xl transition-all shadow-lg shadow-indigo-600/20 flex items-center gap-2">
                            <span>📦</span>
                            <span>دانلود تمام تصاویر در فایل ZIP</span>
                        </button>

                        <!-- Re-render button -->
                        <button type="button" onclick="rerenderAllQuickBatchImages()" class="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all text-xs font-black" title="تولید مجدد تصاویر">
                            🔄
                        </button>
                    </div>
                </div>

                <!-- Gallery Cards Grid -->
                <div id="quick-batch-gallery-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    <!-- Loaded dynamically -->
                </div>

            </div>

        </div>

    </div>

    <!-- Script Application Logic -->
    <script>
        window.rootUrl = "<?php echo $baseUrl; ?>";
        
        var _isTriggeringQuickFile = false;
        function triggerQuickFileInput(e) {
            if (e) {
                if (e.target && e.target.id === 'quick-file-input') return;
                if (typeof e.stopPropagation === 'function') {
                    e.stopPropagation();
                }
            }
            if (_isTriggeringQuickFile) return;
            _isTriggeringQuickFile = true;
            try {
                var inp = document.getElementById('quick-file-input');
                if (inp) {
                    inp.click();
                }
            } catch (err) {
                console.error('Error triggering file input:', err);
            } finally {
                setTimeout(function() {
                    _isTriggeringQuickFile = false;
                }, 300);
            }
        }
        window.triggerQuickFileInput = triggerQuickFileInput;

        // Ensure download functions are available globally in all naming variations
        window.downloadSampleExcel = function() {
            if (typeof window._realDownloadSampleExcelTemplate === 'function') {
                return window._realDownloadSampleExcelTemplate();
            }
            if (typeof downloadSampleExcelTemplate === 'function') {
                return downloadSampleExcelTemplate();
            }
            console.warn('downloadSampleExcelTemplate not yet ready');
        };
        window.downloadSampleJSON = function() {
            if (typeof window._realDownloadSampleJsonTemplate === 'function') {
                return window._realDownloadSampleJsonTemplate();
            }
            if (typeof downloadSampleJsonTemplate === 'function') {
                return downloadSampleJsonTemplate();
            }
            console.warn('downloadSampleJsonTemplate not yet ready');
        };
        window.downloadSampleJson = window.downloadSampleJSON;

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
        window.resolveImageUrl = resolveImageUrl;
        window.resolveImageUrlPHP = resolveImageUrl;

        var app_presets = <?php echo json_encode($active_presets, JSON_UNESCAPED_UNICODE); ?>;
        var app_desc_presets = <?php echo json_encode($project['settings']['size_chart_desc_presets'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
        var app_charts = <?php echo json_encode($project['settings']['size_charts'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
        var app_products = <?php echo json_encode($project['products'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
        var selected_chart = null;
        var scs_settings = <?php echo json_encode($scs_settings, JSON_UNESCAPED_UNICODE); ?>;

        // --- Branding & Styling Engine ---

        // Compatibility for callers using the former branding mode name.
        function setBrandingStyleMode(mode) {
            return setStyleMode(mode);
        }

        function setStyleMode(mode) {
            scs_settings.style_mode = mode;
            
            if (mode === 'custom') {
                $('#btn-mode-custom').addClass('bg-indigo-500 text-white shadow-md').removeClass('text-white/80 hover:text-white hover:bg-white/5');
                $('#btn-mode-default').removeClass('bg-white text-slate-900 shadow-md').addClass('text-white/80 hover:text-white hover:bg-white/5');
                $('#custom-styling-panel').removeClass('opacity-50 pointer-events-none');
                $('#preview-mode-badge').text('استایل: سفارشی و دلخواه').removeClass('bg-slate-100 text-slate-600 border-slate-200').addClass('bg-amber-50 text-amber-700 border-amber-200');
            } else {
                $('#btn-mode-default').addClass('bg-white text-slate-900 shadow-md').removeClass('text-white/80 hover:text-white hover:bg-white/5');
                $('#btn-mode-custom').removeClass('bg-indigo-500 text-white shadow-md').addClass('text-white/80 hover:text-white hover:bg-white/5');
                $('#custom-styling-panel').addClass('opacity-50 pointer-events-none');
                $('#preview-mode-badge').text('استایل: پایه نرم‌افزار').removeClass('bg-amber-50 text-amber-700 border-amber-200').addClass('bg-slate-100 text-slate-600 border-slate-200');
            }

            applyBrandingChanges();
        }

        function setBgPattern(pattern) {
            scs_settings.bg_pattern = pattern;
            $('#bg-patterns-group .pattern-btn').removeClass('border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20').addClass('border-slate-200 bg-slate-50');
            $(`#bg-patterns-group [data-pattern="${pattern}"]`).addClass('border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20').removeClass('border-slate-200 bg-slate-50');
            applyBrandingChanges();
        }

        function setCanvasPreset(preset, w, h) {
            scs_settings.canvas_preset = preset;
            scs_settings.canvas_width = w;
            scs_settings.canvas_height = h;

            $('#setting-canvas-width').val(w);
            $('#setting-canvas-height').val(h);
            $('#current-canvas-dim-label').text(`${w} × ${h} px`);
            $('#preview-size-badge').text(`${w} × ${h} px`);

            $('#canvas-presets-group .canvas-preset-btn').removeClass('border-indigo-600 bg-indigo-50 text-indigo-900 font-black').addClass('border-slate-200 bg-slate-50 text-slate-700');
            $(`#canvas-presets-group [data-preset="${preset}"]`).addClass('border-indigo-600 bg-indigo-50 text-indigo-900 font-black').removeClass('border-slate-200 bg-slate-50 text-slate-700');

            applyBrandingChanges();
        }

        function onCustomDimensionsChanged() {
            var w = parseInt($('#setting-canvas-width').val()) || 800;
            var h = parseInt($('#setting-canvas-height').val()) || 1000;
            scs_settings.canvas_width = w;
            scs_settings.canvas_height = h;
            scs_settings.canvas_preset = 'custom';

            $('#current-canvas-dim-label').text(`${w} × ${h} px`);
            $('#preview-size-badge').text(`${w} × ${h} px`);
            $('#canvas-presets-group .canvas-preset-btn').removeClass('border-indigo-600 bg-indigo-50 text-indigo-900 font-black').addClass('border-slate-200 bg-slate-50 text-slate-700');

            applyBrandingChanges();
        }

        function setLayoutMode(layout) {
            scs_settings.layout_mode = layout;
            $('#layout-modes-group .layout-mode-btn').removeClass('border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20').addClass('border-slate-200 bg-slate-50');
            $(`#layout-modes-group [data-layout="${layout}"]`).addClass('border-emerald-600 bg-emerald-50/40 ring-2 ring-emerald-500/20').removeClass('border-slate-200 bg-slate-50');
            applyBrandingChanges();
        }

        function onBgImageChanged(url) {
            scs_settings.bg_image = url.trim();
            if (scs_settings.bg_image) {
                $('#bg-thumb-box').removeClass('hidden');
                $('#bg-thumb-img').attr('src', resolveImageUrl(scs_settings.bg_image));
                $('#bg-thumb-filename').text(scs_settings.bg_image);
            } else {
                $('#bg-thumb-box').addClass('hidden');
            }
            applyBrandingChanges();
        }

        function removeBgImage() {
            $('#setting-bg-image').val('');
            scs_settings.bg_image = '';
            $('#bg-thumb-box').addClass('hidden');
            applyBrandingChanges();
            showToast('تصویر پس‌زمینه با موفقیت حذف شد.', 'info');
        }

        function uploadBgFile(input) {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            var formData = new FormData();
            formData.append('action', 'td_scs_bg_upload');
            formData.append('file', file);

            showToast('در حال بارگذاری تصویر بک‌گراند...', 'info');

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    try {
                        if (typeof res === 'string') res = JSON.parse(res);
                    } catch(e){}
                    if (res.success) {
                        var uploadedUrl = res.data.url;
                        $('#setting-bg-image').val(uploadedUrl);
                        onBgImageChanged(uploadedUrl);
                        showToast('تصویر پس‌زمینه با موفقیت بارگذاری شد. ✨', 'success');
                    } else {
                        showToast(res.message || 'خطا در بارگذاری تصویر پس‌زمینه.', 'error');
                    }
                },
                error: function() {
                    showToast('خطا در برقراری ارتباط با سرور.', 'error');
                }
            });
        }

        function onLogoUrlChanged(url) {
            scs_settings.image_logo = url.trim();
            applyBrandingChanges();
        }

        function removeLogoImage() {
            $('#setting-image-logo').val('');
            scs_settings.image_logo = '';
            applyBrandingChanges();
            showToast('لوگوی تصویری حذف شد و نام متنی جایگزین گردید.', 'info');
        }

        function uploadLogoFile(input) {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            var formData = new FormData();
            formData.append('action', 'td_scs_logo_upload');
            formData.append('file', file);

            showToast('در حال بارگذاری فایل لوگو...', 'info');

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    try {
                        if (typeof res === 'string') res = JSON.parse(res);
                    } catch(e){}
                    if (res.success) {
                        var uploadedUrl = res.data.url;
                        $('#setting-image-logo').val(uploadedUrl);
                        onLogoUrlChanged(uploadedUrl);
                        showToast('لوگوی برند با موفقیت بارگذاری شد. ✨', 'success');
                    } else {
                        showToast(res.message || 'خطا در بارگذاری لوگو.', 'error');
                    }
                },
                error: function() {
                    showToast('خطا در برقراری ارتباط با سرور.', 'error');
                }
            });
        }

        function applyCustomThemePreset(theme) {
            scs_settings.custom_theme_preset = theme;
            
            if (theme === 'persepolis') {
                scs_settings.card_bg_color = '#ffffff';
                scs_settings.card_bg_opacity = 0;
                scs_settings.card_border_color = 'rgba(255, 255, 255, 0.2)';
                scs_settings.card_border_radius = 16;
                scs_settings.card_shadow = 'none';
                scs_settings.text_primary_color = '#ffffff';
                scs_settings.text_muted_color = '#fecdd3';
                scs_settings.accent_color = '#e11d48';
                scs_settings.table_header_bg = '#e11d48';
                scs_settings.table_header_text = '#ffffff';
                scs_settings.table_cell_text = '#ffffff';
                scs_settings.table_border_color = 'rgba(255, 255, 255, 0.25)';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = 'rgba(225, 29, 72, 0.15)';
                scs_settings.table_bg_transparent = 1;
                scs_settings.table_bg_color = '#000000';
                scs_settings.table_bg_opacity = 0;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'transparent') {
                scs_settings.card_bg_color = '#ffffff';
                scs_settings.card_bg_opacity = 0;
                scs_settings.card_border_color = 'rgba(255, 255, 255, 0.2)';
                scs_settings.card_border_radius = 16;
                scs_settings.card_shadow = 'none';
                scs_settings.text_primary_color = '#ffffff';
                scs_settings.text_muted_color = '#e2e8f0';
                scs_settings.accent_color = '#38bdf8';
                scs_settings.table_header_bg = 'rgba(15, 23, 42, 0.7)';
                scs_settings.table_header_text = '#ffffff';
                scs_settings.table_cell_text = '#ffffff';
                scs_settings.table_border_color = 'rgba(255, 255, 255, 0.2)';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = 'rgba(255, 255, 255, 0.08)';
                scs_settings.table_bg_transparent = 1;
                scs_settings.table_bg_color = '#000000';
                scs_settings.table_bg_opacity = 0;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'glass') {
                scs_settings.card_bg_color = '#ffffff';
                scs_settings.card_bg_opacity = 85;
                scs_settings.card_border_color = 'rgba(255, 255, 255, 0.6)';
                scs_settings.card_border_radius = 24;
                scs_settings.card_shadow = 'lg';
                scs_settings.text_primary_color = '#0f172a';
                scs_settings.text_muted_color = '#64748b';
                scs_settings.accent_color = '#6366f1';
                scs_settings.table_header_bg = '#f1f5f9';
                scs_settings.table_header_text = '#0f172a';
                scs_settings.table_cell_text = '#0f172a';
                scs_settings.table_border_color = '#e2e8f0';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = 'rgba(248, 250, 252, 0.6)';
                scs_settings.table_bg_transparent = 0;
                scs_settings.table_bg_color = '#ffffff';
                scs_settings.table_bg_opacity = 90;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'minimal') {
                scs_settings.card_bg_color = '#ffffff';
                scs_settings.card_bg_opacity = 100;
                scs_settings.card_border_color = '#e2e8f0';
                scs_settings.card_border_radius = 16;
                scs_settings.card_shadow = 'sm';
                scs_settings.text_primary_color = '#0f172a';
                scs_settings.text_muted_color = '#64748b';
                scs_settings.accent_color = '#4f46e5';
                scs_settings.table_header_bg = '#f8fafc';
                scs_settings.table_header_text = '#1e293b';
                scs_settings.table_cell_text = '#1e293b';
                scs_settings.table_border_color = '#e2e8f0';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = '#fcfdfe';
                scs_settings.table_bg_transparent = 0;
                scs_settings.table_bg_color = '#ffffff';
                scs_settings.table_bg_opacity = 100;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'dark_luxury') {
                scs_settings.card_bg_color = '#1e293b';
                scs_settings.card_bg_opacity = 95;
                scs_settings.card_border_color = '#334155';
                scs_settings.card_border_radius = 16;
                scs_settings.card_shadow = 'lg';
                scs_settings.text_primary_color = '#f8fafc';
                scs_settings.text_muted_color = '#94a3b8';
                scs_settings.accent_color = '#38bdf8';
                scs_settings.table_header_bg = '#0f172a';
                scs_settings.table_header_text = '#f8fafc';
                scs_settings.table_cell_text = '#f8fafc';
                scs_settings.table_border_color = '#334155';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = '#1a2234';
                scs_settings.table_bg_transparent = 0;
                scs_settings.table_bg_color = '#1e293b';
                scs_settings.table_bg_opacity = 95;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'warm_organic') {
                scs_settings.card_bg_color = '#fefdfb';
                scs_settings.card_bg_opacity = 95;
                scs_settings.card_border_color = '#e8decb';
                scs_settings.card_border_radius = 16;
                scs_settings.card_shadow = 'sm';
                scs_settings.text_primary_color = '#382e25';
                scs_settings.text_muted_color = '#7d6f5d';
                scs_settings.accent_color = '#b45309';
                scs_settings.table_header_bg = '#f5efe6';
                scs_settings.table_header_text = '#382e25';
                scs_settings.table_cell_text = '#382e25';
                scs_settings.table_border_color = '#e8decb';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = '#fbf8f3';
                scs_settings.table_bg_transparent = 0;
                scs_settings.table_bg_color = '#fefdfb';
                scs_settings.table_bg_opacity = 95;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            } else if (theme === 'navy') {
                scs_settings.card_bg_color = '#ffffff';
                scs_settings.card_bg_opacity = 100;
                scs_settings.card_border_color = '#cbd5e1';
                scs_settings.card_border_radius = 12;
                scs_settings.card_shadow = 'md';
                scs_settings.text_primary_color = '#0c1e3d';
                scs_settings.text_muted_color = '#475569';
                scs_settings.accent_color = '#1d4ed8';
                scs_settings.table_header_bg = '#f0f4f8';
                scs_settings.table_header_text = '#0c1e3d';
                scs_settings.table_cell_text = '#0c1e3d';
                scs_settings.table_border_color = '#cbd5e1';
                scs_settings.table_border_width = 1;
                scs_settings.table_row_alt_bg = '#f8fafc';
                scs_settings.table_bg_transparent = 0;
                scs_settings.table_bg_color = '#ffffff';
                scs_settings.table_bg_opacity = 100;
                scs_settings.table_font_size = 11;
                scs_settings.table_cell_padding = 8;
            }

            // Sync input fields
            $('#setting-card-bg-color').val(scs_settings.card_bg_color);
            $('#setting-card-bg-color-text').val(scs_settings.card_bg_color);
            $('#setting-card-bg-opacity').val(scs_settings.card_bg_opacity);
            $('#card-opacity-label').text(scs_settings.card_bg_opacity + '%');
            $('#setting-card-border-radius').val(scs_settings.card_border_radius);
            $('#setting-card-border-color').val(scs_settings.card_border_color);
            $('#setting-card-border-color-text').val(scs_settings.card_border_color);
            $('#setting-text-primary-color').val(scs_settings.text_primary_color);
            $('#setting-text-primary-color-text').val(scs_settings.text_primary_color);
            $('#setting-text-muted-color').val(scs_settings.text_muted_color);
            $('#setting-text-muted-color-text').val(scs_settings.text_muted_color);
            $('#setting-accent-color').val(scs_settings.accent_color);
            $('#setting-accent-color-text').val(scs_settings.accent_color);
            
            // Table specific fields
            $('#setting-table-header-bg').val(scs_settings.table_header_bg);
            $('#setting-table-header-bg-text').val(scs_settings.table_header_bg);
            $('#setting-table-header-text').val(scs_settings.table_header_text);
            $('#setting-table-header-text-text').val(scs_settings.table_header_text);
            $('#setting-table-cell-text').val(scs_settings.table_cell_text || scs_settings.text_primary_color);
            $('#setting-table-cell-text-text').val(scs_settings.table_cell_text || scs_settings.text_primary_color);
            $('#setting-table-border-color').val(scs_settings.table_border_color);
            $('#setting-table-border-color-text').val(scs_settings.table_border_color);
            $('#setting-table-border-width').val(scs_settings.table_border_width ?? 1);
            $('#setting-table-row-alt-bg').val(scs_settings.table_row_alt_bg);
            $('#setting-table-row-alt-bg-text').val(scs_settings.table_row_alt_bg);
            $('#setting-table-bg-transparent').prop('checked', !!scs_settings.table_bg_transparent);
            $('#setting-table-bg-color').val(scs_settings.table_bg_color || '#ffffff');
            $('#setting-table-bg-color-text').val(scs_settings.table_bg_color || '#ffffff');
            $('#setting-table-bg-opacity').val(scs_settings.table_bg_opacity ?? 100);
            $('#table-bg-opacity-label').text((scs_settings.table_bg_opacity ?? 100) + '%');
            $('#setting-table-font-size').val(scs_settings.table_font_size || 11);
            $('#setting-table-cell-padding').val(scs_settings.table_cell_padding || 8);
            $('#setting-table-pure-data-overlay').prop('checked', !!scs_settings.table_pure_data_overlay);
            $('#setting-table-show-header').prop('checked', (scs_settings.table_show_header !== undefined) ? !!parseInt(scs_settings.table_show_header) : true);
            $('#setting-table-row-gap').val(scs_settings.table_row_gap || 0);
            $('#table-row-gap-label').text((scs_settings.table_row_gap || 0) + 'px');
            $('#setting-table-split-rows').prop('checked', !!scs_settings.table_split_rows);
            $('#split-rows-tools-panel').toggleClass('hidden', !scs_settings.table_split_rows);
            $('#setting-table-split-cells').prop('checked', !!scs_settings.table_split_cells);
            $('#matrix-structure-panel').toggleClass('hidden', !scs_settings.table_split_cells);
            if (scs_settings.table_split_cells) renderMatrixStructurePanel();
            $('#setting-table-text-align').val(scs_settings.table_text_align || 'center');

            setStyleMode('custom');
            showToast(`پریست تم «${theme}» فعال شد.`, 'success');
        }

        // =========================================================================
        // Helper Functions: Pure Data Overlay & Freeform Split Rows Mode
        // =========================================================================
        function enablePureDataPreset() {
            $('#setting-table-pure-data-overlay').prop('checked', true);
            scs_settings.table_pure_data_overlay = 1;
            $('#setting-table-bg-transparent').prop('checked', true);
            scs_settings.table_bg_transparent = 1;
            $('#setting-table-border-width').val(0);
            scs_settings.table_border_width = 0;
            $('#setting-table-show-header').prop('checked', false);
            scs_settings.table_show_header = 0;
            var curGap = parseInt($('#setting-table-row-gap').val()) || 0;
            if (curGap === 0) {
                $('#setting-table-row-gap').val(14);
                $('#table-row-gap-label').text('14px');
                scs_settings.table_row_gap = 14;
            }
            applyBrandingChanges();
            updateLivePreview();
            showToast('حالت دیتای شفاف روی پوستر فعال شد. کادر، خطوط و هدر محو شدند و فاصله سطرها تنظیم شد. ✨', 'success');
        }

        function toggleTablePureDataOverlay(checked) {
            scs_settings.table_pure_data_overlay = checked ? 1 : 0;
            if (checked) {
                $('#setting-table-bg-transparent').prop('checked', true);
                scs_settings.table_bg_transparent = 1;
                $('#setting-table-border-width').val(0);
                scs_settings.table_border_width = 0;
            }
            applyBrandingChanges();
            updateLivePreview();
            showToast(checked ? 'حالت دیتای شفاف فعال شد (بدون کادر، پس‌زمینه و خطوط). ✨' : 'حالت استاندارد کادر جدول فعال شد.', 'info');
        }

        function toggleTableSplitRows(checked) {
            checked = !!checked;
            scs_settings.table_split_rows = checked ? 1 : 0;
            $('#setting-table-split-rows').prop('checked', checked);
            $('#td-preview, #branding-live-preview').toggleClass('scs-table-split-active', checked);
            if (checked) {
                scs_settings.table_split_cells = 0;
                $('#setting-table-split-cells').prop('checked', false);
                $('#matrix-structure-panel').addClass('hidden');
                $('#td-preview, #branding-live-preview').find('.scs-split-cell-block').remove();
                $('#td-preview, #branding-live-preview').find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
                if (scs_settings.positioning_mode !== 'freeform') {
                    scs_settings.positioning_mode = 'freeform';
                    setPositioningMode('freeform');
                    showToast('حالت بوم آزاد (Freeform) جهت جابجایی مستقل ردیف‌ها فعال شد. 📐', 'info');
                }
            } else {
                $('#td-preview, #branding-live-preview').find('.scs-split-row-block').remove();
                $('#td-preview, #branding-live-preview').find('[data-scs-block="table"], #preview-table-card, #bp-table-card').removeClass('hidden').css('display', '');
            }
            $('#split-rows-tools-panel').toggleClass('hidden', !checked);
            applyBrandingChanges();
            updateLivePreview();
            showToast(checked ? 'ردیف‌های جدول به سطرهای آزاد تفکیک شدند و کادر یکپارچه قبلی محو گردید. 🎯' : 'ردیف‌های جدول مجدداً در کادر واحد ادغام شدند.', 'success');
        }

        function setSplitRowsFontSize(val) {
            var size = parseInt(val) || 11;
            size = Math.max(6, Math.min(48, size));
            scs_settings.table_font_size = size;

            if ($('#setting-table-font-size option[value="' + size + '"]').length === 0) {
                $('#setting-table-font-size').append(`<option value="${size}">${size}px (سفارشی)</option>`);
            }
            $('#setting-table-font-size').val(size);
            $('#split-rows-font-size-input').val(size);
            $('#matrix-font-size-input').val(size);
            $('#scs-insp-font-size').val(size);

            if (scs_settings.block_positions) {
                Object.keys(scs_settings.block_positions).forEach(function(k) {
                    if (k.startsWith('table_row_') || k.startsWith('table_cell_') || k.startsWith('table_header_')) {
                        delete scs_settings.block_positions[k].fontSize;
                    }
                });
            }

            var activeFont = getResolvedFontFamily();
            $('.scs-split-cell').css({
                'font-size': size + 'px',
                'font-family': activeFont
            });
            $('.scs-split-cell-block:not(.scs-split-header-cell) .scs-cell-content').css({
                'font-size': size + 'px',
                'font-family': activeFont
            });
            $('.scs-split-header-cell .scs-cell-content').css({
                'font-size': Math.round(size * 1.05) + 'px'
            });

            applyBrandingChanges();
            applyFreeformPositionsToDOM('#td-preview', false);
            applyFreeformPositionsToDOM('#branding-live-preview', true);
        }

        function adjustSplitRowsFontSize(delta) {
            var cur = parseInt(scs_settings.table_font_size) || 11;
            var next = Math.max(6, Math.min(48, cur + delta));
            setSplitRowsFontSize(next);
            showToast(`اندازه قلم جدول به ${next}px تنظیم شد. 🔤`, 'info');
        }

        function distributeSplitRows(gap) {
            if (isNaN(gap) || gap === undefined) gap = 14;
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            
            var tablePos = scs_settings.block_positions['table'] || { top: 180, left: 36, width: (scs_settings.canvas_width || 800) - 72, height: 360 };
            var baseTop = parseInt(tablePos.top) || 180;
            var baseLeft = parseInt(tablePos.left) || 36;
            var baseW = (tablePos.width === 'auto' || tablePos.width === '100%') ? ((scs_settings.canvas_width || 800) - 72) : (parseInt(tablePos.width) || 480);
            
            var rowH = Math.max(28, Math.round(38 * (((scs_settings.table_scale || 100) / 100))));
            var curTop = baseTop;
            var rIdx = 0;

            while (true) {
                var key = 'table_row_' + rIdx;
                var el = $(`#td-preview [data-scs-block="${key}"]`);
                var existing = scs_settings.block_positions[key];
                if (el.length === 0 && !existing) break;

                var h = (existing && existing.height && existing.height !== 'auto') ? parseInt(existing.height) : (el.outerHeight() || rowH);
                scs_settings.block_positions[key] = {
                    top: curTop,
                    left: (existing && existing.left !== undefined) ? existing.left : baseLeft,
                    width: (existing && existing.width !== undefined) ? existing.width : baseW,
                    height: h,
                    zIndex: 20 + rIdx
                };
                curTop += h + gap;
                rIdx++;
            }

            applyBrandingChanges();
            updateLivePreview();
            showToast(`ردیف‌های جدول با فاصله عمودی یکنواخت ${gap}px زیر هم چیده شدند. 📐`, 'success');
        }

        function alignSplitRows() {
            var refKey = 'table_row_0';
            var refPos = scs_settings.block_positions && scs_settings.block_positions[refKey];
            if (!refPos && scs_settings.block_positions && scs_settings.block_positions['table']) {
                refPos = scs_settings.block_positions['table'];
            }
            if (!refPos) return;

            var targetLeft = parseInt(refPos.left) || 36;
            var targetWidth = (refPos.width === 'auto' || refPos.width === '100%') ? ((scs_settings.canvas_width || 800) - 72) : (parseInt(refPos.width) || 480);

            var rIdx = 0;
            while (true) {
                var key = 'table_row_' + rIdx;
                if (!scs_settings.block_positions[key] && $(`[data-scs-block="${key}"]`).length === 0) break;
                if (!scs_settings.block_positions[key]) scs_settings.block_positions[key] = {};
                scs_settings.block_positions[key].left = targetLeft;
                scs_settings.block_positions[key].width = targetWidth;
                rIdx++;
            }

            applyBrandingChanges();
            updateLivePreview();
            showToast('تمامی ردیف‌ها از نظر موقعیت افقی و عرض هم‌راستا شدند. ↔️', 'success');
        }

        function resetSplitRowsPositions() {
            if (!confirm('آیا از بازنشانی موقعیت کلیه ردیف‌های تفکیک‌شده به چیدمان اولیه مطمئن هستید؟')) return;
            var rIdx = 0;
            while (true) {
                var key = 'table_row_' + rIdx;
                if (scs_settings.block_positions && scs_settings.block_positions[key]) {
                    delete scs_settings.block_positions[key];
                } else if ($(`[data-scs-block="${key}"]`).length === 0) {
                    break;
                }
                rIdx++;
            }
            distributeSplitRows(14);
            alignSplitRows();
            showToast('موقعیت ردیف‌ها بازنشانی شد.', 'info');
        }

        // =========================================================================
        // Matrix Structure & Cell-by-Cell Placement Engine
        // =========================================================================
        var showCellCoordBadges = false;
        function toggleCellCoordBadges(forceState) {
            if (typeof forceState === 'boolean') {
                showCellCoordBadges = forceState;
            } else {
                showCellCoordBadges = !showCellCoordBadges;
            }
            $('#td-preview, #branding-live-preview').toggleClass('scs-show-coord-badges', showCellCoordBadges);
            $('#label-cell-badges').text(showCellCoordBadges ? 'مخفی‌سازی برچسب مختصات' : 'نمایش برچسب مختصات روی بوم');
            $('#btn-toggle-cell-badges').toggleClass('bg-violet-600 text-white', showCellCoordBadges).toggleClass('bg-white text-violet-900', !showCellCoordBadges);
            showToast(showCellCoordBadges ? 'برچسب‌های راهنمای مختصات روی تمام سلول‌ها فعال شد. 🏷️' : 'برچسب‌های راهنما پنهان شدند.', 'info');
        }

        function toggleTableSplitCells(checked) {
            checked = !!checked;
            scs_settings.table_split_cells = checked ? 1 : 0;
            $('#setting-table-split-cells').prop('checked', checked);
            $('#td-preview, #branding-live-preview').toggleClass('scs-table-split-active', checked);
            if (checked) {
                scs_settings.table_split_rows = 0;
                $('#setting-table-split-rows').prop('checked', false);
                $('#split-rows-tools-panel').addClass('hidden');
                $('#td-preview, #branding-live-preview').find('.scs-split-row-block').remove();
                $('#td-preview, #branding-live-preview').find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
                if (scs_settings.positioning_mode !== 'freeform') {
                    scs_settings.positioning_mode = 'freeform';
                    setPositioningMode('freeform');
                    showToast('حالت بوم آزاد (Freeform) جهت چیدمان مستقل سلول‌ها فعال شد. 📐', 'info');
                }
            } else {
                $('#td-preview, #branding-live-preview').find('.scs-split-cell-block').remove();
                $('#td-preview, #branding-live-preview').find('[data-scs-block="table"], #preview-table-card, #bp-table-card').removeClass('hidden').css('display', '');
            }
            $('#matrix-structure-panel').toggleClass('hidden', !checked);
            applyBrandingChanges();
            updateLivePreview();
            if (checked) {
                setTimeout(renderMatrixStructurePanel, 50);
            }
            showToast(checked ? 'حالت تفکیک کامل سلول‌ها و چیدمان ماتریسی فعال شد و کادر یکپارچه قبلی محو گردید. ⚡⚡' : 'تفکیک سلول‌ها غیرفعال شد.', 'info');
        }

        function getTableColNames() {
            var cols = [];
            var ths = $('#td-preview [data-scs-block="table"] thead tr th');
            if (ths.length === 0) ths = $('#grid-header-row th');
            ths.each(function() {
                var t = $(this).text().trim();
                if (t && !t.includes('پریست') && !t.includes('فاقد ستون') && t !== 'اکشن') {
                    cols.push(t);
                }
            });
            if (cols.length === 0) {
                cols = ['سایز', 'قد', 'دور سینه', 'سرشانه'];
            }
            return cols;
        }

        function getTableRowNames() {
            var rows = [];
            var trs = $('#td-preview [data-scs-block="table"] tbody tr').filter(function() { return !$(this).find('td[colspan]').length; });
            if (trs.length === 0) trs = $('#grid-body tr');
            trs.each(function(idx) {
                var firstCellVal = '';
                var input = $(this).find('td:first input');
                if (input.length > 0) {
                    firstCellVal = input.val().trim();
                } else {
                    firstCellVal = $(this).find('td:first').text().trim();
                }
                rows.push(firstCellVal || ('سطر ' + (idx + 1)));
            });
            if (rows.length === 0) {
                rows = ['سایز S', 'سایز M', 'سایز L', 'سایز XL'];
            }
            return rows;
        }

        function renderMatrixStructurePanel() {
            if (!scs_settings.table_split_cells) return;
            var colNames = getTableColNames();
            var rowNames = getTableRowNames();
            var numCols = colNames.length;
            var numRows = rowNames.length;

            var tblPos = scs_settings.block_positions['table'] || { top: 180, left: 36, width: (scs_settings.canvas_width || 800) - 72, height: 360 };
            var baseTop = parseInt(tblPos.top) || 180;
            var baseLeft = parseInt(tblPos.left) || 36;
            var baseW = (tblPos.width === 'auto' || tblPos.width === '100%') ? ((scs_settings.canvas_width || 800) - 72) : (parseInt(tblPos.width) || 480);
            var colGap = 8;
            var defaultColW = Math.max(45, Math.floor((baseW - (numCols - 1) * colGap) / numCols));
            var defaultRowH = Math.max(26, Math.round(36 * (((scs_settings.table_scale || 100) / 100))));
            var rowGap = parseInt(scs_settings.table_row_gap) || 8;

            // 1. Render Columns Controls
            var colsHtml = '';
            colNames.forEach(function(colName, cIdx) {
                var sampleKey = 'table_cell_0_' + cIdx;
                var hKey = 'table_header_' + cIdx;
                var pos = (scs_settings.block_positions && scs_settings.block_positions[sampleKey]) || (scs_settings.block_positions && scs_settings.block_positions[hKey]);
                var curX = pos ? parseInt(pos.left) : (baseLeft + cIdx * (defaultColW + colGap));
                var curW = pos ? parseInt(pos.width) : defaultColW;

                colsHtml += `
                    <div class="p-2 bg-slate-50 rounded-xl border border-violet-100 flex flex-col justify-between space-y-1.5 shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-black text-violet-950 truncate" title="${colName}">ستون A${cIdx + 1} (${colName})</span>
                            <button type="button" onclick="alignColCells(${cIdx})" class="text-[9px] bg-violet-100 hover:bg-violet-600 hover:text-white text-violet-800 px-1 py-0.5 rounded cursor-pointer font-bold" title="تراز افقی تمام مقادیر این ستون">↔️ تراز</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1 text-[9px]">
                            <div>
                                <label class="text-slate-500 font-bold block">موقعیت X:</label>
                                <input type="number" id="mat-col-x-${cIdx}" value="${curX}" oninput="onColCoordInputChanged(${cIdx})" class="w-full bg-white border border-slate-200 p-1 rounded font-mono font-bold text-center text-[10px]">
                            </div>
                            <div>
                                <label class="text-slate-500 font-bold block">عرض W:</label>
                                <input type="number" id="mat-col-w-${cIdx}" value="${curW}" oninput="onColCoordInputChanged(${cIdx})" class="w-full bg-white border border-slate-200 p-1 rounded font-mono font-bold text-center text-[10px]">
                            </div>
                        </div>
                    </div>
                `;
            });
            $('#matrix-columns-controls').html(colsHtml);

            // 2. Render Rows Controls
            var rowsHtml = '';
            rowNames.forEach(function(rowName, rIdx) {
                var sampleKey = 'table_cell_' + rIdx + '_0';
                var pos = scs_settings.block_positions && scs_settings.block_positions[sampleKey];
                var curY = pos ? parseInt(pos.top) : (baseTop + rIdx * (defaultRowH + rowGap));
                var curH = pos ? parseInt(pos.height) : defaultRowH;

                rowsHtml += `
                    <div class="p-2 bg-slate-50 rounded-xl border border-violet-100 flex flex-col justify-between space-y-1.5 shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-black text-slate-800 truncate" title="${rowName}">سطر R${rIdx + 1} (${rowName})</span>
                            <button type="button" onclick="alignRowCells(${rIdx})" class="text-[9px] bg-violet-100 hover:bg-violet-600 hover:text-white text-violet-800 px-1 py-0.5 rounded cursor-pointer font-bold" title="تراز عمودی تمام مقادیر این سطر">↕️ تراز</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1 text-[9px]">
                            <div>
                                <label class="text-slate-500 font-bold block">موقعیت Y:</label>
                                <input type="number" id="mat-row-y-${rIdx}" value="${curY}" oninput="onRowCoordInputChanged(${rIdx})" class="w-full bg-white border border-slate-200 p-1 rounded font-mono font-bold text-center text-[10px]">
                            </div>
                            <div>
                                <label class="text-slate-500 font-bold block">ارتفاع H:</label>
                                <input type="number" id="mat-row-h-${rIdx}" value="${curH}" oninput="onRowCoordInputChanged(${rIdx})" class="w-full bg-white border border-slate-200 p-1 rounded font-mono font-bold text-center text-[10px]">
                            </div>
                        </div>
                    </div>
                `;
            });
            $('#matrix-rows-controls').html(rowsHtml);

            // 3. Render 2D Grid Table
            var tableHtml = '<thead><tr class="bg-violet-100/70 border-b border-violet-200 text-violet-950 font-black"><th class="p-2 text-right">سطر \\ ستون</th>';
            colNames.forEach(function(cName, cIdx) {
                tableHtml += `<th class="p-2">A${cIdx + 1}: ${cName}</th>`;
            });
            tableHtml += '</tr></thead><tbody>';

            var tableRows = $('#td-preview [data-scs-block="table"] tbody tr').filter(function() { return !$(this).find('td[colspan]').length; });
            if (tableRows.length === 0) tableRows = $('#grid-body tr');

            rowNames.forEach(function(rName, rIdx) {
                tableHtml += `<tr class="border-b border-slate-100 hover:bg-slate-50/80 transition-colors">`;
                tableHtml += `<td class="p-2 font-black text-slate-800 bg-slate-50/60 text-right whitespace-nowrap">R${rIdx + 1}: ${rName}</td>`;

                var trEl = tableRows.eq(rIdx);
                var cells = [];
                trEl.find('td').each(function() {
                    var inp = $(this).find('input').val();
                    var t = (inp !== undefined) ? inp.trim() : $(this).text().trim();
                    if (t !== 'حذف' && t !== '') cells.push(t);
                });

                colNames.forEach(function(cName, cIdx) {
                    var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
                    var cellVal = cells[cIdx] || ('—');
                    var pos = scs_settings.block_positions && scs_settings.block_positions[cellKey];
                    var xVal = pos ? parseInt(pos.left) : (baseLeft + cIdx * (defaultColW + colGap));
                    var yVal = pos ? parseInt(pos.top) : (baseTop + rIdx * (defaultRowH + rowGap));

                    tableHtml += `
                        <td class="p-1.5 align-middle border-x border-slate-100">
                            <div class="flex flex-col items-center gap-1 bg-white p-1 rounded-lg border border-slate-200 shadow-2xs hover:border-violet-400 transition-colors">
                                <div class="flex items-center justify-between w-full px-1">
                                    <span class="font-black text-slate-900 text-[11px] truncate max-w-[50px]">${cellVal}</span>
                                    <button type="button" onclick="selectCanvasBlock('${cellKey}')" class="text-violet-600 hover:text-violet-900 text-[10px] p-0.5 rounded cursor-pointer" title="انتخاب روی بوم">🎯</button>
                                </div>
                                <div class="flex items-center gap-1 text-[8px] font-mono">
                                    <span class="text-slate-400">X:</span>
                                    <input type="number" id="matrix-cell-x-${rIdx}-${cIdx}" value="${xVal}" oninput="updateIndividualCellPosition(${rIdx}, ${cIdx}, this.value, null)" class="w-12 text-center p-0.5 border border-slate-200 rounded font-bold">
                                    <span class="text-slate-400">Y:</span>
                                    <input type="number" id="matrix-cell-y-${rIdx}-${cIdx}" value="${yVal}" oninput="updateIndividualCellPosition(${rIdx}, ${cIdx}, null, this.value)" class="w-12 text-center p-0.5 border border-slate-200 rounded font-bold">
                                </div>
                            </div>
                        </td>
                    `;
                });
                tableHtml += '</tr>';
            });
            tableHtml += '</tbody>';
            $('#matrix-cells-grid-table').html(tableHtml);
        }

        function onColCoordInputChanged(cIdx) {
            alignColCells(cIdx);
        }

        function onRowCoordInputChanged(rIdx) {
            alignRowCells(rIdx);
        }

        function alignColCells(cIdx) {
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            var rowNames = getTableRowNames();
            var refHKey = 'table_header_' + cIdx;
            var targetX = parseInt($('#mat-col-x-' + cIdx).val());
            var targetW = parseInt($('#mat-col-w-' + cIdx).val());

            if (isNaN(targetX)) targetX = 36 + cIdx * 75;
            if (isNaN(targetW)) targetW = 70;

            if (scs_settings.block_positions[refHKey]) {
                scs_settings.block_positions[refHKey].left = targetX;
                scs_settings.block_positions[refHKey].width = targetW;
            }

            rowNames.forEach(function(rowName, rIdx) {
                var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
                if (!scs_settings.block_positions[cellKey]) scs_settings.block_positions[cellKey] = { height: 36, zIndex: 20 + rIdx * 10 + cIdx };
                scs_settings.block_positions[cellKey].left = targetX;
                scs_settings.block_positions[cellKey].width = targetW;
                $('#matrix-cell-x-' + rIdx + '-' + cIdx).val(targetX);
            });

            applyFreeformPositionsToDOM('#td-preview', false);
            applyFreeformPositionsToDOM('#branding-live-preview', true);
            showToast(`تمام سلول‌های ستون ${cIdx + 1} با موقعیت X=${targetX}px هم‌راستا شدند. ↔️`, 'success');
        }

        function alignRowCells(rIdx) {
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            var colNames = getTableColNames();
            var targetY = parseInt($('#mat-row-y-' + rIdx).val());
            var targetH = parseInt($('#mat-row-h-' + rIdx).val());

            if (isNaN(targetY)) targetY = 180 + rIdx * 45;
            if (isNaN(targetH)) targetH = 36;

            colNames.forEach(function(colName, cIdx) {
                var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
                if (!scs_settings.block_positions[cellKey]) scs_settings.block_positions[cellKey] = { width: 70, zIndex: 20 + rIdx * 10 + cIdx };
                scs_settings.block_positions[cellKey].top = targetY;
                scs_settings.block_positions[cellKey].height = targetH;
                $('#matrix-cell-y-' + rIdx + '-' + cIdx).val(targetY);
            });

            applyFreeformPositionsToDOM('#td-preview', false);
            applyFreeformPositionsToDOM('#branding-live-preview', true);
            showToast(`تمام سلول‌های سطر ${rIdx + 1} با موقعیت Y=${targetY}px هم‌راستا شدند. ↕️`, 'success');
        }

        function applyMatrixGridStructure() {
            var colNames = getTableColNames();
            var rowNames = getTableRowNames();
            if (!scs_settings.block_positions) scs_settings.block_positions = {};

            var showHeader = (scs_settings.table_show_header !== undefined) ? !!parseInt(scs_settings.table_show_header) : true;
            var firstRowY = parseInt($('#mat-row-y-0').val()) || 220;
            var firstRowH = parseInt($('#mat-row-h-0').val()) || 36;
            var headerY = Math.max(10, firstRowY - firstRowH - 8);

            colNames.forEach(function(colName, cIdx) {
                var colX = parseInt($('#mat-col-x-' + cIdx).val()) || 0;
                var colW = parseInt($('#mat-col-w-' + cIdx).val()) || 70;

                if (showHeader) {
                    var hKey = 'table_header_' + cIdx;
                    scs_settings.block_positions[hKey] = {
                        left: colX,
                        top: headerY,
                        width: colW,
                        height: firstRowH,
                        zIndex: 40 + cIdx
                    };
                }

                rowNames.forEach(function(rowName, rIdx) {
                    var rowY = parseInt($('#mat-row-y-' + rIdx).val()) || 0;
                    var rowH = parseInt($('#mat-row-h-' + rIdx).val()) || 36;
                    var cellKey = 'table_cell_' + rIdx + '_' + cIdx;

                    scs_settings.block_positions[cellKey] = {
                        left: colX,
                        top: rowY,
                        width: colW,
                        height: rowH,
                        zIndex: 20 + rIdx * 10 + cIdx
                    };
                });
            });

            applyFreeformPositionsToDOM('#td-preview', false);
            applyFreeformPositionsToDOM('#branding-live-preview', true);
            renderMatrixStructurePanel();
            showToast(`ساختار ماتریسی کامل (${colNames.length} ستون در ${rowNames.length} سطر) روی تمام سلول‌ها اعمال شد. 🎯`, 'success');
        }

        function distributeMatrixCols() {
            var colNames = getTableColNames();
            var numCols = colNames.length;
            var tblPos = scs_settings.block_positions['table'] || { top: 180, left: 36, width: (scs_settings.canvas_width || 800) - 72 };
            var startLeft = parseInt($('#mat-col-x-0').val()) || parseInt(tblPos.left) || 36;
            var baseW = (tblPos.width === 'auto' || tblPos.width === '100%') ? ((scs_settings.canvas_width || 800) - 72) : (parseInt(tblPos.width) || 480);
            var gap = 10;
            var colW = Math.max(45, Math.floor((baseW - (numCols - 1) * gap) / numCols));

            colNames.forEach(function(c, cIdx) {
                var x = startLeft + cIdx * (colW + gap);
                $('#mat-col-x-' + cIdx).val(x);
                $('#mat-col-w-' + cIdx).val(colW);
                alignColCells(cIdx);
            });
            showToast('فواصل ستون‌ها به‌صورت یکنواخت افقی توزیع گردید. 📐', 'success');
        }

        function distributeMatrixRows() {
            var rowNames = getTableRowNames();
            var startTop = parseInt($('#mat-row-y-0').val()) || 220;
            var rowH = parseInt($('#mat-row-h-0').val()) || 36;
            var gap = 12;

            rowNames.forEach(function(r, rIdx) {
                var y = startTop + rIdx * (rowH + gap);
                $('#mat-row-y-' + rIdx).val(y);
                $('#mat-row-h-' + rIdx).val(rowH);
                alignRowCells(rIdx);
            });
            showToast('فواصل سطرها به‌صورت یکنواخت عمودی توزیع گردید. 📐', 'success');
        }

        function resetMatrixToTable() {
            var colNames = getTableColNames();
            var rowNames = getTableRowNames();
            var tblPos = scs_settings.block_positions['table'] || { top: 180, left: 36, width: (scs_settings.canvas_width || 800) - 72, height: 360 };
            var baseTop = parseInt(tblPos.top) || 180;
            var baseLeft = parseInt(tblPos.left) || 36;
            var baseW = (tblPos.width === 'auto' || tblPos.width === '100%') ? ((scs_settings.canvas_width || 800) - 72) : (parseInt(tblPos.width) || 480);
            var colGap = 8;
            var colW = Math.max(45, Math.floor((baseW - (colNames.length - 1) * colGap) / colNames.length));
            var rowH = Math.max(26, Math.round(36 * (((scs_settings.table_scale || 100) / 100))));
            var rowGap = parseInt(scs_settings.table_row_gap) || 8;

            colNames.forEach(function(c, cIdx) {
                $('#mat-col-x-' + cIdx).val(baseLeft + cIdx * (colW + colGap));
                $('#mat-col-w-' + cIdx).val(colW);
            });

            rowNames.forEach(function(r, rIdx) {
                $('#mat-row-y-' + rIdx).val(baseTop + rIdx * (rowH + rowGap));
                $('#mat-row-h-' + rIdx).val(rowH);
            });

            applyMatrixGridStructure();
            showToast('موقعیت سلول‌ها به شبکه منظم استاندارد بازنشانی شد. ↺', 'info');
        }

        function updateIndividualCellPosition(rIdx, cIdx, newX, newY) {
            var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            if (!scs_settings.block_positions[cellKey]) {
                scs_settings.block_positions[cellKey] = {
                    left: 0,
                    top: 0,
                    width: 70,
                    height: 36,
                    zIndex: 20 + rIdx * 10 + cIdx
                };
            }
            if (newX !== null && newX !== undefined && newX !== '') {
                scs_settings.block_positions[cellKey].left = parseInt(newX) || 0;
            }
            if (newY !== null && newY !== undefined && newY !== '') {
                scs_settings.block_positions[cellKey].top = parseInt(newY) || 0;
            }
            var targetEl = $(`#td-preview [data-scs-block="${cellKey}"], #branding-live-preview [data-scs-block="${cellKey}"]`);
            if (targetEl.length > 0) {
                targetEl.css({
                    'left': scs_settings.block_positions[cellKey].left + 'px',
                    'top': scs_settings.block_positions[cellKey].top + 'px'
                });
                targetEl.find('.scs-coord-badge').text(`C${cIdx + 1}-R${rIdx + 1} (${scs_settings.block_positions[cellKey].left}, ${scs_settings.block_positions[cellKey].top})`);
            }
            if (currentSelectedBlockKey === cellKey) {
                $('#scs-insp-x').val(scs_settings.block_positions[cellKey].left);
                $('#scs-insp-y').val(scs_settings.block_positions[cellKey].top);
            }
        }

        function syncMatrixPanelInputs() {
            if (!scs_settings.table_split_cells || $('#matrix-structure-panel').hasClass('hidden')) return;
            var colNames = getTableColNames();
            var rowNames = getTableRowNames();

            colNames.forEach(function(colName, cIdx) {
                var sampleKey = 'table_cell_0_' + cIdx;
                var hKey = 'table_header_' + cIdx;
                var pos = (scs_settings.block_positions && scs_settings.block_positions[sampleKey]) || (scs_settings.block_positions && scs_settings.block_positions[hKey]);
                if (pos) {
                    $('#mat-col-x-' + cIdx).val(pos.left);
                    $('#mat-col-w-' + cIdx).val(pos.width);
                }
            });

            rowNames.forEach(function(rowName, rIdx) {
                var sampleKey = 'table_cell_' + rIdx + '_0';
                var pos = scs_settings.block_positions && scs_settings.block_positions[sampleKey];
                if (pos) {
                    $('#mat-row-y-' + rIdx).val(pos.top);
                    $('#mat-row-h-' + rIdx).val(pos.height);
                }
            });

            rowNames.forEach(function(rowName, rIdx) {
                colNames.forEach(function(colName, cIdx) {
                    var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
                    var pos = scs_settings.block_positions && scs_settings.block_positions[cellKey];
                    if (pos) {
                        $('#matrix-cell-x-' + rIdx + '-' + cIdx).val(pos.left);
                        $('#matrix-cell-y-' + rIdx + '-' + cIdx).val(pos.top);
                    }
                });
            });
        }

        function hexToRgba(hex, opacity) {
            if (!hex) return 'rgba(255,255,255,1)';
            if (hex.startsWith('rgba')) return hex;
            hex = hex.replace('#', '');
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            var r = parseInt(hex.substring(0, 2), 16) || 255;
            var g = parseInt(hex.substring(2, 4), 16) || 255;
            var b = parseInt(hex.substring(4, 6), 16) || 255;
            var alpha = (opacity / 100).toFixed(2);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }

        function getCardBgWithOpacity() {
            var color = (typeof scs_settings !== 'undefined' && scs_settings.card_bg_color) ? scs_settings.card_bg_color : '#ffffff';
            var opacity = (typeof scs_settings !== 'undefined' && scs_settings.card_bg_opacity !== undefined) ? parseInt(scs_settings.card_bg_opacity) : 100;
            if (isNaN(opacity)) opacity = 100;
            if (typeof hexToRgba === 'function') {
                return hexToRgba(color, opacity);
            }
            return color;
        }
        window.getCardBgWithOpacity = getCardBgWithOpacity;

        function getTableThBg() {
            if (typeof scs_settings !== 'undefined' && scs_settings.table_header_bg) {
                return scs_settings.table_header_bg;
            }
            return '#f8fafc';
        }
        window.getTableThBg = getTableThBg;

        // Block definitions and labels for drag-and-drop and visibility control
        var scs_block_definitions = {
            'header': { name: 'هدر، لوگو و عنوان برند', icon: '🏷️', desc: 'نشان تجاری، عنوان و زیرعنوان رسمی' },
            'meta': { name: 'نشانگرهای مدل، دسته‌بندی و کمر', icon: '🔖', desc: 'برچسب‌های مشخصات کوتاه در بالای جدول' },
            'table': { name: 'جدول اصلی اندازه‌ها', icon: '📊', desc: 'جدول ماتریس سایزبندی و مقادیر سانتیمتر' },
            'illus': { name: 'راهنما و تصویر اندام / الگو', icon: '📏', desc: 'تصویر فیگور یا نمودار هدایت اندازه‌گیری' },
            'notes': { name: 'توضیحات تکمیلی و مشاوره سایز', icon: '💡', desc: 'یادداشت‌های اختصاصی و راهنمای انتخاب سایز' },
            'specs': { name: 'مشخصات، الیاف و متریال', icon: '🔬', desc: 'فهرست ویژگی‌های فیزیکی، جنس و جزئیات کالا' },
            'footer': { name: 'پانویس، شعار و آدرس سایت', icon: '🌐', desc: 'آدرس اینترنتی و پیام تشکر برند در انتهای بوم' }
        };

        function getBlockVisibility(key) {
            if (key === 'header') return scs_settings.show_header_brand === undefined ? true : (scs_settings.show_header_brand !== 0 && scs_settings.show_header_brand !== '0' && scs_settings.show_header_brand !== false);
            if (key === 'meta') return scs_settings.show_meta_pills === undefined ? true : (scs_settings.show_meta_pills !== 0 && scs_settings.show_meta_pills !== '0' && scs_settings.show_meta_pills !== false);
            if (key === 'table') return scs_settings.show_table === undefined ? true : (scs_settings.show_table !== 0 && scs_settings.show_table !== '0' && scs_settings.show_table !== false);
            if (key === 'illus') return scs_settings.show_illustration === undefined ? true : (scs_settings.show_illustration !== 0 && scs_settings.show_illustration !== '0' && scs_settings.show_illustration !== false);
            if (key === 'notes') return scs_settings.show_notes === undefined ? true : (scs_settings.show_notes !== 0 && scs_settings.show_notes !== '0' && scs_settings.show_notes !== false);
            if (key === 'specs') return scs_settings.show_specs === undefined ? true : (scs_settings.show_specs !== 0 && scs_settings.show_specs !== '0' && scs_settings.show_specs !== false);
            if (key === 'footer') return scs_settings.show_footer_brand === undefined ? true : (scs_settings.show_footer_brand !== 0 && scs_settings.show_footer_brand !== '0' && scs_settings.show_footer_brand !== false);
            return true;
        }

        function setBlockVisibility(key, isVisible) {
            var val = isVisible ? 1 : 0;
            if (key === 'header') scs_settings.show_header_brand = val;
            if (key === 'meta') scs_settings.show_meta_pills = val;
            if (key === 'table') scs_settings.show_table = val;
            if (key === 'illus') scs_settings.show_illustration = val;
            if (key === 'notes') scs_settings.show_notes = val;
            if (key === 'specs') scs_settings.show_specs = val;
            if (key === 'footer') scs_settings.show_footer_brand = val;

            var inputId = '#setting-show-' + (key === 'header' ? 'header-brand' : key === 'meta' ? 'meta-pills' : key === 'footer' ? 'footer-brand' : key === 'illus' ? 'illustration' : key);
            if ($(inputId).length) {
                $(inputId).prop('checked', isVisible);
            }
        }

        function toggleBlockVisibility(key, forceState) {
            var current = getBlockVisibility(key);
            var nextState = (typeof forceState === 'boolean') ? forceState : !current;
            setBlockVisibility(key, nextState);
            renderDragAndDropBuilder();
            applyBrandingChanges();

            var blockName = scs_block_definitions[key] ? scs_block_definitions[key].name : key;
            if (nextState) {
                showToast(`بخش «${blockName}» به چیدمان بازگردانده شد.`, 'success');
            } else {
                showToast(`بخش «${blockName}» از خروجی چیدمان پنهان گردید.`, 'info');
            }
        }

        function moveBlock(key, direction) {
            if (!Array.isArray(scs_settings.block_order)) {
                scs_settings.block_order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            }
            var visibleKeys = scs_settings.block_order.filter(function(k) { return getBlockVisibility(k); });
            var curIdx = visibleKeys.indexOf(key);
            if (curIdx === -1) return;
            
            var targetVisibleIdx = curIdx + direction;
            if (targetVisibleIdx < 0 || targetVisibleIdx >= visibleKeys.length) return;
            
            var targetKey = visibleKeys[targetVisibleIdx];
            var realIdx1 = scs_settings.block_order.indexOf(key);
            var realIdx2 = scs_settings.block_order.indexOf(targetKey);
            
            var temp = scs_settings.block_order[realIdx1];
            scs_settings.block_order[realIdx1] = scs_settings.block_order[realIdx2];
            scs_settings.block_order[realIdx2] = temp;

            renderDragAndDropBuilder();
            applyBrandingChanges();
        }

        function resetAllBlocksAndLayout() {
            // 1. Reset block order to standard sequence
            scs_settings.block_order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];

            // 2. Restore visibility for all 7 blocks
            ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'].forEach(function(k) {
                setBlockVisibility(k, true);
            });

            // 3. Update checkboxes
            $('#setting-show-header-brand').prop('checked', true);
            $('#setting-show-meta-pills').prop('checked', true);
            $('#setting-show-table').prop('checked', true);
            $('#setting-show-illustration').prop('checked', true);
            $('#setting-show-specs').prop('checked', true);
            $('#setting-show-notes').prop('checked', true);
            $('#setting-show-footer-brand').prop('checked', true);

            scs_settings.show_header_brand = 1;
            scs_settings.show_meta_pills = 1;
            scs_settings.show_table = 1;
            scs_settings.show_illustration = 1;
            scs_settings.show_specs = 1;
            scs_settings.show_notes = 1;
            scs_settings.show_footer_brand = 1;

            // 4. If preset select is empty, assign first preset
            var curPreset = $('#chart-preset-select').val();
            if ((!curPreset || curPreset === '') && Array.isArray(app_presets) && app_presets.length > 0) {
                curPreset = app_presets[0].id;
                $('#chart-preset-select').val(curPreset);
            }
            if (curPreset) {
                applyPresetTemplate(curPreset);
            }

            // 5. If table is empty, auto load sample rows
            if ($('#grid-body tr').length === 0) {
                loadSampleChartData();
            }

            if (!$('#chart-product-desc').val().trim()) {
                $('#chart-product-desc').val('پیراهن آستین‌بلند کتان کلاسیک');
            }

            // 6. Reset freeform positions if in freeform mode
            if (typeof resetBlocksToCanvasPreset === 'function') {
                resetBlocksToCanvasPreset();
            }

            // 7. Ensure DOM preview containers are unhidden
            $('#preview-table-card').removeClass('hidden');
            $('#preview-header-section').removeClass('hidden');
            $('#preview-meta-pills-section').removeClass('hidden');
            $('#preview-illus-card').removeClass('hidden');
            $('#preview-specs-card, #preview-specs-box').removeClass('hidden');
            $('#preview-notes-card, #preview-notes-box').removeClass('hidden');
            $('#preview-footer-section').removeClass('hidden');

            // 8. Refresh UI components
            renderDragAndDropBuilder();
            applyBrandingChanges();
            updateLivePreview();

            showToast('کلیه بخش‌ها، جدول سایزبندی و چیدمان استاندارد با موفقیت بازگردانی و نمایان شدند. ✨', 'success');
        }

        function resetBlockOrderToDefault() {
            resetAllBlocksAndLayout();
        }

        function loadSampleChartData() {
            if (!Array.isArray(app_presets) || app_presets.length === 0) {
                showToast('پریست ستونی در سیستم موجود نیست.', 'warning');
                return;
            }

            var presetId = $('#chart-preset-select').val() || app_presets[0].id;
            $('#chart-preset-select').val(presetId);

            if (!$('#chart-product-desc').val().trim()) {
                $('#chart-product-desc').val('پیراهن آستین‌بلند کتان کلاسیک');
            }

            applyPresetTemplate(presetId);

            var preset = app_presets.find(x => x.id === presetId) || app_presets[0];
            var colCount = (preset && preset.columns) ? preset.columns.length : 4;

            $('#grid-body').empty();
            var sampleRows = [
                ['S (مدیوم/۳۶)', '۴۸', '۷۰', '۶۲', '۴۲', '۳۰'],
                ['M (لارج/۳۸)', '۵۲', '۷۲', '۶۳', '۴۴', '۳۲'],
                ['L (ایکس‌لارج/۴۰)', '۵۶', '۷۴', '۶۴', '۴۶', '۳۴'],
                ['XL (دوایکس/۴۲)', '۶۰', '۷۶', '۶۵', '۴۸', '۳۶']
            ];

            sampleRows.forEach(function(sRow) {
                var rId = 'r_' + Math.floor(Math.random() * 1000000);
                var tr = $(`<tr id="${rId}" class="border-b border-slate-150"></tr>`);
                for (var i = 0; i < colCount; i++) {
                    var val = (i < sRow.length) ? sRow[i] : (i === 0 ? 'سایز ' + (i+1) : (45 + i * 5).toString());
                    tr.append(`<td class="p-2 border border-slate-200"><input type="text" value="${val}" oninput="updateLivePreview()" class="w-full text-center p-2 border border-slate-100 rounded bg-slate-50/50 text-xs font-bold focus:bg-white focus:ring-1 focus:ring-indigo-300 outline-none select-input-cell"></td>`);
                }
                tr.append(`<td class="p-2 border border-slate-200 text-center"><button type="button" onclick="$(this).closest('tr').remove(); updateLivePreview();" class="text-rose-600 font-bold hover:text-rose-500">حذف</button></td>`);
                $('#grid-body').append(tr);
            });

            // Ensure table block is visible
            setBlockVisibility('table', true);
            $('#setting-show-table').prop('checked', true);
            scs_settings.show_table = 1;

            renderDragAndDropBuilder();
            applyBrandingChanges();
            updateLivePreview();
            showToast('داده‌های نمونه جدول با موفقیت درج و در پیش‌نمایش بارگذاری شدند. 📋✨', 'success');
        }

        var draggedBlockKey = null;

        function renderDragAndDropBuilder() {
            var container = $('#scs-drag-builder-list');
            var removedTray = $('#scs-removed-blocks-tray');
            var removedCountBadge = $('#scs-removed-count');
            if (container.length === 0) return;

            if (!Array.isArray(scs_settings.block_order) || scs_settings.block_order.length === 0) {
                scs_settings.block_order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            }

            // Ensure all known blocks exist in array
            ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'].forEach(function(k) {
                if (!scs_settings.block_order.includes(k)) {
                    scs_settings.block_order.push(k);
                }
            });

            var visibleHtml = '';
            var removedHtml = '';
            var removedCount = 0;

            var visibleKeys = scs_settings.block_order.filter(function(k) { return getBlockVisibility(k); });

            scs_settings.block_order.forEach(function(key) {
                var def = scs_block_definitions[key] || { name: key, icon: '📦', desc: '' };
                var isVisible = getBlockVisibility(key);

                if (isVisible) {
                    var visibleIdx = visibleKeys.indexOf(key);
                    var isFirst = (visibleIdx === 0);
                    var isLast = (visibleIdx === visibleKeys.length - 1);

                    visibleHtml += `
                        <div class="scs-drag-item flex items-center justify-between p-3 bg-slate-50 hover:bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all cursor-move group select-none"
                             draggable="true"
                             data-key="${key}">
                            
                            <div class="flex items-center gap-3">
                                <div class="flex items-center justify-center w-7 h-7 rounded-xl bg-slate-200/70 text-slate-500 font-bold text-xs group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors">
                                    <span class="text-xs">⋮⋮</span>
                                </div>
                                <div class="text-lg shrink-0">${def.icon}</div>
                                <div>
                                    <div class="text-xs font-black text-slate-800 flex items-center gap-2">
                                        <span>${def.name}</span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 bg-emerald-100/80 text-emerald-800 rounded-md">در چیدمان فعال</span>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-medium mt-0.5">${def.desc}</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="moveBlock('${key}', -1); event.stopPropagation();" ${isFirst ? 'disabled' : ''} class="w-7 h-7 rounded-lg bg-white hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-white text-slate-600 border border-slate-200 flex items-center justify-center text-xs font-bold transition-all shadow-2xs" title="انتقال به بالاتر">
                                    ▲
                                </button>
                                <button type="button" onclick="moveBlock('${key}', 1); event.stopPropagation();" ${isLast ? 'disabled' : ''} class="w-7 h-7 rounded-lg bg-white hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-white text-slate-600 border border-slate-200 flex items-center justify-center text-xs font-bold transition-all shadow-2xs" title="انتقال به پایین‌تر">
                                    ▼
                                </button>
                                <button type="button" onclick="toggleBlockVisibility('${key}', false); event.stopPropagation();" class="w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200/60 flex items-center justify-center text-xs font-bold transition-all shadow-2xs" title="پنهان‌سازی این کادر از خروجی">
                                    ✕
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    removedCount++;
                    removedHtml += `
                        <button type="button" onclick="toggleBlockVisibility('${key}', true)" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 hover:bg-emerald-50 text-amber-900 hover:text-emerald-900 border border-amber-200 hover:border-emerald-300 rounded-xl text-xs font-black transition-all shadow-2xs group">
                            <span>${def.icon}</span>
                            <span>${def.name}</span>
                            <span class="text-emerald-600 font-black mr-1 group-hover:scale-125 transition-transform">+ افزودن مجدد</span>
                        </button>
                    `;
                }
            });

            container.html(visibleHtml);
            if (removedCount > 0) {
                removedTray.removeClass('hidden');
                $('#scs-removed-blocks-chips').html(removedHtml);
            } else {
                removedTray.addClass('hidden');
                $('#scs-removed-blocks-chips').empty();
            }
            if (removedCountBadge.length > 0) {
                removedCountBadge.text(`${removedCount} بخش پنهان`);
            }

            // Attach drag & drop events
            container.find('.scs-drag-item').each(function() {
                var item = $(this);

                item.on('dragstart', function(e) {
                    draggedBlockKey = $(this).data('key');
                    $(this).addClass('opacity-40 ring-2 ring-indigo-500 scale-[0.98]');
                    if (e.originalEvent && e.originalEvent.dataTransfer) {
                        e.originalEvent.dataTransfer.setData('text/plain', draggedBlockKey);
                        e.originalEvent.dataTransfer.effectAllowed = 'move';
                    }
                });

                item.on('dragend', function() {
                    $(this).removeClass('opacity-40 ring-2 ring-indigo-500 scale-[0.98]');
                    container.find('.scs-drag-item').removeClass('bg-indigo-50/50 border-indigo-400');
                    draggedBlockKey = null;
                });

                item.on('dragover', function(e) {
                    e.preventDefault();
                    if (e.originalEvent && e.originalEvent.dataTransfer) {
                        e.originalEvent.dataTransfer.dropEffect = 'move';
                    }
                    $(this).addClass('bg-indigo-50/50 border-indigo-400');
                });

                item.on('dragleave', function() {
                    $(this).removeClass('bg-indigo-50/50 border-indigo-400');
                });

                item.on('drop', function(e) {
                    e.preventDefault();
                    $(this).removeClass('bg-indigo-50/50 border-indigo-400');
                    var targetKey = $(this).data('key');
                    if (draggedBlockKey && targetKey && draggedBlockKey !== targetKey) {
                        var oldIdx = scs_settings.block_order.indexOf(draggedBlockKey);
                        var targetIdx = scs_settings.block_order.indexOf(targetKey);
                        if (oldIdx !== -1 && targetIdx !== -1) {
                            var movedItem = scs_settings.block_order.splice(oldIdx, 1)[0];
                            scs_settings.block_order.splice(targetIdx, 0, movedItem);
                            renderDragAndDropBuilder();
                            applyBrandingChanges();
                            showToast('ترتیب چیدمان با موفقیت بروزرسانی شد. ✨', 'success');
                        }
                    }
                });
            });
        }

        // Auto-fit canvas dimensions based on background image natural aspect ratio and dimensions
        function autoFitCanvasToBg(silent = false) {
            var bgUrl = $('#setting-bg-image').val().trim() || scs_settings.bg_image;
            if (!bgUrl) {
                if (!silent) showToast('لطفاً ابتدا تصویر پس‌زمینه را انتخاب یا آپلود فرمایید.', 'warning');
                return;
            }
            if (!silent) showToast('در حال دریافت ابعاد تصویر پس‌زمینه...', 'info');
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                var w = img.naturalWidth || 800;
                var h = img.naturalHeight || 1000;
                
                w = Math.max(400, Math.min(2400, w));
                h = Math.max(400, Math.min(3200, h));
                
                scs_settings.canvas_width = w;
                scs_settings.canvas_height = h;
                scs_settings.canvas_preset = 'custom';
                
                $('#setting-canvas-width').val(w);
                $('#setting-canvas-height').val(h);
                $('#current-canvas-dim-label').text(`${w} × ${h} px`);
                $('#preview-size-badge').text(`${w} × ${h} px`);
                $('#bg-thumb-dim-info').text(`ابعاد اصلی تصویر: ${w} × ${h} px`).removeClass('hidden');
                $('.canvas-preset-btn').removeClass('border-indigo-600 bg-indigo-50 text-indigo-900 font-black').addClass('border-slate-200 bg-slate-50 text-slate-700');
                
                if (scs_settings.positioning_mode === 'freeform' && scs_settings.block_positions) {
                    var keys = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
                    keys.forEach(function(k) {
                        var p = scs_settings.block_positions[k];
                        if (p) {
                            if (p.width !== 'auto' && p.width !== '100%') {
                                var pw = parseInt(p.width) || 300;
                                if (pw > w - 40) p.width = Math.max(200, w - 40);
                            }
                            if (p.left + (parseInt(p.width) || 200) > w) {
                                p.left = Math.max(10, w - (parseInt(p.width) || 200) - 20);
                            }
                            if (p.top > h - 40) {
                                p.top = Math.max(10, h - 80);
                            }
                        }
                    });
                }

                applyBrandingChanges();
                updateBrandingPreviewScale();
                if (!silent) showToast(`ابعاد بوم با موفقیت بر اساس سایز تصویر (${w}×${h}px) همگام‌سازی شد. 🎯`, 'success');
            };
            img.onerror = function() {
                if (!silent) showToast('امکان دریافت ابعاد فایل تصویر پس‌زمینه وجود ندارد.', 'error');
            };
            img.src = resolveImageUrl(bgUrl);
        }

        // Apply DOM ordering and block positions
        function applyBlockOrderToDOM(targetSelector, isMini) {
            var root = $(targetSelector);
            if (root.length === 0) return;
            
            var container = isMini ? root.find('#branding-preview-content') : root.find('#preview-content-container, #batch-canvas-content, .scs-content-wrap');
            if (container.length === 0) container = root;

            var order = scs_settings.block_order;
            if (!Array.isArray(order) || order.length === 0) {
                order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            }

            var allKeys = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            allKeys.forEach(function(k) {
                if (!order.includes(k)) order.push(k);
            });

            var gridWrapper = isMini ? root.find('#bp-grid-wrapper') : root.find('#preview-grid-wrapper, #batch-grid-wrapper, #bp-grid-wrapper');
            var tableCard = isMini ? root.find('#bp-table-card') : root.find('#preview-table-card');
            var illusCard = isMini ? root.find('#bp-illus-card') : root.find('#preview-illus-card');

            var gridPlaced = false;

            order.forEach(function(key) {
                if (key === 'table' || key === 'illus') {
                    if (gridWrapper.length > 0) {
                        if (!gridPlaced) {
                            container.append(gridWrapper);
                            gridPlaced = true;

                            // Place table and illustration cards inside the grid wrapper in relative order
                            var tIdx = order.indexOf('table');
                            var iIdx = order.indexOf('illus');
                            if (tIdx <= iIdx) {
                                if (tableCard.length > 0) gridWrapper.append(tableCard);
                                if (illusCard.length > 0) gridWrapper.append(illusCard);
                            } else {
                                if (illusCard.length > 0) gridWrapper.append(illusCard);
                                if (tableCard.length > 0) gridWrapper.append(tableCard);
                            }
                        }

                        if (key === 'table') {
                            var isTableVisible = getBlockVisibility('table') && !(scs_settings.table_split_rows || scs_settings.table_split_cells);
                            if (tableCard.length > 0) {
                                tableCard.toggleClass('hidden', !isTableVisible);
                                if (!isTableVisible) {
                                    tableCard.attr('style', 'display: none !important;');
                                } else {
                                    tableCard.css('display', '');
                                }
                            }
                        } else if (key === 'illus') {
                            var isIllusVisible = getBlockVisibility('illus');
                            if (illusCard.length > 0) {
                                illusCard.toggleClass('hidden', !isIllusVisible);
                                if (!isIllusVisible) {
                                    illusCard.attr('style', 'display: none !important;');
                                } else {
                                    illusCard.css('display', '');
                                }
                            }
                        }
                        return;
                    }
                }

                var el = container.find(`[data-scs-block="${key}"]`);
                if (el.length > 0) {
                    var isVisible = getBlockVisibility(key);
                    if (!isVisible || (key === 'table' && (scs_settings.table_split_rows || scs_settings.table_split_cells))) {
                        el.addClass('hidden').attr('style', 'display: none !important;');
                    } else {
                        if (key === 'notes' && !isMini) {
                            var notesVal = $('#chart-product-notes').val();
                            if (!notesVal || !notesVal.trim()) {
                                el.addClass('hidden');
                            } else {
                                el.removeClass('hidden');
                            }
                        } else if (key === 'specs' && !isMini) {
                            if ($('#preview-specs-list').children().length === 0) {
                                el.addClass('hidden');
                            } else {
                                el.removeClass('hidden');
                            }
                        } else {
                            el.removeClass('hidden');
                        }
                    }
                    container.append(el);
                }
            });
        }

        // Apply all dynamic branding, background and layout styles to preview targets
        function applyBrandingChanges() {
            // Read inputs
            scs_settings.brand_title = $('#setting-brand-title').val().trim();
            scs_settings.brand_subtitle = $('#setting-brand-subtitle').val().trim();
            scs_settings.brand_tagline = $('#setting-brand-tagline').val().trim();
            scs_settings.brand_website = $('#setting-brand-website').val().trim();
            scs_settings.image_logo = $('#setting-image-logo').val().trim();
            scs_settings.bg_image = $('#setting-bg-image').val().trim();
            scs_settings.bg_fit = $('#setting-bg-fit').val();
            scs_settings.bg_overlay_mode = $('#setting-bg-overlay-mode').val();
            scs_settings.bg_overlay_opacity = parseInt($('#setting-bg-overlay-opacity').val()) || 0;
            
            scs_settings.illus_max_height = parseInt($('#setting-illus-max-height').val()) || 160;
            scs_settings.illus_object_fit = $('#setting-illus-object-fit').val() || 'contain';

            // Font & Typography settings
            scs_settings.font_family = $('#setting-font-family').val() || 'Vazirmatn';
            scs_settings.header_font_family = $('#setting-header-font-family').val() || '';
            scs_settings.font_scale = parseInt($('#setting-font-scale').val()) || 100;
            scs_settings.font_weight = $('#setting-font-weight').val() || 'normal';
            scs_settings.font_digits = $('#setting-font-digits').val() || 'persian';
            scs_settings.custom_font_url = $('#setting-custom-font-url').val() || '';
            scs_settings.custom_font_name = $('#setting-custom-font-name').val() || '';

            scs_settings.content_padding_top = parseInt($('#setting-padding-top').val()) || 36;
            scs_settings.content_padding_bottom = parseInt($('#setting-padding-bottom').val()) || 28;
            scs_settings.content_padding_x = parseInt($('#setting-padding-x').val()) || 36;
            scs_settings.content_gap = parseInt($('#setting-content-gap').val()) || 24;

            if ($('#setting-show-header-brand').length) scs_settings.show_header_brand = $('#setting-show-header-brand').is(':checked') ? 1 : 0;
            if ($('#setting-show-meta-pills').length) scs_settings.show_meta_pills = $('#setting-show-meta-pills').is(':checked') ? 1 : 0;
            if ($('#setting-show-table').length) scs_settings.show_table = $('#setting-show-table').is(':checked') ? 1 : 0;
            if ($('#setting-show-illustration').length) scs_settings.show_illustration = $('#setting-show-illustration').is(':checked') ? 1 : 0;
            if ($('#setting-show-specs').length) scs_settings.show_specs = $('#setting-show-specs').is(':checked') ? 1 : 0;
            if ($('#setting-show-notes').length) scs_settings.show_notes = $('#setting-show-notes').is(':checked') ? 1 : 0;
            if ($('#setting-show-footer-brand').length) scs_settings.show_footer_brand = $('#setting-show-footer-brand').is(':checked') ? 1 : 0;

            scs_settings.card_bg_color = $('#setting-card-bg-color').val();
            scs_settings.card_bg_opacity = parseInt($('#setting-card-bg-opacity').val()) ?? 100;
            scs_settings.card_border_color = $('#setting-card-border-color').val();
            scs_settings.card_border_radius = parseInt($('#setting-card-border-radius').val()) || 16;
            scs_settings.text_primary_color = $('#setting-text-primary-color').val();
            scs_settings.text_muted_color = $('#setting-text-muted-color').val();
            scs_settings.accent_color = $('#setting-accent-color').val();
            scs_settings.table_header_bg = $('#setting-table-header-bg').val();
            scs_settings.table_header_text = $('#setting-table-header-text').val();
            scs_settings.table_cell_text = $('#setting-table-cell-text').val() || scs_settings.text_primary_color || '#1e293b';
            scs_settings.table_border_color = $('#setting-table-border-color').val();
            scs_settings.table_border_width = parseInt($('#setting-table-border-width').val()) ?? 1;
            scs_settings.table_row_alt_bg = $('#setting-table-row-alt-bg').val();
            scs_settings.table_bg_transparent = $('#setting-table-bg-transparent').is(':checked') ? 1 : 0;
            scs_settings.table_bg_color = $('#setting-table-bg-color').val() || '#ffffff';
            scs_settings.table_bg_opacity = parseInt($('#setting-table-bg-opacity').val()) ?? 100;
            scs_settings.table_font_size = parseInt($('#setting-table-font-size').val()) || 11;
            $('#split-rows-font-size-input').val(scs_settings.table_font_size);
            $('#matrix-font-size-input').val(scs_settings.table_font_size);
            if (typeof currentSelectedBlockKey !== 'undefined' && (currentSelectedBlockKey === 'table' || currentSelectedBlockKey.startsWith('table_row_') || currentSelectedBlockKey.startsWith('table_cell_'))) {
                $('#scs-insp-font-size').val(scs_settings.table_font_size);
            }
            scs_settings.table_cell_padding = parseInt($('#setting-table-cell-padding').val()) || 8;
            scs_settings.table_pure_data_overlay = $('#setting-table-pure-data-overlay').length ? ($('#setting-table-pure-data-overlay').is(':checked') ? 1 : 0) : 0;
            scs_settings.table_show_header = $('#setting-table-show-header').length ? ($('#setting-table-show-header').is(':checked') ? 1 : 0) : 1;
            scs_settings.table_row_gap = parseInt($('#setting-table-row-gap').val()) || 0;
            if ($('#setting-table-split-rows').length) {
                scs_settings.table_split_rows = $('#setting-table-split-rows').is(':checked') ? 1 : 0;
            }
            if ($('#setting-table-split-cells').length) {
                scs_settings.table_split_cells = $('#setting-table-split-cells').is(':checked') ? 1 : 0;
            }
            if (scs_settings.table_split_rows && scs_settings.table_split_cells) {
                scs_settings.table_split_cells = 0;
                $('#setting-table-split-cells').prop('checked', false);
            }
            var isAnySplit = !!(scs_settings.table_split_rows || scs_settings.table_split_cells);
            $('#td-preview, #branding-live-preview').toggleClass('scs-table-split-active', isAnySplit);
            if (isAnySplit) {
                $('#td-preview, #branding-live-preview').find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
            }
            scs_settings.table_text_align = $('#setting-table-text-align').val() || 'center';
            scs_settings.table_auto_scale = $('#setting-table-auto-scale').length ? ($('#setting-table-auto-scale').is(':checked') ? 1 : 0) : 1;
            scs_settings.table_scale_mode = $('#setting-table-scale-mode').val() || 'smart_fit';
            scs_settings.table_target_height = parseInt($('#setting-table-target-height').val()) || 360;
            scs_settings.table_scale = parseInt($('#setting-table-scale').val()) || (scs_settings.table_scale || 100);
            scs_settings.table_lock_ratio = $('#setting-table-lock-ratio').length ? ($('#setting-table-lock-ratio').is(':checked') ? 1 : 0) : (scs_settings.table_lock_ratio ?? 1);

            // Apply to main #td-preview
            applyBrandingStyleToTarget('#td-preview', false);

            // Apply to studio preview #branding-live-preview
            applyBrandingStyleToTarget('#branding-live-preview', true);

            // Update mini preview scaler
            updateBrandingPreviewScale();
        }

        // Active Font resolution
        function getResolvedFontFamily() {
            var font = scs_settings.font_family || 'Vazirmatn';
            if (font === 'custom' && scs_settings.custom_font_name) {
                return `"${scs_settings.custom_font_name}", "Vazirmatn", sans-serif`;
            }
            return `"${font}", "Vazirmatn", sans-serif`;
        }

        // Global freeform state
        var currentSelectedBlockKey = 'table';
        var isCanvasSnapActive = true;
        var isCanvasGridActive = false;
        var canvasZoomLevel = 1.0;

        function setPositioningMode(mode) {
            scs_settings.positioning_mode = mode;
            
            if (mode === 'freeform') {
                $('#pos-mode-btn-freeform').addClass('border-purple-600 bg-purple-50/50 ring-2 ring-purple-500/20 font-black').removeClass('border-slate-200 bg-white');
                $('#pos-mode-btn-flow').removeClass('border-emerald-600 bg-emerald-50/50 ring-2 ring-emerald-500/20 font-black').addClass('border-slate-200 bg-white');
                $('#pos-mode-indicator').text('بوم آزاد المنتور / فتوشاپ').addClass('bg-purple-100 text-purple-800').removeClass('bg-emerald-100 text-emerald-800');
                $('#scs-freeform-inspector-section').removeClass('hidden');
                $('#scs-flow-structures-section').addClass('hidden');
                $('#scs-padding-controls-row').addClass('hidden');
                $('#preview-positioning-badge').text('حالت بوم: آزاد (فتوشاپ)').addClass('bg-purple-50 text-purple-700 border-purple-200').removeClass('bg-slate-100 text-slate-600');
                $('#btn-quick-toggle-pos').addClass('bg-emerald-600 hover:bg-emerald-700').removeClass('bg-purple-600 hover:bg-purple-700');
                $('#btn-quick-toggle-pos-text').text('تغییر به چیدمان خودکار');
                $('#scs-freeform-tip-banner').removeClass('hidden');
            } else {
                $('#pos-mode-btn-flow').addClass('border-emerald-600 bg-emerald-50/50 ring-2 ring-emerald-500/20 font-black').removeClass('border-slate-200 bg-white');
                $('#pos-mode-btn-freeform').removeClass('border-purple-600 bg-purple-50/50 ring-2 ring-purple-500/20 font-black').addClass('border-slate-200 bg-white');
                $('#pos-mode-indicator').text('چیدمان خودکار و ستونی').addClass('bg-emerald-100 text-emerald-800').removeClass('bg-purple-100 text-purple-800');
                $('#scs-freeform-inspector-section').addClass('hidden');
                $('#scs-flow-structures-section').removeClass('hidden');
                $('#scs-padding-controls-row').removeClass('hidden');
                $('#preview-positioning-badge').text('حالت بوم: خودکار (Flow)').addClass('bg-slate-100 text-slate-600 border-slate-200').removeClass('bg-purple-50 text-purple-700');
                $('#btn-quick-toggle-pos').addClass('bg-purple-600 hover:bg-purple-700').removeClass('bg-emerald-600 hover:bg-emerald-700');
                $('#btn-quick-toggle-pos-text').text('فعال‌سازی بوم آزاد المنتور');
                $('#scs-freeform-tip-banner').addClass('hidden');
            }

            applyBrandingChanges();
            showToast(`حالت چیدمان به «${mode === 'freeform' ? 'بوم آزاد المنتور' : 'خودکار ستونی'}» تغییر یافت. ✨`, 'info');
        }

        function togglePositioningModeQuick() {
            var current = scs_settings.positioning_mode || 'flow';
            setPositioningMode(current === 'freeform' ? 'flow' : 'freeform');
        }

        function zoomCanvas(delta) {
            canvasZoomLevel = Math.max(0.3, Math.min(2.0, Math.round((canvasZoomLevel + delta) * 10) / 10));
            updateCanvasZoomDisplay();
        }

        function resetCanvasZoom() {
            canvasZoomLevel = 1.0;
            updateCanvasZoomDisplay();
        }

        function fitCanvasToScreen() {
            var viewport = $('#scs-canvas-viewport');
            var canvasW = scs_settings.canvas_width || 800;
            if (viewport.length > 0 && canvasW > 0) {
                var availW = viewport.width() - 40;
                if (availW < canvasW) {
                    canvasZoomLevel = Math.max(0.3, Math.round((availW / canvasW) * 10) / 10);
                } else {
                    canvasZoomLevel = 1.0;
                }
                updateCanvasZoomDisplay();
            }
        }

        function updateCanvasZoomDisplay() {
            $('#canvas-zoom-label').text(Math.round(canvasZoomLevel * 100) + '%');
            $('#td-preview').css('transform', `scale(${canvasZoomLevel})`);
        }

        function toggleCanvasGrid() {
            isCanvasGridActive = !isCanvasGridActive;
            if (isCanvasGridActive) {
                $('#td-preview').addClass('scs-canvas-grid-active');
                $('#btn-toggle-grid').addClass('bg-indigo-50 text-indigo-700 border-indigo-200').removeClass('bg-white text-slate-700');
            } else {
                $('#td-preview').removeClass('scs-canvas-grid-active');
                $('#btn-toggle-grid').removeClass('bg-indigo-50 text-indigo-700 border-indigo-200').addClass('bg-white text-slate-700');
            }
        }

        function toggleCanvasSnap() {
            isCanvasSnapActive = !isCanvasSnapActive;
            if (isCanvasSnapActive) {
                $('#btn-toggle-snap').addClass('bg-indigo-50 text-indigo-700 border-indigo-200').removeClass('bg-white text-slate-700');
                showToast('چسبندگی هوشمند خطوط راهنما فعال شد. 🧲', 'info');
            } else {
                $('#btn-toggle-snap').removeClass('bg-indigo-50 text-indigo-700 border-indigo-200').addClass('bg-white text-slate-700');
                showToast('چسبندگی هوشمند غیرفعال شد.', 'info');
            }
        }

        function matchCanvasToBackgroundImage() {
            autoFitCanvasToBg();
        }

        // Inspector Synchronization
        function onInspectorBlockChanged(blockKey) {
            currentSelectedBlockKey = blockKey;
            selectCanvasBlock(blockKey);
        }

        function selectCanvasBlock(blockKey) {
            currentSelectedBlockKey = blockKey;
            if ($(`#scs-inspector-select-block option[value="${blockKey}"]`).length === 0) {
                if (blockKey.startsWith('table_row_')) {
                    var rowNum = parseInt(blockKey.replace('table_row_', '')) + 1;
                    $('#scs-inspector-select-block').append(`<option value="${blockKey}">ردیف ${rowNum} (سایز ${rowNum})</option>`);
                } else if (blockKey.startsWith('table_cell_')) {
                    var parts = blockKey.replace('table_cell_', '').split('_');
                    var r = parseInt(parts[0]) || 0;
                    var c = parseInt(parts[1]) || 0;
                    var colNames = getTableColNames();
                    var rowNames = getTableRowNames();
                    var cName = colNames[c] || ('ستون ' + (c + 1));
                    var rName = rowNames[r] || ('سطر ' + (r + 1));
                    $('#scs-inspector-select-block').append(`<option value="${blockKey}">سلول [ستون ${cName} - ${rName}]</option>`);
                } else if (blockKey.startsWith('table_header_')) {
                    var c = parseInt(blockKey.replace('table_header_', '')) || 0;
                    var colNames = getTableColNames();
                    var cName = colNames[c] || ('ستون ' + (c + 1));
                    $('#scs-inspector-select-block').append(`<option value="${blockKey}">سرستون [${cName}]</option>`);
                }
            }
            $('#scs-inspector-select-block').val(blockKey);
            
            var pos = (scs_settings.block_positions && scs_settings.block_positions[blockKey]) ? scs_settings.block_positions[blockKey] : null;
            if (pos) {
                $('#scs-insp-x').val(pos.left);
                $('#scs-insp-y').val(pos.top);
                $('#scs-insp-w').val(pos.width === 'auto' || pos.width === '100%' ? (scs_settings.canvas_width - 72) : pos.width);
                $('#scs-insp-h').val(pos.height === 'auto' ? '' : pos.height);
                $('#scs-insp-z').val(pos.zIndex || 10);
            }

            var blockCustomFont = (pos && pos.fontSize) ? pos.fontSize : (scs_settings.table_font_size || 11);
            $('#scs-insp-font-size').val(blockCustomFont);

            // Update DOM selection rings on both previews
            $('#td-preview, #branding-live-preview').find('.scs-freeform-block').removeClass('is-selected');
            var targetEls = $(`#td-preview [data-scs-block="${blockKey}"], #branding-live-preview [data-scs-block="${blockKey}"]`);
            if (targetEls.length > 0) {
                targetEls.addClass('is-selected');
            }

            // Highlight in matrix table if visible
            $('#matrix-cells-grid-table td').removeClass('bg-violet-100 ring-2 ring-violet-500');
            if (blockKey.startsWith('table_cell_')) {
                var parts = blockKey.replace('table_cell_', '').split('_');
                $('#matrix-cell-x-' + parts[0] + '-' + parts[1]).closest('td').addClass('bg-violet-100 ring-2 ring-violet-500');
            }
        }

        function toggleTableAspectLock(forceState) {
            if (typeof forceState === 'boolean') {
                scs_settings.table_lock_ratio = forceState ? 1 : 0;
            } else {
                scs_settings.table_lock_ratio = (scs_settings.table_lock_ratio ? 0 : 1);
            }
            var isLocked = !!scs_settings.table_lock_ratio;
            $('#setting-table-lock-ratio').prop('checked', isLocked);
            $('#scs-insp-lock-ratio-btn').html(isLocked ? '🔒 قفل نسبت' : '🔓 نسبت آزاد')
                .toggleClass('bg-emerald-100 text-emerald-800', isLocked)
                .toggleClass('bg-slate-100 text-slate-600', !isLocked);
            $('#toolbar-aspect-btn').toggleClass('bg-emerald-600', isLocked).toggleClass('bg-slate-600', !isLocked);
            showToast(isLocked ? 'تناسب طول و عرض جدول قفل شد. 🔒' : 'تناسب ابعاد آزاد شد. 🔓', 'info');
        }

        function onInspectorFontSizeChanged(val) {
            var key = currentSelectedBlockKey;
            if (!key) return;
            var size = parseInt(val) || 11;
            size = Math.max(6, Math.min(48, size));
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            if (!scs_settings.block_positions[key]) scs_settings.block_positions[key] = {};
            scs_settings.block_positions[key].fontSize = size;
            if (key === 'table' || key.startsWith('table_row_') || key.startsWith('table_cell_') || key.startsWith('table_header_')) {
                scs_settings.table_font_size = size;
                if ($('#setting-table-font-size option[value="' + size + '"]').length === 0) {
                    $('#setting-table-font-size').append(`<option value="${size}">${size}px (سفارشی)</option>`);
                }
                $('#setting-table-font-size').val(size);
                $('#split-rows-font-size-input').val(size);
                $('#matrix-font-size-input').val(size);
            }
            if (key.startsWith('table_row_')) {
                $(`[data-scs-block="${key}"] .scs-split-cell`).css('font-size', size + 'px');
            } else if (key.startsWith('table_cell_') || key.startsWith('table_header_')) {
                $(`[data-scs-block="${key}"] .scs-cell-content`).css('font-size', size + 'px');
            }
            applyBrandingStyleToTarget('#td-preview', false);
            applyBrandingStyleToTarget('#branding-live-preview', true);
            applyFreeformPositionsToDOM('#td-preview', false);
            applyFreeformPositionsToDOM('#branding-live-preview', true);
        }

        function stepInspectorFontSize(delta) {
            var cur = parseInt($('#scs-insp-font-size').val()) || (scs_settings.table_font_size || 11);
            var next = Math.max(6, Math.min(48, cur + delta));
            $('#scs-insp-font-size').val(next);
            onInspectorFontSizeChanged(next);
        }

        function scaleSelectedBlock(factor) {
            var key = currentSelectedBlockKey || 'table';
            if (!key) return;

            if (key === 'table') {
                var currentScale = parseInt(scs_settings.table_scale) || 100;
                var newScale = Math.max(40, Math.min(350, Math.round(currentScale * factor)));
                setTableScale(newScale);
                showToast(`مقیاس جدول به ${newScale}% (${(newScale/100).toFixed(2)}x) تغییر یافت. 📐`, 'info');
                return;
            }

            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            var pos = scs_settings.block_positions[key];
            if (!pos) return;

            var curW = parseInt(pos.width) || 300;
            var curH = (pos.height === 'auto' || !pos.height) ? 120 : parseInt(pos.height);
            var canvasW = scs_settings.canvas_width || 800;
            var canvasH = scs_settings.canvas_height || 1000;

            var newW = Math.max(60, Math.min(canvasW - 40, Math.round(curW * factor)));
            var newH = (pos.height === 'auto') ? 'auto' : Math.max(30, Math.min(canvasH - 40, Math.round(curH * factor)));

            pos.width = newW;
            pos.height = newH;

            $('#scs-insp-w').val(newW);
            $('#scs-insp-h').val(newH === 'auto' ? '' : newH);

            var syncedBlocks = $(`#td-preview [data-scs-block="${key}"], #branding-live-preview [data-scs-block="${key}"]`);
            syncedBlocks.css({
                'width': newW + 'px',
                'height': (newH === 'auto' ? 'auto' : newH + 'px')
            });
            applyBrandingStyleToTarget('#td-preview', false);
            applyBrandingStyleToTarget('#branding-live-preview', true);
            showToast(`کادر ${key} با موفقیت مقیاس‌بندی شد. 📐`, 'info');
        }

        function setTableScale(scalePercent) {
            scalePercent = Math.max(40, Math.min(350, parseInt(scalePercent) || 100));
            scs_settings.table_scale = scalePercent;

            $('#setting-table-scale').val(scalePercent);
            $('#setting-table-scale-num').val(scalePercent);
            $('#table-scale-label').text(scalePercent + '% (' + (scalePercent/100).toFixed(2) + 'x)');
            $('#toolbar-scale-indicator').text(scalePercent + '%');

            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            if (!scs_settings.block_positions['table']) {
                var canvasW = scs_settings.canvas_width || 800;
                scs_settings.block_positions['table'] = { top: 180, left: 36, width: Math.max(300, canvasW - 72), height: 360, zIndex: 15 };
            }
            var tblPos = scs_settings.block_positions['table'];

            if (!tblPos.base_width || tblPos.base_width === 'auto' || tblPos.base_width === '100%') {
                tblPos.base_width = parseInt(tblPos.width) || 480;
            }
            if (!tblPos.base_height || tblPos.base_height === 'auto') {
                tblPos.base_height = parseInt(tblPos.height) || parseInt(scs_settings.table_target_height) || 360;
            }

            var scaleRatio = scalePercent / 100.0;
            var newW = Math.round(tblPos.base_width * scaleRatio);
            var newH = Math.round(tblPos.base_height * scaleRatio);

            tblPos.width = newW;
            tblPos.height = newH;
            scs_settings.table_target_height = newH;
            $('#setting-table-target-height').val(newH);
            $('#table-target-height-label').text(newH + 'px');

            if (currentSelectedBlockKey === 'table') {
                $('#scs-insp-w').val(newW);
                $('#scs-insp-h').val(newH);
            }

            applyBrandingStyleToTarget('#td-preview', false);
            applyBrandingStyleToTarget('#branding-live-preview', true);
        }

        function onInspectorInputUpdated(origin) {
            if (!scs_settings.block_positions) scs_settings.block_positions = {};
            var key = currentSelectedBlockKey;
            if (!key) return;

            var canvasW = scs_settings.canvas_width || 800;
            var canvasH = scs_settings.canvas_height || 1000;

            var x = parseInt($('#scs-insp-x').val()) || 0;
            var y = parseInt($('#scs-insp-y').val()) || 0;
            var w = parseInt($('#scs-insp-w').val()) || 300;
            var hInput = ($('#scs-insp-h').val() || '').toString().trim();
            var h = (hInput !== '' && hInput !== 'auto' && !isNaN(parseInt(hInput))) ? parseInt(hInput) : 'auto';
            var z = parseInt($('#scs-insp-z').val()) || 10;

            var curPos = scs_settings.block_positions[key] || {};
            var curW = parseInt(curPos.width) || 480;
            var curH = (curPos.height && curPos.height !== 'auto') ? parseInt(curPos.height) : (key === 'table' ? (parseInt(scs_settings.table_target_height) || 360) : 300);
            var curAspect = (curW > 0 && curH > 0) ? (curW / curH) : (480 / 360);

            var isRatioLocked = (key === 'table' && (scs_settings.table_lock_ratio ?? 1));
            if (isRatioLocked && curAspect > 0) {
                if (origin === 'w' && w > 0) {
                    h = Math.round(w / curAspect);
                    $('#scs-insp-h').val(h);
                } else if (origin === 'h' && h !== 'auto' && h > 0) {
                    w = Math.round(h * curAspect);
                    $('#scs-insp-w').val(w);
                }
            }

            x = Math.max(0, Math.min(canvasW - 40, x));
            y = Math.max(0, Math.min(canvasH - 40, y));
            w = Math.max(60, Math.min(canvasW, w));
            if (h !== 'auto') {
                h = Math.max(30, Math.min(canvasH - y, h));
            }

            var currentScaleRatio = (key === 'table' && scs_settings.table_scale) ? (scs_settings.table_scale / 100.0) : 1.0;
            scs_settings.block_positions[key] = {
                top: y,
                left: x,
                width: w,
                height: h,
                zIndex: z,
                base_width: Math.round(w / currentScaleRatio),
                base_height: h !== 'auto' ? Math.round(h / currentScaleRatio) : 'auto'
            };

            if (key === 'table' && h !== 'auto') {
                scs_settings.table_target_height = h;
                $('#setting-table-target-height').val(h);
                $('#table-target-height-label').text(h + 'px');
            }

            var syncedBlocks = $(`#td-preview [data-scs-block="${key}"], #branding-live-preview [data-scs-block="${key}"]`);
            syncedBlocks.css({
                'top': y + 'px',
                'left': x + 'px',
                'width': w + 'px',
                'height': (h === 'auto' ? 'auto' : h + 'px'),
                'z-index': z
            });

            if (key === 'table') {
                applyBrandingStyleToTarget('#td-preview', false);
                applyBrandingStyleToTarget('#branding-live-preview', true);
            }
        }

        function setBlockAutoHeight() {
            var key = currentSelectedBlockKey;
            if (!key || !scs_settings.block_positions) return;
            if (!scs_settings.block_positions[key]) scs_settings.block_positions[key] = {};
            scs_settings.block_positions[key].height = 'auto';
            $('#scs-insp-h').val('');
            
            var syncedBlocks = $(`#td-preview [data-scs-block="${key}"], #branding-live-preview [data-scs-block="${key}"]`);
            if (syncedBlocks.length > 0) {
                syncedBlocks.css('height', 'auto');
            }
            showToast('ارتفاع کادر به صورت خودکار (Auto) تنظیم شد. ↕️', 'info');
        }

        function alignSelectedBlock(alignment) {
            var key = currentSelectedBlockKey;
            if (!key || !scs_settings.block_positions) return;
            if (!scs_settings.block_positions[key]) {
                var blockEl = $(`#td-preview [data-scs-block="${key}"]`);
                if (blockEl.length === 0) blockEl = $(`#branding-live-preview [data-scs-block="${key}"]`);
                scs_settings.block_positions[key] = {
                    top: parseInt(blockEl.css('top')) || 0,
                    left: parseInt(blockEl.css('left')) || 0,
                    width: blockEl.outerWidth() || 300,
                    height: 'auto',
                    zIndex: parseInt(blockEl.css('z-index')) || 10
                };
            }
            var pos = scs_settings.block_positions[key];
            var canvasW = scs_settings.canvas_width || 800;
            var padX = scs_settings.content_padding_x || 36;
            var padTop = scs_settings.content_padding_top || 36;
            var blockW = (pos.width === 'auto' || pos.width === '100%') ? (canvasW - (padX * 2)) : (parseInt(pos.width) || 300);

            if (alignment === 'left') {
                pos.left = padX;
            } else if (alignment === 'center_h') {
                pos.left = Math.max(0, Math.round((canvasW - blockW) / 2));
            } else if (alignment === 'right') {
                pos.left = Math.max(0, canvasW - blockW - padX);
            } else if (alignment === 'full_width') {
                pos.left = padX;
                pos.width = canvasW - (padX * 2);
            } else if (alignment === 'top') {
                pos.top = padTop;
            }

            var syncedBlocks = $(`#td-preview [data-scs-block="${key}"], #branding-live-preview [data-scs-block="${key}"]`);
            syncedBlocks.css({
                'left': pos.left + 'px',
                'top': pos.top + 'px',
                'width': (pos.width === 'auto' || pos.width === '100%') ? (canvasW - (padX * 2)) + 'px' : (pos.width + 'px')
            });

            selectCanvasBlock(key);
            showToast('کادر هم‌تراز شد. ✨', 'info');
        }

        function autoArrangeAllFreeformBlocks() {
            var canvasW = scs_settings.canvas_width || 800;
            var canvasH = scs_settings.canvas_height || 1000;
            var padX = 36;
            var padY = 36;
            var fullW = canvasW - (padX * 2);

            var currY = padY;
            var gap = 16;

            if (!scs_settings.block_positions) scs_settings.block_positions = {};

            // 1. Header
            scs_settings.block_positions['header'] = {
                top: currY,
                left: padX,
                width: fullW,
                height: 'auto',
                zIndex: 10
            };
            currY += 90;

            // 2. Meta Pills
            scs_settings.block_positions['meta'] = {
                top: currY,
                left: padX,
                width: fullW,
                height: 'auto',
                zIndex: 11
            };
            currY += 48;

            // 3. Middle Area: Table & Illus
            var isWide = canvasW >= 760;
            if (isWide) {
                var tableW = Math.round(fullW * 0.68);
                var illusW = fullW - tableW - gap;
                var tableH = Math.min(480, canvasH - currY - 260);

                scs_settings.block_positions['table'] = {
                    top: currY,
                    left: padX + illusW + gap,
                    width: tableW,
                    height: 'auto',
                    zIndex: 15
                };
                scs_settings.block_positions['illus'] = {
                    top: currY,
                    left: padX,
                    width: illusW,
                    height: 'auto',
                    zIndex: 14
                };
                currY += Math.max(340, tableH) + gap;
            } else {
                scs_settings.block_positions['table'] = {
                    top: currY,
                    left: padX,
                    width: fullW,
                    height: 'auto',
                    zIndex: 15
                };
                currY += 280 + gap;
                scs_settings.block_positions['illus'] = {
                    top: currY,
                    left: padX,
                    width: fullW,
                    height: 'auto',
                    zIndex: 14
                };
                currY += 180 + gap;
            }

            // 4. Notes
            scs_settings.block_positions['notes'] = {
                top: currY,
                left: padX,
                width: fullW,
                height: 'auto',
                zIndex: 16
            };
            currY += 70 + gap;

            // 5. Specs
            scs_settings.block_positions['specs'] = {
                top: currY,
                left: padX,
                width: fullW,
                height: 'auto',
                zIndex: 17
            };
            currY += 80 + gap;

            // 6. Footer
            scs_settings.block_positions['footer'] = {
                top: Math.max(currY, canvasH - 70),
                left: padX,
                width: fullW,
                height: 'auto',
                zIndex: 12
            };

            selectCanvasBlock(currentSelectedBlockKey);
            applyBrandingStyleToTarget('#td-preview', false);
            showToast('کادرها به صورت هوشمند بر روی ابعاد بوم بازچینی شدند. 🪄', 'success');
        }

        // =========================================================================
        // Dynamic Table Dimensions & Row Scaling Engine
        // Calculates optimal row height, font size, cell padding, and card height based on product size count & scale multiplier
        // =========================================================================
        function calculateDynamicTableDimensions(rowCount, targetBoxHeight, options) {
            options = options || {};
            var count = Math.max(1, parseInt(rowCount) || 1);
            var targetH = Math.max(150, parseInt(targetBoxHeight) || 360);
            var scaleMode = options.scaleMode || 'smart_fit';
            var baseFontSize = parseInt(options.baseFontSize) || 11;
            var basePadding = parseInt(options.basePadding) || 8;
            var isMini = !!options.isMini;
            var fontScale = parseFloat(options.fontScale) || 1.0;
            var miniScale = 1.0;

            var tableScaleRatio = parseFloat(options.tableScale) || ((scs_settings && scs_settings.table_scale) ? (scs_settings.table_scale / 100.0) : 1.0);
            if (isNaN(tableScaleRatio) || tableScaleRatio <= 0) tableScaleRatio = 1.0;

            var minRowH = Math.max(16, parseInt(options.minRowHeight) || 22);
            var maxRowH = Math.max(minRowH + 10, parseInt(options.maxRowHeight) || 90);

            // Target header height scales with targetH and tableScaleRatio
            var thH = Math.max(28, Math.min(65, Math.round(targetH * 0.12 * Math.sqrt(tableScaleRatio))));
            var availBodyH = Math.max(count * minRowH, targetH - thH);

            var rH = 36;
            var tdPadV = basePadding;
            var thPadV = Math.max(4, Math.round(basePadding * 0.9));
            var baseUserFont = Math.round((baseFontSize || 11) * tableScaleRatio);
            var tdFont = Math.max(7, Math.min(48, baseUserFont));

            if (scaleMode === 'fixed_frame') {
                // Fixed frame: rows stretch to fill 100% of the target height without leaving empty space
                rH = Math.max(18, Math.floor(availBodyH / count));
                tdFont = Math.max(7, Math.min(baseUserFont, Math.max(8, rH - 4)));
                tdPadV = Math.max(1, Math.min(30, Math.round((rH - (tdFont * 1.2)) / 2)));
                thPadV = Math.max(2, Math.min(24, Math.round((thH - (tdFont * 1.2)) / 2)));
                var thFont = Math.min(48, Math.max(8, Math.round(tdFont * 1.06)));

                return {
                    rowCount: count,
                    targetHeight: targetH,
                    scaleMode: scaleMode,
                    cardHeight: Math.round(targetH * miniScale),
                    maxCardHeight: Math.round(targetH * miniScale),
                    thHeight: Math.round(thH * miniScale),
                    rowHeight: Math.round(rH * miniScale),
                    tdPaddingV: Math.max(1, Math.round(tdPadV * miniScale)),
                    thPaddingV: Math.max(2, Math.round(thPadV * miniScale)),
                    tdFontSize: Math.max(7, Math.round(tdFont * fontScale * miniScale)),
                    thFontSize: Math.max(8, Math.round(thFont * fontScale * miniScale))
                };
            }

            // Default: 'smart_fit'
            // Scales row height UP for few rows (filling space nicely without awkward empty bottom)
            // and scales row height DOWN for many rows (guaranteeing table stays inside target boundary)
            var idealRowH = Math.floor(availBodyH / count);

            if (count <= 1) {
                // 1 size: expand to comfortably fill a generous portion of the frame
                rH = Math.min(idealRowH, Math.max(54, Math.round(targetH * 0.45)));
                tdFont = Math.max(baseUserFont, Math.min(32, Math.round(rH * 0.30 * tableScaleRatio)));
                tdPadV = Math.max(8, Math.round((rH - (tdFont * 1.25)) / 2));
                thPadV = Math.max(6, Math.round((thH - (tdFont * 1.25)) / 2));
            } else if (count === 2) {
                // 2 sizes
                rH = Math.min(idealRowH, Math.max(46, Math.round(targetH * 0.38)));
                tdFont = Math.max(baseUserFont, Math.min(28, Math.round(rH * 0.30 * tableScaleRatio)));
                tdPadV = Math.max(6, Math.round((rH - (tdFont * 1.25)) / 2));
                thPadV = Math.max(5, Math.round((thH - (tdFont * 1.25)) / 2));
            } else if (count === 3) {
                // 3 sizes
                rH = Math.min(idealRowH, Math.max(40, Math.round(targetH * 0.28)));
                tdFont = Math.max(baseUserFont, Math.min(26, Math.round(rH * 0.30 * tableScaleRatio)));
                tdPadV = Math.max(5, Math.round((rH - (tdFont * 1.25)) / 2));
                thPadV = Math.max(4, Math.round((thH - (tdFont * 1.25)) / 2));
            } else if (count <= 5) {
                // 4-5 sizes
                rH = Math.min(idealRowH, Math.max(34, Math.round(availBodyH / count)));
                tdFont = Math.max(7, Math.min(baseUserFont, Math.max(8, rH - 4)));
                tdPadV = Math.max(4, Math.round((rH - (tdFont * 1.25)) / 2));
                thPadV = Math.max(4, Math.round((thH - (tdFont * 1.25)) / 2));
            } else {
                // 6+ sizes (many sizes): ensure all rows fit inside targetH without overflowing
                rH = Math.max(minRowH, Math.min(idealRowH, Math.floor(availBodyH / count)));
                tdFont = Math.max(7, Math.min(baseUserFont, Math.max(8, rH - 4)));
                tdPadV = Math.max(2, Math.round((rH - (tdFont * 1.25)) / 2));
                thPadV = Math.max(3, Math.round((thH - (tdFont * 1.25)) / 2));
            }

            var totalComputedH = thH + (count * rH);
            if (totalComputedH > targetH) {
                rH = Math.max(minRowH, Math.floor((targetH - thH) / count));
                tdFont = Math.max(7, Math.min(baseUserFont, Math.max(8, rH - 4)));
                tdPadV = Math.max(2, Math.round((rH - (tdFont * 1.25)) / 2));
                thH = Math.max(26, Math.min(thH, targetH - (count * rH)));
                totalComputedH = thH + (count * rH);
            }

            var finalCardH = Math.max(totalComputedH, Math.round(targetH * (count <= 2 ? 0.9 : 1.0)));
            if (finalCardH > totalComputedH && count <= 4) {
                rH = Math.floor((finalCardH - thH) / count);
                tdPadV = Math.max(4, Math.round((rH - (tdFont * 1.25)) / 2));
                totalComputedH = thH + (count * rH);
                finalCardH = totalComputedH;
            }

            var thFont = Math.min(24, Math.max(9, Math.round(tdFont * 1.05)));

            return {
                rowCount: count,
                targetHeight: targetH,
                scaleMode: scaleMode,
                cardHeight: Math.round(finalCardH * miniScale),
                maxCardHeight: Math.round(targetH * miniScale),
                thHeight: Math.round(thH * miniScale),
                rowHeight: Math.round(rH * miniScale),
                tdPaddingV: Math.max(1, Math.round(tdPadV * miniScale)),
                thPaddingV: Math.max(2, Math.round(thPadV * miniScale)),
                tdFontSize: Math.max(7, Math.round(tdFont * fontScale * miniScale)),
                thFontSize: Math.max(8, Math.round(thFont * fontScale * miniScale))
            };
        }

        // Live testing simulator for Branding Studio
        function testTableScaleRowCount(count) {
            var tbody = $('#bp-table-card tbody').empty();
            var sampleSizes = ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
            if (count === 1) {
                tbody.append(`
                    <tr>
                        <td class="p-2 text-center font-black border-l border-slate-100">فری سایز</td>
                        <td class="p-2 text-center border-l border-slate-100">56</td>
                        <td class="p-2 text-center border-l border-slate-100">74</td>
                        <td class="p-2 text-center">48</td>
                    </tr>
                `);
            } else {
                for (var i = 0; i < count; i++) {
                    var sName = sampleSizes[i] || ('سایز ' + (i + 1));
                    var chest = 52 + (i * 3);
                    var length = 72 + (i * 2);
                    var shoulder = 44 + (i * 2);
                    var altClass = (i % 2 === 1) ? 'bg-slate-50/50' : '';
                    tbody.append(`
                        <tr class="border-b last:border-0 border-slate-100 ${altClass}">
                            <td class="p-2 text-center font-black border-l border-slate-100">${sName}</td>
                            <td class="p-2 text-center border-l border-slate-100">${chest}</td>
                            <td class="p-2 text-center border-l border-slate-100">${length}</td>
                            <td class="p-2 text-center">${shoulder}</td>
                        </tr>
                    `);
                }
            }
            applyBrandingStyleToTarget('#branding-live-preview', true);
            updateBrandingPreviewScale();
            showToast(`پیش‌نمایش با ${count} سایز شبیه‌سازی شد. 📊`, 'info');
        }

        // Apply branding style & layout engine
        function applyBrandingStyleToTarget(targetSelector, isMini) {
            var el = $(targetSelector);
            if (el.length === 0) return;

            var isCustom = scs_settings.style_mode === 'custom';
            var isFreeform = (scs_settings.positioning_mode || 'flow') === 'freeform';

            // 0. Typography & Font Family
            var activeFont = getResolvedFontFamily();
            var headerFont = scs_settings.header_font_family ? `"${scs_settings.header_font_family}", ${activeFont}` : activeFont;
            var fontScale = (scs_settings.font_scale || 100) / 100;
            var fontWeight = scs_settings.font_weight || 'normal';

            el.css({
                'font-family': activeFont,
                'font-weight': fontWeight
            });

            // 1. Dimensions & Canvas
            var w = scs_settings.canvas_width || 800;
            var h = scs_settings.canvas_height || 1000;
            el.css({
                'width': w + 'px',
                'height': h + 'px',
                'min-height': h + 'px'
            });
            if (!isMini) {
                $('#preview-size-badge').text(w + ' × ' + h + ' px');
            }

            // 2. Background Image & Patterns
            var bgCss = {};
            if (scs_settings.bg_image) {
                var resolvedBg = resolveImageUrl(scs_settings.bg_image);
                bgCss['background-image'] = `url("${resolvedBg}")`;
                bgCss['background-size'] = scs_settings.bg_fit || 'cover';
                bgCss['background-position'] = 'center center';
                bgCss['background-repeat'] = scs_settings.bg_fit === 'repeat' ? 'repeat' : 'no-repeat';
                bgCss['background-color'] = '#ffffff';
            } else if (scs_settings.bg_pattern === 'linen') {
                bgCss['background-color'] = '#faf7f2';
                bgCss['background-image'] = 'radial-gradient(#d6c7b2 0.75px, transparent 0.75px)';
                bgCss['background-size'] = '8px 8px';
            } else if (scs_settings.bg_pattern === 'grid') {
                bgCss['background-color'] = '#f8fafc';
                bgCss['background-image'] = 'linear-gradient(#e2e8f0 1px, transparent 1px), linear-gradient(90deg, #e2e8f0 1px, transparent 1px)';
                bgCss['background-size'] = '12px 12px';
            } else if (scs_settings.bg_pattern === 'gradient') {
                bgCss['background'] = 'linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%)';
            } else if (scs_settings.bg_pattern === 'craft') {
                bgCss['background'] = '#f4efe6';
            } else if (scs_settings.bg_pattern === 'dark') {
                bgCss['background'] = '#0f172a';
            } else {
                bgCss['background'] = '#ffffff';
            }
            el.css(bgCss);

            // 3. Overlay Layer
            var overlayEl = isMini ? $('#branding-preview-overlay') : (el.find('.scs-bg-overlay, #preview-bg-overlay, #batch-canvas-bg-overlay').length ? el.find('.scs-bg-overlay, #preview-bg-overlay, #batch-canvas-bg-overlay') : $('#preview-bg-overlay'));
            var overlayMode = scs_settings.bg_overlay_mode || 'none';
            var overlayOpacity = (scs_settings.bg_overlay_opacity || 0) / 100;

            if (overlayMode === 'white') {
                overlayEl.css({ 'background-color': '#ffffff', 'opacity': overlayOpacity, 'z-index': 1, 'pointer-events': 'none' });
            } else if (overlayMode === 'dark') {
                overlayEl.css({ 'background-color': '#000000', 'opacity': overlayOpacity, 'z-index': 1, 'pointer-events': 'none' });
            } else if (overlayMode === 'tint') {
                overlayEl.css({ 'background-color': scs_settings.accent_color || '#4f46e5', 'opacity': overlayOpacity, 'z-index': 1, 'pointer-events': 'none' });
            } else {
                overlayEl.css({ 'background-color': 'transparent', 'opacity': 0, 'z-index': 1, 'pointer-events': 'none' });
            }

            // 4. Content Container Layout
            var contentWrap = isMini ? el.find('#branding-preview-content') : el.find('#preview-content-container, #batch-canvas-content, .scs-content-wrap');
            if (contentWrap.length === 0) contentWrap = el.find('.scs-content-wrap, #preview-content-container, #branding-preview-content, #batch-canvas-content');

            var padTop = scs_settings.content_padding_top ?? 36;
            var padBottom = scs_settings.content_padding_bottom ?? 28;
            var padX = scs_settings.content_padding_x ?? 36;
            var gap = scs_settings.content_gap ?? 24;

            if (isFreeform) {
                contentWrap.css({
                    'position': 'relative',
                    'width': '100%',
                    'height': h + 'px',
                    'min-height': h + 'px',
                    'padding': '0',
                    'display': 'block',
                    'z-index': 10
                });
            } else {
                contentWrap.css({
                    'position': 'relative',
                    'width': '100%',
                    'height': '100%',
                    'min-height': h + 'px',
                    'padding-top': padTop + 'px',
                    'padding-bottom': padBottom + 'px',
                    'padding-left': padX + 'px',
                    'padding-right': padX + 'px',
                    'display': 'flex',
                    'flex-direction': 'column',
                    'justify-content': 'space-between',
                    'z-index': 10
                });

                var dynamicLayout = isMini ? el.find('#bp-main-zone') : el.find('#preview-dynamic-layout');
                if (dynamicLayout.length > 0) {
                    dynamicLayout.css({ 'gap': gap + 'px' });
                }
            }

            // 5. Dynamic Layout Modes (Flow mode grid columns)
            var layout = scs_settings.layout_mode || 'standard_split';
            var gridWrapper = isMini ? el.find('#bp-grid-wrapper') : el.find('#preview-grid-wrapper, #batch-grid-wrapper, #bp-grid-wrapper');
            var tableCard = isMini ? el.find('#bp-table-card') : el.find('#preview-table-card');
            var illusCard = isMini ? el.find('#bp-illus-card') : el.find('#preview-illus-card');

            if (isFreeform) {
                if (gridWrapper.length > 0) {
                    gridWrapper.css({ 'display': 'contents', 'width': '100%' });
                }
            } else if (gridWrapper && gridWrapper.length > 0) {
                var isIllusVisible = illusCard.length > 0 && !illusCard.hasClass('hidden') && (typeof getBlockVisibility !== 'function' || getBlockVisibility('illus'));

                if (layout === 'stacked_wide') {
                    gridWrapper.removeClass('scs-split-grid').addClass('scs-stacked-grid');
                    gridWrapper.css({
                        'display': 'flex',
                        'flex-direction': 'column',
                        'width': '100%',
                        'gap': gap + 'px'
                    });
                    tableCard.css({ 'width': '100%', 'flex': '1 1 auto', 'box-sizing': 'border-box' });
                    illusCard.css({ 'width': '100%', 'flex': '1 1 auto', 'box-sizing': 'border-box' });
                } else if (layout === 'table_first') {
                    gridWrapper.removeClass('scs-split-grid').addClass('scs-stacked-grid');
                    gridWrapper.css({
                        'display': 'flex',
                        'flex-direction': 'column',
                        'width': '100%',
                        'gap': Math.round(gap * 0.7) + 'px'
                    });
                    tableCard.css({ 'width': '100%', 'flex': '1 1 auto', 'box-sizing': 'border-box' });
                    illusCard.css({ 'width': '100%', 'flex': '1 1 auto', 'box-sizing': 'border-box' });
                } else {
                    // standard_split or modern_bento: 2-column layout!
                    if (isIllusVisible) {
                        gridWrapper.removeClass('scs-stacked-grid').addClass('scs-split-grid');
                        gridWrapper.css({
                            'display': 'flex',
                            'flex-direction': 'row',
                            'align-items': 'stretch',
                            'justify-content': 'space-between',
                            'width': '100%',
                            'gap': gap + 'px',
                            'box-sizing': 'border-box'
                        });
                        var isBatchSplit = gridWrapper.hasClass('scs-batch-grid-split');
                        if (isBatchSplit) {
                            tableCard.css({ 'width': '100%', 'box-sizing': 'border-box' });
                            illusCard.css({ 'width': '100%', 'box-sizing': 'border-box' });
                        } else {
                            tableCard.removeClass('w-full').css({
                                'width': '66.666%',
                                'flex': '8 1 0%',
                                'min-width': '0',
                                'box-sizing': 'border-box'
                            });
                            illusCard.removeClass('w-full').css({
                                'width': '33.333%',
                                'flex': '4 1 0%',
                                'min-width': '0',
                                'box-sizing': 'border-box'
                            });
                        }
                    } else {
                        gridWrapper.removeClass('scs-split-grid').addClass('scs-stacked-grid');
                        gridWrapper.css({
                            'display': 'flex',
                            'flex-direction': 'column',
                            'width': '100%'
                        });
                        tableCard.css({ 'width': '100%', 'flex': '1 1 auto', 'box-sizing': 'border-box' });
                    }
                }
            }

            // 6. Illustration Image Dimensions & Fit
            var maxH = parseInt(scs_settings.illus_max_height) || 160;
            var objFit = scs_settings.illus_object_fit || 'contain';
            var illusBox = isMini ? el.find('#bp-illus-card') : el.find('#preview-illus-box');
            if (isMini) {
                el.find('#bp-illus-card img').css({ 'max-height': Math.round(maxH * 0.6) + 'px', 'object-fit': objFit });
            } else {
                illusBox.css('max-height', maxH + 'px');
                illusBox.find('img').css({ 'max-height': maxH + 'px', 'object-fit': objFit });
            }

            // 7. Styling Engine (Cards, Borders, Radius, Colors & Size Chart Styling)
            var cardBg = isCustom ? hexToRgba(scs_settings.card_bg_color || '#ffffff', scs_settings.card_bg_opacity ?? 100) : '#ffffff';
            var cardBorder = isCustom ? (scs_settings.card_border_color || '#e2e8f0') : '#e2e8f0';
            var cardRadius = isCustom ? (scs_settings.card_border_radius || 16) + 'px' : '16px';
            var textPrimary = isCustom ? (scs_settings.text_primary_color || '#0f172a') : '#0f172a';
            var textMuted = isCustom ? (scs_settings.text_muted_color || '#64748b') : '#64748b';
            var accent = isCustom ? (scs_settings.accent_color || '#4f46e5') : '#4f46e5';

            // Table specific custom style variables
            var thBg = scs_settings.table_header_bg || '#f8fafc';
            var thText = scs_settings.table_header_text || '#1e293b';
            var tableBorderColor = scs_settings.table_border_color || '#e2e8f0';
            var tableBorderWidth = (scs_settings.table_border_width !== undefined && scs_settings.table_border_width !== null) ? parseInt(scs_settings.table_border_width) : 1;
            var tableBorderCss = tableBorderWidth > 0 ? (tableBorderWidth + 'px solid ' + tableBorderColor) : 'none';
            var cellText = scs_settings.table_cell_text || textPrimary;
            var rowAltBg = scs_settings.table_row_alt_bg || '#fcfdfe';
            var cellPadding = parseInt(scs_settings.table_cell_padding) || 8;
            var tableFontSize = parseInt(scs_settings.table_font_size) || 11;
            var isPureData = !!scs_settings.table_pure_data_overlay;
            var isTableTransparent = isPureData || !!scs_settings.table_bg_transparent;
            if (isPureData) {
                tableBorderWidth = 0;
                tableBorderCss = 'none';
            }
            var tableCardBg = isTableTransparent ? 'transparent' : hexToRgba(scs_settings.table_bg_color || '#ffffff', scs_settings.table_bg_opacity ?? 100);
            var showHeader = (scs_settings.table_show_header !== undefined && scs_settings.table_show_header !== null) ? !!parseInt(scs_settings.table_show_header) : true;
            var rowGap = parseInt(scs_settings.table_row_gap) || 0;
            var tableTextAlign = scs_settings.table_text_align || 'center';

            // Dynamic Table Scaling & Dimension Engine Calculation
            var isAutoScale = (scs_settings.table_auto_scale !== undefined && scs_settings.table_auto_scale !== null) ? !!parseInt(scs_settings.table_auto_scale) : true;
            var tablePos = (scs_settings.block_positions && scs_settings.block_positions['table']) ? scs_settings.block_positions['table'] : null;
            var targetBoxH = parseInt(scs_settings.table_target_height) || (tablePos && tablePos.height && tablePos.height !== 'auto' ? parseInt(tablePos.height) : 360);
            if (!targetBoxH || targetBoxH < 150) targetBoxH = 360;

            var tableEl = el.find('table');
            var bodyRows = tableEl.find('tbody tr').filter(function() {
                return !$(this).find('td[colspan]').length;
            });
            var rowCount = bodyRows.length > 0 ? bodyRows.length : 1;

            var tableScaleRatio = ((scs_settings.table_scale ? parseInt(scs_settings.table_scale) : 100) || 100) / 100.0;

            var tableDim = isAutoScale ? calculateDynamicTableDimensions(rowCount, targetBoxH, {
                scaleMode: scs_settings.table_scale_mode || 'smart_fit',
                baseFontSize: tableFontSize,
                basePadding: cellPadding,
                isMini: isMini,
                fontScale: fontScale,
                tableScale: tableScaleRatio,
                minRowHeight: scs_settings.table_min_row_height || 22,
                maxRowHeight: scs_settings.table_max_row_height || 90
            }) : {
                rowCount: rowCount,
                cardHeight: (tablePos && tablePos.height && tablePos.height !== 'auto') ? parseInt(tablePos.height) : 'auto',
                maxCardHeight: targetBoxH,
                thHeight: Math.max(28, Math.round((cellPadding * 2 + tableFontSize * 1.2) * tableScaleRatio)),
                thPaddingV: Math.max(4, Math.round(cellPadding * 0.9 * tableScaleRatio)),
                thFontSize: Math.round(tableFontSize * fontScale * tableScaleRatio),
                rowHeight: Math.max(28, Math.round((cellPadding * 2 + tableFontSize * 1.2) * tableScaleRatio)),
                tdPaddingV: Math.round(cellPadding * tableScaleRatio),
                tdFontSize: Math.round(tableFontSize * fontScale * tableScaleRatio),
                scaleMode: 'manual'
            };

            // Apply style rules to cards and split rows
            var isAnyTableSplit = !!(scs_settings.table_split_rows || scs_settings.table_split_cells);
            el.toggleClass('scs-table-split-active', isAnyTableSplit);
            if (isAnyTableSplit) {
                el.find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
            }

            el.find('.scs-card').each(function() {
                var isTable = $(this).attr('data-scs-block') === 'table' || $(this).attr('id') === 'bp-table-card' || $(this).attr('id') === 'preview-table-card';
                var isSplitRow = $(this).hasClass('scs-split-row-block');
                if (isTable) {
                    if (isAnyTableSplit) {
                        $(this).addClass('hidden').attr('style', 'display: none !important;');
                        return;
                    }
                    $(this).removeClass('hidden').css('display', '');
                    $(this).addClass('scs-dynamic-table-card');
                    var cardCss = {
                        'background-color': tableCardBg,
                        'background': isTableTransparent ? 'transparent' : tableCardBg,
                        'border-color': isPureData ? 'transparent' : (isTableTransparent ? (tableBorderWidth > 0 ? tableBorderColor : 'transparent') : cardBorder),
                        'border': isPureData ? 'none' : (tableBorderWidth > 0 ? (tableBorderWidth + 'px solid ' + (isTableTransparent ? tableBorderColor : cardBorder)) : 'none'),
                        'border-radius': isPureData ? '0px' : cardRadius,
                        'color': textPrimary,
                        'box-shadow': (isTableTransparent || isPureData) ? 'none' : ''
                    };
                    if (isAutoScale && tableDim.cardHeight) {
                        cardCss['height'] = tableDim.cardHeight + 'px';
                        cardCss['min-height'] = 'unset';
                        cardCss['max-height'] = Math.max(tableDim.cardHeight, targetBoxH) + 'px';
                        cardCss['overflow'] = 'hidden';
                        cardCss['display'] = 'block';
                    }
                    $(this).css(cardCss);
                } else if (isSplitRow) {
                    $(this).css({
                        'background-color': isPureData ? 'transparent' : (isTableTransparent ? 'transparent' : tableCardBg),
                        'background': isPureData ? 'transparent' : (isTableTransparent ? 'transparent' : tableCardBg),
                        'border': isPureData ? 'none' : tableBorderCss,
                        'border-radius': isPureData ? '0px' : cardRadius,
                        'box-shadow': 'none',
                        'color': cellText
                    });
                } else {
                    $(this).css({
                        'background-color': cardBg,
                        'border-color': cardBorder,
                        'border-radius': cardRadius,
                        'color': textPrimary
                    });
                }
            });

            // Apply pill style
            el.find('.scs-pill').each(function() {
                $(this).css({
                    'background-color': isCustom ? hexToRgba(scs_settings.card_bg_color || '#ffffff', Math.min(100, (scs_settings.card_bg_opacity ?? 100) + 10)) : '#f8fafc',
                    'border-color': cardBorder,
                    'color': textPrimary
                });
            });

            // Table container & card overflow protection
            el.find('#preview-table-container, #bp-table-container').css({
                'width': '100%',
                'overflow-x': 'auto',
                'overflow-y': 'hidden',
                'display': 'block'
            });

            // Table styling (headers, rows, cells, fonts, borders, gap)
            tableEl.css({
                'font-size': tableDim.tdFontSize + 'px',
                'border-collapse': (rowGap > 0 ? 'separate' : 'collapse'),
                'border-spacing': (rowGap > 0 ? '0px ' + rowGap + 'px' : '0px'),
                'background-color': isTableTransparent ? 'transparent' : 'inherit',
                'width': '100%',
                'height': (tableDim.scaleMode === 'fixed_frame' ? '100%' : 'auto'),
                'table-layout': 'auto',
                'direction': 'rtl',
                'letter-spacing': '0px',
                'margin': '0',
                'display': 'table'
            });

            var theadEl = el.find('thead');
            if (!showHeader) {
                theadEl.hide();
            } else {
                theadEl.show();
                theadEl.css({
                    'background-color': isTableTransparent ? 'transparent' : thBg,
                    'color': thText,
                    'border-bottom': tableBorderCss,
                    'min-height': tableDim.thHeight + 'px',
                    'display': 'table-header-group'
                });
            }

            var thCells = el.find('thead th');
            var colCount = thCells.length || 4;
            var padH = Math.max(3, Math.round(cellPadding * (colCount >= 8 ? 0.45 : (colCount >= 6 ? 0.6 : 0.8))));
            var thFontAdaptive = colCount >= 8 ? Math.min(10.5, tableDim.thFontSize) : (colCount >= 6 ? Math.min(11, tableDim.thFontSize) : tableDim.thFontSize);
            var tdFontAdaptive = colCount >= 8 ? Math.min(11, tableDim.tdFontSize) : tableDim.tdFontSize;

            thCells.removeClass('bg-slate-50 text-slate-800 border-slate-200 border-slate-100');
            thCells.css({
                'background-color': isTableTransparent ? 'transparent' : thBg,
                'color': thText,
                'border': isPureData ? 'none' : tableBorderCss,
                'padding': `${Math.max(4, tableDim.thPaddingV)}px ${padH}px`,
                'font-family': headerFont,
                'font-size': thFontAdaptive + 'px',
                'vertical-align': 'middle',
                'text-align': tableTextAlign,
                'letter-spacing': '0px',
                'word-break': 'keep-all',
                'white-space': 'nowrap',
                'line-height': '1.35',
                'display': 'table-cell',
                'box-sizing': 'border-box'
            });

            el.find('thead tr').css({
                'display': 'table-row'
            });

            bodyRows.css({
                'min-height': tableDim.rowHeight + 'px',
                'display': 'table-row'
            });

            var tdCells = el.find('tbody td');
            tdCells.removeClass('border-slate-200 border-slate-100');
            tdCells.css({
                'border': isPureData ? 'none' : tableBorderCss,
                'color': cellText,
                'padding': `${Math.max(4, tableDim.tdPaddingV)}px ${padH}px`,
                'font-family': activeFont,
                'font-size': tdFontAdaptive + 'px',
                'vertical-align': 'middle',
                'text-align': tableTextAlign,
                'letter-spacing': '0px',
                'word-break': 'keep-all',
                'white-space': 'nowrap',
                'line-height': '1.35',
                'display': 'table-cell',
                'box-sizing': 'border-box'
            });

            el.find('tbody tr td:first-child').css({ 'font-weight': '900' });
            el.find('thead tr th:first-child').css({ 'font-weight': '900' });

            // Split cells inside individual row blocks and matrix blocks
            var splitRowsFontSize = parseInt(scs_settings.table_font_size) || tableDim.tdFontSize || 11;
            var scaledSplitFontSize = Math.round(splitRowsFontSize * tableScaleRatio);
            el.find('.scs-split-cell').each(function() {
                var rowBlock = $(this).closest('.scs-split-row-block');
                var rKey = rowBlock.data('scs-block');
                var customRowFontSize = (scs_settings.block_positions && scs_settings.block_positions[rKey] && scs_settings.block_positions[rKey].fontSize) ? parseInt(scs_settings.block_positions[rKey].fontSize) : scaledSplitFontSize;
                $(this).css({
                    'color': cellText,
                    'font-size': customRowFontSize + 'px',
                    'font-family': activeFont,
                    'text-align': tableTextAlign
                });
            });

            // Split cells in matrix cell-by-cell mode
            el.find('.scs-split-cell-block').each(function() {
                var cellKey = $(this).data('scs-block') || '';
                var isHeader = $(this).hasClass('scs-split-header-cell');
                var customCellFontSize = (scs_settings.block_positions && scs_settings.block_positions[cellKey] && scs_settings.block_positions[cellKey].fontSize) ? parseInt(scs_settings.block_positions[cellKey].fontSize) : (isHeader ? Math.round(scaledSplitFontSize * 1.05) : scaledSplitFontSize);
                $(this).find('.scs-cell-content').css({
                    'font-size': customCellFontSize + 'px',
                    'font-family': isHeader ? headerFont : activeFont,
                    'color': isHeader ? (scs_settings.table_header_text || textColor) : cellText,
                    'text-align': tableTextAlign
                });
            });

            el.find('tbody tr:nth-child(even)').css({
                'background-color': (isTableTransparent || isPureData) ? 'transparent' : rowAltBg
            });
            el.find('tbody tr:nth-child(odd)').css({
                'background-color': 'transparent'
            });

            // Brand Titles & Subtitles
            if (isMini) {
                $('#bp-brand-title').text(scs_settings.brand_title).css({ 'color': textPrimary, 'font-family': headerFont });
                $('#bp-brand-subtitle').text(scs_settings.brand_subtitle).css({ 'color': textMuted, 'font-family': activeFont });
                $('#bp-brand-tagline').text(scs_settings.brand_tagline).css('color', textMuted);
                $('#bp-brand-website').text(scs_settings.brand_website).css('color', textMuted);
                
                if (scs_settings.image_logo) {
                    $('#bp-brand-header-area').html(`<img src="${resolveImageUrl(scs_settings.image_logo)}" class="max-h-8 object-contain" alt="Logo">`);
                } else {
                    $('#bp-brand-header-area').html(`
                        <div class="text-lg font-black" style="color: ${textPrimary}; font-family: ${headerFont};">${scs_settings.brand_title}</div>
                        <div class="text-[9px] font-black" style="color: ${textMuted}; font-family: ${activeFont};">${scs_settings.brand_subtitle}</div>
                    `);
                }
            } else {
                $('#preview-html-brand-title').text(scs_settings.brand_title).css({ 'color': textPrimary, 'font-family': headerFont });
                $('#preview-html-brand-subtitle').text(scs_settings.brand_subtitle).css({ 'color': textMuted, 'font-family': activeFont });
                $('#preview-html-brand-tagline').text(scs_settings.brand_tagline).css('color', textMuted);
                $('#preview-html-brand-website').text(scs_settings.brand_website).css('color', textMuted);
                $('#preview-title').css({ 'color': textPrimary, 'font-family': headerFont });
                $('#preview-title-sub').css({ 'color': textMuted, 'font-family': activeFont });
                
                if (scs_settings.image_logo) {
                    $('#preview-brand-header-area').html(`<img src="${resolveImageUrl(scs_settings.image_logo)}" class="max-h-12 object-contain" alt="Logo">`);
                } else {
                    $('#preview-brand-header-area').html(`
                        <div class="text-2xl font-black" id="preview-html-brand-title" style="color: ${textPrimary}; font-family: ${headerFont};">${scs_settings.brand_title}</div>
                        <div class="text-[10px] font-black mt-1" id="preview-html-brand-subtitle" style="color: ${textMuted}; font-family: ${activeFont};">${scs_settings.brand_subtitle}</div>
                    `);
                }
            }

            // 8. Positioning Application (Freeform Absolute vs Flow DOM append)
            if (isFreeform) {
                applyFreeformPositionsToDOM(targetSelector, isMini);
            } else {
                // Reset any absolute positioning styling
                resetFreeformStyles(targetSelector, isMini);
                applyBlockOrderToDOM(targetSelector, isMini);
            }

            if (isMini) {
                updateBrandingPreviewScale();
            }
        }

        // Auto scaler for Tab 4 studio preview
        function updateBrandingPreviewScale() {
            var viewport = $('#branding-preview-viewport');
            if (viewport.length === 0) return;
            var scaler = $('#branding-preview-scaler');
            var w = scs_settings.canvas_width || 800;
            var h = scs_settings.canvas_height || 1000;

            var viewportW = viewport.width() || 440;
            var availableW = Math.max(280, viewportW - 20);
            var scale = Math.min(1.0, availableW / w);
            scaler.css({
                'transform': `scale(${scale})`,
                'transform-origin': 'top center',
                'width': w + 'px',
                'height': h + 'px'
            });
            viewport.css({
                'min-height': Math.round(h * scale + 36) + 'px'
            });
        }

        function getBrandingPreviewScale() {
            var scaler = $('#branding-preview-scaler');
            if (scaler.length === 0) return 1.0;
            var transform = scaler.css('transform');
            if (transform && transform !== 'none') {
                var values = transform.split('(')[1].split(')')[0].split(',');
                var a = parseFloat(values[0]);
                if (!isNaN(a) && a > 0) return a;
            }
            var viewport = $('#branding-preview-viewport');
            var w = scs_settings.canvas_width || 800;
            if (viewport.length > 0) {
                var availW = Math.max(280, viewport.width() - 20);
                return Math.min(1.0, availW / w);
            }
            return 1.0;
        }

        // Apply Freeform Absolute Positioning to DOM elements
        function applyFreeformPositionsToDOM(targetSelector, isMini) {
            var root = $(targetSelector);
            if (root.length === 0) return;
            var container = isMini ? root.find('#branding-preview-content') : root.find('#preview-content-container, #batch-canvas-content, .scs-content-wrap');
            if (container.length === 0) container = root;

            var canvasW = scs_settings.canvas_width || 800;
            var canvasH = scs_settings.canvas_height || 1000;

            root.css({
                'width': canvasW + 'px',
                'min-height': canvasH + 'px',
                'height': canvasH + 'px'
            });

            container.css({
                'position': 'relative',
                'min-height': canvasH + 'px',
                'height': canvasH + 'px',
                'width': '100%',
                'display': 'block',
                'padding': '0'
            });

            var defaultPositions = {
                'header': { top: 36, left: 36, width: canvasW - 72, height: 'auto', zIndex: 10 },
                'meta': { top: 125, left: 36, width: canvasW - 72, height: 'auto', zIndex: 11 },
                'table': { top: 180, left: 276, width: Math.max(300, canvasW - 312), height: 'auto', zIndex: 15 },
                'illus': { top: 180, left: 36, width: 224, height: 'auto', zIndex: 14 },
                'notes': { top: 580, left: 36, width: canvasW - 72, height: 'auto', zIndex: 16 },
                'specs': { top: 660, left: 36, width: canvasW - 72, height: 'auto', zIndex: 17 },
                'footer': { top: Math.max(750, canvasH - 70), left: 36, width: canvasW - 72, height: 'auto', zIndex: 12 }
            };

            if (!scs_settings.block_positions) {
                scs_settings.block_positions = defaultPositions;
            }

            var blockKeys = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];

            blockKeys.forEach(function(key) {
                var blockEl = container.find(`[data-scs-block="${key}"]`);
                if (blockEl.length === 0) return;

                var isVisible = getBlockVisibility(key);
                if (!isVisible || (key === 'table' && (scs_settings.table_split_rows || scs_settings.table_split_cells))) {
                    blockEl.addClass('hidden').attr('style', 'display: none !important;');
                    return;
                }
                blockEl.removeClass('hidden');

                var pos = scs_settings.block_positions[key] || defaultPositions[key] || { top: 0, left: 0, width: 300, height: 'auto', zIndex: 10 };
                
                var top = parseInt(pos.top) || 0;
                var left = parseInt(pos.left) || 0;
                var width = (pos.width === '100%' || pos.width === 'auto') ? (canvasW - 72) + 'px' : (parseInt(pos.width) + 'px');
                var height = (pos.height === 'auto' || !pos.height) ? 'auto' : (parseInt(pos.height) + 'px');
                var zIndex = pos.zIndex || 10;

                // If table is split into individual freeform rows or cells, hide monolithic table
                if (key === 'table' && (scs_settings.table_split_rows || scs_settings.table_split_cells)) {
                    blockEl.addClass('hidden').attr('style', 'display: none !important;');
                    return;
                }

                // Dynamic height calculation for table block in freeform mode
                if (key === 'table' && (scs_settings.table_auto_scale !== 0)) {
                    var actualTableRows = blockEl.find('tbody tr').filter(function() { return !$(this).find('td[colspan]').length; });
                    var rCount = actualTableRows.length || 1;
                    var targetH = parseInt(pos.height) || parseInt(scs_settings.table_target_height) || 360;
                    var tScaleRatio = ((scs_settings.table_scale ? parseInt(scs_settings.table_scale) : 100) || 100) / 100.0;
                    var dDim = calculateDynamicTableDimensions(rCount, targetH, {
                        scaleMode: scs_settings.table_scale_mode || 'smart_fit',
                        baseFontSize: parseInt(scs_settings.table_font_size) || 11,
                        basePadding: parseInt(scs_settings.table_cell_padding) || 8,
                        isMini: isMini,
                        fontScale: (scs_settings.font_scale || 100) / 100,
                        tableScale: tScaleRatio,
                        minRowHeight: scs_settings.table_min_row_height || 22,
                        maxRowHeight: scs_settings.table_max_row_height || 90
                    });
                    height = dDim.cardHeight + 'px';
                }

                blockEl.addClass('scs-freeform-block').css({
                    'position': 'absolute',
                    'top': top + 'px',
                    'left': left + 'px',
                    'width': width,
                    'height': height,
                    'z-index': zIndex,
                    'margin': '0',
                    'right': 'auto',
                    'bottom': 'auto'
                });

                // Inject Handles and Quick Toolbar on preview canvases (exclude batch export canvas)
                if (targetSelector !== '#scs-batch-canvas') {
                    if (blockEl.find('.scs-resize-handle').length === 0) {
                        blockEl.append(`
                            <div class="scs-resize-handle scs-handle-nw" data-handle="nw"></div>
                            <div class="scs-resize-handle scs-handle-n" data-handle="n"></div>
                            <div class="scs-resize-handle scs-handle-ne" data-handle="ne"></div>
                            <div class="scs-resize-handle scs-handle-w" data-handle="w"></div>
                            <div class="scs-resize-handle scs-handle-e" data-handle="e"></div>
                            <div class="scs-resize-handle scs-handle-sw" data-handle="sw"></div>
                            <div class="scs-resize-handle scs-handle-s" data-handle="s"></div>
                            <div class="scs-resize-handle scs-handle-se" data-handle="se"></div>
                        `);
                    }

                    if (blockEl.find('.scs-quick-toolbar').length === 0) {
                        var def = scs_block_definitions[key] || { name: key, icon: '📦' };
                        var scaleControls = '';
                        if (key === 'table') {
                            var tScale = scs_settings.table_scale || 100;
                            var isRatioLocked = !!(scs_settings.table_lock_ratio ?? 1);
                            scaleControls = `
                                <div class="flex items-center gap-1 border-r border-indigo-400/40 pr-1.5 mr-1">
                                    <button type="button" onclick="scaleSelectedBlock(0.9); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-700 hover:bg-indigo-600 rounded text-[9px] text-white" title="کوچکتر کردن جدول (-10%)">🔍-</button>
                                    <span class="px-1 text-[9px] font-mono font-black text-indigo-200" id="toolbar-scale-indicator">${tScale}%</span>
                                    <button type="button" onclick="scaleSelectedBlock(1.1); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-700 hover:bg-indigo-600 rounded text-[9px] text-white" title="بزرگتر کردن جدول (+10%)">🔍+</button>
                                    <button type="button" onclick="setTableScale(150); event.stopPropagation();" class="px-1.5 py-0.5 bg-amber-600 hover:bg-amber-500 rounded text-[9px] text-white font-bold" title="۱.۵ برابر">1.5x</button>
                                    <button type="button" onclick="setTableScale(200); event.stopPropagation();" class="px-1.5 py-0.5 bg-emerald-600 hover:bg-emerald-500 rounded text-[9px] text-white font-bold" title="۲ برابر">2x</button>
                                    <button type="button" onclick="toggleTableAspectLock(); event.stopPropagation();" id="toolbar-aspect-btn" class="px-1.5 py-0.5 ${isRatioLocked ? 'bg-emerald-600' : 'bg-slate-600'} rounded text-[9px] text-white" title="قفل یا رهاسازی نسبت ابعاد">🔒</button>
                                    <button type="button" onclick="toggleTablePureDataOverlay(!scs_settings.table_pure_data_overlay); event.stopPropagation();" class="px-1.5 py-0.5 ${scs_settings.table_pure_data_overlay ? 'bg-amber-600' : 'bg-slate-700'} hover:bg-amber-500 rounded text-[9px] text-white font-bold" title="حالت دیتای شفاف بدون کادر">✨ دیتای شفاف</button>
                                    <button type="button" onclick="toggleTableSplitRows(!scs_settings.table_split_rows); event.stopPropagation();" class="px-1.5 py-0.5 ${scs_settings.table_split_rows ? 'bg-indigo-600' : 'bg-slate-700'} hover:bg-indigo-500 rounded text-[9px] text-white font-bold" title="تفکیک ردیف‌های جدول به سطرهای آزاد">⚡ تفکیک ردیف‌ها</button>
                                    <button type="button" onclick="toggleTableSplitCells(!scs_settings.table_split_cells); event.stopPropagation();" class="px-1.5 py-0.5 ${scs_settings.table_split_cells ? 'bg-violet-600' : 'bg-slate-700'} hover:bg-violet-500 rounded text-[9px] text-white font-bold" title="تفکیک کامل تمام سلول‌ها و چیدمان ماتریسی">⚡⚡ چیدمان ماتریسی</button>
                                </div>
                            `;
                        }
                        blockEl.append(`
                            <div class="scs-quick-toolbar select-none" onclick="event.stopPropagation();">
                                <span>${def.icon} ${def.name}</span>
                                ${scaleControls}
                                <button type="button" onclick="alignSelectedBlock('center_h'); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-600 hover:bg-indigo-500 rounded text-[9px] text-white">↔️ وسط</button>
                                <button type="button" onclick="alignSelectedBlock('full_width'); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-600 hover:bg-indigo-500 rounded text-[9px] text-white">↕️ تمام‌عرض</button>
                                <button type="button" onclick="toggleBlockVisibility('${key}', false); event.stopPropagation();" class="px-1.5 py-0.5 bg-rose-600 hover:bg-rose-500 rounded text-[9px] text-white">✕</button>
                            </div>
                        `);
                    }

                    if (key === currentSelectedBlockKey) {
                        blockEl.addClass('is-selected');
                    }
                }
            });

            // Split Table Freeform Rows Rendering Engine
            if (scs_settings.table_split_rows) {
                container.find('.scs-split-cell-block').remove();
                container.find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
                var tableBlock = container.find('[data-scs-block="table"]');
                var tableRows = tableBlock.find('tbody tr').filter(function() { return !$(this).find('td[colspan]').length; });
                if (tableRows.length === 0) {
                    tableRows = $('#grid-body tr');
                }
                var tScaleRatio = ((scs_settings.table_scale ? parseInt(scs_settings.table_scale) : 100) || 100) / 100.0;
                var rowH = Math.max(26, Math.round(38 * tScaleRatio));
                var gap = parseInt(scs_settings.table_row_gap) || 12;
                var isPureData = !!scs_settings.table_pure_data_overlay;
                var isTransparent = isPureData || !!scs_settings.table_bg_transparent;
                var cellPadding = parseInt(scs_settings.table_cell_padding) || 8;
                var fontSize = Math.round((parseInt(scs_settings.table_font_size) || 11) * tScaleRatio);
                var textColor = scs_settings.table_cell_text || scs_settings.text_primary_color || '#1e293b';
                var textAlign = scs_settings.table_text_align || 'center';

                var tblPos = scs_settings.block_positions['table'] || defaultPositions['table'];
                var baseTop = parseInt(tblPos.top) || 180;
                var baseLeft = parseInt(tblPos.left) || 36;
                var baseW = (tblPos.width === '100%' || tblPos.width === 'auto') ? (canvasW - 72) : parseInt(tblPos.width);

                tableRows.each(function(rIdx) {
                    var rowKey = 'table_row_' + rIdx;
                    var cells = [];
                    $(this).find('td').each(function() {
                        var inputVal = $(this).find('input').val();
                        var txt = (inputVal !== undefined) ? inputVal.trim() : $(this).text().trim();
                        if (txt !== 'حذف' && txt !== '') cells.push(txt);
                    });
                    if (cells.length === 0) {
                        cells = ['سایز ' + (rIdx + 1), '۵' + rIdx, '۷' + rIdx, '۴' + rIdx];
                    }

                    var rPos = scs_settings.block_positions[rowKey];
                    var rCustomFontSize = (rPos && rPos.fontSize) ? parseInt(rPos.fontSize) : fontSize;

                    var cellsHtml = cells.map(function(c, cIdx) {
                        var isFirst = (cIdx === 0);
                        return `<div class="scs-split-cell" style="flex: 1; text-align: ${textAlign}; padding: 0 ${Math.max(2, Math.round(cellPadding*0.5))}px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: ${rCustomFontSize}px; color: ${textColor}; font-weight: ${isFirst ? '900' : '700'};">${c}</div>`;
                    }).join('');

                    var rowEl = container.find(`[data-scs-block="${rowKey}"]`);
                    if (rowEl.length === 0) {
                        rowEl = $(`
                            <div class="scs-freeform-block scs-split-row-block scs-card" data-scs-block="${rowKey}">
                                <div class="scs-split-row-inner flex items-center justify-between w-full h-full" style="direction: rtl;">
                                    ${cellsHtml}
                                </div>
                            </div>
                        `);
                        container.append(rowEl);
                    } else {
                        rowEl.find('.scs-split-row-inner').html(cellsHtml);
                        rowEl.find('.scs-split-cell').css('font-size', rCustomFontSize + 'px');
                        rowEl.removeClass('hidden');
                    }

                    if (!rPos) {
                        rPos = {
                            top: baseTop + rIdx * (rowH + gap),
                            left: baseLeft,
                            width: baseW,
                            height: rowH,
                            zIndex: 20 + rIdx
                        };
                        scs_settings.block_positions[rowKey] = rPos;
                    }

                    var rTop = parseInt(rPos.top) || (baseTop + rIdx * (rowH + gap));
                    var rLeft = parseInt(rPos.left) || baseLeft;
                    var rW = (rPos.width === '100%' || rPos.width === 'auto') ? (baseW + 'px') : (parseInt(rPos.width) + 'px');
                    var rH = (rPos.height === 'auto' || !rPos.height) ? (rowH + 'px') : (parseInt(rPos.height) + 'px');

                    rowEl.css({
                        'position': 'absolute',
                        'top': rTop + 'px',
                        'left': rLeft + 'px',
                        'width': rW,
                        'height': rH,
                        'z-index': rPos.zIndex || (20 + rIdx),
                        'background': isPureData ? 'transparent' : (isTransparent ? 'transparent' : (scs_settings.table_bg_color || '#ffffff')),
                        'border': isPureData ? 'none' : ((parseInt(scs_settings.table_border_width) || 0) > 0 ? (scs_settings.table_border_width + 'px solid ' + (scs_settings.table_border_color || '#e2e8f0')) : 'none'),
                        'box-shadow': 'none',
                        'margin': '0'
                    });

                    if (targetSelector !== '#scs-batch-canvas') {
                        if (rowEl.find('.scs-resize-handle').length === 0) {
                            rowEl.append(`
                                <div class="scs-resize-handle scs-handle-nw" data-handle="nw"></div>
                                <div class="scs-resize-handle scs-handle-n" data-handle="n"></div>
                                <div class="scs-resize-handle scs-handle-ne" data-handle="ne"></div>
                                <div class="scs-resize-handle scs-handle-w" data-handle="w"></div>
                                <div class="scs-resize-handle scs-handle-e" data-handle="e"></div>
                                <div class="scs-resize-handle scs-handle-sw" data-handle="sw"></div>
                                <div class="scs-resize-handle scs-handle-s" data-handle="s"></div>
                                <div class="scs-resize-handle scs-handle-se" data-handle="se"></div>
                            `);
                        }
                        if (rowEl.find('.scs-quick-toolbar').length === 0) {
                            rowEl.append(`
                                <div class="scs-quick-toolbar select-none" onclick="event.stopPropagation();">
                                    <span>📏 ردیف ${rIdx + 1} (${cells[0] || ''})</span>
                                    <button type="button" onclick="adjustSplitRowsFontSize(-1); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-900 hover:bg-indigo-800 rounded text-[9px] text-white font-black" title="کوچک‌تر کردن قلم">A-</button>
                                    <button type="button" onclick="adjustSplitRowsFontSize(1); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-900 hover:bg-indigo-800 rounded text-[9px] text-white font-black" title="بزرگ‌تر کردن قلم">A+</button>
                                    <button type="button" onclick="distributeSplitRows(14); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-700 hover:bg-indigo-600 rounded text-[9px] text-white">📐 توزیع منظم</button>
                                    <button type="button" onclick="alignSplitRows(); event.stopPropagation();" class="px-1.5 py-0.5 bg-indigo-700 hover:bg-indigo-600 rounded text-[9px] text-white">↔️ هم‌راستا</button>
                                </div>
                            `);
                        }
                        if (rowKey === currentSelectedBlockKey) {
                            rowEl.addClass('is-selected');
                        }
                    }
                });
            } else if (!scs_settings.table_split_cells) {
                container.find('.scs-split-row-block').remove();
            }

            // Split Table Freeform Cells (Matrix Layout Engine)
            if (scs_settings.table_split_cells) {
                container.find('.scs-split-row-block').remove();
                container.find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
                var tableBlock = container.find('[data-scs-block="table"]');
                var tableRows = tableBlock.find('tbody tr').filter(function() { return !$(this).find('td[colspan]').length; });
                if (tableRows.length === 0) {
                    tableRows = $('#grid-body tr');
                }
                var colNames = getTableColNames();
                var rowNames = getTableRowNames();
                var numCols = colNames.length;
                var numRows = tableRows.length || 4;

                var tScaleRatio = ((scs_settings.table_scale ? parseInt(scs_settings.table_scale) : 100) || 100) / 100.0;
                var isPureData = !!scs_settings.table_pure_data_overlay;
                var isTransparent = isPureData || !!scs_settings.table_bg_transparent;
                var fontSize = Math.round((parseInt(scs_settings.table_font_size) || 11) * tScaleRatio);
                var textColor = scs_settings.table_cell_text || scs_settings.text_primary_color || '#1e293b';
                var textAlign = scs_settings.table_text_align || 'center';
                var tblPos = scs_settings.block_positions['table'] || defaultPositions['table'];
                var baseTop = parseInt(tblPos.top) || 180;
                var baseLeft = parseInt(tblPos.left) || 36;
                var baseW = (tblPos.width === '100%' || tblPos.width === 'auto') ? (canvasW - 72) : parseInt(tblPos.width);
                var showHeader = (scs_settings.table_show_header !== undefined) ? !!parseInt(scs_settings.table_show_header) : true;
                var rowH = Math.max(26, Math.round(36 * tScaleRatio));
                var rowGap = parseInt(scs_settings.table_row_gap) || 8;
                var colGap = 8;
                var defaultColW = Math.max(45, Math.floor((baseW - (numCols - 1) * colGap) / numCols));

                // 1. Header Cells (if enabled)
                if (showHeader) {
                    colNames.forEach(function(colTitle, cIdx) {
                        var hKey = 'table_header_' + cIdx;
                        var hPos = scs_settings.block_positions[hKey];
                        if (!hPos) {
                            hPos = {
                                top: baseTop,
                                left: baseLeft + cIdx * (defaultColW + colGap),
                                width: defaultColW,
                                height: rowH,
                                zIndex: 40 + cIdx
                            };
                            scs_settings.block_positions[hKey] = hPos;
                        }
                        var hTop = parseInt(hPos.top);
                        var hLeft = parseInt(hPos.left);
                        var hW = (hPos.width === 'auto') ? (defaultColW + 'px') : (parseInt(hPos.width) + 'px');
                        var hH = (hPos.height === 'auto' || !hPos.height) ? (rowH + 'px') : (parseInt(hPos.height) + 'px');

                        var hEl = container.find(`[data-scs-block="${hKey}"]`);
                        if (hEl.length === 0) {
                            hEl = $(`
                                <div class="scs-freeform-block scs-split-cell-block scs-split-header-cell" data-scs-block="${hKey}" data-col="${cIdx}">
                                    <div class="scs-coord-badge">A${cIdx + 1}: ${hLeft}, ${hTop}</div>
                                    <div class="scs-cell-content flex items-center justify-center w-full h-full text-center px-1 font-black" style="font-size: ${Math.round(fontSize*1.05)}px; color: ${scs_settings.table_header_text || textColor};">
                                        ${colTitle}
                                    </div>
                                </div>
                            `);
                            container.append(hEl);
                        } else {
                            hEl.find('.scs-cell-content').text(colTitle).css({
                                'font-size': Math.round(fontSize*1.05) + 'px',
                                'color': scs_settings.table_header_text || textColor
                            });
                            hEl.find('.scs-coord-badge').text(`A${cIdx + 1}: ${hLeft}, ${hTop}`);
                            hEl.removeClass('hidden');
                        }
                        hEl.css({
                            'position': 'absolute',
                            'top': hTop + 'px',
                            'left': hLeft + 'px',
                            'width': hW,
                            'height': hH,
                            'z-index': hPos.zIndex || (40 + cIdx),
                            'background': isPureData ? 'transparent' : (isTransparent ? 'transparent' : (scs_settings.table_header_bg || '#f1f5f9')),
                            'border': isPureData ? 'none' : ((parseInt(scs_settings.table_border_width) || 0) > 0 ? (scs_settings.table_border_width + 'px solid ' + (scs_settings.table_border_color || '#e2e8f0')) : 'none'),
                            'border-radius': isPureData ? '0' : '6px',
                            'box-shadow': 'none',
                            'margin': '0'
                        });

                        if (targetSelector !== '#scs-batch-canvas') {
                            if (hEl.find('.scs-resize-handle').length === 0) {
                                hEl.append(`
                                    <div class="scs-resize-handle scs-handle-nw" data-handle="nw"></div>
                                    <div class="scs-resize-handle scs-handle-n" data-handle="n"></div>
                                    <div class="scs-resize-handle scs-handle-ne" data-handle="ne"></div>
                                    <div class="scs-resize-handle scs-handle-w" data-handle="w"></div>
                                    <div class="scs-resize-handle scs-handle-e" data-handle="e"></div>
                                    <div class="scs-resize-handle scs-handle-sw" data-handle="sw"></div>
                                    <div class="scs-resize-handle scs-handle-s" data-handle="s"></div>
                                    <div class="scs-resize-handle scs-handle-se" data-handle="se"></div>
                                `);
                            }
                            if (hEl.find('.scs-quick-toolbar').length === 0) {
                                hEl.append(`
                                    <div class="scs-quick-toolbar select-none" onclick="event.stopPropagation();">
                                        <span>🏷️ سرستون A${cIdx + 1}: ${colTitle}</span>
                                        <button type="button" onclick="alignColCells(${cIdx}); event.stopPropagation();" class="px-1.5 py-0.5 bg-violet-700 hover:bg-violet-600 rounded text-[9px] text-white">↔️ تراز ستون</button>
                                    </div>
                                `);
                            }
                            if (hKey === currentSelectedBlockKey) {
                                hEl.addClass('is-selected');
                            }
                        }
                    });
                } else {
                    container.find('.scs-split-header-cell').remove();
                }

                // 2. Data Cells
                var startBodyTop = baseTop + (showHeader ? (rowH + rowGap) : 0);
                tableRows.each(function(rIdx) {
                    var cells = [];
                    $(this).find('td').each(function() {
                        var inputVal = $(this).find('input').val();
                        var txt = (inputVal !== undefined) ? inputVal.trim() : $(this).text().trim();
                        if (txt !== 'حذف' && txt !== '') cells.push(txt);
                    });
                    if (cells.length === 0) {
                        cells = ['سایز ' + (rIdx + 1), '۵' + rIdx, '۷' + rIdx, '۴' + rIdx];
                    }

                    cells.forEach(function(cellVal, cIdx) {
                        var cellKey = 'table_cell_' + rIdx + '_' + cIdx;
                        var colName = colNames[cIdx] || ('ستون ' + (cIdx + 1));
                        var rowName = rowNames[rIdx] || ('سطر ' + (rIdx + 1));
                        var isFirstCol = (cIdx === 0);

                        var cPos = scs_settings.block_positions[cellKey];
                        if (!cPos) {
                            var matchedHeaderPos = scs_settings.block_positions['table_header_' + cIdx];
                            var cellLeftDefault = matchedHeaderPos ? parseInt(matchedHeaderPos.left) : (baseLeft + cIdx * (defaultColW + colGap));
                            var cellWDefault = matchedHeaderPos ? parseInt(matchedHeaderPos.width) : defaultColW;

                            cPos = {
                                top: startBodyTop + rIdx * (rowH + rowGap),
                                left: cellLeftDefault,
                                width: cellWDefault,
                                height: rowH,
                                zIndex: 20 + rIdx * 10 + cIdx
                            };
                            scs_settings.block_positions[cellKey] = cPos;
                        }

                        var cTop = parseInt(cPos.top);
                        var cLeft = parseInt(cPos.left);
                        var cW = (cPos.width === 'auto') ? (defaultColW + 'px') : (parseInt(cPos.width) + 'px');
                        var cH = (cPos.height === 'auto' || !cPos.height) ? (rowH + 'px') : (parseInt(cPos.height) + 'px');

                        var cCustomFontSize = (cPos && cPos.fontSize) ? parseInt(cPos.fontSize) : fontSize;
                        var cellEl = container.find(`[data-scs-block="${cellKey}"]`);
                        if (cellEl.length === 0) {
                            cellEl = $(`
                                <div class="scs-freeform-block scs-split-cell-block scs-split-data-cell" data-scs-block="${cellKey}" data-row="${rIdx}" data-col="${cIdx}">
                                    <div class="scs-coord-badge">C${cIdx + 1}-R${rIdx + 1} (${cLeft}, ${cTop})</div>
                                    <div class="scs-cell-content flex items-center justify-center w-full h-full text-center px-1 overflow-hidden" style="text-align: ${textAlign}; font-size: ${cCustomFontSize}px; color: ${textColor}; font-weight: ${isFirstCol ? '900' : '700'};">
                                        ${cellVal}
                                    </div>
                                </div>
                            `);
                            container.append(cellEl);
                        } else {
                            cellEl.find('.scs-cell-content').text(cellVal).css({
                                'text-align': textAlign,
                                'font-size': cCustomFontSize + 'px',
                                'color': textColor,
                                'font-weight': isFirstCol ? '900' : '700'
                            });
                            cellEl.find('.scs-coord-badge').text(`C${cIdx + 1}-R${rIdx + 1} (${cLeft}, ${cTop})`);
                            cellEl.removeClass('hidden');
                        }

                        cellEl.css({
                            'position': 'absolute',
                            'top': cTop + 'px',
                            'left': cLeft + 'px',
                            'width': cW,
                            'height': cH,
                            'z-index': cPos.zIndex || (20 + rIdx * 10 + cIdx),
                            'background': isPureData ? 'transparent' : (isTransparent ? 'transparent' : (scs_settings.table_bg_color || '#ffffff')),
                            'border': isPureData ? 'none' : ((parseInt(scs_settings.table_border_width) || 0) > 0 ? (scs_settings.table_border_width + 'px solid ' + (scs_settings.table_border_color || '#e2e8f0')) : 'none'),
                            'border-radius': isPureData ? '0' : '4px',
                            'box-shadow': 'none',
                            'margin': '0'
                        });

                        if (targetSelector !== '#scs-batch-canvas') {
                            if (cellEl.find('.scs-resize-handle').length === 0) {
                                cellEl.append(`
                                    <div class="scs-resize-handle scs-handle-nw" data-handle="nw"></div>
                                    <div class="scs-resize-handle scs-handle-n" data-handle="n"></div>
                                    <div class="scs-resize-handle scs-handle-ne" data-handle="ne"></div>
                                    <div class="scs-resize-handle scs-handle-w" data-handle="w"></div>
                                    <div class="scs-resize-handle scs-handle-e" data-handle="e"></div>
                                    <div class="scs-resize-handle scs-handle-sw" data-handle="sw"></div>
                                    <div class="scs-resize-handle scs-handle-s" data-handle="s"></div>
                                    <div class="scs-resize-handle scs-handle-se" data-handle="se"></div>
                                `);
                            }
                            if (cellEl.find('.scs-quick-toolbar').length === 0) {
                                cellEl.append(`
                                    <div class="scs-quick-toolbar select-none" onclick="event.stopPropagation();">
                                        <span>📍 [A${cIdx + 1} - R${rIdx + 1}]: ${cellVal}</span>
                                        <button type="button" onclick="alignColCells(${cIdx}); event.stopPropagation();" class="px-1.5 py-0.5 bg-violet-700 hover:bg-violet-600 rounded text-[9px] text-white" title="تراز افقی تمام مقادیر این ستون">↔️ تراز ستون</button>
                                        <button type="button" onclick="alignRowCells(${rIdx}); event.stopPropagation();" class="px-1.5 py-0.5 bg-violet-700 hover:bg-violet-600 rounded text-[9px] text-white" title="تراز عمودی تمام مقادیر این سطر">↕️ تراز سطر</button>
                                    </div>
                                `);
                            }
                            if (cellKey === currentSelectedBlockKey) {
                                cellEl.addClass('is-selected');
                            }
                        }
                    });
                });
            } else {
                container.find('.scs-split-cell-block').remove();
            }

            if (targetSelector !== '#scs-batch-canvas') {
                initFreeformCanvasInteraction();
            }
        }

        // Reset elements when switching back to Flow mode
        function resetFreeformStyles(targetSelector, isMini) {
            var root = $(targetSelector);
            if (root.length === 0) return;
            var container = isMini ? root.find('#branding-preview-content') : root.find('#preview-content-container, #batch-canvas-content, .scs-content-wrap');
            if (container.length === 0) container = root;

            var canvasW = scs_settings.canvas_width || 800;
            var canvasH = scs_settings.canvas_height || 1000;

            root.css({
                'width': canvasW + 'px',
                'min-height': canvasH + 'px',
                'height': canvasH + 'px'
            });

            var padTop = scs_settings.content_padding_top ?? 36;
            var padBottom = scs_settings.content_padding_bottom ?? 28;
            var padX = scs_settings.content_padding_x ?? 36;

            container.css({
                'position': 'relative',
                'min-height': canvasH + 'px',
                'height': '100%',
                'width': '100%',
                'display': 'flex',
                'flex-direction': 'column',
                'justify-content': 'space-between',
                'padding-top': padTop + 'px',
                'padding-bottom': padBottom + 'px',
                'padding-left': padX + 'px',
                'padding-right': padX + 'px'
            });

            var blockKeys = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            blockKeys.forEach(function(key) {
                var blockEl = container.find(`[data-scs-block="${key}"]`);
                if (blockEl.length > 0) {
                    blockEl.removeClass('scs-freeform-block is-selected').css({
                        'position': '',
                        'top': '',
                        'left': '',
                        'width': '',
                        'height': '',
                        'z-index': '',
                        'margin': '',
                        'right': '',
                        'bottom': ''
                    });
                }
            });

            if (scs_settings.table_split_rows || scs_settings.table_split_cells) {
                container.find('[data-scs-block="table"], #preview-table-card, #bp-table-card').addClass('hidden').attr('style', 'display: none !important;');
            } else {
                container.find('.scs-split-row-block, .scs-split-cell-block').remove();
                container.find('[data-scs-block="table"], #preview-table-card, #bp-table-card').removeClass('hidden').css('display', '');
            }
        }

        // Freeform Canvas Drag, Resize & Snap Controller (Unified for both canvases)
        function initFreeformCanvasInteraction() {
            var canvases = $('#td-preview, #branding-live-preview');
            if (canvases.length === 0) return;

            // Remove previous handlers to avoid duplicate bindings
            canvases.off('mousedown.scsfreeform');
            $(document).off('mousemove.scsfreeform mouseup.scsfreeform');

            var activeAction = null; // 'drag' | 'resize'
            var activeBlockKey = null;
            var activeHandle = null;
            var startMouseX = 0;
            var startMouseY = 0;
            var startBlockPos = null;
            var activeCanvasIsBranding = false;

            canvases.on('mousedown.scsfreeform', '.scs-freeform-block', function(e) {
                if ($(e.target).closest('button, input, select, textarea').length > 0) return;

                var blockEl = $(this);
                var canvasParent = blockEl.closest('.scs-canvas');
                activeCanvasIsBranding = (canvasParent.attr('id') === 'branding-live-preview');

                activeBlockKey = blockEl.data('scs-block');
                if (!activeBlockKey) return;

                selectCanvasBlock(activeBlockKey);

                var handleTarget = $(e.target).closest('.scs-resize-handle');
                if (handleTarget.length > 0) {
                    activeAction = 'resize';
                    activeHandle = handleTarget.data('handle');
                    $(`#td-preview [data-scs-block="${activeBlockKey}"], #branding-live-preview [data-scs-block="${activeBlockKey}"]`).addClass('is-resizing');
                } else {
                    activeAction = 'drag';
                    activeHandle = null;
                    $(`#td-preview [data-scs-block="${activeBlockKey}"], #branding-live-preview [data-scs-block="${activeBlockKey}"]`).addClass('is-dragging');
                    $('body').css('cursor', 'grabbing');
                }

                startMouseX = e.pageX;
                startMouseY = e.pageY;

                if (!scs_settings.block_positions) scs_settings.block_positions = {};
                if (!scs_settings.block_positions[activeBlockKey]) {
                    scs_settings.block_positions[activeBlockKey] = {
                        top: parseInt(blockEl.css('top')) || 0,
                        left: parseInt(blockEl.css('left')) || 0,
                        width: blockEl.outerWidth() || 300,
                        height: blockEl.outerHeight() || 100,
                        zIndex: parseInt(blockEl.css('z-index')) || 10
                    };
                }

                var currentPos = scs_settings.block_positions[activeBlockKey];

                startBlockPos = {
                    top: parseInt(currentPos.top) || (parseInt(blockEl.css('top')) || 0),
                    left: parseInt(currentPos.left) || (parseInt(blockEl.css('left')) || 0),
                    width: (currentPos.width === 'auto' || currentPos.width === '100%') ? blockEl.outerWidth() : (parseInt(currentPos.width) || blockEl.outerWidth()),
                    height: (currentPos.height === 'auto' || !currentPos.height) ? blockEl.outerHeight() : (parseInt(currentPos.height) || blockEl.outerHeight()),
                    zIndex: currentPos.zIndex || (parseInt(blockEl.css('z-index')) || 10)
                };

                e.preventDefault();
                e.stopPropagation();
            });

            $(document).on('mousemove.scsfreeform', function(e) {
                if (!activeAction || !activeBlockKey || !startBlockPos) return;

                var scaleFactor = activeCanvasIsBranding ? getBrandingPreviewScale() : canvasZoomLevel;
                if (!scaleFactor || isNaN(scaleFactor) || scaleFactor <= 0) scaleFactor = 1.0;

                var deltaX = (e.pageX - startMouseX) / scaleFactor;
                var deltaY = (e.pageY - startMouseY) / scaleFactor;

                var canvasW = scs_settings.canvas_width || 800;
                var canvasH = scs_settings.canvas_height || 1000;
                var syncedBlocks = $(`#td-preview [data-scs-block="${activeBlockKey}"], #branding-live-preview [data-scs-block="${activeBlockKey}"]`);

                if (activeAction === 'drag') {
                    var newLeft = Math.round(startBlockPos.left + deltaX);
                    var newTop = Math.round(startBlockPos.top + deltaY);

                    // Magnetic Snapping
                    if (isCanvasSnapActive) {
                        var snapThreshold = 12;
                        var snapGuideX = activeCanvasIsBranding ? $('#scs-snap-x-branding') : $('#scs-snap-x');
                        var snapGuideY = activeCanvasIsBranding ? $('#scs-snap-y-branding') : $('#scs-snap-y');

                        // Snap to Left margin (36px)
                        if (Math.abs(newLeft - 36) < snapThreshold) {
                            newLeft = 36;
                            snapGuideX.css({ 'left': '36px', 'display': 'block' });
                        }
                        // Snap to Center horizontal
                        else if (Math.abs(newLeft + (startBlockPos.width / 2) - (canvasW / 2)) < snapThreshold) {
                            newLeft = Math.round((canvasW - startBlockPos.width) / 2);
                            snapGuideX.css({ 'left': (canvasW / 2) + 'px', 'display': 'block' });
                        }
                        // Snap to Right margin
                        else if (Math.abs(newLeft + startBlockPos.width - (canvasW - 36)) < snapThreshold) {
                            newLeft = canvasW - startBlockPos.width - 36;
                            snapGuideX.css({ 'left': (canvasW - 36) + 'px', 'display': 'block' });
                        } else {
                            snapGuideX.hide();
                        }

                        // Snap to Top margin (36px)
                        if (Math.abs(newTop - 36) < snapThreshold) {
                            newTop = 36;
                            snapGuideY.css({ 'top': '36px', 'display': 'block' });
                        }
                        // Snap to Bottom margin
                        else if (Math.abs(newTop + startBlockPos.height - (canvasH - 36)) < snapThreshold) {
                            newTop = canvasH - startBlockPos.height - 36;
                            snapGuideY.css({ 'top': (canvasH - 36) + 'px', 'display': 'block' });
                        } else {
                            snapGuideY.hide();
                        }
                    }

                    newLeft = Math.max(0, Math.min(canvasW - 40, newLeft));
                    newTop = Math.max(0, Math.min(canvasH - 40, newTop));

                    if (!scs_settings.block_positions[activeBlockKey]) scs_settings.block_positions[activeBlockKey] = {};
                    scs_settings.block_positions[activeBlockKey].left = newLeft;
                    scs_settings.block_positions[activeBlockKey].top = newTop;

                    syncedBlocks.css({
                        'left': newLeft + 'px',
                        'top': newTop + 'px'
                    });

                    $('#scs-insp-x').val(newLeft);
                    $('#scs-insp-y').val(newTop);

                } else if (activeAction === 'resize') {
                    var newW = startBlockPos.width;
                    var newH = startBlockPos.height;
                    var newL = startBlockPos.left;
                    var newT = startBlockPos.top;

                    var isCorner = (activeHandle === 'nw' || activeHandle === 'ne' || activeHandle === 'sw' || activeHandle === 'se');
                    var isAspectLocked = (activeBlockKey === 'table' && (scs_settings.table_lock_ratio ?? 1)) || e.shiftKey;
                    var aspect = (startBlockPos.width > 0 && startBlockPos.height > 0) ? (startBlockPos.width / startBlockPos.height) : (480 / 360);

                    if (isCorner && isAspectLocked) {
                        var scaleDelta = 0;
                        if (activeHandle === 'se') {
                            scaleDelta = (deltaX / startBlockPos.width + deltaY / startBlockPos.height) / 2;
                            var scale = Math.max(0.2, 1 + scaleDelta);
                            newW = Math.max(60, Math.min(canvasW - newL, Math.round(startBlockPos.width * scale)));
                            newH = Math.max(30, Math.min(canvasH - newT, Math.round(newW / aspect)));
                            newW = Math.round(newH * aspect);
                        } else if (activeHandle === 'sw') {
                            scaleDelta = (-deltaX / startBlockPos.width + deltaY / startBlockPos.height) / 2;
                            var scale = Math.max(0.2, 1 + scaleDelta);
                            newW = Math.max(60, Math.round(startBlockPos.width * scale));
                            newH = Math.max(30, Math.min(canvasH - newT, Math.round(newW / aspect)));
                            newW = Math.round(newH * aspect);
                            newL = Math.max(0, Math.round(startBlockPos.left + (startBlockPos.width - newW)));
                        } else if (activeHandle === 'ne') {
                            scaleDelta = (deltaX / startBlockPos.width - deltaY / startBlockPos.height) / 2;
                            var scale = Math.max(0.2, 1 + scaleDelta);
                            newW = Math.max(60, Math.min(canvasW - newL, Math.round(startBlockPos.width * scale)));
                            newH = Math.max(30, Math.round(newW / aspect));
                            newT = Math.max(0, Math.round(startBlockPos.top + (startBlockPos.height - newH)));
                        } else if (activeHandle === 'nw') {
                            scaleDelta = (-deltaX / startBlockPos.width - deltaY / startBlockPos.height) / 2;
                            var scale = Math.max(0.2, 1 + scaleDelta);
                            newW = Math.max(60, Math.round(startBlockPos.width * scale));
                            newH = Math.max(30, Math.round(newW / aspect));
                            newL = Math.max(0, Math.round(startBlockPos.left + (startBlockPos.width - newW)));
                            newT = Math.max(0, Math.round(startBlockPos.top + (startBlockPos.height - newH)));
                        }
                    } else {
                        // Standard edge or unconstrained corner resizing
                        if (activeHandle === 'e' || activeHandle === 'ne' || activeHandle === 'se') {
                            newW = Math.max(60, Math.min(canvasW - newL, Math.round(startBlockPos.width + deltaX)));
                        }
                        if (activeHandle === 'w' || activeHandle === 'nw' || activeHandle === 'sw') {
                            var targetW = Math.round(startBlockPos.width - deltaX);
                            var targetL = Math.round(startBlockPos.left + deltaX);
                            if (targetW >= 60 && targetL >= 0) {
                                newW = targetW;
                                newL = targetL;
                            }
                        }
                        if (activeHandle === 's' || activeHandle === 'se' || activeHandle === 'sw') {
                            newH = Math.max(30, Math.min(canvasH - newT, Math.round(startBlockPos.height + deltaY)));
                        }
                        if (activeHandle === 'n' || activeHandle === 'ne' || activeHandle === 'nw') {
                            var targetH = Math.round(startBlockPos.height - deltaY);
                            var targetT = Math.round(startBlockPos.top + deltaY);
                            if (targetH >= 30 && targetT >= 0) {
                                newH = targetH;
                                newT = targetT;
                            }
                        }
                    }

                    if (!scs_settings.block_positions[activeBlockKey]) scs_settings.block_positions[activeBlockKey] = {};
                    scs_settings.block_positions[activeBlockKey].width = newW;
                    scs_settings.block_positions[activeBlockKey].height = newH;
                    scs_settings.block_positions[activeBlockKey].left = newL;
                    scs_settings.block_positions[activeBlockKey].top = newT;

                    syncedBlocks.css({
                        'width': newW + 'px',
                        'height': newH + 'px',
                        'left': newL + 'px',
                        'top': newT + 'px'
                    });

                    $('#scs-insp-w').val(newW);
                    $('#scs-insp-h').val(newH);
                    $('#scs-insp-x').val(newL);
                    $('#scs-insp-y').val(newT);
                }
            });

            $(document).on('mouseup.scsfreeform', function() {
                if (activeAction) {
                    var finishedBlockKey = activeBlockKey;
                    var finishedAction = activeAction;
                    if (finishedBlockKey) {
                        $(`#td-preview [data-scs-block="${finishedBlockKey}"], #branding-live-preview [data-scs-block="${finishedBlockKey}"]`).removeClass('is-dragging is-resizing');
                    }
                    $('body').css('cursor', '');
                    activeAction = null;
                    activeBlockKey = null;
                    activeHandle = null;
                    startBlockPos = null;
                    $('#scs-snap-x, #scs-snap-y, #scs-snap-x-branding, #scs-snap-y-branding').hide();

                    if (finishedBlockKey === 'table' && finishedAction === 'resize') {
                        var tblPos = scs_settings.block_positions['table'];
                        if (tblPos) {
                            tblPos.base_width = tblPos.width;
                            tblPos.base_height = tblPos.height;
                            scs_settings.table_target_height = tblPos.height;
                            $('#setting-table-target-height').val(tblPos.height);
                            $('#table-target-height-label').text(tblPos.height + 'px');
                        }
                        applyBrandingStyleToTarget('#td-preview', false);
                        applyBrandingStyleToTarget('#branding-live-preview', true);
                    }

                    if (finishedBlockKey && (finishedBlockKey.startsWith('table_cell_') || finishedBlockKey.startsWith('table_header_'))) {
                        syncMatrixPanelInputs();
                    }
                }
            });
        }

        // Custom Font Upload Handler
        function uploadCustomFontFile(input) {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            var formData = new FormData();
            formData.append('action', 'td_scs_font_upload');
            formData.append('font_file', file);

            showToast('در حال بارگذاری و پردازش فونت دلخواه...', 'info');

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    try {
                        if (typeof res === 'string') res = JSON.parse(res);
                    } catch(e){}
                    if (res.success && res.font_url) {
                        $('#setting-custom-font-url').val(res.font_url);
                        $('#setting-custom-font-name').val(res.font_name);
                        
                        // Inject Font-Face dynamically
                        var fontFaceId = 'dynamic-custom-font-style';
                        $('#' + fontFaceId).remove();
                        var fontFaceCSS = `
                            @font-face {
                                font-family: '${res.font_name}';
                                src: url('${res.font_url}') format('${res.font_format || "truetype"}');
                                font-weight: normal;
                                font-style: normal;
                            }
                        `;
                        $('head').append(`<style id="${fontFaceId}">${fontFaceCSS}</style>`);

                        // Select Custom Font in dropdown
                        $('#setting-font-family').val('custom');
                        onFontFamilyChanged('custom');
                        showToast(`فونت «${res.font_name}» با موفقیت فعال شد. ✨`, 'success');
                    } else {
                        showToast(res.message || 'خطا در بارگذاری فایل فونت.', 'error');
                    }
                },
                error: function() {
                    showToast('خطای سرور در آپلود فونت.', 'error');
                }
            });
        }

        function removeCustomFont() {
            $('#setting-custom-font-url').val('');
            $('#setting-custom-font-name').val('');
            $('#setting-font-family').val('Vazirmatn');
            $('#dynamic-custom-font-style').remove();
            onFontFamilyChanged('Vazirmatn');
            showToast('فونت اختصاصی حذف شد و فونت پیش‌فرض وزیرمتن تنظیم گردید.', 'info');
        }

        function onFontFamilyChanged(fontName) {
            var activeName = fontName;
            if (fontName === 'custom') {
                var customName = $('#setting-custom-font-name').val();
                if (!customName) {
                    showToast('لطفا ابتدا یک فایل فونت معتبر آپلود کنید.', 'warning');
                    $('#setting-font-family').val('Vazirmatn');
                    activeName = 'Vazirmatn';
                } else {
                    activeName = customName;
                }
            }

            $('#font-tester-text').css('font-family', `'${activeName}', sans-serif`);
            $('#font-active-badge').text(activeName);
            applyBrandingChanges();
        }

        // Custom Theme Presets Handler
        function applyCustomThemePreset(presetKey) {
            scs_settings.style_mode = 'custom';
            scs_settings.custom_theme_preset = presetKey;
            setStyleMode('custom');

            if (presetKey === 'persepolis') {
                // 🔴 Persepolis & Sport Red Brand Theme
                $('#setting-card-bg-color').val('#ffffff');
                $('#setting-card-bg-color-text').val('#ffffff');
                $('#setting-card-bg-opacity').val(95);
                $('#card-opacity-label').text('95%');
                $('#setting-card-border-radius').val(16);
                $('#setting-card-border-color').val('#e11d48');
                $('#setting-card-border-color-text').val('#e11d48');
                $('#setting-text-primary-color').val('#881337');
                $('#setting-text-primary-color-text').val('#881337');
                $('#setting-text-muted-color').val('#9f1239');
                $('#setting-text-muted-color-text').val('#9f1239');
                $('#setting-accent-color').val('#e11d48');
                $('#setting-accent-color-text').val('#e11d48');
                $('#setting-table-header-bg').val('#be123c');
                $('#setting-table-header-bg-text').val('#be123c');
                $('#setting-table-header-text').val('#ffffff');
                $('#setting-table-header-text-text').val('#ffffff');
                $('#setting-table-border-color').val('#fda4af');
                $('#setting-table-border-color-text').val('#fda4af');
                $('#setting-table-row-alt-bg').val('#fff1f2');
                $('#setting-table-row-alt-bg-text').val('#fff1f2');
                $('#setting-header-font-family').val('Bebas Neue');
                showToast('تم ورزشی قرمز (پرسپولیس) اعمال شد. 🔴', 'success');
            } else if (presetKey === 'glass') {
                $('#setting-card-bg-color').val('#ffffff');
                $('#setting-card-bg-color-text').val('#ffffff');
                $('#setting-card-bg-opacity').val(80);
                $('#card-opacity-label').text('80%');
                $('#setting-card-border-radius').val(24);
                $('#setting-card-border-color').val('#ffffff');
                $('#setting-card-border-color-text').val('#ffffff');
                $('#setting-text-primary-color').val('#0f172a');
                $('#setting-text-primary-color-text').val('#0f172a');
                $('#setting-text-muted-color').val('#64748b');
                $('#setting-text-muted-color-text').val('#64748b');
                $('#setting-accent-color').val('#4f46e5');
                $('#setting-accent-color-text').val('#4f46e5');
                $('#setting-table-header-bg').val('#f1f5f9');
                $('#setting-table-header-bg-text').val('#f1f5f9');
                $('#setting-table-header-text').val('#0f172a');
                $('#setting-table-header-text-text').val('#0f172a');
                $('#setting-table-border-color').val('#e2e8f0');
                $('#setting-table-border-color-text').val('#e2e8f0');
                $('#setting-table-row-alt-bg').val('#f8fafc');
                $('#setting-table-row-alt-bg-text').val('#f8fafc');
                showToast('پریست شیشه‌ای مدرن اعمال شد. 💎', 'success');
            } else if (presetKey === 'transparent') {
                // Transparent overlay on background image
                $('#setting-card-bg-color').val('#000000');
                $('#setting-card-bg-color-text').val('#000000');
                $('#setting-card-bg-opacity').val(30);
                $('#card-opacity-label').text('30%');
                $('#setting-card-border-radius').val(16);
                $('#setting-card-border-color').val('#ffffff');
                $('#setting-card-border-color-text').val('#ffffff');
                $('#setting-text-primary-color').val('#ffffff');
                $('#setting-text-primary-color-text').val('#ffffff');
                $('#setting-text-muted-color').val('#e2e8f0');
                $('#setting-text-muted-color-text').val('#e2e8f0');
                $('#setting-accent-color').val('#fbbf24');
                $('#setting-accent-color-text').val('#fbbf24');
                $('#setting-table-header-bg').val('#000000');
                $('#setting-table-header-bg-text').val('#000000');
                $('#setting-table-header-text').val('#ffffff');
                $('#setting-table-header-text-text').val('#ffffff');
                $('#setting-table-border-color').val('#475569');
                $('#setting-table-border-color-text').val('#475569');
                $('#setting-table-row-alt-bg').val('#000000');
                $('#setting-table-row-alt-bg-text').val('#000000');
                showToast('پریست ترنسپرنت روی پس‌زمینه اعمال شد. ✨', 'success');
            } else if (presetKey === 'dark_luxury') {
                $('#setting-card-bg-color').val('#18181b');
                $('#setting-card-bg-color-text').val('#18181b');
                $('#setting-card-bg-opacity').val(100);
                $('#card-opacity-label').text('100%');
                $('#setting-card-border-radius').val(16);
                $('#setting-card-border-color').val('#27272a');
                $('#setting-card-border-color-text').val('#27272a');
                $('#setting-text-primary-color').val('#fafafa');
                $('#setting-text-primary-color-text').val('#fafafa');
                $('#setting-text-muted-color').val('#a1a1aa');
                $('#setting-text-muted-color-text').val('#a1a1aa');
                $('#setting-accent-color').val('#f59e0b');
                $('#setting-accent-color-text').val('#f59e0b');
                $('#setting-table-header-bg').val('#27272a');
                $('#setting-table-header-bg-text').val('#27272a');
                $('#setting-table-header-text').val('#f4f4f5');
                $('#setting-table-header-text-text').val('#f4f4f5');
                $('#setting-table-border-color').val('#3f3f46');
                $('#setting-table-border-color-text').val('#3f3f46');
                $('#setting-table-row-alt-bg').val('#202023');
                $('#setting-table-row-alt-bg-text').val('#202023');
                showToast('پریست تیره لوکس اعمال شد. 🖤', 'success');
            } else if (presetKey === 'navy') {
                $('#setting-card-bg-color').val('#0f172a');
                $('#setting-card-bg-color-text').val('#0f172a');
                $('#setting-card-bg-opacity').val(100);
                $('#card-opacity-label').text('100%');
                $('#setting-card-border-radius').val(16);
                $('#setting-card-border-color').val('#1e293b');
                $('#setting-card-border-color-text').val('#1e293b');
                $('#setting-text-primary-color').val('#f8fafc');
                $('#setting-text-primary-color-text').val('#f8fafc');
                $('#setting-text-muted-color').val('#94a3b8');
                $('#setting-text-muted-color-text').val('#94a3b8');
                $('#setting-accent-color').val('#38bdf8');
                $('#setting-accent-color-text').val('#38bdf8');
                $('#setting-table-header-bg').val('#1e293b');
                $('#setting-table-header-bg-text').val('#1e293b');
                $('#setting-table-header-text').val('#38bdf8');
                $('#setting-table-header-text-text').val('#38bdf8');
                $('#setting-table-border-color').val('#334155');
                $('#setting-table-border-color-text').val('#334155');
                $('#setting-table-row-alt-bg').val('#131d35');
                $('#setting-table-row-alt-bg-text').val('#131d35');
                showToast('پریست سرمه‌ای شرکتی اعمال شد. 👔', 'success');
            } else {
                // minimal
                $('#setting-card-bg-color').val('#ffffff');
                $('#setting-card-bg-color-text').val('#ffffff');
                $('#setting-card-bg-opacity').val(100);
                $('#card-opacity-label').text('100%');
                $('#setting-card-border-radius').val(8);
                $('#setting-card-border-color').val('#e2e8f0');
                $('#setting-card-border-color-text').val('#e2e8f0');
                $('#setting-text-primary-color').val('#0f172a');
                $('#setting-text-primary-color-text').val('#0f172a');
                $('#setting-text-muted-color').val('#64748b');
                $('#setting-text-muted-color-text').val('#64748b');
                $('#setting-accent-color').val('#2563eb');
                $('#setting-accent-color-text').val('#2563eb');
                $('#setting-table-header-bg').val('#f8fafc');
                $('#setting-table-header-bg-text').val('#f8fafc');
                $('#setting-table-header-text').val('#0f172a');
                $('#setting-table-header-text-text').val('#0f172a');
                $('#setting-table-border-color').val('#e2e8f0');
                $('#setting-table-border-color-text').val('#e2e8f0');
                $('#setting-table-row-alt-bg').val('#fafafa');
                $('#setting-table-row-alt-bg-text').val('#fafafa');
                showToast('پریست مینیمال روشن اعمال شد. 🤍', 'success');
            }

            applyBrandingChanges();
        }

        function saveBrandingSettings(e) {
            if (e) e.preventDefault();
            
            showToast('در حال ذخیره‌سازی تنظیمات برندینگ، تایپوگرافی و استایل...', 'info');

            var postData = {
                action: 'td_scs_save_branding_settings',
                project_id: <?php echo json_encode($project['id']); ?>,
                brand_title: $('#setting-brand-title').val().trim(),
                brand_subtitle: $('#setting-brand-subtitle').val().trim(),
                brand_tagline: $('#setting-brand-tagline').val().trim(),
                brand_website: $('#setting-brand-website').val().trim(),
                image_logo: $('#setting-image-logo').val().trim(),
                
                bg_image: $('#setting-bg-image').val().trim(),
                bg_pattern: scs_settings.bg_pattern || 'none',
                canvas_preset: scs_settings.canvas_preset || 'catalog_standard',
                canvas_width: parseInt($('#setting-canvas-width').val()) || 800,
                canvas_height: parseInt($('#setting-canvas-height').val()) || 1000,
                bg_fit: $('#setting-bg-fit').val(),
                bg_overlay_mode: $('#setting-bg-overlay-mode').val(),
                bg_overlay_opacity: parseInt($('#setting-bg-overlay-opacity').val()) || 0,
                
                positioning_mode: scs_settings.positioning_mode || 'flow',
                block_positions: JSON.stringify(scs_settings.block_positions || {}),
                layout_mode: scs_settings.layout_mode || 'standard_split',
                block_order: scs_settings.block_order || ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'],
                illus_max_height: parseInt($('#setting-illus-max-height').val()) || 160,
                illus_object_fit: $('#setting-illus-object-fit').val() || 'contain',

                // Font settings
                font_family: $('#setting-font-family').val() || 'Vazirmatn',
                header_font_family: $('#setting-header-font-family').val() || '',
                font_scale: parseInt($('#setting-font-scale').val()) || 100,
                font_weight: $('#setting-font-weight').val() || 'normal',
                font_digits: $('#setting-font-digits').val() || 'persian',
                custom_font_url: $('#setting-custom-font-url').val() || '',
                custom_font_name: $('#setting-custom-font-name').val() || '',

                content_padding_top: parseInt($('#setting-padding-top').val()) || 36,
                content_padding_bottom: parseInt($('#setting-padding-bottom').val()) || 28,
                content_padding_x: parseInt($('#setting-padding-x').val()) || 36,
                content_gap: parseInt($('#setting-content-gap').val()) || 24,
                show_header_brand: (scs_settings.show_header_brand !== undefined && scs_settings.show_header_brand !== null) ? scs_settings.show_header_brand : 1,
                show_meta_pills: (scs_settings.show_meta_pills !== undefined && scs_settings.show_meta_pills !== null) ? scs_settings.show_meta_pills : 1,
                show_table: (scs_settings.show_table !== undefined && scs_settings.show_table !== null) ? scs_settings.show_table : 1,
                show_illustration: (scs_settings.show_illustration !== undefined && scs_settings.show_illustration !== null) ? scs_settings.show_illustration : 1,
                show_specs: (scs_settings.show_specs !== undefined && scs_settings.show_specs !== null) ? scs_settings.show_specs : 1,
                show_notes: (scs_settings.show_notes !== undefined && scs_settings.show_notes !== null) ? scs_settings.show_notes : 1,
                show_footer_brand: (scs_settings.show_footer_brand !== undefined && scs_settings.show_footer_brand !== null) ? scs_settings.show_footer_brand : 1,
                
                style_mode: scs_settings.style_mode || 'default',
                custom_theme_preset: scs_settings.custom_theme_preset || 'custom',
                card_bg_color: $('#setting-card-bg-color').val(),
                card_bg_opacity: parseInt($('#setting-card-bg-opacity').val()) ?? 100,
                card_border_color: $('#setting-card-border-color').val(),
                card_border_width: 1,
                card_border_radius: parseInt($('#setting-card-border-radius').val()) || 16,
                card_shadow: 'sm',
                text_primary_color: $('#setting-text-primary-color').val(),
                text_muted_color: $('#setting-text-muted-color').val(),
                accent_color: $('#setting-accent-color').val(),
                table_header_bg: $('#setting-table-header-bg').val(),
                table_header_text: $('#setting-table-header-text').val(),
                table_border_color: $('#setting-table-border-color').val(),
                table_row_alt_bg: $('#setting-table-row-alt-bg').val(),
                table_cell_text: $('#setting-table-cell-text').val() || $('#setting-text-primary-color').val() || '#1e293b',
                table_border_width: parseInt($('#setting-table-border-width').val()) ?? 1,
                table_bg_transparent: $('#setting-table-bg-transparent').is(':checked') ? 1 : 0,
                table_bg_color: $('#setting-table-bg-color').val() || '#ffffff',
                table_bg_opacity: parseInt($('#setting-table-bg-opacity').val()) ?? 100,
                table_font_size: parseInt($('#setting-table-font-size').val()) || 11,
                table_cell_padding: parseInt($('#setting-table-cell-padding').val()) || 8,
                table_pure_data_overlay: $('#setting-table-pure-data-overlay').is(':checked') ? 1 : 0,
                table_show_header: $('#setting-table-show-header').is(':checked') ? 1 : 0,
                table_row_gap: parseInt($('#setting-table-row-gap').val()) || 0,
                table_split_rows: $('#setting-table-split-rows').is(':checked') ? 1 : 0,
                table_split_cells: $('#setting-table-split-cells').is(':checked') ? 1 : 0,
                table_text_align: $('#setting-table-text-align').val() || 'center',
                table_auto_scale: $('#setting-table-auto-scale').is(':checked') ? 1 : 0,
                table_scale_mode: $('#setting-table-scale-mode').val() || 'smart_fit',
                table_target_height: parseInt($('#setting-table-target-height').val()) || 360,
                table_scale: parseInt($('#setting-table-scale').val()) || (scs_settings.table_scale || 100),
                table_lock_ratio: $('#setting-table-lock-ratio').is(':checked') ? 1 : 0
            };

            $.post('', postData, function(res) {
                try {
                    if (typeof res === 'string') res = JSON.parse(res);
                } catch(e){}
                if (res.success) {
                    showToast('تمامی تنظیمات برندینگ، تایپوگرافی، بک‌گراند و چیدمان ذخیره شد. ✨', 'success');
                    if (res.settings) {
                        scs_settings = res.settings;
                    }
                    renderDragAndDropBuilder();
                    applyBrandingChanges();
                    updateLivePreview();
                } else {
                    showToast('خطا در ذخیره‌سازی تنظیمات.', 'error');
                }
            });
        }

        function resetBrandingToDefault() {
            if (!confirm('آیا از بازنشانی کلیه تنظیمات برند، استایل و پس‌زمینه به حالت پیش‌فرض مطمئن هستید؟')) {
                return;
            }

            $('#setting-brand-title').val('تَـنْ دُرُسْـتْ');
            $('#setting-brand-subtitle').val('پوشاک الیاف طبیعی از ۱۳۸۲');
            $('#setting-brand-tagline').val('تولید ملی ایران زمین - اصالت و ماندگاری الیاف گویای هویت ماست');
            $('#setting-brand-website').val('www.tanedorost.com');
            $('#setting-image-logo').val('');
            $('#setting-bg-image').val('');
            
            setBgPattern('none');
            setCanvasPreset('catalog_standard', 800, 1000);
            setLayoutMode('standard_split');
            
            $('#setting-padding-top').val(36);
            $('#setting-padding-bottom').val(28);
            $('#setting-padding-x').val(36);
            $('#setting-content-gap').val(24);

            $('#setting-illus-max-height').val(160);
            $('#setting-illus-object-fit').val('contain');

            $('#setting-show-header-brand').prop('checked', true);
            $('#setting-show-meta-pills').prop('checked', true);
            $('#setting-show-table').prop('checked', true);
            $('#setting-show-illustration').prop('checked', true);
            $('#setting-show-specs').prop('checked', true);
            $('#setting-show-footer-brand').prop('checked', true);

            scs_settings.block_order = ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];
            renderDragAndDropBuilder();

            setStyleMode('default');
            saveBrandingSettings();
            showToast('تنظیمات به حالت پایه نرم‌افزار بازنشانی شد.', 'info');
        }

        function renderBrandOnPreview() {
            applyBrandingChanges();
        }

        // Custom Notification / Toast module
        function showToast(m, type = 'info') {
            const el = document.createElement('div');
            el.className = `fixed bottom-6 left-6 px-6 py-4 rounded-2xl text-xs font-black text-white shadow-xl flex items-center gap-3 z-50 animate-bounce duration-300`;
            if (type === 'success') el.classList.add('bg-emerald-600');
            else if (type === 'error') el.classList.add('bg-rose-600');
            else if (type === 'warning') el.classList.add('bg-amber-600');
            else el.classList.add('bg-indigo-600');
            
            el.innerHTML = `<span>⚡</span> <span>${m}</span>`;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 4000);
        }

        // Switching Tabs
        function switchTab(t) {
            if (t === 'linker' || t === 'multi_assign') {
                t = 'multi_assign';
            }
            $('.tab-pane').addClass('hidden');
            $(`#tabcontent-${t}`).removeClass('hidden');

            $('.tab-btn').removeClass('bg-indigo-600 text-white shadow-md shadow-indigo-600/10').addClass('text-slate-500 hover:bg-slate-50');
            $(`#btn-tab-${t}`).addClass('bg-indigo-600 text-white shadow-md shadow-indigo-600/10').removeClass('text-slate-500 hover:bg-slate-50');

            if (t === 'multi_assign') {
                if (typeof initMultiAssignTab === 'function') {
                    initMultiAssignTab();
                }
            } else if (t === 'missing_charts') {
                if (typeof renderMissingChartsTab === 'function') {
                    renderMissingChartsTab();
                }
            } else if (t === 'pending_upload') {
                if (typeof renderPendingUploadTab === 'function') {
                    renderPendingUploadTab();
                }
            } else if (t === 'branding') {
                setTimeout(function() {
                    updateBrandingPreviewScale();
                    applyBrandingChanges();
                }, 50);
            }
        }

        // Initialize lists Loader
        $(document).ready(function() {
            $(window).on('resize', function() {
                updateBrandingPreviewScale();
            });

            if (scs_settings.positioning_mode === 'freeform') {
                setPositioningMode('freeform');
            } else {
                setPositioningMode('flow');
            }
            renderDragAndDropBuilder();
            applyBrandingChanges();
            if (scs_settings.table_split_cells) {
                renderMatrixStructurePanel();
            }
            populatePresetsUI();
            populateApprovedProductsDropdown();
            loadChartsList();
            loadDescPresets();
            updateSizeStudioBadgeCounters();

            // Check URL query parameters (e.g. ?product_id=xxx or ?chart_id=xxx or ?tab=xxx)
            var urlParams = new URLSearchParams(window.location.search);
            var urlTab = urlParams.get('tab');
            var urlProductId = urlParams.get('product_id') || urlParams.get('product');
            var urlChartId = urlParams.get('chart_id') || urlParams.get('chart');

            if (urlTab) {
                switchTab(urlTab);
            }

            var urlAssignChart = urlParams.get('assign_chart');
            if (urlAssignChart) {
                openMultiAssignWithChart(urlAssignChart);
            }

            if (urlChartId) {
                loadChart(urlChartId);
            } else if (urlProductId && Array.isArray(app_products) && app_products.some(p => String(p.id) === String(urlProductId))) {
                selectProductForLivePreview(urlProductId);
            } else {
                // Ensure first preset & sample data are loaded so preview is active immediately
                var curPreset = $('#chart-preset-select').val();
                if ((!curPreset || curPreset === '') && Array.isArray(app_presets) && app_presets.length > 0) {
                    var firstPreset = app_presets[0];
                    $('#chart-preset-select').val(firstPreset.id);
                    applyPresetTemplate(firstPreset.id);
                    if ($('#grid-body tr').length === 0) {
                        loadSampleChartData();
                    }
                } else {
                    updateLivePreview();
                }
            }

            // Also asynchronously refresh presets from backend
            loadPresets();
        });

        // Load category Presets
        function loadPresets() {
            $.post('', { action: 'td_scs_presets_list' }, function(res) {
                if (res.success && Array.isArray(res.data.items) && res.data.items.length > 0) {
                    app_presets = res.data.items;
                    populatePresetsUI();
                    updateLivePreview();
                }
            });
        }

        // Build list of category presets in view
        function populatePresetsUI() {
            var filter = $('#filter-category');
            var select = $('#chart-preset-select');
            var list = $('#presets-list-container');
            
            filter.empty().append('<option value="">همه دسته‌بندی‌ها</option>');
            select.empty().append('<option value="">-- انتخاب کنید --</option>');
            list.empty();

            var addedCats = [];
            app_presets.forEach(p => {
                if (!addedCats.includes(p.category)) {
                    addedCats.push(p.category);
                    filter.append(`<option value="${p.category}">${p.category}</option>`);
                }
                select.append(`<option value="${p.id}">${p.name} (${p.category})</option>`);
                
                // Sidebar item
                list.append(`
                    <div onclick="loadCategoryPreset('${p.id}')" class="p-4 bg-slate-50 hover:bg-slate-100 rounded-2xl border border-slate-150 cursor-pointer transition-all flex justify-between items-center group">
                        <div>
                            <span class="text-xs font-black text-slate-800">${p.name}</span>
                            <span class="text-[10px] text-slate-400 block mt-1">دسته: ${p.category}</span>
                        </div>
                        <span class="text-indigo-650 opacity-0 group-hover:opacity-100 transition-all font-black text-xs">←</span>
                    </div>
                `);
            });
        }

        // Upload custom image assets for presets
        function uploadAssetFile(type) {
            var fd = new FormData();
            var file = $(`#p-${type}-file`)[0].files[0];
            if (!file) return;

            fd.append('file', file);
            fd.append('action', `td_scs_${type}_upload`);

            showToast('در حال بارگذاری فایل...', 'info');
            $.ajax({
                url: '',
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(res) {
                    if (res.success) {
                        $(`#p-${type}-url`).val(res.data.url);
                        showToast('بارگذاری دارایی با موفقیت انجام شد.', 'success');
                    } else {
                        showToast(res.message || 'بارگذاری با شکست مواجه شد.', 'error');
                    }
                }
            });
        }

        // Create new category preset
        function initNewPreset() {
            $('#p-id').val('');
            $('#p-name').val('');
            $('#p-category').val('');
            $('#p-columns').val('');
            $('#p-illus-url').val('');
            $('#p-icon-url').val('');
            $('#p-show-model').prop('checked', false);
            $('#p-show-waist').prop('checked', false);
            $('#btn-delete-p').addClass('hidden');
            showToast('آماده ثبت پریست جدید', 'info');
        }

        // Load specific category preset details
        function loadCategoryPreset(id) {
            var p = app_presets.find(x => x.id === id);
            if (!p) return;

            $('#p-id').val(p.id);
            $('#p-name').val(p.name);
            $('#p-category').val(p.category);
            $('#p-columns').val(p.columns.join("\n"));
            $('#p-illus-url').val(p.illus_url || '');
            $('#p-icon-url').val(p.icon_url || '');
            $('#p-show-model').prop('checked', p.show_model == 1);
            $('#p-show-waist').prop('checked', p.show_waist == 1);
            $('#btn-delete-p').removeClass('hidden');
        }

        // Save Category Presets
        function saveCategoryPreset() {
            var colText = $('#p-columns').val();
            var cols = colText.split('\n').map(x => x.trim()).filter(x => x.length > 0);

            var postData = {
                action: 'td_scs_preset_save',
                id: $('#p-id').val(),
                name: $('#p-name').val(),
                category: $('#p-category').val(),
                columns: JSON.stringify(cols),
                show_model: $('#p-show-model').is(':checked') ? 1 : 0,
                show_waist: $('#p-show-waist').is(':checked') ? 1 : 0,
                illus_url: $('#p-illus-url').val(),
                icon_url: $('#p-icon-url').val()
            };

            $.post('', postData, function(res) {
                if (res.success) {
                    showToast('پریست استاندارد دسته با موفقیت به روزرسانی شد.', 'success');
                    loadPresets();
                    initNewPreset();
                } else {
                    showToast(res.message || 'خطا در ثبت اطلاعات.', 'error');
                }
            });
        }

        // Delete Category Preset
        function deleteCategoryPreset() {
            if (!confirm('آیا مایل به حذف این پریست دسته و رکوردهای ستون مرتبط با آن هستید؟')) return;
            $.post('', { action: 'td_scs_preset_delete', id: $('#p-id').val() }, function(res) {
                if (res.success) {
                    showToast('پریست با موفقیت حذف شد.', 'success');
                    loadPresets();
                    initNewPreset();
                }
            });
        }

        // -------------------------
        // Dynamic Description details
        // -------------------------
        function loadDescPresets() {
            $.post('', { action: 'td_scs_desc_presets_list' }, function(res) {
                if (res.success) {
                    app_desc_presets = res.data.items;
                    populateDescPresetsUI();
                }
            });
        }

        function populateDescPresetsUI() {
            var select = $('#chart-desc-preset-select');
            var list = $('#desc-presets-list-container');
            
            select.empty().append('<option value="">-- بدون پریست توضیحات --</option>');
            list.empty();

            app_desc_presets.forEach(p => {
                select.append(`<option value="${p.id}">${p.title}</option>`);
                
                // Sidebar item
                list.append(`
                    <div onclick="loadDescPreset('${p.id}')" class="p-4 bg-slate-50 hover:bg-slate-100 rounded-2xl border border-slate-150 cursor-pointer transition-all flex justify-between items-center group">
                        <div>
                            <span class="text-xs font-black text-slate-800">${p.title}</span>
                            <span class="text-[10px] text-slate-400 block mt-1">تعداد پارامترها: ${p.fields.length} آیتم</span>
                        </div>
                        <span class="text-indigo-650 opacity-0 group-hover:opacity-100 transition-all font-black text-xs">←</span>
                    </div>
                `);
            });
        }

        function initNewDescPreset() {
            $('#dp-id').val('');
            $('#dp-title').val('');
            $('#dp-fields-container').empty();
            $('#btn-delete-dp').addClass('hidden');
        }

        function addDescPresetField(label = '') {
            var k = 'f_' + Math.floor(Math.random() * 1000000);
            var rowHtml = `
                <div class="flex items-center gap-3 bg-slate-50 p-3 rounded-xl border border-slate-150 dp-field-row" data-key="${k}">
                    <span class="text-lg opacity-40">⚙️</span>
                    <input type="text" value="${label}" placeholder="عنوان فیلد (مثلا: الیاف محصول، ضخامت، نوع دوخت...)" class="flex-1 text-xs font-bold border-0 bg-transparent focus:ring-0 p-1 font-black">
                    <button onclick="$(this).parent().remove()" class="text-rose-600 hover:text-rose-500 font-bold text-xs p-1">حذف فیلد</button>
                </div>
            `;
            $('#dp-fields-container').append(rowHtml);
        }

        function loadDescPreset(id) {
            var p = app_desc_presets.find(x => x.id === id);
            if (!p) return;

            $('#dp-id').val(p.id);
            $('#dp-title').val(p.title);
            
            var container = $('#dp-fields-container').empty();
            p.fields.forEach(f => {
                addDescPresetField(f.label);
            });
            $('#btn-delete-dp').removeClass('hidden');
        }

        function saveDescPreset() {
            var fields = [];
            $('.dp-field-row').each(function() {
                var label = $(this).find('input').val().trim();
                var key = $(this).data('key');
                if (label) {
                    fields.push({ key: key, label: label });
                }
            });

            var postData = {
                action: 'td_scs_desc_preset_save',
                id: $('#dp-id').val(),
                title: $('#dp-title').val().trim(),
                fields: JSON.stringify(fields)
            };

            $.post('', postData, function(res) {
                if (res.success) {
                    showToast('پریست فیلدهای مشخصات فرعی ذخیره شد.', 'success');
                    loadDescPresets();
                    initNewDescPreset();
                } else {
                    showToast(res.message || 'خطا در ثبت فرعی.', 'error');
                }
            });
        }

        function deleteDescPreset() {
             if (!confirm('آیا از حذف این استاندارد جزئیات برای همیشه اطمینان دارید؟')) return;
             $.post('', { action: 'td_scs_desc_preset_delete', id: $('#dp-id').val() }, function(res) {
                 if (res.success) {
                     showToast('پریست فیلدهای فرعی حذف گردید.', 'success');
                     loadDescPresets();
                     initNewDescPreset();
                 }
             });
        }

        // -------------------------
        // Size Charts logic
        // -------------------------
        function loadChartsList() {
            var category = $('#filter-category').val();
            $.post('', { action: 'td_scs_charts_list', category: category }, function(res) {
                if (res.success) {
                    app_charts = res.data.items;
                    populateChartsListUI();
                    populateLinkerUI();
                }
            });
        }

        function populateChartsListUI() {
            var container = $('#charts-list-container').empty();
            if (app_charts.length === 0) {
                container.append('<p class="text-xs text-slate-400 text-center py-8">هیچ جدول سایزی طراحی نشده است.</p>');
                return;
            }

            app_charts.forEach(c => {
                var title = c.title || c.product_desc || 'جدول بی نام';
                container.append(`
                    <div onclick="loadChart('${c.id}')" class="p-3.5 bg-slate-50 hover:bg-slate-100/80 rounded-2xl border border-slate-150 cursor-pointer transition-all flex justify-between items-center group">
                        <div class="min-w-0 flex-1 ml-2">
                            <span class="text-xs font-black text-slate-800 truncate block">${title}</span>
                            <span class="text-[9px] text-slate-400 mt-1 block">دسته‌بندی: ${c.category} - ${c.columns.length} ستون</span>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <button onclick="event.stopPropagation(); openMultiAssignWithChart('${c.id}')" class="p-1 px-2 text-[10px] bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 rounded-lg font-black transition-all flex items-center justify-center gap-1 shadow-xs" title="اساین گروهی این جدول به چندین محصول">
                                🎯 <span class="hidden sm:inline">اساین گروهی</span>
                            </button>
                            <button onclick="event.stopPropagation(); deleteChart('${c.id}')" class="p-1 px-2 text-[10px] bg-white text-rose-600 border border-slate-200 hover:border-rose-300 hover:bg-rose-50 rounded-lg font-black transition-all md:opacity-0 group-hover:opacity-100 flex items-center justify-center gap-1" title="حذف این جدول">
                                🗑️ <span class="hidden sm:inline">حذف</span>
                            </button>
                            <span class="text-[9px] text-indigo-550 border border-indigo-100 bg-indigo-50/50 px-2 py-1 rounded-md font-extrabold">انتخاب</span>
                        </div>
                    </div>
                `);
            });
        }

        function deleteChart(id) {
            if (!id) return;
            if (!confirm('آیا از حذف این جدول سایزبندی اطمینان دارید؟ تمامی ارتباطات این جدول به کالاها لغو خواهد شد.')) return;
            
            showToast('در حال حذف جدول سایز...', 'info');
            $.post('', {
                action: 'td_scs_chart_delete',
                id: id
            }, function(res) {
                if (res.success) {
                    showToast('جدول سایز با موفقیت حذف گردید.', 'success');
                    if ($('#editor-chart-id').val() === id) {
                        initNewChart();
                    }
                    loadChartsList();
                } else {
                    showToast(res.message || 'حذف جدول سایز با خطا روبرو شد.', 'error');
                }
            });
        }

        function deleteCurrentChart() {
            var id = $('#editor-chart-id').val();
            if (id) {
                deleteChart(id);
            }
        }

        function initNewChart() {
            $('#editor-chart-id').val('');
            $('#editor-title-text').text('در حال ایجاد جدول جدید');
            $('#chart-title').val('');
            $('#chart-product-desc').val('');
            $('#chart-product-notes').val('');
            $('#chart-preset-select').val('').change();
            $('#chart-model').val('').prop('disabled', true);
            $('#chart-waist-type').val('').prop('disabled', true);
            $('#chart-desc-preset-select').val('').change();
            $('#grid-header-row').html('<th class="p-3 text-center">ابتدا یک پریست ستون انتخاب فرمایید</th>');
            $('#grid-body').empty();
            $('#chart-linked-product-search').val('');
            $('#chart-linked-product-select').val('');
            $('#btn-delete-chart').addClass('hidden');
            
            selected_chart = null;
            populateApprovedProductsDropdown();
            updateLivePreview();
        }

        // Process apply selected category preset and setup grid columns (retains & maps any current table row values)
        function applyPresetTemplate(presetId) {
            // Read any existing columns and row cell inputs from the table before changing template
            var oldColumns = [];
            $('#grid-header-row th').each(function() {
                var text = $(this).text().trim();
                if (text && text !== 'اکشن' && !text.includes('پریست') && !text.includes('فاقد ستون')) {
                    oldColumns.push(text);
                }
            });

            var oldRows = [];
            $('#grid-body tr').each(function() {
                var rowData = {};
                var inputs = $(this).find('.select-input-cell');
                if (inputs.length > 0) {
                    inputs.each(function(idx) {
                        rowData['c' + idx] = $(this).val().trim();
                    });
                    oldRows.push(rowData);
                }
            });

            if (!presetId) {
                $('#chart-model').val('').prop('disabled', true).parent().addClass('opacity-50');
                $('#chart-waist-type').val('').prop('disabled', true).parent().addClass('opacity-50');
                $('#grid-header-row').html('<th class="p-3 text-center text-slate-400">ابتدا یک پریست ستون انتخاب فرمایید</th>');
                $('#grid-body').empty();
                $('#preview-cat').text('—');
                updateLivePreview();
                return;
            }

            var preset = app_presets.find(x => String(x.id) === String(presetId) || x.id == presetId);
            if (!preset && presetId) {
                preset = app_presets.find(x => x.name === presetId || (x.category && x.category === presetId));
            }
            if (!preset) {
                if (window.selected_chart && selected_chart.columns && selected_chart.columns.length > 0) {
                    preset = {
                        id: presetId,
                        name: 'سفارشی',
                        category: selected_chart.category || 'عمومی',
                        columns: selected_chart.columns,
                        show_model: selected_chart.model ? 1 : 0,
                        show_waist: selected_chart.waist_type ? 1 : 0
                    };
                } else if (Array.isArray(app_presets) && app_presets.length > 0) {
                    preset = app_presets[0];
                }
            }
            if (!preset || !preset.columns || preset.columns.length === 0) {
                $('#chart-model').val('').prop('disabled', true).parent().addClass('opacity-50');
                $('#chart-waist-type').val('').prop('disabled', true).parent().addClass('opacity-50');
                updateLivePreview();
                return;
            }

            // Set inputs
            if (preset.show_model == 1) {
                $('#chart-model').prop('disabled', false).parent().removeClass('opacity-50');
            } else {
                $('#chart-model').val('').prop('disabled', true).parent().addClass('opacity-50');
            }

            if (preset.show_waist == 1) {
                $('#chart-waist-type').prop('disabled', false).parent().removeClass('opacity-50');
            } else {
                $('#chart-waist-type').val('').prop('disabled', true).parent().addClass('opacity-50');
            }

            // Build table edit layout
            var headerRow = $('#grid-header-row').empty();
            preset.columns.forEach(col => {
                headerRow.append(`<th class="p-3 bg-slate-100/80 border border-slate-200 text-center font-black">${col}</th>`);
            });
            headerRow.append('<th class="p-3 bg-rose-50 text-rose-600 border border-slate-200 text-center w-16 font-black">اکشن</th>');

            // Rebuild grid body mapping old cells to new matched columns
            $('#grid-body').empty();

            if (oldRows.length > 0 && oldColumns.length > 0) {
                // Safe and accurate column mapping using normalized semantic keys
                function normalizeColName(name) {
                    if (!name) return '';
                    var n = String(name).replace(/[\s\/\-\_\u200c\(\)\.\،]/g, '').toLowerCase();
                    if (n === 'سایز' || n === 'سایزبندی' || n === 'سایزمحصول' || n === 'size') return 'col_size';
                    if (n === 'قدمحصول' || n === 'قدلباس' || n === 'قد' || n === 'height' || n === 'length') return 'col_length';
                    if (n === 'قدآستین' || n === 'آستین' || n === 'sleeve') return 'col_sleeve';
                    if (n === 'عرضشانه' || n === 'سرشانه' || n === 'شانه' || n === 'shoulder') return 'col_shoulder';
                    if (n === 'عرضلباس' || n === 'عرض' || n === 'عرضسینه' || n === 'width') return 'col_width';
                    if (n === 'دورسینه' || n === 'سینه' || n === '12دورسینه' || n === 'نصفدورسینه' || n === 'chest' || n === 'bust') return 'col_chest';
                    if (n === 'دورکمر' || n === 'کمر' || n === '12دورکمر' || n === 'نصفدورکمر' || n === 'waist') return 'col_waist';
                    if (n === 'دورباسن' || n === 'باسن' || n === '12دورباسن' || n === 'نصفدورباسن' || n === 'hip') return 'col_hip';
                    if (n === 'دورران' || n === 'ران' || n === '12دورران' || n === 'thigh') return 'col_thigh';
                    if (n === 'دمپا' || n === 'عرضدمپا' || n === 'leg') return 'col_hem';
                    if (n === 'مدل' || n === 'مدلمحصول' || n === 'model') return 'col_model';
                    if (n === 'نوعکمر' || n === 'کمرمدل') return 'col_waist_type';
                    return n;
                }

                var mapNewToOld = [];
                var usedOldIndices = {};
                preset.columns.forEach(newCol => {
                    var foundOldIdx = -1;
                    var normNew = normalizeColName(newCol);
                    // 1- Exact match
                    for (var i = 0; i < oldColumns.length; i++) {
                        if (!usedOldIndices[i] && oldColumns[i].trim() === newCol.trim()) {
                            foundOldIdx = i;
                            break;
                        }
                    }
                    // 2- Normalized semantic match
                    if (foundOldIdx === -1) {
                        for (var i = 0; i < oldColumns.length; i++) {
                            if (!usedOldIndices[i] && normalizeColName(oldColumns[i]) === normNew) {
                                foundOldIdx = i;
                                break;
                            }
                        }
                    }
                    if (foundOldIdx !== -1) {
                        usedOldIndices[foundOldIdx] = true;
                    }
                    mapNewToOld.push(foundOldIdx);
                });

                // Generate tr rows with correct input cell placements
                oldRows.forEach(row => {
                    var rId = 'r_' + Math.floor(Math.random() * 1000000);
                    var tr = $(`<tr id="${rId}" class="border-b border-slate-150"></tr>`);
                    
                    preset.columns.forEach((col, newIdx) => {
                        var oldIdx = mapNewToOld[newIdx];
                        var cellVal = '';
                        if (oldIdx !== -1 && row['c' + oldIdx] !== undefined) {
                            cellVal = row['c' + oldIdx];
                        }
                        tr.append(`<td class="p-2 border border-slate-200"><input type="text" value="${cellVal}" oninput="updateLivePreview()" class="w-full text-center p-2 border border-slate-100 rounded bg-slate-50/50 hover:bg-white text-xs font-bold focus:bg-white focus:ring-1 focus:ring-indigo-300 outline-none select-input-cell"></td>`);
                    });
                    tr.append(`<td class="p-2 border border-slate-200 text-center"><button type="button" onclick="$(this).closest('tr').remove(); updateLivePreview();" class="text-rose-600 font-bold hover:text-rose-500">حذف</button></td>`);
                    
                    $('#grid-body').append(tr);
                });
            } else {
                // If table was empty or not initialized, create standard empty input row structure
                addChartRow(preset.columns.length);
            }

            // Set category input based on preset
            $('#preview-cat').text(preset.category || 'عمومی');

            updateLivePreview();
        }

        function addChartRow(colsCount = 0) {
            if (colsCount === 0) {
                var validThs = 0;
                $('#grid-header-row th').each(function() {
                    var t = $(this).text().trim();
                    if (t && t !== 'اکشن' && !t.includes('پریست') && !t.includes('فاقد ستون') && !t.includes('ابتدا یک')) {
                        validThs++;
                    }
                });
                if (validThs > 0) colsCount = validThs;
            }
            if (colsCount === 0 && window.selected_chart && Array.isArray(selected_chart.columns) && selected_chart.columns.length > 0) {
                colsCount = selected_chart.columns.length;
            }
            if (colsCount === 0) {
                var currentPresetId = $('#chart-preset-select').val();
                if (currentPresetId) {
                    var pr = app_presets.find(x => String(x.id) === String(currentPresetId) || x.id == currentPresetId);
                    if (pr && pr.columns) colsCount = pr.columns.length;
                }
            }
            if (colsCount === 0) {
                showToast('لطفاً ابتدا یک پریست ستون انتخاب فرمایید.', 'warning');
                return;
            }

            var rId = 'r_' + Math.floor(Math.random() * 1000000);
            var tr = $(`<tr id="${rId}" class="border-b border-slate-150"></tr>`);
            
            for (var i = 0; i < colsCount; i++) {
                tr.append(`<td class="p-2 border border-slate-200"><input type="text" oninput="updateLivePreview()" placeholder="—" class="w-full text-center p-2 border border-slate-100 rounded bg-slate-50/50 hover:bg-white text-xs font-bold focus:bg-white focus:ring-1 focus:ring-indigo-300 outline-none select-input-cell"></td>`);
            }
            tr.append(`<td class="p-2 border border-slate-200 text-center"><button type="button" onclick="$(this).closest('tr').remove(); updateLivePreview();" class="text-rose-600 font-bold hover:text-rose-500">حذف</button></td>`);
            
            $('#grid-body').append(tr);
            updateLivePreview();
        }

        // Specs builder under specs section
        function buildDescriptionFields(presetId, existingValues = {}) {
            var container = $('#description-fields-container').empty();
            if (!presetId) {
                container.append('<p class="text-[10px] text-slate-400 col-span-full">پریست فیلد فرعی فعالی انتخاب نگردیده.</p>');
                updateLivePreview();
                return;
            }

            var pr = app_desc_presets.find(x => x.id === presetId);
            if (!pr) return;

            pr.fields.forEach(f => {
                var v = existingValues[f.key] || '';
                container.append(`
                    <div class="space-y-1.5 desc-custom-field" data-key="${f.key}" data-label="${f.label}">
                        <label class="text-[10px] font-black text-slate-500">${f.label}</label>
                        <input type="text" value="${v}" oninput="updateLivePreview()" placeholder="مثلا: پنبه طبیعی" class="w-full text-xs font-bold border border-slate-200 p-2.5 rounded-xl bg-white outline-none">
                    </div>
                `);
            });

            updateLivePreview();
        }

        // Load size chart record
        function loadChart(id, overrideProductId) {
            initNewChart();
            showToast('در حال دریافت داده‌های جدول سایز...', 'info');

            $.post('', { action: 'td_scs_chart_load', id: id }, function(res) {
                if (res.success) {
                    var d = res.data.data;
                    selected_chart = d;

                    $('#editor-chart-id').val(d.id);
                    $('#editor-title-text').text(`ویرایش: ${d.title || d.product_desc}`);
                    $('#chart-title').val(d.title || '');
                    $('#chart-product-desc').val(d.product_desc || '');
                    $('#chart-product-notes').val(d.product_notes || '');
                    
                    var presetId = d.preset_id || ('custom_' + d.id);
                    var pr = app_presets.find(x => String(x.id) === String(presetId) || x.id == presetId);
                    if (!pr) {
                        pr = {
                            id: presetId,
                            name: d.title || d.product_desc || 'سفارشی',
                            category: d.category || 'عمومی',
                            columns: (d.columns && d.columns.length > 0) ? d.columns : [],
                            show_model: d.model ? 1 : 0,
                            show_waist: d.waist_type ? 1 : 0,
                            illus_url: d.illus_url || '',
                            icon_url: ''
                        };
                        app_presets.push(pr);
                        if ($('#chart-preset-select option[value="' + presetId + '"]').length === 0) {
                            $('#chart-preset-select').append(`<option value="${presetId}">${d.category ? (d.category + ' (قالب ذخیره‌شده)') : 'قالب اختصاصی'}</option>`);
                        }
                    } else if (d.columns && d.columns.length > 0) {
                        pr.columns = d.columns;
                    }
                    $('#chart-preset-select').val(presetId);

                    // Rebuild header row with the chart's exact columns
                    var chartCols = (d.columns && d.columns.length > 0) ? d.columns : (pr ? pr.columns : []);
                    var headerRow = $('#grid-header-row').empty();
                    chartCols.forEach(function(col) {
                        headerRow.append(`<th class="p-3 bg-slate-100/80 border border-slate-200 text-center font-black">${col}</th>`);
                    });
                    headerRow.append('<th class="p-3 bg-rose-50 text-rose-600 border border-slate-200 text-center w-16 font-black">اکشن</th>');

                    // Rebuild body rows with the chart's exact rows
                    var gridBody = $('#grid-body').empty();
                    if (Array.isArray(d.rows) && d.rows.length > 0) {
                        d.rows.forEach(function(row) {
                            var rId = 'r_' + Math.floor(Math.random() * 1000000);
                            var tr = $(`<tr id="${rId}" class="border-b border-slate-150"></tr>`);
                            chartCols.forEach(function(col, idx) {
                                var cellVal = row['c' + idx] !== undefined ? row['c' + idx] : (row[col] !== undefined ? row[col] : (Array.isArray(row) ? row[idx] : (row.values && row.values[col] !== undefined ? row.values[col] : (idx === 0 && row.size !== undefined ? row.size : ''))));
                                tr.append(`<td class="p-2 border border-slate-200"><input type="text" value="${cellVal}" oninput="updateLivePreview()" class="w-full text-center p-2 border border-slate-100 rounded bg-slate-50/50 hover:bg-white text-xs font-bold focus:bg-white outline-none select-input-cell"></td>`);
                            });
                            tr.append(`<td class="p-2 border border-slate-200 text-center"><button type="button" onclick="$(this).closest('tr').remove(); updateLivePreview();" class="text-rose-600 font-bold hover:text-rose-500">حذف</button></td>`);
                            gridBody.append(tr);
                        });
                    }

                    // Model & Waist fields
                    if (d.model) {
                        $('#chart-model').val(d.model).prop('disabled', false).parent().removeClass('opacity-50');
                    } else {
                        $('#chart-model').val('').prop('disabled', !(pr && pr.show_model)).parent().toggleClass('opacity-50', !(pr && pr.show_model));
                    }
                    if (d.waist_type) {
                        $('#chart-waist-type').val(d.waist_type).prop('disabled', false).parent().removeClass('opacity-50');
                    } else {
                        $('#chart-waist-type').val('').prop('disabled', !(pr && pr.show_waist)).parent().toggleClass('opacity-50', !(pr && pr.show_waist));
                    }

                    // Specs & Description presets
                    if (d.desc_preset_id) {
                        $('#chart-desc-preset-select').val(d.desc_preset_id);
                        buildDescriptionFields(d.desc_preset_id, d.desc_values || {});
                    } else if (d.desc_values && Object.keys(d.desc_values).length > 0) {
                        buildDescriptionFields('', d.desc_values);
                    }

                    // Pre-select linked product
                    var activeProdId = overrideProductId;
                    if (!activeProdId && Array.isArray(app_products)) {
                        var linkedProd = app_products.find(function(p) {
                            return String(p.size_chart_template_id) === String(d.id);
                        });
                        if (linkedProd) {
                            activeProdId = linkedProd.id;
                        } else {
                            var matchedProd = app_products.find(function(p) {
                                var base = getProductBaseName(p.name || p.title || '');
                                return base && d.product_desc && base.toLowerCase() === d.product_desc.toLowerCase();
                            });
                            if (matchedProd) activeProdId = matchedProd.id;
                        }
                    }

                    if (activeProdId) {
                        $('#chart-linked-product-select').val(activeProdId);
                        $('#preview-product-quick-select').val(activeProdId);
                        var foundP = app_products.find(p => String(p.id) === String(activeProdId));
                        if (foundP) {
                            var pBase = getProductBaseName(foundP.name || foundP.title || '');
                            var pSku = foundP.sku || '';
                            $('#preview-active-product-label').text('محصول فعال: ' + pBase + (pSku ? ' (کد: ' + pSku + ')' : ''));
                        }
                    } else {
                        $('#chart-linked-product-select').val('');
                        $('#preview-product-quick-select').val('');
                        $('#preview-active-product-label').text(d.product_desc ? 'طرح اختصاصی: ' + d.product_desc : 'یک محصول را انتخاب کنید تا نام و جدول سایز آن در پیش‌نمایش بارگذاری شود');
                    }

                    // Ensure table block is visible
                    setBlockVisibility('table', true);
                    $('#setting-show-table').prop('checked', true);
                    scs_settings.show_table = 1;
                    $('#preview-table-card').removeClass('hidden');

                    $('#btn-delete-chart').removeClass('hidden');
                    renderDragAndDropBuilder();
                    applyBrandingChanges();
                    updateLivePreview();
                    showToast('جدول سایز با موفقیت بارگذاری شد.', 'success');
                }
            });
        }

        // Live Preview Renderer
        function updateLivePreview() {
            var productDesc = $('#chart-product-desc').val().trim();
            var presetId = $('#chart-preset-select').val();
            var modelVal = $('#chart-model').val().trim();
            var waistVal = $('#chart-waist-type').val().trim();
            
            // Output values or defaults
            $('#preview-title').text(productDesc || 'شرح محصول');

            // 1. Primary Source of Truth for Columns: Active Editor Table Headers
            var activeCols = [];
            $('#grid-header-row th').each(function() {
                var colText = $(this).text().trim();
                if (colText && colText !== 'اکشن' && !colText.includes('پریست') && !colText.includes('فاقد ستون') && !colText.includes('ابتدا یک')) {
                    activeCols.push(colText);
                }
            });

            // 2. Fallback to loaded chart columns
            if (activeCols.length === 0 && window.selected_chart && Array.isArray(selected_chart.columns) && selected_chart.columns.length > 0) {
                activeCols = selected_chart.columns;
            }

            // 3. Fallback to preset
            var preset = app_presets.find(x => String(x.id) === String(presetId) || x.id == presetId);
            if (!preset && presetId) {
                preset = app_presets.find(x => x.name === presetId || (x.category && x.category === presetId));
            }
            if (activeCols.length === 0) {
                if (preset && Array.isArray(preset.columns) && preset.columns.length > 0) {
                    activeCols = preset.columns;
                } else if (Array.isArray(app_presets) && app_presets.length > 0 && app_presets[0].columns) {
                    activeCols = app_presets[0].columns;
                }
            }

            var catName = (preset && preset.category) || (window.selected_chart && selected_chart.category) || 'عمومی';
            $('#preview-cat').text(catName);

            // Show model / waist pills
            var showModel = (preset && preset.show_model) || (window.selected_chart && selected_chart.model) || (modelVal ? 1 : 0);
            if (showModel && modelVal) {
                $('#preview-model-pill').removeClass('hidden').css('display', 'flex');
                $('#preview-model').text(modelVal);
            } else {
                $('#preview-model-pill').addClass('hidden').css('display', 'none');
            }

            var showWaist = (preset && preset.show_waist) || (window.selected_chart && selected_chart.waist_type) || (waistVal ? 1 : 0);
            if (showWaist && waistVal) {
                $('#preview-waist-pill').removeClass('hidden').css('display', 'flex');
                $('#preview-waist').text(waistVal);
            } else {
                $('#preview-waist-pill').addClass('hidden').css('display', 'none');
            }

            // Illustration figure loader or generic fallback
            var figureBox = $('#preview-illus-box');
            var illusUrl = (preset && preset.illus_url) || (window.selected_chart && selected_chart.illus_url) || (window.selected_chart && selected_chart.image_path) || '';
            if (illusUrl) {
                figureBox.html(`<img src="${resolveImageUrl(illusUrl)}" class="max-w-full max-h-[160px] object-contain">`);
            } else {
                figureBox.html('<span class="text-4xl text-slate-350">📐</span>');
            }
            
            // Table visual columns builder with 100% matched column-cell mapping
            if (activeCols.length > 0) {
                var tableContainer = $('#preview-table-container');
                var numCols = activeCols.length;
                var cellPaddingH = numCols >= 8 ? 'px-2' : (numCols >= 6 ? 'px-2.5' : 'px-3.5');
                var cellPaddingV = 'py-3';
                var cellFontSize = numCols >= 8 ? 'text-[10.5px]' : (numCols >= 6 ? 'text-[11px]' : 'text-xs');

                var tableHtml = `
                    <table class="w-full text-right ${cellFontSize} border-collapse" style="direction: rtl; width: 100%; table-layout: auto; border-spacing: 0;">
                        <thead class="bg-slate-50 text-slate-800 font-black border-b border-slate-200">
                            <tr>
                `;
                activeCols.forEach((col, idx) => {
                    var borderClass = idx > 0 ? 'border-r border-slate-200/90' : '';
                    tableHtml += `<th class="${cellPaddingV} ${cellPaddingH} bg-slate-50 text-slate-800 border-b-2 border-slate-200 ${borderClass} text-center font-black whitespace-nowrap tracking-normal">${col}</th>`;
                });
                tableHtml += `
                            </tr>
                        </thead>
                        <tbody>
                `;

                // Aggregate rows from editor UI inputs
                var rowsCount = 0;
                var htmlSeoTableRows = ''; // For generating standard searchable HTML snippet
                
                $('#grid-body').find('tr').each(function(rIdx) {
                    var rowInputs = $(this).find('.select-input-cell');
                    if (rowInputs.length > 0) {
                        var isEven = (rIdx % 2 === 1);
                        var bgClass = isEven ? 'bg-slate-50/70' : 'bg-white';
                        tableHtml += `<tr class="border-b last:border-0 border-slate-100 ${bgClass} hover:bg-indigo-50/30 transition-colors">`;
                        htmlSeoTableRows += '<tr>';
                        activeCols.forEach(function(col, idx) {
                            var input = rowInputs.eq(idx);
                            var cellVal = input.length ? input.val().trim() : '';
                            var displayVal = cellVal !== '' ? cellVal : '—';
                            var borderClass = idx > 0 ? 'border-r border-slate-100' : '';
                            var fontBoldClass = (idx === 0) ? 'font-black text-slate-900' : 'font-bold text-slate-700';
                            tableHtml += `<td class="${cellPaddingV} ${cellPaddingH} ${borderClass} text-center ${fontBoldClass} whitespace-nowrap">${displayVal}</td>`;
                            htmlSeoTableRows += `<td style="padding: 10px; border: 1px solid #ddd; text-align: center;">${displayVal}</td>`;
                        });
                        tableHtml += '</tr>';
                        htmlSeoTableRows += '</tr>';
                        rowsCount++;
                    }
                });

                if (rowsCount === 0) {
                    tableHtml += '<tr><td colspan="' + activeCols.length + '" class="p-6 text-center text-slate-400 font-bold">ردیفی ثبت نگردیده است (داده‌ای وارد نشده)</td></tr>';
                }
                
                tableHtml += `
                        </tbody>
                    </table>
                `;
                tableContainer.html(tableHtml);

                // Live dynamic specifications loader
                var specItems = [];
                var htmlSeoSpecs = '';
                $('.desc-custom-field').each(function() {
                    var label = $(this).data('label');
                    var key = $(this).data('key');
                    var val = $(this).find('input').val().trim();
                    if (val) {
                        specItems.push({ label: label, value: val });
                        htmlSeoSpecs += `<li style="margin-bottom: 8px;"><strong>${label}:</strong> ${val}</li>`;
                    }
                });

                function getSpecIcon(lbl) {
                    var l = lbl.toLowerCase();
                    if (l.includes('جنس') || l.includes('الیاف') || l.includes('material')) return '🧵';
                    if (l.includes('ضخامت') || l.includes('thickness')) return '🥞';
                    if (l.includes('تنخور') || l.includes('fit')) return '🧍';
                    if (l.includes('شستشو') || l.includes('wash') || l.includes('نگهداری')) return '🧼';
                    if (l.includes('کشسانی') || l.includes('stretch')) return '🎗️';
                    if (l.includes('فصل') || l.includes('season')) return '🌤️';
                    if (l.includes('مدل') || l.includes('style')) return '🎨';
                    return '🔹';
                }

                if (specItems.length > 0) {
                    $('#preview-specs-box').removeClass('hidden');
                    var specsList = $('#preview-specs-list').empty();
                    specItems.forEach(it => {
                        var icon = getSpecIcon(it.label);
                        specsList.append(`
                            <div class="flex items-center justify-between border-b border-slate-100/60 pb-1.5 hover:bg-slate-50/50 transition-colors">
                                <span class="font-extrabold text-slate-400 flex items-center gap-1.5">
                                    <span>${icon}</span>
                                    <span>${it.label}:</span>
                                </span>
                                <span class="font-black text-slate-850">${it.value}</span>
                            </div>
                        `);
                    });
                } else {
                    $('#preview-specs-box').addClass('hidden');
                }

                // Render product custom advice notes
                var productNotes = $('#chart-product-notes').val().trim();
                if (productNotes) {
                    $('#preview-notes-box').removeClass('hidden');
                    $('#preview-notes-text').html(productNotes.replace(/\n/g, '<br>'));
                } else {
                    $('#preview-notes-box').addClass('hidden');
                }

                // ------------------------------------
                // Build and export SEO standard snippet
                // ------------------------------------
                var seoHtml = `<!-- Size Chart Studio Optimized Block -->\n<div class="size-chart-wrapper" style="direction: rtl; text-align: right; font-family: Tahoma, sans-serif; line-height: 1.8; max-width: 800px; margin: 20px auto; padding: 20px; border: 1px solid #eee; border-radius: 16px; background: #fff;">\n`;
                seoHtml += `    <h2 style="font-size: 20px; color: #111; border-bottom: 2px solid #5a57eb; padding-bottom: 8px; margin-top: 0; font-weight: 900;">جدول سایزبندی: ${productDesc || 'شرح محصول'}</h2>\n`;
                
                if (catName || modelVal || waistVal) {
                    seoHtml += `    <p style="font-size: 13px; color: #666; margin: 12px 0;">\n`;
                    if (catName) seoHtml += `        <strong>دسته‌بندی:</strong> ${catName}\n`;
                    if (modelVal) seoHtml += `        | <strong>مدل:</strong> ${modelVal}\n`;
                    if (waistVal) seoHtml += `        | <strong>نوع کمر:</strong> ${waistVal}\n`;
                    seoHtml += `    </p>\n`;
                }

                if (htmlSeoSpecs) {
                    seoHtml += `    <h3 style="font-size: 14px; margin-top: 20px; color: #333; font-weight: bold;">ویژگی‌ها و جزئیات کالا</h3>\n`;
                    seoHtml += `    <ul style="font-size: 12px; color: #555; padding-right: 20px; margin-top: 8px;">\n${htmlSeoSpecs}    </ul>\n`;
                }

                if (productNotes) {
                    seoHtml += `    <div style="background: #fff8eb; border: 1px solid #f5e4bd; padding: 12px; border-radius: 8px; margin-top: 15px; font-size: 12px; color: #a46a00;">\n`;
                    seoHtml += `        <strong>💡 توضیحات تکمیلی و مشاوره سایز:</strong> ${productNotes}\n`;
                    seoHtml += `    </div>\n`;
                }

                seoHtml += `    <h3 style="font-size: 14px; margin-top: 24px; color: #333; font-weight: bold;">جدول راهنمای ابعاد کالا (سانتی‌متر)</h3>\n`;
                seoHtml += `    <div style="overflow-x: auto; margin-top: 10px;">\n`;
                seoHtml += `        <table style="width: 100%; border-collapse: collapse; font-size: 12px; text-align: center; border: 1px solid #eee;">\n`;
                seoHtml += `            <thead>\n`;
                seoHtml += `                <tr style="background-color: #f9f9f9; font-weight: bold; border-bottom: 2px solid #ddd;">\n`;
                activeCols.forEach(col => {
                    seoHtml += `                    <th style="padding: 10px; border: 1px solid #ddd;">${col}</th>\n`;
                });
                seoHtml += `                </tr>\n`;
                seoHtml += `            </thead>\n`;
                seoHtml += `            <tbody>\n${htmlSeoTableRows}            </tbody>\n`;
                seoHtml += `        </table>\n`;
                seoHtml += `    </div>\n`;
                seoHtml += `    <p style="font-size: 10px; color: #999; margin-top: 16px; text-align: left; border-top: 1px solid #eee; padding-top: 8px;">طراحی شده در Size Chart Studio</p>\n`;
                seoHtml += `</div>`;
                
                $('#seo-html-output').val(seoHtml);
            } else {
                $('#preview-cat').text('—');
                $('#preview-model-pill').addClass('hidden').css('display', 'none');
                $('#preview-waist-pill').addClass('hidden').css('display', 'none');
                $('#preview-specs-box').addClass('hidden');
                $('#preview-notes-box').addClass('hidden');
                $('#preview-table-container').html('<div class="p-6 text-center text-slate-400 font-bold">برای نمایش جدول، ابتدا ساختار و پریست ستون‌ها را انتخاب فرمایید.</div>');
                $('#seo-html-output').val('');
            }

            // Sync dynamic branding, background, and positioning
            applyBrandingStyleToTarget('#td-preview', false);
        }

        // Action: Copy SEO text output
        function copySeoHtml() {
            var el = $('#seo-html-output');
            el.select();
            document.execCommand('copy');
            showToast('کد سئو متناظر با موفقیت کپی شد.', 'success');
        }

        // Helper: Capture clean canvas snapshot with proper 1:1 scale and no zoom artifacts
        function captureCleanChartCanvas(containerToRender) {
            return new Promise(function(resolve, reject) {
                var target = containerToRender || document.querySelector("#td-preview");
                if (!target) {
                    reject(new Error("Canvas element not found"));
                    return;
                }

                // 1. Temporarily remove selection markers and snap lines
                $(target).find('.is-selected').removeClass('is-selected');
                $('#scs-snap-x, #scs-snap-y').hide();

                // 2. Temporarily reset zoom / transform scale to ensure 1:1 pixel fidelity
                var origTransform = $(target).css('transform');
                var origTransformOrigin = $(target).css('transform-origin');
                $(target).css({
                    'transform': 'none',
                    'transform-origin': '0 0'
                });

                // Determine background color
                var bgPattern = (window.scs_settings && scs_settings.bg_pattern) || 'none';
                var hasBgImage = !!(window.scs_settings && scs_settings.bg_image);
                var bgColor = null;
                if (!hasBgImage) {
                    if (bgPattern === 'dark') bgColor = '#0f172a';
                    else if (bgPattern === 'linen') bgColor = '#faf7f2';
                    else if (bgPattern === 'craft') bgColor = '#f4efe6';
                    else if (bgPattern === 'grid') bgColor = '#f8fafc';
                    else bgColor = '#ffffff';
                }

                html2canvas(target, {
                    useCORS: true,
                    allowTaint: true,
                    scale: 2,
                    logging: false,
                    backgroundColor: bgColor,
                    scrollX: 0,
                    scrollY: 0
                }).then(function(canvas) {
                    // Restore original transforms and selections
                    $(target).css({
                        'transform': origTransform || '',
                        'transform-origin': origTransformOrigin || ''
                    });
                    if (window.currentSelectedBlockKey) {
                        $(`[data-scs-block="${currentSelectedBlockKey}"]`).addClass('is-selected');
                    }
                    resolve(canvas);
                }).catch(function(err) {
                    $(target).css({
                        'transform': origTransform || '',
                        'transform-origin': origTransformOrigin || ''
                    });
                    if (window.currentSelectedBlockKey) {
                        $(`[data-scs-block="${currentSelectedBlockKey}"]`).addClass('is-selected');
                    }
                    reject(err);
                });
            });
        }

        // Multi-stage Process: Save & Generate Render Canvas Img & upload to Library
        function saveChartProcess() {
            var productDesc = $('#chart-product-desc').val().trim();
            var presetId = $('#chart-preset-select').val();
            
            if (!productDesc || !presetId) {
                showToast('توضیحات و پریست قالب از فیلدهای اجباری هستند.', 'error');
                return;
            }

            showToast('در حال شروع تصویربرداری و تولید سند ابعاد...', 'info');

            // Set dynamic canvas rendering
            setTimeout(() => {
                captureCleanChartCanvas(document.querySelector("#td-preview")).then(canvas => {
                    var base64 = canvas.toDataURL('image/jpeg', 0.92);

                    showToast('در حال بارگذاری تصویر ساخته‌شده روی سرور...', 'info');
                    
                    $.post('', {
                        action: 'td_scs_chart_image_upload',
                        image_data: base64,
                        existing_image_path: (selected_chart && selected_chart.image_path) ? selected_chart.image_path : ''
                    }, function(response) {
                        if (response.success) {
                            var uploadedPath = response.data.url;
                            
                            // 2- Save metadata size chart
                            var rows = [];
                            $('#grid-body').find('tr').each(function() {
                                var rowObj = {};
                                $(this).find('.select-input-cell').each(function(idx) {
                                    rowObj['c' + idx] = $(this).val().trim();
                                });
                                rows.push(rowObj);
                            });

                            var desc_values = {};
                            $('.desc-custom-field').each(function() {
                                var key = $(this).data('key');
                                var val = $(this).find('input').val().trim();
                                desc_values[key] = val;
                            });

                            var columns = [];
                            $('#grid-header-row th').each(function() {
                                var t = $(this).text().trim();
                                if (t && t !== 'اکشن' && !t.includes('پریست') && !t.includes('فاقد ستون') && !t.includes('ابتدا یک')) {
                                    columns.push(t);
                                }
                            });
                            if (columns.length === 0 && window.selected_chart && Array.isArray(selected_chart.columns) && selected_chart.columns.length > 0) {
                                columns = selected_chart.columns;
                            }
                            var preset = app_presets.find(x => String(x.id) === String(presetId) || x.id == presetId);
                            if (columns.length === 0) {
                                columns = (preset && Array.isArray(preset.columns)) ? preset.columns : [];
                            }
                            var categoryVal = (preset && preset.category) || (window.selected_chart && selected_chart.category) || $('#preview-cat').text().trim() || 'عمومی';

                            var postData = {
                                action: 'td_scs_chart_save',
                                id: $('#editor-chart-id').val(),
                                title: $('#chart-title').val().trim(),
                                product_desc: productDesc,
                                category: categoryVal,
                                preset_id: presetId,
                                model: $('#chart-model').val().trim(),
                                waist_type: $('#chart-waist-type').val().trim(),
                                desc_preset_id: $('#chart-desc-preset-select').val(),
                                desc_values: JSON.stringify(desc_values),
                                columns: JSON.stringify(columns),
                                rows: JSON.stringify(rows),
                                image_path: uploadedPath,
                                product_notes: $('#chart-product-notes').val().trim(),
                                linked_product_id: $('#chart-linked-product-select').val()
                            };

                            $.post('', postData, function(saveRes) {
                                if (saveRes.success) {
                                    showToast('کارت اندازه‌گیری با موفقیت به کتابخانه مرکزی محصولات افزوده و ذخیره شد.', 'success');
                                    
                                    var autoLinkProdId = $('#chart-linked-product-select').val();
                                    if (autoLinkProdId) {
                                        var p_obj = app_products.find(function(x) { return x.id === autoLinkProdId; });
                                        if (p_obj) {
                                            var baseName = getProductBaseName(p_obj.name);
                                            app_products.forEach(function(p) {
                                                var p_base = getProductBaseName(p.name);
                                                if (p.id === autoLinkProdId || p_base.toLowerCase() === baseName.toLowerCase()) {
                                                    p.size_chart_template_id = saveRes.data.id;
                                                    p.size_chart = uploadedPath;
                                                }
                                            });
                                        }
                                        populateLinkerUI();
                                    }

                                    loadChartsList();
                                    initNewChart();
                                } else {
                                    showToast(saveRes.message || 'ثبت تنظیمات با خطا مواجه شد.', 'error');
                                }
                            });

                        } else {
                            showToast(response.message || 'بارگذاری تصویر خروجی با شکست مواجه شد.', 'error');
                        }
                    });
                }).catch(function(err) {
                    showToast('خطا در تصویربرداری از بوم سایزبندی.', 'error');
                    console.error(err);
                });
            }, 300);
        }

        function downloadChartAsJpg() {
            var productDesc = $('#chart-product-desc').val().trim() || 'size-chart';
            showToast('در حال تبدیل جدول سایز به تصویر کیفیت اصلی...', 'info');
            
            captureCleanChartCanvas(document.querySelector('#td-preview')).then(function(canvas) {
                var link = document.createElement('a');
                link.download = productDesc + '-size-chart.jpg';
                link.href = canvas.toDataURL('image/jpeg', 0.95);
                link.click();
                showToast('تصویر با موفقیت در سیستم شما دانلود شد. 🖼️', 'success');
            }).catch(function(err) {
                showToast('خطا در تبدیل جدول به تصویر.', 'error');
                console.error(err);
            });
        }

        // --- Linker and Importer Functions ---

        function populateApprovedProductsDropdown(filterText) {
            var select = $('#chart-linked-product-select');
            var previewSelect = $('#preview-product-quick-select');
            
            var currentVal = select.length > 0 ? select.val() : '';
            var currentPrevVal = previewSelect.length > 0 ? previewSelect.val() : '';

            if (select.length > 0) {
                select.empty();
                select.append('<option value="">-- عدم اتصال خودکار به محصول --</option>');
            }
            if (previewSelect.length > 0) {
                previewSelect.empty();
                previewSelect.append('<option value="">-- انتخاب محصول برای پیش‌نمایش --</option>');
            }

            if (!Array.isArray(app_products) || app_products.length === 0) {
                if (previewSelect.length > 0) {
                    previewSelect.append('<option value="" disabled>(هیچ محصولی یافت نشد)</option>');
                }
                return;
            }
            
            // Group active products by base name
            var uniqueActiveMap = {};
            app_products.forEach(function(p) {
                var pStatus = p.status || 'active';
                if (pStatus !== 'trash' && pStatus !== 'deleted') {
                    var base = getProductBaseName(p.name || p.title || '');
                    var key = (base || p.id || '').toLowerCase();
                    if (key && !uniqueActiveMap[key]) {
                        uniqueActiveMap[key] = p;
                    }
                }
            });

            if (Object.keys(uniqueActiveMap).length === 0) {
                app_products.forEach(function(p) {
                    var base = getProductBaseName(p.name || p.title || '');
                    var key = (base || p.id || '').toLowerCase();
                    if (key && !uniqueActiveMap[key]) {
                        uniqueActiveMap[key] = p;
                    }
                });
            }
            
            var uniqueActiveList = Object.values(uniqueActiveMap);
            
            if (filterText) {
                filterText = filterText.toLowerCase().trim();
                uniqueActiveList = uniqueActiveList.filter(function(p) {
                    var baseName = getProductBaseName(p.name || p.title || '').toLowerCase();
                    var sku = (p.sku || '').toLowerCase();
                    return baseName.indexOf(filterText) !== -1 || sku.indexOf(filterText) !== -1;
                });
            }
            
            uniqueActiveList.sort(function(a, b) {
                var nameA = getProductBaseName(a.name || a.title || '');
                var nameB = getProductBaseName(b.name || b.title || '');
                return nameA.localeCompare(nameB, 'fa');
            });
            
            uniqueActiveList.forEach(function(p) {
                var baseName = getProductBaseName(p.name || p.title || 'محصول بدون نام');
                var hasChart = !!p.size_chart_template_id;
                var chartBadge = hasChart ? ' ⭐' : '';
                var skuBadge = p.sku ? ` (${p.sku})` : '';
                
                if (select.length > 0) {
                    select.append(`<option value="${p.id}" data-id="${p.id}" data-base="${baseName}">${baseName}${skuBadge}${chartBadge}</option>`);
                }
                if (previewSelect.length > 0) {
                    previewSelect.append(`<option value="${p.id}" data-id="${p.id}" data-base="${baseName}">${baseName}${skuBadge}${chartBadge}</option>`);
                }
            });
            
            if (currentVal && select.find('option[value="' + currentVal + '"]').length > 0) {
                select.val(currentVal);
            }
            if (currentPrevVal && previewSelect.find('option[value="' + currentPrevVal + '"]').length > 0) {
                previewSelect.val(currentPrevVal);
            }
        }

        function selectProductForLivePreview(productId) {
            if (!productId) {
                clearLivePreviewProduct();
                return;
            }

            var product = app_products.find(function(p) {
                return String(p.id) === String(productId) || p.id == productId;
            });
            if (!product) {
                // Fallback to name or SKU search
                product = app_products.find(function(p) {
                    return (p.name && p.name.includes(productId)) || (p.title && p.title.includes(productId)) || (p.sku && p.sku === productId);
                });
            }
            if (!product) {
                showToast('محصول انتخابی یافت نشد.', 'warning');
                return;
            }

            var baseName = getProductBaseName(product.name || product.title || '');
            var sku = product.sku || '';

            // 1. Sync dropdown controls & badge label
            $('#preview-product-quick-select').val(product.id);
            $('#chart-linked-product-select').val(product.id);
            $('#preview-active-product-label').text('محصول فعال: ' + baseName + (sku ? ' (کد: ' + sku + ')' : ''));

            // 2. Check if this product is already linked to a saved size chart
            if (product.size_chart_template_id && Array.isArray(app_charts) && app_charts.length > 0) {
                var linkedChart = app_charts.find(function(c) {
                    return String(c.id) === String(product.size_chart_template_id) || c.id == product.size_chart_template_id;
                });
                if (linkedChart) {
                    loadChart(linkedChart.id, product.id);
                    showToast(`جدول سایز اختصاصی محصول «${baseName}» بارگذاری و نمایش داده شد. ✨`, 'success');
                    return;
                }
            }

            // 3. Set product name in editor
            $('#chart-product-desc').val(baseName);
            var currentTitle = $('#chart-title').val().trim();
            if (!currentTitle || currentTitle.includes('جدید') || currentTitle.includes('بدون نام')) {
                $('#chart-title').val('جدول سایز ' + baseName);
            }

            // 4. Auto-detect and select best fitting category preset if not already set or mismatched
            var matchedPreset = null;
            if (Array.isArray(app_presets) && app_presets.length > 0) {
                var pCat = (product.category || '').toLowerCase().trim();
                var pName = (product.name || product.title || '').toLowerCase();

                if (pCat) {
                    matchedPreset = app_presets.find(function(pr) {
                        var prCat = (pr.category || '').toLowerCase().trim();
                        return prCat === pCat || pCat.includes(prCat) || prCat.includes(pCat);
                    });
                }
                if (!matchedPreset) {
                    matchedPreset = app_presets.find(function(pr) {
                        var prName = (pr.name || '').toLowerCase().trim();
                        var prCat = (pr.category || '').toLowerCase().trim();
                        return pName.includes(prName) || pName.includes(prCat);
                    });
                }
                if (!matchedPreset) {
                    matchedPreset = app_presets[0];
                }
            }

            if (matchedPreset) {
                var curPresetVal = $('#chart-preset-select').val();
                if (!curPresetVal || String(curPresetVal) !== String(matchedPreset.id)) {
                    $('#chart-preset-select').val(matchedPreset.id);
                    applyPresetTemplate(matchedPreset.id);
                }
            }

            // 5. If table is empty, auto load standard sample rows
            if ($('#grid-body tr').length === 0) {
                loadSampleChartData();
            }

            // 6. Ensure key blocks are visible
            setBlockVisibility('table', true);
            setBlockVisibility('header', true);
            setBlockVisibility('meta', true);
            setBlockVisibility('illus', true);
            setBlockVisibility('footer', true);

            $('#setting-show-table').prop('checked', true);
            $('#setting-show-header-brand').prop('checked', true);
            $('#setting-show-meta-pills').prop('checked', true);
            $('#setting-show-illustration').prop('checked', true);
            $('#setting-show-footer-brand').prop('checked', true);

            scs_settings.show_table = 1;
            scs_settings.show_header_brand = 1;
            scs_settings.show_meta_pills = 1;
            scs_settings.show_illustration = 1;
            scs_settings.show_footer_brand = 1;

            // Ensure DOM containers are visible
            $('#preview-table-card').removeClass('hidden');
            $('#preview-header-section').removeClass('hidden');
            $('#preview-meta-pills-section').removeClass('hidden');
            $('#preview-illus-card').removeClass('hidden');
            $('#preview-footer-section').removeClass('hidden');

            renderDragAndDropBuilder();
            applyBrandingChanges();
            updateLivePreview();

            showToast(`محصول «${baseName}» با موفقیت در پیش‌نمایش زنده اعمال شد. 🏷️✨`, 'success');
        }

        function selectRandomProductForLivePreview() {
            if (!Array.isArray(app_products) || app_products.length === 0) {
                showToast('هیچ محصولی در این پروژه موجود نیست.', 'warning');
                return;
            }
            var activeProds = app_products.filter(function(p) { return (p.status || 'active') === 'active'; });
            if (activeProds.length === 0) activeProds = app_products;
            var randIndex = Math.floor(Math.random() * activeProds.length);
            var randProd = activeProds[randIndex];
            selectProductForLivePreview(randProd.id);
        }

        function clearLivePreviewProduct() {
            $('#preview-product-quick-select').val('');
            $('#chart-linked-product-select').val('');
            $('#preview-active-product-label').text('یک محصول را انتخاب کنید تا نام و جدول سایز آن در پیش‌نمایش بارگذاری شود');
            showToast('انتخاب محصول ریست شد.', 'info');
        }

        function exportAllChartsJSON() {
            if (!app_charts || app_charts.length === 0) {
                showToast('هیچ جدول سایزی طراحی نشده است تا بتوان از آن خروجی گرفت.', 'error');
                return;
            }
            try {
                var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(app_charts, null, 4));
                var downloadAnchor = document.createElement('a');
                downloadAnchor.setAttribute("href", dataStr);
                downloadAnchor.setAttribute("download", "size_charts_export.json");
                document.body.appendChild(downloadAnchor);
                downloadAnchor.click();
                downloadAnchor.remove();
                showToast('فایل نسخه پشتیبان جداول سایز با موفقیت صادر و دانلود شد.', 'success');
            } catch(err) {
                showToast('خطا در تهیه فایل خروجی جداول سایز.', 'error');
                console.error(err);
            }
        }

        function confirmResetAllCharts() {
            if (!confirm('توجه: با این اقدام تمام جداول سایزبندی ثبت شده به همراه فایل‌های تصویری و تمامی ارتباطات آن‌ها به کالاها به طور کامل حذف خواهند شد و قابل بازیابی نخواهند بود. آیا ادامه می‌دهید؟')) {
                return;
            }
            $.post('', { action: 'td_scs_reset_all_charts' }, function(res) {
                if (res.success) {
                    showToast('تمامی جداول سایزبندی با موفقیت حذف شدند.', 'success');
                    initNewChart();
                    loadChartsList();
                } else {
                    showToast(res.message || 'خطا در انجام عملیات بازنشانی.', 'error');
                }
            });
        }

        function confirmResetAllPresets() {
            if (!confirm('توجه: با این اقدام تمامی استانداردهای تعریف شده (پریست‌های دسته‌ای) به حالت پیش‌فرض و استانداردهای جزئیات و فیلدهای فرعی پاک خواهند شد. آیا ادامه می‌دهید؟')) {
                return;
            }
            $.post('', { action: 'td_scs_reset_all_presets' }, function(res) {
                if (res.success) {
                    showToast('تمامی استانداردهای ابعادی با موفقیت به حالت اولیه بازنشانی شدند.', 'success');
                    loadPresets();
                    loadDescPresets();
                    initNewChart();
                } else {
                    showToast(res.message || 'خطا در بازنشانی استانداردها.', 'error');
                }
            });
        }

        function onLinkedProductChange(productId) {
            if (!productId) {
                clearLivePreviewProduct();
                return;
            }
            selectProductForLivePreview(productId);
        }

        function getProductBaseName(name) {
            if (!name) return 'بدون نام';
            const parts = name.split('-');
            return parts[0].trim();
        }

        function filterLinkerProducts() {
            var term = $('#linker-search').val().trim().toLowerCase();
            $('#linker-unassigned-list > div, #linker-assigned-list > div').each(function() {
                var text = $(this).text().toLowerCase();
                if (text.includes(term)) {
                    $(this).removeClass('hidden');
                } else {
                    $(this).addClass('hidden');
                }
            });
        }

        function populateLinkerUI() {
            if (typeof initMultiAssignTab === 'function') {
                initMultiAssignTab();
            }
        }

        // ==========================================
        // MULTI-ASSIGN SIZE CHART STUDIO (اساین گروهی جدول سایز به محصولات)
        // ==========================================
        var multiAssignSourceType = 'chart';
        var multiAssignSelectedChartId = '';
        var multiAssignSelectedSourceProductId = '';
        var multiAssignSelectedProductIds = new Set();
        var multiAssignFilterSearch = '';
        var multiAssignFilterCategory = '';
        var multiAssignFilterStatus = 'missing';
        var multiAssignFilterStock = 'all';
        var multiAssignPage = 1;
        var multiAssignPageSize = 24;

        function initMultiAssignTab() {
            populateMultiAssignCategoryDropdown();
            populateMultiAssignSourceChartsDropdown();
            populateMultiAssignSourceProductsDropdown();
            updateMultiAssignSourceCard();
            renderMultiAssignProductsList();
            updateMultiAssignStatusStats();
        }

        function setMultiAssignSourceType(type) {
            multiAssignSourceType = type;
            if (type === 'chart') {
                $('#btn-ma-source-chart').addClass('bg-indigo-600 text-white shadow-xs').removeClass('text-slate-600 hover:text-slate-900');
                $('#btn-ma-source-product').removeClass('bg-indigo-600 text-white shadow-xs').addClass('text-slate-600 hover:text-slate-900');
                $('#ma-chart-picker-container').removeClass('hidden');
                $('#ma-product-picker-container').addClass('hidden');
                
                var chartVal = $('#ma-source-chart-select').val();
                if (chartVal) {
                    multiAssignSelectedChartId = chartVal;
                }
            } else {
                $('#btn-ma-source-product').addClass('bg-indigo-600 text-white shadow-xs').removeClass('text-slate-600 hover:text-slate-900');
                $('#btn-ma-source-chart').removeClass('bg-indigo-600 text-white shadow-xs').addClass('text-slate-600 hover:text-slate-900');
                $('#ma-product-picker-container').removeClass('hidden');
                $('#ma-chart-picker-container').addClass('hidden');

                var prodVal = $('#ma-source-product-select').val();
                if (prodVal) {
                    onMultiAssignSourceProductSelect(prodVal);
                }
            }
            updateMultiAssignSourceCard();
            renderMultiAssignProductsList();
        }

        function populateMultiAssignCategoryDropdown() {
            var catSelect = $('#ma-filter-category');
            var currentVal = catSelect.val() || '';
            catSelect.empty().append('<option value="">همه دسته‌بندی‌ها</option>');

            var categories = new Set();
            (app_products || []).forEach(function(p) {
                if (p.category && p.category.trim()) {
                    categories.add(p.category.trim());
                }
            });

            Array.from(categories).sort().forEach(function(cat) {
                var sel = (cat === currentVal) ? 'selected' : '';
                catSelect.append(`<option value="${escapeHtml(cat)}" ${sel}>${escapeHtml(cat)}</option>`);
            });
        }

        function populateMultiAssignSourceChartsDropdown() {
            var select = $('#ma-source-chart-select');
            select.empty();

            $('#ma-charts-count-badge').text(Array.isArray(app_charts) ? app_charts.length : 0);

            if (!Array.isArray(app_charts) || app_charts.length === 0) {
                select.append('<option value="">(هیچ جدول سایزی طراحی نشده است - ابتدا یک جدول بسازید)</option>');
                multiAssignSelectedChartId = '';
                return;
            }

            app_charts.forEach(function(c) {
                var title = c.title || c.product_desc || 'جدول بی نام';
                var sub = c.category ? ` (دسته: ${c.category})` : '';
                select.append(`<option value="${c.id}">${escapeHtml(title)}${escapeHtml(sub)}</option>`);
            });

            if (!multiAssignSelectedChartId || !app_charts.some(function(c) { return c.id === multiAssignSelectedChartId; })) {
                multiAssignSelectedChartId = app_charts[0].id;
            }
            select.val(multiAssignSelectedChartId);
        }

        function populateMultiAssignSourceProductsDropdown(filterText) {
            var select = $('#ma-source-product-select');
            select.empty();

            var search = (filterText || '').toLowerCase().trim();
            var matchedProds = (app_products || []).filter(function(p) {
                var hasChart = !!(p.size_chart_template_id || p.size_chart);
                if (!hasChart) return false;
                if (!search) return true;
                var nameMatch = (p.name || '').toLowerCase().includes(search);
                var skuMatch = (p.sku || '').toLowerCase().includes(search);
                return nameMatch || skuMatch;
            });

            if (matchedProds.length === 0) {
                select.append('<option value="">(هیچ محصول دارای جدول سایزی یافت نشد)</option>');
                multiAssignSelectedSourceProductId = '';
                return;
            }

            matchedProds.forEach(function(p) {
                var chartName = '';
                if (p.size_chart_template_id) {
                    var c = (app_charts || []).find(function(x) { return x.id === p.size_chart_template_id; });
                    if (c) chartName = ` [جدول: ${c.title || c.product_desc || 'بدون عنوان'}]`;
                }
                var label = `${p.name || 'محصول بدون نام'} (کد: ${p.sku || '---'})${chartName}`;
                select.append(`<option value="${p.id}">${escapeHtml(label)}</option>`);
            });

            if (!multiAssignSelectedSourceProductId || !matchedProds.some(function(p) { return p.id === multiAssignSelectedSourceProductId; })) {
                multiAssignSelectedSourceProductId = matchedProds[0].id;
            }
            select.val(multiAssignSelectedSourceProductId);
        }

        function filterMultiAssignSourceProductDropdown(val) {
            populateMultiAssignSourceProductsDropdown(val);
            var prodId = $('#ma-source-product-select').val();
            if (prodId) {
                onMultiAssignSourceProductSelect(prodId);
            }
        }

        function onMultiAssignChartSelect(chartId) {
            multiAssignSelectedChartId = chartId;
            updateMultiAssignSourceCard();
            renderMultiAssignProductsList();
        }

        function onMultiAssignSourceProductSelect(prodId) {
            multiAssignSelectedSourceProductId = prodId;
            var prod = (app_products || []).find(function(p) { return p.id === prodId; });
            if (prod) {
                if (prod.size_chart_template_id) {
                    multiAssignSelectedChartId = prod.size_chart_template_id;
                }
            }
            updateMultiAssignSourceCard();
            renderMultiAssignProductsList();
        }

        function getActiveSourceChart() {
            if (multiAssignSelectedChartId) {
                var c = (app_charts || []).find(function(x) { return x.id === multiAssignSelectedChartId; });
                if (c) return c;
            }
            if (multiAssignSelectedSourceProductId) {
                var p = (app_products || []).find(function(x) { return x.id === multiAssignSelectedSourceProductId; });
                if (p && p.size_chart_template_id) {
                    var c = (app_charts || []).find(function(x) { return x.id === p.size_chart_template_id; });
                    if (c) return c;
                }
            }
            return null;
        }

        function updateMultiAssignSourceCard() {
            var chart = getActiveSourceChart();
            var thumbImg = $('#ma-source-thumb-img');
            var thumbIcon = $('#ma-source-thumb-icon');
            var categoryBadge = $('#ma-source-category-badge');
            var countBadge = $('#ma-source-connected-count-badge');
            var titleText = $('#ma-source-title-text');
            var descText = $('#ma-source-desc-text');
            var extraTags = $('#ma-source-extra-tags').empty();

            if (!chart) {
                thumbImg.addClass('hidden').attr('src', '');
                thumbIcon.removeClass('hidden');
                categoryBadge.text('دسته‌بندی: -');
                countBadge.text('۰ محصول متصل');
                titleText.text('هیچ جدول سایزی انتخاب نشده است');
                descText.text('لطفاً یک جدول یا محصول مرجع را از لیست انتخاب کنید.');
                return;
            }

            var title = chart.title || chart.product_desc || 'جدول بدون نام';
            var category = chart.category || 'عمومی';
            var desc = chart.product_desc || (chart.columns ? `${chart.columns.length} ستون اندازه‌گیری` : '');

            var connectedProds = (app_products || []).filter(function(p) {
                return p.size_chart_template_id === chart.id;
            });

            titleText.text(title);
            descText.text(desc || 'جدول سایز استاندارد');
            categoryBadge.text(`دسته‌بندی: ${category}`);
            countBadge.text(`${connectedProds.length} محصول متصل به این جدول`);

            if (chart.image_path) {
                thumbImg.attr('src', resolveImageUrl(chart.image_path)).removeClass('hidden');
                thumbIcon.addClass('hidden');
            } else {
                thumbImg.addClass('hidden').attr('src', '');
                thumbIcon.removeClass('hidden');
            }

            if (chart.columns && chart.columns.length > 0) {
                extraTags.append(`<span class="px-2 py-0.5 bg-slate-100 rounded-md text-[9px] font-black">${chart.columns.length} ستون ابعاد</span>`);
            }
            if (chart.rows && chart.rows.length > 0) {
                extraTags.append(`<span class="px-2 py-0.5 bg-slate-100 rounded-md text-[9px] font-black">${chart.rows.length} ردیف سایز</span>`);
            }
            if (chart.preset_id) {
                var pr = (app_presets || []).find(function(x) { return x.id === chart.preset_id; });
                if (pr) {
                    extraTags.append(`<span class="px-2 py-0.5 bg-violet-50 text-violet-700 rounded-md text-[9px] font-black border border-violet-100">پریست: ${pr.name}</span>`);
                }
            }
        }

        function onMultiAssignFilterChange() {
            multiAssignFilterSearch = $('#ma-filter-search').val().trim().toLowerCase();
            multiAssignFilterCategory = $('#ma-filter-category').val();
            multiAssignFilterStatus = $('#ma-filter-status').val();
            multiAssignFilterStock = $('#ma-filter-stock').val();
            multiAssignPage = 1;
            renderMultiAssignProductsList();
            updateMultiAssignStatusStats();
        }

        function getFilteredMultiAssignProducts() {
            var search = multiAssignFilterSearch;
            var cat = multiAssignFilterCategory;
            var status = multiAssignFilterStatus;
            var stock = multiAssignFilterStock;
            var activeChartId = multiAssignSelectedChartId;

            return (app_products || []).filter(function(p) {
                if (search) {
                    var nameMatch = (p.name || '').toLowerCase().includes(search);
                    var skuMatch = (p.sku || '').toLowerCase().includes(search);
                    var colorMatch = (p.color || '').toLowerCase().includes(search);
                    var catMatch = (p.category || '').toLowerCase().includes(search);
                    if (!nameMatch && !skuMatch && !colorMatch && !catMatch) return false;
                }

                if (cat && p.category !== cat) {
                    return false;
                }

                var stockVal = parseInt(p.stock, 10);
                if (isNaN(stockVal)) stockVal = 0;
                if (stock === 'in_stock' && stockVal <= 0) return false;
                if (stock === 'out_of_stock' && stockVal > 0) return false;

                var pChartId = p.size_chart_template_id || '';
                var hasChartImg = !!p.size_chart;
                if (status === 'missing') {
                    if (pChartId || hasChartImg) return false;
                } else if (status === 'assigned_this') {
                    if (pChartId !== activeChartId) return false;
                } else if (status === 'assigned_other') {
                    if (!pChartId && !hasChartImg) return false;
                    if (pChartId === activeChartId) return false;
                }

                return true;
            });
        }

        function updateMultiAssignStatusStats() {
            var total = (app_products || []).length;
            var missingCount = 0;
            var assignedThisCount = 0;
            var assignedOtherCount = 0;
            var activeChartId = multiAssignSelectedChartId;

            (app_products || []).forEach(function(p) {
                var pChartId = p.size_chart_template_id || '';
                var hasChartImg = !!p.size_chart;
                if (!pChartId && !hasChartImg) {
                    missingCount++;
                } else if (pChartId === activeChartId) {
                    assignedThisCount++;
                } else {
                    assignedOtherCount++;
                }
            });

            $('#ma-status-stats').html(`
                کل محصولات: <strong class="text-slate-800 font-black">${total}</strong> |
                بدون جدول: <strong class="text-rose-600 font-black">${missingCount}</strong> |
                متصل به همین جدول: <strong class="text-emerald-600 font-black">${assignedThisCount}</strong>
            `);
        }

        function renderMultiAssignProductsList() {
            var grid = $('#ma-products-grid').empty();
            var filtered = getFilteredMultiAssignProducts();
            var activeChartId = multiAssignSelectedChartId;

            $('#ma-filtered-total-text').text(`(از ${filtered.length} مورد نمایان)`);
            $('#ma-selected-badge').text(multiAssignSelectedProductIds.size);

            var allFilteredSelected = filtered.length > 0 && filtered.every(function(p) {
                return multiAssignSelectedProductIds.has(p.id);
            });
            $('#ma-select-all-checkbox').prop('checked', allFilteredSelected);

            var hasSelection = multiAssignSelectedProductIds.size > 0;
            $('#btn-ma-submit').prop('disabled', !hasSelection || !activeChartId);
            $('#btn-ma-unassign').prop('disabled', !hasSelection);
            if (hasSelection) {
                $('#btn-ma-submit-text').text(`اعمال و اساین جدول به ${multiAssignSelectedProductIds.size} محصول انتخاب شده`);
                $('#btn-ma-unassign').text(`❌ قطع انتساب جدول از ${multiAssignSelectedProductIds.size} محصول`);
            } else {
                $('#btn-ma-submit-text').text('اعمال و اساین جدول سایز به موارد انتخاب شده');
                $('#btn-ma-unassign').text('❌ قطع انتساب جدول از موارد انتخابی');
            }

            if (filtered.length === 0) {
                grid.html(`
                    <div class="col-span-full text-center py-16 bg-slate-50 rounded-2xl border border-slate-200/60 p-6">
                        <span class="text-4xl block mb-2">🔍</span>
                        <h4 class="text-xs font-black text-slate-700 mb-1">هیچ محصولی مطابق با فیلترهای کنونی یافت نشد</h4>
                        <p class="text-[11px] text-slate-400">می‌توانید فیلترها را تغییر داده یا جستجو را پاک نمایید.</p>
                        <button type="button" onclick="$('#ma-filter-search').val(''); $('#ma-filter-category').val(''); $('#ma-filter-status').val('all'); onMultiAssignFilterChange();" class="mt-4 px-4 py-2 bg-indigo-50 text-indigo-700 rounded-xl text-xs font-black border border-indigo-100 hover:bg-indigo-100 transition-all">
                            نمایش همه محصولات
                        </button>
                    </div>
                `);
                $('#ma-pagination-container').empty();
                return;
            }

            var totalPages = Math.ceil(filtered.length / multiAssignPageSize) || 1;
            if (multiAssignPage > totalPages) multiAssignPage = totalPages;
            var startIdx = (multiAssignPage - 1) * multiAssignPageSize;
            var pageItems = filtered.slice(startIdx, startIdx + multiAssignPageSize);

            pageItems.forEach(function(p) {
                var isSelected = multiAssignSelectedProductIds.has(p.id);
                var isLinkedToThis = (p.size_chart_template_id === activeChartId && !!activeChartId);
                var pChartId = p.size_chart_template_id || '';
                var hasChartImg = !!p.size_chart;

                var chartBadge = '';
                if (isLinkedToThis) {
                    chartBadge = '<span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-md text-[10px] font-black border border-emerald-200">✅ متصل به همین جدول</span>';
                } else if (pChartId) {
                    var otherChart = (app_charts || []).find(function(c) { return c.id === pChartId; });
                    var otherTitle = otherChart ? (otherChart.title || otherChart.product_desc || 'جدول دیگر') : 'جدول دیگر';
                    chartBadge = `<span class="px-2 py-0.5 bg-amber-50 text-amber-800 rounded-md text-[9.5px] font-bold border border-amber-200 truncate max-w-[140px]" title="${escapeHtml(otherTitle)}">⚠️ ${escapeHtml(otherTitle)}</span>`;
                } else if (hasChartImg) {
                    chartBadge = '<span class="px-2 py-0.5 bg-sky-50 text-sky-800 rounded-md text-[9.5px] font-bold border border-sky-200">🖼️ عکس جدول دارد</span>';
                } else {
                    chartBadge = '<span class="px-2 py-0.5 bg-rose-50 text-rose-700 rounded-md text-[10px] font-black border border-rose-200">🛑 فاقد جدول سایز</span>';
                }

                var imgTag = `<div class="w-14 h-16 bg-slate-100 rounded-xl border border-slate-200 flex items-center justify-center text-slate-300 text-xl select-none shrink-0">👕</div>`;
                if (p.image && !p.image.includes('placehold')) {
                    imgTag = `<img src="${resolveImageUrl(p.image)}" class="w-14 h-16 object-cover rounded-xl border border-slate-200 shadow-xs shrink-0" alt="${escapeHtml(p.name || '')}">`;
                }

                var cardClasses = isSelected
                    ? 'border-indigo-600 bg-indigo-50/40 ring-2 ring-indigo-500/20 shadow-md'
                    : 'border-slate-200/90 bg-white hover:border-slate-300 hover:shadow-xs';

                grid.append(`
                    <div id="ma-card-${p.id}" onclick="toggleMultiAssignProduct('${p.id}')" class="relative rounded-2xl border p-3.5 transition-all cursor-pointer select-none flex flex-col justify-between gap-3 ${cardClasses}">
                        <div class="flex items-start gap-3">
                            <div class="pt-0.5 shrink-0" onclick="event.stopPropagation();">
                                <input type="checkbox" data-prod-id="${p.id}" ${isSelected ? 'checked' : ''} onclick="event.stopPropagation();" onchange="toggleMultiAssignProduct('${p.id}')" class="w-4 h-4 text-indigo-600 rounded cursor-pointer mt-1">
                            </div>
                            ${imgTag}
                            <div class="min-w-0 flex-1">
                                <h5 class="text-xs font-black text-slate-800 leading-snug line-clamp-2 mb-1" title="${escapeHtml(p.name || '')}">
                                    ${escapeHtml(p.name || 'محصول بدون نام')}
                                </h5>
                                <div class="flex items-center gap-1.5 flex-wrap text-[10px] text-slate-400 font-bold mb-1.5">
                                    <span>کد: <strong class="text-slate-600">${escapeHtml(p.sku || '---')}</strong></span>
                                    ${p.color ? `<span class="px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded text-[9px]">${escapeHtml(p.color)}</span>` : ''}
                                </div>
                                <div class="flex items-center gap-1 flex-wrap">
                                    ${chartBadge}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-100 text-[10px] font-bold text-slate-500">
                            <span class="truncate max-w-[120px]">${escapeHtml(p.category || 'بدون دسته')}</span>
                            <span class="${(parseInt(p.stock, 10) || 0) > 0 ? 'text-emerald-700' : 'text-slate-400'}">
                                ${(parseInt(p.stock, 10) || 0) > 0 ? `موجودی: ${p.stock}` : 'ناموجود'}
                            </span>
                        </div>
                    </div>
                `);
            });

            renderMultiAssignPagination(filtered.length, totalPages);
        }

        function renderMultiAssignPagination(totalCount, totalPages) {
            var container = $('#ma-pagination-container').empty();
            if (totalPages <= 1) {
                container.html(`<span class="text-xs text-slate-400 font-bold">نمایش تمام ${totalCount} محصول فیلتر شده</span>`);
                return;
            }

            var startIdx = (multiAssignPage - 1) * multiAssignPageSize + 1;
            var endIdx = Math.min(multiAssignPage * multiAssignPageSize, totalCount);

            var info = `<span class="text-xs text-slate-500 font-bold">نمایش ${startIdx} تا ${endIdx} از ${totalCount} محصول (صفحه ${multiAssignPage} از ${totalPages})</span>`;

            var btns = $('<div class="flex items-center gap-1.5"></div>');

            var prevDisabled = multiAssignPage <= 1 ? 'disabled opacity-40 cursor-not-allowed' : '';
            btns.append(`
                <button type="button" ${prevDisabled} onclick="goToMultiAssignPage(${multiAssignPage - 1})" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-700 text-xs font-black rounded-lg hover:bg-slate-50 transition-all">
                    قبلی
                </button>
            `);

            for (var i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= multiAssignPage - 2 && i <= multiAssignPage + 2)) {
                    var activeClass = i === multiAssignPage ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50';
                    btns.append(`
                        <button type="button" onclick="goToMultiAssignPage(${i})" class="w-8 h-8 flex items-center justify-center border text-xs font-black rounded-lg transition-all ${activeClass}">
                            ${i}
                        </button>
                    `);
                } else if (i === multiAssignPage - 3 || i === multiAssignPage + 3) {
                    btns.append('<span class="text-slate-400 px-1 font-bold">...</span>');
                }
            }

            var nextDisabled = multiAssignPage >= totalPages ? 'disabled opacity-40 cursor-not-allowed' : '';
            btns.append(`
                <button type="button" ${nextDisabled} onclick="goToMultiAssignPage(${multiAssignPage + 1})" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-700 text-xs font-black rounded-lg hover:bg-slate-50 transition-all">
                    بعدی
                </button>
            `);

            container.append(info).append(btns);
        }

        function goToMultiAssignPage(p) {
            multiAssignPage = p;
            renderMultiAssignProductsList();
            var gridEl = document.getElementById('ma-products-grid');
            if (gridEl) {
                gridEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function toggleMultiAssignProduct(prodId) {
            if (multiAssignSelectedProductIds.has(prodId)) {
                multiAssignSelectedProductIds.delete(prodId);
            } else {
                multiAssignSelectedProductIds.add(prodId);
            }
            renderMultiAssignProductsList();
        }

        function toggleMultiAssignSelectAll(checked) {
            var filtered = getFilteredMultiAssignProducts();
            filtered.forEach(function(p) {
                if (checked) {
                    multiAssignSelectedProductIds.add(p.id);
                } else {
                    multiAssignSelectedProductIds.delete(p.id);
                }
            });
            renderMultiAssignProductsList();
        }

        function multiAssignSelectSameCategory() {
            var chart = getActiveSourceChart();
            var category = chart ? chart.category : '';
            if (!category) {
                showToast('دسته‌بندی جدول یا محصول مرجع مشخص نیست.', 'error');
                return;
            }

            var count = 0;
            (app_products || []).forEach(function(p) {
                if (p.category && p.category.trim() === category.trim()) {
                    multiAssignSelectedProductIds.add(p.id);
                    count++;
                }
            });

            renderMultiAssignProductsList();
            showToast(`تعداد ${count} محصول از دسته «${category}» به لیست انتخاب اضافه شدند.`, 'success');
        }

        function clearMultiAssignSelection() {
            multiAssignSelectedProductIds.clear();
            renderMultiAssignProductsList();
            showToast('انتخاب‌ها لغو گردیدند.', 'info');
        }

        function submitMultiAssign() {
            var chartId = multiAssignSelectedChartId;
            var prodIds = Array.from(multiAssignSelectedProductIds);
            var applyVariants = $('#multi-assign-variants-toggle').is(':checked') ? 1 : 0;

            if (!chartId) {
                showToast('لطفاً ابتدا یک جدول سایز را به عنوان سورس انتخاب کنید.', 'error');
                return;
            }

            if (prodIds.length === 0) {
                showToast('لطفاً حداقل یک محصول را برای انتساب جدول سایز تیک بزنید.', 'error');
                return;
            }

            var submitBtn = $('#btn-ma-submit');
            var origHtml = submitBtn.html();
            submitBtn.prop('disabled', true).html('<span class="animate-spin text-sm">⏳</span> <span>در حال اعمال انتساب گروهی...</span>');

            $.post('', {
                action: 'td_scs_multi_assign_chart',
                chart_id: chartId,
                source_product_id: multiAssignSelectedSourceProductId,
                product_ids: prodIds,
                apply_to_variants: applyVariants
            }, function(res) {
                submitBtn.prop('disabled', false).html(origHtml);
                if (res.success) {
                    showToast(res.message, 'success');

                    var updatedSet = new Set(res.updated_ids || prodIds);
                    (app_products || []).forEach(function(p) {
                        if (updatedSet.has(p.id)) {
                            p.size_chart_template_id = chartId;
                            if (res.chart_image) {
                                p.size_chart = res.chart_image;
                            }
                            delete p.manual_size_chart_requested;
                        }
                    });

                    multiAssignSelectedProductIds.clear();
                    updateMultiAssignSourceCard();
                    renderMultiAssignProductsList();
                    updateMultiAssignStatusStats();
                    updateStudioCounters();
                } else {
                    showToast(res.message || 'خطا در ثبت انتساب گروهی.', 'error');
                }
            }, 'json').fail(function() {
                submitBtn.prop('disabled', false).html(origHtml);
                showToast('خطای شبکه در ثبت اطلاعات.', 'error');
            });
        }

        function submitMultiUnassign() {
            var prodIds = Array.from(multiAssignSelectedProductIds);
            var applyVariants = $('#multi-assign-variants-toggle').is(':checked') ? 1 : 0;

            if (prodIds.length === 0) {
                showToast('لطفاً حداقل یک محصول را برای قطع انتساب تیک بزنید.', 'error');
                return;
            }

            if (!confirm(`آیا از قطع انتساب جدول سایز از تعداد ${prodIds.length} محصول انتخاب شده اطمینان دارید؟`)) {
                return;
            }

            var unassignBtn = $('#btn-ma-unassign');
            var origHtml = unassignBtn.html();
            unassignBtn.prop('disabled', true).html('<span class="animate-spin text-sm">⏳</span> <span>درحال حذف انتساب...</span>');

            $.post('', {
                action: 'td_scs_multi_unassign_chart',
                product_ids: prodIds,
                apply_to_variants: applyVariants
            }, function(res) {
                unassignBtn.prop('disabled', false).html(origHtml);
                if (res.success) {
                    showToast(res.message, 'success');

                    var updatedSet = new Set(res.updated_ids || prodIds);
                    (app_products || []).forEach(function(p) {
                        if (updatedSet.has(p.id)) {
                            p.size_chart_template_id = '';
                            p.size_chart = '';
                        }
                    });

                    multiAssignSelectedProductIds.clear();
                    updateMultiAssignSourceCard();
                    renderMultiAssignProductsList();
                    updateMultiAssignStatusStats();
                    updateStudioCounters();
                } else {
                    showToast(res.message || 'خطا در قطع انتساب جدول.', 'error');
                }
            }, 'json').fail(function() {
                unassignBtn.prop('disabled', false).html(origHtml);
                showToast('خطای شبکه در پردازش عملیات.', 'error');
            });
        }

        function updateStudioCounters() {
            if (typeof updateSizeStudioBadgeCounters === 'function') {
                updateSizeStudioBadgeCounters();
                return;
            }
            var missingCount = 0;
            var pendingUploadCount = 0;
            (app_products || []).forEach(function(p) {
                var pChartId = p.size_chart_template_id || '';
                var hasChartImg = !!p.size_chart;
                if (!pChartId && !hasChartImg) {
                    missingCount++;
                }
                if (hasChartImg && !p.size_chart_uploaded) {
                    pendingUploadCount++;
                }
            });

            $('#badge-missing-charts-count').text(missingCount);
            $('#badge-pending-upload-count').text(pendingUploadCount);
        }

        function openMultiAssignWithChart(chartId) {
            multiAssignSelectedChartId = chartId;
            multiAssignSourceType = 'chart';
            multiAssignFilterStatus = 'all';
            $('#ma-filter-status').val('all');
            switchTab('multi_assign');
        }

        function viewCurrentSourceChartDetails() {
            var chart = getActiveSourceChart();
            if (!chart) {
                showToast('ابتدا یک جدول سایز را انتخاب نمایید.', 'error');
                return;
            }

            var modal = $('#modal-source-chart-preview');
            $('#modal-sc-title').text(chart.title || chart.product_desc || 'مشخصات جدول سایز');

            var content = $('#modal-sc-content').empty();

            content.append(`
                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200/80 flex flex-wrap gap-4 text-xs">
                    <div><span class="text-slate-400 font-bold">دسته‌بندی:</span> <strong class="text-slate-800 font-black">${escapeHtml(chart.category || '-')}</strong></div>
                    <div><span class="text-slate-400 font-bold">شرح کالا:</span> <strong class="text-slate-800 font-black">${escapeHtml(chart.product_desc || '-')}</strong></div>
                    <div><span class="text-slate-400 font-bold">نوع فاق/الگو:</span> <strong class="text-slate-800 font-black">${escapeHtml(chart.waist_type || '-')}</strong></div>
                </div>
            `);

            if (chart.columns && chart.columns.length > 0 && chart.rows && chart.rows.length > 0) {
                var firstColIsSize = (chart.columns[0].includes('سایز') || chart.columns[0].toLowerCase() === 'size');
                var hasExplicitRowSize = !firstColIsSize && chart.rows.some(function(r) { return r && r.size; });
                var tableHtml = `
                    <div class="overflow-x-auto border border-slate-200 rounded-2xl">
                        <table class="w-full text-xs text-right border-collapse" style="direction: rtl;">
                            <thead>
                                <tr class="bg-indigo-600 text-white font-black">
                `;
                if (hasExplicitRowSize) {
                    tableHtml += `<th class="p-3 border-b border-indigo-700 text-center">سایز</th>`;
                }
                chart.columns.forEach(function(col) {
                    tableHtml += `<th class="p-3 border-b border-indigo-700 text-center">${escapeHtml(col)}</th>`;
                });
                tableHtml += `
                                </tr>
                            </thead>
                            <tbody>
                `;
                chart.rows.forEach(function(row, rIdx) {
                    var bg = rIdx % 2 === 0 ? 'bg-white' : 'bg-slate-50';
                    tableHtml += `<tr class="${bg} font-bold text-slate-800 border-b border-slate-100">`;
                    if (hasExplicitRowSize) {
                        tableHtml += `<td class="p-3 font-black text-indigo-700 bg-indigo-50/40 text-center">${escapeHtml(row.size || '—')}</td>`;
                    }
                    chart.columns.forEach(function(col, cIdx) {
                        var val = row['c' + cIdx] !== undefined ? row['c' + cIdx] : (row[col] !== undefined ? row[col] : (Array.isArray(row) ? row[cIdx] : (row.values && row.values[col] !== undefined ? row.values[col] : '—')));
                        var isFirst = (cIdx === 0 && firstColIsSize);
                        var boldClass = isFirst ? 'font-black text-indigo-700 bg-indigo-50/30' : '';
                        tableHtml += `<td class="p-3 text-center ${boldClass}">${escapeHtml(val !== '' ? val : '—')}</td>`;
                    });
                    tableHtml += `</tr>`;
                });
                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                `;
                content.append(tableHtml);
            }

            if (chart.image_path) {
                content.append(`
                    <div class="pt-2">
                        <span class="text-xs font-black text-slate-700 block mb-2">تصویر رندر شده جدول سایز:</span>
                        <div class="max-h-[350px] overflow-auto rounded-2xl border border-slate-200 bg-slate-100 p-2 flex justify-center">
                            <img src="${resolveImageUrl(chart.image_path)}" class="max-h-[320px] object-contain rounded-xl shadow-xs" alt="Rendered Chart">
                        </div>
                    </div>
                `);
            }

            modal.removeClass('hidden');
        }

        function editCurrentSourceChartInStudio() {
            var chart = getActiveSourceChart();
            if (!chart) {
                showToast('ابتدا یک جدول سایز را انتخاب نمایید.', 'error');
                return;
            }
            loadChart(chart.id);
            switchTab('charts');
        }

        function openSourceChartLightbox() {
            var chart = getActiveSourceChart();
            if (chart && chart.image_path) {
                window.open(resolveImageUrl(chart.image_path), '_blank');
            } else {
                viewCurrentSourceChartDetails();
            }
        }

         function triggerLinkProduct(prodId) {
             var chartId = $(`#sel-linker-${prodId}`).val();
             if (!chartId) {
                 showToast('لطفا ابتدا یک جدول سایз را جهت اتصال انتخاب نمایید.', 'error');
                 return;
             }
             linkProductAndChart(prodId, chartId);
         }

         function requestManualChartFromStudio(prodId) {
             showToast('در حال ثبت درخواست جدول دستی...', 'info');
             $.post('', {
                 action: 'request_manual_size_chart',
                 product_id: prodId
             }, function(res) {
                 if (res.success) {
                     showToast('درخواست ساخت جدول سایز دستی با موفقیت ثبت شد.', 'success');
                     // Update local cache
                     app_products.forEach(function(p) {
                         if (p.id === prodId) {
                             p.manual_size_chart_requested = 1;
                         }
                     });
                     populateLinkerUI();
                 } else {
                     showToast(res.message || 'ثبت درخواست با خطا مواجه شد.', 'error');
                 }
             });
         }

         function linkProductAndChart(prodId, chartId) {
             showToast('در حال ثبت اتصال محصول...', 'info');
             $.post('', {
                 action: 'td_scs_link_product_chart',
                 product_id: prodId,
                 chart_id: chartId
             }, function(res) {
                 if (res.success) {
                     showToast('اتصال جدول سایزبندی کالا با موفقیت به روزرسانی شد.', 'success');

                     // Look up baseline name & update local cache
                     var p_obj = app_products.find(function(x) { return x.id === prodId; });
                     if (p_obj) {
                         var baseName = getProductBaseName(p_obj.name);
                         app_products.forEach(function(p) {
                             var p_base = getProductBaseName(p.name);
                             if (p.id === prodId || p_base.toLowerCase() === baseName.toLowerCase()) {
                                 p.size_chart_template_id = chartId;
                             }
                         });
                     }
                     populateLinkerUI();
                 } else {
                     showToast(res.message || 'ثبت اتصال کالا با شکست مواجه شد.', 'error');
                 }
             });
         }

         function submitBulkImportJson() {
             var input = $('#json-import-textarea').val().trim();
             if (!input) {
                 showToast('محتوای فیلد JSON نمی‌تواند خالی باشد.', 'error');
                 return;
             }

             showToast('در حال درون‌ریزی گروهی اطلاعات...', 'info');
             var parsed = null;
             try {
                 parsed = JSON.parse(input);
             } catch (e) {
                 showToast('قالب بندی وارد شده غیر مجاز است. قالب فایل باید به صورت JSON معتبر باشد.', 'error');
                 return;
             }

             $.post('', {
                 action: 'td_scs_import_charts_json',
                 json_data: JSON.stringify(parsed)
             }, function(res) {
                 if (res.success) {
                     showToast(`درون ریزی با موفقیت انجام شد و تعداد ${res.imported_count} جدول ساخته شد.`, 'success');
                     $('#import-json-modal').addClass('hidden');
                     $('#json-import-textarea').val('');
                     loadChartsList(); // Reload sets
                    loadPresets();
                    loadDescPresets();
                 } else {
                     showToast(res.message || 'عملیات با خطا مواجه شد.', 'error');
                 }
             });
         }

         // =========================================================================
         // 📐 Missing Size Charts & ☁️ Pending Upload Size Charts UI Engine
         // =========================================================================

         var targetDirectUploadProductId = null;

         function isProductMissingSizeChart(p) {
             if (!p) return false;
             
             // 1. Check template ID: must be non-empty and not '0', 'null', 'none', etc.
             var tId = p.size_chart_template_id;
             var hasTemplate = false;
             if (tId !== undefined && tId !== null) {
                 var strTId = String(tId).trim();
                 if (strTId !== '' && strTId !== '0' && strTId !== 'null' && strTId !== 'undefined' && strTId !== 'none') {
                     hasTemplate = true;
                 }
             }

             // 2. Check custom chart: size_chart or sizeChart
             var chart = p.size_chart || p.sizeChart || '';
             var hasCustomChart = false;
             if (typeof chart === 'string') {
                 var strChart = chart.trim();
                 if (strChart !== '' && strChart !== '0' && strChart !== 'null' && strChart !== 'undefined' && strChart !== '{}' && strChart !== '[]' && strChart.indexOf('placehold') === -1) {
                     hasCustomChart = true;
                 }
             } else if (typeof chart === 'object' && chart !== null) {
                 if (Array.isArray(chart) && chart.length > 0) hasCustomChart = true;
                 else if (!Array.isArray(chart) && Object.keys(chart).length > 0) hasCustomChart = true;
             }

             return !hasTemplate && !hasCustomChart;
         }

         function isProductSizeChartPendingUpload(p) {
             if (!p) return false;
             var hasChart = !isProductMissingSizeChart(p);
             var isUploaded = p.size_chart_uploaded === true || p.size_chart_uploaded === 1 || p.size_chart_uploaded === '1' || p.size_chart_uploaded === 'true';
             return hasChart && !isUploaded;
         }

         function getProductItemStockJS(p) {
             if (!p) return 0;
             if (p.status === 'out_of_stock') return 0;

             // 1. Check variants
             if (p.variants) {
                 var vars = p.variants;
                 if (typeof vars === 'string') {
                     try { vars = JSON.parse(vars); } catch(e) { vars = null; }
                 }
                 if (Array.isArray(vars) && vars.length > 0) {
                     var vSum = 0;
                     var hasV = false;
                     vars.forEach(function(v) {
                         var rawV = v.stock || v.stock_online || v.stock_total || v.quantity || v.count;
                         if (rawV !== undefined && rawV !== null && rawV !== '') {
                             var normV = String(rawV).replace(/[۰-۹]/g, function(d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                                                     .replace(/[٠-٩]/g, function(d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
                             var numV = parseFloat(normV.replace(/[^0-9.-]/g, ''));
                             if (!isNaN(numV) && numV > 0) {
                                 vSum += numV;
                                 hasV = true;
                             }
                         }
                     });
                     if (hasV && vSum > 0) return Math.floor(vSum);
                 }
             }

             // 2. Check warehouses
             if (p.warehouses && typeof p.warehouses === 'object') {
                 var wSum = 0;
                 var hasW = false;
                 Object.values(p.warehouses).forEach(function(w) {
                     var wQty = parseInt(w, 10);
                     if (!isNaN(wQty) && wQty > 0) {
                         wSum += wQty;
                         hasW = true;
                     }
                 });
                 if (hasW && wSum > 0) return wSum;
             }

             // 3. Check direct fields
             var candidates = [p.stock_total, p.stock_online, p.stock, p.quantity, p.count, p.stock_quantity];
             for (var i = 0; i < candidates.length; i++) {
                 var val = candidates[i];
                 if (val !== undefined && val !== null && String(val).trim() !== '') {
                     var sVal = String(val).trim();
                     if (sVal.indexOf('ناموجود') !== -1) return 0;
                     var norm = sVal.replace(/[۰-۹]/g, function(d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                                    .replace(/[٠-٩]/g, function(d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
                     var num = parseFloat(norm.replace(/[^0-9.-]/g, ''));
                     if (!isNaN(num)) return Math.floor(num);
                 }
             }

             return 0;
         }

         function updateSizeStudioBadgeCounters() {
             if (!Array.isArray(app_products)) return;
             var missingCount = 0;
             var pendingCount = 0;
             app_products.forEach(function(p) {
                 if (isProductMissingSizeChart(p)) missingCount++;
                 else if (isProductSizeChartPendingUpload(p)) pendingCount++;
             });
             $('#badge-missing-charts-count').text(missingCount);
             $('#badge-pending-upload-count').text(pendingCount);
         }

         // --- Missing Size Charts Tab Rendering ---

         function renderMissingChartsTab() {
             if (!Array.isArray(app_products)) return;

             // Populate category options
             var catSelect = $('#mc-category-filter');
             var currentCat = catSelect.val();
             catSelect.empty().append('<option value="">همه دسته‌بندی‌ها</option>');

             var catMap = {};
             app_products.forEach(function(p) {
                 if (isProductMissingSizeChart(p) && p.category && String(p.category).trim() !== '') {
                     catMap[p.category.trim()] = true;
                 }
             });
             Object.keys(catMap).sort().forEach(function(c) {
                 catSelect.append(`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`);
             });
             if (currentCat) catSelect.val(currentCat);

             filterMissingChartsList();
         }

         function resetMissingChartsFilters() {
             $('#mc-search-input').val('');
             $('#mc-category-filter').val('');
             $('#mc-stock-filter').val('all');
             filterMissingChartsList();
         }

         function filterMissingChartsList() {
             if (!Array.isArray(app_products)) return;

             var q = ($('#mc-search-input').val() || '').toLowerCase().trim();
             var cat = $('#mc-category-filter').val() || '';
             var stock = $('#mc-stock-filter').val() || 'all';

             var container = $('#missing-charts-grid');
             container.empty();

             var list = app_products.filter(function(p) {
                 if (!isProductMissingSizeChart(p)) return false;

                 if (cat && String(p.category || '').trim() !== cat.trim()) return false;

                 var stockQty = getProductItemStockJS(p);
                 var hasStockField = (p.stock_total !== undefined && p.stock_total !== '') ||
                                     (p.stock_online !== undefined && p.stock_online !== '') ||
                                     (p.stock !== undefined && p.stock !== '') ||
                                     (p.quantity !== undefined && p.quantity !== '');
                 var isOutOfStock = (p.status === 'out_of_stock') || (hasStockField && stockQty <= 0);

                 if (stock === 'in_stock') {
                     if (isOutOfStock) return false;
                 } else if (stock === 'out_of_stock') {
                     if (!isOutOfStock) return false;
                 }

                 if (q) {
                     var name = (p.name || p.title || '').toLowerCase();
                     var sku = (p.sku || '').toLowerCase();
                     var color = (p.color || '').toLowerCase();
                     var category = (p.category || '').toLowerCase();
                     if (name.indexOf(q) === -1 && sku.indexOf(q) === -1 && color.indexOf(q) === -1 && category.indexOf(q) === -1) {
                         return false;
                     }
                 }
                 return true;
             });

             if (list.length === 0) {
                 var totalMissing = app_products.filter(isProductMissingSizeChart).length;
                 var emptyMsg = totalMissing > 0
                     ? `<h3 class="text-sm font-black text-slate-800">هیچ محصولی با فیلترهای انتخابی یافت نشد!</h3>
                        <p class="text-xs text-slate-500 mt-1">از میان ${totalMissing} محصول فاقد جدول سایز، موردی با فیلتر جستجو، دسته‌بندی یا موجودی فعلی همخوانی ندارد.</p>
                        <button type="button" onclick="resetMissingChartsFilters()" class="mt-3 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-slate-950 rounded-xl text-xs font-black transition cursor-pointer shadow-sm inline-flex items-center gap-1.5">
                            <span>🔄</span>
                            <span>پاک کردن فیلترها و نمایش تمام ${totalMissing} کالا</span>
                        </button>`
                     : `<h3 class="text-sm font-black text-slate-800">تمامی محصولات دارای جدول سایز هستند!</h3>
                        <p class="text-xs text-slate-400 mt-1">هیچ محصول بدون جدولی در این پروژه یافت نشد.</p>`;
                 container.html(`
                     <div class="col-span-full bg-white rounded-3xl p-12 text-center border border-slate-100 shadow-xs space-y-4">
                         <div class="w-16 h-16 ${totalMissing > 0 ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600'} rounded-2xl flex items-center justify-center text-3xl mx-auto shadow-sm">
                             ${totalMissing > 0 ? '🔍' : '✨'}
                         </div>
                         <div>${emptyMsg}</div>
                     </div>
                 `);
                 $('#mc-select-all').prop('checked', false);
                 updateMissingChartsSelection();
                 return;
             }

             list.forEach(function(p) {
                 var pId = p.id || '';
                 var pName = p.name || p.title || 'بدون نام';
                 var pSku = p.sku || 'بدون کد';
                 var pColor = p.color || '';
                 var pCat = p.category || 'دسته‌بندی نشده';
                 var pStock = getProductItemStockJS(p);
                 var hasStockField = (p.stock_total !== undefined && p.stock_total !== '') ||
                                     (p.stock_online !== undefined && p.stock_online !== '') ||
                                     (p.stock !== undefined && p.stock !== '') ||
                                     (p.quantity !== undefined && p.quantity !== '');
                 var isOut = (p.status === 'out_of_stock') || (hasStockField && pStock <= 0);

                 var pImg = p.image ? resolveImageUrl(p.image) : 
                            (p.social_image ? resolveImageUrl(p.social_image) : 
                            ((p.images && p.images[0]) ? resolveImageUrl(p.images[0]) : ''));

                 var stockBadge = '';
                 if (isOut) {
                     stockBadge = `<span class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-100 rounded-md text-[10px] font-black">ناموجود</span>`;
                 } else if (pStock > 0) {
                     stockBadge = `<span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-100 rounded-md text-[10px] font-black">${pStock} عدد</span>`;
                 } else {
                     stockBadge = `<span class="px-2 py-0.5 bg-slate-100 text-slate-600 border border-slate-200/80 rounded-md text-[10px] font-black">موجودی ثبت‌نشده</span>`;
                 }

                 var cardHtml = `
                     <div class="bg-white rounded-3xl p-4 shadow-sm border border-slate-100 hover:border-amber-300/80 transition-all flex flex-col justify-between gap-4 group">
                         <div class="space-y-3">
                             <!-- Top Row: Checkbox, SKU, Color -->
                             <div class="flex items-center justify-between gap-2">
                                 <label class="flex items-center gap-2 cursor-pointer">
                                     <input type="checkbox" class="mc-item-check w-4 h-4 text-amber-600 rounded border-slate-300" data-id="${pId}" onchange="updateMissingChartsSelection()">
                                     <span class="text-[11px] font-black text-slate-500 font-mono">${escapeHtml(pSku)}</span>
                                 </label>
                                 ${pColor ? `<span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md text-[10px] font-black">${escapeHtml(pColor)}</span>` : ''}
                             </div>

                             <!-- Image & Details -->
                             <div class="flex gap-3">
                                 <div class="w-16 h-16 rounded-2xl bg-slate-100 border border-slate-200/80 overflow-hidden shrink-0 flex items-center justify-center">
                                     ${pImg ? `<img src="${escapeHtml(pImg)}" alt="${escapeHtml(pName)}" class="w-full h-full object-cover">` : `<span class="text-2xl text-slate-300">👕</span>`}
                                 </div>
                                 <div class="flex-1 min-w-0 space-y-1">
                                     <h4 class="text-xs font-black text-slate-800 leading-snug line-clamp-2" title="${escapeHtml(pName)}">${escapeHtml(pName)}</h4>
                                     <div class="text-[10px] text-slate-400 font-bold">${escapeHtml(pCat)}</div>
                                     <div class="pt-0.5 flex items-center gap-1.5">
                                         ${stockBadge}
                                         <span class="px-2 py-0.5 bg-amber-50 text-amber-800 border border-amber-200/80 rounded-md text-[9px] font-black">فاقد جدول سایز</span>
                                     </div>
                                 </div>
                             </div>
                         </div>

                         <!-- Action Buttons Grid -->
                         <div class="space-y-1.5 pt-3 border-t border-slate-100">
                             <div class="grid grid-cols-2 gap-1.5">
                                 <button type="button" onclick="openQuickBatchForProduct('${escapeHtml(pSku)}', '${escapeHtml(pName)}')" class="px-2.5 py-2 bg-amber-500 hover:bg-amber-600 text-slate-950 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1 shadow-xs">
                                     <span>⚡</span>
                                     <span>ساخت سریع</span>
                                 </button>
                                 <button type="button" onclick="openSingleProductLinkModal('${pId}')" class="px-2.5 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1">
                                     <span>🔗</span>
                                     <span>اتصال جدول</span>
                                 </button>
                             </div>
                             <div class="grid grid-cols-2 gap-1.5">
                                 <button type="button" onclick="triggerDirectChartUpload('${pId}')" class="px-2.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1">
                                     <span>📤</span>
                                     <span>آپلود تصویر</span>
                                 </button>
                                 <button type="button" onclick="openChartStudioForProduct('${pId}')" class="px-2.5 py-2 bg-slate-50 hover:bg-slate-100 text-slate-600 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1">
                                     <span>🎨</span>
                                     <span>استودیو</span>
                                 </button>
                             </div>
                         </div>
                     </div>
                 `;
                 container.append(cardHtml);
             });

             updateMissingChartsSelection();
         }

         function toggleSelectAllMissingCharts(checked) {
             $('.mc-item-check').prop('checked', checked);
             updateMissingChartsSelection();
         }

         function updateMissingChartsSelection() {
             var count = $('.mc-item-check:checked').length;
             $('#mc-selected-count').text(count);
             if (count > 0) {
                 $('#btn-mc-bulk-link').removeClass('hidden');
             } else {
                 $('#btn-mc-bulk-link').addClass('hidden');
             }
         }

         function openSingleProductLinkModal(prodId) {
             $('.mc-item-check').prop('checked', false);
             $(`.mc-item-check[data-id="${prodId}"]`).prop('checked', true);
             updateMissingChartsSelection();
             openBulkLinkModal();
         }

         function openBulkLinkModal() {
             var checkedCount = $('.mc-item-check:checked').length;
             if (checkedCount === 0) {
                 showToast('لطفاً حداقل یک محصول را انتخاب نمایید.', 'error');
                 return;
             }

             $('#modal-bulk-link-count').text(checkedCount);

             var select = $('#modal-bulk-link-chart-select');
             select.empty();

             if (!Array.isArray(app_charts) || app_charts.length === 0) {
                 select.append('<option value="">(هیچ جدول سایزی طراحی نشده است - ابتدا یک جدول بسازید)</option>');
             } else {
                 app_charts.forEach(function(c) {
                     var cTitle = c.title || c.product_desc || 'جدول بدون نام';
                     select.append(`<option value="${c.id}">${escapeHtml(cTitle)}</option>`);
                 });
             }

             $('#bulk-link-modal').removeClass('hidden');
         }

         function executeBulkLinkToChart() {
             var chartId = $('#modal-bulk-link-chart-select').val();
             if (!chartId) {
                 showToast('لطفاً یک جدول سایز انتخاب کنید.', 'error');
                 return;
             }

             var checkedIds = [];
             $('.mc-item-check:checked').each(function() {
                 checkedIds.push($(this).data('id'));
             });

             if (checkedIds.length === 0) {
                 showToast('محصولی انتخاب نشده است.', 'error');
                 return;
             }

             showToast('در حال انتساب جدول سایز به محصولات...', 'info');

             $.post('', {
                 action: 'td_scs_bulk_link_chart',
                 chart_id: chartId,
                 product_ids: checkedIds
             }, function(res) {
                 if (res && res.success) {
                     showToast(res.message || 'جدول سایز با موفقیت به محصولات متصل شد.', 'success');
                     $('#bulk-link-modal').addClass('hidden');

                     // Update in app_products
                     var chartObj = app_charts.find(c => c.id === chartId);
                     var chartImg = chartObj ? (chartObj.image_path || '') : '';

                     app_products.forEach(function(p) {
                         if (checkedIds.includes(p.id)) {
                             p.size_chart_template_id = chartId;
                             if (chartImg) p.size_chart = chartImg;
                             p.size_chart_uploaded = false;
                             p.waiting_final_photo = true;
                             p.status = 'pending';
                         }
                     });

                     updateSizeStudioBadgeCounters();
                     renderMissingChartsTab();
                 } else {
                     showToast((res && res.message) ? res.message : 'خطا در اعمال اتصال.', 'error');
                 }
             }, 'json');
         }

         function triggerDirectChartUpload(productId) {
             targetDirectUploadProductId = productId;
             $('#mc-direct-upload-input').val('').click();
         }

         function handleDirectChartUploadFile(input) {
             if (!input.files || input.files.length === 0 || !targetDirectUploadProductId) return;

             var file = input.files[0];
             var formData = new FormData();
             formData.append('action', 'td_scs_direct_upload_chart');
             formData.append('product_id', targetDirectUploadProductId);
             formData.append('file', file);

             showToast('در حال بارگذاری تصویر جدول سایز...', 'info');

             $.ajax({
                 url: '',
                 type: 'POST',
                 data: formData,
                 processData: false,
                 contentType: false,
                 dataType: 'json',
                 success: function(res) {
                     if (res && res.success) {
                         showToast('تصویر جدول سایز بارگذاری شد و محصول به «در انتظار عکس نهایی» منتقل گردید.', 'success');
                         
                         // Update product in app_products
                         app_products.forEach(function(p) {
                             if (p.id === targetDirectUploadProductId) {
                                 p.size_chart = res.image_url;
                                 p.size_chart_uploaded = false;
                                 p.waiting_final_photo = true;
                                 p.status = 'pending';
                             }
                         });

                         updateSizeStudioBadgeCounters();
                         renderMissingChartsTab();
                     } else {
                         showToast((res && res.message) ? res.message : 'خطا در بارگذاری تصویر.', 'error');
                     }
                 },
                 error: function() {
                     showToast('خطا در برقراری ارتباط با سرور.', 'error');
                 }
             });
         }

         function openQuickBatchForProduct(sku, name) {
             switchTab('quick_batch');
             var sampleJson = [{
                 title: sku ? sku : name,
                 category: "پوشاک",
                 columns: ["سایز", "قد", "دور سینه", "سرشانه"],
                 rows: [["L", "72", "104", "46"], ["XL", "74", "110", "48"]]
             }];
             $('#quick-batch-json-raw').val(JSON.stringify(sampleJson, null, 2));
             showToast(`کد ${sku || name} در ساخت سریع آماده شد. می‌توانید داده‌ها را وارد کرده و پردازش کنید.`, 'info');
         }

         function openChartStudioForProduct(productId) {
             var p = app_products.find(x => x.id === productId);
             if (p) {
                 selectProductForLivePreview(productId);
             }
             switchTab('charts');
         }

         // --- Pending Upload Size Charts Tab Rendering ---

         function renderPendingUploadTab() {
             if (!Array.isArray(app_products)) return;

             // Populate category options
             var catSelect = $('#pu-category-filter');
             var currentCat = catSelect.val();
             catSelect.empty().append('<option value="">همه دسته‌بندی‌ها</option>');

             var catMap = {};
             app_products.forEach(function(p) {
                 if (isProductSizeChartPendingUpload(p) && p.category && String(p.category).trim() !== '') {
                     catMap[p.category.trim()] = true;
                 }
             });
             Object.keys(catMap).sort().forEach(function(c) {
                 catSelect.append(`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`);
             });
             if (currentCat) catSelect.val(currentCat);

             filterPendingUploadList();
         }

         function filterPendingUploadList() {
             if (!Array.isArray(app_products)) return;

             var q = ($('#pu-search-input').val() || '').toLowerCase().trim();
             var cat = $('#pu-category-filter').val() || '';

             var container = $('#pending-upload-grid');
             container.empty();

             var list = app_products.filter(function(p) {
                 if (!isProductSizeChartPendingUpload(p)) return false;
                 if (cat && String(p.category || '').trim() !== cat.trim()) return false;

                 if (q) {
                     var name = (p.name || p.title || '').toLowerCase();
                     var sku = (p.sku || '').toLowerCase();
                     var color = (p.color || '').toLowerCase();
                     var category = (p.category || '').toLowerCase();
                     if (name.indexOf(q) === -1 && sku.indexOf(q) === -1 && color.indexOf(q) === -1 && category.indexOf(q) === -1) {
                         return false;
                     }
                 }
                 return true;
             });

             if (list.length === 0) {
                 container.html(`
                     <div class="col-span-full bg-white rounded-3xl p-12 text-center border border-slate-100 shadow-xs space-y-4">
                         <div class="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center text-3xl mx-auto shadow-sm">
                             ☁️
                         </div>
                         <div>
                             <h3 class="text-sm font-black text-slate-800">هیچ جدول سایزی در انتظار آپلود نیست!</h3>
                             <p class="text-xs text-slate-400 mt-1">تمامی جدول‌های سایز طراحی شده در وب‌سایت بارگذاری شده‌اند یا فیلتر را پاک نمایید.</p>
                         </div>
                     </div>
                 `);
                 $('#pu-select-all').prop('checked', false);
                 updatePendingUploadSelection();
                 return;
             }

             list.forEach(function(p) {
                 var pId = p.id || '';
                 var pName = p.name || p.title || 'بدون نام';
                 var pSku = p.sku || 'بدون کد';
                 var pColor = p.color || '';
                 var pCat = p.category || 'دسته‌بندی نشده';
                 var pStock = parseInt(p.stock || p.quantity || 0, 10);

                 // Find chart image
                 var chartImg = p.size_chart || '';
                 if (!chartImg && p.size_chart_template_id) {
                     var ch = app_charts.find(c => c.id === p.size_chart_template_id);
                     if (ch && ch.image_path) chartImg = ch.image_path;
                 }
                 var chartImgUrl = chartImg ? resolveImageUrl(chartImg) : '';

                 var cardHtml = `
                     <div class="bg-white rounded-3xl p-4 shadow-sm border border-slate-100 hover:border-indigo-300/80 transition-all flex flex-col justify-between gap-4 group">
                         <div class="space-y-3">
                             <!-- Top Row: Checkbox, SKU, Color -->
                             <div class="flex items-center justify-between gap-2">
                                 <label class="flex items-center gap-2 cursor-pointer">
                                     <input type="checkbox" class="pu-item-check w-4 h-4 text-indigo-600 rounded border-slate-300" data-id="${pId}" data-img="${escapeHtml(chartImgUrl)}" onchange="updatePendingUploadSelection()">
                                     <span class="text-[11px] font-black text-slate-500 font-mono">${escapeHtml(pSku)}</span>
                                 </label>
                                 ${pColor ? `<span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md text-[10px] font-black">${escapeHtml(pColor)}</span>` : ''}
                             </div>

                             <!-- Size Chart Thumbnail & Details -->
                             <div class="flex gap-3">
                                 <div class="w-16 h-20 rounded-2xl bg-slate-50 border border-slate-200/80 overflow-hidden shrink-0 flex items-center justify-center cursor-pointer" onclick="window.open('${escapeHtml(chartImgUrl || '#')}', '_blank')">
                                     ${chartImgUrl ? `<img src="${escapeHtml(chartImgUrl)}" alt="جدول سایز" class="w-full h-full object-cover">` : `<span class="text-2xl text-slate-300">📐</span>`}
                                 </div>
                                 <div class="flex-1 min-w-0 space-y-1">
                                     <h4 class="text-xs font-black text-slate-800 leading-snug line-clamp-2" title="${escapeHtml(pName)}">${escapeHtml(pName)}</h4>
                                     <div class="text-[10px] text-slate-400 font-bold">${escapeHtml(pCat)}</div>
                                     <div class="pt-0.5">
                                         <span class="px-2 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200/80 rounded-md text-[9px] font-black">⏳ در انتظار بارگذاری در سایت</span>
                                     </div>
                                 </div>
                             </div>
                         </div>

                         <!-- Action Buttons Grid -->
                         <div class="space-y-1.5 pt-3 border-t border-slate-100">
                             <button type="button" onclick="toggleProductSizeChartUploaded('${pId}')" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black rounded-xl transition flex items-center justify-center gap-1.5 shadow-xs">
                                 <span>✓</span>
                                 <span>ثبت به عنوان آپلود شد</span>
                             </button>
                             <div class="grid grid-cols-2 gap-1.5">
                                 <button type="button" onclick="downloadProductChartImage('${pId}', '${escapeHtml(chartImgUrl)}', '${escapeHtml(pName)}', '${escapeHtml(pSku)}', '${escapeHtml(pColor)}')" class="px-2.5 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1">
                                     <span>📥</span>
                                     <span>دانلود تصویر</span>
                                 </button>
                                 <button type="button" onclick="openChartStudioForProduct('${pId}')" class="px-2.5 py-2 bg-slate-50 hover:bg-slate-100 text-slate-600 text-[10px] font-black rounded-xl transition flex items-center justify-center gap-1">
                                     <span>✏️</span>
                                     <span>استودیو</span>
                                 </button>
                             </div>
                         </div>
                     </div>
                 `;
                 container.append(cardHtml);
             });

             updatePendingUploadSelection();
         }

         function toggleSelectAllPendingUpload(checked) {
             $('.pu-item-check').prop('checked', checked);
             updatePendingUploadSelection();
         }

         function updatePendingUploadSelection() {
             var count = $('.pu-item-check:checked').length;
             $('#pu-selected-count').text(count);
             if (count > 0) {
                 $('#btn-pu-mark-selected').removeClass('hidden');
                 $('#btn-pu-download-selected-zip').removeClass('hidden');
             } else {
                 $('#btn-pu-mark-selected').addClass('hidden');
                 $('#btn-pu-download-selected-zip').addClass('hidden');
             }
         }

         function toggleProductSizeChartUploaded(productId) {
             showToast('در حال تغییر وضعیت آپلود...', 'info');

             $.post('', {
                 action: 'td_scs_toggle_uploaded',
                 product_id: String(productId),
                 mark_uploaded: 1
             }, function(res) {
                 if (res && res.success) {
                     showToast(res.message || 'وضعیت آپلود با موفقیت به‌روزرسانی شد.', 'success');

                     var newStatus = (res.size_chart_uploaded !== undefined)
                         ? Boolean(res.size_chart_uploaded)
                         : ((res.is_uploaded !== undefined) ? Boolean(res.is_uploaded) : true);

                     var updatedSet = new Set((Array.isArray(res.updated_ids) && res.updated_ids.length > 0)
                         ? res.updated_ids.map(String)
                         : [String(productId)]);

                     (app_products || []).forEach(function(p) {
                         if (updatedSet.has(String(p.id)) || (p.sku && updatedSet.has(String(p.sku)))) {
                             p.size_chart_uploaded = newStatus;
                             if (newStatus) {
                                 p.size_chart_uploaded_at = new Date().toISOString();
                             }
                         }
                     });

                     updateSizeStudioBadgeCounters();
                     renderPendingUploadTab();
                     if (typeof renderMultiAssignProductsList === 'function') {
                         renderMultiAssignProductsList();
                     }
                 } else {
                     showToast((res && res.message) ? res.message : 'خطا در ثبت وضعیت.', 'error');
                 }
             }, 'json').fail(function() {
                 showToast('خطای ارتباط با سرور در ثبت وضعیت آپلود.', 'error');
             });
         }

         function bulkMarkSelectedPendingUploaded() {
             var checkedIds = [];
             $('.pu-item-check:checked').each(function() {
                 checkedIds.push(String($(this).data('id')));
             });

             if (checkedIds.length === 0) {
                 showToast('هیچ محصولی انتخاب نشده است.', 'error');
                 return;
             }

             showToast(`در حال علامت‌گذاری ${checkedIds.length} مورد به عنوان آپلود شده...`, 'info');

             $.post('', {
                 action: 'td_scs_bulk_mark_uploaded',
                 product_ids: checkedIds,
                 mark_uploaded: 1
             }, function(res) {
                 if (res && res.success) {
                     showToast(res.message || 'موارد انتخابی با موفقیت علامت‌گذاری شدند.', 'success');

                     var updatedSet = new Set((Array.isArray(res.updated_ids) && res.updated_ids.length > 0)
                         ? res.updated_ids.map(String)
                         : checkedIds.map(String));

                     (app_products || []).forEach(function(p) {
                         if (updatedSet.has(String(p.id)) || (p.sku && updatedSet.has(String(p.sku)))) {
                             p.size_chart_uploaded = true;
                             p.size_chart_uploaded_at = new Date().toISOString();
                         }
                     });

                     updateSizeStudioBadgeCounters();
                     renderPendingUploadTab();
                     if (typeof renderMultiAssignProductsList === 'function') {
                         renderMultiAssignProductsList();
                     }
                 } else {
                     showToast((res && res.message) ? res.message : 'خطا در ثبت وضعیت.', 'error');
                 }
             }, 'json').fail(function() {
                 showToast('خطای ارتباط با سرور در ثبت وضعیت.', 'error');
             });
         }

         function bulkMarkAllPendingUploaded() {
             var pendingIds = [];
             (app_products || []).forEach(function(p) {
                 if (isProductSizeChartPendingUpload(p)) {
                     pendingIds.push(String(p.id));
                 }
             });

             if (pendingIds.length === 0) {
                 showToast('هیچ جدول سایزی در انتظار آپلود وجود ندارد.', 'info');
                 return;
             }

             if (!confirm(`آیا مطمئن هستید که می‌خواهید تمام ${pendingIds.length} جدول سایز آماده را به عنوان «آپلود شده در وب‌سایت» علامت بزنید؟`)) {
                 return;
             }

             showToast('در حال ثبت وضعیت تمامی جداول...', 'info');

             $.post('', {
                 action: 'td_scs_bulk_mark_uploaded',
                 product_ids: pendingIds,
                 mark_uploaded: 1
             }, function(res) {
                 if (res && res.success) {
                     showToast(res.message || 'تمامی موارد به عنوان آپلود شده ثبت شدند.', 'success');

                     var updatedSet = new Set((Array.isArray(res.updated_ids) && res.updated_ids.length > 0)
                         ? res.updated_ids.map(String)
                         : pendingIds.map(String));

                     (app_products || []).forEach(function(p) {
                         if (updatedSet.has(String(p.id)) || (p.sku && updatedSet.has(String(p.sku))) || isProductSizeChartPendingUpload(p)) {
                             p.size_chart_uploaded = true;
                             p.size_chart_uploaded_at = new Date().toISOString();
                         }
                     });

                     updateSizeStudioBadgeCounters();
                     renderPendingUploadTab();
                     if (typeof renderMultiAssignProductsList === 'function') {
                         renderMultiAssignProductsList();
                     }
                 } else {
                     showToast((res && res.message) ? res.message : 'خطا در ثبت وضعیت.', 'error');
                 }
             }, 'json').fail(function() {
                 showToast('خطای ارتباط با سرور در ثبت وضعیت.', 'error');
             });
         }

         function downloadProductChartImage(productId, imgUrl, title, sku, color) {
             if (!imgUrl) {
                 showToast('تصویر جدول سایز یافت نشد.', 'error');
                 return;
             }

             var filename = `${sku || productId} - ${title || 'محصول'} - ${color || ''} - size_chart.jpg`.replace(/[/\\?%*:|"<>]/g, '-').replace(/\s+/g, ' ').trim();
             
             var a = document.createElement('a');
             a.href = imgUrl;
             a.download = filename;
             a.target = '_blank';
             document.body.appendChild(a);
             a.click();
             document.body.removeChild(a);
         }

         async function downloadSelectedPendingZip() {
             var items = [];
             $('.pu-item-check:checked').each(function() {
                 var pId = $(this).data('id');
                 var p = app_products.find(x => x.id === pId);
                 if (p) items.push(p);
             });

             if (items.length === 0) {
                 showToast('هیچ موردی برای دانلود انتخاب نشده است.', 'error');
                 return;
             }

             await bundleAndDownloadSizeChartsZip(items, 'selected_size_charts.zip');
         }

         async function downloadAllPendingSizeChartsZip() {
             var items = app_products.filter(isProductSizeChartPendingUpload);
             if (items.length === 0) {
                 showToast('هیچ جدول سایزی در انتظار آپلود وجود ندارد.', 'info');
                 return;
             }

             await bundleAndDownloadSizeChartsZip(items, 'all_pending_size_charts.zip');
         }

         async function bundleAndDownloadSizeChartsZip(productsList, zipFilename) {
             if (typeof JSZip === 'undefined' || typeof saveAs === 'undefined') {
                 showToast('کتابخانه ساخت فایل زیپ بارگذاری نشده است.', 'error');
                 return;
             }

             showToast('در حال ساخت فایل فشرده ZIP جدول‌های سایز...', 'info');
             var zip = new JSZip();
             var count = 0;

             for (var i = 0; i < productsList.length; i++) {
                 var p = productsList[i];
                 var chartImg = p.size_chart || '';
                 if (!chartImg && p.size_chart_template_id) {
                     var ch = app_charts.find(c => c.id === p.size_chart_template_id);
                     if (ch && ch.image_path) chartImg = ch.image_path;
                 }

                 if (!chartImg) continue;

                 var fullUrl = resolveImageUrl(chartImg);
                 var safeName = `${p.sku || p.id} - ${p.name || ''} - ${p.color || ''} - size_chart.jpg`.replace(/[/\\?%*:|"<>]/g, '-').replace(/\s+/g, ' ').trim();

                 try {
                     var resp = await fetch(fullUrl);
                     if (resp.ok) {
                         var blob = await resp.blob();
                         zip.file(safeName, blob);
                         count++;
                     }
                 } catch (e) {
                     console.warn('Error fetching chart image for zip:', fullUrl, e);
                 }
             }

             if (count === 0) {
                 showToast('هیچ تصویری برای دانلود در فایل زیپ یافت نشد.', 'error');
                 return;
             }

             var content = await zip.generateAsync({ type: 'blob' });
             saveAs(content, zipFilename);
             showToast(`فایل زیپ شامل ${count} جدول سایز با موفقیت ساخته و دانلود شد.`, 'success');
         }

         function escapeHtml(str) {
             if (!str) return '';
             return String(str)
                 .replace(/&/g, '&amp;')
                 .replace(/</g, '&lt;')
                 .replace(/>/g, '&gt;')
                 .replace(/"/g, '&quot;')
                 .replace(/'/g, '&#039;');
         }

         // =========================================================================
         // ⚡ Quick Batch Size Chart Studio Engine (ساخت جدول سایز سریع با اکسل و JSON)
         // =========================================================================

         var quickSelectedFile = null;
         var quickBatchCharts = [];
         var quickIsRendering = false;

         // Helper to safely clean string/cell values
         function cleanCellVal(v) {
             if (v === null || v === undefined) return '';
             var s = String(v).trim();
             return s.replace(/\u00A0/g, ' ');
         }

         // 1. Download Persian Sample JSON
         function downloadSampleJsonTemplate() {
             var sampleData = [
                 {
                     "title": "شلوار کتان بهاره راسته یاراش",
                     "product_desc": "شلوار کتان کشی مردانه طرح اسپرت",
                     "category": "شلوار",
                     "model": "راسته کلاسیک",
                     "waist_type": "کمر دکمه و زیپ",
                     "product_notes": "برای انتخاب دقیق سایز، لطفاً اندازه کمر و قد شلوار فعلی خود را با جدول تطبیق دهید. خطای دوخت ۱ تا ۲ سانتی‌متر نرمال است.",
                     "columns": ["سایز محصول", "قد شلوار", "1/2دور کمر", "1/2دور ران", "دمپا"],
                     "rows": [
                         {"c0": "M (31)", "c1": "100", "c2": "38", "c3": "29", "c4": "18"},
                         {"c0": "L (32)", "c1": "102", "c2": "40", "c3": "30", "c4": "19"},
                         {"c0": "XL (34)", "c1": "104", "c2": "42", "c3": "31", "c4": "20"},
                         {"c0": "2XL (36)", "c1": "106", "c2": "44", "c3": "32", "c4": "21"}
                     ],
                     "desc_values": {
                         "j_material": "کتان ۹۸٪ پنبه + ۲٪ لایکرا کشی",
                         "j_thickness": "متوسط (مناسب ۴ فصل)"
                     }
                 },
                 {
                     "title": "پیراهن آستین بلند نخی کلاسیک",
                     "product_desc": "پیراهن مردانه تمام نخ طرح آکسفورد",
                     "category": "پیراهن",
                     "model": "قواره استاندارد (Regular Fit)",
                     "waist_type": "آزاد",
                     "product_notes": "پارچه بدون آبرفت بوده و در شستشو رنگ ثابت است. با متر خیاطی روی سطح صاف اندازه بگیرید.",
                     "columns": ["سایز محصول", "قد پیراهن (cm)", "عرض سینه (cm)", "طول آستین (cm)", "عرض سرشانه (cm)"],
                     "rows": [
                         {"c0": "M", "c1": "74", "c2": "52", "c3": "63", "c4": "45"},
                         {"c0": "L", "c1": "76", "c2": "55", "c3": "64", "c4": "47"},
                         {"c0": "XL", "c1": "78", "c2": "58", "c3": "65", "c4": "49"},
                         {"c0": "2XL", "c1": "80", "c2": "61", "c3": "66", "c4": "51"}
                     ],
                     "desc_values": {
                         "j_material": "۱۰۰٪ نخ پنبه ترک",
                         "j_thickness": "بهاره و تابستانه"
                     }
                 }
             ];

             var blob = new Blob([JSON.stringify(sampleData, null, 2)], { type: 'application/json;charset=utf-8;' });
             if (window.saveAs) {
                 saveAs(blob, "نمونه_داده_جدول_سایزبندی.json");
             } else {
                 var a = document.createElement('a');
                 a.href = URL.createObjectURL(blob);
                 a.download = "نمونه_داده_جدول_سایزبندی.json";
                 document.body.appendChild(a);
                 a.click();
                 document.body.removeChild(a);
             }
         }

         // 2. Download Persian Sample Excel (.xlsx)
         function downloadSampleExcelTemplate() {
             if (typeof XLSX === 'undefined') {
                 showToast('کتابخانه اکسل هنوز بارگذاری نشده است.', 'error');
                 return;
             }

             try {
                 var wb = XLSX.utils.book_new();

                 // Sheet 1: Single product table with top metadata (Shirt)
                 var shirtSheet = [
                     ["عنوان جدول", "پیراهن آستین بلند نخی مردانه", "", "", ""],
                     ["دسته‌بندی", "پیراهن", "", "", ""],
                     ["شرح کالا", "پیراهن نخی تمام پنبه چهارفصل", "", "", ""],
                     ["مدل", "کلاسیک قواره استاندارد", "", "", ""],
                     ["نوع کمر", "آزاد", "", "", ""],
                     ["یادداشت", "قواره کار استاندارد است. پارچه ۱۰۰٪ پنبه بدون آبرفت.", "", "", ""],
                     ["جنس", "کتان پنبه ترک", "ضخامت", "متوسط بهاره", ""],
                     [],
                     ["سایز محصول", "قد پیراهن (cm)", "عرض سینه (cm)", "طول آستین (cm)", "عرض سرشانه (cm)"],
                     ["M", "74", "52", "63", "45"],
                     ["L", "76", "55", "64", "47"],
                     ["XL", "78", "58", "65", "49"],
                     ["2XL", "80", "61", "66", "51"]
                 ];
                 var ws1 = XLSX.utils.aoa_to_sheet(shirtSheet);
                 XLSX.utils.book_append_sheet(wb, ws1, "پیراهن مردانه");

                 // Sheet 2: Standard pants table (Pants)
                 var pantsSheet = [
                     ["سایز محصول", "قد شلوار", "1/2 دور کمر", "1/2 دور ران", "دمپا"],
                     ["31 (M)", "100", "38", "29", "18"],
                     ["32 (L)", "102", "40", "30", "19"],
                     ["34 (XL)", "104", "42", "31", "20"],
                     ["36 (2XL)", "106", "44", "32", "21"]
                 ];
                 var ws2 = XLSX.utils.aoa_to_sheet(pantsSheet);
                 XLSX.utils.book_append_sheet(wb, ws2, "شلوار کتان");

                 // Sheet 3: Multi-product flat table
                 var multiSheet = [
                     ["نام محصول", "دسته‌بندی", "مدل", "سایز", "قد", "دور کمر", "دمپا"],
                     ["شلوار جین راسته", "شلوار", "راسته", "30", "100", "38", "19"],
                     ["شلوار جین راسته", "شلوار", "راسته", "32", "102", "40", "20"],
                     ["شلوار جین راسته", "شلوار", "راسته", "34", "104", "42", "21"],
                     ["شلوار اسلش ورزشی", "شلوار", "اسلش دمپا کش", "L", "98", "36-44", "کش"],
                     ["شلوار اسلش ورزشی", "شلوار", "اسلش دمپا کش", "XL", "100", "38-46", "کش"]
                 ];
                 var ws3 = XLSX.utils.aoa_to_sheet(multiSheet);
                 XLSX.utils.book_append_sheet(wb, ws3, "چند محصول در یک جدول");

                 XLSX.writeFile(wb, "قالب_اکسل_جدول_سایزبندی.xlsx");
                 showToast('فایل نمونه اکسل با موفقیت دانلود شد.', 'success');
             } catch (e) {
                 console.error('Excel template download error:', e);
                 showToast('خطا در تولید فایل نمونه اکسل: ' + e.message, 'error');
             }
         }

         window._realDownloadSampleExcelTemplate = downloadSampleExcelTemplate;
         window._realDownloadSampleJsonTemplate = downloadSampleJsonTemplate;
         window.downloadSampleExcelTemplate = downloadSampleExcelTemplate;
         window.downloadSampleJsonTemplate = downloadSampleJsonTemplate;
         window.downloadSampleExcel = downloadSampleExcelTemplate;
         window.downloadSampleJSON = downloadSampleJsonTemplate;
         window.downloadSampleJson = downloadSampleJsonTemplate;

         // 3. Dropzone & File Input Handlers
         function handleDragOver(e) {
             if (e) { e.preventDefault(); e.stopPropagation(); }
             $('#quick-dropzone').addClass('border-indigo-500 bg-indigo-50/50');
         }

         function handleDragLeave(e) {
             if (e) { e.preventDefault(); e.stopPropagation(); }
             $('#quick-dropzone').removeClass('border-indigo-500 bg-indigo-50/50');
         }

         function handleFileDrop(e) {
             if (e) { e.preventDefault(); e.stopPropagation(); }
             $('#quick-dropzone').removeClass('border-indigo-500 bg-indigo-50/50');
             var dt = e.dataTransfer || (e.originalEvent && e.originalEvent.dataTransfer);
             var files = dt ? dt.files : null;
             if (files && files.length > 0) {
                 updateQuickFileInfo(files[0]);
             }
         }

         function handleFileInputChange(input) {
             if (input.files && input.files.length > 0) {
                 updateQuickFileInfo(input.files[0]);
             }
         }

         function updateQuickFileInfo(file) {
             quickSelectedFile = file;
             var sizeKb = Math.round(file.size / 1024);
             var sizeStr = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) + ' مگابایت' : sizeKb + ' کیلوبایت';
             var ext = file.name.split('.').pop().toUpperCase();
             $('#quick-file-name').text(file.name);
             $('#quick-file-meta').text('فرمت فایل: ' + ext + ' • اندازه: ' + sizeStr);
             $('#quick-file-info-box').removeClass('hidden');

             // Hide direct paste area to prioritize uploaded file
             $('#quick-paste-box').addClass('hidden');
             $('#btn-toggle-paste').html('<span>📝</span><span>درج مستقیم متن JSON</span>');
         }

         function clearSelectedQuickFile() {
             quickSelectedFile = null;
             $('#quick-file-input').val('');
             $('#quick-file-info-box').addClass('hidden');
         }

         function toggleDirectPasteArea() {
             var isHidden = $('#quick-paste-box').hasClass('hidden');
             if (isHidden) {
                 $('#quick-paste-box').removeClass('hidden');
                 $('#btn-toggle-paste').html('<span>✕</span><span>بستن بخش درج دستی متن</span>');
                 $('#quick-json-textarea').focus();
             } else {
                 $('#quick-paste-box').addClass('hidden');
                 $('#btn-toggle-paste').html('<span>📝</span><span>درج مستقیم متن JSON</span>');
             }
         }

         function updateStorageChoiceUI() {
             var choice = $('input[name="quick_storage_choice"]:checked').val() || 'save';
             if (choice === 'save') {
                 $('#label-storage-save').addClass('border-indigo-600 bg-indigo-50/40').removeClass('border-slate-200 bg-slate-50');
                 $('#label-storage-temp').addClass('border-slate-200 bg-slate-50').removeClass('border-indigo-600 bg-indigo-50/40');
             } else {
                 $('#label-storage-temp').addClass('border-indigo-600 bg-indigo-50/40').removeClass('border-slate-200 bg-slate-50');
                 $('#label-storage-save').addClass('border-slate-200 bg-slate-50').removeClass('border-indigo-600 bg-indigo-50/40');
             }
         }

         function resetQuickBatchForm() {
             clearSelectedQuickFile();
             $('#quick-json-textarea').val('');
             $('#quick-paste-box').addClass('hidden');
             $('#btn-toggle-paste').html('<span>📝</span><span>درج مستقیم متن JSON</span>');
             $('#storage-choice-save').prop('checked', true);
             updateStorageChoiceUI();
             $('#quick-results-container').addClass('hidden');
             $('#quick-progress-container').addClass('hidden');
             $('#quick-batch-gallery-grid').empty();
             quickBatchCharts = [];
             showToast('فرم ماژول سریع بازنشانی شد.', 'info');
         }

         // =========================================================================
         // 4. PARSER ENGINES (Excel & JSON with 100% Data Preservation)
         // =========================================================================

         // Comprehensive Excel & CSV Parser
         function parseExcelWorkbookToCharts(workbook, defaultFileName) {
             var charts = [];
             var baseTitle = (defaultFileName || 'جدول سایز').replace(/\.[^/.]+$/, '').trim();

             workbook.SheetNames.forEach(function(sheetName) {
                 var sheet = workbook.Sheets[sheetName];
                 if (!sheet) return;

                 // Convert to 2D array of rows
                 var rawRows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
                 if (!rawRows || rawRows.length === 0) return;

                 // Clean and remove completely empty rows
                 var validRows = [];
                 rawRows.forEach(function(r) {
                     if (!Array.isArray(r)) return;
                     var hasContent = r.some(function(cell) { return cleanCellVal(cell) !== ''; });
                     if (hasContent) {
                         validRows.push(r.map(cleanCellVal));
                     }
                 });
                 if (validRows.length === 0) return;

                 // Metadata object for this sheet
                 var meta = {
                     title: '',
                     category: '',
                     product_desc: '',
                     model: '',
                     waist_type: '',
                     product_notes: '',
                     desc_values: {}
                 };

                 var tableHeaderIdx = -1;
                 var maxScan = Math.min(validRows.length, 25);

                 // Step 1: Detect table header row and top metadata
                 for (var i = 0; i < maxScan; i++) {
                     var row = validRows[i];

                     // Look for typical clothing size chart column headers
                     var isHeader = row.some(function(c) {
                         var cl = c.toLowerCase();
                         return cl.includes('سایز') || cl.includes('size') || cl.includes('اندازه') ||
                                cl.includes('قد') || cl.includes('سینه') || cl.includes('کمر') ||
                                cl.includes('ران') || cl.includes('دمپا') || cl.includes('سرشانه') ||
                                cl.includes('آستین') || cl.includes('فاق') || cl.includes('باسن') ||
                                cl.includes('length') || cl.includes('chest') || cl.includes('waist') ||
                                cl.includes('hip') || cl.includes('shoulder') || cl.includes('sleeve') ||
                                cl.includes('width') || cl.includes('height');
                     });

                     if (isHeader) {
                         tableHeaderIdx = i;
                         break;
                     }

                     // If not a table header row, check for key-value pairs (e.g. ["عنوان", "پیراهن کتان"])
                     var k1 = row[0] ? row[0].toLowerCase().trim() : '';
                     var v1 = row[1] ? row[1].trim() : '';
                     if (k1 && v1 && row.length <= 4) {
                         if (k1.includes('عنوان') || k1.includes('نام') || k1.includes('title')) {
                             meta.title = v1;
                         } else if (k1.includes('دسته') || k1.includes('category') || k1.includes('گروه')) {
                             meta.category = v1;
                         } else if (k1.includes('شرح') || k1.includes('توضیح') || k1.includes('desc')) {
                             meta.product_desc = v1;
                         } else if (k1.includes('مدل') || k1.includes('model') || k1.includes('برش')) {
                             meta.model = v1;
                         } else if (k1.includes('کمر') || k1.includes('waist')) {
                             meta.waist_type = v1;
                         } else if (k1.includes('یادداشت') || k1.includes('راهنما') || k1.includes('نکته') || k1.includes('note')) {
                             meta.product_notes = v1;
                         } else if (k1.includes('جنس') || k1.includes('پارچه') || k1.includes('material')) {
                             meta.desc_values['j_material'] = v1;
                         } else if (k1.includes('ضخامت') || k1.includes('thickness') || k1.includes('فصل')) {
                             meta.desc_values['j_thickness'] = v1;
                         } else if (k1.includes('کشسانی') || k1.includes('stretch')) {
                             meta.desc_values['j_stretch'] = v1;
                         } else if (k1.includes('آستر') || k1.includes('lining')) {
                             meta.desc_values['j_lining'] = v1;
                         }
                     }
                 }

                 // Step 2: Fallback if no header matched by keyword
                 if (tableHeaderIdx === -1) {
                     for (var j = 0; j < validRows.length; j++) {
                         var filledCount = validRows[j].filter(function(x) { return x !== ''; }).length;
                         if (filledCount >= 2) {
                             tableHeaderIdx = j;
                             break;
                         }
                     }
                 }
                 if (tableHeaderIdx === -1) tableHeaderIdx = 0;

                 var headerRow = validRows[tableHeaderIdx];
                 // Trim trailing empty header columns
                 var lastColIdx = headerRow.length - 1;
                 while (lastColIdx >= 0 && !headerRow[lastColIdx]) { lastColIdx--; }
                 var fullColumns = headerRow.slice(0, lastColIdx + 1);

                 // Check for product identification columns
                 var titleColIdx = -1;
                 var catColIdx = -1;
                 var modelColIdx = -1;
                 var waistColIdx = -1;
                 var notesColIdx = -1;

                 fullColumns.forEach(function(col, idx) {
                     var cLower = col.toLowerCase().trim();
                     var isTitleCol = (cLower.includes('عنوان') || cLower.includes('نام') || cLower.includes('title') || cLower.includes('کالا') || cLower.includes('محصول') || cLower.includes('کد') || cLower.includes('شناسه') || cLower.includes('code') || cLower.includes('product')) && !cLower.includes('قد') && !cLower.includes('سایز') && !cLower.includes('سینه') && !cLower.includes('کمر') && !cLower.includes('size');
                     var isCatCol = (cLower.includes('دسته') || cLower.includes('category') || cLower.includes('گروه')) && !isTitleCol;
                     var isModelCol = (cLower.includes('مدل کالا') || cLower.includes('مدل پوشاک') || cLower === 'مدل' || cLower === 'model' || cLower.includes('برش')) && !isTitleCol && !cLower.includes('قد') && !cLower.includes('کمر');
                     var isWaistCol = (cLower.includes('نوع کمر') || cLower.includes('مدل کمر') || cLower.includes('waist type'));
                     var isNotesCol = (cLower.includes('یادداشت') || cLower.includes('راهنما') || cLower.includes('نکته') || cLower.includes('note') || cLower.includes('guide'));

                     if (isTitleCol && titleColIdx === -1) titleColIdx = idx;
                     else if (isCatCol && catColIdx === -1) catColIdx = idx;
                     else if (isModelCol && modelColIdx === -1) modelColIdx = idx;
                     else if (isWaistCol && waistColIdx === -1) waistColIdx = idx;
                     else if (isNotesCol && notesColIdx === -1) notesColIdx = idx;
                 });

                 var dataRows = validRows.slice(tableHeaderIdx + 1);

                 // Check if this sheet has multiple distinct products
                 var isMultiProduct = false;
                 if (titleColIdx !== -1 && dataRows.length > 0) {
                     var distinctTitles = {};
                     dataRows.forEach(function(r) {
                         var t = cleanCellVal(r[titleColIdx]);
                         if (t) distinctTitles[t] = true;
                     });
                     if (Object.keys(distinctTitles).length > 1) {
                         isMultiProduct = true;
                     }
                 }

                 if (isMultiProduct) {
                     // Multi-product sheet
                     var groups = {};
                     var metaIndices = [titleColIdx, catColIdx, modelColIdx, waistColIdx, notesColIdx].filter(function(x) { return x !== -1; });
                     var measCols = [];
                     fullColumns.forEach(function(col, idx) {
                         if (!metaIndices.includes(idx) && col) {
                             measCols.push({ name: col, originalIdx: idx });
                         }
                     });

                     dataRows.forEach(function(r) {
                         var pTitle = cleanCellVal(r[titleColIdx]);
                         if (!pTitle) return;
                         if (!groups[pTitle]) {
                             groups[pTitle] = {
                                 title: pTitle,
                                 category: (catColIdx !== -1 && r[catColIdx]) ? r[catColIdx] : (meta.category || 'عمومی'),
                                 model: (modelColIdx !== -1 && r[modelColIdx]) ? r[modelColIdx] : meta.model,
                                 waist_type: (waistColIdx !== -1 && r[waistColIdx]) ? r[waistColIdx] : meta.waist_type,
                                 product_notes: (notesColIdx !== -1 && r[notesColIdx]) ? r[notesColIdx] : meta.product_notes,
                                 desc_values: Object.assign({}, meta.desc_values),
                                 rows: []
                             };
                         }
                         var rowObj = {};
                         measCols.forEach(function(colObj, cIdx) {
                             rowObj['c' + cIdx] = cleanCellVal(r[colObj.originalIdx]);
                         });
                         groups[pTitle].rows.push(rowObj);
                     });

                     for (var gt in groups) {
                         var g = groups[gt];
                         if (g.rows.length > 0) {
                             charts.push({
                                 id: 'qb_' + Date.now() + '_' + Math.floor(Math.random() * 10000),
                                 title: g.title,
                                 product_desc: g.title,
                                 category: g.category || 'عمومی',
                                 model: g.model || '',
                                 waist_type: g.waist_type || '',
                                 product_notes: g.product_notes || '',
                                 desc_values: g.desc_values || {},
                                 columns: measCols.map(function(m) { return m.name; }),
                                 rows: g.rows
                             });
                         }
                     }
                 } else {
                     // Single-product sheet: all rows belong to this chart
                     var chartRows = [];
                     dataRows.forEach(function(r) {
                         var rowObj = {};
                         var hasVal = false;
                         fullColumns.forEach(function(col, cIdx) {
                             var val = cleanCellVal(r[cIdx]);
                             if (val !== '') hasVal = true;
                             rowObj['c' + cIdx] = val;
                         });
                         if (hasVal) chartRows.push(rowObj);
                     });

                     if (chartRows.length > 0 && fullColumns.length > 0) {
                         var finalTitle = meta.title;
                         if (!finalTitle) {
                             var cleanSheet = sheetName.replace(/Sheet\d+/i, '').replace(/ورقه\s*\d+/i, '').trim();
                             if (cleanSheet) {
                                 finalTitle = cleanSheet;
                             } else if (workbook.SheetNames.length === 1 && baseTitle) {
                                 finalTitle = baseTitle;
                             } else {
                                 finalTitle = 'جدول سایز ' + (charts.length + 1);
                             }
                         }

                         charts.push({
                             id: 'qb_' + Date.now() + '_' + Math.floor(Math.random() * 10000),
                             title: finalTitle,
                             product_desc: meta.product_desc || finalTitle,
                             category: meta.category || (sheetName && !sheetName.toLowerCase().includes('sheet') ? sheetName : 'عمومی'),
                             model: meta.model || '',
                             waist_type: meta.waist_type || '',
                             product_notes: meta.product_notes || '',
                             desc_values: meta.desc_values || {},
                             columns: fullColumns,
                             rows: chartRows
                         });
                     }
                 }
             });

             return charts;
         }

         // Comprehensive JSON Parser
         function parseAndNormalizeJson(rawInput) {
             var data = (typeof rawInput === 'string') ? JSON.parse(rawInput) : rawInput;
             if (!data) return [];

             var list = [];
             if (Array.isArray(data)) {
                 list = data;
             } else if (typeof data === 'object') {
                 if (Array.isArray(data.charts)) list = data.charts;
                 else if (Array.isArray(data.size_charts)) list = data.size_charts;
                 else if (Array.isArray(data.data)) list = data.data;
                 else if (Array.isArray(data.items)) list = data.items;
                 else list = [data];
             }

             if (list.length === 0) return [];

             // Detect if list is an array of row objects for 1 product:
             // e.g. [ {"سایز": "S", "قد": 70}, {"سایز": "M", "قد": 72} ]
             var isFlatRowsList = list.every(function(item) {
                 return typeof item === 'object' && item !== null && !item.columns && !item.rows && !item.category;
             });

             if (isFlatRowsList) {
                 list = [{
                     title: 'جدول سایز',
                     rows: list
                 }];
             }

             var normalizedCharts = [];

             list.forEach(function(item, idx) {
                 if (!item || typeof item !== 'object') return;

                 var title = item.title || item.product_desc || item.name || ('جدول سایز ' + (idx + 1));
                 var category = item.category || item.cat || 'عمومی';
                 var product_desc = item.product_desc || item.desc || item.description || title;
                 var model = item.model || '';
                 var waist_type = item.waist_type || item.waist || '';
                 var product_notes = item.product_notes || item.notes || item.note || item.guide || '';
                 var desc_values = item.desc_values || item.specs || {};
                 var image_path = item.image_path || item.image || '';

                 var rawColumns = Array.isArray(item.columns) ? item.columns.map(cleanCellVal) : [];
                 var rawRows = Array.isArray(item.rows) ? item.rows : (Array.isArray(item.data) ? item.data : []);

                 var finalColumns = [];
                 var finalRows = [];

                 if (rawRows.length > 0) {
                     var firstRow = rawRows[0];

                     if (Array.isArray(firstRow)) {
                         // Array of arrays: [ ["M", 100, 38], ["L", 102, 40] ]
                         if (rawColumns.length > 0) {
                             finalColumns = rawColumns;
                         } else {
                             for (var c = 0; c < firstRow.length; c++) {
                                 finalColumns.push(c === 0 ? 'سایز' : 'اندازه ' + c);
                             }
                         }
                         rawRows.forEach(function(r) {
                             if (!Array.isArray(r)) return;
                             var rObj = {};
                             finalColumns.forEach(function(col, cIdx) {
                                 rObj['c' + cIdx] = cleanCellVal(r[cIdx]);
                             });
                             finalRows.push(rObj);
                         });
                     } else if (typeof firstRow === 'object' && firstRow !== null) {
                         var hasC0 = ('c0' in firstRow);

                         if (hasC0 && rawColumns.length > 0) {
                             finalColumns = rawColumns;
                             rawRows.forEach(function(r) {
                                 var rObj = {};
                                 finalColumns.forEach(function(col, cIdx) {
                                     rObj['c' + cIdx] = cleanCellVal(r['c' + cIdx]);
                                 });
                                 finalRows.push(rObj);
                             });
                         } else {
                             // Object with named keys (e.g. {"سایز": "M", "قد": "100"})
                             if (rawColumns.length > 0) {
                                 finalColumns = rawColumns;
                             } else {
                                 var keysSet = [];
                                 rawRows.forEach(function(r) {
                                     Object.keys(r).forEach(function(k) {
                                         if (k !== 'id' && k !== 'chart_id' && !keysSet.includes(k)) {
                                             keysSet.push(k);
                                         }
                                     });
                                 });
                                 finalColumns = keysSet;
                             }

                             rawRows.forEach(function(r) {
                                 var rObj = {};
                                 finalColumns.forEach(function(col, cIdx) {
                                     var val = r[col];
                                     if (val === undefined && r['c' + cIdx] !== undefined) val = r['c' + cIdx];
                                     rObj['c' + cIdx] = cleanCellVal(val);
                                 });
                                 finalRows.push(rObj);
                             });
                         }
                     }
                 }

                 if (finalColumns.length > 0 && finalRows.length > 0) {
                     normalizedCharts.push({
                         id: item.id || ('qb_' + Date.now() + '_' + idx),
                         title: title,
                         product_desc: product_desc,
                         category: category,
                         model: model,
                         waist_type: waist_type,
                         product_notes: product_notes,
                         desc_values: desc_values,
                         image_path: image_path,
                         columns: finalColumns,
                         rows: finalRows
                     });
                 }
             });

             return normalizedCharts;
         }

         // Unified entry point for parsing input data
         function parseInputBatchData() {
             return new Promise(function(resolve, reject) {
                 var directJson = $('#quick-json-textarea').val().trim();

                 if (directJson && !$('#quick-paste-box').hasClass('hidden')) {
                     try {
                         var charts = parseAndNormalizeJson(directJson);
                         if (!charts || charts.length === 0) {
                             throw new Error('هیچ جدول سایز معتبری از متن JSON استخراج نشد.');
                         }
                         resolve(charts);
                     } catch (err) {
                         reject(new Error('خطا در تحلیل ساختار JSON: ' + err.message));
                     }
                     return;
                 }

                 if (!quickSelectedFile) {
                     reject(new Error('لطفاً ابتدا یک فایل اکسل یا JSON بارگذاری نموده یا متن JSON را درج نمایید.'));
                     return;
                 }

                 var fileName = quickSelectedFile.name.toLowerCase();

                 if (fileName.endsWith('.json')) {
                     var reader = new FileReader();
                     reader.onload = function(e) {
                         try {
                             var charts = parseAndNormalizeJson(e.target.result);
                             if (!charts || charts.length === 0) {
                                 throw new Error('هیچ جدول سایز معتبری از فایل JSON یافت نشد.');
                             }
                             resolve(charts);
                         } catch (err) {
                             reject(new Error('خطا در تحلیل فایل JSON: ' + err.message));
                         }
                     };
                     reader.onerror = function() {
                         reject(new Error('خطا در خواندن فایل از روی سیستم شما.'));
                     };
                     reader.readAsText(quickSelectedFile, 'UTF-8');
                 } else if (fileName.endsWith('.xlsx') || fileName.endsWith('.xls') || fileName.endsWith('.csv')) {
                     if (typeof XLSX === 'undefined') {
                         reject(new Error('کتابخانه تحلیل اکسل (SheetJS) بارگذاری نشده است.'));
                         return;
                     }
                     var reader = new FileReader();
                     reader.onload = function(e) {
                         try {
                             var data = new Uint8Array(e.target.result);
                             var workbook = XLSX.read(data, { type: 'array', cellDates: true, raw: false });
                             var charts = parseExcelWorkbookToCharts(workbook, quickSelectedFile.name);
                             if (!charts || charts.length === 0) {
                                 throw new Error('هیچ داده یا جدولی از فایل اکسل استخراج نشد. لطفاً از صحت ستون‌ها و ردیف‌های فایل اطمینان حاصل کنید.');
                             }
                             resolve(charts);
                         } catch (err) {
                             reject(new Error('خطا در تحلیل فایل اکسل: ' + err.message));
                         }
                     };
                     reader.onerror = function() {
                         reject(new Error('خطا در خواندن فایل اکسل.'));
                     };
                     reader.readAsArrayBuffer(quickSelectedFile);
                 } else {
                     reject(new Error('فرمت فایل انتخاب شده پشتیبانی نمی‌شود. لطفاً فایل XLSX، CSV یا JSON انتخاب فرمایید.'));
                 }
             });
         }

         // =========================================================================
         // 5. MAIN BATCH PROCESSING CONTROLLER
         // =========================================================================

         function startQuickBatchProcessing() {
             if (quickIsRendering) return;

             var btn = $('#btn-start-quick-batch');
             btn.prop('disabled', true).addClass('opacity-75');

             $('#quick-progress-container').removeClass('hidden');
             $('#quick-results-container').addClass('hidden');
             $('#quick-progress-percent').text('5%');
             $('#quick-progress-bar').css('width', '5%');
             $('#quick-progress-status-text').text('در حال خواندن و تحلیل داده‌های ورودی...');

             parseInputBatchData().then(function(charts) {
                 quickBatchCharts = charts;
                 showToast(`تعداد ${charts.length} جدول سایز با موفقیت تحلیل و آماده‌سازی شد.`, 'success');

                 var shouldSaveToProject = ($('input[name="quick_storage_choice"]:checked').val() === 'save');
                 var shouldAutoRender = $('#quick-opt-auto-render').is(':checked');

                 if (shouldSaveToProject) {
                     $('#quick-progress-status-text').text('در حال ذخیره‌سازی اطلاعات در پروژه...');
                     saveBatchItemsToProjectPromise().then(function(res) {
                         $('#quick-results-storage-status').html('<span class="text-emerald-600 font-black">✅ ذخیره شده در پایگاه‌داده پروژه (قابل دسترسی در مدیریت جداول و اتصال به کالاها)</span>');
                         $('#btn-save-temp-to-project').addClass('hidden');
                         proceedWithRendering(shouldAutoRender);
                     }).catch(function(err) {
                         console.warn('Storage save warning:', err);
                         $('#quick-results-storage-status').html('<span class="text-amber-600 font-black">⚠️ عدم ذخیره در پروژه (' + (err.message || 'خطا') + ') - در حالت پیش‌نمایش موقت</span>');
                         $('#btn-save-temp-to-project').removeClass('hidden');
                         proceedWithRendering(shouldAutoRender);
                     });
                 } else {
                     $('#quick-results-storage-status').html('<span class="text-indigo-600 font-bold">👁️ پیش‌نمایش موقت (در پروژه ذخیره نشده است)</span>');
                     $('#btn-save-temp-to-project').removeClass('hidden');
                     proceedWithRendering(shouldAutoRender);
                 }
             }).catch(function(err) {
                 btn.prop('disabled', false).removeClass('opacity-75');
                 $('#quick-progress-container').addClass('hidden');
                 showToast(err.message || 'خطا در پردازش فایل.', 'error');
             });
         }

         function proceedWithRendering(shouldAutoRender) {
             var btn = $('#btn-start-quick-batch');

             if (shouldAutoRender) {
                 renderAllQuickBatchCharts().then(function() {
                     btn.prop('disabled', false).removeClass('opacity-75');
                     $('#quick-progress-container').addClass('hidden');
                     $('#quick-results-container').removeClass('hidden');
                     displayQuickBatchResultsUI();
                     showToast('تمامی تصاویر با کیفیت عالی رندر و آماده دانلود شدند! 🚀', 'success');
                 }).catch(function(err) {
                     btn.prop('disabled', false).removeClass('opacity-75');
                     $('#quick-progress-container').addClass('hidden');
                     $('#quick-results-container').removeClass('hidden');
                     displayQuickBatchResultsUI();
                     showToast('برخی تصاویر رندر نشدند: ' + err.message, 'warning');
                 });
             } else {
                 btn.prop('disabled', false).removeClass('opacity-75');
                 $('#quick-progress-container').addClass('hidden');
                 $('#quick-results-container').removeClass('hidden');
                 displayQuickBatchResultsUI();
                 showToast('جداول آماده شدند (رندر تصاویر انجام نشد).', 'info');
             }
         }

         // Backend saver helper
         function saveBatchItemsToProjectPromise() {
             return new Promise(function(resolve, reject) {
                 if (!quickBatchCharts || quickBatchCharts.length === 0) {
                     reject(new Error('لیست جداول خالی است.'));
                     return;
                 }
                 $.post('', {
                     action: 'td_scs_import_charts_json',
                     json_data: JSON.stringify(quickBatchCharts)
                 }, function(res) {
                     if (res && res.success) {
                         loadChartsList();
                         loadPresets();
                         loadDescPresets();
                         resolve(res);
                     } else {
                         reject(new Error((res && res.message) ? res.message : 'خطا در ثبت جداول در پروژه'));
                     }
                 }, 'json').fail(function() {
                     reject(new Error('خطای شبکه در ذخیره جداول'));
                 });
             });
         }

         function saveQuickBatchToProjectPermanently() {
             if (!quickBatchCharts || quickBatchCharts.length === 0) return;
             showToast('در حال ذخیره جداول در پایگاه‌داده پروژه... 💾', 'info');
             saveBatchItemsToProjectPromise().then(function(res) {
                 showToast(`تمامی ${res.imported_count || quickBatchCharts.length} جدول با موفقیت در پروژه ذخیره شدند. ✅`, 'success');
                 $('#quick-results-storage-status').html('<span class="text-emerald-600 font-black">✅ ذخیره شده در پایگاه‌داده پروژه</span>');
                 $('#btn-save-temp-to-project').addClass('hidden');
             }).catch(function(err) {
                 showToast(err.message || 'خطا در ثبت اطلاعات.', 'error');
             });
         }

         function syncQuickBatchProductsUploaded() {
             if (!quickBatchCharts || quickBatchCharts.length === 0) {
                 showToast('جدولی برای همگام‌سازی وجود ندارد.', 'warning');
                 return;
             }
             showToast('در حال ثبت تصاویر و به‌روزرسانی وضعیت آپلود محصولات... ⏳', 'info');
             saveBatchItemsToProjectPromise().then(function(res) {
                 var matchCount = res.matched_products_count || 0;
                 var msg = matchCount > 0 ?
                     `وضعیت آپلود ${matchCount} محصول با موفقیت ثبت و متصل شد! 🚀` :
                     'جداول ذخیره شدند. (محصول منطبق برای اتصال خودکار یافت نشد)';
                 showToast(msg, 'success');
                 $('#quick-results-storage-status').html('<span class="text-emerald-600 font-black">✅ وضعیت آپلود محصولات ثبت و همگام شد</span>');
             }).catch(function(err) {
                 showToast(err.message || 'خطا در ثبت وضعیت آپلود محصولات.', 'error');
             });
         }

         // =========================================================================
         // 6. HIGH-FIDELITY BATCH RENDERING PIPELINE
         // =========================================================================

         function buildOffscreenCanvasForChart(chart) {
             var canvas = $('#scs-batch-canvas');
             if (canvas.length === 0) return;

             var container = canvas.find('#batch-canvas-content');
             container.empty();

             // Set canvas size from settings
             var width = parseInt((typeof scs_settings !== 'undefined' && scs_settings.canvas_width) || 800, 10);
             var height = parseInt((typeof scs_settings !== 'undefined' && scs_settings.canvas_height) || 1000, 10);
             canvas.css({
                 'width': width + 'px',
                 'height': height + 'px',
                 'min-height': height + 'px'
             });

             var order = (typeof scs_settings !== 'undefined' && (scs_settings.block_order || scs_settings.blocks_order)) || ['header', 'meta', 'table', 'illus', 'notes', 'specs', 'footer'];

             var blocks = {
                 header: function() {
                     var logoSrc = (typeof scs_settings !== 'undefined' && (scs_settings.image_logo || scs_settings.brand_logo)) || '';
                     var brandTitle = (typeof scs_settings !== 'undefined' && (scs_settings.brand_title || scs_settings.brand_name || scs_settings.site_name)) || '';
                     var brandSubtitle = (typeof scs_settings !== 'undefined' && scs_settings.brand_subtitle) || 'سامانه هوشمند استودیو سایزبندی کالا';
                     var logoHtml = logoSrc ? `<img src="${resolveImageUrl(logoSrc)}" class="max-h-12 object-contain" alt="Logo">` : `
                         <div class="text-2xl font-black text-slate-900" id="preview-html-brand-title">${brandTitle}</div>
                         <div class="text-[10px] font-black text-slate-400 mt-1" id="preview-html-brand-subtitle">${brandSubtitle}</div>
                     `;
                     return `
                         <div id="preview-header-section" data-scs-block="header" class="scs-card relative group flex justify-between items-center border-b-2 pb-5 transition-all w-full">
                             <div id="preview-brand-header-area">
                                 ${logoHtml}
                             </div>
                             <div class="text-left">
                                 <h4 id="preview-title" class="text-xl font-black text-slate-900">${chart.title || 'شرح محصول'}</h4>
                                 <p class="text-[10px] text-slate-400 mt-1" id="preview-title-sub">جدول رسمی اصالت سایزبندی محصول</p>
                             </div>
                         </div>
                     `;
                 },
                 meta: function() {
                     var catBadge = chart.category ? `
                         <div id="preview-cat-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black flex items-center gap-1.5">
                             <span class="text-slate-400">دسته‌بندی:</span>
                             <span id="preview-cat" class="text-slate-800 font-black">${chart.category}</span>
                         </div>` : '';
                     var modelBadge = chart.model ? `
                         <div id="preview-model-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black flex items-center gap-1.5">
                             <span class="text-slate-400">مدل:</span>
                             <span id="preview-model" class="text-slate-800 font-black">${chart.model}</span>
                         </div>` : '';
                     var waistBadge = chart.waist_type ? `
                         <div id="preview-waist-pill" class="scs-pill px-3.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-black flex items-center gap-1.5">
                             <span class="text-slate-400">نوع کمر:</span>
                             <span id="preview-waist" class="text-slate-800 font-black">${chart.waist_type}</span>
                         </div>` : '';
                     return `
                         <div id="preview-meta-pills-section" data-scs-block="meta" class="relative group flex flex-wrap gap-2.5 w-full">
                             ${catBadge}
                             ${modelBadge}
                             ${waistBadge}
                         </div>
                     `;
                 },
                 table: function() {
                     var cols = Array.isArray(chart.columns) ? chart.columns : [];
                     var rows = Array.isArray(chart.rows) ? chart.rows : [];

                     var ths = cols.map(function(col, idx) {
                         var borderClass = idx > 0 ? 'border-r border-slate-200' : '';
                         return `<th class="p-3 bg-slate-50 text-slate-800 border-b border-slate-200 ${borderClass} text-center font-black whitespace-nowrap">${col}</th>`;
                     }).join('');

                     var trs = '';
                     if (rows.length > 0) {
                         trs = rows.map(function(row) {
                             var tds = cols.map(function(col, idx) {
                                 var cellVal = row['c' + idx] !== undefined ? row['c' + idx] : (row[col] !== undefined ? row[col] : (Array.isArray(row) ? row[idx] : (row.values && row.values[col] !== undefined ? row.values[col] : (idx === 0 && row.size !== undefined ? row.size : ''))));
                                 var displayVal = cellVal !== '' ? cellVal : '—';
                                 var borderClass = idx > 0 ? 'border-r border-slate-100' : '';
                                 return `<td class="p-3 ${borderClass} text-center font-bold whitespace-nowrap">${displayVal}</td>`;
                             }).join('');
                             return `<tr class="border-b last:border-0 border-slate-100 hover:bg-slate-50/50 transition-colors">${tds}</tr>`;
                         }).join('');
                     } else {
                         trs = `<tr><td colspan="${Math.max(1, cols.length)}" class="p-6 text-center text-slate-400 font-bold">ردیفی ثبت نگردیده است</td></tr>`;
                     }

                     return `
                         <div id="preview-table-card" data-scs-block="table" class="scs-card relative group w-full overflow-x-auto border border-slate-200 rounded-2xl bg-white shadow-sm transition-all">
                             <div id="preview-table-container" class="w-full">
                                 <table class="w-full text-right text-[11px] border-collapse" style="direction: rtl; width: 100%; table-layout: auto;">
                                     <thead class="bg-slate-50 text-slate-700 font-bold border-b border-slate-200">
                                         <tr>${ths}</tr>
                                     </thead>
                                     <tbody>
                                         ${trs}
                                     </tbody>
                                 </table>
                             </div>
                         </div>
                     `;
                 },
                 illus: function() {
                    var maxH = parseInt((typeof scs_settings !== 'undefined' && scs_settings.illus_max_height) || 160, 10);
                    var objFit = (typeof scs_settings !== 'undefined' && scs_settings.illus_object_fit) || 'contain';
                    var imgUrl = chart.image_path || chart.illus_url || '';
                    if (!imgUrl && Array.isArray(app_presets) && chart.preset_id) {
                        var pr = app_presets.find(p => String(p.id) === String(chart.preset_id));
                        if (pr && pr.illus_url) imgUrl = pr.illus_url;
                    }
                    var mediaHtml = imgUrl ?
                        `<img src="${resolveImageUrl(imgUrl)}" class="max-w-full rounded-lg" style="max-height: ${maxH}px; object-fit: ${objFit};" alt="Illustration" crossorigin="anonymous">` :
                        `<div class="w-full flex items-center justify-center mb-2"><span class="text-4xl text-slate-400">📏</span></div>`;
                    return `
                        <div id="preview-illus-card" data-scs-block="illus" class="scs-card relative group w-full bg-slate-50 rounded-2xl border border-slate-200 p-5 flex flex-col items-center justify-center text-center shadow-sm transition-all" style="box-sizing: border-box;">
                            <div id="preview-illus-box" class="w-full flex items-center justify-center overflow-hidden mb-3 opacity-90" style="max-height: ${maxH}px;">
                                ${mediaHtml}
                            </div>
                            <div class="text-[9px] text-slate-400 leading-relaxed font-black">
                                ابعاد اندازه‌گیری شده محصول مربوط به اندازه تکه‌ای کالا در حالتِ آزاد و غیرکشیده روی سطح است.
                            </div>
                        </div>
                    `;
                },
                notes: function() {
                     if (!chart.product_notes) return '';
                     return `
                         <div id="preview-notes-card" data-scs-block="notes" class="scs-card relative group w-full bg-amber-50/50 p-4 rounded-2xl border border-amber-200/60 text-right shadow-sm transition-all">
                             <div class="flex items-start gap-2.5">
                                 <span class="text-sm shrink-0">💡</span>
                                 <div>
                                     <h5 class="text-[10px] font-black text-amber-900 mb-1">توضیحات تکمیلی و مشاوره سایز:</h5>
                                     <p class="text-[10px] text-amber-800 leading-relaxed font-bold" id="preview-notes">${chart.product_notes}</p>
                                 </div>
                             </div>
                         </div>
                     `;
                 },
                 specs: function() {
                     if (!chart.desc_values || typeof chart.desc_values !== 'object' || Object.keys(chart.desc_values).length === 0) return '';
                     var specsHtml = '';
                     for (var k in chart.desc_values) {
                         var val = chart.desc_values[k];
                         if (val) {
                             var label = k;
                             if (k === 'j_material' || k === 'material') label = 'جنس و ترکیب پارچه';
                             else if (k === 'j_thickness' || k === 'thickness') label = 'ضخامت و فصل';
                             else if (k === 'j_stretch' || k === 'stretch') label = 'خاصیت کشسانی';
                             else if (k === 'j_lining' || k === 'lining') label = 'آستر و لایه داخلی';
                             specsHtml += `
                                 <div class="flex justify-between items-center py-1 border-b border-indigo-100/50">
                                     <span class="text-slate-500 font-bold">${label}:</span>
                                     <span class="text-slate-800 font-black">${val}</span>
                                 </div>
                             `;
                         }
                     }
                     if (!specsHtml) return '';
                     return `
                         <div id="preview-specs-card" data-scs-block="specs" class="scs-card relative group w-full bg-indigo-50/40 p-4 rounded-2xl border border-indigo-200/60 shadow-sm transition-all">
                             <h5 class="text-[11px] font-black text-indigo-900 mb-2.5 border-b border-indigo-100 pb-1.5 flex items-center gap-1.5">
                                 <span>🔬</span>
                                 <span>مشخصات، الیاف و متریال محصول</span>
                             </h5>
                             <div id="preview-specs-list" class="grid grid-cols-2 gap-x-6 gap-y-2 text-[10px]">
                                 ${specsHtml}
                             </div>
                         </div>
                     `;
                 },
                 footer: function() {
                     var brandTagline = (typeof scs_settings !== 'undefined' && scs_settings.brand_tagline) || 'تضمین اصالت و کیفیت کالا';
                     var brandWebsite = (typeof scs_settings !== 'undefined' && scs_settings.brand_website) || '';
                     return `
                         <div id="preview-footer-brand" data-scs-block="footer" class="scs-card relative group flex justify-between items-center pt-4 border-t border-slate-200 text-[10px] text-slate-400 font-black transition-all w-full">
                             <div id="preview-brand-tagline">${brandTagline}</div>
                             <div id="preview-brand-website" dir="ltr">${brandWebsite}</div>
                         </div>
                     `;
                 }
             };

             var layout = (typeof scs_settings !== 'undefined' && scs_settings.layout_mode) || 'standard_split';
             var isFreeform = (typeof scs_settings !== 'undefined' && scs_settings.positioning_mode === 'freeform');
             var renderedBlocks = new Set();

             order.forEach(function(k) {
                 if (renderedBlocks.has(k)) return;
                 if (!blocks[k] || (typeof getBlockVisibility === 'function' && !getBlockVisibility(k))) return;

                 if ((k === 'table' || k === 'illus') && !isFreeform) {
                     var hasTable = order.includes('table') && (typeof getBlockVisibility !== 'function' || getBlockVisibility('table'));
                     var hasIllus = order.includes('illus') && (typeof getBlockVisibility !== 'function' || getBlockVisibility('illus'));

                     if (hasTable && hasIllus && (layout === 'standard_split' || layout === 'modern_bento')) {
                         var tableHtml = blocks.table();
                         var illusHtml = blocks.illus();
                         var gapPx = (typeof scs_settings !== 'undefined' && scs_settings.content_gap) || 16;
                         var gridHtml = `
                             <div id="batch-grid-wrapper" class="w-full scs-batch-grid-split scs-grid-wrapper flex flex-row items-stretch gap-4" style="display: flex !important; flex-direction: row !important; gap: ${gapPx}px !important; align-items: stretch !important; justify-content: space-between !important; width: 100% !important; box-sizing: border-box !important;">
                                 <div style="flex: 8 1 0% !important; width: 66.666% !important; min-width: 0 !important; box-sizing: border-box !important;">
                                     ${tableHtml}
                                 </div>
                                 <div style="flex: 4 1 0% !important; width: 33.333% !important; min-width: 0 !important; box-sizing: border-box !important;">
                                     ${illusHtml}
                                 </div>
                             </div>
                         `;
                         container.append(gridHtml);
                         renderedBlocks.add('table');
                         renderedBlocks.add('illus');
                         return;
                     }
                 }

                 var html = blocks[k]();
                 if (html) container.append(html);
                 renderedBlocks.add(k);
             });

             if (typeof applyBrandingStyleToTarget === 'function') {
                 applyBrandingStyleToTarget('#scs-batch-canvas', false);
             }
         }

         async function renderAllQuickBatchCharts() {
             if (!quickBatchCharts || quickBatchCharts.length === 0) return;
             if (typeof html2canvas === 'undefined') {
                 throw new Error('کتابخانه html2canvas در دسترس نیست.');
             }

             quickIsRendering = true;
             var total = quickBatchCharts.length;
             var failedCount = 0;

             for (var i = 0; i < total; i++) {
                 var chart = quickBatchCharts[i];
                 var percent = Math.round(((i) / total) * 100);
                 $('#quick-progress-percent').text(percent + '%');
                 $('#quick-progress-bar').css('width', percent + '%');
                 $('#quick-progress-status-text').text(`در حال رندر جدول ${i + 1} از ${total}: ${chart.title || 'بدون عنوان'}`);

                 try {
                     buildOffscreenCanvasForChart(chart);

                     // Wait for styles and fonts to paint
                     await new Promise(function(r) { setTimeout(r, 90); });

                     var canvasEl = document.getElementById('scs-batch-canvas');
                     if (canvasEl) {
                         var bgPattern = (window.scs_settings && scs_settings.bg_pattern) || 'none';
                         var hasBgImage = !!(window.scs_settings && scs_settings.bg_image);
                         var bgColor = null;
                         if (!hasBgImage) {
                             if (bgPattern === 'dark') bgColor = '#0f172a';
                             else if (bgPattern === 'linen') bgColor = '#faf7f2';
                             else if (bgPattern === 'craft') bgColor = '#f4efe6';
                             else if (bgPattern === 'grid') bgColor = '#f8fafc';
                             else bgColor = '#ffffff';
                         }

                         var snapshot = await html2canvas(canvasEl, {
                             scale: 2,
                             useCORS: true,
                             allowTaint: true,
                             backgroundColor: bgColor,
                             scrollX: 0,
                             scrollY: 0,
                             logging: false
                         });

                         chart.rendered_image_data_url = snapshot.toDataURL('image/jpeg', 0.95);
                     }
                 } catch (itemErr) {
                     console.warn(`خطا در رندر جدول ${chart.title}:`, itemErr);
                     failedCount++;
                 }
             }

             $('#quick-progress-percent').text('100%');
             $('#quick-progress-bar').css('width', '100%');
             quickIsRendering = false;

             // Automatically persist rendered images and update products with size_chart_uploaded
             var hasRendered = quickBatchCharts.some(function(c) { return !!c.rendered_image_data_url; });
             if (hasRendered && $('#quick-cfg-save-project').is(':checked')) {
                 $('#quick-progress-status-text').text('در حال ثبت خودکار در پایگاه‌داده پروژه و اتصال به محصولات... ⏳');
                 try {
                     var saveRes = await saveBatchItemsToProjectPromise();
                     var matchMsg = (saveRes && saveRes.matched_products_count) ? ` و وضعیت آپلود ${saveRes.matched_products_count} محصول متصل گردید` : '';
                     showToast('جداول سایز با موفقیت ذخیره' + matchMsg + ' شد. ✅', 'success');
                     $('#quick-results-storage-status').html('<span class="text-emerald-600 font-black">✅ ذخیره شده در پایگاه‌داده و متصل به محصولات</span>');
                 } catch (saveErr) {
                     console.warn('خطا در ذخیره خودکار تصاویر رندر شده:', saveErr);
                 }
             }

             if (failedCount > 0) {
                 showToast(`رندر به پایان رسید (${failedCount} مورد با خطا مواجه شد)`, 'warning');
             }
         }

         // =========================================================================
         // 7. RESULTS GALLERY & UI INTERACTIONS
         // =========================================================================

         function displayQuickBatchResultsUI() {
             var container = $('#quick-batch-gallery-grid').empty();
             var count = quickBatchCharts.length;
             $('#quick-results-count-badge').text(count + ' جدول سایز');

             if (count === 0) {
                 container.html('<div class="col-span-full p-8 text-center text-slate-400 font-bold">هیچ جدولی یافت نشد.</div>');
                 return;
             }

             quickBatchCharts.forEach(function(chart, idx) {
                 var imgHtml = chart.rendered_image_data_url ?
                     `<img src="${chart.rendered_image_data_url}" onclick="zoomQuickBatchImage(${idx})" class="w-full h-48 object-cover rounded-2xl cursor-pointer hover:scale-102 transition-transform shadow-sm" alt="${chart.title}">` :
                     `<div class="w-full h-48 bg-slate-100 rounded-2xl flex flex-col items-center justify-center text-slate-400 text-xs font-black gap-2 border border-slate-200">
                         <span class="text-2xl">📊</span>
                         <span>فاقد رندر خودکار</span>
                      </div>`;

                 // Extract size samples
                 var sizes = [];
                 if (chart.rows && chart.rows.length > 0) {
                     chart.rows.forEach(function(r) {
                         var sVal = r['c0'];
                         if (sVal && !sizes.includes(sVal)) sizes.push(sVal);
                     });
                 }
                 var sizesStr = sizes.length > 0 ? (sizes.length + ' سایز (' + sizes.slice(0, 4).join(' , ') + (sizes.length > 4 ? ' ...' : '') + ')') : (chart.rows.length + ' ردیف');

                 var card = $(`
                     <div class="quick-batch-card bg-white rounded-3xl p-5 border border-slate-150 shadow-sm flex flex-col justify-between gap-4 transition-all hover:shadow-md" data-title="${(chart.title || '').toLowerCase()}" data-cat="${(chart.category || '').toLowerCase()}">
                         <div class="space-y-3">
                             <div class="relative overflow-hidden rounded-2xl">
                                 ${imgHtml}
                                 <span class="absolute top-2 right-2 px-2.5 py-1 bg-white/90 backdrop-blur-md border border-white/50 text-indigo-700 text-[10px] font-black rounded-xl shadow-xs">
                                     ${chart.category || 'عمومی'}
                                 </span>
                             </div>

                             <div>
                                 <h4 class="text-xs font-black text-slate-900 line-clamp-1" title="${chart.title}">${chart.title}</h4>
                                 <p class="text-[10px] text-slate-400 mt-0.5">${sizesStr} • ${chart.columns.length} ستون</p>
                             </div>
                         </div>

                         <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100">
                             ${chart.rendered_image_data_url ? `
                                 <button type="button" onclick="zoomQuickBatchImage(${idx})" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-black rounded-xl transition-all flex items-center justify-center gap-1">
                                     <span>👁️</span><span>مشاهده</span>
                                 </button>
                                 <button type="button" onclick="downloadSingleQuickBatchImage(${idx})" class="w-full py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[11px] font-black rounded-xl transition-all flex items-center justify-center gap-1">
                                     <span>📥</span><span>دانلود</span>
                                 </button>
                             ` : `
                                 <button type="button" onclick="rerenderAllQuickBatchImages()" class="col-span-2 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[11px] font-black rounded-xl transition-all flex items-center justify-center gap-1">
                                     <span>⚡</span><span>رندر این تصویر</span>
                                 </button>
                             `}
                             <button type="button" onclick="editQuickBatchChartInEditor(${idx})" class="col-span-2 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-[11px] font-black rounded-xl transition-all flex items-center justify-center gap-1">
                                 <span>✏️</span><span>ویرایش زنده در استودیو</span>
                             </button>
                         </div>
                     </div>
                 `);

                 container.append(card);
             });
         }

         function filterQuickBatchGallery(query) {
             var q = (query || '').toLowerCase().trim();
             $('.quick-batch-card').each(function() {
                 var title = $(this).attr('data-title') || '';
                 var cat = $(this).attr('data-cat') || '';
                 if (!q || title.includes(q) || cat.includes(q)) {
                     $(this).removeClass('hidden');
                 } else {
                     $(this).addClass('hidden');
                 }
             });
         }

         function zoomQuickBatchImage(idx) {
             var chart = quickBatchCharts[idx];
             if (!chart || !chart.rendered_image_data_url) return;
             $('#quick-modal-title').text(chart.title);
             $('#quick-modal-img').attr('src', chart.rendered_image_data_url);
             var safeName = chart.title.replace(/[^a-zA-Z0-9\u0600-\u06FF\s_-]/g, '').trim() || 'جدول_سایز';
             $('#quick-modal-download-btn').attr('href', chart.rendered_image_data_url).attr('download', safeName + '.jpg');
             $('#quick-image-zoom-modal').removeClass('hidden');
         }

         function downloadSingleQuickBatchImage(idx) {
             var chart = quickBatchCharts[idx];
             if (!chart || !chart.rendered_image_data_url) return;
             var safeName = chart.title.replace(/[^a-zA-Z0-9\u0600-\u06FF\s_-]/g, '').trim() || 'جدول_سایز';
             var a = document.createElement('a');
             a.href = chart.rendered_image_data_url;
             a.download = safeName + '.jpg';
             document.body.appendChild(a);
             a.click();
             document.body.removeChild(a);
         }

         function editQuickBatchChartInEditor(idx) {
             var chart = quickBatchCharts[idx];
             if (!chart) return;

             // Switch to Tab 1 (Editor)
             switchTab('tab-editor');

             initNewChart();

             selected_chart = chart;
             $('#editor-chart-id').val(chart.id || '');
             $('#editor-title-text').text(`ویرایش: ${chart.title || chart.product_desc}`);
             $('#chart-title').val(chart.title || '');
             $('#chart-product-desc').val(chart.product_desc || '');
             $('#chart-product-notes').val(chart.product_notes || '');
             if (chart.model) $('#chart-model').val(chart.model).prop('disabled', false).parent().removeClass('opacity-50');
             if (chart.waist_type) $('#chart-waist-type').val(chart.waist_type).prop('disabled', false).parent().removeClass('opacity-50');

             var presetId = chart.preset_id || ('custom_' + (chart.id || idx));
             var pr = app_presets.find(x => String(x.id) === String(presetId) || x.id == presetId);
             if (!pr) {
                 pr = {
                     id: presetId,
                     name: chart.title || 'سفارشی',
                     category: chart.category || 'عمومی',
                     columns: chart.columns || [],
                     show_model: chart.model ? 1 : 0,
                     show_waist: chart.waist_type ? 1 : 0,
                     illus_url: chart.image_path || '',
                     icon_url: ''
                 };
                 app_presets.push(pr);
                 if ($('#chart-preset-select option[value="' + presetId + '"]').length === 0) {
                     $('#chart-preset-select').append(`<option value="${presetId}">${chart.category ? (chart.category + ' (قالب ورودی)') : 'قالب اختصاصی'}</option>`);
                 }
             } else if (chart.columns && chart.columns.length > 0) {
                 pr.columns = chart.columns;
             }
             $('#chart-preset-select').val(presetId);

             // Rebuild header row
             var headerRow = $('#grid-header-row').empty();
             chart.columns.forEach(function(col) {
                 headerRow.append(`<th class="p-3 bg-slate-100/80 border border-slate-200 text-center font-black">${col}</th>`);
             });
             headerRow.append('<th class="p-3 bg-rose-50 text-rose-600 border border-slate-200 text-center w-16 font-black">اکشن</th>');

             // Rebuild body rows
             var gridBody = $('#grid-body').empty();
             chart.rows.forEach(function(r) {
                 var rId = 'r_' + Math.floor(Math.random() * 1000000);
                 var tr = $(`<tr id="${rId}" class="border-b border-slate-150"></tr>`);
                 chart.columns.forEach(function(col, cIdx) {
                     var cellVal = r['c' + cIdx] !== undefined ? r['c' + cIdx] : '';
                     tr.append(`<td class="p-2 border border-slate-200"><input type="text" value="${cellVal}" oninput="updateLivePreview()" class="w-full text-center p-2 border border-slate-100 rounded bg-slate-50/50 hover:bg-white text-xs font-bold focus:bg-white focus:ring-1 focus:ring-indigo-300 outline-none select-input-cell"></td>`);
                 });
                 tr.append(`<td class="p-2 border border-slate-200 text-center"><button type="button" onclick="$(this).closest('tr').remove(); updateLivePreview();" class="text-rose-600 font-bold hover:text-rose-500">حذف</button></td>`);
                 gridBody.append(tr);
             });

             if (chart.desc_values && Object.keys(chart.desc_values).length > 0) {
                 buildDescriptionFields('', chart.desc_values);
             }

             setBlockVisibility('table', true);
             $('#setting-show-table').prop('checked', true);
             scs_settings.show_table = 1;
             $('#preview-table-card').removeClass('hidden');

             $('#preview-cat').text(chart.category || 'عمومی');
             applyBrandingChanges();
             updateLivePreview();

             $('html, body').animate({ scrollTop: $('#tabcontent-editor').offset().top - 80 }, 400);
             showToast(`جدول «${chart.title}» در استودیو بارگذاری شد.`, 'success');
         }

         function rerenderAllQuickBatchImages() {
             if (quickBatchCharts.length === 0) return;
             $('#quick-progress-container').removeClass('hidden');
             renderAllQuickBatchCharts().then(function() {
                 $('#quick-progress-container').addClass('hidden');
                 displayQuickBatchResultsUI();
                 showToast('رندر مجدد تمام تصاویر به پایان رسید.', 'success');
             });
         }

         // =========================================================================
         // 8. ZIP DOWNLOAD & BULK EXPORT
         // =========================================================================

         async function downloadAllQuickBatchAsZip() {
             if (!quickBatchCharts || quickBatchCharts.length === 0) {
                 showToast('هیچ جدولی برای دانلود وجود ندارد.', 'warning');
                 return;
             }

             if (typeof JSZip === 'undefined' || typeof saveAs === 'undefined') {
                 showToast('کتابخانه‌های فشرده‌سازی ZIP بارگذاری نشده‌اند.', 'error');
                 return;
             }

             // Ensure all charts have rendered images
             var unrendered = quickBatchCharts.filter(function(c) { return !c.rendered_image_data_url; });
             if (unrendered.length > 0) {
                 showToast('در حال تکمیل رندر تصاویر برای ایجاد فایل ZIP...', 'info');
                 $('#quick-progress-container').removeClass('hidden');
                 await renderAllQuickBatchCharts();
                 $('#quick-progress-container').addClass('hidden');
                 displayQuickBatchResultsUI();
             }

             showToast('در حال فشرده‌سازی و ساخت آرشیو ZIP... 📦', 'info');

             var zip = new JSZip();
             var folder = zip.folder("SizeCharts");

             quickBatchCharts.forEach(function(chart, idx) {
                 if (chart.rendered_image_data_url) {
                     var base64Data = chart.rendered_image_data_url.replace(/^data:image\/jpeg;base64,/, "");
                     var cleanTitle = (chart.title || ('جدول_' + (idx + 1))).replace(/[^a-zA-Z0-9\u0600-\u06FF\s_-]/g, '').trim() || ('chart_' + (idx + 1));
                     var fileName = `${idx + 1}_${cleanTitle}.jpg`;
                     folder.file(fileName, base64Data, { base64: true });
                 }
             });

             // Add clean JSON backup
             var jsonExport = quickBatchCharts.map(function(c) {
                 return {
                     title: c.title,
                     category: c.category,
                     product_desc: c.product_desc,
                     model: c.model,
                     waist_type: c.waist_type,
                     product_notes: c.product_notes,
                     columns: c.columns,
                     rows: c.rows,
                     desc_values: c.desc_values
                 };
             });
             folder.file("size_charts_backup.json", JSON.stringify(jsonExport, null, 2));

             zip.generateAsync({ type: "blob" }).then(function(content) {
                 var now = new Date();
                 var dateStr = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
                 saveAs(content, `SizeCharts_Batch_Export_${dateStr}.zip`);
                 showToast('آرشیو فشرده ZIP با موفقیت تولید و دانلود شد. 🎉', 'success');
             }).catch(function(err) {
                 showToast('خطا در ساخت فایل ZIP: ' + err.message, 'error');
             });
         }

    </script>

    <!-- Bulk Import JSON Modal Container -->
    <div id="import-json-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl p-6 shadow-2xl border border-slate-150 max-w-2xl w-full max-h-[90vh] overflow-y-auto flex flex-col gap-5" dir="rtl">
            <div class="flex justify-between items-center pb-2 border-b border-slate-100">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>📥</span>
                    <span>درون‌ریزی آنلاین گروهی جداول سایز (قالب JSON)</span>
                </h3>
                <button onclick="$('#import-json-modal').addClass('hidden')" class="w-8 h-8 rounded-full bg-slate-50 hover:bg-slate-100 flex items-center justify-center text-slate-400 font-black text-xs transition-all">✕</button>
            </div>

            <div class="text-xs text-slate-500 leading-relaxed">
                محتوای JSON خروجی حاصل از سیستم قبلی خود را در جعبه متنی زیر درج نمایید. با ثبت اطلاعات، جداول مذکور در کتابخانه لوکال ساخته شده و قابل انتساب به محصولات خواهند بود.
            </div>

            <div class="space-y-2">
                <textarea id="json-import-textarea" rows="8" placeholder="[ { &quot;title&quot;: &quot;شلوار یاراش کتان&quot;, ... } ]" class="w-full text-[11px] font-mono p-4 border border-slate-205 rounded-xl bg-slate-50 outline-none focus:bg-white focus:ring-2 focus:ring-indigo-500/20" dir="ltr"></textarea>
            </div>

            <details class="bg-slate-50 rounded-xl border border-slate-200 cursor-pointer overflow-hidden">
                <summary class="text-xs font-black text-slate-700 p-3 select-none hover:bg-slate-100/50">📋 مشاهده نمونه الگو و مثال استاندارد JSON</summary>
                <div class="p-3 border-t border-slate-200">
                    <pre class="bg-slate-950 text-emerald-400 p-3 rounded-lg text-[9px] ltr text-left overflow-x-auto select-all leading-relaxed">[
  {
    "title": "شلوار کتان بهاره یاراش",
    "product_desc": "شلوار بهاره یاراش",
    "category": "شلوار",
    "preset_id": "pants_simple",
    "model": "راسته کلاسیک",
    "waist_type": "تمام کش",
    "columns": ["سایز محصول", "قد شلوار", "1/2دور کمر", "1/2دور ران", "دمپا"],
    "rows": [
      {"c0": "M", "c1": "100", "c2": "38", "c3": "30", "c4": "18"},
      {"c0": "L", "c1": "102", "c2": "40", "c3": "32", "c4": "19"}
    ],
    "desc_values": {
      "j_material": "کتان ۱۰۰٪ پنبه",
      "j_thickness": "متوسط"
    }
  }
]</pre>
                </div>
            </details>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                <button onclick="$('#import-json-modal').addClass('hidden')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black rounded-lg transition-all">انصراف</button>
                <button onclick="submitBulkImportJson()" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black rounded-lg transition-all shadow-md shadow-emerald-600/10">✔️ درون‌ریزی و ثبت گروهی</button>
            </div>
        </div>
    </div>

    <!-- Offscreen Batch Render Canvas (Dedicated high-res renderer that doesn't blink UI) -->
    <div id="scs-batch-render-container" style="position: fixed; left: -9999px; top: 0; pointer-events: none; z-index: -999; opacity: 1;">
        <div id="scs-batch-canvas" class="scs-canvas relative shrink-0 leading-normal overflow-hidden" dir="rtl" style="font-family: 'Vazirmatn', sans-serif; width: 800px; min-height: 1000px; background-color: #ffffff;">
            <div id="batch-canvas-bg-overlay" class="scs-bg-overlay absolute inset-0 pointer-events-none"></div>
            <div id="batch-canvas-content" class="scs-content-wrap relative z-10 flex flex-col h-full justify-between" style="padding: 36px;">
                <!-- Canvas elements will be dynamically injected here for html2canvas snapshots -->
            </div>
        </div>
    </div>

    <!-- Quick Batch Image Zoom Modal -->
    <div id="quick-image-zoom-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl p-6 shadow-2xl border border-slate-150 max-w-3xl w-full max-h-[90vh] overflow-y-auto flex flex-col gap-4" dir="rtl">
            <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                <h3 id="quick-modal-title" class="text-sm font-black text-slate-900">پیش‌نمایش تصویر جدول سایز</h3>
                <button onclick="$('#quick-image-zoom-modal').addClass('hidden')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-black text-xs transition-all">✕</button>
            </div>

            <div class="flex justify-center bg-slate-100 rounded-2xl p-4 overflow-hidden min-h-[300px] items-center">
                <img id="quick-modal-img" src="" class="max-h-[60vh] max-w-full rounded-xl shadow-md object-contain" alt="Quick Preview">
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <button onclick="$('#quick-image-zoom-modal').addClass('hidden')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all">بستن</button>
                <a id="quick-modal-download-btn" href="#" download="size_chart.jpg" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black rounded-xl transition-all flex items-center gap-1.5 shadow-md shadow-indigo-600/20">
                    <span>📥</span>
                    <span>دانلود این تصویر (JPG)</span>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
