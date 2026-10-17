"""Proposal resources retain their project scope, including published links."""
import json
import sqlite3
import unittest
import urllib.request

import test_http_security as fixtures


class ProposalAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('ProposalFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture('runTest')
        self.case.setUp()
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE username="limited" AND project_id="p"', ('["products"]',))
        self.opener, self.token = self.case.login('limited')
        with sqlite3.connect(self.case.database) as db:
            db.execute('CREATE TABLE IF NOT EXISTS proposals(id TEXT PRIMARY KEY,project_id TEXT,type TEXT,title TEXT,logo_url TEXT,status TEXT,content TEXT,created_at INTEGER,updated_at INTEGER)')
            db.execute('DELETE FROM proposals')
            db.execute('DELETE FROM project_users WHERE project_id="proposal-q"')
            db.execute('DELETE FROM projects WHERE id="proposal-q"')
            db.execute('UPDATE project_users SET permissions=? WHERE username="limited" AND project_id="p"', ('["proposals"]',))
            settings = json.dumps(dict(self.case.settings, active_modules=['proposals', 'chat']))
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (settings,))
            db.execute('INSERT INTO projects(id,name,settings) VALUES (?,?,?)', ('proposal-q', 'Other private project', settings))
            for pid, project, title, status in [('123', 'proposal-q', 'Other private proposal', 'draft'), ('shared', 'proposal-q', 'Published fixture proposal', 'published')]:
                db.execute('INSERT INTO proposals(id,project_id,title,status,content) VALUES (?,?,?,?,?)', (pid, project, title, status, '{"pages":[]}'))
        for p in (self.case.app/'panel/db/cache').glob('*.cache'):
            p.unlink()

    def post(self, fields, csrf=True, query='?project=p'):
        return self.case.request(self.opener, '/panel/pro/'+query, fields, {'X-CSRF-Token': self.token} if csrf else {})

    def test_proposals_grant_opens_workspace_without_product_or_catalog_grants(self):
        code, body = self.case.request(self.opener, '/panel/pro/?project=p')
        self.assertEqual(code, 200)
        self.assertTrue('عدم دسترسی کافی' not in body)
        self.assertTrue('Other private project' not in body)

    def test_existing_document_cannot_be_changed_or_deleted_through_another_project(self):
        for action in ['save', 'delete']:
            with self.subTest(action=action):
                code, _ = self.post({'ajax_action': action, 'id': '123', 'project_id': 'p', 'title': 'Denied update', 'content': '{}'})
                self.assertEqual(code, 403)
        with sqlite3.connect(self.case.database) as db:
            self.assertEqual(db.execute('SELECT title FROM proposals WHERE id="123"').fetchone()[0], 'Other private proposal')

    def test_valid_document_json_and_string_id_survive_create_and_update(self):
        content = json.dumps({'pages': [{'title': 'پیشنهاد فارسی', 'amount': 123}]})
        fields = {'ajax_action': 'save', 'project_id': 'p', 'title': 'Fixture proposal', 'status': 'draft', 'content': content}
        self.assertEqual(self.post(fields, csrf=False)[0], 403)
        code, body = self.post(fields)
        self.assertEqual(code, 200)
        document = json.loads(body)['id']
        fields.update(id=document, title='Updated fixture')
        self.assertTrue(json.loads(self.post(fields)[1])['success'])
        with sqlite3.connect(self.case.database) as db:
            row = db.execute('SELECT project_id,title,content FROM proposals WHERE id=?', (document,)).fetchone()
        self.assertEqual(row, ('p', 'Updated fixture', content))

    def test_published_link_is_read_only_and_respects_disabled_module(self):
        anonymous = urllib.request.build_opener()
        code, body = self.case.request(anonymous, '/panel/pro/?public_id=shared')
        self.assertEqual(code, 200)
        self.assertTrue('Published fixture proposal' in body)
        self.assertEqual(self.post({'ajax_action': 'delete', 'id': 'shared'}, query='?public_id=shared')[0], 403)
        (self.case.app/'panel/db/feature_flags.json').write_text('{"proposals":"maintenance"}')
        self.assertEqual(self.case.request(anonymous, '/panel/pro/?public_id=shared')[0], 403)
        (self.case.app/'panel/db/feature_flags.json').unlink()

    def test_chat_uses_its_own_grant(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET permissions=? WHERE username="limited" AND project_id="p"', ('["chat"]',))
        code, body = self.case.request(self.opener, '/panel/chat.php?project=p')
        self.assertEqual(code, 200)
        self.assertTrue('چت' in body)


if __name__ == '__main__':
    unittest.main()
