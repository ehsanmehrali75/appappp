<?php
/** One global worker lease shared by CLI and browser dispatchers. */
class BackgroundWorker {
    private static $web;
    private static $deadline;

    public static function batchSize() {
        $configured = getenv('MAARKSHO_WORKER_BATCH_SIZE');
        return $configured !== false && ctype_digit($configured) ? max(1, min(20, (int)$configured)) : ((self::$web ?? PHP_SAPI !== 'cli') ? 1 : 5);
    }

    public static function hasTime() {
        return self::$deadline === null || microtime(true) < self::$deadline;
    }
    private static function directory() {
        $dir = getenv('MAARKSHO_WORKER_STATE_DIR') ?: sys_get_temp_dir().'/maarksho-worker-'.substr(hash('sha256', __DIR__), 0, 16);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('فضای وضعیت worker قابل نوشتن نیست.');
        return $dir;
    }

    public static function state() {
        $value = @file_get_contents(self::directory().'/status.json');
        $state = $value === false ? [] : json_decode($value, true);
        return is_array($state) ? array_intersect_key($state, array_flip(['started_at', 'finished_at', 'failed'])) : [];
    }

    private static function write($state) {
        AccessControl::writeJson(self::directory().'/status.json', $state);
    }

    private static function reply($status, $code = 200, $finish = true) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($code);
        echo json_encode(['status' => $status] + self::state(), JSON_UNESCAPED_UNICODE);
        if ($finish) exit;
    }

    /** Returns only when this process owns the worker and should execute it. */
    public static function begin($web = null) {
        $web = $web ?? PHP_SAPI !== 'cli';
        self::$web = $web;
        $seconds = getenv('MAARKSHO_WORKER_BUDGET_SECONDS');
        $seconds = $seconds !== false && is_numeric($seconds) ? max(5, min(600, (float)$seconds)) : ($web ? 45 : 240);
        self::$deadline = microtime(true) + $seconds;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $lock = @fopen(self::directory().'/worker.lock', 'c');
        if (!$lock) throw new RuntimeException('قفل worker قابل بازکردن نیست.');
        @chmod(self::directory().'/worker.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if ($web) self::reply('busy', 202);
            exit("Worker already running.\n");
        }
        $background = $web && ($_GET['background'] ?? '') === '1';
        if ($background && !is_callable('fastcgi_finish_request')) {
            flock($lock, LOCK_UN);
            fclose($lock);
            // A synchronous CGI request cannot honestly promise an early reply.
            // The browser must monitor/leave work to the host's CLI cron instead.
            self::reply('cron_required');
        }
        $started = time();
        self::write(['started_at' => $started, 'finished_at' => null, 'failed' => false]);
        register_shutdown_function(static function() use ($lock, $started) {
            $error = error_get_last();
            $failed = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            self::write(['started_at' => $started, 'finished_at' => time(), 'failed' => (bool)$failed]);
            flock($lock, LOCK_UN);
            fclose($lock);
        });
        if ($background) {
            ignore_user_abort(true);
            self::reply('accepted', 202, false);
            fastcgi_finish_request();
        }
    }
}
