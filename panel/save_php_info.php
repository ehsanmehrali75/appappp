<?php
ob_start();
phpinfo();
$info = ob_get_clean();
file_put_contents('php_info.txt', $info);
echo "PHP Info saved to php_info.txt\n";
