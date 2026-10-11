<?php
/**
 * Mark Show Management Views:
 * - Mark Show Central Team & Structure (markshow_org)
 * - Customers & Project Assignment (markshow_customers)
 * - Dynamic RBAC & Positions (markshow_roles)
 * - Ticketing & Service Request Flow (markshow_tickets)
 * - Financial Review & Receipt Verification (markshow_financial)
 */

if (!class_exists('MarkShowCore')) {
    require_once __DIR__ . '/MarkShowCore.php';
}

$tab = $_GET['tab'] ?? 'dashboard';
$sub = $_GET['sub'] ?? 'list';
$msg = null;
$msgType = 'success';

// Handle Action Posts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ms_action'] ?? '';

    // 1. Save Org Member
    if ($action === 'save_org_member') {
        $pids = $_POST['assigned_projects'] ?? ['*'];
        if (in_array('*', $pids)) {
            $pids = ['*'];
        }
        $res = MarkShowCore::saveOrgMember([
            'id' => $_POST['id'] ?? '',
            'username' => $_POST['username'] ?? '',
            'display_name' => $_POST['display_name'] ?? '',
            'mobile' => $_POST['mobile'] ?? '',
            'email' => $_POST['email'] ?? '',
            'role_id' => $_POST['role_id'] ?? 'admin',
            'status' => $_POST['status'] ?? 'active',
            'assigned_projects' => $pids
        ]);
        if ($res) {
            $msg = 'اطلاعات عضو تیم با موفقیت ثبت شد.';
        } else {
            $msg = 'خطا در ثبت اطلاعات عضو تیم.';
            $msgType = 'error';
        }
    }

    // 2. Delete Org Member
    if ($action === 'delete_org_member') {
        $mid = $_POST['id'] ?? '';
        MarkShowCore::deleteOrgMember($mid);
        $msg = 'عضو تیم حذف گردید.';
    }

    // 3. Save Role
    if ($action === 'save_role') {
        $res = MarkShowCore::saveRole([
            'id' => $_POST['id'] ?? '',
            'name' => $_POST['name'] ?? '',
            'title_fa' => $_POST['title_fa'] ?? '',
            'description' => $_POST['description'] ?? '',
            'permissions' => $_POST['permissions'] ?? []
        ]);
        if ($res) {
            $msg = 'نقش و دسترسی‌ها با موفقیت ذخیره شد.';
        } else {
            $msg = 'خطا در ذخیره نقش.';
            $msgType = 'error';
        }
    }

    // 4. Save Customer
    if ($action === 'save_customer') {
        $res = MarkShowCore::saveCustomer([
            'id' => $_POST['id'] ?? '',
            'name' => $_POST['name'] ?? '',
            'company_name' => $_POST['company_name'] ?? '',
            'mobile' => $_POST['mobile'] ?? '',
            'email' => $_POST['email'] ?? '',
            'status' => $_POST['status'] ?? 'active',
            'notes' => $_POST['notes'] ?? '',
            'project_ids' => $_POST['project_ids'] ?? []
        ]);
        if ($res['success']) {
            $msg = 'اطلاعات مشتری با موفقیت ذخیره شد.';
        } else {
            $msg = $res['error'] ?? 'خطا در ذخیره مشتری.';
            $msgType = 'error';
        }
    }

    // 5. Delete Customer
    if ($action === 'delete_customer') {
        $cid = $_POST['id'] ?? '';
        MarkShowCore::deleteCustomer($cid);
        $msg = 'مشتری و دسترسی‌های متصل حذف شد.';
    }

    // 6. Admin Create Ticket
    if ($action === 'admin_create_ticket') {
        $res = MarkShowCore::createTicket([
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'project_id' => $_POST['project_id'] ?? '',
            'customer_id' => $_POST['customer_id'] ?? '',
            'creator_username' => $_SESSION['user']['username'] ?? 'admin',
            'creator_name' => $_SESSION['user']['display_name'] ?? 'مدیریت مرکزی',
            'creator_type' => 'admin',
            'assigned_to' => $_POST['assigned_to'] ?? '',
            'priority' => $_POST['priority'] ?? 'medium',
            'service_type' => $_POST['service_type'] ?? 'free',
            'price' => $_POST['price'] ?? 0,
            'cost_description' => $_POST['cost_description'] ?? '',
            'execution_days' => $_POST['execution_days'] ?? 0,
            'deadline' => $_POST['deadline'] ?? '',
            'terms' => $_POST['terms'] ?? ''
        ]);
        if ($res['success']) {
            $msg = "تیکت با کد پیگیری {$res['tracking_code']} با موفقیت ثبت گردید.";
        } else {
            $msg = $res['error'] ?? 'خطا در ثبت تیکت.';
            $msgType = 'error';
        }
    }

    // 7. Update Ticket Pricing / Terms
    if ($action === 'set_ticket_pricing') {
        $tid = $_POST['ticket_id'] ?? '';
        $res = MarkShowCore::updateTicketCost($tid, [
            'price' => $_POST['price'] ?? 0,
            'cost_description' => $_POST['cost_description'] ?? '',
            'execution_days' => $_POST['execution_days'] ?? 0,
            'deadline' => $_POST['deadline'] ?? '',
            'terms' => $_POST['terms'] ?? ''
        ]);
        if ($res) {
            $msg = 'تعرفه خدمت و پیش‌فاکتور برای مشتری ارسال گردید.';
        } else {
            $msg = 'خطا در ثبت تعرفه.';
            $msgType = 'error';
        }
    }

    // 8. Update Ticket Status
    if ($action === 'update_ticket_status') {
        $tid = $_POST['ticket_id'] ?? '';
        $st = $_POST['status'] ?? 'open';
        MarkShowCore::updateTicketStatus($tid, $st);
        $msg = 'وضعیت تیکت تغییر یافت.';
    }

    // 9. Admin Reply Ticket
    if ($action === 'admin_reply_ticket') {
        $tid = $_POST['ticket_id'] ?? '';
        $text = trim($_POST['message'] ?? '');
        if (!empty($text)) {
            MarkShowCore::addTicketMessage($tid, [
                'sender_username' => $_SESSION['user']['username'] ?? 'admin',
                'sender_name' => $_SESSION['user']['display_name'] ?? 'تیم پشتیبانی Mark Show',
                'sender_type' => 'admin',
                'message' => $text,
                'is_internal' => !empty($_POST['is_internal']) ? 1 : 0
            ]);
            $msg = 'پاسخ شما ارسال شد.';
        }
    }

    // 10. Review Payment Receipt
    if ($action === 'review_payment') {
        $pid = $_POST['payment_id'] ?? '';
        $decision = $_POST['decision'] ?? 'approve';
        $reason = $_POST['rejection_reason'] ?? '';
        $reviewedBy = $_SESSION['user']['username'] ?? 'admin';
        $res = MarkShowCore::reviewPaymentReceipt($pid, $decision, $reviewedBy, $reason);
        if ($res) {
            $msg = ($decision === 'approve') 
                ? 'رسید با موفقیت تایید شد و تسک اجرایی در پروژه هدف ساخته شد! 🎯' 
                : 'رسید پرداخت رد گردید و پیام اطلاع‌رسانی برای مشتری ارسال شد.';
        }
    }

    // 11. Convert Ticket to Task manually
    if ($action === 'convert_ticket_to_task') {
        $tid = $_POST['ticket_id'] ?? '';
        $assignee = $_POST['assignee'] ?? 'admin';
        $taskId = MarkShowCore::convertTicketToTask($tid, [$assignee]);
        if ($taskId) {
            $msg = "تیکت با موفقیت به تسک شماره {$taskId} تبدیل گردید و در سامانه مدیریت تسک‌ها ثبت شد.";
        } else {
            $msg = "خطا در تبدیل تیکت به تسک.";
            $msgType = 'error';
        }
    }
}

// Global Metrics for Header
$metrics = MarkShowCore::getDashboardMetrics();
$db = getDB();
$allProjects = $db['projects'] ?? [];
$allRoles = MarkShowCore::getRoles();
?>

<!-- Alert Box -->
<?php if ($msg): ?>
    <div class="mb-6 p-4 rounded-2xl flex items-center justify-between text-xs font-bold shadow-sm animate-in fade-in duration-300 <?php echo $msgType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'; ?>">
        <div class="flex items-center gap-2">
            <span><?php echo $msgType === 'success' ? '✅' : '⚠️'; ?></span>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
    </div>
<?php endif; ?>

<!-- Top Central Stats Banner (Always Visible in Mark Show Module) -->
<div class="mb-8 p-6 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl text-white shadow-xl relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-rose-500/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -left-10 -top-10 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-rose-500 to-indigo-600 flex items-center justify-center text-xl shadow-lg">🏛️</span>
                <div>
                    <h1 class="text-2xl font-black tracking-tight">مرکز فرماندهی Mark Show</h1>
                    <p class="text-xs text-slate-400 font-bold">مدیریت کلان چند پروژه‌ای (Multi-Tenant)، مشتریان، تیکتینگ و مالی</p>
                </div>
            </div>
        </div>

        <!-- Quick Switcher Tabs -->
        <div class="flex flex-wrap items-center gap-2 bg-white/5 p-1.5 rounded-2xl border border-white/10 backdrop-blur-md">
            <a href="?tab=markshow_org" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all <?php echo $tab === 'markshow_org' ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/30' : 'text-slate-300 hover:bg-white/10'; ?>">
                🏛️ ساختار تیم مرکزی
            </a>
            <a href="?tab=markshow_customers" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all <?php echo $tab === 'markshow_customers' ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/30' : 'text-slate-300 hover:bg-white/10'; ?>">
                👥 مشتریان و پروژه‌ها
            </a>
            <a href="?tab=markshow_roles" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all <?php echo $tab === 'markshow_roles' ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/30' : 'text-slate-300 hover:bg-white/10'; ?>">
                🛡️ نقش‌ها و دسترسی
            </a>
            <a href="?tab=markshow_tickets" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all relative <?php echo $tab === 'markshow_tickets' ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/30' : 'text-slate-300 hover:bg-white/10'; ?>">
                🎫 تیکتینگ
                <?php if (($metrics['open_tickets'] + $metrics['waiting_payment_tickets']) > 0): ?>
                    <span class="mr-1.5 px-1.5 py-0.2 bg-amber-400 text-slate-900 rounded-full text-[9px] font-black"><?php echo ($metrics['open_tickets'] + $metrics['waiting_payment_tickets']); ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=markshow_financial" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all relative <?php echo $tab === 'markshow_financial' ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/30' : 'text-slate-300 hover:bg-white/10'; ?>">
                💳 مالی و رسیدها
                <?php if ($metrics['pending_payments_count'] > 0): ?>
                    <span class="mr-1.5 px-1.5 py-0.2 bg-amber-400 text-slate-900 rounded-full text-[9px] font-black"><?php echo $metrics['pending_payments_count']; ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Quick Metric Cards -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-6 pt-6 border-t border-white/10">
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">پروژه‌های تحت نظارت</div>
            <div class="text-xl font-black text-white"><?php echo $metrics['total_projects']; ?> <span class="text-xs font-bold text-slate-400">برند</span></div>
        </div>
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">تعداد مشتریان</div>
            <div class="text-xl font-black text-white"><?php echo $metrics['total_customers']; ?> <span class="text-xs font-bold text-slate-400">نفر</span></div>
        </div>
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">تیکت‌های باز و فعال</div>
            <div class="text-xl font-black text-amber-400"><?php echo $metrics['open_tickets'] + $metrics['in_progress_tickets']; ?> <span class="text-xs font-bold text-slate-400">مورد</span></div>
        </div>
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">در انتظار پرداخت</div>
            <div class="text-xl font-black text-rose-400"><?php echo $metrics['waiting_payment_tickets']; ?> <span class="text-xs font-bold text-slate-400">خدمت</span></div>
        </div>
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">رسیدهای در انتظار تایید</div>
            <div class="text-xl font-black text-amber-300"><?php echo $metrics['pending_payments_count']; ?> <span class="text-xs font-bold text-slate-400">رسید</span></div>
        </div>
        <div class="bg-white/5 p-3 rounded-2xl border border-white/5">
            <div class="text-[10px] text-slate-400 font-bold mb-1">مجموع درآمد تایید شده</div>
            <div class="text-lg font-black text-emerald-400"><?php echo number_format($metrics['total_revenue']); ?> <span class="text-[10px] font-bold text-slate-400">تومان</span></div>
        </div>
    </div>
</div>

<?php
// =========================================================================
// TAB 1: MARK SHOW CENTRAL TEAM & STRUCTURE
// =========================================================================
if ($tab === 'markshow_org'):
    $members = MarkShowCore::getOrgMembers();
    $org = MarkShowCore::getOrganization();
?>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-black text-slate-800">اعضای تیم مرکزی و پوزیشن‌های Mark Show</h2>
            <p class="text-xs text-slate-500 font-bold mt-1">تیم مالک و راهبری سیستم با اختیارات کلان بر روی کلیه پروژه‌ها</p>
        </div>
        <button onclick="document.getElementById('member-modal').classList.remove('hidden')" class="px-5 py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl font-black text-xs shadow-lg shadow-rose-600/20 transition-all flex items-center gap-2">
            <span>➕</span> <span>افزودن عضو جدید به تیم مرکزی</span>
        </button>
    </div>

    <!-- Members Table -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-black text-[11px] uppercase tracking-wider">
                        <th class="py-4 px-6">عضو تیم مرکزی</th>
                        <th class="py-4 px-6">نام کاربری / تماس</th>
                        <th class="py-4 px-6">پوزیشن سازمانی (نقش)</th>
                        <th class="py-4 px-6">پروژه‌های تحت نظارت</th>
                        <th class="py-4 px-6">وضعیت</th>
                        <th class="py-4 px-6 text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-bold text-slate-700">
                    <?php if (empty($members)): ?>
                        <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ کاربری در تیم مرکزی ثبت نشده است.</td></tr>
                    <?php else: foreach ($members as $m): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-500 to-rose-500 text-white flex items-center justify-center font-black text-sm shadow-sm">
                                        <?php echo mb_substr($m['display_name'] ?: $m['username'], 0, 1); ?>
                                    </div>
                                    <div>
                                        <div class="font-black text-slate-900"><?php echo htmlspecialchars($m['display_name'] ?: $m['username']); ?></div>
                                        <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($m['email'] ?: 'بدون ایمیل'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-6">
                                <span class="font-mono text-slate-600 bg-slate-100 px-2 py-1 rounded-md text-[11px]"><?php echo htmlspecialchars($m['username']); ?></span>
                                <div class="text-[11px] text-slate-500 mt-1"><?php echo htmlspecialchars($m['mobile'] ?: '—'); ?></div>
                            </td>
                            <td class="py-4 px-6">
                                <span class="px-3 py-1 rounded-full text-[11px] font-black bg-indigo-50 text-indigo-700 border border-indigo-100">
                                    <?php echo htmlspecialchars($m['role_title'] ?: $m['role_id']); ?>
                                </span>
                            </td>
                            <td class="py-4 px-6">
                                <?php if (in_array('*', $m['assigned_projects'])): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-[10px] font-black border border-emerald-100">
                                        🌐 تمام پروژه‌ها (Full Access)
                                    </span>
                                <?php else: ?>
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach ($m['assigned_projects'] as $apid): ?>
                                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px]"><?php echo htmlspecialchars($apid); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-black <?php echo $m['status'] === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'; ?>">
                                    <?php echo $m['status'] === 'active' ? '● فعال' : 'غیرفعال'; ?>
                                </span>
                            </td>
                            <td class="py-4 px-6 text-center">
                                <?php if ($m['username'] !== 'admin'): ?>
                                    <form method="POST" onsubmit="return confirm('آیا از حذف این عضو از تیم مرکزی اطمینان دارید؟')" class="inline-block">
                                        <input type="hidden" name="ms_action" value="delete_org_member">
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($m['id']); ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-rose-600 hover:bg-rose-50 text-[11px] font-black transition-all">حذف</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400">مالک کل سیستم</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div id="member-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-3xl max-w-lg w-full p-8 shadow-2xl animate-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                <h3 class="font-black text-lg text-slate-900">افزودن عضو به تیم Mark Show</h3>
                <button onclick="document.getElementById('member-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="ms_action" value="save_org_member">
                <div class="space-y-4 text-xs font-bold text-slate-700">
                    <div>
                        <label class="block mb-1 text-slate-500">نام کامل یا عنوان شخص</label>
                        <input type="text" name="display_name" required placeholder="مثلاً: علیرضا محمدی" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block mb-1 text-slate-500">نام کاربری سیستم</label>
                            <input type="text" name="username" required placeholder="alireza" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono text-left">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">شماره موبایل</label>
                            <input type="text" name="mobile" placeholder="09123456789" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono text-left">
                        </div>
                    </div>
                    <div>
                        <label class="block mb-1 text-slate-500">پوزیشن سازمانی (Role)</label>
                        <select name="role_id" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-bold">
                            <?php foreach ($allRoles as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['id']); ?>"><?php echo htmlspecialchars($r['title_fa']); ?> (<?php echo htmlspecialchars($r['name']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1 text-slate-500">پروژه‌های مجاز</label>
                        <select name="assigned_projects[]" multiple class="w-full h-24 px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-bold text-xs">
                            <option value="*" selected>🌐 دسترسی به تمام پروژه‌ها (پیش‌فرض تیم مرکزی)</option>
                            <?php foreach ($allProjects as $prj): ?>
                                <option value="<?php echo htmlspecialchars($prj['id']); ?>"><?php echo htmlspecialchars($prj['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-[10px] text-slate-400 mt-1 block">برای انتخاب چندگانه کلید Ctrl را نگه دارید.</span>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 mt-8 pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('member-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-slate-500 hover:bg-slate-100 font-bold text-xs">انصراف</button>
                    <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">ثبت عضو جدید</button>
                </div>
            </form>
        </div>
    </div>

<?php
// =========================================================================
// TAB 2: CUSTOMERS MANAGEMENT (MULTI-PROJECT TENANTS)
// =========================================================================
elseif ($tab === 'markshow_customers'):
    $q = $_GET['q'] ?? '';
    $customers = MarkShowCore::getCustomers($q);
?>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-black text-slate-800">مدیریت مشتریان و کارفرمایان</h2>
            <p class="text-xs text-slate-500 font-bold mt-1">هر مشتری می‌تواند مالک یک یا چند پروژه تحت سیستم Mark Show باشد</p>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" class="relative">
                <input type="hidden" name="tab" value="markshow_customers">
                <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="🔍 جستجوی مشتری..." class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-rose-500 w-60">
            </form>
            <button onclick="document.getElementById('customer-modal').classList.remove('hidden')" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20 flex items-center gap-2">
                <span>➕</span> <span>مشتری جدید</span>
            </button>
        </div>
    </div>

    <!-- Customers Grid / Table -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-black text-[11px] uppercase tracking-wider">
                        <th class="py-4 px-6">نام کارفرما / برند</th>
                        <th class="py-4 px-6">شماره همراه</th>
                        <th class="py-4 px-6">پروژه‌های فعال متصل</th>
                        <th class="py-4 px-6">تیکت‌ها</th>
                        <th class="py-4 px-6">وضعیت</th>
                        <th class="py-4 px-6 text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-bold text-slate-700">
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ مشتری‌ای یافت نشد. می‌توانید با دکمه بالا مشتری جدید تعریف کنید.</td></tr>
                    <?php else: foreach ($customers as $c): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-black text-sm">
                                        👤
                                    </div>
                                    <div>
                                        <div class="font-black text-slate-900"><?php echo htmlspecialchars($c['name']); ?></div>
                                        <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($c['company_name'] ?: 'شخصی'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-6 font-mono text-slate-600"><?php echo htmlspecialchars($c['mobile']); ?></td>
                            <td class="py-4 px-6">
                                <?php if (!empty($c['projects'])): ?>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($c['projects'] as $cp): ?>
                                            <a href="?switch_project=<?php echo urlencode($cp['id']); ?>" class="px-2 py-0.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10px] font-bold border border-indigo-100 transition-all flex items-center gap-1">
                                                <span>🏢</span> <span><?php echo htmlspecialchars($cp['name']); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-400 text-[11px]">بدون پروژه متصل</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6">
                                <a href="?tab=markshow_tickets&customer_id=<?php echo urlencode($c['id']); ?>" class="text-rose-600 hover:underline">
                                    <?php echo (int)($c['tickets_count'] ?? 0); ?> تیکت
                                </a>
                            </td>
                            <td class="py-4 px-6">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black <?php echo $c['status'] === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'; ?>">
                                    <?php echo $c['status'] === 'active' ? 'فعال' : 'معلق'; ?>
                                </span>
                            </td>
                            <td class="py-4 px-6 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button onclick='editCustomer(<?php echo json_encode($c, JSON_UNESCAPED_UNICODE); ?>)' class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10px]">ویرایش</button>
                                    <form method="POST" onsubmit="return confirm('آیا از حذف این مشتری مطمئن هستید؟')" class="inline-block">
                                        <input type="hidden" name="ms_action" value="delete_customer">
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($c['id']); ?>">
                                        <button type="submit" class="px-2 py-1 rounded text-rose-600 hover:bg-rose-50 text-[10px]">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Customer Modal -->
    <div id="customer-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-3xl max-w-lg w-full p-8 shadow-2xl">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                <h3 id="cust-modal-title" class="font-black text-lg text-slate-900">تعریف مشتری جدید</h3>
                <button onclick="document.getElementById('customer-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="ms_action" value="save_customer">
                <input type="hidden" name="id" id="cust-id" value="">
                <div class="space-y-4 text-xs font-bold text-slate-700">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block mb-1 text-slate-500">نام شخص یا مخاطب</label>
                            <input type="text" name="name" id="cust-name" required placeholder="مثلا: آقای رستمی" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">نام شرکت / برند</label>
                            <input type="text" name="company_name" id="cust-company" placeholder="مثلا: پوشاک تن‌درست" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block mb-1 text-slate-500">شماره موبایل (ورود به پورتال)</label>
                            <input type="text" name="mobile" id="cust-mobile" required placeholder="0912..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono text-left">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">ایمیل</label>
                            <input type="email" name="email" id="cust-email" placeholder="customer@domain.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono text-left">
                        </div>
                    </div>
                    <div>
                        <label class="block mb-1 text-slate-500">انتساب به پروژه‌ها (Multi-Project)</label>
                        <select name="project_ids[]" id="cust-projects" multiple class="w-full h-28 px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-bold text-xs">
                            <?php foreach ($allProjects as $prj): ?>
                                <option value="<?php echo htmlspecialchars($prj['id']); ?>"><?php echo htmlspecialchars($prj['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-[10px] text-slate-400 mt-1 block">می‌توانید یک یا چند پروژه را به این مشتری متصل کنید.</span>
                    </div>
                    <div>
                        <label class="block mb-1 text-slate-500">یادداشت / توضیحات قرارداد</label>
                        <textarea name="notes" id="cust-notes" rows="2" placeholder="توضیحات و نیازمندی‌های مشتری..." class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 mt-8 pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('customer-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-slate-500 hover:bg-slate-100 font-bold text-xs">انصراف</button>
                    <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">ذخیره پرونده مشتری</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function editCustomer(c) {
        document.getElementById('cust-id').value = c.id || '';
        document.getElementById('cust-name').value = c.name || '';
        document.getElementById('cust-company').value = c.company_name || '';
        document.getElementById('cust-mobile').value = c.mobile || '';
        document.getElementById('cust-email').value = c.email || '';
        document.getElementById('cust-notes').value = c.notes || '';
        document.getElementById('cust-modal-title').textContent = 'ویرایش مشتری: ' + (c.name || '');

        var sel = document.getElementById('cust-projects');
        var pids = [];
        if (c.projects) {
            for (var i = 0; i < c.projects.length; i++) {
                pids.push(c.projects[i].id);
            }
        }
        for (var j = 0; j < sel.options.length; j++) {
            sel.options[j].selected = pids.indexOf(sel.options[j].value) !== -1;
        }
        document.getElementById('customer-modal').classList.remove('hidden');
    }
    </script>

<?php
// =========================================================================
// TAB 3: DYNAMIC RBAC (ROLES & PERMISSIONS)
// =========================================================================
elseif ($tab === 'markshow_roles'):
    $allPermsList = [
        'all' => '⭐ دسترسی کامل به کل سیستم (Super Admin)',
        'manage_projects' => '🏢 ایجاد و مدیریت پروژه‌ها و سازمان',
        'manage_users' => '👥 مدیریت پرسنل و دسترسی کاربران',
        'manage_tickets' => '🎫 مدیریت کامل تیکت‌ها و زمان‌بندی',
        'manage_financial' => '💳 مدیریت مالی، تعرفه‌ها و حسابداری',
        'approve_payments' => '✅ تایید یا رد رسیدهای بانکی',
        'manage_tasks' => '🎯 مدیریت مأموریت‌ها و تسک‌های پروژه',
        'manage_content' => '💎 تولید و مدیریت کتابخانه کالا و محتوا',
        'manage_calendar' => '📅 مدیریت و زمان‌بندی تقویم انتشار',
        'manage_seo' => '🚀 مدیریت سئو و هوش رتبه کلمات کلیدی',
        'view_reports' => '📊 مشاهده گزارش‌ها و آمار تجاری'
    ];
?>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-black text-slate-800">سیستم نقش‌ها و سطوح دسترسی (RBAC)</h2>
            <p class="text-xs text-slate-500 font-bold mt-1">تعریف پوزیشن‌های شغلی تیم مرکزی (PM، مالی، فنی، پشتیبانی و ...) و اختیارات اختصاصی</p>
        </div>
        <button onclick="document.getElementById('role-modal').classList.remove('hidden')" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20 flex items-center gap-2">
            <span>➕</span> <span>تعریف نقش جدید</span>
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <?php foreach ($allRoles as $r): ?>
            <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-700 flex items-center justify-center font-black text-lg">🛡️</span>
                        <?php if ($r['is_system']): ?>
                            <span class="px-2 py-0.5 bg-slate-100 text-slate-500 rounded-md text-[9px] font-black">سیستمی پیش‌فرض</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-rose-50 text-rose-600 rounded-md text-[9px] font-black">سفارشی</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-base font-black text-slate-900"><?php echo htmlspecialchars($r['title_fa']); ?></h3>
                    <div class="text-[11px] font-mono text-slate-400 mb-2"><?php echo htmlspecialchars($r['name']); ?></div>
                    <p class="text-xs text-slate-500 font-bold leading-relaxed mb-4"><?php echo htmlspecialchars($r['description'] ?: 'بدون توضیح'); ?></p>
                    
                    <div class="space-y-1 mb-6">
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2">مجوزهای فعال:</div>
                        <div class="flex flex-wrap gap-1">
                            <?php foreach ($r['permissions'] as $p): ?>
                                <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded text-[10px] font-bold"><?php echo htmlspecialchars($p); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                    <button onclick='editRole(<?php echo json_encode($r, JSON_UNESCAPED_UNICODE); ?>)' class="px-3 py-1.5 rounded-lg text-indigo-600 hover:bg-indigo-50 text-xs font-black">ویرایش دسترسی‌ها</button>
                    <?php if (!$r['is_system']): ?>
                        <form method="POST" onsubmit="return confirm('آیا از حذف این نقش اطمینان دارید؟')" class="inline-block">
                            <input type="hidden" name="ms_action" value="delete_role">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($r['id']); ?>">
                            <button type="submit" class="px-2.5 py-1 text-rose-600 hover:bg-rose-50 rounded-lg text-xs font-black">حذف</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Role Modal -->
    <div id="role-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-3xl max-w-lg w-full p-8 shadow-2xl">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                <h3 id="role-modal-title" class="font-black text-lg text-slate-900">تعریف نقش / پوزیشن سازمانی</h3>
                <button onclick="document.getElementById('role-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="ms_action" value="save_role">
                <input type="hidden" name="id" id="role-id" value="">
                <div class="space-y-4 text-xs font-bold text-slate-700">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block mb-1 text-slate-500">عنوان فارسی نقش</label>
                            <input type="text" name="title_fa" id="role-title-fa" required placeholder="مثلا: مدیر روابط عمومی" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">شناسه انگلیسی (کد سیستم)</label>
                            <input type="text" name="name" id="role-name" required placeholder="pr_manager" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono text-left">
                        </div>
                    </div>
                    <div>
                        <label class="block mb-1 text-slate-500">شرح وظایف و اختیارات</label>
                        <textarea name="description" id="role-desc" rows="2" placeholder="توضیح پوزیشن سازمانی..." class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500"></textarea>
                    </div>
                    <div>
                        <label class="block mb-2 text-slate-500">انتخاب مجوزهای دسترسی (Permissions):</label>
                        <div class="space-y-2 max-h-48 overflow-y-auto p-2 bg-slate-50 rounded-xl border border-slate-200">
                            <?php foreach ($allPermsList as $pk => $pTitle): ?>
                                <label class="flex items-center gap-2 p-1.5 hover:bg-white rounded-lg cursor-pointer">
                                    <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($pk); ?>" class="role-perm-cb rounded text-rose-600">
                                    <span class="text-xs"><?php echo htmlspecialchars($pTitle); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 mt-8 pt-4 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('role-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-slate-500 hover:bg-slate-100 font-bold text-xs">انصراف</button>
                    <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">ذخیره نقش</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function editRole(r) {
        document.getElementById('role-id').value = r.id || '';
        document.getElementById('role-title-fa').value = r.title_fa || '';
        document.getElementById('role-name').value = r.name || '';
        document.getElementById('role-desc').value = r.description || '';
        document.getElementById('role-modal-title').textContent = 'ویرایش نقش: ' + (r.title_fa || '');

        var cbs = document.querySelectorAll('.role-perm-cb');
        for (var i = 0; i < cbs.length; i++) {
            cbs[i].checked = (r.permissions && r.permissions.indexOf(cbs[i].value) !== -1);
        }
        document.getElementById('role-modal').classList.remove('hidden');
    }
    </script>

<?php
// =========================================================================
// TAB 4: TICKETING & SERVICE REQUESTS
// =========================================================================
elseif ($tab === 'markshow_tickets'):
    $ticketId = $_GET['ticket'] ?? '';
    $selectedTicket = !empty($ticketId) ? MarkShowCore::getTicketById($ticketId) : null;
    $ticketsList = MarkShowCore::getTickets($_GET);
?>
    <?php if ($selectedTicket): ?>
        <!-- Ticket Detail View -->
        <div class="mb-6 flex items-center justify-between">
            <a href="?tab=markshow_tickets" class="inline-flex items-center gap-1.5 text-xs font-black text-slate-500 hover:text-slate-900 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-xs">
                <span>←</span> <span>بازگشت به لیست تیکت‌ها</span>
            </a>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-400 font-bold">کد پیگیری:</span>
                <span class="font-mono text-sm font-black text-indigo-700 bg-indigo-50 px-3 py-1 rounded-xl border border-indigo-100"><?php echo htmlspecialchars($selectedTicket['tracking_code']); ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Left: Messages & Timeline -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Ticket Header Info -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <h2 class="text-xl font-black text-slate-900"><?php echo htmlspecialchars($selectedTicket['title']); ?></h2>
                        <span class="px-3 py-1 rounded-full text-xs font-black <?php 
                            if ($selectedTicket['status'] === 'waiting_payment') echo 'bg-rose-50 text-rose-600 border border-rose-100';
                            elseif ($selectedTicket['status'] === 'in_progress') echo 'bg-amber-50 text-amber-700 border border-amber-100';
                            elseif ($selectedTicket['status'] === 'completed') echo 'bg-emerald-50 text-emerald-700 border border-emerald-100';
                            else echo 'bg-indigo-50 text-indigo-700 border border-indigo-100';
                        ?>">
                            <?php 
                                switch($selectedTicket['status']) {
                                    case 'waiting_payment': echo '💳 در انتظار پرداخت'; break;
                                    case 'in_progress': echo '⏳ در حال اجرا'; break;
                                    case 'completed': echo '✅ تکمیل شده'; break;
                                    case 'closed': echo '🔒 بسته شده'; break;
                                    default: echo '📝 ثبت اولیه / باز'; break;
                                }
                            ?>
                        </span>
                    </div>
                    <div class="text-xs text-slate-500 font-bold flex flex-wrap items-center gap-4">
                        <span>🏢 پروژه: <strong><?php echo htmlspecialchars($selectedTicket['project_name'] ?: $selectedTicket['project_id']); ?></strong></span>
                        <span>👤 فرستنده: <strong><?php echo htmlspecialchars($selectedTicket['customer_name'] ?: $selectedTicket['creator_username']); ?></strong></span>
                        <span>📅 تاریخ: <?php echo date('Y/m/d H:i', $selectedTicket['created_at']); ?></span>
                    </div>
                </div>

                <!-- Messages Thread -->
                <div class="space-y-4">
                    <?php foreach ($selectedTicket['messages'] as $msgItem): ?>
                        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm <?php echo $msgItem['is_internal'] ? 'border-amber-200 bg-amber-50/20' : ''; ?>">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-xl <?php echo $msgItem['sender_type'] === 'admin' ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-700'; ?> flex items-center justify-center text-xs font-black">
                                        <?php echo $msgItem['sender_type'] === 'admin' ? '🛡️' : '👤'; ?>
                                    </span>
                                    <div>
                                        <div class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($msgItem['sender_name']); ?></div>
                                        <div class="text-[10px] text-slate-400"><?php echo date('Y/m/d H:i', $msgItem['created_at']); ?></div>
                                    </div>
                                </div>
                                <?php if ($msgItem['is_internal']): ?>
                                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-[9px] font-black">یادداشت داخلی تیم مرکزی</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs font-bold text-slate-700 leading-relaxed whitespace-pre-wrap">
                                <?php echo htmlspecialchars($msgItem['message']); ?>
                            </div>
                            <?php if (!empty($msgItem['attachments'])): ?>
                                <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap gap-2">
                                    <?php foreach ($msgItem['attachments'] as $att): ?>
                                        <a href="<?php echo htmlspecialchars($att); ?>" target="_blank" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-xl text-[10px] font-black text-slate-700 flex items-center gap-1.5">
                                            <span>📎</span> <span>مشاهده فایل ضمیمه</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Reply Box -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <h3 class="text-sm font-black text-slate-900 mb-4">ارسال پاسخ به مشتری یا یادداشت داخلی</h3>
                    <form method="POST">
                        <input type="hidden" name="ms_action" value="admin_reply_ticket">
                        <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                        <textarea name="message" rows="4" required placeholder="متن پاسخ خود را اینجا بنویسید..." class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:border-rose-500 mb-4"></textarea>
                        
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_internal" value="1" class="rounded text-amber-600">
                                <span class="text-xs font-bold text-slate-600">یادداشت داخلی تیم (مشتری این پیام را نخواهد دید)</span>
                            </label>
                            <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-black shadow-md shadow-rose-600/20">ارسال پاسخ</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Sidebar: Controls & Financial Status -->
            <div class="space-y-6">
                <!-- Status Control -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <h4 class="text-xs font-black text-slate-900 mb-3">کنترل وضعیت تیکت</h4>
                    <form method="POST">
                        <input type="hidden" name="ms_action" value="update_ticket_status">
                        <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                        <select name="status" onchange="this.form.submit()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold outline-none focus:border-rose-500">
                            <option value="open" <?php echo $selectedTicket['status'] === 'open' ? 'selected' : ''; ?>>📝 باز / بررسی اولیه</option>
                            <option value="waiting_payment" <?php echo $selectedTicket['status'] === 'waiting_payment' ? 'selected' : ''; ?>>💳 در انتظار پرداخت هزینه</option>
                            <option value="in_progress" <?php echo $selectedTicket['status'] === 'in_progress' ? 'selected' : ''; ?>>⏳ در حال اجرا</option>
                            <option value="completed" <?php echo $selectedTicket['status'] === 'completed' ? 'selected' : ''; ?>>✅ تکمیل و تحویل شده</option>
                            <option value="closed" <?php echo $selectedTicket['status'] === 'closed' ? 'selected' : ''; ?>>🔒 بسته شده</option>
                        </select>
                    </form>

                    <!-- Convert to Task Action -->
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <?php if (!empty($selectedTicket['converted_task_id'])): ?>
                            <div class="p-3 bg-emerald-50 rounded-2xl border border-emerald-100 text-xs font-bold text-emerald-800">
                                <div class="flex items-center gap-1.5 mb-1 font-black">
                                    <span>🎯</span> <span>متصل به تسک اجرایی:</span>
                                </div>
                                <div class="font-mono text-[11px]"><?php echo htmlspecialchars($selectedTicket['converted_task_id']); ?></div>
                                <a href="task_manager.php?switch_project=<?php echo urlencode($selectedTicket['project_id']); ?>" target="_blank" class="inline-block mt-2 text-[10px] text-indigo-700 underline font-black">مشاهده در مدیریت تسک‌ها →</a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="ms_action" value="convert_ticket_to_task">
                                <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                                <button type="submit" class="w-full py-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-2xl font-black text-xs border border-indigo-200/60 transition-all flex items-center justify-center gap-2">
                                    <span>🎯</span> <span>تبدیل مستقیم به تسک مأموریت</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pricing & Cost Specification Box -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <h4 class="text-xs font-black text-slate-900 mb-2 flex items-center justify-between">
                        <span>💰 برآورد مالی و شرایط خدمت</span>
                        <span class="text-[10px] px-2 py-0.5 rounded <?php echo $selectedTicket['service_type'] === 'paid' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'; ?> font-bold">
                            <?php echo $selectedTicket['service_type'] === 'paid' ? 'خدمت هزینه‌دار' : 'رایگان'; ?>
                        </span>
                    </h4>
                    
                    <form method="POST" class="space-y-3 mt-4 text-xs font-bold text-slate-700">
                        <input type="hidden" name="ms_action" value="set_ticket_pricing">
                        <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                        
                        <div>
                            <label class="block mb-1 text-slate-500">مبلغ خدمت (تومان) - 0 برای رایگان</label>
                            <input type="number" name="price" value="<?php echo (float)$selectedTicket['price']; ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">مهلت اجرا (تعداد روز کاری)</label>
                            <input type="number" name="execution_days" value="<?php echo (int)$selectedTicket['execution_days']; ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">تاریخ تحویل نهایی (Deadline)</label>
                            <input type="date" name="deadline" value="<?php echo htmlspecialchars($selectedTicket['deadline'] ?: ''); ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 font-mono">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">شرح هزینه و الزامات تحویل</label>
                            <textarea name="cost_description" rows="2" placeholder="جزییات هزینه و محدوده کار..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500"><?php echo htmlspecialchars($selectedTicket['cost_description'] ?: ''); ?></textarea>
                        </div>
                        <button type="submit" class="w-full py-2.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-black text-xs shadow-md">
                            ثبت و ارسال پیش‌فاکتور به کارفرما
                        </button>
                    </form>
                </div>

                <!-- Payments attached to this ticket -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <h4 class="text-xs font-black text-slate-900 mb-3">رسیدهای پرداخت تیکت</h4>
                    <?php if (empty($selectedTicket['payments'])): ?>
                        <div class="text-center py-6 text-slate-400 text-xs">هنوز رسیدی ثبت نشده است.</div>
                    <?php else: foreach ($selectedTicket['payments'] as $pay): ?>
                        <div class="p-4 rounded-2xl border border-slate-100 bg-slate-50/50 mb-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-black text-slate-900"><?php echo number_format($pay['amount']); ?> تومان</span>
                                <span class="px-2 py-0.5 rounded text-[9px] font-black <?php echo $pay['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800' : ($pay['status'] === 'rejected' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'); ?>">
                                    <?php echo $pay['status'] === 'approved' ? 'تایید شده' : ($pay['status'] === 'rejected' ? 'رد شده' : 'در انتظار بررسی'); ?>
                                </span>
                            </div>
                            <?php if (!empty($pay['receipt_file'])): ?>
                                <a href="<?php echo htmlspecialchars($pay['receipt_file']); ?>" target="_blank" class="text-indigo-600 hover:underline text-[11px] font-bold block mb-2">
                                    🖼️ مشاهده تصویر فیش واریزی
                                </a>
                            <?php endif; ?>
                            <?php if ($pay['status'] === 'pending'): ?>
                                <div class="flex items-center gap-2 mt-3 pt-3 border-t border-slate-200">
                                    <form method="POST" class="w-1/2">
                                        <input type="hidden" name="ms_action" value="review_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($pay['id']); ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="w-full py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-black">تایید پرداخت</button>
                                    </form>
                                    <button onclick="document.getElementById('reject-pay-<?php echo $pay['id']; ?>').classList.toggle('hidden')" class="w-1/2 py-1.5 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg text-[10px] font-black">رد فیش</button>
                                </div>
                                <form id="reject-pay-<?php echo $pay['id']; ?>" method="POST" class="mt-2 hidden">
                                    <input type="hidden" name="ms_action" value="review_payment">
                                    <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($pay['id']); ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <input type="text" name="rejection_reason" required placeholder="دلیل رد..." class="w-full px-2 py-1 bg-white border border-slate-200 rounded text-xs mb-1">
                                    <button type="submit" class="w-full py-1 bg-rose-600 text-white rounded text-[10px] font-black">ارسال علت رد</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Tickets List View -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-black text-slate-800">میز خدمت و تیکت‌های پشتیبانی</h2>
                <p class="text-xs text-slate-500 font-bold mt-1">مدیریت درخواست‌های کارفرمایان و تبدیل هوشمند به تسک‌های پروژه</p>
            </div>
            <button onclick="document.getElementById('new-ticket-modal').classList.remove('hidden')" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20 flex items-center gap-2">
                <span>➕</span> <span>ثبت تیکت جدید</span>
            </button>
        </div>

        <!-- Filter bar -->
        <div class="bg-white p-4 rounded-3xl border border-slate-200/80 shadow-sm mb-6 flex flex-wrap items-center gap-3 text-xs font-bold">
            <form method="GET" class="flex flex-wrap items-center gap-3 w-full">
                <input type="hidden" name="tab" value="markshow_tickets">
                <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" placeholder="🔍 جستجو در عنوان، کد یا شرح..." class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 flex-1 min-w-[200px]">
                
                <select name="status" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="open" <?php echo ($_GET['status'] ?? '') === 'open' ? 'selected' : ''; ?>>باز</option>
                    <option value="waiting_payment" <?php echo ($_GET['status'] ?? '') === 'waiting_payment' ? 'selected' : ''; ?>>در انتظار پرداخت</option>
                    <option value="in_progress" <?php echo ($_GET['status'] ?? '') === 'in_progress' ? 'selected' : ''; ?>>در حال انجام</option>
                    <option value="completed" <?php echo ($_GET['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>تکمیل شده</option>
                </select>

                <select name="project_id" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                    <option value="">همه پروژه‌ها</option>
                    <?php foreach ($allProjects as $prj): ?>
                        <option value="<?php echo htmlspecialchars($prj['id']); ?>" <?php echo ($_GET['project_id'] ?? '') === $prj['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prj['name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="px-5 py-2.5 bg-slate-900 text-white rounded-xl font-black">فیلتر</button>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-black text-[11px] uppercase tracking-wider">
                            <th class="py-4 px-6">کد پیگیری و عنوان</th>
                            <th class="py-4 px-6">پروژه و کارفرما</th>
                            <th class="py-4 px-6">نوع و هزینه</th>
                            <th class="py-4 px-6">اولویت</th>
                            <th class="py-4 px-6">وضعیت</th>
                            <th class="py-4 px-6">تاریخ</th>
                            <th class="py-4 px-6 text-center">مشاهده</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-bold text-slate-700">
                        <?php if (empty($ticketsList)): ?>
                            <tr><td colspan="7" class="py-12 text-center text-slate-400">هیچ تیکتی با این شرایط یافت نشد.</td></tr>
                        <?php else: foreach ($ticketsList as $tItem): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-[11px] font-black text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-lg border border-indigo-100"><?php echo htmlspecialchars($tItem['tracking_code']); ?></span>
                                        <a href="?tab=markshow_tickets&ticket=<?php echo urlencode($tItem['id']); ?>" class="font-black text-slate-900 hover:text-rose-600 transition-colors">
                                            <?php echo htmlspecialchars($tItem['title']); ?>
                                        </a>
                                    </div>
                                    <?php if (!empty($tItem['converted_task_id'])): ?>
                                        <div class="text-[10px] text-emerald-600 mt-1 flex items-center gap-1">
                                            <span>🎯</span> <span>متصل به تسک: <?php echo htmlspecialchars($tItem['converted_task_id']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="font-bold text-slate-900"><?php echo htmlspecialchars($tItem['project_name'] ?: $tItem['project_id']); ?></div>
                                    <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($tItem['customer_name'] ?: $tItem['creator_username']); ?></div>
                                </td>
                                <td class="py-4 px-6">
                                    <?php if ($tItem['service_type'] === 'paid' && $tItem['price'] > 0): ?>
                                        <span class="font-black text-rose-600"><?php echo number_format($tItem['price']); ?> تومان</span>
                                    <?php else: ?>
                                        <span class="text-slate-400">خدمت رایگان</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black <?php 
                                        if ($tItem['priority'] === 'urgent') echo 'bg-rose-100 text-rose-800';
                                        elseif ($tItem['priority'] === 'high') echo 'bg-amber-100 text-amber-800';
                                        else echo 'bg-slate-100 text-slate-600';
                                    ?>">
                                        <?php 
                                            switch($tItem['priority']) {
                                                case 'urgent': echo '🔴 اضطراری'; break;
                                                case 'high': echo '🟠 بالا'; break;
                                                case 'low': echo '🟢 پایین'; break;
                                                default: echo '🟡 متوسط'; break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black <?php 
                                        if ($tItem['status'] === 'waiting_payment') echo 'bg-rose-50 text-rose-600';
                                        elseif ($tItem['status'] === 'in_progress') echo 'bg-amber-50 text-amber-700';
                                        elseif ($tItem['status'] === 'completed') echo 'bg-emerald-50 text-emerald-700';
                                        else echo 'bg-indigo-50 text-indigo-700';
                                    ?>">
                                        <?php 
                                            switch($tItem['status']) {
                                                case 'waiting_payment': echo 'در انتظار پرداخت'; break;
                                                case 'in_progress': echo 'در حال اجرا'; break;
                                                case 'completed': echo 'تکمیل شده'; break;
                                                case 'closed': echo 'بسته شده'; break;
                                                default: echo 'باز'; break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-[11px] text-slate-500 font-mono">
                                    <?php echo date('Y/m/d', $tItem['created_at']); ?>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <a href="?tab=markshow_tickets&ticket=<?php echo urlencode($tItem['id']); ?>" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all">
                                        بررسی 💬
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create Ticket Modal -->
        <div id="new-ticket-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
            <div class="bg-white rounded-3xl max-w-lg w-full p-8 shadow-2xl">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                    <h3 class="font-black text-lg text-slate-900">ثبت تیکت / خدمت نو برای پروژه</h3>
                    <button onclick="document.getElementById('new-ticket-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="ms_action" value="admin_create_ticket">
                    <div class="space-y-4 text-xs font-bold text-slate-700">
                        <div>
                            <label class="block mb-1 text-slate-500">پروژه هدف</label>
                            <select name="project_id" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                                <?php foreach ($allProjects as $prj): ?>
                                    <option value="<?php echo htmlspecialchars($prj['id']); ?>"><?php echo htmlspecialchars($prj['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">عنوان خدمت / درخواست</label>
                            <input type="text" name="title" required placeholder="مثلاً: طراحی بنر کمپین بلک فرایدی" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">توضیحات و نیازمندی‌ها</label>
                            <textarea name="description" rows="3" required placeholder="جزییات کامل درخواست..." class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-slate-500">نوع خدمت</label>
                                <select name="service_type" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                    <option value="free">خدمت پشتیبانی (رایگان)</option>
                                    <option value="paid">خدمت هزینه‌دار / سفارشی</option>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-1 text-slate-500">مبلغ (در صورت هزینه‌دار بودن)</label>
                                <input type="number" name="price" value="0" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-slate-500">اولویت</label>
                                <select name="priority" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                    <option value="medium">متوسط</option>
                                    <option value="high">بالا</option>
                                    <option value="urgent">اضطراری</option>
                                    <option value="low">پایین</option>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-1 text-slate-500">مهلت انجام (روز کاری)</label>
                                <input type="number" name="execution_days" value="3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono">
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 mt-8 pt-4 border-t border-slate-100">
                        <button type="button" onclick="document.getElementById('new-ticket-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-slate-500 hover:bg-slate-100 font-bold text-xs">انصراف</button>
                        <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">ثبت تیکت</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php
// =========================================================================
// TAB 5: FINANCIAL & PAYMENT REVIEW FLOW
// =========================================================================
elseif ($tab === 'markshow_financial'):
    $statusFilter = $_GET['status'] ?? '';
    $payments = MarkShowCore::getTicketPayments(null, $statusFilter);
?>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-black text-slate-800">مدیریت مالی و تایید رسیدها</h2>
            <p class="text-xs text-slate-500 font-bold mt-1">بررسی رسیدهای بانکی، تایید پرداخت و راه‌اندازی خودکار تسک‌های خدمت</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?tab=markshow_financial" class="px-3 py-1.5 rounded-xl text-xs font-black <?php echo empty($statusFilter) ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 border border-slate-200'; ?>">همه</a>
            <a href="?tab=markshow_financial&status=pending" class="px-3 py-1.5 rounded-xl text-xs font-black <?php echo $statusFilter === 'pending' ? 'bg-amber-500 text-white' : 'bg-white text-slate-600 border border-slate-200'; ?>">در انتظار بررسی (<?php echo $metrics['pending_payments_count']; ?>)</a>
            <a href="?tab=markshow_financial&status=approved" class="px-3 py-1.5 rounded-xl text-xs font-black <?php echo $statusFilter === 'approved' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 border border-slate-200'; ?>">تایید شده</a>
            <a href="?tab=markshow_financial&status=rejected" class="px-3 py-1.5 rounded-xl text-xs font-black <?php echo $statusFilter === 'rejected' ? 'bg-rose-600 text-white' : 'bg-white text-slate-600 border border-slate-200'; ?>">رد شده</a>
        </div>
    </div>

    <!-- Payments List -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-black text-[11px] uppercase tracking-wider">
                        <th class="py-4 px-6">تیکت و کارفرما</th>
                        <th class="py-4 px-6">مبلغ پرداختی</th>
                        <th class="py-4 px-6">تصویر فیش / رسید</th>
                        <th class="py-4 px-6">تاریخ و شماره پیگیری</th>
                        <th class="py-4 px-6">وضعیت مالی</th>
                        <th class="py-4 px-6 text-center">اقدام</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-bold text-slate-700">
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ تراکنش یا فیش واریزی یافت نشد.</td></tr>
                    <?php else: foreach ($payments as $pay): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="py-4 px-6">
                                <a href="?tab=markshow_tickets&ticket=<?php echo urlencode($pay['ticket_id']); ?>" class="font-black text-slate-900 hover:text-rose-600 block">
                                    <?php echo htmlspecialchars($pay['ticket_title']); ?>
                                </a>
                                <div class="text-[10px] text-slate-400 mt-0.5">
                                    کد: <?php echo htmlspecialchars($pay['tracking_code']); ?> | کارفرما: <?php echo htmlspecialchars($pay['customer_name'] ?: 'ناشناس'); ?>
                                </div>
                            </td>
                            <td class="py-4 px-6 font-black text-slate-900 text-sm">
                                <?php echo number_format($pay['amount']); ?> <span class="text-[10px] font-bold text-slate-400">تومان</span>
                            </td>
                            <td class="py-4 px-6">
                                <?php if (!empty($pay['receipt_file'])): ?>
                                    <a href="<?php echo htmlspecialchars($pay['receipt_file']); ?>" target="_blank" class="px-2.5 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10px] font-black inline-flex items-center gap-1 border border-indigo-100">
                                        <span>🖼️</span> <span>مشاهده فیش</span>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400 text-[11px]">بدون تصویر</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6">
                                <div class="font-mono text-slate-600 text-[11px]"><?php echo htmlspecialchars($pay['payment_ref'] ?: '—'); ?></div>
                                <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($pay['payment_date'] ?: date('Y/m/d', $pay['created_at'])); ?></div>
                            </td>
                            <td class="py-4 px-6">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-black <?php echo $pay['status'] === 'approved' ? 'bg-emerald-50 text-emerald-700' : ($pay['status'] === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800'); ?>">
                                    <?php echo $pay['status'] === 'approved' ? '✅ تایید شده' : ($pay['status'] === 'rejected' ? '❌ رد شده' : '⏳ در انتظار تایید'); ?>
                                </span>
                            </td>
                            <td class="py-4 px-6 text-center">
                                <?php if ($pay['status'] === 'pending'): ?>
                                    <div class="flex items-center justify-center gap-1.5">
                                        <form method="POST">
                                            <input type="hidden" name="ms_action" value="review_payment">
                                            <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($pay['id']); ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-black shadow-xs">تایید و شروع تسک</button>
                                        </form>
                                        <button onclick="document.getElementById('reject-row-<?php echo $pay['id']; ?>').classList.toggle('hidden')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-[10px] font-black">رد</button>
                                    </div>
                                    <form id="reject-row-<?php echo $pay['id']; ?>" method="POST" class="mt-2 hidden">
                                        <input type="hidden" name="ms_action" value="review_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($pay['id']); ?>">
                                        <input type="hidden" name="decision" value="reject">
                                        <input type="text" name="rejection_reason" required placeholder="دلیل رد..." class="w-full px-2 py-1 bg-white border border-slate-200 rounded text-xs mb-1">
                                        <button type="submit" class="w-full py-1 bg-rose-600 text-white rounded text-[10px] font-black">ارسال علت رد</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400">بررسی شده</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
