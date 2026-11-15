<?php
/** ChatGPT hands work to external tools. Queue acceptance is never delivery evidence. */
class AiSocial {
    private $store;
    public function __construct($store){$this->store=$store;}
    public static function route($settings,$platform){
        $key=$platform==='instagram_story'?'instagram':$platform;$config=$settings['connections'][$key]??[];
        return in_array($key,['telegram','instagram'],true)&&(!isset($config['enabled'])||$config['enabled'])&&($config['proxy']['bypass_method']??'')==='chatgpt';
    }
    public static function allowed($mode='view'){AiAccess::need('calendar',$mode);}
    private static function fresh($project,$selected=true){
        $id=$project['id']??'';
        if($selected&&($_SESSION['project_id']??'')!==$id)throw new RuntimeException('ابتدا میزکار همین پروژه را انتخاب کنید.');
        $p=getCurrentProject($id,true);
        if(!$p||ProjectLifecycle::isDeleted($id))throw new RuntimeException('پروژهٔ انتشار موجود نیست.');
        if($selected&&!SessionIdentity::select($p))throw new RuntimeException('دسترسی پروژه تغییر کرده است.');
        return $p;
    }
    public static function destinationFingerprint($project,$platform){
        $c=$project['settings']['connections'][$platform]??[];
        return hash('sha256',json_encode(array_intersect_key($c,array_flip(['enabled','chatgpt_destination','chatId','instagram_account_id','token','access_token','proxy']))));
    }
    private static function productMedia($project,$id,$media){
        if($id==='')return;
        $p=AiContent::find($project,['product_id'=>$id]);$approved=array_map([AiData::class,'media'],WebsiteCatalogSync::approvedPhotos($p));
        if(!empty($p['social_image'])&&WebsiteCatalogSync::imageApproved($p,$p['social_image']))$approved[]=AiData::media($p['social_image']);
        if(array_diff($media,$approved))throw new RuntimeException('رسانه باید از تصاویر تأییدشدهٔ فعلی همین محصول انتخاب شود.');
    }
    private static function schedule($project,$id){foreach($project['schedules']??[] as $s)if((string)$s['id']===$id)return $s;throw new RuntimeException('زمان‌بندی متعلق به پروژه نیست یا حذف شده است.');}
    private static function scheduleFingerprint($s){return hash('sha256',json_encode(array_intersect_key($s,array_flip(['platform','caption','mediaUrl','scheduledAt','productId']))));}
    private static function ready($p,$j){
        if(!empty($p['settings']['backup_restore_paused'])||(int)($j['recovery_revision']??-1)!==(int)($p['settings']['data_recovery_revision']??0))throw new RuntimeException('دادهٔ پروژه بازیابی شده یا ارسال متوقف است؛ تأیید و صف تازه لازم است.');
        if(!self::route($p['settings'],$j['platform']))throw new RuntimeException('اتصال این کانال فعال نیست.');
        if(!hash_equals($j['destination_fingerprint']??'',self::destinationFingerprint($p,$j['platform'])))throw new RuntimeException('مقصد اتصال تغییر کرده است؛ محتوا را دوباره بررسی و صف‌بندی کنید.');
        self::productMedia($p,$j['product_id']??'',$j['media']);
        if(($j['schedule_id']??'')!==''){
            $schedule=self::schedule($p,$j['schedule_id']);
            if(($schedule['approvalStatus']??'')!=='approved'||in_array($schedule['stage1_decision']??'',['rejected'],true)||in_array($schedule['stage2_decision']??'',['rejected'],true)||!in_array($schedule['status']??'',['pending','processing','awaiting_chatgpt'],true)||!hash_equals($j['schedule_fingerprint']??'',self::scheduleFingerprint($schedule)))throw new RuntimeException('زمان‌بندی تغییر کرده یا تأیید و مجوز ارسال آن برقرار نیست.');
        }
        if(!empty($j['aria_origin'])){
            $origin=$j['aria_origin'];$plan=$p['settings']['aria_calendar']['plans'][$origin['plan']??'']??[];$entry=$plan['entries'][$origin['entry']??'']??[];
            if(($plan['state']??'')!=='approved'||(int)($plan['recovery_revision']??-1)!==(int)($p['settings']['data_recovery_revision']??0)||($entry['state']??'')!=='queued_external'||($entry['receipt']['job']??'')!==$j['id']||empty($origin['hash'])||!hash_equals($entry['content_hash']??'',$origin['hash'])||!hash_equals($entry['approval']['hash']??'',$origin['hash']))throw new RuntimeException('تقویم یا تأیید محتوای آریا تغییر کرده است.');
        }
    }
    public function enqueue($project,$input,$actor='scheduler'){
        $project=self::fresh($project,false);
        if(!empty($project['settings']['backup_restore_paused']))throw new RuntimeException('ارسال پس از بازیابی متوقف است.');
        $platform=$input['platform']??'';$kind=$input['kind']??'post';
        if(!in_array($platform,['telegram','instagram'],true)||!self::route($project['settings'],$platform))throw new RuntimeException('روش ChatGPT برای این کانال فعال نیست.');
        if(!in_array($kind,$platform==='telegram'?['post']:['post','story','reel','carousel'],true))throw new RuntimeException('نوع انتشار معتبر نیست.');
        $request=$input['request_id']??'';$caption=$input['caption']??'';$media=$input['media']??[];$at=$input['scheduled_at']??time();$destination=$project['settings']['connections'][$platform]['chatgpt_destination']??'';
        if(!is_string($destination)||trim($destination)===''||mb_strlen($destination)>200)throw new RuntimeException('مقصد صریح ابزار ChatGPT را در مرکز داده و اتصال این کانال تعیین کنید.');
        if(!is_string($request)||!preg_match('/^[a-zA-Z0-9_.:-]{8,150}$/D',$request)||!is_string($caption)||mb_strlen($caption)>10000||!is_array($media)||count($media)>10||!is_int($at)||$at<0||$at>time()+366*86400)throw new RuntimeException('محتوا یا زمان انتشار معتبر نیست.');
        foreach($media as $url)if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||strtolower(parse_url($url,PHP_URL_SCHEME)??'')!=='https'||isset(parse_url($url)['user'])||isset(parse_url($url)['pass']))throw new RuntimeException('فایل انتشار باید آدرس عمومی HTTPS داشته باشد.');
        if($caption===''&&!$media||$platform==='instagram'&&!$media)throw new RuntimeException('محتوای انتشار کامل نیست.');
        $productId=$input['product_id']??'';$scheduleId=$input['schedule_id']??'';
        if(!is_string($productId)||!is_string($scheduleId))throw new RuntimeException('شناسهٔ محصول یا زمان‌بندی معتبر نیست.');
        self::productMedia($project,$productId,$media);
        $definition=['destination'=>$destination,'platform'=>$platform,'kind'=>$kind,'caption'=>$caption,'media'=>array_values($media),'scheduled_at'=>$at,'product_id'=>$productId,'schedule_id'=>$scheduleId,'recovery_revision'=>(int)($project['settings']['data_recovery_revision']??0),'destination_fingerprint'=>self::destinationFingerprint($project,$platform)];
        if($scheduleId!=='')$definition['schedule_fingerprint']=self::scheduleFingerprint(self::schedule($project,$scheduleId));
        if(isset($input['aria_origin'])){if(!is_array($input['aria_origin']))throw new RuntimeException('مرجع تقویم معتبر نیست.');$definition['aria_origin']=array_intersect_key($input['aria_origin'],array_flip(['plan','entry','hash']));}
        $hash=hash('sha256',json_encode($definition));$id=substr(hash('sha256',$project['id'].'|'.$request),0,32);
        return $this->store->transaction(static function(&$s)use($id,$definition,$hash,$project,$actor){$old=$s['social_jobs'][$id]??null;if($old){if($old['hash']!==$hash)throw new RuntimeException('شناسهٔ انتشار با محتوای متفاوت تکرار شده است.');return ['id'=>$id,'state'=>$old['state'],'queued'=>true];}$s['social_jobs'][$id]=$definition+['id'=>$id,'project'=>$project['id'],'state'=>'queued','hash'=>$hash,'created'=>time(),'by'=>$actor];return ['id'=>$id,'state'=>'queued','queued'=>true];});
    }
    public function create($project,$grant,$args){$project=self::fresh($project);AiAccess::need('approve','use');AiAccess::scope($grant,'social.write');self::allowed('use');AiAccess::write();unset($args['aria_origin'],$args['schedule_id']);return $this->enqueue($project,$args,$grant['username']);}
    public function jobs($project){self::allowed();$s=$this->store->read();$rows=[];foreach($s['social_jobs']??[] as $j)if($j['project']===$project['id']){$row=$j;unset($row['hash'],$row['lease_hash'],$row['destination_fingerprint']);$row['due']=$j['scheduled_at']<=time();$row['claimable']=false;if($j['state']==='queued')try{self::ready($project,$j);$row['claimable']=$row['due'];}catch(RuntimeException $e){$row['blocked_reason']=$e->getMessage();}$rows[]=$row;}return array_slice(array_reverse($rows),0,200);}
    public function claim($project,$grant,$id,$scheduleExternally=false){
        $project=self::fresh($project);AiAccess::scope($grant,'social.write');self::allowed('use');AiAccess::write();$lease=bin2hex(random_bytes(32));
        return $this->store->transaction(static function(&$s)use($project,$grant,$id,$lease,$scheduleExternally){
            if(!is_string($id)||!isset($s['social_jobs'][$id]))throw new RuntimeException('انتشار یافت نشد.');$j=&$s['social_jobs'][$id];
            if($j['project']!==$project['id']||$j['state']!=='queued'||(!$scheduleExternally&&$j['scheduled_at']>time()))throw new RuntimeException('انتشار آمادهٔ دریافت نیست.');
            self::ready($project,$j);
            $j['state']='claimed';$j['lease_hash']=hash('sha256',$lease);$j['lease_until']=time()+900;$j['claimed_by']=$grant['owner'];
            return ['job'=>array_diff_key($j,['hash'=>1,'lease_hash'=>1,'destination_fingerprint'=>1]),'lease'=>$lease,'instruction'=>'Use only the explicitly named destination with your separately connected publishing tool. Check lease_until before sending. Report the actual provider receipt; queue acceptance is not delivery. Never automatically repeat an expired or uncertain job.'];
        });
    }
    public static function summary($jobs){
        $out=array_fill_keys(['queued','claimed','uncertain','published','scheduled_external','cancelled','failed','late_receipts','expired_claims','overdue_external'],0);$out['oldest_queued']=null;
        foreach($jobs as $j){$state=$j['state']??'';if(in_array($state,['queued','claimed','uncertain','published','scheduled_external','cancelled','failed'],true))$out[$state]++;if(!empty($j['late_receipt']))$out['late_receipts']++;if($state==='claimed'&&($j['lease_until']??0)<time())$out['expired_claims']++;if($state==='scheduled_external'&&($j['scheduled_at']??time())<time()-3600)$out['overdue_external']++;if($state==='queued')$out['oldest_queued']=$out['oldest_queued']===null?($j['created']??time()):min($out['oldest_queued'],$j['created']??time());}
        return $out;
    }
    private static function expire(&$j){$j['state']='uncertain';$j['uncertain_reason']='claim_expired';$j['finished']=time();}
    public function reconcileStale(){
        if(PHP_SAPI!=='cli')throw new RuntimeException('بررسی مهلت تحویل فقط از worker مجاز است.');
        return $this->store->transaction(static function(&$s){$count=0;foreach($s['social_jobs']??[] as $id=>$j)if(($j['state']??'')==='claimed'&&($j['lease_until']??0)<time()){self::expire($s['social_jobs'][$id]);$count++;}return $count;});
    }
    public function ack($project,$grant,$args){
        $project=self::fresh($project);AiAccess::scope($grant,'social.write');self::allowed('use');AiAccess::write();$id=$args['id']??'';$lease=$args['lease']??'';$state=$args['state']??'';$receipt=$args['receipt']??'';
        if(!in_array($state,['published','scheduled_external','failed','uncertain'],true)||!is_string($receipt)||strlen($receipt)>2000||(in_array($state,['published','scheduled_external'],true)&&trim($receipt)===''))throw new RuntimeException('رسید واقعی سرویس برای ثبت انتشار لازم است.');
        return $this->store->transaction(static function(&$s)use($id,$lease,$state,$receipt,$project,$grant){
            if(!is_string($id)||!isset($s['social_jobs'][$id]))throw new RuntimeException('انتشار یافت نشد.');$j=&$s['social_jobs'][$id];
            if($j['project']!==$project['id']||!is_string($lease)||!hash_equals($j['lease_hash']??'',hash('sha256',$lease))||($j['claimed_by']??'')!==$grant['owner'])throw new RuntimeException('اجازهٔ ثبت نتیجهٔ این انتشار وجود ندارد.');
            if($j['state']==='claimed'&&($j['lease_until']??0)<time())self::expire($j);
            if($j['state']==='uncertain'&&$state!=='uncertain'){
                $evidence=['state'=>$state,'receipt'=>$receipt];
                if(isset($j['late_receipt'])&&array_intersect_key($j['late_receipt'],$evidence)!==$evidence)throw new RuntimeException('رسید دیررس دیگری ثبت شده است؛ بررسی انسانی لازم است.');
                $j['late_receipt']=$j['late_receipt']??($evidence+['at'=>time()]);
                return ['id'=>$id,'state'=>'uncertain','verification'=>'late_external_reported'];
            }
            if(in_array($j['state'],['published','scheduled_external','failed','uncertain'],true)&&!($j['state']==='scheduled_external'&&$state==='published')){if($j['state']===$state&&($j['receipt']??'')===$receipt)return ['id'=>$id,'state'=>$state];throw new RuntimeException('نتیجه قبلاً ثبت شده است.');}
            if(!in_array($j['state'],['claimed','scheduled_external'],true))throw new RuntimeException('وضعیت انتشار معتبر نیست.');
            $j['state']=$state;$j['receipt']=$receipt;$j['verification']='external_reported';$j['finished']=time();return ['id'=>$id,'state'=>$state,'verification'=>'external_reported'];
        });
    }
    public function cancel($project,$id){$project=self::fresh($project);self::allowed('use');$this->store->transaction(static function(&$s)use($project,$id){if(!is_string($id)||!isset($s['social_jobs'][$id]))throw new RuntimeException('انتشار یافت نشد.');$j=&$s['social_jobs'][$id];if($j['project']!==$project['id']||$j['state']!=='queued')throw new RuntimeException('فقط انتشار در صف قابل لغو است.');$j['state']='cancelled';$j['finished']=time();});}
    public function plan($project,$grant,$args){
        $dir=sys_get_temp_dir().'/maarksho-website-'.substr(hash('sha256',dirname(__DIR__,2)),0,16);if(!is_dir($dir))mkdir($dir,0700,true);$lock=fopen($dir.'/'.hash('sha256',$project['id']).'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('قفل تقویم آماده نیست.');
        try{$fresh=getCurrentProject($project['id'],true);if(!$fresh)throw new RuntimeException('پروژه یافت نشد.');return $this->planUnlocked($fresh,$grant,$args);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function planUnlocked($project,$grant,$args){
        AiAccess::scope($grant,'social.write');self::allowed('use');AiAccess::write();
        $request=$args['request_id']??'';$items=$args['items']??null;
        if(!is_string($request)||!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$request)||!is_array($items)||!$items||count($items)>30)throw new RuntimeException('برنامه باید شناسهٔ یکتا و ۱ تا ۳۰ محتوا داشته باشد.');
        $patch=[];$ids=[];
        foreach($items as $i=>$item){$platform=$item['platform']??'';$kind=$item['kind']??'post';$at=$item['scheduled_at']??0;$caption=$item['caption']??'';$media=$item['media']??[];
            if(!in_array($platform,['telegram','instagram'],true)||!self::route($project['settings'],$platform)||!in_array($kind,$platform==='telegram'?['post']:['post','story','reel','carousel'],true)||!is_int($at)||$at<time()||$at>time()+366*86400||!is_string($caption)||mb_strlen($caption)>10000||!is_array($media)||count($media)>10)throw new RuntimeException('ردیف برنامه یا زمان آینده معتبر نیست.');
            foreach($media as $url)if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')throw new RuntimeException('آدرس رسانه HTTPS لازم است.');
            if($platform==='instagram'&&!$media)throw new RuntimeException('رسانهٔ اینستاگرام لازم است.');
            $productId=(string)($item['product_id']??'');$product=$productId!==''?AiContent::find($project,['product_id'=>$productId]):null;
            $id=substr(hash('sha256',$project['id'].'|plan|'.$request.'|'.$i),0,32);$ids[]=$id;
            $row=['id'=>$id,'productId'=>$productId,'productName'=>$product['name']??'برنامهٔ ChatGPT','platform'=>$platform==='instagram'&&$kind==='story'?'instagram_story':$platform,'caption'=>$caption,'mediaUrl'=>implode(',',$media).'|'.$kind,'scheduledAt'=>$at,'status'=>'pending','approvalStatus'=>'pending','created_at'=>time()];
            foreach($project['schedules']??[] as $old)if((string)$old['id']===$id){foreach(['platform','caption','mediaUrl','scheduledAt','productId'] as $key)if(($old[$key]??'')!==$row[$key])throw new RuntimeException('برنامه با این شناسه و محتوای متفاوت از قبل وجود دارد.');continue 2;}
            $patch[]=$row;
        }
        if($patch&&!saveProject($project,null,$patch))throw new RuntimeException('ثبت تقویم انجام نشد.');return ['schedule_ids'=>$ids,'created'=>count($patch),'approval_status'=>'pending','note'=>'برنامه در تقویم ثبت شد و تا تأیید عادی تقویم ارسال نمی‌شود.'];
    }
    public function reconcile($project){
        $project=self::fresh($project,false);if(!empty($project['settings']['backup_restore_paused']))return;
        $state=$this->store->read();$patch=[];
        foreach($project['schedules']??[] as $schedule)foreach($state['social_jobs']??[] as $j){
            if($j['project']!==$project['id']||($j['schedule_id']??'')===''||(string)$j['schedule_id']!==(string)$schedule['id']||(int)($j['recovery_revision']??-1)!==(int)($project['settings']['data_recovery_revision']??0)||!hash_equals($j['schedule_fingerprint']??'',self::scheduleFingerprint($schedule)))continue;
            if(!in_array($j['state'],['published','scheduled_external','failed','uncertain','cancelled'],true))continue;
            $status=['published'=>'completed','scheduled_external'=>'scheduled_external','failed'=>'failed','uncertain'=>'awaiting_chatgpt','cancelled'=>'cancelled'][$j['state']];
            if($schedule['status']!==$status){$schedule['status']=$status;$patch[]=$schedule;}
        }
        if($patch)saveProject($project,null,$patch);
    }
    public static function legacy($product,$platform,$settings,$caption,$media,$at=null,$scheduleId='',$kind='post',$project=null){
        require_once __DIR__.'/Bootstrap.php';$project=$project?:getCurrentProject();if(!$project)throw new RuntimeException('پروژهٔ انتشار یافت نشد.');
        $urls=is_array($media)?$media:array_values(array_filter(explode(',',explode('|',(string)$media)[0])));$urls=array_map([AiData::class,'media'],$urls);
        if(is_string($media)&&preg_match('/\|(story|reel|carousel)$/',$media,$match))$kind=$match[1];
        $date=is_int($at)&&$at>0?$at:time();$request=$scheduleId!==''?'schedule-'.substr(hash('sha256',$scheduleId.'|'.self::scheduleFingerprint(self::schedule($project,(string)$scheduleId))),0,48):'send-'.hash('sha256',json_encode([$product['id']??'', $platform,$caption,$urls,$at]));
        $linkedId='';foreach($project['products']??[] as $candidate)if((string)($candidate['id']??'')===(string)($product['id']??'')){$linkedId=(string)$candidate['id'];break;}
        return (new self(new AiStore()))->enqueue($project,['request_id'=>$request,'platform'=>$platform==='instagram_story'?'instagram':$platform,'kind'=>$platform==='instagram_story'?'story':$kind,'caption'=>$caption?:($product['name']??''),'media'=>$urls,'product_id'=>$linkedId,'scheduled_at'=>$date,'schedule_id'=>$scheduleId],$_SESSION['user']['username']??'scheduler');
    }
}
