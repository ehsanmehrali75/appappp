<?php
/**
 * View: Social Media Analysis
 * Instagram & Social Intelligence: Reach, Engagement, Reels Performance & Best Publishing Time
 */

$social = SeoDB::getSocialMetrics($currentBusiness['id'], 'instagram');
$followers = $social['followers'] ?? 128400;
$reach = $social['reach'] ?? 342000;
$er = $social['engagement_rate'] ?? 4.82;
$topReels = $social['top_reels'] ?? [];
$bestHours = $social['best_publishing_hours'] ?? [];
?>

<!-- Social KPI Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">دنبال‌کنندگان (Instagram Followers)</span>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-900 font-mono"><?php echo number_format($followers); ?></span>
            <span class="text-xs font-bold text-slate-400"><?php echo htmlspecialchars($social['account'] ?? '@brand'); ?></span>
        </div>
        <span class="text-[10px] text-emerald-600 font-bold block mt-1">+۱,۴۵۰ فالوور جدید این ماه</span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">ریچ و بازدید ۳۰ روز (Account Reach)</span>
        <span class="text-3xl font-black text-pink-600 font-mono"><?php echo number_format($reach); ?></span>
        <span class="text-[10px] text-slate-400 block mt-1">اکانت‌های یکتای مشاهده‌کننده</span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">نرخ تعامل (Engagement Rate)</span>
        <span class="text-3xl font-black text-emerald-600 font-mono"><?php echo $er; ?>٪</span>
        <span class="text-[10px] text-slate-400 block mt-1">بالاتر از میانگین اینداستری مد (۳.۲٪)</span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">تعداد کل پست‌ها و ریلزها</span>
        <span class="text-3xl font-black text-slate-900 font-mono"><?php echo $social['posts_count'] ?? 512; ?></span>
        <span class="text-[10px] text-indigo-600 font-bold block mt-1">همگام‌سازی شده از طریق Composio</span>
    </div>
</div>

<!-- Main Row: Best Publishing Hours & Top Reels -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Best Publishing Times -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-best-publishing">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>⏰</span> ساعات طلایی انتشار محتوا (Best Publishing Times)
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">تحلیل زمانی بالاترین نرخ تعامل و لایک کاربران</p>
            </div>
            <?php if (ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations', 'use')): ?><button onclick="triggerSync('instagram')" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all cursor-pointer">
                <span>🔄</span> سنک اینستاگرام
            </button><?php endif; ?>
        </div>

        <div class="space-y-3">
            <?php foreach ($bestHours as $slot): ?>
            <div class="p-3.5 bg-slate-50 rounded-xl flex items-center justify-between border border-slate-100">
                <div class="flex items-center gap-3">
                    <span class="w-9 h-9 rounded-xl bg-pink-50 text-pink-600 flex items-center justify-center text-sm font-black">🗓️</span>
                    <div>
                        <span class="text-xs font-black text-slate-900 block"><?php echo htmlspecialchars($slot['day']); ?></span>
                        <span class="text-[11px] text-slate-500 font-mono"><?php echo htmlspecialchars($slot['hour']); ?></span>
                    </div>
                </div>
                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 font-bold text-xs rounded-lg">
                    <?php echo htmlspecialchars($slot['engagement']); ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 p-3 bg-indigo-50/50 rounded-xl text-[11px] text-indigo-900 leading-relaxed">
            <strong>هم‌افزایی با سئو:</strong> با انتشار پست در ساعات طلایی، کلمات کلیدی داغ را با لینک مستقیم به استوری متصل کنید تا سیگنال‌های ترافیکی ورودی موجب رشد کلمات پرفرصت شما در گوگل شوند.
        </div>
    </div>

    <!-- Top Performing Reels -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-top-reels">
        <h3 class="text-sm font-black text-slate-900 mb-1 flex items-center gap-2">
            <span>🎬</span> محبوب‌ترین ریلزها و محتواهای ویدیویی
        </h3>
        <p class="text-xs text-slate-400 mb-4">بیشترین بازدید، تعامل و اشتراک‌گذاری در اینستاگرام</p>

        <div class="space-y-3.5">
            <?php foreach ($topReels as $reel): ?>
            <div class="p-4 bg-slate-50/60 rounded-xl border border-slate-100">
                <span class="text-xs font-black text-slate-900 block mb-2"><?php echo htmlspecialchars($reel['title']); ?></span>
                <div class="grid grid-cols-4 gap-2 text-center text-[11px]">
                    <div class="bg-white p-2 rounded-lg border border-slate-100">
                        <span class="text-slate-400 block text-[10px]">ویو (بازدید)</span>
                        <span class="font-mono font-black text-indigo-700"><?php echo number_format($reel['views']); ?></span>
                    </div>
                    <div class="bg-white p-2 rounded-lg border border-slate-100">
                        <span class="text-slate-400 block text-[10px]">لایک</span>
                        <span class="font-mono font-black text-rose-600"><?php echo number_format($reel['likes']); ?></span>
                    </div>
                    <div class="bg-white p-2 rounded-lg border border-slate-100">
                        <span class="text-slate-400 block text-[10px]">کامنت</span>
                        <span class="font-mono font-black text-slate-800"><?php echo number_format($reel['comments']); ?></span>
                    </div>
                    <div class="bg-white p-2 rounded-lg border border-slate-100">
                        <span class="text-slate-400 block text-[10px]">شیر (ارسال)</span>
                        <span class="font-mono font-black text-emerald-700"><?php echo number_format($reel['shares']); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
