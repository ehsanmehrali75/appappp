<?php
/** Only runs against an explicitly supplied disposable MySQL instance. */
if(!getenv('MEMBERS_TEST_MYSQL_PORT')){echo "SKIPPED: set MEMBERS_TEST_MYSQL_PORT for an isolated test server.\n";exit;}
mysqli_report(MYSQLI_REPORT_OFF);
$db=new mysqli('127.0.0.1','root',getenv('MEMBERS_TEST_MYSQL_PASSWORD')?:'','',(int)getenv('MEMBERS_TEST_MYSQL_PORT'));
if($db->connect_error)throw new RuntimeException('Test DB not ready');
$db->set_charset('utf8mb4');$name='members_test_'.bin2hex(random_bytes(4));
$db->query("CREATE DATABASE `$name` CHARACTER SET latin1");$db->select_db($name);
register_shutdown_function(static function()use($db,$name){$db->query("DROP DATABASE `$name`");});
$db->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$db->query('CREATE TABLE projects (id VARCHAR(128) PRIMARY KEY) ENGINE=InnoDB');
$db->query("INSERT INTO projects VALUES ('p'),('unrelated')");
$db->query("CREATE TABLE project_users (project_id VARCHAR(128),username VARCHAR(50),password VARCHAR(50),role ENUM('editor','viewer'),permissions VARCHAR(50),mobile VARCHAR(50),telegram_chat_id VARCHAR(100),bale_chat_id VARCHAR(100),PRIMARY KEY(project_id,username)) ENGINE=MyISAM DEFAULT CHARSET=latin1");
$db->query("INSERT INTO project_users VALUES ('p','old','legacy-password','viewer','[]','','','')");
$failed=$db->prepare('INSERT INTO project_users(project_id,username,password,role,permissions) VALUES (?,?,?,?,?)');
$pid='p';$username='fixture-new';$password='short-fixture';$role='manager';$permissions='[]';
$failed->bind_param('sssss',$pid,$username,$password,$role,$permissions);
if($failed->execute()||$failed->errno!==1265)throw new RuntimeException('Expected manager role rejection did not reproduce');
$role='viewer';$password=password_hash('Synthetic-password-123!',PASSWORD_DEFAULT);
if($failed->execute()||$failed->errno!==1406)throw new RuntimeException('Expected old password width rejection did not reproduce');$failed->close();
function getConn(){return $GLOBALS['db'];}function isSqlActive(){return true;}
class ProjectLifecycle{public static function isDeleted($id){return $id==='unrelated';}}
class ProjectPermissions{public static function canManageMember($member){return true;}}
require __DIR__.'/../panel/inc/ProjectMembers.php';
$member=['username'=>'کاربر آزمایشی','password'=>$password,'role'=>'manager','permissions'=>array_fill(0,15,'products'),'panel_access'=>['portal'],'mobile'=>'09120000000'];
$project=['id'=>'p','users'=>[$member]];
ProjectMembers::save([$project,['id'=>'unrelated','users'=>[]]],[$member['username']]);
$row=$db->query('SELECT * FROM project_users WHERE username<>"old"')->fetch_assoc();
if(!$row||$row['username']!==$member['username']||$row['role']!=='manager'||json_decode($row['permissions'],true)!==$member['permissions']||!password_verify('Synthetic-password-123!',$row['password'])||json_decode($row['panel_access'],true)!==['portal'])throw new RuntimeException('Legacy migration or Persian roundtrip failed');
$table=$db->query("SHOW TABLE STATUS LIKE 'project_users'")->fetch_assoc();if(strcasecmp($table['Engine'],'InnoDB')!==0)throw new RuntimeException('Membership table not transactional');
// Updates must not delete/reinsert a row referenced by other records.
$db->query('CREATE TABLE member_links(project_id VARCHAR(128),username VARCHAR(50),FOREIGN KEY(project_id,username) REFERENCES project_users(project_id,username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$q=$db->prepare('INSERT INTO member_links VALUES (?,?)');$q->bind_param('ss',$pid,$member['username']);if(!$q->execute())throw new RuntimeException('FK fixture failed');$q->close();
$project['users'][0]['mobile']='09123333333';ProjectMembers::save([$project],[$member['username']]);
if($db->query('SELECT mobile FROM project_users WHERE username<>"old"')->fetch_assoc()['mobile']!=='09123333333')throw new RuntimeException('Foreign-key-safe update failed');
// A later failure rolls back earlier membership writes in the same transaction.
$other=$member;$other['username']='blocked';$project['users'][]=$other;$project['users'][0]['mobile']='not-committed';
$db->query("CREATE TRIGGER reject_member BEFORE INSERT ON project_users FOR EACH ROW BEGIN IF NEW.username='blocked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'; END IF; END");
$rejected=false;try{ProjectMembers::save([$project],[$member['username'],'blocked']);}catch(Throwable $e){$rejected=true;$tracking=ProjectMembers::report($e);if(!preg_match('/^[a-f0-9]{12}$/',$tracking))throw new RuntimeException('Tracking missing');}
if(!$rejected||$db->query('SELECT mobile FROM project_users WHERE username<>"old"')->fetch_assoc()['mobile']!=='09123333333')throw new RuntimeException('Atomic rollback failed');
echo "MariaDB passed: reproduced old schema rejection; password/role/permissions migration, Persian/Unicode, panel access, unrelated deleted project isolation, FK-safe update, atomic rollback and safe diagnostics.\n";
