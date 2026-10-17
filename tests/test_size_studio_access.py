"""Project-bound Size Studio requests using disposable PHP/SQLite data."""
import json
import sqlite3
import unittest

import test_http_security as http_fixture


class SizeStudioAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        http_fixture.HttpSecurity.setUpClass()
        cls.fixture = http_fixture.HttpSecurity('runTest')

    @classmethod
    def tearDownClass(cls):
        http_fixture.HttpSecurity.tearDownClass()

    def setUp(self):
        self.fixture.setUp()
        with sqlite3.connect(self.fixture.database) as db:
            db.execute('DELETE FROM project_users WHERE project_id="q"')
            db.execute('DELETE FROM projects WHERE id="q"')
            db.execute('UPDATE project_users SET role="manager",permissions=? WHERE project_id="p" AND username="limited"', (json.dumps(['products', 'size_chart']),))
            second = dict(self.fixture.settings, size_chart_settings={'brand_title': 'Second original'})
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('q', 'Second fixture', '2026-01-01', '2030-01-01', json.dumps(second)))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('q', 'limited', 'Fixture-password-123!', 'viewer', json.dumps(['products'])))
        self.opener, self.token = self.fixture.login('limited')

    def settings(self, project_id):
        with sqlite3.connect(self.fixture.database) as db:
            return json.loads(db.execute('SELECT settings FROM projects WHERE id=?', (project_id,)).fetchone()[0]).get('size_chart_settings', {})

    def save(self, url_project='p', posted_project='p', **settings):
        fields = {'action': 'td_scs_save_branding_settings', 'project_id': posted_project, 'brand_title': 'First updated', **settings}
        return self.fixture.request(self.opener, '/panel/size/?project='+url_project, fields, {'X-CSRF-Token': self.token})

    def test_conflicting_target_cannot_reuse_another_projects_permission(self):
        code, _ = self.save(posted_project='q')
        self.assertEqual(code, 403)
        self.assertEqual(self.settings('q'), {'brand_title': 'Second original'})
        self.assertEqual(self.settings('p'), {})

    def test_normal_save_stays_bound_to_url_after_another_tab_switches(self):
        # Opening q changes the session's project even though that viewer cannot use the studio.
        self.fixture.request(self.opener, '/panel/portal.php?project=q&tab=library')
        code, body = self.save(content_padding_top='0', content_padding_bottom='0', content_padding_x='0', card_border_radius='0', table_row_colors='{"0":"#123456","2":"#abcdef"}')
        self.assertEqual(code, 200)
        result = json.loads(body)
        self.assertEqual(result['project_id'], 'p')
        saved = self.settings('p')
        for key in ['content_padding_top', 'content_padding_bottom', 'content_padding_x', 'card_border_radius']:
            self.assertEqual(saved[key], 0)
        self.assertEqual(saved['table_row_colors'], {'0': '#123456', '2': '#abcdef'})
        self.assertEqual(self.settings('q'), {'brand_title': 'Second original'})

    def test_products_permission_does_not_grant_studio_access(self):
        opener, _ = self.fixture.login('editor')
        self.assertEqual(self.fixture.request(opener, '/panel/size/?project=p')[0], 403)

    def test_viewer_cannot_change_branding(self):
        with sqlite3.connect(self.fixture.database) as db:
            db.execute('UPDATE project_users SET role="viewer" WHERE project_id="p" AND username="limited"')
        self.assertEqual(self.save()[0], 403)
        self.assertEqual(self.settings('p'), {})

    def test_project_module_restriction_applies_to_direct_url(self):
        with sqlite3.connect(self.fixture.database) as db:
            settings = dict(self.fixture.settings, module_visibility={'size_studio': 'admin_only'})
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(settings),))
        self.assertEqual(self.fixture.request(self.opener, '/panel/size/?project=p')[0], 403)
        self.assertEqual(self.save()[0], 403)


if __name__ == '__main__':
    unittest.main()
