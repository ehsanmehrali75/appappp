"""Inventory preview, SQL-atomic history and conflict-safe rollback, in disposable SQLite."""
import json,re,sqlite3,tempfile,unittest
from unittest.mock import patch
import test_http_security as fixtures

class InventoryVersions(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.state=tempfile.TemporaryDirectory(prefix='inventory-versions-')
        cls.fixture=type('VersionFixture',(fixtures.HttpSecurity,),{})
        with patch.dict('os.environ',{'MAARKSHO_IMPORT_STATE_DIR':cls.state.name}):cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass();cls.state.cleanup()
    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp();
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps({k:v for k,v in self.case.settings.items() if k!="tiam_inventory"}),))
        self.opener,self.csrf=self.case.login('operator')
    def api(self,path,body,expected=200,opener=None,csrf=None):
        code,raw=self.case.request(opener or self.opener,path,raw=json.dumps({'project':'p',**body}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf or self.csrf});self.assertEqual(code,expected,raw);return json.loads(raw)
    def imp(self,action,**data):return self.api('/panel/product_import.php',dict(action=action,**data))
    def version(self,action,run,**data):return self.api('/panel/inventory_versions.php',dict(action=action,run_id=run,**data))
    def prepare(self,extra=1):
        rows=[dict(sku='one',name='محصول one',color='عمومی',stock_tedad=4)]+[dict(sku='NEW-'+str(i),name='New model '+str(i),color='Blue',size='M',stock_tedad=2) for i in range(extra)]
        chunks=[rows[i:i+100] for i in range(0,len(rows),100)];job=self.imp('begin',chunks=len(chunks))['job']
        for i,chunk in enumerate(chunks):self.imp('upload',job=job,index=i,rows=chunk)
        return job,self.imp('prepare',job=job)
    def apply(self,job,state):
        while state['state']!='done':state=self.imp('apply',job=job)
        return state
    def test_preview_cancel_and_paginated_before_after(self):
        job,state=self.prepare(29);self.assertEqual(self.case.record()['stock_total'],'10');changes=self.version('changes',job)
        self.assertEqual(changes['total'],34);self.assertEqual(len(changes['rows']),25);self.assertEqual(len(self.version('changes',job,page=2)['rows']),9)
        row=next(r for r in changes['rows'] if r['id']=='one');self.assertEqual(float(row['before_stock']),10);self.assertEqual(float(row['after_stock']),4)
        self.imp('cancel',job=job);self.assertEqual(self.imp('status',job=job)['state'],'cancelled');self.assertEqual(self.case.record()['stock_total'],'10')
    def test_rollback_restores_stock_preserves_later_photo_and_removes_only_new(self):
        job,state=self.prepare();self.apply(job,state)
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET image="uploads/later-photo.png" WHERE id="one"')
        result=self.version('rollback',job);self.assertEqual(result['status'],'rolled_back');self.assertEqual(self.case.record()['stock_total'],'10');self.assertEqual(self.case.record()['image'],'uploads/later-photo.png')
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0],5)
        self.assertTrue(self.version('rollback',job)['already_done']);self.assertEqual(self.imp('apply',job=job)['state'],'cancelled')
    def test_later_inventory_edit_blocks_every_part_of_rollback(self):
        job,state=self.prepare();self.apply(job,state)
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET stock_total="999" WHERE id="one"')
        response=self.api('/panel/inventory_versions.php',dict(action='rollback',run_id=job),422);self.assertIn('تعارض',response['error']);self.assertEqual(self.case.record()['stock_total'],'999')
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0],6)
    def test_partial_import_rollback_cannot_resume_old_batches(self):
        job,state=self.prepare(60);state=self.imp('apply',job=job);self.assertEqual(state['state'],'applying');self.version('rollback',job)
        self.assertEqual(self.imp('apply',job=job)['state'],'cancelled')
        with sqlite3.connect(self.case.database) as db:self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0],5)
    def test_new_product_with_later_content_is_protected(self):
        job,state=self.prepare();self.apply(job,state)
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET description="Later manual content" WHERE sku="NEW-0"')
        self.api('/panel/inventory_versions.php',dict(action='rollback',run_id=job),422);self.assertEqual(self.case.record()['stock_total'],'4')
    def test_history_survives_temporary_job_removal(self):
        job,state=self.prepare();self.apply(job,state)
        import shutil
        for path in __import__('pathlib').Path(self.state.name).rglob(job):
            if path.is_dir():shutil.rmtree(path)
        self.assertTrue(any(r['id']==job for r in self.version('history',job)['runs']))
        self.assertTrue(self.version('changes',job)['rows'][0]['field_values'])
        self.version('rollback',job);self.assertEqual(self.case.record()['stock_total'],'10')
    def test_auth_csrf_and_project_boundaries(self):
        job,state=self.prepare();viewer,token=self.case.login('viewer')
        self.api('/panel/inventory_versions.php',dict(action='changes',run_id=job),403,viewer,token)
        code,_=self.case.request(self.opener,'/panel/inventory_versions.php',raw=json.dumps(dict(project='p',action='history')).encode(),headers={'Content-Type':'application/json'});self.assertEqual(code,403)
        owner,token=self.case.login();self.api('/panel/inventory_versions.php',dict(project='unknown',action='changes',run_id=job),403,owner,token)
    def test_full_tiam_run_previews_before_apply_and_has_rollback(self):
        owner,csrf=self.case.login();code,page=self.case.request(owner,'/panel/admin.php?tab=settings');tiam_csrf=re.search(r'const tiamInventoryCsrf = "([a-f0-9]+)"',page).group(1)
        def tiam(action,**data):return self.api('/panel/tiam_inventory.php',dict(action=action,csrf=tiam_csrf,**data),opener=owner,csrf=csrf)
        token='d'*64
        tiam('save',config=dict(host='192.0.2.1',database='Fixture',view='Stock',username='reader',password='fixture-password',agent_token=token,mapping=dict(sku='Code',name='Name',model='Name',size='Size',color='Color',stock='Qty')))
        job=tiam('run_full')['id']
        self.api('/panel/tiam_inventory.php',dict(action='agent_poll',agent_token=token))
        result=self.api('/panel/tiam_inventory.php',dict(action='agent_result',agent_token=token,job=job,success=True,rows=[dict(Code='one',Name='محصول one',Size='M',Color='White',Qty=7)],logs=[]))
        self.assertEqual(result['state'],'preview');self.assertEqual(self.case.record()['stock_total'],'10')
        tiam('apply',job=job);self.assertEqual(self.case.record()['stock_total'],'7')
        self.api('/panel/inventory_versions.php',dict(action='rollback',run_id=job),opener=owner,csrf=csrf);self.assertEqual(self.case.record()['stock_total'],'10')
        with sqlite3.connect(self.case.database) as db:cfg=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])['tiam_inventory']
        self.assertFalse(cfg['enabled']);self.assertNotIn('approved',cfg)
