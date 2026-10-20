<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/TiamInventory.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);throw new RuntimeException('POST required');}
    if(!isUser()){http_response_code(401);throw new RuntimeException('ورود لازم است.');}RequestSecurity::enforce();
    $raw=file_get_contents('php://input',false,null,0,16001);if(strlen($raw)>16000)throw new RuntimeException('درخواست بیش از حد بزرگ است.');$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$pid=$body['project']??'';if(!is_string($pid)||!$pid)throw new RuntimeException('پروژه مشخص نیست.');$project=getCurrentProject($pid,true);if(!$project||!SessionIdentity::select($project)||!canAccessFeature('products','use'))AccessControl::deny();
    $sources=[];if(canAccessFeature('settings','use'))$sources[]='tiam';if(canAccessFeature('extract','use'))$sources[]='erp';if(!$sources)AccessControl::deny();InventoryVersions::init();$action=$body['action']??'';
    if($action==='history'){$result=['runs'=>array_values(array_filter(InventoryVersions::history($pid),static fn($r)=>in_array($r['source'],$sources,true)))];}
    else{$id=$body['run_id']??'';$run=InventoryVersions::read($pid,$id);if(!in_array($run['source'],$sources,true))AccessControl::deny();if($action==='changes')$result=InventoryVersions::changes($pid,$id,$body['page']??1);elseif($action==='rollback')$result=TiamInventory::locked($pid,static function()use($pid,$id){$job=TiamInventory::read($pid);if(in_array($job['state']??'',['queued','running'],true)&&($job['expires']??0)>time())throw new RuntimeException('ابتدا درخواست تیام جاری را لغو کنید.');$r=InventoryVersions::rollback($pid,$id);if(($job['run_id']??'')===$id){$job['rolled_back_at']=time();TiamInventory::write($pid,$job);}return $r;});else throw new RuntimeException('عملیات معتبر نیست.');}
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'عملیات نسخهٔ موجودی ناموفق بود.'],JSON_UNESCAPED_UNICODE);}
