"""Helpdesk project selection, private attachment, main-admin delivery/reply and destructive UI in fixtures."""
import json,os,re,shutil,sqlite3,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from unittest.mock import patch
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity

state=tempfile.TemporaryDirectory(prefix='browser-support-');private=Path(state.name)/'attachments'
Fixture=type('SupportBrowserFixture',(HttpSecurity,),{})
with patch.dict(os.environ,{'SUPPORT_STORAGE_DIR':str(private)}):Fixture.setUpClass()
case=Fixture();case.setUp();base=case.base;errors=[]
out=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='support-ui-')));out.mkdir(parents=True,exist_ok=True)
with sqlite3.connect(case.database) as db:
    db.execute('INSERT INTO projects(id,name,settings) VALUES ("other","Other project","{}")')
    db.execute('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES ("other","limited","Fixture-password-123!","manager",?)',(json.dumps(['products']),))
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,content_type='application/json',body='{}')
    if r.request.url.startswith(base):
        asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if asset.is_file() and asset.suffix in ['.css','.js','.woff2','.woff','.svg','.png']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,content_type='image/svg+xml' if r.request.resource_type=='image' else 'text/plain',body='<svg xmlns="http://www.w3.org/2000/svg"/>' if r.request.resource_type=='image' else '')
def login(browser,name):
    context=browser.new_context(viewport={'width':1440,'height':1000});context.route('**/*',route);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
    page.goto(base+'/');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]',name);page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state()
    return context,page
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        user_context,user=login(browser,'limited');user.goto(base+'/panel/support.php?project=p')
        compose=user.locator('#support-create');compose.locator('select[name=project]').select_option('other')
        compose.locator('[name=subject]').fill('Browser helpdesk delivery');compose.locator('[name=category]').select_option('bug');compose.locator('[name=body]').fill('گزارش و لاگ کامل آزمایشی برای مدیر')
        compose.locator('[name="attachments[]"]').set_input_files(str(case.app/'panel/uploads/original.png'))
        with user.expect_navigation():compose.locator('button').click()
        assert 'پروژهٔ درخواست را انتخاب کنید.' not in user.inner_text('body')
        with sqlite3.connect(case.database) as db:
            tid,project=db.execute('SELECT id,project_id FROM support_tickets WHERE subject="Browser helpdesk delivery"').fetchone();assert project=='other'
            attachment=json.loads(db.execute('SELECT attachments FROM support_messages WHERE ticket_id=?',(tid,)).fetchone()[0])[0]
        context,admin=login(browser,'fixture_owner');admin.goto(base+'/panel/support.php')
        assert admin.locator('#ticket-list').get_by_text('Browser helpdesk delivery',exact=True).count()==1
        admin.locator('#ticket-list').get_by_text('Browser helpdesk delivery',exact=True).click()
        assert 'گزارش و لاگ کامل آزمایشی برای مدیر' in admin.inner_text('body')
        link=admin.locator('a.attachment').first.get_attribute('href');response=context.request.get(base+link)
        assert response.status==200 and response.body()==(case.app/'panel/uploads/original.png').read_bytes()
        reply=admin.locator('form:has(input[name=action][value=reply])');reply.locator('textarea').fill('پاسخ ثبت‌شدهٔ ادمین اصلی')
        with admin.expect_navigation():reply.locator('button').click()
        user.reload();assert 'پاسخ ثبت‌شدهٔ ادمین اصلی' in user.inner_text('body')
        for width in [1440,375]:
            admin.set_viewport_size({'width':width,'height':1000});admin.goto(base+'/panel/support.php')
            assert admin.locator('#ticket-list').is_visible()
            admin.locator('.support-new-panel>summary').click()
            assert admin.locator('#support-create select[name=project]').is_visible()
            assert admin.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
            admin.screenshot(path=str(out/f'support-inbox-{width}.png'),full_page=True)
            user.set_viewport_size({'width':width,'height':1000});user.reload()
            assert user.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
            user.screenshot(path=str(out/f'support-thread-{width}.png'),full_page=True)
        admin.goto(base+'/panel/admin.php?tab=projects')
        delete=admin.locator('form:has(input[name=pid][value="other"]):has(button[name=delete_project])')
        delete.locator('xpath=..').locator('summary').click();delete.locator('[name=confirm_project_name]').fill('Other project')
        with admin.expect_navigation():delete.locator('button[name=delete_project]').click()
        with sqlite3.connect(case.database) as db:
            assert db.execute('SELECT count(*) FROM projects WHERE id="other"').fetchone()[0]==0
            assert db.execute('SELECT count(*) FROM projects WHERE id="p"').fetchone()[0]==1
        assert not (private/(attachment['id']+'.upload')).exists()
        assert not errors,errors
        browser.close()
    print(json.dumps(dict(project_selection=True,admin_inbox=True,private_attachment=True,admin_reply=True,complete_delete=True,viewports=[1440,375],javascript_errors=errors)))
finally:Fixture.tearDownClass();state.cleanup()
