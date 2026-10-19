<?php
require_once __DIR__.'/inc/ai/Bootstrap.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if(($_GET['schema']??'')==='openapi'){
    $scopes=['project.read'=>'Read authorized project data','content.write'=>'Save product content','reports.write'=>'Save analyses','datasets.write'=>'Upload analytical datasets'];$paths=[];
    foreach(AiTools::definitions(['scopes'=>array_keys($scopes)]) as $d){$schema=$d['inputSchema'];$paths['/panel/ai_api.php/'.$d['name']]=['post'=>['operationId'=>$d['name'],'summary'=>$d['description'],'x-openai-isConsequential'=>empty($d['annotations']['readOnlyHint']),'security'=>[['OAuth2'=>array_merge(['project.read'],strpos($d['name'],'save_')===0?[$d['name']==='save_content'?'content.write':($d['name']==='save_report'?'reports.write':'datasets.write')]:[])]],'requestBody'=>['required'=>true,'content'=>['application/json'=>['schema'=>$schema]]],'responses'=>['200'=>['description'=>'Result','content'=>['application/json'=>['schema'=>['type'=>'object','additionalProperties'=>true]]]]]]];}
    AiTools::respond(['openapi'=>'3.1.0','info'=>['title'=>'Maarksho project assistant','version'=>'1.0.0','description'=>'Project-scoped data and confirmed content writes.'],'servers'=>[['url'=>AiOAuth::base()]],'security'=>[['OAuth2'=>array_keys($scopes)]],'components'=>['securitySchemes'=>['OAuth2'=>['type'=>'oauth2','flows'=>['authorizationCode'=>['authorizationUrl'=>AiOAuth::base().'/panel/ai_oauth.php?action=authorize','tokenUrl'=>AiOAuth::base().'/panel/ai_oauth.php?action=token','scopes'=>$scopes]]]]],'paths'=>$paths]);
}
try{$store=new AiStore();$grant=(new AiOAuth($store))->bearer(AiTools::bearer());}catch(Throwable $e){AiTools::respond(['error'=>'invalid_token'],401);}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')AiTools::respond(['error'=>'POST required'],405);
try{$name=trim($_SERVER['PATH_INFO']??'','/');AiTools::respond(['result'=>(new AiTools($store))->call($grant,$name,AiTools::jsonBody())]);}catch(Throwable $e){AiTools::respond(['error'=>$e instanceof RuntimeException?$e->getMessage():'عملیات انجام نشد.'],400);}
