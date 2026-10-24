<?php
require_once dirname(__DIR__).'/ai/Store.php';
require_once dirname(__DIR__).'/ProjectPlans.php';
/** Account subscription, account entitlements and project credit allocations share one atomic ledger. */
class SaaSAccounts {
    private $store;
    private static $cache;
    private static $transactions=[];
    public function __construct($store=null){$this->store=$store?:new AiStore(getenv('MAARKSHO_ACCOUNT_STATE_DIR')?:dirname(__DIR__,2).'/db/accounts');}
    public function store(){return $this->store;}
    public function state(){return $this->store->read();}
    public function change($fn){self::$cache=null;try{return $this->store->transaction(function(&$s)use($fn){self::$transactions[]=&$s;try{return $fn($s);}finally{array_pop(self::$transactions);}});}finally{self::$cache=null;}}
    public static function snapshot(){if(self::$transactions)return self::$transactions[array_key_last(self::$transactions)];if(self::$cache===null){$dir=getenv('MAARKSHO_ACCOUNT_STATE_DIR')?:dirname(__DIR__,2).'/db/accounts';self::$cache=is_file($dir.'/state.json')?(new self())->state():[];}return self::$cache;}
    public static function projectAccount($pid){$s=self::snapshot();$id=$s['project_accounts'][$pid]??null;return $id?($s['accounts'][$id]??null):null;}
    public static function planLimits($plan){$defaults=['plan_starter'=>[1,3,1000],'plan_pro'=>[3,10,5000],'plan_enterprise'=>[10,50,20000]];$d=$defaults[$plan['id']??'']??[1,3,1000];return ['projects'=>max(1,(int)($plan['max_projects']??$d[0])),'seats'=>max(1,(int)($plan['max_members']??$d[1])),'storage_mb'=>max(1,(int)($plan['storage_mb']??$d[2]))];}
    public static function validId($id){return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id);}
    public static function decorate($project){if(!is_array($project))return $project;$a=self::projectAccount($project['id']);if($a){$project['activeTo']=(new DateTimeImmutable('@'.$a['expires_at']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');$project['settings']['subscription']['account_id']=$a['id'];$project['settings']['subscription']['modules']=$a['modules'];$project['settings']['storage_quota_mb']=$a['allocations'][$project['id']]??0;}return $project;}
    public static function extendExpiry($timestamp,$cycle){
        if(!in_array($cycle,['monthly','yearly'],true))throw new RuntimeException('دوره معتبر نیست.');
        $d=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Tehran'));
        $target=$d->modify('first day of this month')->modify($cycle==='yearly'?'+1 year':'+1 month');
        return $target->setDate((int)$target->format('Y'),(int)$target->format('n'),min((int)$d->format('j'),(int)$target->format('t')))->getTimestamp();
    }
    public static function active($a){return ($a['expires_at']??0)>time()&&empty($a['suspended']);}
    public static function authenticate($username,$password){foreach(self::snapshot()['accounts']??[] as $a){$m=$a['owner_member']??[];if(($a['owner']??'')===$username&&!empty($m['password'])&&password_verify($password,$m['password']))return $a;}return null;}
    public function canOwn($a){if(isMainAdmin())return true;if(SessionIdentity::customerAccount()===($a['id']??null))return true;if(!isUser()||($_SESSION['user']['username']??'')!==$a['owner'])return false;$anchor=getCurrentProject($a['anchor_project'],true);$member=$anchor?SessionIdentity::member($anchor):null;return $member&&($member['username']??'')===$a['owner']&&($member['role']??'')==='project_admin';}
    public function account($id,$owner=true){$a=$this->state()['accounts'][$id]??null;if(!$a||($owner&&!$this->canOwn($a)))throw new RuntimeException('دسترسی به حساب مشتری مجاز نیست.');return $a;}
    public function owned(){return array_values(array_filter($this->state()['accounts']??[],fn($a)=>$this->canOwn($a)));}
    private static function audit(&$s,$account,$action,$details=[]){$s['audit'][]=['at'=>time(),'account'=>$account,'actor'=>$_SESSION['user']['username']??'verified_payment','action'=>$action,'details'=>$details];}
    public static function assertActive($a){if(!self::active($a))throw new RuntimeException('اشتراک حساب منقضی یا متوقف است؛ ابتدا تمدید کنید.');}
    /** Called only from a server-verified purchase or an explicit central complimentary grant. */
    public function provision($order,$member,$plan,$pid,$expires){
        if(empty($order['paid_at'])&&empty($order['complimentary']))throw new RuntimeException('پرداخت یا مجوز ساخت رایگان تأیید نشده است.');
        $id='acct_'.substr(hash('sha256',$member['username']),0,32);$limits=self::planLimits($plan);
        return $this->change(function(&$s)use($order,$member,$plan,$pid,$expires,$id,$limits){$receipt=$order['order_id'];if(isset($s['purchase_receipts'][$receipt]))return $s['accounts'][$s['purchase_receipts'][$receipt]];
            if(isset($s['accounts'][$id])&&($s['accounts'][$id]['owner_proof']??'')!==hash('sha256',$member['password']))throw new RuntimeException('حساب موجود نیازمند ورود مالک و تمدید از صفحهٔ حساب است.');
            $a=$s['accounts'][$id]??['id'=>$id,'owner'=>$member['username'],'owner_proof'=>hash('sha256',$member['password']),'owner_member'=>$member,'anchor_project'=>$pid,'created_at'=>time(),'projects'=>[],'allocations'=>[],'wallets'=>[],'employees'=>[]];
            if(!isset($a['projects'][$pid])&&count($a['projects'])>=($a['limits']['projects']??$limits['projects']))throw new RuntimeException('ظرفیت پروژه‌های حساب پر است.');
            $a['owner_member']=$member;$a['plan_id']=$plan['id'];$a['plan_name']=$plan['name'];$a['modules']=ProjectPlans::modules($plan);foreach($limits as $key=>$value)$a['limits'][$key]=max($a['limits'][$key]??0,$value);$a['expires_at']=max($a['expires_at']??0,$expires);$a['projects'][$pid]=true;
            $free=max(0,$a['limits']['storage_mb']-array_sum($a['allocations']));$a['allocations'][$pid]=$a['allocations'][$pid]??min($free,max(1,(int)floor($a['limits']['storage_mb']/$a['limits']['projects'])));$a['wallets'][$pid]=$a['wallets'][$pid]??['balance'=>0,'reserved'=>0];
            foreach(['nahal','ehsan'] as $employee){$cap=max(0,(int)($plan[$employee.'_projects']??0));if($cap)$a['employees'][$employee]=max($a['employees'][$employee]??0,$cap);}$s['accounts'][$id]=$a;$s['project_accounts'][$pid]=$id;$s['purchase_receipts'][$receipt]=$id;self::audit($s,$id,'subscription_provisioned',['receipt'=>$receipt,'complimentary'=>!empty($order['complimentary'])]);return $a;});
    }
    public function allocate($id,$pid,$mb){$this->account($id);if(!is_int($mb)||$mb<1)throw new RuntimeException('حجم تخصیصی معتبر نیست.');$project=getCurrentProject($pid,true);$used=$project?(int)ceil(getProjectStorageUsage($project)['used_bytes']/1048576):PHP_INT_MAX;
        return $this->change(function(&$s)use($id,$pid,$mb,$used){$a=&$s['accounts'][$id];self::assertActive($a);if(!isset($a['projects'][$pid])||$mb<$used)throw new RuntimeException('پروژه متعلق به حساب نیست یا حجم کمتر از مصرف فعلی است.');$alloc=$a['allocations'];$alloc[$pid]=$mb;if(array_sum($alloc)>$a['limits']['storage_mb'])throw new RuntimeException('مجموع تخصیص‌ها از حجم حساب بیشتر است.');$a['allocations']=$alloc;self::audit($s,$id,'storage_allocated',['project'=>$pid,'mb'=>$mb]);});}
    public function transfer($id,$from,$to,$amount,$request){$this->account($id);if(!self::validId($request)||!is_int($amount)||$amount<1||$from===$to)throw new RuntimeException('انتقال اعتبار معتبر نیست.');
        return $this->change(function(&$s)use($id,$from,$to,$amount,$request){$key=$id.':'.$request;$hash=hash('sha256',json_encode([$from,$to,$amount]));if(isset($s['transfers'][$key])){if($s['transfers'][$key]!==$hash)throw new RuntimeException('شناسهٔ تکراری با انتقال متفاوت.');return;}$a=&$s['accounts'][$id];self::assertActive($a);if(!isset($a['projects'][$from],$a['projects'][$to]))throw new RuntimeException('انتقال فقط بین پروژه‌های همین حساب مجاز است.');$w=&$a['wallets'][$from];if(($w['balance']??0)-($w['reserved']??0)<$amount)throw new RuntimeException('فقط اعتبار آزاد قابل انتقال است.');$w['balance']-=$amount;$a['wallets'][$to]['balance']=($a['wallets'][$to]['balance']??0)+$amount;$s['transfers'][$key]=$hash;self::audit($s,$id,'credits_transferred',['from'=>$from,'to'=>$to,'credits'=>$amount]);});}
    public function grantEmployee($id,$employee,$cap,$reason){if(!isMainAdmin())throw new RuntimeException('تخصیص با معافیت فقط با تیم مرکزی است.');if(!in_array($employee,['nahal','ehsan'],true)||!is_int($cap)||$cap<1||$cap>10000||trim($reason)===''||mb_strlen($reason)>500)throw new RuntimeException('کارمند، ظرفیت و دلیل معافیت معتبر لازم است.');$a=$this->account($id);if(!in_array('ai_connect',$a['modules'],true))throw new RuntimeException('پلن حساب دسترسی هوش مصنوعی ندارد.');$this->change(function(&$s)use($id,$employee,$cap,$reason){$a=&$s['accounts'][$id];$a['employees'][$employee]=max($a['employees'][$employee]??0,$cap);self::audit($s,$id,'employee_complimentary_grant',['employee'=>$employee,'cap'=>$cap,'reason'=>$reason]);});}
    public function activate($id,$pid,$employee,$enabled){$a=$this->account($id,false);$p=getCurrentProject($pid,true);$member=$p?SessionIdentity::member($p):null;if(!$this->canOwn($a)&&(!$member||($member['role']??'')!=='project_admin'))throw new RuntimeException('فعال‌سازی فقط با ادمین اصلی پروژه است.');if(!in_array($employee,['nahal','ehsan'],true))throw new RuntimeException('کارمند هنوز قابل فعال‌سازی نیست.');return $this->change(function(&$s)use($id,$pid,$employee,$enabled){$a=&$s['accounts'][$id];self::assertActive($a);if(!isset($a['projects'][$pid]))throw new RuntimeException('پروژه متعلق به حساب نیست.');$active=$a['active_employees'][$employee]??[];if($enabled&&!isset($active[$pid])&&count($active)>=($a['employees'][$employee]??0))throw new RuntimeException('دسترسی یا ظرفیت فعال‌سازی این کارمند را خریداری کنید.');if($enabled)$active[$pid]=true;else unset($active[$pid]);$a['active_employees'][$employee]=$active;self::audit($s,$id,'employee_activation',['employee'=>$employee,'project'=>$pid,'enabled'=>$enabled]);});}
    /** Central complimentary assignment; grants entitlement, never fabricates paid credits. */
    public function assignProjectAI($pid,$employees,$reason,$owner='',$plan=null){
        if(!isMainAdmin())throw new RuntimeException('تخصیص AI فقط با تیم مرکزی مجاز است.');
        if(!is_array($employees)||!$employees||array_diff($employees,['nahal','ehsan'])||trim($reason)===''||mb_strlen($reason)>500)throw new RuntimeException('کارمند و دلیل تخصیص را مشخص کنید.');
        $p=getCurrentProject($pid,true);if(!$p)throw new RuntimeException('پروژه یافت نشد.');
        $s=$this->state();$id=$s['project_accounts'][$pid]??null;
        if(!$id){
            $member=null;foreach($p['users']??[] as $m)if(($m['username']??'')===$owner&&($m['role']??'')==='project_admin')$member=$m;
            if(!$member)throw new RuntimeException('ادمین اصلی موجود پروژه را انتخاب کنید.');
            $existing='acct_'.substr(hash('sha256',$owner),0,32);
            if(isset($s['accounts'][$existing])){
                $id=$existing;
                $this->change(function(&$s)use($id,$pid,$member,$p,$reason){
                    $a=&$s['accounts'][$id];self::assertActive($a);
                    if(($a['owner_proof']??'')!==hash('sha256',$member['password']??''))throw new RuntimeException('هویت مالک این پروژه با حساب موجود یکسان نیست؛ ابتدا هویت مالک را اصلاح کنید.');
                    if(isset($s['project_accounts'][$pid])){if($s['project_accounts'][$pid]!==$id)throw new RuntimeException('پروژه به حساب دیگری متصل شده است.');return;}
                    if(count($a['projects'])>=$a['limits']['projects'])throw new RuntimeException('ظرفیت پروژه‌های حساب پر است؛ ابتدا سقف حساب را ارتقا دهید.');
                    $names=[];foreach(array_keys($a['projects']) as $other){$project=getCurrentProject($other,true);foreach($project['users']??[] as $u)$names[$u['username']]=true;}foreach($p['users']??[] as $u)$names[$u['username']]=true;
                    if(count($names)>$a['limits']['seats'])throw new RuntimeException('ظرفیت اعضای حساب کافی نیست.');
                    $a['projects'][$pid]=true;$a['wallets'][$pid]=['balance'=>0,'reserved'=>0];$a['allocations'][$pid]=0;$s['project_accounts'][$pid]=$id;self::audit($s,$id,'legacy_project_attached',['project'=>$pid,'reason'=>$reason]);
                });
            }else{if(!$plan||!in_array('ai_connect',ProjectPlans::modules($plan),true))throw new RuntimeException('برای پروژهٔ قدیمی پلن دارای AI انتخاب کنید.');$a=$this->complimentary($p,$owner,$plan);$id=$a['id'];}
        }
        return $this->change(function(&$s)use($id,$pid,$employees,$reason){
            $a=&$s['accounts'][$id];self::assertActive($a);if(!isset($a['projects'][$pid]))throw new RuntimeException('پروژه متعلق به حساب نیست.');
            if(!in_array('ai_connect',$a['modules'],true)){$a['modules'][]='ai_connect';self::audit($s,$id,'ai_complimentary_grant',['project'=>$pid,'reason'=>$reason]);}
            foreach(array_unique($employees) as $employee){$active=$a['active_employees'][$employee]??[];$active[$pid]=true;$a['employees'][$employee]=max($a['employees'][$employee]??0,count($active));$a['active_employees'][$employee]=$active;self::audit($s,$id,'employee_complimentary_assignment',['project'=>$pid,'employee'=>$employee,'reason'=>$reason]);}
            require_once dirname(__DIR__).'/ProjectOnboarding.php';
            ProjectOnboarding::mutate($pid,function(&$project){
                $settings=&$project['settings'];
                if(isset($settings['active_modules']))$settings['active_modules']=array_values(array_unique(array_merge(is_array($settings['active_modules'])?$settings['active_modules']:[],['ai_connect'])));
                $settings['module_visibility']['ai_connect']='active';
            });
            return $id;
        });
    }
    public static function reserve(&$s,$account,$pid,$credits,$key){$a=&$s['accounts'][$account];if(!$a)throw new RuntimeException('حساب یافت نشد.');self::assertActive($a);if(!isset($a['projects'][$pid])||$credits<1||isset($s['credit_reservations'][$key]))throw new RuntimeException('رزرو اعتبار معتبر نیست.');$w=&$a['wallets'][$pid];if(($w['balance']??0)-($w['reserved']??0)<$credits)throw new RuntimeException('اعتبار آزاد پروژه کافی نیست.');$w['reserved']=($w['reserved']??0)+$credits;$s['credit_reservations'][$key]=['account'=>$account,'project'=>$pid,'credits'=>$credits,'state'=>'reserved','at'=>time()];}
    public static function settle(&$s,$key,$charge,$uncertain=false){$r=&$s['credit_reservations'][$key];if(!$r||!in_array($r['state'],['reserved','uncertain'],true))return;if($uncertain){$r['state']='uncertain';return;}if(!is_int($charge)||$charge<0||$charge>$r['credits'])throw new RuntimeException('تسویهٔ اعتبار معتبر نیست.');$w=&$s['accounts'][$r['account']]['wallets'][$r['project']];$w['reserved']-=$r['credits'];$w['balance']-=$charge;$r['state']='settled';$r['charged']=$charge;self::audit($s,$r['account'],'credits_settled',['project'=>$r['project'],'reservation'=>$key,'credits'=>$charge]);}
    public static function allowsProject($project,$feature){$a=self::projectAccount($project['id']);if(!$a)return true;if(in_array($feature,['settings','users','support','dashboard'],true))return true;return self::active($a)&&ProjectPlans::allows($feature,['subscription'=>['modules'=>$a['modules']]]);}
    public static function validateMembers($projects){$state=self::snapshot();foreach($state['accounts']??[] as $a){$changed=[];foreach($projects as $p)if(isset($a['projects'][$p['id']]))$changed[$p['id']]=$p;if(!$changed)continue;$names=[];foreach(array_keys($a['projects']) as $pid){$p=$changed[$pid]??getCurrentProject($pid,true);foreach($p['users']??[] as $m)$names[$m['username']]=true;}if(count($names)>$a['limits']['seats'])throw new RuntimeException('ظرفیت اعضای حساب پر است؛ ارتقا لازم است.');}}
    public function complimentary($project,$owner,$plan,$cycle='monthly'){
        if(!isMainAdmin())throw new RuntimeException('ساخت بدون پرداخت فقط با تیم مرکزی مجاز است.');$member=null;foreach($project['users']??[] as $m)if($m['username']===$owner&&$m['role']==='project_admin')$member=$m;if(!$member)throw new RuntimeException('ادمین اصلی پروژه را مشخص کنید.');$receipt=['order_id'=>'staff-'.$project['id'],'complimentary'=>true];return $this->provision($receipt,$member,$plan,$project['id'],self::extendExpiry(time(),$cycle));
    }
    public function createProject($id,$name,$request){$a=$this->account($id);$name=trim($name);if($name===''||mb_strlen($name)>150||!self::validId($request))throw new RuntimeException('نام یا شناسهٔ پروژه معتبر نیست.');$member=$a['owner_member']??null;if(!$member){$anchor=getCurrentProject($a['anchor_project'],true);foreach($anchor['users']??[] as $m)if($m['username']===$a['owner'])$member=$m;}if(!$member)throw new RuntimeException('عضویت مالک حساب نیازمند بازیابی است.');ProjectMembers::prepareStorage();
        return $this->change(function(&$s)use($id,$name,$request,$member){$a=&$s['accounts'][$id];self::assertActive($a);$pid='prj_'.substr(hash('sha256',$id.'|'.$request),0,24);if(isset($a['projects'][$pid]))return $pid;if(count($a['projects'])>=$a['limits']['projects'])throw new RuntimeException('ظرفیت پروژه‌ها پر است؛ ابتدا ارتقا دهید.');$p=['id'=>$pid,'name'=>$name,'activeFrom'=>date('Y-m-d'),'activeTo'=>date('Y-m-d',$a['expires_at']),'users'=>[$member],'products'=>[],'settings'=>['platform_type'=>'custom','sourceUrl'=>'','active_modules'=>$a['modules'],'subscription'=>['modules'=>$a['modules'],'account_id'=>$id],'onboarding'=>['created_at'=>time(),'revision'=>0,'steps'=>[]]]];if(ProjectLifecycle::isDeleted($pid))throw new RuntimeException('پروژهٔ این درخواست حذف شده است.');if(!db_save_project($p))throw new RuntimeException('ثبت پروژه انجام نشد.');$a['projects'][$pid]=true;$a['allocations'][$pid]=0;$a['wallets'][$pid]=['balance'=>0,'reserved'=>0];$s['project_accounts'][$pid]=$id;self::audit($s,$id,'project_created',['project'=>$pid]);return $pid;});
    }
}
