<?php
/** Legacy entry point: all WordPress setup, data and writes live in one project-scoped controller. */
$query=[];
foreach(['project','view','kind','page','download_plugin','download_plan','stale','gsc','ga4'] as $key)if(isset($_GET[$key])&&is_string($_GET[$key]))$query[$key]=$_GET[$key];
header('Location: /panel/wp-crm.php'.($query?'?'.http_build_query($query):''),true,303);
exit;
