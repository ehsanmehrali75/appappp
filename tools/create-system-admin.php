<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../panel/inc/SystemAdmins.php';
$options = getopt('', ['username:', 'unrestricted']);
$username = $options['username'] ?? '';
fwrite(STDERR, 'Password (hidden): ');
$terminal = function_exists('stream_isatty') && stream_isatty(STDIN);
if ($terminal) system('stty -echo');
try { $password = rtrim(fgets(STDIN), "\r\n"); }
finally { if ($terminal) system('stty echo'); fwrite(STDERR, "\n"); }
try { SystemAdmins::create($username, $password, isset($options['unrestricted'])); echo "System administrator created.\n"; }
catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
