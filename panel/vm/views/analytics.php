<?php
/**
 * Visual Merchandising Intelligence - Layout Analytics View
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
$zones = $scores['zones'] ?? [];
$layout = VmDB::getStoreLayout($store['id'] ?? '');
$objects = $layout['objects'] ?? [];
$allProducts = VmDB::getProductsForProject($projectId);
$productsBySku = [];
foreach ($allProducts as $p) {
    $productsBySku[$p['sku']] = $p;
}
?>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
            <span>📊</span>
            <span>تحلیل چیدمان و رفتار خریداران (Layout Analytics)</span>
        </h2>
        <p class="text-xs text-slate-500 mt-1">بررسی جریان حرکت مشتریان (Customer Flow)، زوایای دید، پراکندگی موجودی و تحلیل سبدهای مکمل</p>
    </div>

    <div class="flex items-center gap-3">
        <select onchange="window.vmNavigate('analytics', { store: this.value })" class="bg-white text-slate-800 text-xs font-bold border border-slate-200 rounded-xl px-3 py-2 focus:outline-none cursor-pointer">
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
    <h3 class="text-base font-black text-slate-800 mb-2">داده‌ای برای تحلیل چیدمان وجود ندارد</h3>
    <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">هیچ المانی در نقشه چیدمان این فروشگاه تعریف نشده است. جهت مشاهده تحلیل‌ها، ابتدا در استودیو چیدمان، رگال‌ها و محصولات را بچینید.</p>
    <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2 shadow-lg shadow-indigo-600/20">
        <span>📐</span>
        <span>ورود به استودیو چیدمان (Studio)</span>
    </a>
</div>
<?php else: ?>

<!-- Integration Status Alert if POS or Sensors are not connected -->
<?php if (!$scores['has_sales_data'] || !$scores['has_traffic_data']): ?>
<div class="bg-amber-50/80 border border-amber-200/80 rounded-2xl p-4 mb-6 flex items-center justify-between gap-4 text-xs">
    <div class="flex items-center gap-3">
        <span class="text-xl">⚠️</span>
        <div>
            <span class="font-black text-amber-900 block">وضعیت اتصال داده‌های زنده فروشگاهی:</span>
            <span class="text-amber-800">
                <?php 
                $missing = [];
                if (!$scores['has_traffic_data']) $missing[] = 'سنسور تردد پاخور (Footfall)';
                if (!$scores['has_sales_data']) $missing[] = 'وب‌سرویس صندوق فروش (POS)';
                echo implode(' و ', $missing) . ' به این شعبه متصل نیستند. شاخص‌های فیزیکی، دیداری و موجودی از چیدمان استودیو محاسبه شده‌اند.';
                ?>
            </span>
        </div>
    </div>
    <span class="text-[11px] font-bold text-amber-700 bg-amber-100 px-3 py-1 rounded-lg shrink-0">محاسبه بر مبنای چیدمان فیزیکی</span>
</div>
<?php endif; ?>

<!-- Key Analytic Dimensions -->
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block mb-1">مسیر حرکت طبیعی (Flow)</span>
        <?php if ($scores['breakdown']['customer_flow'] !== null): ?>
            <div class="text-xl font-black text-indigo-600 font-mono"><?php echo $scores['breakdown']['customer_flow']; ?>/۱۰۰</div>
            <span class="text-[10px] text-slate-500 mt-1 block">محاسبه از سنسور تردد</span>
        <?php else: ?>
            <div class="text-sm font-black text-amber-600 my-1">بدون دیتا</div>
            <span class="text-[10px] text-slate-400 block">در انتظار سنسور تردد</span>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block mb-1">بهره‌وری فروش (Sales)</span>
        <?php if ($scores['breakdown']['sales_performance'] !== null): ?>
            <div class="text-xl font-black text-emerald-600 font-mono"><?php echo $scores['breakdown']['sales_performance']; ?>/۱۰۰</div>
            <span class="text-[10px] text-slate-500 mt-1 block">تراکم ریالی در هر متر</span>
        <?php else: ?>
            <div class="text-sm font-black text-amber-600 my-1">بدون دیتا</div>
            <span class="text-[10px] text-slate-400 block">در انتظار وب‌سرویس POS</span>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block mb-1">خط دید از ورودی (Visibility)</span>
        <div class="text-xl font-black text-blue-600 font-mono"><?php echo $scores['breakdown']['product_visibility'] ?? '۰'; ?>/۱۰۰</div>
        <span class="text-[10px] text-slate-500 mt-1 block">ارزیابی موانع و نقاط کانونی</span>
    </div>

    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block mb-1">موجودی در معرض دید (Stock)</span>
        <div class="text-xl font-black text-purple-600 font-mono"><?php echo $scores['breakdown']['stock_availability'] ?? '۰'; ?>/۱۰۰</div>
        <span class="text-[10px] text-slate-500 mt-1 block">تخصیص و عمق موجودی انبار</span>
    </div>

    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-sm">
        <span class="text-[11px] font-bold text-slate-400 block mb-1">انطباق با ترند (Trend)</span>
        <?php if ($scores['breakdown']['trend_score'] !== null): ?>
            <div class="text-xl font-black text-amber-600 font-mono"><?php echo $scores['breakdown']['trend_score']; ?>/۱۰۰</div>
            <span class="text-[10px] text-slate-500 mt-1 block">شاخص پایداری کاتالوگ</span>
        <?php else: ?>
            <div class="text-sm font-black text-slate-400 my-1">بدون دیتا</div>
            <span class="text-[10px] text-slate-400 block">نیازمند تخصیص کالا</span>
        <?php endif; ?>
    </div>
</div>

<!-- Zone Intelligence Cards -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-base font-black text-slate-800">امتیازات و کارایی تفکیکی زون‌های فروشگاه (Zone Performance)</h3>
            <p class="text-xs text-slate-400 mt-0.5">محاسبه پویا بر مبنای المان‌های جانمایی شده در هر بخش سالن</p>
        </div>
        <span class="text-xs font-mono font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-lg">تعداد کل المان‌ها: <?php echo count($objects); ?></span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <?php foreach ($zones as $zKey => $z): ?>
        <div class="p-5 rounded-2xl border border-slate-100 bg-slate-50/60 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="font-black text-sm text-slate-800"><?php echo htmlspecialchars($z['name']); ?></span>
                    <span class="font-mono font-black text-sm text-indigo-600"><?php echo $z['vm_score']; ?></span>
                </div>

                <div class="space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>تعداد استند و رگال:</span>
                        <span class="font-mono font-bold"><?php echo $z['fixtures_count']; ?> مورد</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>استندهای دارای کالا:</span>
                        <span class="font-mono font-bold"><?php echo $z['assigned_count']; ?> از <?php echo $z['fixtures_count']; ?></span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>موجودی کل در این زون:</span>
                        <span class="font-mono font-bold text-purple-700"><?php echo number_format($z['total_stock']); ?> عدد</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>امتیاز رویت‌پذیری فیزیکی:</span>
                        <span class="font-mono font-bold text-blue-600"><?php echo $z['visibility_score']; ?>%</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>امتیاز تردد پاخور:</span>
                        <?php if ($z['traffic_score'] !== null): ?>
                            <span class="font-mono font-bold text-indigo-600"><?php echo $z['traffic_score']; ?>%</span>
                        <?php else: ?>
                            <span class="font-bold text-amber-600 text-[11px]">بدون دیتا (نیازمند سنسور)</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>امتیاز فروش ریالی:</span>
                        <?php if ($z['sales_score'] !== null): ?>
                            <span class="font-mono font-bold text-emerald-600"><?php echo $z['sales_score']; ?>%</span>
                        <?php else: ?>
                            <span class="font-bold text-amber-600 text-[11px]">بدون دیتا (نیازمند POS)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-200/60 text-[11px] text-slate-500 font-medium">
                وضعیت تردد: <span class="font-bold <?php echo $z['traffic_score'] !== null ? 'text-emerald-700' : 'text-amber-700'; ?>"><?php echo htmlspecialchars($z['traffic_label']); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Cross Selling Opportunities Calculated from Real Layout & Products -->
<?php
// Extract commercial fixtures that have assigned products
$commercialWithProducts = [];
foreach ($objects as $obj) {
    $skus = [];
    if (!empty($obj['metadata']['product_skus']) && is_array($obj['metadata']['product_skus'])) {
        $skus = $obj['metadata']['product_skus'];
    } elseif (!empty($obj['metadata']['product_sku'])) {
        $skus = [$obj['metadata']['product_sku']];
    }
    if (!empty($skus)) {
        foreach ($skus as $s) {
            if (isset($productsBySku[$s])) {
                $commercialWithProducts[] = [
                    'obj' => $obj,
                    'prod' => $productsBySku[$s]
                ];
            }
        }
    }
}
?>

<div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-base font-black text-slate-800">تحلیل سبد خرید مکمل و همجواری فیزیکی (Cross-Selling Heat)</h3>
        <span class="text-xs text-slate-400 font-mono">محاسبه فاصله بر مبنای نقشه فروشگاه</span>
    </div>
    <p class="text-xs text-slate-400 mb-4 leading-relaxed">
        تحلیل فاصله فیزیکی کالاهای موجود در چیدمان فعلی. برای محاسبه دقیق بر اساس فاکتورهای واقعی صندوق، پس از اتصال وب‌سرویس POS، آمار تراکنش‌ها همگام خواهد شد.
    </p>

    <?php if (count($commercialWithProducts) < 2): ?>
    <div class="bg-slate-50 rounded-xl p-6 text-center border border-slate-100 text-xs text-slate-500">
        برای مشاهده تحلیل سبد مکمل، لطفاً حداقل دو کالا را روی استندهای مختلف در <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="text-indigo-600 font-bold hover:underline">استودیو چیدمان</a> قرار دهید.
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php
        // Compare pair of fixtures
        $pairsCount = 0;
        for ($i = 0; $i < count($commercialWithProducts) && $pairsCount < 3; $i++) {
            for ($j = $i + 1; $j < count($commercialWithProducts) && $pairsCount < 3; $j++) {
                $item1 = $commercialWithProducts[$i];
                $item2 = $commercialWithProducts[$j];
                
                // Calculate physical distance in meters (1 px ~ 0.05 meter)
                $dx = ($item1['obj']['position']['x'] ?? 0) - ($item2['obj']['position']['x'] ?? 0);
                $dy = ($item1['obj']['position']['y'] ?? 0) - ($item2['obj']['position']['y'] ?? 0);
                $distPx = sqrt($dx * $dx + $dy * $dy);
                $distMeters = round($distPx * 0.05, 1);
                $pairsCount++;
                ?>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">🛍️ ➕ 🏷️</span>
                        <div>
                            <div class="text-xs font-black text-slate-800">
                                <?php echo htmlspecialchars($item1['prod']['name']); ?> (استند: <?php echo htmlspecialchars($item1['obj']['title']); ?>) 
                                ➕ 
                                <?php echo htmlspecialchars($item2['prod']['name']); ?> (استند: <?php echo htmlspecialchars($item2['obj']['title']); ?>)
                            </div>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                فاصله فیزیکی فعلی در نقشه: <span class="font-bold text-slate-700 font-mono"><?php echo $distMeters; ?> متر</span> — 
                                موجودی انبار: <?php echo $item1['prod']['stock']; ?> و <?php echo $item2['prod']['stock']; ?> عدد
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold <?php echo $distMeters > 5 ? 'text-amber-700 bg-amber-50' : 'text-emerald-700 bg-emerald-50'; ?> px-2.5 py-1 rounded-lg">
                            <?php echo $distMeters > 5 ? 'فاصله زیاد (پیشنهاد همجواری)' : 'همجواری مطلوب'; ?>
                        </span>
                        <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition">
                            مشاهده در استودیو
                        </a>
                    </div>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>
