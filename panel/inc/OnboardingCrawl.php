<?php
require_once __DIR__.'/ai/Store.php';
/** Initial crawl observations wait for the existing website-import preview/confirmation. */
class OnboardingCrawl {
    private static function store($pid){return new AiStore(dirname(__DIR__).'/db/onboarding-crawl/'.hash('sha256',(string)$pid));}
    public static function rows($pid){$state=self::store($pid)->read();return array_values($state['rows']??[]);}
    public static function collect($pid,$url,$product){
        $code=trim((string)($product['sku']??''));if($code===''&&preg_match('~/product/([0-9]+)(?:/|$)~',$url,$m))$code=$m[1];
        if($code==='')throw new RuntimeException('کد محصول در صفحه پیدا نشد؛ خروجی اپ دسکتاپ را بررسی کنید.');
        $row=['url'=>$url,'product_code'=>$code,'name'=>$product['name']??'','main_image'=>$product['image']??null,'gallery'=>$product['gallery']??[],'description'=>$product['description']??'','price'=>$product['price']??null,'sizes'=>$product['sizes']??[],'color'=>$product['color']??null,'size_guide'=>$product['sizeChart']??$product['size_chart']??null,'category'=>$product['category']??[]];
        return self::store($pid)->transaction(function(&$s)use($url,$row){$s['rows'][hash('sha256',$url)]=$row;if(count($s['rows'])>50000||strlen(json_encode(array_values($s['rows']),JSON_UNESCAPED_UNICODE))>10*1024*1024)throw new RuntimeException('حجم خروجی اولیه به سقف ده مگابایت رسید؛ خروجی را دریافت کنید.');$s['updated_at']=time();return count($s['rows']);});
    }
    /** Public destinations only, with DNS pinning and bounded redirects/body for self-service crawls. */
    public static function fetch($url) {
        static $pages=[];if(isset($pages[$url]))return $pages[$url];$original=$url;$deadline=microtime(true)+15;
        for($redirect=0;$redirect<3;$redirect++){
            $p=parse_url($url);
            if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($p['scheme']??'',['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&!in_array($p['port'],[80,443],true))throw new RuntimeException('مقصد کراول باید یک وب‌سایت عمومی باشد.');
            $ips=gethostbynamel($p['host'])?:[];if(!$ips)throw new RuntimeException('DNS وب‌سایت پاسخ نداد.');
            foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('دریافت از شبکهٔ خصوصی مجاز نیست.');
            $remaining=(int)ceil($deadline-microtime(true));if($remaining<1)throw new RuntimeException('مهلت دریافت سایت به پایان رسید.');
            $port=$p['port']??($p['scheme']==='https'?443:80);$location='';$body='';$ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>min(5,$remaining),CURLOPT_TIMEOUT=>$remaining,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Maarksho-Setup-Crawler/1.0',CURLOPT_RESOLVE=>[$p['host'].':'.$port.':'.$ips[0]],CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>3*1024*1024)return 0;$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if($ok===false)throw new RuntimeException('دریافت سایت قطع شد یا از مهلت/حجم مجاز گذشت.');
            if($code>=300&&$code<400&&$location!==''){$origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');$url=preg_match('~^https?://~i',$location)?$location:(strpos($location,'//')===0?$p['scheme'].':'.$location:$origin.(strpos($location,'/')===0?$location:rtrim(dirname($p['path']??'/'),'/').'/'.$location));continue;}
            if($code<200||$code>=300)throw new RuntimeException('وب‌سایت به درخواست کراول پاسخ موفق نداد؛ از اپ دسکتاپ استفاده کنید.');$pages[$original]=$body;return $body;
        }
        throw new RuntimeException('تعداد انتقال‌های آدرس سایت بیش از حد است.');
    }
    public static function purge($pid){$dir=dirname(__DIR__).'/db/onboarding-crawl/'.hash('sha256',(string)$pid);if(is_dir($dir)){foreach(glob($dir.'/*')?:[] as $file)if(is_file($file)&&!is_link($file))unlink($file);@unlink($dir.'/.htaccess');@rmdir($dir);}}
}
