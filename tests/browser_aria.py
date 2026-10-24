"""Browser flow against an isolated site. SMS send/live providers are never invoked."""
import json,os,shutil,subprocess,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from datetime import date,timedelta
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base;errors=[]
artifacts=Path(tempfile.mkdtemp(prefix='aria-ui-'))
try:
    setup="""require $argv[1].'/panel/inc/ai/Bootstrap.php';require $argv[1].'/panel/inc/saas/Providers.php';SessionIdentity::beginGlobal('fixture_owner');$svc=new SaaSAccounts();$m=['username'=>'limited','password'=>password_hash('Fixture-password-123!',PASSWORD_DEFAULT),'role'=>'project_admin','permissions'=>[]];$a=$svc->provision(['order_id'=>'browser-fixture','paid_at'=>time()],$m,['id'=>'plan_pro','name'=>'Browser fixture','max_members'=>20,'aria_projects'=>1],'p',time()+86400);$svc->change(function(&$s)use($a){$s['accounts'][$a['id']]['wallets']['p']['balance']=100;});(new SaaSProviders($svc))->save(['id'=>'planner','provider'=>'openai','model'=>'fixture-model','key'=>'fixture-not-a-real-key-12345','enabled'=>true,'aria_enabled'=>true,'credits'=>5,'max_output_tokens'=>4096]);"""
    out=subprocess.run([os.environ['PHP_BINARY'],'-r',setup,str(case.app)],capture_output=True,text=True);assert out.returncode==0,out.stderr
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context()
        def route(r):
            if r.request.url.startswith(base):
                asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
                if asset.is_file() and asset.suffix in ['.css','.js','.png','.svg','.woff2']:return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
                return r.continue_()
            return r.fulfill(status=200,body='')
        ctx.route('**/*',route);ctx.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/panel/aria.php?project=p')
        form=page.locator('form').filter(has=page.locator('[value=activate]'));form.locator('[name=enabled]').check()
        with page.expect_navigation():form.locator('button').click()
        assert 'وضعیت آریا ثبت شد.' in page.inner_text('body')
        form=page.locator('form').filter(has=page.locator('[value=configure]'));form.locator('[name=goal]').fill('برنامهٔ محتوای واقعی بر اساس داده‌های پروژه');form.locator('[name=start]').fill((date.today()+timedelta(days=1)).isoformat());form.locator('[name=count]').fill('1');form.locator('[name="channels[]"][value=telegram]').check()
        with page.expect_navigation():form.get_by_role('button',name='ثبت تنظیمات آریا',exact=True).click()
        assert 'هدف، تیم، کانال‌ها و بودجهٔ آریا ثبت شد.' in page.inner_text('body')
        form=page.locator('form').filter(has=page.locator('[value=plan]'))
        with page.expect_navigation():form.locator('button').click()
        assert 'درخواست برنامه در صف قرار گرفت' in page.inner_text('body')
        worker="""require $argv[1].'/panel/inc/ai/Bootstrap.php';require $argv[1].'/panel/inc/saas/Aria.php';$report=['summary'=>'Browser calendar fixture','findings'=>[],'opportunities'=>[],'limitations'=>['No live provider used'],'actions'=>[['title'=>'Browser content','description'=>'Human writing brief','priority'=>'medium','channel'=>'telegram','publish_at'=>date('Y-m-d',strtotime('+1 day')).' 12:00','production_due'=>date('Y-m-d',strtotime('+1 day')).' 09:00','image_brief'=>'Image brief']]];(new Aria(null,fn()=>['status'=>200,'body'=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($report)]]]]]]))->runOne();"""
        out=subprocess.run([os.environ['PHP_BINARY'],'-r',worker,str(case.app)],capture_output=True,text=True);assert out.returncode==0,out.stderr
        page.reload()
        with page.expect_navigation():page.get_by_role('button',name='تأیید تقویم و ساخت تسک‌ها',exact=True).click()
        assert 'تصمیم تقویم ثبت شد' in page.inner_text('body')
        form=page.locator('form').filter(has=page.locator('[value=content]'));form.locator('xpath=ancestor::details').evaluate('(el)=>el.open=true');form.locator('[name=caption]').fill('متن انسانی برای بررسی؛ هیچ انتشار واقعی انجام نشده است.')
        with page.expect_navigation():form.locator('button').click()
        form=page.locator('form').filter(has=page.locator('[value=content_decision]'));form.locator('xpath=ancestor::details').evaluate('(el)=>el.open=true')
        with page.expect_navigation():form.get_by_role('button',name='تأیید همین نسخه برای انتشار',exact=True).click()
        assert 'تصمیم محتوای نهایی ثبت شد.' in page.inner_text('body')
        form=page.locator('form').filter(has=page.locator('[value=sms_save]')).filter(has=page.locator('[name=provider][value=melipayamak]'));form.locator('[name=username]').fill('fixture-user');form.locator('[name=sender]').fill('10001234');form.locator('[name=secret]').fill('fixture-only-not-real')
        with page.expect_navigation():form.locator('button').click()
        form=page.locator('form').filter(has=page.locator('[value=sms_preview]'));form.locator('[name=text]').fill('SMS fixture preview only');form.locator('[name=recipients]').fill('09123456789\n+989123456789')
        with page.expect_navigation():form.locator('button').click()
        assert '1 گیرندهٔ یکتا' in page.inner_text('body')
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':1000})
            for path,label in [('/panel/aria.php?project=p','aria'),('/panel/my-employees.php?project=p','employees'),('/panel/saas-admin.php','central'),('/panel/account.php','account')]:
                response=page.goto(base+path);assert response.status==200,(path,page.inner_text('body'))
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(path,width)
                page.screenshot(path=str(artifacts/f'{label}-{width}.png'),full_page=True)
        assert not errors,errors;browser.close()
    print(json.dumps({'pages':4,'widths':[1440,375],'calendar_approved':True,'content_approved':True,'sms_preview_only':True,'js_errors':errors,'artifacts':str(artifacts)}))
finally:HttpSecurity.tearDownClass()
