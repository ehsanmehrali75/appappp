<?php
require_once __DIR__.'/saas/Accounts.php';require_once __DIR__.'/saas/Aria.php';
/** Operational evidence only. No network probes, implicit repairs or credential output. */
class OperationalHealth {
    private $store,$accounts;
    public function __construct($accounts=null,$store=null){$this->accounts=$accounts?:new SaaSAccounts();$this->store=$store?:new AiStore(getenv('MAARKSHO_OPERATIONS_DIR')?:__DIR__.'/../db/operations');}
    private static function worker($name){if(PHP_SAPI!=='cli'||!in_array($name,['aria','publication','ehsan','nahal','sms','integrations'],true))throw new RuntimeException('ثبت وضعیت worker فقط از CLI مجاز است.');}
    public function begin($name){self::worker($name);$run=bin2hex(random_bytes(12));$this->store->transaction(function(&$s)use($name,$run){$s['workers'][$name]=array_replace($s['workers'][$name]??[],['run'=>$run,'started_at'=>time(),'state'=>'running']);});return $run;}
    public function finish($name,$run,$success){self::worker($name);$this->store->transaction(function(&$s)use($name,$run,$success){$row=&$s['workers'][$name];$at=time();$field=$success?'last_success':'last_failure';$row[$field]=max($row[$field]??0,$at);if(($row['run']??'')===$run){$row['state']=$success?'completed':'failed';$row['finished_at']=$at;}$s['history'][]=['worker'=>$name,'state'=>$success?'completed':'failed','at'=>$at];$s['history']=array_slice($s['history'],-80);});}
    public function workers(){
        $state=$this->store->read();$out=[];foreach(['aria','publication','ehsan','nahal','sms','integrations'] as $name){$r=$state['workers'][$name]??[];$status=!$r?'unknown':($r['state']??'unknown');if($r&&(($r['started_at']??0)<time()-600))$status='stale';$out[$name]=['state'=>$status,'started_at'=>$r['started_at']??null,'finished_at'=>$r['finished_at']??null,'last_success'=>$r['last_success']??null,'last_failure'=>$r['last_failure']??null];}return $out;
    }
    public static function metrics($state,$pid){
        $account=$state['accounts'][$state['project_accounts'][$pid]??'']??null;$wallet=$account['wallets'][$pid]??['balance'=>0,'reserved'=>0];$expected=0;$unknown=0;$issues=[];$queues=[];$allJobs=[];
        foreach(['image_jobs'=>'nahal','ehsan_jobs'=>'ehsan','aria_jobs'=>'aria'] as $table=>$employee){$q=['queued'=>0,'running'=>0,'uncertain'=>0,'failed'=>0,'oldest_queued'=>null];foreach($state[$table]??[] as $id=>$job)if(($job['project']??'')===$pid){$allJobs[$id]=$job;$status=$job['state']??'';if(isset($q[$status]))$q[$status]++;if($status==='queued')$q['oldest_queued']=$q['oldest_queued']===null?($job['at']??time()):min($q['oldest_queued'],$job['at']??time());}$queues[$employee]=$q;}
        $orphan=0;foreach($state['credit_reservations']??[] as $id=>$r)if(($r['project']??'')===$pid&&in_array($r['state']??'',['reserved','uncertain'],true)){$expected+=(int)($r['credits']??0);if(($r['state']??'')==='uncertain')$unknown+=(int)$r['credits'];if(!isset($allJobs[$id]))$orphan++;}
        if(isset($state['project_accounts'][$pid])&&!$account)$issues[]=['code'=>'missing_account','severity'=>'error','text'=>'نگاشت پروژه به حساب ناموجود اشاره می‌کند؛ بررسی تیم مرکزی لازم است.'];
        if($expected!==(int)($wallet['reserved']??0))$issues[]=['code'=>'reservation_mismatch','severity'=>'error','text'=>'جمع رزروهای دفتر با رزرو کیف پول یکسان نیست؛ تسویهٔ خودکار انجام ندهید.'];
        if(($wallet['balance']??0)<0||($wallet['reserved']??0)<0||($wallet['reserved']??0)>($wallet['balance']??0))$issues[]=['code'=>'invalid_wallet','severity'=>'error','text'=>'مقادیر کیف پول ناسازگار است؛ بررسی تیم مرکزی لازم است.'];
        if($orphan)$issues[]=['code'=>'orphan_reservations','severity'=>'error','text'=>'رزرو فعال بدون درخواست متناظر وجود دارد: '.$orphan];
        if($unknown)$issues[]=['code'=>'uncertain_credit','severity'=>'warning','text'=>'کردیت دارای نتیجهٔ نامشخص: '.$unknown.'؛ ابتدا رسید سرویس‌دهنده بررسی شود.'];
        foreach($queues as $name=>$q)if($q['oldest_queued']!==null&&$q['oldest_queued']<time()-900)$issues[]=['code'=>'queue_delay_'.$name,'severity'=>'warning','text'=>'قدیمی‌ترین درخواست '.$name.' بیش از ۱۵ دقیقه در صف مانده است؛ ظرفیت و اجرای worker را بررسی کنید.'];
        $sms=['sending'=>0,'uncertain'=>0,'stale_sending'=>0,'late_receipts'=>0];foreach($state['sms_campaigns']??[] as $r)if(($r['project']??'')===$pid){if(in_array($r['state']??'',['sending','uncertain'],true))$sms[$r['state']]++;if(($r['state']??'')==='sending'&&($r['approval']['at']??$r['at']??time())<time()-180)$sms['stale_sending']++;if(!empty($r['late_receipt']))$sms['late_receipts']++;}
        if($sms['uncertain']||$sms['stale_sending'])$issues[]=['code'=>'sms_review','severity'=>'warning','text'=>'ارسال پیامک متوقف یا نامشخص است؛ بدون بررسی رسید، ارسال دوباره انجام ندهید.'];
        return ['managed'=>$account!==null,'wallet'=>['balance'=>(int)($wallet['balance']??0),'reserved'=>(int)($wallet['reserved']??0),'free'=>(int)($wallet['balance']??0)-(int)($wallet['reserved']??0),'expected_reserved'=>$expected,'uncertain'=>$unknown],'queues'=>$queues,'sms'=>$sms,'issues'=>$issues];
    }
    private static function socialHealth(&$result,$jobs){
        $result['social']=AiSocial::summary($jobs);
        if($result['social']['uncertain']||$result['social']['expired_claims'])$result['issues'][]=['code'=>'social_handoff_review','severity'=>'warning','text'=>'تحویل انتشار به ابزار ChatGPT نامشخص یا منقضی شده است؛ پیش از هر ارسال تازه رسید سرویس بیرونی را بررسی کنید.'];
        if($result['social']['overdue_external'])$result['issues'][]=['code'=>'social_external_receipt_overdue','severity'=>'warning','text'=>'زمان انتشار ثبت‌شده نزد ابزار بیرونی بیش از یک ساعت گذشته و رسید انتشار برنگشته است؛ این وضعیت اثبات شکست یا مجوز تکرار ارسال نیست.'];
    }
    public function project($p){
        if(($_SESSION['project_id']??'')!==($p['id']??null))throw new RuntimeException('پروژهٔ انتخاب‌شده با درخواست یکسان نیست.');$member=SessionIdentity::member($p);if(!isMainAdmin()&&($member['role']??'')!=='project_admin')throw new RuntimeException('سلامت پروژه فقط با ادمین اصلی قابل مشاهده است.');AiAccess::need('settings');
        $p=getCurrentProject($p['id'],true);if(!$p)throw new RuntimeException('پروژه موجود نیست.');$result=self::metrics($this->accounts->state(),$p['id']);$result['project']=['id'=>$p['id'],'name'=>$p['name']];$result['restore_paused']=!empty($p['settings']['backup_restore_paused']);$result['blocked_publications']=0;$result['destination_reapproval']=0;
        foreach($p['settings']['aria_calendar']['plans']??[] as $plan)if(($plan['state']??'')==='approved')foreach($plan['entries'] as $item)if(($item['state']??'')==='ready'){if(!empty($item['blocked_reason']))$result['blocked_publications']++;if(!hash_equals($item['approval']['destination']??'',Aria::destinationFingerprint($p,$item['channel'])))$result['destination_reapproval']++;}
        if($result['restore_paused'])$result['issues'][]=['code'=>'restore_paused','severity'=>'warning','text'=>'پس از بازیابی، همگام‌سازی خودکار پروژه متوقف است؛ ادامه را از مسیر مربوط تأیید کنید.'];
        if($result['destination_reapproval'])$result['issues'][]=['code'=>'destination_reapproval','severity'=>'warning','text'=>'محتوا نیازمند تأیید دوبارهٔ مقصد است: '.$result['destination_reapproval']];
        $base=getenv('MAARKSHO_BACKUP_DIR')?:__DIR__.'/../db/project-backups';$file=$base.'/'.hash('sha256',$p['id']).'/status.json';$backup=is_file($file)?json_decode(file_get_contents($file),true):[];$result['backup']=['last_success'=>$backup['last_success']??null,'has_error'=>!empty($backup['error'])];
        if(!$result['backup']['last_success']||$result['backup']['last_success']<time()-8*86400)$result['issues'][]=['code'=>'weekly_backup_unconfirmed','severity'=>'warning','text'=>'بک‌آپ هفتگی موفق در هشت روز اخیر ثبت نشده است؛ Cron و فضای ذخیره‌سازی بررسی شود.'];
        self::socialHealth($result,array_filter((new AiStore())->read()['social_jobs']??[],static fn($j)=>($j['project']??'')===$p['id']));
        return $result;
    }
    public function central(){
        if(!isMainAdmin())throw new RuntimeException('گزارش سراسری فقط برای تیم مرکزی مجاز است.');$state=$this->accounts->state();$projects=[];$names=[];
        // Central status does not load every product/photo in every project merely to render a name.
        if(isSqlActive()){$rows=getConn()->query('SELECT id,name FROM projects');if(!$rows)throw new RuntimeException('فهرست پروژه‌ها قابل خواندن نیست.');while($r=$rows->fetch_assoc())$names[$r['id']]=$r['name'];}else foreach(getJsonDB()['projects']??[] as $p)$names[$p['id']]=$p['name'];
        $buckets=[];foreach(['image_jobs','ehsan_jobs','aria_jobs','credit_reservations','sms_campaigns'] as $table)foreach($state[$table]??[] as $key=>$value)$buckets[$value['project']??''][$table][$key]=$value;
        $socialBuckets=[];foreach((new AiStore())->read()['social_jobs']??[] as $job)$socialBuckets[$job['project']??''][]=$job;
        foreach(array_unique(array_merge(array_keys($names),array_keys($state['project_accounts']??[]),array_keys($socialBuckets))) as $pid){
            $pid=(string)$pid;if($pid==='')continue;$account=$state['project_accounts'][$pid]??null;$exists=isset($names[$pid])&&!ProjectLifecycle::isDeleted($pid);
            $subset=($buckets[$pid]??[])+['accounts'=>$account?[$account=>$state['accounts'][$account]??null]:[],'project_accounts'=>$account?[$pid=>$account]:[]];
            $row=self::metrics($subset,$pid);self::socialHealth($row,$socialBuckets[$pid]??[]);$row['project']=['id'=>$pid,'name'=>$exists?$names[$pid]:'پروژهٔ حذف‌شده'];
            if(!$exists)$row['issues'][]=['code'=>'deleted_project_account_link','severity'=>'warning','text'=>'حساب یا صف خصوصی به پروژهٔ حذف‌شده اشاره می‌کند؛ اعتبار و ظرفیت پیش از اصلاح دستی بررسی شود.'];$projects[]=$row;
        }
        return ['projects'=>$projects,'workers'=>$this->workers(),'runtime'=>['php'=>PHP_VERSION,'extensions'=>array_combine(['mysqli','sqlite3','pdo_sqlite','mbstring','curl','dom','gd','zip','exif'],array_map('extension_loaded',['mysqli','sqlite3','pdo_sqlite','mbstring','curl','dom','gd','zip','exif']))]];
    }
}
