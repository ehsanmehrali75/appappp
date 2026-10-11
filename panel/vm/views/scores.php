<?php
/**
 * Visual Merchandising Intelligence - Store Score View
 */
$projectId = $project['id'] ?? ($project['slug'] ?? '');
$allStores = VmDB::getStoresByProject($projectId);
$defaultStoreId = !empty($allStores) ? $allStores[0]['id'] : '';
$storeSlug = $_GET['store'] ?? ($_GET['store_id'] ?? $defaultStoreId);
$store = VmDB::getStore($storeSlug, $projectId);
if (!$store && !empty($allStores)) {
    $store = reset($allStores);
}
$scores = VmDB::calculateStoreScore($store['id'] ?? '');
$layout = VmDB::getStoreLayout($store['id'] ?? '');
$objects = $layout['objects'] ?? [];
?>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
            <span>🏆</span>
            <span>سیستم امتیازدهی چندبعدی فروشگاه (Store Score)</span>
        </h2>
        <p class="text-xs text-slate-500 mt-1">فرمول استاندارد VM Score بر مبنای ترکیبی از جریان مشتری، فروش، دید بصری، موجودی و ترند</p>
    </div>

    <div class="flex items-center gap-3">
        <select onchange="window.vmNavigate('scores', { store: this.value })" class="bg-white text-slate-800 text-xs font-bold border border-slate-200 rounded-xl px-3 py-2 focus:outline-none cursor-pointer">
            <?php foreach ($allStores as $st): ?>
            <option value="<?php echo $st['slug']; ?>" <?php echo $st['id'] === $store['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($st['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (empty($objects)): ?>
<div class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center shadow-sm">
    <div class="text-4xl mb-3">📐</div>
    <h3 class="text-base font-black text-slate-800 mb-2">داده‌ای برای محاسبه امتیاز فروشگاه وجود ندارد</h3>
    <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">هیچ المانی در نقشه چیدمان این فروشگاه تعریف نشده است. جهت محاسبه امتیاز چیدمان، ابتدا در استودیو چیدمان، رگال‌ها و محصولات را بچینید.</p>
    <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2 shadow-lg shadow-indigo-600/20">
        <span>📐</span>
        <span>ورود به استودیو چیدمان (Studio)</span>
    </a>
</div>
<?php else: ?>

<?php if (!$scores['has_sales_data'] || !$scores['has_traffic_data']): ?>
<div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl p-4 mb-6 flex items-center justify-between gap-4 text-xs">
    <div class="flex items-center gap-3">
        <span class="text-xl">ℹ️</span>
        <div>
            <span class="font-black text-indigo-950 block">محاسبه بر مبنای مهندسی فیزیکی و بصری چیدمان (Structural VM):</span>
            <span class="text-indigo-800">
                شاخص‌های دید بصری (Visibility) و موجودی کالا (Stock) مستقیماً از نقشه فعال چیدمان استخراج شدند. شاخص‌های تردد و فروش پس از اتصال سنسورها و POS به امتیاز نهایی اضافه می‌شوند.
            </span>
        </div>
    </div>
    <span class="text-[11px] font-bold text-indigo-700 bg-indigo-100/80 px-3 py-1 rounded-lg shrink-0">دیتای واقعی چیدمان</span>
</div>
<?php endif; ?>

<!-- Primary Score Dashboard Matching User Specification -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-gradient-to-br from-indigo-900 to-slate-900 text-white rounded-2xl p-6 shadow-xl border border-indigo-800/40">
        <span class="text-xs font-bold text-indigo-300 block mb-1">Store Score (امتیاز چیدمان فروشگاه)</span>
        <div class="text-5xl font-black font-mono tracking-tight text-white my-2"><?php echo $scores['store_score']; ?><span class="text-xl text-indigo-400 font-normal">/100</span></div>
        <div class="text-[11px] font-bold <?php echo ($scores['store_score'] >= 75) ? 'text-emerald-400' : 'text-amber-400'; ?>">
            وضعیت: <?php echo ($scores['store_score'] >= 75) ? 'بسیار مطلوب (استاندارد)' : 'نیازمند بهینه‌سازی'; ?>
        </div>
    </div>

    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">VM Quality (کیفیت چیدمان بصری)</span>
        <div class="text-4xl font-black text-slate-800 font-mono my-2"><?php echo $scores['vm_quality']; ?></div>
        <div class="text-[11px] text-slate-500">بر مبنای رویت‌پذیری و زاویه استندها</div>
    </div>

    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">Sales Optimization (بهینه‌سازی فروش)</span>
        <?php if ($scores['sales_optimization'] !== null): ?>
            <div class="text-4xl font-black text-emerald-600 font-mono my-2"><?php echo $scores['sales_optimization']; ?></div>
            <div class="text-[11px] text-slate-500">بر مبنای تراکم فروش و موجودی قفسه‌ها</div>
        <?php else: ?>
            <div class="text-2xl font-black text-amber-600 my-2">بدون دیتا</div>
            <div class="text-[11px] text-slate-400">در انتظار اتصال وب‌سرویس POS</div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">Customer Journey (سفر مشتری)</span>
        <div class="text-4xl font-black text-blue-600 font-mono my-2"><?php echo $scores['customer_journey']; ?></div>
        <div class="text-[11px] text-slate-500">بر مبنای سهولت تردد و پهنای راهروها</div>
    </div>
</div>

<!-- Formula Breakdown Card -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-base font-black text-slate-800">فرمول و وزن شاخص‌های محاسبه امتیاز</h3>
            <p class="text-xs text-slate-400 mt-1">فرمول مصوب: <code>VM Score = 30% Customer Flow + 25% Sales Performance + 20% Product Visibility + 15% Stock Availability + 10% Trend Score</code></p>
        </div>
        <span class="text-xs font-mono font-bold bg-slate-100 text-slate-700 px-3 py-1 rounded-lg">الگوریتم v2.4</span>
    </div>

    <div class="space-y-4">
        <!-- 30% Customer Flow -->
        <div>
            <div class="flex justify-between text-xs font-bold mb-1">
                <span class="text-slate-700">۱. جریان حرکت مشتری (Customer Flow) — ۳۰٪ وزن</span>
                <?php if ($scores['breakdown']['customer_flow'] !== null): ?>
                    <span class="font-mono text-indigo-600"><?php echo $scores['breakdown']['customer_flow']; ?>/۱۰۰</span>
                <?php else: ?>
                    <span class="text-amber-600 text-[11px]">بدون دیتا (در انتظار سنسور تردد)</span>
                <?php endif; ?>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2">
                <div class="bg-indigo-600 h-2 rounded-full" style="width: <?php echo $scores['breakdown']['customer_flow'] ?? 0; ?>%"></div>
            </div>
        </div>

        <!-- 25% Sales Performance -->
        <div>
            <div class="flex justify-between text-xs font-bold mb-1">
                <span class="text-slate-700">۲. عملکرد ریالی و تعدادی فروش (Sales Performance) — ۲۵٪ وزن</span>
                <?php if ($scores['breakdown']['sales_performance'] !== null): ?>
                    <span class="font-mono text-emerald-600"><?php echo $scores['breakdown']['sales_performance']; ?>/۱۰۰</span>
                <?php else: ?>
                    <span class="text-amber-600 text-[11px]">بدون دیتا (در انتظار وب‌سرویس POS)</span>
                <?php endif; ?>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2">
                <div class="bg-emerald-600 h-2 rounded-full" style="width: <?php echo $scores['breakdown']['sales_performance'] ?? 0; ?>%"></div>
            </div>
        </div>

        <!-- 20% Product Visibility -->
        <div>
            <div class="flex justify-between text-xs font-bold mb-1">
                <span class="text-slate-700">۳. رویت‌پذیری کالا از خط دید ورودی (Product Visibility) — ۲۰٪ وزن</span>
                <span class="font-mono text-blue-600"><?php echo $scores['breakdown']['product_visibility'] ?? 0; ?>/۱۰۰</span>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2">
                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $scores['breakdown']['product_visibility'] ?? 0; ?>%"></div>
            </div>
        </div>

        <!-- 15% Stock Availability -->
        <div>
            <div class="flex justify-between text-xs font-bold mb-1">
                <span class="text-slate-700">۴. پایداری موجودی استندها (Stock Availability) — ۱۵٪ وزن</span>
                <span class="font-mono text-purple-600"><?php echo $scores['breakdown']['stock_availability'] ?? 0; ?>/۱۰۰</span>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2">
                <div class="bg-purple-600 h-2 rounded-full" style="width: <?php echo $scores['breakdown']['stock_availability'] ?? 0; ?>%"></div>
            </div>
        </div>

        <!-- 10% Trend Score -->
        <div>
            <div class="flex justify-between text-xs font-bold mb-1">
                <span class="text-slate-700">۵. هماهنگی با ترند و مناسبت‌ها (Trend Score) — ۱۰٪ وزن</span>
                <?php if ($scores['breakdown']['trend_score'] !== null): ?>
                    <span class="font-mono text-amber-600"><?php echo $scores['breakdown']['trend_score']; ?>/۱۰۰</span>
                <?php else: ?>
                    <span class="text-slate-400 text-[11px]">بدون دیتا (نیازمند تخصیص کالا)</span>
                <?php endif; ?>
            </div>
            <div class="w-full bg-slate-100 rounded-full h-2">
                <div class="bg-amber-600 h-2 rounded-full" style="width: <?php echo $scores['breakdown']['trend_score'] ?? 0; ?>%"></div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
