"""Filtered workflow round-trips update existing products only, with preview and conflict guards."""
import json,re,sqlite3,unittest
from urllib.parse import urlencode
import test_http_security as fixtures

class WorkflowTransferTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('TransferFixture',(fixtures.HttpSecurity,),{});cls.fixture.setUpClass()
    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()
    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp();self.case.production_fixture(request_required=True,reference_required=True)
        self.opener,self.token=self.case.login('operator')
        _,page=self.case.request(self.opener,'/panel/admin.php?tab=website_export')
        self.csrf=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',page)[1]
    def call(self,action,**fields):
        return self.case.request(self.opener,'/panel/admin.php?tab=website_export',dict(ajax_action=action,csrf=self.csrf,**fields),{'X-CSRF-Token':self.token})
    def good(self,action,**fields):
        code,body=self.call(action,**fields);self.assertEqual(code,200,body);return json.loads(body)
    def export(self,**filters):return self.good('workflow_export',scope='queue',filters=json.dumps(filters))['payload']
    def preview(self,payload):return self.good('workflow_preview',data=json.dumps(payload))
    def test_export_uses_exact_filters_across_all_pages(self):
        with sqlite3.connect(self.case.database) as db:
            for i in range(25):db.execute('INSERT INTO products(id,project_id,sku,name,stock_total) VALUES (?,?,?,?,?)',(f'filtered-{i}','p',f'F-{i}','Target '+str(i),'20'))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        payload=self.export(wc_queue='missing_photo',wc_q='Target',wc_stock='gt_15',wc_size='10',wc_page='2')
        self.assertEqual(len(payload['products']),25)
        self.assertEqual(len(self.export(wc_queue='missing_photo',wc_q='Target',wc_stock='gt_20')['products']),0)
        code,page=self.case.request(self.opener,'/panel/admin.php?'+urlencode(dict(tab='website_export',wc_queue='missing_photo',wc_q='Target',wc_stock='gt_15',wc_size=10,wc_page=2)))
        ids=re.findall(r'<article[^>]+data-product-id="([^"]+)"',page);self.assertEqual(len(ids),10)
        self.assertTrue(set(ids)<=set(row['product_id'] for row in payload['products']))
    def test_preview_then_apply_and_round_trip_noop(self):
        payload=self.export(wc_queue='missing_photo',wc_q='one')
        self.assertEqual(len(payload['products']),1);row=payload['products'][0]
        row.update(image_requirement='not_required',chart_requirement='not_required',issue='technical',note='بررسی از اکسل')
        before=self.case.record();preview=self.preview(payload);self.assertEqual(preview['updated'],1)
        self.assertEqual(self.case.record()['website_sync'],before['website_sync'],'Preview cannot write')
        self.good('workflow_apply',token=preview['token']);after=self.case.record();meta=json.loads(after['website_sync'])
        self.assertTrue(meta['content_preferences']['image_not_required']);self.assertTrue(meta['content_preferences']['chart_not_required'])
        self.assertEqual(meta['review_issue']['note'],'بررسی از اکسل');self.assertEqual(after['stock_total'],before['stock_total']);self.assertEqual(after['image'],before['image'])
        self.assertEqual(self.preview(self.export(wc_queue='technical_pending',wc_q='one'))['updated'],0)
        restored=self.export(wc_queue='technical_pending',wc_q='one');restored['products'][0].update(image_requirement='required',chart_requirement='inherit',issue='none')
        self.good('workflow_apply',token=self.preview(restored)['token'])
        self.assertEqual(len(self.export(wc_queue='image_request',wc_q='one')['products']),1)
    def test_foreign_duplicates_bad_values_and_stale_files_are_rejected(self):
        for mutate in [lambda p:p['products'].append(dict(p['products'][0])),lambda p:p['products'][0].update(project_id='other'),lambda p:p['products'][0].update(product_id='foreign'),lambda p:p['products'][0].update(issue='wrong'),lambda p:p['products'][0].update(note=['invalid'])]:
            payload=self.export(wc_queue='missing_photo',wc_q='one');mutate(payload);self.assertEqual(self.call('workflow_preview',data=json.dumps(payload))[0],400)
        payload=self.export(wc_queue='missing_photo',wc_q='one')
        self.good('website_catalog_bulk_manage',ids=json.dumps(['one']),operation='technical')
        self.assertEqual(self.call('workflow_preview',data=json.dumps(payload))[0],400)
        self.assertEqual(json.loads(self.case.record()['website_sync'])['review_issue']['type'],'technical')
    def test_preview_detects_concurrent_change_and_replay(self):
        payload=self.export(wc_queue='missing_photo',wc_q='one');payload['products'][0]['image_requirement']='not_required'
        preview=self.preview(payload);self.good('website_catalog_bulk_manage',ids=json.dumps(['manual']),operation='technical')
        self.assertEqual(self.call('workflow_apply',token=preview['token'])[0],400)
        fresh=self.export(wc_queue='missing_photo',wc_q='one');fresh['products'][0]['image_requirement']='not_required'
        preview=self.preview(fresh);self.good('workflow_apply',token=preview['token'])
        self.assertEqual(self.call('workflow_apply',token=preview['token'])[0],400)
    def test_images_scope_and_bulk_scope_enforce_project_and_permissions(self):
        payload=self.good('workflow_export',scope='images',ids=json.dumps(['one','manual']))['payload'];self.assertEqual(len(payload['products']),2)
        with sqlite3.connect(self.case.database) as db:db.execute('INSERT INTO products(id,project_id,name) VALUES ("foreign-transfer","other","Private")')
        for action in ['workflow_export','website_catalog_bulk_manage']:
            self.assertEqual(self.call(action,scope='images',ids=json.dumps(['one','foreign-transfer']),operation='technical')[0],400)
        self.assertNotIn('review_issue',json.loads(self.case.record()['website_sync']))
        viewer,token=self.case.login('viewer')
        for action in ['workflow_export','workflow_preview','workflow_apply','website_catalog_bulk_manage']:
            code,_=self.case.request(viewer,'/panel/admin.php?tab=website_export',dict(ajax_action=action,csrf=self.csrf),{'X-CSRF-Token':token});self.assertEqual(code,403)
            code,_=self.case.request(self.opener,'/panel/admin.php?tab=website_export',dict(ajax_action=action),{'X-CSRF-Token':self.token});self.assertEqual(code,403)
    def test_content_requests_require_assigned_role(self):
        payload=self.export(wc_queue='missing_photo',wc_q='one');payload['products'][0]['content_request']='request'
        self.assertEqual(self.call('workflow_preview',data=json.dumps(payload))[0],400)
        self.opener,self.token=self.case.login('fixture_owner');_,page=self.case.request(self.opener,'/panel/admin.php?tab=website_export');self.csrf=re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',page)[1]
        payload=self.export(wc_queue='missing_photo',wc_q='one');payload['products'][0]['content_request']='request'
        self.good('workflow_apply',token=self.preview(payload)['token']);self.assertTrue(self.case.record()['content_gen_requested'])

    def test_chunked_upload_previews_a_workflow_file(self):
        import base64
        payload=self.export(wc_queue='missing_photo',wc_q='one');payload['products'][0]['chart_requirement']='not_required'
        data=json.dumps(payload,ensure_ascii=False).encode()
        upload=self.good('website_catalog_upload_begin',bytes=str(len(data)))
        self.good('website_catalog_upload_chunk',upload_token=upload['upload_token'],index='0',chunk=base64.b64encode(data).decode())
        preview=self.good('workflow_preview',upload_token=upload['upload_token']);self.assertEqual(preview['updated'],1)
        self.assertNotIn('content_preferences',json.loads(self.case.record()['website_sync']))
        self.good('workflow_apply',token=preview['token'])
        self.assertTrue(json.loads(self.case.record()['website_sync'])['content_preferences']['chart_not_required'])

    def test_bulk_all_filtered_selection_over_one_hundred(self):
        ids=[]
        with sqlite3.connect(self.case.database) as db:
            for i in range(105):
                pid='bulk-transfer-'+str(i);ids.append(pid);db.execute('INSERT INTO products(id,project_id,name,stock_total) VALUES (?,?,?,?)',(pid,'p','Batch','8'))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        result=self.good('website_catalog_bulk_manage',ids=json.dumps(ids),operation='image_not_required');self.assertEqual(result['updated'],105)
        self.assertEqual(len(self.export(wc_queue='image_not_required',wc_q='Batch')['products']),105)
        self.assertNotIn('content_preferences',json.loads(self.case.record()['website_sync']))

    def test_persian_workflow_values_are_understood(self):
        payload=self.export(wc_queue='missing_photo',wc_q='one');payload['products'][0].update(image_requirement='عکس نیاز ندارد',chart_requirement='نیاز ندارد',issue='باگ فنی',content_request='بدون تغییر')
        preview=self.preview(payload);self.assertIn('عکس نیاز ندارد',preview['changes'][0]['fields'])
        self.good('workflow_apply',token=preview['token']);meta=json.loads(self.case.record()['website_sync'])
        self.assertTrue(meta['content_preferences']['image_not_required']);self.assertTrue(meta['content_preferences']['chart_not_required']);self.assertEqual(meta['review_issue']['type'],'technical')
