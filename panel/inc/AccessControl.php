<?php
/** Explicit feature audiences. Global restrictions always take precedence. */
class AccessControl {
    public static function roles() {
        return ['main_admin' => 'ادمین اصلی', 'project_admin' => 'ادمین پروژه',
            'manager' => 'مدیر با دسترسی انتخابی', 'editor' => 'کارشناس / ویرایشگر',
            'viewer' => 'کاربر پنل'];
    }

    public static function file() {
        return defined('ACCESS_POLICY_FILE') ? ACCESS_POLICY_FILE : __DIR__ . '/../db/access_policy.json';
    }

    public static function policies() {
        if (!is_file(self::file())) return [];
        $data = json_decode(@file_get_contents(self::file()), true);
        // An unreadable policy must never silently remove configured restrictions.
        if (!is_array($data)) return ['__invalid' => true];
        foreach ($data as $feature => $modes) {
            if (!is_string($feature) || !is_array($modes)) return ['__invalid' => true];
            foreach ($modes as $mode => $rule) {
                if (!in_array($mode, ['view', 'use'], true) || !is_array($rule)) return ['__invalid' => true];
                foreach ($rule as $scope => $items) {
                    if (!in_array($scope, ['roles', 'users', 'projects'], true) || !is_array($items)) return ['__invalid' => true];
                    if (array_keys($items) !== array_keys(array_values($items))) return ['__invalid' => true];
                    foreach ($items as $item) if (!is_string($item) || trim($item) === '') return ['__invalid' => true];
                }
            }
        }
        return $data;
    }

    public static function save($input) {
        if (!FeatureFlags::isMainAdmin() || !is_array($input)) return false;
        $clean = [];
        foreach (FeatureFlags::getCatalog() as $key => $feature) {
            if (isset($input[$key]) && !is_array($input[$key])) return false;
            foreach (['view', 'use'] as $mode) {
                if (isset($input[$key][$mode]) && !is_array($input[$key][$mode])) return false;
                foreach (['roles', 'users', 'projects'] as $scope) {
                    $value = $input[$key][$mode][$scope] ?? '';
                    if (!is_string($value)) return false;
                    $items = preg_split('/[،,\r\n]+/u', $value);
                    $items = array_values(array_unique(array_filter(array_map('trim', $items), 'strlen')));
                    if ($scope === 'roles' && array_diff($items, array_keys(self::roles()))) return false;
                    $clean[$key][$mode][$scope] = $items;
                }
            }
        }
        return self::writeJson(self::file(), $clean);
    }

    public static function aliases() {
        return ['size_chart' => 'size_studio', 'vm' => 'vm_studio', 'seo' => 'seo_classic',
            'sale' => 'sale_analysis', 'prompt_hub' => 'prompthub', 'layout' => 'vm_layout'];
    }

    public static function featureKey($key) {
        return self::aliases()[$key] ?? $key;
    }

    public static function parentFeature($key) {
        $key = self::featureKey($key);
        $granular = ['ai_connect'=>'products', 'library' => 'products', 'reference_photo' => 'products', 'approve_content' => 'products',
            'approve' => 'products', 'colleague' => 'products', 'prompthub_manage' => 'prompthub', 'layout' => 'vm_studio'];
        if (isset($granular[$key])) return $granular[$key];
        if (strpos($key, 'seo_intel_') === 0) return 'seo_intel';
        if (strpos($key, 'vm_') === 0 && $key !== 'vm_studio') return 'vm_studio';
        return null;
    }

    /** Resolve legacy project module names without losing a stricter stored value. */
    public static function moduleVisibility($module, $settings) {
        $module = self::featureKey($module);
        if (!is_array($settings)) return 'disabled';
        require_once __DIR__.'/ProjectPlans.php';
        if (!ProjectPlans::allows($module,$settings)) return 'disabled';
        $parent = self::parentFeature($module);
        $parentVisibility = $parent === null ? 'active' : self::moduleVisibility($parent, $settings);
        $rank = ['active' => 0, 'admin_only' => 1, 'maintenance' => 1, 'disabled' => 2];
        $visibility = null;
        if (isset($settings['module_visibility'])) {
            if (!is_array($settings['module_visibility'])) return 'disabled';
            foreach ($settings['module_visibility'] as $key => $value) {
                if (self::featureKey($key) !== $module) continue;
                if (!is_string($value) || !isset($rank[$value])) return 'disabled';
                if ($visibility === null || $rank[$value] > $rank[$visibility]) $visibility = $value;
            }
        }
        if ($visibility === null && isset($settings['active_modules'])) {
            if (!is_array($settings['active_modules'])) return 'disabled';
            $active = [];
            foreach ($settings['active_modules'] as $key) {
                if (!is_string($key)) return 'disabled';
                $active[] = self::featureKey($key);
            }
            $visibility = in_array($module, $active, true) || ($parent !== null && in_array($parent, $active, true)) ? 'active' : 'disabled';
        }
        $visibility = $visibility ?? 'active';
        if ($rank[$parentVisibility] > $rank[$visibility]) $visibility = $parentVisibility;
        return $visibility === 'maintenance' ? 'admin_only' : $visibility;
    }

    /** Publish complete policy/configuration files so readers never see partial JSON. */
    public static function writeJson($file, $data) {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        $temp = @tempnam($dir, '.access-');
        if ($temp === false) return false;
        $ok = @file_put_contents($temp, $json, LOCK_EX) === strlen($json);
        if ($ok) {
            @chmod($temp, is_file($file) ? (fileperms($file) & 0777) : 0640);
            $ok = @rename($temp, $file);
        }
        if (!$ok) @unlink($temp);
        return $ok;
    }

    /** null means inherit existing permissions, false means explicitly denied. */
    public static function audience($key, $mode = 'view', $user = null, $projectId = null) {
        if (!in_array($mode, ['view', 'use'], true)) return false;
        $explicitUser = $user !== null;
        $user = $user ?? ($_SESSION['user'] ?? []);
        $projectId = $projectId ?? ($_SESSION['project_id'] ?? '');
        $policies = self::policies();
        if (isset($policies['__invalid'])) return false;
        $rules = [];
        foreach ($policies as $storedKey => $modes) {
            if (self::featureKey($storedKey) !== self::featureKey($key)) continue;
            $rule = $modes[$mode] ?? [];
            if (!empty($rule['roles']) || !empty($rule['users']) || !empty($rule['projects'])) $rules[] = $rule;
        }
        if (!$rules) return null;
        if (!$explicitUser && function_exists('getCurrentProject') && $projectId !== '') {
            $project = getCurrentProject($projectId);
            $member = class_exists('SessionIdentity') ? SessionIdentity::member($project) : null;
            if (!class_exists('SessionIdentity')) foreach (($project['users'] ?? []) as $candidate) {
                if (($candidate['username'] ?? '') === ($user['username'] ?? '')) { $member = $candidate; break; }
            }
            if (!$member) return false;
            $user = $member;
        }
        $role = $user['role'] ?? '';
        if ($role === 'superadmin') $role = 'main_admin';
        // Persisted aliases may coexist with canonical rules; neither may reopen
        // an audience that the other spelling explicitly restricted.
        foreach ($rules as $rule) {
            if (!in_array($role, $rule['roles'] ?? [], true)
                && !in_array($user['username'] ?? '', $rule['users'] ?? [], true)
                && !in_array((string)$projectId, $rule['projects'] ?? [], true)) return false;
        }
        return true;
    }

    public static function allowed($key, $mode = 'view') {
        if (!in_array($mode, ['view', 'use'], true)) return false;
        if (FeatureFlags::isMainAdmin()) return true;
        $key = self::featureKey($key);
        $parent = self::parentFeature($key);
        if ($parent !== null && !self::allowed($parent, $mode)) return false;
        if (self::audience($key, 'view') === false) return false;
        return $mode !== 'use' || self::audience($key, 'use') !== false;
    }

    public static function deny() {
        http_response_code(403);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'دسترسی به این بخش یا عملیات مجاز نیست.'], JSON_UNESCAPED_UNICODE);
        } else echo 'دسترسی به این بخش مجاز نیست.';
        exit;
    }
}
