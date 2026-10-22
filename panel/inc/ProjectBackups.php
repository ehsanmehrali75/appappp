<?php
/** Project library snapshots. Membership, billing, settings and queues are never restored. */
class ProjectBackups {
    private static function dir($pid){$base=getenv('MAARKSHO_BACKUP_DIR')?:dirname(__DIR__).'/db/project-backups';$dir=$base.'/'.hash('sha256',$pid);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('فضای بک‌آپ قابل نوشتن نیست.');return $dir;}
    private static function write($path,$data){$tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';try{$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('نوشتن بک‌آپ ناموفق بود.');chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('ثبت فایل بک‌آپ ناموفق بود.');}finally{if(is_file($tmp))unlink($tmp);}}
    private static function media($value,&$files){
        if(is_array($value)){foreach($value as $v)self::media($v,$files);return;}
        if(!is_string($value)||!preg_match('~^/?(?:panel/)?uploads/[a-zA-Z0-9_./ -]+$~D',$value)||strpos($value,'..')!==false)return;
        $root=dirname(__DIR__,2);$relative=ltrim($value,'/');
        if(strpos($relative,'panel/')!==0){$rootRelative=$relative;if($value[0]!=='/'||!is_file($root.'/'.$relative))$relative='panel/'.$relative;if(!is_file($root.'/'.$relative)&&is_file($root.'/'.$rootRelative))$relative=$rootRelative;}
        $path=realpath($root.'/'.$relative);$upload=realpath($root.'/'.(strpos($relative,'panel/')===0?'panel/':'').'uploads');if(!$path||!$upload||strpos($path,$upload.'/')!==0||!is_file($path)||is_link($root.'/'.$relative))return;
        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp','gif','avif','pdf','mp4','webm'],true))return;
        if(!isset($files[$relative])){$bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('خواندن تصویر ناموفق بود.');$files[$relative]=['data'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes)];}
    }
    public static function create($project,$reason='manual'){
        if(!is_array($project)||!is_string($project['id']??null)||$project['id']==='')throw new RuntimeException('پروژهٔ بک‌آپ یافت نشد.');
        $pid=$project['id'];$files=[];self::media($project['products']??[],$files);
        $data=['format'=>'maarksho-project-library-v1','project_id'=>$pid,'created_at'=>time(),'reason'=>$reason,'products'=>$project['products']??[],'files'=>$files];
        $data['checksum']=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));self::write(self::dir($pid).'/'.$id.'.json',$data);return $id;
    }
    public static function capture($pid,$reason='manual'){
        if(!isSqlActive())return self::create(getCurrentProject($pid,true),$reason);
        $conn=getConn();if(!$conn->begin_transaction())throw new RuntimeException('شروع بک‌آپ ناموفق بود.');
        try{InventoryVersions::lockProject($pid);$id=self::create(getCurrentProject($pid,true),$reason);if(!$conn->commit())throw new RuntimeException('ثبت بک‌آپ ناموفق بود.');return $id;}catch(Throwable $e){$conn->rollback();throw $e;}
    }
    public static function import($pid,$raw){if(!is_string($raw)||strlen($raw)>32*1024*1024)throw new RuntimeException('حداکثر حجم فایل ۳۲ مگابایت است.');$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(is_array($data)&&!isset($data['format'])&&isset($data['exported_at'],$data['project_id'],$data['products'])&&is_array($data['products'])){$data=['format'=>'maarksho-project-library-v1','project_id'=>$data['project_id'],'created_at'=>(int)$data['exported_at'],'reason'=>'legacy-upload','products'=>$data['products'],'files'=>[]];$data['checksum']=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}self::validate($pid,$data);$id=gmdate('YmdHis').'-'.bin2hex(random_bytes(8));self::write(self::dir($pid).'/'.$id.'.json',$data);return $id;}
    public static function listing($pid){$out=[];foreach(glob(self::dir($pid).'/*.json') as $path){if(basename($path)==='status.json')continue;$out[]=['id'=>basename($path,'.json'),'at'=>filemtime($path),'bytes'=>filesize($path)];}usort($out,static fn($a,$b)=>strcmp($b['id'],$a['id']));return $out;}
    public static function read($pid,$id){if(!is_string($id)||!preg_match('/^\d{14}-[a-f0-9]{16}$/D',$id))throw new RuntimeException('شناسهٔ بک‌آپ معتبر نیست.');$path=self::dir($pid).'/'.$id.'.json';if(!is_file($path))throw new RuntimeException('بک‌آپ یافت نشد.');$data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);self::validate($pid,$data);return $data;}
    public static function validate($pid,$data){
        if(!is_array($data)||($data['format']??'')!=='maarksho-project-library-v1'||($data['project_id']??'')!==$pid||!is_array($data['products']??null)||!is_array($data['files']??null))throw new RuntimeException('فایل خراب است یا به پروژهٔ دیگری تعلق دارد.');
        $expected=$data['checksum']??'';$body=$data;unset($body['checksum']);if(!is_string($expected)||!hash_equals($expected,hash('sha256',json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))))throw new RuntimeException('تمامیت فایل بک‌آپ تأیید نشد.');
        $ids=[];foreach($data['products'] as $p){if(!is_array($p)||!is_string($p['id']??null)||$p['id']===''||isset($ids[$p['id']]))throw new RuntimeException('فهرست محصولات معتبر نیست.');$ids[$p['id']]=true;}
        foreach($data['files'] as $path=>$entry){if(!is_string($path)||!preg_match('~^(?:panel/)?uploads/[a-zA-Z0-9_./ -]+\.(?:jpg|jpeg|png|webp|gif|avif|pdf|mp4|webm)$~iD',$path)||strpos($path,'..')!==false||!is_array($entry)||!is_string($entry['data']??null)||!is_string($entry['sha256']??null))throw new RuntimeException('مسیر فایل بک‌آپ معتبر نیست.');$bytes=base64_decode($entry['data'],true);if($bytes===false||!hash_equals($entry['sha256'],hash('sha256',$bytes)))throw new RuntimeException('فایل بک‌آپ ناقص است.');}
    }
    public static function restore($project,$data){
        $pid=$project['id'];self::validate($pid,$data);if(!isSqlActive())throw new RuntimeException('بازیابی به SQL نیاز دارد.');
        $conn=getConn();if(!$conn->begin_transaction())throw new RuntimeException('شروع بازیابی ناموفق بود.');$created=[];
        try{
            InventoryVersions::lockProject($pid);$fresh=getCurrentProject($pid,true);if(!$fresh)throw new RuntimeException('پروژه یافت نشد.');$safety=self::create($fresh,'before-restore');$products=$data['products'];
            // New private names prevent restoring over a file shared with another project.
            $map=[];$root=dirname(__DIR__,2);$folder='panel/uploads/restored/'.hash('sha256',$pid).'/'.bin2hex(random_bytes(8));
            foreach($data['files'] as $path=>$entry){$target=$folder.'/'.hash('sha256',$path).'.'.strtolower(pathinfo($path,PATHINFO_EXTENSION));if(!is_dir(dirname($root.'/'.$target))&&!mkdir(dirname($root.'/'.$target),0755,true))throw new RuntimeException('فضای تصاویر قابل نوشتن نیست.');$created[]=$root.'/'.$target;if(file_put_contents($root.'/'.$target,base64_decode($entry['data'],true),LOCK_EX)===false)throw new RuntimeException('بازیابی تصویر ناموفق بود.');if(!hash_equals($entry['sha256'],hash_file('sha256',$root.'/'.$target)))throw new RuntimeException('نوشتن کامل تصویر تأیید نشد.');$map[$path]=$target;$map['/'. $path]='/'.$target;$short=strpos($path,'panel/')===0?substr($path,6):$path;$map[$short]=substr($target,6);$map['/'.$short]='/'.$target;}
            $rewrite=function($v)use(&$rewrite,$map){if(is_array($v)){foreach($v as &$x)$x=$rewrite($x);unset($x);return $v;}return is_string($v)&&isset($map[$v])?$map[$v]:$v;};$products=$rewrite($products);
            // Refuse imported IDs that belong to another project (products.id is globally unique).
            foreach($products as &$p){$q=$conn->prepare('SELECT project_id FROM products WHERE id = ?');$q->bind_param('s',$p['id']);$q->execute();$row=$q->get_result()->fetch_assoc();if($row&&$row['project_id']!==$pid)throw new RuntimeException('شناسهٔ محصول متعلق به پروژهٔ دیگری است.');$p['project_id']=$pid;$p['content_gen_requested']=0;$p['publish_priority']=0;unset($p['website_sync']);}unset($p);
            $q=$conn->prepare('DELETE FROM products WHERE project_id = ?');$q->bind_param('s',$pid);if(!$q->execute())throw new RuntimeException('آماده‌سازی بازیابی ناموفق بود.');
            if(!db_save_project($fresh,$products,null,false))throw new RuntimeException('ثبت بازیابی ناموفق بود.');
            // Keep settings, access, payments and schedules intact; pause automated integrations.
            $fresh['settings']['data_recovery_revision']=(int)($fresh['settings']['data_recovery_revision']??0)+1;$fresh['settings']['backup_restore_paused']=true;$fresh['settings']['tiam']['enabled']=0;$fresh['settings']['tiam_inventory']['enabled']=false;$fresh['settings']['wordpress']['enabled']=false;$fresh['settings']['wordpress']['auto_publish']=false;
            $json=json_encode($fresh['settings'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$q=$conn->prepare('UPDATE projects SET settings = ? WHERE id = ?');$q->bind_param('ss',$json,$pid);if(!$q->execute()||!$conn->commit())throw new RuntimeException('ثبت نهایی بازیابی ناموفق بود.');MaarkshoCache::delete('project_full_'.$pid);return $safety;
        }catch(Throwable $e){$conn->rollback();foreach($created as $path)@unlink($path);throw $e;}
    }
    public static function status($pid){$p=self::dir($pid).'/status.json';return is_file($p)?(json_decode(file_get_contents($p),true)?:[]):[];}
    public static function weekly(){
        $ids=[];if(isSqlActive()){$r=getConn()->query('SELECT id FROM projects');if($r)while($p=$r->fetch_assoc())$ids[]=$p['id'];}else foreach(getJsonDB()['projects']??[] as $p)$ids[]=$p['id'];
        foreach($ids as $pid){$lock=null;$status=[];
            try{
                $dir=self::dir($pid);$lock=fopen($dir.'/weekly.lock','c');if(!$lock)throw new RuntimeException('Backup lock unavailable.');if(!flock($lock,LOCK_EX|LOCK_NB))continue;
                $status=self::status($pid);if(time()-($status['last_success']??0)<7*86400)continue;
                $id=self::capture($pid,'weekly');self::write($dir.'/status.json',['last_success'=>time(),'last_id'=>$id,'error'=>'']);
            }catch(Throwable $e){
                error_log('Weekly backup failed for project '.hash('sha256',$pid));$status['last_attempt']=time();$status['error']='بک‌آپ هفتگی ناموفق بود؛ فضا و دسترسی نوشتن را بررسی کنید.';
                try{self::write(self::dir($pid).'/status.json',$status);}catch(Throwable $ignored){error_log('Backup status storage unavailable.');}
            }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
        }
    }
}
