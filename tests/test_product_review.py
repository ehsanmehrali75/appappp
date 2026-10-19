"""Issue labels pause production, retain ERP inventory and survive website reconciliation."""
import json
import sqlite3
import unittest
import test_http_security as fixtures


class ProductReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('ProductReviewFixture',(fixtures.HttpSecurity,),{})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()

    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp();self.case.production_fixture(request_required=False,reference_required=True)
        self.opener,self.token=self.case.login('operator')
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract')
        import re
        self.site_csrf=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',body)[1]

    def mark(self,kind,pid='one'):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_set_issue',csrf=self.site_csrf,product_id=pid,issue_type=kind,note='پیگیری آزمایشی'),{'X-CSRF-Token':self.token})
        self.assertEqual(code,200,body)
        self.assertEqual(json.loads(body)['status'],'success')

    def contains(self,queue,pid='one'):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract&wc_queue='+queue)
        self.assertEqual(code,200)
        return 'data-product-id="'+pid+'"' in body

    def test_issue_owns_its_queue_and_clearing_resumes_reference(self):
        self.assertTrue(self.contains('missing_photo'))
        self.mark('technical');self.assertTrue(self.contains('technical_pending'));self.assertTrue(self.contains('photo_review'))
        for queue in ['pending_image','needs_reference','missing_photo','reference_ready']:self.assertFalse(self.contains(queue),queue)
        self.mark('inventory');self.assertTrue(self.contains('inventory_issue'));self.assertFalse(self.contains('technical_pending'))
        self.assertEqual(self.case.record()['stock_total'],'10')
        code,body=self.case.request(self.opener,'/panel/portal.php?tab=no-image')
        production_tab=body.split('id="tab-no-image"',1)[1].split('</main>',1)[0]
        self.assertTrue('data-product-id="one"' not in production_tab,'Issue product must not render in the production tab')
        self.mark('none');self.assertTrue(self.contains('needs_reference'));self.assertFalse(self.contains('inventory_issue'))

    def test_explicit_zero_stock_issue_is_reviewable_but_never_enters_production(self):
        self.mark('inventory','zero')
        self.assertTrue(self.contains('inventory_issue','zero'));self.assertTrue(self.contains('photo_review','zero'))
        self.assertFalse(self.contains('pending_image','zero'));self.assertEqual(self.case.record('zero')['stock_total'],'0')

    def test_website_import_does_not_silently_clear_review_flag(self):
        self.mark('technical')
        row=dict(url='https://shop.example/product/one/model',product_code='one',gallery=['https://shop.example/main.webp'],main_image='https://shop.example/main.webp')
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_preview',csrf=self.site_csrf,data=json.dumps([row])),{'X-CSRF-Token':self.token})
        preview=json.loads(body)
        self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_apply',csrf=self.site_csrf,token=preview['token']),{'X-CSRF-Token':self.token})
        self.assertEqual(json.loads(self.case.record()['website_sync'])['review_issue']['type'],'technical')
        self.assertTrue(self.contains('technical_pending'));self.assertFalse(self.contains('pending_image'))

    def test_issue_mutation_requires_edit_access_and_rejects_foreign_product(self):
        viewer,token=self.case.login('viewer')
        code,_=self.case.request(viewer,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_set_issue',product_id='one',issue_type='technical',csrf=self.site_csrf),{'X-CSRF-Token':token})
        self.assertEqual(code,403)
        code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_set_issue',product_id='one',issue_type='technical'))
        self.assertEqual(code,403)
        with sqlite3.connect(self.case.database) as db:db.execute('INSERT OR REPLACE INTO products(id,project_id,name) VALUES ("foreign-review","other","Private")')
        code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_set_issue',csrf=self.site_csrf,product_id='foreign-review',issue_type='technical'),{'X-CSRF-Token':self.token})
        self.assertEqual(code,400)

    def action(self,action,**fields):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action=action,csrf=self.site_csrf,**fields),{'X-CSRF-Token':self.token})
        self.assertEqual(code,200,body);self.assertEqual(json.loads(body)['status'],'success')

    def test_image_opt_out_is_persistent_independent_and_reversible(self):
        self.action('website_catalog_set_preference',product_id='one',value='not_required')
        self.assertTrue(self.contains('image_not_required'))
        for queue in ['missing_photo','pending_image','needs_reference']:self.assertFalse(self.contains(queue),queue)
        self.mark('technical');self.assertTrue(self.contains('technical_pending'))
        self.mark('none');self.assertTrue(self.contains('image_not_required'));self.assertFalse(self.contains('missing_photo'))
        self.action('website_catalog_set_preference',product_id='one',value='required')
        self.assertTrue(self.contains('missing_photo'));self.assertTrue(self.contains('needs_reference'))
        self.assertEqual(self.case.record()['stock_total'],'10')

    def test_category_rule_excludes_only_matching_project_category_and_reverses(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET website_sync=? WHERE id="one"',(json.dumps({'category_labels':['کفش','دویدن']}),))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        self.assertTrue(self.contains('needs_size_chart'))
        self.action('website_catalog_category_rule',category='کفش',value='not_required')
        self.assertFalse(self.contains('needs_size_chart'));self.assertTrue(self.contains('chart_not_required'))
        self.assertTrue(self.contains('needs_size_chart','manual'))
        self.action('website_catalog_category_rule',category='کفش',value='required')
        self.assertTrue(self.contains('needs_size_chart'));self.assertFalse(self.contains('chart_not_required'))

    def test_preferences_and_category_rules_require_edit_access(self):
        viewer,token=self.case.login('viewer')
        for action,fields in [('website_catalog_set_preference',dict(product_id='one',value='not_required')),('website_catalog_category_rule',dict(category='کفش',value='not_required'))]:
            code,_=self.case.request(viewer,'/panel/admin.php?tab=extract',dict(ajax_action=action,csrf=self.site_csrf,**fields),{'X-CSRF-Token':token});self.assertEqual(code,403)
            code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action=action,**fields),{'X-CSRF-Token':self.token});self.assertEqual(code,403)
        code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_set_preference',csrf=self.site_csrf,product_id='foreign',value='not_required'),{'X-CSRF-Token':self.token});self.assertEqual(code,400)
        code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_category_rule',csrf=self.site_csrf,category='unknown',value='not_required'),{'X-CSRF-Token':self.token});self.assertEqual(code,400)
