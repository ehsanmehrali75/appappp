<?php
/** Shared portal/admin handler; callers must authenticate, authorize and enforce CSRF first. */
if(($_SERVER['HTTP_X_PROJECT_CONTEXT']??$_POST['_project_context']??'')!==($project['id']??null))RequestSecurity::reject('پروژهٔ گالری تغییر کرده است؛ صفحه را تازه کنید.');
$lockDir=sys_get_temp_dir().'/maarksho-website-'.substr(hash('sha256',dirname(__DIR__)),0,16);
if(!is_dir($lockDir))@mkdir($lockDir,0700,true);
$reviewLock=fopen($lockDir.'/'.hash('sha256',(string)$project['id']).'.lock','c');
try {
    if(!$reviewLock||!flock($reviewLock,LOCK_EX))throw new RuntimeException('قفل بررسی تصاویر در دسترس نیست.');
    $project=getCurrentProject($project['id'],true);$found=false;
    foreach($project['products'] as &$reviewProduct)if((string)$reviewProduct['id']===(string)($_POST['id']??'')) {
        $found=true;
        if($_POST['action']==='review_image') {
            if(!in_array($_POST['decision']??'',['approved','rejected'],true))throw new RuntimeException('تصمیم نامعتبر است.');
            ProductImageReview::decide($reviewProduct,$project,$_POST['image_key']??'',(int)($_POST['stage']??0),$_POST['decision']==='rejected',$_POST['reason']??'',$_POST['revision']??'');
            if(!saveProject($project,[$reviewProduct]))throw new RuntimeException('ثبت بررسی ناموفق بود.');
        }
        echo json_encode(['status'=>'success','review'=>ProductImageReview::view($reviewProduct,$project)],JSON_UNESCAPED_UNICODE);break;
    }
    unset($reviewProduct);
    if(!$found)throw new RuntimeException('محصول در این پروژه یافت نشد.');
} catch(RuntimeException $e) {http_response_code(409);echo json_encode(['status'=>'error','msg'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
finally {if($reviewLock){flock($reviewLock,LOCK_UN);fclose($reviewLock);}}

exit;
