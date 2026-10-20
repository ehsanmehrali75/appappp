"""Project memberships, feature grants and panel entry permissions remain independent."""
import json,sqlite3,unittest,urllib.parse
import test_http_security as fixtures

class PanelEntryAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('PanelFixture',(fixtures.HttpSecurity,),{})
        cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture();self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET panel_access=NULL WHERE project_id="p"')
            db.execute('DELETE FROM projects WHERE id IN ("other","private")')
            db.execute('DELETE FROM project_users WHERE project_id IN ("other","private") OR username="panel-created"')
    def panels(self,values):
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE project_users SET panel_access=?,role="manager",permissions=? WHERE username="limited" AND project_id="p"',(json.dumps(values),json.dumps(['products'])))
    def destination(self,opener,path):
        response=opener.open(self.case.base+path);response.read();return response.geturl()
    def test_portal_only_blocks_admin_page_and_post(self):
        self.panels(['portal']);opener,csrf=self.case.login('limited')
        self.assertIn('/portal.php',self.destination(opener,'/panel/admin.php'))
        code,_=self.case.request(opener,'/panel/admin.php',{'update_product_data':'1','pid':'one','name':'Denied admin edit'},{'X-CSRF-Token':csrf})
        self.assertEqual(code,200);self.assertEqual(self.case.record()['name'],'محصول one')
    def test_admin_only_and_both_entry_points(self):
        self.panels(['admin']);opener,_=self.case.login('limited')
        self.assertIn('/admin.php',self.destination(opener,'/panel/portal.php'))
        opener,csrf=self.case.login('limited');self.assertEqual(self.case.edit(opener,csrf)[0],200)
        with sqlite3.connect(self.case.database) as db:self.assertEqual(json.loads(db.execute('SELECT panel_access FROM project_users WHERE project_id="p" AND username="limited"').fetchone()[0]),['admin'])
        self.panels(['portal','admin'])
        self.assertIn('/portal.php',self.destination(opener,'/panel/portal.php'))
        self.assertIn('/admin.php',self.destination(opener,'/panel/admin.php'))
    def test_live_revocation_and_feature_permissions_still_apply(self):
        self.panels(['portal','admin']);opener,_=self.case.login('limited')
        self.panels(['portal']);self.assertIn('/portal.php',self.destination(opener,'/panel/admin.php'))
        self.assertEqual(self.case.request(opener,'/panel/portal.php?tab=calendar')[0],403)
    def test_owner_creates_one_account_for_selected_projects_only(self):
        owner,csrf=self.case.login()
        with sqlite3.connect(self.case.database) as db:
            for pid in ['other','private']:db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)',(pid,pid,'2026-01-01','2030-01-01',json.dumps(self.case.settings)))
        body={'add_user':'1','username':'panel-created','password':'Fixture-password-123!','role':'manager','assigned_projects[]':['p','other'],'perms[]':['products'],'panel_access_present':'1','panel_access[]':['portal']}
        self.assertEqual(self.case.request(owner,'/panel/admin.php?tab=users',raw=urllib.parse.urlencode(body,doseq=True).encode(),headers={'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded'})[0],200)
        with sqlite3.connect(self.case.database) as db:
            members=db.execute('SELECT project_id,panel_access FROM project_users WHERE username="panel-created" ORDER BY project_id').fetchall()
        self.assertEqual([r[0] for r in members],['other','p']);self.assertTrue(all(json.loads(r[1])==['portal'] for r in members))
        user,_=self.case.login('panel-created')
        self.assertEqual(self.case.request(user,'/panel/portal.php?project=other')[0],200)
        self.assertEqual(self.case.request(user,'/panel/portal.php?project=private')[0],403)
        self.assertIn('/portal.php',self.destination(user,'/panel/admin.php'))
    def test_project_switch_rechecks_the_target_entry_permission(self):
        self.panels(['portal'])
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)',('other','Other','2026-01-01','2030-01-01',json.dumps(self.case.settings)))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions,panel_access) SELECT "other",username,password,role,permissions,? FROM project_users WHERE project_id="p" AND username="limited"',(json.dumps(['admin']),))
        user,_=self.case.login('limited')
        self.assertIn('/admin.php',self.destination(user,'/panel/admin.php?switch_project=other'))
        self.assertIn('/portal.php',self.destination(user,'/panel/portal.php?project=p'))
        self.assertIn('/portal.php',self.destination(user,'/panel/admin.php'))

    def test_admin_entry_does_not_grant_viewer_write_access(self):
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE project_users SET panel_access=? WHERE username="viewer" AND project_id="p"',(json.dumps(['admin']),))
        user,csrf=self.case.login('viewer')
        self.assertIn('/admin.php',self.destination(user,'/panel/admin.php'))
        self.assertEqual(self.case.edit(user,csrf)[0],403)
        self.assertEqual(self.case.record()['name'],'محصول one')
    def test_management_shared_action_works_without_portal_page_access(self):
        self.panels(['admin']);user,csrf=self.case.login('limited')
        code,body=self.case.request(user,'/panel/portal.php',{'action':'create_couple_set'},{'X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'})
        self.assertEqual(code,200);self.assertEqual(json.loads(body)['status'],'error')
        self.assertIn('/admin.php',self.destination(user,'/panel/portal.php'))

    def test_empty_entry_selection_is_rejected(self):
        owner,csrf=self.case.login()
        code,body=self.case.request(owner,'/panel/admin.php?tab=users',{'add_user':'1','username':'panel-created','password':'Fixture-password-123!','role':'manager','panel_access_present':'1'},{'X-CSRF-Token':csrf})
        self.assertEqual(code,200);self.assertIn('حداقل یکی',body)
        with sqlite3.connect(self.case.database) as db:self.assertIsNone(db.execute('SELECT username FROM project_users WHERE username="panel-created"').fetchone())
