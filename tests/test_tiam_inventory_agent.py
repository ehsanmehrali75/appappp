import contextlib
import io
import urllib.error
import importlib.util
import pathlib
import sys
import types
import unittest
from unittest.mock import patch

path = pathlib.Path(__file__).resolve().parents[1] / 'panel' / 'tiam_inventory_agent.py'
spec = importlib.util.spec_from_file_location('agent', path)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class AgentTests(unittest.TestCase):
    def test_idle_connection_is_visible_without_secrets(self):
        output = io.StringIO()
        with patch.dict(agent.os.environ, {'TIAM_PANEL_URL':'https://example.test/tiam_inventory.php','TIAM_PROJECT_ID':'p1','TIAM_AGENT_TOKEN':'a'*64}), patch.object(sys,'argv',['agent','--once']), patch.object(agent,'request',return_value={'status':'idle'}), contextlib.redirect_stdout(output):
            agent.main()
        self.assertIn('panel connected; waiting',output.getvalue())
        self.assertNotIn('a'*64,output.getvalue())

    def test_http_error_has_status_without_server_body(self):
        output=io.StringIO()
        error=urllib.error.HTTPError('https://example.test',401,'private driver detail',None,None)
        with patch.dict(agent.os.environ, {'TIAM_PANEL_URL':'https://example.test/tiam_inventory.php','TIAM_PROJECT_ID':'p1','TIAM_AGENT_TOKEN':'a'*64}), patch.object(sys,'argv',['agent','--once']), patch.object(agent,'request',side_effect=error), contextlib.redirect_stdout(output):
            agent.main()
        self.assertIn('HTTP 401',output.getvalue())
        self.assertNotIn('private driver detail',output.getvalue())

    def test_existing_network_reaches_sql_without_vpn_commands(self):
        cfg={'host':'192.0.2.1','database':'Test','view':'Stock','username':'reader','password':'sql-secret','vpn_enabled':False,'vpn_secret':'old-vpn-secret'}
        with patch.object(agent,'connect_vpn') as vpn, patch.object(agent.subprocess,'run') as commands, patch.object(agent.socket,'create_connection'), patch.object(agent,'read_view',return_value=(['Code'],[{'Code':'A'}])) as read:
            result=agent.execute_job({'kind':'view_test','config':cfg},'p1')
        self.assertTrue(result['success'])
        vpn.assert_not_called()
        commands.assert_not_called()
        read.assert_called_once_with(cfg,True)
        self.assertEqual(result['logs'][1]['status'],'skipped')
        self.assertNotIn('old-vpn-secret',str(result))

    def test_direct_connection_never_invokes_windows_vpn_tools(self):
        with patch.object(agent.os,'name','nt'), patch.object(agent.subprocess,'run') as commands:
            agent.connect_vpn({'vpn_enabled':False},'p1')
        commands.assert_not_called()

    def test_identifiers_and_dsn_escape(self):
        self.assertEqual(agent.identifier('Factinventory'), '[Factinventory]')
        self.assertEqual(agent.dsn_value('a};PWD=x'), '{a}};PWD=x}')
        with self.assertRaises(ValueError):
            agent.identifier('view; DELETE FROM products')

    def test_tcp_failure_never_returns_rows(self):
        job = {'kind': 'preview', 'config': {'host': '192.0.2.1', 'database': 'Test', 'view': 'Stock', 'username': 'reader', 'password': 'dummy-secret'}}
        with patch.object(agent.socket, 'create_connection', side_effect=TimeoutError):
            result = agent.execute_job(job, 'p1')
        self.assertFalse(result['success'])
        self.assertEqual(result['rows'], [])
        self.assertEqual(result['logs'][-1]['stage'], 'network')
        self.assertNotIn('dummy-secret', str(result))

    def test_exception_messages_are_not_logged(self):
        logs = []
        with self.assertRaises(RuntimeError):
            agent.stage(logs, 'test', lambda: (_ for _ in ()).throw(RuntimeError('sensitive password')), 'ok')
        self.assertNotIn('sensitive password', str(logs))

    def test_sql_is_readonly_and_encrypted(self):
        calls = {}
        class Cursor:
            description = [('Code',), ('Qty',)]
            def execute(self, query):
                calls['query'] = query
            def __iter__(self):
                return iter([('A', 2), ('B', 0)])
        class Connection:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def cursor(self): return Cursor()
        def connect(dsn, **kwargs):
            calls['dsn'] = dsn
            return Connection()
        cfg = {'host': '192.0.2.1', 'database': 'Test', 'view': 'Stock', 'username': 'reader', 'password': 'dummy-secret'}
        with patch.dict(sys.modules, {'pyodbc': types.SimpleNamespace(connect=connect)}):
            columns, rows = agent.read_view(cfg, True)
        self.assertEqual(calls['query'], 'SELECT TOP (5) * FROM [dbo].[Stock]')
        self.assertIn('Encrypt={yes}', calls['dsn'])
        self.assertIn('TrustServerCertificate={no}', calls['dsn'])
        self.assertEqual(rows[1]['Qty'], 0)
        self.assertEqual(columns, ['Code', 'Qty'])

    def test_linux_service_names_are_restricted(self):
        if agent.os.name == 'nt': self.skipTest('Linux only')
        with self.assertRaises(ValueError):
            agent.connect_vpn({'vpn_enabled': True, 'vpn_service': 'arbitrary-service'}, 'p1')


if __name__ == '__main__':
    unittest.main()
