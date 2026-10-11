<?php
/**
 * Customer / Portal Ticketing & Financial Views
 * Included into portal.php when tab=tickets
 */

if (!class_exists('MarkShowCore')) {
    require_once __DIR__ . '/MarkShowCore.php';
}

$pId = $project['id'] ?? '';
$customerMobile = $_SESSION['portal_user'] ?? ($_SESSION['user']['mobile'] ?? '');
$customerUsername = $_SESSION['user']['username'] ?? '';
$customerName = $_SESSION['user']['display_name'] ?? ($_SESSION['display_name'] ?? 'کارفرما');

// Find customer record if exists
$matchedCustomer = null;
if (!empty($customerMobile)) {
    $matchedCustomer = MarkShowCore::getCustomerByMobile($customerMobile);
}
$customerId = $matchedCustomer['id'] ?? null;

$msg = null;
$msgType = 'success';

// Handle Customer Post Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_action'])) {
    $pAction = $_POST['portal_action'];

    // 1. Customer Create Ticket
    if ($pAction === 'create_ticket') {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $serviceType = $_POST['service_type'] ?? 'free';
        $priority = $_POST['priority'] ?? 'medium';

        if (empty($title) || empty($desc)) {
            $msg = 'لطفاً عنوان و متن درخواست را وارد فرمایید.';
            $msgType = 'error';
        } else {
            $ticketData = [
                'title' => $title,
                'description' => $desc,
                'project_id' => $pId,
                'customer_id' => $customerId,
                'creator_username' => $customerUsername ?: ($customerMobile ?: 'portal_user'),
                'creator_name' => $customerName,
                'creator_type' => 'customer',
                'service_type' => $serviceType,
                'priority' => $priority
            ];
            $tRes = MarkShowCore::createTicket($ticketData);
            if ($tRes['success']) {
                $msg = "درخواست شما با کد پیگیری {$tRes['tracking_code']} با موفقیت ثبت شد و به تیم پشتیبانی ارجاع گردید.";
            } else {
                $msg = $tRes['error'] ?? 'خطا در ثبت درخواست.';
                $msgType = 'error';
            }
        }
    }

    // 2. Customer Reply Ticket
    if ($pAction === 'reply_ticket') {
        $tid = $_POST['ticket_id'] ?? '';
        $text = trim($_POST['message'] ?? '');
        if (!empty($text)) {
            MarkShowCore::addTicketMessage($tid, [
                'sender_username' => $customerUsername ?: ($customerMobile ?: 'customer'),
                'sender_name' => $customerName,
                'sender_type' => 'customer',
                'message' => $text,
                'is_internal' => 0
            ]);
            $msg = 'پیام شما برای تیم پشتیبانی ارسال گردید.';
        }
    }

    // 3. Customer Upload Payment Receipt
    if ($pAction === 'upload_payment_receipt') {
        $tid = $_POST['ticket_id'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $pref = trim($_POST['payment_ref'] ?? '');
        $receiptFile = '';

        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (in_array($ext, $allowed)) {
                $newName = 'receipt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (!is_dir(UPLOADS_DIR . 'receipts')) {
                    mkdir(UPLOADS_DIR . 'receipts', 0777, true);
                }
                $dest = UPLOADS_DIR . 'receipts/' . $newName;
                if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $dest)) {
                    $receiptFile = 'uploads/receipts/' . $newName;
                }
            }
        }

        if (empty($receiptFile) && empty($pref)) {
            $msg = 'لطفاً تصویر فیش یا شماره پیگیری تراکنش را وارد فرمایید.';
            $msgType = 'error';
        } else {
            $payRes = MarkShowCore::submitPaymentReceipt($tid, [
                'amount' => $amount,
                'receipt_file' => $receiptFile,
                'payment_ref' => $pref,
                'payment_date' => date('Y/m/d H:i')
            ]);
            if ($payRes['success']) {
                $msg = 'رسید پرداخت شما با موفقیت ثبت شد و برای تایید بخش مالی ارسال گردید.';
            } else {
                $msg = $payRes['error'] ?? 'خطا در ثبت رسید پرداخت.';
                $msgType = 'error';
            }
        }
    }
}

// Fetch Tickets for this customer / project
$selectedTicketId = $_GET['ticket_id'] ?? '';
$selectedTicket = null;
if (!empty($selectedTicketId)) {
    $selectedTicket = MarkShowCore::getTicketById($selectedTicketId);
}

$myTickets = MarkShowCore::getTickets([
    'project_id' => $pId,
    'customer_id' => $customerId
]);
?>

<div class="space-y-6">
    <?php if ($msg): ?>
        <div class="p-4 rounded-2xl flex items-center justify-between text-xs font-bold shadow-sm animate-in fade-in <?php echo $msgType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'; ?>">
            <div class="flex items-center gap-2">
                <span><?php echo $msgType === 'success' ? '✅' : '⚠️'; ?></span>
                <span><?php echo htmlspecialchars($msg); ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($selectedTicket): ?>
        <!-- Customer Ticket Conversation & Payment View -->
        <div class="flex items-center justify-between mb-4">
            <a href="portal.php?project=<?php echo urlencode($pId); ?>&tab=tickets" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-black text-slate-600 hover:text-slate-900 shadow-xs">
                <span>←</span> <span>بازگشت به لیست درخواست‌ها</span>
            </a>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-400 font-bold">کد پیگیری:</span>
                <span class="font-mono text-sm font-black text-indigo-700 bg-indigo-50 px-3 py-1 rounded-xl border border-indigo-100"><?php echo htmlspecialchars($selectedTicket['tracking_code']); ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Thread -->
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <div class="flex items-start justify-between gap-4 mb-2">
                        <h2 class="text-lg font-black text-slate-900"><?php echo htmlspecialchars($selectedTicket['title']); ?></h2>
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
                                    default: echo '📝 در انتظار بررسی تیم پشتیبانی'; break;
                                }
                            ?>
                        </span>
                    </div>
                    <div class="text-[11px] text-slate-400 font-bold">
                        ثبت شده در: <?php echo date('Y/m/d H:i', $selectedTicket['created_at']); ?>
                    </div>
                </div>

                <!-- Messages -->
                <div class="space-y-4">
                    <?php foreach ($selectedTicket['messages'] as $m): ?>
                        <?php if (!empty($m['is_internal'])) continue; // Hide internal notes from customer ?>
                        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="w-7 h-7 rounded-xl <?php echo $m['sender_type'] === 'admin' ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-700'; ?> flex items-center justify-center text-xs font-black">
                                    <?php echo $m['sender_type'] === 'admin' ? '🛡️' : '👤'; ?>
                                </span>
                                <div>
                                    <div class="text-xs font-black text-slate-900"><?php echo htmlspecialchars($m['sender_name']); ?></div>
                                    <div class="text-[9px] text-slate-400"><?php echo date('Y/m/d H:i', $m['created_at']); ?></div>
                                </div>
                            </div>
                            <div class="text-xs font-bold text-slate-700 leading-relaxed whitespace-pre-wrap">
                                <?php echo htmlspecialchars($m['message']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Reply Form -->
                <?php if ($selectedTicket['status'] !== 'closed'): ?>
                    <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                        <h3 class="text-xs font-black text-slate-900 mb-3">ارسال پاسخ / پیام جدید</h3>
                        <form method="POST">
                            <input type="hidden" name="portal_action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                            <textarea name="message" rows="3" required placeholder="پیام خود را بنویسید..." class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold outline-none focus:border-rose-500 mb-3"></textarea>
                            <div class="flex justify-end">
                                <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-black shadow-md shadow-rose-600/20">ارسال پیام</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Sidebar: Financial Quotation & Receipt Upload -->
            <div class="space-y-4">
                <!-- Quotation & Terms -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                    <h3 class="text-xs font-black text-slate-900 mb-3 flex items-center justify-between">
                        <span>💰 پیش‌فاکتور و شرایط خدمت</span>
                        <span class="text-[10px] px-2 py-0.5 rounded <?php echo $selectedTicket['service_type'] === 'paid' ? 'bg-rose-50 text-rose-600 font-black' : 'bg-slate-100 text-slate-500 font-bold'; ?>">
                            <?php echo $selectedTicket['service_type'] === 'paid' ? 'خدمت هزینه‌دار' : 'رایگان'; ?>
                        </span>
                    </h3>

                    <?php if ($selectedTicket['service_type'] === 'paid' && $selectedTicket['price'] > 0): ?>
                        <div class="space-y-3 text-xs font-bold text-slate-700">
                            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                <div class="text-[10px] text-slate-400 font-bold mb-1">مبلغ قابل پرداخت:</div>
                                <div class="text-2xl font-black text-rose-600">
                                    <?php echo number_format($selectedTicket['price']); ?> <span class="text-xs font-bold text-slate-500">تومان</span>
                                </div>
                            </div>

                            <?php if (!empty($selectedTicket['cost_description'])): ?>
                                <div>
                                    <div class="text-[10px] text-slate-400 mb-1">شرح خدمات برآورد شده:</div>
                                    <div class="p-3 bg-slate-50 rounded-xl text-slate-700 text-[11px] leading-relaxed">
                                        <?php echo nl2br(htmlspecialchars($selectedTicket['cost_description'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($selectedTicket['execution_days'] > 0): ?>
                                <div class="flex items-center justify-between text-[11px] p-2 bg-slate-50 rounded-xl">
                                    <span class="text-slate-500">مدت زمان اجرا:</span>
                                    <span class="font-black text-slate-900"><?php echo (int)$selectedTicket['execution_days']; ?> روز کاری</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($selectedTicket['deadline'])): ?>
                                <div class="flex items-center justify-between text-[11px] p-2 bg-slate-50 rounded-xl">
                                    <span class="text-slate-500">موعد تحویل:</span>
                                    <span class="font-black text-slate-900"><?php echo htmlspecialchars($selectedTicket['deadline']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-100 text-xs font-bold text-emerald-800">
                            این درخواست به عنوان پشتیبانی معمول و رایگان برای پروژه شما لحاظ گردیده است.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Receipt Upload Form -->
                <?php if ($selectedTicket['service_type'] === 'paid' && $selectedTicket['price'] > 0): ?>
                    <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
                        <h4 class="text-xs font-black text-slate-900 mb-2">ثبت فیش و رسید بانکی</h4>
                        <p class="text-[10px] text-slate-400 font-bold mb-4">لطفاً پس از واریز به حساب شرکت، تصویر فیش و شماره پیگیری را جهت شروع سریع تسک اجرایی بارگذاری نمایید.</p>
                        
                        <form method="POST" enctype="multipart/form-data" class="space-y-3 text-xs font-bold text-slate-700">
                            <input type="hidden" name="portal_action" value="upload_payment_receipt">
                            <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($selectedTicket['id']); ?>">
                            
                            <div>
                                <label class="block mb-1 text-slate-500">مبلغ واریزی (تومان)</label>
                                <input type="number" name="amount" value="<?php echo (float)$selectedTicket['price']; ?>" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-left outline-none focus:border-rose-500">
                            </div>

                            <div>
                                <label class="block mb-1 text-slate-500">شماره ارجاع / پیگیری تراکنش</label>
                                <input type="text" name="payment_ref" placeholder="مثلا: 92837461" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-left outline-none focus:border-rose-500">
                            </div>

                            <div>
                                <label class="block mb-1 text-slate-500">تصویر فیش یا رسید</label>
                                <input type="file" name="receipt_file" accept="image/*,application/pdf" class="w-full text-xs text-slate-500 file:mr-2 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                            </div>

                            <button type="submit" class="w-full py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">
                                ارسال رسید جهت تایید
                            </button>
                        </form>

                        <!-- Previous Receipts -->
                        <?php if (!empty($selectedTicket['payments'])): ?>
                            <div class="mt-4 pt-4 border-t border-slate-100 space-y-2">
                                <div class="text-[10px] font-black text-slate-400">وضعیت رسیدهای ارسالی شما:</div>
                                <?php foreach ($selectedTicket['payments'] as $pItem): ?>
                                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 flex items-center justify-between text-xs">
                                        <div>
                                            <div class="font-black text-slate-800"><?php echo number_format($pItem['amount']); ?> تومان</div>
                                            <div class="text-[9px] text-slate-400 font-mono"><?php echo htmlspecialchars($pItem['payment_ref'] ?: '---'); ?></div>
                                        </div>
                                        <span class="px-2 py-0.5 rounded text-[9px] font-black <?php echo $pItem['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800' : ($pItem['status'] === 'rejected' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'); ?>">
                                            <?php echo $pItem['status'] === 'approved' ? '✅ تایید شده' : ($pItem['status'] === 'rejected' ? '❌ رد شده' : '⏳ در انتظار تایید'); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Tickets List for Customer -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-xl font-black text-slate-900">میز خدمت و تیکت‌های پشتیبانی</h2>
                <p class="text-xs text-slate-500 font-bold mt-1">ارسال مستقیم نیازمندی‌ها، درخواست خدمات جدید و پیگیری تا تحویل نهایی</p>
            </div>
            <button onclick="document.getElementById('portal-ticket-modal').classList.remove('hidden')" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl font-black text-xs shadow-md shadow-rose-600/20 flex items-center gap-2">
                <span>➕</span> <span>ثبت درخواست / تیکت جدید</span>
            </button>
        </div>

        <!-- Tickets Cards -->
        <?php if (empty($myTickets)): ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200/80 shadow-sm">
                <div class="w-16 h-16 rounded-3xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-3xl mx-auto mb-4">🎫</div>
                <h3 class="text-base font-black text-slate-800 mb-2">هنوز درخواستی ثبت نشده است</h3>
                <p class="text-xs text-slate-500 font-bold max-w-md mx-auto mb-6">در صورت نیاز به هرگونه خدمات طراحی، محتوا، تغییرات یا پشتیبانی می‌توانید با زدن دکمه زیر درخواست خود را ثبت کنید.</p>
                <button onclick="document.getElementById('portal-ticket-modal').classList.remove('hidden')" class="px-6 py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl font-black text-xs shadow-md shadow-rose-600/20">
                    ثبت اولین تیکت
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($myTickets as $t): ?>
                    <a href="portal.php?project=<?php echo urlencode($pId); ?>&tab=tickets&ticket_id=<?php echo urlencode($t['id']); ?>" class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm hover:border-rose-300 hover:shadow-md transition-all flex flex-col justify-between block group">
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <span class="font-mono text-[11px] font-black text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-lg border border-indigo-100">
                                    <?php echo htmlspecialchars($t['tracking_code']); ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black <?php 
                                    if ($t['status'] === 'waiting_payment') echo 'bg-rose-50 text-rose-600';
                                    elseif ($t['status'] === 'in_progress') echo 'bg-amber-50 text-amber-700';
                                    elseif ($t['status'] === 'completed') echo 'bg-emerald-50 text-emerald-700';
                                    else echo 'bg-indigo-50 text-indigo-700';
                                ?>">
                                    <?php 
                                        switch($t['status']) {
                                            case 'waiting_payment': echo 'در انتظار پرداخت'; break;
                                            case 'in_progress': echo 'در حال اجرا'; break;
                                            case 'completed': echo 'تکمیل شده'; break;
                                            case 'closed': echo 'بسته شده'; break;
                                            default: echo 'در حال بررسی'; break;
                                        }
                                    ?>
                                </span>
                            </div>

                            <h3 class="font-black text-slate-900 text-sm group-hover:text-rose-600 transition-colors mb-2">
                                <?php echo htmlspecialchars($t['title']); ?>
                            </h3>

                            <?php if ($t['service_type'] === 'paid' && $t['price'] > 0): ?>
                                <div class="text-xs font-black text-rose-600 mb-3">
                                    مبلغ: <?php echo number_format($t['price']); ?> تومان
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400 font-bold">
                            <span><?php echo date('Y/m/d', $t['created_at']); ?></span>
                            <span class="text-rose-600 font-black">مشاهده و گفتگو ←</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Create Ticket Modal -->
        <div id="portal-ticket-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
            <div class="bg-white rounded-3xl max-w-lg w-full p-8 shadow-2xl">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                    <h3 class="font-black text-lg text-slate-900">ثبت درخواست جدید (تیکت)</h3>
                    <button onclick="document.getElementById('portal-ticket-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="portal_action" value="create_ticket">
                    <div class="space-y-4 text-xs font-bold text-slate-700">
                        <div>
                            <label class="block mb-1 text-slate-500">موضوع یا عنوان درخواست</label>
                            <input type="text" name="title" required placeholder="مثلاً: درخواست عکاسی محصولات جدید یا بازطراحی بنر" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
                        </div>
                        <div>
                            <label class="block mb-1 text-slate-500">توضیح کامل نیازمندی</label>
                            <textarea name="description" rows="4" required placeholder="شرح دقیق خدمات و نکاتی که تیم باید رعایت نماید..." class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-slate-500">نوع درخواست</label>
                                <select name="service_type" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                    <option value="free">پشتیبانی متداول (رایگان)</option>
                                    <option value="paid">خدمت تخصصی / سفارش جدید (با پیش‌فاکتور)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-1 text-slate-500">میزان فوریت</label>
                                <select name="priority" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                    <option value="medium">متوسط</option>
                                    <option value="high">بالا</option>
                                    <option value="urgent">فوری / اضطراری</option>
                                    <option value="low">پایین</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 mt-8 pt-4 border-t border-slate-100">
                        <button type="button" onclick="document.getElementById('portal-ticket-modal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-slate-500 hover:bg-slate-100 font-bold text-xs">انصراف</button>
                        <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-black text-xs shadow-md shadow-rose-600/20">ثبت و ارسال به تیم پشتیبانی</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
