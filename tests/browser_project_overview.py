"""Verify actionable project overview and scoped queue drill-down."""
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
    case.production_fixture()
    with sqlite3.connect(case.database) as db:
        db.execute('UPDATE products SET stock_total=10 WHERE id="one"')
        db.execute('UPDATE products SET stock_total=0 WHERE id="zero"')
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        page=browser.new_page(viewport={'width':1440,'height':1000});page.route('**/*',route);page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/',wait_until='domcontentloaded');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state('domcontentloaded')
        page.goto(base+'/panel/admin.php?tab=dashboard&project=p',wait_until='domcontentloaded');page.locator('#project-overview').wait_for()
        assert page.locator('[data-overview="in_stock"]').is_visible()
        for width in (1440,390):
            page.set_viewport_size({'width':width,'height':1000});page.wait_for_timeout(350)
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),str(width)
            page.screenshot(path=f'/tmp/project-overview-{width}.png',full_page=True)
        work=int(page.locator('[data-overview="work"]').inner_text().replace(',',''))
        page.locator('[data-overview="work"]').click();page.wait_for_load_state('domcontentloaded')
        assert 'wc_stock=in_stock' in page.url
        assert page.locator('.website-catalog-row').count()==work
        assert not errors,errors
        browser.close();print('Project overview desktop/mobile, real queue drill-down and no JS errors passed')
finally:
    case.tearDown();HttpSecurity.tearDownClass()
