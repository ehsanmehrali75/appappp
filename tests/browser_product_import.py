"""Exercise large ERP import and read-only portal in a disposable Chromium fixture."""
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
        page.goto(base+'/panel/admin.php?tab=extract&ex_view=central')
        rows=[dict(sku='ERP-'+str(i),name='محصول '+str(i),color='سبز',stock_tedad=7) for i in range(1000)]
        page.evaluate("rows => {window.testImport=importERPInBatches('p', rows, 'standard');}",rows)
        page.locator('.iv-dialog').wait_for(timeout=60000)
        with sqlite3.connect(case.database) as db:assert db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0]==5
        assert page.locator('.iv-dialog .iv-summary').is_visible()
        with page.expect_event("dialog",timeout=60000):
            page.locator('.iv-dialog button',has_text='تأیید و اعمال').click()
        page.wait_for_timeout(500)
        assert any('همگام‌سازی کامل شد' in d for d in dialogs),dialogs
        with sqlite3.connect(case.database) as db:assert db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0]==1005
        page.wait_for_load_state()
        page.goto(base+'/panel/admin.php?tab=extract&ex_view=central')
        page.locator('.iv-undo').wait_for(timeout=10000)
        page.locator('.iv-undo').click()
        page.get_by_text('بازگردانی انجام شد.',exact=False).wait_for(timeout=30000)
        with sqlite3.connect(case.database) as db:assert db.execute('SELECT count(*) FROM products WHERE project_id="p"').fetchone()[0]==5
        page.set_viewport_size({'width':390,'height':844})
        assert page.locator('[data-inventory-versions]').is_visible()
        assert not errors,errors
        # Separate session avoids inheriting global-owner identity.
        context.close();context=browser.new_context();context.route('**/*',route);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        login('viewer');page.goto(base+'/panel/portal.php?tab=library')
        assert page.locator('#portal-readonly-notice').is_visible()
        assert page.locator('[data-portal-action="approve"]:visible').count()==0
        assert not errors,errors
        browser.close()
    print(json.dumps({'large_import_rows':1000,'rollback_rows':1000,'readonly_portal':True,'page_errors':errors}))
finally:HttpSecurity.tearDownClass()
