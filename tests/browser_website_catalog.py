"""Real file picker -> chunked 10-MiB upload -> preview -> confirmed model match/WebP save."""
import json
import mimetypes
import os
from pathlib import Path
import shutil
import sqlite3
import tempfile
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity

Fixture=type('WebsiteCatalogBrowserFixture',(HttpSecurity,),{'PHP_SETTINGS':('-d','post_max_size=1M')})
Fixture.setUpClass();case=Fixture();case.setUp();base=case.base
errors=[];requests=[];out=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='website-catalog-browser-')));out.mkdir(parents=True,exist_ok=True)
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,content_type='application/json',body='{}')
    if r.request.url.startswith(base):
        path=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if path.is_file() and path.suffix in ['.css','.js','.svg','.png','.woff','.woff2']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(path))[0] or 'application/octet-stream',body=path.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,content_type='image/svg+xml' if r.request.resource_type=='image' else 'text/plain',body='<svg xmlns="http://www.w3.org/2000/svg"/>' if r.request.resource_type=='image' else '')
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context(viewport={'width':1440,'height':1000});context.route('**/*',route);page=context.new_page()
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.on('request',lambda r:requests.append(len(r.post_data_buffer or b'')) if r.method=='POST' else None)
        page.goto(base+'/');page.evaluate('openLoginModal();switchLoginTab("admin")');page.fill('[name=user]','fixture_owner');page.fill('[name=pass]','Fixture-password-123!');page.click('button[name=login]');page.wait_for_load_state()
        page.goto(base+'/panel/admin.php?switch_project=p')
        with sqlite3.connect(case.database) as db:db.execute('UPDATE products SET name="Aero   Pro W",color="سفید",image="",images_list="[]",gallery="[]",status="no_image" WHERE id="one"')
        page.goto(base+'/panel/admin.php?tab=extract')
        row=dict(url='https://shop.example/product/74634/aero',product_code='74634',color='سفید',name='نیم تنه ورزشی زنانه اسپورتلند Aero Pro W',main_image='https://shop.example/main.webp',gallery=['https://shop.example/main.webp','https://shop.example/gallery.webp'],description='')
        encode=lambda:json.dumps([row],ensure_ascii=False,separators=(',',':')).encode()
        row['description']='X'*(10*1024*1024-len(encode()));raw=encode();assert len(raw)==10*1024*1024
        page.locator('#website-catalog-file').set_input_files(dict(name='website-10MiB.json',mimeType='application/json',buffer=raw))
        page.wait_for_function("document.querySelector('#website-catalog-result').textContent.includes('آمادهٔ پیش‌نمایش')")
        page.locator('#website-catalog-preview').click()
        page.wait_for_selector('#website-catalog-apply:not([hidden])',timeout=60000)
        assert 'با نام مدل: 1' in page.locator('#website-catalog-result').inner_text()
        assert case.record()['image']=='','Preview must not write products'
        with page.expect_navigation():page.locator('#website-catalog-apply').click()
        product=case.record();assert product['image']==row['main_image'];assert product['name']=='Aero   Pro W';assert product['stock_total']=='10'
        assert max(requests)<1024*1024,('Request exceeded host limit',max(requests))
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':950})
            page.goto(base+'/panel/admin.php?tab=extract&wc_queue=site_visible')
            product_row=page.locator('.website-catalog-row[data-product-id="one"]')
            assert product_row.count()==1
            product_row.locator('.wc-compare>summary').click()
            assert product_row.locator('a[href="'+row['main_image']+'"]').count()>=1
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
            page.screenshot(path=str(out/f'website-model-{width}.png'),full_page=True)
        assert not errors,errors
        browser.close()
    print(json.dumps(dict(file_bytes=len(raw),max_request_bytes=max(requests),model_match=True,webp_primary=True,viewports=[1440,375],page_errors=errors)))
finally:Fixture.tearDownClass()
