"""Verify canonical login, sectioned settings and complete image galleries."""
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
        page.goto(base+'/panel/')
        assert page.url.endswith('/?action=login'),page.url
        assert page.locator('#login-modal').is_visible()
        login('fixture_owner');page.goto(base+'/panel/');assert page.url.endswith('/panel/admin.php')
        page.goto(base+'/panel/admin.php?switch_project=p')
        page.goto(base+'/panel/admin.php?tab=settings')
        page.locator('.settings-nav').wait_for()
        assert page.locator('.settings-nav button').count()>=8
        assert page.locator('[role=tabpanel]:visible').count()==1
        form_count=page.locator('#project-settings-form input').count()
        page.get_by_role('tab',name='تأیید و تولید تصویر',exact=True).click()
        assert page.locator('#prod-approval-order').is_visible()
        assert not page.get_by_text('مینی‌اپ اجراکننده و هماهنگ‌ساز بومی پایتون (Desktop Scheduler Helper)',exact=True).is_visible()
        assert page.locator('[role=tabpanel] [role=tabpanel]').count()==0
        page.locator('#prod-approval-order').select_option('style_first')
        page.locator('[name=prod_approval_split_enabled]').check()
        page.get_by_role('tab',name='زمان‌بندی و مهلت‌ها',exact=True).click()
        page.locator('[name=approval_timeout_image]').fill('90')
        assert page.locator('#project-settings-form input').count()==form_count
        assert page.evaluate("new FormData(document.getElementById('project-settings-form')).get('prod_approval_order')")=='style_first'
        page.set_viewport_size({'width':390,'height':844})
        assert page.locator('.settings-nav').is_visible()
        assert page.evaluate('document.documentElement.scrollWidth<=window.innerWidth'), 'settings mobile overflow'
        page.set_viewport_size({'width':1440,'height':1000})
        page.get_by_role('tab',name='تأیید و تولید تصویر',exact=True).click()
        page.screenshot(path='/tmp/settings-redesign.png',full_page=False)
        with page.expect_navigation():page.locator('button[name=save_settings]').click()
        with sqlite3.connect(case.database) as db:
            settings=json.loads(db.execute('SELECT settings FROM projects WHERE id="p"').fetchone()[0]);assert settings['prod_approval_order']=='style_first';assert settings['approval_timeout_image']==90
            db.execute('UPDATE products SET gallery=?,images_list=?,social_image=?,reference_gallery=? WHERE id="one"',(json.dumps([{'url':'uploads/gallery.png'}]),json.dumps([{'url':'uploads/list.png','approved':True,'source':'manual'}]),'uploads/social.png',json.dumps(['uploads/ref.png'])))
        for path in (case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        for filename in ['gallery.png','list.png','social.png','ref.png']:(case.app/'panel/uploads'/filename).write_bytes((case.app/'panel/uploads/original.png').read_bytes())
        page.goto(base+'/panel/portal.php?tab=library')
        card=page.locator('.portal-product-card[data-product-id="one"]')
        track=card.locator('.product-media-track');assert track.locator('img').count()==5
        assert track.locator('img').first.get_attribute('src').endswith('uploads/original.png')
        card.scroll_into_view_if_needed();page.wait_for_timeout(500)
        card.locator('[data-media-step="1"]').click();page.wait_for_timeout(700);assert abs(track.evaluate('(el)=>el.scrollLeft'))>0,track.evaluate('(el)=>({left:el.scrollLeft,width:el.clientWidth,scroll:el.scrollWidth,direction:getComputedStyle(el).direction,ready:el.parentElement.dataset.ready,errors:window.testErrors})')
        page.evaluate("openDetailById('one')")
        assert page.locator('#pd-carousel-wrapper img').count()==5
        with sqlite3.connect(case.database) as db:db.execute('UPDATE products SET status="pending",is_manual_image=1,extracted_at=strftime("%s","now"),images_list=? WHERE id="one"',(json.dumps([{'url':'uploads/list.png','approved':False,'source':'manual'}]),))
        for path in (case.app/'panel/db/cache').glob('*.cache'):path.unlink()
        page.goto(base+'/panel/admin.php?tab=products&filter=pending_approval')
        admin_card=page.locator('.product-item').filter(has=page.locator('.product-media-track img[src$="original.png"]')).first
        assert admin_card.locator('.product-media-track img').count()==5
        assert admin_card.locator('[data-approval-deadline]').count()==1
        admin_card.locator('[data-media-step="1"]').click()
        page.wait_for_timeout(700)
        assert abs(admin_card.locator('.product-media-track').evaluate('(el)=>el.scrollLeft'))>0
        page.evaluate("showManualInfo({id:'one',name:'fixture',image:'uploads/original.png',gallery:[{url:'uploads/gallery.png'}],images_list:[{url:'uploads/list.png'}],social_image:'uploads/social.png',reference_gallery:['uploads/ref.png']})")
        assert page.locator('#m-carousel-wrapper img').count()==5
        assert not errors,errors
        # Separate session avoids inheriting global-owner identity.
        context.close();context=browser.new_context();context.route('**/*',route);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        login('viewer');page.goto(base+'/panel/');assert page.url.endswith('/panel/portal.php')
        page.goto(base+'/panel/portal.php?tab=library')
        assert page.locator('#portal-readonly-notice').is_visible()
        assert page.locator('[data-portal-action="approve"]:visible').count()==0
        assert not errors,errors
        browser.close()
    print(json.dumps({'settings_and_media':True,'readonly_portal':True,'page_errors':errors}))
finally:HttpSecurity.tearDownClass()
