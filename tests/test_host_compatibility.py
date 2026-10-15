"""Reproduce the host log: native list helper absent, zlib compression enabled."""
import gzip
import urllib.request
import unittest
import test_http_security as fixtures


class HostCompatibility(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('ConfiguredFixture', (fixtures.HttpSecurity,), {
            'PHP_SETTINGS': ('-d', 'disable_functions=array_is_list', '-d', 'zlib.output_compression=1'),
        })
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()

    def test_admin_and_portal_render_without_native_list_helper(self):
        opener, _ = self.case.login()
        for path, marker in [('/panel/admin.php?tab=products', 'admin-content'), ('/panel/portal.php', 'class="portal-header"'), ('/panel/portal.php?tab=library', 'id="tab-library"')]:
            with self.subTest(path=path):
                code, page = self.case.request(opener, path, headers={'Accept-Encoding': 'gzip'})
                self.assertEqual(code, 200)
                self.assertIn(marker, page)
                self.assertIn('name="app-csrf"', page)
                self.assertNotIn('کد پیگیری', page)
        self.case.log.seek(0)
        logs = self.case.log.read().decode(errors='replace')
        self.assertNotIn("conflicts with 'zlib output compression'", logs)
        self.assertNotIn('failed to create buffer', logs)
        self.assertNotIn('undefined function array_is_list', logs)

    def test_host_zlib_response_is_decodable_once(self):
        opener, _ = self.case.login()
        response = opener.open(urllib.request.Request(self.case.base+'/panel/portal.php', headers={'Accept-Encoding': 'gzip'}), timeout=10)
        body = response.read()
        self.assertEqual(response.headers.get('Content-Encoding'), 'gzip')
        html = gzip.decompress(body).decode()
        self.assertIn('class="portal-header"', html)
        self.assertTrue(html.rstrip().endswith('</html>'))

    def test_csrf_remains_enforced_with_host_compression(self):
        opener, token = self.case.login('limited')
        self.assertEqual(self.case.edit(opener)[0], 403)
        self.assertEqual(self.case.edit(opener, token)[0], 200)
        self.assertEqual(self.case.record()['name'], 'ویرایش محلی')


if __name__ == '__main__':
    unittest.main()
