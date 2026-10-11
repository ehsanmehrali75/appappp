<?php
require_once 'inc/functions.php';
$settings = getSiteSettings();

$sent_msg = '';
$err_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $name = trim($_POST['full_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($mobile) || empty($message)) {
        $err_msg = 'لطفا تمامی فیلدهای ضروری (نام، شماره تماس و متن پیام) را تکمیل نمایید.';
    } else {
        $db = getJsonDB();
        if (!isset($db['contact_messages']) || !is_array($db['contact_messages'])) {
            $db['contact_messages'] = [];
        }
        $db['contact_messages'][] = [
            'id' => uniqid(),
            'name' => $name,
            'mobile' => $mobile,
            'subject' => $subject,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        saveJsonDB($db);
        $sent_msg = 'پیام شما با موفقیت دریافت شد. کارشناسان ما در اسرع وقت با شما تماس خواهند گرفت.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تماس با ما | <?php echo htmlspecialchars($settings['site_title']); ?></title>
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
    <!-- Header -->
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
                <a href="about.php" class="hover:text-indigo-400 transition-all">درباره ما</a>
                <a href="contact.php" class="text-indigo-400">تماس با ما</a>
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

    <main class="flex-1 max-w-6xl mx-auto px-6 py-16 w-full">
        <div class="text-center mb-12">
            <span class="px-4 py-1.5 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-full text-xs font-black inline-block mb-4">
                ارتباط با تیم پشتیبانی و فروش
            </span>
            <h1 class="text-3xl md:text-5xl font-black text-white mb-4">تماس با ما</h1>
            <p class="text-slate-400 text-sm max-w-xl mx-auto">
                سوالی دارید یا نیازمند مشاوره قبل از خرید هستید؟ پاسخگوی شما هستیم.
            </p>
        </div>

        <?php if ($sent_msg): ?>
            <div class="mb-8 p-6 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-2xl text-sm font-bold flex items-center gap-3">
                <span class="text-2xl">✅</span>
                <span><?php echo $sent_msg; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($err_msg): ?>
            <div class="mb-8 p-6 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-2xl text-sm font-bold flex items-center gap-3">
                <span class="text-2xl">⚠️</span>
                <span><?php echo $err_msg; ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Contact Info -->
            <div class="space-y-6">
                <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl flex items-start gap-4">
                    <div class="w-12 h-12 bg-indigo-600/20 border border-indigo-500/30 rounded-xl flex items-center justify-center text-indigo-400 font-bold text-xl flex-shrink-0">📞</div>
                    <div>
                        <div class="text-xs text-slate-400 font-bold mb-1">تلفن پشتیبانی</div>
                        <div class="text-sm font-black text-white" dir="ltr"><?php echo htmlspecialchars($settings['phone']); ?></div>
                        <div class="text-xs text-slate-500 mt-1">شنبه تا پنجشنبه ۸ الی ۲۰</div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl flex items-start gap-4">
                    <div class="w-12 h-12 bg-emerald-600/20 border border-emerald-500/30 rounded-xl flex items-center justify-center text-emerald-400 font-bold text-xl flex-shrink-0">📱</div>
                    <div>
                        <div class="text-xs text-slate-400 font-bold mb-1">موبایل و واتس‌اپ</div>
                        <div class="text-sm font-black text-white" dir="ltr"><?php echo htmlspecialchars($settings['mobile']); ?></div>
                        <div class="text-xs text-slate-500 mt-1">پاسخگویی سریع پیامکی</div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl flex items-start gap-4">
                    <div class="w-12 h-12 bg-sky-600/20 border border-sky-500/30 rounded-xl flex items-center justify-center text-sky-400 font-bold text-xl flex-shrink-0">✉️</div>
                    <div>
                        <div class="text-xs text-slate-400 font-bold mb-1">پست الکترونیک</div>
                        <div class="text-sm font-black text-white" dir="ltr"><?php echo htmlspecialchars($settings['email']); ?></div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl flex items-start gap-4">
                    <div class="w-12 h-12 bg-amber-600/20 border border-amber-500/30 rounded-xl flex items-center justify-center text-amber-400 font-bold text-xl flex-shrink-0">📍</div>
                    <div>
                        <div class="text-xs text-slate-400 font-bold mb-1">آدرس دفتر مرکزی</div>
                        <div class="text-xs font-bold text-slate-300 leading-relaxed"><?php echo htmlspecialchars($settings['address']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div class="lg:col-span-2 bg-slate-900 border border-slate-800 p-8 rounded-3xl shadow-xl">
                <h3 class="text-lg font-black text-white mb-6 border-r-4 border-indigo-500 pr-3">فرم ارسال پیام</h3>
                <form method="POST" class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-2">نام و نام خانوادگی <span class="text-rose-500">*</span></label>
                            <input type="text" name="full_name" required placeholder="مثال: علی محمدی" class="w-full bg-slate-800/80 border border-slate-700/80 rounded-xl px-4 py-3 text-white text-xs font-bold outline-none focus:border-indigo-500 transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-2">شماره همراه <span class="text-rose-500">*</span></label>
                            <input type="text" name="mobile" required placeholder="مثال: 09121112233" dir="ltr" class="w-full bg-slate-800/80 border border-slate-700/80 rounded-xl px-4 py-3 text-white text-xs font-bold outline-none focus:border-indigo-500 transition-all text-right">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-2">موضوع پیام</label>
                        <input type="text" name="subject" placeholder="مثال: درخواست مشاوره قبل از خرید" class="w-full bg-slate-800/80 border border-slate-700/80 rounded-xl px-4 py-3 text-white text-xs font-bold outline-none focus:border-indigo-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-2">متن پیام <span class="text-rose-500">*</span></label>
                        <textarea name="message" rows="5" required placeholder="پیام خود را بنویسید..." class="w-full bg-slate-800/80 border border-slate-700/80 rounded-xl px-4 py-3 text-white text-xs font-bold outline-none focus:border-indigo-500 transition-all leading-relaxed"></textarea>
                    </div>
                    <button type="submit" name="submit_contact" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-black rounded-xl transition-all shadow-lg">
                        ارسال پیام به پشتیبانی 🚀
                    </button>
                </form>
            </div>
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
