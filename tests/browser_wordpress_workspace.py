"""Verify the unified WordPress workflow with fixture-only API responses."""
import json, os, shutil, sqlite3, mimetypes
from pathlib import Path
from urllib.parse import urlparse, unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass()
case=HttpSecurity();case.setUp();base=case.base;errors=[]
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,body='{}')
    if r.request.url.startswith(base):
        path=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if path.is_file() and path.suffix in ['.css','.js','.svg','.png','.woff','.woff2']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(path))[0] or 'application/octet-stream',body=path.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,content_type='image/svg+xml' if r.request.resource_type=='image' else 'text/plain',body='<svg xmlns="http://www.w3.org/2000/svg"/>' if r.request.resource_type=='image' else '')
try:
    api=case.app/'panel/inc/WordPressWorkspace.php';original=api.read_text()
    fake="""if(strpos($url,'/test')!==false)return ['status'=>200,'body'=>['status'=>'success','version'=>'6.0.0','woocommerce_active'=>true,'write_enabled'=>true,'capabilities'=>['products','posts','pages','taxonomies']]];
    if(strpos($url,'export?')!==false){parse_str(parse_url($url,PHP_URL_QUERY),$query);$kind=$query['kind'];$row=['id'=>'101','name'=>'WP fixture','title'=>'WP fixture','sku'=>'WP-101','url'=>'https://shop.fixture/item','status'=>'publish','stock'=>4,'price'=>12,'currency'=>'IRR','images'=>['https://shop.fixture/image.webp'],'description'=>'Content','content_html'=>'<p>Content</p>','modified_at'=>'2020-01-01T00:00:00+00:00'];if($kind==='taxonomies')$row+=['taxonomy'=>'product_cat','term_id'=>101];if($kind==='sales')$row+=['date'=>'2026-10-22','grain'=>'order','gross'=>100,'refund'=>0,'net'=>100,'orders'=>1];return ['status'=>200,'body'=>['status'=>'success','kind'=>$kind,'page'=>(int)$query['page'],'next_page'=>null,'rows'=>[$row]]];}return ['status'=>200,'body'=>['status'=>'success','modified_at'=>'2026-10-22T10:00:00+00:00','updated'=>true]];"""
    api.write_text(original.replace('public static function http($url,$body,$headers){','public static function http($url,$body,$headers){'+fake,1))
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        page=browser.new_page(viewport={'width':1440,'height':1000});page.route('**/*',route);page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/',wait_until='domcontentloaded');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state('domcontentloaded');page.goto(base+'/panel/admin.php?switch_project=p',wait_until='domcontentloaded')
        page.goto(base+'/panel/wp-crm.php?project=p',wait_until='domcontentloaded')
        page.locator('[name=site_url]').fill('https://shop.fixture');page.locator('button[value=save]').click();page.wait_for_load_state('domcontentloaded')
        assert page.locator('[aria-label="توکن API"]').input_value()
        page.locator('button[value=test]').click();page.wait_for_load_state('domcontentloaded');assert 'خواندن API تأیید شد' in page.content()
        page.locator('#wp-pull-all').click();page.wait_for_function("document.getElementById('wp-pull-progress').textContent.includes('دریافت کامل شد')")
        page.goto(base+'/panel/wp-crm.php?project=p&view=content&kind=posts',wait_until='domcontentloaded')
        assert page.locator('.wp-item').count()==1
        page.locator('.wp-item summary').click();page.locator('[name=title]').fill('Updated article');page.locator('button[value=preview]').click();page.wait_for_load_state('domcontentloaded')
        assert 'پیش‌نمایش تغییرات' in page.content();page.locator('button[value=confirm]').click();page.wait_for_load_state('domcontentloaded')
        page.goto(base+'/panel/wp-crm.php?project=p&view=jobs',wait_until='domcontentloaded');assert 'در صف' in page.content();page.locator('button[value=publish]').click();page.wait_for_load_state('domcontentloaded');assert 'پاسخ موفق سایت ثبت شد' in page.content()
        page.goto(base+'/panel/wp-crm.php?project=p&view=seo&stale=30',wait_until='domcontentloaded');assert 'بیش از ۳۰ روز' in page.content();assert 'داده ندارد' in page.content()
        for width in (1440,390):
            page.set_viewport_size({'width':width,'height':1000})
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),str(width)
            page.screenshot(path=f'/tmp/wordpress-workspace-{width}.png',full_page=True)
        page.goto(base+'/panel/admin.php?project=p&tab=extract',wait_until='domcontentloaded');assert '/wp-crm.php?' in page.url
        page.goto(base+'/panel/onboarding.php?project=p&step=inventory',wait_until='domcontentloaded');assert not page.locator('nav a[href*="step=crawl"]').count();assert 'کراول دیگری لازم نیست' in page.content()
        assert not errors,errors
        browser.close();print('WordPress connect, paginated pull, preview/confirm, acknowledgement, stale SEO, mobile and no crawl passed')
finally:
    case.tearDown();HttpSecurity.tearDownClass()
