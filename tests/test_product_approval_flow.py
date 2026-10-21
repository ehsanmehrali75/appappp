import json,sqlite3,time,unittest,urllib.parse,subprocess
import test_http_security as fixtures

class ProductApprovalFlow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('ApprovalFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp();self.now=int(time.time());self.configure()
    def configure(self,order='style_first',minimum=1):
        settings=dict(self.case.settings,prod_approval_split_enabled=1,prod_approval_order=order,prod_approval_sequence_since=self.now,prod_approval_stage1_min=minimum,prod_approval_stage2_min=1,prod_approval_stage1_users='style,style2',prod_approval_stage2_users='conform',approval_timeout_image=60,approval_timeout_library=60,approval_timeout_unit='minutes')
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(settings),))
            db.execute('UPDATE products SET status="pending",is_manual_image=1,extracted_at=?,approvals_stage1="[]",approvals_stage2="[]",stage1_decision="pending",stage2_decision="pending",website_sync="{}" WHERE id="one"',(self.now,))
            db.execute('UPDATE products SET images_list=? WHERE id="one"',(json.dumps([{'url':'uploads/original.png','approved':False,'source':'manual'}]),))
            for username in ['style','style2','conform']:
                db.execute('INSERT OR REPLACE INTO project_users(project_id,username,password,role,permissions,panel_access) VALUES (?,?,?,?,?,?)',('p',username,'Fixture-password-123!','manager',json.dumps(['products','approve_content']),json.dumps(['portal','admin'])))
        self.clear_cache()
    def clear_cache(self):
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
    def decide(self,user,sub,expected=200,admin=False):
        opener,csrf=self.case.login(user)
        body={'action':'quick_action','sub':sub,'pid':'one'}
        # Portal's historic product argument is product_id in some handlers; send the canonical pid as well.
        body['product_id']='one';body['id']='one'
        if admin:body={'ajax_action':'set_status','pid':'one','status':'active','stage':'stage'+sub[-1]}
        code,raw=self.case.request(opener,'/panel/admin.php' if admin else '/panel/portal.php',body,{'X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'})
        self.assertEqual(code,expected,raw[:700]);return json.loads(raw)
    def test_second_stage_cannot_be_approved_or_rejected_first(self):
        self.decide('conform','approve_stage2',403);self.decide('conform','reject_stage2',403)
        self.assertEqual(self.case.record()['stage2_decision'],'pending')
    def test_threshold_and_fresh_second_deadline_survive_reload(self):
        self.configure(minimum=2);self.decide('style','approve_stage1')
        self.assertEqual(self.case.record()['stage1_decision'],'pending');self.decide('conform','approve_stage2',403)
        self.decide('style2','approve_stage1');p=self.case.record();self.assertEqual(p['stage1_decision'],'approved');self.assertEqual(p['status'],'pending')
        flow=json.loads(p['website_sync'])['approval_flow'];self.assertGreaterEqual(flow['approved_1'],self.now)
        self.decide('conform','approve_stage2');self.assertEqual(self.case.record()['status'],'active')
    def test_reverse_order_and_admin_share_the_same_guard(self):
        self.configure(order='conformity_first');self.decide('style','approve_stage1',403,admin=True)
        self.decide('conform','approve_stage2',admin=True);self.decide('style','approve_stage1',admin=True)
        self.assertEqual(self.case.record()['status'],'active')
    def test_generic_approve_does_not_skip_both_stages(self):
        self.decide('style','approve');self.assertEqual(self.case.record()['status'],'pending')
        self.decide('style','approve_stage2',403)
    def test_bulk_approval_obeys_responsibility_and_only_advances_one_stage(self):
        def bulk(user,expected):
            opener,csrf=self.case.login(user)
            code,raw=self.case.request(opener,'/panel/admin.php',{'ajax_action':'bulk_status','pids[]':'one','status':'active'},{'X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'})
            self.assertEqual(code,expected,raw)
        bulk('conform',403);self.assertEqual(self.case.record()['stage1_decision'],'pending')
        bulk('style',200);self.assertEqual(self.case.record()['status'],'pending')
        bulk('conform',200);self.assertEqual(self.case.record()['status'],'active')

    def test_rejection_is_recorded_and_does_not_open_the_next_stage(self):
        opener,csrf=self.case.login('style')
        code,raw=self.case.request(opener,'/panel/portal.php',{'action':'quick_action','sub':'reject_stage1','product_id':'one','comment':'اصلاح نور تصویر'}, {'X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'})
        self.assertEqual(code,200,raw);p=self.case.record();self.assertEqual(p['status'],'rejected')
        self.assertEqual(p['rejection_reason'],'اصلاح نور تصویر');self.assertTrue(json.loads(p['rejections_history']))
        self.decide('conform','approve_stage2',403)

    def test_next_team_queue_opens_after_first_approval(self):
        viewer,_=self.case.login('conform');code,page=self.case.request(viewer,'/panel/portal.php?tab=pending');self.assertEqual(code,200)
        self.assertNotIn('data-product-id="one"',page.split('id="tab-pending"')[1].split('id="tab-calendar"')[0])
        self.decide('style','approve_stage1');self.clear_cache();code,page=self.case.request(viewer,'/panel/portal.php?tab=pending')
        self.assertEqual(code,200);pending=page.split('id="tab-pending"')[1].split('id="tab-calendar"')[0];self.assertIn('data-product-id="one"',pending);self.assertIn('data-approval-deadline=',pending)

    def test_automatic_second_stage_gets_full_duration_after_first_transition(self):
        source="""require $argv[1];$p=getCurrentProject('p',true);$p['settings']['approval_timeout_image']=1;$x=$p['products'][0];foreach($p['products'] as $item)if($item['id']==='one')$x=$item;$now=time();$x['extractedAt']=$now-120;ProductApprovalFlow::autoAdvance($x,$p,$now);$first=$x;ProductApprovalFlow::autoAdvance($x,$p,$now+59);$middle=$x['status'];ProductApprovalFlow::autoAdvance($x,$p,$now+60);echo json_encode([$first,$middle,$x['status'],$now]);"""
        r=subprocess.run([fixtures.PHP,'-r',source,str(self.case.app/'panel/inc/functions.php')],check=True,capture_output=True,text=True)
        first,middle,final,now=json.loads(r.stdout);self.assertEqual(first['status'],'pending');self.assertEqual(first['website_sync']['approval_flow']['approved_1'],now);self.assertEqual(middle,'pending');self.assertEqual(final,'active')
