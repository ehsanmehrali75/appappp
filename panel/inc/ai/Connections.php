<?php
/** Safe project connection inventory: configuration, authorization and observation are separate. */
class ProjectConnections {
    private $store;
    public function __construct($store){$this->store=$store;}
    public function catalog($project){
        $s=$this->store->read();$profile=IntegrationHealth::profile($project);$pid=$project['id'];$base='?project='.rawurlencode($pid);$health=(new IntegrationHealth($this->store))->overview($project);$sources=(new AiHub($this->store))->sources($project);$rows=[];
        $tiam=$project['settings']['tiam_inventory']??[];$legacy=$project['settings']['tiam']??[];
        if(canAccessFeature('extract','view'))$rows[]=['id'=>'tiam','title'=>'سایت و ERP تیام','configured'=>!empty($tiam['host'])||!empty($tiam['agent_token_hash'])||!empty($legacy['endpoint']),'authorized'=>false,'last_received'=>$tiam['last_success']??null,'error'=>$tiam['last_error']??null,'note'=>'مسیر SQL / ایجنت / فایل معتبر قبلی حفظ شده است. زمان آخرین دریافت، آزمون سلامت فعلی سایت نیست.','url'=>'/panel/data-hub.php'.$base.'#hub-health'];
        if(canAccessFeature('wp_crm','view')){$cfg=$project['settings']['wordpress']??[];$test=$health['tests']['wordpress']??[];require_once dirname(__DIR__).'/WordPressWorkspace.php';$wpState=(new WordPressWorkspace())->state($pid);$received=$wpState['last_received']??[];$last=$received?max($received):null;$rows[]=['id'=>'wordpress','title'=>'WordPress / WooCommerce','configured'=>!empty($cfg['site_url'])&&!empty($cfg['token']),'authorized'=>!empty($test['success']),'last_received'=>$last,'error'=>$test['error']??null,'note'=>'تست خواندن و دریافت محصولات، محتوا و فروش از مسیر افزونه؛ تست خواندن، رسید انتشار نیست.','url'=>'/panel/wp-crm.php'.$base];}
        foreach(['gsc'=>'Google Search Console','ga4'=>'Google Analytics 4','composio'=>'Composio'] as $kind=>$title){if($kind==='composio'?!AiHub::canKind('gsc'):!AiHub::canKind($kind))continue;$found=[];$configured=false;$last=null;$error=null;$complete=null;
            foreach($sources as $source){$raw=$s['hub_sources'][$source['id']]??[];$k=$kind==='composio'?'gsc':$kind;if(!in_array($k,$source['kinds'],true)||($kind==='composio'&&empty($raw['composio']))||($kind!=='composio'&&empty($raw['google'])))continue;
                $enabled=!empty($source['enabled']);$configured=$configured||($enabled&&($kind==='composio'?!empty($raw['composio']['key']):!empty($raw['google']['refresh_token'])));$progress=$kind==='composio'?($s['composio_sync'][$source['id']]??[]):($s['google_sync'][$source['id'].':'.$k]??[]);$received=$source['last_received'][$k]??null;$done=$source['last_complete'][$k]??null;if($enabled){$last=max($last??0,$received??0)?:null;$complete=max($complete??0,$done??0)?:null;if(!empty($progress['last_error']))$error=$progress['last_error'];}$found[]=['id'=>$source['id'],'name'=>$source['name'],'enabled'=>$enabled,'last_received'=>$received,'last_complete'=>$done,'last_error'=>$progress['last_error']??null];}
            $rows[]=['id'=>$kind,'title'=>$title,'configured'=>$configured,'authorized'=>false,'last_received'=>$last,'last_complete'=>$complete,'error'=>$error,'sources'=>$found,'note'=>'دریافت موفق از تاریخ و منبع مشخص دیده می‌شود؛ داشتن تنظیمات به‌تنهایی موفقیت دریافت نیست.','url'=>'/panel/data-hub.php'.$base.($kind==='composio'?'#hub-composio':'#hub-google')];}
        if(AiAccess::pageAllowed()){$grants=0;foreach($s['grants']??[] as $g)if(($g['project']??'')===$pid&&empty($g['revoked']))$grants++;$rows[]=['id'=>'chatgpt','title'=>'ChatGPT / MCP مارک‌شو','configured'=>$grants>0,'authorized'=>false,'last_received'=>null,'note'=>$grants?'مجوز اتصال صادر شده است؛ نتیجهٔ فراخوانی واقعی را در ChatGPT بررسی کنید.':'اپ را ثبت کنید و از حساب ChatGPT خودتان مجوز همین پروژه را تأیید کنید.','url'=>'/panel/ai-connect.php'.$base];
            if($account=SaaSAccounts::projectAccount($pid)){
                $central=SaaSAccounts::snapshot();$configured=false;$last=null;$employees=[];
                foreach(['nahal'=>'نهال','ehsan'=>'احسان','aria'=>'آریا'] as $employee=>$label){
                    $available=false;$models=$employee==='nahal'?($central['image_models']??[]):($central['central_providers']??[]);
                    foreach($models as $m)if(!empty($m['enabled'])&&($employee==='nahal'||!empty($m[$employee.'_enabled'])))$available=true;
                    $received=null;$table=['nahal'=>'image_jobs','ehsan'=>'ehsan_jobs','aria'=>'aria_jobs'][$employee];
                    foreach($central[$table]??[] as $j)if(($j['project']??'')===$pid&&!empty($j['completed_at'])&&in_array($j['state']??'',['done','review','applying','accepted','rejected'],true))$received=max($received??0,$j['completed_at']);
                    $employees[]=['id'=>$employee,'name'=>$label,'entitled'=>($account['employees'][$employee]??0)>0,'active'=>!empty($account['active_employees'][$employee][$pid]),'model_available'=>$available,'last_received'=>$received];
                    $configured=$configured||$available;if($received)$last=max($last??0,$received);
                }
                $rows[]=['id'=>'central_ai','title'=>'موتور AI مرکزی و کارمندها','configured'=>$configured,'authorized'=>false,'last_received'=>$last,'employees'=>$employees,'note'=>'مدل آماده، خرید دسترسی و فعال‌سازی پروژه جدا هستند. کلید با تیم مرکزی است و اجرا از کردیت پروژه مصرف می‌کند؛ ثبت مدل، تست زنده یا موجودی سرویس‌دهنده نیست.','url'=>'/panel/my-employees.php'.$base];
            }else{
            $providers=(new AiReports($this->store))->providers(AiAccess::principal($project));$configured=false;$verified=false;foreach($providers as $p){if(!AiReports::eligible($p,AiAccess::principal($project)))continue;$configured=true;$test=$s['provider_tests'][$p['id']]??[];if(!empty($test['success'])&&($test['revision']??'')===($p['revision']??'')&&($test['at']??0)>time()-86400)$verified=true;}$rows[]=['id'=>'openai','title'=>'API هوش مصنوعی پروژه','configured'=>$configured,'authorized'=>$verified,'last_received'=>null,'note'=>'کلید و هزینه متعلق به مالک پروژه است. تست مدل فقط خواندنی است و تولید یا موجودی اعتبار را تأیید نمی‌کند.','url'=>'/panel/ai-connect.php'.$base.'&view=providers'];
            }
        }
        return ['project'=>$pid,'profile'=>$profile,'connections'=>$rows];
    }
}
