<?php
/** Keep the legacy root API URL on the same authorization path as the panel. */
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/panel/api.php';
require __DIR__.'/panel/api.php';
