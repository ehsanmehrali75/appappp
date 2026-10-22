"""Isolated onboarding, plan ceilings and verified purchase provisioning. No live gateway calls."""
import json,re,sqlite3,subprocess,unittest,shutil,urllib.request,http.cookiejar,os
import test_http_security as fixtures

class ProjectOnboarding(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('SetupFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp()
        for name in ['purchases','ai-private','onboarding-crawl']:
            shutil.rmtree(self.case.app/'panel/db'/name,ignore_errors=True)
        with sqlite3.connect(self.case.database) as db:
            ids=[x[0] for x in db.execute("SELECT id FROM projects WHERE id != 'p'")]
            for pid in ids:db.execute('DELETE FROM project_users WHERE project_id=?',(pid,));db.execute('DELETE FROM projects WHERE id=?',(pid,))
            db.execute('DELETE FROM scraping_jobs');db.execute('UPDATE projects SET name="پروژه آزمایشی" WHERE id="p"')
        self.owner,self.csrf=self.case.login()
    def state(self):
        with sqlite3.connect(self.case.database) as db:return json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])
    def post(self,step,**data):
        data.setdefault('revision',self.state().get('onboarding',{}).get('revision',0));data.setdefault('setup_action','save')
        return self.case.request(self.owner,'/panel/onboarding.php?project=p&step='+step,data,{'X-CSRF-Token':self.csrf})
    def test_requires_project_settings_permission_and_csrf(self):
        viewer,_=self.case.login('viewer');self.assertEqual(self.case.request(viewer,'/panel/onboarding.php?project=p')[0],403)
        self.assertEqual(self.case.request(self.owner,'/panel/onboarding.php?project=foreign')[0],403)
        self.assertEqual(self.case.request(self.owner,'/panel/onboarding.php?project=p',{'setup_action':'pause','revision':0})[0],403)
    def test_identity_persists_without_rewriting_products_members_or_secrets(self):
        before=self.case.record();code,page=self.post('identity',platform_type='tiam',project_name='پروژه تازه',website_url='https://shop.example')
        self.assertEqual(code,200);s=self.state();self.assertEqual(s['platform_type'],'tiam');self.assertEqual(s['tiam_inventory']['password'],'synthetic-sql-secret');self.assertEqual(before,self.case.record())
        self.assertIn('اتصال تیام از کامپیوتر',page);self.assertNotIn('synthetic-sql-secret',page)
    def test_skips_pause_and_stale_forms_are_resumable(self):
        self.post('identity',setup_action='skip',platform_type='invalid');self.assertEqual(self.state()['onboarding']['steps']['identity']['state'],'skipped')
        code,page=self.post('identity',revision=0,platform_type='wordpress',project_name='stale',website_url='https://example.com');self.assertIn('صفحهٔ دیگری',page)
        self.post('inventory',setup_action='pause');s=self.state()['onboarding'];self.assertTrue(s['paused_at']);self.assertTrue(s['steps']['identity'])
        self.assertEqual(self.case.request(self.owner,'/panel/onboarding.php?project=p')[0],200)
    def test_exit_links_keep_project_scope_and_finish_requires_review(self):
        code,page=self.case.request(self.owner,'/panel/onboarding.php?project=p')
        self.assertEqual(code,200);self.assertIn('/panel/admin.php?project=p&amp;tab=settings',page)
        code,page=self.post('identity',setup_action='finish')
        self.assertIn('پایان راه‌اندازی فقط',page);self.assertNotIn('finished_at',self.state().get('onboarding',{}))
        code,page=self.post('review',setup_action='finish')
        self.assertEqual(code,200);self.assertTrue(self.state()['onboarding']['finished_at'])
    def test_pause_without_products_returns_to_own_settings(self):
        s=self.state();s['subscription']={'modules':['settings','users']};s['active_modules']=['settings','users']
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(s),))
            db.execute('INSERT OR REPLACE INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('p','setupowner','Fixture-password-123!','project_admin','["all"]'))
        opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.case.request(opener,'/',{'login':'1','user':'setupowner','pass':'Fixture-password-123!'})
        _,chooser=self.case.request(opener,'/panel/workspace.php');pick=re.search(r'name="app-csrf" content="([a-f0-9]+)"',chooser)[1]
        self.case.request(opener,'/panel/workspace.php',{'select_project':'p','app_csrf':pick})
        _,page=self.case.request(opener,'/panel/onboarding.php?project=p');token=re.search(r'name="app-csrf" content="([a-f0-9]+)"',page)[1]
        code,page=self.case.request(opener,'/panel/onboarding.php?project=p&step=identity',{'setup_action':'pause','revision':0},{'X-CSRF-Token':token})
        self.assertEqual(code,200);self.assertIn('project-settings-form',page)
    def test_tiam_test_requires_extract_use_even_with_settings_access(self):
        s=self.state();s['platform_type']='tiam';s['subscription']={'modules':['settings','users','products','extract']};s['active_modules']=['settings','users','products','extract']
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(s),))
            db.execute('INSERT OR REPLACE INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('p','setupowner','Fixture-password-123!','manager','["products","settings"]'))
        opener,token=self.case.login('setupowner')
        code,page=self.case.request(opener,'/panel/onboarding.php?project=p&step=inventory',{'setup_action':'tiam_test'},{'X-CSRF-Token':token})
        self.assertEqual(code,200);self.assertIn('تست تیام برای این بستر فعال نیست',page)
        jobs=list((self.case.app/'panel/db/tiam-inventory').rglob('state.json'))
        self.assertFalse(jobs)
    def test_private_or_credential_urls_do_not_replace_the_website(self):
        for url in ['http://127.0.0.1','https://user:pass@example.com','https://localhost','https://10.0.0.1','https://example.com?token=secret']:
            with self.subTest(url=url):code,page=self.post('identity',platform_type='custom',project_name='test',website_url=url);self.assertEqual(code,200);self.assertIn('آدرس عمومی',page);self.assertNotIn('sourceUrl',self.state())
    def test_server_crawl_is_queued_once_and_not_completed_synchronously(self):
        self.post('identity',platform_type='custom',project_name='test',website_url='https://example.com')
        for _ in range(2):self.assertEqual(self.post('crawl',setup_action='queue')[0],200)
        with sqlite3.connect(self.case.database) as db:rows=db.execute('SELECT type,status FROM scraping_jobs WHERE project_id="p"').fetchall()
        self.assertEqual(rows,[('onboarding_discovery','pending')]);self.assertNotIn('crawl',self.state()['onboarding']['steps'])
    def test_invalid_live_connection_is_not_reported_as_configured_or_verified(self):
        self.post('identity',platform_type='wordpress',project_name='test',website_url='https://example.com')
        code,page=self.post('inventory',setup_action='test',test_platform='wordpress');self.assertEqual(code,200);self.assertIn('هنوز تأیید نشده',page)
        self.assertNotIn('inventory',self.state().get('onboarding',{}).get('steps',{}))
    def test_plan_caps_survive_attempt_to_enable_all_modules(self):
        s=self.state();s['subscription']={'modules':['products','settings','users','extract']};s['active_modules']=['products','settings','users','extract','calendar','seo_intel','wp_crm'];s['module_visibility']={'calendar':'active'}
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(s),));db.execute('INSERT OR REPLACE INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('p','projectowner','Fixture-password-123!','project_admin','["all"]'))
        opener,token=self.case.login('projectowner');code,page=self.case.request(opener,'/panel/onboarding.php?project=p');self.assertEqual(code,200);self.assertNotIn('سئو و آمار سایت',page);self.assertNotIn('شبکه‌های اجتماعی</span>',page)
        self.assertEqual(self.case.request(opener,'/panel/admin.php?tab=calendar')[0],403)
    def test_only_system_admin_can_create_a_free_project(self):
        other,token=self.case.login('limited');fields={'add_project':'1','name':'free','admin_user':'newowner','admin_pass':'new-password-123','activeFrom':'2026-01-01','activeTo':'2030-01-01','platform_type':'tiam'}
        self.assertEqual(self.case.request(other,'/panel/admin.php?tab=products',fields,{'X-CSRF-Token':token})[0],403)
        code,page=self.case.request(self.owner,'/panel/admin.php?tab=projects',fields,{'X-CSRF-Token':self.csrf});self.assertEqual(code,200);self.assertIn('میزکار شما',page)
        with sqlite3.connect(self.case.database) as db:
            pid,password=db.execute("SELECT project_id,password FROM project_users WHERE username='newowner'").fetchone();s=json.loads(db.execute('SELECT settings FROM projects WHERE id=?',(pid,)).fetchone()[0]);self.assertTrue(s['onboarding']['created_at']);self.assertNotEqual(password,'new-password-123')
    def test_test_gateway_cannot_create_a_project(self):
        _,home=self.case.request(self.owner,'/');nonce=re.search(r'name="checkout_nonce" value="([a-f0-9]+)"',home)[1]
        code,page=self.case.request(self.owner,'/',{'checkout_nonce':nonce,'submit_checkout_order':'1','app_csrf':self.csrf,'payment_gateway':'test','plan_id':'plan_pro','billing_cycle':'monthly','customer_name':'test','customer_mobile':'09121112233','customer_business':'test','account_username':'buyer','account_password':'long-password-123'})
        self.assertEqual(code,200);self.assertIn('درگاه پرداخت آماده نیست',page)
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM projects').fetchone()[0],1)
    def php(self,source):
        proc=subprocess.run([fixtures.PHP,'-r',"require $argv[1];require dirname($argv[1]).'/ProjectPurchases.php';"+source,str(self.case.app/'panel/inc/functions.php')],capture_output=True,text=True,check=True)
        return json.loads(proc.stdout)
    def test_existing_account_can_buy_another_project_without_merging_unrelated_accounts(self):
        r=self.php(r'''SessionIdentity::beginPassword('limited','Fixture-password-123!','p');$svc=new ProjectPurchases();$i=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Existing buyer','customer_mobile'=>'09121112233','customer_business'=>'Second project','account_username'=>'ignored','account_password'=>'Ignored-password'];$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'fake','zarinpal_sandbox'=>false];$a=$svc->start($i,$site,'https://panel.example/index.php',static fn()=>['success'=>true,'authority'=>'existing','payment_url'=>'https://gateway.example/pay']);$o=$svc->confirm($a['order']['order_id'],'existing','OK',static fn()=>['success'=>true,'ref_id'=>'existing-ref']);$p=getCurrentProject($o['project_id'],true);$joined=SessionIdentity::includePurchasedProject($p);$member=SessionIdentity::member($p);$fake=$p;$fake['id']='different-password';$fake['users'][0]['password']=password_hash('another-password',PASSWORD_DEFAULT);db_save_project($fake);$blocked=!SessionIdentity::includePurchasedProject(getCurrentProject($fake['id'],true));echo json_encode(['joined'=>$joined,'username'=>$member['username'],'role'=>$member['role'],'blocked'=>$blocked,'grants'=>count($_SESSION['auth_identity']['grants'])]);''')
        self.assertTrue(r['joined']);self.assertTrue(r['blocked']);self.assertEqual(r['username'],'limited');self.assertEqual(r['role'],'project_admin');self.assertEqual(r['grants'],2)
    def test_duplicate_checkout_uses_the_original_server_priced_order(self):
        r=self.php(r'''$svc=new ProjectPurchases();$i=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Buyer','customer_mobile'=>'09121112233','customer_business'=>'Project','account_username'=>'buyer','account_password'=>'Fixture-password-123!','checkout_nonce'=>str_repeat('a',32),'amount'=>1];$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'fake','zarinpal_sandbox'=>false];$calls=0;$request=function()use(&$calls){$calls++;return ['success'=>true,'authority'=>'one-request','payment_url'=>'https://gateway.example/pay'];};$first=$svc->start($i,$site,'https://panel.example/index.php',$request);$second=$svc->start($i,$site,'https://panel.example/index.php',$request);echo json_encode(['calls'=>$calls,'same'=>$first['order']['order_id']===$second['order']['order_id'],'amount'=>$second['order']['amount']]);''')
        self.assertEqual(r['calls'],1);self.assertTrue(r['same']);self.assertEqual(r['amount'],790000)
    def test_rial_gateway_uses_ten_times_the_toman_plan_price(self):
        r=self.php(r'''$svc=new ProjectPurchases();$i=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Buyer','customer_mobile'=>'09121112233','customer_business'=>'Project','account_username'=>'buyer','account_password'=>'Fixture-password-123!'];$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'fake','zarinpal_sandbox'=>false,'zarinpal_currency'=>'IRR'];$sent=0;$verified=0;$a=$svc->start($i,$site,'https://panel.example/index.php',function($amount)use(&$sent){$sent=$amount;return ['success'=>true,'authority'=>'rial','payment_url'=>'https://gateway.example/pay'];});$o=$svc->confirm($a['order']['order_id'],'rial','OK',function($auth,$amount)use(&$verified){$verified=$amount;return ['success'=>true,'ref_id'=>'rial-receipt'];});echo json_encode(['sent'=>$sent,'verified'=>$verified,'receipt'=>$o['amount']]);''')
        self.assertEqual(r,{'sent':7900000,'verified':7900000,'receipt':790000})
    def test_crawl_worker_stores_observations_without_inserting_products(self):
        source_path=self.case.app/'panel/inc/OnboardingCrawl.php';original=source_path.read_text();start=original.index('    public static function fetch(');end=original.index('    public static function purge(',start)
        source_path.write_text(original[:start]+"    public static function fetch($url){return file_get_contents(dirname(__DIR__).'/db/setup-fixture.html');}\n"+original[end:])
        (self.case.app/'panel/db/setup-fixture.html').write_text('<html><h1>Observed model</h1><span class="sku">10</span><div class="product-description">Website description</div><figure class="product-image"><img src="https://shop.example/main.webp"></figure></html>')
        with sqlite3.connect(self.case.database) as db:db.execute("INSERT INTO scraping_jobs(project_id,url,type,status) VALUES ('p','https://shop.example/product/10','onboarding_product','pending')")
        before=self.case.record()
        try:
            proc=subprocess.run([fixtures.PHP,'cron.php'],cwd=self.case.app/'panel',env=dict(os.environ,MAARKSHO_WORKER_BATCH_SIZE='1',MAARKSHO_WORKER_BUDGET_SECONDS='30'),capture_output=True,text=True,timeout=30)
            self.assertEqual(proc.returncode,0,proc.stderr)
            with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT status FROM scraping_jobs').fetchone()[0],'completed',proc.stdout+'\n'+proc.stderr)
            after=self.case.record()
            for field in ['sku','name','image','stock_total','description','gallery']:self.assertEqual(after[field] or '',before[field] or '',field)
            with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0],5)
            code,body=self.case.request(self.owner,'/panel/onboarding.php?project=p&download_crawl=1');rows=json.loads(body);self.assertEqual(len(rows),1);self.assertEqual(rows[0]['name'],'Observed model');self.assertEqual(rows[0]['main_image'],'https://shop.example/main.webp')
        finally:source_path.write_text(original)

    def test_crawl_worker_marks_php_errors_failed_instead_of_leaving_running_jobs(self):
        source_path=self.case.app/'panel/inc/OnboardingCrawl.php';original=source_path.read_text()
        try:
            source_path.write_text(original.replace('public static function fetch($url) {',"public static function fetch($url) { throw new Error('Synthetic parser failure');"))
            with sqlite3.connect(self.case.database) as db:
                db.execute("INSERT INTO scraping_jobs(project_id,url,type,status) VALUES ('p','https://shop.example/product/123','onboarding_product','pending')")
            proc=subprocess.run([fixtures.PHP,'cron.php'],cwd=self.case.app/'panel',env=dict(os.environ,MAARKSHO_WORKER_BATCH_SIZE='1',MAARKSHO_WORKER_BUDGET_SECONDS='30'),capture_output=True,text=True,timeout=30)
            self.assertEqual(proc.returncode,0,proc.stderr)
            with sqlite3.connect(self.case.database) as db:
                row=db.execute('SELECT status,error_message FROM scraping_jobs').fetchone()
            self.assertEqual(row,('failed','Synthetic parser failure'))
        finally:source_path.write_text(original)

    def test_gateway_probe_uses_real_site_callback_and_sufficient_amount(self):
        r=self.php(r'''$_SERVER['HTTP_HOST']='www.shop.example';$_SERVER['HTTPS']='on';$s=getSiteSettings();unset($s['zarinpal_callback_url']);saveSiteSettings($s);$captured=[];$probe=zarinpalTestConnection('fixture-merchant','',false,function($amount,$description,$callback)use(&$captured){$captured=[$amount,$callback];return ['success'=>true,'authority'=>'test-authority'];});echo json_encode(['captured'=>$captured,'status'=>$probe['rest_gateway_status'],'override'=>zarinpalCallbackUrl(['zarinpal_callback_url'=>'https://shop.example/index.php'])]);''')
        self.assertEqual(r['captured'],[10000,'https://www.shop.example/index.php?payment_callback=zarinpal&order_id=TEST-PROBE']);self.assertEqual(r['status'],'connected');self.assertEqual(r['override'],'https://shop.example/index.php')
    def test_gateway_callback_rejects_credentials_query_and_invalid_host(self):
        r=self.php(r'''$blocked=0;foreach(['https://user:pass@shop.example/index.php','https://shop.example/index.php?token=secret','javascript:alert(1)','https://shop.example/other.php'] as $url)try{zarinpalCallbackUrl(['zarinpal_callback_url'=>$url]);}catch(RuntimeException $e){$blocked++;}$_SERVER['HTTP_HOST']='invalid host';try{zarinpalCallbackUrl([]);}catch(RuntimeException $e){$blocked++;}echo json_encode($blocked);''')
        self.assertEqual(r,5)

    def test_gateway_validation_errors_are_visible_without_creating_project(self):
        r=self.php(r'''$svc=new ProjectPurchases();$i=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Buyer','customer_mobile'=>'09121112233','customer_business'=>'Project','account_username'=>'buyer','account_password'=>'Fixture-password-123!'];$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'fake','zarinpal_sandbox'=>false];try{$svc->start($i,$site,'https://panel.example/index.php',static fn()=>['success'=>false,'error'=>'The amount must be at least 1500','code'=>-9]);}catch(RuntimeException $e){echo json_encode(['message'=>$e->getMessage(),'projects'=>count(getDB()['projects'])]);}''')
        self.assertIn('The amount must be at least 1500',r['message']);self.assertIn('-9',r['message']);self.assertEqual(r['projects'],1)

    def transfer_checkout(self):
        self.php("$s=getSiteSettings();$s['bank_transfer']=['enabled'=>true,'card'=>'6037991234567890','holder'=>'Fixture owner','iban'=>'IR'+str_repeat('1',24),'bank'=>'Fixture Bank'];saveSiteSettings($s);echo '{}';".replace("'IR'+str_repeat", "'IR'.str_repeat"))
        opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _,home=self.case.request(opener,'/');nonce=re.search(r'name="checkout_nonce" value="([a-f0-9]+)"',home)[1];csrf=re.search(r'name="app_csrf" value="([a-f0-9]+)"',home)[1]
        fields={'submit_checkout_order':'1','app_csrf':csrf,'checkout_nonce':nonce,'payment_gateway':'bank_transfer','plan_id':'plan_pro','billing_cycle':'monthly','customer_name':'Buyer','customer_mobile':'09121112233','customer_business':'Transfer project','account_username':'transferbuyer','account_password':'Fixture-password-123!'}
        code,page=self.case.request(opener,'/',fields);self.assertEqual(code,200,page[:200]);oid=re.search(r'ORD-[0-9]{8}-[a-f0-9]{16}',page)[0]
        return opener,csrf,oid,fields
    def transfer_upload(self,opener,csrf,oid,content=None):
        import uuid
        boundary='----transfer-'+uuid.uuid4().hex;body=bytearray()
        for key,value in {'app_csrf':csrf,'last_four':'1234','reference':'BANK-123','paid_date':'1405/08/01 12:30'}.items():body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="receipt"; filename="receipt.png"\r\nContent-Type: image/png\r\n\r\n'.encode());body.extend(fixtures.PNG if content is None else content);body.extend(f'\r\n--{boundary}--\r\n'.encode())
        return self.case.request(opener,'/panel/transfer-payment.php?order='+oid,raw=bytes(body),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    def test_transfer_waits_for_receipt_then_main_admin_and_is_idempotent(self):
        buyer,token,oid,fields=self.transfer_checkout()
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM projects').fetchone()[0],1)
        self.assertEqual(self.case.request(self.owner,'/panel/transfer-payment.php?order='+oid,{'app_csrf':self.csrf,'review':'approve'})[0],200)
        code,page=self.transfer_upload(buyer,token,oid);self.assertEqual(code,200);self.assertIn('در انتظار تأیید مدیر',page)
        self.assertEqual(self.case.request(urllib.request.build_opener(),'/panel/transfer-payment.php?order='+oid+'&image=1')[0],403)
        self.assertEqual(self.case.request(buyer,'/panel/transfer-payment.php?order='+oid,{'app_csrf':token,'review':'approve'})[0],403)
        for _ in range(2):
            code,page=self.case.request(self.owner,'/panel/transfer-payment.php?order='+oid,{'app_csrf':self.csrf,'review':'approve','note':'Bank checked'});self.assertEqual(code,200);self.assertIn('پروژه فعال شد',page)
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM projects').fetchone()[0],2)
        code,page=self.case.request(buyer,'/panel/transfer-payment.php?order='+oid);self.assertIn('پروژه فعال شد',page)
    def test_transfer_rejection_requires_note_and_allows_resubmission(self):
        buyer,token,oid,fields=self.transfer_checkout();self.transfer_upload(buyer,token,oid)
        code,page=self.case.request(self.owner,'/panel/transfer-payment.php?order='+oid,{'app_csrf':self.csrf,'review':'reject'});self.assertIn('علت را وارد کنید',page)
        self.case.request(self.owner,'/panel/transfer-payment.php?order='+oid,{'app_csrf':self.csrf,'review':'reject','note':'مبلغ درست نیست'})
        code,page=self.case.request(buyer,'/panel/transfer-payment.php?order='+oid);self.assertIn('مبلغ درست نیست',page)
        code,page=self.transfer_upload(buyer,token,oid);self.assertIn('در انتظار تأیید مدیر',page)
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM projects').fetchone()[0],1)
    def test_transfer_rejects_disguised_receipts_and_csrf(self):
        buyer,token,oid,fields=self.transfer_checkout()
        code,page=self.transfer_upload(buyer,token,oid,b'<?php echo 1; ?>');self.assertIn('تصویر معتبر',page)
        self.assertEqual(self.case.request(buyer,'/panel/transfer-payment.php?order='+oid,{'last_four':'1234'})[0],403)

    def test_canceled_or_unverified_payment_never_provisions(self):
        r=self.php(r'''$svc=new ProjectPurchases();$i=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Buyer','customer_mobile'=>'09121112233','customer_business'=>'Project','account_username'=>'buyer','account_password'=>'Fixture-password-123!'];$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'fake','zarinpal_sandbox'=>false];$a=$svc->start($i,$site,'https://panel.example/index.php',static fn()=>['success'=>true,'authority'=>'failed','payment_url'=>'https://gateway.example/pay']);$blocked=0;foreach(['NOK','OK'] as $status)try{$svc->confirm($a['order']['order_id'],'failed',$status,static fn()=>['success'=>false]);}catch(RuntimeException $e){$blocked++;}echo json_encode(['blocked'=>$blocked,'projects'=>count(getDB()['projects'])]);''')
        self.assertEqual(r,{'blocked':2,'projects':1})
    def test_initial_crawl_waits_for_preview_and_exports_only_its_project(self):
        before=self.case.record()
        r=self.php(r'''require dirname($argv[1]).'/OnboardingCrawl.php';OnboardingCrawl::collect('p','https://example.com/product/10',['sku'=>'10','name'=>'Sample','image'=>'https://example.com/main.webp','gallery'=>['https://example.com/extra.webp']]);OnboardingCrawl::collect('p','https://example.com/product/10',['sku'=>'10','name'=>'Updated','image'=>'https://example.com/main.webp']);$blocked=false;try{OnboardingCrawl::fetch('http://127.0.0.1/private');}catch(RuntimeException $e){$blocked=true;}echo json_encode(['rows'=>count(OnboardingCrawl::rows('p')),'other'=>OnboardingCrawl::rows('other'),'private_blocked'=>$blocked]);''')
        self.assertEqual(r,{'rows':1,'other':[],'private_blocked':True});self.assertEqual(before,self.case.record())
        code,body=self.case.request(self.owner,'/panel/onboarding.php?project=p&download_crawl=1');self.assertEqual(code,200);self.assertEqual(json.loads(body)[0]['name'],'Updated')

    def test_verified_purchase_creates_one_project_and_login_resumes_setup(self):
        source=r'''require $argv[1];require dirname($argv[1]).'/ProjectPurchases.php';
$svc=new ProjectPurchases();$input=['plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Synthetic Buyer','customer_mobile'=>'09121112233','customer_business'=>'Purchased project','account_username'=>'buyer','account_password'=>'Fixture-password-123!'];
$site=['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'synthetic-merchant','zarinpal_sandbox'=>false,'zarinpal_currency'=>'IRT'];
$start=$svc->start($input,$site,'https://panel.example/index.php',static fn()=>['success'=>true,'authority'=>'synthetic-authority','payment_url'=>'https://gateway.example/pay']);$id=$start['order']['order_id'];$verifyCount=0;$verify=function()use(&$verifyCount){$verifyCount++;return ['success'=>true,'ref_id'=>'receipt-123'];};
$blocked=false;try{$svc->confirm($id,'wrong-authority','OK',$verify);}catch(RuntimeException $e){$blocked=true;}
$first=$svc->confirm($id,'synthetic-authority','OK',$verify);$second=$svc->confirm($id,'synthetic-authority','OK',$verify);echo json_encode(['first'=>$first,'second'=>$second,'verified'=>$verifyCount,'blocked'=>$blocked]);'''
        proc=subprocess.run([fixtures.PHP,'-r',source,str(self.case.app/'panel/inc/functions.php')],capture_output=True,text=True,check=True);r=json.loads(proc.stdout);self.assertEqual(r['verified'],1);self.assertTrue(r['blocked']);self.assertEqual(r['first']['project_id'],r['second']['project_id']);pid=r['first']['project_id']
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM projects').fetchone()[0],2);owner=db.execute('SELECT username,mobile,password FROM project_users WHERE project_id=?',(pid,)).fetchone();self.assertEqual(owner[:2],('buyer','09121112233'));self.assertNotEqual(owner[2],'Fixture-password-123!')
        opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        code,page=self.case.request(opener,'/index.php?payment_callback=zarinpal&Status=OK&Authority=synthetic-authority&order_id='+r['first']['order_id']);self.assertEqual(code,200);self.assertIn('ورود و راه‌اندازی پروژه',page)
        code,page=self.case.request(opener,'/',{'login':'1','user':'buyer','pass':'Fixture-password-123!'});self.assertEqual(code,200);self.assertIn('project-setup-form',page);self.assertIn('Purchased project',page)
