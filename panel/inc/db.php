<?php
require_once __DIR__.'/DatabaseConfig.php';
$db_config=DatabaseConfig::load();

// Helper classes to make SQLite behave exactly like MySQLi
class SQLite3ResultWrapper {
    private $result;
    private $rows = null;
    private $currentIndex = 0;
    public $num_rows = 0;

    public function __construct($result) {
        if (is_array($result)) {
            $this->rows = $result;
            $this->num_rows = count($result);
        } else {
            $this->result = $result;
            $this->rows = [];
            if ($this->result) {
                while ($row = $this->result->fetchArray(SQLITE3_ASSOC)) {
                    $this->rows[] = $row;
                }
            }
            $this->num_rows = count($this->rows);
        }
    }

    public function fetch_assoc() {
        if ($this->currentIndex < $this->num_rows) {
            return $this->rows[$this->currentIndex++];
        }
        return null;
    }

    public function fetch_row() {
        if ($this->currentIndex < $this->num_rows) {
            return array_values($this->rows[$this->currentIndex++]);
        }
        return null;
    }
}

class SQLite3StmtWrapper {
    private $stmt;
    private $parent;
    private $boundParams = [];
    private $result = null;

    public function __construct($stmt, $parent) {
        $this->stmt = $stmt;
        $this->parent = $parent;
    }

    public function bind_param($types, &...$vars) {
        $this->boundParams = $vars;
        return true;
    }

    public function execute() {
        $this->parent->error = null;
        if (!$this->stmt) return false;
        
        $this->stmt->clear();
        foreach ($this->boundParams as $index => $val) {
            if (is_bool($val)) {
                $this->stmt->bindValue($index + 1, $val ? 1 : 0, SQLITE3_INTEGER);
            } elseif ($val === null) {
                $this->stmt->bindValue($index + 1, null, SQLITE3_NULL);
            } elseif (is_int($val)) {
                $this->stmt->bindValue($index + 1, $val, SQLITE3_INTEGER);
            } elseif (is_float($val)) {
                $this->stmt->bindValue($index + 1, $val, SQLITE3_FLOAT);
            } else {
                $this->stmt->bindValue($index + 1, (string)$val, SQLITE3_TEXT);
            }
        }

        try {
            $res = $this->stmt->execute();
            if ($res === false) {
                $this->parent->error = $this->stmt->lastErrorMsg();
                return false;
            }
            $this->result = $res;
            return true;
        } catch (Exception $e) {
            $this->parent->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        if ($this->result) {
            return new SQLite3ResultWrapper($this->result);
        }
        return false;
    }

    public function close() {
        if ($this->stmt) {
            @$this->stmt->close();
        }
        return true;
    }
}

class SQLite3ToMysqliWrapper {
    private $db;
    public $connect_error = null;
    public $error = null;

    public function __construct($path) {
        try {
            $this->db = new SQLite3($path);
            if (!$this->db) {
                $this->connect_error = "Could not open SQLite database";
            } else {
                $this->db->busyTimeout(10000); // 10s wait for locks
                $this->db->exec('PRAGMA foreign_keys = ON;');
                $this->db->exec('PRAGMA journal_mode = WAL;'); // high concurrency WAL database mode
            }
        } catch (Exception $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function set_charset($charset) {
        return true;
    }

    public function ping() {
        return $this->db !== null;
    }

    public function real_escape_string($string) {
        if (!$this->db) return $string;
        return $this->db->escapeString($string);
    }

    public function begin_transaction() {
        return $this->db->exec('BEGIN TRANSACTION');
    }

    public function commit() {
        return $this->db->exec('COMMIT');
    }

    public function rollback() {
        return $this->db->exec('ROLLBACK');
    }

    public function query($sql) {
        $this->error = null;
        if (!$this->db) return false;

        $sqlLower = strtolower(trim($sql));
        
        // AUTO_INCREMENT mapping for table creations
        if (strpos($sqlLower, 'auto_increment') !== false) {
            $sql = preg_replace('/id\s+int\s+auto_increment\s+primary\s+key/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = preg_replace('/id\s+int\s+auto_increment/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        }
        
        // Strip AFTER columns in ALTER TABLE
        if (strpos($sqlLower, 'alter table') !== false && strpos($sqlLower, 'after') !== false) {
            $sql = preg_replace('/after\s+[a-zA-Z0-9_]+/i', '', $sql);
        }

        // Ignore dynamic type modify queries in SQLite since typing is dynamic
        if (strpos($sqlLower, 'modify') !== false && strpos($sqlLower, 'alter table') !== false) {
            return true;
        }

        // Schema query mappings
        if (strpos($sqlLower, 'show tables like') !== false) {
            if (preg_match("/like\s+'([^']+)'/i", $sql, $matches)) {
                $tbl = $matches[1];
                $sql = "SELECT name AS `Tables_in_database` FROM sqlite_master WHERE type='table' AND name='$tbl'";
            } else {
                $sql = "SELECT name AS `Tables_in_database` FROM sqlite_master WHERE type='table'";
            }
        } elseif (strpos($sqlLower, 'show tables') !== false) {
            $sql = "SELECT name AS `Tables_in_database` FROM sqlite_master WHERE type='table'";
        } elseif (strpos($sqlLower, 'show columns from') !== false) {
            if (preg_match("/from\s+([a-zA-Z0-9_]+)/i", $sql, $tblMatches)) {
                $tbl = $tblMatches[1];
                $pragmaSql = "PRAGMA table_info($tbl)";
                $res = $this->db->query($pragmaSql);
                if (!$res) return false;
                
                $colToFind = null;
                if (preg_match("/like\s+'([^']+)'/i", $sql, $likeMatches)) {
                    $colToFind = $likeMatches[1];
                }
                
                $rows = [];
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    if ($colToFind !== null && $row['name'] !== $colToFind) {
                        continue;
                    }
                    $rows[] = [
                        'Field' => $row['name'],
                        'Field_Name' => $row['name'],
                        'Type' => $row['type'],
                        'Null' => $row['notnull'] ? 'NO' : 'YES',
                        'Key' => $row['pk'] ? 'PRI' : '',
                        'Default' => $row['dflt_value'],
                        'Extra' => ''
                    ];
                }
                return new SQLite3ResultWrapper($rows);
            }
        } elseif (strpos($sqlLower, 'show variables like') !== false) {
            $rows = [['Variable_name' => 'socket', 'Value' => 'sqlite_native_socket']];
            return new SQLite3ResultWrapper($rows);
        }

        // For non-SELECT queries in SQLite, use exec()
        $trimmedSql = trim($sqlLower);
        if (strpos($trimmedSql, 'select') !== 0 && strpos($trimmedSql, 'pragma') !== 0) {
            try {
                $execRes = @$this->db->exec($sql);
                if ($execRes === false) {
                    $lastErr = $this->db->lastErrorMsg();
                    if (strpos(strtolower($lastErr), 'duplicate column name') !== false || 
                        strpos(strtolower($lastErr), 'already exists') !== false ||
                        strpos(strtolower($lastErr), 'unique constraint failed') !== false) {
                        return true;
                    }
                    $this->error = $lastErr;
                    return false;
                }
                return true;
            } catch (Exception $e) {
                $lastErr = $e->getMessage();
                if (strpos(strtolower($lastErr), 'duplicate column name') !== false || 
                    strpos(strtolower($lastErr), 'already exists') !== false ||
                    strpos(strtolower($lastErr), 'unique constraint failed') !== false) {
                    return true;
                }
                $this->error = $lastErr;
                return false;
            }
        }

        try {
            $result = @$this->db->query($sql);
            if ($result === false) {
                $lastErr = $this->db->lastErrorMsg();
                if (strpos(strtolower($lastErr), 'duplicate column name') !== false || strpos(strtolower($lastErr), 'already exists') !== false) {
                    return true; // safekeeping for duplicate column add operations
                }
                $this->error = $lastErr;
                return false;
            }
            if ($result instanceof SQLite3Result) {
                return new SQLite3ResultWrapper($result);
            }
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        $this->error = null;
        if (!$this->db) return false;
        try {
            $stmt = @$this->db->prepare($sql);
            if ($stmt === false) {
                $this->error = $this->db->lastErrorMsg();
                return false;
            }
            return new SQLite3StmtWrapper($stmt, $this);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }
}

function getConn() {
    global $db_config;
    static $conn = null;
    static $migrationsRun = false;
    
    if ($conn === null) {
        $connected = false;
        $GLOBALS['db_driver'] = 'json_fallback'; // default indicator

        // 1. Try MySQL Connection if configured
        if (isset($db_config['driver']) && $db_config['driver'] === 'mysql' && class_exists('mysqli')) {
            mysqli_report(MYSQLI_REPORT_OFF);
            try {
                $conn = @new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['dbname']);
                if ($conn && !$conn->connect_error) {
                    $conn->set_charset("utf8mb4");
                    $GLOBALS['db_driver'] = 'mysql';
                    $connected = true;

                    // LOG SOCKET FOR WORKER
                    $res = $conn->query("SHOW VARIABLES LIKE 'socket'");
                    if ($res) {
                        $row = $res->fetch_assoc();
                        @file_put_contents(__DIR__ . '/../scraper/socket_path.txt', $row['Value']);
                    }
                } else {
                    if ($conn) {
                        $GLOBALS['db_connection_error'] = $conn->connect_error;
                        $conn = null;
                    }
                }
            } catch (Exception $e) {
                $GLOBALS['db_connection_error'] = $e->getMessage();
                $conn = null;
            }
        }

        // 2. Fallback to relational SQLite if MySQL failed or is disabled
        if (!$connected && class_exists('SQLite3') && (($db_config['driver']??'')==='sqlite'||!empty($db_config['allow_fallback']))) {
            try {
                $conn = new SQLite3ToMysqliWrapper($db_config['sqlite_path']);
                if (!$conn->connect_error) {
                    $GLOBALS['db_driver'] = 'sqlite';
                    $connected = true;
                } else {
                    $GLOBALS['db_connection_error'] = $conn->connect_error;
                    $conn = null;
                }
            } catch (Exception $e) {
                $GLOBALS['db_connection_error'] = $e->getMessage();
                $conn = null;
            }
        }
    }
    
    if($conn===null&&($db_config['driver']??'mysql')!=='json'&&empty($db_config['allow_fallback'])) {
        http_response_code(503);
        throw new RuntimeException('پایگاه دادهٔ انتخاب‌شده در دسترس نیست. برای جلوگیری از ثبت در منبع اشتباه، ذخیره‌ساز تغییر نکرد.');
    }

    if ($conn !== null && !$migrationsRun) {
        $migrationsRun = true;
        db_get_all();
    }
    
    return $conn;
}

// Check if using SQL (either MySQL or SQLite) vs Raw static JSON
function isSqlActive() {
    return getConn() !== null;
}

function fetchDB() {
    $default = ['super_users' => [], 'projects' => []];
    
    if (isSqlActive()) {
        $conn = getConn();
        $db = ['super_users' => [], 'projects' => []];
        
        $res = $conn->query("SELECT * FROM super_users");
        if ($res) {
            while ($row = $res->fetch_assoc()) { $db['super_users'][] = $row; }
        }
        
        $res = $conn->query("SELECT id FROM projects");
        if ($res) {
            $pids = [];
            while ($row = $res->fetch_assoc()) {
                $pids[] = $row['id'];
            }
            foreach ($pids as $pid) {
                $proj = getCurrentProject($pid);
                if ($proj) $db['projects'][] = $proj;
            }
        }
        if (empty($db['super_users'])) $db['super_users'] = $default['super_users'];
        return $db;
    } 
    return getJsonDB();
}

function db_auto_migrate_from_json($conn) {
    $db_path = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../db/projects_v2.json';
    if (!file_exists($db_path)) return;

    // Check if projects table is empty
    $checkProj = $conn->query("SELECT COUNT(*) as cnt FROM projects");
    if ($checkProj) {
        $row = $checkProj->fetch_assoc();
        if ((int)($row['cnt'] ?? 0) === 0) {
            $json = file_get_contents($db_path);
            $data = json_decode($json, true);
            if ($data && !empty($data['projects'])) {
                foreach ($data['projects'] as $project) {
                    try {
                        db_save_project($project);
                    } catch (Exception $e) {
                        error_log("Migration failed for project " . ($project['id'] ?? '') . ": " . $e->getMessage());
                    }
                }
            }
        }
    }
}

function db_get_all() {
    static $migrationDone = false;
    if (!$migrationDone && isSqlActive()) {
        $conn = getConn();
        
        // Ensure tables exist
        $conn->query("CREATE TABLE IF NOT EXISTS super_users (username VARCHAR(50) PRIMARY KEY, password VARCHAR(255))");
        $conn->query("CREATE TABLE IF NOT EXISTS projects (id VARCHAR(128) PRIMARY KEY, name VARCHAR(255), active_from DATE, active_to DATE, settings TEXT)");
        $conn->query("CREATE TABLE IF NOT EXISTS project_users (project_id VARCHAR(128), username VARCHAR(50), password VARCHAR(255), role VARCHAR(50), permissions TEXT, mobile VARCHAR(50), telegram_chat_id VARCHAR(100) DEFAULT NULL, bale_chat_id VARCHAR(100) DEFAULT NULL, PRIMARY KEY (project_id, username))");
        $conn->query("CREATE TABLE IF NOT EXISTS portal_users (mobile VARCHAR(20) PRIMARY KEY, display_name VARCHAR(100), user_icon VARCHAR(255), otp_code VARCHAR(10), otp_expires INT, created_at INT)");
        $conn->query("CREATE TABLE IF NOT EXISTS products (id VARCHAR(128) PRIMARY KEY, project_id VARCHAR(128), sku VARCHAR(128), name TEXT, description TEXT, image TEXT, social_image TEXT, gallery TEXT, link TEXT, price VARCHAR(100), extracted_at INT, manual_edit TINYINT, likes INT, dislikes INT, user_votes TEXT, user_feedback TEXT, platforms_sent TEXT, status VARCHAR(50), color VARCHAR(100), stock_online VARCHAR(100), stock_total VARCHAR(100), sizes TEXT, size_chart TEXT, priority INT, utm_source VARCHAR(128), utm_medium VARCHAR(128), utm_campaign VARCHAR(128), variants TEXT, uploaded_to_site TINYINT DEFAULT 0, uploaded_by VARCHAR(128) DEFAULT NULL, is_manual_image TINYINT DEFAULT 0, content_gen_requested TINYINT DEFAULT 0, publish_priority TINYINT DEFAULT 0, reference_gallery TEXT, images_list TEXT)");
        $conn->query("CREATE TABLE IF NOT EXISTS logs (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(128), at INT, type VARCHAR(50), product_name TEXT, platform VARCHAR(50))");
        $conn->query("CREATE TABLE IF NOT EXISTS schedules (id VARCHAR(128) PRIMARY KEY, project_id VARCHAR(128), product_id VARCHAR(128), platform VARCHAR(50), caption TEXT, media_url TEXT, scheduled_at DATETIME, status VARCHAR(50), approval_status VARCHAR(50) DEFAULT 'pending', comments TEXT, reactions TEXT)");
        $conn->query("CREATE TABLE IF NOT EXISTS scraping_jobs (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(128), url TEXT, type VARCHAR(50), status VARCHAR(50) DEFAULT 'pending', priority INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, started_at TIMESTAMP NULL, completed_at TIMESTAMP NULL, error_message TEXT, result_summary TEXT, worker_id VARCHAR(50))");
        $conn->query("CREATE TABLE IF NOT EXISTS site_configs (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE, selectors TEXT, active TINYINT DEFAULT 1)");
        $conn->query("CREATE TABLE IF NOT EXISTS proposals (id VARCHAR(128) PRIMARY KEY, project_id VARCHAR(128), type VARCHAR(50), title VARCHAR(255), logo_url TEXT, status VARCHAR(50), content LONGTEXT, created_at INT, updated_at INT)");

        // Migrations for lengths
        $conn->query("ALTER TABLE projects MODIFY id VARCHAR(128)");
        $conn->query("ALTER TABLE products MODIFY id VARCHAR(128)");
        $conn->query("ALTER TABLE products MODIFY project_id VARCHAR(128)");
        $conn->query("ALTER TABLE project_users MODIFY project_id VARCHAR(128)");
        @$conn->query("ALTER TABLE project_users ADD COLUMN mobile VARCHAR(50)");
        @$conn->query("ALTER TABLE project_users ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL");
        @$conn->query("ALTER TABLE project_users ADD COLUMN bale_chat_id VARCHAR(100) DEFAULT NULL");
        $conn->query("ALTER TABLE logs MODIFY project_id VARCHAR(128)");
        $conn->query("ALTER TABLE schedules MODIFY project_id VARCHAR(128)");
        $conn->query("ALTER TABLE schedules MODIFY product_id VARCHAR(50) NULL");

        // Products table migrations
        $cols = [
            'sku' => 'VARCHAR(255)',
            'description' => 'TEXT',
            'extracted_at' => 'INT',
            'manual_edit' => 'TINYINT DEFAULT 0',
            'likes' => 'INT DEFAULT 0',
            'dislikes' => 'INT DEFAULT 0',
            'user_votes' => 'TEXT',
            'user_feedback' => 'TEXT',
            'platforms_sent' => 'TEXT',
            'color' => 'VARCHAR(255)', 
            'stock_online' => 'VARCHAR(255)', 
            'stock_total' => 'VARCHAR(255)', 
            'sizes' => 'TEXT', 
            'size_chart' => 'TEXT', 
            'size_chart_template_id' => 'VARCHAR(128)',
            'priority' => 'INT DEFAULT 0', 
            'status' => 'VARCHAR(50) DEFAULT "active"',
            'gallery' => 'TEXT',
            'social_image' => 'VARCHAR(255)',
            'utm_source' => 'VARCHAR(255)',
            'utm_medium' => 'VARCHAR(255)',
            'utm_campaign' => 'VARCHAR(255)',
            'variants' => 'TEXT',
            'uploaded_to_site' => 'TINYINT DEFAULT 0',
            'uploaded_by' => 'VARCHAR(128) DEFAULT NULL',
            'is_manual_image' => 'TINYINT DEFAULT 0',
            'approvals_stage1' => 'TEXT',
            'approvals_stage2' => 'TEXT',
            'rejection_reason' => 'TEXT',
            'rejection_by' => 'VARCHAR(255)',
            'rejection_side' => 'VARCHAR(255)',
            'rejections_history' => 'TEXT',
            'content_gen_requested' => 'TINYINT DEFAULT 0',
            'publish_priority' => 'TINYINT DEFAULT 0',
            'reference_gallery' => 'TEXT',
            'images_list' => 'TEXT',
            'warehouses_stock' => 'TEXT',
            'warehouse_details' => 'TEXT',
            'model_code' => 'VARCHAR(255)',
            'stage1_decision' => 'VARCHAR(50) DEFAULT "pending"',
            'stage2_decision' => 'VARCHAR(50) DEFAULT "pending"',
            'size_chart_uploaded' => 'TINYINT DEFAULT 0',
            'size_chart_uploaded_at' => 'VARCHAR(50) DEFAULT NULL',
            'website_sync' => 'TEXT'
        ];
        foreach($cols as $col => $type) {
            $check = $conn->query("SHOW COLUMNS FROM products LIKE '$col'");
            if ($check && $check->num_rows == 0) {
                $conn->query("ALTER TABLE products ADD COLUMN $col $type");
            }
        }
        
        // Schedules table migrations (Make product_id nullable)
        $conn->query("ALTER TABLE schedules MODIFY product_id VARCHAR(50) NULL");
        $check = $conn->query("SHOW COLUMNS FROM schedules LIKE 'media_url'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE schedules ADD COLUMN media_url TEXT AFTER caption");
        }
        
        $scols = [
            'approval_status' => 'VARCHAR(50) DEFAULT "pending"',
            'comments' => 'TEXT',
            'reactions' => 'TEXT',
            'approvals_stage1' => 'TEXT',
            'approvals_stage2' => 'TEXT',
            'stage1_decision' => 'VARCHAR(50) DEFAULT "pending"',
            'stage2_decision' => 'VARCHAR(50) DEFAULT "pending"',
            'rejection_reason' => 'TEXT',
            'rejection_by' => 'VARCHAR(255)',
            'rejection_side' => 'VARCHAR(255)',
            'rejection_at' => 'INT',
            'rejections_history' => 'TEXT',
            'stage1_system_approved' => 'TINYINT DEFAULT 0',
            'stage2_system_approved' => 'TINYINT DEFAULT 0',
            'custom_title' => 'VARCHAR(255) DEFAULT NULL',
            'post_type' => 'VARCHAR(50) DEFAULT "image"',
            'instagram_caption' => 'TEXT',
            'instagram_media_id' => 'VARCHAR(255) DEFAULT NULL',
            'instagram_account_id' => 'VARCHAR(255) DEFAULT NULL',
            'error_log' => 'TEXT',
            'publish_status' => 'VARCHAR(50) DEFAULT "scheduled"',
            'retry_count' => 'INT DEFAULT 0'
        ];
        foreach($scols as $col => $type) {
            $check = $conn->query("SHOW COLUMNS FROM schedules LIKE '$col'");
            if ($check && $check->num_rows == 0) {
                $conn->query("ALTER TABLE schedules ADD COLUMN $col $type");
            }
        }
        
        // Auto-migrate from JSON offline backup file if empty
        db_auto_migrate_from_json($conn);

        $migrationDone = true;
    }
    return fetchDB();
}

function db_save_project($project, $onlyProducts = null, $onlySchedules = null) {
    if (class_exists('MaarkshoCache') && isset($project['id'])) {
        MaarkshoCache::delete('project_full_' . $project['id']);
    }
    if (isSqlActive()) {
        $conn = getConn();
        $success = true;
        
        // Use Transaction for integrity
        $conn->begin_transaction();
        try {
            // 1. Update/Insert Project basic info & settings (Only on full save)
            if ($onlyProducts === null && $onlySchedules === null) {
                $chkPrj = $conn->prepare("SELECT id FROM projects WHERE id = ?");
                $prjExists = false;
                if ($chkPrj) {
                    $chkPrj->bind_param("s", $project['id']);
                    $chkPrj->execute();
                    $resChk = $chkPrj->get_result();
                    $prjExists = ($resChk && $resChk->num_rows > 0);
                    $chkPrj->close();
                }

                $settings = json_encode($project['settings'] ?? [], JSON_UNESCAPED_UNICODE);
                $activeFrom = $project['activeFrom'] ?? date('Y-m-d');
                $activeTo = $project['activeTo'] ?? date('Y-m-d', strtotime('+1 year'));
                $name = $project['name'] ?? 'پروژه ' . $project['id'];

                if (!$prjExists) {
                    $stmt = $conn->prepare("INSERT INTO projects (id, name, active_from, active_to, settings) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("sssss", $project['id'], $name, $activeFrom, $activeTo, $settings);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("UPDATE projects SET name = ?, active_from = ?, active_to = ?, settings = ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("sssss", $name, $activeFrom, $activeTo, $settings, $project['id']);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                // Sync Users (Only on full save)
                if (isset($project['users']) && is_array($project['users'])) {
                    // First, remove old/deleted users
                    $usernames = array_column($project['users'], 'username');
                    if (!empty($usernames)) {
                        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
                        $stmt = $conn->prepare("DELETE FROM project_users WHERE project_id = ? AND username NOT IN ($placeholders)");
                        if ($stmt) {
                            $bindTypes = 's' . str_repeat('s', count($usernames));
                            $bindArgs = array_merge([$project['id']], $usernames);
                            $stmt->bind_param($bindTypes, ...$bindArgs);
                            $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        $stmt = $conn->prepare("DELETE FROM project_users WHERE project_id = ?");
                        if ($stmt) {
                            $stmt->bind_param("s", $project['id']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    foreach ($project['users'] as $u) {
                        $stmt = $conn->prepare("REPLACE INTO project_users (project_id, username, password, role, permissions, mobile, telegram_chat_id, bale_chat_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt) {
                            $perms = json_encode($u['permissions'] ?? [], JSON_UNESCAPED_UNICODE);
                            $mobile = $u['mobile'] ?? '';
                            $tg_chat_id = $u['telegram_chat_id'] ?? '';
                            $bale_chat_id = $u['bale_chat_id'] ?? '';
                            $stmt->bind_param("ssssssss", $project['id'], $u['username'], $u['password'], $u['role'], $perms, $mobile, $tg_chat_id, $bale_chat_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
            
            // 2. Sync Products
            if ($onlySchedules === null) {
                $productsToSave = $onlyProducts !== null ? $onlyProducts : ($project['products'] ?? []);
                
                if (!empty($productsToSave)) {
                    $stmt = $conn->prepare("REPLACE INTO products (id, project_id, sku, name, description, image, social_image, gallery, link, price, extracted_at, manual_edit, likes, dislikes, user_votes, user_feedback, platforms_sent, status, color, stock_online, stock_total, sizes, size_chart, size_chart_template_id, priority, utm_source, utm_medium, utm_campaign, variants, uploaded_to_site, uploaded_by, is_manual_image, approvals_stage1, approvals_stage2, rejection_reason, rejection_by, rejection_side, rejections_history, content_gen_requested, publish_priority, reference_gallery, images_list, warehouses_stock, warehouse_details, model_code, stage1_decision, stage2_decision, size_chart_uploaded, size_chart_uploaded_at, website_sync) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        foreach ($productsToSave as $p) {
                            if (!is_array($p)) continue;
                            $p_id = (string)$p['id'];
                            $p_project_id = (string)$project['id'];
                            $p_sku = (string)($p['sku'] ?? $p_id);
                            $p_name = (string)($p['name'] ?? 'بی‌نام');
                            $p_desc = (string)($p['description'] ?? '');
                            $p_image = (string)($p['image'] ?? '');
                            $p_social = (string)($p['social_image'] ?? $p['socialImage'] ?? '');
                            $p_link = (string)($p['link'] ?? '');
                            $p_price = (string)($p['price'] ?? 'نامشخص');
                            
                            $votes = json_encode($p['userVotes'] ?? $p['user_votes'] ?? [], JSON_UNESCAPED_UNICODE);
                            $feedback = json_encode($p['userFeedback'] ?? $p['user_feedback'] ?? [], JSON_UNESCAPED_UNICODE);
                            $gallery = json_encode($p['gallery'] ?? [], JSON_UNESCAPED_UNICODE);
                            $sent = json_encode($p['platformsSent'] ?? $p['platforms_sent'] ?? [], JSON_UNESCAPED_UNICODE);
                            $sizes = json_encode($p['sizes'] ?? [], JSON_UNESCAPED_UNICODE);
                            
                            $manual = (isset($p['manualEdit']) && $p['manualEdit']) || (isset($p['manual_edit']) && $p['manual_edit']) ? 1 : 0;
                            $p_status = $p['status'] ?? 'active';
                            $p_color = $p['color'] ?? '';
                            $p_stock = $p['stock_online'] ?? $p['stock_online'] ?? '';
                            $p_stock_t = $p['stock_total'] ?? $p['stock_total'] ?? '';
                            $p_chart = $p['size_chart'] ?? $p['size_chart'] ?? '';
                            $p_chart_template_id = $p['size_chart_template_id'] ?? $p['size_chart_template_id'] ?? '';
                            $p_priority = (int)($p['priority'] ?? 0);
                            $extracted = (int)($p['extractedAt'] ?? $p['extracted_at'] ?? time());
                            $likes = (int)($p['likes'] ?? 0);
                            $dislikes = (int)($p['dislikes'] ?? 0);
                            $u_s = $p['utm_source'] ?? $p['utmSource'] ?? '';
                            $u_m = $p['utm_medium'] ?? $p['utmMedium'] ?? '';
                            $u_c = $p['utm_campaign'] ?? $p['utmCampaign'] ?? '';
                            $p_variants = json_encode($p['variants'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_uploaded_to_site = (int)($p['uploaded_to_site'] ?? $p['uploadedToSite'] ?? 0);
                            $p_uploaded_by = (string)($p['uploaded_by'] ?? '');
                            $p_is_manual_image = (int)(!empty($p['is_manual_image']) ? 1 : 0);
                            
                            $p_approvals_st1 = json_encode($p['approvals_stage1'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_approvals_st2 = json_encode($p['approvals_stage2'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_rejection_reason = (string)($p['rejection_reason'] ?? $p['rejectionReason'] ?? '');
                            $p_rejection_by = (string)($p['rejection_by'] ?? '');
                            $p_rejection_side = (string)($p['rejection_side'] ?? '');
                            $p_rejections_history = json_encode($p['rejections_history'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_content_gen_requested = (int)(!empty($p['content_gen_requested']) ? 1 : 0);
                            
                            // Check if already sent to any social platform
                            $sent_data = $p['platformsSent'] ?? $p['platforms_sent'] ?? [];
                            $has_sent = false;
                            if (is_array($sent_data)) {
                                foreach ($sent_data as $key => $val) {
                                    if (is_array($val)) {
                                        if (!empty($val['platform'])) {
                                            $has_sent = true;
                                            break;
                                        }
                                    } else {
                                        if ($val > 0) {
                                            $has_sent = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            $p_publish_priority = 0;
                            if (!$has_sent) {
                                $p_publish_priority = (int)(!empty($p['publish_priority']) ? 1 : 0);
                            } else {
                                if (isset($p['publish_priority'])) {
                                    $p['publish_priority'] = false;
                                }
                            }

                            $p_reference_gallery = json_encode($p['reference_gallery'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_images_list = json_encode($p['images_list'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_warehouses_stock = json_encode($p['warehouses_stock'] ?? $p['warehousesStock'] ?? (object)[], JSON_UNESCAPED_UNICODE);
                            $p_warehouse_details = json_encode($p['warehouse_details'] ?? $p['warehouseDetails'] ?? [], JSON_UNESCAPED_UNICODE);
                            $p_model_code = (string)($p['model_code'] ?? $p['modelCode'] ?? $p['model'] ?? '');
                            $p_stage1_decision = (string)($p['stage1_decision'] ?? 'pending');
                            $p_stage2_decision = (string)($p['stage2_decision'] ?? 'pending');
                            $p_size_chart_uploaded = (int)(!empty($p['size_chart_uploaded']) ? 1 : 0);
                            $p_size_chart_uploaded_at = (string)($p['size_chart_uploaded_at'] ?? '');
                            $p_website_sync = json_encode($p['website_sync'] ?? (object)[], JSON_UNESCAPED_UNICODE);
                            
                            $stmt->bind_param("ssssssssssiiiissssssssssissssisissssssiisssssssiss",
                                $p_id, $p_project_id, $p_sku, $p_name, $p_desc, $p_image, $p_social, $gallery, $p_link, 
                                $p_price, $extracted, $manual, $likes, $dislikes, 
                                $votes, $feedback, $sent, $p_status, $p_color, $p_stock, $p_stock_t, $sizes, $p_chart, $p_chart_template_id, $p_priority,
                                $u_s, $u_m, $u_c, $p_variants, $p_uploaded_to_site, $p_uploaded_by, $p_is_manual_image,
                                $p_approvals_st1, $p_approvals_st2, $p_rejection_reason, $p_rejection_by, $p_rejection_side, $p_rejections_history,
                                $p_content_gen_requested, $p_publish_priority, $p_reference_gallery, $p_images_list,
                                $p_warehouses_stock, $p_warehouse_details, $p_model_code,
                                $p_stage1_decision, $p_stage2_decision,
                                $p_size_chart_uploaded, $p_size_chart_uploaded_at, $p_website_sync
                            );
                            $stmt->execute();
                        }
                        $stmt->close();
                    } else {
                        throw new Exception("MySQL Prepare Failed for Products: " . $conn->error);
                    }
                }
            }

            // Sync Schedules (Only on full save when schedules are provided, or onlySchedules save)
            if ($onlyProducts === null && (isset($project['schedules']) || $onlySchedules !== null || !empty($project['settings']['schedules']))) {
                if ($onlySchedules === null) {
                    $conn->query("DELETE FROM schedules WHERE project_id = '" . $conn->real_escape_string($project['id']) . "'");
                }

                $schedulesToSave = $onlySchedules !== null ? $onlySchedules : (!empty($project['schedules']) ? $project['schedules'] : ($project['settings']['schedules'] ?? []));
                if (!empty($schedulesToSave)) {
                    $stmt = $conn->prepare("REPLACE INTO schedules (id, project_id, product_id, platform, caption, media_url, scheduled_at, status, approval_status, comments, reactions, approvals_stage1, approvals_stage2, stage1_decision, stage2_decision, rejection_reason, rejection_by, rejection_side, rejection_at, rejections_history, stage1_system_approved, stage2_system_approved, custom_title, post_type, instagram_caption, instagram_media_id, instagram_account_id, error_log, publish_status, retry_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        foreach ($schedulesToSave as $s) {
                            $s_id = (string)($s['id'] ?? uniqid());
                            $s_pid = !empty($s['productId']) ? (string)$s['productId'] : (!empty($s['product_id']) ? (string)$s['product_id'] : null);
                            $s_platform = (string)($s['platform'] ?? 'telegram');
                            $s_caption = (string)($s['caption'] ?? '');
                            $s_media = (string)($s['mediaUrl'] ?? $s['media_url'] ?? '');
                            $s_at = date('Y-m-d H:i:s', is_numeric($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 0)) ? ($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 0)) : strtotime($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 'now')));
                            $s_status = (string)($s['status'] ?? 'pending');
                            $s_app = (string)($s['approvalStatus'] ?? $s['approval_status'] ?? 'pending');
                            $s_comments = json_encode($s['comments'] ?? []);
                            $s_reactions = json_encode($s['reactions'] ?? []);
                            
                            $s_approvals1 = json_encode($s['approvals_stage1'] ?? [], JSON_UNESCAPED_UNICODE);
                            $s_approvals2 = json_encode($s['approvals_stage2'] ?? [], JSON_UNESCAPED_UNICODE);
                            $s_st1_dec = (string)($s['stage1_decision'] ?? 'pending');
                            $s_st2_dec = (string)($s['stage2_decision'] ?? 'pending');
                            $s_rejection_reason = (string)($s['rejection_reason'] ?? '');
                            $s_rejection_by = (string)($s['rejection_by'] ?? '');
                            $s_rejection_side = (string)($s['rejection_side'] ?? '');
                            $s_rejection_at = (int)($s['rejection_at'] ?? 0);
                            $s_rejections_history = json_encode($s['rejections_history'] ?? [], JSON_UNESCAPED_UNICODE);
                            $s_st1_sys = (int)(!empty($s['stage1_system_approved']) ? 1 : 0);
                            $s_st2_sys = (int)(!empty($s['stage2_system_approved']) ? 1 : 0);
                            $s_custom_title = (string)($s['productName'] ?? $s['product_name'] ?? 'پست سفارشی');

                            $s_post_type = (string)($s['post_type'] ?? 'image');
                            $s_ig_caption = (string)($s['instagram_caption'] ?? '');
                            $s_ig_media_id = (string)($s['instagram_media_id'] ?? '');
                            $s_ig_acc_id = (string)($s['instagram_account_id'] ?? '');
                            $s_err_log = (string)($s['error_log'] ?? '');
                            $s_pub_status = (string)($s['publish_status'] ?? 'scheduled');
                            $s_retry_cnt = (int)($s['retry_count'] ?? 0);

                            $stmt->bind_param("ssssssssssssssssssisiisssssssi", 
                                $s_id, $project['id'], $s_pid, $s_platform, $s_caption, $s_media, $s_at, $s_status, $s_app, $s_comments, $s_reactions,
                                $s_approvals1, $s_approvals2, $s_st1_dec, $s_st2_dec, $s_rejection_reason, $s_rejection_by, $s_rejection_side, 
                                $s_rejection_at, $s_rejections_history, $s_st1_sys, $s_st2_sys, $s_custom_title,
                                $s_post_type, $s_ig_caption, $s_ig_media_id, $s_ig_acc_id, $s_err_log, $s_pub_status, $s_retry_cnt
                            );
                            $stmt->execute();
                        }
                        $stmt->close();
                    } else {
                        throw new Exception("MySQL Prepare Failed for Schedules: " . ($conn->error ?? 'unknown connection error'));
                    }
                }
            }
            
            // Sync Logs (Only on full save)
            if ($onlyProducts === null && $onlySchedules === null && isset($project['logs'])) {
                $conn->query("DELETE FROM logs WHERE project_id = '" . $conn->real_escape_string($project['id']) . "'");
                if (is_array($project['logs']) && !empty($project['logs'])) {
                    $stmtL = $conn->prepare("INSERT INTO logs (project_id, at, type, product_name, platform) VALUES (?, ?, ?, ?, ?)");
                    if ($stmtL) {
                        foreach ($project['logs'] as $log) {
                            $l_at = (int)($log['at'] ?? time());
                            $l_type = (string)($log['type'] ?? 'log');
                            $l_prod = (string)($log['productName'] ?? $log['product_name'] ?? '');
                            $l_plat = (string)($log['platform'] ?? 'SYSTEM');
                            $stmtL->bind_param("sisss", $project['id'], $l_at, $l_type, $l_prod, $l_plat);
                            $stmtL->execute();
                        }
                        $stmtL->close();
                    }
                }
            }
            
            $conn->commit();
        } catch (Exception $e) {
            if (isset($conn) && $conn) $conn->rollback();
            error_log("DB SAVE ERR: " . $e->getMessage());
            $success = false;
            throw $e;
        }
        return $success;
    } else {
        $db = getJsonDB();
        $found = false;
        foreach ($db['projects'] as &$p) {
            if ($p['id'] === $project['id']) { $p = $project; $found = true; break; }
        }
        if (!$found) $db['projects'][] = $project;
        saveJsonDB($db);
        return true;
    }
}

/**
 * Save single schedule item directly to database
 */
function db_save_schedule($s) {
    if (defined('USE_MYSQL') && USE_MYSQL) {
        $conn = getDbConnection();
        if (!$conn) return false;
        try {
            $stmt = $conn->prepare("REPLACE INTO schedules (id, project_id, product_id, platform, caption, media_url, scheduled_at, status, approval_status, comments, reactions, approvals_stage1, approvals_stage2, stage1_decision, stage2_decision, rejection_reason, rejection_by, rejection_side, rejection_at, rejections_history, stage1_system_approved, stage2_system_approved, custom_title, post_type, instagram_caption, instagram_media_id, instagram_account_id, error_log, publish_status, retry_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $s_id = (string)($s['id'] ?? uniqid());
                $proj_id = (string)($s['project_id'] ?? ($GLOBALS['CURRENT_PROJECT_ID'] ?? 'p1'));
                $s_pid = !empty($s['productId']) ? (string)$s['productId'] : (!empty($s['product_id']) ? (string)$s['product_id'] : null);
                $s_platform = (string)($s['platform'] ?? 'telegram');
                $s_caption = (string)($s['caption'] ?? '');
                $s_media = (string)($s['mediaUrl'] ?? $s['media_url'] ?? '');
                $s_at = date('Y-m-d H:i:s', is_numeric($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 0)) ? ($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 0)) : strtotime($s['scheduledAt'] ?? ($s['scheduled_at'] ?? 'now')));
                $s_status = (string)($s['status'] ?? 'pending');
                $s_app = (string)($s['approvalStatus'] ?? $s['approval_status'] ?? 'pending');
                $s_comments = json_encode($s['comments'] ?? []);
                $s_reactions = json_encode($s['reactions'] ?? []);
                $s_approvals1 = json_encode($s['approvals_stage1'] ?? [], JSON_UNESCAPED_UNICODE);
                $s_approvals2 = json_encode($s['approvals_stage2'] ?? [], JSON_UNESCAPED_UNICODE);
                $s_st1_dec = (string)($s['stage1_decision'] ?? 'pending');
                $s_st2_dec = (string)($s['stage2_decision'] ?? 'pending');
                $s_rejection_reason = (string)($s['rejection_reason'] ?? '');
                $s_rejection_by = (string)($s['rejection_by'] ?? '');
                $s_rejection_side = (string)($s['rejection_side'] ?? '');
                $s_rejection_at = (int)($s['rejection_at'] ?? 0);
                $s_rejections_history = json_encode($s['rejections_history'] ?? [], JSON_UNESCAPED_UNICODE);
                $s_st1_sys = (int)(!empty($s['stage1_system_approved']) ? 1 : 0);
                $s_st2_sys = (int)(!empty($s['stage2_system_approved']) ? 1 : 0);
                $s_custom_title = (string)($s['productName'] ?? $s['product_name'] ?? 'پست سفارشی');

                $s_post_type = (string)($s['post_type'] ?? 'image');
                $s_ig_caption = (string)($s['instagram_caption'] ?? '');
                $s_ig_media_id = (string)($s['instagram_media_id'] ?? '');
                $s_ig_acc_id = (string)($s['instagram_account_id'] ?? '');
                $s_err_log = (string)($s['error_log'] ?? '');
                $s_pub_status = (string)($s['publish_status'] ?? 'scheduled');
                $s_retry_cnt = (int)($s['retry_count'] ?? 0);

                $stmt->bind_param("ssssssssssssssssssisiisssssssi", 
                    $s_id, $proj_id, $s_pid, $s_platform, $s_caption, $s_media, $s_at, $s_status, $s_app, $s_comments, $s_reactions,
                    $s_approvals1, $s_approvals2, $s_st1_dec, $s_st2_dec, $s_rejection_reason, $s_rejection_by, $s_rejection_side, 
                    $s_rejection_at, $s_rejections_history, $s_st1_sys, $s_st2_sys, $s_custom_title,
                    $s_post_type, $s_ig_caption, $s_ig_media_id, $s_ig_acc_id, $s_err_log, $s_pub_status, $s_retry_cnt
                );
                $stmt->execute();
                $stmt->close();
                return true;
            }
        } catch (Exception $e) {
            error_log("DB SAVE SCHEDULE ERR: " . $e->getMessage());
        }
    }
    return false;
}

