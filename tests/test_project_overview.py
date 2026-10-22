"""Project overview uses the actual queue, explicit inventory coverage and real schedule states."""
import json,subprocess,sqlite3,unittest
import test_http_security as fixtures
class ProjectOverview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('OverviewFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp();self.owner,self.token=self.case.login()
    def php(self,source):
        p=subprocess.run([fixtures.PHP,'-r',"require $argv[1];require dirname($argv[1]).'/ProjectOverview.php';"+source,str(self.case.app/'panel/inc/functions.php')],check=True,capture_output=True,text=True)
        return json.loads(p.stdout)
    def test_inventory_partition_and_unique_work_match_the_same_filtered_queue(self):
        r=self.php("$p=getCurrentProject('p',true);$p['products'][0]['stock_total']='';$p['products'][0]['stock_online']='';unset($p['products'][0]['stock']);$o=ProjectOverview::build($p);echo json_encode(['overview'=>$o,'work'=>count(WebsiteCatalogQueue::filtered($p,['wc_stock'=>'in_stock','wc_queue'=>'work'])),'upload'=>count(WebsiteCatalogQueue::filtered($p,['wc_stock'=>'in_stock','wc_queue'=>'pending_upload']))]);")
        o=r['overview'];self.assertEqual(sum(o['inventory'].values()),o['total']);self.assertGreater(o['inventory']['unknown'],0);self.assertEqual(o['counts']['work'],r['work']);self.assertEqual(o['counts']['pending_upload'],r['upload'])
    def test_known_zero_stock_never_enters_action_queue(self):
        r=self.php("$p=getCurrentProject('p',true);foreach($p['products'] as &$x){$x['stock_total']=0;$x['stock_online']=20;}unset($x);$o=ProjectOverview::build($p);echo json_encode($o);")
        self.assertEqual(r['inventory']['out_of_stock'],r['total']);self.assertEqual(r['counts']['work'],0);self.assertEqual(r['priorities'],[])
    def test_schedules_exclude_completed_cancelled_and_failed_from_upcoming(self):
        r=self.php("$p=['id'=>'test','settings'=>[],'products'=>[],'schedules'=>[['id'=>'next','scheduledAt'=>200,'status'=>'pending'],['scheduledAt'=>300,'status'=>'completed'],['scheduledAt'=>400,'status'=>'cancelled'],['scheduledAt'=>500,'status'=>'failed'],['scheduledAt'=>50,'status'=>'pending'],['scheduledAt'=>600,'publish_status'=>'published']]];echo json_encode(ProjectOverview::build($p,100));")
        self.assertEqual([x['id'] for x in r['upcoming']],['next']);self.assertEqual(r['failed'],1);self.assertEqual(r['overdue'],1)
    def test_no_products_is_an_empty_state_not_a_fabricated_trend(self):
        r=self.php("echo json_encode(ProjectOverview::build(['id'=>'empty','settings'=>[],'products'=>[]]));")
        self.assertEqual(r['total'],0);self.assertEqual(r['counts']['site_visible'],0);self.assertEqual(r['counts']['work'],0)
    def test_library_positive_stock_filter_matches_overview_without_unknowns(self):
        with sqlite3.connect(self.case.database) as db:db.execute("UPDATE products SET stock_total='',stock_online='' WHERE id='one'")
        r=self.php("echo json_encode(ProjectOverview::build(getCurrentProject('p',true))['inventory']['in_stock']);")
        code,page=self.case.request(self.owner,'/panel/admin.php?project=p&tab=products&filter=all&f_stock=known_in_stock')
        import re
        self.assertEqual(code,200);self.assertEqual(len(re.findall(r'<div[^>]*class="ig-post-card',page)),r)

    def test_dashboard_scopes_links_and_removes_old_vote_widgets(self):
        code,page=self.case.request(self.owner,'/panel/admin.php?tab=dashboard&project=p')
        self.assertEqual(code,200);self.assertIn('id="project-overview"',page);self.assertIn('wc_stock=in_stock',page);self.assertIn('project=p',page)
        self.assertNotIn('scrapingChart',page);self.assertNotIn('محبوب‌ترین محتواها',page);self.assertNotIn('Schedule Active',page)
    def test_dashboard_does_not_reveal_product_data_without_product_permission(self):
        with sqlite3.connect(self.case.database) as db:
            settings=dict(self.case.settings);settings['active_modules']=settings['active_modules']+['dashboard'];db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(settings),))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions,panel_access) VALUES (?,?,?,?,?,?)',('p','dashonly','Fixture-password-123!','manager','["dashboard"]','["admin"]'))
        import urllib.request,http.cookiejar
        opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.case.request(opener,'/',{'login':'1','user':'dashonly','pass':'Fixture-password-123!'})
        code,page=self.case.request(opener,'/panel/admin.php?tab=dashboard&project=p')
        self.assertEqual(code,200);self.assertNotIn('data-overview="in_stock"',page);self.assertNotIn('صف کار محصولات موجود',page);self.assertNotIn('انتشارهای پیش‌رو',page)
