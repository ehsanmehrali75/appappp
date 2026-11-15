"""Actual ZIP UI: capacity wait, dropped chunk reply, persisted resume, no duplicate product."""
import os,sys,json,shutil,subprocess,mimetypes,tempfile,zipfile
from pathlib import Path
from urllib.parse import urlparse,unquote,parse_qs
from unittest.mock import patch
from playwright.sync_api import sync_playwright
from test_http_security import HttpSecurity,PHP
with patch.dict(os.environ,{'MAARKSHO_EXPORT_CONCURRENCY':'1'}):HttpSecurity.setUpClass()
c=HttpSecurity();c.setUp();holder=None;errors=[];dialogs=[];dropped=[]
try:
 subprocess.run([PHP,'-r',"$i=imagecreatetruecolor(20,30);imagepng($i,$argv[1]);imagedestroy($i);",str(c.app/'panel/uploads/original.png')],check=True)
 opener,_=c.login();jar=next(h.cookiejar for h in opener.handlers if hasattr(h,'cookiejar'))
 holder=subprocess.Popen([PHP,'-r',"require $argv[1].'/panel/inc/WebsiteExportCapacity.php';$slot=WebsiteExportCapacity::acquire();echo \"ready\\n\";flush();fgets(STDIN);WebsiteExportCapacity::release($slot);",str(c.app)],env=dict(os.environ,MAARKSHO_EXPORT_CONCURRENCY='1'),stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 assert holder.stdout.readline().strip()=='ready'
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=shutil.which('chromium'),headless=True,args=['--no-sandbox']);ctx=browser.new_context(accept_downloads=True)
  def route(r):
   if '/cron.php' in r.request.url:return r.fulfill(status=200,content_type='application/json',body='{}')
   if r.request.url.startswith(c.base):
    asset=c.app/unquote(urlparse(r.request.url).path).lstrip('/')
    if asset.is_file() and asset.suffix in ['.js','.css','.png','.svg','.woff2']:return r.fulfill(status=200,content_type=mimetypes.guess_type(str(asset))[0] or 'application/octet-stream',body=asset.read_bytes())
    fields=parse_qs(r.request.post_data or '')
    if fields.get('ajax_action')==['export_website_chunk'] and not dropped:
     response=r.fetch();data=response.json()
     if data.get('done'):
      dropped.append(data);return r.abort()
     return r.fulfill(response=response)
    return r.continue_()
   return r.fulfill(status=200,body='')
  ctx.route('**/*',route);ctx.add_cookies([{'name':x.name,'value':x.value,'url':c.base} for x in jar]);page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:(dialogs.append(d.message),d.accept()))
  page.goto(c.base+'/panel/admin.php?tab=website_export',wait_until='domcontentloaded');page.locator('.chk-export-item[value="one"]').check();button=page.locator('#btn-export-selected');button.click()
  page.wait_for_function("document.querySelector('#btn-export-selected').textContent.includes('در صف ظرفیت')")
  holder.stdin.write('\n');holder.stdin.flush();holder.wait(5)
  page.wait_for_function("!document.querySelector('#btn-export-selected').disabled")
  assert dropped and dialogs,dialogs
  assert page.evaluate("Object.keys(localStorage).some(k=>k.startsWith('maarksho-export-'))")
  with page.expect_download() as info:button.click()
  download=info.value;path=Path(tempfile.gettempdir())/'maarksho-browser-export.zip';download.save_as(path)
  with zipfile.ZipFile(path) as z:
   assert z.testzip() is None;manifest=json.loads(z.read('manifest.json'));assert [p['id'] for p in manifest['products']]==['one']
  assert not page.evaluate("Object.keys(localStorage).some(k=>k.startsWith('maarksho-export-'))")
  assert len(list((c.app/'panel/uploads').glob('website_export_*.zip')))==1
  assert not errors,errors;path.unlink();browser.close()
 print(json.dumps({'capacity_wait':True,'dropped_response_resumed':True,'one_product_once':True,'download_valid':True,'javascript_errors':errors}))
finally:
 if holder and holder.poll() is None:holder.terminate();holder.wait(5)
 HttpSecurity.tearDownClass()
