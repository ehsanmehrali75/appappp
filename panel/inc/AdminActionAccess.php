<?php
/** The operation, rather than a caller-supplied tab, determines mutation access. */
class AdminActionAccess {
    public static function module($permission) {
        return ['reference_photo' => 'products', 'library' => 'products'][$permission] ?? $permission;
    }

    public static function formActions() {
        return [
            'settings' => ['save_project_settings', 'save_settings', 'save_automation', 'save_test_settings', 'send_test_sms', 'register_bale_webhook', 'register_tg_webhook', 'add_feedback', 'delete_feedback'],
            'users' => ['add_user', 'edit_user', 'delete_user'],
            'projects' => ['add_project', 'reset_project'],
            'main_settings' => ['save_site_settings', 'test_zarinpal_action'],
            'main_plans' => ['save_subscription_plans'],
            'main_orders' => ['update_order_status'],
            'storage_monitor' => ['update_project_quota'],
            'products' => ['add_manual_product', 'delete_product', 'delete_image', 'delete_social_image', 'update_product_data', 'delete_size_chart'],
            'calendar' => ['add_schedule', 'update_schedule', 'delete_schedule', 'quick_send_schedule', 'batch_fill_calendar', 'add_planner_custom_post', 'delete_day_action'],
            'extract' => ['add_scraping_job', 'cancel_job', 'clear_jobs', 'reset_extraction'],
            'auto_admin' => ['save_auto_admin', 'trigger_auto_admin'],
            'excel' => ['import_excel', 'confirm_import'],
            'occasions' => ['add_custom_occasion', 'delete_custom_occasion', 'save_occasion_sheet'],
            'logs' => ['clear_logs', 'trigger_cumulative_sms'],
            'system_debug' => ['clear_debug', 'clear_sms_logs'],
            'sale_analysis' => ['add_store_action', 'delete_store_action', 'save_store_config_action', 'delete_target_id', 'import_sales_data_action', 'save_sync_settings_action', 'manual_sync_analytics_action']
        ];
    }

    public static function ajaxActions() {
        return [
            'landing_builder' => ['landing_save', 'landing_generate_ai', 'landing_rewrite_ai', 'landing_seo_optimize_ai', 'landing_optimize_ux_ai'],
            'settings' => ['tiam_test_connection', 'tiam_trigger_sync', 'tiam_generate_scripts', 'clear_cache', 'test_conn', 'test_daily_notification', 'test_custom_url', 'ping_v2ray_sub', 'disconnect_instagram'],
            'adv_plan' => ['add_campaign', 'delete_campaign', 'upload_campaign_pdf', 'delete_campaign_pdf', 'add_campaign_task', 'delete_campaign_task', 'toggle_campaign_task', 'update_campaign_performance'],
            'occasions' => ['upload_occasion_pdf', 'save_occasion_platforms', 'delete_occasion_pdf', 'add_occasion_task', 'delete_occasion_task', 'toggle_occasion_task'],
            'extract' => ['discover', 'test_extract_conn', 'extract_draft_only', 'confirm_sync_drafts', 'image_debug_get_queue', 'image_debug_test_single', 'image_debug_batch_process', 'extract_single', 'extract', 'import_json'],
            'products' => ['send_quick', 'send_instagram_api', 'set_status', 'bulk_status'],
            'calendar' => ['get_pending_browser_relay_posts', 'mark_relay_schedule_status', 'send_schedule_quick', 'reset_calendar', 'reset_schedule_pending', 'retry_instagram_post']
        ];
    }

    /** Each item is an independently required operation; alternatives share one UI. */
    public static function requirements($post) {
        $requirements = [];
        foreach (self::formActions() as $feature => $actions) {
            foreach ($actions as $action) {
                if (isset($post[$action])) { $requirements[] = [$feature]; break; }
            }
        }
        foreach (['ajax_action', 'action'] as $field) {
            $action = $post[$field] ?? null;
            if (!is_string($action)) continue;
            // Reconciliation is rendered in both extraction and website export.
            if (in_array($action, ['website_catalog_upload_begin', 'website_catalog_upload_chunk', 'website_catalog_preview', 'website_catalog_apply', 'website_catalog_resolve', 'website_catalog_request_image'], true)) {
                $requirements[] = ['extract', 'website_export'];
                continue;
            }
            if (in_array($action, ['export_size_charts_only', 'export_website_begin', 'export_website_chunk', 'export_website_finish', 'export_website', 'mark_as_uploaded'], true)) {
                $requirements[] = ['extract', 'website_export'];
                continue;
            }
            if ($action === 'save_reference_photos_fast') {
                $requirements[] = ['reference_photo', 'library', 'products', 'extract', 'website_export'];
                continue;
            }
            // These product operations also appear in the shared work queues;
            // their handlers retain the specific product/reference actor checks.
            if (in_array($action, ['request_manual_size_chart', 'mark_size_chart_incomplete', 'mark_size_chart_complete', 'toggle_size_chart_uploaded', 'bulk_mark_size_chart_uploaded', 'toggle_content_priority'], true)) {
                $requirements[] = ['products', 'extract', 'website_export'];
                continue;
            }
            foreach (self::ajaxActions() as $feature => $actions) {
                if (in_array($action, $actions, true)) { $requirements[] = [$feature]; break; }
            }
        }
        return $requirements;
    }
}
