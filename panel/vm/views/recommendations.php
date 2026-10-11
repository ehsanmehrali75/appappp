<?php
/**
 * Visual Merchandising Intelligence - AI Recommendations View
 */
$projectId = $project['id'] ?? ($project['slug'] ?? '');
$allStores = VmDB::getStoresByProject($projectId);
$defaultStoreId = !empty($allStores) ? $allStores[0]['id'] : '';
$storeSlug = $_GET['store'] ?? ($_GET['store_id'] ?? $defaultStoreId);
$store = VmDB::getStore($storeSlug, $projectId);
if (!$store && !empty($allStores)) {
    $store = reset($allStores);
}
$recs = VmAI::getRecommendations($store['id'] ?? '');
$layout = VmDB::getStoreLayout($store['id'] ?? '');
$objects = $layout['objects'] ?? [];
?>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
            <span>🧠</span>
            <span>موتور هوشمند پیشنهاد چیدمان (AI Recommendation Engine)</span>
        </h2>
        <p class="text-xs text-slate-500 mt-1">تحلیل واقعی چیدمان، موجودی انبار، موانع دیداری و نقاط فروش جهت بهینه‌سازی موقعیت اقلام</p>
    </div>

    <div class="flex items-center gap-3">
        <select onchange="window.vmNavigate('recommendations', { store: this.value })" class="bg-white text-slate-800 text-xs font-bold border border-slate-200 rounded-xl px-3 py-2 focus:outline-none cursor-pointer">
            <?php foreach ($allStores as $st): ?>
            <option value="<?php echo $st['slug']; ?>" <?php echo $st['id'] === $store['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($st['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <button onclick="generateFreshAI()" id="btn-ai-generate" class="px-4 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg transition flex items-center gap-2">
            <span>✨</span>
            <span>تحلیل مجدد و تولید پیشنهادهای جدید</span>
        </button>
    </div>
</div>

<?php if (empty($objects)): ?>
<div class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center shadow-sm">
    <div class="text-4xl mb-3">📐</div>
    <h3 class="text-base font-black text-slate-800 mb-2">داده‌ای برای تحلیل هوش مصنوعی وجود ندارد</h3>
    <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">هیچ المانی در نقشه چیدمان این فروشگاه تعریف نشده است. لطفاً ابتدا در استودیو چیدمان، رگال‌ها و محصولات را بچینید تا هوش مصنوعی قادر به ارائه پیشنهاد باشد.</p>
    <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2 shadow-lg shadow-indigo-600/20">
        <span>📐</span>
        <span>ورود به استودیو چیدمان (Studio)</span>
    </a>
</div>
<?php elseif (empty($recs)): ?>
<div class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center shadow-sm">
    <div class="text-4xl mb-3">✨</div>
    <h3 class="text-base font-black text-slate-800 mb-2">چیدمان فعلی در وضعیت مطلوب است</h3>
    <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">هیچ تداخل یا هشدار بحرانی برای این چیدمان یافت نشد. می‌توانید با کلیک بر روی دکمه زیر، تحلیل عمیق‌تری را فراخوانی نمایید.</p>
    <button onclick="generateFreshAI()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2 shadow-lg shadow-indigo-600/20">
        <span>🔄</span>
        <span>تحلیل مجدد چیدمان با هوش مصنوعی</span>
    </button>
</div>
<?php else: ?>

<div class="space-y-4">
    <?php foreach ($recs as $r): ?>
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm hover:shadow-md transition">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
            <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold">
                    🎯
                </span>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-black text-slate-800"><?php echo htmlspecialchars($r['title']); ?></h3>
                        <?php
                        $prioClass = 'bg-red-50 text-red-700 border-red-200';
                        if ($r['priority'] === 'Medium') $prioClass = 'bg-amber-50 text-amber-700 border-amber-200';
                        if ($r['priority'] === 'Low') $prioClass = 'bg-slate-50 text-slate-700 border-slate-200';
                        ?>
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-black border <?php echo $prioClass; ?>">
                            اولویت: <?php echo htmlspecialchars($r['priority']); ?>
                        </span>
                    </div>
                    <span class="text-[11px] text-slate-400 font-mono mt-0.5 block">ثبت شده در: <?php echo htmlspecialchars($r['created_at']); ?></span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="text-left bg-emerald-50 border border-emerald-100 px-3 py-1.5 rounded-xl">
                    <span class="text-[10px] text-emerald-600 font-bold block">پیش‌بینی تاثیر (Impact):</span>
                    <span class="font-black text-emerald-700 text-xs font-mono"><?php echo htmlspecialchars($r['impact_prediction']); ?></span>
                </div>

                <?php if (($r['status'] ?? '') === 'applied'): ?>
                <span class="px-4 py-2 bg-slate-100 text-slate-500 rounded-xl text-xs font-bold flex items-center gap-1.5">
                    <span>✓</span> اعمال شده در چیدمان
                </span>
                <?php elseif (!empty($r['target_coords'])): ?>
                <button onclick="applyAiSuggestion('<?php echo $r['id']; ?>', this)" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-indigo-600/20">
                    <span>⚡</span>
                    <span>اعمال خودکار بر روی نقشه</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-slate-50 rounded-xl p-4 my-3 text-xs space-y-2">
            <div>
                <span class="font-bold text-slate-700">دستور اجرایی (Recommendation):</span>
                <p class="text-slate-800 mt-1 font-medium leading-relaxed"><?php echo htmlspecialchars($r['recommendation']); ?></p>
            </div>
            <div class="pt-2 border-t border-slate-200/60">
                <span class="font-bold text-slate-700">علت و استدلال داده‌ای (Reason):</span>
                <p class="text-slate-600 mt-1 leading-relaxed"><?php echo htmlspecialchars($r['reason']); ?></p>
            </div>
        </div>

        <div class="flex items-center justify-between text-[11px] text-slate-400 pt-1">
            <span>المان هدف: <code class="text-slate-700"><?php echo htmlspecialchars($r['object_id'] ?? 'عمومی'); ?></code></span>
            <a href="<?php echo vm_url('studio', ['store' => $store['slug']]); ?>" class="text-indigo-600 font-bold hover:underline">
                مشاهده در VM Studio ←
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<script>
function applyAiSuggestion(recId, btn) {
    btn.innerHTML = '<span>⏳</span><span>در حال اعمال...</span>';
    btn.disabled = true;

    fetch('/panel/vm/api.php?action=apply_recommendation&store_id=<?php echo $store['id']; ?>&rec_id=' + encodeURIComponent(recId))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message || 'خطا در اعمال پیشنهاد');
                btn.disabled = false;
                btn.innerHTML = '<span>⚡</span><span>اعمال خودکار بر روی نقشه</span>';
            }
        })
        .catch(err => {
            alert('خطای ارتباط با سرور.');
            btn.disabled = false;
            btn.innerHTML = '<span>⚡</span><span>اعمال خودکار بر روی نقشه</span>';
        });
}

function generateFreshAI() {
    const btn = document.getElementById('btn-ai-generate');
    if (btn) {
        btn.innerHTML = '<span>⏳</span><span>در حال تحلیل هوشمند چیدمان...</span>';
        btn.disabled = true;
    }

    fetch('/panel/vm/api.php?action=generate_ai_recommendations&store_id=<?php echo $store['id']; ?>')
        .then(r => r.json())
        .then(data => {
            alert(data.message || 'پیشنهادهای هوش مصنوعی به‌روزرسانی شدند.');
            window.location.reload();
        })
        .catch(err => {
            alert('خطای ارتباط با سرور.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span>✨</span><span>تحلیل مجدد و تولید پیشنهادهای جدید</span>';
            }
        });
}
</script>
