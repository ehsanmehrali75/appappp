"""Disposable browser check of the account/employee/central pages; no live API calls."""
import json,os,shutil,subprocess,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base
artifacts=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='saas-ui-')));artifacts.mkdir(parents=True,exist_ok=True);errors=[]
try:
    setup="""require $argv[1].'/panel/inc/ai/Bootstrap.php';SessionIdentity::beginGlobal('fixture_owner');$svc=new SaaSAccounts();$m=['username'=>'limited','password'=>password_hash('Fixture-password-123!',PASSWORD_DEFAULT),'role'=>'project_admin','permissions'=>[]];$a=$svc->provision(['order_id'=>'browser-fixture','paid_at'=>time()],$m,['id'=>'plan_pro','name'=>'Browser test plan','max_members'=>20,'max_projects'=>2,'storage_mb'=>100,'nahal_projects'=>1,'ehsan_projects'=>1],'p',time()+86400);$svc->change(function(&$s)use($a){$s['accounts'][$a['id']]['wallets']['p']['balance']=100;});require $argv[1].'/panel/inc/saas/Providers.php';(new SaaSProviders($svc))->save(['id'=>'ehsan-test','provider'=>'openai','model'=>'fixture-analyst','key'=>'fixture-not-a-real-key-12345','enabled'=>true,'ehsan_enabled'=>true,'credits'=>5]);"""
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
        paths=['/panel/account.php','/panel/saas-admin.php','/panel/employees.php?project=p','/panel/workspace.php','/panel/connections.php?project=p','/panel/ehsan.php?project=p','/panel/my-employees.php?project=p']
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
        page.goto(base+'/panel/ehsan.php?project=p');form=page.locator('form').filter(has=page.locator('[name=enabled]'));form.locator('[name=enabled]').check()
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('فعال‌سازی احسان ثبت شد.',exact=True).is_visible()
        form=page.locator('form').filter(has=page.locator('[name=goal]'));form.locator('[name=goal]').fill('افزایش فروش با بررسی موجودی و داده‌های واقعی');form.locator('[name=budget]').fill('20')
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('هدف، بودجه و زمان‌بندی احسان ثبت شد.',exact=True).is_visible()
        form=page.locator('form').filter(has=page.locator('[name=question]'));form.locator('[name=question]').fill('کدام محصولات نیاز به بررسی دارند؟')
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('درخواست در صف قرار گرفت؛ کردیت رزرو شد.',exact=True).is_visible()
        worker="""require $argv[1].'/panel/inc/ai/Bootstrap.php';require $argv[1].'/panel/inc/saas/Ehsan.php';$report=['summary'=>'گزارش آزمون جداشده','findings'=>[['title'=>'بررسی محصولات','evidence'=>'products fixture source','source_ids'=>['products'],'severity'=>'info']],'opportunities'=>[],'limitations'=>['اتصال زنده تست نشده است'],'actions'=>[['title'=>'بررسی موجودی','description'=>'پیشنهاد آزمون','priority'=>'medium']]];(new Ehsan(null,fn()=>['status'=>200,'body'=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]]))->runOne();"""
        result=subprocess.run([os.environ['PHP_BINARY'],'-r',worker,str(case.app)],capture_output=True,text=True);assert result.returncode==0,result.stderr
        page.reload();assert page.get_by_text('گزارش آزمون جداشده',exact=True).is_visible()
        with page.expect_navigation():page.get_by_role('button',name='تأیید و ساخت تسک',exact=True).click()
        assert page.get_by_text('تصمیم پیشنهاد ثبت شد.',exact=True).is_visible()
        assert not errors,errors;browser.close()
    print(json.dumps({'pages':7,'analyst_configured':True,'analyst_queued':True,'viewports':[1440,375],'activation':True,'js_errors':errors,'artifacts':str(artifacts)}))
finally:HttpSecurity.tearDownClass()
