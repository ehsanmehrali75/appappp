<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/inc/ai/Bootstrap.php';
try{$count=(new AiReports(new AiStore()))->tick();echo 'Schedules checked: '.$count.PHP_EOL;}catch(Throwable $e){fwrite(STDERR,"AI worker failed; check private state and configuration.\n");exit(1);}
