"""Sportland media regression using supplied public HTML and a local JS fixture."""
import asyncio
import json
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from crawler import scraper

URL='https://www.sportland.ir/product/88273/on-stride-u'
GALLERY=(Path(__file__).parent/'fixtures/sportland-gallery.html').read_text()
HEADER='<h1 class="product-name">کتونی ON Stride U</h1><span id="product-code">88273</span>'
EXPECTED=[f'https://www.sportland.ir/content/media/product/88273/pro_{code}.webp' for code in range(68419,68423)]
row=scraper.parse_html(HEADER+GALLERY,URL,['name','gallery','size_guide'])
assert row['gallery']==EXPECTED,row
assert row['main_image']==EXPECTED[0]
assert row['size_guide'] is None,'Supplied HTML contains no size-guide evidence'
assert all('_400' not in image for image in row['gallery'])
# A cloned carousel item or a recommendation card must never become the primary photo.
cloned='<div class="product-gallery"><div class="product-single-carousel"><div class="owl-item cloned"><img src="/clone.jpg"></div></div></div>'
assert scraper.parse_html(HEADER+cloned+GALLERY+'<div class="related-products"><img src="/other.jpg"></div>',URL,['gallery'])['gallery']==EXPECTED
structured='<script type="application/ld+json">'+json.dumps({'@context':'https://schema.org','@graph':[{'@type':'Product','sku':'88273','name':'ON Stride U','image':[{'@type':'ImageObject','contentUrl':EXPECTED[0]},EXPECTED[1]]}]})+'</script>'
assert scraper.parse_html(structured,URL,['gallery','name'])['gallery']==EXPECTED[:2]
unsafe='<div class="product-gallery"><div class="product-single-carousel"><img src="data:image/png;base64,AA"><img data-src="javascript:alert(1)"><img src="/placeholder.png"></div></div>'
assert scraper.parse_html(HEADER+unsafe,URL,['gallery'])['gallery']==[]

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        script='''<div id="images"></div><div class="container-sizeguide"><a id="a-size-guide"></a></div><script>
        setTimeout(()=>{document.querySelector('#images').innerHTML=GALLERY},1200);
        setTimeout(()=>{document.querySelector('#a-size-guide').setAttribute('data-link','/content/media/chartsize/Chart_88273.jpg')},1800);
        </script>'''.replace('GALLERY',json.dumps(GALLERY))
        body=HEADER+script
        self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(body.encode())

async def main():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler);threading.Thread(target=server.serve_forever,daemon=True).start()
    playwright=browser=None
    try:
        playwright,browser,page=await scraper.create_browser(False,True)
        url=f'http://127.0.0.1:{server.server_port}/product/88273/on-stride-u'
        result=await scraper.parse_product_page(page,url,['name','gallery','size_guide'],scraper.scraper_controller,lambda _:None,10000,800,size_guide_wait_ms=3000)
        assert len(result['gallery'])==4,result
        assert result['main_image'].endswith('/pro_68419.webp')
        assert result['size_guide']==f'http://127.0.0.1:{server.server_port}/content/media/chartsize/Chart_88273.jpg'
        # Gallery still waits for delayed markup when size guides are deselected.
        result=await scraper.parse_product_page(page,url,['gallery'],scraper.scraper_controller,lambda _:None,10000,800,size_guide_wait_ms=0)
        assert len(result['gallery'])==4,result
        assert 'size_guide' not in result
    finally:
        if browser:await browser.close()
        if playwright:await playwright.stop()
        server.shutdown();server.server_close()
    print('Sportland media passed: exact four full-size images, main image, no thumbnails/clones, schema fallback, safe URLs, delayed gallery and size chart')

if __name__=='__main__':asyncio.run(main())
