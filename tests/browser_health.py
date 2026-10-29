"""Health dashboard uses recorded fixture evidence; never performs a live service probe."""
import json,os,shutil,subprocess,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();artifacts=Path(tempfile.mkdtemp(prefix='health-ui-'));errors=[]
try:
    setup="""require $argv[1].'/panel/inc/ai/Bootstrap.php';require $argv[1].'/panel/inc/OperationalHealth.php';SessionIdentity::beginGlobal('fixture_owner');$svc=new SaaSAccounts();$member=['username'=>'limited','password'=>password_hash('Fixture-password-123!',PASSWORD_DEFAULT),'role'=>'project_admin','permissions'=>[]];$svc->provision(['order_id'=>'browser-health-fixture','paid_at'=>time()],$member,['id'=>'plan_pro','name'=>'Health fixture','storage_mb'=>100,'max_members'=>20],'p',time()+86400);$health=new OperationalHealth($svc);$run=$health->begin('aria');$health->finish('aria',$run,true);"""
    r=subprocess.run([os.environ['PHP_BINARY'],'-r',setup,str(case.app)],capture_output=True,text=True);assert r.returncode==0,r.stderr
    opener,_=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context()
        def route(r):
            if r.request.url.startswith(case.base):
                asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
                if asset.is_file() and asset.suffix in ['.css','.js','.woff2','.svg','.png']:return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
                return r.continue_()
            return r.abort()
        ctx.route('**/*',route);ctx.add_cookies([{'name':c.name,'value':c.value,'url':case.base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':1000})
            for name,path in [('central','/panel/health.php?central=1'),('project','/panel/health.php?project=p')]:
                response=page.goto(case.base+path);assert response.status==200,page.inner_text('body')
                assert 'سلامت سیستم و صف‌ها' in page.inner_text('body')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(name,width)
                assert 'Fatal error' not in page.inner_text('body');page.screenshot(path=str(artifacts/f'{name}-{width}.png'),full_page=True)
        assert not errors,errors;browser.close()
    print(json.dumps({'pages':2,'viewports':[1440,375],'js_errors':errors,'artifacts':str(artifacts)}))
finally:HttpSecurity.tearDownClass()
