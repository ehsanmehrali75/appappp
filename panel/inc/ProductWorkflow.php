<?php
/** Shared catalog views; publication evidence remains separate from portal image approval. */
class ProductWorkflow {
    public static function label($status) {
        return ['image_not_required'=>'عکس نیاز ندارد','technical_pending'=>'باگ فنی؛ در حال بررسی','inventory_issue'=>'مشکل موجودی؛ در حال بررسی','active'=>'تصاویر تأییدشده','image_request'=>'درخواست تولید تصویر','pending_approval'=>'منتظر تأیید تصویر','out_of_stock'=>'ناموجود؛ در آرشیو',
            'rejected'=>'تصویر ردشده','waiting_reference'=>'منتظر عکس رفرنس','waiting_ai_image'=>'منتظر عکس نهایی','no_image'=>'منتظر تولید عکس'][$status]??'نیازمند بررسی';
    }
    public static function filters() {
        return ['image_not_required'=>'عکس نیاز ندارد','all'=>'همهٔ محصولات','ready'=>'تصاویر تأییدشده','pending_approval'=>'منتظر تأیید تصویر',
            'image_request'=>'درخواست تولید تصویر','needs_reference'=>'عکس رفرنس ندارد','reference_ready'=>'رفرنس دارد؛ آمادهٔ تولید',
            'needs_image'=>'گردش تولید تصویر','production_ready'=>'آمادهٔ تولید عکس','pending_upload'=>'منتظر آپلود عکس','needs_size_chart'=>'منتظر تولید جدول سایز',
            'size_chart_upload'=>'منتظر آپلود جدول سایز','needs_description'=>'منتظر تولید توضیحات',
            'inventory_issue'=>'مشکل موجودی','photo_review'=>'بررسی عکس و مشکلات','technical_pending'=>'بررسی فنی انتشار','unknown_stock'=>'موجودی نامشخص','out_of_stock'=>'آرشیو ناموجودها','rejected'=>'تصاویر ردشده'];
    }
    public static function matches($project,$product,$filter,$state=null,$status=null) {
        if($filter==='all')return true;
        if($filter==='image_not_required')return ImageProduction::imageExempt($product);
        $issue=ImageProduction::issueType($product);
        if($filter==='inventory_issue')return $issue==='inventory';
        if($filter==='photo_review'){$review=$state??WebsiteCatalogSync::state($project,$product);return $issue!==''||$review['needs_image']||$review['publication']==='technical_pending';}
        if($filter==='technical_pending'&&$issue==='technical')return true;
        $status=$status??getProductWorkflowStatus($product,$project);
        if($filter==='out_of_stock')return $status==='out_of_stock';
        if($status==='out_of_stock')return false;
        if($filter==='ready')return $status==='active';
        if($filter==='pending_approval')return $status==='pending_approval';
        if($filter==='rejected')return $status==='rejected';
        if($filter==='needs_image')return ($state??WebsiteCatalogSync::state($project,$product))['needs_image'];
        if($filter==='unknown_stock')return WebsiteCatalogSync::inventory($product)==='unknown';
        $state=$state??WebsiteCatalogSync::state($project,$product);
        if($filter==='production_ready')return $state['image_production']['can_produce_image'];
        if($filter==='needs_reference'||$filter==='reference_ready')return $state['image_production'][$filter];
        if($filter==='size_chart_upload')return $state['size_chart_upload_pending'];
        if(in_array($filter,['needs_size_chart','needs_description'],true))return $state[$filter];
        return $state['publication']===$filter;
    }
    public static function counts($project) {
        $counts=array_fill_keys(array_keys(self::filters()),0);
        foreach($project['products']??[] as $product) {
            $status=getProductWorkflowStatus($product,$project);$state=WebsiteCatalogSync::state($project,$product);
            foreach($counts as $filter=>$count)if(self::matches($project,$product,$filter,$state,$status))$counts[$filter]++;
        }
        return $counts;
    }
}
