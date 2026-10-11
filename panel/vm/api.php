<?php
/**
 * Visual Merchandising Intelligence (VM) - REST API
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/vm_db.php';
require_once __DIR__ . '/vm_mflow.php';
require_once __DIR__ . '/vm_ai.php';

// Check access permission
$isSuper = function_exists('isSuperAdmin') ? isSuperAdmin() : false;
$isProjAdmin = function_exists('isProjectAdmin') ? isProjectAdmin() : false;
$hasVmPermission = function_exists('hasPermission') ? (hasPermission('vm') || hasPermission('layout')) : false;

if (!$isSuper && !$isProjAdmin && !$hasVmPermission) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی شما به ماژول چیدمان (VM) مسدود است.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'save_layout':
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) {
            $payload = $_POST;
        }

        $storeId = $payload['store_id'] ?? '';
        $layoutData = $payload['layout'] ?? [];

        if (empty($storeId) || empty($layoutData)) {
            echo json_encode(['success' => false, 'message' => 'شناسه فروشگاه یا اطلاعات چیدمان ارسال نشده است.']);
            exit;
        }

        $saved = VmDB::saveStoreLayout($storeId, $layoutData);
        echo json_encode(['success' => true, 'message' => 'چیدمان با موفقیت ذخیره شد.', 'updated_at' => date('Y/m/d H:i')]);
        break;

    case 'apply_recommendation':
        $storeId = $_REQUEST['store_id'] ?? '';
        $recId = $_REQUEST['rec_id'] ?? '';
        $res = VmAI::applyRecommendation($storeId, $recId);
        echo json_encode($res);
        break;

    case 'generate_ai_recommendations':
        $storeId = $_REQUEST['store_id'] ?? '';
        if (empty($storeId)) {
            $allP = VmDB::getAllProjects();
            $pId = !empty($allP) ? $allP[0]['id'] : '';
            $stList = VmDB::getStoresByProject($pId);
            $storeId = !empty($stList) ? $stList[0]['id'] : '';
        }
        $res = VmAI::generateSmartRecommendations($storeId);
        echo json_encode($res);
        break;

    case 'mflow_sync':
        $projectId = $_REQUEST['project_id'] ?? '';
        if (empty($projectId)) {
            $allP = VmDB::getAllProjects();
            $projectId = !empty($allP) ? $allP[0]['id'] : '';
        }
        $res = VmMflow::triggerSync($projectId);
        echo json_encode($res);
        break;

    case 'get_store_data':
        $storeId = $_REQUEST['store_id'] ?? '';
        $store = VmDB::getStore($storeId);
        $layout = VmDB::getStoreLayout($storeId);
        $scores = VmDB::calculateStoreScore($storeId);
        $recs = VmAI::getRecommendations($storeId);

        echo json_encode([
            'success' => true,
            'store' => $store,
            'layout' => $layout,
            'scores' => $scores,
            'recommendations' => $recs
        ]);
        break;

    case 'get_score':
        $storeId = $_REQUEST['store_id'] ?? '';
        $scores = VmDB::calculateStoreScore($storeId);
        echo json_encode(['success' => true, 'data' => $scores]);
        break;

    case 'get_stores':
        $projectId = $_REQUEST['project_id'] ?? '';
        if (empty($projectId)) {
            $allP = VmDB::getAllProjects();
            $projectId = !empty($allP) ? $allP[0]['id'] : '';
        }
        $stores = VmDB::getStoresByProject($projectId);
        echo json_encode(['success' => true, 'data' => $stores]);
        break;

    case 'get_products':
        $projectId = $_REQUEST['project_id'] ?? '';
        if (empty($projectId)) {
            $allP = VmDB::getAllProjects();
            $projectId = !empty($allP) ? $allP[0]['id'] : '';
        }
        $products = VmDB::getProductsByProject($projectId);
        echo json_encode(['success' => true, 'data' => $products]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر است.']);
        break;
}
