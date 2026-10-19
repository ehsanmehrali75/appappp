<?php
/** Per-project prerequisites are derived from real assets, never a stale has_reference flag. */
class ImageProduction {
    public static function references($product) {
        $urls=[];
        $add=static function($item)use(&$urls){
            $url=ProductSizeChartCatalog::mediaUrl(is_array($item)?($item['url']??$item['src']??$item['image']??''):$item);
            if($url!==''&&(!function_exists('isValidProductImage')||isValidProductImage($url)))$urls[$url]=true;
        };
        foreach(['reference_gallery','reference_images'] as $field) {
            $items=$product[$field]??[];if(is_string($items))$items=json_decode($items,true)?:[];
            if(is_array($items))foreach($items as $item)$add($item);
        }
        $items=$product['images_list']??[];if(is_string($items))$items=json_decode($items,true)?:[];
        if(is_array($items))foreach($items as $item)if(is_array($item)&&in_array($item['type']??$item['image_type']??'', ['reference','ref'],true))$add($item);
        return array_keys($urls);
    }
    public static function issueType($product) {
        $meta=$product['website_sync']??[];if(is_string($meta))$meta=json_decode($meta,true);
        $type=is_array($meta)?($meta['review_issue']['type']??''):'';
        return in_array($type,['technical','inventory'],true)?$type:'';
    }
    public static function imageExempt($product) {
        $meta=$product['website_sync']??[];if(is_string($meta))$meta=json_decode($meta,true);
        return !empty($meta['content_preferences']['image_not_required']);
    }
    public static function state($project,$product,$hasFinal,$archived=false) {
        $referenceRequired=!empty($project['settings']['prod_require_reference']);
        $requestRequired=!empty($project['settings']['prod_require_image_request']);
        $references=self::references($product);$requested=!empty($product['content_gen_requested']);
        $issue=self::issueType($product);$blocked=$issue!=='';
        $exempt=self::imageExempt($product);$eligible=!$archived&&!$hasFinal&&!$blocked&&!$exempt;
        $needsRequest=$eligible&&$requestRequired&&!$requested;
        $needsReference=$eligible&&!$needsRequest&&!$references;
        $ready=$eligible&&!$needsRequest&&(!$referenceRequired||$references);
        $stage=$blocked?($issue==='technical'?'technical_pending':'inventory_issue'):(!$eligible?($archived?'archived':'complete'):($needsRequest?'image_request':($referenceRequired&&!$references?'needs_reference':'pending_image')));
        return ['request_required'=>$requestRequired,'reference_required'=>$referenceRequired,'requested'=>$requested,
            'has_reference'=>(bool)$references,'reference_urls'=>$references,'needs_image_request'=>$needsRequest,
            'needs_reference'=>$needsReference,'reference_ready'=>$eligible&&!$needsRequest&&(bool)$references,
            'image_not_required'=>$exempt,'can_produce_image'=>$ready,'blocked_by_issue'=>$blocked,'stage'=>$stage];
    }
    public static function canRequest($project,$user=null) {
        return self::allowedOperator($project,'prod_content_gen_users',$user);
    }
    public static function canReference($project,$user=null) {
        return self::allowedOperator($project,'prod_ref_image_users',$user);
    }
    private static function allowedOperator($project,$setting,$user) {
        if($user===null&&function_exists('isMainAdmin')&&isMainAdmin())return true;
        $user=$user??($_SESSION['user']??[]);
        if(in_array($user['role']??'',['owner','main_admin','superadmin','project_admin'],true))return true;
        $allowed=array_filter(array_map('trim',preg_split('/[,،\r\n]+/u',mb_strtolower($project['settings'][$setting]??''))));
        return !$allowed||in_array(mb_strtolower($user['username']??''),$allowed,true);
    }
}
