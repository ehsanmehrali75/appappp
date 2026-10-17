<?php
/**
 * FeatureFlags - Centralized Feature & Module Management Engine
 * Controls feature toggling, maintenance mode, and admin-only visibility.
 */

if (!defined('FEATURE_FLAGS_FILE')) {
    define('FEATURE_FLAGS_FILE', __DIR__ . '/../db/feature_flags.json');
}

require_once __DIR__ . '/AccessControl.php';

class FeatureFlags {
    const STATUS_ACTIVE = 'active';           // فعال برای همه کاربران
    const STATUS_MAINTENANCE = 'maintenance'; // در دست تعمیر (فقط ادمین اصلی)
    const STATUS_DISABLED = 'disabled';       // غیرفعال کامل (مخفی حتی از ادمین)

    private static $cache = null;

    /**
     * System administrator authority comes from the role, never the username.
     */
    public static function isMainAdmin() {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) return false;
        return in_array($user['role'] ?? '', ['owner', 'main_admin', 'superadmin'], true);
    }

    public static function isUnrestrictedAdmin() {
        return ($_SESSION['user']['role'] ?? '') === 'owner';
    }

    /**
     * Comprehensive catalog of all features, menus, and capabilities in the system
     */
    public static function getCatalog() {
        return [
            'support' => ['key'=>'support','title'=>'پشتیبانی','icon'=>'🎫','category'=>'settings_users','category_title'=>'⚙️ مدیریت و دسترسی‌ها','desc'=>'ثبت درخواست، پاسخ ادمین اصلی و تأیید هزینه با مهلت ۴۸ ساعت','url'=>'support.php','type'=>'page'],
            // ==========================================
            // دسته ۱: مدیریت کلان وب‌سایت (Core System)
            // ==========================================
            'main_orders' => [
                'key' => 'main_orders',
                'title' => 'سفارشات و پرداخت‌ها',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '💳',
                'desc' => 'مشاهده و پیگیری سفارشات، پرداخت‌های زرین‌پال و تایید تراکنش‌ها',
                'url' => 'admin.php?tab=main_orders',
                'type' => 'tab'
            ],
            'main_plans' => [
                'key' => 'main_plans',
                'title' => 'مدیریت تعرفه‌ها و پلن‌ها',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '💎',
                'desc' => 'تنظیم پکیج‌های اشتراک، قیمت‌گذاری ماهانه/سالانه و امکانات هر پلن',
                'url' => 'admin.php?tab=main_plans',
                'type' => 'tab'
            ],
            'main_settings' => [
                'key' => 'main_settings',
                'title' => 'تنظیمات اصلی وب‌سایت',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '🌐',
                'desc' => 'پیکربندی هویت سایت، درگاه پرداخت زرین‌پال، اطلاعات تماس و نمادها',
                'url' => 'admin.php?tab=main_settings',
                'type' => 'tab'
            ],
            'projects' => [
                'key' => 'projects',
                'title' => 'پروژه‌های فعال و برندها',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '🏢',
                'desc' => 'ایجاد، سوئیچ و مدیریت پروژه‌های مستقل برندها و فروشگاه‌ها',
                'url' => 'admin.php?tab=projects',
                'type' => 'tab'
            ],
            'storage_monitor' => [
                'key' => 'storage_monitor',
                'title' => 'مانیتورینگ فضا و کش',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '💾',
                'desc' => 'پایش حجم دیسک، کش‌ها، پایگاه داده و فایل‌های چندرسانه‌ای سرور',
                'url' => 'admin.php?tab=storage_monitor',
                'type' => 'tab'
            ],
            'system_debug' => [
                'key' => 'system_debug',
                'title' => 'دیباگ و سلامت سیستم',
                'category' => 'core',
                'category_title' => '👑 مدیریت کلان وب‌سایت',
                'icon' => '🛠️',
                'desc' => 'تست سلامت دیتابیس، لاگ خطاها و اطلاعات محیط اجرایی سرور',
                'url' => 'admin.php?tab=system_debug',
                'type' => 'tab'
            ],

            // ==========================================
            // دسته ۱.۵: سازمان مرکزی و مدیریت پروژه‌ها (Mark Show Core)
            // ==========================================
            'markshow_org' => [
                'key' => 'markshow_org',
                'title' => 'سازمان و تیم مرکزی (Mark Show)',
                'category' => 'markshow',
                'category_title' => '🏢 سازمان مرکزی Mark Show',
                'icon' => '🏛️',
                'desc' => 'مدیریت تیم مرکزی، تعیین پوزیشن‌ها و مدیریت سلسله مراتب سازمانی',
                'url' => 'admin.php?tab=markshow_org',
                'type' => 'tab'
            ],
            'markshow_customers' => [
                'key' => 'markshow_customers',
                'title' => 'مدیریت مشتریان و پروژه‌ها',
                'category' => 'markshow',
                'category_title' => '🏢 سازمان مرکزی Mark Show',
                'icon' => '👥',
                'desc' => 'پرونده مشتریان، انتساب چندگانه پروژه‌ها و پایش وضعیت همکاری',
                'url' => 'admin.php?tab=markshow_customers',
                'type' => 'tab'
            ],
            'markshow_roles' => [
                'key' => 'markshow_roles',
                'title' => 'نقش‌ها و سطوح دسترسی (RBAC)',
                'category' => 'markshow',
                'category_title' => '🏢 سازمان مرکزی Mark Show',
                'icon' => '🛡️',
                'desc' => 'تعریف پوزیشن‌های شغلی (PM, Financial, Support, Tech) با دسترسی‌های جزئی‌نگر',
                'url' => 'admin.php?tab=markshow_roles',
                'type' => 'tab'
            ],
            'markshow_tickets' => [
                'key' => 'markshow_tickets',
                'title' => 'میز خدمت و تیکتینگ یکپارچه',
                'category' => 'markshow',
                'category_title' => '🏢 سازمان مرکزی Mark Show',
                'icon' => '🎫',
                'desc' => 'مدیریت تیکت‌های پشتیبانی، درخواست‌های هزینه دار/رایگان و تبدیل به تسک اجرایی',
                'url' => 'admin.php?tab=markshow_tickets',
                'type' => 'tab'
            ],
            'markshow_financial' => [
                'key' => 'markshow_financial',
                'title' => 'مدیریت مالی و تایید رسیدها',
                'category' => 'markshow',
                'category_title' => '🏢 سازمان مرکزی Mark Show',
                'icon' => '💳',
                'desc' => 'پایش تراکنش‌ها، تایید یا رد رسیدهای بانکی تیکت‌ها و گزارش درآمد سازمان',
                'url' => 'admin.php?tab=markshow_financial',
                'type' => 'tab'
            ],

            // ==========================================
            // دسته ۲: منوهای اصلی و ارتباطات (General)
            // ==========================================
            'dashboard' => [
                'key' => 'dashboard',
                'title' => 'میز کار (Insight Dashboard)',
                'category' => 'general',
                'category_title' => '📊 منوهای اصلی و عمومی',
                'icon' => '📈',
                'desc' => 'داشبورد هوشمند مانیتورینگ عملکرد کتابخانه، ویجت‌های تحلیلی و وضعیت پروژه',
                'url' => 'admin.php?tab=dashboard',
                'type' => 'tab'
            ],
            'chat' => [
                'key' => 'chat',
                'title' => 'پاسخگویی و چت زنده',
                'category' => 'general',
                'category_title' => '📊 منوهای اصلی و عمومی',
                'icon' => '💬',
                'desc' => 'سامانه گفتگوی آنلاین با کاربران و مشتریان متصل به ویجت اختصاصی',
                'url' => 'chat.php',
                'type' => 'standalone'
            ],

            // ==========================================
            // دسته ۳: مدیریت محتوا و کالا (Content & Store)
            // ==========================================
            'products' => [
                'key' => 'products',
                'title' => 'کتابخانه محتوا و کالاها',
                'category' => 'content',
                'category_title' => '💎 مدیریت محتوا و کالا',
                'icon' => '💎',
                'desc' => 'آرشیو کالاها، گالری تصاویر، تگ‌ها، ویژگی‌ها و وضعیت انتشار محصولات',
                'url' => 'admin.php?tab=products',
                'type' => 'tab'
            ],
            'extract' => [
                'key' => 'extract',
                'title' => 'اتوماسیون استخراج هوشمند (Scraper)',
                'category' => 'content',
                'category_title' => '💎 مدیریت محتوا و کالا',
                'icon' => '✨',
                'desc' => 'استخراج مشخصات و تصاویر کالاها از لینک‌ها و پلتفرم‌های خارجی با AI',
                'url' => 'admin.php?tab=extract',
                'type' => 'tab'
            ],
            'excel' => [
                'key' => 'excel',
                'title' => 'مدیریت اکسل و ورود گروهی',
                'category' => 'content',
                'category_title' => '💎 مدیریت محتوا و کالا',
                'icon' => '📊',
                'desc' => 'ایمپورت و اکسپورت فایل‌های اکسل/CSV، نگاشت ستون‌ها و همگام‌سازی از شیت',
                'url' => 'admin.php?tab=excel',
                'type' => 'tab'
            ],
            'website_export' => [
                'key' => 'website_export',
                'title' => 'خروجی وب‌سایت (Website Export)',
                'category' => 'content',
                'category_title' => '💎 مدیریت محتوا و کالا',
                'icon' => '🌐',
                'desc' => 'ساخت بسته JSON استاندارد و دایرکتوری داده‌ها برای اتصال به سایت اصلی',
                'url' => 'admin.php?tab=website_export',
                'type' => 'tab'
            ],
            'wp_crm' => [
                'key' => 'wp_crm',
                'title' => 'سینک وردپرس CRM و ووکامرس',
                'category' => 'content',
                'category_title' => '💎 مدیریت محتوا و کالا',
                'icon' => '🛒',
                'desc' => 'همگام‌سازی دوطرفه کالاها، مشتریان و سفارشات با سایت وردپرسی و WooCommerce',
                'url' => 'wp-crm.php',
                'type' => 'standalone'
            ],

            // ==========================================
            // دسته ۴: تقویم و ابزارهای تولید (Calendar & AI)
            // ==========================================
            'calendar' => [
                'key' => 'calendar',
                'title' => 'تقویم انتشار محتوا',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '📅',
                'desc' => 'برنامه‌ریزی، زمان‌بندی و مدیریت صف انتشار پست‌ها و استوری‌ها',
                'url' => 'admin.php?tab=calendar',
                'type' => 'tab'
            ],
            'occasions' => [
                'key' => 'occasions',
                'title' => 'تقویم مناسبت‌ها و کمپین‌ها',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '🏮',
                'desc' => 'رویدادها و مناسبت‌های شمسی/جهانی جهت تولید محتوا و حراج‌های فصلی',
                'url' => 'admin.php?tab=occasions',
                'type' => 'tab'
            ],
            'adv_plan' => [
                'key' => 'adv_plan',
                'title' => 'پلن تبلیغاتی و اینفلوئنسرها',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '📢',
                'desc' => 'ردیابی همکاری با صفحات، زمان‌بندی تبلیغات و ارزیابی نرخ بازگشت بودجه',
                'url' => 'admin.php?tab=adv_plan',
                'type' => 'tab'
            ],
            'auto_admin' => [
                'key' => 'auto_admin',
                'title' => 'ادمین خودکار روباتیک (Auto Pilot)',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '🤖',
                'desc' => 'موتور اجرای کارهای خودکار دوره‌ای، تولید محتوا و ارسال زمان‌بندی‌شده',
                'url' => 'admin.php?tab=auto_admin',
                'type' => 'tab'
            ],
            'prompthub' => [
                'key' => 'prompthub',
                'title' => 'پرامپت هاب هوش مصنوعی (PromptHub)',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '🧠',
                'desc' => 'بانک تخصصی پرامپت‌های مهندسی‌شده برای تولید تصاویر و توضیحات کالا با AI',
                'url' => 'prompthub.php',
                'type' => 'standalone'
            ],
            'landing_builder' => [
                'key' => 'landing_builder',
                'title' => 'سازنده لندینگ هوشمند (AI Landing)',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '🚀',
                'desc' => 'طراحی سریع و ویرایش بصری صفحات فرود و جشنواره‌ها با کمک هوش مصنوعی',
                'url' => 'admin.php?tab=landing_builder',
                'type' => 'tab'
            ],
            'size_studio' => [
                'key' => 'size_studio',
                'title' => 'استودیو جدول سایز (Size Studio)',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '📐',
                'desc' => 'طراحی بصری راهنمای اندازه‌ها و قواره‌ها متناسب با البسه و محصولات',
                'url' => 'size/',
                'type' => 'standalone'
            ],
            'catalog' => [
                'key' => 'catalog',
                'title' => 'کاتالوگ آنلاین هوشمند',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '📖',
                'desc' => 'ارائه لوک‌بوک و کاتالوگ تعاملی دیجیتال برای اشتراک‌گذاری با مشتریان',
                'url' => 'catalog/',
                'type' => 'standalone'
            ],
            'proposals' => [
                'key' => 'proposals',
                'title' => 'پروپوزال و برنامه‌ریزی استراتژیک',
                'category' => 'calendar_ai',
                'category_title' => '🗓️ تقویم و ابزارهای تولید محتوا',
                'icon' => '📋',
                'desc' => 'تدوین مستندات استراتژی، پروپوزال‌های تجاری و نقشه‌های راه پروژه',
                'url' => 'pro/',
                'type' => 'standalone'
            ],

            // ==========================================
            // دسته ۵: سئو و هوش تجاری سنتی (Analytics & Classic)
            // ==========================================
            'seo_classic' => [
                'key' => 'seo_classic',
                'title' => 'آنالیز و سئو کلاسیک (SEO Panel)',
                'category' => 'analytics_classic',
                'category_title' => '📈 تحلیل و هوش تجاری کلاسیک',
                'icon' => '📊',
                'desc' => 'داشبورد کلاسیک تحلیل عبارات کلیدی، متاتگ‌ها و وضعیت سئوی صفحات',
                'url' => 'seo.php',
                'type' => 'standalone'
            ],
            'sale_analysis' => [
                'key' => 'sale_analysis',
                'title' => 'تحلیل مبالغ و شعب (Sales BI)',
                'category' => 'analytics_classic',
                'category_title' => '📈 تحلیل و هوش تجاری کلاسیک',
                'icon' => '💰',
                'desc' => 'گزارش‌گیری مالی، تحلیل شعب فیزیکی و کانال‌های فروشگاهی برند',
                'url' => 'sale.php',
                'type' => 'standalone'
            ],

            // ==========================================
            // دسته ۶: سئو و آنالیتیکس سازمانی (Composio Powered)
            // ==========================================
            'seo_intel' => [
                'key' => 'seo_intel',
                'title' => 'کل ماژول سئو سازمانی Composio',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '⚡',
                'desc' => 'دسترسی کلان به تمام بخش‌های پلتفرم هوش سئو و انبار داده تحلیلی',
                'url' => '/admin/business/{slug}/seo',
                'type' => 'module'
            ],
            'seo_intel_keywords' => [
                'key' => 'seo_intel_keywords',
                'title' => 'هوش کلمات کلیدی (Keywords)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '🎯',
                'desc' => 'رصد لایو رتبه کلمات، عبارات فرصت‌ساز، نرخ کلیک و تحلیل تغییرات جایگاه',
                'url' => '/admin/business/{slug}/seo/keywords',
                'type' => 'submodule'
            ],
            'seo_intel_pages' => [
                'key' => 'seo_intel_pages',
                'title' => 'تحلیل صفحات و محتوا (Pages)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '📑',
                'desc' => 'آنالیز صفحات با بیشترین ورودی، نرخ پرش و سرعت افت یا رشد لندینگ‌ها',
                'url' => '/admin/business/{slug}/seo/pages',
                'type' => 'submodule'
            ],
            'seo_intel_technical' => [
                'key' => 'seo_intel_technical',
                'title' => 'سئو تکنیکال (Technical Audits)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '⚙️',
                'desc' => 'پایش سلامت کدهای وضعیت HTTP، ساختار اسکیما، تگ‌های کنونیکال و Core Web Vitals',
                'url' => '/admin/business/{slug}/seo/technical',
                'type' => 'submodule'
            ],
            'seo_intel_traffic' => [
                'key' => 'seo_intel_traffic',
                'title' => 'ترافیک و آنالیتیکس (GA4)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '📈',
                'desc' => 'تحلیل رفتار مخاطبان، سشن‌ها، منابع ورودی و ترند ترافیکی گوگل آنالیتیکس ۴',
                'url' => '/admin/business/{slug}/seo/traffic',
                'type' => 'submodule'
            ],
            'seo_intel_content' => [
                'key' => 'seo_intel_content',
                'title' => 'محتوا و درآمدزایی (Content & Revenue)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '📝',
                'desc' => 'محاسبه ارزش ریالی ترافیک ورودی مقالات و نرخ تبدیل ترافیک به فروش',
                'url' => '/admin/business/{slug}/seo/content',
                'type' => 'submodule'
            ],
            'seo_intel_social' => [
                'key' => 'seo_intel_social',
                'title' => 'شبکه‌های اجتماعی (Social Reach)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '📸',
                'desc' => 'تحلیل ایمپرشن‌ها، بازخورد مخاطبان در اینستاگرام و سیگنال‌های اجتماعی برند',
                'url' => '/admin/business/{slug}/seo/social',
                'type' => 'submodule'
            ],
            'seo_intel_recommendations' => [
                'key' => 'seo_intel_recommendations',
                'title' => 'پیشنهادات هوش مصنوعی سئو (AI Recs)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '🧠',
                'desc' => 'پیشنهادات راهبردی برگرفته از داده‌های واقعی سرچ کنسول و الگوریتم‌های گوگل',
                'url' => '/admin/business/{slug}/seo/recommendations',
                'type' => 'submodule'
            ],
            'seo_intel_integrations' => [
                'key' => 'seo_intel_integrations',
                'title' => 'یکپارچه‌سازی و کانکشن‌های Composio',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '🔗',
                'desc' => 'تنظیم کلید Composio و اتصال اکانت‌های Search Console, GA4, WooCommerce',
                'url' => '/admin/business/{slug}/seo/integrations',
                'type' => 'submodule'
            ],
            'seo_intel_reports' => [
                'key' => 'seo_intel_reports',
                'title' => 'گزارش جامع بیزنس (Executive Reports)',
                'category' => 'seo_intel',
                'category_title' => '🚀 سئو و آنالیتیکس سازمانی (Composio Powered)',
                'icon' => '📊',
                'desc' => 'تولید خلاصه مدیریتی با قابلیت چاپ و ارائه آمارهای پیشرفت سئو به کارفرما',
                'url' => '/admin/business/{slug}/seo/reports',
                'type' => 'submodule'
            ],

            // ==========================================
            // دسته ۷: هوش چیدمان فروشگاه (Visual Merchandising)
            // ==========================================
            'vm_studio' => [
                'key' => 'vm_studio',
                'title' => 'کل ماژول چیدمان و استودیو VM',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '🛍️',
                'desc' => 'دسترسی کلان به ابزار مرچندایزینگ بصری و استودیو دوبعدی چیدمان شعب',
                'url' => '/admin/projects/{slug}/vm',
                'type' => 'module'
            ],
            'vm_stores' => [
                'key' => 'vm_stores',
                'title' => 'مدیریت شعب و زون‌های فروشگاه',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '🏪',
                'desc' => 'تعریف شعب فیزیکی، متراژ، مناطق پرتردد و بخش‌های ورودی فروشگاه',
                'url' => '/admin/projects/{slug}/vm/stores',
                'type' => 'submodule'
            ],
            'vm_layout' => [
                'key' => 'vm_layout',
                'title' => 'استودیو ترسیم چیدمان بصری (2D Studio)',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '📐',
                'desc' => 'کنواس تعاملی ترسیم رگال‌ها، قفسه‌ها و جانمایی لباس‌ها و کالاهای تجاری',
                'url' => '/admin/projects/{slug}/vm/studio',
                'type' => 'submodule'
            ],
            'vm_analytics' => [
                'key' => 'vm_analytics',
                'title' => 'آنالیز چیدمان و نقشه حرارتی (Heatmaps)',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '📊',
                'desc' => 'تحلیل بازدهی هر رگال و قفسه و نمایش بصری نقاط سرد و گرم فروشگاهی',
                'url' => '/admin/projects/{slug}/vm/analytics',
                'type' => 'submodule'
            ],
            'vm_scores' => [
                'key' => 'vm_scores',
                'title' => 'امتیاز چیدمان شعب (Store Scores)',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '🏆',
                'desc' => 'چک‌لیست استانداردسازی و امتیازدهی به کیفیت مرچندایزینگ در شعب مختلف',
                'url' => '/admin/projects/{slug}/vm/scores',
                'type' => 'submodule'
            ],
            'vm_ai' => [
                'key' => 'vm_ai',
                'title' => 'دستیار هوش مصنوعی VM',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '🧠',
                'desc' => 'پیشنهادات هوشمندانه جابجایی کالاها برای افزایش فروش متقابل (Cross-Selling)',
                'url' => '/admin/projects/{slug}/vm/recommendations',
                'type' => 'submodule'
            ],
            'vm_reports' => [
                'key' => 'vm_reports',
                'title' => 'گزارشات جامع چیدمان',
                'category' => 'vm',
                'category_title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)',
                'icon' => '📑',
                'desc' => 'گزارش تحلیلی کامل بهره‌وری فضاها و مقایسه چیدمان دوره‌های گذشته',
                'url' => '/admin/projects/{slug}/vm/reports',
                'type' => 'submodule'
            ],

            // ==========================================
            // دسته ۸: مدیریت، پرسنل و دسترسی‌ها (Settings & Users)
            // ==========================================
            'users' => [
                'key' => 'users',
                'title' => 'مدیریت کاربران و دسترسی‌ها',
                'category' => 'settings_users',
                'category_title' => '⚙️ مدیریت و دسترسی‌ها',
                'icon' => '🛡️',
                'desc' => 'تعریف اعضای تیم، تعیین نقش‌ها (ویرایشگر، ناظر، مدیر پروژه) و مجوزهای هر نقش',
                'url' => 'admin.php?tab=users',
                'type' => 'tab'
            ],
            'users_performance' => [
                'key' => 'users_performance',
                'title' => 'عملکرد و کارایی پرسنل',
                'category' => 'settings_users',
                'category_title' => '⚙️ مدیریت و دسترسی‌ها',
                'icon' => '👥',
                'desc' => 'ارزیابی نرخ بهره‌وری پرسنل، تعداد تسک‌های انجام‌شده و سرعت کاربری',
                'url' => 'admin.php?tab=users_performance',
                'type' => 'tab'
            ],
            'logs' => [
                'key' => 'logs',
                'title' => 'لاگ‌ها و حسابرسی سیستم',
                'category' => 'settings_users',
                'category_title' => '⚙️ مدیریت و دسترسی‌ها',
                'icon' => '📜',
                'desc' => 'ردیابی کامل اقدامات کاربران، اضافه و حذف محصولات و رویدادهای سیستمی',
                'url' => 'admin.php?tab=logs',
                'type' => 'tab'
            ],
            'settings' => [
                'key' => 'settings',
                'title' => 'پیکربندی و تنظیمات پروژه',
                'category' => 'settings_users',
                'category_title' => '⚙️ مدیریت و دسترسی‌ها',
                'icon' => '⚙️',
                'desc' => 'تنظیمات اختصاصی پروژه، شبکه‌های اجتماعی، اتصال وب‌سایت و پیکربندی عمومی',
                'url' => 'admin.php?tab=settings',
                'type' => 'tab'
            ],
        ];
    }

    /**
     * Load current configuration from storage
     */
    public static function getConfig() {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $default = [];
        $catalog = self::getCatalog();
        foreach ($catalog as $k => $item) {
            $default[$k] = self::STATUS_ACTIVE;
        }

        $file = FEATURE_FLAGS_FILE;
        if (!file_exists($file)) {
            self::$cache = $default;
            return $default;
        }

        $content = @file_get_contents($file);
        $data = @json_decode($content, true);

        // Broken configuration must not reopen sections reserved for administrators.
        // Maintenance keeps administrators able to inspect and repair the settings.
        if (!is_array($data)) {
            self::$cache = array_fill_keys(array_keys($catalog), self::STATUS_MAINTENANCE);
            return self::$cache;
        }

        $merged = $default;
        foreach ($data as $key => $status) {
            $key = AccessControl::featureKey($key);
            if (!isset($catalog[$key])) continue;
            if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_MAINTENANCE, self::STATUS_DISABLED], true)) $status = self::STATUS_MAINTENANCE;
            // If a legacy and canonical name coexist, retain the stricter setting.
            $rank = [self::STATUS_ACTIVE => 0, self::STATUS_MAINTENANCE => 1, self::STATUS_DISABLED => 2];
            if ($rank[$status] > $rank[$merged[$key]]) $merged[$key] = $status;
        }
        self::$cache = $merged;
        return $merged;
    }

    /**
     * Save configuration to JSON storage
     */
    public static function saveConfig($flags) {
        if (!self::isMainAdmin() || !is_array($flags)) return false;
        $catalog = self::getCatalog();
        $clean = [];

        foreach ($catalog as $k => $item) {
            $val = $flags[$k] ?? self::STATUS_ACTIVE;
            if (!in_array($val, [self::STATUS_ACTIVE, self::STATUS_MAINTENANCE, self::STATUS_DISABLED], true)) {
                return false;
            }
            $clean[$k] = $val;
        }

        if (!AccessControl::writeJson(FEATURE_FLAGS_FILE, $clean)) return false;
        self::$cache = $clean;
        return true;
    }

    /**
     * Get status for a specific feature key
     * Returns: 'active' | 'maintenance' | 'disabled'
     */
    public static function getStatus($featureKey) {
        $featureKey = AccessControl::featureKey($featureKey);
        $config = self::getConfig();
        return $config[$featureKey] ?? self::STATUS_ACTIVE;
    }

    /**
     * Main access control gate:
     * - 'active': Available for everyone (regular users/roles who have normal permissions).
     * - 'maintenance': Visible and accessible ONLY to main admin ('admin'). Hidden from all other users.
     * - 'disabled': Hidden from EVERYONE including the main admin.
     *
     * @param string $featureKey The feature identifier
     * @return bool True if the feature should be shown and accessible to the current user
     */
    public static function isAvailable($featureKey) {
        $featureKey = AccessControl::featureKey($featureKey);
        if (self::isUnrestrictedAdmin()) return true;
        if ($featureKey === 'settings' && self::isMainAdmin()) return true;
        $parent = AccessControl::parentFeature($featureKey);
        if ($parent !== null && !self::isAvailable($parent)) return false;
        $status = self::getStatus($featureKey);

        if ($status === self::STATUS_DISABLED) {
            // Completely disabled: hidden from everyone, even main admin
            return false;
        }

        if ($status === self::STATUS_MAINTENANCE) {
            // Maintenance: ONLY visible and accessible to main admin
            return self::isMainAdmin();
        }

        return AccessControl::allowed($featureKey);
    }

    /**
     * Check if a feature is enabled at all (active or maintenance)
     */
    public static function isEnabledAtAll($featureKey) {
        return self::getStatus($featureKey) !== self::STATUS_DISABLED;
    }

    /**
     * Returns a badge HTML to visually signify maintenance mode to the main admin
     */
    public static function getBadgeHtml($featureKey) {
        if (!self::isMainAdmin()) {
            return '';
        }
        $st = self::getStatus($featureKey);
        if ($st === self::STATUS_MAINTENANCE) {
            return '<span class="mr-auto px-1.5 py-0.5 text-[8px] bg-amber-500/20 text-amber-700 border border-amber-300/40 rounded font-bold" title="فقط برای ادمین اصلی قابل رویت است">تعمیر</span>';
        }
        return '';
    }

    /**
     * Returns human-readable label, badge color classes, and description for a status
     */
    public static function getStatusMeta($status) {
        switch ($status) {
            case self::STATUS_ACTIVE:
                return [
                    'label' => 'فعال (عمومی)',
                    'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'icon' => '🟢',
                    'desc' => 'فعال برای تمام کاربران دارای دسترسی'
                ];
            case self::STATUS_MAINTENANCE:
                return [
                    'label' => 'فقط ادمین اصلی',
                    'badge' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'icon' => '🟡',
                    'desc' => 'مخفی از تمام کاربران؛ فقط ادمین اصلی دسترسی دارد'
                ];
            case self::STATUS_DISABLED:
                return [
                    'label' => 'غیرفعال کامل (مخفی از همه)',
                    'badge' => 'bg-rose-50 text-rose-700 border-rose-200',
                    'icon' => '🔴',
                    'desc' => 'کاملاً غیرفعال و مخفی از منوهای تمام افراد حتی ادمین'
                ];
            default:
                return [
                    'label' => 'نامشخص',
                    'badge' => 'bg-slate-100 text-slate-600 border-slate-200',
                    'icon' => '⚪',
                    'desc' => '-'
                ];
        }
    }
}
