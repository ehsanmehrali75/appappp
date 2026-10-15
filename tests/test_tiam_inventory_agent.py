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
