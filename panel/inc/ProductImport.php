<?php
require_once __DIR__.'/InventoryVersions.php';
/** Complete snapshots are normalized once; only the resulting products are committed in batches. */
class ProductImport {
    private static function directory() {
        $dir=getenv('MAARKSHO_IMPORT_STATE_DIR') ?: sys_get_temp_dir().'/maarksho-import-'.substr(hash('sha256',__DIR__),0,16);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('فضای موقت واردسازی قابل نوشتن نیست.');
        return $dir;
    }
    private static function hash($value) { return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    private static function write($file,$value) {
        if(!AccessControl::writeJson($file,$value))throw new RuntimeException('ذخیرهٔ وضعیت واردسازی ناموفق بود؛ فضای دیسک را بررسی کنید.');
    }
    public static function run($project,$owner,$body) {
        if(!isSqlActive())throw new RuntimeException('واردسازی مرحله‌ای به پایگاه دادهٔ SQL نیاز دارد.');
        InventoryVersions::init();
        $action=$body['action']??'';$pid=$project['id'];$dir=self::directory();
        $lock=fopen($dir.'/'.hash('sha256',$pid).'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('واردسازی دیگری در حال پردازش است؛ چند ثانیه بعد ادامه دهید.');
        try {
            if($action==='begin') {
                foreach(new DirectoryIterator($dir) as $old) {
                    if(!$old->isDir()||!preg_match('/^[a-f0-9]{64}$/D',$old->getFilename()))continue;
                    $oldState=json_decode(@file_get_contents($old->getPathname().'/state.json')?:'null',true);
                    if($oldState&&($oldState['expires']??PHP_INT_MAX)<time()) {foreach(glob($old->getPathname().'/*.json') as $file)@unlink($file);@rmdir($old->getPathname());break;}
                }
                $count=$body['chunks']??0;
                if(!is_int($count)||$count<1||$count>1000)throw new RuntimeException('تعداد بخش‌ها معتبر نیست.');
                $job=bin2hex(random_bytes(32));
                $state=['recovery_revision'=>(int)($project['settings']['data_recovery_revision']??0),'job'=>$job,'project'=>$pid,'owner'=>$owner,'expires'=>time()+86400,'chunks'=>$count,'uploaded'=>0,'rows'=>0,'bytes'=>0,'mode'=>($body['mode']??'')==='sportland'?'sportland':'standard','state'=>'uploading','cursor'=>0];
                $path=$dir.'/'.$job;mkdir($path,0700);self::write($path.'/state.json',$state);
            } else {
                $job=$body['job']??'';
                if(!is_string($job)||!preg_match('/^[a-f0-9]{64}$/D',$job))throw new RuntimeException('شناسهٔ واردسازی معتبر نیست.');
                $path=$dir.'/'.$job;$state=json_decode(@file_get_contents($path.'/state.json')?:'null',true);
                if(!$state||$state['project']!==$pid||!hash_equals($state['owner'],$owner)||$state['expires']<time())throw new RuntimeException('واردسازی منقضی شده یا متعلق به این نشست نیست.');
                if((int)($state['recovery_revision']??0)!==(int)($project['settings']['data_recovery_revision']??0))throw new RuntimeException('پروژه بازیابی شده است؛ واردسازی قبلی ادامه پیدا نمی‌کند. پیش‌نمایش جدید بگیرید.');
                if($action==='upload') {
                    if($state['state']!=='uploading')throw new RuntimeException('مرحلهٔ ارسال پایان یافته است.');
                    $index=$body['index']??-1;$rows=$body['rows']??null;
                    if(!is_int($index)||$index<0||$index>=$state['chunks']||!is_array($rows)||!count($rows)||count($rows)>100||array_keys($rows)!==range(0,count($rows)-1))throw new RuntimeException('بخش ارسالی معتبر نیست.');
                    foreach($rows as $row)if(!is_array($row)||isset($row['id'])||isset($row['products']))throw new RuntimeException('این مسیر فقط برای ردیف‌های موجودی ERP است؛ فایل پشتیبان را از بخش بازیابی وارد کنید.');
                    if(WebsiteCatalogSync::looksLikeWebsite($rows))throw new RuntimeException('خروجی وب‌سایت را از بخش تطبیق وارد کنید.');
                    $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    if(strlen($json)>512*1024)throw new RuntimeException('حجم این بخش بیش از حد مجاز است.');
                    $file=$path.'/input-'.$index.'.json';
                    if(is_file($file)&&$index<$state['uploaded']) { if(!hash_equals(self::hash(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)),self::hash($rows)))throw new RuntimeException('محتوای بخش تکراری تغییر کرده است.'); }
                    else {
                        if($index!==$state['uploaded']||$state['rows']+count($rows)>50000||$state['bytes']+strlen($json)>32*1024*1024)throw new RuntimeException('ترتیب یا حجم فایل معتبر نیست (حداکثر ۵۰ هزار ردیف و ۳۲ مگابایت).');
                        self::write($file,$rows);$state['uploaded']++;$state['rows']+=count($rows);$state['bytes']+=strlen($json);
                    }
                } elseif($action==='prepare'&&$state['state']==='uploading') {
                    if($state['uploaded']!==$state['chunks'])throw new RuntimeException('ارسال فایل هنوز کامل نشده است.');
                    $rows=[];for($i=0;$i<$state['chunks'];$i++)foreach(json_decode(file_get_contents($path.'/input-'.$i.'.json'),true,512,JSON_THROW_ON_ERROR) as $row)$rows[]=$row;
                    $base=[];foreach($project['products']??[] as $p)$base[(string)$p['id']]=self::hash($p);
                    $beforeProject=$project;
                    $result=processAndSyncProducts($project,$rows,'json',$state['mode']);
                    $products=array_values($project['products']??[]);$state['total']=count($products);$state['summary']=['new'=>$result['new']??0,'updated'=>$result['updated']??0];
                    $version=InventoryVersions::prepare($beforeProject,'erp',$job,$products);$products=array_values($version['payload']['planned']);$state['total']=count($products);$state['run_id']=$job;$state['version_summary']=InventoryVersions::changes($pid,$job);
                    foreach(array_chunk($products,50) as $i=>$batch)self::write($path.'/batch-'.$i.'.json',$batch);
                    self::write($path.'/base.json',$base);$state['state']='prepared';if(!$products){InventoryVersions::record($pid,$job,[],true);$state['state']='done';}
                } elseif($action==='cancel'){
                    if(!empty($state['run_id']))InventoryVersions::cancel($pid,$state['run_id']);$state['state']='cancelled';
                } elseif($action==='apply'||$action==='status') {
                    if(!empty($state['run_id'])){$version=InventoryVersions::read($pid,$state['run_id']);if(in_array($version['state'],['rolled_back','cancelled','stopped'],true)){$state['state']='cancelled';self::write($path.'/state.json',$state);return ['job'=>$job,'state'=>'cancelled','run_id'=>$state['run_id']];}}
                    $conn=getConn();
                    if(!$conn->query('CREATE TABLE IF NOT EXISTS product_import_receipts (job VARCHAR(64) NOT NULL, batch_no INTEGER NOT NULL, PRIMARY KEY(job,batch_no))'))throw new RuntimeException('ایجاد وضعیت پایگاه داده ناموفق بود.');
                    $stmt=$conn->prepare('SELECT batch_no FROM product_import_receipts WHERE job = ? ORDER BY batch_no DESC LIMIT 1');$stmt->bind_param('s',$job);$stmt->execute();$receipt=$stmt->get_result()->fetch_assoc();$stmt->close();
                    $state['cursor']=$receipt?(int)$receipt['batch_no']+1:0;
                    if(isset($state['total'])&&$state['cursor']>=ceil($state['total']/50))$state['state']='done';
                    if($action==='apply'&&in_array($state['state'],['prepared','applying'],true)) {
                        $batch=json_decode(file_get_contents($path.'/batch-'.$state['cursor'].'.json'),true,512,JSON_THROW_ON_ERROR);
                        $base=json_decode(file_get_contents($path.'/base.json'),true,512,JSON_THROW_ON_ERROR);
                        if(!$conn->begin_transaction())throw new RuntimeException('شروع تراکنش ناموفق بود.');
                        try {
                            InventoryVersions::lockProject($pid);
                            $fresh=getCurrentProject($pid,true);if((int)($state['recovery_revision']??0)!==(int)($fresh['settings']['data_recovery_revision']??0))throw new RuntimeException('پروژه بازیابی شده است؛ پیش‌نمایش جدید بگیرید.');$current=[];foreach($fresh['products']??[] as $p)$current[(string)$p['id']]=$p;
                            foreach($batch as $p) {
                                $id=(string)$p['id'];
                                if(($base[$id]??null)!==(isset($current[$id])?self::hash($current[$id]):null))throw new RuntimeException('یکی از محصولات حین واردسازی ویرایش شده است. برای حفظ تغییرات، فایل را دوباره پیش‌نمایش کنید.');
                            }
                            if(!db_save_project($fresh,$batch,null,false))throw new RuntimeException('ثبت بخش محصولات ناموفق بود.');
                            $stmt=$conn->prepare('INSERT INTO product_import_receipts (job,batch_no) VALUES (?,?)');$cursor=$state['cursor'];$stmt->bind_param('si',$job,$cursor);if(!$stmt->execute())throw new RuntimeException('ثبت رسید ناموفق بود.');$stmt->close();
                            $persisted=getCurrentProject($pid,true);$ids=array_column($batch,'id');$saved=array_values(array_filter($persisted['products']??[],static fn($p)=>in_array($p['id'],$ids,true)));
                            InventoryVersions::record($pid,$state['run_id'],$saved,$state['cursor']+1>=ceil($state['total']/50));
                            if(!$conn->commit())throw new RuntimeException('ثبت تراکنش ناموفق بود.');
                        } catch(Throwable $e) {$conn->rollback();throw $e;}
                        $state['cursor']++;$state['state']=$state['cursor']>=ceil($state['total']/50)?'done':'applying';
                    }
                } elseif($action!=='prepare')throw new RuntimeException('عملیات معتبر نیست.');
                self::write($path.'/state.json',$state);
            }
            return array_intersect_key($state,array_flip(['job','state','uploaded','chunks','rows','cursor','total','summary','run_id','version_summary']));
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
