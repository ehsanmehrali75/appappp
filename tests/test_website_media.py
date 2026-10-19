"""Website evidence remains visible independently of content production queues."""
import json
import sqlite3
import unittest
import test_http_security as fixtures


class WebsiteMedia(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=type('WebsiteMediaFixture',(fixtures.HttpSecurity,),{})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):cls.fixture.tearDownClass()

    def setUp(self):
        self.case=self.fixture('runTest');self.case.setUp()
        self.opener,self.token=self.case.login()
        self.page_url='https://www.sportland.ir/product/88273/on-stride-u'
        self.main='https://www.sportland.ir/content/media/product/88273/pro_68419.webp'
        self.gallery='https://www.sportland.ir/content/media/product/88273/pro_68420.webp'
        self.chart='https://www.sportland.ir/content/media/chartsize/Chart_88273.jpg'
        meta=dict(presence='seen',page_urls=[self.page_url],main_image_url=self.main,image_urls=[self.main,self.gallery],has_description=False,has_size_chart=False,publication='uploaded')
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET website_sync=?,uploaded_to_site=1 WHERE id="one"',(json.dumps(meta),))
            db.execute('UPDATE products SET website_sync=?,uploaded_to_site=1 WHERE id="zero"',(json.dumps(meta),))

    def page(self,queue):
        code,body=self.case.request(self.opener,'/panel/admin.php?tab=website_export&we_view=work&wc_queue='+queue)
        self.assertEqual(code,200)
        return body

    def test_site_photo_filter_shows_links_and_keeps_content_tasks(self):
        body=self.page('site_visible')
        self.assertIn('data-product-id="one"',body)
        self.assertNotIn('data-product-id="zero"',body,'Stock-zero products stay outside active filters')
        self.assertIn('در سایت موجود؛ تصویر ثبت شده',body)
        for url in [self.page_url,self.main,self.gallery]:self.assertIn('href="'+url+'"',body)
        self.assertIn('data-product-id="one"',self.page('needs_description'))
        self.assertIn('data-product-id="one"',self.page('needs_size_chart'))

    def test_chart_and_unsafe_metadata_are_rendered_safely(self):
        with sqlite3.connect(self.case.database) as db:
            meta=json.loads(db.execute('SELECT website_sync FROM products WHERE id="one"').fetchone()[0])
            meta.update(has_size_chart=True,size_guide_urls=[self.chart,'javascript:unsafeChart()'],page_urls=[self.page_url,'javascript:unsafePage()'])
            db.execute('UPDATE products SET website_sync=? WHERE id="one"',(json.dumps(meta),))
        body=self.page('site_visible')
        self.assertIn('href="'+self.chart+'"',body)
        self.assertNotIn('href="javascript:unsafe',body)
        self.assertNotIn('data-product-id="one"',self.page('needs_size_chart'))

    def test_site_size_stock_is_distinct_from_erp_and_photo_presence(self):
        with sqlite3.connect(self.case.database) as db:
            meta=json.loads(db.execute('SELECT website_sync FROM products WHERE id="one"').fetchone()[0])
            meta['size_availability_by_page']={self.page_url:[{'size':'40','available':False},{'size':'41','available':True}]}
            db.execute('UPDATE products SET website_sync=? WHERE id="one"',(json.dumps(meta),))
        body=self.page('site_visible')
        self.assertIn('سایزهای مشاهده‌شده در سایت',body)
        self.assertIn('موجود: 41',body)
        self.assertIn('ناموجود: 40',body)
        self.assertIn('href="'+self.main+'"',body)

    def test_unknown_is_distinct_from_confirmed_missing(self):
        body=self.page('site_unknown')
        self.assertIn('data-product-id="manual"',body)
        self.assertNotIn('data-product-id="one"',body)
        self.assertNotIn('data-product-id="manual"',self.page('site_missing'))
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE products SET website_sync=? WHERE id="manual"',(json.dumps(dict(presence='missing')),))
        self.assertIn('data-product-id="manual"',self.page('site_missing'))
