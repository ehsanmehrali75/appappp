<?php
$dir=sys_get_temp_dir().'/owner-recovery-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
define('SYSTEM_ADMINS_FILE',$dir.'/admins.php');
require __DIR__.'/../panel/inc/SystemAdmins.php';
register_shutdown_function(function()use($dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
$count=0;
function checkRecovery($value,$message){global $count;if(!$value)throw new RuntimeException($message);$count++;}
function rejectRecovery($fn,$message){try{$fn();}catch(RuntimeException $e){checkRecovery(true,$message);return;}throw new LogicException($message);}
$token=bin2hex(random_bytes(32));$file=SystemAdmins::recoveryFile();
rejectRecovery(fn()=>SystemAdmins::recoverFirstOwner($token,'first-owner','FixturePassword123!'),'missing host file denied');
$source=SystemAdmins::recoveryKeySource($token,time()+3600);
checkRecovery(strpos($source,$token)===false,'file contains hash only');
file_put_contents($file,$source);
rejectRecovery(fn()=>SystemAdmins::recoverFirstOwner(str_repeat('0',64),'first-owner','FixturePassword123!'),'other session key denied');
rejectRecovery(fn()=>SystemAdmins::recoverFirstOwner($token,'first-owner','short'),'invalid password denied');
checkRecovery(is_file($file) && !SystemAdmins::hasOwner(),'failed creation preserves key and ownerless state');
file_put_contents($file,SystemAdmins::recoveryKeySource($token,time()-1));
rejectRecovery(fn()=>SystemAdmins::recoverFirstOwner($token,'first-owner','FixturePassword123!'),'expired key denied');
file_put_contents($file,SystemAdmins::recoveryKeySource($token,time()+3600));
$account=SystemAdmins::recoverFirstOwner($token,'first-owner','FixturePassword123!');
checkRecovery($account['role']==='owner','host proof creates unrestricted owner');
checkRecovery(!is_file($file),'host key removed');
checkRecovery(SystemAdmins::authenticate('first-owner','FixturePassword123!')['role']==='owner','new owner authenticates');
file_put_contents($file,$source);
rejectRecovery(fn()=>SystemAdmins::recoverFirstOwner($token,'another-owner','FixturePassword123!'),'replay denied even if key uploaded again');
checkRecovery(count(SystemAdmins::all())===1,'no extra owners');
echo "$count first-owner recovery checks passed\n";
