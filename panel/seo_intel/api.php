<?php
/**
 * Enterprise SEO & Analytics Intelligence - REST API
 * Handles AJAX actions for data fetching, synchronization, Composio integrations,
 * and live AI Assistant requests.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$fn = __DIR__ . '/../inc/functions.php';
if (file_exists($fn)) require_once $fn;

require_once __DIR__ . '/SeoDB.php';
require_once __DIR__ . '/ComposioService.php';
require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/SeoAIAnalyst.php';

require_once __DIR__ . '/../inc/ModuleProjectAccess.php';
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true);
if (!is_array($jsonBody)) $jsonBody = [];
$input = array_replace($_GET, $_POST, $jsonBody);
$action = $input['action'] ?? '';
if (!is_string($action)) AccessControl::deny();
$readActions = ['get_overview', 'get_keywords', 'get_pages', 'get_technical', 'get_traffic', 'get_social',
    'get_recommendations', 'get_rank_history', 'calculate_avg_positions', 'get_internal_links', 'get_comparison', 'get_report', 'get_sync_logs'];
$mutable = !in_array($action, $readActions, true);
if ($mutable) ModuleProjectAccess::mutation();
$businessSlug = $input['business'] ?? ($_SESSION['project_id'] ?? null);
if ($businessSlug === null && isMainAdmin()) $businessSlug = SeoDB::getBusinesses()[0]['slug'] ?? null;
$business = ModuleProjectAccess::business($businessSlug);
$businessId = $business['id'];
$actionFeature = ['get_keywords'=>'keywords', 'add_keyword'=>'keywords', 'get_rank_history'=>'rank_tracking',
    'calculate_avg_positions'=>'rank_tracking', 'add_rank_history'=>'rank_tracking', 'get_pages'=>'pages',
    'get_technical'=>'technical', 'get_traffic'=>'traffic', 'get_social'=>'social', 'get_recommendations'=>'recommendations',
    'update_recommendation'=>'recommendations', 'ask_ai'=>'recommendations', 'get_internal_links'=>'links',
    'get_comparison'=>'reports', 'get_report'=>'reports', 'get_sync_logs'=>'integrations', 'trigger_sync'=>'integrations',
    'test_composio'=>'integrations', 'save_composio_config'=>'integrations', 'save_connection'=>'integrations', 'initiate_oauth'=>'integrations'][$action] ?? 'overview';
ModuleProjectAccess::enforce('seo_intel', 'analysis', ModuleProjectAccess::seoFeature($actionFeature), $mutable ? 'use' : 'view');
// The Composio credential configures the entire installation, not one business.
if (in_array($action, ['save_composio_config', 'test_composio'], true) && !isMainAdmin()) AccessControl::deny();

$project=ModuleProjectAccess::businessProject($business);
if($mutable){http_response_code(410);echo json_encode(['success'=>false,'status'=>'retired','error'=>'این مسیر قدیمی بازنشسته شده است؛ اتصال واقعی پروژه را در مرکز اتصال‌ها تنظیم کنید.','url'=>'/panel/connections.php?project='.rawurlencode($project['id'])],JSON_UNESCAPED_UNICODE);exit;}
// Unverified legacy warehouse rows are never represented as live observations.
echo json_encode(['success'=>true,'status'=>'use_project_sources','business'=>array_intersect_key($business,array_flip(['id','name','slug','domain'])),'data'=>[],'total'=>0,'message'=>'دادهٔ معتبر از منابع پروژه در مرکز داده قابل دریافت است.','url'=>'/panel/insights.php?'.http_build_query(['project'=>$project['id'],'domain'=>'intelligence'])],JSON_UNESCAPED_UNICODE);exit;
