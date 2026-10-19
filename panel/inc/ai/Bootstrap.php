<?php
require_once dirname(__DIR__).'/functions.php';
require_once dirname(__DIR__).'/ModuleProjectAccess.php';
foreach(['Store','Access','OAuth','Http','Data','Content','Billing','Reports','Social','Hub','Google','IntegrationHealth','HubReports','ComposioBridge','Tools'] as $aiClass)require_once __DIR__.'/'.$aiClass.'.php';
