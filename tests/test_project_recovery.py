"""Project selection, stale tabs, private snapshots and atomic recovery on disposable data."""
import json,re,sqlite3,subprocess,unittest
import test_http_security as fixtures

class ProjectRecovery(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('RecoveryFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture()
        with sqlite3.connect(self.case.database) as db:db.execute('DROP TRIGGER IF EXISTS fail_snapshot');db.execute('DROP TRIGGER IF EXISTS fail_restore')
        self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute('DROP TRIGGER IF EXISTS fail_snapshot');db.execute('DROP TRIGGER IF EXISTS fail_restore')
            db.execute('DELETE FROM products WHERE project_id="q"');db.execute('DELETE FROM project_users WHERE project_id="q"');db.execute('DELETE FROM projects WHERE id="q"')
            db.execute('INSERT INTO projects(id,name,settings) VALUES (?,?,?)',('q','Other project','{}'))
        self.owner,self.csrf=self.case.login()
    def php(self,code):
        prefix="require 'panel/inc/functions.php';require_once 'panel/inc/InventoryVersions.php';require 'panel/inc/ProjectBackups.php';$p=getCurrentProject('p',true);"
        r=subprocess.run([fixtures.PHP,'-r',prefix+code],cwd=self.case.app,capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stdout+r.stderr);return json.loads(r.stdout)
    def test_stale_tab_after_explicit_switch_and_direct_url(self):
        self.assertEqual(self.case.request(self.owner,'/panel/admin.php?project=q')[0],403)
        code,page=self.case.request(self.owner,'/panel/workspace.php');self.assertEqual(code,200)
        self.assertEqual(self.case.request(self.owner,'/panel/workspace.php',{'select_project':'q','app_csrf':self.csrf})[0],200)
        code,_=self.case.request(self.owner,'/panel/admin.php?tab=products',{'update_product_data':1,'pid':'one','name':'Wrong project','_project_context':'p','app_csrf':self.csrf});self.assertEqual(code,403)
        code,_=self.case.request(self.owner,'/panel/product_import.php',raw=json.dumps({'project':'p','action':'begin','chunks':1}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf,'X-Project-Context':'p'});self.assertEqual(code,403)
        self.assertEqual(self.case.record()['name'],'محصول one')
    def test_unauthorized_backup_and_foreign_project_hidden(self):
        viewer,token=self.case.login('viewer');self.assertEqual(self.case.request(viewer,'/panel/backups.php?project=p')[0],403)
        code,page=self.case.request(viewer,'/panel/workspace.php');self.assertNotIn('Other project',page);self.assertNotIn('value="__central__"',page)
        self.assertEqual(self.case.request(viewer,'/panel/workspace.php',{'select_project':'q','app_csrf':token})[0],403)
    def test_backup_preserves_media_users_other_projects_and_pauses_sync(self):
        r=self.php("""InventoryVersions::init();$id=ProjectBackups::create($p);$data=ProjectBackups::read('p',$id);$before=$p['users'];$q=getConn()->query("UPDATE products SET stock_total='99' WHERE id='one'");$safety=ProjectBackups::restore(getCurrentProject('p',true),$data);$after=getCurrentProject('p',true);echo json_encode(['files'=>count($data['files']),'safety'=>$safety,'stock'=>$after['products'][0]['stock_total'],'users'=>$before===$after['users'],'other'=>getCurrentProject('q',true)['name'],'paused'=>$after['settings']['backup_restore_paused'],'image'=>$after['products'][0]['image'],'exists'=>is_file('panel/'.$after['products'][0]['image'])]);""")
        self.assertEqual(r['files'],1);self.assertTrue(r['users']);self.assertTrue(r['paused']);self.assertTrue(r['exists']);self.assertEqual(r['other'],'Other project');self.assertTrue(r['safety'])
        self.assertEqual(self.case.record()['stock_total'],'10')
    def test_corrupt_foreign_and_cross_project_ids_refused(self):
        r=self.php("""InventoryVersions::init();$id=ProjectBackups::create($p);$data=ProjectBackups::read('p',$id);$errors=[];$bad=$data;$bad['project_id']='q';try{ProjectBackups::restore($p,$bad);}catch(Throwable $e){$errors[]=1;}$bad=$data;$k=array_key_first($bad['files']);$bad['files'][$k]['data']='broken';try{ProjectBackups::restore($p,$bad);}catch(Throwable $e){$errors[]=2;}getConn()->query("INSERT INTO products(id,project_id,name) VALUES ('foreign','q','Other')");$bad=$data;$bad['products'][0]['id']='foreign';unset($bad['checksum']);$bad['checksum']=hash('sha256',json_encode($bad,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));try{ProjectBackups::restore($p,$bad);}catch(Throwable $e){$errors[]=3;}echo json_encode($errors);""")
        self.assertEqual(r,[1,2,3]);self.assertEqual(self.case.record()['stock_total'],'10')
    def test_asset_fallback_does_not_reset_an_explicit_selection(self):
        self.case.request(self.owner,'/panel/panel/uploads/missing-fixture.png')
        code,page=self.case.request(self.owner,'/panel/admin.php?tab=products');self.assertEqual(code,200);self.assertIn('محصول one',page)
    def test_legacy_backup_upload_discards_access_settings_and_queues(self):
        r=self.php("$raw=json_encode(['project_id'=>'p','exported_at'=>time(),'products'=>$p['products'],'users'=>[['username'=>'attacker','role'=>'owner']],'settings'=>['fixture_restore'=>true],'schedules'=>[['id'=>'old-send']]]);$id=ProjectBackups::import('p',$raw);$safe=ProjectBackups::read('p',$id);ProjectBackups::restore($p,$safe);$fresh=getCurrentProject('p',true);echo json_encode([isset($safe['users']),isset($safe['settings']),isset($safe['schedules']),isset($fresh['settings']['fixture_restore']),$fresh['users']===$p['users']]);")
        self.assertEqual(r,[False,False,False,False,True])
    def test_login_requires_explicit_selection_even_with_one_project(self):
        import urllib.request,http.cookiejar
        opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        code,page=self.case.request(opener,'/',{'login':'1','user':'operator','pass':'Fixture-password-123!'})
        self.assertEqual(code,200);self.assertIn('میزکار شما',page)
        code,page=self.case.request(opener,'/panel/admin.php?tab=products');self.assertEqual(code,200);self.assertIn('میزکار شما',page);self.assertNotIn('محصول one',page)
    def test_central_desk_has_no_default_project_and_is_not_a_member_option(self):
        self.assertEqual(self.case.request(self.owner,'/panel/workspace.php',{'select_project':'__central__','app_csrf':self.csrf})[0],200)
        code,page=self.case.request(self.owner,'/panel/admin.php?tab=projects');self.assertEqual(code,200);self.assertIn('مدیریت مرکزی',page);self.assertNotIn('محصول one',page);self.assertNotIn('uploads/original.png',page)
        code,page=self.case.request(self.owner,'/panel/admin.php?tab=markshow_org');self.assertEqual(code,200);self.assertNotIn('محصول one',page)
    def test_downloaded_file_upload_previews_before_restore(self):
        data=self.php("$id=ProjectBackups::create($p);echo json_encode(ProjectBackups::read('p',$id));")
        boundary='fixture-backup-boundary'
        parts=[]
        for name,value in [('action','upload'),('app_csrf',self.csrf),('_project_context','p')]:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n')
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="backup_file"; filename="backup.json"\r\nContent-Type: application/json\r\n\r\n'+json.dumps(data)+f'\r\n--{boundary}--\r\n')
        code,page=self.case.request(self.owner,'/panel/backups.php?project=p',raw=''.join(parts).encode(),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
        self.assertEqual(code,200);self.assertIn('پیش‌نمایش بازیابی',page);self.assertEqual(self.case.record()['stock_total'],'10')
    def test_pre_restore_import_job_cannot_resume_after_recovery(self):
        def action(name,**fields):
            return self.case.request(self.owner,'/panel/product_import.php',raw=json.dumps(dict(project='p',action=name,**fields)).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf})
        code,raw=action('begin',chunks=1);self.assertEqual(code,200);job=json.loads(raw)['job']
        self.assertEqual(action('upload',job=job,index=0,rows=[dict(sku='one',name='محصول one',stock_tedad=99)])[0],200)
        self.assertEqual(action('prepare',job=job)[0],200)
        self.php("$id=ProjectBackups::create($p);ProjectBackups::restore($p,ProjectBackups::read('p',$id));echo json_encode(true);")
        self.assertEqual(action('apply',job=job)[0],422);self.assertEqual(self.case.record()['stock_total'],'10')
    def test_restore_failure_rolls_back_products_and_keeps_current_media(self):
        result=self.php("""InventoryVersions::init();$id=ProjectBackups::create($p);$data=ProjectBackups::read('p',$id);getConn()->query("UPDATE products SET description='Current content' WHERE id='one'");getConn()->query("CREATE TRIGGER fail_restore BEFORE INSERT ON products WHEN NEW.id='one' BEGIN SELECT RAISE(FAIL,'fixture'); END");$failed=false;try{ProjectBackups::restore(getCurrentProject('p',true),$data);}catch(Throwable $e){$failed=true;}echo json_encode([$failed,getCurrentProject('p',true)['products'][0]['image'],is_file('panel/uploads/original.png')]);""")
        self.assertTrue(result[0]);self.assertTrue(result[2]);self.assertEqual(self.case.record()['description'],'Current content')
    def test_weekly_failure_is_recorded_and_does_not_change_products(self):
        r=self.php("getConn()->begin_transaction();ProjectBackups::weekly();getConn()->rollback();echo json_encode(ProjectBackups::status('p'));")
        self.assertTrue(r['last_attempt']);self.assertTrue(r['error']);self.assertEqual(self.case.record()['stock_total'],'10')
    def test_weekly_is_idempotent_and_status_records_success(self):
        r=self.php("ProjectBackups::weekly();$before=count(ProjectBackups::listing('p'));ProjectBackups::weekly();echo json_encode([ProjectBackups::status('p'),$before,count(ProjectBackups::listing('p'))]);")
        self.assertTrue(r[0]['last_success']);self.assertEqual(r[1],r[2])
    def test_large_chunked_snapshot_roundtrip_and_failed_prepare_keeps_products(self):
        r=self.php("""InventoryVersions::init();$products=$p['products'];$products[0]['description']=str_repeat('متن بزرگ ERP ',30000);$id=bin2hex(random_bytes(32));$v=InventoryVersions::prepare($p,'erp',$id,$products);$large=strlen($v['payload']['planned'][$products[0]['id']]['description']);$n=getConn()->query('SELECT count(*) AS n FROM inventory_version_chunks')->fetch_assoc()['n'];getConn()->query("CREATE TRIGGER fail_snapshot BEFORE INSERT ON inventory_import_versions BEGIN SELECT RAISE(FAIL,'fixture'); END");$failed=false;try{InventoryVersions::prepare($p,'erp',bin2hex(random_bytes(32)),$products);}catch(Throwable $e){$failed=true;}echo json_encode([$large,$n,$failed,getCurrentProject('p',true)['products']===$p['products']]);""")
        self.assertGreater(r[0],500000);self.assertGreater(r[1],1);self.assertTrue(r[2]);self.assertTrue(r[3])

if __name__=='__main__':unittest.main()
