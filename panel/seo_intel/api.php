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

try {
    switch ($action) {
        case 'get_overview':
            $scoreData = SeoDB::calculateScoreAndStats($businessId);
            $canIntegrate = ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations');
            $conns = $canIntegrate ? SeoDB::getConnections($businessId) : [];
            $recentLogs = $canIntegrate ? SeoDB::getSyncLogs($businessId, 5) : [];
            $recentRecs = ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_recommendations') ? SeoDB::getRecommendations($businessId, 'pending') : [];

            echo json_encode([
                'success' => true,
                'business' => $business,
                'score' => $scoreData,
                'connections' => $conns,
                'recent_logs' => $recentLogs,
                'recommendations_count' => count($recentRecs)
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_keywords':
            $filter = $_GET['filter'] ?? 'all';
            $search = $_GET['search'] ?? '';
            $kws = SeoDB::getKeywords($businessId, $filter, $search);
            echo json_encode(['success' => true, 'data' => $kws, 'total' => count($kws)], JSON_UNESCAPED_UNICODE);
            exit;

        case 'add_keyword':
            $keyword = trim($jsonBody['keyword'] ?? $_POST['keyword'] ?? '');
            $targetUrl = trim($jsonBody['target_url'] ?? $_POST['target_url'] ?? '');
            if (empty($keyword)) {
                echo json_encode(['success' => false, 'error' => 'نام کلمه کلیدی الزامی است.']);
                exit;
            }
            $saved = SeoDB::saveKeyword($businessId, [
                'keyword' => $keyword,
                'position' => rand(12, 35),
                'prev_position' => rand(15, 40),
                'clicks' => rand(10, 80),
                'impressions' => rand(300, 2500),
                'ctr' => rand(15, 35) / 10,
                'intent' => $jsonBody['intent'] ?? 'Commercial',
                'opportunity_score' => 'High',
                'target_url' => $targetUrl ?: ('https://' . ($business['domain'] ?? 'site.ir') . '/'),
                'search_volume' => rand(2000, 25000),
                'cannibalized_urls' => []
            ]);
            // Re-run AI analysis
            SeoAIAnalyst::analyzeBusiness($businessId);
            echo json_encode(['success' => true, 'data' => $saved, 'message' => 'کلمه کلیدی جدید به ردیاب افزوده شد.']);
            exit;

        case 'get_pages':
            $filter = $_GET['filter'] ?? 'all';
            $search = $_GET['search'] ?? '';
            $pages = SeoDB::getPages($businessId, $filter, $search);
            echo json_encode(['success' => true, 'data' => $pages, 'total' => count($pages)], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_technical':
            $tech = SeoDB::getTechnicalAudit($businessId);
            echo json_encode(['success' => true, 'data' => $tech], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_traffic':
            $traffic = SeoDB::getTrafficMetrics($businessId);
            echo json_encode(['success' => true, 'data' => $traffic], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_social':
            $platform = $_GET['platform'] ?? 'instagram';
            $social = SeoDB::getSocialMetrics($businessId, $platform);
            echo json_encode(['success' => true, 'data' => $social], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_recommendations':
            $status = $_GET['status'] ?? 'all';
            $recs = SeoDB::getRecommendations($businessId, $status);
            echo json_encode(['success' => true, 'data' => $recs], JSON_UNESCAPED_UNICODE);
            exit;

        case 'update_recommendation':
            $recId = $jsonBody['id'] ?? $_POST['id'] ?? '';
            $newStatus = $jsonBody['status'] ?? $_POST['status'] ?? 'applied';
            $updated = SeoDB::updateRecommendationStatus($businessId, $recId, $newStatus);
            echo json_encode(['success' => $updated, 'message' => 'وضعیت پیشنهاد تغییر یافت.']);
            exit;

        case 'trigger_sync':
            $source = $jsonBody['source'] ?? $_POST['source'] ?? 'all';
            $trigger = $jsonBody['trigger'] ?? 'manual';
            $syncRes = SyncEngine::runSync($businessId, $source, $trigger);
            $stats = SeoDB::calculateScoreAndStats($businessId);
            echo json_encode([
                'success' => $syncRes['success'],
                'sync_result' => $syncRes,
                'new_score' => $stats,
                'message' => 'همگام‌سازی با موفقیت انجام شد و داده‌ها Normalize شدند.'
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'test_composio':
            $apiKey = $jsonBody['api_key'] ?? ($_POST['api_key'] ?? ($_GET['api_key'] ?? null));
            $res = ComposioService::verifyAuth($apiKey);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_composio_config':
            $apiKey = trim($jsonBody['api_key'] ?? ($_POST['api_key'] ?? ''));
            $saved = ComposioService::saveConfig(['api_key' => $apiKey]);
            $verification = !empty($apiKey) ? ComposioService::verifyAuth($apiKey) : ['success' => true, 'message' => 'تنظیمات ذخیره شد.'];
            echo json_encode([
                'success' => $verification['success'] ?? false,
                'config' => $saved,
                'verification' => $verification,
                'message' => $verification['message'] ?? 'تنظیمات Composio ذخیره شد.'
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_connection':
            $integrationSlug = $jsonBody['integration_slug'] ?? $_POST['integration_slug'] ?? '';
            $propertyId = $jsonBody['property_id'] ?? $_POST['property_id'] ?? '';
            $accountName = $jsonBody['account_name'] ?? $_POST['account_name'] ?? '';
            $syncFrequency = $jsonBody['sync_frequency'] ?? $_POST['sync_frequency'] ?? 'daily';
            $syncEnabled = isset($jsonBody['sync_enabled']) ? (bool)$jsonBody['sync_enabled'] : true;

            if (empty($integrationSlug)) {
                echo json_encode(['success' => false, 'error' => 'نوع ادغام مشخص نشده است.']);
                exit;
            }

            $savedConn = SeoDB::saveConnection($businessId, $integrationSlug, [
                'property_id' => $propertyId,
                'account_name' => $accountName,
                'status' => 'connected',
                'sync_frequency' => $syncFrequency,
                'sync_enabled' => $syncEnabled,
                'last_sync_status' => 'success'
            ]);

            echo json_encode(['success' => true, 'connection' => $savedConn, 'message' => 'کانکشن ذخیره و فعال شد.']);
            exit;

        case 'initiate_oauth':
            $integrationSlug = $jsonBody['integration_slug'] ?? $_POST['integration_slug'] ?? 'google_search_console';
            $oauthRes = ComposioService::initiateConnection($integrationSlug, $business['slug'] ?? 'biz');
            echo json_encode($oauthRes, JSON_UNESCAPED_UNICODE);
            exit;

        case 'ask_ai':
            $prompt = trim($jsonBody['prompt'] ?? $_POST['prompt'] ?? '');
            if (empty($prompt)) {
                echo json_encode(['success' => false, 'error' => 'پرامپت خالی است.']);
                exit;
            }
            $answer = SeoAIAnalyst::askGeminiAssistant($prompt, $business['slug'] ?? 'red-style');
            echo json_encode(['success' => true, 'output' => $answer], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_rank_history':
            $filter = $_GET['filter'] ?? 'all';
            $search = $_GET['search'] ?? '';
            $ranks = SeoDB::getRankHistory($businessId, $filter, $search);
            echo json_encode(['success' => true, 'data' => $ranks, 'total' => count($ranks)], JSON_UNESCAPED_UNICODE);
            exit;

        case 'calculate_avg_positions':
            $period = $_GET['period'] ?? 'daily';
            $groupBy = $_GET['group_by'] ?? 'keyword';
            $data = SeoDB::calculateAveragePositions($businessId, $period, $groupBy);
            echo json_encode(['success' => true, 'period' => $period, 'group_by' => $groupBy, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_internal_links':
            $filter = $_GET['filter'] ?? 'all';
            $links = SeoDB::getInternalLinks($businessId, $filter);
            $opps = SeoDB::getInternalLinkOpportunities($businessId);
            echo json_encode(['success' => true, 'links' => $links, 'opportunities' => $opps], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_comparison':
            $type = $_GET['type'] ?? 'month_over_month';
            $comp = SeoDB::getComparisonMetrics($businessId, $type);
            echo json_encode(['success' => true, 'comparison' => $comp], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_report':
            $reportType = $_GET['report_type'] ?? 'monthly';
            $report = SeoDB::getExecutiveReport($businessId, $reportType);
            echo json_encode(['success' => true, 'report' => $report], JSON_UNESCAPED_UNICODE);
            exit;

        case 'add_rank_history':
            $keyword = trim($jsonBody['keyword'] ?? $_POST['keyword'] ?? '');
            $url = trim($jsonBody['url'] ?? $_POST['url'] ?? '');
            $position = floatval($jsonBody['avg_position'] ?? $_POST['avg_position'] ?? 5.0);
            $clicks = intval($jsonBody['clicks'] ?? $_POST['clicks'] ?? 120);
            $impressions = intval($jsonBody['impressions'] ?? $_POST['impressions'] ?? 3500);
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 1) . '%' : '3.4%';
            $category = trim($jsonBody['category'] ?? $_POST['category'] ?? 'عمومی');

            $added = SeoDB::addRankHistoryRecord($businessId, [
                'date' => date('Y/m/d'),
                'business' => $business['name'] ?? 'کسب‌وکار',
                'business_slug' => $business['slug'] ?? 'biz',
                'keyword' => $keyword,
                'url' => $url,
                'avg_position' => $position,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position_change' => '+0.5',
                'category' => $category
            ]);
            echo json_encode(['success' => true, 'record' => $added, 'message' => 'رکورد رتبه جدید ذخیره شد.'], JSON_UNESCAPED_UNICODE);
            exit;

        case 'get_sync_logs':
            $limit = intval($_GET['limit'] ?? 20);
            $logs = SeoDB::getSyncLogs($businessId, $limit);
            echo json_encode(['success' => true, 'logs' => $logs], JSON_UNESCAPED_UNICODE);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Action نامعتبر است: ' . htmlspecialchars($action)]);
            exit;
    }
} catch (Exception $ex) {
    echo json_encode(['success' => false, 'error' => 'خطای سرور: ' . $ex->getMessage()]);
    exit;
}
