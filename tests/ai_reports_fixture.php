<?php
// Invoked only against test_http_security's disposable site, never deployment data.
require $argv[1].'/panel/inc/ai/Bootstrap.php';
function checkAi($yes,$label){if(!$yes)throw new RuntimeException($label);}
$store=new AiStore();$state=$store->read();$grant=reset($state['grants']);
$result=AiAccess::run($grant,function($project)use($store,$grant){
    $calls=0;$fake=static function($url,$payload,$headers=[])use(&$calls){$calls++;return ['status'=>200,'body'=>['choices'=>[['message'=>['content'=>'گزارش ساختگی برای تست']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20,'total_tokens'=>120]]];};
    $service=new AiReports($store,$fake);$provider=$service->saveProvider($grant,['name'=>'Fixture API','model'=>'fixture-model','key'=>'sk-synthetic-fixture-key-12345','projects'=>[$project['id']],'users'=>['limited'],'daily_tokens'=>1000000,'max_output'=>500,'platform'=>1,'input_rate'=>1000000,'output_rate'=>2000000],[$project['id']]);
    $store->transaction(static function(&$s)use($grant){$s['wallets'][$grant['owner']]=['balance'=>1000000,'reserved'=>0];});
    $schedule=$service->schedule($project,$grant,['title'=>'Fixture report','prompt'=>'Analyze exact counts','sources'=>['products'],'provider'=>$provider,'frequency'=>'weekly','weekday'=>6,'clock'=>'12:00']);
    $r=$service->run($schedule,$grant,'same-slot');checkAi($calls===1&&$r['charge_rial']===140,'actual priced usage');$service->run($schedule,$grant,'same-slot');checkAi($calls===1,'duplicate run did not call API');
    $s=$store->read();checkAi($s['wallets'][$grant['owner']]['balance']===999860&&$s['wallets'][$grant['owner']]['reserved']===0,'actual settlement');checkAi($s['provider_usage'][$provider][gmdate('Y-m-d')]===120,'actual token accounting');
    $report=(new AiContent($store))->getReport($project,$r['id']);checkAi(isset($report['snapshot']['sources'][0]['exact_counts']),'snapshot provenance');
    $fail=new AiReports($store,static function(){throw new RuntimeException('synthetic connection lost');});try{$fail->run($schedule,$grant,'ambiguous-slot');throw new LogicException('expected failure');}catch(RuntimeException $e){}
    $s=$store->read();$key=hash('sha256',$schedule.'|ambiguous-slot');checkAi($s['reservations'][$key]['state']==='uncertain'&&$s['wallets'][$grant['owner']]['reserved']>0,'ambiguous result held');$before=$s['wallets'][$grant['owner']]['balance'];$service->resolve($key,0);$s=$store->read();checkAi($s['wallets'][$grant['owner']]['reserved']===0&&$s['wallets'][$grant['owner']]['balance']===$before,'review releases only reservation');
    $reject=new AiReports($store,static fn()=>['status'=>429,'body'=>[]]);try{$reject->run($schedule,$grant,'reject-slot');throw new LogicException('expected 429');}catch(RuntimeException $e){}$s=$store->read();checkAi($s['wallets'][$grant['owner']]['reserved']===0,'definite rejection releases credit');
    $store->transaction(static function(&$s)use($provider){$s['providers'][$provider]['users']=[];});$p=$store->read()['providers'][$provider];checkAi(!AiReports::eligible($p,['project'=>'p','owner'=>'foreign','username'=>'limited']),'share removal applied');checkAi(!AiReports::eligible($p,['project'=>'foreign','owner'=>$grant['owner'],'username'=>$grant['username']]),'owner still project scoped');
    $store->transaction(static function(&$s)use($grant){AiBilling::reserve($s,$grant['owner'],'p',999000,'budget-one');});try{$store->transaction(static function(&$s)use($grant){AiBilling::reserve($s,$grant['owner'],'p',2000,'budget-two');});throw new LogicException('expected insufficient balance');}catch(RuntimeException $e){}
    $store->transaction(static function(&$s){AiBilling::settle($s,'budget-one',0);});
    $encrypted=$store->encrypt('fixture-secret');checkAi($store->decrypt($encrypted)==='fixture-secret'&&strpos($encrypted,'fixture-secret')===false,'credential encryption');
    $next=AiReports::next('daily','12:00',0,strtotime('2026-10-19 09:00:00 UTC'));checkAi(gmdate('Y-m-d H:i',$next)==='2026-10-20 08:30','Tehran schedule');
    // Gateway is stubbed; no real money or network calls.
    saveSiteSettings(['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'synthetic-merchant','zarinpal_currency'=>'IRT','zarinpal_sandbox'=>true]);$gatewayCalls=0;
    $gateway=static function($url,$payload)use(&$gatewayCalls){$gatewayCalls++;return ['status'=>200,'body'=>['data'=>strpos($url,'request.json')!==false?['code'=>100,'authority'=>'SYNTHETIC_AUTHORITY_12345']:['code'=>100,'ref_id'=>'synthetic-ref-1']]];};
    $billing=new AiBilling($store,$gateway);$balance=AiBilling::available($store->read(),$grant['owner']);$url=$billing->checkout($grant,100001);checkAi(strpos($url,'https://sandbox.zarinpal.com/')===0,'sandbox checkout');$s=$store->read();$payment=end($s['payments']);checkAi($payment['amount']===10001&&$payment['credit']===100010,'IRT rounding');
    try{$billing->verify($payment['id'],'wrong-authority','OK');throw new LogicException('expected forged callback reject');}catch(RuntimeException $e){}
    $billing->verify($payment['id'],$payment['authority'],'OK');$billing->verify($payment['id'],$payment['authority'],'OK');checkAi($gatewayCalls===2,'payment verified once');checkAi(AiBilling::available($store->read(),$grant['owner'])===$balance,'sandbox cannot mint real credit');
    saveSiteSettings(['zarinpal_enabled'=>true,'zarinpal_merchant_id'=>'synthetic-merchant','zarinpal_currency'=>'IRR','zarinpal_sandbox'=>false]);$billing->checkout($grant,10000);$s=$store->read();$payment=end($s['payments']);$billing->verify($payment['id'],$payment['authority'],'OK');$billing->verify($payment['id'],$payment['authority'],'OK');checkAi(AiBilling::available($store->read(),$grant['owner'])===$balance+10000,'real credit exactly once');
    return ['api_charge'=>140,'idempotent'=>true,'uncertain_reservation'=>true,'share_acl'=>true,'overdraft_blocked'=>true,'sandbox_isolated'=>true,'payment_idempotent'=>true];
});echo json_encode($result);
