"""Optional Chromium interaction regression: install playwright and set CHROMIUM_PATH if needed.
Uses the same disposable synthetic fixture as test_http_security.py.
"""
import json, os, sqlite3, sys, tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright
sys.path.insert(0,str(Path(__file__).resolve().parent))
from test_http_security import HttpSecurity
HttpSecurity.setUpClass()
case=HttpSecurity();case.setUp();case.production_fixture()
base=case.base
out=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='maarksho-browser-')));out.mkdir(parents=True,exist_ok=True)
checks=[];errors=[]
def check(value, message):
    assert value, message
    checks.append(message)
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,body='fixture worker disabled')
    if r.request.url.startswith(base):return r.continue_()
    return r.fulfill(status=200, content_type='image/svg+xml' if r.request.resource_type=='image' else 'text/plain', body='<svg xmlns="http://www.w3.org/2000/svg"/>' if r.request.resource_type=='image' else '')
try:
 with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
    context=browser.new_context(viewport={'width':1440,'height':1000},device_scale_factor=1)
    context.route('**/*',route)
    page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)));page.on('dialog',lambda d:d.accept())
    page.goto(base+'/');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state()
    page.goto(base+'/panel/admin.php?switch_project=p');page.wait_for_load_state()
    page.goto(base+'/panel/admin.php?tab=extract&wc_queue=image_request');page.wait_for_load_state()
    page.wait_for_selector('.website-catalog-row[data-product-id="one"]')
    row=page.locator('.website-catalog-row[data-product-id="one"]');row.locator('.wc-compare>summary').click()
    with page.expect_navigation():row.locator('.website-catalog-request-image').click()
    check(not page.locator('.website-catalog-row[data-product-id="one"]').count(),'request removes item from request queue')
    check(bool(case.record()['content_gen_requested']),'browser request persisted')
    page.goto(base+'/panel/admin.php?tab=website_export&we_view=work&wc_queue=needs_reference');page.wait_for_load_state()
    row=page.locator('.website-catalog-row[data-product-id="one"]');row.locator('.wc-compare>summary').click();row.locator('.website-catalog-reference').click()
    page.wait_for_selector('#referencePhotoFastModal',state='visible')
    page.locator('#ref-fast-file-input').set_input_files(str(case.app/'panel/uploads/original.png'))
    with page.expect_navigation():page.locator('#ref-fast-save-btn').click()
    check(not page.locator('.website-catalog-row[data-product-id="one"]').count(),'actual reference upload advances item from reference queue')
    check(bool(json.loads(case.record()['reference_gallery'])),'reference file metadata persisted')
    page.goto(base+'/panel/admin.php?tab=extract&wc_queue=pending_image');page.wait_for_load_state()
    check(page.locator('.website-catalog-row[data-product-id="one"]').count()==1,'ready production visible after reference upload')
    for width in [1440,768,375,320]:
      page.set_viewport_size({'width':width,'height':950})
      for tab in ['overview','library','pending','out-of-stock','no-image']:
        page.goto(base+f'/panel/portal.php?project=p&tab={tab}');page.wait_for_load_state()
        check(page.locator('.portal-header').count()==1 and page.locator('#portal-sidebar').count()==1,f'{width} {tab} shared shell')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),f'{width} {tab} no horizontal overflow')
        if tab=='library':page.screenshot(path=str(out/f'library-final-{width}.png'),full_page=True)
    page.goto(base+'/panel/portal.php?project=p&tab=library');page.wait_for_load_state()
    page.locator('#portal-menu-toggle').click();check(page.locator('#portal-menu-toggle').get_attribute('aria-expanded')=='true','mobile drawer opens')
    page.locator('#portal-nav-search').fill('کتابخانه___');check(not page.locator('#portal-nav-search-status').is_hidden(),'menu search has feedback')
    page.locator('#portal-nav-search').fill('');page.keyboard.press('Escape');check(page.locator('#portal-menu-toggle').get_attribute('aria-expanded')=='false','Escape closes drawer')
    page.locator('.portal-account>summary').click();page.locator('.portal-account button').click();check(page.locator('#profile-modal').is_visible(),'profile accessible from account menu')
    page.evaluate("document.getElementById('profile-modal').style.display='none'")
    # Both prerequisites are persisted by the real settings form, independently per project.
    page.goto(base+'/panel/admin.php?tab=settings');page.wait_for_load_state()
    page.locator('[name=prod_require_reference]').uncheck();page.locator('[name=prod_require_image_request]').check()
    form=page.locator('[name=prod_require_reference]').locator('xpath=ancestor::form')
    # submit() avoids unrelated legacy required controls; preserve the form submit marker explicitly.
    action=form.locator('[name=save_project_settings],[name=save_settings]')
    marker=action.first.get_attribute('name') if action.count() else 'save_project_settings'
    with page.expect_navigation():
      form.evaluate('(form,marker)=>{const h=document.createElement("input");h.name=marker;h.value="1";form.append(h);form.submit()}',marker)
    with sqlite3.connect(case.database) as db:settings=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0])
    check(not settings['prod_require_reference'] and settings['prod_require_image_request'],'real project settings persist independent prerequisites')
    check(not errors,'no JavaScript errors: '+repr(errors))
    browser.close()
 print(json.dumps({'checks':len(checks),'passed':checks,'javascript_errors':errors},ensure_ascii=False,indent=2))
finally:HttpSecurity.tearDownClass()
