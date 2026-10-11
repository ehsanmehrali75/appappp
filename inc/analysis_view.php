<?php
/**
 * Unified Analytics and Sales Performance View
 * Included in both admin.php and portal.php
 */

$stores = $project['settings']['stores'] ?? [];
$selected_store_id = $_GET['store_id'] ?? $_SESSION['selected_store_id'] ?? ($stores[0]['id'] ?? '');

$current_store = null;
$current_store_idx = 0;
foreach ($stores as $idx => $st) {
    if ($st['id'] === $selected_store_id) {
        $current_store = $st;
        $current_store_idx = $idx;
        break;
    }
}
if (!$current_store && !empty($stores)) {
    $current_store = $stores[0];
    $current_store_idx = 0;
    $selected_store_id = $current_store['id'];
}
$_SESSION['selected_store_id'] = $selected_store_id;

$sales_data = $current_store['sales_data'] ?? [];

// Calculate overall summary stats
$total_sales_money = 0;
$total_sales_count = 0;
$total_invoice_count = 0;
foreach ($sales_data as $row) {
    $total_sales_money += ($row['sales_amount'] ?? 0);
    $total_sales_count += ($row['sales_count'] ?? 0);
    $total_invoice_count += ($row['invoice_count'] ?? 0);
}

// Target computations
$targets = $current_store['targets'] ?? [];
$active_target = null;
if (!empty($targets)) {
    $active_target = end($targets); // Defaults to the latest target defined
}

// Parse personnel
$personnel_list = $current_store['personnel'] ?? [];
$custom_metrics = $current_store['custom_metrics'] ?? [];

// Dynamic filters for Analysis
$analysis_filter = $_GET['analysis_filter'] ?? 'all'; // all, sales, traffic, platforms
$platform_subfilter = $_GET['platform_subfilter'] ?? 'telegram'; // telegram, instagram, rubika, bale

$analytics_history = $project['settings']['analytics_history'] ?? [];
$latest_sync = $project['settings']['analytics_last_sync'] ?? null;
?>
<div class="space-y-8" id="unified-analysis-container">
    <!-- Top Configuration Header & Store Switcher -->
    <div class="premium-card bg-gradient-to-br from-indigo-900 via-indigo-950 to-slate-950 text-white rounded-[2.5rem] p-8 shadow-xl relative overflow-hidden">
        <!-- Background light elements -->
        <span class="absolute top-0 right-1/4 w-96 h-96 bg-brand-500/10 rounded-full blur-3xl text-indigo-500 pointer-events-none"></span>
        <span class="absolute bottom-0 left-10 w-64 h-64 bg-emerald-500/10 rounded-full blur-2xl text-emerald-500 pointer-events-none"></span>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <span class="text-[10px] bg-indigo-500/30 text-indigo-200 border border-indigo-400/20 px-3.5 py-1.5 rounded-full font-black uppercase tracking-wider mb-3.5 inline-block">پنل تحلیل رفتاری Maarksho Engine v3</span>
                <h2 class="text-3xl font-black tracking-tight mb-2">رصد، مقایسه و تحلیل هوشمند داده‌ها</h2>
                <p class="text-slate-300 text-xs font-semibold leading-relaxed max-w-xl">
                    آنالیز تجمیعی و تفکیکی شبکه‌های اجتماعی بله، روبیکا، تلگرام، اینستاگرام به همراه تحلیل فاکتورهای فروشگاهی و سئو گوگل بر اساس بازه‌های زمانی شمسی.
                </p>
            </div>
            
            <!-- Store selector -->
            <div class="bg-white/10 backdrop-blur-md p-4 border border-white/10 rounded-3xl flex flex-col gap-2 min-w-[280px]">
                <label class="text-[10px] font-black text-indigo-200 block">🏪 انتخاب فروشگاه / شعبه فعال</label>
                <div class="flex gap-2">
                    <select onchange="window.location.href=updateQueryStringParameter(window.location.href, 'store_id', this.value)" class="flex-1 px-3 py-2 bg-slate-900 border border-white/20 text-white rounded-xl text-xs font-black outline-none focus:border-brand-400">
                        <?php if (empty($stores)): ?>
                            <option value="">فروشگاهی تعریف نشده</option>
                        <?php endif; ?>
                        <?php foreach ($stores as $st): ?>
                            <option value="<?php echo $st['id']; ?>" <?php echo $st['id'] === $selected_store_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st['name']); ?> (<?php echo $st['type'] === 'physical' ? 'حضوری' : 'اینترنتی'; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button onclick="document.getElementById('addStoreModal').style.display='flex'" class="p-2.5 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-black shadow-lg transition-all" title="تعریف فروشگاه جدید">➕</button>
                    <?php if ($current_store): ?>
                        <button onclick="document.getElementById('configureStoreModal').style.display='flex'" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-black transition-all" title="تنظیمات فروشگاه">⚙️</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Message block if present -->
    <?php if (empty($stores)): ?>
        <div class="p-6 bg-amber-50 border border-amber-200 text-amber-900 rounded-[2rem] flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-start gap-4">
                <span class="text-3xl">⚠️</span>
                <div>
                    <h4 class="font-black text-sm text-amber-950 mb-1">هیچ فروشگاهی تعریف نشده است!</h4>
                    <p class="text-xs font-semibold text-amber-800/90 leading-relaxed">برای شروع بررسی و بارگذاری دیتای فروشگاهی، لطفاً ابتدا یک فروشگاه جدید به نام کسب و کار خود بسازید.</p>
                </div>
            </div>
            <button onclick="document.getElementById('addStoreModal').style.display='flex'" class="px-5 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-black transition-all self-start md:self-center shadow-md">تعریف اولین فروشگاه</button>
        </div>
    <?php else: ?>

        <!-- TAB CONTENT 1: Analysis & Review (آنالیز و بررسی) -->
        <?php if ($tab === 'analysis'): ?>
            <!-- Global filters -->
            <div class="bg-white/80 backdrop-blur-md p-2.5 border border-slate-200/50 rounded-3xl flex gap-1 overflow-x-auto scroll-smooth">
                <a href="?tab=analysis&analysis_filter=all&store_id=<?php echo $selected_store_id; ?>" class="px-5 py-3.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 <?php echo $analysis_filter === 'all' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-600 hover:bg-slate-100'; ?>">
                    <span>📊</span>
                    <span>بررسی کلی</span>
                </a>
                <a href="?tab=analysis&analysis_filter=sales&store_id=<?php echo $selected_store_id; ?>" class="px-5 py-3.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 <?php echo $analysis_filter === 'sales' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-600 hover:bg-slate-100'; ?>">
                    <span>💰</span>
                    <span>تحلیل مبالغ و فروش</span>
                </a>
                <a href="?tab=analysis&analysis_filter=traffic&store_id=<?php echo $selected_store_id; ?>" class="px-5 py-3.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 <?php echo $analysis_filter === 'traffic' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-600 hover:bg-slate-100'; ?>">
                    <span>🌐</span>
                    <span>بازدید و محتوا (سرچ‌کنسول)</span>
                </a>
                <a href="?tab=analysis&analysis_filter=platforms&store_id=<?php echo $selected_store_id; ?>" class="px-5 py-3.5 rounded-2xl text-xs font-black transition-all flex items-center gap-2 <?php echo $analysis_filter === 'platforms' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-600 hover:bg-slate-100'; ?>">
                    <span>📡</span>
                    <span>آمار پلتفرم‌ها (سوشال)</span>
                </a>
            </div>

            <!-- Platform secondary filter if active -->
            <?php if ($analysis_filter === 'platforms'): ?>
                <div class="flex gap-2 flex-wrap bg-slate-100/60 p-2 rounded-2xl">
                    <a href="?tab=analysis&analysis_filter=platforms&platform_subfilter=telegram&store_id=<?php echo $selected_store_id; ?>" class="px-4 py-2 text-xs font-bold rounded-xl flex items-center gap-2 transition-all <?php echo $platform_subfilter === 'telegram' ? 'bg-sky-500 text-white' : 'text-slate-500 hover:bg-slate-200'; ?>">
                        <span>Telegram</span>
                    </a>
                    <a href="?tab=analysis&analysis_filter=platforms&platform_subfilter=instagram&store_id=<?php echo $selected_store_id; ?>" class="px-4 py-2 text-xs font-bold rounded-xl flex items-center gap-2 transition-all <?php echo $platform_subfilter === 'instagram' ? 'bg-rose-500 text-white' : 'text-slate-500 hover:bg-slate-200'; ?>">
                        <span>Instagram</span>
                    </a>
                    <a href="?tab=analysis&analysis_filter=platforms&platform_subfilter=rubika&store_id=<?php echo $selected_store_id; ?>" class="px-4 py-2 text-xs font-bold rounded-xl flex items-center gap-2 transition-all <?php echo $platform_subfilter === 'rubika' ? 'bg-orange-500 text-white' : 'text-slate-500 hover:bg-slate-200'; ?>">
                        <span>Rubika</span>
                    </a>
                    <a href="?tab=analysis&analysis_filter=platforms&platform_subfilter=bale&store_id=<?php echo $selected_store_id; ?>" class="px-4 py-2 text-xs font-bold rounded-xl flex items-center gap-2 transition-all <?php echo $platform_subfilter === 'bale' ? 'bg-green-500 text-white' : 'text-slate-500 hover:bg-slate-200'; ?>">
                        <span>Bale</span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Sync Widget Option inside Analysis -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main KPI Cards with SVGs -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40">
                            <div class="flex items-center justify-between">
                                <span class="bg-indigo-50 text-indigo-700 text-[10px] font-black px-2.5 py-1 rounded-full">کل فروش دوره</span>
                                <span class="text-xl">💰</span>
                            </div>
                            <div>
                                <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo number_format($total_sales_money); ?> <span class="text-xs text-slate-400 font-bold">تومان</span></h3>
                                <p class="text-[9px] font-black text-slate-400 mt-2">مجموع تراکنش‌های ثبت شده</p>
                            </div>
                        </div>

                        <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40">
                            <div class="flex items-center justify-between">
                                <span class="bg-emerald-50 text-emerald-700 text-[10px] font-black px-2.5 py-1 rounded-full">کل اقلام فروخته شده</span>
                                <span class="text-xl">👕</span>
                            </div>
                            <div>
                                <h3 class="text-3xl font-black text-emerald-700 tracking-tight"><?php echo number_format($total_sales_count); ?> <span class="text-xs text-emerald-400 font-bold">قلم</span></h3>
                                <p class="text-[9px] font-black text-slate-400 mt-2">تعداد خالص کالاها</p>
                            </div>
                        </div>

                        <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-40">
                            <div class="flex items-center justify-between">
                                <span class="bg-sky-50 text-sky-700 text-[10px] font-black px-2.5 py-1 rounded-full">محبوبیت کلی پلتفرم</span>
                                <span class="text-xl">✨</span>
                            </div>
                            <div>
                                <?php
                                $lastHist = !empty($analytics_history) ? end($analytics_history) : null;
                                $igFollowers = $lastHist['instagram']['followers'] ?? 42000;
                                ?>
                                <h3 class="text-3xl font-black text-slate-800 tracking-tight"><?php echo number_format($igFollowers); ?> <span class="text-xs text-slate-400 font-bold">دنبال‌کننده</span></h3>
                                <p class="text-[9px] font-black text-slate-400 mt-2">اینستاگرام فعال پروژه</p>
                            </div>
                        </div>
                    </div>

                    <!-- Beautiful Interactive SVG Chart / Diagram -->
                    <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="font-black text-sm text-slate-800">📊 نمودار تحلیل روند زمانی (Trend Analysis)</h3>
                                <p class="text-[9px] text-slate-400 font-bold mt-1">مقایسه پیشرفت آماری در ۶ دوره گذشته</p>
                            </div>
                            <span class="text-[10px] bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full font-black">خودکار</span>
                        </div>

                        <?php if (empty($analytics_history)): ?>
                            <div class="p-12 text-center border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50/50">
                                <span class="text-4xl block mb-3">📡</span>
                                <p class="text-slate-500 font-bold text-xs mb-4">آمار پلتفرم‌ها هنوز همگام نشده است.</p>
                                <form method="POST">
                                    <button name="manual_sync_analytics_action" class="px-5 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md transition-all inline-flex items-center gap-2">
                                        <span>⚡ آغاز اولین همگام‌سازی اطلاعات</span>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <!-- Draw Interactive SVG Chart -->
                            <div class="w-full h-64 overflow-visible my-4 relative">
                                <svg class="w-full h-full" viewBox="0 0 600 220" preserveAspectRatio="none">
                                    <defs>
                                        <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.2"/>
                                            <stop offset="100%" stop-color="#4f46e5" stop-opacity="0"/>
                                        </linearGradient>
                                    </defs>
                                    <!-- Grid lines -->
                                    <line x1="40" y1="20" x2="580" y2="20" stroke="#f1f5f9" stroke-width="1" />
                                    <line x1="40" y1="70" x2="580" y2="70" stroke="#f1f5f9" stroke-width="1" />
                                    <line x1="40" y1="120" x2="580" y2="120" stroke="#f1f5f9" stroke-width="1" />
                                    <line x1="40" y1="170" x2="580" y2="170" stroke="#f1f5f9" stroke-dasharray="3,3" stroke-width="1" />

                                    <?php
                                    $points = [];
                                    $xSpace = 500 / (count($analytics_history) - 1);
                                    
                                    // Identify max value to scale appropriately
                                    $maxVal = 1;
                                    foreach ($analytics_history as $h) {
                                        if ($analysis_filter === 'traffic') {
                                            $val = $h['website']['search_console_clicks'] ?? 0;
                                        } elseif ($analysis_filter === 'sales') {
                                            $val = $h['telegram']['views'] ?? 0;
                                        } elseif ($analysis_filter === 'platforms') {
                                            if ($platform_subfilter === 'telegram') $val = $h['telegram']['members'] ?? 0;
                                            elseif ($platform_subfilter === 'instagram') $val = $h['instagram']['followers'] ?? 0;
                                            elseif ($platform_subfilter === 'rubika') $val = $h['rubika']['members'] ?? 0;
                                            else $val = $h['bale']['members'] ?? 0;
                                        } else {
                                            $val = $h['telegram']['members'] ?? 0; // Default members
                                        }
                                        if ($val > $maxVal) $maxVal = $val;
                                    }
                                    
                                    foreach ($analytics_history as $idx => $h) {
                                        if ($analysis_filter === 'traffic') {
                                            $val = $h['website']['search_console_clicks'] ?? 0;
                                        } elseif ($analysis_filter === 'sales') {
                                            $val = $h['telegram']['views'] ?? 0;
                                        } elseif ($analysis_filter === 'platforms') {
                                            if ($platform_subfilter === 'telegram') $val = $h['telegram']['members'] ?? 0;
                                            elseif ($platform_subfilter === 'instagram') $val = $h['instagram']['followers'] ?? 0;
                                            elseif ($platform_subfilter === 'rubika') $val = $h['rubika']['members'] ?? 0;
                                            else $val = $h['bale']['members'] ?? 0;
                                        } else {
                                            $val = $h['telegram']['members'] ?? 0;
                                        }
                                        
                                        $x = 40 + ($idx * $xSpace);
                                        $y = 170 - (($val / $maxVal) * 140);
                                        $points[] = "$x,$y";
                                    }
                                    $pointsStr = implode(" ", $points);
                                    
                                    // Add area path mapping underneath
                                    $firstPoint = explode(",", $points[0]);
                                    $lastPoint = explode(",", end($points));
                                    $areaPointsStr = "40,170 " . $pointsStr . " " . $lastPoint[0] . ",170";
                                    ?>
                                    <polygon points="<?php echo $areaPointsStr; ?>" fill="url(#chartGradient)" />
                                    <polyline points="<?php echo $pointsStr; ?>" fill="none" stroke="#4f46e5" stroke-width="3" />

                                    <!-- Coordinate dots & text labels -->
                                    <?php foreach ($analytics_history as $idx => $h): 
                                        $pNode = explode(",", $points[$idx]);
                                        if ($analysis_filter === 'traffic') {
                                            $val = $h['website']['search_console_clicks'] ?? 0;
                                        } elseif ($analysis_filter === 'sales') {
                                            $val = $h['telegram']['views'] ?? 0;
                                        } elseif ($analysis_filter === 'platforms') {
                                            if ($platform_subfilter === 'telegram') $val = $h['telegram']['members'] ?? 0;
                                            elseif ($platform_subfilter === 'instagram') $val = $h['instagram']['followers'] ?? 0;
                                            elseif ($platform_subfilter === 'rubika') $val = $h['rubika']['members'] ?? 0;
                                            else $val = $h['bale']['members'] ?? 0;
                                        } else {
                                            $val = $h['telegram']['members'] ?? 0;
                                        }
                                    ?>
                                        <circle cx="<?php echo $pNode[0]; ?>" cy="<?php echo $pNode[1]; ?>" r="5" fill="#ffffff" stroke="#4f46e5" stroke-width="2" />
                                        <text x="<?php echo $pNode[0]; ?>" y="<?php echo $pNode[1] - 12; ?>" text-anchor="middle" fill="#1e1b4b" class="text-[9px] font-black"><?php echo number_format($val); ?></text>
                                        <text x="<?php echo $pNode[0]; ?>" y="195" text-anchor="middle" fill="#64748b" class="text-[8px] font-bold"><?php echo htmlspecialchars($h['date']); ?></text>
                                    <?php endforeach; ?>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sync & Auto Automation config box -->
                <div class="space-y-6">
                    <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4">
                        <div class="flex items-center gap-3 border-b border-slate-100 pb-3">
                            <span class="text-2xl">⚡</span>
                            <div>
                                <h3 class="font-black text-xs text-slate-800">بروزرسانی خودکار آمار</h3>
                                <p class="text-[9px] text-slate-400 font-bold">بای‌پس فیلترینگ و یکپارچه‌ساز نوین‌هاب</p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-3">
                            <div>
                                <label class="text-[9px] font-black text-slate-400 block mb-1 uppercase">متد همگام‌سازی هوشمند</label>
                                <select name="sync_mode" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                                    <?php
                                    $syncMode = $project['settings']['analytics_sync_mode'] ?? 'browser_relay';
                                    ?>
                                    <option value="browser_relay" <?php echo $syncMode === 'browser_relay' ? 'selected' : ''; ?>>پل ارتباطی مرورگر کاربر (Browser Relay)</option>
                                    <option value="novinhub" <?php echo $syncMode === 'novinhub' ? 'selected' : ''; ?>>مستقیم از API نوین هاب (NovinHub API)</option>
                                    <option value="socks5" <?php echo $syncMode === 'socks5' ? 'selected' : ''; ?>>پروکسی SOCKS5 سیستم</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-[9px] font-black text-slate-400 block mb-1 uppercase">ساعت اجرای روزانه</label>
                                <input type="text" name="sync_hour" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-center font-bold outline-none" value="<?php echo htmlspecialchars($project['settings']['analytics_sync_hour'] ?? '03:00'); ?>" placeholder="03:00">
                            </div>

                            <button type="submit" name="save_sync_settings_action" class="w-full py-3 bg-slate-800 hover:bg-slate-900 border border-slate-900 text-white rounded-xl text-xs font-black shadow-sm transition-all">ذخیره بازه زمانبندی</button>
                        </form>
                    </div>

                    <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4">
                        <div class="flex items-center gap-3 border-b border-slate-100 pb-3">
                            <span class="text-2xl">🛰️</span>
                            <div>
                                <h3 class="font-black text-xs text-slate-800">همگام‌سازی دستی آنی</h3>
                                <p class="text-[9px] text-slate-400 font-bold">بروزرسانی مستقیم کلیه کانال‌ها</p>
                            </div>
                        </div>

                        <p class="text-slate-500 text-[10px] font-semibold leading-relaxed">
                            در صورت فیلتر بودن سرور، سیستم با بهره‌گیری از گره‌های پرسرعت V2Ray یا پل ارتباطی مرورگر شما، اقدام به خواندن نرخ جذب فالوور می‌کند.
                        </p>

                        <form method="POST">
                            <button type="submit" name="manual_sync_analytics_action" class="w-full py-4 bg-brand-50 hover:bg-brand-100 text-brand-700 border border-brand-200 rounded-2xl text-xs font-black shadow-sm transition-all flex items-center justify-center gap-2">
                                <span>⚡ دریافت و بروزرسانی فوری اطلاعات</span>
                            </button>
                        </form>
                        
                        <?php if ($latest_sync): ?>
                            <div class="text-center">
                                <span class="text-[9px] text-slate-400 font-bold">آخرین همگام‌سازی موفق: <?php echo date('Y/m/d H:i:s', $latest_sync); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <!-- TAB CONTENT 2: Sales Analysis (تحلیل فروش) -->
        <?php elseif ($tab === 'sales_analysis'): ?>
            <!-- Importer Header / Dynamic Excel Uploader -->
            <div class="premium-card p-6 bg-indigo-50/50 border border-indigo-100/50 rounded-[2rem] shadow-sm flex flex-col gap-6">
                <div class="flex items-start gap-4">
                    <span class="text-4xl">📥</span>
                    <div>
                        <h3 class="font-black text-lg text-slate-900 mb-1">بارگذاری هوشمند فایل فروش روزانه (Excel Paste / JSON)</h3>
                        <p class="text-xs font-semibold text-slate-500 leading-relaxed">
                            برای افزودن انبوه داده‌ها، ستون‌های دلخواه خود را در اکسل یا شیت گوگل کپی کنید و بدون تعییر قالب، به این فیلد کپی-پیست نمایید. سیستم هدرها را به صورت خودکار شناسایی می‌کند.
                        </p>
                    </div>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">نوع فرمت ارسال داده</label>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                                    <input type="radio" name="import_type" value="excel" checked class="text-brand-600 focus:ring-brand-500 border-slate-300">
                                    <span>کپی پیست از فایل اکسل (TSV Tab-separated)</span>
                                </label>
                                <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                                    <input type="radio" name="import_type" value="json" class="text-brand-600 focus:ring-brand-500 border-slate-300">
                                    <span>آرایه ساختاریافته JSON</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">محتوای متنی جدول</label>
                        <textarea name="import_content" rows="4" class="w-full p-4 bg-white border border-indigo-100 rounded-2xl text-xs font-mono outline-none focus:border-brand-500 leading-relaxed" placeholder="یا کپی پیست ردیف ها مپ شده از اکسل..." required></textarea>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-center gap-3">
                        <div class="text-[10px] text-indigo-700 font-extrabold flex items-center gap-1">
                            <span>💡 ساختار خودکار ستون‌ها:</span>
                            <span class="bg-indigo-100 px-2 py-0.5 rounded font-mono">تاریخ | روز | فروش | تعداد فروش | تعداد فاکتور | پرسنل | ست برتر</span>
                        </div>
                        <button type="submit" name="import_sales_data_action" class="px-7 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md transition-all self-stretch md:self-auto">کپی و تجمیع داده‌ها</button>
                    </div>
                </form>
            </div>

            <!-- Active Target / Milestones Progress widget -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Money Target progress -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="font-black text-sm text-slate-800">🎯 تارگت و هدف مالی دوره</h3>
                            <p class="text-[9px] text-slate-400 font-bold mt-1">تارگت عددی مبلغ فروش</p>
                        </div>
                        <span class="text-xs bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full font-black"><?php echo htmlspecialchars($active_target['period_name'] ?? 'دوره جاری'); ?></span>
                    </div>

                    <?php
                    $targetMoney = $active_target['target_money'] ?? 1;
                    $percentMoney = min(100, round(($total_sales_money / ($targetMoney ?: 1)) * 100));
                    ?>

                    <div class="flex items-end justify-between font-bold text-xs">
                        <span class="text-slate-500">فروش ثبت‌شده: <?php echo number_format($total_sales_money); ?> تومان</span>
                        <span class="text-indigo-700 font-black">هدف: <?php echo number_format($targetMoney); ?> تومان</span>
                    </div>

                    <!-- Beautiful linear bar -->
                    <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                        <div class="bg-indigo-600 h-full rounded-full transition-all duration-1000" style="width: <?php echo $percentMoney; ?>%;"></div>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-[10px] text-slate-400 font-bold">پیشرفت فیزیکی مالی</span>
                        <span class="text-xs font-black text-indigo-600"><?php echo $percentMoney; ?>% حاصل شده</span>
                    </div>
                </div>

                <!-- Item target progress -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm space-y-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="font-black text-sm text-slate-800">👚 تارگت عددی تعداد اقلام</h3>
                            <p class="text-[9px] text-slate-400 font-bold mt-1">هدف خالص تعداد پوشاک فروخته شده</p>
                        </div>
                        <span class="text-xs bg-emerald-50 text-emerald-700 px-3 py-1 rounded-full font-black"><?php echo htmlspecialchars($active_target['period_name'] ?? 'دوره جاری'); ?></span>
                    </div>

                    <?php
                    $targetCount = $active_target['target_count'] ?? 1;
                    $percentCount = min(100, round(($total_sales_count / ($targetCount ?: 1)) * 100));
                    ?>

                    <div class="flex items-end justify-between font-bold text-xs">
                        <span class="text-slate-500">خروجی اقلام: <?php echo number_format($total_sales_count); ?> قلم</span>
                        <span class="text-emerald-700 font-black">هدف فروشگاه: <?php echo number_format($targetCount); ?> قلم</span>
                    </div>

                    <!-- Beautiful linear bar -->
                    <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                        <div class="bg-emerald-500 h-full rounded-full transition-all duration-1000" style="width: <?php echo $percentCount; ?>%;"></div>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-[10px] text-slate-400 font-bold">بازه کالا و حجم فیزیکی</span>
                        <span class="text-xs font-black text-emerald-600"><?php echo $percentCount; ?>% پوشش داده شده</span>
                    </div>
                </div>
            </div>

            <!-- Bento Box: Best sales metrics analysis -->
            <h3 class="font-black text-lg text-slate-800 mt-6 mb-2">⭐ تحلیل تخصصی برترین‌های فروش</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Card 1: Top Personnel -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-44">
                    <div>
                        <span class="text-[9px] bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-full font-black uppercase">نفرات برتر فروش</span>
                        <h4 class="font-black text-xs text-slate-500 mt-3.5">بیشترین موفقیت ثبت فاکتور</h4>
                    </div>
                    <?php
                    // Count entries
                    $pCounts = [];
                    foreach ($sales_data as $row) {
                        if (!empty($row['personnel_name'])) {
                            $pCounts[$row['personnel_name']] = ($pCounts[$row['personnel_name']] ?? 0) + ($row['sales_amount'] ?? 0);
                        }
                    }
                    arsort($pCounts);
                    $topPers = !empty($pCounts) ? key($pCounts) : 'ثبت نشده';
                    $topPersVal = !empty($pCounts) ? current($pCounts) : 0;
                    ?>
                    <div>
                        <h4 class="text-xl font-black text-slate-800 leading-tight"><?php echo htmlspecialchars($topPers); ?></h4>
                        <p class="text-[10px] text-slate-400 font-bold mt-1">فروش ثبت‌شده: <?php echo number_format($topPersVal); ?> تومان</p>
                    </div>
                </div>

                <!-- Card 2: Best Sold Set -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-44">
                    <div>
                        <span class="text-[9px] bg-rose-50 text-rose-700 px-2.5 py-1 rounded-full font-black uppercase">ست برتر فروخته شده</span>
                        <h4 class="font-black text-xs text-slate-500 mt-3.5">بیشترین هماهنگی انتخاب استایل</h4>
                    </div>
                    <?php
                    $setCounts = [];
                    foreach ($sales_data as $row) {
                        if (!empty($row['best_selling_set'])) {
                            $setCounts[$row['best_selling_set']] = ($setCounts[$row['best_selling_set']] ?? 0) + 1;
                        }
                    }
                    arsort($setCounts);
                    $topSet = !empty($setCounts) ? key($setCounts) : 'ثبت نشده';
                    ?>
                    <div>
                        <h4 class="text-xl font-black text-rose-700 leading-tight"><?php echo htmlspecialchars($topSet); ?></h4>
                        <p class="text-[10px] text-slate-400 font-bold mt-1">ست لباس هماهنگ هفته</p>
                    </div>
                </div>

                <!-- Card 3: Best product of the week -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-44">
                    <div>
                        <span class="text-[9px] bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full font-black uppercase">محصول برتر هفته</span>
                        <h4 class="font-black text-xs text-slate-500 mt-3.5">بیشترین فروش تکی و ترند دوره</h4>
                    </div>
                    <?php
                    $prCounts = [];
                    foreach ($sales_data as $row) {
                        if (!empty($row['best_product_of_week'])) {
                            $prCounts[$row['best_product_of_week']] = ($prCounts[$row['best_product_of_week']] ?? 0) + 1;
                        }
                    }
                    arsort($prCounts);
                    $topPr = !empty($prCounts) ? key($prCounts) : 'ثبت نشده';
                    ?>
                    <div>
                        <h4 class="text-xl font-black text-emerald-700 leading-tight"><?php echo htmlspecialchars($topPr); ?></h4>
                        <p class="text-[10px] text-slate-400 font-bold mt-1">بازه تقویمی هفته اخیر</p>
                    </div>
                </div>

                <!-- Card 4: Sizes & Colors -->
                <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm flex flex-col justify-between h-44">
                    <div>
                        <span class="text-[9px] bg-sky-50 text-sky-700 px-2.5 py-1 rounded-full font-black uppercase">ابعاد، تنخور و رنگبندی</span>
                        <h4 class="font-black text-xs text-slate-500 mt-3.5">سایز و رنگ‌های فروخته‌شده</h4>
                    </div>
                    <?php
                    $colorsRaw = [];
                    foreach ($sales_data as $row) {
                        if (!empty($row['sizes_and_colors'])) {
                            $colorsRaw[] = $row['sizes_and_colors'];
                        }
                    }
                    $combColors = !empty($colorsRaw) ? implode(" / ", array_slice(array_unique($colorsRaw), 0, 2)) : 'ثبت نشده';
                    ?>
                    <div>
                        <h4 class="text-sm font-black text-slate-800 leading-tight"><?php echo htmlspecialchars($combColors); ?></h4>
                        <p class="text-[10px] text-slate-400 font-bold mt-1">سایزهای ترند کالا</p>
                    </div>
                </div>
            </div>

            <!-- Main Detailed Analytics Table -->
            <div class="premium-card p-6 bg-white border border-slate-100 shadow-sm overflow-hidden">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                    <div>
                        <h3 class="font-black text-sm text-slate-800">📋 جدول تجمیعی شاخص‌های فروش روزانه (Daily KIPs)</h3>
                        <p class="text-[9px] text-slate-400 font-bold mt-1">محاسبه خودکار UPT , AUV , ATV و نرخ همگرایی Conversion Rate</p>
                    </div>
                    
                    <button onclick="switchComparisonMode()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black rounded-xl transition-all">🔄 ابزار مقایسه بازه‌های زمانی</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-indigo-50/50 text-indigo-950 font-black border-b border-indigo-100 rounded-xl">
                                <th class="p-3">تاریخ</th>
                                <th class="p-3">روز هفته</th>
                                <th class="p-3">کل فروش (تومان)</th>
                                <th class="p-3">تعداد فروش</th>
                                <th class="p-3">تعداد فاکتور</th>
                                <th class="p-3">UPT</th>
                                <th class="p-3">AUV</th>
                                <th class="p-3">ATV</th>
                                <th class="p-3">ثبت کننده</th>
                                <th class="p-3 text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-semibold text-slate-600">
                            <?php if (empty($sales_data)): ?>
                                <tr>
                                    <td colspan="10" class="p-10 text-center text-slate-400">اطلاعاتی یافت نشد. برای شروع چند روز را در ایمپورتر بالا کپی کنید.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sales_data as $row): 
                                    $rowMoney = ($row['sales_amount'] !== '' && $row['sales_amount'] !== null) ? (float)$row['sales_amount'] : null;
                                    $rowQty = ($row['sales_count'] !== '' && $row['sales_count'] !== null) ? (int)$row['sales_count'] : null;
                                    $rowInvoices = ($row['invoice_count'] !== '' && $row['invoice_count'] !== null) ? (int)$row['invoice_count'] : null;
                                    
                                    // UPT: units per transaction (sales_count / invoice_count)
                                    $upt = ($rowInvoices > 0 && $rowQty !== null) ? round($rowQty / $rowInvoices, 2) : null;
                                    // AUV: average unit value (sales_amount / sales_count)
                                    $auv = ($rowQty > 0 && $rowMoney !== null) ? round($rowMoney / $rowQty) : null;
                                    // ATV: average transaction value (sales_amount / invoice_count)
                                    $atv = ($rowInvoices > 0 && $rowMoney !== null) ? round($rowMoney / $rowInvoices) : null;
                                ?>
                                    <tr class="hover:bg-slate-50 transition-all">
                                        <td class="p-3 font-mono font-bold text-slate-900"><?php echo htmlspecialchars($row['date']); if (!empty($row['date_end'])) { echo ' <span class="text-indigo-650 font-black mx-1">تا</span> ' . htmlspecialchars($row['date_end']); } ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($row['day_of_week'] ?? '---'); ?></td>
                                        <td class="p-3 font-mono text-slate-900 font-black">
                                            <?php echo ($rowMoney !== null) ? number_format($rowMoney) : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 font-mono">
                                             <?php echo ($rowQty !== null) ? number_format($rowQty) : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 font-mono">
                                             <?php echo ($rowInvoices !== null) ? number_format($rowInvoices) : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 font-mono text-indigo-600 font-bold">
                                             <?php echo ($upt !== null) ? $upt : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 font-mono">
                                             <?php echo ($auv !== null) ? number_format($auv) : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 font-mono">
                                             <?php echo ($atv !== null) ? number_format($atv) : '<span class="text-slate-300">---</span>'; ?>
                                         </td>
                                        <td class="p-3 text-slate-400 text-[10px] font-black"><?php echo htmlspecialchars($row['personnel_name'] ?? 'سیستم'); ?></td>
                                        <td class="p-3 text-center">
                                            <form method="POST" onsubmit="return confirm('آیا از حذف داده‌های عملیاتی روز انتخاب شده مطمئن هستید؟')">
                                                <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">
                                                <input type="hidden" name="day_date" value="<?php echo htmlspecialchars($row['date']); ?>">
                                                <button type="submit" name="delete_day_action" class="text-rose-500 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 p-1.5 rounded-lg text-[10px]" title="حذف روز">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 3 inside sales: Periods Comparison Tool -->
            <div id="comparisonToolSection" class="premium-card p-6 bg-slate-50 border border-slate-200 shadow-sm space-y-6 hidden transition-all duration-300">
                <div class="flex justify-between items-center pb-3 border-b border-slate-200">
                    <h3 class="font-black text-sm text-slate-800">🔄 ابزار مقابسه مشابه دوره‌ای (Period Matching Tool)</h3>
                    <button onclick="document.getElementById('comparisonToolSection').classList.add('hidden')" class="text-slate-400 text-lg font-black">×</button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 block mb-1">انتخاب بازه اول (پایه)</label>
                        <select id="compareGroupA" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold" onchange="runComparison()">
                            <option value="">یک بازه زمانی را انتخاب کنید</option>
                            <?php foreach ($sales_data as $row): ?>
                                <option value="<?php echo htmlspecialchars($row['date']); ?>"><?php echo htmlspecialchars($row['date']); ?> (<?php echo htmlspecialchars($row['day_of_week'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="text-[10px] font-black text-slate-500 block mb-1">انتخاب بازه دوم (مقایسه)</label>
                        <select id="compareGroupB" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold" onchange="runComparison()">
                            <option value="">یک بازه زمانی را انتخاب کنید</option>
                            <?php foreach ($sales_data as $row): ?>
                                <option value="<?php echo htmlspecialchars($row['date']); ?>"><?php echo htmlspecialchars($row['date']); ?> (<?php echo htmlspecialchars($row['day_of_week'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="comparisonResultBox" class="p-4 bg-white border border-slate-200 rounded-2xl hidden">
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="p-3 bg-slate-50 rounded-xl">
                            <span class="text-[9px] font-black text-slate-400 block mb-1">تراکنش پایه</span>
                            <h4 id="compValA" class="text-lg font-black">0</h4>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl">
                            <span class="text-[9px] font-black text-slate-400 block mb-1">تراکنش مقایسه</span>
                            <h4 id="compValB" class="text-lg font-black">0</h4>
                        </div>
                        <div class="p-3 rounded-xl flex items-center justify-center font-black text-xs" id="compScoreBox">
                            <span>در حال مقایسه...</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ============================================== -->
<!-- MODAL: ADD STORE -->
<!-- ============================================== -->
<div id="addStoreModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] p-8 max-w-md w-full border border-slate-100 shadow-2xl relative" onclick="event.stopPropagation();">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-black text-lg text-slate-900">🏪 تعریف فروشگاه / شعبه</h3>
            <button onclick="document.getElementById('addStoreModal').style.display='none'" class="text-slate-400 text-xl font-bold">×</button>
        </div>
        
        <form method="POST" class="space-y-4">
            <div>
                <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">نام کامل فروشگاه</label>
                <input type="text" name="store_name" required class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500" placeholder="مثال: گالری آنلاین مژگان">
            </div>

            <div>
                <label class="text-[10px] font-black text-slate-400 block mb-1.5 uppercase">نوع کسب و کار</label>
                <select name="store_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-500">
                    <option value="online">اینترنتی / آنلاین شاپ</option>
                    <option value="physical">حضوری / شعبه آفلاین</option>
                </select>
            </div>

            <button type="submit" name="add_store_action" class="w-full py-4 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-black shadow-md transition-all">ایجاد و ذخیره‌سازی</button>
        </form>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL: CONFIGURE STORE -->
<!-- ============================================== -->
<?php if ($current_store): ?>
<div id="configureStoreModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-[2.5rem] p-8 max-w-2xl w-full border border-slate-100 shadow-2xl relative my-8" onclick="event.stopPropagation();">
        <div class="flex items-center justify-between mb-6 border-b border-slate-100 pb-3">
            <div>
                <h3 class="font-black text-lg text-slate-900">⚙️ تنظیمات و مدیریت فروشگاه: <?php echo htmlspecialchars($current_store['name']); ?></h3>
                <p class="text-[9px] text-slate-400 font-bold mt-1">تعریف پرسنل، تارگت‌های سالانه/ماهانه، و شاخص‌های آماری دلخواه</p>
            </div>
            <button onclick="document.getElementById('configureStoreModal').style.display='none'" class="text-slate-400 text-xl font-bold">×</button>
        </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="store_id" value="<?php echo $selected_store_id; ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نام فروشگاه</label>
                    <input type="text" name="store_name" value="<?php echo htmlspecialchars($current_store['name']); ?>" required class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-400">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 block mb-1 uppercase">نوع فروشگاه</label>
                    <select name="store_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none">
                        <option value="online" <?php echo $current_store['type'] === 'online' ? 'selected' : ''; ?>>اینترنتی</option>
                        <option value="physical" <?php echo $current_store['type'] === 'physical' ? 'selected' : ''; ?>>حضوری</option>
                    </select>
                </div>
            </div>

            <!-- Personnel names area -->
            <div>
                <label class="text-[10px] font-black text-slate-400 block mb-1 uppercase">✍️ تعریف نام پرسنل (هر فروشنده در یک خط)</label>
                <textarea name="personnel_list" rows="3" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-brand-400" placeholder="علی احمدی&#11088;مهدی رضایی"><?php echo htmlspecialchars(implode("\n", $current_store['personnel'] ?? [])); ?></textarea>
            </div>

            <!-- Customize Metrics config -->
            <div>
                <label class="text-[10px] font-black text-slate-400 block mb-2 uppercase">📊 پارامترهای آماری اختیاری اختصاصی</label>
                <div class="space-y-2" id="metrics-editor-container">
                    <?php if (empty($custom_metrics)): ?>
                        <div class="grid grid-cols-2 gap-2 metric-row">
                            <input type="text" name="metric_names[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-bold" placeholder="عنوان متغیر (مثال: تعداد زنگ خور)">
                            <input type="text" name="metric_keys[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-mono" placeholder="کلید سیستمی (مثال: calls_count)">
                        </div>
                    <?php else: ?>
                        <?php foreach ($custom_metrics as $m): ?>
                            <div class="grid grid-cols-2 gap-2 metric-row">
                                <input type="text" name="metric_names[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-bold" value="<?php echo htmlspecialchars($m['name']); ?>" placeholder="عنوان">
                                <input type="text" name="metric_keys[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-mono" value="<?php echo htmlspecialchars($m['key']); ?>" placeholder="کلید">
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" onclick="addNewMetricRow()" class="text-[10px] text-brand-600 hover:text-brand-700 font-bold mt-2">➕ افزودن ستون متغیر دیگر</button>
            </div>

            <!-- Define Targets and Milestones list -->
            <div class="border-t border-slate-100 pt-4">
                <h4 class="text-xs font-black text-slate-800 mb-3">📍 تعریف تارگت‌های ماهیانه (ماه شِمسی)</h4>
                <div class="space-y-3 mb-3">
                    <?php foreach ($targets as $tg): ?>
                        <div class="flex items-center justify-between bg-slate-50 p-3 rounded-xl text-xs">
                            <div class="font-bold">
                                <span class="text-slate-800 font-black"><?php echo htmlspecialchars($tg['period_name']); ?></span> |
                                <span class="text-indigo-600 font-bold"><?php echo number_format($tg['target_money']); ?> ت</span> |
                                <span class="text-emerald-600"><?php echo number_format($tg['target_count']); ?> کالا</span>
                            </div>
                            <button type="submit" name="delete_target_id" value="<?php echo $tg['id']; ?>" class="text-rose-500 hover:text-rose-700 text-[10px]">❌ حذف</button>
                            <!-- Preserve inputs inside request -->
                            <input type="hidden" name="edit_target_ids[]" value="<?php echo $tg['id']; ?>">
                            <input type="hidden" name="edit_period_name[]" value="<?php echo htmlspecialchars($tg['period_name']); ?>">
                            <input type="hidden" name="edit_target_money[]" value="<?php echo $tg['target_money']; ?>">
                            <input type="hidden" name="edit_target_count[]" value="<?php echo $tg['target_count']; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bg-indigo-50/30 p-4 border border-indigo-100/50 rounded-2xl grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    <div class="grid grid-cols-2 gap-2">
                        <?php
                        $monthsListT = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                        $currentJMonthT = (int)jdate('m', time(), '', 'Asia/Tehran', 'en');
                        $currentJYearT = (int)jdate('Y', time(), '', 'Asia/Tehran', 'en');
                        ?>
                        <div>
                            <label class="text-[9px] font-black text-indigo-900 block mb-1">انتخاب ماه</label>
                            <select name="new_period_month" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-[11px] font-black outline-none">
                                <?php foreach ($monthsListT as $mIdx => $mName): ?>
                                    <option value="<?php echo $mName; ?>" <?php echo ($mIdx + 1 == $currentJMonthT) ? 'selected' : ''; ?>>
                                        <?php echo $mName; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-[9px] font-black text-indigo-900 block mb-1">انتخاب سال</label>
                            <select name="new_period_year" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-[11px] font-black outline-none">
                                <?php for ($yr = 1400; $yr <= 1415; $yr++): ?>
                                    <option value="<?php echo $yr; ?>" <?php echo ($yr == $currentJYearT) ? 'selected' : ''; ?>>
                                        <?php echo $yr; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-[9px] font-black text-indigo-900 block mb-1">تارگت مبلع فروش (تومان)</label>
                        <input type="number" name="new_target_money" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-xs font-bold outline-none" placeholder="150000000">
                    </div>
                    <div>
                        <label class="text-[9px] font-black text-indigo-900 block mb-1">تارگت تعداد اقلام فروش</label>
                        <input type="number" name="new_target_count" class="w-full p-2 bg-white border border-indigo-100 rounded-lg text-xs font-bold outline-none" placeholder="500">
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center gap-3 border-t border-slate-100 pt-4">
                <button type="submit" name="delete_store_action" onclick="return confirm('آیا مطمئن هستید که می‌خواهید کل اطلاعات عملیاتی این فروشگاه را حذف کنید؟')" class="px-5 py-3.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-xl text-xs font-black transition-all">🗑️ حذف کل فروشگاه</button>
                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('configureStoreModal').style.display='none'" class="px-5 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-black">انصراف</button>
                    <button type="submit" name="save_store_config_action" class="px-7 py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md">ذخیره نهایی تنظیمات</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- System Helper Script for parameters modification without breaking standard navigation -->
<script>
function updateQueryStringParameter(uri, key, value) {
    var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
    var separator = uri.indexOf('?') !== -1 ? "&" : "?";
    if (uri.match(re)) {
        return uri.replace(re, '$1' + key + "=" + value + '$2');
    }
    else {
        return uri + separator + key + "=" + value;
    }
}

function addNewMetricRow() {
    var container = document.getElementById('metrics-editor-container');
    var div = document.createElement('div');
    div.className = 'grid grid-cols-2 gap-2 metric-row mt-2';
    div.innerHTML = `
        <input type="text" name="metric_names[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-bold" placeholder="عنوان">
        <input type="text" name="metric_keys[]" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-mono" placeholder="کلید">
    `;
    container.appendChild(div);
}

function switchComparisonMode() {
    var sect = document.getElementById('comparisonToolSection');
    if (sect) {
        sect.classList.toggle('hidden');
    }
}

// Map the items to display side-by-side comparison on the fly
function runComparison() {
    var a = document.getElementById('compareGroupA').value;
    var b = document.getElementById('compareGroupB').value;
    var resBox = document.getElementById('comparisonResultBox');
    
    if (a && b) {
        resBox.classList.remove('hidden');
        
        // Use PHP loaded dataset in JS safely
        var salesData = <?php echo json_encode($sales_data, JSON_UNESCAPED_UNICODE); ?>;
        var rowA = salesData.find(r => r.date === a);
        var rowB = salesData.find(r => r.date === b);
        
        if (rowA && rowB) {
            var valA = parseFloat(rowA.sales_amount || 0);
            var valB = parseFloat(rowB.sales_amount || 0);
            
            document.getElementById('compValA').innerHTML = valA.toLocaleString() + ' ت';
            document.getElementById('compValB').innerHTML = valB.toLocaleString() + ' ت';
            
            var scoreBox = document.getElementById('compScoreBox');
            if (valA === 0) {
                scoreBox.className = 'p-3 bg-slate-100 text-slate-750 font-black rounded-xl text-center';
                scoreBox.innerHTML = 'بدون تغییر';
            } else {
                var growth = ((valB - valA) / valA) * 100;
                if (growth >= 0) {
                    scoreBox.className = 'p-3 bg-emerald-50 text-emerald-700 font-black rounded-xl text-center';
                    scoreBox.innerHTML = '📈 ' + growth.toFixed(1) + '% رشد';
                } else {
                    scoreBox.className = 'p-3 bg-rose-50 text-rose-700 font-black rounded-xl text-center';
                    scoreBox.innerHTML = '📉 ' + Math.abs(growth).toFixed(1) + '% کاهش';
                }
            }
        }
    }
}
</script>
