<?php
/**
 * MarkShow Organization & Multi-Tenant Management Engine
 * Handles:
 * - Central Organization: Mark Show
 * - Customers & Customer Accounts
 * - Multi-Project Assignment
 * - Granular RBAC (Roles & Permissions)
 * - Ticketing (Free vs Paid Service Requests)
 * - Payment & Receipt Review Flow
 * - Ticket-to-Task Conversion
 * - Central Management Dashboard Metrics
 * - Multi-Channel Notifications
 */

if (!defined('MARKSHOW_MODULE_ACTIVE')) {
    define('MARKSHOW_MODULE_ACTIVE', true);
}

class MarkShowCore {
    private static $initialized = false;

    /**
     * Bootstraps database tables and default organization/roles safely (additive migrations)
     */
    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        if (function_exists('isSqlActive') && isSqlActive()) {
            $conn = getConn();
            if ($conn) {
                // 1. Organizations
                $conn->query("CREATE TABLE IF NOT EXISTS ms_organizations (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    code VARCHAR(64) UNIQUE,
                    settings TEXT,
                    created_at INT
                )");

                // 2. Roles
                $conn->query("CREATE TABLE IF NOT EXISTS ms_roles (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    title_fa VARCHAR(255) NOT NULL,
                    description TEXT,
                    permissions TEXT,
                    is_system TINYINT DEFAULT 0,
                    created_at INT
                )");

                // 3. Organization Members / Team
                $conn->query("CREATE TABLE IF NOT EXISTS ms_org_members (
                    id VARCHAR(64) PRIMARY KEY,
                    org_id VARCHAR(64) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    display_name VARCHAR(255),
                    mobile VARCHAR(50),
                    email VARCHAR(255),
                    role_id VARCHAR(64) NOT NULL,
                    status VARCHAR(50) DEFAULT 'active',
                    assigned_projects TEXT,
                    created_at INT,
                    UNIQUE KEY uq_org_user (org_id, username)
                )");

                // 4. Customers
                $conn->query("CREATE TABLE IF NOT EXISTS ms_customers (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    company_name VARCHAR(255),
                    mobile VARCHAR(50) NOT NULL,
                    email VARCHAR(255),
                    status VARCHAR(50) DEFAULT 'active',
                    notes TEXT,
                    created_at INT
                )");

                // 5. Customer - Project Relationships
                $conn->query("CREATE TABLE IF NOT EXISTS ms_customer_projects (
                    id VARCHAR(64) PRIMARY KEY,
                    customer_id VARCHAR(64) NOT NULL,
                    project_id VARCHAR(128) NOT NULL,
                    role VARCHAR(50) DEFAULT 'owner',
                    created_at INT,
                    UNIQUE KEY uq_cust_proj (customer_id, project_id)
                )");

                // 6. Tickets
                $conn->query("CREATE TABLE IF NOT EXISTS ms_tickets (
                    id VARCHAR(64) PRIMARY KEY,
                    tracking_code VARCHAR(32) UNIQUE,
                    title VARCHAR(255) NOT NULL,
                    description LONGTEXT,
                    project_id VARCHAR(128) NOT NULL,
                    customer_id VARCHAR(64) NULL,
                    creator_username VARCHAR(100),
                    creator_type VARCHAR(50) DEFAULT 'customer',
                    assigned_to VARCHAR(100) NULL,
                    priority VARCHAR(30) DEFAULT 'medium',
                    ticket_type VARCHAR(50) DEFAULT 'request',
                    status VARCHAR(50) DEFAULT 'open',
                    service_type VARCHAR(30) DEFAULT 'free',
                    estimated_time VARCHAR(100) NULL,
                    deadline VARCHAR(100) NULL,
                    price DECIMAL(15,2) DEFAULT 0,
                    cost_description TEXT NULL,
                    execution_days INT DEFAULT 0,
                    terms TEXT NULL,
                    converted_task_id VARCHAR(128) NULL,
                    created_at INT,
                    updated_at INT
                )");

                // 7. Ticket Messages & Attachments
                $conn->query("CREATE TABLE IF NOT EXISTS ms_ticket_messages (
                    id VARCHAR(64) PRIMARY KEY,
                    ticket_id VARCHAR(64) NOT NULL,
                    sender_username VARCHAR(100) NOT NULL,
                    sender_name VARCHAR(255),
                    sender_type VARCHAR(50) DEFAULT 'customer',
                    message LONGTEXT,
                    attachments TEXT,
                    is_internal TINYINT DEFAULT 0,
                    created_at INT
                )");

                // 8. Ticket Payments & Receipts
                $conn->query("CREATE TABLE IF NOT EXISTS ms_ticket_payments (
                    id VARCHAR(64) PRIMARY KEY,
                    ticket_id VARCHAR(64) NOT NULL,
                    customer_id VARCHAR(64) NULL,
                    amount DECIMAL(15,2) NOT NULL,
                    receipt_file TEXT NULL,
                    payment_date VARCHAR(50) NULL,
                    payment_ref VARCHAR(100) NULL,
                    notes TEXT NULL,
                    status VARCHAR(50) DEFAULT 'pending',
                    reviewed_by VARCHAR(100) NULL,
                    reviewed_at INT NULL,
                    rejection_reason TEXT NULL,
                    created_at INT
                )");

                // 9. Notifications
                $conn->query("CREATE TABLE IF NOT EXISTS ms_notifications (
                    id VARCHAR(64) PRIMARY KEY,
                    recipient_type VARCHAR(50) DEFAULT 'admin',
                    recipient_id VARCHAR(100) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT,
                    link VARCHAR(255) NULL,
                    is_read TINYINT DEFAULT 0,
                    type VARCHAR(50) DEFAULT 'system',
                    created_at INT
                )");

                // Auto Seed Organization & Default System Roles
                self::seedDefaults($conn);
            }
        }
    }

    /**
     * Seeds Mark Show Organization and default Roles if not present
     */
    private static function seedDefaults($conn) {
        // Seed Organization
        $chkOrg = $conn->query("SELECT id FROM ms_organizations WHERE code = 'mark_show'");
        if ($chkOrg && $chkOrg->num_rows === 0) {
            $orgId = 'org_mark_show';
            $orgName = 'Mark Show';
            $code = 'mark_show';
            $settings = json_encode([
                'title' => 'سازمان مرکزی مارک‌شو (Mark Show)',
                'slogan' => 'Digital Agency & Flow Automation HQ',
                'auto_task_on_payment' => true,
                'notify_sms' => true,
                'notify_telegram' => true,
                'notify_bale' => true
            ], JSON_UNESCAPED_UNICODE);
            $now = time();
            $stmt = $conn->prepare("INSERT INTO ms_organizations (id, name, code, settings, created_at) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssssi", $orgId, $orgName, $code, $settings, $now);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Seed Roles
        $systemRoles = [
            'super_admin' => [
                'name' => 'super_admin',
                'title_fa' => 'مدیر ارشد (Super Admin)',
                'desc' => 'دسترسی نامحدود به تمامی پروژه‌ها، کاربران، مالی، تنظیمات، تیکت‌ها و گزارشات',
                'perms' => ['all', 'manage_projects', 'manage_users', 'manage_financial', 'manage_tickets', 'manage_roles', 'manage_settings', 'manage_tasks', 'view_reports']
            ],
            'admin' => [
                'name' => 'admin',
                'title_fa' => 'مدیر سیستم (Admin)',
                'desc' => 'مدیریت کامل اجرایی پروژه‌ها، تسک‌ها، تیکت‌ها و پرسنل',
                'perms' => ['manage_projects', 'manage_users', 'manage_tickets', 'manage_tasks', 'view_financial', 'view_reports']
            ],
            'project_manager' => [
                'name' => 'project_manager',
                'title_fa' => 'مدیر پروژه (Project Manager)',
                'desc' => 'مدیریت و پایش پروژه‌های منتسب، زمان‌بندی تسک‌ها و پاسخگویی به مشتریان',
                'perms' => ['manage_assigned_projects', 'manage_tasks', 'manage_tickets', 'assign_tasks', 'contact_customer']
            ],
            'financial_manager' => [
                'name' => 'financial_manager',
                'title_fa' => 'مدیر مالی (Financial Manager)',
                'desc' => 'بررسی و تایید رسیدهای پرداخت، مدیریت فاکتورها، گزارش‌های درآمد و فعال‌سازی سرویس‌ها',
                'perms' => ['manage_financial', 'approve_payments', 'view_invoices', 'view_financial_reports']
            ],
            'support_manager' => [
                'name' => 'support_manager',
                'title_fa' => 'مدیر پشتیبانی (Support Manager)',
                'desc' => 'مدیریت تیکت‌ها، پاسخ‌دهی به درخواست‌ها، تغییر وضعیت و زمان‌بندی پشتیبانی',
                'perms' => ['manage_tickets', 'reply_tickets', 'change_ticket_status', 'assign_tickets']
            ],
            'developer' => [
                'name' => 'developer',
                'title_fa' => 'توسعه‌دهنده فنی (Developer)',
                'desc' => 'اجرای تسک‌های فنی، کدنویسی، بررسی زیرساخت و حل مشکلات سیستم',
                'perms' => ['view_assigned_tasks', 'update_task_progress', 'reply_tickets', 'technical_access']
            ],
            'content_manager' => [
                'name' => 'content_manager',
                'title_fa' => 'مدیر محتوا (Content Manager)',
                'desc' => 'تولید و انتشار محتوا، تایید تقویم، مدیریت گالری و کتابخانه کالا',
                'perms' => ['manage_content', 'manage_calendar', 'approve_media', 'view_assigned_tasks']
            ],
            'seo_manager' => [
                'name' => 'seo_manager',
                'title_fa' => 'مدیر سئو و آنالیتیکس (SEO Manager)',
                'desc' => 'بهینه‌سازی کلمات کلیدی، تحلیل ترافیک، هوش رقبا و گزارش‌های گوگل',
                'perms' => ['manage_seo', 'view_analytics', 'view_reports', 'view_assigned_tasks']
            ]
        ];

        foreach ($systemRoles as $key => $r) {
            $chk = $conn->query("SELECT id FROM ms_roles WHERE id = '{$key}'");
            if ($chk && $chk->num_rows === 0) {
                $permsJson = json_encode($r['perms'], JSON_UNESCAPED_UNICODE);
                $now = time();
                $isSys = 1;
                $stmt = $conn->prepare("INSERT INTO ms_roles (id, name, title_fa, description, permissions, is_system, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssii", $key, $r['name'], $r['title_fa'], $r['desc'], $permsJson, $isSys, $now);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Auto-seed admin user into Mark Show Org Member
        $chkAdmin = $conn->query("SELECT id FROM ms_org_members WHERE username = 'admin'");
        if ($chkAdmin && $chkAdmin->num_rows === 0) {
            $mId = 'mem_admin_root';
            $orgId = 'org_mark_show';
            $uName = 'admin';
            $dName = 'مدیر ارشد سازمان (Mark Show HQ)';
            $mob = '09120000000';
            $email = 'admin@markshow.ir';
            $roleId = 'super_admin';
            $status = 'active';
            $allPrj = json_encode(['*']);
            $now = time();
            $stmt = $conn->prepare("INSERT INTO ms_org_members (id, org_id, username, display_name, mobile, email, role_id, status, assigned_projects, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssssssssi", $mId, $orgId, $uName, $dName, $mob, $email, $roleId, $status, $allPrj, $now);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // ==========================================
    // Organizations & Members
    // ==========================================
    public static function getOrganization() {
        self::init();
        $conn = getConn();
        $res = $conn->query("SELECT * FROM ms_organizations WHERE code = 'mark_show' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $row['settings'] = json_decode($row['settings'] ?? '{}', true) ?: [];
            return $row;
        }
        return [
            'id' => 'org_mark_show',
            'name' => 'Mark Show',
            'code' => 'mark_show',
            'settings' => []
        ];
    }

    public static function getOrgMembers() {
        self::init();
        $conn = getConn();
        $members = [];
        $res = $conn->query("SELECT m.*, r.title_fa as role_title, r.permissions as role_permissions 
                             FROM ms_org_members m 
                             LEFT JOIN ms_roles r ON m.role_id = r.id 
                             ORDER BY m.created_at ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['assigned_projects'] = json_decode($row['assigned_projects'] ?? '[]', true) ?: [];
                $row['role_permissions'] = json_decode($row['role_permissions'] ?? '[]', true) ?: [];
                $members[] = $row;
            }
        }
        return $members;
    }

    public static function saveOrgMember($data) {
        self::init();
        $conn = getConn();
        $id = !empty($data['id']) ? $data['id'] : 'mem_' . uniqid();
        $orgId = 'org_mark_show';
        $username = trim($data['username'] ?? '');
        $displayName = trim($data['display_name'] ?? $username);
        $mobile = trim($data['mobile'] ?? '');
        $email = trim($data['email'] ?? '');
        $roleId = trim($data['role_id'] ?? 'admin');
        $status = trim($data['status'] ?? 'active');
        $assignedProjects = json_encode($data['assigned_projects'] ?? ['*'], JSON_UNESCAPED_UNICODE);
        $now = time();

        $chk = $conn->prepare("SELECT id FROM ms_org_members WHERE id = ? OR (org_id = ? AND username = ?)");
        if ($chk) {
            $chk->bind_param("sss", $id, $orgId, $username);
            $chk->execute();
            $r = $chk->get_result();
            $exists = ($r && $r->num_rows > 0);
            $chk->close();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE ms_org_members SET display_name = ?, mobile = ?, email = ?, role_id = ?, status = ?, assigned_projects = ? WHERE id = ? OR (org_id = ? AND username = ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssssss", $displayName, $mobile, $email, $roleId, $status, $assignedProjects, $id, $orgId, $username);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO ms_org_members (id, org_id, username, display_name, mobile, email, role_id, status, assigned_projects, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssssssi", $id, $orgId, $username, $displayName, $mobile, $email, $roleId, $status, $assignedProjects, $now);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            }
        }
        return false;
    }

    public static function deleteOrgMember($id) {
        self::init();
        $conn = getConn();
        $stmt = $conn->prepare("DELETE FROM ms_org_members WHERE id = ? AND username != 'admin'");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    // ==========================================
    // Roles & Permissions
    // ==========================================
    public static function getRoles() {
        self::init();
        $conn = getConn();
        $roles = [];
        $res = $conn->query("SELECT * FROM ms_roles ORDER BY is_system DESC, created_at ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['permissions'] = json_decode($row['permissions'] ?? '[]', true) ?: [];
                $roles[] = $row;
            }
        }
        return $roles;
    }

    public static function saveRole($data) {
        self::init();
        $conn = getConn();
        $id = !empty($data['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['id']) : 'role_' . uniqid();
        $name = trim($data['name'] ?? $id);
        $titleFa = trim($data['title_fa'] ?? $name);
        $desc = trim($data['description'] ?? '');
        $perms = json_encode($data['permissions'] ?? [], JSON_UNESCAPED_UNICODE);
        $isSys = !empty($data['is_system']) ? 1 : 0;
        $now = time();

        $chk = $conn->prepare("SELECT id FROM ms_roles WHERE id = ?");
        if ($chk) {
            $chk->bind_param("s", $id);
            $chk->execute();
            $r = $chk->get_result();
            $exists = ($r && $r->num_rows > 0);
            $chk->close();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE ms_roles SET title_fa = ?, description = ?, permissions = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("ssss", $titleFa, $desc, $perms, $id);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO ms_roles (id, name, title_fa, description, permissions, is_system, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssii", $id, $name, $titleFa, $desc, $perms, $isSys, $now);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            }
        }
        return false;
    }

    public static function deleteRole($id) {
        self::init();
        $conn = getConn();
        $stmt = $conn->prepare("DELETE FROM ms_roles WHERE id = ? AND is_system = 0");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    /**
     * Checks if a user has a specific permission in Mark Show HQ or project scope
     */
    public static function userCan($permission, $username = null) {
        if (function_exists('isSuperAdmin') && isSuperAdmin()) return true;
        if (!$username) {
            $username = $_SESSION['user']['username'] ?? '';
        }
        if (empty($username)) return false;
        if ($username === 'admin') return true;

        self::init();
        $conn = getConn();
        $stmt = $conn->prepare("SELECT r.permissions FROM ms_org_members m JOIN ms_roles r ON m.role_id = r.id WHERE m.username = ? AND m.status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $perms = json_decode($row['permissions'] ?? '[]', true) ?: [];
                if (in_array('all', $perms) || in_array($permission, $perms)) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }

        // Project-level fallback for backward compatibility
        if (function_exists('hasPermission')) {
            return hasPermission($permission);
        }
        return false;
    }

    // ==========================================
    // Customers Management
    // ==========================================
    public static function getCustomers($q = '') {
        self::init();
        $conn = getConn();
        $customers = [];
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM ms_customer_projects cp WHERE cp.customer_id = c.id) as projects_count,
                  (SELECT COUNT(*) FROM ms_tickets t WHERE t.customer_id = c.id) as tickets_count
                  FROM ms_customers c";
        if (!empty($q)) {
            $qSafe = '%' . $conn->escape_string($q) . '%';
            $query .= " WHERE c.name LIKE '{$qSafe}' OR c.company_name LIKE '{$qSafe}' OR c.mobile LIKE '{$qSafe}'";
        }
        $query .= " ORDER BY c.created_at DESC";

        $res = $conn->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                // Get assigned projects details
                $row['projects'] = self::getCustomerProjects($row['id']);
                $customers[] = $row;
            }
        }
        return $customers;
    }

    public static function getCustomerById($id) {
        self::init();
        $conn = getConn();
        $stmt = $conn->prepare("SELECT * FROM ms_customers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $row['projects'] = self::getCustomerProjects($row['id']);
                $stmt->close();
                return $row;
            }
            $stmt->close();
        }
        return null;
    }

    public static function getCustomerByMobile($mobile) {
        self::init();
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        $conn = getConn();
        $stmt = $conn->prepare("SELECT * FROM ms_customers WHERE mobile = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $mobile);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $row['projects'] = self::getCustomerProjects($row['id']);
                $stmt->close();
                return $row;
            }
            $stmt->close();
        }
        return null;
    }

    public static function saveCustomer($data) {
        self::init();
        $conn = getConn();
        $id = !empty($data['id']) ? $data['id'] : 'cust_' . uniqid();
        $name = trim($data['name'] ?? '');
        $company = trim($data['company_name'] ?? '');
        $mobile = preg_replace('/[^0-9]/', '', trim($data['mobile'] ?? ''));
        $email = trim($data['email'] ?? '');
        $status = trim($data['status'] ?? 'active');
        $notes = trim($data['notes'] ?? '');
        $now = time();

        if (empty($name) || empty($mobile)) {
            return ['success' => false, 'error' => 'نام و شماره تماس الزامی است.'];
        }

        $chk = $conn->prepare("SELECT id FROM ms_customers WHERE id = ?");
        if ($chk) {
            $chk->bind_param("s", $id);
            $chk->execute();
            $r = $chk->get_result();
            $exists = ($r && $r->num_rows > 0);
            $chk->close();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE ms_customers SET name = ?, company_name = ?, mobile = ?, email = ?, status = ?, notes = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssss", $name, $company, $mobile, $email, $status, $notes, $id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO ms_customers (id, name, company_name, mobile, email, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssssssi", $id, $name, $company, $mobile, $email, $status, $notes, $now);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Sync assigned projects if passed
            if (isset($data['project_ids']) && is_array($data['project_ids'])) {
                self::setCustomerProjects($id, $data['project_ids']);
            }

            return ['success' => true, 'id' => $id];
        }
        return ['success' => false, 'error' => 'خطای دیتابیس'];
    }

    public static function deleteCustomer($id) {
        self::init();
        $conn = getConn();
        $conn->query("DELETE FROM ms_customer_projects WHERE customer_id = '{$id}'");
        $stmt = $conn->prepare("DELETE FROM ms_customers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    public static function getCustomerProjects($customerId) {
        self::init();
        $conn = getConn();
        $projects = [];
        $stmt = $conn->prepare("SELECT p.id, p.name, p.active_from, p.active_to, cp.role, cp.created_at 
                                FROM ms_customer_projects cp 
                                JOIN projects p ON cp.project_id = p.id 
                                WHERE cp.customer_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $customerId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $projects[] = $row;
                }
            }
            $stmt->close();
        }
        return $projects;
    }

    public static function setCustomerProjects($customerId, array $projectIds) {
        self::init();
        $conn = getConn();
        $conn->query("DELETE FROM ms_customer_projects WHERE customer_id = '{$customerId}'");
        $now = time();
        foreach ($projectIds as $pid) {
            $pid = trim($pid);
            if (empty($pid)) continue;
            $cpId = 'cp_' . uniqid();
            $role = 'owner';
            $stmt = $conn->prepare("INSERT INTO ms_customer_projects (id, customer_id, project_id, role, created_at) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssssi", $cpId, $customerId, $pid, $role, $now);
                $stmt->execute();
                $stmt->close();
            }
        }
        return true;
    }

    // ==========================================
    // Ticketing System
    // ==========================================
    public static function getTickets($filters = []) {
        self::init();
        $conn = getConn();
        $tickets = [];

        $where = ["1=1"];
        if (!empty($filters['project_id'])) {
            $pid = $conn->escape_string($filters['project_id']);
            $where[] = "t.project_id = '{$pid}'";
        }
        if (!empty($filters['customer_id'])) {
            $cid = $conn->escape_string($filters['customer_id']);
            $where[] = "t.customer_id = '{$cid}'";
        }
        if (!empty($filters['status'])) {
            $st = $conn->escape_string($filters['status']);
            $where[] = "t.status = '{$st}'";
        }
        if (!empty($filters['service_type'])) {
            $srv = $conn->escape_string($filters['service_type']);
            $where[] = "t.service_type = '{$srv}'";
        }
        if (!empty($filters['priority'])) {
            $pr = $conn->escape_string($filters['priority']);
            $where[] = "t.priority = '{$pr}'";
        }
        if (!empty($filters['q'])) {
            $qSafe = '%' . $conn->escape_string($filters['q']) . '%';
            $where[] = "(t.title LIKE '{$qSafe}' OR t.tracking_code LIKE '{$qSafe}' OR t.description LIKE '{$qSafe}')";
        }

        $whereSql = implode(' AND ', $where);
        $query = "SELECT t.*, p.name as project_name, c.name as customer_name, c.company_name, c.mobile as customer_mobile,
                  (SELECT COUNT(*) FROM ms_ticket_messages tm WHERE tm.ticket_id = t.id) as message_count,
                  (SELECT COUNT(*) FROM ms_ticket_payments tp WHERE tp.ticket_id = t.id AND tp.status = 'pending') as pending_payments_count
                  FROM ms_tickets t
                  LEFT JOIN projects p ON t.project_id = p.id
                  LEFT JOIN ms_customers c ON t.customer_id = c.id
                  WHERE {$whereSql}
                  ORDER BY t.created_at DESC";

        $res = $conn->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tickets[] = $row;
            }
        }
        return $tickets;
    }

    public static function getTicketById($id) {
        self::init();
        $conn = getConn();
        $stmt = $conn->prepare("SELECT t.*, p.name as project_name, c.name as customer_name, c.company_name, c.mobile as customer_mobile 
                                FROM ms_tickets t 
                                LEFT JOIN projects p ON t.project_id = p.id 
                                LEFT JOIN ms_customers c ON t.customer_id = c.id 
                                WHERE t.id = ? OR t.tracking_code = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $id, $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $row['messages'] = self::getTicketMessages($row['id']);
                $row['payments'] = self::getTicketPayments($row['id']);
                $stmt->close();
                return $row;
            }
            $stmt->close();
        }
        return null;
    }

    public static function createTicket($data) {
        self::init();
        $conn = getConn();
        $id = 'tkt_' . uniqid();
        $trackingCode = 'MS-' . strtoupper(substr(uniqid(), -6));
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $projectId = trim($data['project_id'] ?? '');
        $customerId = !empty($data['customer_id']) ? $data['customer_id'] : null;
        $creatorUsername = trim($data['creator_username'] ?? 'customer');
        $creatorType = trim($data['creator_type'] ?? 'customer');
        $assignedTo = !empty($data['assigned_to']) ? $data['assigned_to'] : null;
        $priority = trim($data['priority'] ?? 'medium');
        $ticketType = trim($data['ticket_type'] ?? 'request');
        $serviceType = trim($data['service_type'] ?? 'free');
        $status = 'open';

        // Free or Paid specs
        $estimatedTime = trim($data['estimated_time'] ?? '');
        $deadline = trim($data['deadline'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $costDesc = trim($data['cost_description'] ?? '');
        $executionDays = (int)($data['execution_days'] ?? 0);
        $terms = trim($data['terms'] ?? '');

        if ($serviceType === 'paid' && $price > 0) {
            $status = 'waiting_payment';
        }

        $now = time();

        if (empty($title) || empty($projectId)) {
            return ['success' => false, 'error' => 'عنوان تیکت و انتخاب پروژه الزامی است.'];
        }

        $stmt = $conn->prepare("INSERT INTO ms_tickets (
            id, tracking_code, title, description, project_id, customer_id, 
            creator_username, creator_type, assigned_to, priority, ticket_type, 
            status, service_type, estimated_time, deadline, price, cost_description, 
            execution_days, terms, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $stmt->bind_param(
                "ssssssssssssssdssisii",
                $id, $trackingCode, $title, $description, $projectId, $customerId,
                $creatorUsername, $creatorType, $assignedTo, $priority, $ticketType,
                $status, $serviceType, $estimatedTime, $deadline, $price, $costDesc,
                $executionDays, $terms, $now, $now
            );
            $stmt->execute();
            $stmt->close();

            // Save attachment as first message if provided
            if (!empty($data['attachments']) || !empty($description)) {
                $attachments = is_array($data['attachments']) ? json_encode($data['attachments'], JSON_UNESCAPED_UNICODE) : ($data['attachments'] ?? '[]');
                self::addTicketMessage($id, [
                    'sender_username' => $creatorUsername,
                    'sender_name' => $data['creator_name'] ?? $creatorUsername,
                    'sender_type' => $creatorType,
                    'message' => $description,
                    'attachments' => $attachments
                ]);
            }

            // Create notification for admin & customer
            self::notifyTicketCreated($id, $title, $creatorType, $projectId);

            return ['success' => true, 'id' => $id, 'tracking_code' => $trackingCode];
        }

        return ['success' => false, 'error' => $conn->error];
    }

    public static function updateTicketCost($ticketId, $data) {
        self::init();
        $conn = getConn();
        $price = (float)($data['price'] ?? 0);
        $costDesc = trim($data['cost_description'] ?? '');
        $executionDays = (int)($data['execution_days'] ?? 0);
        $deadline = trim($data['deadline'] ?? '');
        $terms = trim($data['terms'] ?? '');
        $now = time();

        $status = ($price > 0) ? 'waiting_payment' : 'in_progress';
        $serviceType = ($price > 0) ? 'paid' : 'free';

        $stmt = $conn->prepare("UPDATE ms_tickets SET 
            price = ?, cost_description = ?, execution_days = ?, deadline = ?, 
            terms = ?, status = ?, service_type = ?, updated_at = ? 
            WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("dsissssiss", $price, $costDesc, $executionDays, $deadline, $terms, $status, $serviceType, $now, $ticketId);
            // Wait: 9 args: price(d), costDesc(s), executionDays(i), deadline(s), terms(s), status(s), serviceType(s), now(i), ticketId(s)
            // Fix type spec: d s i s s s s i s (9 chars)
            $types = "dsissssis";
            $stmt->bind_param($types, $price, $costDesc, $executionDays, $deadline, $terms, $status, $serviceType, $now, $ticketId);
            $stmt->execute();
            $stmt->close();

            // Notify customer of cost invoice
            $t = self::getTicketById($ticketId);
            if ($t) {
                self::addNotification('customer', $t['customer_id'] ?: $t['creator_username'], "فاکتور خدمات تیکت {$t['tracking_code']}", "مبلغ {$price} تومان برای تیکت «{$t['title']}» صادر شد. جهت شروع فرآیند، لطفاً پرداخت و رسید را ثبت فرمایید.", "portal.php?tab=tickets&ticket=" . $ticketId, 'payment_requested');
            }

            return true;
        }
        return false;
    }

    public static function updateTicketStatus($ticketId, $status) {
        self::init();
        $conn = getConn();
        $now = time();
        $stmt = $conn->prepare("UPDATE ms_tickets SET status = ?, updated_at = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sis", $status, $now, $ticketId);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    public static function getTicketMessages($ticketId) {
        self::init();
        $conn = getConn();
        $messages = [];
        $stmt = $conn->prepare("SELECT * FROM ms_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        if ($stmt) {
            $stmt->bind_param("s", $ticketId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['attachments'] = json_decode($row['attachments'] ?? '[]', true) ?: [];
                    $messages[] = $row;
                }
            }
            $stmt->close();
        }
        return $messages;
    }

    public static function addTicketMessage($ticketId, $data) {
        self::init();
        $conn = getConn();
        $id = 'msg_' . uniqid();
        $senderUser = trim($data['sender_username'] ?? 'system');
        $senderName = trim($data['sender_name'] ?? $senderUser);
        $senderType = trim($data['sender_type'] ?? 'admin');
        $message = trim($data['message'] ?? '');
        $attachments = is_array($data['attachments']) ? json_encode($data['attachments'], JSON_UNESCAPED_UNICODE) : ($data['attachments'] ?? '[]');
        $isInternal = !empty($data['is_internal']) ? 1 : 0;
        $now = time();

        $stmt = $conn->prepare("INSERT INTO ms_ticket_messages (id, ticket_id, sender_username, sender_name, sender_type, message, attachments, is_internal, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssii", $id, $ticketId, $senderUser, $senderName, $senderType, $message, $attachments, $isInternal, $now);
            $stmt->execute();
            $stmt->close();

            // Update ticket's updated_at
            $conn->query("UPDATE ms_tickets SET updated_at = {$now} WHERE id = '{$ticketId}'");

            return true;
        }
        return false;
    }

    // ==========================================
    // Payments & Receipts Flow
    // ==========================================
    public static function getTicketPayments($ticketId = null, $status = null) {
        self::init();
        $conn = getConn();
        $payments = [];
        $where = ["1=1"];
        if (!empty($ticketId)) {
            $tSafe = $conn->escape_string($ticketId);
            $where[] = "tp.ticket_id = '{$tSafe}'";
        }
        if (!empty($status)) {
            $stSafe = $conn->escape_string($status);
            $where[] = "tp.status = '{$stSafe}'";
        }
        $whereSql = implode(' AND ', $where);

        $query = "SELECT tp.*, t.title as ticket_title, t.tracking_code, t.project_id, p.name as project_name, c.name as customer_name, c.mobile as customer_mobile
                  FROM ms_ticket_payments tp
                  JOIN ms_tickets t ON tp.ticket_id = t.id
                  LEFT JOIN projects p ON t.project_id = p.id
                  LEFT JOIN ms_customers c ON tp.customer_id = c.id
                  WHERE {$whereSql}
                  ORDER BY tp.created_at DESC";

        $res = $conn->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $payments[] = $row;
            }
        }
        return $payments;
    }

    public static function submitPaymentReceipt($ticketId, $data) {
        self::init();
        $conn = getConn();
        $id = 'pay_' . uniqid();
        $customerId = !empty($data['customer_id']) ? $data['customer_id'] : null;
        $amount = (float)($data['amount'] ?? 0);
        $receiptFile = trim($data['receipt_file'] ?? '');
        $paymentDate = trim($data['payment_date'] ?? date('Y-m-d H:i'));
        $paymentRef = trim($data['payment_ref'] ?? '');
        $notes = trim($data['notes'] ?? '');
        $status = 'pending';
        $now = time();

        $stmt = $conn->prepare("INSERT INTO ms_ticket_payments (id, ticket_id, customer_id, amount, receipt_file, payment_date, payment_ref, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssdsssssi", $id, $ticketId, $customerId, $amount, $receiptFile, $paymentDate, $paymentRef, $notes, $status, $now);
            $stmt->execute();
            $stmt->close();

            // Update ticket status to in_progress or waiting financial review
            $conn->query("UPDATE ms_tickets SET status = 'in_progress', updated_at = {$now} WHERE id = '{$ticketId}'");

            // Notify Admin / Financial Manager
            self::addNotification('admin', 'admin', "رسید پرداخت جدید برای تیکت", "رسید پرداخت مبلغ " . number_format($amount) . " تومان برای تیکت شناسه {$ticketId} آپلود شد و در انتظار تایید مالی است.", "admin.php?tab=markshow_financial&payment=" . $id, 'payment_submitted');

            return ['success' => true, 'payment_id' => $id];
        }
        return ['success' => false, 'error' => $conn->error];
    }

    public static function reviewPaymentReceipt($paymentId, $decision, $reviewedBy, $rejectionReason = '') {
        self::init();
        $conn = getConn();
        $now = time();
        $newStatus = ($decision === 'approve') ? 'approved' : 'rejected';

        $stmt = $conn->prepare("UPDATE ms_ticket_payments SET status = ?, reviewed_by = ?, reviewed_at = ?, rejection_reason = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssiss", $newStatus, $reviewedBy, $now, $rejectionReason, $paymentId);
            $stmt->execute();
            $stmt->close();

            // Fetch payment details
            $res = $conn->query("SELECT * FROM ms_ticket_payments WHERE id = '{$paymentId}' LIMIT 1");
            if ($res && $pRow = $res->fetch_assoc()) {
                $ticketId = $pRow['ticket_id'];
                $t = self::getTicketById($ticketId);

                if ($decision === 'approve') {
                    // Update ticket status
                    $conn->query("UPDATE ms_tickets SET status = 'in_progress', updated_at = {$now} WHERE id = '{$ticketId}'");

                    // AUTO CONVERT TO TASK IN CURRENT TASK MANAGEMENT SYSTEM!
                    self::convertTicketToTask($ticketId);

                    // Notify Customer
                    if ($t) {
                        self::addNotification('customer', $t['customer_id'] ?: $t['creator_username'], "پرداخت شما تایید شد ✅", "رسید پرداخت شما برای تیکت «{$t['title']}» توسط واحد مالی تایید و مأموریت اجرایی آن در پروژه آغاز گردید.", "portal.php?tab=tickets&ticket=" . $ticketId, 'payment_approved');
                    }
                } else {
                    $conn->query("UPDATE ms_tickets SET status = 'waiting_payment', updated_at = {$now} WHERE id = '{$ticketId}'");
                    if ($t) {
                        self::addNotification('customer', $t['customer_id'] ?: $t['creator_username'], "رسید پرداخت تایید نشد ❌", "رسید پرداخت برای تیکت «{$t['title']}» مورد قبول واقع نشد: {$rejectionReason}", "portal.php?tab=tickets&ticket=" . $ticketId, 'payment_rejected');
                    }
                }
            }
            return true;
        }
        return false;
    }

    // ==========================================
    // Seamless Ticket -> Existing Task Converter
    // ==========================================
    public static function convertTicketToTask($ticketId, $assignedUsers = ['admin'], $priorityOverride = null) {
        self::init();
        $ticket = self::getTicketById($ticketId);
        if (!$ticket) return false;

        $projectId = $ticket['project_id'];
        $project = getCurrentProject($projectId);
        if (!$project) return false;

        if (!isset($project['settings']['task_manager_v3'])) {
            $project['settings']['task_manager_v3'] = ['tasks' => []];
        }

        $tasks = &$project['settings']['task_manager_v3']['tasks'];
        $newTaskId = 'tsk_tkt_' . uniqid();

        $priorityMap = [
            'high' => 'high',
            'urgent' => 'high',
            'medium' => 'medium',
            'low' => 'low'
        ];
        $priority = $priorityOverride ?: ($priorityMap[$ticket['priority']] ?? 'medium');

        $dueDate = !empty($ticket['deadline']) ? $ticket['deadline'] : '';
        if (empty($dueDate) && !empty($ticket['execution_days'])) {
            $dueDate = date('Y-m-d', strtotime('+' . $ticket['execution_days'] . ' days'));
        }

        $taskDesc = "📋 مأموریت تولید شده از تیکت: " . $ticket['tracking_code'] . "\n\n";
        $taskDesc .= $ticket['description'] . "\n\n";
        if ($ticket['service_type'] === 'paid') {
            $taskDesc .= "💰 نوع خدمت: هزینه دار (" . number_format($ticket['price']) . " تومان - تایید شده مالی)\n";
            $taskDesc .= "⏱️ زمان تعهد اجرا: " . $ticket['execution_days'] . " روز کاری\n";
        }
        if (!empty($ticket['customer_name'])) {
            $taskDesc .= "👤 مشتری: " . $ticket['customer_name'] . " (" . ($ticket['company_name'] ?: '') . ")\n";
        }

        $newTask = [
            'id' => $newTaskId,
            'title' => '🎯 ' . $ticket['title'],
            'description' => $taskDesc,
            'created_by' => 'mark_show_hq',
            'created_by_name' => 'سیستم تیکتینگ مارک‌شو',
            'created_at' => time(),
            'due_date' => $dueDate,
            'due_time' => '18:00',
            'group' => 'درخواست‌های مشتریان (Tickets)',
            'assignees' => !empty($ticket['assigned_to']) ? [$ticket['assigned_to']] : $assignedUsers,
            'status' => 'not_started',
            'priority' => $priority,
            'subtasks' => [
                [
                    'id' => 'sb_' . uniqid(),
                    'title' => 'بررسی الزامات و شروع اجرای خدمت تیکت ' . $ticket['tracking_code'],
                    'completed' => false,
                    'assignee' => ''
                ],
                [
                    'id' => 'sb_' . uniqid(),
                    'title' => 'تست نهایی کیفیت و ثبت گزارش تحویل به مشتری',
                    'completed' => false,
                    'assignee' => ''
                ]
            ],
            'comments' => [
                [
                    'id' => 'cm_' . uniqid(),
                    'user' => 'Mark Show HQ',
                    'text' => 'این تسک پس از تایید نهایی درخواست تیکت ' . $ticket['tracking_code'] . ' به صورت خودکار ایجاد گردید.',
                    'at' => time()
                ]
            ],
            'ticket_id' => $ticket['id'],
            'ticket_tracking_code' => $ticket['tracking_code']
        ];

        $tasks[] = $newTask;

        // Save project back safely preserving all project settings
        saveProject($project);

        // Update ticket with converted_task_id
        $conn = getConn();
        $conn->query("UPDATE ms_tickets SET converted_task_id = '{$newTaskId}', status = 'in_progress' WHERE id = '{$ticketId}'");

        return $newTaskId;
    }

    // ==========================================
    // Central Management Dashboard Metrics
    // ==========================================
    public static function getDashboardMetrics() {
        self::init();
        $conn = getConn();

        // 1. Projects Count & Active
        $totalProjects = 0;
        $activeProjects = 0;
        $resP = $conn->query("SELECT COUNT(*) as total FROM projects");
        if ($resP && $rP = $resP->fetch_assoc()) $totalProjects = (int)$rP['total'];

        // 2. Customers Count
        $totalCustomers = 0;
        $resC = $conn->query("SELECT COUNT(*) as total FROM ms_customers");
        if ($resC && $rC = $resC->fetch_assoc()) $totalCustomers = (int)$rC['total'];

        // 3. Tickets Stats
        $openTickets = 0;
        $waitingPaymentTickets = 0;
        $inProgressTickets = 0;
        $completedTickets = 0;

        $resT = $conn->query("SELECT status, COUNT(*) as cnt FROM ms_tickets GROUP BY status");
        if ($resT) {
            while ($r = $resT->fetch_assoc()) {
                if ($r['status'] === 'open') $openTickets += (int)$r['cnt'];
                elseif ($r['status'] === 'waiting_payment') $waitingPaymentTickets += (int)$r['cnt'];
                elseif ($r['status'] === 'in_progress') $inProgressTickets += (int)$r['cnt'];
                elseif ($r['status'] === 'completed' || $r['status'] === 'closed') $completedTickets += (int)$r['cnt'];
            }
        }

        // 4. Financials
        $totalRevenue = 0;
        $pendingPaymentsCount = 0;
        $pendingPaymentsAmount = 0;

        $resPay = $conn->query("SELECT status, SUM(amount) as total_amt, COUNT(*) as cnt FROM ms_ticket_payments GROUP BY status");
        if ($resPay) {
            while ($rp = $resPay->fetch_assoc()) {
                if ($rp['status'] === 'approved') {
                    $totalRevenue += (float)$rp['total_amt'];
                } elseif ($rp['status'] === 'pending') {
                    $pendingPaymentsCount = (int)$rp['cnt'];
                    $pendingPaymentsAmount = (float)$rp['total_amt'];
                }
            }
        }

        // 5. Team Members
        $totalMembers = 0;
        $resM = $conn->query("SELECT COUNT(*) as cnt FROM ms_org_members WHERE status = 'active'");
        if ($resM && $rm = $resM->fetch_assoc()) $totalMembers = (int)$rm['cnt'];

        // 6. Overdue & Open Tasks across projects
        $allTasksCount = 0;
        $overdueTasksCount = 0;
        $db = getDB();
        $today = date('Y-m-d');
        foreach ($db['projects'] as $p) {
            $pTasks = $p['settings']['task_manager_v3']['tasks'] ?? [];
            foreach ($pTasks as $tsk) {
                if (($tsk['status'] ?? '') !== 'completed') {
                    $allTasksCount++;
                    if (!empty($tsk['due_date']) && $tsk['due_date'] < $today) {
                        $overdueTasksCount++;
                    }
                }
            }
        }

        return [
            'total_projects' => $totalProjects,
            'total_customers' => $totalCustomers,
            'open_tickets' => $openTickets,
            'waiting_payment_tickets' => $waitingPaymentTickets,
            'in_progress_tickets' => $inProgressTickets,
            'completed_tickets' => $completedTickets,
            'total_revenue' => $totalRevenue,
            'pending_payments_count' => $pendingPaymentsCount,
            'pending_payments_amount' => $pendingPaymentsAmount,
            'total_members' => $totalMembers,
            'open_tasks_count' => $allTasksCount,
            'overdue_tasks_count' => $overdueTasksCount
        ];
    }

    // ==========================================
    // Notification Engine
    // ==========================================
    public static function addNotification($recipientType, $recipientId, $title, $message, $link = null, $type = 'system') {
        self::init();
        $conn = getConn();
        $id = 'notif_' . uniqid();
        $now = time();
        $stmt = $conn->prepare("INSERT INTO ms_notifications (id, recipient_type, recipient_id, title, message, link, is_read, type, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssi", $id, $recipientType, $recipientId, $title, $message, $link, $type, $now);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    public static function getNotifications($recipientType = 'admin', $recipientId = 'admin', $unreadOnly = false) {
        self::init();
        $conn = getConn();
        $notifs = [];
        $where = ["recipient_type = '{$recipientType}'"];
        if ($recipientId !== 'all') {
            $where[] = "(recipient_id = '{$recipientId}' OR recipient_id = 'all')";
        }
        if ($unreadOnly) {
            $where[] = "is_read = 0";
        }
        $whereSql = implode(' AND ', $where);

        $res = $conn->query("SELECT * FROM ms_notifications WHERE {$whereSql} ORDER BY created_at DESC LIMIT 50");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $notifs[] = $row;
            }
        }
        return $notifs;
    }

    public static function markNotificationRead($id) {
        self::init();
        $conn = getConn();
        $conn->query("UPDATE ms_notifications SET is_read = 1 WHERE id = '{$id}'");
        return true;
    }

    private static function notifyTicketCreated($ticketId, $title, $creatorType, $projectId) {
        $p = getCurrentProject($projectId);
        $pName = $p['name'] ?? 'پروژه جاری';

        // 1. Notify Admins
        self::addNotification('admin', 'admin', "تیکت جدید: {$title}", "یک تیکت تازه در پروژه «{$pName}» ایجاد شد.", "admin.php?tab=markshow_tickets&ticket=" . $ticketId, 'ticket_created');

        // 2. Dispatch SMS / Telegram if configured
        if (!empty($p['settings']['connections']['telegram']['token'])) {
            $tgToken = $p['settings']['connections']['telegram']['token'];
            $tgChatId = $p['settings']['connections']['telegram']['chatId'] ?? '';
            $proxy = $p['settings']['connections']['telegram']['proxy'] ?? null;
            if (!empty($tgChatId)) {
                $msg = "🎫 تیکت جدید در سامانه‌:\n" . "🔹 عنوان: {$title}\n" . "🏢 پروژه: {$pName}\n" . "🔗 مدیریت تیکت‌ها در پنل Mark Show";
                @sendToTelegram($tgToken, $tgChatId, $msg, null, $proxy);
            }
        }
    }
}
