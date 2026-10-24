<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/inc/ai/Bootstrap.php';
require_once __DIR__.'/inc/saas/Nahal.php';require_once __DIR__.'/inc/saas/Ehsan.php';
try{$analyst=new Ehsan();$analyst->tick();$analyst->runOne();$analyst->notifyOne();$service=new Nahal();echo $service->runOne()?"One employee job processed\n":"No eligible job\n";}catch(Throwable $e){fwrite(STDERR,"Employee worker failed; inspect private job state.\n");exit(1);}
