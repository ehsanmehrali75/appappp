"""Ten-MiB website JSON is staged below a one-MiB host POST limit before preview/apply."""
import base64
import json
import os
import re
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
import test_http_security as fixtures


class WebsiteUpload(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.state=tempfile.TemporaryDirectory(prefix='website-upload-')
        cls.fixture=type('WebsiteUploadFixture',(fixtures.HttpSecurity,),{'PHP_SETTINGS':('-d','post_max_size=1M')})
        with patch.dict(os.environ,{'MAARKSHO_WEBSITE_UPLOAD_DIR':cls.state.name}):cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass();cls.state.cleanup()

    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp()
        self.opener,self.csrf=self.case.login()
        self.site_csrf=self.site_token(self.opener)

    def site_token(self,opener):
        code,body=self.case.request(opener,'/panel/admin.php?tab=extract')
        self.assertEqual(code,200)
        return re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"',body)[1]

    def call(self,action,**fields):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action=action,csrf=self.site_csrf,**fields),{'X-CSRF-Token':self.csrf})
        self.assertEqual(code,200,body[:500])
        result=json.loads(body);self.assertNotEqual(result.get('status'),'failed',body[:500])
        return result

    def test_exact_ten_mib_file_matches_model_and_imports_webp_through_preview_apply(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET name="Aero   Pro W",image="",images_list="[]",gallery="[]",status="no_image" WHERE id="one"')
        row=dict(url='https://shop.example/product/74634/aero',product_code='74634',name='نیم تنه ورزشی زنانه اسپورتلند Aero Pro W',main_image='https://shop.example/main.webp',gallery=['https://shop.example/main.webp','https://shop.example/gallery.webp'],description='')
        raw=json.dumps([row],ensure_ascii=False,separators=(',',':')).encode()
        row['description']='X'*(10*1024*1024-len(raw))
        raw=json.dumps([row],ensure_ascii=False,separators=(',',':')).encode()
        self.assertEqual(len(raw),10*1024*1024)
        begin=self.call('website_catalog_upload_begin',bytes=len(raw));token=begin['upload_token'];size=begin['chunk_bytes']
        for index,start in enumerate(range(0,len(raw),size)):
            self.call('website_catalog_upload_chunk',upload_token=token,index=index,chunk=base64.b64encode(raw[start:start+size]).decode())
        self.assertEqual(self.case.record()['image'],'','Staging cannot mutate products')
        preview=self.call('website_catalog_preview',upload_token=token,update_stock=0,update_price=0)
        self.assertEqual(preview['counts']['matched_name'],1)
        self.assertEqual(self.case.record()['image'],'','Preview cannot mutate products')
        self.call('website_catalog_apply',token=preview['token'])
        product=self.case.record()
        self.assertEqual(product['name'],'Aero   Pro W')
        self.assertEqual(product['image'],row['main_image'])
        self.assertEqual(product['stock_total'],'10')
        self.assertEqual(len(product['description']),len(row['description']))
        self.assertTrue(all(image['approved'] and image['source']=='site' for image in json.loads(product['images_list'])))

    def test_bounds_incomplete_and_repeated_chunks(self):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_upload_begin',csrf=self.site_csrf,bytes=10*1024*1024+1),{'X-CSRF-Token':self.csrf})
        self.assertEqual(json.loads(body)['status'],'failed')
        row=dict(url='https://shop.example/product/one/example',product_code='one',description='')
        raw=json.dumps([row],separators=(',',':')).encode()
        prefix=raw.index(b'"description":"')+len(b'"description":"')
        row['description']=' '*(256*1024-prefix-1)+'آ'
        raw=json.dumps([row],ensure_ascii=False,separators=(',',':')).encode()
        self.assertEqual(raw[256*1024-1:256*1024+1],'آ'.encode())
        begin=self.call('website_catalog_upload_begin',bytes=len(raw));token=begin['upload_token'];size=begin['chunk_bytes']
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_preview',csrf=self.site_csrf,upload_token=token),{'X-CSRF-Token':self.csrf})
        self.assertIn('ارسال فایل هنوز کامل نشده',json.loads(body)['msg'])
        encoded=base64.b64encode(raw[:size]).decode()
        for _ in range(2):self.assertEqual(self.call('website_catalog_upload_chunk',upload_token=token,index=0,chunk=encoded)['uploaded'],1)
        self.assertEqual(self.call('website_catalog_upload_chunk',upload_token=token,index=1,chunk=base64.b64encode(raw[size:]).decode())['uploaded'],2)
        self.assertEqual(self.call('website_catalog_preview',upload_token=token)['counts']['matched'],1)

    def test_staging_keeps_csrf_permissions_and_session_ownership(self):
        token=self.call('website_catalog_upload_begin',bytes=2)['upload_token']
        code,_=self.case.request(self.opener,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_upload_begin',bytes=2))
        self.assertEqual(code,403)
        other,csrf=self.case.login('viewer')
        code,_=self.case.request(other,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_upload_begin',bytes=2,csrf=self.site_csrf),{'X-CSRF-Token':csrf})
        self.assertEqual(code,403)
        other,csrf=self.case.login()
        other_site=self.site_token(other)
        code,body=self.case.request(other,'/panel/admin.php?tab=extract',dict(ajax_action='website_catalog_upload_chunk',upload_token=token,index=0,chunk='W10=',csrf=other_site),{'X-CSRF-Token':csrf})
        self.assertIn('متعلق به این نشست',json.loads(body)['msg'])
