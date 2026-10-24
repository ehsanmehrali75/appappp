<?php
/**
 * Scraping Engine Cron / Manual Trigger
 * This file should be triggered every 1-5 minutes
 */
require_once 'inc/functions.php';

// The global worker changes multiple projects; only CLI or an authenticated main administrator may run it.
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {header('Allow: POST');http_response_code(405);exit('POST required');}
    RequestSecurity::enforce();
    if (!isMainAdmin()) AccessControl::deny();
}

require_once __DIR__.'/panel/inc/ProjectBackups.php';
require_once __DIR__.'/panel/inc/InventoryVersions.php';
try{ProjectBackups::weekly();}catch(Throwable $e){error_log('Project backup maintenance could not run; check private storage permissions.');}

require_once __DIR__.'/panel/inc/BackgroundWorker.php';
BackgroundWorker::begin();

// Deterministic 48-hour support-quote expiry; independent of scraper outcome.
try { require_once __DIR__ . '/panel/inc/SupportTickets.php'; (new SupportTickets())->expire(); }
catch (Throwable $e) { error_log('Support quote expiry maintenance could not run.'); }


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
$stuck = $conn ? $conn->query("SELECT COUNT(*) as count FROM scraping_jobs WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)") : false;
if ($stuck && ($stuckRow = $stuck->fetch_assoc())) {
    $stuckCount = $stuckRow['count'] ?? 0;
    if ($stuckCount > 0) {
        logSystem("[Cron] Resetting $stuckCount stuck jobs...");
        $conn->query("UPDATE scraping_jobs SET status = 'pending' WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}

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
    $conn->query("UPDATE scraping_jobs SET status = 'processing', started_at = NOW() WHERE id = '$jid'");
    
    try {
        $project = getCurrentProject($pid);
        if(!empty($project['settings']['backup_restore_paused']))continue;
        if (!$project) throw new Exception("Project not found");

        if ($type === 'discovery') {
            logSystem("[Scraper] Running discovery for $url...");
            
            // Check if this is already a product listing page
            $products = scrapeProductsFromCategory($url);
            if (count($products) > 0) {
                logSystem("[Scraper] Detected " . count($products) . " products on discovery page. Queuing for deep sync...");
                $newCount = 0;
                foreach ($products as $newP) {
                    $pUrl = $conn->real_escape_string($newP['link']);
                    $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                                  VALUES ('$pid', '$pUrl', 'product_sync', 'pending', 0)");
                    $newCount++;
                }
                $summary = "Queued $newCount product sync jobs from listing";
            } else {
                $categories = scrapeCategories($url);
                logSystem("[Scraper] Found " . count($categories) . " categories.");
                foreach ($categories as $cat) {
                    $catUrl = $conn->real_escape_string($cat['url']);
                    $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                                  VALUES ('$pid', '$catUrl', 'category_sync', 'pending', 1)");
                }
                $summary = "Found " . count($categories) . " categories";
            }
        } elseif ($type === 'category_sync') {
            $products = scrapeProductsFromCategory($url);
            logSystem("[Scraper] Found " . count($products) . " products in category. Queuing deep sync...");
            
            $newCount = 0;
            foreach ($products as $newP) {
                $pUrl = $conn->real_escape_string($newP['link']);
                $conn->query("INSERT IGNORE INTO scraping_jobs (project_id, url, type, status, priority) 
                              VALUES ('$pid', '$pUrl', 'product_sync', 'pending', 0)");
                $newCount++;
            }
            $summary = "Queued $newCount product sync jobs";
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

        $conn->query("UPDATE scraping_jobs SET status = 'completed', completed_at = NOW(), result_summary = '$summary' WHERE id = '$jid'");
        logSystem("[Scraper] ✅ Job $jid completed.");

    } catch (Exception $e) {
        $msg = $conn->real_escape_string($e->getMessage());
        $conn->query("UPDATE scraping_jobs SET status = 'failed', error_message = '$msg' WHERE id = '$jid'");
        logSystem("[Scraper] ❌ Job $jid failed: " . $e->getMessage());
    }
}

// 3. Auto-Approve Products & Send Daily Aggregated Notifications
if (isSqlActive()) {
    $conn = getConn();
    $res = $conn->query("SELECT id FROM projects");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $proj = getCurrentProject($row['id']);
            if ($proj && empty($proj['settings']['backup_restore_paused'])) {
                autoApprovePendingProducts($proj);
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
            autoApprovePendingProducts($proj);
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
        if ($proj && empty($proj['settings']['backup_restore_paused'])) {
            $product = null;
            if ($task['product_id']) {
                foreach(($proj['products'] ?? []) as $p) {
                    if ($p['id'] == $task['product_id']) { $product = $p; break; }
                }
            }
            
            $plat = $task['platform'];
            
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

// Google/Composio observations are received only by panel/integration_worker.php.
// Missing credentials never produce replacement analytics history.
logSystem("[Cron] Task execution finished.");
