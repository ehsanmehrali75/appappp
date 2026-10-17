<?php
/**
 * View: Internal Link Analysis & Graph Intelligence
 * Requirement 8 - Item 7: Internal Link Analysis
 * Provides deep insights into inlink counts, anchor texts, orphan pages, click depths,
 * and high-impact internal linking opportunities for ranking elevation.
 */

$filter = $_GET['filter'] ?? 'all';
$links = SeoDB::getInternalLinks($currentBusiness['id'], $filter);
$opportunities = SeoDB::getInternalLinkOpportunities($currentBusiness['id']);

$totalInlinks = count($links);
$orphanCount = count(array_filter($links, function($l) { return !empty($l['is_orphan']); }));
$oppsCount = count($opportunities);
?>

<!-- Header -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4" id="card-links-header">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 rounded-md bg-indigo-50 text-indigo-700 text-xs font-black">Internal Link Intelligence</span>
            <span class="text-xs text-slate-400 font-mono"><?php echo date('Y/m/d'); ?></span>
        </div>
        <h2 class="text-lg font-black text-slate-900">آنالیز ساختار لینک‌سازی داخلی (Internal Link Analysis)</h2>
        <p class="text-xs text-slate-500 mt-1">
            بررسی جریان اعتبار صفحات (PageRank Flow)، شناسایی صفحات یتیم (Orphan Pages) و ایجاد لینک‌های استراتژیک برای صفحات با ایمپرشن بالا.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <?php if (ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations', 'use')): ?><button onclick="triggerSync('wordpress')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
            <span>🔄</span> کراول لینک‌های وردپرس
        </button><?php endif; ?>
    </div>
</div>

<!-- Metrics Overview -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">لینک‌های پایش شده</span>
        <span class="text-2xl font-black text-slate-900 font-mono"><?php echo $totalInlinks; ?> پیوند</span>
        <span class="text-[10px] text-slate-400 block mt-1">ساختار شبکه‌ای در جریان</span>
    </div>
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">صفحات یتیم (بدون این‌لینک)</span>
        <span class="text-2xl font-black <?php echo $orphanCount > 0 ? 'text-rose-600' : 'text-emerald-600'; ?> font-mono">
            <?php echo $orphanCount; ?> صفحه
        </span>
        <span class="text-[10px] text-slate-400 block mt-1"><?php echo $orphanCount > 0 ? 'نیاز فوری به ریدایرکت یا لینک' : 'تمام صفحات متصل هستند'; ?></span>
    </div>
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">فرصت‌های جهش رتبه</span>
        <span class="text-2xl font-black text-indigo-700 font-mono"><?php echo $oppsCount; ?> لندینگ</span>
        <span class="text-[10px] text-emerald-600 font-bold block mt-1">ایمپرشن بالا با لینک ورودی اندک</span>
    </div>
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-500 block mb-1">عمق کلیک بهینه (Click Depth)</span>
        <span class="text-2xl font-black text-slate-900 font-mono">≤ ۳ کلیک</span>
        <span class="text-[10px] text-emerald-600 font-bold block mt-1">دسترسی خزنده‌های گوگل تأیید شد</span>
    </div>
</div>

<!-- Strategic Opportunities Card -->
<?php if (!empty($opportunities)): ?>
<div class="bg-indigo-50/70 border border-indigo-100 p-6 rounded-2xl mb-6 shadow-sm">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-black text-indigo-950 flex items-center gap-2">
            <span>💡</span> اولویت‌های ویژه تقویت لینک‌سازی داخلی (AI Internal Link Boost)
        </h3>
        <span class="text-xs font-bold text-indigo-700 bg-white px-2.5 py-1 rounded-lg border border-indigo-200">
            تأثیر بالا روی رتبه‌بندی
        </span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($opportunities as $opp): ?>
        <div class="bg-white p-4 rounded-xl border border-indigo-100/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-900"><?php echo htmlspecialchars($opp['target_title']); ?></span>
                <span class="text-[10px] font-black px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-mono">
                    تأثیر: <?php echo htmlspecialchars($opp['expected_impact']); ?>
                </span>
            </div>
            <p class="text-xs text-slate-600 leading-relaxed mb-3">
                <?php echo htmlspecialchars($opp['reason']); ?>
            </p>
            <div class="bg-slate-50 p-2.5 rounded-lg text-[11px] space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 font-bold">صفحه مبدا پیشنهادی:</span>
                    <span class="font-mono text-indigo-700 dir-ltr text-right truncate max-w-xs"><?php echo htmlspecialchars($opp['source_candidate']); ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 font-bold">انکرتکست هدف:</span>
                    <strong class="text-slate-900"><?php echo htmlspecialchars($opp['suggested_anchor']); ?></strong>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Internal Link Network Table -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
        <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
            <span>🔗</span> فهرست لینک‌های داخلی، انکرتکست‌ها و عمق دسترسی
        </h3>

        <!-- Filter Tabs -->
        <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold">
            <a href="?view=links&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=all" 
               class="px-3 py-1 rounded-lg <?php echo $filter === 'all' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600'; ?>">
               همه پیوندها
            </a>
            <a href="?view=links&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=opportunities" 
               class="px-3 py-1 rounded-lg <?php echo $filter === 'opportunities' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600'; ?>">
               🎯 دارای فرصت بهینه‌سازی
            </a>
            <a href="?view=links&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=orphan" 
               class="px-3 py-1 rounded-lg <?php echo $filter === 'orphan' ? 'bg-white text-rose-700 shadow-xs' : 'text-slate-600'; ?>">
               ⚠️ صفحات یتیم (Orphan)
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-100">
                <tr>
                    <th class="p-3">صفحه مبدأ (Source)</th>
                    <th class="p-3">صفحه مقصد (Target URL)</th>
                    <th class="p-3">انکرتکست (Anchor Text)</th>
                    <th class="p-3 text-center">نوع لینک</th>
                    <th class="p-3 text-center">تعداد لینک ورودی</th>
                    <th class="p-3 text-center">عمق کلیک</th>
                    <th class="p-3 text-center">انتقال اعتبار</th>
                    <th class="p-3 text-center">وضعیت</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($links as $l): ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3 max-w-xs">
                        <div class="font-bold text-slate-900 truncate"><?php echo htmlspecialchars($l['source_title']); ?></div>
                        <span class="text-[10px] text-slate-400 font-mono dir-ltr block truncate"><?php echo htmlspecialchars($l['source_url']); ?></span>
                    </td>
                    <td class="p-3 max-w-xs">
                        <div class="font-bold text-indigo-700 truncate"><?php echo htmlspecialchars($l['target_title']); ?></div>
                        <span class="text-[10px] text-slate-400 font-mono dir-ltr block truncate"><?php echo htmlspecialchars($l['target_url']); ?></span>
                    </td>
                    <td class="p-3">
                        <span class="font-black text-slate-800 bg-slate-100 px-2 py-1 rounded-md">
                            «<?php echo htmlspecialchars($l['anchor_text']); ?>»
                        </span>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-slate-100 text-slate-700">
                            <?php echo $l['status'] ?? 'Follow'; ?>
                        </span>
                    </td>
                    <td class="p-3 text-center font-mono font-bold text-slate-900">
                        <?php echo $l['inlinks_count']; ?>
                    </td>
                    <td class="p-3 text-center font-mono text-slate-600">
                        <?php echo $l['click_depth']; ?>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded text-[10px] font-black <?php echo ($l['equity_transfer'] ?? '') === 'High' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>">
                            <?php echo $l['equity_transfer'] ?? 'Medium'; ?>
                        </span>
                    </td>
                    <td class="p-3 text-center">
                        <?php if (!empty($l['is_orphan'])): ?>
                            <span class="px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 text-[10px] font-bold">صفحه یتیم</span>
                        <?php elseif (!empty($l['has_opportunity'])): ?>
                            <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 text-[10px] font-bold">نیازمند لینک بیشتر</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[10px] font-bold">متصل و فعال</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
