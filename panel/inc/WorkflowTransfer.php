<?php
require_once __DIR__.'/WebsiteCatalogQueue.php';
/** Round-trip only work decisions; product identity, ERP inventory and original assets are read-only. */
class WorkflowTransfer {
    public const SCHEMA='maarksho.workflow.v1';
    public const MAX_ROWS=5000;
    public const COLUMNS=['project_id','product_id','sku','name','category_path','stock_total','workflow','image_requirement','chart_requirement','chart_effective','issue','note','content_request','revision'];
    public static function revision($project,$product) {
        $meta=$product['website_sync']??[];if(is_string($meta))$meta=json_decode($meta,true)?:[];
        return hash('sha256',json_encode([$project['id'],(string)$product['id'],(string)($product['sku']??''),ImageProduction::imageExempt($product),!empty($meta['content_preferences']['chart_not_required']),ImageProduction::issueType($product),$meta['review_issue']['note']??'',!empty($product['content_gen_requested']),WebsiteCatalogSync::inventory($product),WebsiteCatalogSync::totalStock($product),WebsiteCatalogSync::categories($product),WebsiteCatalogSync::photoFingerprint($product),$project['settings']['website_catalog']['chart_exempt_categories']??[]],JSON_UNESCAPED_UNICODE));
    }
    public static function export($project,$filters=[],$ids=null) {
        if($ids===null)$products=array_column(WebsiteCatalogQueue::filtered($project,$filters),'product');
        else {
            self::validateIds($project,$ids);$byId=[];foreach($project['products']??[] as $p)$byId[(string)$p['id']]=$p;
            $products=array_map(static fn($id)=>$byId[$id],array_values(array_unique($ids)));
        }
        if(count($products)>self::MAX_ROWS)throw new RuntimeException('برای هر فایل حداکثر ۵۰۰۰ محصول انتخاب کنید.');
        $rows=[];
        foreach($products as $product) {
            $meta=$product['website_sync']??[];if(is_string($meta))$meta=json_decode($meta,true)?:[];
            $state=WebsiteCatalogSync::state($project,$product);
            $rows[]=['project_id'=>(string)$project['id'],'product_id'=>(string)$product['id'],'sku'=>(string)($product['sku']??''),'name'=>(string)($product['name']??''),'category_path'=>implode(' / ',WebsiteCatalogSync::categories($product)),'stock_total'=>(string)($product['stock_total']??''),'workflow'=>$state['publication'],'image_requirement'=>$state['image_not_required']?'not_required':'required','chart_requirement'=>!empty($meta['content_preferences']['chart_not_required'])?'not_required':'inherit','chart_effective'=>$state['chart_not_required']?'not_required':'required','issue'=>$state['issue_type']?:'none','note'=>(string)($meta['review_issue']['note']??''),'content_request'=>'keep','revision'=>self::revision($project,$product)];
        }
        return ['schema'=>self::SCHEMA,'project_id'=>(string)$project['id'],'exported_at'=>gmdate(DATE_ATOM),'products'=>$rows];
    }
    public static function validateIds($project,$ids) {
        if(!is_array($ids)||count($ids)>self::MAX_ROWS)throw new RuntimeException('حداکثر ۵۰۰۰ شناسهٔ محصول مجاز است.');
        $known=[];foreach($project['products']??[] as $p)$known[(string)$p['id']]=true;
        foreach($ids as $id)if(!is_string($id)||!isset($known[$id]))throw new RuntimeException('یکی از محصولات متعلق به این پروژه نیست.');
    }
    private static function option($row,$key,$allowed) {
        $value=$row[$key]??'keep';
        if(is_string($value)) {
            $value=trim($value);if($value==='')$value='keep';
            $aliases=['بدون تغییر'=>'keep'];
            if($key==='image_requirement')$aliases+=['نیاز ندارد'=>'not_required','عکس نیاز ندارد'=>'not_required','نیاز دارد'=>'required','عکس نیاز دارد'=>'required'];
            if($key==='chart_requirement')$aliases+=['نیاز ندارد'=>'not_required','جدول سایز نیاز ندارد'=>'not_required','تابع دسته‌بندی'=>'inherit','پیش‌فرض'=>'inherit'];
            if($key==='issue')$aliases+=['باگ فنی'=>'technical','بررسی فنی'=>'technical','مشکل موجودی'=>'inventory','رفع مشکل'=>'none','بدون مشکل'=>'none'];
            if($key==='content_request')$aliases+=['درخواست تولید'=>'request','درخواست'=>'request'];
            $value=$aliases[$value]??$value;
        }
        if(!is_string($value)||!in_array($value,$allowed,true))throw new RuntimeException('مقدار ستون '.$key.' معتبر نیست.');
        return $value;
    }
    public static function plan($project,$payload,$actor,$canRequest) {
        if(!is_array($payload)||($payload['schema']??'')!==self::SCHEMA||($payload['project_id']??'')!==(string)$project['id'])throw new RuntimeException('فایل مدیریت صف متعلق به این پروژه نیست یا قالب آن معتبر نیست.');
        $rows=$payload['products']??null;
        if(!is_array($rows)||!$rows||count($rows)>self::MAX_ROWS||array_keys($rows)!==range(0,count($rows)-1))throw new RuntimeException('فایل باید بین ۱ تا ۵۰۰۰ ردیف محصول داشته باشد.');
        $known=[];foreach($project['products']??[] as $p)$known[(string)$p['id']]=$p;
        $seen=[];$checked=[];
        // Validate every row before constructing any changes.
        foreach($rows as $index=>$row) {
            $line=$index+2;
            if(!is_array($row)||($row['project_id']??'')!==(string)$project['id']||!is_string($row['product_id']??null)||!isset($known[$row['product_id']]))throw new RuntimeException('ردیف '.$line.': شناسهٔ محصول یا پروژه معتبر نیست.');
            $id=$row['product_id'];if(isset($seen[$id]))throw new RuntimeException('ردیف '.$line.': محصول تکراری است.');$seen[$id]=true;
            if(array_diff(array_keys($row),self::COLUMNS))throw new RuntimeException('ردیف '.$line.': نام ستون ناشناخته است؛ از فایل خروجی همین بخش استفاده کنید.');
            if(!is_string($row['revision']??null)||!hash_equals(self::revision($project,$known[$id]),$row['revision']))throw new RuntimeException('ردیف '.$line.': وضعیت محصول پس از خروجی تغییر کرده؛ خروجی جدید بگیرید.');
            $image=self::option($row,'image_requirement',['keep','required','not_required']);
            $chart=self::option($row,'chart_requirement',['keep','inherit','not_required']);
            $issue=self::option($row,'issue',['keep','none','technical','inventory']);
            $request=self::option($row,'content_request',['keep','request']);
            $note=$row['note']??null;if($note!==null&&(!is_string($note)||mb_strlen($note)>1000))throw new RuntimeException('ردیف '.$line.': توضیح باید متن و حداکثر ۱۰۰۰ نویسه باشد.');
            if($request==='request'&&!$canRequest)throw new RuntimeException('اجازهٔ ثبت درخواست تولید برای این پروژه ندارید.');
            $checked[]=[$id,$image,$chart,$issue,$request,$note];
        }
        $working=$project;$updated=[];$changes=[];$skipped=0;
        foreach($checked as [$id,$image,$chart,$issue,$request,$note]) {
            $original=$known[$id];$meta=$original['website_sync']??[];if(is_string($meta))$meta=json_decode($meta,true)?:[];
            $product=$original;$labels=[];
            if($image!=='keep'&&($image==='not_required')!==ImageProduction::imageExempt($original)){$product=WebsiteCatalogSync::setContentPreference($working,$id,$image,$actor);$labels[]=$image==='not_required'?'عکس نیاز ندارد':'عکس نیاز دارد';}
            if($chart!=='keep'&&($chart==='not_required')!==!empty($meta['content_preferences']['chart_not_required'])){$product=WebsiteCatalogSync::setContentPreference($working,$id,$chart==='inherit'?'required':'not_required',$actor,'chart');$labels[]=$chart==='not_required'?'جدول سایز نیاز ندارد':'جدول سایز تابع قانون دسته‌بندی';}
            $currentIssue=ImageProduction::issueType($original)?:'none';$targetIssue=$issue==='keep'?$currentIssue:$issue;
            if($targetIssue!==$currentIssue||($targetIssue!=='none'&&$note!==null&&trim($note)!==($meta['review_issue']['note']??''))) {
                $product=WebsiteCatalogSync::setIssue($working,$id,$targetIssue,$note??($meta['review_issue']['note']??''),$actor);$labels[]=['none'=>'رفع مشکل','technical'=>'ارجاع به بررسی فنی','inventory'=>'مشکل موجودی'][$targetIssue];if($targetIssue!=='none')$labels[]='توضیح بررسی: '.trim($note??($meta['review_issue']['note']??''));
            }
            if($request==='request') {
                $result=WebsiteCatalogSync::bulkManage($working,[$id],'content_request',$actor);
                if($result['updated']){$product=$result['products'][0];$labels[]='درخواست تولید';}else $skipped++;
            }
            if($labels){$updated[]=$product;$changes[]=['id'=>$id,'sku'=>(string)($product['sku']??''),'name'=>$product['name']??'','fields'=>$labels,'before'=>WebsiteCatalogSync::state($project,$original)['publication'],'after'=>WebsiteCatalogSync::state($working,$product)['publication']];}
        }
        return ['products'=>$updated,'changes'=>$changes,'updated'=>count($updated),'unchanged'=>count($rows)-count($updated),'skipped_requests'=>$skipped];
    }
}
