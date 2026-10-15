<?php
// Retired diagnostic endpoint. Use the authenticated system monitoring page.
http_response_code(404);
header('Cache-Control: no-store');
exit;
