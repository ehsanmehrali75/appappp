"""Durable export batches and real session concurrency in temporary PHP/SQLite sites."""
import unittest,subprocess,json,shutil,sqlite3,socket,os,time,threading,urllib.request
from test_http_security import HttpSecurity as Base,PHP
class ExportBatches(unittest.TestCase):
    PHP_SETTINGS=()
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    fixtureSetUp=Base.setUp
    login=Base.login
    request=Base.request
    def setUp(self):
        self.fixtureSetUp();shutil.rmtree(self.app/'panel/db/website-exports',ignore_errors=True)
        for file in (self.app/'panel/uploads').glob('website_export_*.zip'):file.unlink()
        subprocess.run([PHP,'-r',"$i=imagecreatetruecolor(16,20);imagepng($i,$argv[1]);imagedestroy($i);",str(self.app/'panel/uploads/original.png')],check=True,capture_output=True)
    def php(self,code):
        setup="require $argv[1].'/panel/inc/functions.php';require $argv[1].'/panel/inc/WebsiteExportBatch.php';$p=getCurrentProject('p',true);$exports=new WebsiteExportBatch('p','fixture-owner',$argv[1].'/panel/uploads');"
        r=subprocess.run([PHP,'-r',setup+code,str(self.app)],capture_output=True,text=True,timeout=30)
        self.assertEqual(r.returncode,0,r.stderr+r.stdout);return json.loads(r.stdout)
    def test_chunk_retry_and_finish_are_idempotent_and_state_survives_new_service(self):
        r=self.php("$j=$exports->begin(['one']);$a=$exports->step($j['token'],0);$b=$exports->step($j['token'],0);$out=$exports->finish($j['token']);$again=(new WebsiteExportBatch('p','fixture-owner',$argv[1].'/panel/uploads'))->finish($j['token']);echo json_encode([$a,$b,$out===$again,array_keys($exports->receipts($p)['products']),count(glob($argv[1].'/panel/uploads/website_export_*.zip'))]);")
        self.assertEqual(r,[{'processed':1,'total':1,'done':True}]*2+[True,['one'],1])
    def test_other_owner_project_and_foreign_product_cannot_use_job(self):
        r=self.php("$j=$exports->begin(['one']);$blocked=0;foreach([['p','other-owner'],['other-project','fixture-owner']] as $pair){try{(new WebsiteExportBatch($pair[0],$pair[1],$argv[1].'/panel/uploads'))->step($j['token'],0);}catch(RuntimeException $e){$blocked++;}}try{$exports->begin(['missing']);}catch(RuntimeException $e){$blocked++;}echo json_encode($blocked);")
        self.assertEqual(r,3)
    def test_restore_epoch_revokes_unfinished_export(self):
        r=self.php("require $argv[1].'/panel/inc/ProjectOnboarding.php';$j=$exports->begin(['one']);$exports->step($j['token'],0);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;});$blocked=false;try{$exports->finish($j['token']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$exports->receipts(getCurrentProject('p',true))['products']]);")
        self.assertEqual(r,[True,[]])
    def test_changed_content_after_chunk_cannot_receive_final_receipt(self):
        r=self.php("$j=$exports->begin(['one']);$exports->step($j['token'],0);$p['products'][0]['description']='Changed after rendering';saveProject($p,[$p['products'][0]]);$blocked=false;try{$exports->finish($j['token']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$exports->receipts($p)['products']]);")
        self.assertEqual(r,[True,[]])
    def test_two_batches_do_not_overwrite_each_others_progress(self):
        r=self.php("$a=$exports->begin(['one']);$b=$exports->begin(['one']);$exports->step($a['token'],0);$exports->step($b['token'],0);$two=$exports->finish($b['token']);$one=$exports->finish($a['token']);echo json_encode([$a['token']!==$b['token'],$one['zip_url']!==$two['zip_url'],$one['exported_ids'],$two['exported_ids']]);")
        self.assertEqual(r,[True,True,['one'],['one']])
    def test_numeric_legacy_product_id_keeps_its_string_type_for_upload_confirmation(self):
        r=self.php("$item=$p['products'][0];$item['id']='123';saveProject($p,[$item]);$j=$exports->begin(['123']);$exports->step($j['token'],0);echo json_encode($exports->finish($j['token'])['exported_ids']);")
        self.assertEqual(r,['123'])
    def test_capacity_wait_does_not_advance_or_duplicate_work(self):
        r=self.php("putenv('MAARKSHO_EXPORT_CONCURRENCY=1');$slot=WebsiteExportCapacity::acquire();$j=$exports->begin(['one']);$wait=$exports->step($j['token'],0);WebsiteExportCapacity::release($slot);$done=$exports->step($j['token'],0);echo json_encode([$wait,$done,count(glob($argv[1].'/panel/uploads/website_export_*.zip'))]);")
        self.assertEqual(r,[{'processed':0,'total':1,'done':False,'busy':True},{'processed':1,'total':1,'done':True},1])
    def test_missing_part_cannot_publish_receipts_or_fake_a_complete_archive(self):
        r=self.php("$j=$exports->begin(['one']);$exports->step($j['token'],0);foreach(glob($argv[1].'/panel/uploads/website_export_*.zip') as $file)unlink($file);$blocked=false;try{$exports->finish($j['token']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$exports->receipts($p)['products']]);")
        self.assertEqual(r,[True,[]])
    def test_janitor_removes_only_expired_owned_archives_and_preserves_originals(self):
        r=self.php("$j=$exports->begin(['one']);$exports->step($j['token'],0);$out=$exports->finish($j['token']);$live=$exports->begin(['one']);$exports->step($live['token'],0);$base=$argv[1].'/panel/db/website-exports';$store=new AiStore($base.'/'.hash('sha256','p|fixture-owner'));$store->transaction(function(&$s)use($j){$s['jobs'][$j['token']]['expires']=time()-1;$s['jobs'][$j['token']]['parts'][]='uploads/original.png';$s['receipts']['one']['expires']=time()-1;});$before=hash_file('sha256',$argv[1].'/panel/uploads/original.png');$r=WebsiteExportBatch::maintain($base,$argv[1].'/panel/uploads');echo json_encode([$r['expired_jobs'],$r['errors'],count(glob($argv[1].'/panel/uploads/website_export_*.zip')),$before===hash_file('sha256',$argv[1].'/panel/uploads/original.png'),$exports->receipts($p)['products']]);")
        self.assertEqual(r,[1,0,1,True,[]])
    def test_janitor_skips_an_active_owner_lock_without_blocking(self):
        r=self.php("$j=$exports->begin(['one']);$base=$argv[1].'/panel/db/website-exports';$lock=fopen($base.'/'.hash('sha256','p|fixture-owner').'/state.lock','c');flock($lock,LOCK_EX);$start=microtime(true);$r=WebsiteExportBatch::maintain($base,$argv[1].'/panel/uploads');$fast=microtime(true)-$start<0.5;flock($lock,LOCK_UN);fclose($lock);echo json_encode([$r['scanned'],$r['errors'],$fast]);")
        self.assertEqual(r,[1,0,True])
    def test_http_export_receipt_is_used_when_confirming_manual_upload(self):
        opener,csrf=self.login();_,page=self.request(opener,'/panel/admin.php?tab=website_export')
        import re
        token=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',page)[1]
        def action(name,**values):
            status,body=self.request(opener,'/panel/admin.php?tab=website_export',dict(ajax_action=name,csrf=token,**values),{'X-CSRF-Token':csrf,'X-Project-Context':'p'})
            self.assertEqual(status,200,body);r=json.loads(body);self.assertEqual(r['status'],'success',body);return r
        job=action('export_website_begin',product_ids='["one"]');action('export_website_chunk',token=job['token'],offset=0);action('export_website_finish',token=job['token']);action('mark_as_uploaded',product_ids='["one"]')
        with sqlite3.connect(self.database) as db:uploaded,meta=db.execute("SELECT uploaded_to_site,website_sync FROM products WHERE id='one'").fetchone()
        self.assertEqual(uploaded,1);self.assertIn('images',json.loads(meta)['manual_upload_receipt']['assets'])
    def test_slow_export_releases_session_for_another_web_request(self):
        opener,csrf=self.login();_,page=self.request(opener,'/panel/admin.php?tab=website_export')
        import re
        token=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',page)[1]
        _,body=self.request(opener,'/panel/admin.php?tab=website_export',{'ajax_action':'export_website_begin','csrf':token,'product_ids':'["one"]'},{'X-CSRF-Token':csrf,'X-Project-Context':'p'})
        job=json.loads(body);self.assertEqual(job['status'],'success',body)
        builder=self.app/'panel/inc/WebsiteExportPackage.php';source=builder.read_text();marker=self.app/'panel/db/export-sleep-started'
        builder.write_text(source.replace('public function build($project,$ids,$allowEmpty=false) {',"public function build($project,$ids,$allowEmpty=false) { file_put_contents(__DIR__.'/../db/export-sleep-started','1');usleep(3000000);"))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        env=dict(os.environ,MAARKSHO_AUTH_STATE_DIR=str(self.temp.name+'/auth'))
        second=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','router.php'],cwd=self.app,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        response=[];errors=[]
        def export():
            try:response.append(self.request(opener,'/panel/admin.php?tab=website_export',{'ajax_action':'export_website_chunk','csrf':token,'token':job['token'],'offset':0},{'X-CSRF-Token':csrf,'X-Project-Context':'p'}))
            except Exception as e:errors.append(str(e))
        thread=threading.Thread(target=export)
        try:
            for _ in range(100):
                try:urllib.request.urlopen(f'http://127.0.0.1:{port}/',timeout=.2).read();break
                except OSError:time.sleep(.02)
            thread.start()
            for _ in range(150):
                if marker.exists():break
                time.sleep(.02)
            self.assertTrue(marker.exists(),'Rendering never started')
            cookie='; '.join(c.name+'='+c.value for h in opener.handlers if hasattr(h,'cookiejar') for c in h.cookiejar)
            start=time.monotonic();r=urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/panel/workspace.php',headers={'Cookie':cookie}),timeout=5);r.read();elapsed=time.monotonic()-start
            self.assertEqual(r.status,200);self.assertLess(elapsed,1.5,'Other request waited on PHP session during image rendering')
        finally:
            if thread.ident:thread.join(15)
            second.terminate();second.wait(5);builder.write_text(source)
        self.assertFalse(errors);self.assertEqual(response[0][0],200,response)
        self.assertEqual(json.loads(response[0][1])['processed'],1)

del Base
