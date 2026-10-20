"""Verify temporary Tiam status failures retry automatically in the browser."""
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
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context();context.route('**/*',route);page=context.new_page()
        page.on('pageerror',lambda e:errors.append(str(e)));dialogs=[]
        page.on('dialog',lambda d:(dialogs.append(d.message),d.accept()))
        def login(name):
            page.goto(base+'/');page.evaluate('openLoginModal();switchLoginTab("admin")')
            page.fill('[name=user]',name);page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state()
        login('fixture_owner');page.goto(base+'/panel/admin.php?switch_project=p')
        retries=[]
        def temporary_busy(route):
            body=route.request.post_data_json
            if body.get('action') in ['latest','status']:
                retries.append(body['action'])
                if len(retries)==1:return route.fulfill(status=503,content_type='text/html',body='<html>Fixture busy</html>')
            return route.continue_()
        page.route('**/panel/tiam_inventory.php',temporary_busy)
        page.goto(base+'/panel/admin.php?tab=settings')
        page.wait_for_function("document.getElementById('tiam-inventory-log')?.textContent.includes('۵ ثانیه')")
        page.wait_for_function("!document.getElementById('tiam-inventory-log')?.textContent.includes('۵ ثانیه')",timeout=15000)
        assert len(retries)>=2,retries
        assert not errors,errors
        # Separate session avoids inheriting global-owner identity.
        context.close();context=browser.new_context();context.route('**/*',route);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        login('viewer');page.goto(base+'/panel/portal.php?tab=library')
        assert page.locator('#portal-readonly-notice').is_visible()
        assert page.locator('[data-portal-action="approve"]:visible').count()==0
        assert not errors,errors
        browser.close()
    print(json.dumps({'tiam_status_retry':True,'readonly_portal':True,'page_errors':errors}))
finally:HttpSecurity.tearDownClass()
