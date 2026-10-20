<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/TiamInventory.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function tiamReply($value, $status = 200) { http_response_code($status); echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tiamReply(['error' => 'POST required'], 405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8*1024*1024) tiamReply(['error' => 'Payload too large'], 413);
    $raw = file_get_contents('php://input', false, null, 0, 8*1024*1024+1);
    if (strlen($raw) > 8*1024*1024) tiamReply(['error' => 'Payload too large'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) tiamReply(['error' => 'Invalid JSON'], 400);
    $id = $body['project'] ?? null; $action = $body['action'] ?? '';
    if (!is_string($id) || $id === '') tiamReply(['error' => 'Project required'], 400);
    $project = getCurrentProject($id, true);
    if (!$project) tiamReply(['error' => 'Project not found'], 404);
    $cfg = $project['settings']['tiam_inventory'] ?? [];
    $agent = in_array($action, ['agent_poll', 'agent_result'], true);
    if ($agent) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $localTest = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$https && !$localTest) tiamReply(['error' => 'HTTPS required'], 400);
        $token = $_SERVER['HTTP_X_TIAM_TOKEN'] ?? '';
        if (!TiamInventory::agentAuthorized($cfg,$token,$body['agent_token']??'')) tiamReply(['error'=>'Unauthorized agent','code'=>'TIAM_AGENT_AUTH_FAILED'],401);
    } else {
        if (!isUser()) tiamReply(['error' => 'Login required'], 401);
        if (!isMainAdmin()) {
            $member = SessionIdentity::member($project);
            if (!$member) AccessControl::deny();
            $_SESSION['user'] = $member;
        }
        $_SESSION['project_id'] = $id;
        if (!hasPermission('settings') || !isModuleActive('settings') || !AccessControl::allowed('settings', 'use')) AccessControl::deny();
        if (empty($body['csrf']) || !hash_equals($_SESSION['tiam_csrf'] ?? '', $body['csrf'])) tiamReply(['error' => 'Invalid CSRF token'], 403);
        if (in_array($action, ['preview', 'apply', 'export_manual'], true) && (!hasPermission('products') || !isModuleActive('products') || !AccessControl::allowed('products', 'use'))) AccessControl::deny();
    }
    $response = TiamInventory::locked($id, function() use ($id, $action, $body, $agent) {
        $project = getCurrentProject($id, true); $cfg = $project['settings']['tiam_inventory'] ?? [];
        $job = TiamInventory::read($id);
        if ($action === 'pair') {
            $token=bin2hex(random_bytes(32));$project['settings']['tiam_inventory']['agent_token_hash']=hash('sha256',$token);
            if(!saveProject($project))throw new RuntimeException('ثبت کلید اتصال ناموفق بود.');
            return ['status'=>'paired','connection'=>['version'=>1,'panel_url'=>'https://maarksho.com/panel/tiam_inventory.php','project_id'=>$id,'agent_token'=>$token]];
        }
        if ($action === 'save') {
            $project['settings']['tiam_inventory'] = TiamInventory::config($cfg, $body['config'] ?? []);
            if (!empty($project['settings']['tiam_inventory']['enabled'])) $project['settings']['tiam']['enabled'] = 0;
            if (!saveProject($project)) throw new RuntimeException('ذخیرهٔ تنظیمات ناموفق بود.');
            return ['status' => 'saved'];
        }
        if (in_array($action, ['vpn_test', 'view_test', 'preview'], true)) {
            if (empty($cfg['agent_token_hash'])) throw new RuntimeException('ابتدا تنظیمات و کلید ایجنت را ذخیره کنید.');
            $job = TiamInventory::createJob($project, $action, TiamInventory::owner());
        } elseif ($action === 'export_manual') {
            if (empty($job['erp_items']) || !is_string($body['job']??null) || !hash_equals($job['id']??'',$body['job']) || ($job['state']??'')!=='preview' || ($job['expires']??0)<=time() || !hash_equals($job['owner']??'',TiamInventory::owner()) || !hash_equals($job['base']??'',TiamInventory::base($project)) || !hash_equals($job['config_hash']??'',TiamInventory::fingerprint($cfg))) throw new RuntimeException('برای خروجی دستی، پیش‌نمایش معتبر متعلق به همین نشست لازم است.');
            return ['format'=>ERPInventoryContract::VERSION,'rows'=>array_map([ERPInventoryContract::class,'row'],$job['erp_items']??[])];
        } elseif ($action === 'cancel') {
            $job = TiamInventory::cancelJob($id,$body['job']??'',TiamInventory::owner(),isMainAdmin() || isProjectAdmin());
        } elseif ($action === 'latest') {
            // Settings users can inspect the latest scheduled run across sessions.
        } elseif ($action === 'status') {
            if (!hash_equals($job['owner'] ?? '', TiamInventory::owner()) || !hash_equals($job['id'] ?? '', $body['job'] ?? '')) throw new RuntimeException('درخواست معتبر نیست یا متعلق به نشست دیگری است.');
            if (($job['expires'] ?? 0) < time() && !in_array($job['state'] ?? '', ['applied', 'done', 'error'], true)) { $job['state'] = 'expired'; }
        } elseif ($action === 'apply') {
            if (!hash_equals($job['owner'] ?? '', TiamInventory::owner()) || !hash_equals($job['id'] ?? '', $body['job'] ?? '')) throw new RuntimeException('پیش‌نمایش متعلق به این نشست نیست.');
            $job = TiamInventory::apply($project, $job);
        } elseif ($action === 'agent_poll') {
            $job['agent_last_seen'] = time();
            TiamInventory::write($id,$job);
            $pending = in_array($job['state'] ?? '', ['queued', 'running'], true) && ($job['expires'] ?? 0) > time();
            $waitingForConfirmation = ($job['state'] ?? '') === 'preview' && ($job['expires'] ?? 0) > time();
            if (!$pending && !$waitingForConfirmation && TiamInventory::due($cfg)) {
                $project['settings']['tiam_inventory']['last_attempt'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
                if (!saveProject($project)) throw new RuntimeException('ذخیرهٔ زمان تلاش ناموفق بود.');
                $job = TiamInventory::createJob($project, 'scheduled'); $pending = true;
            }
            if (!$pending) return ['status' => 'idle'];
            // A job is leased for 15 minutes. Polling never starts it twice.
            if (($job['state'] ?? '') === 'running') return ['status' => 'idle'];
            $job['state'] = 'running'; $job['started_at'] = time(); TiamInventory::write($id, $job);
            unset($cfg['agent_token_hash'], $cfg['approved']);
            if (empty($cfg['vpn_enabled'])) foreach (['vpn_server','vpn_username','vpn_password','vpn_secret','vpn_service'] as $field) unset($cfg[$field]);
            return ['status' => 'job', 'job' => $job['id'], 'kind' => $job['kind'], 'config' => $cfg];
        } elseif ($action === 'agent_result') {
            if (!hash_equals($job['id'] ?? '', $body['job'] ?? '')) throw new RuntimeException('نتیجهٔ تکراری یا منقضی دریافت شد.');
            // A response can be lost after the result is committed. Acknowledge
            // retries of that same job without revalidating rows or applying again.
            if (in_array($job['state'] ?? '', ['done', 'error', 'preview', 'applied'], true)) return ['status' => 'received', 'state' => $job['state']];
            if (($job['state'] ?? '') !== 'running' || ($job['expires'] ?? 0) <= time()) throw new RuntimeException('نتیجهٔ تکراری یا منقضی دریافت شد.');
            $logs = [];
            foreach (array_slice($body['logs'] ?? [], 0, 30) as $log) {
                $message = substr((string)($log['message'] ?? ''), 0, 1500);
                foreach (['password', 'vpn_password', 'vpn_secret'] as $secret) if (!empty($cfg[$secret])) $message = str_replace($cfg[$secret], '[REDACTED]', $message);
                $logs[] = ['stage' => substr((string)($log['stage'] ?? ''), 0, 40), 'status' => substr((string)($log['status'] ?? ''), 0, 20), 'message' => $message, 'elapsed_ms' => (int)($log['elapsed_ms'] ?? 0)];
            }
            $job['logs'] = $logs;
            $configurationMatches = hash_equals($job['config_hash'], TiamInventory::fingerprint($cfg));
            if (!$configurationMatches) $job['logs'][] = ['stage' => 'configuration', 'status' => 'error', 'message' => 'تنظیمات حین اجرا تغییر کرده است؛ تست را دوباره اجرا کنید.'];
            if (empty($body['success']) || !$configurationMatches) { $job['state'] = 'error'; }
            elseif (in_array($job['kind'], ['vpn_test', 'view_test'], true)) {
                $job['state'] = 'done'; $job['columns'] = $body['columns'] ?? []; $job['sample'] = array_slice($body['rows'] ?? [], 0, 5);
            } else {
                try {
                    $job = TiamInventory::preview($project, $job, $body['rows'] ?? []);
                    if ($job['kind'] === 'scheduled') {
                        if (empty($cfg['enabled']) || ($cfg['approved'] ?? '') !== TiamInventory::fingerprint($cfg)) throw new RuntimeException('زمان‌بندی غیرفعال شده یا نگاشت تأیید نشده است.');
                        $job = TiamInventory::apply($project, $job);
                    }
                } catch (RuntimeException $e) {
                    $job['state'] = 'error';
                    $job['logs'][] = ['stage' => 'validation', 'status' => 'error', 'message' => $e->getMessage(), 'elapsed_ms' => 0];
                    unset($job['products']);
                }
            }
            TiamInventory::write($id, $job);
            return ['status' => 'received', 'state' => $job['state']];
        } else throw new RuntimeException('عملیات ناشناخته است.');
        if (($job['expires'] ?? 0) < time() && in_array($job['state'] ?? '', ['queued','running','preview'], true)) $job['state'] = 'expired';
        $job['can_cancel'] = in_array($job['state']??'', ['queued','running','preview'],true) && (isMainAdmin() || isProjectAdmin() || hash_equals($job['owner']??'', TiamInventory::owner()));
        $job['agent_connected'] = !empty($job['agent_last_seen']) && $job['agent_last_seen'] >= time()-90;
        $job['can_apply'] = ($job['state'] ?? '') === 'preview' && hash_equals($job['owner'] ?? '', TiamInventory::owner());
        unset($job['products'], $job['erp_items'], $job['owner'], $job['config_hash'], $job['base']);
        return $job;
    });
    tiamReply($response);
} catch (Throwable $e) {
    // Detailed driver messages stay on the agent; responses contain no connection strings.
    tiamReply(['code' => $e instanceof TiamStateBusy ? 'TIAM_STATE_BUSY' : ($e instanceof TiamJobBusy ? 'TIAM_JOB_BUSY' : 'TIAM_ERROR'), 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'خطای داخلی همگام‌سازی؛ تنظیمات و لاگ مراحل را بررسی کنید.'], $e instanceof TiamStateBusy ? 503 : 400);
}
