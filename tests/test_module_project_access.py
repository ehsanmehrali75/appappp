"""Real HTTP authorization of project-scoped VM and SEO resources; no external services."""
import json
import sqlite3
import unittest
import urllib.request
import test_http_security as fixtures


class ModuleProjectAccess(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('ModuleFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()
        self.settings = dict(self.case.settings, active_modules=['products', 'vm_studio', 'seo_intel'])
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(self.settings),))
            db.execute('DELETE FROM project_users WHERE project_id="q"')
            db.execute('DELETE FROM products WHERE project_id="q"')
            db.execute('DELETE FROM projects WHERE id="q"')
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('q', 'PRIVATE PROJECT Q', '2026-01-01', '2030-01-01', json.dumps(self.settings)))
            db.execute('UPDATE project_users SET permissions=? WHERE project_id="p" AND username IN ("limited","viewer")', (json.dumps(['products', 'vm', 'analysis']),))
            db.execute('UPDATE project_users SET role="manager" WHERE project_id="p" AND username="limited"')
        self.private = self.case.app/'panel/db'
        for name in ['access_policy.json', 'feature_flags.json']:
            (self.private/name).unlink(missing_ok=True)
        self.vm = {
            'stores': [{'id': 'store-'+p, 'slug': 'store-'+p, 'project_id': p, 'name': 'STORE '+p} for p in ['p', 'q']],
            'layouts': [{'id': 'layout-'+p, 'store_id': 'store-'+p, 'is_active': True, 'objects': [], 'zones': []} for p in ['p', 'q']],
            'ai_recommendations': [{'id': 'rec-'+p, 'store_id': 'store-'+p, 'status': 'pending'} for p in ['p', 'q']],
        }
        (self.private/'vm_data.json').write_text(json.dumps(self.vm))
        (self.private/'composio_config.json').write_text(json.dumps({'api_key': 'synthetic-global-composio-secret'}))
        # Real project businesses are synchronized by SeoDB itself.
        (self.private/'seo_warehouse.json').unlink(missing_ok=True)
        self.clear_cache()

    def clear_cache(self):
        for path in (self.private/'cache').glob('*.cache'):
            path.unlink()

    def post(self, opener, token, path, body):
        return self.case.request(opener, path, raw=json.dumps(body).encode(), headers={'Content-Type': 'application/json', 'X-CSRF-Token': token})

    def test_unassigned_project_is_denied_on_all_vm_and_seo_entrypoints(self):
        opener, _ = self.case.login('limited')
        paths = [
            '/panel/vm/api.php?action=get_stores&project_id=q',
            '/panel/vm/api.php?action=get_products&project_id=q',
            '/panel/vm/api.php?action=get_store_data&store_id=store-q',
            '/panel/vm/api.php?action=get_score&store_id=store-q',
            '/panel/vm/index.php?project_slug=q', '/panel/vm/?project=q',
            '/admin/projects/q/vm', '/admin/projects/q/vm/stores/store-q',
            '/panel/seo_intel/api.php?action=get_overview&business=q',
            '/panel/seo_intel/index.php?business=q', '/panel/seo/?business=q',
            '/admin/projects/q/seo', '/admin/business/q/seo',
        ]
        for path in paths:
            with self.subTest(path=path):
                code, body = self.case.request(opener, path)
                self.assertEqual(code, 403)
                self.assertNotIn('PRIVATE PROJECT Q', body)
                self.assertNotIn('store-q', body)

    def test_assigned_project_read_access_and_switchers_are_scoped(self):
        opener, _ = self.case.login('limited')
        for path in ['/panel/vm/index.php?project_slug=p', '/admin/projects/p/vm', '/panel/seo_intel/index.php?business=p', '/admin/business/p/seo']:
            with self.subTest(path=path):
                code, body = self.case.request(opener, path)
                self.assertEqual(code, 200)
                self.assertNotIn('PRIVATE PROJECT Q', body)
        code, body = self.case.request(opener, '/panel/vm/api.php?action=get_stores&project_id=p')
        self.assertEqual(code, 200)
        self.assertIn('store-p', [x['id'] for x in json.loads(body)['data']])
        self.assertTrue(all(x['project_id'] == 'p' for x in json.loads(body)['data']))
        code, body = self.case.request(opener, '/panel/seo_intel/api.php?action=get_overview&business=p')
        self.assertEqual(code, 200)
        self.assertEqual(json.loads(body)['business']['id'], 'p')

    def test_unknown_resources_never_fall_back_to_first_project_business_or_store(self):
        opener, _ = self.case.login('limited')
        for path in ['/panel/vm/index.php?project_slug=missing', '/panel/vm/index.php?project=p&view=studio&store_id=store-q', '/panel/vm/api.php?action=get_store_data&store_id=missing', '/panel/seo_intel/api.php?action=get_overview&business=missing', '/panel/seo_intel/index.php?business=red-style']:
            with self.subTest(path=path):
                self.assertEqual(self.case.request(opener, path)[0], 403)

    def test_vm_mutations_require_post_and_csrf_then_persist_authorized_layout(self):
        opener, token = self.case.login('limited')
        path = '/panel/vm/api.php?action=save_layout'
        payload = {'project_id': 'p', 'store_id': 'store-p', 'layout': {'objects': [{'id': 'fixture-object', 'type': 'rack'}], 'zones': []}}
        for action in ['save_layout', 'apply_recommendation', 'generate_ai_recommendations', 'mflow_sync']:
            with self.subTest(action=action):
                self.assertEqual(self.case.request(opener, '/panel/vm/api.php?action='+action+'&project_id=p&store_id=store-p')[0], 403)
        self.assertEqual(self.post(opener, '', path, payload)[0], 403)
        self.assertEqual(json.loads((self.private/'vm_data.json').read_text()), self.vm)
        code, body = self.post(opener, token, path, payload)
        self.assertEqual(code, 200)
        self.assertTrue(json.loads(body)['success'])
        data = json.loads((self.private/'vm_data.json').read_text())
        self.assertTrue(any(any(o['id']=='fixture-object' for o in x.get('objects', [])) for x in data['layouts'] if x['store_id']=='store-p'))
        self.assertEqual(next(x for x in data['layouts'] if x['store_id']=='store-q'), self.vm['layouts'][1])

    def test_vm_cross_project_and_cross_origin_mutations_never_persist(self):
        opener, token = self.case.login('limited')
        payload = {'project_id': 'p', 'store_id': 'store-q', 'layout': {'objects': [{'id':'denied'}]}}
        self.assertEqual(self.post(opener, token, '/panel/vm/api.php?action=save_layout', payload)[0], 403)
        self.assertEqual(self.post(opener, token, '/panel/vm/api.php?action=apply_recommendation&project_id=q&store_id=store-q&rec_id=rec-q', {})[0], 403)
        self.assertEqual(self.case.request(opener, '/panel/vm/api.php?action=save_layout', raw=json.dumps(dict(payload, store_id='store-p')).encode(), headers={'Content-Type':'application/json','X-CSRF-Token':token,'Origin':'https://unrelated.example'})[0],403)
        self.assertEqual(json.loads((self.private/'vm_data.json').read_text()), self.vm)

    def test_seo_mutation_requires_post_csrf_and_business_membership(self):
        opener, token = self.case.login('limited')
        path = '/panel/seo_intel/api.php?action=save_connection&business=p'
        payload = {'integration_slug': 'fixture-integration', 'property_id': 'safe-fixture-property'}
        self.assertEqual(self.case.request(opener, path)[0], 403)
        self.assertEqual(self.post(opener, '', path, payload)[0], 403)
        self.assertEqual(self.post(opener, token, path.replace('business=p','business=q'), payload)[0], 403)
        code, body = self.post(opener, token, path, payload)
        self.assertEqual(code, 410)
        self.assertEqual(json.loads(body)['status'], 'retired')
        self.assertFalse((self.private/'seo_warehouse.json').exists() and 'fixture-integration' in (self.private/'seo_warehouse.json').read_text())

    def test_viewer_can_read_but_cannot_mutate_even_with_csrf(self):
        opener, token = self.case.login('viewer')
        for path in ['/panel/vm/api.php?action=get_stores&project_id=p', '/panel/seo_intel/api.php?action=get_keywords&business=p']:
            self.assertEqual(self.case.request(opener, path)[0], 200)
        self.assertEqual(self.post(opener, token, '/panel/vm/api.php?action=apply_recommendation&project_id=p&store_id=store-p&rec_id=rec-p', {})[0],403)
        self.assertEqual(self.post(opener, token, '/panel/seo_intel/api.php?action=save_connection&business=p', {'integration_slug':'fixture'})[0],403)

    def test_global_composio_secret_is_hidden_and_not_editable_by_project_managers(self):
        opener, token = self.case.login('limited')
        code, body = self.case.request(opener, '/panel/seo_intel/index.php?business=p&view=integrations&role=admin')
        self.assertEqual(code, 200)
        self.assertNotIn('synthetic-global-composio-secret', body)
        self.assertNotIn('id="composio-api-key"', body)
        self.assertNotIn('switchRole(', body)
        for action in ['test_composio', 'save_composio_config']:
            self.assertEqual(self.post(opener, token, '/panel/seo_intel/api.php?action='+action+'&business=p', {'api_key':'replacement'})[0],403)
        self.assertEqual(json.loads((self.private/'composio_config.json').read_text())['api_key'], 'synthetic-global-composio-secret')

    def test_child_visibility_rules_cover_pretty_direct_api_and_navigation(self):
        (self.private/'access_policy.json').write_text(json.dumps({x:{'view':{'roles':['main_admin']}} for x in ['vm_layout','seo_intel_integrations']}))
        opener, token = self.case.login('limited')
        for path in ['/admin/projects/p/vm/studio','/panel/vm/index.php?project=p&view=studio','/admin/business/p/seo/integrations','/panel/seo_intel/index.php?business=p&view=integrations&role=admin']:
            with self.subTest(path=path):self.assertEqual(self.case.request(opener,path)[0],403)
        self.assertEqual(self.post(opener,token,'/panel/vm/api.php?action=save_layout',{'project_id':'p','store_id':'store-p','layout':{'objects':[]}})[0],403)
        self.assertEqual(self.post(opener,token,'/panel/seo_intel/api.php?action=save_connection&business=p',{'integration_slug':'fixture'})[0],403)
        _, body = self.case.request(opener,'/panel/vm/index.php?project=p')
        self.assertNotIn('href="?view=studio',body)
        _, body = self.case.request(opener,'/panel/seo_intel/index.php?business=p')
        self.assertNotIn('view=integrations&amp;business=p',body)

    def test_project_and_global_restrictions_cover_api_actions(self):
        opener, token = self.case.login('limited')
        settings = dict(self.settings, module_visibility={'vm_studio':'admin_only','seo_intel':'admin_only'})
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(settings),))
        for path in ['/panel/vm/api.php?action=get_stores&project_id=p','/panel/seo_intel/api.php?action=get_overview&business=p','/admin/projects/p/vm','/admin/business/p/seo']:
            self.assertEqual(self.case.request(opener,path)[0],403)
        with sqlite3.connect(self.case.database) as db:db.execute('UPDATE projects SET settings=? WHERE id="p"',(json.dumps(self.settings),))
        (self.private/'feature_flags.json').write_text(json.dumps({'vm_studio':'disabled','seo_intel':'disabled'}))
        for path in ['/panel/vm/api.php?action=get_stores&project_id=p','/panel/seo_intel/api.php?action=get_overview&business=p']:
            self.assertEqual(self.case.request(opener,path)[0],403)

    def test_target_project_permissions_override_stale_previous_tab_context(self):
        with sqlite3.connect(self.case.database) as db:
            db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)',('q','limited','Fixture-password-123!','manager',json.dumps(['vm','analysis'])))
            db.execute('UPDATE project_users SET permissions=? WHERE project_id="p" AND username="limited"',(json.dumps(['products']),))
        opener, token = self.case.login('limited')
        self.assertEqual(self.case.request(opener,'/panel/vm/index.php?project_slug=q')[0],403)
        self.assertEqual(self.case.request(opener,'/panel/workspace.php',{'select_project':'q','app_csrf':token})[0],200)
        for path in ['/panel/vm/index.php?project_slug=q','/admin/projects/q/vm','/panel/seo_intel/api.php?action=get_overview&business=q','/admin/projects/q/seo']:
            with self.subTest(path=path):self.assertEqual(self.case.request(opener,path)[0],200)
        self.assertEqual(self.case.request(opener,'/panel/vm/index.php?project_slug=p')[0],403)

    def test_owner_retains_cross_project_module_access(self):
        opener, token = self.case.login()
        self.assertEqual(self.case.request(opener,'/panel/vm/index.php?project_slug=q')[0],403)
        self.assertEqual(self.case.request(opener,'/panel/workspace.php',{'select_project':'q','app_csrf':token})[0],200)
        for path in ['/panel/vm/index.php?project_slug=q','/panel/vm/api.php?action=get_stores&project_id=q','/panel/seo_intel/api.php?action=get_overview&business=q','/admin/projects/q/seo']:
            with self.subTest(path=path):self.assertEqual(self.case.request(opener,path)[0],200)


if __name__ == '__main__':
    unittest.main()
