"""Real HTTP integration on disposable fixtures; provider/payment calls are fake only."""
import base64
import json
import os
import re
import sqlite3
import subprocess
import unittest
import urllib.parse
import urllib.request
import test_http_security as fixture
PNG = fixture.PNG
PHP = fixture.PHP

class NoRedirect(urllib.request.HTTPRedirectHandler):
    handler_order = 100
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class AiConnection(unittest.TestCase):
    PHP_SETTINGS = ()
    setUpClass = classmethod(fixture.HttpSecurity.setUpClass.__func__)
    tearDownClass = classmethod(fixture.HttpSecurity.tearDownClass.__func__)
    request = fixture.HttpSecurity.request
    login = fixture.HttpSecurity.login
    record = fixture.HttpSecurity.record
    production_fixture = fixture.HttpSecurity.production_fixture

    def setUp(self):
        fixture.HttpSecurity.setUp(self)
        with sqlite3.connect(self.database) as db:
            db.execute('UPDATE project_users SET permissions=?, role="manager" WHERE username="limited"', (json.dumps(['products']),))
            db.execute('DELETE FROM projects WHERE id="other"')
            db.execute('INSERT INTO projects(id,name,active_from,active_to,settings) VALUES (?,?,?,?,?)', ('other', 'Private other', '2026-01-01', '2030-01-01', json.dumps(self.settings)))
            db.execute('DELETE FROM project_users WHERE project_id="other"')
        ai_dir=self.app/'panel/db/ai-private'
        if ai_dir.exists():
            import shutil
            shutil.rmtree(ai_dir)

    def post(self, opener, view='connect', **fields):
        return self.request(opener, '/panel/ai-connect.php?project=p&view='+view, fields)

    def connect(self, username='fixture_owner', scopes='project.read content.write reports.write datasets.write', public=True):
        opener, csrf=self.login(username)
        code,page=self.post(opener,app_csrf=csrf,action='client_create',name='Fixture ChatGPT',redirects='https://client.example/callback',client_type='public' if public else 'confidential')
        self.assertEqual(code,200,page[:200])
        m=re.search(r'Client ID<code>([a-f0-9]{32})',page)
        self.assertIsNotNone(m,page[-1000:])
        cid=m[1];secret=re.search(r'Client Secret.*?<code>([a-f0-9]{64})',page)[1]
        verifier='v'*64
        import hashlib
        challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=')
        args=dict(action='authorize',client_id=cid,response_type='code',redirect_uri='https://client.example/callback',state='state-fixture-123',scope=scopes)
        if public:args.update(code_challenge=challenge,code_challenge_method='S256')
        code,page=self.request(opener,'/panel/ai_oauth.php?'+urllib.parse.urlencode(args))
        self.assertEqual(code,200,page)
        nonce=re.search(r'name="consent_id" value="([a-f0-9]+)',page)[1]
        cookies=next(h for h in opener.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
        opener=urllib.request.build_opener(NoRedirect(),cookies)
        req=urllib.request.Request(self.base+'/panel/ai_oauth.php?action=authorize',data=urllib.parse.urlencode(dict(app_csrf=csrf,consent_id=nonce,project='p')).encode())
        try:result=opener.open(req)
        except urllib.error.HTTPError as e:result=e
        self.assertEqual(result.code,302,result.read().decode())
        location=result.headers['Location'];authcode=urllib.parse.parse_qs(urllib.parse.urlparse(location).query)['code'][0]
        tokenargs=dict(grant_type='authorization_code',client_id=cid,code=authcode,redirect_uri='https://client.example/callback')
        if public:tokenargs['code_verifier']=verifier
        else:tokenargs['client_secret']=secret
        status,body=self.request(opener,'/panel/ai_oauth.php?action=token',tokenargs)
        self.assertEqual(status,200,body)
        return opener,csrf,json.loads(body),cid,secret,tokenargs

    def call(self, token, name, args=None):
        return self.request(urllib.request.build_opener(),'/panel/ai_mcp.php',headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'},raw=json.dumps({'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':name,'arguments':args or {}}}).encode())

    def test_page_csrf_private_files_and_no_cookie_api(self):
        opener,csrf=self.login()
        code,page=self.request(opener,'/panel/ai-connect.php?project=p')
        self.assertEqual(code,200);self.assertIn('اتصال و گزارش هوشمند',page)
        self.assertEqual(self.post(opener,action='client_create',name='unsafe')[0],403)
        self.assertEqual(self.request(opener,'/panel/ai_mcp.php')[0],401)
        self.assertEqual(self.request(opener,'/panel/db/ai-private/state.json')[0],404)
        code,schema=self.request(opener,'/panel/ai_api.php?schema=openapi')
        self.assertEqual(code,200);self.assertIn('/panel/ai_api.php/save_content',json.loads(schema)['paths'])
        for path in ['/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource/panel/ai_mcp.php']:
            code,body=self.request(opener,path);self.assertEqual(code,200,body);json.loads(body)

    def test_oauth_rotation_one_use_and_project_scope(self):
        opener,csrf,t,cid,secret,args=self.connect()
        code,body=self.request(opener,'/panel/ai_oauth.php?action=token',args);self.assertEqual(code,400)
        code,body=self.call(t['access_token'],'project_context');self.assertEqual(code,200,body)
        result=json.loads(json.loads(body)['result']['content'][0]['text']);self.assertEqual(result['id'],'p')
        self.assertNotIn('synthetic-sql-secret',body)
        code,body=self.call(t['access_token'],'read_data',{'source':'products','project_id':'other'});self.assertTrue(json.loads(body)['result']['isError'])
        fields=dict(grant_type='refresh_token',client_id=cid,refresh_token=t['refresh_token'])
        code,body=self.request(opener,'/panel/ai_oauth.php?action=token',fields);self.assertEqual(code,200,body)
        self.assertNotEqual(json.loads(body)['refresh_token'],t['refresh_token'])
        self.assertEqual(self.request(opener,'/panel/ai_oauth.php?action=token',fields)[0],400)
        state=json.loads((self.app/'panel/db/ai-private/state.json').read_text())
        self.assertNotIn(t['access_token'],json.dumps(state));self.assertNotIn(secret,json.dumps(state))
        gid=next(iter(state['grants']))
        self.post(opener,app_csrf=csrf,action='revoke',id=gid)
        self.assertEqual(self.call(t['access_token'],'project_context')[0],401)

    def test_independent_users_connections_and_actions_route(self):
        owner,csrf_owner,t_owner,*_=self.connect()
        limited,csrf_limited,t_limited,*_=self.connect('limited',scopes='project.read')
        state=json.loads((self.app/'panel/db/ai-private/state.json').read_text())
        grants=list(state['grants'].values());self.assertNotEqual(grants[0]['owner'],grants[1]['owner'])
        code,page=self.post(limited,app_csrf=csrf_limited,action='revoke',id=grants[0]['id'])
        self.assertIn('دسترسی اتصال مجاز نیست.',page)
        code,body=self.call(t_owner['access_token'],'project_context');self.assertEqual(code,200,body)
        code,body=self.request(urllib.request.build_opener(),'/panel/ai_api.php/read_data',headers={'Authorization':'Bearer '+t_limited['access_token'],'Content-Type':'application/json'},raw=b'{"source":"products","limit":1}')
        self.assertEqual(code,200,body);self.assertEqual(json.loads(body)['result']['project_id'],'p')
        self.assertTrue(json.loads(body)['result']['rows'][0]['local_images'][0].startswith('https://maarksho.com/'))

    def test_readonly_scope_and_live_permission_revocation(self):
        opener,csrf,t,*_=self.connect('limited',scopes='project.read')
        code,body=self.call(t['access_token'],'workflow_counts');self.assertFalse(json.loads(body)['result']['isError'],body)
        code,body=self.call(t['access_token'],'save_content',{'request_id':'fixture-request','product_id':'one','kind':'description','text':'new'});self.assertTrue(json.loads(body)['result']['isError'])
        with sqlite3.connect(self.database) as db:db.execute('UPDATE project_users SET permissions=?, role="manager" WHERE username="limited"', ('[]',))
        self.assertEqual(self.call(t['access_token'],'project_context')[0],401)

    def test_feature_use_audience_blocks_write_without_blocking_read(self):
        opener,csrf,t,*_=self.connect('limited',scopes='project.read content.write')
        policy=self.app/'panel/db/access_policy.json'
        policy.write_text(json.dumps({'ai_connect':{'use':{'roles':['main_admin'],'users':[],'projects':[]}}}))
        try:
            code,body=self.call(t['access_token'],'project_context');self.assertEqual(code,200,body);self.assertFalse(json.loads(body)['result']['isError'],body)
            code,body=self.call(t['access_token'],'save_content',{'request_id':'section-use-change','product_id':'one','kind':'description','text':'blocked'})
            self.assertEqual(code,200,body);self.assertTrue(json.loads(body)['result']['isError'],body)
        finally:policy.unlink()

    def test_connection_use_restriction_blocks_existing_write_token(self):
        opener,csrf,t,*_=self.connect('limited',scopes='project.read content.write')
        with sqlite3.connect(self.database) as db:db.execute('UPDATE project_users SET role="viewer" WHERE username="limited"')
        code,body=self.call(t['access_token'],'save_content',{'request_id':'limited-role-change','product_id':'one','kind':'description','text':'blocked'})
        self.assertEqual(code,200,body);self.assertTrue(json.loads(body)['result']['isError'],body)
        self.assertEqual(self.record()['description'],'')

    def test_confidential_refresh_needs_secret(self):
        opener,csrf,t,cid,secret,_=self.connect(public=False)
        fields=dict(grant_type='refresh_token',client_id=cid,refresh_token=t['refresh_token'])
        self.assertEqual(self.request(opener,'/panel/ai_oauth.php?action=token',fields)[0],400)
        self.assertEqual(self.request(opener,'/panel/ai_oauth.php?action=token',fields|{'client_secret':secret})[0],200)

    def test_content_preserves_original_and_requires_approval_idempotently(self):
        opener,csrf,t,*_=self.connect()
        args=dict(request_id='fixture-main-image',product_id='one',kind='main',base64=base64.b64encode(PNG).decode())
        code,body=self.call(t['access_token'],'save_content',args);r=json.loads(body)['result'];self.assertFalse(r['isError'],body)
        result=json.loads(r['content'][0]['text']);self.assertTrue(result['approval_required'])
        product=self.record();images=json.loads(product['images_list']);self.assertTrue(any(i['url']=='uploads/original.png' for i in images));self.assertFalse(images[-1]['approved'])
        self.assertEqual(product['status'],'pending')
        code,body=self.call(t['access_token'],'save_content',args);self.assertFalse(json.loads(body)['result']['isError'],body)
        self.assertEqual(len(json.loads(self.record()['images_list'])),len(images))
        code,body=self.call(t['access_token'],'save_content',args|{'kind':'description','text':'different'});self.assertTrue(json.loads(body)['result']['isError'])

    def test_datasets_report_authorization_and_cross_project_rejection(self):
        opener,csrf,t,*_=self.connect()
        args=dict(kind='custom',name='Sales fixture',origin='synthetic',unit='IRR',date_field='date',rows=[{'date':'2026-10-19','sales':20}])
        code,body=self.call(t['access_token'],'save_dataset',args);self.assertFalse(json.loads(body)['result']['isError'],body);dataset=json.loads(json.loads(body)['result']['content'][0]['text'])['id']
        code,body=self.call(t['access_token'],'read_data',{'source':'dataset:'+dataset});self.assertFalse(json.loads(body)['result']['isError'],body)
        self.assertIn('20',body)
        code,body=self.call(t['access_token'],'save_report',dict(request_id='fixture-report',title='گزارش آزمایش',text='فروش ۲۰',sources=['dataset:'+dataset]));self.assertFalse(json.loads(body)['result']['isError'],body)
        code,page=self.request(opener,'/panel/ai-connect.php?project=p&view=reports');self.assertEqual(code,200);self.assertIn('گزارش آزمایش',page)
        statefile=self.app/'panel/db/ai-private/state.json';s=json.loads(statefile.read_text());s['datasets'][dataset]['project']='other';statefile.write_text(json.dumps(s))
        code,body=self.call(t['access_token'],'read_data',{'source':'dataset:'+dataset});self.assertTrue(json.loads(body)['result']['isError'])

    def test_api_reports_billing_and_scheduler_with_fake_services(self):
        self.connect()
        result=subprocess.run([PHP,str(fixture.ROOT/'tests/ai_reports_fixture.php'),str(self.app)],capture_output=True,text=True,timeout=20)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(json.loads(result.stdout)['api_charge'],140)

    def test_insights_workspaces_are_project_scoped_and_no_fake_metrics(self):
        opener,csrf=self.login()
        for domain in ['sales','seo','intelligence']:
            for section in ['overview','reports','integrations','recommendations','tools']:
                code,page=self.request(opener,f'/panel/insights.php?project=p&domain={domain}&section={section}')
                self.assertEqual(code,200,(domain,section,page[:100]));self.assertIn('insight-app',page);self.assertNotIn('synthetic-sql-secret',page)
        limited,_=self.login('limited')
        self.assertEqual(self.request(limited,'/panel/insights.php?project=p&domain=sales')[0],403)
        self.assertEqual(self.request(limited,'/panel/insights.php?project=other&domain=sales')[0],403)
        self.assertEqual(self.request(opener,'/panel/insights.php?project=p&domain=sales',{'action':'test','platform':'telegram'})[0],403)

    def test_wordpress_plugin_contract(self):
        result=subprocess.run([PHP,str(fixture.ROOT/'tests/wordpress_bridge_fixture.php'),str(self.app)],capture_output=True,text=True,timeout=20)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertTrue(json.loads(result.stdout)['variations'])

    def test_warehouse_social_calendar_and_google_adapters(self):
        self.connect()
        result=subprocess.run([PHP,str(fixture.ROOT/'tests/hub_social_fixture.php'),str(self.app)],capture_output=True,text=True,timeout=30)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertTrue(json.loads(result.stdout)['calendar'])

    def test_data_hub_page_and_ingestion_bearer_are_project_scoped(self):
        opener,csrf,t,*_=self.connect()
        fields=[('app_csrf',csrf),('action','source'),('name','HTTP ERP source'),('origin','fixture'),('store_id','branch-1'),('kinds[]','inventory')]
        code,page=self.request(opener,'/panel/data-hub.php?project=p',raw=urllib.parse.urlencode(fields).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
        self.assertEqual(code,200,page);match=re.search(r'<code>([a-f0-9]{64})</code>',page);self.assertIsNotNone(match,page[-500:]);token=match[1]
        payload=dict(kind='inventory',batch_id='http-fixture-123',rows=[dict(id='sku-1',sku='SKU1',stock=4)])
        def ingest(body,auth=token):return self.request(urllib.request.build_opener(),'/panel/integration_ingest.php',headers={'Authorization':'Bearer '+auth,'Content-Type':'application/json'},raw=json.dumps(body).encode())
        code,body=ingest(payload);self.assertEqual(code,200,body);self.assertEqual(json.loads(body)['total'],1)
        code,body=ingest(payload);self.assertEqual(json.loads(body)['total'],1)
        self.assertEqual(ingest(payload|{'project_id':'other'})[0],422)
        self.assertEqual(ingest(payload,'f'*64)[0],401)
        self.assertEqual(self.request(opener,'/panel/data-hub.php?project=p',{'action':'source'})[0],403)

    def test_all_tabs_and_shared_source_acl(self):
        opener,csrf=self.login()
        fields=dict(app_csrf=csrf,action='provider',name='Synthetic personal key',model='fixture-model',key='sk-synthetic-fixture-key-12345',max_output='500',daily_tokens='200000')
        raw=urllib.parse.urlencode(list(fields.items())+[('projects[]','p'),('users[]','limited')]).encode()
        code,page=self.request(opener,'/panel/ai-connect.php?project=p&view=providers',raw=raw,headers={'Content-Type':'application/x-www-form-urlencoded'})
        self.assertEqual(code,200);self.assertIn('ذخیره شد',page);self.assertNotIn(fields['key'],page)
        for tab in ['connect','reports','providers','data','wallet']:
            code,page=self.request(opener,'/panel/ai-connect.php?project=p&view='+tab);self.assertEqual(code,200);self.assertNotIn('Application failure',page)
        limited,_=self.login('limited');editor,_=self.login('editor')
        self.assertIn('Synthetic personal key',self.request(limited,'/panel/ai-connect.php?project=p&view=providers')[1])
        self.assertNotIn('Synthetic personal key',self.request(editor,'/panel/ai-connect.php?project=p&view=providers')[1])

if __name__=='__main__':unittest.main()
