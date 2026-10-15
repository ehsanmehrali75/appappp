<?php
if (!hasPermission('products')) return;
if (empty($_SESSION['website_catalog_csrf'])) $_SESSION['website_catalog_csrf']=bin2hex(random_bytes(24));
$websiteQueues=[];
foreach(($project['products']??[]) as $websiteProduct) $websiteQueues[]=['product'=>$websiteProduct,'state'=>WebsiteCatalogSync::state($project,$websiteProduct)];
$websiteQueueLabels=['technical_pending'=>'باگ فنی / منتظر آپلود','pending_upload'=>'منتظر آپلود','pending_image'=>'منتظر تولید عکس','uploaded'=>'در سایت موجود','resolved'=>'رفع نقص تأییدشده'];
?>
<section id="website-catalog-panel" class="premium-card p-6 mb-8 space-y-5" dir="rtl">
    <div><h2 class="font-black text-xl">تکمیل اطلاعات و تطبیق با سایت</h2><p class="text-sm text-slate-500 mt-2">فقط محصولات موجود با کد کالا یا لینک قطعی تطبیق می‌شوند؛ محصول جدید، رنگ یا تنوع ساخته نمی‌شود. تصاویر قبلی حفظ می‌شوند و تصاویر سایت با لینک اصلی به تصاویر نهایی اضافه می‌شوند.</p></div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label class="text-sm">خروجی JSON سایت<input id="website-catalog-file" type="file" accept=".json,application/json" class="input mt-2"></label>
        <label class="text-sm">واحد قیمت<select id="website-catalog-price-unit" class="select mt-2"><option value="auto">از متن قیمت (ریال / تومان)</option><option value="rial">ریال</option><option value="toman">تومان</option></select></label>
    </div>
    <div class="flex flex-wrap gap-5 text-sm">
        <label class="flex items-center gap-2"><input id="website-catalog-price" type="checkbox" checked>به‌روزرسانی قیمت</label>
        <label class="flex items-center gap-2"><input id="website-catalog-stock" type="checkbox" checked>به‌روزرسانی موجودی فقط در صورت وجود مقدار صریح</label>
    </div>
    <label class="flex items-start gap-3 text-sm"><input id="website-catalog-complete" type="checkbox"><span>تأیید می‌کنم این خروجی، فهرست کامل سایت برای همین پروژه است و خطای دریافت ندارد؛ محصولات غایب وارد صف بررسی انتشار شوند.</span></label>
    <p class="text-xs text-slate-500">خروجی یک دسته‌بندی، بستهٔ محدود یا فقط کالاهای موجود، نبودن محصول در کل سایت را ثابت نمی‌کند. برای این خروجی‌ها تیک بالا را فعال نکنید. موجودی تیام به علت غیبت از فایل صفر نمی‌شود.</p>
    <div class="flex flex-wrap gap-3"><button type="button" id="website-catalog-preview" class="btn btn-outline">پیش‌نمایش تغییرات</button><button type="button" id="website-catalog-apply" class="btn btn-primary" hidden>تأیید و ثبت تغییرات</button></div>
    <div id="website-catalog-result" role="status" aria-live="polite" tabindex="0" aria-label="گزارش پیش‌نمایش و ثبت" class="text-sm whitespace-pre-wrap max-h-96 overflow-auto"></div>
    <hr>
    <div class="flex flex-wrap gap-3">
        <label>صف کار<select id="website-catalog-queue" class="select"><option value="work">همهٔ کارهای باقی‌مانده</option><option value="technical_pending">باگ فنی / منتظر آپلود</option><option value="pending_upload">آمادهٔ آپلود / به‌روزرسانی سایت</option><option value="pending_image">فاقد عکس نهایی، خارج از سایت</option><option value="needs_size_chart">تولید جدول سایز</option><option value="needs_description">تولید توضیحات</option><option value="content_upload">آپلود توضیحات / جدول ساخته‌شده</option><option value="resolved">رفع نقص تأییدشده</option><option value="all">همهٔ محصولات</option></select></label>
        <label>جست‌وجو<input id="website-catalog-search" type="search" class="input" placeholder="نام یا کد کالا"></label>
    </div>
    <p id="website-catalog-queue-count" class="text-xs text-slate-500" aria-live="polite"></p>
    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="صف‌های تکمیل اطلاعات سایت"><table class="w-full text-sm"><thead><tr><th class="p-3">محصول</th><th class="p-3">وضعیت انتشار</th><th class="p-3">کارهای محتوا</th><th class="p-3">اقدام</th></tr></thead><tbody>
    <?php foreach($websiteQueues as $websiteEntry): $wp=$websiteEntry['product'];$ws=$websiteEntry['state'];$pub=$ws['publication']??'';$tasks=[];if($ws['needs_size_chart'])$tasks[]='needs_size_chart';if($ws['needs_description'])$tasks[]='needs_description';if($ws['description_upload_pending']||$ws['size_chart_upload_pending'])$tasks[]='content_upload';if(in_array($pub,['technical_pending','pending_upload','pending_image'],true))$tasks[]=$pub;if($pub==='resolved')$tasks[]='resolved'; ?>
        <tr class="website-catalog-row border-t" data-queues="<?php echo htmlspecialchars(implode(' ',$tasks),ENT_QUOTES,'UTF-8'); ?>" data-search="<?php echo htmlspecialchars(($wp['name']??'').' '.($wp['sku']??''),ENT_QUOTES,'UTF-8'); ?>">
            <td class="p-3"><strong><?php echo htmlspecialchars($wp['name']??'',ENT_QUOTES,'UTF-8'); ?></strong><div class="text-xs" dir="ltr"><?php echo htmlspecialchars($wp['sku']??'',ENT_QUOTES,'UTF-8'); ?></div></td>
            <td class="p-3"><?php echo htmlspecialchars($websiteQueueLabels[$pub]??'هنوز تطبیق نشده',ENT_QUOTES,'UTF-8'); ?><?php if(!empty($ws['resolved_by'])): ?><div class="text-xs">تأیید توسط <?php echo htmlspecialchars($ws['resolved_by'],ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?></td>
            <td class="p-3"><?php $labels=[];if($ws['needs_description'])$labels[]='تولید توضیحات';if($ws['needs_size_chart'])$labels[]='تولید جدول سایز';if($ws['description_upload_pending'])$labels[]='آپلود توضیحات';if($ws['size_chart_upload_pending'])$labels[]='آپلود جدول سایز';echo htmlspecialchars(implode(' · ',$labels)?:'محتوا آماده است',ENT_QUOTES,'UTF-8'); ?></td>
            <td class="p-3"><a class="btn btn-outline text-xs" href="admin.php?tab=products&amp;filter=all&amp;admin_q=<?php echo rawurlencode($wp['sku']??$wp['name']??''); ?>">مشاهده و ویرایش</a><?php if(in_array($pub,['technical_pending','pending_upload'],true)): ?><button type="button" class="btn btn-outline website-catalog-resolve" data-id="<?php echo htmlspecialchars((string)$wp['id'],ENT_QUOTES,'UTF-8'); ?>">درست شد؛ آپلود را بررسی کردم</button><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<script>const websiteCatalogCsrf=<?php echo json_encode($_SESSION['website_catalog_csrf']); ?>;</script>
<script src="/panel/assets/website-catalog.js?v=<?php echo filemtime(__DIR__.'/../assets/website-catalog.js'); ?>" defer></script>
