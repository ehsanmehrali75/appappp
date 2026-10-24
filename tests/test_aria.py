"""Aria, publication and SMS contracts in disposable databases; no live paid requests."""
import unittest
from test_saas_accounts import SaaSAccounts as Base

class Aria(unittest.TestCase):
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    php=Base.php
    def planner(self,code):
        return self.php("""require $argv[1].'/panel/inc/saas/Aria.php';require $argv[1].'/panel/inc/saas/AriaPublisher.php';require $argv[1].'/panel/inc/saas/ProjectSms.php';$svc->grantEmployee($id,'aria',1,'fixture');$svc->activate($id,'p','aria',true);(new SaaSProviders($svc))->save(['id'=>'planner','provider'=>'openai','model'=>'fixture-planner','key'=>'fixture-not-a-real-key-12345','enabled'=>true,'aria_enabled'=>true,'credits'=>5,'max_output_tokens'=>4096]);$calls=0;$report=['summary'=>'Controlled plan fixture','findings'=>[],'opportunities'=>[],'limitations'=>['Live services not connected'],'actions'=>[['title'=>'Content topic','description'=>'Human writing brief','priority'=>'medium','channel'=>'telegram','publish_at'=>date('Y-m-d',strtotime('+1 day')).' 12:00','production_due'=>date('Y-m-d',strtotime('+1 day')).' 09:00','image_brief'=>'Reference image brief']]];$e=new Aria($svc,function($url,$body,$headers)use(&$calls,&$report){$calls++;return ['status'=>200,'body'=>['id'=>'fixture-provider-receipt','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]],'usage'=>['input_tokens'=>10,'output_tokens'=>20]]];});$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$config=['model'=>'planner','goal'=>'Plan useful content','budget'=>10,'channels'=>['telegram','sms'],'start'=>date('Y-m-d',strtotime('+1 day')),'count'=>1,'frequencies'=>[]];$e->configure($p,$grant,$config);"""+code)
    def approved(self,code):
        return self.planner("$job=$e->enqueue($p,$grant,'New weekly calendar','plan-one','weekly');$e->runOne();$e->approveCalendar($p,$job,true);$fresh=getCurrentProject('p',true);$entry=array_key_first($fresh['settings']['aria_calendar']['plans'][$job]['entries']);"+code)
    def test_queue_is_idempotent_and_monthly_budget_reserves_before_call(self):
        r=self.planner("$j=$e->enqueue($p,$grant,'Request','one','weekly');$same=$e->enqueue($p,$grant,'Request','one','weekly');$e->enqueue($p,$grant,'Request','two','monthly');$blocked=false;try{$e->enqueue($p,$grant,'Request','three','weekly');}catch(RuntimeException $x){$blocked=true;}echo json_encode([$j===$same,$blocked,$calls,$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[True,True,0,{'balance':100,'reserved':10}])
    def test_proposal_is_not_an_approved_calendar_or_task(self):
        r=self.planner("$j=$e->enqueue($p,$grant,'Request','one','weekly');$e->runOne();echo json_encode([$svc->state()['aria_jobs'][$j]['state'],$svc->account($id)['wallets']['p'],getCurrentProject('p',true)['settings']['aria_calendar']??[],getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]]);")
        self.assertEqual(r,['done',{'balance':95,'reserved':0},[],[]])
    def test_approved_calendar_creates_exactly_one_real_task_and_no_image_job(self):
        r=self.approved("$e->approveCalendar($p,$job,true);$tasks=getCurrentProject('p',true)['settings']['task_manager_v3']['tasks'];echo json_encode([count($tasks),$tasks[0]['assignment_state'],$tasks[0]['assignees'],$tasks[0]['channel'],count($svc->state()['image_jobs']??[])]);")
        self.assertEqual(r,[1,'needs_assignee',[],'telegram',0])
    def test_project_members_are_assigned_from_approved_role_pools(self):
        r=self.planner("$config['writer']=['customer'];$config['designer']=['customer'];$config['reviewer']=['customer'];$e->configure($p,$grant,$config);$job=$e->enqueue($p,$grant,'Request','one','weekly');$e->runOne();$e->approveCalendar($p,$job,true);$task=getCurrentProject('p',true)['settings']['task_manager_v3']['tasks'][0];echo json_encode([$task['assignees'],$task['assignment_state']]);")
        self.assertEqual(r,[['customer'],'assigned'])
    def test_invalid_members_and_auto_sms_are_rejected_before_config_write(self):
        r=self.planner("$before=$e->config($p);$bad=0;foreach([['writer'=>['outside']],['auto_publish'=>['sms']]] as $change){try{$e->configure($p,$grant,array_replace($config,$change));}catch(RuntimeException $x){$bad++;}}echo json_encode([$bad,$before===$e->config($p)]);")
        self.assertEqual(r,[2,True])
    def test_unrequested_channel_malformed_dates_and_forged_approvals_never_become_tasks(self):
        r=self.planner("$report['actions'][0]['channel']='bale';$report['actions'][0]['state']='approved';$j=$e->enqueue($p,$grant,'Request','one','weekly');$e->runOne();echo json_encode([$svc->state()['aria_jobs'][$j]['state'],$svc->account($id)['wallets']['p'],getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]]);")
        self.assertEqual(r,['uncertain',{'balance':100,'reserved':5},[]])
    def test_rejected_calendar_creates_no_task_and_cannot_be_reapproved(self):
        r=self.planner("$j=$e->enqueue($p,$grant,'Request','one','weekly');$e->runOne();$e->approveCalendar($p,$j,false);$bad=false;try{$e->approveCalendar($p,$j,true);}catch(RuntimeException $x){$bad=true;}echo json_encode([$bad,getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]]);")
        self.assertEqual(r,[True,[]])
    def test_content_change_invalidates_old_approval_and_stale_tab(self):
        r=self.approved("$e->content($p,$job,$entry,['caption'=>'Human copy','revision'=>0]);$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];$e->approveContent($p,$job,$entry,1,$r['content_hash'],true);$e->content($p,$job,$entry,['caption'=>'Revised copy','revision'=>1]);$bad=false;try{$e->approveContent($p,$job,$entry,1,$r['content_hash'],true);}catch(RuntimeException $x){$bad=true;}$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];echo json_encode([$bad,$r['state'],$r['approval'],$r['revision']]);")
        self.assertEqual(r,[True,'awaiting_approval',None,2])
    def test_restore_epoch_blocks_calendar_approval_and_does_not_create_task(self):
        r=self.planner("$j=$e->enqueue($p,$grant,'Request','one','weekly');$e->runOne();ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;});$p=getCurrentProject('p',true);$bad=false;try{$e->approveCalendar($p,$j,true);}catch(RuntimeException $x){$bad=true;}echo json_encode([$bad,getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]]);")
        self.assertEqual(r,[True,[]])
    def test_cross_project_and_stale_selected_project_are_blocked(self):
        r=self.approved("$_SESSION['project_id']='outside';$bad=0;try{$e->approveCalendar($p,$job,true);}catch(RuntimeException $x){$bad++;}try{$e->content($p,$job,$entry,['caption'=>'Bad','revision'=>0]);}catch(RuntimeException $x){$bad++;}echo json_encode($bad);")
        self.assertEqual(r,2)
    def test_ambiguous_call_is_not_retried_and_can_be_settled_centrally(self):
        r=self.planner("$j=$e->enqueue($p,$grant,'Request','one','weekly');$worker=new Aria($svc,fn()=>throw new RuntimeException('fixture transport'));$worker->runOne();$again=$worker->runOne();$e->resolve($j,2);echo json_encode([$again,$svc->state()['aria_jobs'][$j]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[False,'reviewed',{'balance':98,'reserved':0}])
    def test_automatic_weekly_review_does_not_modify_approved_calendar(self):
        r=self.approved("$before=getCurrentProject('p',true)['settings']['aria_calendar'];$svc->change(function(&$s){$s['aria_configs']['p']['frequencies']=['weekly'];$s['aria_configs']['p']['next_due']=['weekly'=>time()-1];});$e->tick();$e->tick();echo json_encode([$before===getCurrentProject('p',true)['settings']['aria_calendar'],count($svc->state()['aria_jobs'])]);")
        self.assertEqual(r,[True,2])
    def test_calendar_data_source_contains_approved_aria_entries(self):
        r=self.approved("$fresh=getCurrentProject('p',true);$data=(new AiData(new AiStore()))->read($fresh,'calendar',[],0,100);echo json_encode(array_values(array_filter($data['rows'],fn($r)=>($r['origin']??'')==='aria')));")
        self.assertEqual(len(r),1);self.assertEqual(r[0]['status'],'awaiting_content')
    def test_sms_credentials_are_encrypted_and_preview_is_not_send(self):
        r=self.planner("$n=new ProjectSms($svc,fn()=>throw new RuntimeException('Must not call provider'));$n->save($p,['provider'=>'najva','sender'=>'10001234','secret'=>'fixture-secret-only']);$r=$n->preview($p,'najva','Human SMS','09123456789, +989123456789','preview');$raw=$svc->state()['project_sms']['p']['najva'];echo json_encode([$r['count'],$r['state'],$r['cost'],$raw['secret']!=='fixture-secret-only',isset($n->connections($p)['najva']['secret'])]);")
        self.assertEqual(r,[1,'awaiting_provider_quote',None,True,False])
    def sms(self,code):
        return self.planner("$smsCalls=0;$n=new ProjectSms($svc,function($url,$body,$headers=[])use(&$smsCalls){$smsCalls++;return ['status'=>200,'body'=>['Value'=>'123456789','RetStatus'=>1]];});$n->save($p,['provider'=>'melipayamak','username'=>'fixture-user','sender'=>'10001234','secret'=>'fixture-secret-only']);$campaign=$n->preview($p,'melipayamak','Human SMS','09123456789','preview');"+code)
    def test_sms_send_requires_exact_count_cost_hash_and_explicit_consent(self):
        r=self.sms("$n->quote($p,$campaign['id'],1000,'Provider panel invoice fixture');$q=$n->campaigns($p)[0];$bad=0;foreach([[0,1000,true],[1,999,true],[1,1000,false]] as $args){try{$n->send($p,$q['id'],$q['quote_hash'],$args[0],$args[1],$args[2]);}catch(RuntimeException $x){$bad++;}}echo json_encode([$bad,$smsCalls,$n->campaigns($p)[0]['state']]);")
        self.assertEqual(r,[3,0,'quoted'])
    def test_sms_single_send_is_not_delivery_and_replay_never_sends_twice(self):
        r=self.sms("$n->quote($p,$campaign['id'],1000,'Provider panel invoice fixture');$q=$n->campaigns($p)[0];$state=$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);$bad=false;try{$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);}catch(RuntimeException $x){$bad=true;}echo json_encode([$state,$bad,$smsCalls,$n->campaigns($p)[0]['receipt']['delivery_verified']]);")
        self.assertEqual(r,['accepted',True,1,False])
    def test_sms_changed_connection_invalidates_campaign_consent(self):
        r=self.sms("$n->quote($p,$campaign['id'],1000,'Provider panel invoice fixture');$q=$n->campaigns($p)[0];$n->save($p,['provider'=>'melipayamak','username'=>'fixture-user','sender'=>'10001235','secret'=>'']);$bad=false;try{$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);}catch(RuntimeException $x){$bad=true;}echo json_encode([$bad,$smsCalls]);")
        self.assertEqual(r,[True,0])
    def test_sms_price_response_is_not_a_quote_when_operator_counts_are_incomplete(self):
        r=self.sms("$bad=false;try{$n->quoteFromProvider($p,$campaign['id'],0,0);}catch(RuntimeException $x){$bad=true;}echo json_encode([$bad,$smsCalls]);")
        self.assertEqual(r,[True,0])
    def publication(self,code):
        return self.approved("ProjectOnboarding::mutate('p',function(&$p)use($job,$entry){$p['settings']['aria_calendar']['plans'][$job]['entries'][$entry]['publish_at']=date('Y-m-d H:i',time()-60);$p['settings']['connections']['telegram']=['token'=>'fixture-token-only-12345','chatId'=>'fixture-chat'];});$p=getCurrentProject('p',true);$e->content($p,$job,$entry,['caption'=>'Final human text','revision'=>0]);$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];$e->approveContent($p,$job,$entry,1,$r['content_hash'],true);"+code)
    def test_publication_requires_approved_content_and_records_real_transport_receipt(self):
        r=self.publication("$sent=0;$pub=new AriaPublisher($svc,function($url,$body,$headers)use(&$sent){$sent++;return ['status'=>200,'body'=>['ok'=>true,'result'=>['message_id'=>123]]];});$pub->tick();$pub->tick();$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];echo json_encode([$sent,$r['state'],$r['receipt']['message_id']]);")
        self.assertEqual(r,[1,'published',123])
    def test_publication_timeout_does_not_retry_a_potential_duplicate(self):
        r=self.publication("$sent=0;$pub=new AriaPublisher($svc,function()use(&$sent){$sent++;throw new RuntimeException('fixture interrupted');});$pub->tick();$pub->tick();echo json_encode([$sent,getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry]['state']]);")
        self.assertEqual(r,[1,'uncertain'])
    def test_paused_calendar_stops_publication_without_deleting_tasks(self):
        r=self.publication("$e->pauseCalendar($p,$job);$sent=0;$pub=new AriaPublisher($svc,function()use(&$sent){$sent++;return [];});$pub->tick();echo json_encode([$sent,count(getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']),getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['state']]);")
        self.assertEqual(r,[0,1,'paused'])
    def test_overlapping_approved_calendar_needs_explicit_pause_before_replacement(self):
        r=self.approved("$second=$e->enqueue($p,$grant,'Revised plan','plan-two','weekly');$e->runOne();$bad=false;try{$e->approveCalendar($p,$second,true);}catch(RuntimeException $x){$bad=true;}$e->pauseCalendar($p,$job);$e->approveCalendar($p,$second,true);echo json_encode([$bad,getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$second]['state']]);")
        self.assertEqual(r,[True,'approved'])
    def test_sms_najva_payload_and_receipts_follow_documented_campaign_contract(self):
        r=self.planner("$sent=0;$payload=null;$n=new ProjectSms($svc,function($url,$body,$headers)use(&$sent,&$payload){$sent++;$payload=[$url,$body['receivers'],$body['message'],strpos($headers[0],'Authorization: Bearer ')===0];return ['status'=>200,'body'=>['return'=>['status'=>200],'entries'=>[['messageid'=>555,'receptor'=>'09123456789','status'=>1,'cost'=>1000]]]];});$n->save($p,['provider'=>'najva','sender'=>'10001234','secret'=>'fixture-token']);$campaign=$n->preview($p,'najva','Human SMS','09123456789','preview');$n->quote($p,$campaign['id'],1000,'fixture panel quote');$q=$n->campaigns($p)[0];$result=$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);echo json_encode([$sent,$payload,$result,$n->campaigns($p)[0]['receipt']['reported_cost']]);")
        self.assertEqual(r,[1,['https://sms.najva.com/v2/sms/send',['09123456789'],'Human SMS',True],'accepted',1000])
    def test_sms_api_quote_keeps_declared_operator_provenance_and_does_not_send(self):
        r=self.sms("$n->quoteFromProvider($p,$campaign['id'],1,0);$q=$n->campaigns($p)[0];echo json_encode([$q['quote_source'],$q['state'],$q['currency'],$smsCalls]);")
        self.assertEqual(r,['provider_api_declared_operator_counts','quoted','IRR',1])
    def test_sms_ambiguous_send_remains_uncertain_and_cannot_be_repeated(self):
        r=self.sms("$n=new ProjectSms($svc,fn()=>throw new RuntimeException('fixture timeout'));$n->quote($p,$campaign['id'],1000,'fixture panel quote');$q=$n->campaigns($p)[0];$first=$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);$bad=false;try{$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);}catch(RuntimeException $x){$bad=true;}echo json_encode([$first,$bad]);")
        self.assertEqual(r,['uncertain',True])
    def test_image_handoff_is_durable_scoped_and_has_no_automatic_charge(self):
        r=self.approved("$svc->activate($id,'p','nahal',true);$before=$svc->account($id)['wallets']['p'];$handoff=$e->nahalRequest($p,$job,$entry);$e->nahalRequest($p,$job,$entry);$n=new Nahal($svc);$count=count($n->handoffs($p));$e->pauseCalendar($p,$job);echo json_encode([$count,count($n->handoffs($p)),$before===$svc->account($id)['wallets']['p'],strpos($handoff['url'],'handoff=')!==false]);")
        self.assertEqual(r,[1,0,True,True])
    def test_manual_task_assignment_updates_aria_only_from_the_allowed_writer_pool(self):
        r=self.approved("$config['writer']=['customer'];$e->configure($p,$grant,$config);ProjectOnboarding::mutate('p',function(&$p)use($entry){foreach($p['settings']['task_manager_v3']['tasks'] as &$task)if($task['id']===$entry)$task['assignees']=['customer'];});echo json_encode($e->calendars(getCurrentProject('p',true))[0]['entries'][$entry]['roles']['writer']);")
        self.assertEqual(r,'customer')
    def test_http_page_requires_csrf_and_connection_page_is_scoped(self):
        owner,_=self.case.login();self.assertEqual(self.case.request(owner,'/panel/aria.php?project=p',{'action':'configure'})[0],403)

del Base
