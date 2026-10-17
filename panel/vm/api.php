<?php
/**
 * Visual Merchandising Intelligence (VM) - REST API
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/vm_db.php';
require_once __DIR__ . '/vm_mflow.php';
require_once __DIR__ . '/vm_ai.php';

require_once __DIR__ . '/../inc/ModuleProjectAccess.php';
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = [];
$input = array_replace($_GET, $_POST, $payload);
$action = $input['action'] ?? '';
if (!is_string($action)) AccessControl::deny();
$mutable = in_array($action, ['save_layout', 'apply_recommendation', 'generate_ai_recommendations', 'mflow_sync'], true);
if ($mutable) ModuleProjectAccess::mutation();
$projectId = $input['project_id'] ?? ($input['project'] ?? ($_SESSION['project_id'] ?? null));
$project = ModuleProjectAccess::select($projectId);
$projectId = $project['id'];
$actionFeature = ['save_layout'=>'vm_layout', 'apply_recommendation'=>'vm_ai', 'generate_ai_recommendations'=>'vm_ai',
    'mflow_sync'=>'vm_studio', 'get_store_data'=>'vm_stores', 'get_score'=>'vm_scores', 'get_stores'=>'vm_stores', 'get_products'=>'vm_studio'][$action] ?? 'vm_studio';
ModuleProjectAccess::enforce('vm_studio', 'vm', $actionFeature, $mutable ? 'use' : 'view');
$storeId = $input['store_id'] ?? '';
if (in_array($action, ['save_layout', 'apply_recommendation', 'generate_ai_recommendations', 'get_store_data', 'get_score'], true)) {
    if ($action === 'generate_ai_recommendations' && $storeId === '') {
        $stores = VmDB::getStoresByProject($projectId);
        $storeId = $stores[0]['id'] ?? '';
    }
    if (!is_string($storeId) || $storeId === '') AccessControl::deny();
    $store = VmDB::getStore($storeId, $projectId);
    if (!$store) AccessControl::deny();
    $storeId = $store['id'];
}

switch ($action) {
    case 'save_layout':
        $layoutData = $input['layout'] ?? [];

        if (empty($storeId) || !is_array($layoutData) || empty($layoutData)) {
            echo json_encode(['success' => false, 'message' => 'شناسه فروشگاه یا اطلاعات چیدمان ارسال نشده است.']);
            exit;
        }

        $saved = VmDB::saveStoreLayout($storeId, $layoutData);
        echo json_encode(['success' => true, 'message' => 'چیدمان با موفقیت ذخیره شد.', 'updated_at' => date('Y/m/d H:i')]);
        break;

    case 'apply_recommendation':
        $recId = $input['rec_id'] ?? '';
        $res = VmAI::applyRecommendation($storeId, $recId);
        echo json_encode($res);
        break;

    case 'generate_ai_recommendations':
        $res = VmAI::generateSmartRecommendations($storeId);
        echo json_encode($res);
        break;

    case 'mflow_sync':
        $res = VmMflow::triggerSync($projectId);
        echo json_encode($res);
        break;

    case 'get_store_data':
        $store = VmDB::getStore($storeId, $projectId);
        $layout = ModuleProjectAccess::allowed('vm_studio', 'vm', 'vm_layout') ? VmDB::getStoreLayout($storeId) : null;
        $scores = ModuleProjectAccess::allowed('vm_studio', 'vm', 'vm_scores') ? VmDB::calculateStoreScore($storeId) : [];
        $recs = ModuleProjectAccess::allowed('vm_studio', 'vm', 'vm_ai') ? VmAI::getRecommendations($storeId) : [];

        echo json_encode([
            'success' => true,
            'store' => $store,
            'layout' => $layout,
            'scores' => $scores,
            'recommendations' => $recs
        ]);
        break;

    case 'get_score':
        $scores = VmDB::calculateStoreScore($storeId);
        echo json_encode(['success' => true, 'data' => $scores]);
        break;

    case 'get_stores':
        $stores = VmDB::getStoresByProject($projectId);
        echo json_encode(['success' => true, 'data' => $stores]);
        break;

    case 'get_products':
        $products = VmDB::getProductsByProject($projectId);
        echo json_encode(['success' => true, 'data' => $products]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر است.']);
        break;
}
