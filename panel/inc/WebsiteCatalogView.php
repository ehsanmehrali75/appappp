<?php
if (!hasPermission('products')) return;
if (empty($_SESSION['website_catalog_csrf'])) $_SESSION['website_catalog_csrf']=bin2hex(random_bytes(24));
$wcEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$websiteQueueLabels=['technical_pending'=>'بررسی مشکل فنی','pending_upload'=>'منتظر آپلود عکس','pending_image'=>'منتظر تولید عکس','pending_approval'=>'منتظر تأیید پورتال','uploaded'=>'عکس در سایت موجود','resolved'=>'آپلود ثبت‌شده؛ منتظر تطبیق بعدی'];
$wcFilters=['work'=>'همهٔ کارهای باقی‌مانده','pending_upload'=>'آپلود عکس','technical_pending'=>'باگ فنی انتشار','pending_image'=>'تولید عکس','pending_approval'=>'تأیید تصویر در پورتال','needs_size_chart'=>'تولید جدول سایز','size_chart_upload'=>'آپلود جدول سایز','needs_description'=>'تولید توضیحات','content_upload'=>'آپلود محتوای آماده','compare'=>'مقایسهٔ پنل و سایت','resolved'=>'آپلود تأییدشده','all'=>'همهٔ محصولات'];
$wcQueueInput=$_GET['wc_queue']??'';
$wcQueue=is_string($wcQueueInput)&&isset($wcFilters[$wcQueueInput])?$wcQueueInput:'work';
$wcSearch=trim(mb_substr(is_string($_GET['wc_q']??null)?$_GET['wc_q']:'',0,120));
$wcStock=in_array($_GET['wc_stock']??'',['in_stock','out_of_stock','unknown'],true)?$_GET['wc_stock']:'';
$wcLimit=in_array((int)($_GET['wc_size']??20),[10,20,50],true)?(int)($_GET['wc_size']??20):20;
$wcNormalize=static fn($value)=>mb_strtolower(str_replace(['ي','ك',"\u{200c}"],['ی','ک',''],(string)$value));
$websiteQueues=[];$wcCounts=array_fill_keys(array_keys($wcFilters),0);
foreach(($project['products']??[]) as $websiteProduct) {
    $ws=WebsiteCatalogSync::state($project,$websiteProduct);$pub=$ws['publication']??'';$tasks=[];
    foreach(['needs_size_chart','needs_description'] as $key)if($ws[$key])$tasks[]=$key;
    if($ws['size_chart_upload_pending'])$tasks[]='size_chart_upload';
    if($ws['description_upload_pending']||$ws['size_chart_upload_pending'])$tasks[]='content_upload';
    if(in_array($pub,['technical_pending','pending_upload','pending_image','pending_approval','resolved'],true))$tasks[]=$pub;
    if($ws['comparison_available'])$tasks[]='compare';
    $work=(bool)array_diff($tasks,['resolved','compare']);
    $wcCounts['all']++;if($work)$wcCounts['work']++;foreach(array_unique($tasks) as $key)if(isset($wcCounts[$key]))$wcCounts[$key]++;
    if($wcQueue!=='all'&&!($wcQueue==='work'?$work:in_array($wcQueue,$tasks,true)))continue;
    if($wcStock!==''&&$ws['inventory']!==$wcStock)continue;
    if($wcSearch!==''&&mb_strpos($wcNormalize(($websiteProduct['name']??'').' '.($websiteProduct['sku']??'')),$wcNormalize($wcSearch))===false)continue;
    $websiteQueues[]=['product'=>$websiteProduct,'state'=>$ws,'tasks'=>$tasks];
}
usort($websiteQueues,static function($a,$b){
    $rank=static fn($e)=>(!empty($e['state']['low_priority'])?100:0)+(['technical_pending'=>0,'pending_upload'=>1,'pending_image'=>2,'pending_approval'=>3][$e['state']['publication']??'']??4);
    return $rank($a)<=>$rank($b) ?: strcmp((string)($a['product']['sku']??''),(string)($b['product']['sku']??''));
});
$wcTotal=count($websiteQueues);$wcPages=max(1,(int)ceil($wcTotal/$wcLimit));$wcPage=max(1,min($wcPages,(int)($_GET['wc_page']??1)));
$websiteQueues=array_slice($websiteQueues,($wcPage-1)*$wcLimit,$wcLimit);
$wcUrl=static function($page)use($tab,$wcQueue,$wcSearch,$wcStock,$wcLimit){return 'admin.php?'.http_build_query(['tab'=>$tab,'wc_queue'=>$wcQueue,'wc_q'=>$wcSearch,'wc_stock'=>$wcStock,'wc_size'=>$wcLimit,'wc_page'=>$page]).'#website-catalog-list';};
$wcPhotos=static function($urls,$label)use($wcEscape){
    echo '<div class="wc-media-grid">';foreach(array_slice($urls,0,8) as $url){$safe=ProductSizeChartCatalog::mediaUrl($url);if(!$safe)continue;echo '<a href="'.$wcEscape($safe).'" target="_blank" rel="noopener"><img src="'.$wcEscape($safe).'" loading="lazy" alt="'.$wcEscape($label).'"></a>';}
    echo '</div>';if(count($urls)>8)echo '<p>۸ تصویر اول نمایش داده شده است؛ بقیه در ویرایش محصول در دسترس است.</p>';
};
?>
<link rel="stylesheet" href="/panel/assets/website-catalog.css?v=<?php echo filemtime(__DIR__.'/../assets/website-catalog.css'); ?>">
<section id="website-catalog-panel" class="premium-card wc-panel" dir="rtl">
    <header class="wc-heading"><div><p class="wc-eyebrow">موجودی مرکزی ← تولید محتوا ← انتشار در سایت</p><h2>تطبیق محصولات و صف کار</h2><p>وضعیت تولید، آپلود و مشکلات انتشار را بر اساس داده‌های همین پروژه بررسی کنید.</p></div><a class="btn btn-outline" href="admin.php?tab=website_export">رفتن به خروجی وب‌سایت ↗</a></header>
    <details class="wc-import" <?php echo empty($project['settings']['website_catalog']['last_sync'])?'open':''; ?>><summary>دریافت و تطبیق خروجی جدید سایت <span><?php $wcLast=$project['settings']['website_catalog']['last_sync']['at']??null;echo $wcLast?'آخرین تطبیق: '.$wcEscape(date('Y/m/d H:i',(int)$wcLast)):'هنوز فایل سایت تطبیق نشده است'; ?></span></summary><div class="wc-import-body">
        <p>فقط محصولات موجود با کد کالا یا لینک قطعی به‌روز می‌شوند. عکس‌های پنل و جدول استودیو حفظ و فایل‌های سایت جداگانه ثبت می‌شوند.</p>
        <div class="wc-import-fields"><label>فایل JSON سایت<input id="website-catalog-file" type="file" accept=".json,application/json" class="input"></label><label>واحد قیمت<select id="website-catalog-price-unit" class="select"><option value="auto">از متن قیمت (ریال / تومان)</option><option value="rial">ریال</option><option value="toman">تومان</option></select></label></div>
        <div class="wc-options"><label><input id="website-catalog-price" type="checkbox" checked>به‌روزرسانی قیمت</label><label><input id="website-catalog-stock" type="checkbox">جایگزینی موجودی صریح از فایل سایت</label></div>
        <p class="wc-hint">موجودی مرکزی حفظ می‌شود؛ گزینهٔ جایگزینی موجودی فقط در صورت انتخاب شما اعمال می‌شود.</p>
        <label class="wc-checkbox"><input id="website-catalog-complete" type="checkbox"><span>این فایل، خروجی کامل همین سایت بدون محدودیت دسته‌بندی، تعداد یا موجودی است؛ غیبت محصولات هم بررسی شود.</span></label>
        <p class="wc-hint">خروجی ناقص، نبودن محصول در سایت را ثابت نمی‌کند؛ موجودی هیچ محصولی به علت غیبت از فایل صفر نمی‌شود.</p>
        <div class="wc-actions"><button type="button" id="website-catalog-preview" class="btn btn-outline">پیش‌نمایش تغییرات</button><button type="button" id="website-catalog-apply" class="btn btn-primary" hidden>تأیید و ثبت تغییرات</button></div>
    </div></details>
    <div id="website-catalog-result" role="status" aria-live="polite" tabindex="0" aria-label="گزارش پیش‌نمایش و ثبت"></div>
    <div class="wc-summary" aria-label="خلاصهٔ کارهای پروژه"><?php foreach(['pending_image'=>'تولید عکس','pending_upload'=>'آپلود عکس','needs_size_chart'=>'تولید جدول سایز','size_chart_upload'=>'آپلود جدول سایز','technical_pending'=>'بررسی فنی'] as $key=>$label): ?><a href="<?php echo $wcEscape('admin.php?'.http_build_query(['tab'=>$tab,'wc_queue'=>$key]).'#website-catalog-list'); ?>"><strong><?php echo number_format($wcCounts[$key]); ?></strong><span><?php echo $label; ?></span></a><?php endforeach; ?></div>
    <div id="website-catalog-list">
    <form method="get" action="admin.php#website-catalog-list" class="wc-filters"><input type="hidden" name="tab" value="<?php echo $wcEscape($tab); ?>">
        <label>صف کار<select id="website-catalog-queue" name="wc_queue" class="select"><?php foreach($wcFilters as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $wcQueue===$key?'selected':''; ?>><?php echo $wcEscape($label).' ('.$wcCounts[$key].')'; ?></option><?php endforeach; ?></select></label>
        <label>جست‌وجوی محصول<input id="website-catalog-search" name="wc_q" type="search" class="input" placeholder="نام یا کد کالا" value="<?php echo $wcEscape($wcSearch); ?>"></label>
        <label>موجودی<select name="wc_stock" class="select"><option value="">همه؛ ناموجودها در انتها</option><?php foreach(['in_stock'=>'موجود','out_of_stock'=>'ناموجود / اولویت پایین','unknown'=>'موجودی نامشخص'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $wcStock===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <label>در هر صفحه<select name="wc_size" class="select"><?php foreach([10,20,50] as $limit): ?><option <?php echo $wcLimit===$limit?'selected':''; ?>><?php echo $limit; ?></option><?php endforeach; ?></select></label><button class="btn btn-outline">اعمال فیلتر</button>
    </form>
    <p id="website-catalog-queue-count" class="wc-hint"><?php echo $wcTotal?'نمایش '.((($wcPage-1)*$wcLimit)+1).' تا '.min($wcPage*$wcLimit,$wcTotal).' از '.$wcTotal.' محصول':'محصولی با این فیلتر پیدا نشد.'; ?> — صفحهٔ <?php echo $wcPage.' از '.$wcPages; ?></p>
    <div class="wc-products">
    <?php foreach($websiteQueues as $websiteEntry):$wp=$websiteEntry['product'];$ws=$websiteEntry['state'];$pub=$ws['publication']??'';$labels=[];
        if($ws['needs_description'])$labels[]='تولید توضیحات';if($ws['needs_size_chart'])$labels[]='تولید جدول سایز';if($ws['description_upload_pending'])$labels[]='آپلود توضیحات';if($ws['size_chart_upload_pending'])$labels[]=$ws['size_chart_export_needed']?'جدول ساخته شده؛ خروجی تصویر و آپلود':'آپلود جدول سایز';
        $wcProductUrl='admin.php?tab=products&filter=all&admin_q='.rawurlencode($wp['sku']??$wp['name']??'');
    ?>
    <article class="website-catalog-row wc-product <?php echo $ws['low_priority']?'wc-low-priority':''; ?>" data-product-id="<?php echo $wcEscape($wp['id']); ?>">
        <div class="wc-product-summary"><div><strong><?php echo $wcEscape($wp['name']??''); ?></strong><div class="wc-sku" dir="ltr"><?php echo $wcEscape($wp['sku']??''); ?></div></div><div><span class="wc-badge"><?php echo $wcEscape($websiteQueueLabels[$pub]??'هنوز تطبیق نشده'); ?></span><p class="wc-task-label"><?php echo $wcEscape(implode(' · ',$labels)?:'کار محتوایی باقی نمانده'); ?></p></div><div class="wc-stock"><span><?php echo ['in_stock'=>'موجود','out_of_stock'=>'ناموجود؛ اولویت پایین','unknown'=>'موجودی نامشخص'][$ws['inventory']]; ?></span><small>کل: <?php echo $wcEscape($wp['stock_total']??'—'); ?> · آنلاین: <?php echo $wcEscape($wp['stock_online']??'—'); ?></small></div></div>
        <details class="wc-compare"><summary>مقایسهٔ عکس و جدول سایز / اقدامات</summary><div class="wc-comparison-body">
            <p class="wc-hint">مقایسهٔ دیداری بر اساس آخرین دریافت سایت است؛ یکسان‌بودن محتوا خودکار تأیید نمی‌شود.<?php if(!empty($ws['checked_at']))echo ' آخرین بررسی: '.$wcEscape(date('Y/m/d H:i',(int)$ws['checked_at'])); ?><?php if(($ws['presence']??'')==='missing')echo ' — محصول در خروجی کامل اخیر سایت پیدا نشد.'; ?></p>
            <div class="wc-compare-columns"><section><h3>عکس‌های نهایی پنل</h3><?php $wcPhotos($ws['local_image_urls'],'عکس نهایی پنل');if(!$ws['local_image_urls'])echo '<p>عکس نهایی غیرسوشیال در پنل ثبت نشده است. رفرنس اختیاری است؛ عکس نهایی برای انتشار لازم است.</p>'; ?></section><section><h3>عکس‌های سایت</h3><?php $wcPhotos($ws['site_image_urls'],'عکس ثبت‌شده در سایت');if(!$ws['site_image_urls'])echo '<p>در آخرین دادهٔ سایت، عکس تأییدشده‌ای مشاهده نشده است.</p>'; ?></section><section><h3>جدول سایز پنل / استودیو</h3><?php $chart=$ws['chart'];if($chart['image_url'])$wcPhotos([$chart['image_url']],'جدول سایز پنل');elseif($chart['exists'])echo '<p>جدول در استودیو ساخته شده است؛ برای آپلود، تصویر خروجی بگیرید.</p>';else echo '<p>جدول تولید نشده است.</p>'; ?>
            <?php if($chart['rows']): ?><div class="wc-chart-data" tabindex="0" role="region" aria-label="داده‌های جدول سایز استودیو"><table><thead><tr><?php foreach($chart['columns'] as $column): ?><th><?php echo $wcEscape(is_array($column)?($column['label']??$column['title']??$column['key']??''): $column); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach(array_slice($chart['rows'],0,20) as $row): ?><tr><?php foreach((array)$row as $cell): ?><td><?php echo $wcEscape(is_scalar($cell)?$cell:json_encode($cell,JSON_UNESCAPED_UNICODE)); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <a href="<?php echo $wcEscape($chart['studio_url']); ?>" class="btn btn-outline">باز کردن در استودیو سایز ↗</a></section><section><h3>جدول سایز سایت</h3><?php $wcPhotos($ws['site_chart_urls'],'جدول سایز سایت');if(!$ws['site_chart_urls'])echo '<p>در آخرین دادهٔ سایت، جدول سایز مشاهده نشده است.</p>'; ?></section></div>
            <div class="wc-actions"><a class="btn btn-outline" href="<?php echo $wcEscape($wcProductUrl); ?>">ویرایش محصول و بارگذاری عکس</a><?php if(in_array($pub,['technical_pending','pending_upload'],true)&&!$ws['approval_pending']): ?><button type="button" class="btn btn-outline website-catalog-resolve" data-id="<?php echo $wcEscape($wp['id']); ?>">بررسی کردم؛ آپلود انجام شده است</button><?php endif; ?><?php if(!empty($ws['resolved_by'])): ?><span class="wc-hint">تأیید آپلود توسط <?php echo $wcEscape($ws['resolved_by']); ?>؛ در دریافت بعدی سایت دوباره بررسی می‌شود.</span><?php endif; ?></div>
        </div></details>
    </article>
    <?php endforeach; ?>
    </div>
    <?php if($wcPages>1): ?><nav class="wc-pagination" aria-label="صفحه‌بندی محصولات"><?php if($wcPage>1): ?><a class="btn btn-outline" href="<?php echo $wcEscape($wcUrl($wcPage-1)); ?>">صفحهٔ قبل</a><?php endif; ?><?php for($i=max(1,$wcPage-2);$i<=min($wcPages,$wcPage+2);$i++): ?><a class="btn btn-outline" href="<?php echo $wcEscape($wcUrl($i)); ?>" <?php echo $i===$wcPage?'aria-current="page"':''; ?>><?php echo $i; ?></a><?php endfor; ?><?php if($wcPage<$wcPages): ?><a class="btn btn-outline" href="<?php echo $wcEscape($wcUrl($wcPage+1)); ?>">صفحهٔ بعد</a><?php endif; ?></nav><?php endif; ?>
    </div>
</section>
<script>const websiteCatalogCsrf=<?php echo json_encode($_SESSION['website_catalog_csrf']); ?>;</script>
<script src="/panel/assets/website-catalog.js?v=<?php echo filemtime(__DIR__.'/../assets/website-catalog.js'); ?>" defer></script>
