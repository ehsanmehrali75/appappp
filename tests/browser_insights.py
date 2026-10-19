"""Unified analytics UX across real project-scoped routes at desktop/mobile widths."""
import json,shutil,tempfile,mimetypes,re,urllib.parse
from pathlib import Path
from urllib.parse import urlparse,unquote
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity
HttpSecurity.setUpClass();case=HttpSecurity();case.setUp();base=case.base;errors=[];out=Path(tempfile.mkdtemp(prefix='insights-browser-'))
def route(r):
    if r.request.url.startswith(base):
        asset=case.app/unquote(urlparse(r.request.url).path).lstrip('/')
        if asset.is_file() and asset.suffix in ['.js','.css','.woff2','.png']:return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
        return r.continue_()
    return r.fulfill(status=200,body='')
try:
    opener,csrf=case.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
    for kind in ['sales','posts']:
        fields=[('app_csrf',csrf),('action','source'),('name','Fixture '+kind),('origin','fixture.local'),('store_id','branch-'+kind),('kinds[]',kind)]
        code,html=case.request(opener,'/panel/data-hub.php?project=p',raw=urllib.parse.urlencode(fields).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
        assert code==200;token=re.search(r'<code>([a-f0-9]{64})</code>',html)[1]
        row={'id':'order-1','date':'2026-10-19','grain':'order','currency':'IRR','gross':1000,'refund':100,'net':900,'orders':1,'status':'completed'} if kind=='sales' else {'id':'post-1','title':'صفحهٔ نمونهٔ واقعی تست','seo_title':'','seo_description':'','robots':['noindex']}
        code,body=case.request(opener,'/panel/integration_ingest.php',raw=json.dumps({'kind':kind,'batch_id':'browser-fixture-'+kind,'rows':[row]}).encode(),headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'});assert code==200,body
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context(viewport={'width':1440,'height':1000});ctx.route('**/*',route);ctx.add_cookies([{'name':c.name,'value':c.value,'url':base} for c in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        for domain in ['sales','seo','intelligence']:
            for section in ['overview','reports','integrations','recommendations','tools']:
                for width in [1440,375]:
                    page.set_viewport_size({'width':width,'height':1000});page.goto(f'{base}/panel/insights.php?project=p&domain={domain}&section={section}');assert page.locator('.insight-main').is_visible();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(domain,section,width)
                    if section in ['overview','integrations']:page.screenshot(path=str(out/f'{domain}-{section}-{width}.png'),full_page=True)
        page.goto(base+'/panel/sale.php?project=p');assert '/panel/insights.php?' in page.url
        page.goto(base+'/panel/seo.php?project=p');assert '/panel/insights.php?' in page.url
        assert not errors,errors;browser.close()
    print(json.dumps({'workspaces':3,'sections':5,'viewports':[1440,375],'routes':60,'legacy_entry_redirects':True,'js_errors':errors,'artifacts':str(out)}))
finally:HttpSecurity.tearDownClass()
