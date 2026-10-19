<?php
require_once __DIR__.'/Http.php';
class AiContent {
    private $store;
    public function __construct($store){$this->store=$store;}
    public static function find($project,$input) {
        $found=[];foreach($project['products']??[] as $p){
            if(!empty($input['product_id'])&&(string)$p['id']!==(string)$input['product_id'])continue;
            if(!empty($input['sku'])&&WebsiteCatalogSync::code($p['sku']??'')!==WebsiteCatalogSync::code($input['sku']))continue;
            if(isset($input['color'])&&trim((string)$input['color'])!==trim((string)($p['color']??'')))continue;
            if(empty($input['product_id'])&&empty($input['sku']))throw new RuntimeException('شناسه یا کد قطعی محصول لازم است.');$found[]=$p;
        }
        if(count($found)!==1)throw new RuntimeException('محصول یکتا پیدا نشد؛ شناسهٔ دقیق و رنگ را بررسی کنید.');return $found[0];
    }
    public function save($principal,$input) {
        AiAccess::scope($principal,'content.write');AiAccess::write();
        $kind=$input['kind']??'';if(!in_array($kind,['main','gallery','social','size_chart','description'],true))throw new RuntimeException('نوع محتوا معتبر نیست.');
        if($kind==='size_chart')AiAccess::need('size_studio','use');
        $request=$input['request_id']??'';if(!is_string($request)||!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D',$request))throw new RuntimeException('شناسهٔ یکتای درخواست لازم است.');
        $fingerprint=hash('sha256',json_encode($input,JSON_UNESCAPED_UNICODE));$receiptKey=hash('sha256',$principal['owner'].'|'.$request);
        $existing=getCurrentProject($principal['project'],true);if(!$existing)throw new RuntimeException('پروژه یافت نشد.');
        $existingProduct=self::find($existing,$input);$previous=$existingProduct['website_sync']['ai_receipts'][$receiptKey]??null;
        if($previous){if(!hash_equals($previous['hash'],$fingerprint))throw new RuntimeException('شناسهٔ تکراری با محتوای متفاوت فرستاده شده است.');return $previous['result'];}
        $bytes=null;
        if($kind==='description') {if(!is_string($input['text']??null)||trim($input['text'])===''||mb_strlen($input['text'])>30000)throw new RuntimeException('توضیح معتبر حداکثر ۳۰ هزار نویسه لازم است.');}
        else {
            if(isset($input['base64'])){if(!is_string($input['base64'])||strlen($input['base64'])>12*1024*1024)throw new RuntimeException('تصویر بزرگ است.');$bytes=base64_decode($input['base64'],true);}
            elseif(!empty($input['url']))$bytes=AiHttp::image($input['url']);
            elseif(!empty($input['openaiFileIdRefs'][0]['download_link']))$bytes=AiHttp::image($input['openaiFileIdRefs'][0]['download_link']);
            if(!is_string($bytes)||strlen($bytes)>8*1024*1024)throw new RuntimeException('فایل تصویر یا لینک دانلود مستقیم لازم است. مسیر sandbox قابل دریافت نیست.');
            $info=@getimagesizefromstring($bytes);if(!$info||$info[0]*$info[1]>40000000||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new RuntimeException('فقط تصویر واقعی PNG، JPEG یا WebP تا ۴۰ مگاپیکسل مجاز است.');
        }
        $lockDir=sys_get_temp_dir().'/maarksho-website-'.substr(hash('sha256',dirname(__DIR__,2)),0,16);if(!is_dir($lockDir))mkdir($lockDir,0700,true);
        $lock=fopen($lockDir.'/'.hash('sha256',$principal['project']).'.lock','c');flock($lock,LOCK_EX);$file=null;
        try {
            $project=getCurrentProject($principal['project'],true);if(!$project)throw new RuntimeException('پروژه یافت نشد.');$product=self::find($project,$input);
            $receipt=$product['website_sync']['ai_receipts'][$receiptKey]??null;
            if($receipt){if(!hash_equals($receipt['hash'],$fingerprint))throw new RuntimeException('شناسهٔ تکراری با محتوای متفاوت فرستاده شده است.');return $receipt['result'];}
            $url=null;
            if($bytes!==null){$ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'][$info[2]];$dir=dirname(__DIR__,2).'/uploads/';if(!is_dir($dir))mkdir($dir,0755,true);$name='ai_'.bin2hex(random_bytes(20)).'.'.$ext;$file=$dir.$name;if(file_put_contents($file,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('ذخیرهٔ تصویر ناموفق بود.');chmod($file,0644);$url='/panel/uploads/'.$name;}
            if($kind==='description')$product['description']=strip_tags(trim($input['text']));
            elseif($kind==='size_chart'){$product['size_chart']=$url;$product['size_chart_template_id']='';$product['size_chart_uploaded']=0;}
            else {
                $list=$product['images_list']??[];if(!is_array($list))$list=[];
                if($kind==='main'&&!empty($product['image'])&&!in_array($product['image'],array_column($list,'url'),true))$list[]=['url'=>$product['image'],'type'=>'main','source'=>'manual','approved'=>!in_array($product['image'],WebsiteCatalogSync::pendingApprovalPhotos($product),true)];
                $list[]=['url'=>$url,'type'=>$kind,'source'=>'ai','approved'=>false,'uploaded_by'=>$principal['username'],'uploaded_at'=>time()];$product['images_list']=$list;
                if($kind==='main')$product['image']=$url;
                if($kind==='social')$product['social_image']=$url;
                else {$product['status']='pending';$product['uploaded_to_site']=0;}
            }
            $result=['product_id'=>(string)$product['id'],'sku'=>$product['sku']??'','kind'=>$kind,'url'=>$url,'approval_required'=>in_array($kind,['main','gallery','social'],true),'saved'=>true];
            $product['website_sync']['ai_receipts'][$receiptKey]=['hash'=>$fingerprint,'result'=>$result,'at'=>time(),'by'=>$principal['username']];
            if(count($product['website_sync']['ai_receipts'])>100)$product['website_sync']['ai_receipts']=array_slice($product['website_sync']['ai_receipts'],-100,null,true);
            if(!saveProject($project,[$product]))throw new RuntimeException('ثبت محتوای محصول ناموفق بود.');$file=null;
            $this->store->transaction(static function(&$s)use($principal,$result){AiStore::audit($s,$principal,'content_saved',['product'=>$result['product_id'],'kind'=>$result['kind']]);});return $result;
        } finally {if($file&&is_file($file))unlink($file);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function report($project,$principal,$input) {
        AiAccess::scope($principal,'reports.write');AiAccess::write();
        foreach(['title','text','request_id'] as $f)if(!is_string($input[$f]??null)||trim($input[$f])==='')throw new RuntimeException('عنوان، متن و شناسهٔ درخواست گزارش لازم است.');
        if(mb_strlen($input['title'])>200||mb_strlen($input['text'])>80000||strlen($input['request_id'])>100)throw new RuntimeException('گزارش بیش از اندازه بزرگ است.');
        $sources=$input['sources']??[];$data=new AiData($this->store);$known=array_column($data->sources($project),'id');if(!is_array($sources)||!$sources||array_diff($sources,$known))throw new RuntimeException('منابع گزارش را از منابع مجاز همین پروژه انتخاب کنید.');
        $key=hash('sha256',$principal['owner'].'|'.$project['id'].'|'.$input['request_id']);$hash=hash('sha256',json_encode($input));
        return $this->store->transaction(function(&$s)use($project,$principal,$input,$sources,$key,$hash){
            foreach($s['reports']??[] as $id=>$r)if(($r['request_key']??'')===$key){if(!hash_equals($r['hash'],$hash))throw new RuntimeException('درخواست تکراری با متن متفاوت است.');return ['id'=>$id,'saved'=>true];}
            $id=bin2hex(random_bytes(16));$this->store->putDocument($project['id'],$id,['title'=>$input['title'],'text'=>$input['text'],'sources'=>$sources,'origin'=>'chatgpt','generated_at'=>gmdate(DATE_ATOM)]);
            $s['reports'][$id]=['id'=>$id,'project'=>$project['id'],'owner'=>$principal['owner'],'title'=>$input['title'],'sources'=>$sources,'origin'=>'chatgpt','at'=>time(),'request_key'=>$key,'hash'=>$hash];AiStore::audit($s,$principal,'report_saved',['id'=>$id]);return ['id'=>$id,'saved'=>true];
        });
    }
    public function reports($project) {$known=array_column((new AiData($this->store))->sources($project),'id');$s=$this->store->read();return array_values(array_filter($s['reports']??[],static fn($r)=>$r['project']===$project['id']&&!array_diff($r['sources'],$known)));}
    public function getReport($project,$id){foreach($this->reports($project) as $r)if($r['id']===$id)return $this->store->document($project['id'],$id);throw new RuntimeException('گزارش مجاز یافت نشد.');}
}
