<?php
/** Optional real MySQL/MariaDB packet-limit regression; creates and drops a disposable database. */
$socket=getenv('MAARKSHO_TEST_MYSQL_SOCKET');if(!$socket){echo "SKIP: MAARKSHO_TEST_MYSQL_SOCKET is not configured\n";exit;}
mysqli_report(MYSQLI_REPORT_OFF);
$name='snapshot_fixture_'.bin2hex(random_bytes(6));
function fixtureConnection($database=''){return new mysqli('localhost',getenv('MAARKSHO_TEST_MYSQL_USER')?:'root',getenv('MAARKSHO_TEST_MYSQL_PASSWORD')?:'',$database,0,getenv('MAARKSHO_TEST_MYSQL_SOCKET'));}
$admin=fixtureConnection();if(!$admin->query('CREATE DATABASE `'.$name.'`'))throw new RuntimeException('Cannot create isolated fixture database.');
function getConn(){global $name;static $conn;if(!$conn){$conn=fixtureConnection($name);$conn->set_charset('utf8mb4');}return $conn;}
require __DIR__.'/../panel/inc/InventoryVersions.php';
try{
    InventoryVersions::init();$packet=(int)getConn()->query('SELECT @@max_allowed_packet AS n')->fetch_assoc()['n'];
    if($packet>4194304)throw new RuntimeException('Run this regression with max_allowed_packet <= 4 MiB.');
    $products=[];for($i=0;$i<2500;$i++)$products[]=['id'=>'fixture-'.$i,'name'=>'ERP '.$i,'description'=>str_repeat('متن بزرگ ERP ',180),'stock_total'=>5];
    $raw=json_encode(['planned'=>$products],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $legacy=fixtureConnection($name);$legacy->set_charset('utf8mb4');$legacy->query('CREATE TABLE legacy_packet_fixture (data LONGTEXT)');$q=$legacy->prepare('INSERT INTO legacy_packet_fixture(data) VALUES (?)');$q->bind_param('s',$raw);$ok=@$q->execute();$errno=$legacy->errno;$legacy->close();
    if($ok||$errno!==1153)throw new RuntimeException('Legacy packet-limit failure was not reproduced.');
    $id=bin2hex(random_bytes(32));$v=InventoryVersions::prepare(['id'=>'project-fixture','products'=>[]],'erp',$id,$products,'fixture');
    if(count($v['payload']['planned'])!==count($products)||$v['payload']['planned']['fixture-2499']['description']!==$products[2499]['description'])throw new RuntimeException('Chunked snapshot roundtrip failed.');
    $retry=InventoryVersions::prepare(['id'=>'project-fixture','products'=>[]],'erp',$id,$products,'fixture');
    if($retry['id']!==$id||getConn()->query('SELECT count(*) AS n FROM inventory_import_versions')->fetch_assoc()['n']!=1)throw new RuntimeException('Retry duplicated snapshot.');
    echo 'PASS: legacy SQL error 1153 reproduced; '.strlen($raw).' byte ERP snapshot stored and read with packet limit '.$packet.'; retry is idempotent.'.PHP_EOL;
}finally{$admin->query('DROP DATABASE `'.$name.'`');}
