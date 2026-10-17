"""Worker dispatch never runs global jobs as an unauthorised browser request."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import test_http_security as fixtures


class WorkerDispatch(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.state = tempfile.TemporaryDirectory(prefix='worker-dispatch-')
        cls.fixture = type('WorkerFixture', (fixtures.HttpSecurity,), {})
        with patch.dict(os.environ, {'MAARKSHO_WORKER_STATE_DIR': cls.state.name}):
            cls.fixture.setUpClass()

    @classmethod
    def tearDownClass(cls):
        cls.fixture.tearDownClass()
        cls.state.cleanup()

    def setUp(self):
        self.case = self.fixture('runTest')
        self.case.setUp()

    def test_background_trigger_releases_request_without_executing_sync_jobs(self):
        opener, token = self.case.login()
        for path in ['/panel/cron.php?background=1', '/cron.php?background=1']:
            with self.subTest(path=path):
                code, body = self.case.request(opener, path, {}, {'X-CSRF-Token': token})
                self.assertEqual(code, 200)
                self.assertEqual(json.loads(body)['status'], 'cron_required')
        self.assertFalse((Path(self.state.name) / 'status.json').exists(), 'Non-FPM trigger must not start synchronous work')

    def test_dispatch_keeps_authentication_csrf_and_method_guards(self):
        opener, token = self.case.login('limited')
        self.assertEqual(self.case.request(opener, '/panel/cron.php?background=1', {}, {'X-CSRF-Token': token})[0], 403)
        opener, token = self.case.login()
        self.assertEqual(self.case.request(opener, '/panel/cron.php?background=1')[0], 405)
        self.assertEqual(self.case.request(opener, '/panel/cron.php?background=1', {})[0], 403)

    def test_worker_lock_is_shared_between_cli_and_browser_dispatch(self):
        if os.name != 'posix':
            self.skipTest('Lock fixture uses POSIX flock')
        import fcntl
        opener, token = self.case.login()
        with (Path(self.state.name) / 'worker.lock').open('ab') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            code, body = self.case.request(opener, '/panel/cron.php?background=1', {}, {'X-CSRF-Token': token})
            self.assertEqual(code, 202)
            self.assertEqual(json.loads(body)['status'], 'busy')
        self.assertFalse((Path(self.state.name) / 'status.json').exists())

    def test_fastcgi_acknowledgement_precedes_work_and_keeps_lease_until_exit(self):
        with tempfile.TemporaryDirectory(prefix='worker-fastcgi-') as state:
            script = '''require $argv[1]; require $argv[2];
                function fastcgi_finish_request() { file_put_contents(getenv('MAARKSHO_WORKER_STATE_DIR').'/ack', '1'); }
                $_GET['background']='1'; BackgroundWorker::begin(true);
                $path=getenv('MAARKSHO_WORKER_STATE_DIR');
                if(!file_exists($path.'/ack'))throw new RuntimeException('Work preceded acknowledgement');
                $f=fopen($path.'/worker.lock','c');
                if(flock($f,LOCK_EX|LOCK_NB))throw new RuntimeException('Lease released before work');
                fclose($f); file_put_contents($path.'/worked','1');'''
            result = subprocess.run([fixtures.PHP, '-r', script,
                str(fixtures.ROOT / 'panel/inc/AccessControl.php'), str(fixtures.ROOT / 'panel/inc/BackgroundWorker.php')],
                env=dict(os.environ, MAARKSHO_WORKER_STATE_DIR=state), capture_output=True, text=True, check=True)
            self.assertEqual(json.loads(result.stdout)['status'], 'accepted')
            self.assertTrue((Path(state) / 'worked').exists())
            final = json.loads((Path(state) / 'status.json').read_text())
            self.assertIsNotNone(final['finished_at'])
            self.assertFalse(final['failed'])

    def test_browser_dispatch_coalesces_requests_and_backs_off_after_failure(self):
        node = shutil.which('node')
        if not node:
            self.skipTest('Node is required for the isolated browser dispatcher test')
        script = r'''
            const fs=require('fs'),vm=require('vm'),assert=require('assert');
            let now=1000,calls=0,pendingResolve;
            const context={window:{},document:{hidden:false},AbortController,
                Date:{now:()=>now},setTimeout:()=>1,clearTimeout:()=>{},
                fetch:()=>{calls++;return new Promise(r=>pendingResolve=r)}};
            vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),context);
            const ping=context.window.workerHeartbeat;
            (async()=>{
                let a=ping(),b=ping();assert.strictEqual(a,b);assert.strictEqual(calls,1);
                pendingResolve({ok:true,json:async()=>({status:'accepted'})});
                assert.strictEqual((await a).status,'accepted');await ping();assert.strictEqual(calls,1);
                now+=60001;context.fetch=()=>{calls++;return Promise.reject(new Error('timeout'))};
                assert.strictEqual((await ping()).status,'unavailable');assert.strictEqual(calls,2);
                await ping();assert.strictEqual(calls,2);now+=30001;
                await ping();assert.strictEqual(calls,3);now+=30001;
                await ping();assert.strictEqual(calls,3);now+=30000;
                context.fetch=async()=>{calls++;return {ok:true,json:async()=>({status:'busy'})}};
                assert.strictEqual((await ping()).status,'busy');assert.strictEqual(calls,4);
                context.document.hidden=true;now+=120000;await ping();assert.strictEqual(calls,4);
            })().catch(e=>{console.error(e);process.exitCode=1});
        '''
        subprocess.run([node, '-e', script, str(fixtures.ROOT / 'panel/assets/worker-heartbeat.js')], check=True, capture_output=True, text=True)


if __name__ == '__main__':
    unittest.main()
