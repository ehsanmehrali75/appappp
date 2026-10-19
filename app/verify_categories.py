"""Ordered product breadcrumbs, scaffolding removal and deferred category rendering."""
import asyncio
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from crawler import scraper

HEADER='<h1 class="product-name">تی شرت نمونه</h1><span id="product-code">123</span>'
URL='https://www.sportland.ir/product/123/example'
def breadcrumb(labels):
    return '<ul class="breadcrumb breadcrumb-lg"><li><a href="/"><i class="d-icon-home"></i></a></li>'+''.join(f'<li><a href="/search/category-{i}">{label}</a></li>' for i,label in enumerate(labels))+'</ul>'
HTML='<div class="product-navigation">'+breadcrumb(['دسته بندی کالاها','مردانه','پوشاک','تی شرت ورزشی'])+'</div>'
parsed=scraper.parse_html(HEADER+HTML,URL,['name'])
assert parsed['category']==['مردانه','پوشاک','تی شرت ورزشی']
assert parsed['category_path']=='مردانه/پوشاک/تی شرت ورزشی'
assert parsed['primary_category']=='تی شرت ورزشی'
other=scraper.parse_html(HEADER+breadcrumb(['دسته بندی کالاها','ورزش','کمپ و سفر']),URL,['category'])
assert other['category']==['ورزش','کمپ و سفر'] and other['primary_category']=='کمپ و سفر'
assert scraper.parse_html(HEADER+HTML+breadcrumb(['برندها','برند اشتباه']),URL,['category'])['category']==parsed['category']
assert scraper.parse_html(HEADER+breadcrumb(['خانه','دسته\u200cبندی کالاها','پوشاک','تی شرت نمونه']),URL,[])['category']==['پوشاک']
empty=scraper.parse_html(HEADER,URL,['price'])
assert empty['category']==[] and empty['category_path']=='' and empty['primary_category'] is None

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        body=HEADER+'<div class="product-navigation" id="navigation"><ul class="breadcrumb"><li><a href="/">خانه</a></li><li><a href="/search">دسته بندی کالاها</a></li></ul></div><script>setTimeout(()=>{document.getElementById("navigation").innerHTML='+__import__('json').dumps(HTML)+'},150)</script>'
        self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(body.encode())

async def main():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler);threading.Thread(target=server.serve_forever,daemon=True).start()
    playwright=browser=None
    try:
        playwright,browser,page=await scraper.create_browser(False,True)
        result=await scraper.parse_product_page(page,f'http://127.0.0.1:{server.server_port}/product/123/example',['name'],scraper.scraper_controller,lambda _:None,10000,1000)
        assert result['category']==parsed['category'] and result['primary_category']=='تی شرت ورزشی',result
    finally:
        if browser:await browser.close()
        if playwright:await playwright.stop()
        server.shutdown();server.server_close()
    print('8 category checks passed: ordered hierarchy, leaf, root removal, no duplicate trails, mandatory export and delayed rendering')

if __name__=='__main__':asyncio.run(main())
