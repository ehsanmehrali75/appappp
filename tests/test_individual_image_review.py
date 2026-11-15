"""Real isolated PHP/SQLite reviews and approved-only WordPress/ZIP payloads."""
import json, sqlite3, subprocess, unittest
import test_http_security as fixtures

class IndividualImageReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('ReviewFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp()
        self.urls=['uploads/'+name+'.png' for name in ['front','back','side','detail']]
        subprocess.run([fixtures.PHP,'-r',"$im=imagecreatetruecolor(20,30);imagefill($im,0,0,imagecolorallocate($im,50,100,160));foreach(['front','back','side','detail'] as $n)imagepng($im,$argv[1].'/'.$n.'.png');",str(self.case.app/'panel/uploads')],check=True,capture_output=True)
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET image=?,gallery=?,images_list=?,status="pending",is_manual_image=1 WHERE id="manual"',(self.urls[0],json.dumps(self.urls[1:]),json.dumps([{'url':u,'source':'manual','approved':False} for u in self.urls])))
        self.opener,self.csrf=self.case.login()
    def request(self,action='image_review_state',expected=200,**fields):
        code,body=self.case.request(self.opener,'/panel/image-review.php' if action in ['image_review_state','review_image'] else '/panel/portal.php?project=p',{'action':action,'id':'manual',**fields},{'X-CSRF-Token':self.csrf,'X-Requested-With':'XMLHttpRequest','X-Project-Context':'p'})
        self.assertEqual(code,expected,body[:1000]);return json.loads(body)
    def state(self):return self.request()['review']
    def decide(self,index,decision='approved',stage=0,reason='',revision=None,expected=200):
        state=self.state()
        return self.request('review_image',expected=expected,image_key='all' if index=='all' else state['rows'][index]['key'],decision=decision,stage=stage,reason=reason,revision=revision or state['revision'])
    def record(self):
        with sqlite3.connect(self.case.database) as db:
            db.row_factory=sqlite3.Row
            return dict(db.execute('SELECT * FROM products WHERE id="manual"').fetchone())
    def php(self,source):
        code="require $argv[1];SessionIdentity::beginGlobal('fixture_owner');SessionIdentity::select(getCurrentProject('p',true));$p=getCurrentProject('p',true);foreach($p['products'] as $x)if($x['id']==='manual')$product=$x;"+source
        run=subprocess.run([fixtures.PHP,'-r',code,str(self.case.app/'panel/inc/functions.php')],text=True,capture_output=True)
        self.assertEqual(run.returncode,0,run.stderr);return json.loads(run.stdout)
    def mixed(self):
        self.decide(1,'rejected',reason='نور تصویر پشت اصلاح شود');self.decide(3,'rejected',reason='دیتیل واضح نیست');self.decide(0);self.decide(2)
    def test_mixed_decisions_preserve_all_originals_and_exclude_rejections_from_wordpress(self):
        self.mixed();state=self.state();self.assertEqual([r['state'] for r in state['rows']],['approved','rejected','approved','rejected'])
        self.assertEqual(state['status'],'active');self.assertEqual(state['rows'][1]['reason'],'نور تصویر پشت اصلاح شود')
        self.assertEqual(json.loads(self.record()['gallery']),self.urls[1:])
        result=self.php("require dirname($argv[1]).'/WordPressWorkspace.php';$p['settings']['platform_type']='wordpress';$p['settings']['wordpress']=['site_url'=>'https://shop.example','enabled'=>true];$p['settings']['app_base_url']='https://panel.example/panel';$product['website_sync']['wordpress']=['id'=>'101','origin'=>'https://shop.example','status'=>'publish'];$svc=new WordPressWorkspace();$id=$svc->queueProduct($p,$product);echo json_encode([$svc->state('p')['jobs'][$id]['payload']['image_urls'],WebsiteCatalogSync::state($p,$product)['publication']]);")
        self.assertEqual(result[0],['https://panel.example/panel/'+self.urls[i] for i in [0,2]])
        self.assertEqual(result[1],'pending_upload')
    def test_rejection_requires_reason_and_does_not_change_siblings(self):
        self.decide(1,'rejected',expected=409);self.assertTrue(all(r['state']=='pending' for r in self.state()['rows']))
        self.decide(1,'rejected',reason='اصلاح');self.decide('all')
        self.assertEqual([r['state'] for r in self.state()['rows']],['approved','rejected','approved','approved'])
        self.decide(1,expected=409)
    def test_old_gallery_revision_and_unknown_image_are_rejected(self):
        old=self.state()['revision'];self.decide(0);self.decide(2,revision=old,expected=409)
        self.request('review_image',expected=409,image_key='not-in-product',decision='approved',stage=0,revision=self.state()['revision'])
        self.assertEqual(self.state()['rows'][2]['state'],'pending')
    def test_shared_endpoint_requires_csrf_and_post(self):
        self.assertEqual(self.case.request(self.opener,'/panel/image-review.php')[0],403)
        self.assertEqual(self.case.request(self.opener,'/panel/image-review.php',{'action':'review_image','id':'manual'})[0],403)
        self.assertEqual(json.loads(self.record()['website_sync']),{})

    def test_viewer_can_view_but_cannot_decide(self):
        self.opener,self.csrf=self.case.login('viewer');self.assertEqual(self.state()['rows'][0]['available'],[])
        self.decide(0,expected=403)
    def test_product_wide_buttons_cannot_override_individual_rejections(self):
        self.mixed()
        self.request('quick_action',expected=409,sub='approve',product_id='manual')
        code,_=self.case.request(self.opener,'/panel/admin.php',{'ajax_action':'set_status','pid':'manual','status':'active'},{'X-CSRF-Token':self.csrf})
        self.assertEqual(code,409);self.assertEqual(self.state()['rows'][1]['state'],'rejected')
    def test_newly_added_url_does_not_inherit_sibling_approval(self):
        self.decide('all');old=self.state()['revision']
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET gallery=? WHERE id="manual"',(json.dumps(self.urls[1:]+['uploads/new.png']),))
        self.assertEqual(self.state()['rows'][-1]['state'],'pending');self.assertNotEqual(old,self.state()['revision'])
    def test_sequential_stage_roles_and_minimum_votes_apply_to_each_image(self):
        with sqlite3.connect(self.case.database) as db:
            settings=dict(self.case.settings,prod_approval_split_enabled=1,prod_approval_order='style_first',prod_approval_stage1_users='style,style2',prod_approval_stage2_users='conform',prod_approval_stage1_min=2)
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(settings),))
            for name in ['style','style2','conform']:db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('p',name,'Fixture-password-123!','manager',json.dumps(['products','approve_content'])))
        self.opener,self.csrf=self.case.login('conform');self.decide(0,stage=2,expected=409)
        self.opener,self.csrf=self.case.login('style');self.decide(0,stage=1);self.decide(0,stage=1)
        self.assertEqual(self.state()['rows'][0]['stages']['1']['state'],'pending')
        self.opener,self.csrf=self.case.login('style2');self.decide(0,stage=1)
        self.opener,self.csrf=self.case.login('conform');self.decide(0,stage=2)
        self.assertEqual([r['state'] for r in self.state()['rows']],['approved','pending','pending','pending'])
    def test_stale_project_tab_cannot_review_after_switch(self):
        state=self.state()
        with sqlite3.connect(self.case.database) as db:
            db.execute('DELETE FROM projects WHERE id="q"')
            db.execute('INSERT INTO projects(id,name,settings) VALUES (?,?,?)',('q','Other project','{}'))
        self.assertEqual(self.case.request(self.opener,'/panel/workspace.php',{'select_project':'q','app_csrf':self.csrf})[0],200)
        self.request('review_image',expected=403,image_key=state['rows'][0]['key'],decision='approved',stage=0,revision=state['revision'])
        self.assertEqual(json.loads(self.record()['website_sync']),{})

    def test_individual_pending_images_do_not_auto_approve_on_timeout(self):
        self.decide(0)
        result=self.php("$p['settings']['approval_timeout_library']=1;$p['settings']['approval_timeout_unit']='minutes';foreach($p['products'] as &$item)$item['extractedAt']=time()-999999;unset($item);autoApprovePendingProducts($p,true);foreach($p['products'] as $item)if($item['id']==='manual')echo json_encode(array_column(ProductImageReview::rows($item),'state'));")
        self.assertEqual(result,['approved','pending','pending','pending'])

    def test_changed_approved_selection_blocks_an_already_queued_wordpress_job(self):
        self.decide(0)
        result=self.php("require dirname($argv[1]).'/WordPressWorkspace.php';$p['settings']['wordpress']=['site_url'=>'https://shop.example','enabled'=>true];$p['settings']['app_base_url']='https://panel.example/panel';$product['website_sync']['wordpress']=['id'=>'101','origin'=>'https://shop.example','status'=>'publish'];$calls=0;$svc=new WordPressWorkspace(null,function()use(&$calls){$calls++;return [];});$id=$svc->queueProduct($p,$product);$keys=array_keys(ProductImageReview::rows($product));ProductImageReview::decide($product,$p,$keys[2],0,false,'',ProductImageReview::revision($product));$p['products']=[$product];$blocked=false;try{$svc->publishOne($p);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$calls]);")
        self.assertEqual(result,[True,0])

    def test_backup_restore_keeps_decisions_when_local_urls_are_relocated(self):
        self.mixed()
        result=self.php("require_once dirname($argv[1]).'/InventoryVersions.php';require_once dirname($argv[1]).'/ProjectBackups.php';InventoryVersions::init();foreach($p['products'] as &$item)if($item['id']==='manual')$item['website_sync']['wordpress']=['id'=>'must-not-restore'];unset($item);$id=ProjectBackups::create($p);ProjectBackups::restore($p,ProjectBackups::read('p',$id));$fresh=getCurrentProject('p',true);foreach($fresh['products'] as $item)if($item['id']==='manual')echo json_encode([array_column(ProductImageReview::rows($item),'state'),WebsiteCatalogSync::approvedPhotos($item),$fresh['settings']['backup_restore_paused'],isset($item['website_sync']['wordpress'])]);")
        self.assertEqual(result[0],['approved','rejected','approved','rejected'])
        self.assertEqual(len(result[1]),2);self.assertTrue(all('/restored/' in u for u in result[1]));self.assertTrue(result[2]);self.assertFalse(result[3])

    def test_manual_zip_includes_only_the_two_approved_photos(self):
        self.mixed()
        result=self.php("require dirname($argv[1]).'/WebsiteExportPackage.php';$p['products']=[$product];$export=(new WebsiteExportPackage(dirname($argv[1]).'/../uploads'))->build($p,['manual']);$zip=new ZipArchive();$zip->open(dirname($argv[1]).'/../'.$export['zip_url']);echo $zip->getFromName('manifest.json');")
        self.assertEqual(result['products'][0]['photos'],2)
