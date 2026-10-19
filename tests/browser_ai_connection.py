"""Project assistant UI: local CSV, per-user source sharing, manual content and mobile layout."""
import json,os,shutil,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity,PNG
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base
errors=[];artifacts=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='ai-ui-')));artifacts.mkdir(parents=True,exist_ok=True)
def route(r):
    if r.request.url.startswith(base):
        asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if asset.is_file() and asset.suffix in ['.js','.css','.woff2','.png']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,body='')
try:
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context(viewport={'width':1440,'height':1000});ctx.route('**/*',route)
        ctx.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        def visit(tab):page.goto(base+'/panel/ai-connect.php?project=p&view='+tab);page.wait_for_load_state()
        visit('data');form=page.locator('#ai-dataset');form.locator('[name=name]').fill('فروش آزمایشی');form.locator('[name=origin]').fill('CSV fixture');form.locator('[name=unit]').fill('ریال');form.locator('[name=date_field]').fill('date')
        page.locator('#ai-data-file').set_input_files({'name':'data.csv','mimeType':'text/csv','buffer':b'date,sales\r\n2026-10-19,120\r\n2026-10-20,180\r\n'})
        page.wait_for_function("document.querySelector('#ai-data-json').value.includes('120')")
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('منبع داده به پروژه اضافه شد.',exact=True).is_visible()
        visit('providers');form=page.locator('form').filter(has=page.locator('[name=key]'));form.locator('[name=name]').fill('کلید آزمایشی');form.locator('[name=key]').fill('sk-synthetic-fixture-browser-key-123');form.locator('#ai-users').fill('limited\neditor')
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('منبع API و دسترسی‌های اشتراک ذخیره شد.',exact=True).is_visible()
        s=json.loads((case.app/'panel/db/ai-private/state.json').read_text());provider=next(iter(s['providers'].values()));assert provider['users']==['limited','editor'];assert 'sk-synthetic' not in provider['key']
        visit('reports');form=page.locator('form').filter(has=page.locator('[name=prompt]'));form.locator('[name=title]').fill('تحلیل روزانه');form.locator('[name=prompt]').fill('صف محصولات را تحلیل کن.');form.locator('[name=provider]').select_option(provider['id']);form.locator('[name="sources[]"][value=products]').check()
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('برنامهٔ گزارش ذخیره شد.',exact=True).is_visible()
        for tab in ['connect','reports','data','providers','wallet']:
            for width in [1440,375]:
                page.set_viewport_size({'width':width,'height':1000});visit(tab);assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(tab,width);page.screenshot(path=str(artifacts/f'{tab}-{width}.png'),full_page=True)
        visit('connect');form=page.locator('form[enctype="multipart/form-data"]');form.locator('[name=product_id]').fill('one');form.locator('[name=asset]').set_input_files({'name':'generated.png','mimeType':'image/png','buffer':PNG})
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('محتوا در محصول ثبت شد؛ تصاویر اصلی و گالری برای تأیید در پورتال قرار گرفتند.',exact=True).is_visible()
        assert not json.loads(case.record()['images_list'])[-1]['approved']
        assert not errors,errors
        browser.close()
    print(json.dumps({'csv_upload':True,'sharing':True,'schedule_created':True,'image_pending':True,'tabs':5,'viewports':[1440,375],'js_errors':errors,'artifacts':str(artifacts)}))
finally:HttpSecurity.tearDownClass()
