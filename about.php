<?php
require_once 'inc/functions.php';
$settings = getSiteSettings();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>درباره ما | <?php echo htmlspecialchars($settings['site_title']); ?></title>
    <link rel="stylesheet" href="<?php echo getFullUrl('assets/style.css'); ?>">
    <script src="<?php echo getFullUrl('assets/tailwindcss.js'); ?>"></script>
    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Regular.woff2'); ?>') format('woff2');
            font-weight: normal;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: url('<?php echo getFullUrl('assets/fonts/Vazirmatn-Bold.woff2'); ?>') format('woff2');
            font-weight: bold;
        }
        body { font-family: 'Vazirmatn', sans-serif; background-color: #0f172a; color: #f8fafc; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Navigation Header -->
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-600 text-white rounded-2xl flex items-center justify-center font-black text-xl shadow-lg shadow-indigo-500/30">
                    <?php echo htmlspecialchars($settings['logo_icon']); ?>
                </div>
                <span class="font-black text-xl text-white tracking-tight"><?php echo htmlspecialchars($settings['logo_text']); ?></span>
            </a>
            <div class="hidden md:flex items-center gap-8 text-sm font-bold text-slate-300">
                <a href="index.php#features" class="hover:text-indigo-400 transition-all">امکانات و ابزارها</a>
                <a href="index.php#pricing" class="hover:text-indigo-400 transition-all">تعرفه‌ها</a>
                <a href="about.php" class="text-indigo-400">درباره ما</a>
                <a href="contact.php" class="hover:text-indigo-400 transition-all">تماس با ما</a>
            </div>
            <div class="flex items-center gap-3">
                <?php if (isSuperAdmin()): ?>
                    <a href="panel/admin.php" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md">ورود به مدیریت کلان 👑</a>
                <?php elseif (isUser()): ?>
                    <a href="panel/portal.php" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md">ورود به پنل کاربری 👤</a>
                <?php else: ?>
                    <a href="index.php?action=login" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-md">ورود به پنل 🔑</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Hero -->
    <main class="flex-1 max-w-5xl mx-auto px-6 py-16">
        <div class="text-center mb-16">
            <span class="px-4 py-1.5 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-full text-xs font-black inline-block mb-4">
                داستان و چشم‌انداز مارکشو
            </span>
            <h1 class="text-3xl md:text-5xl font-black text-white mb-6 leading-tight">
                <?php echo htmlspecialchars($settings['about_title']); ?>
            </h1>
            <p class="text-slate-400 text-base max-w-2xl mx-auto leading-relaxed">
                ایجاد بستری یکپارچه برای رشد هوشمند کسب‌وکارهای آنلاین ایرانی با تلفیق فناوری هوش مصنوعی و اتوماسیون تجاری.
            </p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 md:p-12 mb-12 shadow-2xl relative overflow-hidden">
            <div class="absolute -top-24 -left-24 w-72 h-72 bg-indigo-500/10 rounded-full blur-3xl"></div>
            <h2 class="text-xl font-black text-white mb-6 border-r-4 border-indigo-500 pr-4">ماموریت ما</h2>
            <p class="text-slate-300 leading-loose text-justify text-sm mb-8 font-medium">
                <?php echo nl2br(htmlspecialchars($settings['about_text'])); ?>
            </p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-slate-800">
                <div class="bg-slate-800/50 p-6 rounded-2xl border border-slate-700/50 text-center">
                    <div class="text-3xl font-black text-indigo-400 mb-2">۱,۲۰۰+</div>
                    <div class="text-xs text-slate-400 font-bold">کسب‌وکار و فروشگاه فعال</div>
                </div>
                <div class="bg-slate-800/50 p-6 rounded-2xl border border-slate-700/50 text-center">
                    <div class="text-3xl font-black text-emerald-400 mb-2">۹۹.۹٪</div>
                    <div class="text-xs text-slate-400 font-bold">پایداری و دسترسی شبکه</div>
                </div>
                <div class="bg-slate-800/50 p-6 rounded-2xl border border-slate-700/50 text-center">
                    <div class="text-3xl font-black text-amber-400 mb-2">۲۴/۷</div>
                    <div class="text-xs text-slate-400 font-bold">پشتیبانی فنی اختصاصی</div>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-r from-indigo-900/40 to-slate-900 border border-indigo-500/20 p-8 rounded-3xl flex flex-col md:flex-row items-center justify-between gap-6">
            <div>
                <h3 class="text-lg font-black text-white mb-2">آماده ارتقای کسب‌وکار خود هستید؟</h3>
                <p class="text-xs text-slate-400 font-medium">همین امروز با ثبت سفارش، به تمامی ابزارهای مارکشو فلو دسترسی پیدا کنید.</p>
            </div>
            <a href="index.php#pricing" class="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-2xl transition-all shadow-lg whitespace-nowrap">
                مشاهده و خرید آنلاین اشتراک 🚀
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-800 bg-slate-950 py-8 text-center text-slate-500 text-xs">
        <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row items-center justify-between gap-4">
            <div>تمامی حقوق مادی و معنوی برای پلتفرم <?php echo htmlspecialchars($settings['site_title']); ?> محفوظ است.</div>
            <div class="flex items-center gap-6">
                <a href="index.php" class="hover:text-white">صفحه اصلی</a>
                <a href="about.php" class="hover:text-white">درباره ما</a>
                <a href="contact.php" class="hover:text-white">تماس با ما</a>
            </div>
        </div>
    </footer>
</body>
</html>
