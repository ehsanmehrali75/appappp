<?php
require_once 'inc/cors.php';
require_once 'inc/functions.php';
header('Content-Type: application/json');

// Global error handler for JSON responses
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        $msg = "PHP Error: [$errno] $errstr in $errfile on line $errline";
        error_log($msg . "\n", 3, "inc/debug.log");
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'msg' => $msg
        ]);
        exit();
    }
});

set_exception_handler(function($e) {
    $msg = "Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log($msg . "\n" . $e->getTraceAsString() . "\n", 3, "inc/debug.log");
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'msg' => $msg,
        'trace' => $e->getTraceAsString()
    ]);
    exit();
});

$action = $_REQUEST['action'] ?? $_REQUEST['ajax_action'] ?? null;
$pid = $_REQUEST['project_id'] ?? $_SERVER['HTTP_X_PROJECT_ID'] ?? null;

if (!$action) {
    echo json_encode(['status' => 'error', 'msg' => 'No action specified']);
    exit();
}

if (!$pid) {
    echo json_encode(['status' => 'error', 'msg' => 'Project ID is required']);
    exit();
}

$project = getCurrentProject($pid);
if (!$project) {
    echo json_encode(['status' => 'error', 'msg' => 'Project not found: ' . $pid]);
    exit();
}

// Action Handlers
if ($action === 'get_project_status') {
    $now = time();
    $tg_method = $project['settings']['connections']['telegram']['proxy']['bypass_method'] ?? 'none';
    $ig_method = $project['settings']['connections']['instagram']['proxy']['bypass_method'] ?? 'none';
    
    // Filter pending/future schedules
    $schedules = [];
    foreach ($project['schedules'] ?? [] as $s) {
        $status = $s['status'] ?? 'pending';
        $approvalStatus = $s['approvalStatus'] ?? 'approved';
        $scheduledAtRaw = $s['scheduledAt'] ?? 0;
        $scheduledAtTs = is_numeric($scheduledAtRaw) ? (int)$scheduledAtRaw : (strtotime($scheduledAtRaw) ?: 0);
        
        $s_item = [
            'id' => $s['id'] ?? '',
            'platform' => $s['platform'] ?? '',
            'productName' => $s['productName'] ?? 'پست سفارشی',
            'caption' => mb_substr($s['caption'] ?? '', 0, 50) . (mb_strlen($s['caption'] ?? '') > 50 ? '...' : ''),
            'scheduledAt' => $scheduledAtTs,
            'status' => $status,
            'approvalStatus' => $approvalStatus,
        ];
        $schedules[] = $s_item;
    }
    
    // Reverse logs and pick latest 50
    $logs = array_slice(array_reverse($project['logs'] ?? []), 0, 50);
    
    echo json_encode([
        'status' => 'success',
        'project_id' => $pid,
        'project_name' => $project['name'] ?? 'پروژه مارک‌شو',
        'telegram_bypass_method' => $tg_method,
        'instagram_bypass_method' => $ig_method,
        'telegram_config' => $project['settings']['connections']['telegram'] ?? [],
        'instagram_config' => $project['settings']['connections']['instagram'] ?? [],
        'schedules' => $schedules,
        'logs' => $logs
    ]);
    exit();
}

if ($action === 'get_pending_browser_relay_posts') {
    $posts = [];
    $now = time();
    
    foreach ($project['schedules'] ?? [] as $s) {
        $status = $s['status'] ?? 'pending';
        $approvalStatus = $s['approvalStatus'] ?? 'approved';
        $scheduledAtRaw = $s['scheduledAt'] ?? 0;
        $scheduledAtTs = is_numeric($scheduledAtRaw) ? (int)$scheduledAtRaw : (strtotime($scheduledAtRaw) ?: 0);
        
        if ($status === 'pending' && $approvalStatus === 'approved' && $scheduledAtTs <= $now) {
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
                // Find product
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
                        'name' => $s['productName'] ?? 'پست سفارشی',
                        'image' => $s['mediaUrl'] ?? '',
                        'gallery' => [],
                        'link' => '',
                        'price' => ''
                    ];
                }
                
                $schedule_date = ($scheduledAtTs > $now + 10) ? $scheduledAtTs : null;
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

if ($action === 'mark_relay_schedule_status') {
    $sid = $_REQUEST['schedule_id'] ?? '';
    $scheduleStatus = $_REQUEST['status'] ?? 'completed'; // completed / failed
    $errorMsg = $_REQUEST['error_message'] ?? '';
    
    $found = false;
    foreach ($project['schedules'] ?? [] as $idx => $s) {
        if (($s['id'] ?? '') === $sid) {
            $project['schedules'][$idx]['status'] = $scheduleStatus;
            
            if ($scheduleStatus === 'completed') {
                $productName = $s['productName'] ?? 'پست زمان‌بندی شده';
                $project['logs'][] = [
                    'at' => time(),
                    'type' => 'ارسال خودکار مینی‌اپ پایتون',
                    'productName' => $productName,
                    'platform' => $s['platform'] ?? ''
                ];
            } else {
                $productName = $s['productName'] ?? 'پست زمان‌بندی شده';
                $project['logs'][] = [
                    'at' => time(),
                    'type' => 'خطای ارسال مینی‌اپ پایتون',
                    'productName' => $productName,
                    'platform' => $s['platform'] ?? '',
                    'msg' => $errorMsg
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

if ($action === 'external_sync') {
    $products = $_POST['products'] ?? [];
    if (is_string($products)) {
        $products = json_decode($products, true);
    }
    
    if (!is_array($products)) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid products data format']);
        exit();
    }

    $newCount = 0;
    $updatedCount = 0;
    $toSave = [];
    
    // Optimize: Create map of existing products
    $productMap = [];
    foreach ($project['products'] as $idx => $p_exist) {
        if (!is_array($p_exist)) continue;
        $productMap[(string)$p_exist['id']] = $idx;
    }

    foreach ($products as $p) {
        $pId = (string)($p['id'] ?? $p['product_code'] ?? '');
        if (!$pId) continue;
        
        if (isset($productMap[$pId])) {
            $idx = $productMap[$pId];
            $project['products'][$idx] = mergeProductData($project['products'][$idx], $p, 'scraper');
            $updatedCount++;
            $toSave[] = $project['products'][$idx];
        } else {
            // New product
            $newP = [
                'id' => $pId,
                'sku' => (string)($p['sku'] ?? $p['product_code'] ?? $pId),
                'name' => (string)($p['name'] ?? 'محصول بدون نام'),
                'image' => (string)($p['image'] ?? ''),
                'link' => (string)($p['link'] ?? '#'),
                'price' => (string)($p['price'] ?? 'نامشخص'),
                'extractedAt' => time(),
                'manualEdit' => false,
                'likes' => 0,
                'dislikes' => 0,
                'userVotes' => [],
                'userFeedback' => [],
                'platformsSent' => [],
                'status' => 'pending',
                'gallery' => $p['gallery'] ?? []
            ];
            $project['products'][] = $newP;
            $productMap[$pId] = count($project['products']) - 1;
            $newCount++;
            $toSave[] = $newP;
        }
    }
    
    $saveRes = true;
    if (!empty($toSave)) {
        $saveRes = saveProject($project, $toSave);
    }
    
    if (!$saveRes) {
        echo json_encode(['status' => 'error', 'msg' => 'Failed to save project data to database. check permissions or SQL logs.']);
        exit();
    }
    
    echo json_encode([
        'status' => 'success',
        'project' => $project['name'],
        'new' => $newCount,
        'updated' => $updatedCount,
        'total' => count($project['products'])
    ]);
    exit();
}

if ($action === 'track_conversion') {
    $promo_code = strtoupper(trim($_REQUEST['promo_code'] ?? ''));
    $revenue = floatval($_REQUEST['revenue'] ?? 0);
    $orders = intval($_REQUEST['orders'] ?? 1);
    $clicks = intval($_REQUEST['clicks'] ?? 1);
    
    if (empty($promo_code)) {
        echo json_encode(['status' => 'error', 'msg' => 'Promo code is required']);
        exit();
    }
    
    $found = false;
    if (isset($project['settings']['campaigns']) && is_array($project['settings']['campaigns'])) {
        foreach ($project['settings']['campaigns'] as &$camp) {
            $camp_promo = strtoupper(trim($camp['promo_code'] ?? ''));
            if ($camp_promo !== '' && $camp_promo === $promo_code) {
                if (!isset($camp['perf_revenue'])) $camp['perf_revenue'] = 0;
                if (!isset($camp['perf_orders'])) $camp['perf_orders'] = 0;
                if (!isset($camp['perf_clicks'])) $camp['perf_clicks'] = 0;
                
                $camp['perf_revenue'] += $revenue;
                $camp['perf_orders'] += $orders;
                $camp['perf_clicks'] += $clicks;
                $found = true;
                break;
            }
        }
        unset($camp);
    }
    
    if ($found) {
        saveProject($project);
        echo json_encode([
            'status' => 'success',
            'msg' => 'Conversion tracked successfully',
            'promo_code' => $promo_code,
            'attributed_revenue' => $revenue,
            'attributed_orders' => $orders
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'msg' => 'No active campaign found matching promo code: ' . $promo_code
        ]);
    }
    exit();
}

echo json_encode(['status' => 'error', 'msg' => 'Unsupported action: ' . $action]);
