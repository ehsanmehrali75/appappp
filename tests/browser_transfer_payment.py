"""Verify manual payment configuration and admin review."""
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
    import subprocess
    source="require $argv[1];require dirname($argv[1]).'/TransferPurchases.php';$s=getSiteSettings();$s['bank_transfer']=['enabled'=>true,'card'=>'6037991234567890','holder'=>'Fixture holder','iban'=>'IR'.str_repeat('1',24),'bank'=>'Fixture bank'];saveSiteSettings($s);$svc=new TransferPurchases();$o=$svc->startTransfer(['checkout_nonce'=>str_repeat('b',32),'plan_id'=>'plan_pro','billing_cycle'=>'monthly','customer_name'=>'Buyer','customer_mobile'=>'09121112233','customer_business'=>'Fixture transfer project','account_username'=>'transferbuyer','account_password'=>'Fixture-password-123!'],$s);$store=new AiStore(dirname($argv[1],2).'/db/purchases');$store->transaction(function(&$s)use($o){$s['orders'][$o['order_id']]['receipt']['status']='awaiting_review';$s['orders'][$o['order_id']]['evidence']=['last_four'=>'1234','reference'=>'BANK-FIXTURE','paid_date'=>'1405/08/01','file'=>'receipt-'.str_repeat('a',48).'.png','mime'=>'image/png'];});echo json_encode($o);"
    from test_http_security import PHP
    result=subprocess.run([PHP,'-r',source,str(case.app/'panel/inc/functions.php')],check=True,capture_output=True,text=True)
    oid=json.loads(result.stdout)['order_id']
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        page=browser.new_page(viewport={'width':1440,'height':1000});page.route('**/*',route);page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base+'/',wait_until='domcontentloaded');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state('domcontentloaded');page.goto(base+'/panel/workspace.php',wait_until='domcontentloaded');page.locator('button[value=p]').click();page.wait_for_load_state('domcontentloaded')
        page.goto(base+'/panel/admin.php?tab=main_settings',wait_until='domcontentloaded')
        assert page.locator('[name=transfer_card]').input_value()=='6037991234567890'
        page.goto(base+'/panel/transfer-payment.php?order='+oid,wait_until='domcontentloaded')
        for width in (1440,390):
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
            assert page.get_by_role('button',name='تأیید و فعال‌سازی پروژه').is_visible()
            page.screenshot(path=f'/tmp/transfer-{width}.png')
        page.locator('[name=note]').fill('تأیید واریز بانکی')
        page.get_by_role('button',name='تأیید و فعال‌سازی پروژه').click();page.wait_for_load_state('domcontentloaded')
        assert 'پروژه فعال شد' in page.content()
        assert not errors,errors
        browser.close();print('Transfer settings, review, approval and mobile layout passed')
finally:
    case.tearDown();HttpSecurity.tearDownClass()
