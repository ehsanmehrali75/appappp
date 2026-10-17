"""Explicit view/use decisions against an isolated application's real helpers."""
import json
import sqlite3
import subprocess
import unittest

import test_http_security as fixtures


class PermissionModes(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = type('PermissionModesFixture', (fixtures.HttpSecurity,), {})
        cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()

    def setUp(self):
        self.case = self.fixture()
        self.case.setUp()
        self.policy = self.case.app / 'panel/db/access_policy.json'
        if self.policy.exists():
            self.policy.unlink()
        self.configure('manager', ['products'], ['products', 'settings'])

    def configure(self, role, permissions, modules):
        with sqlite3.connect(self.case.database) as db:
            db.execute('UPDATE project_users SET role=?,permissions=? WHERE project_id="p" AND username="limited"', (role, json.dumps(permissions)))
            db.execute('UPDATE projects SET settings=? WHERE id="p"', (json.dumps(dict(self.case.settings, active_modules=modules)),))
        for path in (self.case.app / 'panel/db/cache').glob('*.cache'):
            path.unlink()

    def decisions(self, key):
        source = '''session_start(); require $argv[1];
            $_SESSION["user"]=["username"=>"limited","role"=>"manager"];
            $_SESSION["project_id"]="p"; $_SERVER["REQUEST_METHOD"]="GET";
            echo json_encode([hasPermission($argv[2],"view"),hasPermission($argv[2],"use"),
                hasPermission($argv[2],"invalid"),canAccessFeature($argv[2],"view"),canAccessFeature($argv[2],"use")]);'''
        result = subprocess.run([fixtures.PHP, '-r', source, str(self.case.app / 'panel/inc/functions.php'), key], check=True, capture_output=True, text=True, timeout=10)
        return json.loads(result.stdout)

    def test_use_grant_requires_inherited_or_explicit_view_permission(self):
        self.policy.write_text(json.dumps({'settings': {'use': {'users': ['limited']}}}))
        self.assertEqual(self.decisions('settings'), [False, False, False, False, False])
        self.policy.write_text(json.dumps({'settings': {'view': {'users': ['limited']}, 'use': {'users': ['limited']}}}))
        self.assertEqual(self.decisions('settings'), [True, True, False, True, True])

    def test_viewer_requires_explicit_use_grant_even_during_get_rendering(self):
        self.configure('viewer', ['products'], ['products'])
        self.assertEqual(self.decisions('products'), [True, False, False, True, False])
        self.policy.write_text(json.dumps({'products': {'use': {'users': ['limited']}}}))
        self.assertEqual(self.decisions('products'), [True, True, False, True, True])

    def test_granular_operator_retains_work_without_a_product_management_grant(self):
        self.configure('manager', ['reference_photo'], ['products'])
        self.assertEqual(self.decisions('reference_photo'), [True, True, False, True, True])
        self.assertEqual(self.decisions('products'), [False, False, False, False, False])
        self.policy.write_text(json.dumps({'products': {'use': {'users': ['another-user']}}}))
        self.assertEqual(self.decisions('reference_photo'), [True, False, False, True, False])

    def test_disabled_parent_project_module_blocks_granular_permission(self):
        self.configure('manager', ['reference_photo'], [])
        self.assertEqual(self.decisions('reference_photo'), [False, False, False, False, False])

    def test_legacy_alias_permissions_match_enabled_project_module(self):
        for alias, canonical in [('size_chart', 'size_studio'), ('vm', 'vm_studio'), ('seo', 'seo_classic'), ('sale', 'sale_analysis'), ('prompt_hub', 'prompthub')]:
            with self.subTest(alias=alias):
                self.configure('manager', [alias], [alias])
                self.assertEqual(self.decisions(canonical), [True, True, False, True, True])
                self.assertEqual(self.decisions(alias), [True, True, False, True, True])


if __name__ == '__main__':
    unittest.main()
