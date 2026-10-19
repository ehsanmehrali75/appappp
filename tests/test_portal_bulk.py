"""Library bulk changes share reconciliation state, authorization and project boundaries."""
import json
import re
import sqlite3
import unittest
from urllib.parse import urlencode
import test_http_security as fixtures


class PortalBulk(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('PortalBulkFixture',(fixtures.HttpSecurity,),{})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()

    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp();self.case.production_fixture(request_required=True,reference_required=True)
        self.opener,self.token=self.case.login('operator')

    def bulk(self,operation,ids,opener=None,token=None):
        fields=[('action','bulk_manage_products'),('operation',operation)]+[('product_ids[]',pid) for pid in ids]
        return self.case.request(opener or self.opener,'/panel/portal.php?project=p&tab=library',headers={'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest','X-CSRF-Token':token or self.token},raw=urlencode(fields).encode())

    def good(self,operation,ids):
        code,body=self.bulk(operation,ids);self.assertEqual(code,200,body);return json.loads(body)

    def queue(self,queue,pid='one'):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract&wc_queue='+queue)
        self.assertEqual(code,200);return 'data-product-id="'+pid+'"' in body

    def test_bulk_decisions_are_shared_with_admin_and_reversible(self):
        self.assertEqual(self.good('image_not_required',['one','manual'])['updated'],2)
        self.assertFalse(self.queue('missing_photo'));self.assertTrue(self.queue('image_not_required'))
        self.good('technical',['one','manual']);self.assertTrue(self.queue('technical_pending'))
        self.good('clear_issue',['one']);self.assertTrue(self.queue('image_not_required'));self.assertFalse(self.queue('missing_photo'))
        self.good('image_required',['one']);self.assertTrue(self.queue('missing_photo'))
        self.good('chart_not_required',['one','manual']);self.assertFalse(self.queue('needs_size_chart'));self.assertTrue(self.queue('chart_not_required'));self.assertTrue(self.queue('image_request'))
        self.good('chart_required',['one']);self.assertTrue(self.queue('needs_size_chart'))
        record=self.case.record();self.assertEqual(record['stock_total'],'10');self.assertEqual(record['image'],'')

    def test_content_request_honors_operator_assignment_and_prerequisites(self):
        self.assertEqual(self.bulk('content_request',['one'])[0],403)
        self.good('technical',['manual']);self.good('image_not_required',['unknown'])
        self.opener,self.token=self.case.login('fixture_owner')
        body=self.good('content_request',['one','manual','zero','site','unknown'])
        self.assertEqual((body['updated'],body['skipped']),(1,4))
        self.assertTrue(self.case.record()['content_gen_requested']);self.assertTrue(self.queue('needs_reference'))
        self.assertEqual(self.good('content_request',['one'])['skipped'],1)

    def test_viewers_csrf_and_foreign_ids_cannot_mutate(self):
        viewer,token=self.case.login('viewer');self.assertEqual(self.bulk('technical',['one'],viewer,token)[0],403)
        self.assertEqual(self.bulk('technical',['one'],token='invalid')[0],403)
        with sqlite3.connect(self.case.database) as db:db.execute('INSERT INTO products(id,project_id,name) VALUES ("foreign-bulk","other","private")')
        for ids in [['one','foreign-bulk'],['one']*101,[]]:
            self.assertEqual(self.bulk('technical',ids)[0],400)
            self.assertNotIn('review_issue',json.loads(self.case.record()['website_sync']))
        self.assertEqual(self.bulk('invalid',['one'])[0],400)

    def test_library_selection_includes_products_without_images(self):
        code,body=self.case.request(self.opener,'/panel/portal.php?project=p&tab=library')
        self.assertEqual(code,200);library=body.split('id="tab-library"',1)[1].split('id="tab-',1)[0]
        self.assertIn('portal-library-batch-form',library)
        self.assertTrue(re.search(r'name="selected_library_products\[\]" value="one"',library))
        viewer,_=self.case.login('viewer');_,body=self.case.request(viewer,'/panel/portal.php?project=p&tab=library')
        self.assertNotIn('id="portal-library-batch-form"',body)

    def test_website_hierarchy_imports_beside_central_categories_and_refreshes(self):
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET website_sync=? WHERE id="one"',(json.dumps({'category_labels':['مرکزی']}),))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        _,body=self.case.request(self.opener,'/panel/admin.php?tab=extract');csrf=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',body)[1]
        def apply(categories):
            row=dict(url='https://shop.example/product/one/model',product_code='one',category=categories,category_path='/'.join(categories),primary_category=categories[-1])
            code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_preview',csrf=csrf,data=json.dumps([row])),{'X-CSRF-Token':self.token})
            self.assertEqual(code,200,body)
            code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_apply',csrf=csrf,token=json.loads(body)['token']),{'X-CSRF-Token':self.token})
            self.assertEqual(code,200,body)
        apply(['مردانه','پوشاک','تی شرت ورزشی'])
        meta=json.loads(self.case.record()['website_sync']);self.assertEqual(meta['site_category_labels'],['مردانه','پوشاک','تی شرت ورزشی'])
        self.assertEqual(meta['site_primary_category'],'تی شرت ورزشی');self.assertEqual(meta['category_labels'],['مرکزی'])
        code,body=self.case.request(self.opener,'/panel/portal.php?'+urlencode({'project':'p','tab':'library','f_category':'پوشاک'}))
        self.assertEqual(code,200);library=body.split('id="tab-library"',1)[1].split('id="tab-',1)[0]
        self.assertIn('data-product-id="one"',library);self.assertNotIn('data-product-id="manual"',library)
        apply(['ورزش','کمپ و سفر']);meta=json.loads(self.case.record()['website_sync'])
        self.assertEqual(meta['site_category_path'],'ورزش/کمپ و سفر');self.assertEqual(meta['site_primary_category'],'کمپ و سفر')
        self.assertEqual(meta['category_labels'],['مرکزی'])

    def test_chart_restoration_keeps_project_category_rule(self):
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE products SET website_sync=? WHERE id="one"',(json.dumps({'site_category_labels':['کفش']}),))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        _,body=self.case.request(self.opener,'/panel/admin.php?tab=extract');csrf=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',body)[1]
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_category_rule',csrf=csrf,category='کفش',value='not_required'),{'X-CSRF-Token':self.token});self.assertEqual(code,200,body)
        self.good('chart_not_required',['one']);self.good('chart_required',['one'])
        self.assertTrue(self.queue('chart_not_required'));self.assertFalse(self.queue('needs_size_chart'))
        self.assertTrue(self.queue('image_request'),'Chart exception must not suppress image production')

    def test_legacy_production_button_uses_the_same_review_guards(self):
        self.good('technical',['one']);self.opener,self.token=self.case.login('fixture_owner')
        code,body=self.case.request(self.opener,'/panel/portal.php?project=p',dict(action='bulk_request_content_gen',**{'product_ids[]':'one'}),{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':self.token})
        self.assertEqual(code,200,body);self.assertEqual(json.loads(body)['skipped'],1)
        self.assertFalse(self.case.record()['content_gen_requested'])
        self.assertEqual(json.loads(self.case.record()['website_sync'])['review_issue']['type'],'technical')
