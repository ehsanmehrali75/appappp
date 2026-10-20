import contextlib
import io
import json
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
    result = {'success': True, 'rows': [{'Code': 'A', 'Qty': 3}], 'columns': ['Code', 'Qty'], 'logs': []}

    def test_result_transport_failure_retries_identical_payload_without_sql(self):
        with patch.object(agent, 'request', side_effect=[urllib.error.URLError('private transport detail'), {'status': 'received', 'state': 'preview'}]) as request, patch.object(agent.time, 'sleep') as sleep, patch.object(agent, 'execute_job') as execute:
            reply = agent.submit_result('https://example.test', 'p1', 'a'*64, 'job1', self.result)
        self.assertEqual(reply['state'], 'preview')
        self.assertEqual(request.call_count, 2)
        self.assertEqual(request.call_args_list[0], request.call_args_list[1])
        sleep.assert_called_once_with(2)
        execute.assert_not_called()

    def test_result_retries_server_errors_but_not_cancelled_job(self):
        for code, attempts in [(503, 3), (429, 3), (400, 1), (401, 1)]:
            with self.subTest(code=code), patch.object(agent, 'request', side_effect=urllib.error.HTTPError('https://example.test', code, 'private response', None, None)) as request, patch.object(agent.time, 'sleep'), self.assertRaises(urllib.error.HTTPError):
                agent.submit_result('https://example.test', 'p1', 'a'*64, 'job1', self.result)
            self.assertEqual(request.call_count, attempts)

    def test_unsent_result_survives_poll_loop_without_querying_again(self):
        output = io.StringIO()
        responses = [{'status': 'job', 'job': 'job1'}, *[urllib.error.URLError('private response') for _ in range(3)], {'status': 'received', 'state': 'preview'}]
        with patch.dict(agent.os.environ, {'TIAM_PANEL_URL': 'https://example.test/tiam_inventory.php', 'TIAM_PROJECT_ID': 'p1', 'TIAM_AGENT_TOKEN': 'a'*64}), patch.object(sys, 'argv', ['agent']), patch.object(agent, 'request', side_effect=responses) as request, patch.object(agent, 'execute_job', return_value=self.result) as execute, patch.object(agent.time, 'sleep', side_effect=[None, None, None, KeyboardInterrupt]), contextlib.redirect_stdout(output), self.assertRaises(KeyboardInterrupt):
            agent.main()
        execute.assert_called_once()
        self.assertEqual([call.args[3] for call in request.call_args_list], ['agent_poll', *['agent_result']*4])
        self.assertIn('Result delivery pending', output.getvalue())
        self.assertIn('job finished: ok', output.getvalue())
        self.assertNotIn('private response', output.getvalue())
        self.assertNotIn('a'*64, output.getvalue())

    def test_invalid_panel_json_never_replaces_sql_result_with_error(self):
        with patch.object(agent, 'request', side_effect=[agent.InvalidPanelResponse('invalid JSON'), {'status': 'received', 'state': 'preview'}]) as request, patch.object(agent.time, 'sleep'):
            agent.submit_result('https://example.test', 'p1', 'a'*64, 'job1', self.result)
        self.assertEqual(request.call_args_list[1].kwargs['rows'], self.result['rows'])
        self.assertTrue(request.call_args_list[1].kwargs['success'])

    def test_oversized_local_payload_is_uploaded_in_parts(self):
        replies=[agent.PayloadError('too large'),{'status':'chunk_received','index':0},{'status':'received','state':'preview'}]
        with patch.object(agent,'request',side_effect=replies) as request:
            agent.submit_result('https://example.test','p1','a'*64,'job1',self.result)
        self.assertEqual(request.call_args_list[1].args[3],'agent_result_chunk')
        self.assertEqual(json.loads(request.call_args_list[1].kwargs['data']),self.result['rows'])
        self.assertTrue(request.call_args_list[2].kwargs['success']);self.assertTrue(request.call_args_list[2].kwargs['uploaded'])
        self.assertNotIn('rows',request.call_args_list[2].kwargs)

    def test_unrepresentable_result_reports_failure_without_truncating(self):
        with patch.object(agent,'request',side_effect=[agent.PayloadError('large'),{'status':'received','state':'error'}]) as request,patch.object(agent,'upload_result',side_effect=agent.PayloadError('large')):
            agent.submit_result('https://example.test','p1','a'*64,'job1',self.result)
        self.assertFalse(request.call_args_list[1].kwargs['success'])

    def test_host_413_uses_parts_and_terminal_retry_is_acknowledged(self):
        with patch.object(agent,'request',side_effect=[urllib.error.HTTPError('u',413,'large',{},None),{'status':'received','state':'preview'}]) as request:
            reply=agent.submit_result('https://example.test','p1','a'*64,'job1',self.result)
        self.assertEqual(reply['state'],'preview');self.assertEqual(request.call_args_list[1].args[3],'agent_result_chunk')

    def test_rejected_result_reports_failure_so_job_does_not_stay_running(self):
        error=urllib.error.HTTPError('https://example.test',400,'private server detail',None,None)
        error.tiam_code='TIAM_PANEL_UPDATE_REQUIRED';error.tiam_action='agent_result_chunk'
        responses=[{'status':'job','job':'j1','kind':'preview','config':{}},{'status':'received','state':'error'}]
        output=io.StringIO()
        with patch.object(sys,'argv',['agent','--once']),patch.object(agent,'request',side_effect=responses) as request,patch.object(agent,'execute_job',return_value=self.result),patch.object(agent,'submit_result',side_effect=error),contextlib.redirect_stdout(output):
            agent.main(configuration={'TIAM_PANEL_URL':'https://example.test','TIAM_PROJECT_ID':'p','TIAM_AGENT_TOKEN':'a'*64})
        self.assertEqual(request.call_args_list[-1].args[3],'agent_result')
        self.assertFalse(request.call_args_list[-1].kwargs['success'])
        self.assertIn('TIAM_PANEL_UPDATE_REQUIRED',output.getvalue());self.assertNotIn('private server detail',output.getvalue())

    def test_idle_poll_uses_five_second_interval(self):
        with patch.object(sys,'argv',['agent']),patch.object(agent,'request',return_value={'status':'idle'}),patch.object(agent.time,'sleep',side_effect=KeyboardInterrupt) as sleep,self.assertRaises(KeyboardInterrupt):
            agent.main(configuration={'TIAM_PANEL_URL':'https://example.test','TIAM_PROJECT_ID':'p','TIAM_AGENT_TOKEN':'a'*64},log=lambda *a,**k:None)
        sleep.assert_called_once_with(5)

    def test_old_panel_rejection_has_safe_actionable_code(self):
        body=json.dumps({'error':'عملیات ناشناخته است.','code':'TIAM_ERROR'}).encode()
        error=urllib.error.HTTPError('https://example.test',400,'private detail',{},io.BytesIO(body))
        with patch.object(agent.urllib.request,'build_opener') as build:
            build.return_value.open.side_effect=error
            with self.assertRaises(urllib.error.HTTPError) as caught:agent.request('https://example.test','p','a'*64,'agent_result_chunk')
        self.assertEqual(caught.exception.tiam_code,'TIAM_PANEL_UPDATE_REQUIRED')
        self.assertEqual(caught.exception.tiam_action,'agent_result_chunk')

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
            __slots__ = ()  # Real pyodbc Cursor rejects a timeout attribute.
            description = [('Code',), ('Qty',)]
            def execute(self, query):
                calls['query'] = query
            def __iter__(self):
                return iter([('A', 2), ('B', 0)])
        class Connection:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def cursor(self):
                calls['query_timeout'] = self.timeout
                return Cursor()
        def connect(dsn, **kwargs):
            calls['dsn'] = dsn
            return Connection()
        cfg = {'host': '192.0.2.1', 'database': 'Test', 'view': 'Stock', 'username': 'reader', 'password': 'dummy-secret'}
        with patch.dict(sys.modules, {'pyodbc': types.SimpleNamespace(connect=connect)}):
            columns, rows = agent.read_view(cfg, True)
        self.assertEqual(calls['query'], 'SELECT TOP (5) * FROM [dbo].[Stock]')
        self.assertEqual(calls['query_timeout'], 60)
        self.assertIn('Encrypt={yes}', calls['dsn'])
        self.assertIn('TrustServerCertificate={no}', calls['dsn'])
        self.assertEqual(rows[1]['Qty'], 0)
        self.assertEqual(columns, ['Code', 'Qty'])
        cfg['sql_security']='compatibility'
        cfg['certificate_hostname']='unused.example'
        with patch.dict(sys.modules, {'pyodbc': types.SimpleNamespace(connect=connect)}):
            agent.read_view(cfg,True)
        self.assertIn('Encrypt={no}',calls['dsn'])
        self.assertIn('TrustServerCertificate={yes}',calls['dsn'])
        self.assertNotIn('HostNameInCertificate',calls['dsn'])
        cfg['sql_security']='invalid'
        with patch.dict(sys.modules, {'pyodbc': types.SimpleNamespace(connect=connect)}), self.assertRaises(ValueError):
            agent.read_view(cfg,True)


    def test_linux_service_names_are_restricted(self):
        if agent.os.name == 'nt': self.skipTest('Linux only')
        with self.assertRaises(ValueError):
            agent.connect_vpn({'vpn_enabled': True, 'vpn_service': 'arbitrary-service'}, 'p1')

    def test_desktop_configuration_and_graceful_stop(self):
        import threading
        event=threading.Event()
        config={'TIAM_PANEL_URL':'https://example.test/panel/tiam_inventory.php','TIAM_PROJECT_ID':'p1','TIAM_AGENT_TOKEN':'a'*64}
        def poll(*args,**kwargs):
            event.set()
            return {'status':'idle'}
        with patch.object(agent,'request',side_effect=poll) as request,patch.object(agent.time,'sleep') as sleep:
            agent.main([],configuration=config,stop_event=event,log=lambda *args,**kwargs:None)
        self.assertEqual(request.call_count,1)
        sleep.assert_not_called()

    def test_stop_finishes_claimed_result_delivery(self):
        import threading
        event=threading.Event()
        config={'TIAM_PANEL_URL':'https://example.test/panel/tiam_inventory.php','TIAM_PROJECT_ID':'p1','TIAM_AGENT_TOKEN':'a'*64}
        def poll(*args,**kwargs):
            event.set()
            return {'status':'job','job':'job1'}
        with patch.object(agent,'request',side_effect=poll),patch.object(agent,'execute_job',return_value=self.result),patch.object(agent,'submit_result',return_value={'state':'preview'}) as submit:
            agent.main([],configuration=config,stop_event=event,log=lambda *args,**kwargs:None)
        submit.assert_called_once()

    def test_sql_diagnostics_classify_without_echoing_driver_credentials(self):
        for message, category in [
            ('SSL Provider: The certificate chain was issued by an authority that is not trusted (-2146893019)', 'certificate_untrusted'),
            ("Invalid value specified for connection string attribute 'Encrypt'", 'invalid_connection_attribute'),
            ('SSL Provider: no common algorithm', 'tls_handshake'),
            ('Login failed for user private-login (18456)', 'login_failed'),
            ('Data source name not found IM002', 'driver_missing'),
            ('TCP Provider: connection forcibly closed 10054', 'connection_reset'),
        ]:
            logs=[]
            with self.assertRaises(Exception):
                agent.stage(logs,'sql_view',lambda: (_ for _ in ()).throw(Exception('08001',message+' PWD=private-secret;UID=private-login;')),'ok')
            self.assertEqual(logs[0]['diagnostic']['category'],category)
            self.assertNotIn('private-secret',str(logs))
            self.assertNotIn('private-login',str(logs))
            self.assertNotIn('PWD=',str(logs))

    def test_non_sql_exception_arguments_are_not_printed_as_codes(self):
        logs=[]
        with self.assertRaises(ValueError):
            agent.stage(logs,'test',lambda: (_ for _ in ()).throw(ValueError('SECRET_PASSWORD_123')),'ok')
        self.assertNotIn('SECRET_PASSWORD_123',str(logs))

    def test_agent_sends_json_token_and_compatible_header(self):
        import json
        for token in ['a'*64, 'کلیدتست'*8]:
            response=io.BytesIO(b'{"status":"idle"}')
            with patch.object(agent.urllib.request,'build_opener') as opener:
                opener.return_value.open.return_value=response
                self.assertEqual(agent.request('https://example.test','p1',token,'agent_poll')['status'],'idle')
                req=opener.return_value.open.call_args.args[0]
                self.assertEqual(json.loads(req.data)['agent_token'],token)
                if token.isascii():self.assertEqual(req.get_header('X-tiam-token'),token)
                else:self.assertIsNone(req.get_header('X-tiam-token'))


if __name__ == '__main__':
    unittest.main()
