<?php
/**
 * Visual Merchandising Intelligence - Single Store Live Dashboard
 * URL: /admin/projects/{project}/vm/stores/{store}
 */

$projectId = $project['id'] ?? ($project['slug'] ?? '');
$allStores = VmDB::getStoresByProject($projectId);
$defaultStoreId = !empty($allStores) ? $allStores[0]['id'] : '';
$storeSlug = $_GET['store_id'] ?? ($_GET['store'] ?? $defaultStoreId);
$store = VmDB::getStore($storeSlug, $projectId);
if (!$store && !empty($allStores)) {
    $store = reset($allStores);
}

$layout = VmDB::getStoreLayout($store['id']);
$scores = VmDB::calculateStoreScore($store['id']);
$zones = $scores['zones'] ?? [];
$recs = VmAI::getRecommendations($store['id']);
?>

<!-- Store Breadcrumb & Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
            <a href="<?php echo vm_url('stores'); ?>" class="hover:text-indigo-600 transition">فروشگاه‌ها</a>
            <span>/</span>
            <span class="text-slate-600 font-bold"><?php echo htmlspecialchars($store['name']); ?></span>
        </div>
        <h2 class="text-2xl font-black text-slate-800 flex items-center gap-3">
            <span>🏬</span>
            <span><?php echo htmlspecialchars($store['name']); ?></span>
            <span class="text-xs px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold border border-indigo-100">
                <?php echo htmlspecialchars($store['store_type']); ?>
            </span>
        </h2>
    </div>

    <div class="flex items-center gap-3">
        <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-lg transition flex items-center gap-2">
            <span>📐</span>
            <span>طراحی و ویرایش در VM Studio</span>
        </a>
        <a href="<?php echo vm_url('analytics', ['store' => $store['slug']]); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-bold rounded-xl transition">
            <span>📊</span>
            <span>آنالیز زون‌ها</span>
        </a>
    </div>
</div>

<!-- Live KPI Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">بازدیدکنندگان امروز (Footfall)</span>
        <?php if (isset($store['today_visitors']) && $store['today_visitors'] !== null): ?>
            <div class="text-2xl font-black text-slate-800 font-mono"><?php echo number_format($store['today_visitors']); ?> <span class="text-xs font-normal text-slate-400">نفر</span></div>
            <div class="mt-2 text-[11px] text-emerald-600 font-bold">ثبت شده از سنسور ورودی</div>
        <?php else: ?>
            <div class="text-xl font-bold text-slate-400">بدون دیتا</div>
            <div class="mt-2 text-[11px] text-slate-400">در انتظار اتصال سنسور تردد</div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">فروش ثبت شده امروز</span>
        <?php if (isset($store['today_sales']) && $store['today_sales'] !== null): ?>
            <div class="text-2xl font-black text-emerald-600 font-mono"><?php echo number_format($store['today_sales']); ?> <span class="text-xs font-normal text-slate-400">تومان</span></div>
            <div class="mt-2 text-[11px] text-emerald-600 font-bold">ثبت شده از درگاه صندوق</div>
        <?php else: ?>
            <div class="text-xl font-bold text-slate-400">بدون دیتا</div>
            <div class="mt-2 text-[11px] text-slate-400">در انتظار اتصال وب‌سرویس POS</div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">میانگین مبلغ سبد خرید</span>
        <?php if (isset($store['avg_basket']) && $store['avg_basket'] !== null): ?>
            <div class="text-2xl font-black text-slate-800 font-mono"><?php echo number_format($store['avg_basket']); ?> <span class="text-xs font-normal text-slate-400">تومان</span></div>
            <div class="mt-2 text-[11px] text-slate-500">میانگین فاکتور</div>
        <?php else: ?>
            <div class="text-xl font-bold text-slate-400">بدون دیتا</div>
            <div class="mt-2 text-[11px] text-slate-400">در انتظار تراکنش</div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm">
        <span class="text-xs font-bold text-slate-400 block mb-1">نرخ تبدیل چیدمان به خرید</span>
        <?php if (isset($store['conversion_rate']) && $store['conversion_rate'] !== null): ?>
            <div class="text-2xl font-black text-indigo-600 font-mono"><?php echo $store['conversion_rate']; ?>٪</div>
            <div class="mt-2 text-[11px] text-indigo-600 font-bold">شاخص تبدیل چیدمان</div>
        <?php else: ?>
            <div class="text-xl font-bold text-slate-400">بدون دیتا</div>
            <div class="mt-2 text-[11px] text-slate-400">در انتظار لاگ تردد و فروش</div>
        <?php endif; ?>
    </div>
</div>

<!-- Layout & Zone Intelligence Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Store Plan Summary -->
    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-black text-slate-800">پیش‌نمایش چیدمان و موقعیت آبجکت‌ها</h3>
                <p class="text-xs text-slate-400 mt-0.5">نسخه فعال: <?php echo htmlspecialchars($layout['version'] ?? 'v1.0'); ?> — <?php echo htmlspecialchars($layout['name'] ?? 'چیدمان اصلی'); ?></p>
            </div>
            <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">
                مشاهده در بوم تعاملی ۲D/۳D ←
            </a>
        </div>

        <!-- Mini Canvas Map -->
        <div class="relative w-full h-80 bg-slate-900 rounded-xl overflow-hidden border border-slate-800 p-4 flex items-center justify-center">
            <!-- Background Grid -->
            <div class="absolute inset-0 bg-[radial-gradient(#1e293b_1px,transparent_1px)] [background-size:16px_16px] opacity-40"></div>
            
            <!-- Heatmap / Zone overlays -->
            <div class="absolute top-4 left-4 p-2 bg-slate-800/80 backdrop-blur rounded-lg border border-slate-700 text-[10px] text-slate-300 font-mono">
                Store ID: <?php echo htmlspecialchars($store['id']); ?><br>
                Canvas: <?php echo count($layout['objects'] ?? []); ?> Objects Active
            </div>

            <!-- Mini Representation -->
            <div class="relative z-10 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-500/20 border border-indigo-400/40 text-3xl mb-3 shadow-lg">
                    📐
                </div>
                <div class="text-sm font-black text-white"><?php echo htmlspecialchars($layout['name'] ?? 'چیدمان فروشگاهی'); ?></div>
                <div class="text-xs text-slate-400 mt-1">دارای <?php echo count($layout['objects'] ?? []); ?> المان استقراریافته (رگال، مانکن، قفسه و ورودی)</div>
                <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg transition">
                    <span>ورود به استودیو چیدمان کامل</span>
                    <span>←</span>
                </a>
            </div>
        </div>

        <!-- Top and Low Performance Products -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
            <div class="p-4 rounded-xl bg-emerald-50/60 border border-emerald-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-emerald-800 flex items-center gap-1">
                        <span>🔥</span> کالای برتر در چیدمان (Top Product)
                    </span>
                    <span class="text-[10px] font-mono bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded">Zone A</span>
                </div>
                <div class="font-black text-slate-800 text-sm">کیت اول پرسپولیس ۱۴۰۵</div>
                <div class="text-xs text-slate-500 mt-1">۸۷ عدد فروش ۳۰ روزه — نرخ تبدیل ۱۲٪ روی میز سنتر استیج</div>
            </div>

            <div class="p-4 rounded-xl bg-amber-50/60 border border-amber-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-amber-800 flex items-center gap-1">
                        <span>⚠️</span> کالای کم‌بازده در نقطه کور (Low Performance)
                    </span>
                    <span class="text-[10px] font-mono bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">Zone C</span>
                </div>
                <div class="font-black text-slate-800 text-sm">رگال شماره ۳ در انتهای فروشگاه</div>
                <div class="text-xs text-slate-500 mt-1">فقط ۳ فروش در هفته گذشته به دلیل افت شدید پاخور عابران</div>
            </div>
        </div>
    </div>

    <!-- Store Score & Zone Breakdown -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-800">امتیاز VM فروشگاه</h3>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700">فرمول هوشمند ۵ شاخصه</span>
            </div>

            <div class="text-center p-6 rounded-2xl bg-gradient-to-br from-indigo-500/10 via-purple-500/5 to-slate-50 border border-indigo-100 mb-6">
                <div class="text-5xl font-black text-indigo-700 font-mono tracking-tight"><?php echo $scores['store_score']; ?></div>
                <div class="text-xs font-bold text-slate-500 mt-1">امتیاز کل کیفیت چیدمان (از ۱۰۰)</div>
            </div>

            <h4 class="text-xs font-bold text-slate-700 mb-3">تفکیک امتیازات زون‌ها (Zone Scores):</h4>
            <div class="space-y-3">
                <?php foreach ($zones as $zKey => $z): ?>
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
                    <div class="flex items-center justify-between text-xs font-bold mb-1">
                        <span class="text-slate-800"><?php echo htmlspecialchars($z['name']); ?></span>
                        <span class="text-indigo-600 font-mono"><?php echo $z['vm_score']; ?> / ۱۰۰</span>
                    </div>
                    <div class="w-full bg-slate-200 rounded-full h-1.5 mb-1.5">
                        <div class="bg-indigo-600 h-1.5 rounded-full" style="width: <?php echo min(100, $z['vm_score']); ?>%"></div>
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-slate-400">
                        <span><?php echo htmlspecialchars($z['traffic_label'] ?? 'بدون دیتا'); ?></span>
                        <span>مدت توقف: <?php echo htmlspecialchars($z['dwell_time'] ?? 'بدون دیتا'); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-slate-100">
            <a href="<?php echo vm_url('recommendations', ['store' => $store['slug']]); ?>" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold flex items-center justify-center gap-2 transition">
                <span>🧠</span>
                <span>مشاهده <?php echo count($recs); ?> پیشنهاد هوش مصنوعی برای این شعبه</span>
            </a>
        </div>
    </div>
</div>
