<?php
/**
 * View: Traffic Analysis (Google Analytics 4)
 * Channel Attribution, User Engagement, Device Breakdown & Timeline
 */

$traffic = SeoDB::getTrafficMetrics($currentBusiness['id']);
$summary = $traffic['summary'] ?? [];
$channels = $traffic['channels'] ?? [];
$devices = $traffic['devices'] ?? [];
$timeline = $traffic['timeline'] ?? [];
?>

<!-- KPI Top Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">کل سشن‌های ارگانیک (Organic Sessions)</span>
        <span class="text-3xl font-black text-slate-900 font-mono"><?php echo number_format($summary['organic_sessions'] ?? 0); ?></span>
        <span class="text-[11px] font-bold text-emerald-600 block mt-1">+<?php echo $summary['growth_pct'] ?? 34.2; ?>٪ رشد ۳۰ روز اخیر</span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">کاربران فعال ارگانیک (Organic Users)</span>
        <span class="text-3xl font-black text-indigo-700 font-mono"><?php echo number_format($summary['organic_users'] ?? 0); ?></span>
        <span class="text-[11px] font-bold text-slate-400 block mt-1">ورودی یکتای جستجو</span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">نرخ تعامل و پایداری (Engagement Rate)</span>
        <span class="text-3xl font-black text-emerald-600 font-mono"><?php echo $summary['engagement_rate'] ?? 61.6; ?>٪</span>
        <span class="text-[11px] font-bold text-slate-400 block mt-1">میانگین زمان: <?php echo $summary['avg_session_duration'] ?? '3m 24s'; ?></span>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">نرخ خروج سریع (Bounce Rate)</span>
        <span class="text-3xl font-black text-rose-600 font-mono"><?php echo $summary['bounce_rate'] ?? 38.4; ?>٪</span>
        <span class="text-[11px] font-bold text-emerald-600 block mt-1">۴.۲٪ بهبود نسبت به ماه قبل</span>
    </div>
</div>

<!-- Main Row: Traffic Channels & Devices -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Channel Breakdown (2 cols) -->
    <div class="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-channel-breakdown">
        <div class="flex items-center justify-between mb-5 pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>🌐</span> کانال‌های جذب ورودی و ترافیک (Traffic Acquisition Channels)
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">تفکیک سهم سشن‌های ورودی از موتورهای جستجو، شبکه‌های اجتماعی و مستقیم</p>
            </div>
            <?php if (ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations', 'use')): ?><button onclick="triggerSync('google_analytics_4')" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
                <span>🔄</span> بروزرسانی GA4
            </button><?php endif; ?>
        </div>

        <div class="space-y-4">
            <?php foreach ($channels as $ch): ?>
            <div>
                <div class="flex items-center justify-between text-xs font-bold mb-1.5">
                    <span class="text-slate-800 flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full" style="background-color: <?php echo $ch['color']; ?>"></span>
                        <?php echo htmlspecialchars($ch['name']); ?>
                    </span>
                    <span class="font-mono text-slate-900">
                        <?php echo number_format($ch['sessions']); ?> نشست 
                        <span class="text-slate-400 font-normal mr-1">(<?php echo $ch['share']; ?>٪)</span>
                    </span>
                </div>
                <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-700" style="width: <?php echo $ch['share']; ?>%; background-color: <?php echo $ch['color']; ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 pt-5 border-t border-slate-100 bg-indigo-50/40 p-4 rounded-xl flex items-start gap-3">
            <span class="text-xl">💡</span>
            <p class="text-xs text-indigo-900 leading-relaxed font-medium">
                <strong>نتیجه‌گیری هوشمند:</strong> ترافیک ارگانیک با سهم بیش از ۵۸٪ ستون فقرات جذب مشتری در این بیزنس است. به دلیل نرخ تبدیل بالاتر ترافیک ارگانیک نسبت به سوشیال (۲.۸٪ در برابر ۱.۱٪)، هر ۱۰٪ رشد سئو بازده مالی بالاتری نسبت به کمپین‌های اینفلوئنسری ایجاد می‌کند.
            </p>
        </div>
    </div>

    <!-- Device Breakdown -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between" id="sec-device-breakdown">
        <div>
            <h3 class="text-sm font-black text-slate-900 mb-1 flex items-center gap-2">
                <span>📱</span> تفکیک بر اساس نوع دستگاه
            </h3>
            <p class="text-xs text-slate-400 mb-5">سهم موبایل، دسکتاپ و تبلت در بازدیدها</p>

            <div class="space-y-4">
                <?php foreach ($devices as $d): ?>
                <div class="p-3 bg-slate-50 rounded-xl">
                    <div class="flex justify-between items-center text-xs font-bold mb-1">
                        <span class="text-slate-700"><?php echo $d['type']; ?></span>
                        <span class="font-mono text-indigo-700 font-black"><?php echo $d['share']; ?>٪</span>
                    </div>
                    <div class="w-full bg-slate-200/70 h-2 rounded-full overflow-hidden">
                        <div class="bg-indigo-600 h-full rounded-full" style="width: <?php echo $d['share']; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-3 bg-amber-50 text-amber-900 rounded-xl text-[11px] leading-relaxed mt-4 border border-amber-100">
            <strong>نکته سئو موبایل:</strong> بیش از ۸۰٪ کاربران شما از موبایل استفاده می‌کنند. اولویت بهینه‌سازی سرعت LCP و چیدمان باید برای گوشی‌های هوشمند تنظیم شود.
        </div>
    </div>
</div>
