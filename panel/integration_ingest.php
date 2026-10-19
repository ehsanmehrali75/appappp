<?php
require_once __DIR__.'/inc/ai/Bootstrap.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')AiTools::respond(['error'=>'POST required'],405);
try{$store=new AiStore();$hub=new AiHub($store);$source=$hub->credentials(AiTools::bearer());}catch(Throwable $e){AiTools::respond(['error'=>'invalid_token'],401);}
try{$body=AiTools::jsonBody(4500000);if(isset($body['project'])||isset($body['project_id']))throw new RuntimeException('پروژه از توکن منبع تعیین می‌شود.');$result=AiAccess::run($source['principal'],static function($project)use($hub,$source,$body){return $hub->ingest($project,$source,$body['kind']??'',$body['rows']??null,$body['batch_id']??'',!empty($body['complete']));});AiTools::respond($result);}catch(Throwable $e){AiTools::respond(['error'=>$e instanceof RuntimeException?$e->getMessage():'دریافت داده انجام نشد.'],422);}
