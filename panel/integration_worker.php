<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/inc/ai/Bootstrap.php';require_once __DIR__.'/inc/OperationalHealth.php';
$health=null;$run=null;$monitorFailed=false;
try{$health=new OperationalHealth();$run=$health->begin('integrations');}catch(Throwable $e){$monitorFailed=true;fwrite(STDERR,"Integration health storage unavailable.\n");}
try{$store=new AiStore();$state=$store->read();$projectIds=[];foreach($state['social_jobs']??[] as $j)$projectIds[$j['project']]=true;foreach(array_keys($projectIds) as $id){$p=getCurrentProject($id,true);if($p)(new AiSocial($store))->reconcile($p);}echo 'Composio batches: '.(new ComposioBridge($store))->tick().PHP_EOL;echo 'Google batches: '.(new AiGoogle($store))->tick().PHP_EOL;}
catch(Throwable $e){if($run)try{$health->finish('integrations',$run,false);}catch(Throwable $ignored){}fwrite(STDERR,"Integration worker failed; check configuration.\n");exit(1);}
if($run)try{$health->finish('integrations',$run,true);}catch(Throwable $e){$monitorFailed=true;fwrite(STDERR,"Integration health completion write failed.\n");}
exit($monitorFailed?1:0);
