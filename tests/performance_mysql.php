<?php
/** Optional real MariaDB/MySQL regression. Only creates disposable databases and copied bootstrap files. */
$port=getenv('PERFORMANCE_TEST_MYSQL_PORT');
if(!$port){echo "SKIP: PERFORMANCE_TEST_MYSQL_PORT is not configured\n";exit;}
mysqli_report(MYSQLI_REPORT_OFF);
$host=getenv('PERFORMANCE_TEST_MYSQL_HOST')?:'127.0.0.1';$user=getenv('PERFORMANCE_TEST_MYSQL_USER')?:'root';$password=getenv('PERFORMANCE_TEST_MYSQL_PASSWORD')?:'';
$admin=new mysqli($host,$user,$password,'',(int)$port);
if($admin->connect_error)throw new RuntimeException('Disposable test server is unavailable.');
$name='perf_fixture_'.bin2hex(random_bytes(6));$dir=sys_get_temp_dir().'/'.$name;
mkdir($dir.'/panel/inc',0700,true);mkdir($dir.'/panel/db',0700,true);
foreach(['db.php','DatabaseConfig.php','ProjectLifecycle.php','DatabaseSchema.php'] as $file)copy(__DIR__.'/../panel/inc/'.$file,$dir.'/panel/inc/'.$file);
$config=['driver'=>'mysql','host'=>$host.':'.$port,'dbname'=>$name,'user'=>$user,'pass'=>$password,'allow_fallback'=>false];
file_put_contents($dir.'/panel/db/database.local.php','<?php return '.var_export($config,true).';');chmod($dir.'/panel/db/database.local.php',0600);
$code=<<<'CODE'
$start=hrtime(true);define('DB_PATH',$argv[1].'/absent-legacy.json');require $argv[1].'/panel/inc/db.php';$c=getConn();
$alter=$c->query("SHOW SESSION STATUS LIKE 'Com_alter_table'")->fetch_assoc()['Value'];
$create=$c->query("SHOW SESSION STATUS LIKE 'Com_create_table'")->fetch_assoc()['Value'];
$row=$c->query("SELECT name FROM products WHERE project_id='reader'")->fetch_assoc();
echo json_encode(['ms'=>(hrtime(true)-$start)/1e6,'alter'=>(int)$alter,'create'=>(int)$create,'name'=>$row['name']??null]);
CODE;
$launch=static function()use($code,$dir){$pipes=[];$p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file()?:'', '-r',$code,$dir],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Cannot start fixture process.');fclose($pipes[0]);return [$p,$pipes];};
$finish=static function($process){[$p,$pipes]=$process;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_get_status($p);$exit=proc_close($p);if($exit<0)$exit=$status['exitcode'];if($exit!==0)throw new RuntimeException('Fixture bootstrap failed: '.$err);$r=json_decode($out,true);if(!is_array($r))throw new RuntimeException('Fixture receipt invalid.');return $r;};
try{
    if(!$admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4'))throw new RuntimeException('Cannot create fixture database.');$admin->select_db($name);
    $finish($launch());
    if(!$admin->query("INSERT INTO products(id,project_id,name) VALUES ('r','reader','Original reader'),('w','writer','Original writer')"))throw new RuntimeException('Cannot create fixture rows.');
    $admin->begin_transaction();$admin->query("UPDATE products SET name='Uncommitted writer' WHERE id='w'");
    $reader=$launch();usleep(1500000);$running=proc_get_status($reader[0]);$admin->rollback();$result=$finish($reader);
    if($running['running']||$result['ms']>=1400||$result['name']!=='Original reader')throw new RuntimeException('Cross-project reader waited for the writer.');
    if($result['alter']!==0||$result['create']!==0)throw new RuntimeException('Warm request repeated schema DDL.');
    $index=$admin->query("EXPLAIN SELECT * FROM products WHERE project_id='reader'")->fetch_assoc();
    if(strpos($index['possible_keys']??'','idx_maarksho_products_project')===false)throw new RuntimeException('Project lookup index missing.');
    echo 'PASS: warm bootstrap performs zero CREATE/ALTER; independent reader finished in '.round($result['ms'],2).' ms while another project held a write transaction; project index available.'.PHP_EOL;
}finally{
    $admin->rollback();$admin->query('DROP DATABASE IF EXISTS `'.$name.'`');
    foreach(glob($dir.'/panel/inc/*')?:[] as $file)unlink($file);@unlink($dir.'/panel/db/database.local.php');@rmdir($dir.'/panel/inc');@rmdir($dir.'/panel/db');@rmdir($dir.'/panel');@rmdir($dir);
}
