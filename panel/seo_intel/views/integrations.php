<?php
/**
 * View: Integrations & Composio Hub
 * Multi-Platform Connection Manager, Composio CLI & API Config, OAuth, and Sync Audit Logs
 */

$composioConfig = ComposioService::getConfig();
$connections = SeoDB::getConnections($currentBusiness['id']);
$allIntegrations = SeoDB::getData()['integrations'] ?? [];
$syncLogs = SeoDB::getSyncLogs($currentBusiness['id'], 15);
?>

<!-- Composio Engine Status Hero -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-composio-core">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-6 border-b border-slate-100">
        <div class="flex items-start gap-4">
            <span class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-2xl font-black shadow-md shadow-indigo-200">
                ⚡
            </span>
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <h2 class="text-base font-black text-slate-900">هسته یکپارچه‌ساز ابزارها (Composio Core Engine)</h2>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black <?php echo !empty($composioConfig['api_key']) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'; ?>">
                        <?php echo !empty($composioConfig['api_key']) ? 'فعال و پیکربندی شده' : 'نیازمند کلید API'; ?>
                    </span>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed max-w-2xl">
                    اتصال به اکانت‌های خارجی (Google Search Console, GA4, Instagram, WooCommerce) از طریق لایه امنیتی Composio و CLI روی سرور.
                </p>
            </div>
        </div>

        <!-- Composio Environment Indicators -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <div class="p-2.5 bg-slate-50 border border-slate-100 rounded-xl text-center">
                <span class="text-[10px] text-slate-400 block mb-0.5">معماری سرویس</span>
                <span class="font-mono font-bold text-emerald-700">Composio v3.1 (REST API)</span>
            </div>
            <div class="p-2.5 bg-slate-50 border border-slate-100 rounded-xl text-center">
                <span class="text-[10px] text-slate-400 block mb-0.5">وضعیت CLI سرور</span>
                <span class="font-mono font-bold <?php echo $composioConfig['cli_available'] ? 'text-slate-800' : 'text-indigo-700'; ?>">
                    <?php echo $composioConfig['cli_status_text']; ?>
                </span>
            </div>
            <div class="p-2.5 bg-indigo-50/70 border border-indigo-100 rounded-xl text-center">
                <span class="text-[10px] text-indigo-500 block mb-0.5">IP خروجی سرور (Whitelist)</span>
                <span class="font-mono font-bold text-indigo-900 cursor-pointer hover:underline" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($composioConfig['outbound_ip']); ?>'); showToast('آی‌پی سرور (<?php echo htmlspecialchars($composioConfig['outbound_ip']); ?>) کپی شد.', 'info');" title="کلیک جهت کپی">
                    <?php echo htmlspecialchars($composioConfig['outbound_ip']); ?> 📋
                </span>
            </div>
            <div class="p-2.5 bg-slate-50 border border-slate-100 rounded-xl text-center">
                <span class="text-[10px] text-slate-400 block mb-0.5">محیط اجرا</span>
                <span class="font-mono font-bold text-slate-700">Production Layer</span>
            </div>
        </div>
    </div>

    <!-- Composio API Key Configuration Form -->
    <div class="mt-6">
        <form onsubmit="saveComposioKey(event)" class="flex flex-col md:flex-row items-stretch md:items-end gap-3">
            <div class="flex-1">
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-bold text-slate-700">کلید اختصاصی Composio API Key (جهت احراز هویت سرویس و تولید خودکار لینک OAuth)</label>
                    <span class="text-[11px] text-indigo-600 font-medium">پشتیبانی از کلیدهای Project API Key و Org API Key</span>
                </div>
                <div class="relative">
                    <input type="password" id="composio-api-key" value="<?php echo htmlspecialchars($composioConfig['api_key']); ?>" placeholder="کلید API کامپوزیو را اینجا وارد کنید (مثال: comp_...)" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono outline-none focus:border-indigo-500">
                </div>
            </div>
            <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition-all cursor-pointer whitespace-nowrap">
                ذخیره و تست اتصال Composio
            </button>
            <button type="button" onclick="testComposioConnection()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition-all cursor-pointer whitespace-nowrap">
                بررسی لایو سلامت
            </button>
        </form>
        <p class="text-[11px] text-slate-400 mt-2">
            💡 راهنما: کلید API را از داشبورد Composio بخش <strong>Settings > Project Settings > API Keys</strong> کپی نمایید. در صورت فعال بودن Whitelist در کامپوزیو، مطمئن شوید آی‌پی خروجی سرور (<code class="font-mono text-slate-700 font-bold"><?php echo htmlspecialchars($composioConfig['outbound_ip']); ?></code>) در آن ثبت شده باشد.
        </p>
        <div id="composio-test-result" class="hidden mt-3 p-3 rounded-xl text-xs font-medium"></div>
    </div>
</div>

<!-- Business Connections Hub -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-6" id="sec-business-connections">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 mb-6 border-b border-slate-100">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>🔗</span> کانکشن‌های اختصاصی کسب‌وکار: <?php echo htmlspecialchars($currentBusiness['name']); ?>
            </h3>
            <p class="text-xs text-slate-400 mt-1">شناسه یکتای نهاد (Entity ID): <code class="font-mono text-indigo-700"><?php echo htmlspecialchars($currentBusiness['slug']); ?></code> | دامنه: <code class="font-mono text-slate-600"><?php echo htmlspecialchars($currentBusiness['domain']); ?></code></p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="triggerSync('all')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl transition-all shadow-sm cursor-pointer flex items-center gap-1.5">
                <span>🔄</span> همگام‌سازی کامل تمام سرویس‌ها
            </button>
        </div>
    </div>

    <!-- Integrations Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($allIntegrations as $intg): 
            $slug = $intg['slug'];
            $conn = (isset($connections[$slug]) && is_array($connections[$slug])) ? $connections[$slug] : [];
            $isConnected = !empty($conn) && (isset($conn['status']) && $conn['status'] === 'connected');
        ?>
        <div class="p-5 rounded-2xl border transition-all <?php echo $isConnected ? 'border-slate-200/80 bg-white shadow-xs' : 'border-slate-100 bg-slate-50/60 opacity-90'; ?>">
            <div class="flex items-start justify-between gap-2 mb-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-lg">
                        <?php echo $intg['icon']; ?>
                    </span>
                    <div>
                        <h4 class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($intg['name']); ?></h4>
                        <span class="text-[10px] text-slate-400 font-mono"><?php echo $slug; ?></span>
                    </div>
                </div>

                <span class="px-2 py-0.5 rounded text-[10px] font-black <?php echo $isConnected ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-500'; ?>">
                    <?php echo $isConnected ? 'متصل (Active)' : 'غیرفعال'; ?>
                </span>
            </div>

            <p class="text-xs text-slate-500 mb-4 h-9 leading-relaxed overflow-hidden">
                <?php echo htmlspecialchars($intg['description']); ?>
            </p>

            <div class="pt-3 border-t border-slate-100 text-[11px] space-y-1.5 mb-4">
                <div class="flex justify-between">
                    <span class="text-slate-400">حساب متصل:</span>
                    <span class="font-mono text-slate-800 font-bold max-w-[140px] truncate"><?php echo $conn['account_name'] ?? '-'; ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">زمان‌بندی سنک:</span>
                    <span class="text-slate-700 font-medium"><?php echo ($conn['sync_frequency'] ?? 'daily') === 'hourly' ? 'ساعتی' : 'روزانه'; ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">آخرین بروزرسانی:</span>
                    <span class="font-mono text-slate-600 text-[10px]"><?php echo $conn['last_sync_at'] ?? 'هنوز انجام نشده'; ?></span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button onclick="openConfigConnectionModal('<?php echo $slug; ?>', '<?php echo addslashes($intg['name']); ?>', '<?php echo addslashes($conn['property_id'] ?? ''); ?>', '<?php echo addslashes($conn['account_name'] ?? ''); ?>')" class="flex-1 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold transition-all text-center cursor-pointer">
                    ⚙️ پیکربندی
                </button>
                <?php if ($isConnected): ?>
                    <button onclick="triggerSync('<?php echo $slug; ?>')" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-bold transition-all cursor-pointer">
                        🔄 سنک
                    </button>
                <?php else: ?>
                    <button onclick="initiateOauthConnection('<?php echo $slug; ?>')" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition-all cursor-pointer">
                        اتصال
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Sync Audit Logs Section -->
<div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden" id="sec-sync-logs">
    <div class="p-5 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-black text-slate-900 flex items-center gap-2">
                <span>📜</span> لاگ‌های سیستمی و حسابرسی همگام‌سازی (Sync Audit Logs)
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">ثبت دقیق تاریخ، منبع داده، تعداد رکوردهای پردازش شده و وضعیت خطای احتمالی</p>
        </div>
        <button onclick="refreshSyncLogs()" class="text-xs text-indigo-600 font-bold hover:underline cursor-pointer">بروزرسانی جدول</button>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-right text-xs">
            <thead>
                <tr class="bg-slate-50/70 border-b border-slate-200/80 text-slate-500">
                    <th class="py-3 px-4 font-black">منبع داده (Source)</th>
                    <th class="py-3 px-3 font-black text-center">زمان شروع</th>
                    <th class="py-3 px-3 font-black text-center">زمان اتمام</th>
                    <th class="py-3 px-3 font-black text-center">وضعیت</th>
                    <th class="py-3 px-3 font-black text-center">رکوردهای نرمال شده</th>
                    <th class="py-3 px-4 font-black text-left">جزئیات / خطا</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" id="sync-logs-tbody">
                <?php if (empty($syncLogs)): ?>
                <tr>
                    <td colspan="6" class="py-8 text-center text-slate-400">هنوز عملیات همگام‌سازی ثبت نشده است.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($syncLogs as $log): 
                    $isSuccess = (($log['status'] ?? '') === 'success');
                ?>
                <tr class="hover:bg-slate-50/60">
                    <td class="py-3 px-4 font-bold text-slate-900">
                        <?php echo htmlspecialchars($log['source'] ?? '-'); ?>
                    </td>
                    <td class="py-3 px-3 text-center font-mono text-slate-500 text-[11px]">
                        <?php echo htmlspecialchars($log['start_time'] ?? '-'); ?>
                    </td>
                    <td class="py-3 px-3 text-center font-mono text-slate-500 text-[11px]">
                        <?php echo htmlspecialchars($log['end_time'] ?? '-'); ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="px-2 py-0.5 rounded text-[10px] font-black <?php echo $isSuccess ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?>">
                            <?php echo $isSuccess ? 'موفق' : 'خطا'; ?>
                        </span>
                    </td>
                    <td class="py-3 px-3 text-center font-mono font-bold text-slate-800">
                        <?php echo number_format($log['records_count'] ?? 0); ?>
                    </td>
                    <td class="py-3 px-4 text-left font-mono text-slate-500 text-[11px]">
                        <?php if (!empty($log['error'])): ?>
                            <span class="text-rose-600 font-bold"><?php echo htmlspecialchars($log['error']); ?></span>
                        <?php else: ?>
                            <span class="text-emerald-700">داده‌ها با موفقیت در انبار نرمال شدند</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Configure Connection -->
<div id="modal-config-conn" class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-900" id="modal-conn-title">پیکربندی اتصال</h3>
            <button onclick="closeConfigConnectionModal()" class="text-slate-400 hover:text-slate-600 text-lg font-black">&times;</button>
        </div>

        <form id="form-config-conn" onsubmit="submitSaveConnection(event)" class="space-y-4">
            <input type="hidden" id="conn-intg-slug" value="">

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">شناسه پراپرتی / آدرس وب‌سایت (Property ID / Site URL)</label>
                <input type="text" id="conn-prop-id" placeholder="sc-domain:example.com یا properties/12345" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">نام یا عنوان اکانت</label>
                <input type="text" id="conn-account-name" placeholder="اکانت رسمی سرچ کنسول" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">دوره همگام‌سازی خودکار (Sync Frequency)</label>
                <select id="conn-sync-frequency" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-indigo-500">
                    <option value="hourly">ساعتی (Hourly Sync)</option>
                    <option value="daily" selected>روزانه (Daily Sync)</option>
                    <option value="weekly">هفتگی (Weekly Sync)</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeConfigConnectionModal()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:bg-slate-100 rounded-xl">انصراف</button>
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl">ذخیره کانکشن</button>
            </div>
        </form>
    </div>
</div>
