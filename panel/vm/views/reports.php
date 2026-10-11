<?php
/**
 * Visual Merchandising Intelligence - Reports & Export View
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
$allProducts = VmDB::getProductsForProject($projectId);
$productsBySku = [];
foreach ($allProducts as $p) {
    $productsBySku[$p['sku']] = $p;
}

// Compile stats from actual layout objects
$totalFixtures = 0;
$assignedFixtures = 0;
$totalUnitsOnDisplay = 0;
$fixtureRows = [];

foreach ($objects as $obj) {
    $type = $obj['type'] ?? '';
    if (in_array($type, ['rack', 'shelf', 'display_table', 'wall_shelf', 'mannequin'])) {
        $totalFixtures++;
        $skus = [];
        if (!empty($obj['metadata']['product_skus']) && is_array($obj['metadata']['product_skus'])) {
            $skus = $obj['metadata']['product_skus'];
        } elseif (!empty($obj['metadata']['product_sku'])) {
            $skus = [$obj['metadata']['product_sku']];
        }

        $assignedProds = [];
        foreach ($skus as $s) {
            if (isset($productsBySku[$s])) {
                $p = $productsBySku[$s];
                $totalUnitsOnDisplay += intval($p['stock'] ?? 0);
                $assignedProds[] = $p;
            }
        }

        if (!empty($assignedProds)) {
            $assignedFixtures++;
        }

        $fixtureRows[] = [
            'title' => $obj['title'] ?? 'استند',
            'type' => $type,
            'zone' => $obj['zone'] ?? ($obj['metadata']['zone'] ?? 'عمومی'),
            'products' => $assignedProds
        ];
    }
}
?>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
            <span>📑</span>
            <span>گزارشات اجرایی و مانیتورینگ چیدمان (Executive Reports)</span>
        </h2>
        <p class="text-xs text-slate-500 mt-1">خروجی‌های تحلیلی، مانیتورینگ عملکرد چیدمان، گزارش تخصیص کالا و آماده‌سازی برای جلسات مدیریت</p>
    </div>

    <div class="flex items-center gap-3">
        <select onchange="window.vmNavigate('reports', { store: this.value })" class="bg-white text-slate-800 text-xs font-bold border border-slate-200 rounded-xl px-3 py-2 focus:outline-none cursor-pointer">
            <?php foreach ($allStores as $st): ?>
            <option value="<?php echo $st['slug']; ?>" <?php echo $st['id'] === $store['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($st['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <button onclick="downloadCsvReport()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-2">
            <span>📥</span>
            <span>خروجی اکسل (CSV)</span>
        </button>

        <button onclick="window.print()" class="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-2">
            <span>🖨️</span>
            <span>چاپ گزارش PDF</span>
        </button>
    </div>
</div>

<?php if (empty($objects)): ?>
<div class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center shadow-sm mb-6">
    <div class="text-4xl mb-3">📐</div>
    <h3 class="text-base font-black text-slate-800 mb-2">داده‌ای برای گزارش‌گیری وجود ندارد</h3>
    <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">هیچ المانی در نقشه چیدمان این فروشگاه تعریف نشده است. لطفاً ابتدا در استودیو چیدمان، رگال‌ها و محصولات را بچینید.</p>
    <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2 shadow-lg shadow-indigo-600/20">
        <span>📐</span>
        <span>ورود به استودیو چیدمان (Studio)</span>
    </a>
</div>
<?php else: ?>

<!-- Print-Ready Executive Summary Card -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-8 shadow-sm print:shadow-none print:border-none mb-6">
    <div class="flex items-center justify-between border-b border-slate-200 pb-6 mb-6">
        <div>
            <h1 class="text-xl font-black text-slate-900">گزارش خلاصه وضعیت چیدمان و فروشگاهی (Executive Summary)</h1>
            <p class="text-xs text-slate-500 mt-1">
                پروژه: <strong class="text-slate-700"><?php echo htmlspecialchars($project['name']); ?></strong> — 
                شعبه: <strong class="text-slate-700"><?php echo htmlspecialchars($store['name']); ?></strong> 
                (مساحت: <?php echo $store['area_sqm'] ?? '---'; ?> مترمربع)
            </p>
        </div>
        <div class="text-left font-mono text-xs text-slate-400">
            تاریخ صدور: <?php echo date('Y/m/d'); ?><br>
            سیستم: Visual Merchandising Intelligence
        </div>
    </div>

    <!-- Key Metrics Dashboard -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-center bg-slate-50 rounded-2xl p-6 mb-6">
        <div>
            <span class="text-xs text-slate-400 block font-medium">امتیاز ارزیابی چیدمان</span>
            <?php if ($scores['store_score'] !== null): ?>
                <span class="text-2xl font-black text-indigo-700 font-mono"><?php echo $scores['store_score']; ?>/100</span>
            <?php else: ?>
                <span class="text-sm font-black text-amber-600">بدون دیتا</span>
            <?php endif; ?>
        </div>
        <div>
            <span class="text-xs text-slate-400 block font-medium">فروش ثبت شده روزانه</span>
            <?php if (isset($store['today_sales']) && $store['today_sales'] !== null): ?>
                <span class="text-2xl font-black text-emerald-700 font-mono"><?php echo number_format($store['today_sales']); ?> تومان</span>
            <?php else: ?>
                <span class="text-xs font-bold text-amber-600 block mt-1">بدون دیتا (در انتظار POS)</span>
            <?php endif; ?>
        </div>
        <div>
            <span class="text-xs text-slate-400 block font-medium">تردد ثبت شده روزانه</span>
            <?php if (isset($store['today_visitors']) && $store['today_visitors'] !== null): ?>
                <span class="text-2xl font-black text-slate-800 font-mono"><?php echo number_format($store['today_visitors']); ?> نفر</span>
            <?php else: ?>
                <span class="text-xs font-bold text-amber-600 block mt-1">بدون دیتا (در انتظار سنسور)</span>
            <?php endif; ?>
        </div>
        <div>
            <span class="text-xs text-slate-400 block font-medium">نرخ تبدیل چیدمان</span>
            <?php if (isset($store['conversion_rate']) && $store['conversion_rate'] !== null): ?>
                <span class="text-2xl font-black text-blue-700 font-mono"><?php echo $store['conversion_rate']; ?>%</span>
            <?php else: ?>
                <span class="text-xs font-bold text-slate-400 block mt-1">بدون دیتا</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Layout & Inventory Facts -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 text-xs text-slate-600 border border-slate-100 rounded-xl p-4">
        <div>کل المان‌های جانمایی‌شده: <strong class="font-bold text-slate-900"><?php echo count($objects); ?> عدد</strong></div>
        <div>استندها و رگال‌های تجاری: <strong class="font-bold text-slate-900"><?php echo $totalFixtures; ?> مورد</strong></div>
        <div>استندهای دارای کالا: <strong class="font-bold text-indigo-700"><?php echo $assignedFixtures; ?> از <?php echo $totalFixtures; ?></strong></div>
        <div>مجموع موجودی روی استندها: <strong class="font-bold text-purple-700"><?php echo number_format($totalUnitsOnDisplay); ?> عدد</strong></div>
    </div>

    <!-- Dynamic Executive Narrative -->
    <div class="bg-slate-50/70 border border-slate-200/60 rounded-xl p-4 text-xs text-slate-700 leading-relaxed">
        <strong class="text-slate-900 font-black block mb-1">خلاصه تحلیلی هوش چیدمان:</strong>
        چیدمان شعبه «<?php echo htmlspecialchars($store['name']); ?>» با متراژ مفید <?php echo $store['area_sqm'] ?? '---'; ?> مترمربع دارای <?php echo $totalFixtures; ?> جایگاه تجاری است که <?php echo $assignedFixtures; ?> مورد آن به محصولات فعال تخصیص یافته است.
        <?php if ($scores['has_sales_data'] && $scores['has_traffic_data']): ?>
            امتیاز کلی این شعبه <?php echo $scores['store_score']; ?> از ۱۰۰ بوده و با تردد <?php echo number_format($store['today_visitors']); ?> نفر، فروش روزانه به مبلغ <?php echo number_format($store['today_sales']); ?> تومان ثبت گردیده است.
        <?php else: ?>
            امتیاز مهندسی فیزیکی چیدمان (بر مبنای خطوط دیداری و پایداری موجودی کالاها) برابر با <?php echo $scores['store_score']; ?> از ۱۰۰ محاسبه شده است. به دلیل عدم اتصال وب‌سرویس صندوق فروش (POS) یا سنسورهای تردد، تحلیل‌های ریالی و نرخ تبدیل پس از اتصال درگاه‌های مربوطه تکمیل خواهند شد.
        <?php endif; ?>
    </div>
</div>

<!-- Detailed Fixtures & Product Display Table -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm mb-6">
    <h3 class="text-base font-black text-slate-800 mb-4">فهرست استندها و کالاهای منتسب بر روی نقشه چیدمان</h3>
    
    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs" id="fixtures-table">
            <thead>
                <tr class="border-b border-slate-200 text-slate-400 font-bold">
                    <th class="pb-3 px-3">نام استند / رگال</th>
                    <th class="pb-3 px-3">زون</th>
                    <th class="pb-3 px-3">کالاهای تخصیص‌یافته</th>
                    <th class="pb-3 px-3">کد کالا (SKU)</th>
                    <th class="pb-3 px-3">موجودی انبار</th>
                    <th class="pb-3 px-3">قیمت</th>
                    <th class="pb-3 px-3">وضعیت کالا</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($fixtureRows as $row): ?>
                <tr>
                    <td class="py-3 px-3 font-bold text-slate-800"><?php echo htmlspecialchars($row['title']); ?></td>
                    <td class="py-3 px-3 text-slate-500"><?php echo htmlspecialchars($row['zone']); ?></td>
                    <td class="py-3 px-3">
                        <?php if (empty($row['products'])): ?>
                            <span class="text-amber-600 font-bold">استند خالی (بدون کالا)</span>
                        <?php else: ?>
                            <?php foreach ($row['products'] as $p): ?>
                                <div class="font-bold text-slate-700"><?php echo htmlspecialchars($p['name']); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 font-mono text-slate-500">
                        <?php foreach ($row['products'] as $p): ?>
                            <div><?php echo htmlspecialchars($p['sku']); ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="py-3 px-3 font-mono font-bold">
                        <?php foreach ($row['products'] as $p): ?>
                            <div class="<?php echo ($p['stock'] <= 0) ? 'text-red-600 font-black' : (($p['stock'] < 5) ? 'text-amber-600' : 'text-slate-800'); ?>">
                                <?php echo $p['stock']; ?> عدد
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td class="py-3 px-3 font-mono text-slate-600">
                        <?php foreach ($row['products'] as $p): ?>
                            <div><?php echo $p['price_formatted'] ?? number_format($p['price']) . ' تومان'; ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="py-3 px-3">
                        <?php if (empty($row['products'])): ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700">خالی</span>
                        <?php else: ?>
                            <?php foreach ($row['products'] as $p): ?>
                                <div>
                                    <?php if ($p['stock'] <= 0): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-50 text-red-700">اتمام موجودی</span>
                                    <?php elseif ($p['stock'] < 5): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700">موجودی کم</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700">موجود</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function downloadCsvReport() {
    let csv = "\uFEFFنام استند,زون,نام کالا,کد کالا,موجودی,قیمت\n";
    const table = document.getElementById('fixtures-table');
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(tr => {
        const cols = tr.querySelectorAll('td');
        const title = cols[0]?.innerText.trim().replace(/,/g, ' ');
        const zone = cols[1]?.innerText.trim().replace(/,/g, ' ');
        const prod = cols[2]?.innerText.trim().replace(/,/g, ' ');
        const sku = cols[3]?.innerText.trim().replace(/,/g, ' ');
        const stock = cols[4]?.innerText.trim().replace(/,/g, ' ');
        const price = cols[5]?.innerText.trim().replace(/,/g, ' ');
        csv += `"${title}","${zone}","${prod}","${sku}","${stock}","${price}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'vm_report_<?php echo $store['slug']; ?>_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php endif; ?>
