<?php
/**
 * View: Reports & Comparative Intelligence
 * Requirements 14 & 15:
 * - Reporting System: Daily, Weekly, and Monthly Executive Reports
 * - Comparative Analysis:
 *   1. Month-over-Month (ماه جاری با ماه قبل)
 *   2. Week-over-Week (هفته جاری با هفته قبل)
 *   3. Before/After SEO Changes (قبل و بعد از تغییرات سئو)
 */

$reportType = $_GET['report_type'] ?? 'monthly';
$comparisonType = $_GET['comparison_type'] ?? 'month_over_month';

$report = SeoDB::getExecutiveReport($currentBusiness['id'], $reportType);
$comparison = SeoDB::getComparisonMetrics($currentBusiness['id'], $comparisonType);
$stats = SeoDB::calculateScoreAndStats($currentBusiness['id']);
$topKws = SeoDB::getKeywords($currentBusiness['id'], 'top10');
$recs = SeoDB::getRecommendations($currentBusiness['id'], 'pending');
?>

<!-- Header & Export Actions -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4" id="card-report-header">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 rounded-md bg-indigo-50 text-indigo-700 text-xs font-black">Executive Reporting & Comparative Analytics</span>
            <span class="text-xs text-slate-400 font-mono"><?php echo date('Y/m/d'); ?></span>
        </div>
        <h2 class="text-lg font-black text-slate-900">سامانه گزارشات اجرایی و تحلیل مقایسه‌ای سئو</h2>
        <p class="text-xs text-slate-500 mt-1">
            کسب‌وکار: <strong class="text-slate-900"><?php echo htmlspecialchars($currentBusiness['name']); ?></strong> (دامنه: <code class="font-mono text-indigo-700"><?php echo htmlspecialchars($currentBusiness['domain']); ?></code>)
        </p>
    </div>

    <div class="flex items-center gap-2 no-print">
        <button onclick="window.print()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
            <span>🖨️</span> چاپ / ذخیره رسمی PDF
        </button>
        <button onclick="triggerSync('all')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
            <span>🔄</span> بروزرسانی همزمان داده‌ها
        </button>
    </div>
</div>

<!-- Section 1: Report Mode Switcher (Daily / Weekly / Monthly) - Requirement 14 -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-report-type">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 mb-5">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>📑</span> نوع گزارش دوره‌ای (Periodic Executive Report)
            </h3>
            <span class="text-xs text-slate-400">گزارشات اختصاصی تولید شده بر اساس شاخص‌های سرچ کنسول، GA4 و تبدیل‌های ووکامرس</span>
        </div>

        <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold no-print">
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=daily&comparison_type=<?php echo $comparisonType; ?>" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $reportType === 'daily' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               📅 گزارش روزانه (Daily)
            </a>
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=weekly&comparison_type=<?php echo $comparisonType; ?>" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $reportType === 'weekly' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               📊 گزارش هفتگی (Weekly)
            </a>
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=monthly&comparison_type=<?php echo $comparisonType; ?>" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $reportType === 'monthly' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               🏢 گزارش ماهانه هیئت مدیره (Monthly)
            </a>
        </div>
    </div>

    <!-- Active Report Details -->
    <div class="bg-slate-50/70 p-4 rounded-xl border border-slate-100 mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <div class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($report['title']); ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5">بازه زمانی: <strong class="text-slate-800"><?php echo htmlspecialchars($report['period']); ?></strong> | تاریخ تولید: <?php echo htmlspecialchars($report['generated_at']); ?></div>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-black font-mono">
                شاخص سلامت: <?php echo $stats['seo_health']; ?>/۱۰۰
            </span>
        </div>
    </div>

    <!-- Report Metric Highlights -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ($report['metrics'] as $lbl => $val): ?>
        <div class="bg-white p-4 rounded-xl border border-slate-200/60 shadow-xs">
            <span class="text-xs text-slate-500 font-bold block mb-1"><?php echo htmlspecialchars($lbl); ?></span>
            <span class="text-xl font-black text-slate-900 font-mono"><?php echo htmlspecialchars($val); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Section 2: Comparative Analysis Engine (Requirement 15) -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-comparative-engine">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 mb-5">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>⚖️</span> موتور تحلیل مقایسه‌ای دوره‌ای (Comparative Analysis Engine)
            </h3>
            <span class="text-xs text-slate-400">سنجش دقیق پیشرفت و اثربخشی استراتژی‌های سئو با مقایسه دوره‌ها</span>
        </div>

        <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold no-print">
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=<?php echo $reportType; ?>&comparison_type=month_over_month" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $comparisonType === 'month_over_month' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               ماه جاری با ماه قبل (MoM)
            </a>
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=<?php echo $reportType; ?>&comparison_type=week_over_week" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $comparisonType === 'week_over_week' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               هفته جاری با هفته قبل (WoW)
            </a>
            <a href="?view=reports&business=<?php echo urlencode($currentBusiness['slug']); ?>&report_type=<?php echo $reportType; ?>&comparison_type=before_after_seo" 
               class="px-3 py-1.5 rounded-lg transition-all <?php echo $comparisonType === 'before_after_seo' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
               قبل و بعد از تغییرات سئو
            </a>
        </div>
    </div>

    <!-- Active Comparison Header -->
    <div class="flex items-center justify-between p-3.5 bg-indigo-50/60 border border-indigo-100 rounded-xl mb-6">
        <div>
            <span class="text-xs font-black text-indigo-950 block"><?php echo htmlspecialchars($comparison['title']); ?></span>
            <span class="text-[11px] text-slate-500 font-mono">
                دوره پایه: <?php echo htmlspecialchars($comparison['previous_period_label']); ?> ➔ دوره هدف: <?php echo htmlspecialchars($comparison['current_period_label']); ?>
            </span>
        </div>
        <span class="text-xs font-bold text-indigo-700 bg-white px-3 py-1 rounded-lg border border-indigo-200">
            تحلیل دلتا فعال
        </span>
    </div>

    <!-- Side-by-Side Metric Comparison Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <?php foreach ($comparison['metrics'] as $key => $m): 
            $pct = floatval($m['change_pct']);
            $isPositive = $pct >= 0;
            // Note for avg position: decrease in position number is positive in SEO!
            if ($key === 'avg_position') {
                $isPositive = $pct <= 0;
            }
            $badgeClass = $isPositive ? 'text-emerald-700 bg-emerald-50' : 'text-rose-700 bg-rose-50';
        ?>
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($m['label']); ?></span>
                <span class="px-2 py-0.5 rounded-md font-mono text-xs font-black <?php echo $badgeClass; ?>">
                    <?php echo $m['change_pct']; ?>٪
                </span>
            </div>
            
            <div class="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-slate-100">
                <div>
                    <span class="text-[10px] text-slate-400 block">دوره قبل:</span>
                    <span class="font-mono font-bold text-slate-600">
                        <?php echo is_numeric($m['previous']) ? number_format($m['previous']) : $m['previous']; ?>
                    </span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 block">دوره جاری:</span>
                    <span class="font-mono font-black text-slate-900">
                        <?php echo is_numeric($m['current']) ? number_format($m['current']) : $m['current']; ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Top Winners & Losers Keywords During Period -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-2">
        <!-- Top Gaining Keywords -->
        <div class="p-4 bg-slate-50/60 rounded-xl border border-slate-100">
            <h4 class="text-xs font-black text-emerald-800 flex items-center gap-1.5 mb-3">
                <span>📈</span> کلمات با بیشترین رشد رتبه و کلیک در این دوره
            </h4>
            <div class="space-y-2">
                <?php foreach ($comparison['top_winners'] as $win): ?>
                <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-100 text-xs">
                    <div>
                        <span class="font-bold text-slate-900 block"><?php echo htmlspecialchars($win['keyword']); ?></span>
                        <span class="text-[10px] text-slate-400 font-mono">کلیک: <?php echo number_format($win['clicks']); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-slate-500 font-mono">از #<?php echo $win['from_pos']; ?> ➔ #<?php echo $win['to_pos']; ?></span>
                        <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-black font-mono text-[11px]"><?php echo $win['delta']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Top Falling Keywords -->
        <div class="p-4 bg-slate-50/60 rounded-xl border border-slate-100">
            <h4 class="text-xs font-black text-rose-800 flex items-center gap-1.5 mb-3">
                <span>📉</span> کلمات نیازمند توجه و بازبینی (افت دوره)
            </h4>
            <div class="space-y-2">
                <?php foreach ($comparison['top_losers'] as $lose): ?>
                <div class="flex items-center justify-between p-2.5 bg-white rounded-lg border border-slate-100 text-xs">
                    <div>
                        <span class="font-bold text-slate-900 block"><?php echo htmlspecialchars($lose['keyword']); ?></span>
                        <span class="text-[10px] text-slate-400 font-mono">کلیک: <?php echo number_format($lose['clicks']); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-slate-500 font-mono">از #<?php echo $lose['from_pos']; ?> ➔ #<?php echo $lose['to_pos']; ?></span>
                        <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 font-black font-mono text-[11px]"><?php echo $lose['delta']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Section 3: AI Executive Summary & Actionable Roadmap -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-executive-roadmap">
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
        <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
            <span>🧠</span> خلاصه مدیریتی هوش مصنوعی و برنامه اقدام دوره آینده (AI Strategic Roadmap)
        </h3>
        <span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-lg">Gemini Intelligence</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 text-xs leading-relaxed text-slate-700 space-y-2.5">
            <h4 class="font-black text-slate-900">جمع‌بندی عملکرد کسب‌وکار <?php echo htmlspecialchars($currentBusiness['name']); ?>:</h4>
            <p>
                روند کلی سئو در شاخص‌های کلیدی دارای رشد باثبات و مثبت بوده است. جهش <strong class="text-emerald-700">+<?php echo $stats['organic_growth_pct']; ?>٪</strong> در ترافیک ارگانیک ماهانه به همراه پایداری در رتبه‌های ۱ تا ۳ کلمات کلیدی اصلی، درآمد مستقیم فروشگاه را افزایش داده است.
            </p>
            <p>
                فرصت‌های اصلی رشد برای دوره آینده، تمرکز بر بهینه‌سازی Title و Meta Description لندینگ‌های با Impression بالا و افزایش لینک‌سازی داخلی به سمت دسته‌بندی‌های نوظهور است.
            </p>
        </div>

        <div class="space-y-2 text-xs">
            <h4 class="font-black text-slate-900 mb-2">اقدامات اولویت‌دار دوره بعد:</h4>
            <?php foreach (array_slice($recs, 0, 3) as $i => $rec): ?>
            <div class="p-3 bg-white border border-slate-200/70 rounded-xl flex items-start gap-2 shadow-xs">
                <span class="w-5 h-5 rounded-full bg-indigo-50 text-indigo-700 flex items-center justify-center font-bold text-[10px] shrink-0 mt-0.5"><?php echo $i+1; ?></span>
                <div>
                    <span class="font-bold text-slate-900 block"><?php echo htmlspecialchars($rec['title']); ?></span>
                    <span class="text-[11px] text-slate-500 leading-normal block mt-0.5"><?php echo htmlspecialchars($rec['recommendation']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
