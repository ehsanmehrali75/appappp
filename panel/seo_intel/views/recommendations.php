<?php
/**
 * View: AI SEO Analyst & Recommendations
 * AI-driven recommendations engine synthesized from Search Console, GA4, Social, and Sales
 */

$statusFilter = $_GET['status'] ?? 'all';
$recommendations = SeoDB::getRecommendations($currentBusiness['id'], $statusFilter);
?>

<!-- AI Analyst Header & Interactive Assistant -->
<div class="bg-gradient-to-l from-indigo-900 via-indigo-800 to-slate-900 text-white p-6 rounded-3xl shadow-lg mb-6" id="sec-ai-analyst-hero">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="px-2.5 py-0.5 rounded-full bg-indigo-500/30 border border-indigo-400/40 text-[11px] font-black text-indigo-200">
                    ⚡ هوش مصنوعی مولد Maarksho AI & Gemini
                </span>
                <span class="text-xs text-indigo-300 font-mono">Real-time Synthesizer</span>
            </div>
            <h2 class="text-lg font-black mb-2">موتور تحلیل هوشمند رشد و سئو (AI SEO Analyst)</h2>
            <p class="text-xs text-indigo-200/80 max-w-2xl leading-relaxed">
                ترکیب خودکار داده‌های سرچ کنسول گوگل، آنالیتیکس ۴، بازخورد اینستاگرام و فروش ووکامرس جهت تولید دقیق‌ترین راهکارهای افزایش رتبه و درآمد برای برند <?php echo htmlspecialchars($currentBusiness['name']); ?>.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="triggerFullAiScan()" class="px-4 py-2.5 bg-white hover:bg-slate-100 text-indigo-950 font-black text-xs rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-2">
                <span>🔄</span> اسکن عمیق مجدد با هوش مصنوعی
            </button>
        </div>
    </div>

    <!-- Live AI Prompt Box -->
    <div class="mt-6 pt-5 border-t border-indigo-700/50">
        <label class="block text-xs font-bold text-indigo-200 mb-2">از دستیار اختصاصی سئو درباره این بیزنس بپرسید:</label>
        <div class="flex items-center gap-2">
            <input type="text" id="ai-assistant-input" placeholder="مثال: برای صفحه شلوار کارگو یک Title جذاب و ۵ سوال متداول FAQ اسکیما تولید کن..." class="flex-1 px-4 py-2.5 bg-indigo-950/60 border border-indigo-500/40 rounded-xl text-xs text-white placeholder-indigo-300/50 outline-none focus:border-white transition-all">
            <button onclick="submitAiQuery()" id="btn-submit-ai" class="px-5 py-2.5 bg-indigo-500 hover:bg-indigo-400 text-white text-xs font-black rounded-xl transition-all shadow-sm cursor-pointer whitespace-nowrap">
                ارسال به هوش مصنوعی ✨
            </button>
        </div>

        <!-- AI Assistant Output Response Container -->
        <div id="ai-assistant-response" class="hidden mt-4 p-4 bg-slate-900/90 border border-indigo-500/50 rounded-2xl text-xs text-slate-100 leading-relaxed font-sans max-h-72 overflow-y-auto">
            <div class="flex items-center justify-between pb-2 mb-2 border-b border-indigo-800 text-indigo-300 font-bold">
                <span>پاسخ تحلیل‌گر ارشد سئو (Gemini AI):</span>
                <button onclick="document.getElementById('ai-assistant-response').classList.add('hidden')" class="text-slate-400 hover:text-white">&times;</button>
            </div>
            <div id="ai-assistant-content" class="prose prose-invert prose-xs"></div>
        </div>
    </div>
</div>

<!-- Recommendations List Filter -->
<div class="flex items-center justify-between gap-4 mb-4">
    <div class="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl text-xs font-bold">
        <a href="?view=recommendations&status=all&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $statusFilter === 'all' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500'; ?>">
            همه پیشنهادات (<?php echo count(SeoDB::getRecommendations($currentBusiness['id'], 'all')); ?>)
        </a>
        <a href="?view=recommendations&status=pending&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $statusFilter === 'pending' ? 'bg-white text-amber-700 shadow-xs' : 'text-slate-500'; ?>">
            ⚡ در انتظار اقدام (<?php echo count(SeoDB::getRecommendations($currentBusiness['id'], 'pending')); ?>)
        </a>
        <a href="?view=recommendations&status=applied&business=<?php echo urlencode($currentBusiness['slug']); ?>" class="px-3 py-1.5 rounded-lg transition-all <?php echo $statusFilter === 'applied' ? 'bg-white text-emerald-700 shadow-xs' : 'text-slate-500'; ?>">
            ✅ اعمال شده (<?php echo count(SeoDB::getRecommendations($currentBusiness['id'], 'applied')); ?>)
        </a>
    </div>
</div>

<!-- Recommendations Cards -->
<div class="space-y-4" id="recommendations-container">
    <?php if (empty($recommendations)): ?>
    <div class="p-12 text-center bg-white rounded-2xl border border-slate-200/80 text-slate-400 text-xs">
        هیچ پیشنهادی با این فیلتر ثبت نشده است. می‌توانید با کلیک روی «اسکن عمیق مجدد»، تحلیل جدیدی دریافت کنید.
    </div>
    <?php else: ?>
    <?php foreach ($recommendations as $rec): 
        $isApplied = ($rec['status'] ?? 'pending') === 'applied';
        $impactBadge = ($rec['impact'] === 'High') 
            ? 'bg-rose-50 text-rose-700 border-rose-100' 
            : 'bg-amber-50 text-amber-700 border-amber-100';
    ?>
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm transition-all hover:border-indigo-200 <?php echo $isApplied ? 'opacity-75 bg-slate-50/50' : ''; ?>" id="rec-card-<?php echo $rec['id']; ?>">
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 mb-3">
            <div>
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($rec['title']); ?></span>
                    <span class="text-[10px] font-black px-2 py-0.5 rounded border <?php echo $impactBadge; ?>">
                        اثرگذاری: <?php echo $rec['impact'] === 'High' ? 'بسیار زیاد (High Impact)' : 'متوسط'; ?>
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                        اولویت: <?php echo $rec['priority'] ?? 'P1'; ?>
                    </span>
                </div>
                <span class="text-[11px] text-indigo-600 font-mono"><?php echo htmlspecialchars($rec['target_entity'] ?? ''); ?></span>
            </div>

            <div class="flex items-center gap-2">
                <?php if (!$isApplied): ?>
                    <button onclick="updateRecStatus('<?php echo $rec['id']; ?>', 'applied')" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-xs cursor-pointer flex items-center gap-1">
                        <span>✓</span> علامت‌گذاری به عنوان اعمال شده
                    </button>
                    <button onclick="updateRecStatus('<?php echo $rec['id']; ?>', 'dismissed')" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition-all cursor-pointer">
                        نادیده گرفتن
                    </button>
                <?php else: ?>
                    <span class="px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-xl text-xs font-black flex items-center gap-1">
                        <span>✅</span> اقدام اعمال شد
                    </span>
                    <button onclick="updateRecStatus('<?php echo $rec['id']; ?>', 'pending')" class="text-xs text-slate-400 hover:underline">بازگردانی</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Issue -->
        <div class="p-3.5 bg-slate-50 rounded-xl mb-3 border border-slate-100 text-xs text-slate-700 leading-relaxed">
            <strong class="text-slate-900 block mb-1">🔍 ریشه‌یابی و تحلیل داده‌ها (Issue):</strong>
            <?php echo htmlspecialchars($rec['issue']); ?>
        </div>

        <!-- Recommendation & Action Plan -->
        <div class="p-3.5 bg-indigo-50/40 rounded-xl border border-indigo-100/60 text-xs text-indigo-950 leading-relaxed">
            <strong class="text-indigo-900 block mb-1">💡 راهکار عملیاتی پیشنهادی (Action):</strong>
            <?php echo htmlspecialchars($rec['recommendation']); ?>
            <?php if (!empty($rec['action_plan'])): ?>
                <div class="mt-2 pt-2 border-t border-indigo-100 text-[11px] text-slate-600">
                    <strong class="text-indigo-800">مراحل اجرا:</strong> <?php echo htmlspecialchars($rec['action_plan']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
