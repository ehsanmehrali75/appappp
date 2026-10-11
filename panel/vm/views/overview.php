<?php
/**
 * Visual Merchandising Intelligence - Executive Multi-Store Overview
 */
$projectId = $project['id'] ?? ($project['slug'] ?? '');
$allStores = VmDB::getStoresByProject($projectId);
$sync = VmMflow::getSyncStatus($projectId);
$prods = VmDB::getProductsByProject($projectId);
$prodCount = count($prods);

$totalStores = count($allStores);
$sumScore = 0;
$scoredStoresCount = 0;
$bestStore = null;
$worstStore = null;
$totalSales = 0;
$hasSalesData = false;
$totalVisitors = 0;
$hasVisitorData = false;

foreach ($allStores as &$s) {
    if (!isset($s['overall_vm_score']) || $s['overall_vm_score'] === null) {
        $calc = VmDB::calculateStoreScore($s['id']);
        $s['overall_vm_score'] = $calc['store_score'] ?? null;
    }
    if ($s['overall_vm_score'] !== null) {
        $sumScore += $s['overall_vm_score'];
        $scoredStoresCount++;
    }
    if (isset($s['today_sales']) && $s['today_sales'] !== null) {
        $totalSales += $s['today_sales'];
        $hasSalesData = true;
    }
    if (isset($s['today_visitors']) && $s['today_visitors'] !== null) {
        $totalVisitors += $s['today_visitors'];
        $hasVisitorData = true;
    }

    if ($bestStore === null || ($s['overall_vm_score'] ?? 0) > ($bestStore['overall_vm_score'] ?? 0)) {
        $bestStore = $s;
    }
    if ($worstStore === null || ($s['overall_vm_score'] ?? 0) < ($worstStore['overall_vm_score'] ?? 0)) {
        $worstStore = $s;
    }
}
unset($s);
$avgScore = $scoredStoresCount > 0 ? round($sumScore / $scoredStoresCount, 1) : null;
?>

<!-- mflow Data Integration Bar -->
<div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white rounded-2xl p-5 mb-6 shadow-xl border border-indigo-900/40 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/20 border border-indigo-400/30 flex items-center justify-center text-2xl">
            ⚡
        </div>
        <div>
            <div class="flex items-center gap-2">
                <span class="font-black text-sm text-indigo-300">اتصال لایو دیتالیر mflow</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1"></span>
                    همگام با کاتالوگ (<?php echo $prodCount; ?> کالا)
                </span>
            </div>
            <p class="text-xs text-slate-300 mt-1">
                کاتالوگ و موجودی کالاها از دیتابیس مرکزی سینک شده است. وضعیت وب‌سرویس فروش و تردد: <span class="font-medium text-amber-300">بدون دیتا (در انتظار اتصال درگاه POS / سنسور)</span> | آخرین همگام‌سازی: <span class="font-mono text-indigo-300" id="sync-time-display"><?php echo htmlspecialchars($sync['last_sync'] ?? 'لحظاتی پیش'); ?></span>
            </p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="triggerMflowSync()" id="btn-sync-mflow" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg transition flex items-center gap-2">
            <span>🔄</span>
            <span>همگام‌سازی کاتالوگ و موجودی</span>
        </button>
        <a href="<?php echo vm_url('studio'); ?>" class="px-4 py-2.5 bg-white/10 hover:bg-white/20 text-white text-xs font-bold rounded-xl border border-white/10 transition flex items-center gap-2">
            <span>📐</span>
            <span>ورود مستقیم به VM Studio</span>
        </a>
    </div>
</div>

<!-- Executive KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition">
        <div class="flex items-center justify-between text-slate-500 text-xs font-bold mb-3">
            <span>تعداد کل شعب و پاپ‌آپ‌ها</span>
            <span class="p-2 rounded-lg bg-blue-50 text-blue-600">🏪</span>
        </div>
        <div class="text-3xl font-black text-slate-800"><?php echo $totalStores; ?> <span class="text-xs font-normal text-slate-400">شعبه فعال</span></div>
        <div class="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
            <span class="text-emerald-600 font-bold">۱۰۰٪ تحت پوشش</span> چیدمان دیجیتال
        </div>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition">
        <div class="flex items-center justify-between text-slate-500 text-xs font-bold mb-3">
            <span>میانگین شاخص VM Score</span>
            <span class="p-2 rounded-lg bg-indigo-50 text-indigo-600">🏆</span>
        </div>
        <div class="text-3xl font-black text-indigo-600">
            <?php if ($avgScore !== null): ?>
                <?php echo $avgScore; ?> <span class="text-xs font-normal text-slate-400">از ۱۰۰</span>
            <?php else: ?>
                <span class="text-base text-slate-400 font-bold">بدون دیتا</span>
            <?php endif; ?>
        </div>
        <div class="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
            <span class="text-indigo-600 font-bold"><?php echo $scoredStoresCount; ?> از <?php echo $totalStores; ?></span> شعبه دارای نقشه چیدمان
        </div>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition">
        <div class="flex items-center justify-between text-slate-500 text-xs font-bold mb-3">
            <span>بهترین عملکرد چیدمان</span>
            <span class="p-2 rounded-lg bg-emerald-50 text-emerald-600">🥇</span>
        </div>
        <div class="text-lg font-black text-slate-800 truncate"><?php echo htmlspecialchars($bestStore['name'] ?? '-'); ?></div>
        <div class="mt-2 text-[11px] text-emerald-600 font-bold flex items-center gap-1">
            امتیاز برتر: <?php echo $bestStore['overall_vm_score'] ?? 0; ?>/۱۰۰
        </div>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow-md transition">
        <div class="flex items-center justify-between text-slate-500 text-xs font-bold mb-3">
            <span>نیازمند بهینه‌سازی فوری</span>
            <span class="p-2 rounded-lg bg-amber-50 text-amber-600">⚠️</span>
        </div>
        <div class="text-lg font-black text-slate-800 truncate"><?php echo htmlspecialchars($worstStore['name'] ?? '-'); ?></div>
        <div class="mt-2 text-[11px] text-amber-600 font-bold flex items-center gap-1">
            امتیاز: <?php echo $worstStore['overall_vm_score'] ?? 0; ?> (نیازمند ریدیزاین فصلی)
        </div>
    </div>
</div>

<!-- Stores Comparison Table -->
<div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden mb-6">
    <div class="p-5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-base font-black text-slate-800 flex items-center gap-2">
                <span>🏬</span>
                <span>جدول عملکرد شعب پروژه <?php echo htmlspecialchars($project['name']); ?></span>
            </h3>
            <p class="text-xs text-slate-400 mt-1">تحلیل یکپارچه چیدمان، پاخور، نرخ تبدیل و پیشنهادات هوش مصنوعی</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?php echo vm_url('stores'); ?>" class="px-3.5 py-1.5 text-xs font-bold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-xl transition">
                مدیریت همه شعب ←
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-100">
                <tr>
                    <th class="p-4">نام شعبه</th>
                    <th class="p-4">نوع فروشگاه</th>
                    <th class="p-4">مساحت / طبقات</th>
                    <th class="p-4">مدیریت شعبه</th>
                    <th class="p-4">بازدید امروز</th>
                    <th class="p-4">فروش امروز</th>
                    <th class="p-4">امتیاز VM</th>
                    <th class="p-4">وضعیت هوش مصنوعی</th>
                    <th class="p-4 text-center">عملیات اختصاصی</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php foreach ($allStores as $s): ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="p-4">
                        <div class="font-black text-slate-800 text-sm"><?php echo htmlspecialchars($s['name']); ?></div>
                        <div class="text-[11px] text-slate-400 mt-0.5 truncate max-w-xs"><?php echo htmlspecialchars($s['location']); ?></div>
                    </td>
                    <td class="p-4">
                        <?php
                        $typeClass = 'bg-slate-100 text-slate-700';
                        if ($s['store_type'] === 'Flagship') $typeClass = 'bg-purple-100 text-purple-700 border border-purple-200';
                        if ($s['store_type'] === 'Franchise') $typeClass = 'bg-blue-100 text-blue-700 border border-blue-200';
                        if ($s['store_type'] === 'Outlet') $typeClass = 'bg-emerald-100 text-emerald-700 border border-emerald-200';
                        if ($s['store_type'] === 'Temporary') $typeClass = 'bg-amber-100 text-amber-700 border border-amber-200';
                        ?>
                        <span class="inline-block px-2.5 py-1 rounded-lg text-[10px] font-black <?php echo $typeClass; ?>">
                            <?php echo htmlspecialchars($s['store_type']); ?>
                        </span>
                    </td>
                    <td class="p-4">
                        <div class="font-bold"><?php echo $s['area']; ?> مترمربع</div>
                        <div class="text-[10px] text-slate-400"><?php echo $s['floor_count']; ?> طبقه</div>
                    </td>
                    <td class="p-4 font-medium text-slate-600">
                        <?php echo htmlspecialchars($s['manager']); ?>
                    </td>
                    <td class="p-4 font-mono font-bold text-slate-800">
                        <?php if (isset($s['today_visitors']) && $s['today_visitors'] !== null): ?>
                            <?php echo number_format($s['today_visitors']); ?> <span class="text-[10px] font-normal text-slate-400">نفر</span>
                        <?php else: ?>
                            <span class="text-xs font-normal text-slate-400">بدون دیتا</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-4 font-bold text-emerald-600">
                        <?php if (isset($s['today_sales']) && $s['today_sales'] !== null): ?>
                            <?php echo number_format($s['today_sales']); ?> <span class="text-[10px] font-normal text-slate-400">تومان</span>
                        <?php else: ?>
                            <span class="text-xs font-normal text-slate-400">بدون دیتا</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-4">
                        <?php if ($s['overall_vm_score'] !== null): ?>
                        <div class="flex items-center gap-2">
                            <span class="font-black text-sm text-indigo-600"><?php echo $s['overall_vm_score']; ?></span>
                            <div class="w-16 bg-slate-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: <?php echo min(100, $s['overall_vm_score']); ?>%"></div>
                            </div>
                        </div>
                        <?php else: ?>
                            <span class="text-xs font-normal text-slate-400">بدون دیتا</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-4">
                        <?php
                        $sRecs = VmAI::getRecommendations($s['id']);
                        $sRecCount = count($sRecs);
                        ?>
                        <span class="inline-flex items-center gap-1 text-[11px] font-bold <?php echo $sRecCount > 0 ? 'text-emerald-600 bg-emerald-50' : 'text-slate-500 bg-slate-100'; ?> px-2 py-0.5 rounded-md">
                            <span>✨</span> <?php echo $sRecCount > 0 ? ($sRecCount . ' پیشنهاد هوشمند') : 'بدون پیشنهاد'; ?>
                        </span>
                    </td>
                    <td class="p-4 text-center">
                        <div class="flex items-center justify-center gap-1.5">
                            <a href="<?php echo vm_url('store', ['store' => $s['slug']]); ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold transition">
                                داشبورد شعبه
                            </a>
                            <a href="<?php echo vm_url('studio', ['store' => $s['slug']]); ?>" class="px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition">
                                استودیو VM
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
