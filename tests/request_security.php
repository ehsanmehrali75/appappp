<?php
require __DIR__.'/../panel/inc/RequestSecurity.php';
$_SESSION=[];$_SERVER['HTTP_HOST']='panel.example:8080';$_SERVER['HTTPS']='off';
$checks=0;
function rsCheck($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach(['','admin.php','/panel/admin.php','http://panel.example:8080/panel/'] as $url)rsCheck(RequestSecurity::sameOrigin($url),'local URL: '.$url);
foreach(['https://evil.example','//evil.example','/\\evil.example','\\\\evil.example',"\nhttps://evil.example",'javascript:alert(1)','https://panel.example:8080/','http://panel.example/'] as $url)rsCheck(!RequestSecurity::sameOrigin($url),'foreign/malformed URL rejected');
foreach([
    '<form method=post action=admin.php>' => true,
    '<form method="post" action="/panel/admin.php">' => true,
    '<form method=POST>' => true,
    '<form method=post action="">' => true,
    '<form method=post action=https://evil.example>' => false,
    '<form method=post action="https://evil.example">' => false,
    '<form method=post action="/\\evil.example">' => false,
    '<form method=post action="&#x2f;&#x5c;evil.example">' => false,
    '<form method=get action=admin.php>' => false,
    '<form data-method=post action=admin.php>' => false,
    '<form method=get method=post>' => false,
] as $form=>$expected){
    $html=RequestSecurity::decorateHtml('<html><head></head><body>'.$form.'</form></body></html>');
    rsCheck((strpos($html,'name="app_csrf"')!==false)===$expected,'form decoration: '.$form);
    rsCheck(strpos($html,'name="app-csrf"')!==false,'page receives token meta');
}
rsCheck(RequestSecurity::valid(RequestSecurity::token()),'session token valid');
rsCheck(!RequestSecurity::valid(['invalid']),'non-string token rejected');
rsCheck(!RequestSecurity::valid(str_repeat('a',64)),'wrong token rejected');
$script='<script>const form="<form method=post action=admin.php>";</script>';
$decorated=RequestSecurity::decorateHtml('<html><head></head><body>'.$script.'<textarea><form method=post></textarea><!-- <form method=post> --></body></html>');
rsCheck(strpos($decorated,$script)!==false,'JavaScript form templates are not rewritten');
rsCheck(strpos($decorated,'name="app_csrf"')===false,'inert text and comments are not forms');
$oldLimit=ini_get('pcre.backtrack_limit');ini_set('pcre.backtrack_limit','1000');
$largeScript='<script>const products='.json_encode(str_repeat('large product payload ',250000)).';const template="<form method=post>";</script>';
$large='<html><head></head><body>'.$largeScript.'<form method=post action="admin.php?x=&gt;"></form><p id="page-end">پایان</p></body></html>';
$largeDecorated=RequestSecurity::decorateHtml($large);
rsCheck(strlen($largeDecorated)>strlen($large),'large page stays complete under a small PCRE limit');
rsCheck(strpos($largeDecorated,$largeScript)!==false,'multi-megabyte product JSON is preserved exactly');
rsCheck(substr_count($largeDecorated,'name="app_csrf"')===1,'real form protected once, script template untouched');
rsCheck(strpos($largeDecorated,'name="app-csrf"')!==false&&strpos($largeDecorated,'id="page-end"')!==false,'large page keeps assets and trailing content');
ini_set('pcre.backtrack_limit',$oldLimit);
$quoted=RequestSecurity::decorateHtml('<html><head></head><body><form method=post action="admin.php?q=>"></form></body></html>');
rsCheck(substr_count($quoted,'name="app_csrf"')===1,'quoted > does not truncate form tag');
echo "$checks request security checks passed\n";
