<?php
if (!isset($project) || !function_exists('hasPermission') || !hasPermission('settings')) { http_response_code(403); exit; }
require_once __DIR__.'/TiamInventory.php';
if (empty($_SESSION['tiam_csrf'])) $_SESSION['tiam_csrf'] = bin2hex(random_bytes(32));
$inventoryCfg = $project['settings']['tiam_inventory'] ?? [];
?>
<style>#tiam-inventory-panel [hidden]{display:none!important}</style>
<section id="tiam-inventory-panel" class="p-5 my-6 rounded-2xl border border-indigo-200 bg-indigo-50 space-y-4">
    <h3 class="font-black text-indigo-900">دریافت موجودی تیام از SQL Server — اتصال مخصوص این پروژه</h3>
    <p class="text-xs leading-6">مناسب هاست اشتراکی: ایجنت روی کامپیوتر شما از شبکهٔ فعلی یا VPNای که خودتان وصل کرده‌اید، ویو را می‌خواند. ساخت VPN توسط ایجنت اختیاری است. سایت نیازی به نصب VPN ندارد. ابتدا تست ویو را بزنید، نام ستون‌ها را نگاشت کنید و پیش‌نمایش را تأیید کنید؛ سپس به‌روزرسانی روزانه فعال می‌شود.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <?php foreach (['host'=>'آدرس SQL Server (مثال 192.168.2.215)', 'port'=>'پورت SQL (معمولاً 1433)', 'database'=>'دیتابیس (مثال SplandDB)', 'schema'=>'Schema (معمولاً dbo)', 'view'=>'نام ویو (مثال Factinventory)', 'username'=>'کاربر SQL (مثال ReportUser)', 'password'=>'رمز SQL — خالی: حفظ مقدار قبلی', 'certificate_hostname'=>'نام سرور مطابق گواهی TLS (اختیاری)', 'vpn_server'=>'سرور VPN', 'vpn_username'=>'نام کاربری VPN', 'vpn_password'=>'رمز VPN — خالی: حفظ مقدار قبلی', 'vpn_secret'=>'Secret / PSK — خالی: حفظ مقدار قبلی', 'vpn_service'=>'نام سرویس VPN در لینوکس (در ویندوز لازم نیست)', 'sync_hour'=>'ساعت روزانه به وقت تهران', 'agent_token'=>'کلید ایجنت (حداقل ۳۲ کاراکتر؛ خالی: حفظ کلید قبلی)'] as $field=>$label):
        $secret = in_array($field, ['password','vpn_password','vpn_secret','agent_token'], true);
        $value = $secret ? '' : ($inventoryCfg[$field] ?? (['port'=>1433,'schema'=>'dbo','sync_hour'=>'12:00'][$field] ?? ''));
    ?>
        <label class="text-xs font-bold"><?php echo htmlspecialchars($label); ?>
            <input data-tiam-field="<?php echo $field; ?>" type="<?php echo $secret?'password':($field==='sync_hour'?'time':'text'); ?>" value="<?php echo htmlspecialchars((string)$value); ?>" autocomplete="off" class="input text-xs mt-1" dir="ltr">
        </label>
    <?php endforeach; ?>
    </div>
    <label class="text-xs font-bold">امنیت اتصال SQL
        <select data-tiam-field="sql_security" class="input text-xs mt-1">
            <option value="verified" <?php echo ($inventoryCfg['sql_security']??'verified')==='verified'?'selected':''; ?>>اتصال رمزگذاری‌شده با بررسی گواهی</option>
            <option value="compatibility" <?php echo ($inventoryCfg['sql_security']??'verified')==='compatibility'?'selected':''; ?>>بدون الزام رمزگذاری و بدون بررسی گواهی — شبکهٔ مورد اعتماد</option>
        </select>
    </label>
    <p class="text-xs">حالت سازگاری، رمزگذاری SQL را الزامی نمی‌کند و گواهی سرور را بررسی نمی‌کند. اگر سرور رمزگذاری را اجباری کند، اتصال رمزگذاری می‌شود.</p>
    <label class="text-xs font-bold">روش دسترسی به SQL Server
        <select data-tiam-field="vpn_enabled" id="tiam-network-mode" class="input text-xs mt-1" onchange="tiamUpdateNetworkMode()">
            <option value="0" <?php echo empty($inventoryCfg['vpn_enabled'])?'selected':''; ?>>استفاده از شبکه / VPN فعلی سیستم — بدون تغییر اتصال</option>
            <option value="1" <?php echo !empty($inventoryCfg['vpn_enabled'])?'selected':''; ?>>ساخت و اتصال VPN توسط ایجنت (اختیاری)</option>
        </select>
    </label>
    <p class="text-xs">اگر داخل شبکهٔ شرکت هستید، یا VPN را خودتان روی ویندوز متصل کرده‌اید، گزینهٔ اول را انتخاب و تنظیمات را ذخیره کنید. ایجنت VPN را نمی‌سازد، قطع یا وصل نمی‌کند؛ فقط مسیر SQL و سپس دسترسی به ویو را بررسی می‌کند. مشخصات اتصال SQL همچنان لازم است.</p>
    <button type="button" onclick="tiamGenerateKey()" class="text-xs border rounded px-3 py-2 bg-white">ساخت کلید جدید و کپی برای ایجنت</button>
    <div class="flex flex-wrap gap-4 text-xs">
        <?php foreach (['enabled'=>'همگام‌سازی روزانه پس از تأیید اولیه', 'zero_missing'=>'صفرکردن موجودی کالاهای تیامِ غایب از اسنپ‌شات کامل'] as $field=>$label): ?>
            <label><input type="checkbox" data-tiam-field="<?php echo $field; ?>" <?php echo !empty($inventoryCfg[$field])?'checked':''; ?>> <?php echo $label; ?></label>
        <?php endforeach; ?>
    </div>
    <label class="text-xs">واحد قیمت در ویو
        <select data-tiam-field="price_unit" class="input text-xs">
            <option value="toman" <?php echo ($inventoryCfg['price_unit'] ?? 'toman') === 'toman'?'selected':''; ?>>تومان</option>
            <option value="rial" <?php echo ($inventoryCfg['price_unit'] ?? '') === 'rial'?'selected':''; ?>>ریال (تبدیل به تومان)</option>
        </select>
    </label>
    <h4 class="font-bold text-sm">نگاشت ستون‌های ویو به ساختار محصولات</h4>
    <button type="button" id="tiam-factinventory-preset" onclick="tiamFactInventoryPreset()" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs">جایگزینی نگاشت با الگوی FactInventory</button>
    <button type="button" onclick="tiamFactInventoryPreset(true)" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs">الگوی کامل ERP پس از تکمیل گزارش سایز و رنگ</button>
    <p class="text-xs">الگوی کامل شامل ModelCode، Size، Color، ColorCode، NetItem، AnbarCode، AnbarName و CategoryPath است. تا اضافه‌شدن این ستون‌ها، از الگوی فعلی استفاده کنید.</p>
    <p class="text-xs">الگو بر اساس ساختار بررسی‌شده: کد ProCode، نام ProDes، موجودی StockTedad و شعبه ShopCode. هویت محصول بر اساس ProCode است. سایز، رنگ و قیمت خالی می‌مانند؛ Grade با نام خودش در اطلاعات منبع حفظ می‌شود. الگو فقط این فرم را تغییر می‌دهد؛ سپس ذخیره کنید و پیش‌نمایش بگیرید.</p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php foreach (['sku'=>'کد / بارکد کالا *', 'name'=>'نام کالا *', 'stock'=>'موجودی *', 'size'=>'سایز', 'color'=>'رنگ', 'warehouse'=>'انبار / شعبه', 'price'=>'قیمت (واحد فعلی سیستم)', 'model'=>'شناسهٔ پایدار محصول / مدل مادر', 'category'=>'دسته‌بندی منبع', 'subcategory'=>'زیردسته‌بندی منبع', 'product_type'=>'نوع محصول منبع', 'grade'=>'Grade (بدون فرض سایز)', 'warehouse_name'=>'نام شعبه', 'source_updated_at'=>'زمان به‌روزرسانی منبع', 'color_code'=>'کد رنگ', 'category_path'=>'مسیر دسته‌بندی سازگار با ERP'] as $field=>$label): ?>
        <label class="text-xs"><?php echo $label; ?><input data-tiam-map="<?php echo $field; ?>" value="<?php echo htmlspecialchars($inventoryCfg['mapping'][$field] ?? ''); ?>" class="input text-xs mt-1" dir="ltr"></label>
    <?php endforeach; ?>
    </div>
    <p class="text-xs">کلید ایجنت را فقط روی سیستم اجرای ایجنت وارد کنید. رمزها در خروجی این فرم نمایش داده نمی‌شوند. تغییر اتصال یا نگاشت نیازمند تأیید دوبارهٔ پیش‌نمایش است. ردیف تکراری یا دادهٔ ناقص اعمال نمی‌شود.</p>
    <div class="flex flex-wrap gap-2">
        <?php foreach (['save'=>'ذخیرهٔ تنظیمات', 'vpn_test'=>'تست مسیر شبکه تا SQL', 'view_test'=>'تست اتصال SQL و نمایش ستون‌ها', 'preview'=>'دریافت و پیش‌نمایش تغییرات', 'latest'=>'آخرین وضعیت و لاگ ایجنت'] as $action=>$label): ?>
            <button type="button" onclick="tiamInventoryAction('<?php echo $action; ?>')" class="bg-indigo-600 text-white px-3 py-2 rounded-lg text-xs"><?php echo $label; ?></button>
        <?php endforeach; ?>
        <button type="button" onclick="tiamPairAgent(this)" class="bg-slate-700 text-white px-3 py-2 rounded-lg text-xs">ساخت کلید جدید و کپی اتصال ایجنت</button>
        <a href="https://github.com/ehsanmehrali75/appappp/releases?q=tiam-agent" target="_blank" rel="noopener" class="px-3 py-2 rounded-lg bg-white text-xs">نسخهٔ ویندوز EXE</a>
        <a href="<?php echo htmlspecialchars('/panel/tiam_inventory_agent.py'); ?>" download class="px-3 py-2 rounded-lg bg-white text-xs">دانلود ایجنت SQL</a>
    </div>
    <p class="text-xs"><a href="/panel/tiam_sql_diagnostic.ps1" download class="underline">دانلود ابزار تست مستقیم SQL در ویندوز</a> — بدون نیاز به ایجنت یا ساخت VPN؛ خروجی ساختار ستون‌ها برای بررسی و اصلاح نگاشت. نمونهٔ ردیف‌ها فقط با انتخاب صریح شما دریافت می‌شود.</p>
    <p class="text-xs">آخرین روز موفق: <?php echo htmlspecialchars($inventoryCfg['last_success'] ?? 'هنوز اجرا نشده'); ?> — ساعت پیش‌فرض ۱۲:۰۰ تهران. هنگام قطع ایجنت، کار به‌صورت خودکار انجام نمی‌شود.</p>
    <button type="button" onclick="tiamCopyLog()" id="tiam-copy-log" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs">کپی لاگ</button>
    <span id="tiam-copy-result" class="text-xs" role="status" aria-live="polite"></span>
    <textarea id="tiam-copy-fallback" readonly hidden class="input text-xs" dir="rtl" aria-label="لاگ برای کپی دستی"></textarea>
    <pre id="tiam-inventory-log" class="text-xs bg-slate-900 text-slate-100 p-4 rounded-lg whitespace-pre-wrap max-h-96 overflow-auto" dir="rtl" aria-live="polite"></pre>
    <p class="text-xs">لغو درخواست، نتیجهٔ آن را بی‌اعتبار می‌کند؛ اتصال VPN یا پردازش محلی در حال اجرا را فوراً متوقف نمی‌کند.</p>
    <button type="button" id="tiam-inventory-cancel" hidden onclick="tiamInventoryAction('cancel')" class="bg-rose-700 text-white px-4 py-2 rounded-lg text-xs">لغو درخواست معطل / جاری</button>
    <button type="button" id="tiam-inventory-export" hidden onclick="tiamInventoryAction('export_manual')" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs">دانلود JSON سازگار با بخش همگام‌سازی دستی</button>
    <button type="button" id="tiam-inventory-confirm" hidden onclick="tiamInventoryAction('apply')" class="bg-emerald-600 text-white px-5 py-3 rounded-lg font-bold">تأیید پیش‌نمایش و اعمال تغییرات</button>
</section>
<script>
window.tiamInventoryConfigured = <?php echo !empty($inventoryCfg['host'])?'true':'false'; ?>;
let tiamInventoryJob = null, tiamInventoryTimer = null, tiamInventoryRequest = 0;
const tiamInventoryProject = <?php echo json_encode($project['id']); ?>;
const tiamInventoryCsrf = <?php echo json_encode($_SESSION['tiam_csrf']); ?>;
async function tiamPairAgent(button) {
    button.disabled=true;
    const log=document.getElementById('tiam-inventory-log');
    try {
        const response=await fetch('/panel/tiam_inventory.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pair',project:tiamInventoryProject,csrf:tiamInventoryCsrf})});
        const result=await response.json();
        if(!response.ok||!result.connection)throw new Error(result.error||'ثبت اتصال ناموفق بود.');
        const profile=JSON.stringify(result.connection);
        try {await navigator.clipboard.writeText(profile);log.textContent='کلید جدید ثبت و اتصال کپی شد. در ایجنت نسخهٔ ۱.۰.۳، «چسباندن اتصال از پنل» و سپس «اتصال و شروع» را بزنید. کلید قبلی جایگزین شد.';}
        catch(e){window.prompt('کلید جدید ثبت شد؛ اطلاعات زیر را کپی و در ایجنت بچسبانید. این متن محرمانه است.',profile);log.textContent='کلید جدید ثبت شد. اتصال را در ایجنت نسخهٔ ۱.۰.۳ بچسبانید.';}
        const tokenField=document.querySelector('[data-tiam-field="agent_token"]');if(tokenField)tokenField.value='';
    } catch(e){log.textContent='ساخت اتصال انجام نشد: '+e.message;}
    finally{button.disabled=false;}
}
function tiamFactInventoryPreset(complete=false) {
    const baseMapping=<?php echo json_encode(TiamInventory::factInventoryMapping(), JSON_UNESCAPED_UNICODE); ?>;
    const mapping=complete?<?php echo json_encode(TiamInventory::factInventoryMapping(true), JSON_UNESCAPED_UNICODE); ?>:baseMapping;
    document.querySelectorAll('[data-tiam-map]').forEach(el=>el.value=mapping[el.dataset.tiamMap]||'');
    if(!complete)document.querySelector('[data-tiam-field="view"]').value='FactInventory';
    document.getElementById('tiam-inventory-log').textContent=complete?'الگوی کامل ERP وارد شد؛ نام گزارش را مطابق خروجی تیم فنی تنظیم کنید. فقط پس از اضافه‌شدن ستون‌ها ذخیره و تست کنید.':'نگاشت FactInventory فعلی وارد شد؛ سایز، رنگ و قیمت تعیین نشده‌اند. تنظیمات را ذخیره کنید، سپس پیش‌نمایش را بررسی کنید.';
}
function tiamUpdateNetworkMode() {
    const managed=document.getElementById('tiam-network-mode').value==='1';
    document.querySelectorAll('[data-tiam-field^="vpn_"]').forEach(el=>{
        if(el.dataset.tiamField==='vpn_enabled') return;
        el.disabled=!managed;
        el.closest('label').hidden=!managed;
    });
}
async function tiamCopyLog() {
    const text=document.getElementById('tiam-inventory-log').textContent;
    const result=document.getElementById('tiam-copy-result');
    const fallback=document.getElementById('tiam-copy-fallback');
    fallback.hidden=true;
    if(!text.trim()) { result.textContent='هنوز لاگی برای کپی وجود ندارد.'; return; }
    try { await navigator.clipboard.writeText(text); result.textContent='لاگ کپی شد.'; }
    catch(e) {
        fallback.value=text;fallback.hidden=false;fallback.focus();fallback.select();
        result.textContent='کپی خودکار مجاز نبود؛ متن انتخاب شده را با Ctrl+C یا گزینهٔ کپی دستگاه بردارید.';
    }
}
async function tiamGenerateKey() {
    const bytes = new Uint8Array(32); crypto.getRandomValues(bytes);
    const key = Array.from(bytes, b=>b.toString(16).padStart(2,'0')).join('');
    document.querySelector('[data-tiam-field="agent_token"]').value = key;
    try { await navigator.clipboard.writeText(key); alert('کلید کپی شد. آن را در محیط ایجنت وارد و سپس تنظیمات را ذخیره کنید.'); }
    catch(e) { alert('کپی خودکار ممکن نشد؛ کلید در فیلد کلید ایجنت قرار گرفت.'); }
}
async function tiamInventoryAction(action) {
    const requestVersion = ++tiamInventoryRequest;
    clearTimeout(tiamInventoryTimer);
    const log = document.getElementById('tiam-inventory-log');
    if(action!=='export_manual')document.getElementById('tiam-inventory-confirm').hidden = true;
    const payload = {action, project:tiamInventoryProject, csrf:tiamInventoryCsrf, job:tiamInventoryJob};
    if (action==='save') {
        const cfg={mapping:{}};
        document.querySelectorAll('[data-tiam-field]').forEach(el=>cfg[el.dataset.tiamField]=el.type==='checkbox'?el.checked:el.value);
        document.querySelectorAll('[data-tiam-map]').forEach(el=>cfg.mapping[el.dataset.tiamMap]=el.value);
        payload.config=cfg;
    }
    try {
        const response=await fetch(<?php echo json_encode('/panel/tiam_inventory.php'); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data=await response.json();
        if(requestVersion!==tiamInventoryRequest) return;
        if(data.code==='TIAM_JOB_BUSY') { await tiamInventoryAction('latest'); if(tiamInventoryRequest===requestVersion+1) log.textContent='درخواست جدید ساخته نشد؛ وضعیت درخواست قبلی:\n'+log.textContent; return; }
        if(!response.ok || data.error) throw new Error(data.error || 'خطای سرور');
        if(data.format==='maarksho.erp.inventory.v1') {
            const url=URL.createObjectURL(new Blob([JSON.stringify(data.rows,null,2)],{type:'application/json'}));
            const link=document.createElement('a');link.href=url;link.download='tiam-erp-manual-'+tiamInventoryProject+'.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);return;
        }
        if(data.id) tiamInventoryJob=data.id;
        if(data.status==='saved') {
            window.tiamInventoryConfigured=true;
            log.textContent='تنظیمات این پروژه ذخیره شد. ایجنت را با آدرس '+window.location.origin+<?php echo json_encode('/panel/tiam_inventory.php'); ?>+' و شناسهٔ پروژه '+tiamInventoryProject+' اجرا کنید.';
            document.querySelectorAll('[data-tiam-field][type="password"]').forEach(el=>el.value=''); return;
        }
        document.getElementById('tiam-inventory-cancel').hidden=!data.can_cancel;
        document.getElementById('tiam-inventory-export').hidden=!data.can_apply;
        const labels={queued:'در صف؛ هنوز توسط ایجنت دریافت نشده',running:'دریافت‌شده توسط ایجنت؛ منتظر نتیجه',cancelled:'لغو شده',expired:'مهلت درخواست تمام شده',done:'تست تمام شده',error:'عملیات ناموفق',preview:'آمادهٔ بررسی پیش‌نمایش',applied:'اعمال شده'};
        const seen=data.agent_last_seen?new Date(data.agent_last_seen*1000).toLocaleString('fa-IR',{timeZone:'Asia/Tehran'}):'هنوز ارتباط معتبری ثبت نشده';
        log.textContent=(labels[data.state]||'وضعیت ایجنت')+'\nآخرین ارتباط ایجنت: '+seen+' (تهران)\n'+(data.agent_connected?'ایجنت در ۹۰ ثانیهٔ اخیر با پنل ارتباط داشته است.':'ارتباط اخیر ایجنت ثبت نشده؛ آدرس، شناسهٔ پروژه و توکن را بررسی کنید.')+'\n'+JSON.stringify(data,null,2);
        if(data.state==='queued') log.textContent='در انتظار اتصال ایجنت این پروژه…\n'+log.textContent;
        if(data.state==='preview') {
            log.textContent='پیش‌نمایش (حداکثر ۱۰۰ تغییر نمایش داده می‌شود؛ تعداد کل در change_count):\n'+log.textContent;
            document.getElementById('tiam-inventory-confirm').hidden=!data.can_apply;
        }
        if(['queued','running'].includes(data.state)) tiamInventoryTimer=setTimeout(()=>tiamInventoryAction(action==='latest'?'latest':'status'),3000);
    } catch(e) {if(requestVersion===tiamInventoryRequest) log.textContent=e.message;}
}
document.addEventListener('DOMContentLoaded',()=>{tiamUpdateNetworkMode();tiamInventoryAction('latest');},{once:true});
</script>
