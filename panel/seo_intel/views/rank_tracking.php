<?php
/**
 * View: Daily Rank Tracking & Average Position Analysis
 * Meets Requirements 6 & 7:
 * - Daily keyword position tracking table
 * - Schema: [Date, Business, Keyword, URL, Avg Position, Clicks, Impressions, CTR, Position Change]
 * - Average Position Calculation: Daily, Weekly, Monthly across Keyword, Category, Landing Page, Business
 */

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$ranks = SeoDB::getRankHistory($currentBusiness['id'], $filter, $search);

// Calculation params
$calcPeriod = $_GET['calc_period'] ?? 'daily';
$calcGroupBy = $_GET['calc_group'] ?? 'keyword';
$avgPositions = SeoDB::calculateAveragePositions($currentBusiness['id'], $calcPeriod, $calcGroupBy);
?>

<!-- Header Banner -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4" id="card-rank-header">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 rounded-md bg-indigo-50 text-indigo-700 text-xs font-black">Daily Rank Tracker</span>
            <span class="text-xs text-slate-400 font-mono"><?php echo date('Y/m/d'); ?></span>
        </div>
        <h2 class="text-lg font-black text-slate-900">سامانه ردیابی روزانه رتبه‌های گوگل (Rank Tracking System)</h2>
        <p class="text-xs text-slate-500 mt-1">
            پایش دقیق و روزانه جایگاه کلمات کلیدی کسب‌وکار <strong class="text-slate-900"><?php echo htmlspecialchars($currentBusiness['name']); ?></strong> همراه با میانگین‌های روزانه، هفتگی و ماهانه.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <button onclick="document.getElementById('modal-add-rank').classList.remove('hidden')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
            <span>+</span> ثبت رکورد رتبه جدید
        </button>
        <button onclick="triggerSync('google_search_console')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
            <span>🔄</span> همگام‌سازی سرچ کنسول
        </button>
    </div>
</div>

<!-- Section 1: Average Position Calculation Cards (Requirement 7) -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="card-avg-positions">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-4 pb-4 border-b border-slate-100">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>📊</span> محاسبه و تحلیل میانگین رتبه (Average Position Intelligence)
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
                محاسبه خودکار رتبه میانگین در بازه‌های روزانه، هفتگی و ماهانه به تفکیک کلمه، دسته‌بندی موضوعی، لندینگ پیج و کل کسب‌وکار.
            </p>
        </div>

        <!-- Period and Group Selectors -->
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-slate-400 font-bold">بازه:</span>
            <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold">
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=daily&calc_group=<?php echo $calcGroupBy; ?>" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcPeriod === 'daily' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   روزانه
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=weekly&calc_group=<?php echo $calcGroupBy; ?>" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcPeriod === 'weekly' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   هفتگی
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=monthly&calc_group=<?php echo $calcGroupBy; ?>" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcPeriod === 'monthly' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   ماهانه
                </a>
            </div>

            <span class="text-xs text-slate-400 font-bold mr-2">تفکیک بر اساس:</span>
            <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold">
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=<?php echo $calcPeriod; ?>&calc_group=keyword" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcGroupBy === 'keyword' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   کلمه کلیدی
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=<?php echo $calcPeriod; ?>&calc_group=category" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcGroupBy === 'category' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   دسته‌بندی
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=<?php echo $calcPeriod; ?>&calc_group=landing_page" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcGroupBy === 'landing_page' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   صفحه فرود (Landing)
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&calc_period=<?php echo $calcPeriod; ?>&calc_group=business" 
                   class="px-2.5 py-1 rounded-lg transition-all <?php echo $calcGroupBy === 'business' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'; ?>">
                   کل کسب‌وکار
                </a>
            </div>
        </div>
    </div>

    <!-- Average Summary Table -->
    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead class="bg-slate-50/80 text-slate-500 font-bold border-b border-slate-100">
                <tr>
                    <th class="p-3">موجودیت (<?php echo $calcGroupBy === 'category' ? 'دسته‌بندی' : ($calcGroupBy === 'landing_page' ? 'صفحه فرود' : ($calcGroupBy === 'business' ? 'کسب‌وکار' : 'کلمه کلیدی')); ?>)</th>
                    <th class="p-3 text-center">میانگین روزانه</th>
                    <th class="p-3 text-center">میانگین ۷ روز اخیر (هفتگی)</th>
                    <th class="p-3 text-center">میانگین ۳۰ روز (ماهانه)</th>
                    <th class="p-3 text-center">مجموع کلیک</th>
                    <th class="p-3 text-center">مجموع ایمپرشن</th>
                    <th class="p-3 text-center">میانگین CTR</th>
                    <th class="p-3 text-center">وضعیت</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($avgPositions as $ag): 
                    $pos = $calcPeriod === 'daily' ? $ag['daily_avg_position'] : ($calcPeriod === 'weekly' ? $ag['weekly_avg_position'] : $ag['monthly_avg_position']);
                    $badgeBg = $pos <= 3 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($pos <= 10 ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                ?>
                <tr class="hover:bg-slate-50/60 transition-colors">
                    <td class="p-3">
                        <div class="font-bold text-slate-900"><?php echo htmlspecialchars($ag['entity']); ?></div>
                        <?php if (!empty($ag['category']) && $calcGroupBy !== 'category'): ?>
                            <span class="text-[10px] text-slate-400 font-medium"><?php echo htmlspecialchars($ag['category']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3 text-center font-mono font-bold text-slate-700">
                        <?php echo $ag['daily_avg_position']; ?>
                    </td>
                    <td class="p-3 text-center font-mono font-black text-indigo-700">
                        <?php echo $ag['weekly_avg_position']; ?>
                    </td>
                    <td class="p-3 text-center font-mono font-bold text-slate-600">
                        <?php echo $ag['monthly_avg_position']; ?>
                    </td>
                    <td class="p-3 text-center font-mono text-slate-700">
                        <?php echo number_format($ag['total_clicks']); ?>
                    </td>
                    <td class="p-3 text-center font-mono text-slate-500">
                        <?php echo number_format($ag['total_impressions']); ?>
                    </td>
                    <td class="p-3 text-center font-mono text-slate-700">
                        <?php echo $ag['avg_ctr']; ?>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded-md border text-[11px] font-mono font-bold <?php echo $badgeBg; ?>">
                            رتبه <?php echo $pos; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Section 2: Master Daily Rank Tracking Table (Requirement 6) -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm" id="card-rank-table">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>📋</span> جدول ثبت و سابقه روزانه رتبه‌ها (Daily Rank History Table)
            </h3>
            <span class="text-xs text-slate-400">تعداد کل رکوردهای ثبت شده: <?php echo count($ranks); ?> رکورد</span>
        </div>

        <!-- Filter and Search -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex bg-slate-100 p-1 rounded-xl text-xs font-bold">
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=all" 
                   class="px-2.5 py-1 rounded-lg <?php echo $filter === 'all' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600'; ?>">
                   همه
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=opportunity" 
                   class="px-2.5 py-1 rounded-lg <?php echo $filter === 'opportunity' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-600'; ?>">
                   🎯 فرصت رشد (Opportunity)
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=growing" 
                   class="px-2.5 py-1 rounded-lg <?php echo $filter === 'growing' ? 'bg-white text-emerald-700 shadow-xs' : 'text-slate-600'; ?>">
                   📈 صعودی
                </a>
                <a href="?view=rank_tracking&business=<?php echo urlencode($currentBusiness['slug']); ?>&filter=declining" 
                   class="px-2.5 py-1 rounded-lg <?php echo $filter === 'declining' ? 'bg-white text-rose-700 shadow-xs' : 'text-slate-600'; ?>">
                   📉 نزولی
                </a>
            </div>

            <form method="GET" class="flex items-center">
                <input type="hidden" name="view" value="rank_tracking">
                <input type="hidden" name="business" value="<?php echo htmlspecialchars($currentBusiness['slug']); ?>">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="جستجوی کلمه یا آدرس..." 
                       class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-indigo-500 w-40 sm:w-52">
            </form>
        </div>
    </div>

    <!-- The Requested Table Format (Exact Prompt Format) -->
    <div class="overflow-x-auto border border-slate-100 rounded-xl">
        <table class="w-full text-right text-xs">
            <thead class="bg-slate-100/80 text-slate-700 font-black border-b border-slate-200">
                <tr>
                    <th class="p-3">تاریخ (Date)</th>
                    <th class="p-3">کسب‌وکار</th>
                    <th class="p-3">کلمه کلیدی (Keyword)</th>
                    <th class="p-3">آدرس صفحه (Target URL)</th>
                    <th class="p-3 text-center">میانگین رتبه</th>
                    <th class="p-3 text-center">تعداد کلیک</th>
                    <th class="p-3 text-center">نمایش (Impressions)</th>
                    <th class="p-3 text-center">نرخ کلیک (CTR)</th>
                    <th class="p-3 text-center">تغییر رتبه</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($ranks)): ?>
                <tr>
                    <td colspan="9" class="p-6 text-center text-slate-400 font-bold">رکوردی برای این فیلتر یافت نشد.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($ranks as $r): 
                    $change = $r['position_change'] ?? '۰';
                    $isUp = strpos($change, '+') !== false;
                    $isDown = strpos($change, '-') !== false;
                    $changeClass = $isUp ? 'text-emerald-700 bg-emerald-50' : ($isDown ? 'text-rose-700 bg-rose-50' : 'text-slate-600 bg-slate-100');
                ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="p-3 font-mono text-slate-600 whitespace-nowrap">
                        📅 <?php echo htmlspecialchars($r['date']); ?>
                    </td>
                    <td class="p-3 font-bold text-slate-800 whitespace-nowrap">
                        <?php echo htmlspecialchars($r['business']); ?>
                    </td>
                    <td class="p-3">
                        <span class="font-black text-slate-900 block"><?php echo htmlspecialchars($r['keyword']); ?></span>
                        <?php if (!empty($r['category'])): ?>
                            <span class="text-[10px] text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded font-medium"><?php echo htmlspecialchars($r['category']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3 font-mono text-slate-500 text-[11px] dir-ltr text-right max-w-xs truncate" title="<?php echo htmlspecialchars($r['url']); ?>">
                        <?php echo htmlspecialchars($r['url']); ?>
                    </td>
                    <td class="p-3 text-center font-mono font-black text-sm text-slate-900">
                        <?php echo $r['avg_position']; ?>
                    </td>
                    <td class="p-3 text-center font-mono font-bold text-indigo-700">
                        <?php echo number_format($r['clicks']); ?>
                    </td>
                    <td class="p-3 text-center font-mono text-slate-600">
                        <?php echo number_format($r['impressions']); ?>
                    </td>
                    <td class="p-3 text-center font-mono font-bold text-slate-800">
                        <?php echo htmlspecialchars($r['ctr']); ?>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-mono font-black <?php echo $changeClass; ?>">
                            <?php echo $change; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Rank History Entry -->
<div id="modal-add-rank" class="fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-xl">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="text-sm font-black text-slate-900">ثبت رکورد رتبه‌بندی جدید در دیتابیس</h3>
            <button onclick="document.getElementById('modal-add-rank').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form onsubmit="saveRankEntry(event)" class="space-y-3 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">کلمه کلیدی *</label>
                <input type="text" id="new-rank-keyword" required placeholder="مثال: خرید لباس ورزشی" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">آدرس صفحه لندینگ *</label>
                <input type="text" id="new-rank-url" required placeholder="/category/sport-wear" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-mono dir-ltr">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">میانگین رتبه *</label>
                    <input type="number" step="0.1" id="new-rank-pos" required placeholder="4.8" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">دسته‌بندی</label>
                    <input type="text" id="new-rank-cat" placeholder="لباس ورزشی" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تعداد کلیک</label>
                    <input type="number" id="new-rank-clicks" value="340" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">تعداد نمایش (Imp)</label>
                    <input type="number" id="new-rank-imp" value="8200" class="w-full p-2 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 font-mono">
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('modal-add-rank').classList.add('hidden')" class="px-3 py-1.5 bg-slate-100 text-slate-600 rounded-xl font-bold">انصراف</button>
                <button type="submit" class="px-4 py-1.5 bg-indigo-600 text-white rounded-xl font-bold">ذخیره رکورد</button>
            </div>
        </form>
    </div>
</div>

<script>
async function saveRankEntry(e) {
    e.preventDefault();
    const payload = {
        keyword: document.getElementById('new-rank-keyword').value,
        url: document.getElementById('new-rank-url').value,
        avg_position: parseFloat(document.getElementById('new-rank-pos').value),
        category: document.getElementById('new-rank-cat').value,
        clicks: parseInt(document.getElementById('new-rank-clicks').value),
        impressions: parseInt(document.getElementById('new-rank-imp').value)
    };

    try {
        const res = await fetch(`${API_BASE}?action=add_rank_history&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast('رکورد جدید با موفقیت ثبت شد.');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'خطا در ثبت رکورد', 'error');
        }
    } catch (err) {
        showToast('خطای ارتباط با سرور', 'error');
    }
}
</script>
