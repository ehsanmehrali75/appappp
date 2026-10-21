"""Real HTTP regressions for supported legacy media metadata; synthetic data only."""
import json
import re
import sqlite3
import subprocess
import unittest
import urllib.parse

import test_http_security as http_fixtures


class PortalLegacyMedia(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        http_fixtures.HttpSecurity.setUpClass()

    @classmethod
    def tearDownClass(cls):
        http_fixtures.HttpSecurity.tearDownClass()

    def setUp(self):
        self.fixture = http_fixtures.HttpSecurity()
        self.fixture.setUp()
        self.opener, _ = self.fixture.login()

    def update_product(self, **fields):
        with sqlite3.connect(self.fixture.database) as db:
            db.execute('UPDATE products SET '+','.join(name+'=?' for name in fields)+' WHERE id="one"', tuple(fields.values()))
        for path in (self.fixture.app/'panel/db/cache').glob('*.cache'):
            path.unlink()

    def assert_portal_renders(self, expected_sources=()):
        for tab in ['overview', 'library']:
            with self.subTest(tab=tab):
                code, page = self.fixture.request(self.opener, '/panel/portal.php?tab='+tab)
                self.assertEqual(code, 200)
                self.assertTrue('</html>' in page, 'Portal response is incomplete')
                self.assertFalse('خطای موقت سامانه' in page, 'Portal returned a temporary failure')
                sources = [urllib.parse.urljoin(self.fixture.base, src) for src in re.findall(r'<img\b[^>]*\bsrc="([^"]*)"', page)]
                for source in expected_sources:
                    self.assertIn(self.fixture.base+source, sources)

    def test_object_gallery_and_references_render_without_changing_originals(self):
        gallery = [{'url': 'uploads/gallery-original.png', 'approved': True}, {'src': 'uploads/gallery-second.png'}]
        references = [{'image': 'uploads/reference-original.png'}, {'url': 'uploads/reference-second.png'}]
        for name in ['gallery-original.png', 'gallery-second.png', 'reference-original.png', 'reference-second.png']:
            (self.fixture.app/'panel/uploads'/name).write_bytes(http_fixtures.PNG)
        self.update_product(gallery=json.dumps(gallery), reference_gallery=json.dumps(references), uploaded_by='fixture_owner')
        before = self.fixture.record()
        self.assert_portal_renders(['/panel/uploads/'+name for name in ['gallery-original.png', 'gallery-second.png', 'reference-original.png', 'reference-second.png']])
        self.assertEqual(self.fixture.record(), before)
        for name in ['original.png', 'gallery-original.png', 'gallery-second.png', 'reference-original.png', 'reference-second.png']:
            self.assertEqual((self.fixture.app/'panel/uploads'/name).read_bytes(), http_fixtures.PNG)

    def test_pending_gallery_shows_both_approved_and_unapproved_images(self):
        gallery = [{'url': 'uploads/approved.png'}, {'src': 'uploads/unapproved.png'}]
        approvals = [{'url': 'uploads/approved.png', 'approved': True}, {'url': 'uploads/unapproved.png', 'approved': False}]
        self.update_product(gallery=json.dumps(gallery), images_list=json.dumps(approvals), status='pending')
        self.assert_portal_renders(['/panel/uploads/unapproved.png'])
        code, page = self.fixture.request(self.opener, '/panel/portal.php?tab=library')
        self.assertEqual(code, 200)
        sources = [urllib.parse.urljoin(self.fixture.base, src) for src in re.findall(r'<img\b[^>]*\bsrc="([^"]*)"', page)]
        self.assertIn(self.fixture.base+'/panel/uploads/approved.png', sources)

    def test_invalid_gallery_entries_are_ignored(self):
        invalid = [True, 42, {'unexpected': 'uploads/hidden.png'}, {'url': ['uploads/hidden.png']}, 'javascript:alert(1)', '../private.png']
        self.update_product(gallery=json.dumps(invalid), reference_gallery=json.dumps(invalid))
        self.assert_portal_renders(['/panel/uploads/original.png'])

    def test_serialized_chart_urls_are_safe_and_keep_site_source_evidence(self):
        meta = {'size_guide_urls': json.dumps(['https://shop.example/chart.jpg'])}
        self.update_product(size_chart='https://shop.example/chart.jpg', website_sync=json.dumps(meta))
        self.assert_portal_renders()
        script = '''require $argv[1];
$project=['id'=>'p','settings'=>[]];
$product=['id'=>'one','size_chart'=>'https://shop.example/chart.jpg','website_sync'=>['size_guide_urls'=>'["https://shop.example/chart.jpg"]']];
$native=ProductSizeChartCatalog::forProduct($project,$product);
$product['website_sync']=json_encode($product['website_sync']);
$serialized=ProductSizeChartCatalog::forProduct($project,$product);
$invalid=[];
foreach([true,42,'broken-json','{"url":"https://shop.example/chart.jpg"}',[['url'=>'https://shop.example/chart.jpg']]] as $urls) {
    $product['website_sync']=['size_guide_urls'=>$urls];
    $invalid[]=ProductSizeChartCatalog::forProduct($project,$product);
}
echo json_encode(['valid'=>[$native,$serialized],'invalid'=>$invalid]);'''
        result = subprocess.run([http_fixtures.PHP, '-r', script, str(self.fixture.app/'panel/inc/ProductSizeChartCatalog.php')], check=True, capture_output=True, text=True)
        charts = json.loads(result.stdout)
        for chart in charts['valid']:
            self.assertEqual(chart['source'], 'site')
            self.assertFalse(chart['has_local'])
        for chart in charts['invalid']:
            self.assertEqual(chart['source'], 'product')
            self.assertTrue(chart['has_local'])


if __name__ == '__main__':
    unittest.main()
