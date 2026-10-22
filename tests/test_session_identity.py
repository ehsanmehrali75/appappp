"""Verified login scopes, role refresh and account revocation over real PHP HTTP."""
import http.cookiejar
import json
import sqlite3
import subprocess
import unittest
import urllib.request

import test_http_security as fixtures


class SessionIdentity(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('IdentityFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()
        cls.owner_file = cls.fixture.app / 'panel/db/system_admins.php'
        cls.owner_source = cls.owner_file.read_text()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()
        self.owner_file.write_text(self.owner_source)
        for name in ['feature_flags.json', 'access_policy.json']:
            (self.case.app / 'panel/db' / name).unlink(missing_ok=True)
        with sqlite3.connect(self.case.database) as db:
            db.execute('DELETE FROM products WHERE project_id="identity-q"')
            db.execute('DELETE FROM project_users WHERE project_id="identity-q" OR username="legacy-fixture"')
            db.execute('DELETE FROM projects WHERE id="identity-q"')
            db.execute('DELETE FROM super_users WHERE username="legacy-fixture"')
            db.execute('UPDATE project_users SET password=?,role="manager",permissions=? WHERE project_id="p" AND username="limited"', ('Fixture-password-123!', '["products"]'))
        self.clear_cache()

    def clear_cache(self):
        for path in (self.case.app / 'panel/db/cache').glob('*.cache'):
            path.unlink()

    def other_project(self, password='Different-project-password!', role='project_admin'):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('identity-q', 'Unrelated private identity project', '2026-01-01', '2030-01-01', json.dumps({'active_modules': ['products', 'settings']})))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('identity-q', 'limited', password, role, '["products","settings"]'))
            db.execute('INSERT INTO products(id,project_id,sku,name,status) VALUES (?,?,?,?,?)', ('identity-secret', 'identity-q', 'identity-secret', 'Unrelated-private-identity-product', 'active'))
        self.clear_cache()

    def assert_revoked(self, opener):
        code, body = self.case.request(opener, '/panel/admin.php?tab=feature_flags')
        self.assertNotEqual(code, 500)
        self.assertNotIn('name="save_feature_flags"', body)
        code, body = self.case.request(opener, '/panel/admin.php?tab=products')
        self.assertNotEqual(code, 500)
        self.assertNotIn('data-product-id="one"', body)
        self.assertNotIn('Unrelated-private-identity-product', body)

    def test_same_username_with_different_password_is_not_project_membership(self):
        self.other_project()
        opener, token = self.case.login('limited')
        for path in ['/panel/admin.php?tab=settings&project=identity-q', '/panel/admin.php?tab=products&switch_project=identity-q', '/panel/portal.php?project=identity-q', '/panel/portal.php?switch_project=identity-q']:
            with self.subTest(path=path):
                code, body = self.case.request(opener, path)
                self.assertEqual(code, 403)
                self.assertNotIn('Unrelated-private-identity-product', body)
        code, body = self.case.request(opener, '/panel/admin.php?tab=products')
        self.assertEqual(code, 200)
        self.assertNotIn('Unrelated private identity project', body)
        self.assertEqual(self.case.edit(opener, token)[0], 200)

    def test_matching_password_proves_both_memberships_even_with_distinct_hashes(self):
        import subprocess
        hashed = subprocess.check_output([fixtures.PHP, '-r', 'echo password_hash("Fixture-password-123!", PASSWORD_DEFAULT);'], text=True)
        self.other_project(password=hashed, role='manager')
        opener, token = self.case.login('limited')
        self.assertEqual(self.case.request(opener, '/panel/admin.php?tab=products&switch_project=identity-q')[0],403)
        code, body = self.case.request(opener, '/panel/workspace.php', {'select_project':'identity-q','app_csrf':token})
        self.assertEqual(code,200)
        self.assertIn('Unrelated-private-identity-product',body)
        self.assertEqual(self.case.request(opener, '/panel/workspace.php', {'select_project':'p','app_csrf':token})[0],200)

    def test_member_password_reset_or_removal_revokes_existing_session(self):
        opener, token = self.case.login('limited')
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET password="new-fixture-password" WHERE project_id="p" AND username="limited"')
        self.clear_cache()
        code, body = self.case.edit(opener, token)
        self.assertNotEqual(code, 500)
        self.assertNotIn('data-product-id="one"', body)
        self.assertNotEqual(self.case.record()['name'], 'ویرایش محلی')
        self.assert_revoked(opener)

    def test_member_removed_from_project_cannot_keep_using_portal(self):
        opener, _ = self.case.login('limited')
        with sqlite3.connect(self.case.database) as db:
            db.execute('DELETE FROM project_users WHERE project_id="p" AND username="limited"')
        self.clear_cache()
        code, body = self.case.request(opener, '/panel/portal.php?tab=library')
        self.assertNotEqual(code, 500)
        self.assertNotIn('data-product-id="one"', body)
        # Restore this shared fixture member for the next case.
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('p', 'limited', 'Fixture-password-123!', 'manager', '["products"]'))

    def test_role_demotion_takes_effect_in_current_portal_session(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role="project_admin" WHERE project_id="p" AND username="limited"')
        opener, token = self.case.login('limited')
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role="viewer" WHERE project_id="p" AND username="limited"')
        self.clear_cache()
        code, _ = self.case.request(opener, '/panel/portal.php', {'action': 'approve', 'product_id': 'manual'}, {'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest'})
        self.assertEqual(code, 403)
        self.assertEqual(self.case.record('manual')['status'], 'pending')

    def test_deleted_global_owner_is_revoked(self):
        opener, _ = self.case.login()
        self.owner_file.write_text('<?php return [];')
        self.assert_revoked(opener)

    def test_owner_role_demotion_refreshes_without_logging_out_valid_account(self):
        opener, _ = self.case.login()
        self.owner_file.write_text(self.owner_source.replace("'role' => 'owner'", "'role' => 'main_admin'"))
        (self.case.app / 'panel/db/feature_flags.json').write_text(json.dumps({'products': 'disabled'}))
        self.assertEqual(self.case.request(opener, '/panel/admin.php?tab=products')[0], 403)
        self.assertEqual(self.case.request(opener, '/panel/admin.php?tab=feature_flags')[0], 200)

    def test_project_stored_global_role_cannot_authenticate_as_global_admin(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role="owner" WHERE project_id="p" AND username="limited"')
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.case.request(opener, '/', {'login': '1', 'user': 'limited', 'pass': 'Fixture-password-123!'})
        self.assert_revoked(opener)

    def test_legacy_global_password_reset_revokes_session(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO super_users(username,password) VALUES (?,?)', ('legacy-fixture', 'Fixture-password-123!'))
        opener, _ = self.case.login('legacy-fixture')
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE super_users SET password="reset-legacy-fixture-password" WHERE username="legacy-fixture"')
        self.assert_revoked(opener)

    def support_ticket(self, actor, subject):
        source = '''require $argv[1]; require $argv[2];
            $s=new SupportTickets(); echo $s->create('__support_account__',$argv[3],$argv[4],'access','Fixture support text');'''
        return subprocess.check_output([fixtures.PHP, '-r', source,
            str(self.case.app/'panel/inc/functions.php'), str(self.case.app/'panel/inc/SupportTickets.php'), actor, subject], text=True)

    def support_actor(self):
        source = '''require $argv[1]; SessionIdentity::beginPassword('limited','Fixture-password-123!','p'); echo SessionIdentity::supportActor('__support_account__');'''
        return subprocess.check_output([fixtures.PHP, '-r', source, str(self.case.app/'panel/inc/functions.php')], text=True)

    def test_generic_support_does_not_merge_unrelated_equal_usernames(self):
        self.other_project()
        opener, _ = self.case.login('limited')
        ticket = self.support_ticket(self.support_actor(), 'First account support sentinel')
        self.assertTrue('First account support sentinel' in self.case.request(opener, '/panel/support.php?project=__support_account__')[1])
        unrelated = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.case.request(unrelated, '/', {'login': '1', 'user': 'limited', 'pass': 'Different-project-password!'})
        code, body = self.case.request(unrelated, '/panel/support.php?project=__support_account__')
        self.assertEqual(code, 200)
        self.assertTrue('First account support sentinel' not in body)
        self.assertEqual(self.case.request(unrelated, '/panel/support.php?project=__support_account__&ticket='+ticket)[0], 403)

    def test_unambiguous_legacy_generic_support_keeps_history(self):
        ticket = self.support_ticket('limited', 'Legacy support sentinel')
        opener, _ = self.case.login('limited')
        code, body = self.case.request(opener, '/panel/support.php?project=__support_account__&ticket='+ticket)
        self.assertEqual(code, 200)
        self.assertTrue('Legacy support sentinel' in body)
        with sqlite3.connect(self.case.database) as db:
            creator = db.execute('SELECT creator FROM support_tickets WHERE id=?', (ticket,)).fetchone()[0]
        self.assertTrue(creator.startswith('acct:'))


if __name__ == '__main__':
    unittest.main()
