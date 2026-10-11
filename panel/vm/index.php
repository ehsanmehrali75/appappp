<?php
/**
 * Visual Merchandising Intelligence (VM) - Master Controller & Layout
 * Route: /admin/projects/{project-slug}/vm
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/vm_db.php';
require_once __DIR__ . '/vm_mflow.php';
require_once __DIR__ . '/vm_ai.php';

$allProjects = VmDB::getAllProjects();
$defaultSlug = !empty($allProjects) ? $allProjects[0]['slug'] : '';

// Detect project slug from URL or GET parameter or session
$projectSlug = $_GET['project_slug'] ?? ($_GET['project'] ?? ($_SESSION['current_project'] ?? ($_SESSION['project_id'] ?? $defaultSlug)));
$project = VmDB::getProject($projectSlug);
if (!$project && !empty($allProjects)) {
    $project = $allProjects[0];
    $projectSlug = $project['slug'];
}

// -------------------------------------------------------------
// Access Control Enforcement:
// Check user login, project assignment, and VM module permissions
// -------------------------------------------------------------
$isSuper = function_exists('isSuperAdmin') ? isSuperAdmin() : false;
$isProjAdmin = function_exists('isProjectAdmin') ? isProjectAdmin() : false;
$currentUser = $_SESSION['user'] ?? null;

// Require login
if (!$isSuper && empty($currentUser) && function_exists('isUser') && !isUser()) {
    header('Location: /panel/index.php');
    exit;
}

// If not super admin, verify project assignment and VM permission
if (!$isSuper && $currentUser) {
    // 1. Verify project assignment (if user is restricted to specific projects)
    $assignedProjects = $currentUser['assigned_projects'] ?? [];
    if (!empty($assignedProjects) && is_array($assignedProjects)) {
        $projId = $project['id'] ?? '';
        $projSlug = $project['slug'] ?? '';
        if (!in_array($projId, $assignedProjects) && !in_array($projSlug, $assignedProjects)) {
            http_response_code(403);
            ?>
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <title>عدم دسترسی به پروژه | استودیو چیدمان</title>
                <script src="https://cdn.tailwindcss.com"></script>
                <link rel="stylesheet" href="/panel/assets/fonts/vazirmatn.css">
                <style>body { font-family: 'Vazirmatn', Tahoma, sans-serif; }</style>
            </head>
            <body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
                <div class="bg-white max-w-md w-full rounded-3xl p-8 border border-slate-200 shadow-xl text-center">
                    <div class="w-16 h-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl font-black">⛔</div>
                    <h2 class="text-xl font-black text-slate-800 mb-2">عدم دسترسی به این پروژه</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">حساب کاربری شما (<?php echo htmlspecialchars($currentUser['username'] ?? 'کاربر'); ?>) به پروژه «<?php echo htmlspecialchars($project['name']); ?>» دسترسی ندارد.</p>
                    <a href="/panel/admin.php" class="inline-block w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-indigo-600/20">بازگشت به پنل مدیریت</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    // 2. Verify VM permission
    $hasVmPermission = function_exists('hasPermission') ? (hasPermission('vm') || hasPermission('layout')) : false;
    if (!$isProjAdmin && !$hasVmPermission) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>عدم دسترسی به چیدمان (VM) | استودیو چیدمان</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="/panel/assets/fonts/vazirmatn.css">
            <style>body { font-family: 'Vazirmatn', Tahoma, sans-serif; }</style>
        </head>
        <body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
            <div class="bg-white max-w-md w-full rounded-3xl p-8 border border-slate-200 shadow-xl text-center">
                <div class="w-16 h-16 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl font-black">🔒</div>
                <h2 class="text-xl font-black text-slate-800 mb-2">دسترسی بخش چیدمان محدود است</h2>
                <p class="text-xs text-slate-500 leading-relaxed mb-2">مجوز دسترسی به «هوش چیدمان و استودیو VM» برای کاربر «<?php echo htmlspecialchars($currentUser['username'] ?? 'کاربر'); ?>» فعال نشده است.</p>
                <p class="text-[11px] text-slate-400 mb-6">جهت فعال‌سازی این بخش، با مدیر ارشد یا مدیر پروژه تماس بگیرید.</p>
                <a href="/panel/admin.php" class="inline-block w-full py-3 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition">بازگشت به پنل اصلی</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Current active view tab
$view = $_GET['view'] ?? 'overview';
$validViews = ['overview', 'stores', 'store', 'studio', 'analytics', 'scores', 'recommendations', 'reports'];
if (!in_array($view, $validViews)) {
    $view = 'overview';
}

// Determine if routed via pretty URL or direct query params
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isPretty = (strpos($requestUri, '/admin/projects/') !== false);

if (!function_exists('vm_url')) {
    function vm_url($targetView = 'overview', $params = []) {
        global $project, $isPretty, $defaultSlug;
        $slug = urlencode($project['slug'] ?? ($defaultSlug ?: 'project'));
        
        if ($isPretty) {
            if ($targetView === 'overview') {
                $url = "/admin/projects/{$slug}/vm";
            } elseif ($targetView === 'store' && !empty($params['store'])) {
                $url = "/admin/projects/{$slug}/vm/stores/" . urlencode($params['store']);
                unset($params['store']);
            } elseif ($targetView === 'stores') {
                $url = "/admin/projects/{$slug}/vm/stores";
            } else {
                $url = "/admin/projects/{$slug}/vm/{$targetView}";
            }
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        } else {
            $p = array_merge([
                'project' => $project['slug'] ?? ($defaultSlug ?: 'project'),
                'view' => $targetView
            ], $params);
            return '/panel/vm/?' . http_build_query($p);
        }
    }
}

// Base URL for backwards compatibility
$baseUrl = vm_url('overview');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Merchandising Intelligence (VM) | <?php echo htmlspecialchars($project['name']); ?></title>
    
    <!-- Tailwind CSS -->
    <script src="/assets/tailwindcss.js"></script>
    <script>
        // Suppress benign warnings
        (function(){
            var origWarn = console.warn;
            console.warn = function() {
                var str = "";
                for (var i = 0; i < arguments.length; i++) {
                    if (arguments[i]) str += " " + String(arguments[i]);
                }
                if (str.indexOf('tailwindcss') !== -1 && (str.indexOf('production') !== -1 || str.indexOf('cdn.tailwindcss.com') !== -1)) return;
                return origWarn.apply(console, arguments);
            };
        })();
    </script>
    
    <!-- Persian Vazirmatn Font & Styling -->
    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn'), local('Vazir'), url('/assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
            font-weight: normal;
        }
        @font-face {
            font-family: 'Vazirmatn';
            src: local('Vazirmatn-Bold'), local('Vazir-Bold'), url('/assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
            font-weight: bold;
        }
        body {
            font-family: 'Vazirmatn', 'Tahoma', -apple-system, BlinkMacSystemFont, Arial, sans-serif !important;
            background-color: #f8fafc;
            color: #0f172a;
        }
        /* Hide scrollbars in clean canvas */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 flex flex-col selection:bg-indigo-500 selection:text-white antialiased">

    <!-- Top Unified Enterprise Bar -->
    <header class="bg-slate-900 text-white border-b border-slate-800 sticky top-0 z-40 shadow-md">
        <div class="w-full px-3 sm:px-6 lg:px-8 h-14 sm:h-16 flex items-center justify-between gap-3">
            <!-- Left Brand & Project Switcher -->
            <div class="flex items-center gap-3">
                <a href="<?php echo vm_url('overview'); ?>" class="flex items-center gap-2 group text-white">
                    <span class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-indigo-400 flex items-center justify-center text-base sm:text-lg shadow-lg shadow-indigo-500/30 group-hover:scale-105 transition">
                        🛍️
                    </span>
                    <div>
                        <div class="text-xs sm:text-sm font-black flex items-center gap-1.5">
                            <span>Visual Merchandising (VM)</span>
                            <span class="text-[9px] sm:text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-mono font-bold">INTELLIGENCE</span>
                        </div>
                        <div class="text-[9px] sm:text-[10px] text-slate-400 hidden sm:block">پنل هوشمند مدیریت و تحلیل چیدمان فروشگاهی</div>
                    </div>
                </a>

                <div class="h-6 w-px bg-slate-800 hidden md:block"></div>

                <!-- Project Switcher Dropdown (Supports both Pretty URL & Direct query) -->
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] text-slate-400 font-medium hidden sm:inline">پروژه:</span>
                    <div class="relative inline-block">
                        <select onchange="switchVmProject(this.value)" class="bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold py-1 px-2.5 rounded-xl border border-slate-700 focus:outline-none focus:border-indigo-500 transition cursor-pointer max-w-[140px] sm:max-w-[200px] truncate">
                            <?php foreach ($allProjects as $pItem): ?>
                            <option value="<?php echo $pItem['slug']; ?>" <?php echo $pItem['slug'] === $project['slug'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pItem['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Right Controls: Back to Admin, Help, Profile -->
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="/panel/admin.php" class="px-2.5 sm:px-3 py-1.5 text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-xl border border-slate-700 transition flex items-center gap-1.5">
                    <span>←</span>
                    <span class="hidden sm:inline">بازگشت به پنل اصلی</span>
                </a>

                <div class="flex items-center gap-2 border-r border-slate-800 pr-2 mr-1">
                    <span class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-xs font-bold text-slate-300">
                        👨‍💼
                    </span>
                    <span class="text-xs text-slate-300 font-bold hidden lg:inline">
                        <?php echo htmlspecialchars($_SESSION['user']['username'] ?? 'مدیر ارشد'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Submenus Navigation Bar (6 Pillars: Stores, VM Studio, Layout Analytics, Store Score, AI Recommendations, Reports) -->
        <div class="bg-slate-950 border-t border-slate-800/80 shadow-inner">
            <div class="w-full px-2 sm:px-6 lg:px-8 flex items-center gap-1 overflow-x-auto no-scrollbar text-xs font-bold py-1">
                <a href="<?php echo vm_url('overview'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'overview' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>⚡</span>
                    <span>داشبورد کلان (Overview)</span>
                </a>

                <a href="<?php echo vm_url('stores'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo ($view === 'stores' || $view === 'store') ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>🏪</span>
                    <span>۱. مدیریت شعب (Stores)</span>
                </a>

                <a href="<?php echo vm_url('studio'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'studio' ? 'bg-indigo-600 text-white shadow ring-2 ring-indigo-400/50' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>📐</span>
                    <span>۲. استودیو چیدمان (VM Studio)</span>
                </a>

                <a href="<?php echo vm_url('analytics'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'analytics' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>📊</span>
                    <span>۳. تحلیل چیدمان (Layout Analytics)</span>
                </a>

                <a href="<?php echo vm_url('scores'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'scores' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>🏆</span>
                    <span>۴. امتیاز فروشگاه (Store Score)</span>
                </a>

                <a href="<?php echo vm_url('recommendations'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'recommendations' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>🧠</span>
                    <span>۵. هوش مصنوعی (AI Recommendations)</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                </a>

                <a href="<?php echo vm_url('reports'); ?>" class="px-3 py-1.5 rounded-xl transition flex items-center gap-1.5 whitespace-nowrap <?php echo $view === 'reports' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'; ?>">
                    <span>📑</span>
                    <span>۶. گزارشات (Reports)</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Dynamic Content Container (Fluid in Studio mode, Contained in Dashboard mode) -->
    <main class="flex-1 w-full <?php echo $view === 'studio' ? 'p-1 sm:p-3 flex flex-col' : 'max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-5 sm:py-6'; ?>">
        <?php
        $viewFile = __DIR__ . '/views/' . ($view === 'store' ? 'store_detail.php' : $view . '.php');
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            require __DIR__ . '/views/overview.php';
        }
        ?>
    </main>

    <!-- Global Scripts -->
    <script>
    function triggerMflowSync() {
        const btn = document.getElementById('btn-sync-mflow');
        if (btn) {
            btn.innerHTML = '<span>⏳</span><span>در حال دریافت داده‌های mflow...</span>';
            btn.disabled = true;
        }

        fetch('/panel/vm/api.php?action=mflow_sync&project_id=<?php echo urlencode($project['slug']); ?>')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message + '\nتعداد رکورد سینک شده: ' + data.synced_records);
                    window.location.reload();
                } else {
                    alert(data.message || 'خطا در همگام‌سازی');
                }
            })
            .catch(err => {
                alert('خطا در برقراری ارتباط با دیتالیر mflow');
                if (btn) {
                    btn.innerHTML = '<span>🔄</span><span>همگام‌سازی فوری mflow</span>';
                    btn.disabled = false;
                }
            });
    }

    function switchVmProject(slug) {
        const isPretty = <?php echo $isPretty ? 'true' : 'false'; ?>;
        const currentView = <?php echo json_encode($view); ?>;
        if (isPretty) {
            let path = '/admin/projects/' + encodeURIComponent(slug) + '/vm';
            if (currentView && currentView !== 'overview' && currentView !== 'store') {
                path += '/' + encodeURIComponent(currentView);
            }
            window.location.href = path;
        } else {
            window.location.href = '/panel/vm/?project=' + encodeURIComponent(slug) + '&view=' + encodeURIComponent(currentView);
        }
    }

    // Universal navigation helper safe in both pretty URL and direct query parameter setups
    window.vmNavigate = function(targetView, params = {}) {
        const isPretty = <?php echo $isPretty ? 'true' : 'false'; ?>;
        const projectSlug = <?php echo json_encode($project['slug']); ?>;
        if (isPretty) {
            let path = '/admin/projects/' + encodeURIComponent(projectSlug) + '/vm';
            if (targetView && targetView !== 'overview') {
                if (targetView === 'store' && params.store) {
                    path += '/stores/' + encodeURIComponent(params.store);
                    const rest = { ...params };
                    delete rest.store;
                    const qs = new URLSearchParams(rest).toString();
                    if (qs) path += '?' + qs;
                    window.location.href = path;
                    return;
                } else {
                    path += '/' + encodeURIComponent(targetView);
                }
            }
            const qs = new URLSearchParams(params).toString();
            if (qs) path += '?' + qs;
            window.location.href = path;
        } else {
            const all = Object.assign({ project: projectSlug, view: targetView || 'overview' }, params);
            window.location.href = '/panel/vm/?' + new URLSearchParams(all).toString();
        }
    };
    </script>
</body>
</html>
