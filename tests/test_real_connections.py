"""Production connection contracts in disposable sites; never customer credentials or live publication."""
import io,json,shutil,sqlite3,subprocess,unittest,zipfile
import test_http_security as fixtures

class RealConnections(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('RealConnectionsFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass();shutil.copytree(fixtures.ROOT/'app',cls.fixture.app/'app',ignore=shutil.ignore_patterns('work','*.zip','__pycache__'))
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp()
        for name in ['ai-private','wordpress-api']:shutil.rmtree(self.case.app/'panel/db'/name,ignore_errors=True)
        for name in ['access_policy.json','seo_warehouse.json']:(self.case.app/'panel/db'/name).unlink(missing_ok=True)
    def php(self,code):
        prefix="require $argv[1].'/panel/inc/ai/Bootstrap.php';SessionIdentity::beginGlobal('fixture_owner');SessionIdentity::select(getCurrentProject('p',true));$p=getCurrentProject('p',true);$store=new AiStore();$grant=AiAccess::principal($p)+['scopes'=>['project.read','website.write']];"
        result=subprocess.run([fixtures.PHP,'-r',prefix+code,str(self.case.app)],capture_output=True,text=True,timeout=30)
        self.assertEqual(result.returncode,0,result.stderr);return json.loads(result.stdout)
    def test_empty_project_reports_no_fake_success_and_private_configuration_is_absent(self):
        owner,_=self.case.login();code,page=self.case.request(owner,'/panel/connections.php?project=p')
        self.assertEqual(code,200);self.assertIn('در انتظار اتصال کاربر',page);self.assertNotIn('synthetic-sql-secret',page)
        r=self.php("echo json_encode((new ProjectConnections($store))->catalog($p));")
        for connection in r['connections']:
            self.assertFalse(connection['configured']);self.assertFalse(connection['authorized']);self.assertIsNone(connection['last_received'])
    def test_normal_profiles_are_tiam_tiam_and_wordpress_wordpress(self):
        r=self.php("$out=[];foreach(['tiam','wordpress','custom'] as $type){$p['settings']['platform_type']=$type;$out[$type]=IntegrationHealth::profile($p);}echo json_encode($out);")
        for kind in ['tiam','wordpress']:
            self.assertEqual(r[kind]['website_adapter'],kind);self.assertEqual(r[kind]['inventory_authority'],kind);self.assertEqual(r[kind]['price_authority'],kind)
    def test_wordpress_setup_cannot_silently_convert_a_tiam_project(self):
        r=self.php("require $argv[1].'/panel/inc/WordPressWorkspace.php';ProjectOnboarding::mutate('p',function(&$p){$p['settings']['platform_type']='tiam';});$blocked=false;try{(new WordPressWorkspace())->configure(getCurrentProject('p',true),['site_url'=>'https://shop.fixture']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,getCurrentProject('p',true)['settings']['platform_type']]);")
        self.assertEqual(r,[True,'tiam'])
    def test_model_probe_is_get_without_generation_and_failure_does_not_claim_success(self):
        r=self.php("$calls=[];$svc=new AiReports($store,function($url,$body,$headers)use(&$calls){$calls[]=[$url,$body];return ['status'=>200,'body'=>['id'=>'fixture-model']];});$id=$svc->saveProvider($grant,['name'=>'Owner API','model'=>'fixture-model','key'=>'sk-fixture-not-a-real-key-123456','projects'=>['p'],'users'=>[],'daily_tokens'=>100000,'max_output'=>200],['p']);$ok=$svc->probe($grant,$id);$bad=(new AiReports($store,fn()=>['status'=>401,'body'=>[]]))->probe($grant,$id);echo json_encode([$ok,$bad,$calls]);")
        self.assertTrue(r[0]['success']);self.assertFalse(r[0]['generation_tested']);self.assertFalse(r[1]['success']);self.assertIsNone(r[2][0][1]);self.assertTrue(r[2][0][0].endswith('/models/fixture-model'))
    def test_new_provider_cannot_be_bound_to_another_project(self):
        r=self.php("$blocked=false;try{(new AiReports($store))->saveProvider($grant,['projects'=>['foreign'] ],['p','foreign']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)
    def test_downloads_are_complete_checksum_verified_and_contain_no_credentials(self):
        owner,_=self.case.login()
        for kind in ['chatgpt','python']:
            with owner.open(self.case.base+'/panel/connections.php?project=p&download='+kind) as response:
                self.assertEqual(response.headers.get_content_type(),'application/zip');data=response.read()
            self.assertNotIn(b'synthetic-sql-secret',data)
            with zipfile.ZipFile(io.BytesIO(data)) as package:
                self.assertIsNone(package.testzip());manifest=json.loads(package.read('manifest.json'))
                import hashlib
                for path,digest in manifest['sha256'].items():self.assertEqual(hashlib.sha256(package.read(path)).hexdigest(),digest)
                if kind=='chatgpt':
                    config=json.loads(package.read('maarksho-chatgpt/connection.json'));self.assertEqual(config['project'],'p');schema=json.loads(package.read('maarksho-chatgpt/openapi.json'));self.assertIn('/panel/ai_api.php/website_confirm',schema['paths']);self.assertEqual(schema['paths']['/panel/ai_api.php/website_confirm']['post']['security'],[{'OAuth2':['project.read','website.write']}])
                else:self.assertIn('maarksho-tools/app/tiam-agent/launcher.py',package.namelist())
    def test_legacy_seo_does_not_return_seeded_metrics_even_with_legacy_flag(self):
        owner,csrf=self.case.login()
        for path in ['/panel/seo.php?project=p&legacy=1','/panel/seo_intel/index.php?business=p&legacy=1']:
            code,page=self.case.request(owner,path);self.assertEqual(code,200);self.assertNotIn('99.98',page);self.assertNotIn('Math.random',page)
        code,body=self.case.request(owner,'/panel/seo_intel/api.php?action=get_keywords&business=p');self.assertEqual(code,200);self.assertEqual(json.loads(body)['data'],[])
        code,body=self.case.request(owner,'/panel/seo_intel/api.php?business=p',{'action':'trigger_sync','app_csrf':csrf,'_project_context':'p'});self.assertEqual(code,410)
    def test_legacy_statistics_webhook_cannot_write_synthetic_history(self):
        owner,_=self.case.login();code,body=self.case.request(owner,'/panel/chat_webhook.php?project=p&platform=social_sync',{'clicks':'1'});self.assertEqual(code,410)
        with sqlite3.connect(self.case.database) as db:settings=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])
        self.assertNotIn('analytics_history',settings)
    def test_legacy_warehouse_initializes_empty_and_diagnostics_do_not_fake_soap_auth(self):
        r=self.php("require $argv[1].'/panel/seo_intel/SeoDB.php';require_once $argv[1].'/panel/inc/TiamConnector.php';$m=new ReflectionMethod('TiamConnector','testErpAuth');$soap=$m->invoke(null,['endpoint'=>'https://not-contacted.fixture/service.asmx']);echo json_encode([SeoDB::getInitialWarehouseSchema(),$soap]);")
        self.assertTrue(all(not v for v in r[0].values()));self.assertFalse(r[1]['success']);self.assertEqual(r[1]['token'],'')
    def test_website_preview_confirm_queue_receipt_and_scope_are_shared(self):
        r=self.php("require $argv[1].'/panel/inc/WordPressWorkspace.php';$row=['id'=>'501','title'=>'Original','description'=>'Body','content_html'=>'<p>Body</p>','url'=>'https://shop.fixture/post','status'=>'publish','version'=>'v1','modified_at'=>'2026-10-20T00:00:00Z'];$http=function($url,$body)use($row){if(strpos($url,'export?')!==false)return ['status'=>200,'body'=>['status'=>'success','kind'=>'posts','page'=>1,'next_page'=>null,'rows'=>[$row]]];return ['status'=>200,'body'=>['status'=>'success','updated'=>true,'id'=>501,'record'=>array_replace($row,['title'=>'Updated','version'=>'v2'])]];};$svc=new WordPressWorkspace(null,$http);$svc->configure($p,['site_url'=>'https://shop.fixture']);$p=getCurrentProject('p',true);$svc->pull($p,'posts');$tools=new AiTools($store);$records=$tools->call($grant,'website_records',['kind'=>'posts']);$preview=$tools->call($grant,'website_preview',['kind'=>'posts','id'=>'501','changes'=>['title'=>'Updated']]);$queued=$tools->call($grant,'website_confirm',['draft'=>$preview['draft'],'confirmed'=>true]);$again=$tools->call($grant,'website_confirm',['draft'=>$preview['draft'],'confirmed'=>true]);$before=$tools->call($grant,'website_job',['id'=>$queued['job']]);$svc->publishOne($p);$after=$tools->call($grant,'website_job',['id'=>$queued['job']]);$blocked=false;$read=$grant;$read['scopes']=['project.read'];try{$tools->call($read,'website_confirm',['draft'=>$preview['draft'],'confirmed'=>true]);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$records,$preview,$queued,$again,$before,$after,$blocked]);")
        self.assertEqual(r[0]['rows'][0]['id'],'501');self.assertEqual(r[1]['before']['title'],'Original');self.assertEqual(r[1]['after']['title'],'Updated');self.assertEqual(r[2]['job'],r[3]['job']);self.assertFalse(r[2]['published']);self.assertEqual(r[4]['state'],'queued');self.assertEqual(r[5]['state'],'done');self.assertTrue(r[5]['result']['updated']);self.assertTrue(r[6])
    def test_ga4_relative_path_matches_site_url_without_merging_different_queries(self):
        r=self.php("require $argv[1].'/panel/inc/WordPressWorkspace.php';$p['settings']['wordpress']['site_url']='https://shop.fixture';echo json_encode([WordPressWorkspace::pageKey($p,'/product/a/'),WordPressWorkspace::pageKey($p,'https://shop.fixture/product/a'),WordPressWorkspace::pageKey($p,'/product/a?size=L'),WordPressWorkspace::pageKey($p,'/product/a?size=M')]);")
        self.assertEqual(r[0],r[1]);self.assertNotEqual(r[2],r[3])
