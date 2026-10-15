"""Controlled browser regression for deferred size guides; no production requests."""
import asyncio
import json
import tempfile
import threading
import time
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from crawler import scraper
from urllib.parse import urlparse

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_): pass
    def do_GET(self):
        if urlparse(self.path).path == '/search':
            body='<input id="total-page" value="1">'+''.join(f'<div class="product-wrap"><div class="product-name"><a href="/{name}">{name}</a></div></div>' for name in ['late','attribute','ready','absent'])
        else:
            name=self.path.strip('/')
            body=f'<h1 class="product-name">{name}</h1><span id="product-code">{name}</span><div id="product-tab-description">Description</div>'
            if name=='late':
                body+='''<script>setTimeout(()=>document.body.insertAdjacentHTML('beforeend', '<div class="container-sizeguide"><a id="a-size-guide" data-link="/content/media/chartsize/Chart_31543.jpg">راهنمای سایز</a></div>'), 2100)</script>'''
            elif name=='attribute':
                body+='''<a id="a-size-guide" data-link=""></a><script>setTimeout(()=>document.querySelector('#a-size-guide').setAttribute('data-link','/chart-attribute.jpg'),1200)</script>'''
            elif name=='ready':
                body+='<div class="container-sizeguide"><a data-link="/ready.jpg">Size</a></div>'
        self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(body.encode())

async def main(base):
    root=Path(tempfile.mkdtemp(prefix='size-guide-check-'));logs=[]
    await scraper.run_scraper(base+'/search','fresh',['name','description','size_guide'],logs.append,lambda _:None,use_system_proxy=False,output=str(root/'products.json'),size_guide_wait_ms=3000)
    rows={r['product_code']:r for r in json.loads((root/'products.json').read_text())}
    assert rows['late']['size_guide']==base+'/content/media/chartsize/Chart_31543.jpg'
    assert rows['attribute']['size_guide']==base+'/chart-attribute.jpg'
    assert rows['ready']['size_guide']==base+'/ready.jpg'
    assert rows['absent']['size_guide'] is None
    assert any('no valid link' in line and '/absent' in line for line in logs)
    parsed=scraper.parse_html('<h1 class="product-name">Invalid</h1><a id="a-size-guide" data-link="javascript:alert(1)"></a>',base+'/x',['size_guide'])
    assert parsed['size_guide'] is None
    playwright,browser,page=await scraper.create_browser(False)
    try:
        start=time.monotonic()
        await scraper.parse_product_page(page,base+'/absent',['name'],scraper.scraper_controller,logs.append,10000,800)
        assert time.monotonic()-start<2, 'unselected size guide must not incur delay'
        start=time.monotonic()
        await scraper.parse_product_page(page,base+'/ready',['size_guide'],scraper.scraper_controller,logs.append,10000,800)
        assert time.monotonic()-start<2, 'ready guide must exit wait immediately'
    finally:
        await browser.close();await playwright.stop()
    print('7 deferred size-guide checks passed (late insertion, late attribute, ready, absent, unsafe URL, unselected, early exit)')

if __name__=='__main__':
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    try:asyncio.run(main(f'http://127.0.0.1:{server.server_port}'))
    finally:server.shutdown()
