import asyncio, importlib.util, json, tempfile, threading, time
from pathlib import Path
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse,parse_qs
import sys
from crawler import scraper as fast
ROOT=Path(tempfile.mkdtemp(prefix='scraper-verification-'));hits=[];lock=threading.Lock();active=0;peak=0
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*a):pass
 def do_GET(self):
  global active,peak
  parsed=urlparse(self.path)
  with lock:hits.append(parsed.path+'?'+parsed.query)
  if parsed.path in ['/search','/failure-search','/dynamic-search']:
   time.sleep(.03);page=int(parse_qs(parsed.query).get('pageno',['1'])[0]);numbers=range((page-1)*4+1,page*4+1)
   links=''.join(f'<div class="product-wrap"><div class="product-name"><a href="/p/{n}">Product {n}</a></div></div>' for n in numbers)
   total=3
   if parsed.path=='/failure-search':links='<div class="product-wrap"><div class="product-name"><a href="/p/fail">Fail</a></div></div>';total=1
   if parsed.path=='/dynamic-search':links='<div class="product-wrap"><div class="product-name"><a href="/p/dynamic">Dynamic</a></div></div>';total=1
   body=f'<input id="total-page" value="{total}">'+links
  elif parsed.path.startswith('/p/'):
   with lock:active+=1;peak=max(peak,active)
   time.sleep(.08)
   with lock:active-=1
   code=parsed.path.split('/')[-1]
   if code=='fail':self.send_response(503);self.end_headers();return
   price='<span id="sale-price">125000</span>' if code!='dynamic' else '<script>setTimeout(()=>{let p=document.createElement("span");p.id="sale-price";p.textContent="250000";document.body.append(p)},150)</script>'
   body=f'''<h1 class="product-name">محصول {code}</h1><span id="product-code">{code}</span>{price}<a name="size-variant">M</a><div id="coloe-des">آبی</div><nav class="breadcrumb"><a>پوشاک</a></nav><div id="product-tab-description">توضیح محصول</div><a id="a-size-guide" data-link="/guide/{code}"></a><div id="dv-product-gallery-main"><div class="product-single-carousel"><img src="/asset.jpg" data-zoom-image="/images/{code}.jpg"><img data-src="/images/{code}-2.jpg"></div></div>'''
  elif parsed.path=='/asset.jpg':time.sleep(.04);self.send_response(200);self.send_header('Content-Type','image/svg+xml');self.end_headers();self.wfile.write(b'<svg xmlns="http://www.w3.org/2000/svg"/>');return
  else:self.send_response(404);self.end_headers();return
  self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.end_headers();self.wfile.write(body.encode())
server=ThreadingHTTPServer(('127.0.0.1',0),Handler);threading.Thread(target=server.serve_forever,daemon=True).start();base=f'http://127.0.0.1:{server.server_port}'
fields=list(fast.SELECTORS);progress=[];checks=[]
class Logs(list):
 def append(self,line):
  super().append(line);print(line,flush=True)
logs=Logs()
async def run(**kwargs):
 return await fast.run_scraper(base+'/search','fresh',fields,logs.append,progress.append,use_system_proxy=False,output=str(ROOT/'fast.json'),**kwargs)
async def main():
 global peak
 start=time.monotonic();await run();fast_time=time.monotonic()-start;data=json.loads((ROOT/'fast.json').read_text());assert len(data)==12;assert [x['product_code'] for x in data]==[str(i) for i in range(1,13)];assert progress==sorted(progress) and progress[-1]==100;assert 1<peak<=4;checks.append('bounded real browser concurrency, deterministic rows, monotonic progress')
 # Original code uses Windows paths; only browser path and output location change for this local comparison.
 import types
 original=types.ModuleType('original_scraper');source=(Path(__file__).resolve().parent/'reference'/'original_scraper.py').read_text();exec(compile(source,'original_scraper.py','exec'),original.__dict__)
 original.find_browser=fast.find_browser;original.OUTPUT=str(ROOT/'original.json')
 start=time.monotonic();await original.run_scraper(base+'/search','fresh',fields,lambda s:None,lambda p:None,use_system_proxy=False);old_time=time.monotonic()-start
 before=json.loads((ROOT/'original.json').read_text());assert {r['url']:r for r in before}=={r['url']:r for r in data};checks.append('all 12 fixture rows match original output exactly')
 hits.clear();out=ROOT/'batch.json'
 await fast.run_scraper(base+'/search','fresh',['name'],logs.append,lambda p:None,batch_size=3,use_system_proxy=False,output=str(out));assert len(json.loads(out.read_text()))==3;assert sum(h.startswith('/search?') for h in hits)==1;checks.append('batch stops discovery after first sufficient category page')
 await fast.run_scraper(base+'/search','continue',['name'],logs.append,lambda p:None,batch_size=3,use_system_proxy=False,output=str(out));assert len(json.loads(out.read_text()))==6;checks.append('continue appends next unsaved products')
 await fast.run_scraper(base+'/search','update',['price'],logs.append,lambda p:None,batch_size=1,refresh_existing=True,use_system_proxy=False,output=str(out));updated=json.loads(out.read_text());item=next(r for r in updated if r['product_code']=='1');assert item['name']=='محصول 1' and item['price']=='125000';checks.append('explicit refresh preserves unselected fields')
 await fast.run_scraper(base+'/dynamic-search','fresh',['price'],logs.append,lambda p:None,use_system_proxy=False,output=str(ROOT/'dynamic.json'));assert json.loads((ROOT/'dynamic.json').read_text())[0]['price']=='250000';checks.append('delayed JS price retained without networkidle')
 saved=out.read_bytes()
 try:await fast.run_scraper(base+'/failure-search','fresh',['name'],logs.append,lambda p:None,use_system_proxy=False,output=str(out),timeout_ms=1000)
 except RuntimeError:pass
 else:raise AssertionError('all-failed fresh must fail')
 assert out.read_bytes()==saved;checks.append('failed fresh run leaves previous JSON intact')
 # Checkpoint recovery, including the first fresh snapshot crash window.
 for mode in ['fresh','continue']:
  path=ROOT/(mode+'-journal.json');fast.save_products([{'url':'old','name':'old'}],path);store=fast.OutputStore(path,mode,10,logs.append);store.add({'url':'new','name':'new'});recovered=fast.OutputStore(path,'continue',10,logs.append);assert 'new' in recovered.items;assert ('old' in recovered.items)==(mode!='fresh');recovered.checkpoint();assert 'new' in fast.OutputStore(path,'continue',10,logs.append).items
 checks.append('fresh and merge journals recover rows before snapshot')
 # Stale lock files do not block; active locks do.
 with fast.OutputLock(ROOT/'locked.json'):
  try:
   with fast.OutputLock(ROOT/'locked.json'):pass
  except RuntimeError:pass
  else:raise AssertionError('lock must exclude concurrent writers')
 with fast.OutputLock(ROOT/'locked.json'):pass
 checks.append('concurrent output writers excluded, lock reusable')
 # GUI thread can pause an asyncio run; no new HTTP requests begin while paused.
 ctl=fast.ScraperController();ctl.pause();count=len(hits);paused=asyncio.create_task(fast.run_scraper(base+'/search','fresh',['name'],logs.append,lambda p:None,batch_size=1,controller=ctl,use_system_proxy=False,output=str(ROOT/'paused.json')));await asyncio.sleep(1);assert len(hits)==count;ctl.resume();await paused;checks.append('thread-safe pause blocks navigation until resumed')
 result={'original_seconds':round(old_time,3),'optimized_seconds':round(fast_time,3),'ratio_local_only':round(old_time/fast_time,2),'products':12,'peak_requests':peak,'checks':checks,'note':'Local controlled fixture only; no production site speed claim. Original browser path adjusted for Linux only.'};(Path(__file__).resolve().parent/'verification.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps(result,ensure_ascii=False,indent=2))
try:asyncio.run(main())
finally:server.shutdown()
