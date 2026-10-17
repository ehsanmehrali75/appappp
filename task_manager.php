<?php
/** Keep the legacy URL on the canonical project authorization path. */
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/panel/task_manager.php';
require __DIR__.'/panel/task_manager.php';
