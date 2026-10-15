<?php
/**
 * View: Feature & Module Management (فعال و غیر فعال سازی امکانات)
 * Only accessible to the main admin (username === 'admin')
 */

if (!defined('DB_PATH') && !class_exists('FeatureFlags')) {
    require_once __DIR__ . '/functions.php';
}

if (!isMainAdmin()) {
    ?>
    <div class="p-8 bg-rose-50 border border-rose-200 text-rose-800 rounded-3xl text-center my-8 shadow-sm">
        <div class="text-4xl mb-3">⛔</div>
        <h3 class="text-base font-black mb-1">دسترسی غیرمجاز</h3>
        <p class="text-xs text-rose-600 font-medium">این بخش منحصراً در اختیار ادمین اصلی سامانه می‌باشد.</p>
    </div>
    <?php
    return;
}

if (empty($_SESSION['access_csrf'])) $_SESSION['access_csrf'] = bin2hex(random_bytes(32));
$accessPolicies = AccessControl::policies();
$catalog = FeatureFlags::getCatalog();
$config = FeatureFlags::getConfig();

// Count stats
$totalCount = count($catalog);
$activeCount = 0;
$maintCount = 0;
$disabledCount = 0;

foreach ($catalog as $k => $item) {
    $st = $config[$k] ?? FeatureFlags::STATUS_ACTIVE;
    if ($st === FeatureFlags::STATUS_ACTIVE) $activeCount++;
    elseif ($st === FeatureFlags::STATUS_MAINTENANCE) $maintCount++;
    elseif ($st === FeatureFlags::STATUS_DISABLED) $disabledCount++;
}

// Group catalog by category
$categories = [
    'core' => ['title' => '👑 مدیریت کلان وب‌سایت', 'items' => []],
    'general' => ['title' => '📊 منوهای اصلی و عمومی', 'items' => []],
    'content' => ['title' => '💎 مدیریت محتوا و کالا', 'items' => []],
    'calendar_ai' => ['title' => '🗓️ تقویم و ابزارهای تولید محتوا', 'items' => []],
    'analytics_classic' => ['title' => '📈 تحلیل و هوش تجاری کلاسیک', 'items' => []],
    'seo_intel' => ['title' => '🚀 سئو و آنالیتیکس سازمانی (Composio)', 'items' => []],
    'vm' => ['title' => '🛍️ هوش چیدمان فروشگاه (VM Studio)', 'items' => []],
    'settings_users' => ['title' => '⚙️ مدیریت، پرسنل و دسترسی‌ها', 'items' => []],
];

foreach ($catalog as $k => $item) {
    $cat = $item['category'] ?? 'general';
    if (!isset($categories[$cat])) {
        $categories[$cat] = ['title' => $item['category_title'] ?? $cat, 'items' => []];
    }
    $categories[$cat]['items'][$k] = $item;
}
?>

<div class="max-w-7xl mx-auto py-2 px-1">
    <!-- Header Hero Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white rounded-3xl p-6 lg:p-8 shadow-xl relative overflow-hidden mb-8 border border-slate-800">
        <div class="absolute -left-10 -bottom-10 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute right-0 top-0 w-80 h-80 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl bg-amber-500/20 border border-amber-400/30 flex items-center justify-center text-3xl shadow-inner flex-shrink-0">
                    🎛️
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <h2 class="text-xl md:text-2xl font-black text-white tracking-tight">فعال و غیر فعال سازی امکانات سامانه</h2>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-400 text-slate-950 shadow-sm flex items-center gap-1">
                            <span>👑</span> فقط یوزر admin
                        </span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
                            موتور پایش متمرکز
                        </span>
                    </div>
                    <p class="text-xs text-slate-300 leading-relaxed max-w-3xl">
                        کنترل جامع تمام منوها، ابزارها و ماژول‌های مارک‌شو فلو. امکانات در دست تعمیر از دید تمام کاربران مخفی شده و منحصراً برای شما قابل تست و آپدیت است. همچنین با انتخاب گزینه «غیرفعال کامل»، قابلیت از دید خود شما نیز تا زمان فعال‌سازی مجدد پنهان خواهد شد.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 w-full md:w-auto">
                <button type="button" onclick="submitAllFeatureFlagsForm()" class="w-full md:w-auto px-6 py-3 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 font-black text-xs rounded-2xl transition-all shadow-lg shadow-emerald-500/20 flex items-center justify-center gap-2 cursor-pointer">
                    <span>💾</span> ذخیره سریع تغییرات
                </button>
            </div>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block mb-1">کل امکانات سامانه</span>
                <span class="text-2xl font-black text-slate-900" id="stat-total"><?php echo $totalCount; ?></span>
            </div>
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-lg text-slate-600">
                📦
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-emerald-100 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[10px] font-black text-emerald-600 uppercase tracking-wider block mb-1">فعال برای همه کاربران</span>
                <span class="text-2xl font-black text-emerald-700" id="stat-active"><?php echo $activeCount; ?></span>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-lg text-emerald-600">
                🟢
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-amber-100 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[10px] font-black text-amber-600 uppercase tracking-wider block mb-1">در دست تعمیر (فقط ادمین)</span>
                <span class="text-2xl font-black text-amber-700" id="stat-maint"><?php echo $maintCount; ?></span>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-lg text-amber-600">
                🟡
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-rose-100 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[10px] font-black text-rose-600 uppercase tracking-wider block mb-1">غیرفعال کامل (مخفی)</span>
                <span class="text-2xl font-black text-rose-700" id="stat-disabled"><?php echo $disabledCount; ?></span>
            </div>
            <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center text-lg text-rose-600">
                🔴
            </div>
        </div>
    </div>

    <!-- Instructions / Logic Guide -->
    <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl p-5 mb-8">
        <h4 class="text-xs font-black text-indigo-900 mb-2 flex items-center gap-1.5">
            <span>💡</span> راهنمای منطق سه وضعیتی امکانات:
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs leading-relaxed text-slate-700">
            <div class="p-3 bg-white/80 rounded-xl border border-indigo-100/60">
                <span class="font-black text-emerald-700 block mb-1">🟢 فعال (عمومی):</span>
                قابلیت در منوهای سایدبار، نوبار و تنظیمات برای تمام کاربران و نقش‌های دارای مجوز نمایش داده شده و دسترسی کامل برقرار است.
            </div>
            <div class="p-3 bg-white/80 rounded-xl border border-indigo-100/60">
                <span class="font-black text-amber-700 block mb-1">🟡 در دست تعمیر (فقط ادمین اصلی):</span>
                این ویژگی از منوها و دسترسی تمام یوزرها حذف می‌شود و <strong>فقط برای یوزر admin</strong> در سایدبار نمایش می‌یابد تا بتوانید آن را آپدیت، توسعه و تست کنید.
            </div>
            <div class="p-3 bg-white/80 rounded-xl border border-indigo-100/60">
                <span class="font-black text-rose-700 block mb-1">🔴 غیرفعال کامل (مخفی حتی از ادمین):</span>
                قابلیت به طور کامل غیرفعال شده و <strong>حتی از سایدبار خود شما نیز حذف می‌شود</strong> تا زمانی که در همین صفحه مجدداً آن را فعال کنید.
            </div>
        </div>
    </div>

    <!-- Filter and Search Toolbar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs mb-6 flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-4">
        <!-- Search Input -->
        <div class="relative flex-1">
            <input type="text" id="feature-search" oninput="applyFeatureFilters()" placeholder="جستجوی عنوان یا شناسه امکانات (مثلاً: چت، سئو، تقویم، محصولات)..." class="w-full pl-4 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-indigo-500 transition-all font-medium">
            <span class="absolute right-3 top-3 text-slate-400 text-sm">🔍</span>
        </div>

        <!-- Filter Status Chips -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0">
            <button type="button" onclick="setFilterStatus('all')" class="filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-900 text-white" data-status="all">
                همه (<?php echo $totalCount; ?>)
            </button>
            <button type="button" onclick="setFilterStatus('active')" class="filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-100 text-slate-600 hover:bg-slate-200" data-status="active">
                🟢 فقط فعال‌ها
            </button>
            <button type="button" onclick="setFilterStatus('maintenance')" class="filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-100 text-slate-600 hover:bg-slate-200" data-status="maintenance">
                🟡 فقط در دست تعمیر
            </button>
            <button type="button" onclick="setFilterStatus('disabled')" class="filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-100 text-slate-600 hover:bg-slate-200" data-status="disabled">
                🔴 فقط غیرفعال‌ها
            </button>
        </div>

        <!-- Bulk Action Buttons -->
        <div class="flex items-center gap-2 border-t lg:border-t-0 pt-2 lg:pt-0">
            <div class="relative inline-block text-right">
                <button type="button" onclick="toggleBulkDropdown()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all flex items-center gap-1.5 cursor-pointer">
                    <span>⚡</span> <span>عملیات گروهی</span> <span class="text-[9px]">▼</span>
                </button>
                <div id="bulk-dropdown" class="hidden absolute left-0 mt-2 w-52 bg-white rounded-2xl shadow-2xl border border-slate-100 py-2 z-30">
                    <button type="button" onclick="setAllStatuses('active')" class="w-full text-right px-4 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-50 flex items-center gap-2">
                        <span>🟢</span> فعال‌سازی همه امکانات
                    </button>
                    <button type="button" onclick="setAllStatuses('maintenance')" class="w-full text-right px-4 py-2 text-xs font-bold text-amber-700 hover:bg-amber-50 flex items-center gap-2">
                        <span>🟡</span> بردن همه به حالت تعمیر
                    </button>
                    <button type="button" onclick="setAllStatuses('disabled')" class="w-full text-right px-4 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 flex items-center gap-2">
                        <span>🔴</span> غیرفعال‌سازی همه
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Master Form -->
    <form id="form-feature-flags" method="POST" action="admin.php?tab=feature_flags" onsubmit="handleFormSubmit(event)">
        <input type="hidden" name="save_feature_flags" value="1">
        <input type="hidden" name="access_csrf" value="<?php echo htmlspecialchars($_SESSION['access_csrf']); ?>">
        <div class="p-4 mb-5 bg-indigo-50 rounded-xl text-xs space-y-2">
            <p>ادمین اصلی بر پایهٔ نقش main_admin تعیین می‌شود؛ نقش قدیمی superadmin نیز معتبر است. نام کاربری دسترسی ایجاد نمی‌کند.</p>
            <p>مشاهده و استفاده را جدا تنظیم کنید. خالی = مجوزهای فعلی؛ در صورت انتخاب، فقط نقش‌ها یا افراد یا اعضای پروژه‌های انتخابی مجازند. استفاده به مشاهده هم نیاز دارد. «فقط ادمین اصلی» بر تمام مجوزها اولویت دارد.</p>
            <p>نقش‌ها: project_admin (ادمین پروژه)، manager (مدیر با دسترسی انتخابی)، editor (کارشناس)، viewer (کاربر پنل).</p>
        </div>

        <div class="space-y-8" id="features-container">
            <?php foreach ($categories as $catKey => $catData): 
                if (empty($catData['items'])) continue;
            ?>
            <div class="feature-category-block" data-category="<?php echo $catKey; ?>">
                <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-200/80">
                    <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                        <span><?php echo $catData['title']; ?></span>
                        <span class="text-[11px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">
                            <?php echo count($catData['items']); ?> مورد
                        </span>
                    </h3>
                    <div class="text-[11px] text-slate-400 font-medium">
                        تنظیم وضعیت دسترسی
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($catData['items'] as $fKey => $item): 
                        $status = $config[$fKey] ?? FeatureFlags::STATUS_ACTIVE;
                        $meta = FeatureFlags::getStatusMeta($status);
                    ?>
                    <div class="feature-card bg-white p-5 rounded-2xl border transition-all shadow-xs hover:shadow-md flex flex-col justify-between"
                         id="card-<?php echo $fKey; ?>"
                         data-key="<?php echo $fKey; ?>"
                         data-title="<?php echo htmlspecialchars($item['title']); ?>"
                         data-status="<?php echo $status; ?>">
                        
                        <div>
                            <!-- Header: Icon, Title, Status Badge -->
                            <div class="flex items-start justify-between gap-2 mb-2.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-xl shadow-inner flex-shrink-0">
                                        <?php echo $item['icon']; ?>
                                    </span>
                                    <div>
                                        <h4 class="text-xs font-black text-slate-900 leading-snug">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </h4>
                                        <span class="text-[10px] text-slate-400 font-mono tracking-tight">
                                            <?php echo $fKey; ?>
                                        </span>
                                    </div>
                                </div>

                                <span class="status-indicator-badge px-2 py-0.5 rounded text-[10px] font-black border <?php echo $meta['badge']; ?>" id="badge-<?php echo $fKey; ?>">
                                    <?php echo $meta['label']; ?>
                                </span>
                            </div>

                            <!-- Description -->
                            <p class="text-[11px] text-slate-500 leading-relaxed mb-4 min-h-[38px]">
                                <?php echo htmlspecialchars($item['desc']); ?>
                            </p>
                        </div>

                        <details class="my-3 text-xs border rounded-xl p-3">
                            <summary class="cursor-pointer font-bold">دسترسی نقش، فرد و پروژه</summary>
                            <?php foreach (['view' => 'مشاهده', 'use' => 'استفاده و عملیات'] as $accessMode => $accessLabel): ?>
                                <fieldset class="mt-3 space-y-2"><legend class="font-bold"><?php echo $accessLabel; ?></legend>
                                <?php foreach (['roles' => 'نقش‌ها (شناسه نقش)', 'users' => 'افراد (نام کاربری)', 'projects' => 'پروژه‌ها (شناسه پروژه)'] as $accessScope => $scopeLabel): ?>
                                    <label class="block"><?php echo $scopeLabel; ?>
                                        <input type="text" dir="ltr" class="w-full border rounded p-2 mt-1" placeholder="جداشده با ویرگول؛ خالی = ارث‌بری"
                                            name="access_policy[<?php echo htmlspecialchars($fKey); ?>][<?php echo $accessMode; ?>][<?php echo $accessScope; ?>]"
                                            value="<?php echo htmlspecialchars(implode(', ', $accessPolicies[$fKey][$accessMode][$accessScope] ?? [])); ?>">
                                    </label>
                                <?php endforeach; ?></fieldset>
                            <?php endforeach; ?>
                        </details>
                        <!-- 3-State Controls -->
                        <div class="pt-3 border-t border-slate-100">
                            <label class="block text-[10px] font-black text-slate-400 mb-2 uppercase tracking-wider">انتخاب وضعیت:</label>
                            
                            <div class="grid grid-cols-3 gap-1.5 p-1 bg-slate-100/80 rounded-xl border border-slate-200/50">
                                <!-- Option 1: Active -->
                                <label class="status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all <?php echo $status === 'active' ? 'bg-emerald-600 text-white font-black shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium hover:bg-slate-200/60'; ?>">
                                    <input type="radio" 
                                           name="features[<?php echo $fKey; ?>]" 
                                           value="active" 
                                           class="hidden" 
                                           <?php echo $status === 'active' ? 'checked' : ''; ?>
                                           onchange="updateFeatureStatusUI('<?php echo $fKey; ?>', 'active')">
                                    <span class="text-xs mb-0.5">🟢</span>
                                    <span class="text-[10px] leading-tight">فعال عمومی</span>
                                </label>

                                <!-- Option 2: Maintenance (Admin Only) -->
                                <label class="status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all <?php echo $status === 'maintenance' ? 'bg-amber-500 text-slate-950 font-black shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium hover:bg-slate-200/60'; ?>" title="فقط برای نقش ادمین اصلی نمایش داده می‌شود">
                                    <input type="radio" 
                                           name="features[<?php echo $fKey; ?>]" 
                                           value="maintenance" 
                                           class="hidden" 
                                           <?php echo $status === 'maintenance' ? 'checked' : ''; ?>
                                           onchange="updateFeatureStatusUI('<?php echo $fKey; ?>', 'maintenance')">
                                    <span class="text-xs mb-0.5">🟡</span>
                                    <span class="text-[10px] leading-tight">فقط ادمین اصلی</span>
                                </label>

                                <!-- Option 3: Disabled (Hidden Even for Admin) -->
                                <label class="status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all <?php echo $status === 'disabled' ? 'bg-rose-600 text-white font-black shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium hover:bg-slate-200/60'; ?>" title="حتی از سایدبار ادمین اصلی نیز مخفی خواهد شد">
                                    <input type="radio" 
                                           name="features[<?php echo $fKey; ?>]" 
                                           value="disabled" 
                                           class="hidden" 
                                           <?php echo $status === 'disabled' ? 'checked' : ''; ?>
                                           onchange="updateFeatureStatusUI('<?php echo $fKey; ?>', 'disabled')">
                                    <span class="text-xs mb-0.5">🔴</span>
                                    <span class="text-[10px] leading-tight">غیرفعال کامل</span>
                                </label>
                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Sticky Footer Save Bar -->
        <div class="sticky bottom-6 mt-10 p-4 bg-white/95 backdrop-blur-md rounded-2xl border border-slate-200 shadow-2xl flex items-center justify-between gap-4 z-20">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center justify-center text-lg">
                    ⚡
                </span>
                <div>
                    <h5 class="text-xs font-black text-slate-800">ذخیره وضعیت امکانات سیستم</h5>
                    <p class="text-[10px] text-slate-400">تغییرات بلافاصله بر روی منوهای تمام کاربران و ادمین اعمال خواهند شد.</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-8 py-3 bg-emerald-600 hover:bg-emerald-500 active:scale-95 text-white font-black text-xs rounded-xl shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-2 cursor-pointer">
                    <span>💾</span> ذخیره تمام تغییرات
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Toast Notification Container -->
<div id="toast-notify" class="fixed bottom-8 left-8 bg-slate-900 text-white px-5 py-3 rounded-2xl shadow-2xl text-xs font-black flex items-center gap-2 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none">
    <span id="toast-icon">✨</span>
    <span id="toast-msg">تغییرات با موفقیت ذخیره شد.</span>
</div>

<script>
let currentFilterStatus = 'all';

function toggleBulkDropdown() {
    const el = document.getElementById('bulk-dropdown');
    el.classList.toggle('hidden');
}

// Close bulk dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('bulk-dropdown');
    const btn = e.target.closest('button[onclick="toggleBulkDropdown()"]');
    if (!dropdown.classList.contains('hidden') && !btn && !dropdown.contains(e.target)) {
        dropdown.classList.add('hidden');
    }
});

function showToast(msg, icon = '✨') {
    const toast = document.getElementById('toast-notify');
    document.getElementById('toast-msg').innerText = msg;
    document.getElementById('toast-icon').innerText = icon;
    toast.classList.remove('translate-y-20', 'opacity-0');
    toast.classList.add('translate-y-0', 'opacity-100');
    setTimeout(() => {
        toast.classList.remove('translate-y-0', 'opacity-100');
        toast.classList.add('translate-y-20', 'opacity-0');
    }, 2800);
}

function updateFeatureStatusUI(fKey, newStatus) {
    const card = document.getElementById('card-' + fKey);
    if (!card) return;
    card.setAttribute('data-status', newStatus);

    const badge = document.getElementById('badge-' + fKey);
    if (badge) {
        if (newStatus === 'active') {
            badge.className = 'status-indicator-badge px-2 py-0.5 rounded text-[10px] font-black border bg-emerald-50 text-emerald-700 border-emerald-200';
            badge.innerText = 'فعال (عمومی)';
        } else if (newStatus === 'maintenance') {
            badge.className = 'status-indicator-badge px-2 py-0.5 rounded text-[10px] font-black border bg-amber-50 text-amber-700 border-amber-200';
            badge.innerText = 'در دست تعمیر (فقط ادمین)';
        } else if (newStatus === 'disabled') {
            badge.className = 'status-indicator-badge px-2 py-0.5 rounded text-[10px] font-black border bg-rose-50 text-rose-700 border-rose-200';
            badge.innerText = 'غیرفعال کامل (مخفی از همه)';
        }
    }

    // Update style of radio labels inside this card
    const labels = card.querySelectorAll('.status-radio-label');
    labels.forEach(l => {
        const inp = l.querySelector('input');
        if (inp.value === newStatus) {
            if (newStatus === 'active') {
                l.className = 'status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all bg-emerald-600 text-white font-black shadow-xs';
            } else if (newStatus === 'maintenance') {
                l.className = 'status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all bg-amber-500 text-slate-950 font-black shadow-xs';
            } else if (newStatus === 'disabled') {
                l.className = 'status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all bg-rose-600 text-white font-black shadow-xs';
            }
        } else {
            l.className = 'status-radio-label flex flex-col items-center justify-center py-2 px-1 rounded-lg text-center cursor-pointer transition-all text-slate-600 hover:text-slate-900 font-medium hover:bg-slate-200/60';
        }
    });

    recalculateStats();
    showToast('وضعیت «' + (card.getAttribute('data-title') || fKey) + '» تغییر کرد. ذخیره فرم برای نهایی‌سازی نیاز است.');
}

function recalculateStats() {
    const cards = document.querySelectorAll('.feature-card');
    let a = 0, m = 0, d = 0;
    cards.forEach(c => {
        const st = c.getAttribute('data-status');
        if (st === 'active') a++;
        else if (st === 'maintenance') m++;
        else if (st === 'disabled') d++;
    });

    document.getElementById('stat-active').innerText = a;
    document.getElementById('stat-maint').innerText = m;
    document.getElementById('stat-disabled').innerText = d;
}

function setFilterStatus(status) {
    currentFilterStatus = status;
    document.querySelectorAll('.filter-status-btn').forEach(btn => {
        if (btn.getAttribute('data-status') === status) {
            btn.className = 'filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-900 text-white';
        } else {
            btn.className = 'filter-status-btn px-3 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer bg-slate-100 text-slate-600 hover:bg-slate-200';
        }
    });
    applyFeatureFilters();
}

function applyFeatureFilters() {
    const query = (document.getElementById('feature-search').value || '').trim().toLowerCase();
    const cards = document.querySelectorAll('.feature-card');
    const categories = document.querySelectorAll('.feature-category-block');

    cards.forEach(card => {
        const title = (card.getAttribute('data-title') || '').toLowerCase();
        const key = (card.getAttribute('data-key') || '').toLowerCase();
        const status = card.getAttribute('data-status');

        const matchesQuery = (!query || title.includes(query) || key.includes(query));
        const matchesStatus = (currentFilterStatus === 'all' || status === currentFilterStatus);

        if (matchesQuery && matchesStatus) {
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
        }
    });

    // Hide empty categories
    categories.forEach(cat => {
        const visibleCards = cat.querySelectorAll('.feature-card:not(.hidden)');
        if (visibleCards.length === 0) {
            cat.classList.add('hidden');
        } else {
            cat.classList.remove('hidden');
        }
    });
}

function setAllStatuses(status) {
    if (!confirm('آیا مطمئن هستید که می‌خواهید تمام امکانات سیستم را به وضعیت ' + (status === 'active' ? '«فعال»' : (status === 'maintenance' ? '«در دست تعمیر»' : '«غیرفعال»')) + ' تغییر دهید؟')) {
        return;
    }
    const cards = document.querySelectorAll('.feature-card');
    cards.forEach(card => {
        const key = card.getAttribute('data-key');
        const radio = card.querySelector('input[type="radio"][value="' + status + '"]');
        if (radio) {
            radio.checked = true;
            updateFeatureStatusUI(key, status);
        }
    });
    toggleBulkDropdown();
    showToast('تمام امکانات به وضعیت درخواستی تغییر یافت. لطفاً روی دکمه ذخیره کلیک کنید.');
}

function submitAllFeatureFlagsForm() {
    document.getElementById('form-feature-flags').submit();
}

function handleFormSubmit(e) {
    // Normal POST submission handles redirect and full consistency
}
</script>
