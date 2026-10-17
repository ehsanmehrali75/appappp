<?php
/** One permission vocabulary for both member forms; legacy grants stay editable. */
class ProjectPermissions {
    /** Client approval widgets need workflow thresholds, never integration secrets. */
    public static function publicSettings($settings) {
        return array_intersect_key($settings, array_flip([
            'prod_approval_split_enabled', 'prod_approval_stage1_min', 'prod_approval_stage2_min',
            'prod_approval_stage1_users', 'prod_approval_stage2_users',
            'cal_approval_split_enabled', 'cal_approval_stage1_min', 'cal_approval_stage2_min',
            'cal_approval_stage1_users', 'cal_approval_stage2_users',
        ]));
    }

    public static function catalog($members = []) {
        $permissions = [];
        foreach (FeatureFlags::getCatalog() as $key => $feature) {
            if (in_array($feature['category'] ?? '', ['core', 'markshow'], true)) continue;
            $permissions[$key] = ($feature['icon'] ?? '') . ' ' . $feature['title'];
        }
        $permissions += [
            'library' => '📚 کتابخانه محتوا (مجوز مستقل)',
            'approve' => '✈️ تأیید ارسال به شبکه‌های اجتماعی',
            'approve_content' => '✓ تأیید یا رد تصاویر محتوا',
            'reference_photo' => '📸 ثبت عکس رفرنس',
            'prompthub_manage' => '🧠 مدیریت محتوای پرامپت هاب',
            'analysis' => '📊 تحلیل فروش و سئو (مجوز قدیمی مشترک)',
        ];
        foreach ($members as $member) {
            foreach (($member['permissions'] ?? []) as $permission) {
                if (!is_string($permission)) continue;
                $canonical = AccessControl::featureKey($permission);
                if (!isset($permissions[$canonical])) $permissions[$canonical] = 'مجوز قبلی: ' . $canonical;
            }
        }
        return $permissions;
    }

    public static function canManageMember($member) {
        if (isMainAdmin()) return true;
        if (in_array($member['role'] ?? '', ['owner', 'main_admin', 'superadmin'], true)) return false;
        if (isProjectAdmin()) return true;
        if (!hasPermission('users', 'use') || ($member['role'] ?? '') === 'project_admin') return false;
        foreach (($member['permissions'] ?? []) as $permission) {
            if (!hasPermission($permission, 'use')) return false;
        }
        return true;
    }

    public static function assignable($catalog) {
        if (isMainAdmin() || isProjectAdmin()) return $catalog;
        return array_filter($catalog, static fn($label, $key) => hasPermission($key, 'use'), ARRAY_FILTER_USE_BOTH);
    }

    public static function publicMember($member) {
        // The editor never needs a stored password or its hash.
        $public = array_intersect_key($member, array_flip(['username', 'role', 'mobile', 'telegram_chat_id', 'bale_chat_id', 'permissions', 'assigned_projects']));
        $public['permissions'] = array_values(array_unique(array_map([AccessControl::class, 'featureKey'], $member['permissions'] ?? [])));
        return $public;
    }
}
