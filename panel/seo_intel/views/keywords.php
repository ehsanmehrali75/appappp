<?php
/**
 * View: Keyword Intelligence
 * Real-time Rank Tracker, Opportunity Keywords, Cannibalization Matrix & Growth
 */

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$keywordsList = SeoDB::getKeywords($currentBusiness['id'], $filter, $search);
?>

<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-keywords-header">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-5 border-b border-slate-100">
        <div>
            <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
                <span>🎯</span> هوش کلمات کلیدی (Keyword Intelligence & Rank Tracker)
            </h2>
            <p class="text-xs text-slate-400 mt-1">رهگیری روزانه رتبه‌های گوگل، کلمات پرفرصت آستانه صفحه اول و کشف تداخل‌ها (Cannibalization)</p>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openAddKeywordModal()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm shadow-indigo-200 cursor-pointer">
                <span>➕</span> افزودن کلمه به ردیاب
            </button>
            <button onclick="triggerSync('google_search_console')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer">
                <span>🔄</span> بروزرسانی از سرچ کنسول
            </button>
        </div>
    </div>

    <!-- Filter Tabs & Search Bar -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mt-5">
        <div class="flex flex-wrap items-center gap-1.5 p-1 bg-slate-100/80 rounded-xl text-xs font-bold">
            <a href="?view=keywords&filter=all&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'all' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                همه کلمات (<?php echo count(SeoDB::getKeywords($currentBusiness['id'])); ?>)
            </a>
            <a href="?view=keywords&filter=opportunity&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'opportunity' ? 'bg-white text-amber-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>" title="نمایش بالا (> ۳۰۰۰)، رتبه ۵ تا ۲۰، نرخ CTR کمتر از ۴.۵٪">
                🎯 کلمات پرفرصت (Opportunity)
            </a>
            <a href="?view=keywords&filter=growth&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'growth' ? 'bg-white text-emerald-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                📈 در حال رشد (Growing)
            </a>
            <a href="?view=keywords&filter=lost&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'lost' ? 'bg-white text-rose-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                📉 افت کرده (Declining)
            </a>
            <a href="?view=keywords&filter=cannibalization&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'cannibalization' ? 'bg-white text-purple-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                ⚠️ هم‌پوشانی (Cannibalization)
            </a>
            <a href="?view=keywords&filter=top10&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $filter === 'top10' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500 hover:text-slate-800'; ?>">
                ⭐ تاپ ۱۰ گوگل
            </a>
        </div>

        <!-- Search Input Form -->
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="view" value="keywords">
            <input type="hidden" name="business" value="<?php echo htmlspecialchars($currentBusiness['slug']); ?>">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="جستجو در کلمات یا صفحات..." class="w-64 px-3.5 py-1.5 pr-8 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-indigo-500 transition-all">
                <span class="absolute right-2.5 top-2 text-xs text-slate-400">🔍</span>
            </div>
            <button type="submit" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition-all">
                فیلتر
            </button>
            <?php if (!empty($search)): ?>
                <a href="?view=keywords&filter=<?php echo urlencode($filter); ?>&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="text-xs text-rose-600 font-bold hover:underline">حذف جستجو</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Keywords Table -->
<div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden" id="table-keywords-container">
    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead>
                <tr class="bg-slate-50/70 border-b border-slate-200/80 text-slate-500">
                    <th class="py-3.5 px-4 font-black">کلمه کلیدی / هدف جستجو</th>
                    <th class="py-3.5 px-3 font-black text-center">رتبه فعلی</th>
                    <th class="py-3.5 px-3 font-black text-center">تغییر دوره</th>
                    <th class="py-3.5 px-3 font-black text-center">حجم ماهانه</th>
                    <th class="py-3.5 px-3 font-black text-center">کلیک ۳۰ روز</th>
                    <th class="py-3.5 px-3 font-black text-center">ایمپرشن</th>
                    <th class="py-3.5 px-3 font-black text-center">نرخ CTR</th>
                    <th class="py-3.5 px-3 font-black text-center">قصد کاربر (Intent)</th>
                    <th class="py-3.5 px-3 font-black text-center">پتانسیل رشد</th>
                    <th class="py-3.5 px-4 font-black text-left">اقدام هوشمند</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($keywordsList)): ?>
                <tr>
                    <td colspan="10" class="py-12 text-center text-slate-400">
                        هیچ کلمه کلیدی با این مشخصات یافت نشد. می‌توانید با کلید «افزودن کلمه به ردیاب» کلمه جدید اضافه کنید.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($keywordsList as $kw): 
                    $pos = $kw['position'] ?? 99;
                    $prevPos = $kw['prev_position'] ?? $pos;
                    $posDiff = $prevPos - $pos; // positive means rank improved
                    $hasCannibal = !empty($kw['cannibalized_urls']) && count($kw['cannibalized_urls']) >= 2;

                    $intentColors = [
                        'Transactional' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                        'Commercial' => 'bg-indigo-50 text-indigo-700 border-indigo-100',
                        'Informational' => 'bg-sky-50 text-sky-700 border-sky-100',
                        'Navigational' => 'bg-slate-100 text-slate-700 border-slate-200'
                    ];
                ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="py-3 px-4">
                        <div class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($kw['keyword']); ?></div>
                        <div class="text-[10px] text-slate-400 font-mono mt-0.5 max-w-xs truncate" title="<?php echo htmlspecialchars($kw['target_url'] ?? ''); ?>">
                            <?php echo htmlspecialchars($kw['target_url'] ?? ''); ?>
                        </div>
                        <?php if ($hasCannibal): ?>
                            <div class="mt-1 flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded w-fit">
                                <span>⚠️</span> <?php echo count($kw['cannibalized_urls']); ?> صفحه در حال رقابت روی این کلمه
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="py-3 px-3 text-center">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl font-mono font-black text-sm <?php echo $pos <= 3 ? 'bg-emerald-50 text-emerald-700' : ($pos <= 10 ? 'bg-indigo-50 text-indigo-700' : ($pos <= 20 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600')); ?>">
                            <?php echo $pos; ?>
                        </span>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold">
                        <?php if ($posDiff > 0): ?>
                            <span class="text-emerald-600">▲ +<?php echo $posDiff; ?></span>
                        <?php elseif ($posDiff < 0): ?>
                            <span class="text-rose-600">▼ <?php echo $posDiff; ?></span>
                        <?php else: ?>
                            <span class="text-slate-400">-</span>
                        <?php endif; ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono text-slate-700">
                        <?php echo number_format($kw['search_volume'] ?? 0); ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold text-slate-900">
                        <?php echo number_format($kw['clicks'] ?? 0); ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono text-slate-500">
                        <?php echo number_format($kw['impressions'] ?? 0); ?>
                    </td>

                    <td class="py-3 px-3 text-center font-mono font-bold text-indigo-600">
                        <?php echo $kw['ctr']; ?>٪
                    </td>

                    <td class="py-3 px-3 text-center">
                        <span class="px-2 py-0.5 rounded border text-[10px] font-bold <?php echo $intentColors[$kw['intent']] ?? 'bg-slate-100 text-slate-600 border-slate-200'; ?>">
                            <?php echo $kw['intent']; ?>
                        </span>
                    </td>

                    <td class="py-3 px-3 text-center">
                        <?php if (($kw['opportunity_score'] ?? '') === 'High'): ?>
                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-black rounded-md">فرصت بالا</span>
                        <?php elseif (($kw['opportunity_score'] ?? '') === 'Medium'): ?>
                            <span class="px-2 py-0.5 bg-sky-50 text-sky-700 text-[10px] font-black rounded-md">فرصت متوسط</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold rounded-md">استاندارد</span>
                        <?php endif; ?>
                    </td>

                    <td class="py-3 px-4 text-left">
                        <button onclick="askAiAboutKeyword('<?php echo addslashes($kw['keyword']); ?>', <?php echo $pos; ?>)" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-bold text-[11px] transition-colors cursor-pointer whitespace-nowrap">
                            💡 تحلیل AI
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Keyword -->
<div id="modal-add-keyword" class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900">افزودن کلمه کلیدی به ردیاب رتبه</h3>
            <button onclick="closeAddKeywordModal()" class="text-slate-400 hover:text-slate-600 text-lg font-black">&times;</button>
        </div>

        <form id="form-add-keyword" onsubmit="submitNewKeyword(event)" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">کلمه یا عبارت کلیدی *</label>
                <input type="text" id="new-kw-name" required placeholder="مثال: خرید کفش پیاده‌روی زنانه" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">آدرس صفحه هدف (Target URL)</label>
                <input type="url" id="new-kw-url" placeholder="https://<?php echo htmlspecialchars($currentBusiness['domain']); ?>/product/..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">قصد جستجو (Search Intent)</label>
                <select id="new-kw-intent" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-indigo-500">
                    <option value="Transactional">خریداری و معامله (Transactional)</option>
                    <option value="Commercial">بررسی تجاری و مقایسه (Commercial)</option>
                    <option value="Informational">آموزشی و اطلاعاتی (Informational)</option>
                    <option value="Navigational">ناوبری برند (Navigational)</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeAddKeywordModal()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:bg-slate-100 rounded-xl">انصراف</button>
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-sm shadow-indigo-200">ثبت در ردیاب</button>
            </div>
        </form>
    </div>
</div>
