<?php
require_once __DIR__.'/ai/Store.php';
require_once __DIR__.'/WebsiteExportPackage.php';
/** Durable, owner/project-scoped batches. Heavy work never needs the PHP session lock. */
class WebsiteExportInvalid extends RuntimeException {}
class WebsiteExportBatch {
    private $pid,$store,$builder,$uploads;
    public function __construct($pid,$owner,$uploads,$host='',$directory=null,$builder=null){
        if(!is_string($pid)||$pid===''||!is_string($owner)||$owner==='')throw new RuntimeException('مالک خروجی مشخص نیست.');
        $this->pid=$pid;$this->uploads=rtrim($uploads,'/').'/';
        $base=$directory?:getenv('MAARKSHO_EXPORT_STATE_DIR')?:__DIR__.'/../db/website-exports';
        $this->store=new AiStore($base.'/'.hash('sha256',$pid.'|'.$owner));$this->builder=$builder?:new WebsiteExportPackage($uploads,$host);
    }
    private function project($job=null){
        $p=getCurrentProject($this->pid,true);if(!$p)throw new WebsiteExportInvalid('پروژهٔ خروجی موجود نیست.');
        if($job!==null&&(int)($job['epoch']??-1)!==(int)($p['settings']['data_recovery_revision']??0))throw new WebsiteExportInvalid('پروژه بازیابی شده است؛ خروجی تازه ایجاد کنید.');return $p;
    }
    private function job($s,$token){
        if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))throw new WebsiteExportInvalid('شناسهٔ خروجی معتبر نیست.');
        $j=$s['jobs'][$token]??null;if(!$j||$j['expires']<time())throw new WebsiteExportInvalid('خروجی منقضی یا متعلق به نشست و پروژهٔ دیگری است.');return $j;
    }
    private function remove($urls){$ok=true;foreach($urls as $url){$name=basename($url);if(preg_match('/^website_export_[a-f0-9]{32}\.zip$/D',$name)&&is_file($this->uploads.$name)&&!@unlink($this->uploads.$name))$ok=false;}return $ok;}
    public function begin($ids){
        if(!is_array($ids)||!$ids||count($ids)>5000||array_filter($ids,static fn($x)=>!is_string($x)||$x===''))throw new RuntimeException('فهرست انتخاب محصولات معتبر نیست.');
        $p=$this->project();$ids=array_values(array_unique($ids));$known=array_fill_keys(array_column($p['products'],'id'),true);
        if(array_diff_key(array_fill_keys($ids,true),$known))throw new RuntimeException('محصول انتخاب‌شده در همین پروژه موجود نیست.');
        $token=bin2hex(random_bytes(24));
        $out=$this->store->transaction(function(&$s)use($token,$p,$ids){
            foreach($s['jobs']??[] as $key=>$j)if($j['expires']<time()){if($this->remove(array_merge($j['parts']??[],isset($j['result']['zip_url'])?[$j['result']['zip_url']]:[])))unset($s['jobs'][$key]);}
            foreach($s['receipts']??[] as $key=>$r)if($r['expires']<time())unset($s['receipts'][$key]);
            $active=0;foreach($s['jobs']??[] as $j)if(!isset($j['result'])&&$j['expires']>=time())$active++;
            if($active>=8)throw new RuntimeException('هشت خروجی باز دارید؛ ابتدا خروجی‌های قبلی را تمام کنید.');
            $s['jobs'][$token]=['expires'=>time()+3600,'epoch'=>(int)($p['settings']['data_recovery_revision']??0),'ids'=>$ids,'offset'=>0,'parts'=>[],'receipts'=>[],'warnings'=>[]];
            return ['token'=>$token,'total'=>count($ids),'processed'=>0];
        });return $out;
    }
    public function step($token,$expected=null){
        $created=[];
        try{return $this->store->transaction(function(&$s)use($token,$expected,&$created){
            $j=$this->job($s,$token);$p=$this->project($j);
            if($expected!==null&&(!is_int($expected)||$expected<0||$expected>count($j['ids'])))throw new RuntimeException('نقطهٔ ادامهٔ خروجی معتبر نیست.');
            if(!isset($j['result'])&&$j['offset']<count($j['ids'])&&($expected===null||$expected===$j['offset'])){
                $batch=array_slice($j['ids'],$j['offset'],5);if(array_diff($batch,array_column($p['products'],'id')))throw new WebsiteExportInvalid('محصول حذف شده است؛ خروجی تازه بگیرید.');try{$result=$this->builder->build($p,$batch,true);}catch(WebsiteExportBusy $busy){return ['processed'=>$j['offset'],'total'=>count($j['ids']),'done'=>false,'busy'=>true];}
                if($result['zip_url']){$j['parts'][]=$result['zip_url'];$created[]=$result['zip_url'];}
                $j['receipts']+=$result['receipts'];$j['warnings']=array_merge($j['warnings'],$result['warnings']);$j['offset']+=count($batch);$s['jobs'][$token]=$j;
            }
            return ['processed'=>$j['offset'],'total'=>count($j['ids']),'done'=>$j['offset']>=count($j['ids'])];
        });}catch(Throwable $e){$this->remove($created);throw $e;}
    }
    public function finish($token){
        $created=[];$parts=[];
        try{$result=$this->store->transaction(function(&$s)use($token,&$created,&$parts){
            $j=$this->job($s,$token);$p=$this->project($j);if(isset($j['result']))return $j['result'];
            if($j['offset']<count($j['ids']))throw new RuntimeException('ساخت بسته‌ها هنوز تمام نشده است.');
            $products=array_column($p['products'],null,'id');
            foreach($j['receipts'] as $id=>$receipt){
                $product=$products[$id]??null;if(!$product)throw new WebsiteExportInvalid('محصول حذف شده است؛ خروجی تازه بگیرید.');
                $state=WebsiteCatalogSync::state($p,$product);
                if($state['archived']||$state['production_blocked']||$state['image_not_required']||!WebsiteCatalogSync::publishable($product)
                    ||!hash_equals($receipt['all_image_fingerprint'],hash('sha256',json_encode(WebsiteCatalogSync::approvedPhotos($product))))
                    ||!hash_equals($receipt['chart_fingerprint'],WebsiteCatalogSync::chartFingerprint($state['chart']))
                    ||!hash_equals($receipt['description_fingerprint'],hash('sha256',trim((string)($product['description']??'')))))
                    throw new WebsiteExportInvalid('محتوا یا تأیید محصول تغییر کرده است؛ خروجی تازه بگیرید.');
            }
            try{$url=$this->builder->merge($j['parts'],$j['warnings']);}catch(WebsiteExportBusy $busy){return ['busy'=>true];}catch(WebsiteExportSourceInvalid $bad){throw new WebsiteExportInvalid($bad->getMessage());}$created[]=$url;$parts=$j['parts'];
            $result=['zip_url'=>$url,'exported_ids'=>array_map('strval',array_keys($j['receipts'])),'warnings'=>$j['warnings']];
            foreach($j['receipts'] as $id=>$receipt)$s['receipts'][$id]=['expires'=>time()+86400,'epoch'=>$j['epoch'],'assets'=>$receipt];
            $j['result']=$result;$j['expires']=time()+86400;unset($j['receipts']);$s['jobs'][$token]=$j;return $result;
        });}catch(Throwable $e){$this->remove($created);throw $e;}
        $this->remove($parts);return $result;
    }
    public function cancel($token){
        if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return;
        $parts=$this->store->transaction(function(&$s)use($token){$j=$s['jobs'][$token]??null;if(!$j||isset($j['result']))return [];$s['jobs'][$token]['expires']=0;return $j['parts'];});$this->remove($parts);
    }
    public function receipts($project){
        $out=[];foreach($this->store->read()['receipts']??[] as $id=>$r)if($r['expires']>=time()&&(int)$r['epoch']===(int)($project['settings']['data_recovery_revision']??0))$out[$id]=$r['assets'];
        return ['project'=>$this->pid,'expires'=>time()+60,'products'=>$out];
    }
    public function record($project,$products){
        $epoch=(int)($project['settings']['data_recovery_revision']??0);$this->project(['epoch'=>$epoch]);
        $this->store->transaction(function(&$s)use($epoch,$products){foreach($products as $id=>$receipt)$s['receipts'][$id]=['expires'=>time()+86400,'epoch'=>$epoch,'assets'=>$receipt];});
    }
    /** Bounded janitor; skip active builders rather than waiting on their locks. */
    public static function maintain($base=null,$uploads=null,$limit=32){
        if(PHP_SAPI!=='cli'&&!isMainAdmin())throw new RuntimeException('پاک‌سازی خروجی فقط برای worker مجاز است.');
        $base=$base?:getenv('MAARKSHO_EXPORT_STATE_DIR')?:__DIR__.'/../db/website-exports';$uploads=rtrim($uploads?:__DIR__.'/../uploads','/').'/';
        $out=['scanned'=>0,'expired_jobs'=>0,'errors'=>0];if(!is_dir($base))return $out;
        $cursorLock=fopen($base.'/cleanup.lock','c');if(!$cursorLock)throw new RuntimeException('قفل نگهداری خروجی در دسترس نیست.');
        if(!flock($cursorLock,LOCK_EX|LOCK_NB)){fclose($cursorLock);return $out;}
        try{
            $dirs=array_values(array_filter(glob($base.'/*',GLOB_ONLYDIR)?:[],static fn($d)=>(bool)preg_match('/^[a-f0-9]{64}$/D',basename($d))));sort($dirs);if(!$dirs)return $out;
            $cursor=json_decode(@file_get_contents($base.'/cleanup.json')?:'{}',true);$at=array_search($cursor['after']??'',array_map('basename',$dirs),true);$start=$at===false?0:($at+1)%count($dirs);$last='';
            for($i=0;$i<min(count($dirs),max(1,min(100,$limit)));$i++){
                $dir=$dirs[($start+$i)%count($dirs)];$last=basename($dir);$out['scanned']++;$lock=fopen($dir.'/state.lock','c');
                if(!$lock){$out['errors']++;continue;}if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);continue;}
                try{
                    $file=$dir.'/state.json';if(!is_file($file))continue;$s=json_decode(file_get_contents($file),true);if(!is_array($s))throw new RuntimeException('وضعیت خروجی خراب است.');$changed=false;$expired=0;
                    foreach($s['jobs']??[] as $token=>$j)if(($j['expires']??PHP_INT_MAX)<time()){
                        $removed=true;foreach(array_merge($j['parts']??[],isset($j['result']['zip_url'])?[$j['result']['zip_url']]:[]) as $url){$name=basename($url);if(preg_match('/^website_export_[a-f0-9]{32}\.zip$/D',$name)&&is_file($uploads.$name)&&!@unlink($uploads.$name)){$removed=false;$out['errors']++;}}
                        if($removed){unset($s['jobs'][$token]);$changed=true;$expired++;}
                    }
                    foreach($s['receipts']??[] as $id=>$r)if(($r['expires']??PHP_INT_MAX)<time()){unset($s['receipts'][$id]);$changed=true;}
                    if($changed){if(!AccessControl::writeJson($file,$s))throw new RuntimeException('ثبت پاک‌سازی خروجی ناموفق بود.');@chmod($file,0600);$out['expired_jobs']+=$expired;}
                }catch(Throwable $e){$out['errors']++;}finally{flock($lock,LOCK_UN);fclose($lock);}
            }
            if(!AccessControl::writeJson($base.'/cleanup.json',['after'=>$last]))$out['errors']++;return $out;
        }finally{flock($cursorLock,LOCK_UN);fclose($cursorLock);}
    }
}
