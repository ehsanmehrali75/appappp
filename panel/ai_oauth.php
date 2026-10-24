<?php
require_once __DIR__.'/inc/ai/Bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
$base=AiOAuth::base();$action=$_GET['action']??'';
if($action==='metadata')AiTools::respond(['issuer'=>$base,'authorization_endpoint'=>$base.'/panel/ai_oauth.php?action=authorize','token_endpoint'=>$base.'/panel/ai_oauth.php?action=token','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'token_endpoint_auth_methods_supported'=>['client_secret_post','client_secret_basic','none'],'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>['project.read','content.write','reports.write','datasets.write','social.write','website.write']]);
if($action==='resource')AiTools::respond(['resource'=>$base.'/panel/ai_mcp.php','authorization_servers'=>[$base],'bearer_methods_supported'=>['header'],'scopes_supported'=>['project.read','content.write','reports.write','datasets.write','social.write','website.write'],'resource_name'=>'Maarksho project data']);
$store=new AiStore();$oauth=new AiOAuth($store);
if($action==='token'){
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')AiTools::respond(['error'=>'invalid_request'],405);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{$input=$_POST;foreach($input as $v)if(!is_string($v))throw new RuntimeException('invalid_request');
        $auth=$_SERVER['HTTP_AUTHORIZATION']??'';if(strpos($auth,'Basic ')===0){$decoded=base64_decode(substr($auth,6),true);if(!$decoded||strpos($decoded,':')===false)throw new RuntimeException('invalid_client');[$cid,$secret]=explode(':',$decoded,2);$input['client_id']=urldecode($cid);$input['client_secret']=urldecode($secret);}
        if(isset($input['resource'])&&$input['resource']!==$base.'/panel/ai_mcp.php')throw new RuntimeException('invalid_target');AiTools::respond($oauth->exchange($input));
    }catch(Throwable $e){AiTools::respond(['error'=>in_array($e->getMessage(),['invalid_client','invalid_grant','unsupported_grant_type','invalid_target'],true)?$e->getMessage():'invalid_request'],400);}
}
if($action!=='authorize'){http_response_code(404);exit;}
$error='';$request=[];$client=null;
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){RequestSecurity::enforce();$nonce=$_POST['consent_id']??'';$request=$_SESSION['ai_consents'][$nonce]??[];if(!$request)throw new RuntimeException('درخواست منقضی شده؛ اتصال را دوباره آغاز کنید.');}
    else{$request=$_GET;unset($request['action']);foreach($request as $v)if(!is_string($v))throw new RuntimeException('درخواست معتبر نیست.');}
    $client=$oauth->request($request);
    if(isUser()){
        $projects=ModuleProjectAccess::visibleProjects(getDB()['projects']??[],'products','products');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $project=ModuleProjectAccess::select($_POST['project']??'');if(!AiAccess::pageAllowed())throw new RuntimeException('مجوز اتصال این پروژه فعال نیست.');$principal=AiAccess::principal($project);$scopes=AiAccess::scopes($request['scope']??'project.read');
            $code=$oauth->authorize($request,$principal,$scopes);unset($_SESSION['ai_consents'][$nonce]);$sep=strpos($request['redirect_uri'],'?')===false?'?':'&';header('Location: '.$request['redirect_uri'].$sep.http_build_query(['code'=>$code,'state'=>$request['state']]));exit;
        }
    }
    $nonce=bin2hex(random_bytes(16));$_SESSION['ai_consents']=array_slice($_SESSION['ai_consents']??[],-9,null,true);$_SESSION['ai_consents'][$nonce]=$request;
}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'درخواست معتبر نیست.';http_response_code(400);}
function ai_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اجازه اتصال ChatGPT</title><link rel="stylesheet" href="/panel/assets/ai-connect.css"></head><body><main class="ai-shell narrow"><section class="ai-card"><h1>اجازهٔ اتصال به پروژه</h1><?php if($error):?><p role="alert"><?=ai_h($error)?></p><?php elseif(!isUser()):?><p>ابتدا در پنل وارد شوید، سپس درخواست اتصال را دوباره از ChatGPT آغاز کنید.</p><a href="/panel/">ورود به پنل</a><?php else:?><p>اپ «<?=ai_h($client['name'])?>» فقط به پروژه‌ای که انتخاب می‌کنید و مجوزهای فعلی حساب شما دسترسی خواهد داشت.</p><p>دسترسی درخواستی: <b dir="ltr"><?=ai_h($request['scope']??'project.read')?></b></p><p class="ai-muted">مقصد بازگشت: <span dir="ltr"><?=ai_h($request['redirect_uri'])?></span></p><form method="post"><input type="hidden" name="app_csrf" value="<?=ai_h(RequestSecurity::token())?>"><input type="hidden" name="consent_id" value="<?=ai_h($nonce)?>"><label>پروژه<select required name="project"><?php foreach($projects as $p):?><option value="<?=ai_h($p['id'])?>"><?=ai_h($p['name'])?></option><?php endforeach;?></select></label><p>این اتصال اشتراک ChatGPT شما را به سایر کاربران واگذار نمی‌کند. هر زمان در پنل می‌توانید دسترسی را لغو کنید.</p><button>اجازه می‌دهم</button> <a href="/panel/ai-connect.php">انصراف</a></form><?php endif;?></section></main></body></html>
