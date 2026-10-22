"""Verify persistent library management and responsive admin occasion dialogs."""
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
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        page=browser.new_page(viewport={'width':1440,'height':1000});page.route('**/*',route)
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/',wait_until='domcontentloaded');page.evaluate('openLoginModal();switchLoginTab("admin")')
        page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state('domcontentloaded')
        page.goto(base+'/panel/admin.php?switch_project=p',wait_until='domcontentloaded')
        page.goto(base+'/panel/admin.php?tab=products',wait_until='domcontentloaded')
        assert page.locator('.library-product-management button').count()>0
        page.locator('.library-product-management button').first.click()
        assert page.locator('#editModal').is_visible()
        page.goto(base+'/panel/admin.php?tab=occasions',wait_until='domcontentloaded')
        page.locator('#occasions-workspace').wait_for()
        for width in (1440,390):
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),str(width)
            page.screenshot(path=f'/tmp/occasions-page-{width}.png',full_page=False)
            page.get_by_role('button',name='➕ مناسبت سفارشی جدید',exact=True).click()
            assert page.locator('#add-occasion-modal').is_visible()
            assert page.locator('#add-occasion-modal>div').bounding_box()['height']<=900
            assert page.locator('#add-occasion-modal [name=title]').bounding_box()['x']>=0
            page.locator('#add-occasion-modal [name=title]').fill('تست مناسبت')
            page.screenshot(path=f'/tmp/occasions-{width}.png')
            page.locator('#add-occasion-modal').get_by_role('button',name='✕',exact=True).click()
        assert not errors,errors
        browser.close();print('Admin library edit and occasions desktop/mobile passed')
finally:
    case.tearDown();HttpSecurity.tearDownClass()
