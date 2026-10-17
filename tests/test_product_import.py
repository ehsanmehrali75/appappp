"""Large ERP imports use a full snapshot with bounded, resumable SQL writes."""
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
import test_http_security as fixtures


class ProductImport(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.state=tempfile.TemporaryDirectory(prefix='product-import-')
        # Reproduce PHP 8.0 hosting where array_is_list does not exist.
        cls.fixture=type('ImportFixture',(fixtures.HttpSecurity,),{'PHP_SETTINGS':('-d','disable_functions=array_is_list')})
        with patch.dict(os.environ,{'MAARKSHO_IMPORT_STATE_DIR':cls.state.name}):
            cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass();cls.state.cleanup()

    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp()
        self.opener,self.token=self.case.login('operator')
        with sqlite3.connect(self.case.database) as db:self.baseline_settings=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])

    def call(self,action,**fields):
        code,body=self.case.request(self.opener,'/panel/product_import.php',headers={'Content-Type':'application/json','X-CSRF-Token':self.token},raw=json.dumps(dict(project='p',action=action,**fields)).encode())
        self.assertEqual(code,200,body)
        return json.loads(body)

    def prepare(self,rows):
        chunks=[rows[i:i+100] for i in range(0,len(rows),100)]
        job=self.call('begin',chunks=len(chunks))['job']
        for i,chunk in enumerate(chunks):self.call('upload',job=job,index=i,rows=chunk)
        return job,self.call('prepare',job=job)

    def test_full_snapshot_batches_resume_and_preserve_media_settings_members(self):
        rows=[dict(sku='one',name='محصول one',color='عمومی',size=str(i),stock_tedad=2,anbar_code=str(i)) for i in range(110)]
        job,state=self.prepare(rows)
        self.assertEqual(self.case.record()['stock_total'],'10','No product changes during upload/prepare')
        while state['state']!='done':state=self.call('apply',job=job)
        record=self.case.record()
        self.assertEqual(float(record['stock_total']),220)
        self.assertEqual(record['image'],'uploads/original.png')
        self.assertEqual(self.call('apply',job=job)['state'],'done')
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0]),self.baseline_settings)
            self.assertEqual(db.execute('SELECT count(*) FROM project_users WHERE project_id="p"').fetchone()[0],4)

    def test_large_file_progress_and_lost_acknowledgement(self):
        rows=[dict(sku='ERP-'+str(i),name='محصول '+str(i),color='سبز',stock_tedad=6) for i in range(5000)]
        job,state=self.prepare(rows)
        self.call('apply',job=job)
        state_file=Path(self.state.name)/job/'state.json'
        stale=json.loads(state_file.read_text());stale['cursor']=0;stale['state']='prepared';state_file.write_text(json.dumps(stale))
        state=self.call('status',job=job)
        self.assertEqual(state['cursor'],1,'Database receipt survives a lost state-file acknowledgement')
        while state['state']!='done':state=self.call('apply',job=job)
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0],5005)
            self.assertEqual(db.execute('SELECT count(*) FROM product_import_receipts WHERE job=?',(job,)).fetchone()[0],101)

    def test_conflicting_manual_change_is_not_overwritten(self):
        job,_=self.prepare([dict(sku='one',name='محصول one',stock_tedad=9)])
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET description="Manual concurrent edit" WHERE id="one"')
        code,body=self.case.request(self.opener,'/panel/product_import.php',headers={'Content-Type':'application/json','X-CSRF-Token':self.token},raw=json.dumps(dict(project='p',action='apply',job=job)).encode())
        self.assertEqual(code,422,body)
        self.assertEqual(self.case.record()['description'],'Manual concurrent edit')

    def test_retries_and_invalid_sources_and_access(self):
        job=self.call('begin',chunks=1)['job'];rows=[dict(sku='one',name='محصول one',stock_tedad=9)]
        self.call('upload',job=job,index=0,rows=rows)
        self.assertEqual(self.call('upload',job=job,index=0,rows=rows)['rows'],1)
        code,_=self.case.request(self.opener,'/panel/product_import.php',headers={'Content-Type':'application/json'},raw=b'{"project":"p","action":"begin","chunks":1}')
        self.assertEqual(code,403)
        other,token=self.case.login('limited')
        code,_=self.case.request(other,'/panel/product_import.php',headers={'Content-Type':'application/json','X-CSRF-Token':token},raw=b'{"project":"p","action":"begin","chunks":1}')
        self.assertEqual(code,403)
        other,token=self.case.login()
        code,_=self.case.request(other,'/panel/product_import.php',headers={'Content-Type':'application/json','X-CSRF-Token':token},raw=json.dumps(dict(project='p',action='status',job=job)).encode())
        self.assertEqual(code,422,'Even owner cannot resume another session import')

    def test_same_sku_in_another_project_is_never_replaced(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT OR REPLACE INTO projects(id,name,settings) VALUES ("other","Private other project","{}")')
            db.execute('INSERT OR REPLACE INTO products(id,project_id,sku,name) VALUES ("ERP-SAME","other","ERP-SAME","Private other product")')
        job,state=self.prepare([dict(sku='ERP-SAME',name='New local product',stock_tedad=8)])
        while state['state']!='done':state=self.call('apply',job=job)
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT project_id,name FROM products WHERE id="ERP-SAME"').fetchone(),('other','Private other product'))
            self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p" AND sku="ERP-SAME"').fetchone()[0],1)

    def test_legacy_task_and_chat_entry_points_do_not_accept_foreign_project(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT OR REPLACE INTO projects(id,name,settings) VALUES ("other","Private other project","{}")')
        for path in ['/task_manager.php?project=other','/user_chat.php?project=other','/api.php?project_id=other&action=get_project_status']:
            with self.subTest(path=path):
                code,body=self.case.request(self.opener,path)
                self.assertIn(code,[401,403])
                self.assertNotIn('Private other project',body)

    def test_upload_rejects_object_instead_of_row_list(self):
        job=self.call('begin',chunks=1)['job']
        for rows in [[],{'product':{'sku':'one','name':'Invalid object'}}]:
            with self.subTest(rows=rows):
                code,body=self.case.request(self.opener,'/panel/product_import.php',headers={'Content-Type':'application/json','X-CSRF-Token':self.token},raw=json.dumps(dict(project='p',action='upload',job=job,index=0,rows=rows)).encode())
                self.assertEqual(code,422,body)
                self.assertIn('بخش ارسالی معتبر نیست',json.loads(body)['error'])
        self.assertEqual(self.call('status',job=job)['uploaded'],0)
