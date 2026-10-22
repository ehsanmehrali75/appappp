"""Real guided setup, save/skip/resume, project scope and mobile interaction."""
import json,os,shutil,sqlite3,mimetypes
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base;errors=[]
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,body='{}')
    if r.request.url.startswith(base):
        path=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if path.is_file() and path.suffix in ['.css','.js','.png','.woff2','.woff','.svg']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(path))[0] or 'application/octet-stream',body=path.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,body='')
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        page=browser.new_page(viewport={'width':1440,'height':1000});page.route('**/*',route);page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/',wait_until='domcontentloaded');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state('domcontentloaded');page.goto(base+'/panel/workspace.php',wait_until='domcontentloaded');page.locator('button[value=p]').click();page.wait_for_load_state('domcontentloaded')
        page.goto(base+'/panel/admin.php?tab=settings',wait_until='domcontentloaded');page.get_by_role('link',name='شروع / ادامهٔ راه‌اندازی').click();page.wait_for_url('**/onboarding.php?project=p*',wait_until='domcontentloaded')
        assert page.locator('#project-setup-form').is_visible();assert page.locator('nav[aria-label="مراحل راه‌اندازی"] a').count()>=6
        page.fill('[name=project_name]','فروشگاه آزمایشی');page.fill('[name=website_url]','https://example.com');page.check('[name=platform_type][value=tiam]')
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='ذخیره و ادامه').click()
        assert 'step=inventory' in page.url;assert page.get_by_role('heading',name='اتصال تیام از کامپیوتر داخل شبکه').is_visible();assert page.get_by_role('link',name='دانلود ایجنت ویندوز EXE').count()==1
        assert page.locator('[name=inventory_method][value=manual]').is_checked()
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='ذخیره و ادامه').click()
        assert 'step=crawl' in page.url
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='افزودن اولین بررسی به صف').click()
        assert page.get_by_role('status').is_visible()
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='این مرحله بعداً').click()
        assert 'step=ai' in page.url;page.select_option('[name=ai_mode]','none')
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='ذخیره و ادامه').click()
        assert 'step=social' in page.url;page.check('[name="social_channels[]"][value=telegram]')
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='ذخیره و ادامه').click()
        if 'step=seo' in page.url:
            with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='این مرحله بعداً').click()
        assert 'step=workflow' in page.url;page.check('[name=prod_require_reference]');page.check('[name=prod_approval_split_enabled]');page.select_option('[name=prod_approval_order]','style_first')
        page.screenshot(path='/tmp/onboarding-desktop.png',full_page=True)
        page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth');page.screenshot(path='/tmp/onboarding-mobile.png',full_page=True)
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='ذخیره و ادامه').click()
        assert 'step=review' in page.url
        with sqlite3.connect(case.database) as db:
            s=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0]);assert s['platform_type']=='tiam';assert s['onboarding']['steps']['crawl']['state']=='skipped';assert s['prod_approval_order']=='style_first';assert s['onboarding_social_channels']==['telegram'];assert db.execute('SELECT count(*) FROM scraping_jobs').fetchone()[0]==1
        with page.expect_navigation(wait_until='domcontentloaded'):page.get_by_role('button',name='شروع کار با پروژه').click()
        assert 'admin.php' in page.url
        page.goto(base+'/panel/admin.php?tab=settings',wait_until='domcontentloaded');page.get_by_role('link',name='شروع / ادامهٔ راه‌اندازی').click();assert 'onboarding.php' in page.url
        page.locator('nav[aria-label="مراحل راه‌اندازی"] a[href*="step=identity"]').click();assert page.locator('[name=website_url]').input_value()=='https://example.com'
        page.goto(base+'/panel/admin.php?tab=settings#tiam-inventory-panel',wait_until='domcontentloaded');assert page.locator('#tiam-inventory-panel').is_visible()
        assert not errors,errors;browser.close()
    print(json.dumps({'setup_save_skip_resume':True,'tiam_profile':True,'queue':True,'mobile':True,'javascript_errors':errors}))
finally:
    case.log.seek(0);open('/tmp/onboarding-browser-server.log','wb').write(case.log.read())
    HttpSecurity.tearDownClass()
