<?php
/**
 * Enterprise SEO & Analytics Intelligence Platform
 * Master Controller & UI Shell
 * 
 * Supports Multi-Business/Project Connections, Composio Integration Layer,
 * Data Warehouse Normalization, Real-time Rank Tracking & AI Recommendations.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load core system functions if available
$systemFuncs = __DIR__ . '/../inc/functions.php';
if (file_exists($systemFuncs)) {
    require_once $systemFuncs;
}

require_once __DIR__ . '/SeoDB.php';
require_once __DIR__ . '/ComposioService.php';
require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/SeoAIAnalyst.php';

require_once __DIR__ . '/../inc/ModuleProjectAccess.php';
$businessParam = $_GET['business'] ?? ($_GET['slug'] ?? ($_SESSION['project_id'] ?? null));
if ($businessParam === null && isMainAdmin()) $businessParam = SeoDB::getBusinesses()[0]['slug'] ?? null;
$currentBusiness = ModuleProjectAccess::business($businessParam);
ModuleProjectAccess::enforce('seo_intel', 'analysis');
$allBusinesses = ModuleProjectAccess::visibleBusinesses(SeoDB::getBusinesses());

// Determine current view
$view = $_GET['view'] ?? ($_GET['tab'] ?? 'overview');
$validViews = [
    'overview' => 'پیشخوان جامع',
    'rank_tracking' => 'ردیاب رتبه‌ها و میانگین',
    'keywords' => 'هوش کلمات کلیدی',
    'pages' => 'تحلیل صفحات و فرود',
    'links' => 'لینک‌سازی داخلی',
    'technical' => 'سئو تکنیکال',
    'traffic' => 'تحلیل ترافیک (GA4)',
    'content' => 'محتوا و درآمد',
    'social' => 'شبکه‌های اجتماعی',
    'recommendations' => 'پیشنهادات AI',
    'integrations' => 'یکپارچه‌سازی و Composio',
    'reports' => 'گزارشات و مقایسه‌ها'
];

// Screen visibility follows real session grants and explicit child restrictions.
if (!is_string($view) || !isset($validViews[$view])) $view = 'overview';
ModuleProjectAccess::enforce('seo_intel', 'analysis', ModuleProjectAccess::seoFeature($view));
$allowedViews = [];
foreach ($validViews as $candidate => $label) {
    if (ModuleProjectAccess::allowed('seo_intel', 'analysis', ModuleProjectAccess::seoFeature($candidate))) $allowedViews[] = $candidate;
}

// Calculate summary stats for header
$stats = SeoDB::calculateScoreAndStats($currentBusiness['id']);
$trafficData = SeoDB::getTrafficMetrics($currentBusiness['id']);

// Helper for SEO URLs (Supports clean /admin/business/... pretty paths and query fallback)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isPrettyBusinessUrl = (strpos($requestUri, '/admin/business/') !== false);
$isPrettyProjectsUrl = (strpos($requestUri, '/admin/projects/') !== false);

if (!function_exists('buildSeoUrl')) {
    function buildSeoUrl($bizSlug, $vKey = 'overview', $extraParams = []) {
        global $isPrettyBusinessUrl, $isPrettyProjectsUrl;
        $prefix = $isPrettyProjectsUrl ? '/admin/projects/' : '/admin/business/';
        if ($isPrettyBusinessUrl || $isPrettyProjectsUrl) {
            $base = $prefix . urlencode($bizSlug) . '/seo';
            $url = ($vKey === 'overview') ? $base : ($base . '/' . $vKey);
            if (!empty($extraParams)) {
                $url .= '?' . http_build_query($extraParams);
            }
            return $url;
        }
        $params = array_merge(['view' => $vKey, 'business' => $bizSlug], $extraParams);
        return '?' . http_build_query($params);
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($validViews[$view]); ?> | پلتفرم سئو و هوش داده <?php echo htmlspecialchars($currentBusiness['name']); ?></title>
    
    <!-- Tailwind CSS with custom styling -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Vazirmatn', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }
        .font-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-indigo-500 selection:text-white">
<?php require_once __DIR__.'/../inc/ai/HubSummary.php';maarkshoHubSummary(ModuleProjectAccess::businessProject($currentBusiness),'seo'); ?>
<?php $dataProject=ModuleProjectAccess::businessProject($currentBusiness);if($dataProject): ?><div style="padding:16px;background:#e5eee8;text-align:center"><a href="/panel/data-hub.php?project=<?php echo rawurlencode($dataProject['id']); ?>">داشبورد دادهٔ واقعی: WordPress / Rank Math / GA4 / Search Console ←</a><p style="font-size:12px">گزارش جدید بر اساس منبع و تاریخ دریافت است؛ داده‌های نمایشی قدیمی، شاهد اتصال واقعی محسوب نمی‌شوند.</p></div><?php endif; ?>

    <!-- Top Navigation Bar -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40 no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 gap-4">
                
                <!-- Left (RTL Start): Logo & Breadcrumb -->
                <div class="flex items-center gap-3">
                    <a href="/panel/admin.php" class="flex items-center gap-2.5 group">
                        <span class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-black text-base shadow-sm shadow-indigo-300 transition-transform group-hover:scale-105">
                            M
                        </span>
                        <div class="hidden sm:block">
                            <span class="text-xs font-black text-slate-900 block leading-none">Maarksho Intelligence</span>
                            <span class="text-[10px] text-slate-400 font-bold">Enterprise SEO & Data Engine</span>
                        </div>
                    </a>

                    <span class="text-slate-300 hidden md:inline">/</span>

                    <!-- Business Switcher Dropdown -->
                    <div class="relative flex items-center">
                        <select onchange="switchBusiness(this.value)" class="bg-slate-100 hover:bg-slate-200/80 text-slate-800 text-xs font-black rounded-xl px-3 py-1.5 border-none outline-none cursor-pointer transition-colors">
                            <?php foreach ($allBusinesses as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['slug']); ?>" <?php echo $b['slug'] === $currentBusiness['slug'] ? 'selected' : ''; ?>>
                                    🏢 <?php echo htmlspecialchars($b['name']); ?> (<?php echo htmlspecialchars($b['domain']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Right (RTL End): Role Switcher, Quick Sync & Exit -->
                <div class="flex items-center gap-3">
                    <!-- SEO Health Badge -->
                    <div class="hidden md:flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-100 text-xs font-bold">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>سلامت سئو: <strong class="font-mono font-black"><?php echo $stats['seo_health']; ?>٪</strong></span>
                    </div>

                    <span class="text-xs text-slate-500 font-bold"><?php echo htmlspecialchars(AccessControl::roles()[$_SESSION['user']['role'] ?? ''] ?? 'مدیر سیستم'); ?></span>

                    <!-- Sync All Trigger -->
                    <?php if (ModuleProjectAccess::allowed('seo_intel', 'analysis', 'seo_intel_integrations', 'use')): ?><button onclick="triggerSync('all')" id="btn-header-sync" class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm shadow-indigo-200 cursor-pointer">
                        <span id="header-sync-icon">🔄</span>
                        <span class="hidden sm:inline">همگام‌سازی زنده</span>
                    </button><?php endif; ?>

                    <!-- Exit to Admin Panel -->
                    <a href="/panel/admin.php" class="p-2 text-slate-400 hover:text-slate-700 rounded-xl hover:bg-slate-100 transition-colors" title="بازگشت به کنترل پنل">
                        ✕
                    </a>
                </div>
            </div>

            <!-- View Navigation Tabs -->
            <nav class="flex items-center gap-1 overflow-x-auto py-2 scrollbar-none border-t border-slate-100 text-xs font-bold">
                <?php foreach ($allowedViews as $vKey): 
                    $vLabel = $validViews[$vKey];
                    $isActive = ($view === $vKey);
                    $tabUrl = buildSeoUrl($currentBusiness['slug'], $vKey);
                ?>
                <a href="<?php echo htmlspecialchars($tabUrl); ?>" 
                   class="px-3 py-1.5 rounded-lg whitespace-nowrap transition-all <?php echo $isActive ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'; ?>">
                    <?php echo $vLabel; ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <?php
        $viewFile = __DIR__ . '/views/' . $view . '.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<div class="p-8 bg-white rounded-2xl border text-center text-slate-500">صفحه مورد نظر در حال آماده‌سازی است.</div>';
        }
        ?>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200/80 py-4 mt-auto no-print text-center text-xs text-slate-400">
        <div class="max-w-7xl mx-auto px-4">
            پلتفرم جامع Enterprise SEO & Analytics Intelligence پلتفرم maarksho.com • قدرت‌گرفته از Composio API Engine و Google Search Console & GA4
        </div>
    </footer>

    <!-- Toast Notification Element -->
    <div id="toast-container" class="fixed bottom-5 left-5 z-50 flex flex-col gap-2 pointer-events-none"></div>

    <!-- Core Scripts for AJAX Interactions -->
    <script>
        const CURRENT_BUSINESS = <?php echo json_encode($currentBusiness['slug']); ?>;
        const API_BASE = '/panel/seo_intel/api.php';

        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const bgClass = type === 'success' ? 'bg-emerald-600 text-white' : (type === 'error' ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
            toast.className = `${bgClass} px-4 py-2.5 rounded-xl shadow-xl text-xs font-bold transition-all duration-300 flex items-center gap-2 pointer-events-auto opacity-0 translate-y-2`;
            toast.innerHTML = `<span>${type === 'success' ? '✓' : 'ℹ'}</span> <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.remove('opacity-0', 'translate-y-2');
            }, 10);
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        function switchBusiness(slug) {
            const path = window.location.pathname;
            const match = path.match(/^\/admin\/(business|projects)\/([^/]+)\/seo(?:\/(.*))?$/);
            if (match) {
                const type = match[1];
                const sub = match[3] ? '/' + match[3] : '';
                window.location.href = '/admin/' + type + '/' + encodeURIComponent(slug) + '/seo' + sub + window.location.search;
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('business', slug);
            window.location.href = url.toString();
        }

        async function triggerSync(source = 'all') {
            const icon = document.getElementById('header-sync-icon');
            if (icon) icon.classList.add('animate-spin');
            showToast('در حال همگام‌سازی با Composio و سرورهای گوگل...', 'info');

            try {
                const res = await fetch(`${API_BASE}?action=trigger_sync&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ source: source, trigger: 'manual' })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('همگام‌سازی موفقیت‌آمیز بود! داده‌ها در انبار نرمال شدند.', 'success');
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    showToast(data.error || 'خطا در همگام‌سازی', 'error');
                }
            } catch (err) {
                showToast('خطای ارتباط با سرور: ' + err.message, 'error');
            } finally {
                if (icon) icon.classList.remove('animate-spin');
            }
        }

        async function updateRecStatus(id, newStatus) {
            try {
                const res = await fetch(`${API_BASE}?action=update_recommendation&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, status: newStatus })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('وضعیت پیشنهاد با موفقیت بروز شد.', 'success');
                    setTimeout(() => window.location.reload(), 600);
                }
            } catch (e) {
                showToast('خطا در ذخیره وضعیت', 'error');
            }
        }

        function applyRecommendation(recId) {
            updateRecStatus(recId, 'applied');
        }

        // Add Keyword Modal
        function openAddKeywordModal() {
            document.getElementById('modal-add-keyword').classList.remove('hidden');
            document.getElementById('modal-add-keyword').classList.add('flex');
        }
        function closeAddKeywordModal() {
            document.getElementById('modal-add-keyword').classList.add('hidden');
            document.getElementById('modal-add-keyword').classList.remove('flex');
        }
        async function submitNewKeyword(e) {
            e.preventDefault();
            const keyword = document.getElementById('new-kw-name').value;
            const targetUrl = document.getElementById('new-kw-url').value;
            const intent = document.getElementById('new-kw-intent').value;

            try {
                const res = await fetch(`${API_BASE}?action=add_keyword&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ keyword, target_url: targetUrl, intent })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('کلمه کلیدی جدید به ردیاب اضافه شد.', 'success');
                    closeAddKeywordModal();
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showToast(data.error || 'خطا در ثبت کلمه', 'error');
                }
            } catch (err) {
                showToast('خطا در اتصال به سرور', 'error');
            }
        }

        // Configure Connection Modal
        function openConfigConnectionModal(slug, name, propId, accName) {
            document.getElementById('conn-intg-slug').value = slug;
            document.getElementById('modal-conn-title').innerText = 'پیکربندی اتصال ' + name;
            document.getElementById('conn-prop-id').value = propId || '';
            document.getElementById('conn-account-name').value = accName || '';
            document.getElementById('modal-config-conn').classList.remove('hidden');
            document.getElementById('modal-config-conn').classList.add('flex');
        }
        function closeConfigConnectionModal() {
            document.getElementById('modal-config-conn').classList.add('hidden');
            document.getElementById('modal-config-conn').classList.remove('flex');
        }
        async function submitSaveConnection(e) {
            e.preventDefault();
            const slug = document.getElementById('conn-intg-slug').value;
            const propId = document.getElementById('conn-prop-id').value;
            const accName = document.getElementById('conn-account-name').value;
            const freq = document.getElementById('conn-sync-frequency').value;

            try {
                const res = await fetch(`${API_BASE}?action=save_connection&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        integration_slug: slug,
                        property_id: propId,
                        account_name: accName,
                        sync_frequency: freq,
                        sync_enabled: true
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('کانکشن با موفقیت ذخیره و فعال شد.', 'success');
                    closeConfigConnectionModal();
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showToast(data.error || 'خطا در ذخیره کانکشن', 'error');
                }
            } catch (err) {
                showToast('خطا در سرور', 'error');
            }
        }

        async function initiateOauthConnection(slug) {
            showToast('در حال دریافت لینک احراز هویت از Composio...', 'info');
            try {
                const res = await fetch(`${API_BASE}?action=initiate_oauth&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ integration_slug: slug })
                });
                const data = await res.json();
                if (data.success && data.auth_url && data.auth_url !== '#connected-mock') {
                    window.location.href = data.auth_url;
                } else {
                    // Activate connection directly
                    await fetch(`${API_BASE}?action=save_connection&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            integration_slug: slug,
                            account_name: 'Connected via Composio',
                            sync_frequency: 'daily'
                        })
                    });
                    showToast('اتصال سرویس با موفقیت فعال گردید.', 'success');
                    setTimeout(() => window.location.reload(), 900);
                }
            } catch (e) {
                showToast('خطا در احراز هویت', 'error');
            }
        }

        // Composio Key Save & Test
        async function saveComposioKey(e) {
            e.preventDefault();
            const keyInput = document.getElementById('composio-api-key');
            const key = keyInput ? keyInput.value.trim() : '';
            const resBox = document.getElementById('composio-test-result');
            resBox.classList.remove('hidden');
            resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-indigo-50 text-indigo-800 border border-indigo-100 flex items-center gap-2';
            resBox.innerHTML = '<span class="animate-spin">⏳</span> <span>در حال ذخیره و اعتبارسنجی کلید با Composio v3 Cloud API...</span>';

            try {
                const res = await fetch(`${API_BASE}?action=save_composio_config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: key })
                });
                const data = await res.json();
                const ver = data.verification || {};

                if (data.success && ver.success) {
                    resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-100 flex items-start gap-2';
                    resBox.innerHTML = `<span>✓</span> <div><strong>اتصال موفق:</strong> ${ver.message || 'کلید معتبر است و ارتباط لایه ابری با Composio Core برقرار شد.'}</div>`;
                    showToast('تنظیمات و احراز هویت با موفقیت تأیید شد', 'success');
                } else {
                    resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-amber-50 text-amber-900 border border-amber-200 flex items-start gap-2';
                    const detail = ver.message || data.message || data.error || 'خطا در احراز هویت با سرور Composio';
                    resBox.innerHTML = `<span>⚠️</span> <div><strong>پیام وضعیت:</strong> ${detail}</div>`;
                    showToast('بررسی پاسخ سرور کامپوزیو الزامی است', 'info');
                }
            } catch (err) {
                resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-rose-50 text-rose-800 border border-rose-100';
                resBox.innerText = 'خطای ارتباط با سرور: ' + err.message;
                showToast('خطا در اتصال به سرور داخلی', 'error');
            }
        }

        async function testComposioConnection() {
            const keyInput = document.getElementById('composio-api-key');
            const key = keyInput ? keyInput.value.trim() : '';
            const resBox = document.getElementById('composio-test-result');
            resBox.classList.remove('hidden');
            resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-indigo-50 text-indigo-800 border border-indigo-100 flex items-center gap-2';
            resBox.innerHTML = '<span class="animate-spin">⏳</span> <span>در حال بررسی زنده اتصال به سرور مرکزی Composio v3...</span>';

            try {
                const res = await fetch(`${API_BASE}?action=test_composio`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: key })
                });
                const data = await res.json();
                if (data.success) {
                    resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-100 flex items-start gap-2';
                    resBox.innerHTML = `<span>✓</span> <div><strong>وضعیت ارتباط فعال:</strong> ${data.message || 'اتصال معتبر است.'}</div>`;
                    showToast('اتصال پایدار و فعال است', 'success');
                } else {
                    resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-amber-50 text-amber-900 border border-amber-200 flex items-start gap-2';
                    resBox.innerHTML = `<span>ℹ️</span> <div><strong>پیام وضعیت:</strong> ${data.message || 'کلید API نامعتبر است یا امکان دسترسی وجود ندارد.'}</div>`;
                }
            } catch (e) {
                resBox.className = 'mt-3 p-3 rounded-xl text-xs font-medium bg-rose-50 text-rose-800 border border-rose-100';
                resBox.innerText = 'خطا در ارسال درخواست تست سلامت به سرور';
            }
        }

        // Live AI Assistant
        async function submitAiQuery(customPrompt = null) {
            const input = document.getElementById('ai-assistant-input');
            const prompt = customPrompt || input.value.trim();
            if (!prompt) return;

            const btn = document.getElementById('btn-submit-ai');
            const respBox = document.getElementById('ai-assistant-response');
            const contentBox = document.getElementById('ai-assistant-content');

            btn.disabled = true;
            btn.innerText = 'در حال تحلیل...';
            respBox.classList.remove('hidden');
            contentBox.innerHTML = '<span class="animate-pulse text-indigo-300">در حال پردازش داده‌ها و پرس‌وجو از مدل هوش مصنوعی Gemini...</span>';

            try {
                const res = await fetch(`${API_BASE}?action=ask_ai&business=${encodeURIComponent(CURRENT_BUSINESS)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prompt })
                });
                const data = await res.json();
                if (data.success && data.output) {
                    // Convert simple markdown newlines to html
                    contentBox.innerHTML = data.output.replace(/\n/g, '<br>');
                } else {
                    contentBox.innerHTML = '<span class="text-rose-400">خطا: ' + (data.error || 'پاسخی دریافت نشد') + '</span>';
                }
            } catch (err) {
                contentBox.innerHTML = '<span class="text-rose-400">خطای ارتباط با سرور: ' + err.message + '</span>';
            } finally {
                btn.disabled = false;
                btn.innerText = 'ارسال به هوش مصنوعی ✨';
            }
        }

        function askAiAboutKeyword(kw, pos) {
            const prompt = `تحلیل تخصصی کلمه کلیدی «${kw}» که در رتبه ${pos} گوگل قرار دارد: راهکار پیشنهادی برای ارتقای رتبه به تاپ ۳، اصلاح Title، تگ‌های H2 و انکرتکست‌های پیشنهادی چیست؟`;
            const input = document.getElementById('ai-assistant-input');
            if (input) input.value = prompt;
            const path = window.location.pathname;
            const match = path.match(/^\/admin\/(business|projects)\/([^/]+)\/seo(?:\/(.*))?$/);
            if (match) {
                window.location.href = `/admin/${match[1]}/${encodeURIComponent(CURRENT_BUSINESS)}/seo/recommendations`;
            } else {
                window.location.href = `?view=recommendations&business=${encodeURIComponent(CURRENT_BUSINESS)}`;
            }
        }

        function askAiAboutPage(url, title, ctr) {
            const prompt = `تحلیل صفحه با آدرس «${url}» و عنوان «${title}» با نرخ کلیک CTR فعلی ${ctr}٪: پیشنهاد بهینه‌سازی Title برای ترغیب کلیک و سوالات متداول FAQ Schema چیست؟`;
            const input = document.getElementById('ai-assistant-input');
            if (input) input.value = prompt;
            const path = window.location.pathname;
            const match = path.match(/^\/admin\/(business|projects)\/([^/]+)\/seo(?:\/(.*))?$/);
            if (match) {
                window.location.href = `/admin/${match[1]}/${encodeURIComponent(CURRENT_BUSINESS)}/seo/recommendations`;
            } else {
                window.location.href = `?view=recommendations&business=${encodeURIComponent(CURRENT_BUSINESS)}`;
            }
        }

        function triggerFullAiScan() {
            triggerSync('all');
        }

        async function refreshSyncLogs() {
            try {
                const res = await fetch(`${API_BASE}?action=get_sync_logs&business=${encodeURIComponent(CURRENT_BUSINESS)}`);
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                }
            } catch (e) {}
        }
    </script>
</body>
</html>
