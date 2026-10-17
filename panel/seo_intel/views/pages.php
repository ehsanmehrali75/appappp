<?php
/**
 * View: Page Analysis
 * Full page-level performance analysis: Clicks, Impressions, CTR, Conversions, Revenue and Opportunity detection
 */

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$pagesList = SeoDB::getPages($currentBusiness['id'], $filter, $search);

$highImpCount = count(SeoDB::getPages($currentBusiness['id'], 'high_imp_low_ctr'));
$issuesCount = count(SeoDB::getPages($currentBusiness['id'], 'issues'));
?>

<!-- KPI Highlights -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs text-slate-500 font-bold block mb-1">صفحات تحلیل شده</span>
        <span class="text-2xl font-black text-slate-900 font-mono"><?php echo count(SeoDB::getPages($currentBusiness['id'])); ?></span>
        <span class="text-[10px] text-slate-400 block mt-1">بر اساس داده‌های GSC و GA4</span>
    </div>
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs text-amber-700 font-bold block mb-1">فرصت طلایی (Impression بالا، CTR کم)</span>
        <span class="text-2xl font-black text-amber-600 font-mono"><?php echo $highImpCount; ?> صفحه</span>
        <span class="text-[10px] text-slate-400 block mt-1">با اصلاح Title تا ۵۰٪ کلیک بیشتر می‌گیرند</span>
    </div>
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs text-rose-700 font-bold block mb-1">صفحات نیازمند رفع عیب تکنیکال</span>
        <span class="text-2xl font-black text-rose-600 font-mono"><?php echo $issuesCount; ?> صفحه</span>
        <span class="text-[10px] text-slate-400 block mt-1">کانیبالیزیشن یا سرعت پایین</span>
    </div>
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs text-emerald-700 font-bold block mb-1">مجموع درآمد لندینگ‌های ارگانیک</span>
        <span class="text-2xl font-black text-emerald-700 font-mono"><?php echo number_format(round($stats['organic_revenue'] / 1000000)); ?> م.ت</span>
        <span class="text-[10px] text-slate-400 block mt-1">اتصال به فروشگاه <?php echo htmlspecialchars($currentBusiness['name']); ?></span>
    </div>
</div>

<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-pages-controls">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
        <div>
            <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
                <span>📑</span> تحلیل عملکرد صفحات (Page Analysis & Revenue Attribution)
            </h2>
            <p class="text-xs text-slate-400 mt-1">ارزیابی عمیق صفحات فرود، نرخ کلیک، درآمدزایی ارگانیک و کشف فرصت‌های نرخ تبدیل</p>
        </div>

        <div class="flex items-center gap-2">
            <?php if (ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations', 'use')): ?><button onclick="triggerSync('google_search_console')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
                <span>🔄</span> بروزرسانی آمار صفحات
            </button><?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mt-5">
        <div class="flex flex-wrap items-center gap-1.5 p-1 bg-slate-100/80 rounded-xl text-xs font-bold">
            <a href="?view=pages&filter=all&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'all' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                همه صفحات (<?php echo count(SeoDB::getPages($currentBusiness['id'])); ?>)
            </a>
            <a href="?view=pages&filter=high_imp_low_ctr&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'high_imp_low_ctr' ? 'bg-white text-amber-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                ⚡ ایمپرشن بالا، CTR پایین (<?php echo $highImpCount; ?>)
            </a>
            <a href="?view=pages&filter=issues&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'issues' ? 'bg-white text-rose-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                ⚠️ دارای خطا یا کانیبال (<?php echo $issuesCount; ?>)
            </a>
        </div>

        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="view" value="pages">
            <input type="hidden" name="business" value="<?php echo htmlspecialchars($currentBusiness['slug']); ?>">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="جستجو در URL یا عنوان..." class="w-64 px-3.5 py-1.5 pr-8 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-indigo-500 transition-all">
                <span class="absolute right-2.5 top-2 text-xs text-slate-400">🔍</span>
            </div>
            <button type="submit" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition-all">فیلتر</button>
        </form>
    </div>
</div>

<!-- Pages Table -->
<div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden" id="table-pages-container">
    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead>
                <tr class="bg-slate-50/70 border-b border-slate-200/80 text-slate-500">
                    <th class="py-3.5 px-4 font-black">آدرس صفحه (URL) و عنوان</th>
                    <th class="py-3.5 px-3 font-black text-center">Clicks (کلیک)</th>
                    <th class="py-3.5 px-3 font-black text-center">Impressions (نمایش)</th>
                    <th class="py-3.5 px-3 font-black text-center">CTR (نرخ کلیک)</th>
                    <th class="py-3.5 px-3 font-black text-center">Avg Position</th>
                    <th class="py-3.5 px-3 font-black text-center">Organic Traffic</th>
                    <th class="py-3.5 px-3 font-black text-center">Conversions</th>
                    <th class="py-3.5 px-4 font-black text-right">پیشنهاد هوشمند سئو (AI Recommendation)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($pagesList)): ?>
                <tr>
                    <td colspan="8" class="py-12 text-center text-slate-400">
                        هیچ صفحه‌ای با این فیلترها یافت نشد.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($pagesList as $p): 
                    $hasIssues = !empty($p['issues']);
                    $isLowCtr = (($p['impressions'] ?? 0) > 500 && ($p['ctr'] ?? 0) < 2.5);
                    $aiSuggestion = $p['ai_suggestion'] ?? ($isLowCtr ? 'این صفحه Impression بالایی دارد ولی CTR پایین است، پیشنهاد می‌شود Title و Meta Description اصلاح شود.' : 'عملکرد صفحه مطلوب است. رصد مستمر رتبه‌ها توصیه می‌شود.');
                ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="py-3 px-4 max-w-xs">
                        <div class="font-black text-slate-900 text-xs truncate" title="<?php echo htmlspecialchars($p['title']); ?>">
                            <?php echo htmlspecialchars($p['title']); ?>
                        </div>
                        <div class="text-[11px] text-indigo-600 font-mono mt-0.5 truncate dir-ltr text-right" title="<?php echo htmlspecialchars($p['url']); ?>">
                            <?php echo htmlspecialchars($p['url']); ?>
                        </div>
                        <?php if ($hasIssues): ?>
                            <div class="mt-1 space-y-0.5">
                                <?php foreach ($p['issues'] as $issue): ?>
                                    <div class="text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded flex items-center gap-1 w-fit">
                                        <span>⚠️</span> <?php echo htmlspecialchars($issue); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-black text-slate-900">
                        <?php echo number_format($p['clicks'] ?? 0); ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono text-slate-600">
                        <?php echo number_format($p['impressions'] ?? 0); ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold">
                        <span class="px-2 py-1 rounded-lg <?php echo $isLowCtr ? 'bg-amber-100 text-amber-800 font-black' : 'bg-slate-100 text-slate-800'; ?>">
                            <?php echo $p['ctr']; ?>٪
                        </span>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold text-slate-700">
                        <?php echo $p['avg_position']; ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold text-indigo-700">
                        <?php echo number_format($p['organic_traffic'] ?? ($p['clicks'] * 1.5)); ?>
                        <span class="text-[10px] text-slate-400 block font-normal">سشن GA4</span>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-black text-emerald-700">
                        <?php echo number_format($p['conversions'] ?? 0); ?>
                        <span class="text-[10px] text-slate-400 block font-normal">خرید نهایی</span>
                    </td>

                    <td class="py-3 px-4 text-right max-w-md">
                        <div class="p-2.5 bg-indigo-50/60 border border-indigo-100 rounded-xl text-xs text-slate-700 leading-relaxed">
                            <span class="font-black text-indigo-950 block mb-0.5">💡 راهکار اختصاصی:</span>
                            <?php echo htmlspecialchars($aiSuggestion); ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
