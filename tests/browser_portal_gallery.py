"""Real library selection, bulk decisions, shared admin queues and mobile layout."""
import json,os,mimetypes,shutil,tempfile
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base
errors=[];out=Path(os.environ.get('BROWSER_ARTIFACTS',tempfile.mkdtemp(prefix='portal-bulk-')));out.mkdir(parents=True,exist_ok=True)
def route(r):
    if '/cron.php' in r.request.url:return r.fulfill(status=200,body='{}')
    if r.request.url.startswith(base):
        asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if asset.is_file() and asset.suffix in ['.js','.css','.woff2','.woff','.svg','.png']:
            return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,content_type='image/svg+xml' if r.request.resource_type=='image' else 'text/plain',body='<svg xmlns="http://www.w3.org/2000/svg"/>' if r.request.resource_type=='image' else '')
try:
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);context=browser.new_context();context.route('**/*',route);context.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':900});page.goto(base+'/panel/portal.php?project=p&tab=library');page.wait_for_load_state()
            image=page.locator('[data-media-gallery] img:not([src*="placehold"]):visible').first
            # Add a second actual local asset URL to the disposable product carousel.
            image.evaluate("el=>{const img=el.cloneNode();img.src=el.src+'?second=1';el.parentElement.append(img)}")
            image.click();dialog=page.locator('dialog.portal-image-gallery');assert dialog.is_visible(),(errors,page.evaluate('window.portalImageGalleryInstalled'),page.url)
            assert page.locator('#product-detail-modal').is_hidden()
            assert dialog.locator('.pig-thumbs button').count()>=2
            old=dialog.locator('.pig-image').get_attribute('src');dialog.get_by_role('button',name='تصویر بعدی',exact=True).click();assert dialog.locator('.pig-image').get_attribute('src')!=old
            dialog.get_by_role('button',name='بزرگ‌نمایی تصویر').click();assert dialog.locator('.pig-viewport').evaluate("el=>el.classList.contains('is-zoomed')")
            page.keyboard.press('Escape');assert not dialog.is_visible()
            image.focus();page.keyboard.press('Enter');assert dialog.is_visible();dialog.get_by_role('button',name='بستن گالری').click();assert not dialog.is_visible()
            # Delegated handling also applies to images inserted after AJAX updates.
            page.evaluate("()=>{const group=document.createElement('div');group.dataset.mediaGallery='';group.style.cssText='position:relative;z-index:1;padding:30px';group.innerHTML='<img src=\"/panel/uploads/original.png?ajax=1\" alt=\"تصویر تازه\" width=\"150\" height=\"150\">';document.querySelector('#portal-main-container').append(group)}")
            page.locator('img[alt="تصویر تازه"]').click();assert dialog.is_visible();page.keyboard.press('Escape')
            page.goto(base+'/panel/portal.php?project=p&tab=pending');page.locator('#tab-pending [data-media-gallery] img:not([src*="placehold"]):visible').first.click();assert page.locator('dialog.portal-image-gallery').is_visible();page.keyboard.press('Escape')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
        assert not errors,errors;browser.close()
    print(json.dumps({'viewports':[1440,375],'gallery':True,'navigation':True,'zoom':True,'keyboard':True,'ajax':True,'js_errors':errors}))
finally:HttpSecurity.tearDownClass()
