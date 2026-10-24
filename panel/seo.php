<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/ModuleProjectAccess.php';
header('Cache-Control: no-store');
if(!isUser())redirect('/?action=login');
$project=ModuleProjectAccess::select($_GET['project']??$_SESSION['project_id']??'');
if(!canAccessFeature('seo_classic','view'))AccessControl::deny();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){RequestSecurity::enforce();http_response_code(410);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>'مسیر قدیمی سئو بازنشسته شده است؛ از مرکز اتصال و تحلیل دادهٔ واقعی استفاده کنید.'],JSON_UNESCAPED_UNICODE);exit;}
redirect('/panel/insights.php?'.http_build_query(['project'=>$project['id'],'domain'=>'seo']));
