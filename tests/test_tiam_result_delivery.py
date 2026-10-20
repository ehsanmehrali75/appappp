"""Lost-acknowledgement regressions against isolated PHP/SQLite, never live SQL."""
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import unittest
import urllib.request
from pathlib import Path

import test_http_security as fixtures


class TiamResultDelivery(unittest.TestCase):
    token = 'a' * 64

    @classmethod
    def setUpClass(cls):
        fixtures.HttpSecurity.setUpClass()
        cls.fixture = fixtures.HttpSecurity('runTest')
        result = subprocess.run([
            fixtures.PHP, '-r', 'require $argv[1]; echo TiamInventory::directory();',
            str(cls.fixture.app / 'panel/inc/TiamInventory.php'),
        ], check=True, capture_output=True, text=True)
        cls.state_dir = Path(result.stdout)
        cls.job_path = cls.state_dir / (hashlib.sha256(b'p').hexdigest() + '.job')

    @classmethod
    def tearDownClass(cls):
        fixtures.HttpSecurity.tearDownClass()
        shutil.rmtree(cls.state_dir)

    def setUp(self):
        self.fixture.setUp()
        self.job_path.unlink(missing_ok=True)
        self.opener = urllib.request.build_opener()

    def seed(self, kind='view_test', state='running', expired=False):
        config = {
            'host': '192.0.2.1', 'database': 'Fixture', 'view': 'Stock',
            'username': 'reader', 'password': 'fixture-sql-password',
            'agent_token': self.token, 'enabled': kind == 'scheduled',
            'mapping': {'sku': 'Code', 'name': 'Name', 'stock': 'Qty'},
        }
        source = '''require_once $argv[1]; require_once $argv[2];
            $p=getCurrentProject('p',true);
            $cfg=TiamInventory::config([],json_decode($argv[3],true));
            if($argv[4]==='scheduled')$cfg['approved']=TiamInventory::fingerprint($cfg);
            $p['settings']['tiam_inventory']=$cfg;
            if(!saveProject($p))throw new RuntimeException('fixture save failed');
            $j=TiamInventory::createJob($p,$argv[4],'fixture-owner');
            $j['state']=$argv[5];
            if($argv[6]==='1')$j['expires']=time()-1;
            TiamInventory::write('p',$j); echo $j['id'];'''
        result = subprocess.run([
            fixtures.PHP, '-r', source, str(self.fixture.app / 'panel/inc/functions.php'),
            str(self.fixture.app / 'panel/inc/TiamInventory.php'), json.dumps(config),
            kind, state, '1' if expired else '0',
        ], check=True, capture_output=True, text=True)
        return result.stdout

    def send(self, job_id, quantity=3, token=None):
        body = {'action': 'agent_result', 'project': 'p', 'job': job_id, 'success': True,
                'columns': ['Code', 'Name', 'Qty'],
                'rows': [{'Code': 'A', 'Name': 'Fixture import', 'Qty': quantity}],
                'logs': [{'stage': 'sql_view', 'status': 'success', 'message': 'Fixture SELECT completed'}]}
        code, raw = self.fixture.request(
            self.opener, '/panel/tiam_inventory.php', raw=json.dumps(body).encode(),
            headers={'Content-Type': 'application/json', 'X-Tiam-Token': token or self.token},
        )
        return code, json.loads(raw)

    def test_body_token_authenticates_when_host_strips_custom_header(self):
        self.seed()
        body={'action':'agent_poll','project':'p','agent_token':self.token}
        code,raw=self.fixture.request(self.opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
        self.assertEqual(code,200,raw)
        self.assertEqual(json.loads(raw)['status'],'idle')
        self.assertNotIn(self.token,raw)
        body['agent_token']='wrong-token'
        code,raw=self.fixture.request(self.opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-Tiam-Token':self.token})
        self.assertEqual(code,401,'Contradictory credentials must fail closed')

    def test_pairing_is_csrf_protected_and_rotates_project_token(self):
        import re
        self.seed()
        opener,csrf=self.fixture.login()
        code,page=self.fixture.request(opener,'/panel/admin.php?tab=settings')
        self.assertEqual(code,200)
        tiam_csrf=re.search(r'const tiamInventoryCsrf = "([a-f0-9]+)"',page).group(1)
        body={'action':'pair','project':'p','csrf':'bad'}
        code,raw=self.fixture.request(opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
        self.assertEqual(code,403)
        body['csrf']=tiam_csrf
        code,raw=self.fixture.request(opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
        self.assertEqual(code,200,raw)
        profile=json.loads(raw)['connection']
        self.assertEqual(profile['project_id'],'p')
        self.assertEqual(len(profile['agent_token']),64)
        body={'action':'agent_poll','project':'p','agent_token':profile['agent_token']}
        code,raw=self.fixture.request(self.opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
        self.assertEqual(code,200,raw)
        code,raw=self.fixture.request(self.opener,'/panel/tiam_inventory.php',raw=json.dumps(dict(body,agent_token=self.token)).encode(),headers={'Content-Type':'application/json'})
        self.assertEqual(code,401,'Old token must be revoked')
        code,page=self.fixture.request(opener,'/panel/admin.php?tab=settings')
        self.assertNotIn(profile['agent_token'],page,'Saved settings cannot reveal the pairing secret')

    def test_pairing_is_not_available_to_anonymous_or_viewer(self):
        self.seed()
        body={'action':'pair','project':'p','csrf':'not-a-valid-csrf'}
        code,raw=self.fixture.request(self.opener,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
        self.assertEqual(code,401)
        viewer,csrf=self.fixture.login('viewer')
        code,raw=self.fixture.request(viewer,'/panel/tiam_inventory.php',raw=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
        self.assertEqual(code,403)

    def test_completed_view_retry_acknowledges_without_replacing_sample(self):
        job = self.seed()
        self.assertEqual(self.send(job), (200, {'status': 'received', 'state': 'done'}))
        saved = self.job_path.read_bytes()
        self.assertEqual(self.send(job, 999), (200, {'status': 'received', 'state': 'done'}))
        self.assertEqual(self.job_path.read_bytes(), saved)

    def test_preview_retry_does_not_apply_or_replace_preview(self):
        job = self.seed('preview')
        self.assertEqual(self.send(job), (200, {'status': 'received', 'state': 'preview'}))
        saved = self.job_path.read_bytes()
        self.assertEqual(self.send(job, 999), (200, {'status': 'received', 'state': 'preview'}))
        self.assertEqual(self.job_path.read_bytes(), saved)
        with sqlite3.connect(self.fixture.database) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0], 5)

    def test_scheduled_result_retry_never_applies_twice(self):
        job = self.seed('scheduled')
        self.assertEqual(self.send(job), (200, {'status': 'received', 'state': 'applied'}))
        with sqlite3.connect(self.fixture.database) as db:
            self.assertEqual(float(db.execute('SELECT stock_total FROM products WHERE project_id="p" AND sku="A"').fetchone()[0]), 3)
            db.execute('UPDATE products SET stock_total="77" WHERE project_id="p" AND sku="A"')
        self.assertEqual(self.send(job, 999), (200, {'status': 'received', 'state': 'applied'}))
        with sqlite3.connect(self.fixture.database) as db:
            self.assertEqual(float(db.execute('SELECT stock_total FROM products WHERE project_id="p" AND sku="A"').fetchone()[0]), 77)

    def test_busy_state_lock_is_retryable_without_losing_result(self):
        if os.name != 'posix':
            self.skipTest('This lock-contention fixture uses POSIX flock')
        import fcntl
        job = self.seed()
        lock_path = self.state_dir / (hashlib.sha256(b'p').hexdigest() + '.lock')
        saved = self.job_path.read_bytes()
        with lock_path.open('ab') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            code, reply = self.send(job)
            self.assertEqual(code, 503)
            self.assertEqual(reply['code'], 'TIAM_STATE_BUSY')
            self.assertEqual(self.job_path.read_bytes(), saved)
        self.assertEqual(self.send(job), (200, {'status': 'received', 'state': 'done'}))

    def test_retry_keeps_auth_job_identity_and_active_lease_guards(self):
        job = self.seed()
        self.assertEqual(self.send(job)[0], 200)
        self.assertEqual(self.send(job, token='b'*64)[0], 401)
        self.assertEqual(self.send('unrelated-job')[0], 400)
        for state, expired in [('queued', False), ('cancelled', False), ('running', True)]:
            with self.subTest(state=state, expired=expired):
                self.job_path.unlink(missing_ok=True)
                job = self.seed(state=state, expired=expired)
                saved = self.job_path.read_bytes()
                self.assertEqual(self.send(job)[0], 400)
                self.assertEqual(self.job_path.read_bytes(), saved)


if __name__ == '__main__':
    unittest.main()
