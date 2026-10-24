"""Disposable browser check of the account/employee/central pages; no live API calls."""
import json,os,shutil,subprocess,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base
artifacts=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='saas-ui-')));artifacts.mkdir(parents=True,exist_ok=True);errors=[]
try:
    setup="""require $argv[1].'/panel/inc/ai/Bootstrap.php';SessionIdentity::beginGlobal('fixture_owner');$svc=new SaaSAccounts();$m=['username'=>'limited','password'=>password_hash('Fixture-password-123!',PASSWORD_DEFAULT),'role'=>'project_admin','permissions'=>[]];$a=$svc->provision(['order_id'=>'browser-fixture','paid_at'=>time()],$m,['id'=>'plan_pro','name'=>'Browser test plan','max_members'=>20,'max_projects'=>2,'storage_mb'=>100,'nahal_projects'=>1],'p',time()+86400);"""
    result=subprocess.run([os.environ['PHP_BINARY'],'-r',setup,str(case.app)],capture_output=True,text=True);assert result.returncode==0,result.stderr
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    def route(r):
        if r.request.url.startswith(base):
            asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
            if asset.is_file() and asset.suffix in ['.js','.css','.woff2','.png','.svg']:
                return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
            return r.continue_()
        return r.fulfill(status=200,body='')
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context();ctx.route('**/*',route)
        ctx.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        paths=['/panel/account.php','/panel/saas-admin.php','/panel/employees.php?project=p','/panel/workspace.php','/panel/connections.php?project=p']
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':1000})
            for i,path in enumerate(paths):
                response=page.goto(base+path);page.wait_for_load_state();assert response.status==200,(path,response.status,page.inner_text('body'))
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(path,width)
                assert 'Fatal error' not in page.inner_text('body');page.screenshot(path=str(artifacts/f'page-{i}-{width}.png'),full_page=True)
        page.goto(base+'/panel/employees.php?project=p');form=page.locator('form').filter(has=page.locator('[name=enabled]')).first
        form.locator('[name=enabled]').check()
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('وضعیت نهال ثبت شد.',exact=True).is_visible(),page.inner_text('body')
        assert not errors,errors;browser.close()
    print(json.dumps({'pages':5,'viewports':[1440,375],'activation':True,'js_errors':errors,'artifacts':str(artifacts)}))
finally:HttpSecurity.tearDownClass()
