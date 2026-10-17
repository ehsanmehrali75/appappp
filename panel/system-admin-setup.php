<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/SystemAdmins.php';
header('Cache-Control: no-store');
if (!isUser()) { redirect('/index.php?action=login'); exit; }

if (empty($_SESSION['system_admin_csrf'])) $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
$error=''; $success=false; $recovered=false;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['system_admin_csrf'], $_POST['csrf'])) AccessControl::deny();
    try {
        if (($_POST['action']??'')==='recover_owner') {
            if (($_POST['password']??'')!==($_POST['confirmation']??'')) throw new RuntimeException('تکرار رمز با رمز ورود یکسان نیست.');
            $verified=SystemAdmins::recoverFirstOwner($_POST['recovery_token']??'',$_POST['username']??'',$_POST['password']??'');
            session_regenerate_id(true);
            SessionIdentity::beginGlobal($verified['username']);
            $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
            $success=true; $recovered=true;
        } elseif (!isMainAdmin()) {
            $limit = $_SESSION['admin_reauth_limit'] ?? ['attempts'=>0,'until'=>0];
            if ($limit['until'] <= time()) $limit=['attempts'=>0,'until'=>time()+900];
            if ($limit['attempts'] >= 5) throw new RuntimeException('تلاش‌های تأیید هویت بیش از حد مجاز است؛ ۱۵ دقیقه بعد دوباره تلاش کنید.');
            $limit['attempts']++;
            $_SESSION['admin_reauth_limit']=$limit;
            $verified=SystemAdmins::authenticateGlobal($_POST['current_username']??'', $_POST['current_password']??'', getDB()['super_users']??[]);
            if (!$verified) throw new RuntimeException('اطلاعات حساب مدیر سراسری معتبر نیست. حساب مدیر پروژه برای این کار کافی نیست.');
            session_regenerate_id(true);
            SessionIdentity::beginGlobal($verified['username'], ($verified['role']??'')==='superadmin'?'global':'system');
            unset($_SESSION['admin_reauth_limit']);
            $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
        } else {
        if (($_POST['password']??'')!==($_POST['confirmation']??'')) throw new RuntimeException('تکرار رمز با رمز ورود یکسان نیست.');
        SystemAdmins::create($_POST['username']??'', $_POST['password']??'', true);
        $success=true;
        $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
        }
    } catch (RuntimeException $e) { $error=$e->getMessage(); }
}
$recoveryAvailable = !SystemAdmins::hasOwner();
$recoveryToken = $recoveryAvailable ? bin2hex(random_bytes(32)) : '';
$recoverySource = $recoveryAvailable ? SystemAdmins::recoveryKeySource($recoveryToken,time()+3600) : '';
function adminSetupEscape($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>فعال‌سازی مدیر کل | مارک‌شو</title><link rel="stylesheet" href="/panel/assets/neo-tokens.css?v=1"><link rel="stylesheet" href="/panel/assets/support.css?v=3"></head><body><main>
<header><div><small>مدیریت سامانه</small><h1>فعال‌سازی مدیر کل</h1></div><nav class="row"><a href="/panel/admin.php">بازگشت به پنل</a><a href="/panel/support.php">پشتیبانی</a></nav></header>
<?php if ($error): ?><p class="error" role="alert"><?=adminSetupEscape($error)?></p><?php endif; ?>
<?php if (!isMainAdmin()): ?>
<section><h2>تأیید هویت مدیر فعلی</h2><p>نشست فعلی شما نقش مدیر سراسری ندارد؛ ممکن است نشست قدیمی باشد یا با حساب پروژه وارد شده باشید. نام کاربری و رمز حساب مدیر سراسری فعلی را وارد کنید تا دسترسی دوباره بررسی شود.</p><form method="post"><input type="hidden" name="csrf" value="<?=adminSetupEscape($_SESSION['system_admin_csrf'])?>"><label>نام کاربری مدیر فعلی<input name="current_username" autocomplete="username" dir="ltr" required></label><label>رمز مدیر فعلی<input type="password" name="current_password" autocomplete="current-password" dir="ltr" required></label><button>تأیید هویت مدیر سراسری</button></form></section>
<?php if($recoveryAvailable): ?>
<section><h2>ساخت اولین مدیر کل با دسترسی هاست</h2><p>اگر حساب مدیر سراسری ندارید، مالکیت هاست را با فایل موقت تأیید کنید. این روش به رمز ادمین قبلی نیاز ندارد.</p>
<ol><li>فایل کلید زیر را دانلود کنید.</li><li>در File Manager هاست، پوشهٔ سایت را باز کنید و فایل را با همین نام در <b dir="ltr">panel/db/owner-recovery-key.php</b> قرار دهید. فایل PHP است و نباید پسوند txt داشته باشد.</li><li>بدون بارگذاری مجدد این صفحه، فرم زیر را تکمیل کنید. کلید یک ساعت اعتبار دارد.</li></ol>
<button type="button" id="download-recovery-key">دانلود فایل کلید موقت</button>
<details><summary>نمایش محتوای فایل برای ساخت دستی در File Manager</summary><pre dir="ltr" style="text-align:left"><?=adminSetupEscape($recoverySource)?></pre></details>
<form method="post"><input type="hidden" name="csrf" value="<?=adminSetupEscape($_SESSION['system_admin_csrf'])?>"><input type="hidden" name="action" value="recover_owner"><label>کلید موقت همین فایل<input name="recovery_token" value="<?=adminSetupEscape($recoveryToken)?>" autocomplete="off" dir="ltr" pattern="[a-f0-9]{64}" required></label><small>اگر صفحه عوض شده، کلید صفحهٔ قبلی را وارد کنید یا فایل جدید را روی هاست بگذارید.</small><label>نام کاربری مدیر کل<input name="username" value="ehsanmehrali" autocomplete="username" dir="ltr" minlength="3" maxlength="64" required></label><div class="grid"><label>رمز جدید مدیر کل<input type="password" name="password" minlength="10" autocomplete="new-password" dir="ltr" required></label><label>تکرار رمز جدید<input type="password" name="confirmation" minlength="10" autocomplete="new-password" dir="ltr" required></label></div><button>تأیید فایل هاست و ساخت اولین مدیر کل</button></form>
<p><small>قرار دادن فایل روی هاست مجوز ساخت مدیر کل است. پس از موفقیت، فایل حذف و این مسیر غیرفعال می‌شود. رمز ورود در فایل کلید ذخیره نمی‌شود.</small></p></section>
<script>
document.getElementById('download-recovery-key').addEventListener('click', () => {
    const source=<?=json_encode($recoverySource,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
    const url=URL.createObjectURL(new Blob([source],{type:'application/octet-stream'}));
    const link=document.createElement('a');link.href=url;link.download='owner-recovery-key.php';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
});
</script>
<?php else: ?><section><h2>مدیر کل از قبل وجود دارد</h2><p>بازیابی اولیه بسته است؛ با حساب مدیر کل وارد شوید یا از دسترسی مستقیم هاست برای مدیریت حساب استفاده کنید.</p></section><?php endif; ?>
<?php elseif ($success): ?><section role="status"><h2>حساب مدیر کل روی این هاست ساخته شد</h2><p><?php echo $recovered?'اکنون با حساب مدیر کل جدید وارد هستید. اطلاعات حساب‌های قبلی حفظ شده است.':'برای ورود با حساب جدید، ابتدا از حساب فعلی خارج شوید. اطلاعات حساب‌های قبلی حفظ شده است.'; ?></p><a class="button" href="/panel/admin.php">ورود به پنل</a> <a class="button" href="/panel/logout.php">خروج و ورود با حساب جدید</a></section><?php else: ?>
<section><h2>ساخت حساب بدون محدودیت</h2><p>این حساب به تمام پروژه‌ها و بخش‌ها، حتی بخش‌های غیرفعال دسترسی دارد. رمز فقط به صورت هش روی همین هاست ذخیره می‌شود.</p><form method="post"><input type="hidden" name="csrf" value="<?=adminSetupEscape($_SESSION['system_admin_csrf'])?>"><label>نام کاربری<input name="username" value="ehsanmehrali" pattern="[a-zA-Z0-9_.\-]{3,64}" minlength="3" maxlength="64" autocomplete="username" dir="ltr" required></label><div class="grid"><label>رمز ورود<input type="password" name="password" minlength="10" autocomplete="new-password" dir="ltr" required></label><label>تکرار رمز ورود<input type="password" name="confirmation" minlength="10" autocomplete="new-password" dir="ltr" required></label></div><button>ساخت مدیر کل روی این هاست</button></form><p><small>این صفحه فقط برای ادمین اصلی فعلی باز است. حساب موجود بازنویسی نمی‌شود.</small></p></section>
<?php endif; ?></main></body></html>
