<?php
$dir=sys_get_temp_dir().'/system-admin-test-'.bin2hex(random_bytes(8));mkdir($dir);
define('SYSTEM_ADMINS_FILE',$dir.'/accounts.php');
define('FEATURE_FLAGS_FILE',$dir.'/flags.json');
require __DIR__.'/../panel/inc/SystemAdmins.php';
require __DIR__.'/../panel/inc/FeatureFlags.php';
register_shutdown_function(function() use($dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
function verify($v,$m){if(!$v)throw new RuntimeException($m);}
SystemAdmins::create('test-owner','TestPassword123!',true);
verify(SystemAdmins::authenticate('test-owner','incorrect')===null,'wrong password');
$actor=SystemAdmins::authenticate('test-owner','TestPassword123!');verify($actor['role']==='owner','owner role');
verify(strpos(file_get_contents(SYSTEM_ADMINS_FILE),'TestPassword123!')===false,'plaintext not stored');
session_start();$_SESSION['user']=$actor;
verify(FeatureFlags::isMainAdmin(),'main admin recognition');
FeatureFlags::saveConfig(['size_studio'=>'disabled']);
verify(FeatureFlags::isAvailable('size_studio'),'unrestricted bypass of actual disabled feature');
try{SystemAdmins::create('test-owner','NewPassword123!',true);throw new LogicException('overwritten');}catch(RuntimeException $e){}
verify(SystemAdmins::authenticate('test-owner','TestPassword123!')!==null,'existing password retained');
verify(SystemAdmins::authenticateGlobal('legacy-root','LegacyPassword123!',[['username'=>'legacy-root','password'=>password_hash('LegacyPassword123!',PASSWORD_DEFAULT)]])['role']==='superadmin','legacy hashed global account can reauthenticate');
verify(SystemAdmins::authenticateGlobal('legacy-root','WrongPassword123!',[['username'=>'legacy-root','password'=>'LegacyPassword123!']])===null,'wrong legacy credential rejected');
verify(SystemAdmins::authenticateGlobal('project-admin','ProjectPassword123!',[])===null,'project-only account never gains global role');
verify(SystemAdmins::authenticateGlobal('legacy-root','LegacyPassword123!',[['username'=>'legacy-root','password'=>'LegacyPassword123!']])['role']==='superadmin','legacy plaintext compatibility');
echo "System administrator authentication and storage checks passed\n";
