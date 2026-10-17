<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/ProductImport.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);throw new RuntimeException('POST required');}
    if(!isUser()){http_response_code(401);throw new RuntimeException('ورود لازم است.');}
    RequestSecurity::enforce();
    if((int)($_SERVER['CONTENT_LENGTH']??0)>600*1024){http_response_code(413);throw new RuntimeException('بخش ارسالی بیش از حد بزرگ است.');}
    $raw=file_get_contents('php://input',false,null,0,600*1024+1);
    if(strlen($raw)>600*1024){http_response_code(413);throw new RuntimeException('بخش ارسالی بیش از حد بزرگ است.');}
    $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $id=$body['project']??null;
    if(!is_string($id)||$id==='')throw new RuntimeException('پروژه مشخص نیست.');
    $project=getCurrentProject($id,true);
    if(!$project||!SessionIdentity::select($project)||!canAccessFeature('extract','use')||!canAccessFeature('products','use'))AccessControl::deny();
    $owner=hash('sha256',session_id().'|'.($_SESSION['user']['username']??''));session_write_close();
    echo json_encode(ProductImport::run($project,$owner,$body),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    if(http_response_code()<400)http_response_code(422);
    $msg=$e instanceof RuntimeException?$e->getMessage():'پردازش واردسازی ناموفق بود؛ گزارش سرور را بررسی کنید.';
    if(!($e instanceof RuntimeException))error_log('Product import failed: '.get_class($e).' at '.$e->getFile().':'.$e->getLine());
    echo json_encode(['error'=>$msg],JSON_UNESCAPED_UNICODE);
}
