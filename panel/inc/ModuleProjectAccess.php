<?php
/** Resolve module resources before checking grants; a URL never inherits another project's role. */
class ModuleProjectAccess {
    public static function findProject($identifier) {
        if (!is_string($identifier) || $identifier === '') return null;
        $project = getCurrentProject($identifier, true);
        if ($project) return $project;
        foreach ((getDB()['projects'] ?? []) as $candidate) {
            if (($candidate['slug'] ?? null) === $identifier) return getCurrentProject($candidate['id'], true);
        }
        return null;
    }

    public static function select($identifier) {
        if (!isUser()) AccessControl::deny();
        $project = self::findProject($identifier);
        if (!$project || !SessionIdentity::select($project)) AccessControl::deny();
        return $project;
    }

    public static function allowed($parent, $permission, $feature = null, $mode = 'view') {
        if (FeatureFlags::isUnrestrictedAdmin()) return true;
        $feature = $feature ?? $parent;
        if (!FeatureFlags::isAvailable($parent) || !AccessControl::allowed($parent, $mode) || !isModuleActive($parent)) return false;
        if (!FeatureFlags::isAvailable($feature) || !AccessControl::allowed($feature, $mode)) return false;
        if (!isModuleActive($feature)) return false;
        return hasPermission($parent, $mode) || ($feature !== $parent && hasPermission($feature, $mode));
    }

    public static function enforce($parent, $permission, $feature = null, $mode = 'view') {
        if (!self::allowed($parent, $permission, $feature, $mode)) AccessControl::deny();
    }

    public static function mutation() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') AccessControl::deny();
        RequestSecurity::enforce();
    }

    public static function visibleProjects($projects, $parent, $permission) {
        $savedProject = $_SESSION['project_id'] ?? null;
        $savedUser = $_SESSION['user'] ?? null;
        $visible = [];
        foreach ($projects as $candidate) {
            $project = self::findProject($candidate['id'] ?? null);
            if (!$project || !SessionIdentity::select($project)) continue;
            if (self::allowed($parent, $permission)) $visible[] = $candidate;
        }
        $_SESSION['project_id'] = $savedProject;
        $_SESSION['user'] = $savedUser;
        return $visible;
    }

    public static function vmFeature($view) {
        return ['stores'=>'vm_stores', 'store'=>'vm_stores', 'studio'=>'vm_layout', 'analytics'=>'vm_analytics',
            'scores'=>'vm_scores', 'recommendations'=>'vm_ai', 'reports'=>'vm_reports'][$view] ?? 'vm_studio';
    }

    public static function seoFeature($view) {
        return ['rank_tracking'=>'seo_intel_keywords', 'keywords'=>'seo_intel_keywords', 'pages'=>'seo_intel_pages',
            'links'=>'seo_intel_pages', 'technical'=>'seo_intel_technical', 'traffic'=>'seo_intel_traffic',
            'content'=>'seo_intel_content', 'social'=>'seo_intel_social', 'recommendations'=>'seo_intel_recommendations',
            'integrations'=>'seo_intel_integrations', 'reports'=>'seo_intel_reports'][$view] ?? 'seo_intel';
    }

    public static function businessProject($business) {
        if (!is_array($business)) return null;
        return self::findProject($business['project_id'] ?? '') ?: self::findProject($business['id'] ?? '') ?: self::findProject($business['slug'] ?? '');
    }

    public static function business($identifier) {
        if (!isUser() || !is_string($identifier) || $identifier === '') AccessControl::deny();
        $business = null;
        // getBusiness() has a legacy first-business fallback. Authorization requires an exact match.
        foreach (SeoDB::getBusinesses() as $candidate) {
            if (($candidate['id'] ?? '') === $identifier || ($candidate['slug'] ?? '') === $identifier) { $business = $candidate; break; }
        }
        if (!$business) AccessControl::deny();
        $project = self::businessProject($business);
        if ($project) {
            if (!SessionIdentity::select($project)) AccessControl::deny();
        } elseif (!isMainAdmin()) AccessControl::deny();
        return $business;
    }

    public static function visibleBusinesses($businesses) {
        $savedProject = $_SESSION['project_id'] ?? null;
        $savedUser = $_SESSION['user'] ?? null;
        $visible = [];
        foreach ($businesses as $business) {
            $project = self::businessProject($business);
            if ($project ? !SessionIdentity::select($project) : !isMainAdmin()) continue;
            if (self::allowed('seo_intel', 'analysis')) $visible[] = $business;
        }
        $_SESSION['project_id'] = $savedProject;
        $_SESSION['user'] = $savedUser;
        return $visible;
    }
}
