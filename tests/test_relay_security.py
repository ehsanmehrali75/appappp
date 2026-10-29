"""No outbound requests: assert TLS policy and execute the actual browser relay with fake fetch."""
import json,re,shutil,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class RelaySecurity(unittest.TestCase):
    def test_php_transports_never_disable_tls_verification(self):
        pattern=re.compile(r'CURLOPT_SSL_(?:VERIFYPEER|VERIFYHOST)\s*(?:=>|,)\s*(?:false|0)\b')
        failures=[]
        for path in (ROOT/'panel').rglob('*.php'):
            if 'db' in path.relative_to(ROOT/'panel').parts:continue
            if pattern.search(path.read_text()):failures.append(str(path.relative_to(ROOT)))
        self.assertEqual(failures,[])
    @unittest.skipUnless(shutil.which('node'),'Node required to execute the actual browser relay')
    def test_browser_rubika_relay_never_falls_back_or_claims_success_without_receipt(self):
        script=r"""const fs=require('fs'),path=require('path');
(async()=>{const results=[];
for(const name of ['panel/admin.php','panel/portal.php']){
 const text=fs.readFileSync(path.join(process.argv[1],name),'utf8');const start=text.indexOf('// Single official Rubika request');const end=text.indexOf('// End official Rubika request',start);if(start<0||end<0)throw Error('relay not found');const block=text.slice(start,end);const row=[];
 for(const mode of ['success','no_receipt','network','image']){
  let calls=0;const urls=[];const payload={token:'fixture-rubika-token',chatId:'fixture-chat',caption:'Human text',image:mode==='image'?'https://example.org/photo.png':null};
  const fetch=async(url)=>{calls++;urls.push(url);if(mode==='network')throw Error('fixture interrupted');return {ok:true,json:async()=>({status:'OK',data:mode==='success'?{message_id:'fixture-receipt'}:{}})}};
  let success=false;try{success=await eval('(async()=>{'+block+'})()')}catch(e){}row.push({mode,calls,success,official:urls.every(x=>x==='https://botapi.rubika.ir/v3/fixture-rubika-token/sendMessage')});
 }results.push(row);
}process.stdout.write(JSON.stringify(results));})().catch(e=>{process.stderr.write(e.message);process.exit(1)});"""
        result=subprocess.run(['node','-e',script,str(ROOT)],capture_output=True,text=True,timeout=20)
        self.assertEqual(result.returncode,0,result.stderr)
        for row in json.loads(result.stdout):
            self.assertEqual([r['calls'] for r in row],[1,1,1,0]);self.assertEqual([r['success'] for r in row],[True,False,False,False]);self.assertTrue(all(r['official'] for r in row))
