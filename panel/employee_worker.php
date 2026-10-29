<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/inc/ai/Bootstrap.php';require_once __DIR__.'/inc/OperationalHealth.php';require_once __DIR__.'/inc/saas/ProjectSms.php';
require_once __DIR__.'/inc/saas/Nahal.php';require_once __DIR__.'/inc/saas/Ehsan.php';require_once __DIR__.'/inc/saas/Aria.php';require_once __DIR__.'/inc/saas/AriaPublisher.php';
$failed=false;$health=null;
try{$health=new OperationalHealth();}catch(Throwable $e){$failed=true;fwrite(STDERR,"Worker health storage unavailable.\n");}
foreach(['aria'=>static function(){$s=new Aria();$s->tick();$s->runOne();$s->notifyOne();},'publication'=>static function(){(new AriaPublisher())->tick();},'ehsan'=>static function(){$s=new Ehsan();$s->tick();$s->runOne();$s->notifyOne();},'nahal'=>static function(){(new Nahal())->runOne();},'sms'=>static function(){(new ProjectSms())->reconcileStale();}] as $name=>$work){
    $run=null;$success=true;
    if($health)try{$run=$health->begin($name);}catch(Throwable $e){$failed=true;fwrite(STDERR,"Worker health write failed.\n");}
    try{$work();}catch(Throwable $e){$failed=true;$success=false;fwrite(STDERR,$name." worker failed; inspect private state.\n");}
    if($run)try{$health->finish($name,$run,$success);}catch(Throwable $e){$failed=true;fwrite(STDERR,"Worker health completion write failed.\n");}
}
echo "Employee worker cycle finished\n";exit($failed?1:0);
