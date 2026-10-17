"""Operation-level ACL regressions against a temporary PHP/SQLite application."""
import json
import sqlite3
import subprocess
import unittest
import uuid
import test_http_security as fixtures


class AdminActionAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('ActionFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=?,role="manager" WHERE project_id="p" AND username="limited"', (json.dumps(['products']),))
            db.execute('DELETE FROM projects WHERE id="other"')
            db.execute('DELETE FROM project_users WHERE project_id="other" OR username="shared-fixture"')
        policy = self.case.app/'panel/db/access_policy.json'
        if policy.exists():
            policy.unlink()
        self.clear_cache()

    def clear_cache(self):
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):
            path.unlink()

    def configure(self, permissions, modules):
        settings = dict(self.case.settings, active_modules=modules)
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE project_id="p" AND username="limited"', (json.dumps(permissions),))
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(settings),))
        self.clear_cache()

    def settings(self):
        with sqlite3.connect(self.case.database) as db:
            return json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])

    def post(self, opener, token, fields, path='/panel/admin.php?tab=products'):
        return self.case.request(opener, path, fields, {'X-CSRF-Token': token})

    def test_an_allowed_tab_does_not_authorize_other_modules_actions(self):
        opener, token = self.case.login('limited')
        for action in ['landing_save', 'reset_calendar', 'tiam_generate_scripts']:
            with self.subTest(action=action):
                code, _ = self.post(opener, token, {'ajax_action': action, 'landings_json': '[{"id":"denied"}]'})
                self.assertEqual(code, 403)
        self.assertNotIn('landing_pages', self.settings())
        self.assertEqual(self.post(opener, token, {'add_feedback': '1', 'feedback_text': 'Denied fixture feedback'})[0], 403)
        self.assertNotIn('feedbackOptions', self.settings())

    def test_authorized_bare_controller_action_does_not_require_dashboard(self):
        opener, token = self.case.login('limited')
        self.configure(['landing_builder'], ['landing_builder'])
        code, body = self.post(opener, token, {'ajax_action': 'landing_save', 'landings_json': '[{"id":"allowed"}]'}, '/panel/admin.php')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        self.assertEqual(self.settings()['landing_pages'], [{'id': 'allowed'}])

    def test_module_and_use_policy_still_restrict_the_action(self):
        opener, token = self.case.login('limited')
        self.configure(['products', 'landing_builder'], ['products'])
        fields = {'ajax_action': 'landing_save', 'landings_json': '[]'}
        self.assertEqual(self.post(opener, token, fields)[0], 403)
        self.configure(['products', 'landing_builder'], ['products', 'landing_builder'])
        (self.case.app/'panel/db/access_policy.json').write_text(json.dumps({'landing_builder': {'view': {'roles': ['manager']}, 'use': {'roles': ['main_admin']}}}))
        self.assertEqual(self.post(opener, token, fields)[0], 403)
        self.assertNotIn('landing_pages', self.settings())

    def test_bare_product_edit_uses_its_product_permission(self):
        opener, token = self.case.login('limited')
        code, _ = self.post(opener, token, {'update_product_data': '1', 'pid': 'one', 'name': 'Scoped product edit', 'price': '10', 'status': 'active'}, '/panel/admin.php')
        self.assertEqual(code, 200)
        self.assertEqual(self.case.record()['name'], 'Scoped product edit')

    def test_legacy_permission_names_grant_their_matching_feature(self):
        self.configure(['size_chart', 'vm', 'analysis'], ['size_studio', 'vm_studio', 'sale_analysis'])
        result = subprocess.run([fixtures.PHP, '-r', 'session_start(); require $argv[1]; $_SESSION["user"]=["username"=>"limited","role"=>"manager"]; $_SESSION["project_id"]="p"; echo json_encode([hasPermission("size_studio"),hasPermission("size_chart"),hasPermission("vm_studio"),hasPermission("sale_analysis")]);', str(self.case.app/'panel/inc/functions.php')], capture_output=True, text=True, check=True, timeout=10)
        self.assertEqual(json.loads(result.stdout), [True, True, True, True])

    def test_standalone_reference_and_library_grants_can_save_reference(self):
        opener, token = self.case.login('limited')
        for permission in ['reference_photo', 'library']:
            with self.subTest(permission=permission):
                self.configure([permission], ['products'])
                code, body = self.post(opener, token, {'ajax_action': 'save_reference_photos_fast', 'pid': 'manual', 'existing_images': '["uploads/original.png"]'}, '/panel/admin.php')
                self.assertEqual(code, 200)
                self.assertEqual(json.loads(body)['status'], 'success')
        self.configure(['reference_photo'], [])
        self.assertEqual(self.post(opener, token, {'ajax_action': 'save_reference_photos_fast', 'pid': 'manual'}, '/panel/admin.php')[0], 403)

    def test_work_queue_actions_remain_available_to_the_assigned_operator(self):
        opener, token = self.case.login('operator')
        boundary = '----reference-fixture-'+uuid.uuid4().hex
        body = bytearray()
        for key, value in {'ajax_action': 'save_reference_photos_fast', 'pid': 'manual'}.items():
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="reference_files[]"; filename="reference.png"\r\nContent-Type: image/png\r\n\r\n'.encode())
        body.extend(fixtures.PNG)
        body.extend(f'\r\n--{boundary}--\r\n'.encode())
        code, response = self.case.request(opener, '/panel/admin.php?tab=extract', headers={'X-CSRF-Token': token, 'Content-Type': 'multipart/form-data; boundary='+boundary}, raw=bytes(body))
        self.assertEqual(code, 200)
        uploaded = json.loads(response)
        self.assertEqual(uploaded['status'], 'success')
        self.assertEqual(len(uploaded['reference_gallery']), 1)
        self.assertTrue((self.case.app/'panel'/uploaded['reference_gallery'][0]).is_file())
        for tab in ['extract', 'website_export']:
            with self.subTest(tab=tab):
                code, body = self.post(opener, token, {'ajax_action': 'save_reference_photos_fast', 'pid': 'manual', 'existing_images': '["uploads/original.png"]'}, '/panel/admin.php?tab='+tab)
                self.assertEqual(code, 200)
                self.assertEqual(json.loads(body)['status'], 'success')
                self.assertEqual(json.loads(self.case.record('manual')['reference_gallery']), ['uploads/original.png'])
                code, body = self.post(opener, token, {'ajax_action': 'toggle_size_chart_uploaded', 'pid': 'manual', 'is_uploaded': '1'}, '/panel/admin.php?tab='+tab)
                self.assertEqual(code, 200)
                self.assertEqual(json.loads(body)['status'], 'success')
                self.assertTrue(self.case.record('manual')['size_chart_uploaded'])

    def test_complete_backup_restore_cannot_replace_users_or_access_settings(self):
        opener, token = self.case.login('limited')
        self.configure(['products', 'extract'], ['products', 'extract'])
        payload = {'project_id': 'p', 'products': [], 'users': [{'username': 'limited', 'password': 'ignored', 'role': 'owner', 'permissions': []}], 'settings': {'prod_require_reference': False}}
        code, _ = self.post(opener, token, {'ajax_action': 'import_json', 'data': json.dumps(payload)}, '/panel/admin.php')
        self.assertEqual(code, 403)
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT role FROM project_users WHERE project_id="p" AND username="limited"').fetchone()[0], 'manager')
        self.assertNotIn('prod_require_reference', self.settings())
        self.assertIsNotNone(self.case.record())

    def test_owner_can_still_restore_complete_backup(self):
        opener, token = self.case.login()
        payload = {'project_id': 'p', 'products': [], 'settings': {'fixture_restore': True}}
        code, body = self.post(opener, token, {'ajax_action': 'import_json', 'data': json.dumps(payload)}, '/panel/admin.php')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        self.assertTrue(self.settings()['fixture_restore'])

    def test_adding_project_user_preserves_memberships_in_other_projects(self):
        opener, token = self.case.login('limited')
        self.configure(['products', 'users'], ['products', 'users'])
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('other', 'Other fixture project', '2026-01-01', '2030-01-01', '{}'))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('other', 'shared-fixture', 'Other-secret', 'viewer', '["products"]'))
        fields = {'add_user': '1', 'username': 'shared-fixture', 'password': 'Fixture-new-password', 'role': 'viewer', 'assigned_projects[]': 'p', 'perms[]': 'products'}
        code, _ = self.post(opener, token, fields)
        self.assertEqual(code, 200)
        with sqlite3.connect(self.case.database) as db:
            other = db.execute('SELECT password,role FROM project_users WHERE project_id="other" AND username="shared-fixture"').fetchone()
            self.assertEqual(other, ('Other-secret', 'viewer'))
            self.assertIsNotNone(db.execute('SELECT username FROM project_users WHERE project_id="p" AND username="shared-fixture"').fetchone())


if __name__ == '__main__':
    unittest.main()
