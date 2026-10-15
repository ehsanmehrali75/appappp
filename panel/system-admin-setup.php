<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/SystemAdmins.php';
header('Cache-Control: no-store');
if (!isUser()) { redirect('/index.php?action=login'); exit; }
if (!isMainAdmin()) AccessControl::deny();
if (empty($_SESSION['system_admin_csrf'])) $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
$error=''; $success=false;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['system_admin_csrf'], $_POST['csrf'])) AccessControl::deny();
    try {
        if (($_POST['password']??'')!==($_POST['confirmation']??'')) throw new RuntimeException('تکرار رمز با رمز ورود یکسان نیست.');
        SystemAdmins::create($_POST['username']??'', $_POST['password']??'', true);
        $success=true;
        $_SESSION['system_admin_csrf']=bin2hex(random_bytes(32));
    } catch (RuntimeException $e) { $error=$e->getMessage(); }
}
function adminSetupEscape($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>فعال‌سازی مدیر کل | مارک‌شو</title><link rel="stylesheet" href="/panel/assets/support.css?v=2"></head><body><main>
<header><div><small>مدیریت سامانه</small><h1>فعال‌سازی مدیر کل</h1></div><nav class="row"><a href="/panel/admin.php">بازگشت به پنل</a><a href="/panel/support.php">پشتیبانی</a></nav></header>
<?php if ($error): ?><p class="error" role="alert"><?=adminSetupEscape($error)?></p><?php endif; ?>
<?php if ($success): ?><section role="status"><h2>حساب مدیر کل روی این هاست ساخته شد</h2><p>برای ورود با حساب جدید، ابتدا از حساب فعلی خارج شوید. اطلاعات حساب‌های قبلی حفظ شده است.</p><a class="button" href="/panel/logout.php">خروج و ورود با حساب جدید</a></section><?php else: ?>
<section><h2>ساخت حساب بدون محدودیت</h2><p>این حساب به تمام پروژه‌ها و بخش‌ها، حتی بخش‌های غیرفعال دسترسی دارد. رمز فقط به صورت هش روی همین هاست ذخیره می‌شود.</p><form method="post"><input type="hidden" name="csrf" value="<?=adminSetupEscape($_SESSION['system_admin_csrf'])?>"><label>نام کاربری<input name="username" value="ehsanmehrali" pattern="[a-zA-Z0-9_.\-]{3,64}" minlength="3" maxlength="64" autocomplete="username" dir="ltr" required></label><div class="grid"><label>رمز ورود<input type="password" name="password" minlength="10" autocomplete="new-password" dir="ltr" required></label><label>تکرار رمز ورود<input type="password" name="confirmation" minlength="10" autocomplete="new-password" dir="ltr" required></label></div><button>ساخت مدیر کل روی این هاست</button></form><p><small>این صفحه فقط برای ادمین اصلی فعلی باز است. حساب موجود بازنویسی نمی‌شود.</small></p></section>
<?php endif; ?></main></body></html>
