<?php
/** Outbound SQL Server bridge for shared hosting. No VPN commands run in PHP. */
class TiamJobBusy extends RuntimeException {}
class TiamInventory {
    public static function directory() {
        $dir = getenv('TIAM_STATE_DIR') ?: sys_get_temp_dir() . '/maarksho-tiam-' . substr(hash('sha256', __DIR__), 0, 16);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('فضای خصوصی صف همگام‌سازی قابل نوشتن نیست.');
        return $dir;
    }
    public static function path($id, $kind) { return self::directory() . '/' . hash('sha256', (string)$id) . '.' . $kind; }
    public static function read($id) { $p = self::path($id, 'job'); return is_file($p) ? (json_decode(file_get_contents($p), true) ?: []) : []; }
    public static function write($id, $data) {
        $path = self::path($id, 'job'); $tmp = tempnam(self::directory(), 'state');
        chmod($tmp, 0600);
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) throw new RuntimeException('ذخیرهٔ وضعیت همگام‌سازی ناموفق بود.');
    }
    public static function locked($id, $fn) {
        $f = fopen(self::path($id, 'lock'), 'c'); chmod(self::path($id, 'lock'), 0600);
        if (!$f || !flock($f, LOCK_EX | LOCK_NB)) throw new RuntimeException('همگام‌سازی دیگری برای این پروژه در حال اجرا است.');
        try { return $fn(); } finally { flock($f, LOCK_UN); fclose($f); }
    }
    public static function fingerprint($cfg) {
        unset($cfg['agent_token_hash'], $cfg['enabled'], $cfg['sync_hour'], $cfg['approved'], $cfg['last_success'], $cfg['last_attempt'], $cfg['last_error']);
        return hash('sha256', json_encode($cfg));
    }
    public static function config($old, $input) {
        $cfg = $old;
        $cfg['sql_security'] = $input['sql_security'] ?? ($old['sql_security'] ?? 'verified');
        if (!in_array($cfg['sql_security'], ['verified','compatibility'], true)) throw new RuntimeException('حالت امنیت اتصال SQL معتبر نیست.');
        foreach (['host', 'database', 'schema', 'view', 'username', 'vpn_server', 'vpn_username', 'vpn_service', 'certificate_hostname'] as $key) $cfg[$key] = trim((string)($input[$key] ?? $old[$key] ?? ''));
        foreach (['password', 'vpn_password', 'vpn_secret'] as $key) if (!empty($input[$key])) $cfg[$key] = (string)$input[$key];
        $cfg['port'] = (int)($input['port'] ?? 1433);
        $cfg['schema'] = $cfg['schema'] ?: 'dbo';
        foreach (['schema', 'view'] as $key) if (!preg_match('/^[\p{L}_][\p{L}\p{N}_ ]{0,127}$/uD', $cfg[$key])) throw new RuntimeException('نام schema یا view معتبر نیست.');
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/D', $cfg['host']) || $cfg['port'] < 1 || $cfg['port'] > 65535) throw new RuntimeException('آدرس SQL Server یا پورت معتبر نیست.');
        if ($cfg['database'] === '' || preg_match('/[;\r\n{}]/', $cfg['database'])) throw new RuntimeException('نام دیتابیس معتبر نیست.');
        $cfg['enabled'] = !empty($input['enabled']); $cfg['vpn_enabled'] = !empty($input['vpn_enabled']);
        $cfg['zero_missing'] = !empty($input['zero_missing']);
        $cfg['sync_hour'] = $input['sync_hour'] ?? '12:00';
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $cfg['sync_hour'])) throw new RuntimeException('ساعت همگام‌سازی معتبر نیست.');
        $cfg['price_unit'] = $input['price_unit'] ?? 'toman';
        if (!in_array($cfg['price_unit'], ['toman', 'rial'], true)) throw new RuntimeException('واحد قیمت معتبر نیست.');
        $cfg['mapping'] = [];
        foreach (['sku', 'name', 'stock', 'size', 'color', 'warehouse', 'price', 'model'] as $field) {
            $column = trim((string)($input['mapping'][$field] ?? ''));
            if ($column !== '' && !preg_match('/^[\p{L}_][\p{L}\p{N}_ ]{0,127}$/uD', $column)) throw new RuntimeException('نام ستون معتبر نیست.');
            $cfg['mapping'][$field] = $column;
        }
        if (!empty($input['agent_token'])) {
            if (strlen($input['agent_token']) < 32) throw new RuntimeException('کلید ایجنت باید حداقل ۳۲ کاراکتر باشد.');
            $cfg['agent_token_hash'] = hash('sha256', $input['agent_token']);
        }
        if (self::fingerprint($cfg) !== self::fingerprint($old)) unset($cfg['approved']);
        return $cfg;
    }
    public static function normalize($rows, $cfg) {
        if (!is_array($rows) || !$rows || count($rows) > 50000) throw new RuntimeException('دریافت خالی یا بیش از ۵۰۰۰۰ ردیف؛ هیچ داده‌ای اعمال نشد.');
        $map = $cfg['mapping'] ?? []; $out = []; $seen = [];
        foreach (['sku', 'name', 'stock'] as $f) if (empty($map[$f])) throw new RuntimeException('نگاشت کد کالا، نام و موجودی الزامی است.');
        foreach ($rows as $i => $row) {
            if (!is_array($row)) throw new RuntimeException('ساختار ردیف دریافتی معتبر نیست.');
            $item = [];
            foreach ($map as $field => $column) {
                if ($column === '') continue;
                if (!array_key_exists($column, $row)) throw new RuntimeException('ستون نگاشت‌شده در ویو وجود ندارد: ' . $column);
                if (!is_scalar($row[$column]) && $row[$column] !== null) throw new RuntimeException('مقدار ستون قابل تبدیل نیست.');
                $item[$field] = trim((string)$row[$column]);
            }
            if ($item['sku'] === '' || $item['name'] === '' || !is_numeric($item['stock']) || !is_finite((float)$item['stock'])) throw new RuntimeException('کد، نام یا موجودی نامعتبر در ردیف ' . ($i + 1));
            $item['stock'] = (float)$item['stock'];
            if (isset($item['price']) && (!is_numeric($item['price']) || !is_finite((float)$item['price']) || (float)$item['price'] < 0)) throw new RuntimeException('قیمت نامعتبر در ردیف ' . ($i + 1));
            if (isset($item['price']) && ($cfg['price_unit'] ?? 'toman') === 'rial') $item['price'] = (float)$item['price'] / 10;
            $key = json_encode([$item['sku'], $item['color'] ?? '', $item['size'] ?? '', $item['warehouse'] ?? '']);
            if (isset($seen[$key])) throw new RuntimeException('ردیف تکراری برای ترکیب کالا/رنگ/سایز/انبار؛ ابتدا سطح دادهٔ ویو را اصلاح کنید.');
            $seen[$key] = true; $out[] = $item;
        }
        return $out;
    }
    public static function productKey($name, $color) { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name))) . '|' . mb_strtolower(trim($color)); }
    public static function merge($project, $items, $cfg) {
        $products = $project['products'] ?? []; $groups = []; $changes = []; $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0];
        foreach ($items as $item) {
            $key = self::productKey($item['model'] ?? $item['name'], $item['color'] ?? '');
            $groups[$key][] = $item;
        }
        $indices = [];
        foreach ($products as $i => $p) $indices[$p['tiam_key'] ?? self::productKey($p['name'] ?? '', $p['color'] ?? '')] = $i;
        $touched = [];
        foreach ($groups as $key => $rows) {
            $index = $indices[$key] ?? ($indices[self::productKey($rows[0]['name'], $rows[0]['color'] ?? '')] ?? null); $old = $index !== null ? $products[$index] : [];
            $variants = []; $warehouses = [];
            foreach ($rows as $row) {
                $vk = json_encode([$row['sku'], $row['size'] ?? '']);
                if (isset($variants[$vk]['price'], $row['price']) && (float)$variants[$vk]['price'] !== (float)$row['price']) throw new RuntimeException('قیمت یک واریانت بین انبارها متفاوت است؛ نگاشت یا ویو را اصلاح کنید.');
                if (!isset($variants[$vk])) {
                    $variants[$vk] = ['sku' => $row['sku'], 'size' => $row['size'] ?? '', 'inventory' => 0];
                    if (isset($row['price'])) $variants[$vk]['price'] = (float)$row['price'];
                }
                $variants[$vk]['inventory'] += $row['stock'];
                $warehouses[] = ['sku' => $row['sku'], 'size' => $row['size'] ?? '', 'warehouse' => $row['warehouse'] ?? '', 'inventory' => $row['stock']];
            }
            foreach (($old['variants'] ?? []) as $oldVariant) {
                $vk = json_encode([(string)($oldVariant['sku'] ?? ''), (string)($oldVariant['size'] ?? '')]);
                if (isset($variants[$vk])) $variants[$vk] = array_replace($oldVariant, $variants[$vk]);
                else { $variants[$vk] = $oldVariant; if (!empty($cfg['zero_missing'])) $variants[$vk]['inventory'] = 0; }
            }
            $stock = array_sum(array_column($variants, 'inventory'));
            $new = array_replace($old, ['id' => $old['id'] ?? ('tiam_' . substr(hash('sha256', $project['id'] . $key), 0, 24)),
                'name' => $rows[0]['name'], 'color' => $rows[0]['color'] ?? '', 'sku' => $rows[0]['sku'],
                'variants' => array_values($variants), 'sizes' => array_values(array_unique(array_column($variants, 'size'))),
                'stock_total' => $stock, 'stock_online' => $stock, 'inventory' => $stock, 'quantity' => $stock,
                'tiam_key' => $key, 'tiam_warehouses' => $warehouses]);
            if (isset($rows[0]['price'])) $new['price'] = (string)$rows[0]['price'];
            if (!$old) { $new['status'] = 'draft'; $new['extracted_at'] = date('Y-m-d H:i:s'); }
            $changed = json_encode($old) !== json_encode($new); $type = !$old ? 'new' : ($changed ? 'updated' : 'unchanged'); $counts[$type]++;
            if ($changed) $changes[] = ['type' => $type, 'name' => $new['name'], 'color' => $new['color'], 'before' => $old['stock_total'] ?? null, 'after' => $stock, 'sizes' => $new['sizes'], 'variants' => $new['variants']];
            if ($index === null) $products[] = $new; else $products[$index] = $new;
            $touched[$key] = true;
        }
        foreach ($products as &$p) {
            if (!isset($p['tiam_key']) || isset($touched[$p['tiam_key']])) continue;
            $counts['missing']++;
            if (!empty($cfg['zero_missing'])) {
                $changes[] = ['type' => 'zero_missing', 'name' => $p['name'], 'before' => $p['stock_total'] ?? null, 'after' => 0];
                foreach (['stock_total', 'stock_online', 'inventory', 'quantity'] as $f) $p[$f] = 0;
                foreach ($p['variants'] as &$v) $v['inventory'] = 0; unset($v);
                foreach (($p['tiam_warehouses'] ?? []) as $i => $w) $p['tiam_warehouses'][$i]['inventory'] = 0;
            }
        } unset($p);
        return ['products' => array_values($products), 'counts' => $counts, 'changes' => $changes];
    }
    public static function owner() { return hash('sha256', session_id() . '|' . ($_SESSION['user']['username'] ?? '')); }
    public static function base($project) { return hash('sha256', json_encode($project['products'] ?? [])); }
    public static function due($cfg, $now = null) {
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        return !empty($cfg['enabled']) && !empty($cfg['approved']) && $cfg['approved'] === self::fingerprint($cfg)
            && ($cfg['last_success'] ?? '') !== $now->format('Y-m-d') && ($cfg['last_attempt'] ?? '') !== $now->format('Y-m-d')
            && $now->format('H:i') >= ($cfg['sync_hour'] ?? '12:00');
    }
    public static function createJob($project, $kind, $owner = '') {
        $old = self::read($project['id']);
        if (in_array($old['state'] ?? '', ['queued', 'running'], true) && ($old['expires'] ?? 0) > time()) {
            if (($old['kind']??'') === $kind && $owner !== '' && hash_equals($old['owner']??'', $owner)) return $old;
            throw new TiamJobBusy(($old['state']==='queued')?'درخواست قبلی در صف و منتظر دریافت توسط ایجنت است.':'ایجنت درخواست قبلی را دریافت کرده؛ منتظر نتیجه است.');
        }
        $cfg = $project['settings']['tiam_inventory'] ?? [];
        $job = ['id' => bin2hex(random_bytes(24)), 'kind' => $kind, 'state' => 'queued', 'owner' => $owner, 'created' => time(), 'expires' => time()+900, 'agent_last_seen' => $old['agent_last_seen']??0,
            'config_hash' => self::fingerprint($cfg), 'base' => self::base($project)];
        self::write($project['id'], $job); return $job;
    }
    public static function cancelJob($projectId, $jobId, $owner, $admin = false) {
        $job = self::read($projectId);
        if (!is_string($jobId) || $jobId === '' || !hash_equals($job['id']??'', $jobId)) throw new RuntimeException('درخواست تغییر کرده است؛ آخرین وضعیت را دوباره دریافت کنید.');
        if (!$admin && !hash_equals($job['owner']??'', $owner)) throw new RuntimeException('لغو این درخواست به ایجادکننده یا مدیر پروژه نیاز دارد.');
        if (!in_array($job['state']??'', ['queued','running','preview'], true)) throw new RuntimeException('این درخواست دیگر فعال نیست.');
        $job['state']='cancelled'; $job['cancelled_at']=time();
        $job['logs'][]=['stage'=>'queue','status'=>'cancelled','message'=>'درخواست لغو شد؛ نتیجهٔ دیررس ایجنت پذیرفته نمی‌شود.','elapsed_ms'=>0];
        unset($job['products']);
        self::write($projectId,$job);
        return $job;
    }
    public static function preview($project, $job, $rows) {
        $cfg = $project['settings']['tiam_inventory'];
        if (!hash_equals($job['config_hash'], self::fingerprint($cfg))) throw new RuntimeException('تنظیمات تغییر کرده؛ درخواست جدید بسازید.');
        $result = self::merge($project, self::normalize($rows, $cfg), $cfg);
        $job['base'] = self::base($project); $job['state'] = 'preview';
        $job['products'] = $result['products']; $job['counts'] = $result['counts']; $job['changes'] = array_slice($result['changes'], 0, 100);
        $job['change_count'] = count($result['changes']); $job['sample'] = array_slice($rows, 0, 5);
        return $job;
    }
    public static function apply(&$project, $job) {
        $cfg = $project['settings']['tiam_inventory'] ?? [];
        if (($job['state'] ?? '') !== 'preview' || ($job['expires'] ?? 0) <= time() || !hash_equals($job['base'], self::base($project)) || !hash_equals($job['config_hash'], self::fingerprint($cfg))) throw new RuntimeException('پیش‌نمایش منقضی یا داده‌ها تغییر کرده‌اند؛ دوباره دریافت کنید.');
        $project['products'] = $job['products'];
        $project['settings']['tiam_inventory']['approved'] = self::fingerprint($cfg);
        $project['settings']['tiam_inventory']['last_success'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $project['settings']['tiam_inventory']['last_error'] = '';
        if (!saveProject($project)) throw new RuntimeException('ذخیرهٔ محصولات ناموفق بود.');
        unset($job['products']); $job['state'] = 'applied'; self::write($project['id'], $job);
        return $job;
    }
}
