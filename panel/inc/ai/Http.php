<?php
class AiHttp {
    public static function json($url,$payload,$headers=[]) {
        if(!function_exists('curl_init'))throw new RuntimeException('افزونهٔ cURL روی هاست لازم است.');
        $allowed=['backend.composio.dev','api.openai.com','payment.zarinpal.com','sandbox.zarinpal.com'];$p=parse_url($url);
        if(($p['scheme']??'')!=='https'||!in_array($p['host']??'',$allowed,true))throw new RuntimeException('مقصد سرویس مجاز نیست.');
        $body='';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json'],$headers),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>24,CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>3*1024*1024)return 0;$body.=$part;return strlen($part);}]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false)throw new RuntimeException('ارتباط سرویس قطع شد؛ وضعیت مصرف باید بررسی شود.');
        $decoded=json_decode($body,true);return ['status'=>$status,'body'=>is_array($decoded)?$decoded:[]];
    }
    public static function image($url) {
        $p=is_string($url)?parse_url($url):false;
        if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||($p['port']??443)!==443||strlen($url)>8000)throw new RuntimeException('لینک تصویر باید HTTPS عمومی باشد.');
        if(!function_exists('curl_init'))throw new RuntimeException('cURL برای دریافت فایل لازم است.');
        $ips=gethostbynamel($p['host'])?:[];
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('مقصد خصوصی مجاز نیست.');
        if(!$ips)throw new RuntimeException('آدرس فایل قابل شناسایی نیست.');
        $bytes='';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_RESOLVE=>[$p['host'].':443:'.$ips[0]],CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$bytes){if(strlen($bytes)+strlen($part)>8*1024*1024)return 0;$bytes.=$part;return strlen($part);}]);$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false||$status!==200)throw new RuntimeException('دریافت فایل ناموفق بود؛ لینک مستقیم تازه یا بارگذاری دستی استفاده کنید.');return $bytes;
    }
}
