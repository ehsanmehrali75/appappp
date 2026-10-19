"""Data hub source creation, real XLSX preview/import and mobile overflow regression."""
import json,os,shutil,tempfile,mimetypes
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base;errors=[];out=Path(tempfile.mkdtemp(prefix='data-hub-browser-'))
def route(r):
    if r.request.url.startswith(base):
        asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if asset.is_file() and asset.suffix in ['.js','.css','.woff2','.png']:return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,body='')
try:
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context(viewport={'width':1440,'height':1000});ctx.route('**/*',route);ctx.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)));page.goto(base+'/panel/data-hub.php?project=p')
        page.locator('a[href="#hub-sources"]').click();form=page.locator('form').filter(has=page.locator('[name=action][value=source]'));form.locator('[name=name]').fill('Warehouse fixture');form.locator('[name=origin]').fill('fixture');form.locator('[name=store_id]').fill('branch-1');form.locator('[name="kinds[]"][value=sales]').check()
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('منبع ساخته شد؛ توکن فقط همین بار نمایش داده می‌شود.',exact=True).is_visible()
        buf=page.evaluate('()=>{const b=XLSX.utils.book_new();XLSX.utils.book_append_sheet(b,XLSX.utils.json_to_sheet([{id:"order-001",date:"2026-10-19",grain:"order",currency:"IRR",gross:120,refund:20,net:100,orders:1}]),"Sales");return Array.from(new Uint8Array(XLSX.write(b,{type:"array",bookType:"xlsx"})));}')
        page.locator('a[href="#hub-import"]').click();page.locator('#hub-file').set_input_files({'name':'sales.xlsx','mimeType':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','buffer':bytes(buf)})
        page.wait_for_function("document.querySelector('#hub-rows').value.includes('order-001')");form=page.locator('#hub-import-form');form.locator('[name=kind]').select_option('sales')
        with page.expect_navigation():form.locator('button').click()
        assert page.get_by_text('1 ردیف ثبت/به‌روز شد؛ شناسه‌های تکراری محصول تازه نمی‌سازند.',exact=True).is_visible()
        page.goto(base+'/panel/data-hub.php?project=p&kind=sales');assert page.get_by_text('خالص: 100.00 · ناخالص: 120.00',exact=True).is_visible()
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':1000});page.wait_for_timeout(100);assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');page.screenshot(path=str(out/f'hub-{width}.png'),full_page=True)
        assert not errors,errors;browser.close()
    print(json.dumps({'xlsx_import':True,'source_creation':True,'actual_sales_totals':True,'viewports':[1440,375],'js_errors':errors,'artifacts':str(out)}))
finally:HttpSecurity.tearDownClass()
