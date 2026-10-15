# UI audit and staged redesign

Baseline: `52e2ad9`; urgent support/owner fixes: `cda7b07`. This is a source inventory, not a claim that every route has been exercised in a browser.

## Architecture and constraints

The PHP app uses server-rendered pages and a large tab controller (`panel/admin.php`), local Tailwind runtime, shared CSS, jQuery, Chart.js and Persian date widgets. Root routes include legacy duplicates and aliases into panel modules. There is no required frontend build step; package build/lint placeholders are not meaningful verification. Permissions pass through AccessControl/FeatureFlags and project membership; preserve these gates and every form name, DOM ID, endpoint and POST action.

Shared components: header, sidebar search/category groups, project selectors, cards, forms, tables, custom modal overlays and global loader. Independent UIs include size chart studio, catalogs, VM, SEO intelligence, prompt hub, task management, support and portal. Landing-builder iframe templates are separate documents. Size studio custom mouse dragging needs a deliberate touch adaptation in a later phase; changing the shell alone cannot fix it.

## Current gaps and risks

* CSS is duplicated between shared assets and page inline styles; introduce scoped overrides on a dashboard body class before any rollout. Root/panel duplicates require route-by-route checks.
* Existing sidebar uses a 1024px mobile boundary while the Tailwind hamburger uses lg:hidden; test exactly 1024px. Focus management and keyboard dismissal need improvement.
* Existing dashboard uses actual project products, votes and schedules. Its chart currently substitutes today's date for products missing an extraction timestamp; the pilot should exclude unknown timestamps without modifying stored products. Do not invent metrics or growth percentages.
* There is no manifest, service worker or Web Push subscription backend. Existing MarkShow notifications and task messenger hooks need a recipient/security audit before new delivery channels. Do not show pretend notification counts or ask for Push permission on page load.
* Existing authenticated APIs, private support attachments, sessions and POSTs must never enter a PWA response cache. Future offline mode should cache only versioned public shell assets and explicitly state that server changes are unavailable offline.
* Main calendar and custom editors require real touch workflows, not only narrower containers. Tables require readable cards or contained scrolling with controls on mobile.

## Acceptance stages

1. Source audit and working dashboard pilot: shared tokens, sage/gray soft shadows, real data, accessible navigation, mobile/desktop and legacy comparison.
2. User appearance/UX acceptance (explicit requirement in the attached master prompt), then migrate module groups without changing business logic.
3. PWA separately: public asset cache, offline page, user-controlled install/update flow, no unsaved-data loss.
4. Notifications separately: reversible schema, per-user preferences/subscriptions, VAPID configuration, real assignment/approval events, authorized recipients and deduplication, delivery/revocation tests and iOS installation guidance.

## Validation

Capture the existing dashboard first. Check the pilot at 320, 375, 768, 1024 and 1440px, keyboard drawer operation, link destinations, role-dependent output, no horizontal overflow, no new JS errors, non-dashboard styles unchanged, and real empty states. Compare local browser navigation timings as indicative measurements only (not production performance). Run PHP syntax and existing access/workflow tests. Future modules need their own save, search, drag/drop and modal regression checks. Live-host deployment, real SQL/VPN, and actual Push delivery cannot be inferred from local tests.

## PHP route/component inventory

The counts are static matches, used to locate integration points. Dynamic templates may add more at runtime.

| File | Kind | Forms | fetch/ajax | iframe |
|---|---|---:|---:|---:|
| about.php | HTML page | 0 | 0 | 0 |
| admin/business/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| admin/projects/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| admin.php | controller/API/alias/CLI | 0 | 0 | 0 |
| ajax_helper.php | controller/API/alias/CLI | 0 | 0 | 0 |
| api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| callback/account/Instagram/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| catalog/index.php | HTML page | 0 | 4 | 0 |
| chat/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| chat.php | HTML page | 5 | 1 | 0 |
| chat_webhook.php | controller/API/alias/CLI | 0 | 0 | 0 |
| contact.php | HTML page | 1 | 0 | 0 |
| cron.php | controller/API/alias/CLI | 0 | 0 | 0 |
| debug.php | controller/API/alias/CLI | 0 | 0 | 0 |
| debug_db.php | controller/API/alias/CLI | 0 | 0 | 0 |
| emergency_reset.php | controller/API/alias/CLI | 1 | 0 | 0 |
| find_mysql.php | controller/API/alias/CLI | 0 | 0 | 0 |
| get_debug_logs.php | controller/API/alias/CLI | 0 | 0 | 0 |
| get_diag.php | controller/API/alias/CLI | 0 | 0 | 0 |
| get_logs.php | controller/API/alias/CLI | 0 | 0 | 0 |
| inc/ImportService.php | library/view | 0 | 0 | 0 |
| inc/analysis_view.php | library/view | 7 | 0 | 0 |
| inc/auto_admin_engine.php | library/view | 0 | 0 | 0 |
| inc/cors.php | library/view | 0 | 0 | 0 |
| inc/db.php | library/view | 0 | 0 | 0 |
| inc/functions.php | library/view | 0 | 0 | 0 |
| inc/jdf.php | library/view | 0 | 0 | 0 |
| index.php | HTML page | 4 | 0 | 0 |
| log_socket.php | controller/API/alias/CLI | 0 | 0 | 0 |
| logout.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/admin.php | HTML page | 42 | 95 | 0 |
| panel/ajax_helper.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/callback_instagram.php | HTML page | 0 | 0 | 0 |
| panel/catalog/index.php | HTML page | 0 | 4 | 0 |
| panel/chat/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/chat.php | HTML page | 5 | 1 | 0 |
| panel/chat_webhook.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/cron.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/debug.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/debug_db.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/emergency_reset.php | controller/API/alias/CLI | 1 | 0 | 0 |
| panel/find_mysql.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/get_debug_logs.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/get_diag.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/get_logs.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/inc/AccessControl.php | library/view | 0 | 0 | 0 |
| panel/inc/FeatureFlags.php | library/view | 0 | 0 | 0 |
| panel/inc/ImportService.php | library/view | 0 | 0 | 0 |
| panel/inc/InstagramAdapter.php | library/view | 0 | 0 | 0 |
| panel/inc/InstagramPublishHandler.php | library/view | 0 | 0 | 0 |
| panel/inc/MarkShowCore.php | library/view | 0 | 0 | 0 |
| panel/inc/SupportTickets.php | library/view | 0 | 0 | 0 |
| panel/inc/SupportUploads.php | library/view | 0 | 0 | 0 |
| panel/inc/SystemAdmins.php | library/view | 0 | 0 | 0 |
| panel/inc/SystemAdminsView.php | library/view | 0 | 1 | 0 |
| panel/inc/TiamConnector.php | library/view | 0 | 0 | 0 |
| panel/inc/TiamInventory.php | library/view | 0 | 0 | 0 |
| panel/inc/TiamInventoryView.php | library/view | 0 | 1 | 0 |
| panel/inc/TiamVpnManager.php | library/view | 0 | 0 | 0 |
| panel/inc/analysis_view.php | library/view | 7 | 0 | 0 |
| panel/inc/auto_admin_engine.php | library/view | 0 | 0 | 0 |
| panel/inc/cors.php | library/view | 0 | 0 | 0 |
| panel/inc/db.php | library/view | 0 | 0 | 0 |
| panel/inc/feature_flags_view.php | library/view | 1 | 0 | 0 |
| panel/inc/functions.php | library/view | 0 | 0 | 0 |
| panel/inc/jdf.php | library/view | 0 | 0 | 0 |
| panel/inc/markshow_views.php | library/view | 17 | 0 | 0 |
| panel/inc/portal_tickets_view.php | library/view | 3 | 0 | 0 |
| panel/instagram_oauth_start.php | HTML page | 0 | 0 | 0 |
| panel/log_socket.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/logout.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/portal.php | HTML page | 3 | 28 | 0 |
| panel/pro/index.php | HTML page | 1 | 4 | 0 |
| panel/prompthub.php | HTML page | 1 | 10 | 0 |
| panel/sale/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/sale.php | HTML page | 5 | 1 | 0 |
| panel/save_php_info.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo.php | HTML page | 51 | 4 | 0 |
| panel/seo_intel/ComposioService.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/SeoAIAnalyst.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/SeoDB.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/SyncEngine.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/index.php | HTML page | 0 | 10 | 0 |
| panel/seo_intel/views/content.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/integrations.php | controller/API/alias/CLI | 2 | 0 | 0 |
| panel/seo_intel/views/keywords.php | controller/API/alias/CLI | 2 | 0 | 0 |
| panel/seo_intel/views/links.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/overview.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/pages.php | controller/API/alias/CLI | 1 | 0 | 0 |
| panel/seo_intel/views/rank_tracking.php | controller/API/alias/CLI | 2 | 1 | 0 |
| panel/seo_intel/views/recommendations.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/reports.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/social.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/technical.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/seo_intel/views/traffic.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/size/index.php | HTML page | 0 | 6 | 0 |
| panel/support.php | HTML page | 6 | 0 | 0 |
| panel/system-admin-setup.php | HTML page | 1 | 0 | 0 |
| panel/system_admins.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/task_manager.php | HTML page | 0 | 6 | 0 |
| panel/test_diag.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/test_php.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/tiam_inventory.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/user_chat.php | HTML page | 0 | 5 | 0 |
| panel/vm/api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/index.php | HTML page | 0 | 1 | 0 |
| panel/vm/views/analytics.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/overview.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/recommendations.php | controller/API/alias/CLI | 0 | 2 | 0 |
| panel/vm/views/reports.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/scores.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/store_detail.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/stores.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/views/studio.php | controller/API/alias/CLI | 0 | 1 | 0 |
| panel/vm/vm_ai.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/vm_db.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/vm/vm_mflow.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/widget-iframe.php | HTML page | 2 | 4 | 0 |
| panel/widget_api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| panel/wp-crm.php | HTML page | 3 | 5 | 0 |
| portal.php | controller/API/alias/CLI | 0 | 0 | 0 |
| pro/index.php | HTML page | 1 | 4 | 0 |
| prompthub.php | HTML page | 1 | 10 | 0 |
| router.php | controller/API/alias/CLI | 0 | 0 | 0 |
| sale/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| sale.php | HTML page | 5 | 1 | 0 |
| save_php_info.php | controller/API/alias/CLI | 0 | 0 | 0 |
| seo/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| seo.php | HTML page | 51 | 4 | 0 |
| size/index.php | controller/API/alias/CLI | 0 | 0 | 0 |
| support.php | controller/API/alias/CLI | 0 | 0 | 0 |
| task_manager.php | HTML page | 0 | 6 | 0 |
| test_diag.php | controller/API/alias/CLI | 0 | 0 | 0 |
| test_php.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tests/access_control.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tests/support_mysql.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tests/support_tickets.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tests/system_admins.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tests/tiam_inventory.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tools/create-system-admin.php | controller/API/alias/CLI | 0 | 0 | 0 |
| tools/support-maintenance.php | controller/API/alias/CLI | 0 | 0 | 0 |
| user_chat.php | HTML page | 0 | 5 | 0 |
| widget-iframe.php | HTML page | 2 | 4 | 0 |
| widget_api.php | controller/API/alias/CLI | 0 | 0 | 0 |
| wp-crm.php | HTML page | 3 | 5 | 0 |

## Feature catalog / admin tabs

| Key | Route | Title |
|---|---|---|
| support | support.php | پشتیبانی |
| main_orders | admin.php?tab=main_orders | سفارشات و پرداخت‌ها |
| main_plans | admin.php?tab=main_plans | مدیریت تعرفه‌ها و پلن‌ها |
| main_settings | admin.php?tab=main_settings | تنظیمات اصلی وب‌سایت |
| projects | admin.php?tab=projects | پروژه‌های فعال و برندها |
| storage_monitor | admin.php?tab=storage_monitor | مانیتورینگ فضا و کش |
| system_debug | admin.php?tab=system_debug | دیباگ و سلامت سیستم |
| markshow_org | admin.php?tab=markshow_org | سازمان و تیم مرکزی (Mark Show) |
| markshow_customers | admin.php?tab=markshow_customers | مدیریت مشتریان و پروژه‌ها |
| markshow_roles | admin.php?tab=markshow_roles | نقش‌ها و سطوح دسترسی (RBAC) |
| markshow_tickets | admin.php?tab=markshow_tickets | میز خدمت و تیکتینگ یکپارچه |
| markshow_financial | admin.php?tab=markshow_financial | مدیریت مالی و تایید رسیدها |
| dashboard | admin.php?tab=dashboard | میز کار (Insight Dashboard) |
| chat | chat.php | پاسخگویی و چت زنده |
| products | admin.php?tab=products | کتابخانه محتوا و کالاها |
| extract | admin.php?tab=extract | اتوماسیون استخراج هوشمند (Scraper) |
| excel | admin.php?tab=excel | مدیریت اکسل و ورود گروهی |
| website_export | admin.php?tab=website_export | خروجی وب‌سایت (Website Export) |
| wp_crm | wp-crm.php | سینک وردپرس CRM و ووکامرس |
| calendar | admin.php?tab=calendar | تقویم انتشار محتوا |
| occasions | admin.php?tab=occasions | تقویم مناسبت‌ها و کمپین‌ها |
| adv_plan | admin.php?tab=adv_plan | پلن تبلیغاتی و اینفلوئنسرها |
| auto_admin | admin.php?tab=auto_admin | ادمین خودکار روباتیک (Auto Pilot) |
| prompthub | prompthub.php | پرامپت هاب هوش مصنوعی (PromptHub) |
| landing_builder | admin.php?tab=landing_builder | سازنده لندینگ هوشمند (AI Landing) |
| size_studio | size/ | استودیو جدول سایز (Size Studio) |
| catalog | catalog/ | کاتالوگ آنلاین هوشمند |
| proposals | pro/ | پروپوزال و برنامه‌ریزی استراتژیک |
| seo_classic | seo.php | آنالیز و سئو کلاسیک (SEO Panel) |
| sale_analysis | sale.php | تحلیل مبالغ و شعب (Sales BI) |
| seo_intel | /admin/business/{slug}/seo | کل ماژول سئو سازمانی Composio |
| seo_intel_keywords | /admin/business/{slug}/seo/keywords | هوش کلمات کلیدی (Keywords) |
| seo_intel_pages | /admin/business/{slug}/seo/pages | تحلیل صفحات و محتوا (Pages) |
| seo_intel_technical | /admin/business/{slug}/seo/technical | سئو تکنیکال (Technical Audits) |
| seo_intel_traffic | /admin/business/{slug}/seo/traffic | ترافیک و آنالیتیکس (GA4) |
| seo_intel_content | /admin/business/{slug}/seo/content | محتوا و درآمدزایی (Content & Revenue) |
| seo_intel_social | /admin/business/{slug}/seo/social | شبکه‌های اجتماعی (Social Reach) |
| seo_intel_recommendations | /admin/business/{slug}/seo/recommendations | پیشنهادات هوش مصنوعی سئو (AI Recs) |
| seo_intel_integrations | /admin/business/{slug}/seo/integrations | یکپارچه‌سازی و کانکشن‌های Composio |
| seo_intel_reports | /admin/business/{slug}/seo/reports | گزارش جامع بیزنس (Executive Reports) |
| vm_studio | /admin/projects/{slug}/vm | کل ماژول چیدمان و استودیو VM |
| vm_stores | /admin/projects/{slug}/vm/stores | مدیریت شعب و زون‌های فروشگاه |
| vm_layout | /admin/projects/{slug}/vm/studio | استودیو ترسیم چیدمان بصری (2D Studio) |
| vm_analytics | /admin/projects/{slug}/vm/analytics | آنالیز چیدمان و نقشه حرارتی (Heatmaps) |
| vm_scores | /admin/projects/{slug}/vm/scores | امتیاز چیدمان شعب (Store Scores) |
| vm_ai | /admin/projects/{slug}/vm/recommendations | دستیار هوش مصنوعی VM |
| vm_reports | /admin/projects/{slug}/vm/reports | گزارشات جامع چیدمان |
| users | admin.php?tab=users | مدیریت کاربران و دسترسی‌ها |
| users_performance | admin.php?tab=users_performance | عملکرد و کارایی پرسنل |
| logs | admin.php?tab=logs | لاگ‌ها و حسابرسی سیستم |
| settings | admin.php?tab=settings | پیکربندی و تنظیمات پروژه |
