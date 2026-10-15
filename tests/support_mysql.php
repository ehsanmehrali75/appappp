<?php
require __DIR__.'/../panel/inc/SupportTickets.php';
if (!getenv('SUPPORT_TEST_MYSQL_PORT')) { echo "SKIPPED: set SUPPORT_TEST_MYSQL_PORT for an isolated test server.\n"; exit; }
mysqli_report(MYSQLI_REPORT_OFF);
$db=new mysqli(getenv('SUPPORT_TEST_MYSQL_HOST') ?: '127.0.0.1',getenv('SUPPORT_TEST_MYSQL_USER') ?: 'root',getenv('SUPPORT_TEST_MYSQL_PASSWORD') ?: '','',(int)getenv('SUPPORT_TEST_MYSQL_PORT'));if($db->connect_error)throw new RuntimeException('Test DB not ready');
$db->set_charset('utf8mb4');
$name='support_test_'.bin2hex(random_bytes(4));$db->query("CREATE DATABASE `$name` CHARACTER SET latin1");$db->select_db($name);
$dir=sys_get_temp_dir().'/'.$name;mkdir($dir,0700);
register_shutdown_function(function()use($db,$name,$dir){$db->query("DROP DATABASE `$name`");foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
// Reproduce the deployed inherited-latin1 schema before running the migration.
$db->query('CREATE TABLE support_tickets (id VARCHAR(64) PRIMARY KEY, project_id VARCHAR(128) NOT NULL, creator VARCHAR(100) NOT NULL, subject VARCHAR(255) NOT NULL, category VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, quote_amount BIGINT DEFAULT 0, quote_version INT DEFAULT 0, quote_expires_at BIGINT DEFAULT 0, accepted_at BIGINT DEFAULT 0, estimate TEXT, finish_at BIGINT DEFAULT 0, cancel_reason VARCHAR(50), created_at BIGINT, updated_at BIGINT)');
$stmt=$db->prepare('INSERT INTO support_tickets (id,project_id,creator,subject,category,status) VALUES (?,?,?,?,?,?)');
$vars=['before','p1','alice','درخواست فارسی 🎨','bug','pending_review'];$stmt->bind_param('ssssss',...$vars);
if($stmt->execute() || $stmt->errno!==1366)throw new RuntimeException('Expected charset reproduction did not occur');
$stmt->close();
$s=new SupportTickets($db,$dir);
$id=$s->create('p1','alice','درخواست فارسی 🎨','bug','متن فارسی و لاگ خطا ✅');
$t=$s->get($id,'alice',false,'p1');if($t['subject']!=='درخواست فارسی 🎨' || $t['messages'][0]['body']!=='متن فارسی و لاگ خطا ✅')throw new RuntimeException('Unicode round trip failed');
$s->offer($id,'root',true,'125000','سه روز کاری',0);$s->decide($id,'alice','p1',1,true);$s->transition($id,'root',true,'queued');$s->reply($id,'root',true,null,'انجام شد ✅');$s->transition($id,'root',true,'resolved');
if($s->get($id,'alice',false,'p1')['status']!=='resolved')throw new RuntimeException('Workflow failed');
// Reopening is idempotent and preserves tickets.
$again=new SupportTickets($db,$dir);if(count($again->list('alice',false,'p1'))!==1)throw new RuntimeException('Migration lost tickets');
echo "MariaDB passed: reproduced errno1366, automatic existing-latin1 migration, Persian/emoji roundtrip, approval/execution/resolution, idempotency.\n";
