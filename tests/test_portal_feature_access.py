"""Portal view/use boundaries against disposable application data."""
import json
import sqlite3
import unittest

import test_http_security as fixtures


class PortalFeatureAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('PortalAclFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture('runTest')
        self.case.setUp()
        self.policy = self.case.app / 'panel/db/access_policy.json'
        self.policy.unlink(missing_ok=True)
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role="viewer",permissions=? WHERE username="viewer"', (json.dumps(['products', 'calendar', 'occasions']),))
        self.opener, self.token = self.case.login('viewer')

    def post(self, action, **fields):
        return self.case.request(self.opener, '/panel/portal.php?project=p', {'action': action, **fields},
            {'X-CSRF-Token': self.token, 'X-Requested-With': 'XMLHttpRequest'})

    def test_viewer_can_read_but_cannot_change_products_or_reference_assets(self):
        self.assertEqual(self.case.request(self.opener, '/panel/portal.php?tab=library')[0], 200)
        before = self.case.record('manual')
        for action, fields in [
            ('upload_product_ref_images', {'product_id': 'manual', 'ref_urls': 'https://assets.example/ref.jpg'}),
            ('bulk_request_content_gen', {'product_ids[]': 'manual'}),
            ('comment', {'product_id': 'manual', 'comment': 'Denied comment'}),
        ]:
            with self.subTest(action=action):
                self.assertEqual(self.post(action, **fields)[0], 403)
        self.assertEqual(self.case.record('manual'), before)

    def test_viewer_cannot_vote_on_calendar_or_occasions(self):
        self.assertEqual(self.post('cast_occasion_vote', occ_key='fixture', stage='stage1')[0], 403)
        self.assertEqual(self.post('get_pending_browser_relay_posts')[0], 403)
        with sqlite3.connect(self.case.database) as db:
            settings = json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])
        self.assertNotIn('occasions_plans', settings)

    def test_profile_update_is_independent_of_product_use(self):
        code, body = self.post('update_profile', display_name='نام آزمایشی')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')

    def test_parent_use_grant_allows_only_the_viewers_assigned_reference_task(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE username="viewer"', (json.dumps(['reference_photo']),))
        self.policy.write_text(json.dumps({'products': {'use': {'users': ['viewer']}}}))
        code, body = self.post('upload_product_ref_images', product_id='manual', ref_urls='https://assets.example/ref.jpg')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        self.assertEqual(self.post('comment', product_id='manual', comment='Not assigned')[0], 403)

    def test_explicit_use_grant_allows_selected_feature_only(self):
        self.policy.write_text(json.dumps({'products': {'use': {'users': ['viewer']}}}))
        code, body = self.post('comment', product_id='manual', comment='Allowed comment')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')
        self.assertEqual(self.post('cast_occasion_vote', occ_key='fixture', stage='stage1')[0], 403)

    def test_product_audience_restriction_also_applies_to_portal(self):
        self.policy.write_text(json.dumps({'products': {'view': {'users': ['someone_else']}}}))
        code, body = self.case.request(self.opener, '/panel/portal.php?tab=library')
        self.assertEqual(code, 403)
        self.assertNotIn('window.portalProducts', body)

    def test_calendar_operation_on_bare_controller_needs_no_product_grant(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE username="viewer"', (json.dumps(['calendar']),))
        self.policy.write_text(json.dumps({'calendar': {'use': {'users': ['viewer']}}}))
        code, body = self.post('get_pending_browser_relay_posts')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['status'], 'success')

    def test_calendar_only_view_does_not_emit_the_full_product_catalog(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE username="viewer"', (json.dumps(['calendar']),))
            db.execute('UPDATE products SET name="Private catalog sentinel" WHERE id="manual"')
        code, body = self.case.request(self.opener, '/panel/portal.php?tab=calendar')
        self.assertEqual(code, 200)
        self.assertTrue('Private catalog sentinel' not in body, 'Calendar response leaked a hidden product')
        self.assertEqual(self.case.request(self.opener, '/panel/portal.php?tab=library')[0], 403)
        self.assertEqual(self.case.record('manual')['name'], 'Private catalog sentinel')

    def test_client_settings_never_include_sql_or_connection_credentials(self):
        with sqlite3.connect(self.case.database) as db:
            settings = dict(self.case.settings, prod_approval_split_enabled=True, prod_approval_stage1_min=2,
                connections={'telegram': {'token': 'private-telegram-sentinel'}},
                openai_api_key='private-ai-sentinel')
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(settings),))
        for username, path in [('viewer', '/panel/portal.php?tab=library'), ('limited', '/panel/admin.php?tab=products')]:
            with self.subTest(username=username):
                opener, _ = self.case.login(username)
                code, body = self.case.request(opener, path)
                self.assertEqual(code, 200)
                for secret in ['synthetic-sql-secret', 'private-telegram-sentinel', 'private-ai-sentinel']:
                    self.assertTrue(secret not in body, 'Page exposed a setting outside the approval widget contract')
                self.assertIn('"prod_approval_stage1_min":2', body)


if __name__ == '__main__':
    unittest.main()
