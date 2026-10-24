<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/inc/ai/Bootstrap.php';
require_once __DIR__.'/inc/saas/Nahal.php';require_once __DIR__.'/inc/saas/Ehsan.php';require_once __DIR__.'/inc/saas/Aria.php';require_once __DIR__.'/inc/saas/AriaPublisher.php';
$failed=false;
foreach(['aria'=>static function(){$s=new Aria();$s->tick();$s->runOne();$s->notifyOne();},'publication'=>static function(){(new AriaPublisher())->tick();},'ehsan'=>static function(){$s=new Ehsan();$s->tick();$s->runOne();$s->notifyOne();},'nahal'=>static function(){(new Nahal())->runOne();}] as $name=>$work){
    try{$work();}catch(Throwable $e){$failed=true;fwrite(STDERR,$name." worker failed; inspect private state.\n");}
}
echo "Employee worker cycle finished\n";exit($failed?1:0);
