<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../panel/inc/functions.php';
require __DIR__.'/../panel/inc/SupportTickets.php';
(new SupportTickets())->expire();
echo "Expired support quotes cancelled.\n";
