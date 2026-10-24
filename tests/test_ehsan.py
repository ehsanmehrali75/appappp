"""Project analyst contracts; controlled provider/mail responses in disposable sites, never live AI."""
import json,unittest
from test_saas_accounts import SaaSAccounts as Base

class Ehsan(unittest.TestCase):
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    php=Base.php
    def analyst(self,code):
        setup="""require $argv[1].'/panel/inc/saas/Ehsan.php';$svc->change(function(&$s)use($id){$s['accounts'][$id]['employees']['ehsan']=1;});$svc->activate($id,'p','ehsan',true);$providers=new SaaSProviders($svc);$providers->save(['id'=>'analyst','provider'=>'openai','model'=>'configured-analyst','key'=>'fixture-secret-not-a-real-key','enabled'=>true,'ehsan_enabled'=>true,'credits'=>5,'max_output_tokens'=>2048]);$report=['summary'=>'Source-backed fixture analysis','findings'=>[['title'=>'Stock review','evidence'=>'products source at fixture date','source_ids'=>['products'],'severity'=>'warning']],'opportunities'=>['Review available inventory'],'actions'=>[['title'=>'Review products','description'=>'Inspect product records','priority'=>'medium']],'limitations'=>['No Google account connected']];$calls=0;$e=new Ehsan($svc,function($url,$body,$headers)use(&$calls,$report){$calls++;return ['status'=>200,'body'=>['id'=>'provider-fixture-receipt','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]],'usage'=>['input_tokens'=>20,'output_tokens'=>30]]];});$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$config=['model'=>'analyst','goal'=>'Improve sales using project data','budget'=>10,'frequencies'=>[]];$e->configure($p,$grant,$config);"""
        return self.php(setup+code)
    def test_queue_is_idempotent_and_budget_includes_reservations(self):
        r=self.analyst("$one=$e->enqueue($p,$grant,'Question','first-request');$same=$e->enqueue($p,$grant,'Question','first-request');$e->enqueue($p,$grant,'Question','second-request');$blocked=false;try{$e->enqueue($p,$grant,'Question','third-request');}catch(RuntimeException $x){$blocked=true;}echo json_encode([$one===$same,$blocked,$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[True,True,{'balance':100,'reserved':10}])
    def test_generation_stores_real_fixture_receipt_and_does_not_create_tasks(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$e->runOne();$j=$svc->state()['ehsan_jobs'][$job];echo json_encode([$calls,$j['state'],$j['provider_request_id'],$svc->account($id)['wallets']['p'],$svc->state()['ehsan_usage']['p'][date('Y-m')],getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]]);")
        self.assertEqual(r,[1,'done','provider-fixture-receipt',{'balance':95,'reserved':0},{'reserved':0,'spent':5},[]])
    def test_failed_auth_releases_monthly_and_wallet_reservations(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');(new Ehsan($svc,fn()=>['status'=>401,'body'=>[]]))->runOne();echo json_encode([$svc->state()['ehsan_jobs'][$job]['state'],$svc->account($id)['wallets']['p'],$svc->state()['ehsan_usage']['p'][date('Y-m')]['reserved']]);")
        self.assertEqual(r,['failed',{'balance':100,'reserved':0},0])
    def test_ambiguous_generation_is_not_retried_and_needs_central_settlement(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$worker=new Ehsan($svc,function(){throw new RuntimeException('fixture disconnect');});$worker->runOne();$again=$worker->runOne();$held=$svc->account($id)['wallets']['p'];$state=$svc->state()['ehsan_jobs'][$job]['state'];$worker->resolve($job,3);echo json_encode([$state,$again,$held,$svc->account($id)['wallets']['p'],$svc->state()['ehsan_usage']['p'][date('Y-m')]]);")
        self.assertEqual(r,['uncertain',False,{'balance':100,'reserved':5},{'balance':97,'reserved':0},{'reserved':0,'spent':3}])
    def test_task_requires_approval_is_idempotent_and_does_not_publish(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$e->runOne();$action=$svc->state()['ehsan_jobs'][$job]['report']['actions'][0]['id'];$e->task($p,$job,$action,true);$e->task($p,$job,$action,true);echo json_encode([count(getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']),$svc->state()['ehsan_jobs'][$job]['report']['actions'][0]['state'],getCurrentProject('p',true)['products'][0]['image']]);")
        self.assertEqual(r[0],1);self.assertEqual(r[1],'approved');self.assertEqual(r[2],'uploads/original.png')
    def test_schedules_claim_once_and_automatic_tasks_are_opt_in(self):
        r=self.analyst("$e->configure($p,$grant,$config+['auto_tasks'=>true]);$svc->change(function(&$s){$s['ehsan_configs']['p']['next_due']['daily']=time()-1;});$e->tick();$e->tick();$count=count($svc->state()['ehsan_jobs']);$e->runOne();$e->tick();echo json_encode([$count,count(getCurrentProject('p',true)['settings']['task_manager_v3']['tasks'])]);")
        self.assertEqual(r,[1,1])
    def test_notifications_opt_in_and_claim_once_with_transport_acceptance(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$e->runOne();$none=$e->notifyOne(fn()=>true);$e->configure($p,$grant,$config+['notify_email'=>true,'email'=>'fixture@example.test','notify_telegram'=>true]);$sent=[];$transport=function($channel,$msg)use(&$sent){$sent[]=$channel;return true;};$e->notifyOne($transport);$e->notifyOne($transport);$again=$e->notifyOne($transport);echo json_encode([$none,$sent,$again,array_column($svc->state()['ehsan_deliveries'],'state')]);")
        self.assertEqual(r,[False,['email','telegram'],False,['accepted','accepted']])
    def test_invalid_identity_is_rejected_before_any_paid_call(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$svc->change(function(&$s)use($job){$s['ehsan_jobs'][$job]['principal']['identity']['proof']='revoked';});$e->runOne();echo json_encode([$calls,$svc->state()['ehsan_jobs'][$job]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[0,'failed',{'balance':100,'reserved':0}])
    def test_snapshot_is_project_scoped_and_marks_missing_sources(self):
        r=self.analyst("$snap=$e->snapshot($p,'monthly');echo json_encode([$snap['project']['id'],$snap['filters'],$snap['missing_kinds'],$snap['sources'][0]['data']['total']]);")
        self.assertEqual(r[0],'p');self.assertIn('ga4',r[2]);self.assertGreater(r[3],0);self.assertLess(r[1]['from'],r[1]['to'])
    def test_generated_action_cannot_mark_itself_approved(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$report['actions'][0]['state']='approved';$report['actions'][0]['id']='forged';$report['actions'][0]['title']='<script>bad()</script>Review';$worker=new Ehsan($svc,fn()=>['status'=>200,'body'=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]]);$worker->runOne();$action=$svc->state()['ehsan_jobs'][$job]['report']['actions'][0];echo json_encode([$action['state'],$action['id']!=='forged',strpos($action['title'],'<')===false]);")
        self.assertEqual(r,['pending',True,True])
    def test_notice_transport_failure_is_not_marked_delivered(self):
        r=self.analyst("$e->configure($p,$grant,$config+['notify_email'=>true,'email'=>'fixture@example.test']);$e->testNotifications($p,'notice-request');$e->notifyOne(fn()=>false);echo json_encode(array_column($svc->state()['ehsan_deliveries'],'state'));")
        self.assertEqual(r,['failed'])
    def test_google_statistics_scan_all_received_rows_and_keep_coverage_limits(self):
        r=self.analyst("$hub=new AiHub(new AiStore());$source=$hub->createSource($p,$grant,['name'=>'GSC fixture','origin'=>'Google fixture','store_id'=>'site','kinds'=>['gsc']]);$raw=$hub->source($p,$source['id']);$hub->ingest($p,$raw,'gsc',[['id'=>'a','date'=>date('Y-m-d'),'clicks'=>10,'impressions'=>100,'ctr'=>0.1,'position'=>2],['id'=>'b','date'=>date('Y-m-d'),'clicks'=>30,'impressions'=>300,'ctr'=>0.1,'position'=>6]],'fixture-batch',true);$snapshot=$e->snapshot($p,'question');foreach($snapshot['sources'] as $item)if(isset($item['analytics']))$analytics=$item['analytics'];echo json_encode($analytics);")
        self.assertEqual(r['records_scanned'],2);self.assertEqual(r['received_query_rows'],{'clicks':40,'impressions':400,'ctr':0.1,'position':5});self.assertIn('anonymized',r['coverage'])
    def test_all_three_provider_protocols_process_actual_response_shapes(self):
        r=self.analyst("$config['budget']=100;$e->configure($p,$grant,$config);$out=[];foreach(['openai','anthropic','avalai'] as $provider){$providers->save(['id'=>'analyst','provider'=>$provider,'model'=>'configured-analyst','key'=>'fixture-secret-not-a-real-key','enabled'=>true,'ehsan_enabled'=>true,'credits'=>5]);$job=$e->enqueue($p,$grant,'Question','provider-'.$provider);$body=$provider==='openai'?['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]:($provider==='anthropic'?['content'=>[['type'=>'text','text'=>json_encode($report)]]]:['choices'=>[['message'=>['content'=>json_encode($report)]]]]);(new Ehsan($svc,fn()=>['status'=>200,'body'=>$body]))->runOne();$out[]=$svc->state()['ehsan_jobs'][$job]['state'];}echo json_encode($out);")
        self.assertEqual(r,['done','done','done'])
    def test_snapshot_rejects_a_project_other_than_active_workspace(self):
        r=self.analyst("$foreign=$svc->createProject($id,'Second','create');$blocked=false;try{$e->snapshot(getCurrentProject($foreign,true),'question');}catch(RuntimeException $x){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)
    def test_staff_can_grant_employee_capacity_without_minting_credit(self):
        r=self.analyst("$svc->grantEmployee($id,'ehsan',2,'Authorized team allocation');$a=$svc->account($id);echo json_encode([$a['employees']['ehsan'],$a['wallets']['p']['balance'],array_slice($svc->state()['audit'],-1)[0]['action']]);")
        self.assertEqual(r,[2,100,'employee_complimentary_grant'])
    def test_budget_stop_creates_notice_without_a_paid_provider_call(self):
        r=self.analyst("$e->enqueue($p,$grant,'Question','first-request');$e->enqueue($p,$grant,'Question','second-request');try{$e->enqueue($p,$grant,'Question','third-request');}catch(RuntimeException $x){}echo json_encode([$calls,count($svc->state()['ehsan_notices']??[])]);")
        self.assertEqual(r,[0,1])
    def test_invented_source_cannot_be_published_as_a_report(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$report['findings'][0]['source_ids']=['unconnected-ga4'];(new Ehsan($svc,fn()=>['status'=>200,'body'=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]]))->runOne();$j=$svc->state()['ehsan_jobs'][$job];echo json_encode([$j['state'],isset($j['report'])]);")
        self.assertEqual(r,['uncertain',False])
    def test_restore_revision_stops_queued_analysis_and_old_notifications(self):
        r=self.analyst("$e->configure($p,$grant,$config+['notify_email'=>true,'email'=>'fixture@example.test']);$job=$e->enqueue($p,$grant,'Question','first-request');ProjectOnboarding::mutate('p',function(&$fresh){$fresh['settings']['data_recovery_revision']=1;});$e->runOne();$sent=0;$e->notifyOne(function()use(&$sent){$sent++;return true;});$blocked=false;try{$e->enqueue(getCurrentProject('p',true),$grant,'Question','after-restore');}catch(RuntimeException $x){$blocked=true;}echo json_encode([$calls,$sent,$blocked,$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[0,0,True,{'balance':100,'reserved':0}])
    def test_deactivation_stops_automatic_task_creation(self):
        r=self.analyst("$e->configure($p,$grant,$config+['auto_tasks'=>true]);$job=$e->enqueue($p,$grant,'Question','first-request');$e->runOne();$svc->activate($id,'p','ehsan',false);$e->tick();echo json_encode(getCurrentProject('p',true)['settings']['task_manager_v3']['tasks']??[]);")
        self.assertEqual(r,[])
    def test_late_provider_reply_does_not_reopen_central_settlement(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$worker=new Ehsan($svc,function()use($svc,$job,$report){$svc->change(function(&$s)use($job){$s['ehsan_jobs'][$job]['state']='uncertain';SaaSAccounts::settle($s,$job,0,true);});(new Ehsan($svc))->resolve($job,3);return ['status'=>200,'body'=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]];});$worker->runOne();echo json_encode([$svc->state()['ehsan_jobs'][$job]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,['reviewed',{'balance':97,'reserved':0}])
    def test_month_end_schedule_clamps_in_tehran_timezone(self):
        r=self.analyst("$ts=(new DateTimeImmutable('2028-01-31 12:00',new DateTimeZone('Asia/Tehran')))->getTimestamp();echo json_encode(date('Y-m-d H:i',Ehsan::next('monthly','08:00',6,31,$ts)));")
        self.assertEqual(r,'2028-02-29 08:00')
    def test_cross_project_reports_cannot_be_turned_into_tasks(self):
        r=self.analyst("$job=$e->enqueue($p,$grant,'Question','first-request');$e->runOne();$action=$svc->state()['ehsan_jobs'][$job]['report']['actions'][0]['id'];$foreign=$svc->createProject($id,'Second','create');$blocked=false;try{$e->task(getCurrentProject($foreign,true),$job,$action,true);}catch(RuntimeException $x){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)

del Base
