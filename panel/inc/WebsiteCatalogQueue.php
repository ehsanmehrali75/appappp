<?php
/** Shared server-side filters for the work queue, its counts and complete filtered exports. */
class WebsiteCatalogQueue {
    public const FILTERS=['image_not_required'=>'عکس نیاز ندارد','chart_not_required'=>'جدول سایز نیاز ندارد','photo_review'=>'بررسی عکس و مشکلات','missing_photo'=>'بدون عکس؛ نیازمند عکس','inventory_issue'=>'مشکل موجودی','site_visible'=>'در سایت با تصویر موجود','site_missing'=>'در خروجی کامل سایت نیست','site_unknown'=>'هنوز با سایت تطبیق نشده','archive'=>'آرشیو ناموجودها','work'=>'همهٔ کارهای باقی‌مانده','pending_upload'=>'آپلود عکس','technical_pending'=>'باگ فنی انتشار','image_request'=>'درخواست تولید تصویر','needs_reference'=>'عکس رفرنس ندارد','reference_ready'=>'رفرنس دارد؛ آمادهٔ تولید','pending_image'=>'تولید عکس','pending_approval'=>'تأیید تصویر در پورتال','needs_size_chart'=>'تولید جدول سایز','size_chart_upload'=>'آپلود جدول سایز','needs_description'=>'تولید توضیحات','content_upload'=>'آپلود محتوای آماده','compare'=>'مقایسهٔ پنل و سایت','resolved'=>'آپلود تأییدشده','all'=>'همهٔ محصولات'];

    public static function entries($project,$filters=[]) {
        $wcSearch=is_string($filters['wc_q']??null)?mb_substr(trim($filters['wc_q']),0,120):'';
        $wcCategory=is_string($filters['wc_category']??null)?$filters['wc_category']:'';
        if($wcCategory!==''){$knownCategories=$project['settings']['website_catalog']['chart_exempt_categories']??[];foreach($project['products']??[] as $p)$knownCategories=array_merge($knownCategories,WebsiteCatalogSync::categories($p));if(!in_array($wcCategory,$knownCategories,true))$wcCategory='';}
        $wcStock=is_string($filters['wc_stock']??null)?$filters['wc_stock']:'';
        $wcStockStatuses=['in_stock'=>true,'out_of_stock'=>true,'unknown'=>true];
        $wcStockThresholds=['gt_5'=>5,'gt_10'=>10,'gt_15'=>15,'gt_20'=>20];
        $wcNormalize=static fn($v)=>mb_strtolower(str_replace(['ي','ك',"\u{200c}"],['ی','ک',''],(string)$v));
        $websiteQueues=[];
        foreach(($project['products']??[]) as $websiteProduct) {
    if($wcSearch!==''&&mb_strpos($wcNormalize(($websiteProduct['name']??'').' '.($websiteProduct['sku']??'')),$wcNormalize($wcSearch))===false)continue;
    if($wcCategory!==''&&!in_array($wcCategory,WebsiteCatalogSync::categories($websiteProduct),true))continue;
    $ws=WebsiteCatalogSync::state($project,$websiteProduct);$pub=$ws['publication']??'';$tasks=[];
    if(isset($wcStockStatuses[$wcStock])&&$ws['inventory']!==$wcStock)continue;
    if(isset($wcStockThresholds[$wcStock])) {
        $wcQuantity=WebsiteCatalogSync::totalStock($websiteProduct);
        if($ws['archived']||$wcQuantity===null||$wcQuantity<=$wcStockThresholds[$wcStock])continue;
    }
    foreach(['needs_size_chart','needs_description'] as $key)if($ws[$key])$tasks[]=$key;
    if($ws['size_chart_upload_pending'])$tasks[]='size_chart_upload';
    if($ws['description_upload_pending']||$ws['size_chart_upload_pending'])$tasks[]='content_upload';
    if($ws['archived'])$tasks[]='archive';
    if(in_array($pub,['technical_pending','pending_upload','image_request','needs_reference','pending_image','pending_approval','resolved'],true))$tasks[]=$pub;
    if($ws['image_production']['needs_reference'])$tasks[]='needs_reference';
    if($ws['image_production']['reference_ready'])$tasks[]='reference_ready';
    if($ws['comparison_available'])$tasks[]='compare';
    if($ws['archived'])$tasks=['archive'];
    if(!$ws['archived']&&$ws['needs_image'])$tasks[]='missing_photo';
    if($ws['image_not_required'])$tasks[]='image_not_required';
    if($ws['chart_not_required'])$tasks[]='chart_not_required';
    if($ws['issue_type']==='technical')$tasks[]='technical_pending';
    if($ws['issue_type']==='inventory')$tasks[]='inventory_issue';
    if(in_array('missing_photo',$tasks,true)||$ws['production_blocked']||$pub==='technical_pending')$tasks[]='photo_review';
    $work=!$ws['archived']&&(bool)array_diff($tasks,['resolved','compare','archive','image_not_required','chart_not_required']);
    if(!$ws['archived']&&$ws['site_visible_with_images'])$tasks[]='site_visible';
    if(!$ws['archived']&&($ws['presence']??'')==='missing')$tasks[]='site_missing';
    if(!$ws['archived']&&!in_array($ws['presence']??'',['seen','missing'],true))$tasks[]='site_unknown';



    $tasks=array_values(array_unique($tasks));if($work)$tasks[]='work';$tasks[]='all';
    $websiteQueues[]=['product'=>$websiteProduct,'state'=>$ws,'tasks'=>$tasks];
}

        return $websiteQueues;
    }
    public static function sorted($entries) {
        usort($entries,static function($a,$b){$rank=static fn($e)=>(!empty($e['state']['low_priority'])?100:0)+(['technical_pending'=>0,'pending_upload'=>1,'pending_image'=>2,'pending_approval'=>3][$e['state']['publication']??'']??4);return $rank($a)<=>$rank($b) ?: strcmp((string)($a['product']['sku']??''),(string)($b['product']['sku']??''));});return $entries;
    }
    public static function matches($entry,$queue) {
        return in_array($queue,$entry['tasks'],true);
    }
    public static function filtered($project,$filters=[]) {
        $queue=$filters['wc_queue']??'work';
        if(!is_string($queue)||!isset(self::FILTERS[$queue]))$queue='work';
        return self::sorted(array_values(array_filter(self::entries($project,$filters),static fn($e)=>self::matches($e,$queue))));
    }
}
