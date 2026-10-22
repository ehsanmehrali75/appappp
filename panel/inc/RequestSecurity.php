<?php
/** Shared protection for session-authenticated HTML applications, not agent/webhook APIs. */
class RequestSecurity {
    public static function token() {
        if(empty($_SESSION['app_csrf']))$_SESSION['app_csrf']=bin2hex(random_bytes(32));
        return $_SESSION['app_csrf'];
    }
    public static function valid($token) {
        return is_string($token)&&$token!==''&&!empty($_SESSION['app_csrf'])&&hash_equals($_SESSION['app_csrf'],$token);
    }
    public static function sameOrigin($url) {
        if(!is_string($url)||preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/',$url))return false;
        if($url==='')return true;
        $parts=parse_url($url);if(!$parts)return false;
        if(!isset($parts['host']))return !isset($parts['scheme'])&&strpos($url,'//')!==0;
        $secure=($_SERVER['HTTPS']??'')==='on'||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
        $expected=parse_url(($secure?'https':'http').'://'.($_SERVER['HTTP_HOST']??''));
        return $expected&&strtolower($parts['host'])===strtolower($expected['host']??'')
            &&strtolower($parts['scheme']??'')===($secure?'https':'http')
            &&($parts['port']??($secure?443:80))===($expected['port']??($secure?443:80));
    }
    public static function reject($message='درخواست معتبر نیست؛ صفحه را تازه کنید و دوباره تلاش کنید.') {
        http_response_code(403);header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','success'=>false,'msg'=>$message,'error'=>$message],JSON_UNESCAPED_UNICODE);exit;
    }
    public static function enforce() {
        if(in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','OPTIONS'],true))return;
        if(isset($_SERVER['HTTP_ORIGIN'])&&!self::sameOrigin($_SERVER['HTTP_ORIGIN']))self::reject();
        $token=$_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['app_csrf']??'');
        $valid=self::valid($token);
        // Existing independently protected workflows retain their established contracts.
        foreach(['website_catalog_csrf'=>'csrf','support_csrf'=>'csrf','access_csrf'=>'access_csrf'] as $session=>$field) {
            $value=$_POST[$field]??null;
            if(is_string($value)&&$value!==''&&!empty($_SESSION[$session])&&hash_equals($_SESSION[$session],$value))$valid=true;
        }
        if(!$valid)self::reject();
    }
    public static function protectBrowser() {
        if(PHP_SAPI==='cli')return;
        $path=str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']??'');
        if(!in_array(basename($path),['workspace.php','backups.php','onboarding.php','insights.php','data-hub.php','ai-connect.php','admin.php','portal.php','task_manager.php','prompthub.php','system-admin-setup.php','support.php','sale.php','seo.php','wp-crm.php','chat.php','user_chat.php'],true)
            &&!preg_match('~/(?:size|vm|seo_intel|pro)/index\.php$~',$path))return;
        self::token();self::enforce();
        if(!empty($_FILES)) {
            require_once __DIR__.'/MediaUploads.php';
            try {MediaUploads::validateRequest($_FILES,$path);}
            catch(RuntimeException $e){self::reject($e->getMessage());}
        }
        ob_start([self::class,'decorateHtml']);
    }
    /** Find a tag boundary without treating a quoted > as the end of the tag. */
    private static function tagEnd($html,$start) {
        $length=strlen($html);$cursor=$start+1;
        while($cursor<$length) {
            $cursor+=strcspn($html,"\"'>",$cursor);
            if($cursor>=$length)return null;
            if($html[$cursor]==='>')return $cursor+1;
            $quote=$html[$cursor];$close=strpos($html,$quote,$cursor+1);
            if($close===false)return null;
            $cursor=$close+1;
        }
        return null;
    }
    private static function postForm($tag,$requirePost=true) {
        $attributes=[];
        preg_match_all('~\s([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~',$tag,$matches,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
        foreach($matches as $attribute) {
            $name=strtolower($attribute[1]);
            // Browsers use the first occurrence of a duplicate attribute.
            if(!array_key_exists($name,$attributes))$attributes[$name]=html_entity_decode($attribute[2]??$attribute[3]??$attribute[4]??'',ENT_QUOTES,'UTF-8');
        }
        return (!$requirePost||strtolower($attributes['method']??'get')==='post')&&self::sameOrigin($attributes['action']??'');
    }
    public static function decorateHtml($html) {
        foreach(headers_list() as $header)if(stripos($header,'Content-Type:')===0&&stripos($header,'text/html')===false)return $html;
        if(stripos($html,'<html')===false)return $html;
        $token=htmlspecialchars(self::token(),ENT_QUOTES,'UTF-8');
        $context=htmlspecialchars($_SESSION['project_id']??'',ENT_QUOTES,'UTF-8');
        $assets='<meta name="project-context" content="'.$context.'"><meta name="app-csrf" content="'.$token.'"><script src="/panel/assets/request-security.js?v=2"></script>';
        $hidden='<input type="hidden" name="_project_context" value="'.$context.'"><input type="hidden" name="app_csrf" value="'.$token.'">';
        $parts=[];$offset=0;$copied=0;$headAdded=false;
        // Match only small opening tokens. A whole-page .*? regex can exhaust
        // pcre.backtrack_limit on the multi-megabyte product JSON and erase output.
        while(preg_match('~<!--|<(script|style|textarea|template)\b|<form\b|</head\b~i',$html,$match,PREG_OFFSET_CAPTURE,$offset)===1) {
            $start=$match[0][1];$opening=$match[0][0];
            if($opening==='<!--') {
                $close=strpos($html,'-->',$start+4);
                if($close===false)break;
                $offset=$close+3;continue;
            }
            $end=self::tagEnd($html,$start);if($end===null)break;
            $rawTag=$match[1][0]??'';
            if($rawTag!=='') {
                if(preg_match('~</'.preg_quote($rawTag,'~').'(?=[\s/>])~i',$html,$close,PREG_OFFSET_CAPTURE,$end)!==1)break;
                $closeEnd=self::tagEnd($html,$close[0][1]);if($closeEnd===null)break;
                $offset=$closeEnd;continue;
            }
            if(strcasecmp($opening,'</head')===0&&!$headAdded) {
                $parts[]=substr($html,$copied,$start-$copied);$parts[]=$assets;
                $copied=$start;$headAdded=true;
            } elseif(strcasecmp($opening,'<form')===0&&self::postForm(substr($html,$start,$end-$start),false)) {
                $parts[]=substr($html,$copied,$end-$copied);$parts[]=self::postForm(substr($html,$start,$end-$start))?$hidden:'<input type="hidden" name="_project_context" value="'.$context.'">';$copied=$end;
            }
            $offset=$end;
        }
        $parts[]=substr($html,$copied);
        $html=implode('',$parts);
        if(function_exists('isUser')&&isUser()&&in_array(basename($_SERVER['SCRIPT_FILENAME']??''),['admin.php','portal.php'],true)){
            require_once __DIR__.'/WorkspaceView.php';
            $toolbar='<link rel="stylesheet" href="/panel/assets/workspace-shell.css?v='.filemtime(__DIR__.'/../assets/workspace-shell.css').'">'.WorkspaceView::toolbar($hidden);
            $html=preg_replace_callback('~(<body\b[^>]*>)~i',static fn($m)=>$m[1].$toolbar,$html,1);
        }
        return $html;
    }
}
