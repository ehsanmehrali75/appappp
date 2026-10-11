<?php
/**
 * View: Dashboard / Overview
 * Enterprise SEO & Analytics Intelligence Executive Dashboard
 */
?>

<!-- Top KPI Cards Row -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <!-- SEO Health Score -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden" id="card-seo-health">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-500">سلامت جامع سئو (Health)</span>
            <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm font-black">🏆</span>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-3xl font-black text-slate-900" id="val-seo-health"><?php echo $stats['seo_health']; ?></span>
            <span class="text-xs font-bold text-slate-400">/ ۱۰۰</span>
            <span class="mr-auto text-[11px] font-black text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md">عالی (Grade A)</span>
        </div>
        <!-- Mini Health Progress -->
        <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden mb-2">
            <div class="bg-gradient-to-r from-indigo-500 to-emerald-500 h-full rounded-full transition-all duration-700" style="width: <?php echo $stats['seo_health']; ?>%"></div>
        </div>
        <div class="flex justify-between text-[10px] text-slate-400 font-medium">
            <span>تکنیکال: <?php echo $stats['health_breakdown']['technical']; ?>٪</span>
            <span>رتبه‌ها: <?php echo $stats['health_breakdown']['rankings']; ?>٪</span>
            <span>محتوا: <?php echo $stats['health_breakdown']['content']; ?>٪</span>
        </div>
    </div>

    <!-- Organic Traffic Growth -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm" id="card-organic-traffic">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-500">رشد ترافیک ارگانیک (GA4)</span>
            <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-black">📈</span>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-3xl font-black text-emerald-600 font-mono">+<?php echo $stats['organic_growth_pct']; ?>٪</span>
            <span class="text-[10px] text-slate-400 font-medium">نسبت به دوره قبل</span>
        </div>
        <div class="flex items-center justify-between text-[11px] text-slate-500 font-semibold pt-1 border-t border-slate-100">
            <span>بازدید ارگانیک:</span>
            <span class="font-mono text-slate-800"><?php echo number_format($stats['organic_sessions']); ?> نشست</span>
        </div>
    </div>

    <!-- Total Tracked Keywords -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm" id="card-total-keywords">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-500">کلمات کلیدی ردیابی شده</span>
            <span class="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-sm font-black">🎯</span>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-3xl font-black text-slate-900 font-mono"><?php echo number_format($stats['total_keywords']); ?></span>
            <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">+۱۲ جدید</span>
        </div>
        <div class="flex items-center justify-between text-[11px] text-slate-500 font-semibold pt-1 border-t border-slate-100">
            <span>در آستانه صفحه ۱ (۱۱-۲۰):</span>
            <span class="font-mono text-amber-600 font-bold"><?php echo $stats['striking_distance']; ?> کلمه</span>
        </div>
    </div>

    <!-- Top 10 Keywords (Page 1) -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm" id="card-top10-keywords">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-500">کلمات صفحه اول گوگل (Top 10)</span>
            <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm font-black">⭐</span>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-3xl font-black text-amber-600 font-mono"><?php echo $stats['top_10_keywords']; ?></span>
            <span class="text-[10px] text-slate-400 font-medium">کوئری برتر</span>
        </div>
        <div class="flex items-center justify-between text-[11px] text-slate-500 font-semibold pt-1 border-t border-slate-100">
            <span>رتبه ۱ تا ۳ گوگل:</span>
            <span class="font-mono text-indigo-600 font-bold"><?php echo $stats['top_3_keywords']; ?> کوئری</span>
        </div>
    </div>

    <!-- Indexed Pages Coverage -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm" id="card-indexed-pages">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-500">صفحات ایندکس شده (GSC)</span>
            <span class="w-8 h-8 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center text-sm font-black">📑</span>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-3xl font-black text-slate-900 font-mono"><?php echo number_format($stats['indexed_pages']); ?></span>
            <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">۹۶.۲٪ سلامت</span>
        </div>
        <div class="flex items-center justify-between text-[11px] text-slate-500 font-semibold pt-1 border-t border-slate-100">
            <span>درآمد منتسب به ارگانیک:</span>
            <span class="font-mono text-emerald-700 font-bold"><?php echo number_format(round($stats['organic_revenue'] / 1000000)); ?> م.ت</span>
        </div>
    </div>
</div>

<!-- Main Row: Traffic Evolution Chart + Keyword Rank Distribution -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Traffic Evolution Chart (2 cols) -->
    <div class="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-traffic-chart">
        <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>📊</span> روند رشد ترافیک ارگانیک و کانال‌های ورودی (GA4 & GSC)
                </h3>
                <p class="text-xs text-slate-400 mt-1">مقایسه دوره‌ای سشن‌های سرچ گوگل، شبکه‌های اجتماعی و ورودی مستقیم</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="flex items-center gap-1.5 text-xs text-indigo-600 font-bold">
                    <span class="w-3 h-3 rounded-full bg-indigo-600"></span> ارگانیک
                </span>
                <span class="flex items-center gap-1.5 text-xs text-pink-500 font-bold">
                    <span class="w-3 h-3 rounded-full bg-pink-500"></span> سوشیال
                </span>
                <span class="flex items-center gap-1.5 text-xs text-sky-500 font-bold">
                    <span class="w-3 h-3 rounded-full bg-sky-500"></span> مستقیم
                </span>
            </div>
        </div>

        <!-- Custom SVG Multi-Line Chart -->
        <div class="w-full h-64 relative flex items-end justify-between px-4 pb-6 pt-4 bg-slate-50/50 rounded-xl border border-slate-100">
            <?php 
            $timeline = $trafficData['timeline'] ?? [];
            $maxVal = 50000;
            foreach ($timeline as $idx => $t): 
                $orgHeight = min(95, round(($t['organic'] / $maxVal) * 100));
                $socHeight = min(95, round(($t['social'] / $maxVal) * 100));
                $dirHeight = min(95, round(($t['direct'] / $maxVal) * 100));
            ?>
            <div class="flex-1 flex flex-col items-center h-full justify-end group relative">
                <!-- Tooltip on hover -->
                <div class="absolute -top-12 bg-slate-900 text-white text-[10px] py-1.5 px-3 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity z-10 whitespace-nowrap shadow-lg pointer-events-none">
                    <div>ارگانیک: <?php echo number_format($t['organic']); ?></div>
                    <div>سوشیال: <?php echo number_format($t['social']); ?></div>
                </div>

                <!-- Bars representation -->
                <div class="w-full max-w-[42px] flex items-end justify-center gap-1 h-full">
                    <div class="w-3 bg-indigo-600 rounded-t-md transition-all duration-500 group-hover:bg-indigo-700" style="height: <?php echo $orgHeight; ?>%"></div>
                    <div class="w-2.5 bg-pink-500 rounded-t-md transition-all duration-500 group-hover:bg-pink-600" style="height: <?php echo $socHeight; ?>%"></div>
                    <div class="w-2 bg-sky-400 rounded-t-md transition-all duration-500 group-hover:bg-sky-500" style="height: <?php echo $dirHeight; ?>%"></div>
                </div>

                <span class="text-[11px] font-bold text-slate-500 mt-2"><?php echo $t['date']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-3 gap-4 mt-5 pt-4 border-t border-slate-100 text-center">
            <div class="p-3 bg-indigo-50/50 rounded-xl">
                <span class="text-[11px] text-slate-500 block mb-1">میانگین زمان سشن</span>
                <span class="text-sm font-black text-indigo-900 font-mono"><?php echo $trafficData['summary']['avg_session_duration'] ?? '3m 24s'; ?></span>
            </div>
            <div class="p-3 bg-emerald-50/50 rounded-xl">
                <span class="text-[11px] text-slate-500 block mb-1">نرخ تعامل (Engagement Rate)</span>
                <span class="text-sm font-black text-emerald-700 font-mono"><?php echo $trafficData['summary']['engagement_rate'] ?? '61.6'; ?>٪</span>
            </div>
            <div class="p-3 bg-rose-50/50 rounded-xl">
                <span class="text-[11px] text-slate-500 block mb-1">نرخ پرش (Bounce Rate)</span>
                <span class="text-sm font-black text-rose-700 font-mono"><?php echo $trafficData['summary']['bounce_rate'] ?? '38.4'; ?>٪</span>
            </div>
        </div>
    </div>

    <!-- Keyword Position Distribution & Funnel -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between" id="sec-ranking-dist">
        <div>
            <h3 class="text-sm font-black text-slate-900 mb-1 flex items-center gap-2">
                <span>🎯</span> توزیع رتبه‌های کلمات در گوگل
            </h3>
            <p class="text-xs text-slate-400 mb-5">وضعیت موقعیت کیوردها در نتایج ارگانیک سرچ کنسول</p>

            <div class="space-y-3.5">
                <div>
                    <div class="flex justify-between text-xs font-bold mb-1">
                        <span class="text-emerald-700">رتبه‌های ۱ تا ۳ (Top 3)</span>
                        <span class="font-mono text-slate-800"><?php echo $stats['top_3_keywords']; ?> کلمه (۲۵٪)</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-emerald-500 h-full rounded-full" style="width: 25%"></div>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between text-xs font-bold mb-1">
                        <span class="text-indigo-700">رتبه‌های ۴ تا ۱۰ (صفحه ۱)</span>
                        <span class="font-mono text-slate-800"><?php echo $stats['top_10_keywords'] - $stats['top_3_keywords']; ?> کلمه (۳۸٪)</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-indigo-600 h-full rounded-full" style="width: 38%"></div>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between text-xs font-bold mb-1">
                        <span class="text-amber-600">رتبه‌های ۱۱ تا ۲۰ (آستانه صفحه ۱)</span>
                        <span class="font-mono text-slate-800"><?php echo $stats['striking_distance']; ?> کلمه (۲۲٪)</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-amber-500 h-full rounded-full" style="width: 22%"></div>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between text-xs font-bold mb-1">
                        <span class="text-slate-500">رتبه‌های ۲۱ تا ۵۰ (صفحه ۲ و ۳)</span>
                        <span class="font-mono text-slate-800">۱۵ کلمه (۱۵٪)</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-slate-400 h-full rounded-full" style="width: 15%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Marketing Funnel Box -->
        <div class="mt-6 pt-5 border-t border-slate-100 bg-slate-50 p-4 rounded-xl">
            <span class="text-xs font-black text-slate-800 block mb-3">قیف تبدیل سئو (Search to Revenue Funnel)</span>
            <div class="space-y-1.5 text-[11px]">
                <div class="flex justify-between font-medium">
                    <span class="text-slate-500">۱. ایمپرشن سرچ گوگل:</span>
                    <span class="font-mono font-bold text-slate-800"><?php echo number_format($stats['total_impressions']); ?></span>
                </div>
                <div class="flex justify-between font-medium">
                    <span class="text-slate-500">۲. کلیک‌های ارگانیک (CTR <?php echo $stats['average_ctr']; ?>٪):</span>
                    <span class="font-mono font-bold text-indigo-700"><?php echo number_format($stats['total_clicks']); ?></span>
                </div>
                <div class="flex justify-between font-medium">
                    <span class="text-slate-500">۳. خریداران نهایی:</span>
                    <span class="font-mono font-bold text-emerald-700">۱,۲۴۰ سفارش</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Row: Top Opportunity Keywords & AI High Priority Alerts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Top Opportunity Keywords -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-opportunity-keywords">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>⚡</span> کلمات پرفرصت در آستانه جهش (Striking Distance)
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">کلماتی که در رتبه‌های ۱۱ الی ۲۰ قرار دارند و با کمترین اقدام به تاپ ۵ می‌رسند</p>
            </div>
            <a href="?view=keywords&filter=opportunity&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">مشاهده همه ←</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="border-b border-slate-100 text-slate-400">
                        <th class="pb-2 font-bold">کلمه کلیدی</th>
                        <th class="pb-2 font-bold text-center">رتبه فعلی</th>
                        <th class="pb-2 font-bold text-center">حجم ماهانه</th>
                        <th class="pb-2 font-bold text-center">پتانسیل</th>
                        <th class="pb-2 font-bold text-left">اقدام پیشنهادی</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php 
                    $oppKws = SeoDB::getKeywords($currentBusiness['id'], 'opportunity');
                    $oppKws = array_slice($oppKws, 0, 4);
                    foreach ($oppKws as $okw): 
                    ?>
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        <td class="py-3 font-bold text-slate-800">
                            <div><?php echo htmlspecialchars($okw['keyword']); ?></div>
                            <span class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($okw['target_url'] ?? ''); ?></span>
                        </td>
                        <td class="py-3 text-center">
                            <span class="px-2 py-1 bg-amber-50 text-amber-700 font-mono font-black rounded-lg text-xs">
                                <?php echo $okw['position']; ?>
                            </span>
                        </td>
                        <td class="py-3 text-center font-mono text-slate-600">
                            <?php echo number_format($okw['search_volume'] ?? 0); ?>
                        </td>
                        <td class="py-3 text-center">
                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-600 text-[10px] font-black rounded-md">بسیار بالا</span>
                        </td>
                        <td class="py-3 text-left">
                            <a href="?view=recommendations&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg hover:bg-indigo-100 transition-colors">
                                بهبود Title و FAQ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active AI SEO Analyst Alerts -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="sec-ai-alerts">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                    <span>🧠</span> هشدارهای هوش مصنوعی و تحلیل‌گر رشد (AI SEO Analyst)
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">تحلیل ترکیب داده‌های سرچ کنسول، آنالیتیکس و شبکه‌های اجتماعی</p>
            </div>
            <a href="?view=recommendations&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">مرکز پیشنهادات ←</a>
        </div>

        <div class="space-y-3">
            <?php 
            $recs = SeoDB::getRecommendations($currentBusiness['id'], 'pending');
            $recs = array_slice($recs, 0, 3);
            foreach ($recs as $r): 
                $impactColor = ($r['impact'] === 'High') ? 'bg-rose-50 text-rose-700 border-rose-100' : 'bg-amber-50 text-amber-700 border-amber-100';
            ?>
            <div class="p-4 rounded-xl border border-slate-100 hover:border-indigo-100 bg-slate-50/40 hover:bg-indigo-50/20 transition-all">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($r['title']); ?></span>
                    <span class="text-[10px] font-black px-2 py-0.5 rounded border <?php echo $impactColor; ?>">
                        اثرگذاری: <?php echo $r['impact'] === 'High' ? 'بسیار زیاد (High)' : 'متوسط'; ?>
                    </span>
                </div>
                <p class="text-xs text-slate-600 leading-relaxed mb-2.5">
                    <?php echo htmlspecialchars($r['issue']); ?>
                </p>
                <div class="flex items-center justify-between text-[11px] pt-2 border-t border-slate-200/60">
                    <span class="text-slate-500 font-medium">اقدام: <?php echo htmlspecialchars(mb_substr($r['recommendation'], 0, 60)) . '...'; ?></span>
                    <button onclick="applyRecommendation('<?php echo $r['id']; ?>')" class="text-indigo-600 font-black hover:underline cursor-pointer">
                        بررسی و اعمال ←
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
