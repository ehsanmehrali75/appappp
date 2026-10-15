"""Isolated real-PHP HTTP regressions. Never connects to a deployment database."""
import base64
import contextlib
import gzip
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', shutil.which('php') or 'php')
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=')


class HttpSecurity(unittest.TestCase):
    PHP_SETTINGS = ()
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='maarksho-http-security-')
        cls.app = Path(cls.temp.name) / 'site'
        shutil.copytree(ROOT, cls.app, ignore=shutil.ignore_patterns('.git', '.env', 'db', 'uploads', 'tests', 'app', 'node_modules', '__pycache__'))
        private = cls.app / 'panel/db'
        private.mkdir(parents=True)
        (cls.app / 'panel/uploads').mkdir()
        (cls.app / 'panel/uploads/original.png').write_bytes(PNG)
        cls.database = private / 'test.sqlite'
        (private / 'database.local.php').write_text("<?php return ['driver'=>'sqlite','sqlite_path'=>__DIR__.'/test.sqlite','allow_fallback'=>false];")
        subprocess.run([PHP, '-r', 'require $argv[1]; SystemAdmins::create("fixture_owner","Fixture-password-123!",true);', str(cls.app / 'panel/inc/SystemAdmins.php')], check=True, capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        cls.log = tempfile.TemporaryFile()
        env = dict(os.environ, MAARKSHO_AUTH_STATE_DIR=str(Path(cls.temp.name) / 'auth'))
        cls.server = subprocess.Popen([PHP, *cls.PHP_SETTINGS, '-S', f'127.0.0.1:{port}', 'router.php'], cwd=cls.app, env=env, stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                urllib.request.urlopen(cls.base+'/', timeout=1).read()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.05)
        else:
            raise RuntimeError('Isolated PHP server did not start')
        db = sqlite3.connect(cls.database)
        settings = {'active_modules': ['products', 'extract', 'website_export', 'calendar', 'size_studio', 'prompt_hub'], 'tiam_inventory': {'password': 'synthetic-sql-secret'}}
        cls.settings = settings
        db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('p', 'پروژه آزمایشی', '2026-01-01', '2030-01-01', json.dumps(settings)))
        for name, role, permissions in [('limited', 'manager', ['products']), ('editor', 'editor', ['products']), ('viewer', 'viewer', ['products']), ('operator', 'manager', ['products', 'extract', 'website_export', 'reference_photo'])]:
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('p', name, 'Fixture-password-123!', role, json.dumps(permissions)))
        db.commit()
        db.close()

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=10)
        cls.log.close()
        cls.temp.cleanup()

    def setUp(self):
        db = sqlite3.connect(self.database)
        db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(self.settings),))
        db.execute('DELETE FROM products WHERE project_id="p"')
        for pid, stock, status, source, approved in [('one', '10', 'active', 'manual', True), ('manual', '15', 'pending', 'manual', False), ('site', '20', 'pending', 'site', False), ('zero', '0', 'active', 'manual', True), ('unknown', '—', 'active', 'manual', True)]:
            db.execute('INSERT INTO products(id,project_id,sku,name,image,images_list,gallery,stock_total,status,description,website_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?)', (pid, 'p', pid, 'محصول '+pid, 'uploads/original.png', json.dumps([{'url': 'uploads/original.png', 'source': source, 'approved': approved}]), '[]', stock, status, '', '{}'))
        db.commit()
        db.close()
        for p in (self.app/'panel/db/cache').glob('*.cache'):
            p.unlink()

    def request(self, opener, path, fields=None, headers=None, raw=None):
        data = raw if raw is not None else (urllib.parse.urlencode(fields).encode() if fields is not None else None)
        req = urllib.request.Request(self.base+path, data=data, headers=headers or {})
        try:
            response = opener.open(req, timeout=10)
        except urllib.error.HTTPError as exc:
            response = exc
        content=response.read()
        if response.headers.get('Content-Encoding')=='gzip':content=gzip.decompress(content)
        return response.code, content.decode('utf-8', errors='replace')

    def login(self, username='fixture_owner'):
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.request(opener, '/', {'login': '1', 'user': username, 'pass': 'Fixture-password-123!'})
        if username == 'fixture_owner':
            self.request(opener, '/panel/admin.php?switch_project=p')
        code, page = self.request(opener, '/panel/admin.php?tab=products' if username != 'viewer' else '/panel/portal.php?tab=library')
        self.assertEqual(code, 200)
        token = re.search(r'<meta name="app-csrf" content="([a-f0-9]+)">', page)
        self.assertIsNotNone(token)
        return opener, token[1]

    def record(self, pid='one'):
        with sqlite3.connect(self.database) as db:
            db.row_factory = sqlite3.Row
            return dict(db.execute('SELECT * FROM products WHERE id=?', (pid,)).fetchone())

    def edit(self, opener, token=None, **extra):
        fields = {'update_product_data': '1', 'pid': 'one', 'name': 'ویرایش محلی', 'price': '10', 'status': 'active'}
        fields.update(extra)
        return self.request(opener, '/panel/admin.php?tab=products', fields, {'X-CSRF-Token': token} if token else {})

    def test_diagnostic_and_private_files_are_unavailable(self):
        opener = urllib.request.build_opener()
        for path in ['/debug_db.php', '/panel/debug_db.php', '/emergency_reset.php', '/panel/emergency_reset.php', '/.env', '/panel/db/test.sqlite', '/inc/db.php']:
            with self.subTest(path=path):
                code, _ = self.request(opener, path)
                self.assertIn(code, [403, 404])
        self.assertEqual(self.request(opener, '/panel/get_debug_logs.php')[0], 403)

    def test_limited_manager_cannot_download_sensitive_backup(self):
        opener, _ = self.login('limited')
        code, body = self.request(opener, '/panel/admin.php?tab=products&download_full_backup=1')
        self.assertEqual(code, 403)
        self.assertNotIn('synthetic-sql-secret', body)

    def test_owner_can_download_private_backup(self):
        opener, _ = self.login()
        code, body = self.request(opener, '/panel/admin.php?tab=products&download_full_backup=1')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['project_id'], 'p')

    def test_edit_requires_csrf_and_valid_edit_persists(self):
        opener, token = self.login('limited')
        self.assertEqual(self.edit(opener)[0], 403)
        self.assertNotEqual(self.record()['name'], 'ویرایش محلی')
        self.assertEqual(self.edit(opener, token)[0], 200)
        self.assertEqual(self.record()['name'], 'ویرایش محلی')

    def test_cross_origin_is_rejected_even_with_a_token(self):
        opener, token = self.login('limited')
        code, _ = self.request(opener, '/panel/admin.php?tab=products', {'update_product_data': '1', 'pid': 'one'}, {'X-CSRF-Token': token, 'Origin': 'https://unrelated.example'})
        self.assertEqual(code, 403)

    def upload(self, opener, token, name, content):
        boundary = '----fixture-'+uuid.uuid4().hex
        body = bytearray()
        for key, value in {'update_product_data': '1', 'pid': 'one', 'name': 'تصویر جدید', 'price': '10', 'status': 'active'}.items():
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="image_file"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode())
        body.extend(content)
        body.extend(f'\r\n--{boundary}--\r\n'.encode())
        return self.request(opener, '/panel/admin.php?tab=products', headers={'X-CSRF-Token': token, 'Content-Type': 'multipart/form-data; boundary='+boundary}, raw=bytes(body))

    def test_upload_rejects_script_and_disguised_image_before_changes(self):
        opener, token = self.login()
        for name in ['bad.php', 'bad.png']:
            with self.subTest(name=name):
                self.assertEqual(self.upload(opener, token, name, b'<?php /* inert fixture; never executed */ ?>')[0], 403)
                self.assertEqual(self.record()['image'], 'uploads/original.png')
                self.assertEqual(self.record()['name'], 'محصول one')

    def test_manual_image_original_is_preserved_and_requires_approval(self):
        opener, token = self.login()
        self.assertEqual(self.upload(opener, token, 'valid.png', PNG)[0], 200)
        row = self.record()
        self.assertEqual(row['status'], 'pending')
        self.assertEqual((self.app/'panel'/row['image']).read_bytes(), PNG)
        self.assertEqual((self.app/'panel/uploads/original.png').read_bytes(), PNG)
        self.assertFalse(next(x for x in json.loads(row['images_list']) if x['url']==row['image'])['approved'])

    def test_portal_queues_share_approval_and_archive_semantics(self):
        opener, _ = self.login()
        _, page = self.request(opener, '/panel/portal.php?tab=library&library_status=pending_approval')
        cards = re.findall(r'data-product-id="([^"]+)" data-workflow="([^"]+)"', page)
        self.assertIn(('manual', 'pending_approval'), cards)
        self.assertNotIn(('site', 'pending_approval'), cards)
        _, page = self.request(opener, '/panel/portal.php?tab=library&library_status=all')
        self.assertIn('data-product-id="zero" data-workflow="out_of_stock"', page)
        self.assertIn('data-product-id="unknown" data-workflow="active"', page)

    def test_limited_user_cannot_approve_or_reject(self):
        opener, token = self.login('viewer')
        for action in ['approve', 'reject']:
            code, _ = self.request(opener, '/panel/portal.php', {'action': action, 'product_id': 'manual'}, {'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest'})
            self.assertEqual(code, 403)
        self.assertEqual(self.record('manual')['status'], 'pending')

    def test_owner_rejection_preserves_product_and_original(self):
        opener, token = self.login()
        code, body = self.request(opener, '/panel/portal.php', {'action': 'reject', 'product_id': 'manual'}, {'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest'})
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        self.assertEqual(self.record('manual')['status'], 'rejected')
        self.assertEqual((self.app/'panel/uploads/original.png').read_bytes(), PNG)

    def test_owner_approval_is_explicit_and_persists_image_evidence(self):
        opener, token = self.login()
        code, body = self.request(opener, '/panel/portal.php', {'action': 'approve', 'product_id': 'manual'}, {'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest'})
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        row = self.record('manual')
        self.assertEqual(row['status'], 'active')
        self.assertTrue(json.loads(row['images_list'])[0]['approved'])

    def test_missing_mysql_never_silently_opens_sqlite(self):
        local = self.app/'panel/db/database.local.php'
        old = local.read_text()
        fallback = self.app/'panel/db/unexpected-fallback.sqlite'
        local.write_text("<?php return ['driver'=>'mysql','host'=>'127.0.0.1','user'=>'invalid_fixture','pass'=>'invalid_fixture','dbname'=>'invalid_fixture','sqlite_path'=>__DIR__.'/unexpected-fallback.sqlite','allow_fallback'=>false];")
        try:
            result = subprocess.run([PHP, '-r', 'require $argv[1]; try {getConn(); echo "UNEXPECTED_CONNECTION";} catch (RuntimeException $e) {echo "EXPECTED_FAILURE";}', str(self.app/'panel/inc/db.php')], capture_output=True, text=True, timeout=10)
            self.assertIn('EXPECTED_FAILURE', result.stdout)
            self.assertNotIn('UNEXPECTED_CONNECTION', result.stdout)
            self.assertFalse(fallback.exists())
        finally:
            local.write_text(old)

    def production_fixture(self, request_required=True, reference_required=True):
        settings = dict(self.settings, prod_require_image_request=request_required, prod_require_reference=reference_required, prod_content_gen_users='requester', prod_ref_image_users='operator')
        with sqlite3.connect(self.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(settings),))
            db.execute('UPDATE products SET image="",images_list="[]",gallery="[]",status="no_image",reference_gallery="[]",content_gen_requested=0 WHERE id IN ("one","manual","zero")')
            db.execute('UPDATE products SET content_gen_requested=1 WHERE id="manual"')
        for path in (self.app/'panel/db/cache').glob('*.cache'):
            path.unlink()

    def queue(self, opener, queue, tab='extract'):
        code, page = self.request(opener, '/panel/admin.php?'+urllib.parse.urlencode({'tab': tab, 'we_view': 'work', 'wc_queue': queue}))
        self.assertEqual(code, 200)
        return re.findall(r'<article[^>]*data-product-id="([^"]+)"', page), page

    def seed_large_catalog(self):
        with sqlite3.connect(self.database) as db:
            for number in range(900):
                pid=f'catalog-{number:04d}'
                db.execute('INSERT INTO products(id,project_id,sku,name,image,images_list,gallery,stock_total,status,description,website_sync) VALUES (?,?,?,?,?,?,?,?,?,?,?)', (pid, 'p', pid, 'کالای بزرگ '+str(number), 'uploads/original.png', '[{"url":"uploads/original.png","source":"site","approved":true}]', '[]', '5', 'active', 'catalog description '*80, '{}'))
        for path in (self.app/'panel/db/cache').glob('*.cache'):
            path.unlink()

    def test_large_portal_catalog_renders_complete_gzip_response(self):
        # Inline product JSON exceeded the old whole-document regex limit.
        self.seed_large_catalog()
        opener, _ = self.login()
        for tab in [None, 'library']:
            with self.subTest(tab=tab):
                code, page = self.request(opener, '/panel/portal.php'+('?tab='+tab if tab else ''), headers={'Accept-Encoding': 'gzip'})
                self.assertEqual(code, 200)
                self.assertGreater(len(page), 2_000_000)
                self.assertIn('class="portal-header"', page)
                self.assertIn('id="portal-main-container"', page)
                self.assertIn('name="app-csrf"', page)
                self.assertIn('catalog-0899', page)
                self.assertIn('</html>', page)

    def test_pretty_admin_route_enforces_security(self):
        opener, token = self.login('limited')
        code, page = self.request(opener, '/admin?tab=products')
        self.assertEqual(code, 200)
        self.assertIn('name="app-csrf"', page)
        fields = {'update_product_data': '1', 'pid': 'one', 'name': 'route-edit', 'price': '1', 'status': 'active'}
        self.assertEqual(self.request(opener, '/admin?tab=products', fields)[0], 403)
        self.assertEqual(self.request(opener, '/admin?tab=products', fields, {'X-CSRF-Token': token})[0], 200)
        self.assertEqual(self.record()['name'], 'route-edit')

    def test_production_request_advances_queue_and_records_actor(self):
        self.production_fixture()
        opener, token = self.login()
        ids, page = self.queue(opener, 'image_request')
        self.assertEqual(ids, ['one'])
        csrf = re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"', page)[1]
        self.assertEqual(self.queue(opener, 'needs_reference')[0], ['manual'])
        fields = {'ajax_action': 'website_catalog_request_image', 'csrf': csrf, 'product_id': 'one'}
        code, body = self.request(opener, '/panel/admin.php?tab=extract', fields, {'X-CSRF-Token': token})
        self.assertEqual((code, json.loads(body)['status']), (200, 'success'))
        self.assertTrue(self.record()['content_gen_requested'])
        self.assertEqual(json.loads(self.record()['website_sync'])['image_request']['by'], 'fixture_owner')
        self.assertEqual(self.queue(opener, 'image_request')[0], [])
        self.assertEqual(set(self.queue(opener, 'needs_reference', 'website_export')[0]), {'one', 'manual'})
        self.assertEqual(self.queue(opener, 'pending_image')[0], [])

    def test_reference_operator_cannot_impersonate_requester(self):
        self.production_fixture()
        opener, token = self.login('operator')
        _, page = self.queue(opener, 'image_request')
        csrf = re.search(r'const websiteCatalogCsrf="([a-f0-9]+)"', page)[1]
        fields = {'ajax_action': 'website_catalog_request_image', 'csrf': csrf, 'product_id': 'one'}
        self.assertEqual(self.request(opener, '/panel/admin.php?tab=extract', fields, {'X-CSRF-Token': token})[0], 403)
        self.assertFalse(self.record()['content_gen_requested'])
        code, _ = self.request(opener, '/panel/admin.php?tab=extract', {'ajax_action': 'save_reference_photos_fast', 'pid': 'one', 'existing_images': '[]'}, {'X-CSRF-Token': token})
        self.assertEqual(code, 400)

    def test_reference_completion_and_optional_reference_queues(self):
        self.production_fixture()
        opener, token = self.login('operator')
        code, body = self.request(opener, '/panel/portal.php', {'action': 'upload_product_ref_images', 'product_id': 'manual', 'ref_urls': 'https://assets.example/ref.jpg'}, {'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest'})
        self.assertEqual((code, json.loads(body)['status']), (200, 'success'))
        self.assertIn('manual', self.queue(opener, 'pending_image')[0])
        self.assertNotIn('manual', self.queue(opener, 'needs_reference')[0])
        self.production_fixture(request_required=False, reference_required=False)
        self.assertEqual(self.queue(opener, 'image_request')[0], [])
        self.assertEqual(set(self.queue(opener, 'needs_reference')[0]), {'one', 'manual'})
        self.assertEqual(set(self.queue(opener, 'pending_image')[0]), {'one', 'manual'})
        self.assertNotIn('zero', self.queue(opener, 'needs_reference')[0])

    def test_total_stock_threshold_and_pagination_preserve_filters(self):
        opener, _ = self.login()
        code, page = self.request(opener, '/panel/admin.php?tab=extract&wc_queue=all&wc_stock=gt_10')
        self.assertEqual(code, 200)
        self.assertEqual(set(re.findall(r'<article[^>]*data-product-id="([^"]+)"', page)), {'manual', 'site'})
        with sqlite3.connect(self.database) as db:
            for number in range(15):
                pid = f'bulk-{number:02d}'
                db.execute('INSERT INTO products(id,project_id,sku,name,stock_total,status) VALUES (?,?,?,?,?,?)', (pid, 'p', pid, 'کالای صفحه‌بندی', '25', 'no_image'))
        for path in (self.app/'panel/db/cache').glob('*.cache'):
            path.unlink()
        code, page = self.request(opener, '/panel/admin.php?tab=website_export&we_view=work&wc_queue=pending_image&wc_stock=gt_20&wc_size=10&wc_page=2')
        self.assertEqual(code, 200)
        self.assertEqual(len(re.findall(r'<article[^>]*data-product-id="([^"]+)"', page)), 5)
        self.assertIn('wc_stock=gt_20', page)
        self.assertIn('wc_queue=pending_image', page)
        self.assertIn('wc_size=10', page)

    def test_global_worker_is_not_public_or_available_to_project_manager(self):
        anonymous = urllib.request.build_opener()
        for path in ['/cron.php', '/panel/cron.php']:
            self.assertEqual(self.request(anonymous, path)[0], 405)
            self.assertEqual(self.request(anonymous, path, {'trigger': '1'})[0], 403)
        opener, token = self.login('limited')
        self.assertEqual(self.request(opener, '/panel/cron.php', {'trigger': '1'}, {'X-CSRF-Token': token})[0], 403)
        self.assertEqual(self.request(opener, '/panel/ajax_helper.php?action=clear_debug_log')[0], 403)
        self.assertEqual(self.request(opener, '/panel/ajax_helper.php?action=clear_debug_log', {'clear': '1'}, {'X-CSRF-Token': token})[0], 403)

    def test_editor_with_product_permission_can_enter_management(self):
        opener, _ = self.login('editor')
        code, page = self.request(opener, '/panel/admin.php?tab=products')
        self.assertEqual(code, 200)
        self.assertIn('admin-content', page)


if __name__ == '__main__':
    unittest.main()
