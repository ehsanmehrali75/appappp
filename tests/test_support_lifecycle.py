"""Real HTTP helpdesk delivery and isolated project reset/permanent-delete regressions."""
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import test_http_security as fixtures


class SupportLifecycle(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.state=tempfile.TemporaryDirectory(prefix='support-lifecycle-')
        cls.support=Path(cls.state.name)/'support';cls.support.mkdir()
        cls.imports=Path(cls.state.name)/'imports';cls.imports.mkdir()
        cls.fixture=type('SupportLifecycleFixture',(fixtures.HttpSecurity,),{})
        with patch.dict(os.environ,{'SUPPORT_STORAGE_DIR':str(cls.support),'MAARKSHO_IMPORT_STATE_DIR':str(cls.imports)}):cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass();cls.state.cleanup()

    def setUp(self):
        (self.fixture.app/'panel/db/deleted_projects.json').unlink(missing_ok=True)
        with sqlite3.connect(self.fixture.database) as db:
            db.execute('INSERT OR REPLACE INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)',('p','پروژه آزمایشی','2026-01-01','2030-01-01',json.dumps(self.fixture.settings)))
            for name,role,permissions in [('limited','manager',['products']),('editor','editor',['products']),('viewer','viewer',['products']),('operator','manager',['products','extract','website_export','reference_photo'])]:
                db.execute('INSERT OR REPLACE INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('p',name,'Fixture-password-123!',role,json.dumps(permissions)))
        self.case=self.fixture('runTest');self.case.setUp()
        self.owner,self.token=self.case.login()
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT OR REPLACE INTO projects(id,name,settings) VALUES ("other","Other project","{}")')
            db.execute('INSERT OR REPLACE INTO products(id,project_id,sku,name,image) VALUES ("other-photo","other","OTHER","Other product","uploads/original.png")')

    def support_token(self,opener,path='/panel/support.php'):
        code,body=self.case.request(opener,path);self.assertEqual(code,200,body[:200])
        return re.search(r'name="csrf" value="([a-f0-9]+)"',body)[1],body

    def create_ticket(self,opener,csrf,subject):
        code,body=self.case.request(opener,'/panel/support.php',dict(action='create',project='p',csrf=csrf,subject=subject,category='bug',body='توضیح کامل و لاگ آزمایشی'))
        self.assertEqual(code,200,body[:300]);self.assertNotIn('پروژهٔ درخواست را انتخاب کنید.',body)
        with sqlite3.connect(self.case.database) as db:return db.execute('SELECT id FROM support_tickets WHERE subject=?',(subject,)).fetchone()[0]

    def test_actual_form_has_its_own_project_selection_and_admin_receives_ticket(self):
        user,token=self.case.login('limited');csrf,body=self.support_token(user)
        form=body.split('id="support-create"',1)[1].split('</form>',1)[0]
        self.assertIn('<select name="project" required>',form)
        self.assertNotIn('type="hidden" name="project"',form)
        tid=self.create_ticket(user,csrf,'User helpdesk delivery')
        _,inbox=self.support_token(self.owner)
        self.assertIn('User helpdesk delivery',inbox)
        self.assertIn('project=p&amp;ticket='+tid,inbox)
        admin_csrf,detail=self.support_token(self.owner,'/panel/support.php?ticket='+tid)
        self.assertIn('توضیح کامل و لاگ آزمایشی',detail)
        self.assertIn('name="project" value="p"',detail)
        self.case.request(self.owner,'/panel/support.php',dict(action='reply',project='p',ticket_id=tid,csrf=admin_csrf,body='پاسخ مدیر اصلی'))
        code,reply=self.case.request(user,'/panel/support.php?project=p&ticket='+tid)
        self.assertEqual(code,200);self.assertIn('پاسخ مدیر اصلی',reply)
        _,panel=self.case.request(self.owner,'/panel/admin.php?tab=products')
        self.assertIn('صندوق پشتیبانی',panel)

    def test_admin_can_select_project_inside_create_form_from_all_project_inbox(self):
        csrf,body=self.support_token(self.owner)
        self.assertIn('<option value="">پروژه را انتخاب کنید</option>',body)
        self.create_ticket(self.owner,csrf,'Admin selected project')

    def test_reset_does_not_reinsert_schedules_and_preserves_settings_members_and_other_project(self):
        with sqlite3.connect(self.case.database) as db:
            settings=dict(self.case.settings,schedules=[dict(id='stale',platform='instagram')],schedule_slots=['12:00'])
            db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(settings),))
            db.execute('INSERT OR REPLACE INTO schedules(id,project_id,product_id) VALUES ("stale","p","one")')
            db.execute('INSERT OR REPLACE INTO schedules(id,project_id,product_id) VALUES ("other-job","other","other-photo")')
        code,body=self.case.request(self.owner,'/panel/admin.php?tab=projects',dict(reset_project='1',pid='p'),{'X-CSRF-Token':self.token})
        self.assertEqual(code,200)
        with sqlite3.connect(self.case.database) as db:
            for table in ['products','schedules','logs']:self.assertEqual(db.execute('SELECT count(*) FROM '+table+' WHERE project_id="p"').fetchone()[0],0)
            self.assertEqual(db.execute('SELECT count(*) FROM project_users WHERE project_id="p"').fetchone()[0],4)
            saved=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0]);self.assertEqual(saved['schedules'],[]);self.assertEqual(saved['tiam_inventory'],settings['tiam_inventory'])
            self.assertEqual(db.execute('SELECT count(*) FROM schedules WHERE project_id="other"').fetchone()[0],1)
        self.assertTrue((self.case.app/'panel/uploads/original.png').is_file())

    def test_complete_delete_clears_project_children_assets_modules_jobs_and_blocks_stale_save(self):
        csrf,_=self.support_token(self.owner);self.create_ticket(self.owner,csrf,'Deletion ticket')
        exclusive=self.case.app/'panel/uploads/exclusive.png';exclusive.write_bytes(fixtures.PNG)
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET image="uploads/exclusive.png",gallery=? WHERE id="one"',(json.dumps(['uploads/original.png']),))
            db.execute('INSERT OR REPLACE INTO proposals(id,project_id,title) VALUES ("proposal-p","p","Private proposal")')
        warehouse=self.case.app/'panel/db/vm_data.json'
        warehouse.write_text(json.dumps(dict(projects=[dict(id='p'),dict(id='other')],stores=[dict(id='sp',project_id='p'),dict(id='so',project_id='other')],layouts=[dict(id='lp',store_id='sp'),dict(id='lo',store_id='so')])))
        job=self.imports/('a'*64);job.mkdir(exist_ok=True);(job/'state.json').write_text(json.dumps(dict(project='p')));(job/'batch-0.json').write_text('[]')
        wrong,_=self.case.request(self.owner,'/panel/admin.php?tab=projects',dict(delete_project='1',pid='p',confirm_project_name='wrong'),{'X-CSRF-Token':self.token})
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM projects WHERE id="p"').fetchone()[0],1)
        code,body=self.case.request(self.owner,'/panel/admin.php?tab=projects',dict(delete_project='1',pid='p',confirm_project_name='پروژه آزمایشی'),{'X-CSRF-Token':self.token})
        self.assertEqual(code,200,body[:200])
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM projects WHERE id="p"').fetchone()[0],0)
            for table in ['products','project_users','support_tickets','proposals']:self.assertEqual(db.execute('SELECT count(*) FROM '+table+' WHERE project_id="p"').fetchone()[0],0)
            self.assertEqual(db.execute('SELECT count(*) FROM support_messages WHERE ticket_id NOT IN (SELECT id FROM support_tickets)').fetchone()[0],0)
            self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="other"').fetchone()[0],1)
        self.assertFalse(exclusive.exists());self.assertTrue((self.case.app/'panel/uploads/original.png').exists());self.assertFalse(job.exists())
        saved=json.loads(warehouse.read_text());self.assertEqual(saved['stores'],[dict(id='so',project_id='other')]);self.assertEqual(saved['layouts'],[dict(id='lo',store_id='so')])
        result=subprocess.run([fixtures.PHP,'-r','require $argv[1]; try {db_save_project(["id"=>"p"]);echo "UNEXPECTED";} catch(RuntimeException $e){echo "STALE_BLOCKED";}',str(self.case.app/'panel/inc/functions.php')],capture_output=True,text=True,timeout=10)
        self.assertIn('STALE_BLOCKED',result.stdout)

    def test_project_actions_require_main_admin_and_csrf(self):
        limited,token=self.case.login('limited')
        for opener,fields,headers in [(limited,dict(delete_project='1',pid='p',confirm_project_name='پروژه آزمایشی'),{'X-CSRF-Token':token}),(self.owner,dict(reset_project='1',pid='p'),{})]:
            self.assertEqual(self.case.request(opener,'/panel/admin.php?tab=projects',fields,headers)[0],403)

    def test_delete_after_reset_remembers_exclusive_files(self):
        exclusive=self.case.app/'panel/uploads/reset-owned.png';exclusive.write_bytes(fixtures.PNG)
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET image="uploads/reset-owned.png" WHERE id="one"')
        self.case.request(self.owner,'/panel/admin.php?tab=projects',dict(reset_project='1',pid='p'),{'X-CSRF-Token':self.token})
        self.assertTrue(exclusive.exists(),'Reset preserves original files')
        self.case.request(self.owner,'/panel/admin.php?tab=projects',dict(delete_project='1',pid='p',confirm_project_name='پروژه آزمایشی'),{'X-CSRF-Token':self.token})
        self.assertFalse(exclusive.exists(),'Permanent delete cleans known exclusive files even after reset')
