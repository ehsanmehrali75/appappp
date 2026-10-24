<?php
/**
 * Scraping Engine Cron / Manual Trigger
 * This file should be triggered every 1-5 minutes
 */
require_once 'inc/functions.php';
require_once __DIR__.'/inc/OnboardingCrawl.php';

// The global worker changes multiple projects; only CLI or an authenticated main administrator may run it.
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {header('Allow: POST');http_response_code(405);exit('POST required');}
    RequestSecurity::enforce();
    if (!isMainAdmin()) AccessControl::deny();
}

require_once __DIR__.'/inc/BackgroundWorker.php';
BackgroundWorker::begin();

// Deterministic 48-hour support-quote expiry; independent of scraper outcome.
try { require_once __DIR__ . '/inc/SupportTickets.php'; (new SupportTickets())->expire(); }
catch (Throwable $e) { error_log('Support quote expiry maintenance could not run.'); }


// Release session lock immediately so browser requests are never blocked
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

$logFile = __DIR__ . '/scraper/worker.log';
function logSystem($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] $msg\n";
    file_put_contents($logFile, $formatted, FILE_APPEND);
    echo $msg . "\n";
}

logSystem("[Cron] Checking for pending scraping jobs...");

if (!isSqlActive()) {
    die("[Cron] SQL is not active. Exiting.\n");
}

$conn = getConn();

// 1. Sync Job Statuses (Optional sanity check)
$staleTime=$conn instanceof SQLite3ToMysqliWrapper?"datetime('now', '-1 hour')":'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)';
$stuck = $conn ? $conn->query("SELECT COUNT(*) as count FROM scraping_jobs WHERE status = 'processing' AND started_at < $staleTime") : false;
if ($stuck && ($stuckRow = $stuck->fetch_assoc())) {
    $stuckCount = $stuckRow['count'] ?? 0;
    if ($stuckCount > 0) {
        logSystem("[Cron] Resetting $stuckCount stuck jobs...");
        $conn->query("UPDATE scraping_jobs SET status = 'pending' WHERE status = 'processing' AND started_at < $staleTime");
    }
}

// One plugin/API transport for configured WordPress projects; run with the configuring user's current grants.
try {
    require_once __DIR__.'/inc/WordPressWorkspace.php';$wordpress=new WordPressWorkspace();
    foreach($wordpress->principals() as $wpPid=>$principal){if(!BackgroundWorker::hasTime())break;try{AiAccess::run($principal,static function($p)use($wordpress){if(ProjectIntegrationProfile::get($p)['website_adapter']==='wordpress')$wordpress->tick($p);});}catch(Throwable $e){error_log('WordPress scheduled connection could not run: '.get_class($e));}}
} catch(Throwable $e){error_log('WordPress worker initialization failed: '.get_class($e));}

// 2. Process pending scraping jobs (Replacing Node.js worker)
$pendingJobs = $conn ? $conn->query("SELECT * FROM scraping_jobs WHERE status = 'pending' ORDER BY priority DESC, id ASC LIMIT " . BackgroundWorker::batchSize()) : false;
while ($pendingJobs && BackgroundWorker::hasTime() && ($job = $pendingJobs->fetch_assoc())) {
    $jid = $job['id'];
    $pid = $job['project_id'];
    $url = $job['url'];
    $type = $job['type'];

    // Human-like delay
    if (!isset($_GET['ajax'])) {
        usleep(rand(2000000, 5000000));
    } else {
        usleep(rand(200000, 500000)); // Much shorter delay for AJAX to avoid browser timeout
    }

    logSystem("[Scraper] Processing job $jid ($type) for project $pid: $url");
    if (isset($_GET['ajax'])) flush();
    
    // Mark as processing
    if(!$conn->query("UPDATE scraping_jobs SET status = 'processing', started_at = CURRENT_TIMESTAMP WHERE id = '$jid'")){logSystem('[Scraper] Could not claim job '.$jid);continue;}
    
    try {
        $project = getCurrentProject($pid);
        if (!$project) throw new Exception("Project not found");

        if(($project['settings']['platform_type']??'')==='wordpress'){
            $conn->query("UPDATE scraping_jobs SET status='failed', error_message='WordPress uses plugin API; separate crawl disabled' WHERE id='$jid'");continue;
        }

        $setupCrawl=strpos($type,'onboarding_')===0;$GLOBALS['onboarding_crawl']=$setupCrawl;
        $productJobType=$setupCrawl?'onboarding_product':'product_sync';
        $categoryJobType=$setupCrawl?'onboarding_category':'category_sync';
        if ($type === 'discovery' || $type === 'onboarding_discovery') {
            logSystem("[Scraper] Running discovery for $url...");
            
            // Check if this is already a product listing page
            $products = scrapeProductsFromCategory($url);
            if (count($products) > 0) {
                logSystem("[Scraper] Detected " . count($products) . " products on discovery page. Queuing for deep sync...");
                $newCount = 0;
                foreach ($products as $newP) {
                    $pUrl = $conn->real_escape_string($newP['link']);
                    $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                                  VALUES ('$pid', '$pUrl', '$productJobType', 'pending', 0)");
                    $newCount++;
                }
                $summary = "Queued $newCount product sync jobs from listing";
            } else {
                $categories = scrapeCategories($url);
                if($setupCrawl&&!$categories)throw new RuntimeException('هیچ محصول یا دسته‌بندی قابل خواندن پیدا نشد؛ از اپ دسکتاپ استفاده کنید.');
                logSystem("[Scraper] Found " . count($categories) . " categories.");
                foreach ($categories as $cat) {
                    $catUrl = $conn->real_escape_string($cat['url']);
                    $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                                  VALUES ('$pid', '$catUrl', '$categoryJobType', 'pending', 1)");
                }
                $summary = "Found " . count($categories) . " categories";
            }
        } elseif ($type === 'category_sync' || $type === 'onboarding_category') {
            $products = scrapeProductsFromCategory($url);
            logSystem("[Scraper] Found " . count($products) . " products in category. Queuing deep sync...");
            
            $newCount = 0;
            foreach ($products as $newP) {
                $pUrl = $conn->real_escape_string($newP['link']);
                $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                              VALUES ('$pid', '$pUrl', '$productJobType', 'pending', 0)");
                $newCount++;
            }
            $summary = "Queued $newCount product sync jobs";
        } elseif ($type === 'onboarding_product') {
            $deepData=getProductDeepData(['link'=>$url]);
            if(empty($deepData['sku'])&&empty($deepData['image'])&&empty($deepData['description']))throw new RuntimeException('صفحهٔ محصول قابل خواندن نبود؛ اپ دسکتاپ را امتحان کنید.');
            $count=OnboardingCrawl::collect($pid,$url,$deepData);
            $summary='Website observations saved for import preview; rows: '.$count;
        } elseif ($type === 'product_sync') {
/* ... */
            // Atomic product sync
            $dummyProduct = ['link' => $url, 'id' => md5($url), 'products' => []];
            $deepData = getProductDeepData($dummyProduct);
            
            // Update in project
            $found = false;
            foreach ($project['products'] as &$p) {
                if ($p['link'] === $url) {
                    $p = mergeProductData($p, $deepData, 'scraper');
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $deepData['status'] = 'pending';
                $deepData['extractedAt'] = time();
                $project['products'][] = $deepData;
            }
            saveProject($project);
            $summary = "Product data synced";
        } else {
            throw new Exception("Unknown job type: $type");
        }

        if(!$conn->query("UPDATE scraping_jobs SET status = 'completed', completed_at = CURRENT_TIMESTAMP, result_summary = '$summary' WHERE id = '$jid'"))throw new RuntimeException('ثبت نتیجهٔ صف ناموفق بود.');
        logSystem("[Scraper] ✅ Job $jid completed.");

    } catch (Throwable $e) {
        $msg = $conn->real_escape_string($e->getMessage());
        $conn->query("UPDATE scraping_jobs SET status = 'failed', error_message = '$msg' WHERE id = '$jid'");
        logSystem("[Scraper] ❌ Job $jid failed: " . $e->getMessage());
    }
    unset($GLOBALS['onboarding_crawl']);
}

// 3. Auto-Approve Products & Send Daily Aggregated Notifications
if (isSqlActive()) {
    $conn = getConn();
    $res = $conn->query("SELECT id FROM projects");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $proj = getCurrentProject($row['id']);
            if ($proj) {
                autoApprovePendingProducts($proj,true);
                checkAndSendDailyAggregatedNotifications($proj);
                saveProject($proj);
            }
        }
        logSystem("[System] Checked product auto-approvals and daily aggregated notifications (SQL).");
    }
} else {
    $db = getJsonDB();
    if (!empty($db['projects'])) {
        foreach ($db['projects'] as &$proj) {
            autoApprovePendingProducts($proj,true);
            checkAndSendDailyAggregatedNotifications($proj);
        }
        saveJsonDB($db);
        logSystem("[System] Checked product auto-approvals and daily aggregated notifications (JSON).");
    }
}

// 4. Dispatch Scheduled Posts
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("SELECT * FROM schedules WHERE status = 'pending' AND approval_status = 'approved' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT " . BackgroundWorker::batchSize());
if ($stmt) {
    $stmt->bind_param("s", $now);
    $stmt->execute();
    $tasks = $stmt->get_result();
    while (BackgroundWorker::hasTime() && ($task = $tasks->fetch_assoc())) {
        $tid = $task['id'];
        logSystem("[Scheduler] Dispatching post ID: $tid");
        
        $proj = getCurrentProject($task['project_id']);
        if ($proj) {
            $product = null;
            if ($task['product_id']) {
                foreach(($proj['products'] ?? []) as $p) {
                    if ($p['id'] == $task['product_id']) { $product = $p; break; }
                }
            }
            
            $plat = $task['platform'];
            
            require_once __DIR__.'/inc/ai/Social.php';
            if(AiSocial::route($proj['settings'],$plat)){
                try{$job=AiSocial::legacy($product?:[],$plat,$proj['settings'],$task['caption']??'',$task['media_url']??($product['social_image']??$product['image']??''),(int)($task['scheduledAt']??(strtotime($task['scheduled_at']??'')?:time())),(string)$tid,'post',$proj);
                    foreach($proj['schedules'] as &$chatSchedule)if((string)$chatSchedule['id']===(string)$tid)$chatSchedule['status']='awaiting_chatgpt';unset($chatSchedule);saveProject($proj);
                }catch(Throwable $e){logSystem('[ChatGPT] Queue preparation failed for scheduled publication.');}continue;
            }
            if ($plat === 'instagram_story') {
                logSystem("[Scheduler] Instagram Story ID: $tid is manual-only. Skipping auto-publish on server.");
                
                // Update in memory & direct SQL update
                $scheduleUpdated = false;
                if (isset($proj['schedules']) && is_array($proj['schedules'])) {
                    foreach ($proj['schedules'] as &$s) {
                        if (($s['id'] ?? '') === $tid) {
                            $s['status'] = 'completed';
                            $scheduleUpdated = true;
                            break;
                        }
                    }
                    unset($s);
                }
                if ($scheduleUpdated) {
                    saveProject($proj);
                } else {
                    $conn->query("UPDATE schedules SET status = 'completed' WHERE id = '$tid'");
                }
                continue;
            }
            
            // Skip sending for platforms using Browser Relay
            $is_browser_relay = false;
            $conf = $proj['settings']['connections'][$plat] ?? null;
            if ($conf) {
                if ($plat === 'telegram' || $plat === 'instagram') {
                    $bypass_method = $conf['proxy']['bypass_method'] ?? 'none';
                } else {
                    $bypass_method = $conf['bypass_method'] ?? 'none';
                }
                if ($bypass_method === 'browser_relay') {
                    $is_browser_relay = true;
                }
            }
            
            if ($is_browser_relay) {
                logSystem("[Scheduler] Skipping server-side send for ID: $tid on $plat. Handled by Browser Relay.");
                continue;
            }

            if ($plat === 'instagram') {
                logSystem("[Scheduler] Processing Instagram post via InstagramPublishHandler for ID: $tid...");
                if (class_exists('InstagramPublishHandler')) {
                    $igResult = InstagramPublishHandler::handle($task, $proj, $product);
                    $updatedStatus = $igResult['ok'] ? 'completed' : 'failed';
                    logSystem("[Scheduler] Instagram publish finished with status '$updatedStatus' for post ID: $tid.");
                    continue;
                }
            }

            logSystem("[Scheduler] Sending post to $plat...");
            
            // If it's a custom post (no product), we wrap it in a dummy product structure for sendToPlatform
            if (!$product) {
                $product = [
                    'id' => null,
                    'name' => 'Custom Post',
                    'image' => $task['media_url'] ?? '',
                    'gallery' => [],
                    'link' => '',
                    'price' => ''
                ];
            }

            $sent = sendToPlatform($product, $plat, $proj['settings'], $task['caption'] ?? '', null, $task['media_url'] ?? '');
            $updatedStatus = ($sent === true) ? 'completed' : 'failed';
            
            // Synchronously update the in-memory array and save of the project
            $scheduleUpdated = false;
            if (isset($proj['schedules']) && is_array($proj['schedules'])) {
                foreach ($proj['schedules'] as &$s) {
                    if (($s['id'] ?? '') === $tid) {
                        $s['status'] = $updatedStatus;
                        $scheduleUpdated = true;
                        break;
                    }
                }
                unset($s);
            }
            
            if ($scheduleUpdated) {
                saveProject($proj);
                logSystem("[Scheduler] Saved updated project status to '$updatedStatus' for post ID: $tid.");
            } else {
                // Direct DB update in case it was somehow not in $proj['schedules'] list
                $conn->query("UPDATE schedules SET status = '$updatedStatus' WHERE id = '$tid'");
                logSystem("[Scheduler] Direct DB update to '$updatedStatus' for post ID: $tid (fallback).");
            }
        }
    }
}

// 5. System Status
logSystem("[Status] Pure PHP Mode Active. System is handling scraping and scheduling.");

// Analytics is ingested only by project-owned Google/Composio adapters.
// Never manufacture statistics when credentials or upstream data are absent.
logSystem("[Cron] Task execution finished.");
