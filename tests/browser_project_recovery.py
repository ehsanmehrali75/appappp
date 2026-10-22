"""Two real browser tabs share one login; stale forms and AJAX must fail closed."""
import json,sqlite3,shutil
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp()
try:
    with sqlite3.connect(case.database) as db:db.execute('INSERT INTO projects(id,name,settings) VALUES (?,?,?)',('q','پروژه دوم','{}'))
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),args=['--no-sandbox']);context=browser.new_context();a=context.new_page();b=context.new_page()
        def local(route):
            if not route.request.url.startswith(case.base):return route.fulfill(status=200,body='')
            if '/cron.php' in route.request.url:return route.fulfill(status=200,body='{}')
            return route.continue_()
        context.route('**/*',local)
        a.goto(case.base+'/',wait_until='domcontentloaded');a.evaluate('openLoginModal();switchLoginTab("admin")');a.fill('[name=user]','fixture_owner');a.fill('[name=pass]','Fixture-password-123!');a.click('button[name=login]');a.wait_for_url('**/workspace.php',wait_until='domcontentloaded');a.locator('button[value=p]').click();a.wait_for_url('**/admin.php?project=p',wait_until='domcontentloaded')
        a.goto(case.base+'/panel/admin.php?tab=products',wait_until='domcontentloaded');assert a.locator('.project-toolbar select').input_value()=='p'
        b.goto(case.base+'/panel/workspace.php',wait_until='domcontentloaded');b.locator('button[value=q]').click();b.wait_for_url('**/admin.php?project=q',wait_until='domcontentloaded')
        result=a.evaluate("""async()=>{let r=await fetch('/panel/product_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project:'p',action:'begin',chunks:1})});return r.status;}""");assert result==403,result
        # A native browser form carries the old context independently of the shared cookie.
        with a.expect_navigation(wait_until='domcontentloaded'):
            a.evaluate("""()=>{const f=document.createElement('form');f.method='POST';f.action='/panel/admin.php?tab=products';for(const [name,value] of Object.entries({app_csrf:document.querySelector('meta[name=app-csrf]').content,_project_context:'p',update_product_data:'1',pid:'one',name:'WRONG TAB'})){const i=document.createElement('input');i.name=name;i.value=value;f.append(i);}document.body.append(f);f.submit();}""")
        a.wait_for_load_state('domcontentloaded');assert 'پروژه تغییر کرده' in a.content()
        assert case.record()['name']=='محصول one'
        b.goto(case.base+'/panel/backups.php?project=q',wait_until='domcontentloaded');b.locator('button[value=create]').click();b.wait_for_load_state('domcontentloaded');assert b.locator('button[value=preview]').count()==1
        for width in (1440,390):
            b.set_viewport_size({'width':width,'height':950});assert b.evaluate('document.documentElement.scrollWidth<=innerWidth')
        browser.close()
    print('Two browser tabs, stale AJAX, stale native form, chooser, project backup and mobile layout passed')
finally:HttpSecurity.tearDownClass()
