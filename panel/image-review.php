<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/ModuleProjectAccess.php';
require_once __DIR__.'/inc/PortalAccess.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(!isUser())AccessControl::deny();
ModuleProjectAccess::mutation();
$action=$_POST['action']??'';
if(!is_string($action)||!in_array($action,['image_review_state','review_image'],true)||!PortalAccess::canAction($action))AccessControl::deny();
$project=ModuleProjectAccess::select($_SESSION['project_id']??'');
require __DIR__.'/inc/ProductImageReviewEndpoint.php';
