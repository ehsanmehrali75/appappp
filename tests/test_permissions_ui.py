"""Member editor and navigation contracts against an isolated PHP/SQLite site."""
import json
import re
import sqlite3
import unittest
from html.parser import HTMLParser
import test_http_security as fixtures


class FormControls(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'form':
            self.current = []
            self.forms.append(self.current)
        elif self.current is not None and tag in ('input', 'select', 'button'):
            self.current.append(attributes)

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None

    def form(self, action):
        return next(form for form in self.forms if any(control.get('name') == action for control in form))


class PermissionsUI(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('PermissionsUIFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute('DELETE FROM projects WHERE id="other"')
            db.execute('DELETE FROM project_users WHERE project_id="other"')
            db.execute('UPDATE project_users SET role="project_admin",permissions="[]" WHERE username="limited" AND project_id="p"')
            db.execute('UPDATE project_users SET permissions=? WHERE username="editor" AND project_id="p"', (json.dumps(['products', 'library', 'approve_content', 'size_chart', 'prompthub_manage', 'legacy_custom']),))
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(dict(self.case.settings, active_modules=['products', 'users', 'size_studio', 'website_export', 'calendar', 'prompthub'])),))
        for path in (self.case.app/'panel/db/cache').glob('*.cache'):
            path.unlink()
        for filename in ['access_policy.json', 'feature_flags.json']:
            path = self.case.app/'panel/db'/filename
            if path.exists():
                path.unlink()

    def users_page(self, username=None):
        opener, _ = self.case.login(username) if username else self.case.login()
        status, body = self.case.request(opener, '/panel/admin.php?tab=users')
        self.assertEqual(status, 200)
        return body, opener

    def test_add_and_edit_use_the_same_complete_permission_catalog(self):
        html, _ = self.users_page()
        forms = FormControls(html)
        grants = lambda action: {control['value'] for control in forms.form(action) if control.get('name') == 'perms[]'}
        self.assertEqual(grants('add_user'), grants('edit_user'))
        self.assertTrue({'products', 'library', 'approve_content', 'prompthub_manage', 'users', 'website_export', 'size_studio', 'legacy_custom'}.issubset(grants('edit_user')))
        self.assertFalse({'main_orders', 'markshow_roles', 'schedule', 'view_items'} & grants('edit_user'))
        members = [json.loads(match) for match in re.findall(r'window\.projectUsers\[\'[^\']+\'\] = (.*?);', html)]
        editor = next(member for member in members if member['username'] == 'editor')
        self.assertIn('size_studio', editor['permissions'])
        self.assertNotIn('size_chart', editor['permissions'])
        self.assertIn('legacy_custom', editor['permissions'])
        self.assertTrue(all('password' not in member for member in members))

    def test_project_admin_editor_only_offers_current_project(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('other', 'Other private project', '2026-01-01', '2030-01-01', '{}'))
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)', ('other', 'editor', 'Other-fixture-password', 'viewer', '[]'))
        html, _ = self.users_page('limited')
        forms = FormControls(html)
        for action in ['add_user', 'edit_user']:
            self.assertEqual([control['value'] for control in forms.form(action) if control.get('name') == 'assigned_projects[]'], ['p'])
        edit_username = next(control for control in forms.form('edit_user') if control.get('name') == 'username')
        self.assertIn('readonly', edit_username)
        self.assertNotIn('Other private project', html)

    def test_view_only_user_management_hides_mutation_forms(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role="manager",permissions=? WHERE username="limited" AND project_id="p"', (json.dumps(['products', 'users']),))
        (self.case.app/'panel/db/access_policy.json').write_text(json.dumps({'users': {'use': {'roles': ['main_admin']}}}))
        html, _ = self.users_page('limited')
        self.assertNotIn('onclick="openEditUser(', html)
        self.assertNotIn('name="delete_user"', html)
        self.assertNotIn('id="editUserModal"', html)


if __name__ == '__main__':
    unittest.main()
