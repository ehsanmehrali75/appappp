<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/SupportTickets.php';
require_once __DIR__.'/inc/SupportUploads.php';
header('Cache-Control: no-store');
if(!isUser()){redirect('/index.php?action=login');exit;}
$admin=isMainAdmin();$all=getDB()['projects']??[];$projects=[];
foreach($all as $p){
    if($admin){$projects[]=$p;continue;}
    if(SessionIdentity::member($p))$projects[]=$p;
}
$projects[]=['id'=>'__support_account__','name'=>'حساب کاربری / پشتیبانی عمومی'];
$requested=$_POST['project']??($_GET['project']??null);
$defaultPid=in_array($_SESSION['project_id']??'',array_column($projects,'id'),true)?$_SESSION['project_id']:'__support_account__';
$pid=$requested??($admin?'':$defaultPid);
$actor=SessionIdentity::supportActor($pid);
if(!is_string($pid) || ($pid!=='' && !in_array($pid,array_column($projects,'id'),true)) || (!$admin && $pid===''))AccessControl::deny();
if($pid!=='' && $pid!=='__support_account__'){
    $p=getCurrentProject($pid,true);if(!$p)AccessControl::deny();
    if(!$admin && isset($p['settings']['module_visibility']['support']) && !checkModuleSettingAccess('support',['module_visibility'=>['support'=>$p['settings']['module_visibility']['support']]]))AccessControl::deny();
}
$status=FeatureFlags::getStatus('support');
if(!FeatureFlags::isUnrestrictedAdmin() && ($status==='disabled' || ($status==='maintenance' && !$admin)))AccessControl::deny();
$audienceUser=['username'=>$_SESSION['user']['username']??'','role'=>$_SESSION['user']['role']??''];
if(!$admin && AccessControl::audience('support','view',$audienceUser,$pid)===false)AccessControl::deny();
if(empty($_SESSION['support_csrf']))$_SESSION['support_csrf']=bin2hex(random_bytes(32));
function supportEscape($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function supportActorLabel($value){return preg_replace('/^acct:[a-f0-9]{40}:/','',(string)$value);}
function supportTime($value){if(!$value)return '—';return (new DateTimeImmutable('@'.(int)$value))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y/m/d H:i');}
function supportUrl($pid,$ticket=''){return '/panel/support.php?project='.rawurlencode($pid).($ticket!==''?'&ticket='.rawurlencode($ticket):'');}
$error='';$files=[];$page=max(1,min(10000,(int)($_GET['page']??1)));
try{
    $service=new SupportTickets();
    if(!$admin && SessionIdentity::canClaimLegacySupport())$service->claimLegacyAccount($_SESSION['user']['username']??'',SessionIdentity::supportActor('__support_account__'));
    $ticketId=$_GET['ticket']??'';
    if(isset($_GET['file'])){
        $t=$service->get($ticketId,$actor,$admin,$pid);$found=null;
        foreach($t['messages'] as $m)foreach($m['attachments'] as $file)if(hash_equals($file['id'],(string)$_GET['file']))$found=$file;
        if(!$found || !preg_match('/^[a-f0-9]{48}$/D',$found['id']))AccessControl::deny();
        $path=$service->directory().'/'.$found['id'].'.upload';if(!is_file($path)){http_response_code(404);exit;}
        header('X-Content-Type-Options: nosniff');header('Content-Type: '.$found['mime']);
        $disposition=strpos($found['mime'],'image/')===0?'inline':'attachment';
        header('Content-Disposition: '.$disposition."; filename*=UTF-8''".rawurlencode($found['name']));header('Content-Length: '.filesize($path));readfile($path);exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>7*1024*1024)throw new RuntimeException('حجم کل درخواست بیش از ۷ مگابایت است.');
        if(empty($_POST['csrf']) || !hash_equals($_SESSION['support_csrf'],$_POST['csrf']))AccessControl::deny();
        if(!$admin && AccessControl::audience('support','use',$audienceUser,$pid)===false)AccessControl::deny();
        $action=$_POST['action']??'';$tid=$_POST['ticket_id']??'';
        if($action!=='create')$service->get($tid,$actor,$admin,$pid);
        if(in_array($action,['create','reply'],true))$files=SupportUploads::receive($_FILES['attachments']??null,$service->directory());
        if($action==='create'){
            if($pid==='' || !in_array($pid,array_column($projects,'id'),true))throw new RuntimeException('پروژهٔ درخواست را انتخاب کنید.');
            $tid=$service->create($pid,$actor,$_POST['subject']??'',$_POST['category']??'',trim($_POST['body']??''),$files);
        }elseif($action==='reply')$service->reply($tid,$actor,$admin,$pid,trim($_POST['body']??''),$files);
        elseif($action==='quote'){
            if(!$admin)AccessControl::deny();
            $finish=0;
            if(!empty($_POST['finish_at'])){
                $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$_POST['finish_at'],new DateTimeZone('Asia/Tehran'));
                if(!$date || $date->format('Y-m-d\TH:i')!==$_POST['finish_at'])throw new RuntimeException('تاریخ اتمام معتبر نیست.');$finish=$date->getTimestamp();
            }
            $service->offer($tid,$actor,true,$_POST['amount']??'',trim($_POST['estimate']??''),$finish);
        }elseif($action==='accept' || $action==='reject'){
            if($admin)throw new RuntimeException('پذیرش یا رد هزینه فقط توسط درخواست‌کننده انجام می‌شود.');
            $service->decide($tid,$actor,$pid,$_POST['quote_version']??0,$action==='accept');
        }elseif($action==='status')$service->transition($tid,$actor,$admin,$_POST['status']??'');
        else throw new RuntimeException('عملیات نامعتبر است.');
        redirect(supportUrl($pid,$tid));exit;
    }
    $ticket=$ticketId!==''?$service->get($ticketId,$actor,$admin,$pid):null;
    $tickets=$service->list($actor,$admin,$pid,$page);
}catch(Throwable $e){
    if(isset($service))SupportUploads::remove($files,$service->directory());
    if($e instanceof SupportAccessDenied)http_response_code(403);
    $error=$e instanceof RuntimeException?$e->getMessage():'خطای داخلی پشتیبانی؛ دوباره تلاش کنید.';
    $ticket=null;$tickets=[];
    if(isset($service))try{$ticket=(!empty($_GET['ticket']))?$service->get($_GET['ticket'],$actor,$admin,$pid):null;$tickets=$service->list($actor,$admin,$pid,$page);}catch(Throwable $ignored){}
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پشتیبانی</title>
<link rel="stylesheet" href="/panel/assets/neo-tokens.css?v=1"><link rel="stylesheet" href="/panel/assets/support.css?v=3"></head><body><main>
<header><h1>🎫 پشتیبانی</h1><div class="row"><span><?php echo supportEscape(supportActorLabel($actor)); ?></span><a href="<?php echo canAccessManagement()?'/panel/admin.php':'/panel/portal.php'; ?>">بازگشت به پنل</a><a href="<?php echo supportEscape(supportUrl($pid)); ?>">فهرست درخواست‌ها</a><?php if($admin): ?><a href="/panel/system-admin-setup.php">فعال‌سازی مدیر کل</a><?php endif; ?></div></header>
<?php if($error): ?><p class="error" role="alert"><?php echo supportEscape($error); ?></p><?php endif; ?>
<form method="GET" class="row"><label>پروژه <select name="project"><option value=""><?php echo $admin?'همهٔ پروژه‌ها':'انتخاب پروژه'; ?></option><?php foreach($projects as $p): ?><option value="<?php echo supportEscape($p['id']); ?>" <?php echo $pid===$p['id']?'selected':''; ?>><?php echo supportEscape($p['name']); ?></option><?php endforeach; ?></select></label><button>نمایش</button></form>
<?php if($ticket): ?>
<section><div class="row"><h2><?php echo supportEscape($ticket['subject']); ?></h2><span class="badge"><?php echo supportEscape(SupportTickets::STATUSES[$ticket['status']]); ?></span></div>
<p><?php echo supportEscape($ticket['id']); ?> — <?php echo supportEscape(SupportTickets::CATEGORIES[$ticket['category']]??$ticket['category']); ?> — ثبت‌کننده: <?php echo supportEscape(supportActorLabel($ticket['creator'])); ?></p>
<small>ثبت: <?php echo supportTime($ticket['created_at']); ?> — آخرین تغییر: <?php echo supportTime($ticket['updated_at']); ?> (تهران)</small>
<?php if((int)$ticket['quote_version']>0): ?><article><h3>هزینه و زمان اجرا — پیشنهاد شمارهٔ <?php echo (int)$ticket['quote_version']; ?></h3>
<p><?php echo (int)$ticket['quote_amount']>0?number_format($ticket['quote_amount']).' تومان':'رایگان'; ?></p><p>زمان اجرا: <?php echo supportEscape($ticket['estimate']); ?> — تاریخ اتمام: <?php echo supportTime($ticket['finish_at']); ?></p>
<?php if($ticket['accepted_at']): ?><p>✅ کاربر هزینه را در <?php echo supportTime($ticket['accepted_at']); ?> پذیرفته است.</p><?php endif; ?>
<?php if($ticket['status']==='waiting_approval'): ?><p>مهلت پذیرش: <b><?php echo supportTime($ticket['quote_expires_at']); ?></b> — <?php echo max(0,(int)ceil(($ticket['quote_expires_at']-time())/3600)); ?> ساعت باقی مانده. پس از پایان ۴۸ ساعت درخواست لغو می‌شود.</p>
<?php if(!$admin): ?><form method="POST"><input type="hidden" name="csrf" value="<?php echo supportEscape($_SESSION['support_csrf']); ?>"><input type="hidden" name="project" value="<?php echo supportEscape($pid); ?>"><input type="hidden" name="ticket_id" value="<?php echo supportEscape($ticket['id']); ?>"><input type="hidden" name="quote_version" value="<?php echo (int)$ticket['quote_version']; ?>"><button name="action" value="accept">هزینه را قبول دارم</button> <button class="danger" name="action" value="reject">رد هزینه و لغو تیکت</button></form><?php endif; ?>
<?php endif; ?></article><?php endif; ?>
<?php if($ticket['status']==='cancelled'): ?><p class="error"><?php echo ['approval_timeout'=>'درخواست به علت پایان مهلت ۴۸ ساعتهٔ پذیرش هزینه لغو شد.','cost_rejected'=>'درخواست با رد هزینه توسط کاربر لغو شد.','admin_cancelled'=>'درخواست توسط ادمین اصلی لغو شد.'][$ticket['cancel_reason']]??'درخواست لغو شده است.'; ?></p><?php endif; ?>
<h3>گفت‌وگو و پیوست‌ها</h3>
<?php foreach($ticket['messages'] as $m): ?><div class="message <?php echo $m['sender_type']==='admin'?'admin':''; ?>"><b><?php echo $m['sender_type']==='admin'?'پشتیبانی — ':'';echo supportEscape(supportActorLabel($m['sender'])); ?></b> <small><?php echo supportTime($m['created_at']); ?></small><pre><?php echo supportEscape($m['body']); ?></pre>
<?php foreach($m['attachments'] as $f): ?><a class="attachment" href="<?php echo supportEscape(supportUrl($pid,$ticket['id']).'&file='.rawurlencode($f['id'])); ?>" target="_blank" rel="noopener"><?php echo supportEscape($f['name']); ?> (<?php echo number_format($f['size']/1024); ?> KB)</a><?php endforeach; ?></div><?php endforeach; ?>
<?php if(!in_array($ticket['status'],['cancelled','resolved'],true)): ?><form method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo supportEscape($_SESSION['support_csrf']); ?>"><input type="hidden" name="project" value="<?php echo supportEscape($pid); ?>"><input type="hidden" name="ticket_id" value="<?php echo supportEscape($ticket['id']); ?>"><input type="hidden" name="action" value="reply"><label>پاسخ یا توضیحات<textarea name="body" maxlength="20000"></textarea></label><label>تصویر یا لاگ<input type="file" name="attachments[]" multiple accept=".png,.jpg,.jpeg,.webp,.txt,.log,.json"></label><button>ارسال پاسخ</button></form><?php endif; ?>
</section>
<?php if($admin && !in_array($ticket['status'],['cancelled','resolved'],true)): ?><div class="grid"><?php if(in_array($ticket['status'],['pending_review','answered','waiting_approval'],true) && !(int)$ticket['accepted_at']): ?><section><h3>تعیین هزینه و زمان‌بندی</h3><form method="POST"><input type="hidden" name="csrf" value="<?php echo supportEscape($_SESSION['support_csrf']); ?>"><input type="hidden" name="project" value="<?php echo supportEscape($pid); ?>"><input type="hidden" name="ticket_id" value="<?php echo supportEscape($ticket['id']); ?>"><input type="hidden" name="action" value="quote"><label>مبلغ به تومان؛ صفر = رایگان<input type="number" min="0" step="1" name="amount" value="<?php echo (int)$ticket['quote_amount']; ?>" required></label><label>زمان تخمینی اجرا<input name="estimate" maxlength="500" value="<?php echo supportEscape($ticket['estimate']); ?>" placeholder="مثلاً ۳ روز کاری"></label><label>تاریخ و ساعت اتمام به وقت تهران<input type="datetime-local" name="finish_at"></label><button>ارسال پیشنهاد هزینه و زمان</button><p><small>برای هزینهٔ مثبت، مهلت پذیرش از لحظهٔ ارسال ۴۸ ساعت است. هزینهٔ تأییدشده قابل تغییر نیست.</small></p></form></section>
<?php endif; ?><section><h3>وضعیت اجرا</h3><form method="POST"><input type="hidden" name="csrf" value="<?php echo supportEscape($_SESSION['support_csrf']); ?>"><input type="hidden" name="project" value="<?php echo supportEscape($pid); ?>"><input type="hidden" name="ticket_id" value="<?php echo supportEscape($ticket['id']); ?>"><input type="hidden" name="action" value="status"><select name="status"><?php foreach(SupportTickets::allowedTransitions($ticket['status']) as $key): $label=SupportTickets::STATUSES[$key]; ?><option value="<?php echo $key; ?>"><?php echo supportEscape($label); ?></option><?php endforeach; ?></select><button>ثبت وضعیت</button><p><small>پیش از پذیرش هزینه، ورود درخواست هزینه‌دار به صف یا اجرای آن مجاز نیست.</small></p></form></section></div><?php endif; ?>
<?php else: ?>
<section><h2>درخواست پشتیبانی جدید</h2><form method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo supportEscape($_SESSION['support_csrf']); ?>"><input type="hidden" name="project" value="<?php echo supportEscape($pid); ?>"><input type="hidden" name="action" value="create"><label>موضوع<input name="subject" maxlength="200" required></label><label>دسته‌بندی<select name="category"><?php foreach(SupportTickets::CATEGORIES as $key=>$label): ?><option value="<?php echo $key; ?>"><?php echo supportEscape($label); ?></option><?php endforeach; ?></select></label><label>متن، توضیحات و لاگ خطا<textarea name="body" maxlength="20000" required></textarea></label><label>پیوست تصویر یا فایل لاگ<input type="file" name="attachments[]" multiple accept=".png,.jpg,.jpeg,.webp,.txt,.log,.json"></label><small>حداکثر ۳ پیوست؛ هر فایل حداکثر ۳ و مجموع حداکثر ۶ مگابایت. ابتدا پروژه را انتخاب کنید.</small><p><button>ثبت درخواست — در انتظار بررسی</button></p></form></section>
<section><h2><?php echo $admin?'درخواست‌های کاربران':'درخواست‌های من'; ?></h2><small>صفحهٔ <?php echo $page; ?> — حداکثر ۵۰ درخواست در هر صفحه</small><div class="scroll"><table><thead><tr><th>کد و موضوع</th><th>پروژه</th><th>ثبت‌کننده</th><th>وضعیت</th><th>آخرین تغییر</th></tr></thead><tbody><?php foreach($tickets as $t): ?><tr><td data-label="کد و موضوع"><a href="<?php echo supportEscape(supportUrl($pid,$t['id'])); ?>"><?php echo supportEscape($t['subject']); ?></a><br><small><?php echo supportEscape($t['id']); ?></small></td><td data-label="پروژه"><?php echo supportEscape(array_column($projects,'name','id')[$t['project_id']]??$t['project_id']); ?></td><td data-label="ثبت‌کننده"><?php echo supportEscape(supportActorLabel($t['creator'])); ?></td><td data-label="وضعیت"><?php echo supportEscape(SupportTickets::STATUSES[$t['status']]); ?></td><td data-label="آخرین تغییر"><?php echo supportTime($t['updated_at']); ?></td></tr><?php endforeach; ?><?php if(!$tickets): ?><tr><td colspan="5" class="empty">هنوز درخواستی برای نمایش وجود ندارد.</td></tr><?php endif; ?></tbody></table></div><div class="row"><?php if($page>1): ?><a href="<?php echo supportEscape(supportUrl($pid).'&page='.($page-1)); ?>">صفحهٔ قبل</a><?php endif; ?><?php if(count($tickets)===50): ?><a href="<?php echo supportEscape(supportUrl($pid).'&page='.($page+1)); ?>">صفحهٔ بعد</a><?php endif; ?></div></section>
<?php endif; ?></main></body></html>
