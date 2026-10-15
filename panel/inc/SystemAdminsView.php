<?php
if (!function_exists('isMainAdmin') || !isMainAdmin()) { http_response_code(403); exit; }
if (empty($_SESSION['system_admin_csrf'])) $_SESSION['system_admin_csrf'] = bin2hex(random_bytes(32));
?>
<section class="p-5 my-5 bg-amber-50 rounded-xl border border-amber-200 space-y-3">
    <h3 class="font-black">حساب مدیر کل سامانه</h3>
    <a href="/panel/system-admin-setup.php" class="underline">باز کردن صفحهٔ مستقل فعال‌سازی مدیر کل</a>
    <p class="text-xs">مدیر کل بدون محدودیت به تمام پروژه‌ها و بخش‌ها، حتی بخش‌های غیرفعال، دسترسی دارد. رمز فقط به‌صورت هش روی همین هاست ذخیره می‌شود و حساب موجود بازنویسی نمی‌شود.</p>
    <div class="grid md:grid-cols-2 gap-3">
        <label class="text-xs">نام کاربری<input id="system-admin-username" value="ehsanmehrali" autocomplete="off" class="input" dir="ltr"></label>
        <label class="text-xs">رمز ورود<input id="system-admin-password" type="password" autocomplete="new-password" class="input" dir="ltr"></label>
    </div>
    <label class="text-xs"><input type="checkbox" id="system-admin-unrestricted" checked> مدیر کل بدون محدودیت</label>
    <button type="button" onclick="createSystemAdmin()" class="bg-amber-600 text-white px-4 py-2 rounded text-xs">ساخت حساب مدیر کل روی این هاست</button>
    <p id="system-admin-result" class="text-xs" aria-live="polite"></p>
</section>
<script>
async function createSystemAdmin() {
    const output=document.getElementById('system-admin-result');
    const password=document.getElementById('system-admin-password');
    try {
        const response=await fetch(<?php echo json_encode('/panel/system_admins.php'); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
            username:document.getElementById('system-admin-username').value,password:password.value,
            unrestricted:document.getElementById('system-admin-unrestricted').checked,csrf:<?php echo json_encode($_SESSION['system_admin_csrf']); ?>
        })});
        const data=await response.json(); if(!response.ok) throw new Error(data.error||'ساخت حساب ناموفق بود.');
        password.value='';output.textContent='حساب روی این هاست ساخته شد. اکنون با نام کاربری و رمز جدید وارد شوید.';
    } catch(e) {output.textContent=e.message;}
}
</script>
