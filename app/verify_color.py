"""Exact Sportland color structures and delayed selected swatches; synthetic HTTP only."""
import asyncio
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from crawler import scraper

HEADER='<h1 class="product-name">Sportland product</h1><span id="product-code">35300</span>'
URL='https://www.sportland.ir/product/35300/example'
SIMPLE='<div class="product-form product-color"><label>رنگ :</label><label>سفید</label></div>'
SWATCHES='''<div class="product-form product-color"><label>رنگ ها :</label><div class="product-variations">
<a class="color border-no color-box" data-tooltip="سفید-سبز" data-src="60416" href="/product/60416/other"><img src="/other.jpg"></a>
<a class="color border-no color-box" data-tooltip="سفید" data-src="35300"><img class="img-border color-active" src="/main.jpg"></a>
<a class="color border-no color-box" data-tooltip="سیاه" data-src="35285" href="/product/35285/other"><img src="/other.jpg"></a>
</div></div>'''
def color(html,fields=None):return scraper.parse_html(HEADER+html,URL,fields or ['color'])
assert color(SIMPLE)['color']=='سفید'
assert color(SWATCHES)['color']=='سفید','Selected color, not first sibling tooltip'
assert color(SIMPLE+SWATCHES)['color']=='سفید'
assert color(SWATCHES.replace('color-active',''))['color']=='سفید','Exact current product code fallback'
assert color(SWATCHES.replace('data-src="35300"','data-src="99999"').replace('color-active',''))['color']=='سفید','Unique non-navigating current swatch fallback'
assert color('<div class="product-form product-color"><label>رنگ ها :</label><a data-tooltip="سیاه" href="/other"></a></div>')['color'] is None
assert color('<div class="product-form product-color"><label>رنگ‌ها :</label></div>')['color'] is None
assert color('<div id="coloe-des">آبی</div>'+SWATCHES)['color']=='آبی','Existing Tanedorost color preserved'
assert 'color' not in color(SIMPLE,['name'])
assert color(SWATCHES,['gallery'])['gallery']==[],'Color thumbnails are not product gallery'

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        body=HEADER+'''<div class="product-form product-color"><label>رنگ ها :</label><div class="product-variations" id="swatches"></div></div>
        <script>setTimeout(()=>{document.querySelector('#swatches').innerHTML='<a data-tooltip="سفید" data-src="35300"><img class="color-active"></a>'},150)</script>'''
        self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(body.encode())

async def main():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler);threading.Thread(target=server.serve_forever,daemon=True).start()
    playwright=browser=None
    try:
        playwright,browser,page=await scraper.create_browser(False,True)
        result=await scraper.parse_product_page(page,f'http://127.0.0.1:{server.server_port}/product/35300/example',['color'],scraper.scraper_controller,lambda _:None,10000,800)
        assert result['color']=='سفید',result
    finally:
        if browser:await browser.close()
        if playwright:await playwright.stop()
        server.shutdown();server.server_close()
    print('11 color checks passed: explicit label, active swatch, product-code fallback, no sibling confusion, legacy color and delayed tooltip')

if __name__=='__main__':asyncio.run(main())
