<?php
/**
 * Root Loader for Functions
 */
if (file_exists(__DIR__ . '/../panel/inc/functions.php')) {
    require_once __DIR__ . '/../panel/inc/functions.php';
} elseif (file_exists(__DIR__ . '/panel/inc/functions.php')) {
    require_once __DIR__ . '/panel/inc/functions.php';
}
