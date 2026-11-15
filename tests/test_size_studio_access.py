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

    def test_preset_backgrounds_save_together_and_survive_legacy_save(self):
        overrides={'pants_simple':'uploads/women.png','pants_extended':'uploads/women.png','tops_extended':'https://cdn.example/men.jpg'}
        code,body=self.save(bg_image='uploads/default.png',preset_backgrounds=json.dumps(overrides))
        self.assertEqual(code,200,body)
        self.assertEqual(self.settings('p')['preset_backgrounds'],overrides)
        self.assertEqual(self.settings('p')['bg_image'],'uploads/default.png')
        self.save()
        self.assertEqual(self.settings('p')['preset_backgrounds'],overrides)
        self.assertEqual(self.settings('q'),{'brand_title':'Second original'})

    def test_invalid_background_map_is_rejected_without_saving_branding(self):
        for value in ['broken','{"x":"javascript:alert(1)"}','{"x":{"url":"uploads/a.png"}}']:
            self.assertEqual(self.save(preset_backgrounds=value)[0],422)
            self.assertEqual(self.settings('p'),{})

    def preset_request(self, **fields):
        defaults={'action':'td_scs_preset_save','name':'شلوار','category':'شلوار','columns':json.dumps(['سایز','قد']),'show_model':'1','illus_url':'uploads/guide.png'}
        defaults.update(fields)
        code,body=self.fixture.request(self.opener,'/panel/size/?project=p',defaults,{'X-CSRF-Token':self.token})
        return code,json.loads(body)

    def presets(self):
        with sqlite3.connect(self.fixture.database) as db:
            return json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0]).get('size_chart_presets',[])

    def test_copy_draft_saves_new_group_without_changing_original(self):
        code,result=self.preset_request(preset_group='men');self.assertEqual(code,200)
        original=result['data']['id'];before=self.presets()[0].copy()
        code,result=self.preset_request(preset_group='women',name='شلوار زنانه');self.assertEqual(code,200)
        copy=result['data']['id'];self.assertNotEqual(copy,original)
        presets=self.presets();self.assertEqual(presets[0],before)
        self.assertEqual(presets[1]['preset_group'],'women');self.assertEqual(presets[1]['columns'],before['columns']);self.assertEqual(presets[1]['illus_url'],before['illus_url'])
        self.preset_request(id=original,name='شلوار مردانه')
        self.assertEqual(self.presets()[0]['preset_group'],'men')
        self.assertEqual(self.settings('q'),{'brand_title':'Second original'})

    def test_invalid_group_or_missing_preset_cannot_save(self):
        self.assertEqual(self.preset_request(preset_group='unknown')[0],422)
        self.assertEqual(self.preset_request(id='missing',preset_group='men')[0],422)
        self.assertEqual(self.presets(),[])

    def test_group_backgrounds_are_scoped_and_legacy_save_keeps_them(self):
        groups={group:'uploads/'+group+'.png' for group in ['men','women','accessories','shoes']}
        code,body=self.save(group_backgrounds=json.dumps(groups));self.assertEqual(code,200,body)
        self.assertEqual(self.settings('p')['group_backgrounds'],groups)
        self.save();self.assertEqual(self.settings('p')['group_backgrounds'],groups)
        self.assertEqual(self.settings('q'),{'brand_title':'Second original'})

    def test_invalid_group_backgrounds_do_not_save_other_settings(self):
        for value in ['broken','{"unknown":"uploads/a.png"}','{"men":"javascript:alert(1)"}']:
            self.assertEqual(self.save(group_backgrounds=value)[0],422)
            self.assertEqual(self.settings('p'),{})

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
