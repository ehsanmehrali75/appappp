<?php
/**
 * VM Studio - Visual Layout Builder & Interactive 2D/3D Canvas
 * Highly Responsive, Enterprise Modern SaaS Dashboard
 */

$projectId = $project['id'] ?? ($project['slug'] ?? '');
$allStores = VmDB::getStoresByProject($projectId);
$defaultStoreId = !empty($allStores) ? $allStores[0]['id'] : '';
$storeSlug = $_GET['store'] ?? ($_GET['store_id'] ?? $defaultStoreId);
$store = VmDB::getStore($storeSlug, $projectId);
if (!$store && !empty($allStores)) {
    $store = reset($allStores);
}

$layout = VmDB::getStoreLayout($store['id'] ?? '');
$allProducts = VmDB::getProductsByProject($projectId);
?>

<div class="flex flex-col h-[calc(100vh-125px)] min-h-[580px] w-full bg-slate-900 rounded-2xl overflow-hidden border border-slate-800 shadow-2xl relative">
    
    <!-- Studio Header & Responsive Control Bar -->
    <div class="bg-slate-950/95 backdrop-blur border-b border-slate-800 px-3 sm:px-4 py-2 flex flex-wrap lg:flex-nowrap items-center justify-between gap-2 select-none z-30 min-h-14">
        
        <!-- Right Section (in RTL): Studio Title & Store Select -->
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">
            <div class="flex items-center gap-2">
                <span class="text-lg sm:text-xl">📐</span>
                <div>
                    <div class="text-xs sm:text-sm font-black text-white flex items-center gap-1.5">
                        <span>VM Studio</span>
                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-indigo-500/20 text-indigo-300 font-mono" id="layout-version-badge">
                            <?php echo htmlspecialchars($layout['version'] ?? 'v2.4'); ?>
                        </span>
                    </div>
                    <span class="text-[10px] text-slate-400 hidden sm:block" id="layout-name-display"><?php echo htmlspecialchars($layout['name'] ?? 'چیدمان اصلی'); ?></span>
                </div>
            </div>

            <div class="h-5 w-px bg-slate-800 hidden sm:block"></div>

            <!-- Store Selector -->
            <div class="flex items-center gap-1.5">
                <label class="text-[11px] text-slate-400 font-medium hidden md:inline">شعبه:</label>
                <select onchange="window.vmNavigate('studio', { store: this.value })" class="bg-slate-900 text-white text-xs font-bold border border-slate-700 rounded-xl px-2.5 py-1.5 focus:outline-none focus:border-indigo-500 max-w-[130px] sm:max-w-[190px] truncate cursor-pointer">
                    <?php foreach ($allStores as $st): ?>
                    <option value="<?php echo $st['slug']; ?>" <?php echo $st['id'] === $store['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($st['name']); ?> (<?php echo $st['store_type']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Center Section: Canvas & Visualization Controls -->
        <div class="flex items-center gap-1.5 bg-slate-900/90 p-1 rounded-xl border border-slate-800 overflow-x-auto no-scrollbar max-w-full">
            <!-- Zoom Controls -->
            <div class="flex items-center bg-slate-950/80 rounded-lg p-0.5 border border-slate-800">
                <button onclick="changeZoom(-0.1)" title="کوچک‌نمایی" class="px-2 py-1 hover:bg-slate-800 text-slate-300 rounded text-xs font-mono transition">-</button>
                <span id="zoom-level-text" class="text-[10px] sm:text-[11px] font-mono font-bold text-slate-200 px-1.5 min-w-[38px] text-center">100%</span>
                <button onclick="changeZoom(0.1)" title="بزرگ‌نمایی" class="px-2 py-1 hover:bg-slate-800 text-slate-300 rounded text-xs font-mono transition">+</button>
                <button onclick="autoFitStage()" title="تنظیم خودکار با ابعاد صفحه (Fit Screen)" class="px-2 py-1 hover:bg-slate-800 text-indigo-300 rounded text-[10px] font-bold transition flex items-center gap-0.5">
                    <span>⤢</span>
                    <span class="hidden sm:inline">تناسب</span>
                </button>
            </div>

            <div class="h-4 w-px bg-slate-800 mx-0.5"></div>

            <!-- Grid Toggle -->
            <button onclick="toggleGrid()" id="btn-grid" title="تغییر نمایش خطوط راهنما و اسنپ" class="px-2 sm:px-2.5 py-1.5 bg-slate-800 text-indigo-300 rounded-lg text-xs font-bold transition flex items-center gap-1 shrink-0">
                <span>▦</span>
                <span class="text-[10px] hidden sm:inline">گرید</span>
            </button>

            <!-- Heatmap Layer Selector -->
            <div class="flex items-center gap-1 bg-slate-950 px-2 py-1 rounded-lg border border-slate-800 shrink-0">
                <span class="text-[10px] text-slate-400 hidden md:inline">هیت‌مپ:</span>
                <select id="heatmap-mode-select" onchange="setHeatmapMode(this.value)" class="bg-transparent text-[11px] text-amber-300 font-bold focus:outline-none cursor-pointer">
                    <option value="none" class="bg-slate-900 text-white">خاموش</option>
                    <option value="traffic" selected class="bg-slate-900 text-white">🔥 تردد (Footfall)</option>
                    <option value="dwell" class="bg-slate-900 text-white">⏱ توقف (Dwell)</option>
                    <option value="sales" class="bg-slate-900 text-white">💰 تراکم فروش (Sales)</option>
                </select>
            </div>

            <!-- Digital Twin 3D View Toggle -->
            <button onclick="toggle3DView()" id="btn-3d-toggle" title="تغییر به مدل دوقلوی دیجیتال سه‌بعدی" class="px-2.5 py-1.5 bg-indigo-950/80 text-indigo-300 hover:bg-indigo-900 border border-indigo-700/50 rounded-lg text-xs font-bold transition flex items-center gap-1 shrink-0">
                <span>🧊</span>
                <span class="text-[10px]">3D</span>
            </button>
        </div>

        <!-- Left Section (in RTL): Responsive Drawers & Save Actions -->
        <div class="flex items-center gap-1.5 shrink-0">
            <!-- Mobile Drawers Toggles -->
            <button onclick="togglePalette()" title="المان‌ها و کاتالوگ" class="lg:hidden px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-xl border border-slate-700 transition flex items-center gap-1">
                <span>📦</span>
                <span class="text-[10px]">المان‌ها</span>
            </button>

            <button onclick="toggleInspector()" title="تنظیمات شیء انتخابی" class="lg:hidden px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-xl border border-slate-700 transition flex items-center gap-1">
                <span>⚙️</span>
                <span class="text-[10px]">تنظیمات</span>
            </button>

            <button onclick="openCompareModal()" title="مقایسه نسخه‌های چیدمان" class="hidden sm:flex px-2.5 sm:px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-xl border border-slate-700 transition items-center gap-1">
                <span>⚖️</span>
                <span class="text-[10px] sm:text-xs">مقایسه</span>
            </button>

            <!-- Save Layout Button (Always Visible & Protected from clipping) -->
            <button onclick="saveCurrentLayout()" id="btn-save-layout" class="px-3 sm:px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-indigo-600/30 transition flex items-center gap-1.5 shrink-0">
                <span>💾</span>
                <span class="whitespace-nowrap">ذخیره چیدمان</span>
            </button>
        </div>
    </div>

    <!-- Main Workspace (3 Column: Palette, Canvas, Inspector) -->
    <div class="flex-1 flex overflow-hidden relative" id="studio-workspace">
        
        <!-- Mobile Drawer Backdrop -->
        <div id="drawer-backdrop" onclick="closeAllDrawers()" class="fixed inset-0 bg-black/60 backdrop-blur-xs z-30 hidden transition-opacity lg:hidden"></div>

        <!-- 1. Right Column in RTL: Element Palette & Catalog -->
        <aside id="palette-aside" class="w-64 bg-slate-950/95 lg:bg-slate-950/70 backdrop-blur border-l border-slate-800 flex flex-col z-40 lg:z-20 select-none fixed inset-y-0 right-0 lg:static lg:inset-auto h-full shadow-2xl lg:shadow-none translate-x-full lg:translate-x-0 transition-transform duration-300">
            <!-- Mobile Header for Palette -->
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800 lg:hidden">
                <span class="text-xs font-black text-white flex items-center gap-2">
                    <span>📦</span>
                    <span>المان‌ها و کاتالوگ محصولات</span>
                </span>
                <button onclick="togglePalette(false)" class="text-slate-400 hover:text-white text-base">✕</button>
            </div>

            <!-- Tab Switcher -->
            <div class="flex border-b border-slate-800 text-xs font-bold shrink-0">
                <button onclick="switchPaletteTab('objects')" id="tab-btn-objects" class="flex-1 py-2.5 text-center border-b-2 border-indigo-500 text-indigo-400 bg-slate-900/50 transition">
                    المان‌های فروشگاهی
                </button>
                <button onclick="switchPaletteTab('products')" id="tab-btn-products" class="flex-1 py-2.5 text-center border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition">
                    کاتالوگ کالا
                </button>
            </div>

            <!-- Tab 1: Store Elements Palette -->
            <div id="palette-objects" class="flex-1 overflow-y-auto p-3 space-y-3">
                <div class="text-[10px] font-black text-slate-500 uppercase tracking-wider">سازه و استندهای چیدمان (کلیک یا درگ)</div>

                <div class="grid grid-cols-2 gap-2">
                    <div draggable="true" ondragstart="handleDragNewObject(event, 'rack')" onclick="addElementToCenter('rack')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🧥</div>
                        <div class="text-xs font-bold text-slate-300">رگال آویز</div>
                        <div class="text-[9px] text-slate-500">Rack</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'shelf')" onclick="addElementToCenter('shelf')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">👟</div>
                        <div class="text-xs font-bold text-slate-300">استند طبقاتی</div>
                        <div class="text-[9px] text-slate-500">Shelf</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'mannequin')" onclick="addElementToCenter('mannequin')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🧍</div>
                        <div class="text-xs font-bold text-slate-300">مانکن ست</div>
                        <div class="text-[9px] text-slate-500">Mannequin</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'display_table')" onclick="addElementToCenter('display_table')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🪑</div>
                        <div class="text-xs font-bold text-slate-300">میز نمایش سنتر</div>
                        <div class="text-[9px] text-slate-500">Display Table</div>
                    </div>
                </div>

                <div class="text-[10px] font-black text-slate-500 uppercase tracking-wider pt-2">معماری و سازه‌های ثابت</div>

                <div class="grid grid-cols-2 gap-2">
                    <div draggable="true" ondragstart="handleDragNewObject(event, 'counter')" onclick="addElementToCenter('counter')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">💳</div>
                        <div class="text-xs font-bold text-slate-300">میز صندوق</div>
                        <div class="text-[9px] text-slate-500">Counter</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'entrance')" onclick="addElementToCenter('entrance')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🚪</div>
                        <div class="text-xs font-bold text-slate-300">ورودی درب</div>
                        <div class="text-[9px] text-slate-500">Entrance</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'window')" onclick="addElementToCenter('window')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🪟</div>
                        <div class="text-xs font-bold text-slate-300">ویترین شیشه</div>
                        <div class="text-[9px] text-slate-500">Glass Window</div>
                    </div>

                    <div draggable="true" ondragstart="handleDragNewObject(event, 'wall')" onclick="addElementToCenter('wall')" class="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500/50 cursor-grab active:cursor-grabbing text-center transition group">
                        <div class="text-2xl mb-1 group-hover:scale-110 transition">🧱</div>
                        <div class="text-xs font-bold text-slate-300">دیوار پارتیشن</div>
                        <div class="text-[9px] text-slate-500">Wall</div>
                    </div>
                </div>
                
                <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800 text-[11px] text-slate-400 leading-relaxed">
                    💡 <strong>راهنما:</strong> می‌توانید المان را با ماوس به داخل نقشه بکشید یا فقط روی آن کلیک کنید تا در مرکز نقشه قرار گیرد.
                </div>
            </div>

            <!-- Tab 2: Products Catalog for Drag & Drop onto Elements -->
            <div id="palette-products" class="flex-1 overflow-y-auto p-3 space-y-2 hidden">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[10px] font-black text-slate-500 uppercase tracking-wider">کتابخانه و کاتالوگ کالا</div>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-500/20 text-indigo-300 font-bold" id="product-count-badge">
                        <?php echo count($allProducts); ?> کالا
                    </span>
                </div>
                <!-- Search Box for Products -->
                <div class="relative mb-2">
                    <input type="text" id="vm-product-search" oninput="filterVmProducts(this.value)" placeholder="جستجوی نام یا SKU..." class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
                    <span class="absolute left-2.5 top-1.5 text-xs text-slate-500 pointer-events-none">🔍</span>
                </div>
                <div id="vm-products-list" class="space-y-2">
                <?php if (empty($allProducts)): ?>
                <div class="p-4 rounded-xl bg-slate-900 border border-dashed border-slate-800 text-center text-slate-500">
                    <div class="text-2xl mb-1">📦</div>
                    <div class="text-xs font-bold">هیچ کالایی در کتابخانه یافت نشد</div>
                    <div class="text-[10px] mt-1">محصولات به صورت خودکار بارگذاری می‌شوند</div>
                </div>
                <?php else: ?>
                <?php foreach ($allProducts as $p): ?>
                <div draggable="true" ondragstart="handleDragProduct(event, '<?php echo $p['sku']; ?>')" onclick="linkProductToSelected('<?php echo $p['sku']; ?>')" data-sku="<?php echo htmlspecialchars(strtolower($p['sku'])); ?>" data-name="<?php echo htmlspecialchars(mb_strtolower($p['name'])); ?>" class="vm-product-card p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-indigo-500 cursor-grab active:cursor-grabbing transition flex items-center gap-3 group" title="کلیک برای چیدن روی شلف انتخابی یا درگ روی نقشه">
                    <img src="<?php echo htmlspecialchars($p['image']); ?>" alt="" class="w-10 h-10 rounded-lg object-cover bg-slate-800 shrink-0 group-hover:scale-105 transition">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-bold text-slate-200 truncate group-hover:text-indigo-300 transition"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="text-[10px] text-slate-400 font-mono mt-0.5">SKU: <?php echo htmlspecialchars($p['sku']); ?></div>
                        <div class="flex items-center justify-between mt-1">
                            <span class="text-[10px] text-emerald-400 font-bold"><?php echo $p['stock'] !== null ? ($p['stock'] . ' عدد') : 'بدون دیتا'; ?></span>
                            <span class="text-[10px] text-indigo-300 font-bold"><?php echo $p['price_formatted']; ?></span>
                        </div>
                    </div>
                    <button type="button" onclick="event.stopPropagation(); linkProductToSelected('<?php echo $p['sku']; ?>');" class="opacity-0 group-hover:opacity-100 p-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-bold transition shrink-0" title="افزودن به شلف انتخابی">
                        +
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- 2. Center: Interactive Canvas / Stage Container -->
        <main class="flex-1 overflow-hidden relative bg-slate-950 flex items-center justify-center p-2 sm:p-4" id="canvas-container">
            
            <!-- Canvas Stage (Zoomable, Pannable & Auto-fit) -->
            <div id="vm-stage" class="relative bg-slate-900 border-2 border-slate-800 rounded-2xl shadow-2xl transition-transform duration-100 ease-out select-none"
                 style="width: 960px; height: 760px; transform-origin: center center;"
                 ondragover="event.preventDefault()"
                 ondrop="handleCanvasDrop(event)">

                <!-- Grid Canvas Pattern -->
                <div id="grid-overlay" class="absolute inset-0 bg-[radial-gradient(#334155_1px,transparent_1px)] [background-size:20px_20px] pointer-events-none rounded-2xl"></div>

                <!-- Heatmap Layer Overlay -->
                <div id="heatmap-overlay" class="absolute inset-0 pointer-events-none rounded-2xl transition-opacity duration-300">
                    <div class="absolute w-72 h-72 rounded-full bg-red-500/25 blur-3xl" style="top: 500px; left: 350px;"></div>
                    <div class="absolute w-64 h-64 rounded-full bg-amber-500/20 blur-2xl" style="top: 100px; left: 100px;"></div>
                    <div class="absolute w-56 h-56 rounded-full bg-blue-500/15 blur-2xl" style="top: 80px; left: 700px;"></div>
                </div>

                <!-- Zone Delimiters and Labels -->
                <div class="absolute top-4 right-4 px-3 py-1.5 bg-red-950/60 border border-red-800/40 rounded-lg text-[10px] font-black text-red-300 font-mono pointer-events-none">
                    ZONE C: نقطه کور انبار (Low Footfall)
                </div>
                <div class="absolute bottom-4 left-6 px-3 py-1.5 bg-emerald-950/60 border border-emerald-800/40 rounded-lg text-[10px] font-black text-emerald-300 font-mono pointer-events-none">
                    ZONE A: ورودی و کانون توجه (Prime Traffic)
                </div>

                <!-- Objects Container (Rendered by JavaScript) -->
                <div id="canvas-objects" class="absolute inset-0">
                    <!-- Objects will be dynamically injected here -->
                </div>
            </div>
        </main>

        <!-- 3. Left Column in RTL: Object Inspector & Product Linker -->
        <aside id="inspector-drawer" class="w-72 xl:w-80 bg-slate-950/95 lg:bg-slate-950/90 backdrop-blur border-r border-slate-800 p-4 sm:p-5 flex flex-col z-40 lg:z-20 select-none overflow-y-auto fixed inset-y-0 left-0 lg:static lg:inset-auto h-full shadow-2xl lg:shadow-none -translate-x-full lg:translate-x-0 transition-transform duration-300">
            
            <!-- Mobile Header for Inspector -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-800 lg:hidden mb-3">
                <span class="text-xs font-black text-white flex items-center gap-2">
                    <span>⚙️</span>
                    <span>مشخصات شیء انتخابی</span>
                </span>
                <button onclick="toggleInspector(false)" class="text-slate-400 hover:text-white text-base">✕</button>
            </div>

            <!-- Empty State when nothing is selected -->
            <div id="inspector-empty" class="flex-1 flex flex-col items-center justify-center text-center text-slate-500 py-10">
                <div class="text-4xl mb-3 opacity-40">👆</div>
                <p class="text-xs font-bold text-slate-400">یک المان را در بوم انتخاب کنید</p>
                <p class="text-[11px] text-slate-500 mt-1 max-w-[200px]">برای مشاهده ابعاد، چرخش، اتصال محصول و تحلیل فروش، روی هر شیء کلیک نمایید.</p>
            </div>

            <!-- Content state when an object is selected -->
            <div id="inspector-content" class="space-y-4 hidden">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <span class="text-[10px] font-mono text-indigo-400 uppercase tracking-wider block" id="ins-type-label">RACK</span>
                        <h3 class="text-sm font-black text-white truncate" id="ins-title-display">رگال شماره ۳</h3>
                    </div>
                    <button onclick="deleteSelectedObject()" class="p-1.5 text-slate-400 hover:text-red-400 rounded-lg hover:bg-slate-800 transition" title="حذف شیء">
                        🗑️
                    </button>
                </div>

                <!-- Transform Controls -->
                <div class="space-y-3 bg-slate-900 p-3.5 rounded-xl border border-slate-800">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">موقعیت و زوایای چرخش</span>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">عرض (Width)</label>
                            <input type="number" id="ins-width" onchange="updateSelectedProp('width', parseInt(this.value))" class="w-full bg-slate-950 border border-slate-700 text-white rounded-lg p-2 font-mono text-xs">
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">طول (Height)</label>
                            <input type="number" id="ins-height" onchange="updateSelectedProp('height', parseInt(this.value))" class="w-full bg-slate-950 border border-slate-700 text-white rounded-lg p-2 font-mono text-xs">
                        </div>
                    </div>

                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">زاویه چرخش (Rotation)</label>
                        <div class="grid grid-cols-4 gap-1">
                            <button onclick="updateSelectedProp('rotation', 0)" class="py-1 bg-slate-800 hover:bg-indigo-600 text-white rounded text-[11px] font-mono">0°</button>
                            <button onclick="updateSelectedProp('rotation', 45)" class="py-1 bg-slate-800 hover:bg-indigo-600 text-white rounded text-[11px] font-mono">45°</button>
                            <button onclick="updateSelectedProp('rotation', 90)" class="py-1 bg-slate-800 hover:bg-indigo-600 text-white rounded text-[11px] font-mono">90°</button>
                            <button onclick="updateSelectedProp('rotation', 180)" class="py-1 bg-slate-800 hover:bg-indigo-600 text-white rounded text-[11px] font-mono">180°</button>
                        </div>
                    </div>
                </div>

                <!-- Multi-Product Connection Section -->
                <div class="bg-slate-900 p-4 rounded-xl border border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-black text-indigo-400 uppercase tracking-wider">کالاهای چیده‌شده روی این شلف</span>
                        <span class="text-[10px] px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 font-bold" id="ins-products-count-badge">۰ کالا</span>
                    </div>

                    <!-- Shelf Inventory Summary -->
                    <div class="flex items-center justify-between bg-slate-950 p-2.5 rounded-xl border border-slate-800 text-xs">
                        <span class="text-slate-400 text-[11px]">مجموع موجودی این شلف:</span>
                        <span class="font-bold text-emerald-400 font-mono" id="ins-shelf-total-stock">۰ عدد</span>
                    </div>

                    <!-- List of Assigned Products -->
                    <div id="ins-assigned-products-list" class="space-y-2 max-h-64 overflow-y-auto pr-0.5">
                        <!-- Injected via selectObject() -->
                    </div>

                    <!-- Add Product to this Shelf -->
                    <div class="pt-2 border-t border-slate-800 space-y-2">
                        <label class="text-[10px] text-slate-400 block font-bold">+ افزودن کالای جدید به این شلف:</label>
                        <div class="flex gap-2">
                            <select id="ins-product-add-select" class="flex-1 bg-slate-950 border border-slate-700 text-white rounded-lg p-2 text-xs focus:outline-none cursor-pointer">
                                <option value="">انتخاب از کاتالوگ...</option>
                                <?php foreach ($allProducts as $pr): ?>
                                <option value="<?php echo $pr['sku']; ?>"><?php echo htmlspecialchars($pr['name']); ?> (<?php echo $pr['sku']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="addProductFromInspector()" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-bold transition shrink-0">
                                افزودن
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-500 leading-relaxed">
                            💡 می‌توانید چندین کالا را روی یک شلف یا رگال بچینید، یا کالا را مستقیماً از ستون کاتالوگ درگ کنید.
                        </p>
                    </div>
                </div>

                <!-- Duplicate Object -->
                <div class="flex gap-2">
                    <button onclick="duplicateSelectedObject()" class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold transition">
                        کپی المان (Duplicate)
                    </button>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- Layout Comparison Modal -->
<div id="compare-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 sm:p-6">
    <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full p-5 sm:p-6 shadow-2xl text-white">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
            <div>
                <h3 class="text-base font-black text-white">مقایسه دو نسخه چیدمان (Layout Diff)</h3>
                <p class="text-xs text-slate-400 mt-1">مقایسه نسخه فعلی v2.4 در برابر نسخه پایه فصلی v2.0</p>
            </div>
            <button onclick="closeCompareModal()" class="text-slate-400 hover:text-white text-xl">✕</button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
            <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-center">
                <div class="text-xs font-bold text-indigo-400 mb-2">نسخه فعال فعلی (v2.4)</div>
                <div class="text-2xl font-black text-white mb-2">امتیاز: ۸۸/۱۰۰</div>
                <p class="text-xs text-slate-400">کیت پرسپولیس روی میز سنتر استیج منتقل شده و پاخور ۲۴٪ افزایش یافته است.</p>
            </div>

            <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-center">
                <div class="text-xs font-bold text-amber-400 mb-2">نسخه قبلی (v2.0)</div>
                <div class="text-2xl font-black text-slate-300 mb-2">امتیاز: ۷۹/۱۰۰</div>
                <p class="text-xs text-slate-400">کیت پرسپولیس در انتهای گوشه راست قرار داشت و دچار رکود فروش بود.</p>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button onclick="closeCompareModal()" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-xs font-bold transition">
                بستن پنجره مقایسه
            </button>
        </div>
    </div>
</div>

<script>
// Initial layout state passed from PHP
window.currentStoreId = <?php echo json_encode($store['id']); ?>;
window.currentLayout = <?php echo json_encode($layout); ?>;
window.catalogProducts = <?php echo json_encode($allProducts); ?>;
window.selectedObjectId = null;
window.zoomLevel = 1.0;
window.is3DMode = false;
window.gridEnabled = true;

// Responsive Drawer Toggles
function togglePalette(force) {
    const el = document.getElementById('palette-aside');
    const backdrop = document.getElementById('drawer-backdrop');
    const isClosed = el.classList.contains('translate-x-full');
    const shouldOpen = force !== undefined ? force : isClosed;
    
    if (shouldOpen) {
        el.classList.remove('translate-x-full');
        backdrop.classList.remove('hidden');
        // close other drawer
        toggleInspector(false);
    } else {
        el.classList.add('translate-x-full');
        checkBackdrop();
    }
}

function toggleInspector(force) {
    const el = document.getElementById('inspector-drawer');
    const backdrop = document.getElementById('drawer-backdrop');
    const isClosed = el.classList.contains('-translate-x-full');
    const shouldOpen = force !== undefined ? force : isClosed;
    
    if (shouldOpen) {
        el.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
        // close other drawer
        togglePalette(false);
    } else {
        el.classList.add('-translate-x-full');
        checkBackdrop();
    }
}

function closeAllDrawers() {
    togglePalette(false);
    toggleInspector(false);
}

function checkBackdrop() {
    const pal = document.getElementById('palette-aside');
    const ins = document.getElementById('inspector-drawer');
    const backdrop = document.getElementById('drawer-backdrop');
    if (pal.classList.contains('translate-x-full') && ins.classList.contains('-translate-x-full')) {
        backdrop.classList.add('hidden');
    }
}

// Auto Fit Stage to Container Dimensions
function autoFitStage() {
    const container = document.getElementById('canvas-container');
    const stage = document.getElementById('vm-stage');
    if (!container || !stage) return;
    
    const pad = window.innerWidth < 640 ? 16 : 32;
    const availW = container.clientWidth - pad;
    const availH = container.clientHeight - pad;
    const baseW = 960;
    const baseH = 760;
    
    const scaleX = availW / baseW;
    const scaleY = availH / baseH;
    let fit = Math.min(scaleX, scaleY);
    // Clamp between 0.35 and 1.15
    fit = Math.max(0.35, Math.min(1.15, fit));
    
    window.zoomLevel = Math.round(fit * 100) / 100;
    applyStageTransform();
}

// Multi-Product Shelf Helpers
function getObjectProductSkus(obj) {
    if (!obj || !obj.metadata) return [];
    if (Array.isArray(obj.metadata.product_skus)) {
        return obj.metadata.product_skus.filter(Boolean);
    }
    if (obj.metadata.product_sku) {
        return [obj.metadata.product_sku];
    }
    return [];
}

function addProductToObj(obj, sku) {
    if (!obj || !sku) return;
    if (!obj.metadata) obj.metadata = {};
    let skus = getObjectProductSkus(obj);
    if (!skus.includes(sku)) {
        skus.push(sku);
    }
    obj.metadata.product_skus = skus;
    obj.metadata.product_sku = skus[0] || null;
    renderCanvasObjects();
    selectObject(obj.id);
}

function removeProductFromShelf(objId, sku) {
    const obj = (window.currentLayout.objects || []).find(o => o.id === objId);
    if (!obj || !obj.metadata) return;
    let skus = getObjectProductSkus(obj);
    skus = skus.filter(s => s !== sku);
    obj.metadata.product_skus = skus;
    obj.metadata.product_sku = skus[0] || null;
    renderCanvasObjects();
    selectObject(obj.id);
}

function addProductFromInspector() {
    const sel = document.getElementById('ins-product-add-select');
    if (!sel || !sel.value) return;
    const obj = (window.currentLayout.objects || []).find(o => o.id === window.selectedObjectId);
    if (!obj) {
        alert('لطفاً ابتدا یک شلف یا المان را روی نقشه انتخاب کنید.');
        return;
    }
    addProductToObj(obj, sel.value);
    sel.value = '';
}

// Render Objects on Stage
function renderCanvasObjects() {
    const container = document.getElementById('canvas-objects');
    if (!container || !window.currentLayout) return;
    container.innerHTML = '';

    (window.currentLayout.objects || []).forEach(obj => {
        const el = document.createElement('div');
        el.id = 'dom-' + obj.id;
        el.className = `absolute cursor-move select-none rounded-lg flex flex-col items-center justify-center transition-shadow shadow-md ${
            window.selectedObjectId === obj.id ? 'ring-2 ring-indigo-400 shadow-indigo-500/50' : 'hover:ring-1 hover:ring-white/40'
        }`;
        
        el.style.left = obj.position.x + 'px';
        el.style.top = obj.position.y + 'px';
        el.style.width = obj.width + 'px';
        el.style.height = obj.height + 'px';
        el.style.backgroundColor = obj.color || '#475569';
        el.style.transform = `rotate(${obj.rotation || 0}deg)`;

        // Icon based on type
        let icon = '📦';
        if (obj.type === 'rack') icon = '🧥';
        if (obj.type === 'shelf') icon = '👟';
        if (obj.type === 'mannequin') icon = '🧍';
        if (obj.type === 'display_table') icon = '🪑';
        if (obj.type === 'counter') icon = '💳';
        if (obj.type === 'entrance') icon = '🚪';
        if (obj.type === 'window') icon = '🪟';
        if (obj.type === 'wall') icon = '🧱';

        // Products mapping for this object
        const assignedSkus = getObjectProductSkus(obj);
        const assignedProds = assignedSkus.map(s => (window.catalogProducts || []).find(p => p.sku === s)).filter(Boolean);
        let totalStock = 0;
        assignedProds.forEach(p => { 
            if (p.stock !== null && p.stock !== undefined) {
                totalStock += parseInt(p.stock) || 0; 
            }
        });

        let badgeHtml = '';
        let previewHtml = '';

        if (assignedProds.length === 1) {
            const p = assignedProds[0];
            badgeHtml = `
                <div class="absolute -top-2.5 -right-2.5 bg-indigo-600 text-white rounded-full px-2 py-0.5 text-[8px] font-bold shadow border border-indigo-400/50 z-10 pointer-events-none flex items-center gap-0.5 max-w-[110px] truncate">
                    <span>🏷️</span>
                    <span class="truncate">${p.name}</span>
                </div>
            `;
            previewHtml = `
                <div class="flex items-center justify-center gap-1 mt-0.5">
                    <img src="${p.image}" class="w-3.5 h-3.5 rounded-full object-cover border border-white/40">
                    <span class="text-[8px] text-emerald-300 font-mono font-bold">${p.stock !== null ? p.stock + ' عدد' : 'بدون دیتا'}</span>
                </div>
            `;
        } else if (assignedProds.length > 1) {
            badgeHtml = `
                <div class="absolute -top-2.5 -right-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full px-2 py-0.5 text-[9px] font-black shadow-lg border border-indigo-300 z-10 pointer-events-none flex items-center gap-0.5">
                    <span>✨</span>
                    <span>${assignedProds.length} کالا</span>
                </div>
            `;
            const avatars = assignedProds.slice(0, 3).map((p, idx) => 
                `<img src="${p.image}" class="w-3.5 h-3.5 rounded-full object-cover border border-white/60 shadow-xs ${idx > 0 ? '-mr-1.5' : ''}">`
            ).join('');
            previewHtml = `
                <div class="flex items-center justify-center gap-1 mt-0.5">
                    <div class="flex items-center">${avatars}</div>
                    <span class="text-[8px] text-emerald-300 font-mono font-bold">${totalStock} موجودی</span>
                </div>
            `;
        } else if (['rack', 'shelf', 'display_table', 'mannequin'].includes(obj.type)) {
            previewHtml = `
                <div class="text-[8px] text-slate-400/70 mt-0.5">
                    (بدون کالا)
                </div>
            `;
        }

        el.innerHTML = `
            ${badgeHtml}
            <div class="text-center pointer-events-none p-1 overflow-hidden w-full">
                <div class="text-xs leading-none">${icon}</div>
                <div class="text-[9px] font-bold text-white truncate max-w-full drop-shadow mt-0.5">${obj.title || obj.type}</div>
                ${previewHtml}
            </div>
        `;

        // Click to select
        el.onclick = (e) => {
            e.stopPropagation();
            selectObject(obj.id);
        };

        // Enable Dragging on Canvas
        setupDraggable(el, obj);

        container.appendChild(el);
    });
}

// Select Object and populate Inspector
function selectObject(id) {
    window.selectedObjectId = id;
    renderCanvasObjects();

    const obj = (window.currentLayout.objects || []).find(o => o.id === id);
    const emptyState = document.getElementById('inspector-empty');
    const content = document.getElementById('inspector-content');

    if (!obj) {
        emptyState.classList.remove('hidden');
        content.classList.add('hidden');
        return;
    }

    emptyState.classList.add('hidden');
    content.classList.remove('hidden');

    document.getElementById('ins-type-label').innerText = (obj.type || 'OBJECT').toUpperCase();
    document.getElementById('ins-title-display').innerText = obj.title || obj.id;
    document.getElementById('ins-width').value = obj.width;
    document.getElementById('ins-height').value = obj.height;

    // Multi-Product Mapping
    const assignedSkus = getObjectProductSkus(obj);
    const assignedProds = assignedSkus.map(s => (window.catalogProducts || []).find(p => p.sku === s)).filter(Boolean);
    let totalStock = 0;
    assignedProds.forEach(p => { 
        if (p.stock !== null && p.stock !== undefined) {
            totalStock += parseInt(p.stock) || 0; 
        }
    });

    const countBadge = document.getElementById('ins-products-count-badge');
    if (countBadge) countBadge.innerText = assignedProds.length + ' کالا';
    const totalStockEl = document.getElementById('ins-shelf-total-stock');
    if (totalStockEl) totalStockEl.innerText = assignedProds.length > 0 ? (totalStock + ' عدد') : 'بدون کالا';

    const listContainer = document.getElementById('ins-assigned-products-list');
    if (listContainer) {
        if (assignedProds.length === 0) {
            listContainer.innerHTML = `
                <div class="text-xs text-slate-500 py-4 text-center bg-slate-950 rounded-xl border border-dashed border-slate-800">
                    <div class="text-lg mb-1 opacity-50">📭</div>
                    <div>هنوز کالایی به این شلف اختصاص داده نشده است.</div>
                    <div class="text-[10px] text-slate-600 mt-1">یک کالا از زیر انتخاب کرده یا از کاتالوگ به اینجا بکشید.</div>
                </div>
            `;
        } else {
            listContainer.innerHTML = assignedProds.map(p => `
                <div class="flex items-center gap-2.5 bg-slate-950 p-2.5 rounded-xl border border-slate-800 hover:border-slate-700 transition">
                    <img src="${p.image}" alt="" class="w-10 h-10 rounded-lg object-cover bg-slate-800 shrink-0">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-black text-white truncate" title="${p.name}">${p.name}</div>
                        <div class="text-[10px] text-slate-400 font-mono mt-0.5">${p.sku}</div>
                        <div class="flex items-center justify-between mt-1 text-[10px]">
                            <span class="text-emerald-400 font-bold">موجودی: ${p.stock !== null && p.stock !== undefined ? (p.stock + ' عدد') : 'بدون دیتا'}</span>
                            <span class="text-indigo-300 font-medium">${p.price_formatted || 'بدون دیتا'}</span>
                        </div>
                        <div class="flex items-center gap-2 mt-1 text-[9px] text-slate-500">
                            <span>فروش: <strong class="text-slate-300">${p.monthly_sales !== null && p.monthly_sales !== undefined ? (p.monthly_sales + ' عدد') : 'بدون دیتا'}</strong></span>
                            <span>•</span>
                            <span>تبدیل: <strong class="text-indigo-300">${p.conversion_rate !== null && p.conversion_rate !== undefined ? (p.conversion_rate + '٪') : 'بدون دیتا'}</strong></span>
                        </div>
                    </div>
                    <button type="button" onclick="removeProductFromShelf('${obj.id}', '${p.sku}')" class="p-1.5 text-slate-500 hover:text-red-400 rounded-lg hover:bg-red-950/30 transition shrink-0" title="حذف کالا از این شلف">
                        ❌
                    </button>
                </div>
            `).join('');
        }
    }
}

// Add element to center of canvas (Works for both touch and desktop clicks!)
function addElementToCenter(type) {
    const newObj = {
        id: 'obj-' + Date.now(),
        type: type,
        title: 'المان ' + type,
        position: { x: 420, y: 340 },
        width: 120,
        height: 50,
        rotation: 0,
        layer: 2,
        color: '#6366f1',
        metadata: {}
    };
    window.currentLayout.objects.push(newObj);
    renderCanvasObjects();
    selectObject(newObj.id);
    closeAllDrawers();
}

// Draggable setup
function setupDraggable(el, obj) {
    let startX = 0, startY = 0, initialX = 0, initialY = 0;

    // Mouse drag
    el.onmousedown = function(e) {
        if (e.target !== el && !el.contains(e.target)) return;
        startX = e.clientX;
        startY = e.clientY;
        initialX = obj.position.x;
        initialY = obj.position.y;

        document.onmousemove = function(e) {
            let dx = (e.clientX - startX) / window.zoomLevel;
            let dy = (e.clientY - startY) / window.zoomLevel;
            
            let newX = initialX + dx;
            let newY = initialY + dy;

            if (window.gridEnabled) {
                newX = Math.round(newX / 20) * 20;
                newY = Math.round(newY / 20) * 20;
            }

            obj.position.x = Math.max(0, Math.min(960 - obj.width, newX));
            obj.position.y = Math.max(0, Math.min(760 - obj.height, newY));

            el.style.left = obj.position.x + 'px';
            el.style.top = obj.position.y + 'px';
        };

        document.onmouseup = function() {
            document.onmousemove = null;
            document.onmouseup = null;
        };
    };
}

// Drag from Palette
function handleDragNewObject(e, type) {
    e.dataTransfer.setData('new_object_type', type);
}

function handleDragProduct(e, sku) {
    e.dataTransfer.setData('product_sku', sku);
}

function filterVmProducts(query) {
    const q = (query || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.vm-product-card');
    let visibleCount = 0;
    cards.forEach(card => {
        const sku = card.getAttribute('data-sku') || '';
        const name = card.getAttribute('data-name') || '';
        if (!q || sku.includes(q) || name.includes(q)) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });
    const badge = document.getElementById('product-count-badge');
    if (badge) {
        badge.textContent = visibleCount + ' کالا';
    }
}

function handleCanvasDrop(e) {
    e.preventDefault();
    const newType = e.dataTransfer.getData('new_object_type');
    const sku = e.dataTransfer.getData('product_sku');
    const rect = document.getElementById('vm-stage').getBoundingClientRect();
    
    let x = (e.clientX - rect.left) / window.zoomLevel;
    let y = (e.clientY - rect.top) / window.zoomLevel;

    if (window.gridEnabled) {
        x = Math.round(x / 20) * 20;
        y = Math.round(y / 20) * 20;
    }

    if (newType) {
        const newObj = {
            id: 'obj-' + Date.now(),
            type: newType,
            title: 'المان (' + newType + ')',
            position: { x: Math.max(0, x - 50), y: Math.max(0, y - 25) },
            width: 120,
            height: 50,
            rotation: 0,
            layer: 2,
            color: '#6366f1',
            metadata: {}
        };
        window.currentLayout.objects.push(newObj);
        renderCanvasObjects();
        selectObject(newObj.id);
    } else if (sku) {
        // Check if dropped directly onto an existing element
        const targetObj = (window.currentLayout.objects || []).find(o => {
            return x >= o.position.x && x <= (o.position.x + o.width) &&
                   y >= o.position.y && y <= (o.position.y + o.height);
        });

        if (targetObj) {
            addProductToObj(targetObj, sku);
        } else if (window.selectedObjectId) {
            const selObj = (window.currentLayout.objects || []).find(o => o.id === window.selectedObjectId);
            if (selObj) {
                addProductToObj(selObj, sku);
            }
        } else {
            // Create a new rack with the linked product
            const pInfo = (window.catalogProducts || []).find(p => p.sku === sku);
            const newObj = {
                id: 'obj-' + Date.now(),
                type: 'rack',
                title: pInfo ? pInfo.name.substring(0, 20) : ('استند ' + sku),
                position: { x: Math.max(0, x - 60), y: Math.max(0, y - 25) },
                width: 120,
                height: 50,
                rotation: 0,
                layer: 2,
                color: '#4f46e5',
                metadata: { product_skus: [sku], product_sku: sku }
            };
            window.currentLayout.objects.push(newObj);
            renderCanvasObjects();
            selectObject(newObj.id);
        }
    }
}

// Update Property
function updateSelectedProp(prop, val) {
    const obj = (window.currentLayout.objects || []).find(o => o.id === window.selectedObjectId);
    if (!obj) return;
    obj[prop] = val;
    renderCanvasObjects();
}

// Link Product (appends product to selected shelf or creates one)
function linkProductToSelected(sku) {
    let obj = (window.currentLayout.objects || []).find(o => o.id === window.selectedObjectId);
    if (!obj) {
        // If nothing is selected, select the first rack or shelf
        obj = (window.currentLayout.objects || []).find(o => o.type === 'rack' || o.type === 'shelf' || o.type === 'display_table');
        if (!obj && window.currentLayout.objects && window.currentLayout.objects.length > 0) {
            obj = window.currentLayout.objects[0];
        }
    }
    if (obj) {
        addProductToObj(obj, sku);
        closeAllDrawers();
    } else {
        // Create new fixture with this product
        const pInfo = (window.catalogProducts || []).find(p => p.sku === sku);
        const newObj = {
            id: 'obj-' + Date.now(),
            type: 'rack',
            title: pInfo ? pInfo.name.substring(0, 20) : ('استند ' + sku),
            position: { x: 420, y: 350 },
            width: 120,
            height: 50,
            rotation: 0,
            layer: 2,
            color: '#4f46e5',
            metadata: { product_skus: [sku], product_sku: sku }
        };
        window.currentLayout.objects.push(newObj);
        renderCanvasObjects();
        selectObject(newObj.id);
        closeAllDrawers();
    }
}

// Duplicate
function duplicateSelectedObject() {
    if (!window.selectedObjectId) return;
    const obj = (window.currentLayout.objects || []).find(o => o.id === window.selectedObjectId);
    if (!obj) return;

    const copy = JSON.parse(JSON.stringify(obj));
    copy.id = 'obj-' + Date.now();
    copy.position.x = Math.min(840, copy.position.x + 30);
    copy.position.y = Math.min(680, copy.position.y + 30);
    copy.title = (copy.title || 'شیء') + ' (کپی)';
    
    window.currentLayout.objects.push(copy);
    renderCanvasObjects();
    selectObject(copy.id);
}

// Delete
function deleteSelectedObject() {
    if (!window.selectedObjectId) return;
    window.currentLayout.objects = window.currentLayout.objects.filter(o => o.id !== window.selectedObjectId);
    window.selectedObjectId = null;
    renderCanvasObjects();
    selectObject(null);
}

// Zoom
function changeZoom(delta) {
    window.zoomLevel = Math.max(0.35, Math.min(2.0, Math.round((window.zoomLevel + delta) * 10) / 10));
    applyStageTransform();
}
function resetZoom() {
    window.zoomLevel = 1.0;
    applyStageTransform();
}
function applyStageTransform() {
    const txt = document.getElementById('zoom-level-text');
    if (txt) txt.innerText = Math.round(window.zoomLevel * 100) + '%';
    const stage = document.getElementById('vm-stage');
    if (!stage) return;
    let transform = `scale(${window.zoomLevel})`;
    if (window.is3DMode) {
        transform += ` rotateX(45deg) rotateZ(-20deg)`;
    }
    stage.style.transform = transform;
}

// 3D Digital Twin View
function toggle3DView() {
    window.is3DMode = !window.is3DMode;
    const btn = document.getElementById('btn-3d-toggle');
    if (window.is3DMode) {
        btn.classList.add('bg-indigo-600', 'text-white');
    } else {
        btn.classList.remove('bg-indigo-600', 'text-white');
    }
    applyStageTransform();
}

// Grid Toggle
function toggleGrid() {
    window.gridEnabled = !window.gridEnabled;
    const overlay = document.getElementById('grid-overlay');
    if (overlay) overlay.style.display = window.gridEnabled ? 'block' : 'none';
}

// Heatmap Mode
function setHeatmapMode(mode) {
    const overlay = document.getElementById('heatmap-overlay');
    if (!overlay) return;
    if (mode === 'none') {
        overlay.style.opacity = '0';
    } else {
        overlay.style.opacity = '1';
    }
}

// Save Layout to Backend (Using absolute /panel/vm/api.php to prevent 404s)
function saveCurrentLayout() {
    const btn = document.getElementById('btn-save-layout');
    btn.innerHTML = '<span>⏳</span><span>در حال ذخیره...</span>';
    btn.disabled = true;
    
    fetch('/panel/vm/api.php?action=save_layout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            store_id: window.currentStoreId,
            layout: window.currentLayout
        })
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<span>💾</span><span>ذخیره چیدمان</span>';
        btn.disabled = false;
        if (data.success) {
            alert('نسخه چیدمان فروشگاه با موفقیت ذخیره و به‌روزرسانی شد.');
        } else {
            alert(data.message || 'خطا در ذخیره سازی');
        }
    })
    .catch(err => {
        btn.innerHTML = '<span>💾</span><span>ذخیره چیدمان</span>';
        btn.disabled = false;
        alert('خطای شبکه در ارتباط با سرور.');
    });
}

// Palette tab switch
function switchPaletteTab(tab) {
    const objTab = document.getElementById('tab-btn-objects');
    const prodTab = document.getElementById('tab-btn-products');
    const objPal = document.getElementById('palette-objects');
    const prodPal = document.getElementById('palette-products');

    if (tab === 'objects') {
        objTab.className = "flex-1 py-2.5 text-center border-b-2 border-indigo-500 text-indigo-400 bg-slate-900/50 transition";
        prodTab.className = "flex-1 py-2.5 text-center border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition";
        objPal.classList.remove('hidden');
        prodPal.classList.add('hidden');
    } else {
        prodTab.className = "flex-1 py-2.5 text-center border-b-2 border-indigo-500 text-indigo-400 bg-slate-900/50 transition";
        objTab.className = "flex-1 py-2.5 text-center border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition";
        prodPal.classList.remove('hidden');
        objPal.classList.add('hidden');
    }
}

function openCompareModal() {
    document.getElementById('compare-modal').classList.remove('hidden');
}
function closeCompareModal() {
    document.getElementById('compare-modal').classList.add('hidden');
}

// Window resize listener to auto-fit
window.addEventListener('resize', () => {
    autoFitStage();
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    renderCanvasObjects();
    autoFitStage();
    // Select first object by default if present
    if (window.currentLayout.objects && window.currentLayout.objects.length > 0) {
        selectObject(window.currentLayout.objects[0].id);
    }
});
</script>
