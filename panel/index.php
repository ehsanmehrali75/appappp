<?php
/** One login screen and one credential store for every panel entry point. */
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/ProjectOnboarding.php';
header('Cache-Control: no-store');
redirect(isUser() ? ProjectOnboarding::landing(canAccessManagement()?'/panel/admin.php':'/panel/portal.php') : '/?action=login');
