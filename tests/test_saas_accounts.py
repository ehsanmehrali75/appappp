"""Account/provider/credit contracts against isolated PHP and SQLite, without live paid calls."""
import json,subprocess,unittest,sqlite3
import test_http_security as fixtures

class SaaSAccounts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('AccountFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute("DELETE FROM project_users WHERE username='customer'")
            db.execute("DELETE FROM project_users WHERE project_id!='p'")
            db.execute("DELETE FROM projects WHERE id!='p'")
    def php(self,code):
        setup="""require $argv[1].'/panel/inc/ai/Bootstrap.php';require $argv[1].'/panel/inc/ProjectMembers.php';require $argv[1].'/panel/inc/saas/Nahal.php';require $argv[1].'/panel/inc/saas/Providers.php';require $argv[1].'/panel/inc/saas/Commerce.php';SessionIdentity::beginGlobal('fixture_owner');SessionIdentity::select(getCurrentProject('p',true));$p=getCurrentProject('p',true);$svc=new SaaSAccounts();$member=['username'=>'customer','password'=>password_hash('Customer-pass-123!',PASSWORD_DEFAULT),'role'=>'project_admin','permissions'=>[]];$p['users'][]=$member;ProjectMembers::save([$p],['customer']);$plan=['id'=>'plan_pro','name'=>'Account plan','max_projects'=>2,'max_members'=>20,'storage_mb'=>100,'nahal_projects'=>1];$a=$svc->provision(['order_id'=>'verified-fixture','paid_at'=>time()],$member,$plan,'p',time()+86400);$id=$a['id'];$svc->change(function(&$s)use($id){$s['accounts'][$id]['wallets']['p']['balance']=100;});"""
        result=subprocess.run([fixtures.PHP,'-r',setup+code,str(self.case.app)],capture_output=True,text=True,timeout=40)
        self.assertEqual(result.returncode,0,result.stderr+result.stdout);return json.loads(result.stdout)
    def nahal(self,code):
        setup="""$p=getCurrentProject('p',true);foreach($p['products'] as &$product)if($product['id']==='one')$product['reference_gallery']=['/panel/uploads/original.png'];unset($product);saveProject($p,$p['products']);$p=getCurrentProject('p',true);$svc->activate($id,'p','nahal',true);$n=new Nahal($svc,fn()=>['status'=>200,'body'=>['data'=>[['b64_json'=>base64_encode(file_get_contents($argv[1].'/panel/uploads/original.png'))]],'usage'=>['fixture_only'=>true]]]);$n->saveModel(['id'=>'image','provider'=>'openai','model'=>'configured-image-model','credits'=>10,'key'=>'fixture-secret-not-a-real-key','enabled'=>true]);$template=$n->saveTemplate($p,['name'=>'Fixture template','prompt'=>'Preserve product','size'=>'1024x1024','quality'=>'medium']);$grant=AiAccess::principal($p);$input=['template'=>$template,'model'=>'image','products'=>['one'],'request_id'=>'generation-fixture'];"""
        return self.php(setup+code)
    def test_nahal_reserves_once_and_actual_fixture_response_enters_review(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$again=$n->enqueue($p,$grant,$input);$reserved=$svc->account($id)['wallets']['p'];$n->runOne();$row=$svc->state()['image_jobs'][$jobs[0]];echo json_encode([$jobs===$again,$reserved,$row['state'],$svc->account($id)['wallets']['p'],$n->runOne()]);")
        self.assertEqual(r,[True,{'balance':100,'reserved':10},'review',{'balance':90,'reserved':0},False])
    def test_nahal_acceptance_is_idempotent_and_retains_final_approval(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$n->runOne();$out=$n->decide($p,$grant,$jobs[0],'accept','main');$again=$n->decide($p,$grant,$jobs[0],'accept','main');$different=false;try{$n->decide($p,$grant,$jobs[0],'accept','social');}catch(RuntimeException $e){$different=true;}$fresh=getCurrentProject('p',true);foreach($fresh['products'] as $prod)if($prod['id']==='one')$images=$prod['images_list'];echo json_encode([$out===$again,$out['approval_required'],$different,count(array_filter($images,fn($r)=>($r['source']??'')==='ai')),end($images)['approved']]);")
        self.assertEqual(r,[True,True,True,1,False])
    def test_nahal_rejection_does_not_refund_completed_generation(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$n->runOne();$n->decide($p,$grant,$jobs[0],'reject');echo json_encode([$svc->state()['image_jobs'][$jobs[0]]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,['rejected',{'balance':90,'reserved':0}])
    def test_nahal_ambiguous_response_holds_credit_and_never_retries(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$calls=0;$worker=new Nahal($svc,function()use(&$calls){$calls++;throw new RuntimeException('fixture interrupted transport');});$worker->runOne();$worker->runOne();$held=$svc->account($id)['wallets']['p'];$state=$svc->state()['image_jobs'][$jobs[0]]['state'];$worker->resolve($jobs[0],4);echo json_encode([$calls,$state,$held,$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[1,'uncertain',{'balance':100,'reserved':10},{'balance':96,'reserved':0}])
    def test_nahal_definite_auth_failure_releases_credit(self):
        r=self.nahal("$jobs=$n->enqueue($p,$grant,$input);$worker=new Nahal($svc,fn()=>['status'=>401,'body'=>[]]);$worker->runOne();echo json_encode([$svc->state()['image_jobs'][$jobs[0]]['state'],$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,['failed',{'balance':100,'reserved':0}])
    def test_nahal_batch_failure_is_atomic_and_cannot_use_other_project_assets(self):
        r=self.nahal("$input['products']=['one','missing'];$blocked=false;try{$n->enqueue($p,$grant,$input);}catch(RuntimeException $e){$blocked=true;}$asset=$n->asset(file_get_contents($argv[1].'/panel/uploads/original.png'),$p);$outside=false;try{$n->assetBytes(['id'=>'foreign'],$asset);}catch(RuntimeException $e){$outside=true;}echo json_encode([$blocked,$outside,$svc->account($id)['wallets']['p'],count($svc->state()['image_jobs']??[])]);")
        self.assertEqual(r,[True,True,{'balance':100,'reserved':0},0])
    def test_month_end_renewal_clamps_without_skipping_february(self):
        r=self.php("$start=(new DateTimeImmutable('2028-01-31 12:30:00',new DateTimeZone('Asia/Tehran')))->getTimestamp();echo json_encode(date('Y-m-d H:i',SaaSAccounts::extendExpiry($start,'monthly')));")
        self.assertEqual(r,'2028-02-29 12:30')
    def test_verified_account_upgrade_applies_once_and_snapshots_price(self):
        r=self.php("$site=getSiteSettings();$site['zarinpal_enabled']=true;$site['zarinpal_merchant_id']='fixture-merchant';$site['zarinpal_sandbox']=false;$site['zarinpal_currency']='IRT';saveSiteSettings($site);$c=new SaaSCommerce($svc);$product=['id'=>'credits','kind'=>'credits','name'=>'Test credits','price_toman'=>1500,'units'=>50,'enabled'=>true];$c->saveProduct($product);$url=$c->checkout($id,'credits','p','buy',fn()=>['success'=>true,'authority'=>'fixture-authority','payment_url'=>'https://payment.zarinpal.com/fixture']);$product['units']=999;$product['price_toman']=9999;$c->saveProduct($product);$orders=$svc->state()['orders'];$o=reset($orders);$bad=false;try{$c->verify($o['id'],'wrong','OK',fn()=>['success'=>true,'ref_id'=>'123']);}catch(RuntimeException $e){$bad=true;}$calls=0;$verify=function()use(&$calls){$calls++;return ['success'=>true,'ref_id'=>'123'];};$c->verify($o['id'],'fixture-authority','OK',$verify);$c->verify($o['id'],'fixture-authority','OK',$verify);echo json_encode([$bad,$calls,$o['amount'],$svc->account($id)['wallets']['p']['balance']]);")
        self.assertEqual(r,[True,1,1500,150])
    def test_new_account_pages_require_csrf_and_central_page_is_restricted(self):
        owner,_=self.case.login()
        for path in ['/panel/account.php','/panel/saas-admin.php','/panel/employees.php?project=p']:
            self.assertEqual(self.case.request(owner,path,{'action':'activate'})[0],403)
        limited,_=self.case.login('limited');self.assertEqual(self.case.request(limited,'/panel/saas-admin.php')[0],403)
    def test_provision_requires_payment_and_receipt_is_idempotent(self):
        r=self.php("$blocked=false;try{$svc->provision(['order_id'=>'unpaid'],$member,$plan,'foreign',time()+86400);}catch(RuntimeException $e){$blocked=true;}$again=$svc->provision(['order_id'=>'verified-fixture','paid_at'=>time()],$member,$plan,'foreign',time()+86400);echo json_encode([$blocked,$again['projects'],$again['wallets']['p']['balance']]);")
        self.assertEqual(r,[True,{'p':True},100])
    def test_customer_identity_is_independent_and_only_authorizes_account_memberships(self):
        r=self.php("$account=SaaSAccounts::authenticate('customer','Customer-pass-123!');SessionIdentity::beginCustomer($account);SessionIdentity::refresh();$own=$svc->canOwn($a);$pid=$svc->createProject($id,'Second','creation');$joined=SessionIdentity::selectCreated($pid,'p');$outside=SessionIdentity::member(['id'=>'foreign']);$bad=SaaSAccounts::authenticate('customer','bad');echo json_encode([$own,$joined,$outside,$bad,SessionIdentity::customerAccount()]);")
        self.assertEqual(r[:4],[True,True,None,None]);self.assertTrue(r[4].startswith('acct_'))
    def test_project_creation_limit_idempotency_and_shared_expiry(self):
        r=self.php("$pid=$svc->createProject($id,'Second','create');$again=$svc->createProject($id,'Second','create');$blocked=false;try{$svc->createProject($id,'Third','third');}catch(RuntimeException $e){$blocked=true;}$p2=getCurrentProject($pid,true);echo json_encode([$pid===$again,$blocked,getCurrentProject('p',true)['activeTo']===$p2['activeTo'],$p2['settings']['storage_quota_mb']]);")
        self.assertEqual(r,[True,True,True,0])
    def test_transfer_only_free_credit_preserves_total_and_is_idempotent(self):
        r=self.php("$pid=$svc->createProject($id,'Second','create');$svc->change(function(&$s)use($id){SaaSAccounts::reserve($s,$id,'p',60,'job');});$blocked=false;try{$svc->transfer($id,'p',$pid,41,'too-much');}catch(RuntimeException $e){$blocked=true;}$svc->transfer($id,'p',$pid,40,'move');$svc->transfer($id,'p',$pid,40,'move');$changed=false;try{$svc->transfer($id,'p',$pid,1,'move');}catch(RuntimeException $e){$changed=true;}$a=$svc->account($id);echo json_encode([$blocked,$changed,$a['wallets']['p'],$a['wallets'][$pid]]);")
        self.assertEqual(r,[True,True,{'balance':60,'reserved':60},{'balance':40,'reserved':0}])
    def test_uncertain_reservation_holds_until_review_and_cannot_charge_twice(self):
        r=self.php("$svc->change(function(&$s)use($id){SaaSAccounts::reserve($s,$id,'p',30,'job');SaaSAccounts::settle($s,'job',0,true);});$held=$svc->account($id)['wallets']['p'];$svc->change(function(&$s){SaaSAccounts::settle($s,'job',20);SaaSAccounts::settle($s,'job',20);});echo json_encode([$held,$svc->account($id)['wallets']['p']]);")
        self.assertEqual(r,[{'balance':100,'reserved':30},{'balance':80,'reserved':0}])
    def test_storage_pool_and_employee_project_cap_are_account_wide(self):
        r=self.php("$pid=$svc->createProject($id,'Second','create');$svc->allocate($id,'p',70);$blocked=false;try{$svc->allocate($id,$pid,31);}catch(RuntimeException $e){$blocked=true;}$svc->allocate($id,$pid,30);$svc->activate($id,'p','nahal',true);$cap=false;try{$svc->activate($id,$pid,'nahal',true);}catch(RuntimeException $e){$cap=true;}$svc->activate($id,'p','nahal',false);$svc->activate($id,$pid,'nahal',true);echo json_encode([$blocked,$cap,$svc->account($id)['active_employees']['nahal']]);")
        self.assertTrue(r[0]);self.assertTrue(r[1]);self.assertEqual(list(r[2].values()),[True])
    def test_provider_protocols_use_distinct_auth_and_payloads(self):
        r=self.php("$out=[];foreach(['openai','anthropic','avalai'] as $kind)$out[$kind]=SaaSProviders::request($kind,'configured-model','fixture-key','hello',256);echo json_encode($out);")
        self.assertTrue(r['openai']['url'].endswith('/responses'));self.assertEqual(r['openai']['body']['max_output_tokens'],256);self.assertFalse(r['openai']['body']['store'])
        self.assertTrue(r['anthropic']['url'].endswith('/messages'));self.assertIn('anthropic-version: 2023-06-01',r['anthropic']['headers']);self.assertEqual(r['anthropic']['body']['max_tokens'],256)
        self.assertTrue(r['avalai']['url'].endswith('/chat/completions'))
    def test_provider_keys_are_encrypted_probe_is_not_generation_or_balance(self):
        r=self.php("$calls=[];$providers=new SaaSProviders($svc,function($url,$body,$headers)use(&$calls){$calls[]=[$url,$body];return ['status'=>200,'body'=>['id'=>'configured-model']];});$providers->save(['id'=>'central','provider'=>'anthropic','model'=>'configured-model','key'=>'fixture-secret-not-a-real-key','enabled'=>true]);$probe=$providers->probe('central');$catalog=$providers->catalog();$encrypted=$svc->state()['central_providers']['central']['key'];echo json_encode([$calls,$probe,$catalog,strpos($encrypted,'fixture-secret')===false]);")
        self.assertIsNone(r[0][0][1]);self.assertTrue(r[1]['success']);self.assertFalse(r[1]['generation_tested']);self.assertFalse(r[1]['balance_verified']);self.assertNotIn('key',r[2]['central']);self.assertTrue(r[3])
    def test_provider_change_requires_its_own_credential_and_failure_does_not_claim_success(self):
        r=self.php("$providers=new SaaSProviders($svc,fn()=>['status'=>401,'body'=>[]]);$providers->save(['id'=>'central','provider'=>'openai','model'=>'configured-model','key'=>'fixture-secret-not-a-real-key','enabled'=>true]);$blocked=false;try{$providers->save(['id'=>'central','provider'=>'anthropic','model'=>'configured-model','enabled'=>true]);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$providers->probe('central')]);")
        self.assertTrue(r[0]);self.assertFalse(r[1]['success'])
    def test_normalization_uses_actual_response_and_rejects_empty_success(self):
        r=self.php("$a=SaaSProviders::normalize('anthropic',['id'=>'actual-receipt','content'=>[['type'=>'text','text'=>'Real text']],'usage'=>['input_tokens'=>4,'output_tokens'=>2]]);$blocked=false;try{SaaSProviders::normalize('openai',[]);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$a,$blocked]);")
        self.assertEqual(r[0]['text'],'Real text');self.assertEqual(r[0]['usage']['input_tokens'],4);self.assertTrue(r[1])
    def test_foreign_project_cannot_receive_account_credit(self):
        r=self.php("$blocked=false;try{$svc->transfer($id,'p','foreign',1,'move');}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$svc->account($id)['wallets']['p']['balance']]);")
        self.assertEqual(r,[True,100])
    def test_expired_account_preserves_login_and_renewal_access_but_denies_operations(self):
        r=self.php("$svc->change(function(&$s)use($id){$s['accounts'][$id]['expires_at']=time()-1;});SessionIdentity::beginCustomer(SaaSAccounts::authenticate('customer','Customer-pass-123!'));SessionIdentity::refresh();$blocked=false;try{$svc->createProject($id,'Second','create');}catch(RuntimeException $e){$blocked=true;}echo json_encode([SessionIdentity::customerAccount()===$id,$svc->canOwn($svc->account($id)),$blocked,SaaSAccounts::allowsProject(getCurrentProject('p',true),'products'),SaaSAccounts::allowsProject(getCurrentProject('p',true),'settings')]);")
        self.assertEqual(r,[True,True,True,False,True])
