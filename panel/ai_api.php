<?php
require_once __DIR__.'/inc/ai/Bootstrap.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if(($_GET['schema']??'')==='openapi')AiTools::respond(AiSchema::openapi());

try{$store=new AiStore();$grant=(new AiOAuth($store))->bearer(AiTools::bearer());}catch(Throwable $e){AiTools::respond(['error'=>'invalid_token'],401);}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')AiTools::respond(['error'=>'POST required'],405);
try{$name=trim($_SERVER['PATH_INFO']??'','/');AiTools::respond(['result'=>(new AiTools($store))->call($grant,$name,AiTools::jsonBody())]);}catch(Throwable $e){AiTools::respond(['error'=>$e instanceof RuntimeException?$e->getMessage():'عملیات انجام نشد.'],400);}
