<?php
/**
 * Visual Merchandising Intelligence - Stores Management
 */
$projectId = $project['id'] ?? ($project['slug'] ?? '');
$stores = VmDB::getStoresByProject($projectId);
?>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
            <span>🏪</span>
            <span>فروشگاه‌ها و شعب پروژه <?php echo htmlspecialchars($project['name']); ?></span>
        </h2>
        <p class="text-xs text-slate-500 mt-1">مدیریت شعب، متراژ، نوع فروشگاه و لینک مستقیم به چیدمان بصری اختصاصی</p>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="alert('فرم افزودن شعبه جدید')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-2">
            <span>+</span>
            <span>ثبت شعبه جدید</span>
        </button>
    </div>
</div>

<?php if (empty($stores)): ?>
<div class="bg-white rounded-2xl border border-slate-200 p-12 text-center my-6">
    <div class="w-16 h-16 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-3xl mx-auto mb-4">
        🏬
    </div>
    <h3 class="text-lg font-black text-slate-800 mb-2">هنوز هیچ شعبه‌ای برای این پروژه تعریف نشده است</h3>
    <p class="text-sm text-slate-500 max-w-md mx-auto mb-6">برای طراحی چیدمان و تحلیل داده‌های استودیو، ابتدا شعبه‌های فیزیکی این پروژه را ثبت فرمایید.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($stores as $s): ?>
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition overflow-hidden flex flex-col justify-between">
        <div class="p-6 border-b border-slate-100">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div class="flex-1">
                    <?php
                    $typeClass = 'bg-slate-100 text-slate-700';
                    if ($s['store_type'] === 'Flagship') $typeClass = 'bg-purple-100 text-purple-700 border border-purple-200';
                    if ($s['store_type'] === 'Franchise') $typeClass = 'bg-blue-100 text-blue-700 border border-blue-200';
                    if ($s['store_type'] === 'Outlet') $typeClass = 'bg-emerald-100 text-emerald-700 border border-emerald-200';
                    if ($s['store_type'] === 'Temporary') $typeClass = 'bg-amber-100 text-amber-700 border border-amber-200';
                    ?>
                    <span class="inline-block px-2.5 py-0.5 rounded-md text-[10px] font-black <?php echo $typeClass; ?> mb-2">
                        <?php echo htmlspecialchars($s['store_type']); ?>
                    </span>
                    <h3 class="text-base font-black text-slate-800">
                        <a href="<?php echo vm_url('store', ['store' => $s['slug']]); ?>" class="hover:text-indigo-600 transition">
                            <?php echo htmlspecialchars($s['name']); ?>
                        </a>
                    </h3>
                </div>
                <div class="text-center p-2 rounded-xl bg-indigo-50 border border-indigo-100">
                    <span class="block text-[10px] font-bold text-indigo-500">VM Score</span>
                    <span class="font-black text-base text-indigo-700"><?php echo $s['overall_vm_score'] ?? 'بدون دیتا'; ?></span>
                </div>
            </div>

            <p class="text-xs text-slate-500 mb-4 line-clamp-2 leading-relaxed">
                📍 <?php echo htmlspecialchars($s['address']); ?>
            </p>

            <div class="grid grid-cols-2 gap-2.5 bg-slate-50 rounded-xl p-3 text-xs">
                <div>
                    <span class="text-[10px] text-slate-400 block font-medium">مساحت و طبقات</span>
                    <span class="font-bold text-slate-700"><?php echo $s['area']; ?> متر (<?php echo $s['floor_count']; ?> طبقه)</span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 block font-medium">مدیر شعبه</span>
                    <span class="font-bold text-slate-700 truncate block"><?php echo htmlspecialchars($s['manager']); ?></span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 block font-medium">ساعت کاری</span>
                    <span class="font-bold text-slate-700 text-[11px]"><?php echo htmlspecialchars($s['working_hours']); ?></span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 block font-medium">تاریخ افتتاح</span>
                    <span class="font-bold text-slate-700 text-[11px] font-mono"><?php echo htmlspecialchars($s['opening_date']); ?></span>
                </div>
            </div>
        </div>

        <div class="p-4 bg-slate-50/50 flex items-center justify-between gap-2 border-t border-slate-100">
            <span class="text-[11px] text-slate-500 flex items-center gap-1 font-medium">
                <?php if (!empty($s['cctv_connected'])): ?>
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>سنسور تردد و دوربین (<?php echo $s['cctv_cameras']; ?>)</span>
                <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                    <span>ثبت دستی سنسور</span>
                <?php endif; ?>
            </span>

            <div class="flex items-center gap-2">
                <a href="<?php echo vm_url('store', ['store' => $s['slug']]); ?>" class="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-xl text-xs font-bold transition">
                    داشبورد
                </a>
                <a href="<?php echo vm_url('studio', ['store' => $s['slug']]); ?>" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition">
                    استودیو VM
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
