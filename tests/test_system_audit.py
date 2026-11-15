"""Reproductions from the system audit; temporary stores and controlled transports only."""
import unittest,re,html,sqlite3
from test_saas_accounts import SaaSAccounts as Base
from test_aria import Aria as PlanningFixture

class SystemAudit(unittest.TestCase):
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    php=Base.php
    nahal=Base.nahal
    planner=PlanningFixture.planner
    approved=PlanningFixture.approved
    publication=PlanningFixture.publication
    sms=PlanningFixture.sms
    def test_nahal_late_success_cannot_reopen_a_centrally_settled_job(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$job=$jobs[0];$worker=new Nahal($svc,function()use($svc,$n,$job){$svc->change(function(&$s)use($job){$s['image_jobs'][$job]['state']='uncertain';SaaSAccounts::settle($s,$job,0,true);});$n->resolve($job,2);return ['status'=>200,'body'=>['data'=>[['b64_json'=>base64_encode(file_get_contents($GLOBALS['argv'][1].'/panel/uploads/original.png'))]]]];});$worker->runOne();echo json_encode([$svc->state()['image_jobs'][$job]['state'],$svc->account($id)['wallets']['p'],count($svc->state()['image_assets']??[])]);")
        self.assertEqual(r,['reviewed',{'balance':98,'reserved':0},0])
    def test_nahal_late_transport_failure_cannot_reopen_a_settled_reservation(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$job=$jobs[0];$worker=new Nahal($svc,function()use($svc,$n,$job){$svc->change(function(&$s)use($job){$s['image_jobs'][$job]['state']='uncertain';SaaSAccounts::settle($s,$job,0,true);});$n->resolve($job,2);throw new RuntimeException('fixture interrupted after settlement');});$worker->runOne();echo json_encode([$svc->state()['image_jobs'][$job]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,['reviewed',{'balance':98,'reserved':0}])
    def test_restore_blocks_old_nahal_generation_before_any_paid_call(self):
        r=self.nahal("require $GLOBALS['argv'][1].'/panel/inc/ProjectOnboarding.php';$jobs=$n->enqueue($p,$grant,$input);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;});$calls=0;$worker=new Nahal($svc,function()use(&$calls){$calls++;return ['status'=>401,'body'=>[]];});$worker->runOne();echo json_encode([$calls,$svc->state()['image_jobs'][$jobs[0]]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[0,'failed',{'balance':100,'reserved':0}])
    def test_content_api_respects_account_storage_allocation(self):
        r=self.php("$svc->change(function(&$s)use($id){$s['accounts'][$id]['allocations']['p']=0;});$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$grant['scopes']=['content.write'];$before=glob($GLOBALS['argv'][1].'/panel/uploads/*');$blocked=false;try{(new AiContent(new AiStore()))->save($grant,['product_id'=>'one','kind'=>'main','request_id'=>'quota-fixture-call','base64'=>base64_encode(file_get_contents($GLOBALS['argv'][1].'/panel/uploads/original.png'))]);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$before===glob($GLOBALS['argv'][1].'/panel/uploads/*')]);")
        self.assertEqual(r,[True,True])
    def test_content_write_rejects_an_old_recovery_epoch(self):
        r=self.php("require_once $GLOBALS['argv'][1].'/panel/inc/ProjectOnboarding.php';ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;});$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$grant['scopes']=['content.write'];$blocked=false;try{(new AiContent(new AiStore()))->save($grant,['product_id'=>'one','kind'=>'description','text'=>'Must not apply','request_id'=>'old-epoch-fixture','expected_recovery_revision'=>0]);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,getCurrentProject('p',true)['products'][0]['description']]);")
        self.assertEqual(r,[True,''])
    def test_failed_content_database_write_rolls_back_file_and_product(self):
        r=self.php("""$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$grant['scopes']=['content.write'];getConn()->query("CREATE TRIGGER fail_content BEFORE INSERT ON products WHEN NEW.id='one' BEGIN SELECT RAISE(FAIL, 'fixture SQL rollback'); END");$before=glob($GLOBALS['argv'][1].'/panel/uploads/*');$blocked=false;try{(new AiContent(new AiStore()))->save($grant,['product_id'=>'one','kind'=>'main','request_id'=>'rollback-fixture-call','base64'=>base64_encode(file_get_contents($GLOBALS['argv'][1].'/panel/uploads/original.png'))]);}catch(RuntimeException $e){$blocked=true;}getConn()->query('DROP TRIGGER fail_content');$fresh=getCurrentProject('p',true);echo json_encode([$blocked,$before===glob($GLOBALS['argv'][1].'/panel/uploads/*'),$fresh['products'][0]['image']]);""")
        self.assertEqual(r,[True,True,'uploads/original.png'])

    def test_sqlite_unique_constraint_is_a_failure_not_a_success_receipt(self):
        r=self.php("$db=getConn();$db->query('CREATE TABLE audit_unique (id INTEGER PRIMARY KEY, value TEXT)');$db->query(\"INSERT INTO audit_unique VALUES (1,'original')\");$ok=$db->query(\"INSERT INTO audit_unique VALUES (1,'duplicate')\");$error=$db->error;$row=$db->query('SELECT value FROM audit_unique WHERE id=1')->fetch_assoc();echo json_encode([$ok,$error!==null,$row['value']]);")
        self.assertEqual(r,[False,True,'original'])

    def test_private_state_read_does_not_rewrite_the_financial_file(self):
        r=self.php("$store=new AiStore($GLOBALS['argv'][1].'/panel/db/audit-read');$raw=base64_decode('eyAic2FtcGxlIiA6IDEgfQo=');file_put_contents($store->directory().'/state.json',$raw);$value=$store->read();echo json_encode([$value['sample'],file_get_contents($store->directory().'/state.json')===$raw]);")
        self.assertEqual(r,[1,True])
    def test_aria_cached_ready_item_cannot_dispatch_after_calendar_pause(self):
        r=self.publication("$cached=getCurrentProject('p',true);$plan=$cached['settings']['aria_calendar']['plans'][$job];$item=$plan['entries'][$entry];$e->pauseCalendar($p,$job);$calls=0;$pub=new AriaPublisher($svc,function()use(&$calls){$calls++;return ['status'=>200,'body'=>['ok'=>true,'result'=>['message_id'=>123]]];});$method=new ReflectionMethod(AriaPublisher::class,'dispatch');$result=$method->invoke($pub,$cached,$plan,$item,'direct');echo json_encode([$result,$calls,getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['state']]);")
        self.assertEqual(r,[False,0,'paused'])
    def test_aria_destination_change_requires_content_reapproval(self):
        r=self.publication("ProjectOnboarding::mutate('p',function(&$p){$p['settings']['connections']['telegram']['chatId']='changed-destination';});$calls=0;$pub=new AriaPublisher($svc,function()use(&$calls){$calls++;return ['status'=>200,'body'=>['ok'=>true,'result'=>['message_id'=>123]]];});$pub->tick();$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];echo json_encode([$calls,$r['state']]);")
        self.assertEqual(r,[0,'ready'])
    def test_aria_external_handoff_rechecks_approval_and_reconciles_cancellation(self):
        r=self.publication("ProjectOnboarding::mutate('p',function(&$p)use($job,$entry){$p['settings']['connections']['instagram']=['enabled'=>true,'chatgpt_destination'=>'fixture-instagram','proxy'=>['bypass_method'=>'chatgpt']];$r=&$p['settings']['aria_calendar']['plans'][$job]['entries'][$entry];$r['channel']='instagram_post';$r['media']='https://example.test/approved-image.jpg';$r['approval']['destination']=Aria::destinationFingerprint($p,'instagram_post');});$pub=new AriaPublisher($svc,fn()=>throw new RuntimeException('No provider call allowed'));$pub->tick();$fresh=getCurrentProject('p',true);$row=$fresh['settings']['aria_calendar']['plans'][$job]['entries'][$entry];$social=new AiSocial(new AiStore());$grant=AiAccess::principal($fresh);$grant['scopes']=['social.write'];ProjectOnboarding::mutate('p',function(&$p)use($job,$entry){unset($p['settings']['aria_calendar']['plans'][$job]['entries'][$entry]['approval']);});$blocked=false;try{$social->claim($fresh,$grant,$row['receipt']['job']);}catch(RuntimeException $x){$blocked=true;}$social->cancel(getCurrentProject('p',true),$row['receipt']['job']);ProjectOnboarding::mutate('p',function(&$p)use($job){$p['settings']['aria_calendar']['plans'][$job]['state']='approved';});$pub->tick();$final=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];echo json_encode([$row['state'],$blocked,$final['state'],$final['receipt']['verification']]);")
        self.assertEqual(r,['queued_external',True,'cancelled','cancelled_before_handoff'])

    def test_sms_crashed_sending_is_flagged_uncertain_without_retrying(self):
        r=self.sms("$svc->change(function(&$s)use($campaign){$r=&$s['sms_campaigns'][$campaign['id']];$r['state']='sending';$r['approval']=['at'=>time()-600];});$one=$n->reconcileStale();$two=$n->reconcileStale();echo json_encode([$one,$two,$n->campaigns($p)[0]['state'],$smsCalls]);")
        self.assertEqual(r,[1,0,'uncertain',0])
    def test_late_sms_receipt_is_evidence_not_a_silent_status_reopen(self):
        r=self.sms("$n=new ProjectSms($svc,function()use($svc,$campaign){$svc->change(function(&$s)use($campaign){$s['sms_campaigns'][$campaign['id']]['state']='uncertain';});return ['status'=>200,'body'=>['Value'=>'123456789','RetStatus'=>1]];});$n->quote($p,$campaign['id'],1000,'fixture panel quote');$q=$n->campaigns($p)[0];$result=$n->send($p,$q['id'],$q['quote_hash'],1,1000,true);$row=$n->campaigns($p)[0];echo json_encode([$result,$row['state'],$row['late_receipt']['reported_state']]);")
        self.assertEqual(r,['uncertain','uncertain','accepted'])

    def test_health_detects_ledger_mismatch_without_exposing_secrets(self):
        r=self.php("require $GLOBALS['argv'][1].'/panel/inc/OperationalHealth.php';$svc->change(function(&$s)use($id){$s['accounts'][$id]['wallets']['p']['reserved']=7;$s['central_providers']['private']=['key'=>'never-show-this'];});$health=new OperationalHealth($svc);$before=$svc->state();$result=$health->project($p);echo json_encode([array_column($result['issues'],'code'),strpos(json_encode($result),'never-show-this')===false,$before===$svc->state()]);")
        self.assertIn('reservation_mismatch',r[0]);self.assertTrue(r[1]);self.assertTrue(r[2])
    def test_health_worker_completion_cannot_overwrite_a_newer_running_cycle(self):
        r=self.php("require $GLOBALS['argv'][1].'/panel/inc/OperationalHealth.php';$health=new OperationalHealth($svc);$old=$health->begin('aria');$new=$health->begin('aria');$health->finish('aria',$old,true);$during=$health->workers()['aria'];$health->finish('aria',$new,false);$after=$health->workers()['aria'];echo json_encode([$during['state'],$during['last_success']!==null,$after['state'],$after['last_failure']!==null]);")
        self.assertEqual(r,['running',True,'failed',True])
    def test_central_health_includes_unmanaged_projects_and_summarizes_private_handoffs(self):
        r=self.php("require $GLOBALS['argv'][1].'/panel/inc/OperationalHealth.php';$legacy=$p;$legacy['id']='123';$legacy['name']='Unmanaged legacy';$legacy['products']=[];db_save_project($legacy);(new AiStore())->transaction(function(&$s){$s['social_jobs']['fixture']=['project'=>'123','state'=>'claimed','lease_until'=>time()-1,'caption'=>'private-caption-sentinel','lease_hash'=>'private-lease-sentinel'];});$before=$svc->state();$report=(new OperationalHealth($svc))->central();$row=null;foreach($report['projects'] as $item)if($item['project']['id']==='123')$row=$item;echo json_encode([$row['managed'],$row['social']['expired_claims'],in_array('social_handoff_review',array_column($row['issues'],'code')),strpos(json_encode($report),'private-caption-sentinel')===false,strpos(json_encode($report),'private-lease-sentinel')===false,$before===$svc->state()]);")
        self.assertEqual(r,[False,1,True,True,True,True])
    def test_text_employee_models_are_visible_without_an_image_model_or_live_success_claim(self):
        r=self.php("$svc->change(function(&$s)use($id){$s['central_providers']['text']=['enabled'=>true,'ehsan_enabled'=>true,'aria_enabled'=>true,'key'=>'private-model-key'];$s['accounts'][$id]['employees']['ehsan']=1;$s['accounts'][$id]['active_employees']['ehsan']['p']=true;});$rows=(new ProjectConnections(new AiStore()))->catalog(getCurrentProject('p',true))['connections'];$row=null;foreach($rows as $item)if($item['id']==='central_ai')$row=$item;echo json_encode([$row['configured'],$row['authorized'],array_column($row['employees'],'model_available','id'),array_column($row['employees'],'active','id'),strpos(json_encode($rows),'private-model-key')===false]);")
        self.assertEqual(r,[True,False,{'nahal':False,'ehsan':True,'aria':True},{'nahal':False,'ehsan':True,'aria':False},True])
    def test_route_settings_reject_blank_destination_and_do_not_rewrite_products(self):
        def cleanup():
            with sqlite3.connect(self.case.database) as db:db.execute('DROP TRIGGER IF EXISTS forbid_route_product_write')
        self.addCleanup(cleanup)
        self.php("getConn()->query(\"CREATE TRIGGER forbid_route_product_write BEFORE INSERT ON products BEGIN SELECT RAISE(FAIL, 'settings must not rewrite products'); END\");echo json_encode(true);")
        opener,token=self.case.login()
        _,page=self.case.request(opener,'/panel/data-hub.php?project=p')
        context=html.unescape(re.search(r'name="_project_context" value="([^"]+)"',page)[1])
        fields={'action':'social_route','platform':'telegram','destination':'','app_csrf':token,'_project_context':context}
        status,body=self.case.request(opener,'/panel/data-hub.php?project=p',fields)
        self.assertEqual(status,200);self.assertIn('مقصد صریح اکانت یا کانال لازم است',body)
        fields['destination']='fixture-explicit-channel'
        status,body=self.case.request(opener,'/panel/data-hub.php?project=p',fields)
        self.assertEqual(status,200);self.assertIn('روش ChatGPT برای این کانال فعال شد',body)
        self.assertEqual(self.case.record()['name'],'محصول one')
    def test_health_requires_central_permission_and_explicit_project_selection(self):
        r=self.php("require $GLOBALS['argv'][1].'/panel/inc/OperationalHealth.php';$health=new OperationalHealth($svc);$_SESSION['project_id']='another';$bad=false;try{$health->project($p);}catch(RuntimeException $e){$bad=true;}echo json_encode($bad);")
        self.assertTrue(r)
    def test_health_http_is_read_only_and_rejects_nonadmin_central_access(self):
        owner,_=self.case.login();self.assertEqual(self.case.request(owner,'/panel/health.php?central=1')[0],200)
        self.assertEqual(self.case.request(owner,'/panel/health.php?central=1',{'action':'repair'})[0],405)
        limited,_=self.case.login('limited');self.assertEqual(self.case.request(limited,'/panel/health.php?central=1')[0],403)
        self.assertEqual(self.case.request(limited,'/panel/health.php?project=p')[0],403)

    def test_rubika_contract_uses_only_the_official_host_and_requires_a_receipt(self):
        r=self.php("require $GLOBALS['argv'][1].'/panel/inc/RubikaBot.php';$request=RubikaBot::request('fixture-rubika-token','fixture-chat','Human text');$fake=RubikaBot::receipt(200,['status'=>'OK','data'=>[]]);$good=RubikaBot::receipt(200,['status'=>'OK','data'=>['message_id'=>'receipt-123']]);$bad=false;try{RubikaBot::request('../other-host','chat','text');}catch(RuntimeException $e){$bad=true;}echo json_encode([$request['url'],$fake,$good,$bad]);")
        self.assertEqual(r,['https://botapi.rubika.ir/v3/fixture-rubika-token/sendMessage',None,'receipt-123',True])
    def test_rubika_aria_text_publication_uses_a_real_response_receipt(self):
        r=self.publication("ProjectOnboarding::mutate('p',function(&$p)use($job,$entry){$p['settings']['connections']['rubika']=['token'=>'fixture-rubika-token','chatId'=>'fixture-chat'];$r=&$p['settings']['aria_calendar']['plans'][$job]['entries'][$entry];$r['channel']='rubika';$r['approval']['destination']=Aria::destinationFingerprint($p,'rubika');});$calls=0;$pub=new AriaPublisher($svc,function($url,$body)use(&$calls){$calls++;return ['status'=>200,'body'=>['status'=>'OK','data'=>['message_id'=>'rubika-receipt']]];});$pub->tick();$r=getCurrentProject('p',true)['settings']['aria_calendar']['plans'][$job]['entries'][$entry];echo json_encode([$calls,$r['state'],$r['receipt']['message_id']]);")
        self.assertEqual(r,[1,'published','rubika-receipt'])

    def test_support_ticket_project_filter_does_not_switch_or_bypass_workspace_access(self):
        ticket=self.php("require $GLOBALS['argv'][1].'/panel/inc/SupportTickets.php';$other=$p;$other['id']='audit-other';$other['name']='Audit other';$other['products']=[];$other['users']=[$member];db_save_project($other);echo json_encode((new SupportTickets())->create('audit-other','customer','Audit support','bug','Cross-project support sentinel'));")
        owner,_=self.case.login();status,body=self.case.request(owner,'/panel/support.php?project=audit-other&ticket='+ticket)
        self.assertEqual(status,200);self.assertIn('Cross-project support sentinel',body)
        self.assertEqual(self.case.request(owner,'/panel/admin.php?project=p')[0],200)
        limited,_=self.case.login('limited');self.assertEqual(self.case.request(limited,'/panel/support.php?project=audit-other&ticket='+ticket)[0],403)

del Base,PlanningFixture
