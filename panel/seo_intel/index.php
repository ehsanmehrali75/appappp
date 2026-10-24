<?php
/**
 * Enterprise SEO & Analytics Intelligence Platform
 * Master Controller & UI Shell
 * 
 * Supports Multi-Business/Project Connections, Composio Integration Layer,
 * Data Warehouse Normalization, Real-time Rank Tracking & AI Recommendations.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load core system functions if available
$systemFuncs = __DIR__ . '/../inc/functions.php';
if (file_exists($systemFuncs)) {
    require_once $systemFuncs;
}

require_once __DIR__ . '/SeoDB.php';
require_once __DIR__ . '/ComposioService.php';
require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/SeoAIAnalyst.php';

require_once __DIR__ . '/../inc/ModuleProjectAccess.php';
$businessParam = $_GET['business'] ?? ($_GET['slug'] ?? ($_SESSION['project_id'] ?? null));
if ($businessParam === null && isMainAdmin()) $businessParam = SeoDB::getBusinesses()[0]['slug'] ?? null;
$currentBusiness = ModuleProjectAccess::business($businessParam);
ModuleProjectAccess::enforce('seo_intel', 'analysis');
$allBusinesses = ModuleProjectAccess::visibleBusinesses(SeoDB::getBusinesses());

// Determine current view
$view = $_GET['view'] ?? ($_GET['tab'] ?? 'overview');
$validViews = [
    'overview' => 'پیشخوان جامع',
    'rank_tracking' => 'ردیاب رتبه‌ها و میانگین',
    'keywords' => 'هوش کلمات کلیدی',
    'pages' => 'تحلیل صفحات و فرود',
    'links' => 'لینک‌سازی داخلی',
    'technical' => 'سئو تکنیکال',
    'traffic' => 'تحلیل ترافیک (GA4)',
    'content' => 'محتوا و درآمد',
    'social' => 'شبکه‌های اجتماعی',
    'recommendations' => 'پیشنهادات AI',
    'integrations' => 'یکپارچه‌سازی و Composio',
    'reports' => 'گزارشات و مقایسه‌ها'
];

// Screen visibility follows real session grants and explicit child restrictions.
if (!is_string($view) || !isset($validViews[$view])) $view = 'overview';
ModuleProjectAccess::enforce('seo_intel', 'analysis', ModuleProjectAccess::seoFeature($view));
$allowedViews = [];
foreach ($validViews as $candidate => $label) {
    if (ModuleProjectAccess::allowed('seo_intel', 'analysis', ModuleProjectAccess::seoFeature($candidate))) $allowedViews[] = $candidate;
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$insightProject=ModuleProjectAccess::businessProject($currentBusiness);if($insightProject){$insightSections=['rank_tracking'=>'keywords','keywords'=>'keywords','pages'=>'pages','traffic'=>'traffic','reports'=>'reports','integrations'=>'integrations','recommendations'=>'recommendations','technical'=>'tools','links'=>'tools','content'=>'pages','social'=>'tools'];header('Location: /panel/insights.php?'.http_build_query(['domain'=>'intelligence','project'=>$insightProject['id'],'section'=>$insightSections[$view]??'overview']));exit;}}

http_response_code(410);exit;
