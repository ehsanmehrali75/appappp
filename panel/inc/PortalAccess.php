<?php
/** Portal pages and actions use the same feature grants as management pages. */
class PortalAccess {
    public static function canProducts($mode = 'view') {
        foreach (['products', 'library', 'reference_photo', 'approve_content'] as $permission)
            if (canAccessFeature($permission, $mode)) return true;
        return false;
    }

    public static function canViewTab($tab) {
        if ($tab === 'calendar' || $tab === 'occasions') return canAccessFeature($tab, 'view');
        return self::canProducts();
    }

    public static function canAction($action) {
        if ($action === 'update_profile') return true;
        if ($action === 'cast_occasion_vote') return canAccessFeature('occasions', 'use');
        if (in_array($action, ['get_pending_browser_relay_posts', 'mark_relay_schedule_status', 'vote_schedule', 'comment_schedule'], true))
            return canAccessFeature('calendar', 'use');
        if (in_array($action, ['upload_product_ref_images', 'delete_product_ref_image'], true)) {
            foreach (['reference_photo', 'library', 'products'] as $permission)
                if (canAccessFeature($permission, 'use')) return true;
            return false;
        }
        if (in_array($action, ['approve', 'reject'], true)) return canAccessFeature('approve_content', 'use');
        if (in_array($action, ['quick_action', 'vote'], true))
            return canAccessFeature('products', 'use') || canAccessFeature('approve_content', 'use');
        return in_array($action, ['create_couple_set', 'bulk_request_content_gen', 'reset_content_gen_priority', 'bulk_request_publish_priority', 'reset_publish_priority', 'comment', 'feedback'], true)
            && canAccessFeature('products', 'use');
    }
}
