<?php
/**
 * View: Content Analysis & Performance
 * Editorial & Landing Page Revenue Attribution, Content Decay & Top Converting Content
 */

$pages = SeoDB::getPages($currentBusiness['id']);
// Sort by revenue
$sortedByRev = $pages;
usort($sortedByRev, function($a, $b) {
    return ($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0);
});
?>

<!-- Content Performance Header -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-content-header">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
        <div>
            <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
                <span>📝</span> تحلیل جامع محتوا و درآمدزایی (Content & Revenue Attribution)
            </h2>
            <p class="text-xs text-slate-400 mt-1">شناسایی صفحات و مقالات پول‌ساز، نرخ تبدیل کاربران به خریدار و پایش عمر محتوا</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-xl font-bold">
                اتصال مستقیم به داده‌های تجارت الکترونیک (WooCommerce & GA4)
            </span>
        </div>
    </div>
</div>

<!-- Content Revenue Table -->
<div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden mb-6" id="table-content-rev">
    <div class="p-4 bg-slate-50/70 border-b border-slate-100">
        <h3 class="text-xs font-black text-slate-800">صفحات با بالاترین درآمد و فروش ارگانیک</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead>
                <tr class="bg-slate-50/40 border-b border-slate-200/80 text-slate-500">
                    <th class="py-3 px-4 font-black">صفحه / عنوان محتوا</th>
                    <th class="py-3 px-3 font-black text-center">کلیک ارگانیک</th>
                    <th class="py-3 px-3 font-black text-center">نرخ تبدیل (CR)</th>
                    <th class="py-3 px-3 font-black text-center">تعداد تراکنش</th>
                    <th class="py-3 px-3 font-black text-center">درآمد محتوا</th>
                    <th class="py-3 px-3 font-black text-center">وضعیت عمر محتوا</th>
                    <th class="py-3 px-4 font-black text-left">اقدام سئو</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($sortedByRev as $p): 
                    $cr = ($p['clicks'] ?? 0) > 0 ? round((($p['conversions'] ?? 0) / $p['clicks']) * 100, 2) : 0;
                ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="py-3 px-4 max-w-sm">
                        <div class="font-black text-slate-900 text-xs"><?php echo htmlspecialchars($p['title']); ?></div>
                        <div class="text-[10px] text-slate-400 font-mono mt-0.5 truncate"><?php echo htmlspecialchars($p['url']); ?></div>
                    </td>
                    <td class="py-3 px-3 text-center font-mono font-bold text-slate-800">
                        <?php echo number_format($p['clicks'] ?? 0); ?>
                    </td>
                    <td class="py-3 px-3 text-center font-mono font-black text-indigo-700">
                        <?php echo $cr; ?>٪
                    </td>
                    <td class="py-3 px-3 text-center font-mono font-bold text-emerald-700">
                        <?php echo number_format($p['conversions'] ?? 0); ?> سفارش
                    </td>
                    <td class="py-3 px-3 text-center font-mono font-black text-slate-900">
                        <?php echo number_format(round(($p['revenue'] ?? 0) / 1000000)); ?> میلیون تومان
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[10px] font-bold">سبز و تازه</span>
                    </td>
                    <td class="py-3 px-4 text-left">
                        <button onclick="askAiAboutPage('<?php echo addslashes($p['url']); ?>', '<?php echo addslashes($p['title']); ?>', <?php echo $p['ctr']; ?>)" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-bold text-[11px] transition-colors cursor-pointer">
                            💡 تقویت تبدیل
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
