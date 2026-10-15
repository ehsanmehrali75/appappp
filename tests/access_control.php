<?php
// Run: php tests/access_control.php. Uses temporary policy files only.
$dir = sys_get_temp_dir() . '/maarksho-acl-' . bin2hex(random_bytes(6));
mkdir($dir);
define('FEATURE_FLAGS_FILE', $dir . '/flags.json');
define('ACCESS_POLICY_FILE', $dir . '/policy.json');
require __DIR__ . '/../panel/inc/FeatureFlags.php';
register_shutdown_function(function() use ($dir) { foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir); });
session_start();
$count = 0;
function check($ok, $name) { global $count; if (!$ok) throw new RuntimeException($name); $count++; }
$_SESSION['user'] = ['username' => 'admin', 'role' => 'editor'];
check(!FeatureFlags::isMainAdmin(), 'username admin is not a privilege');
foreach (['main_admin', 'superadmin'] as $role) {
    $_SESSION['user'] = ['username' => 'owner', 'role' => $role];
    check(FeatureFlags::isMainAdmin(), 'role recognized');
}
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
check(FeatureFlags::saveConfig(['size_studio' => 'maintenance']), 'save feature restriction');
check(AccessControl::save(['size_studio' => ['view' => ['roles' => 'project_admin, manager', 'users' => 'alice', 'projects' => 'p1'], 'use' => ['users' => 'alice']]]), 'save audience');
foreach (['project_admin', 'manager', 'editor', 'viewer'] as $role) {
    $_SESSION['user'] = ['username' => 'alice', 'role' => $role];
    $_SESSION['project_id'] = 'p1';
    check(!FeatureFlags::isAvailable('size_studio'), 'main admin restriction wins over audience');
    check(!AccessControl::save([]), 'non-main cannot save policy');
}
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
check(FeatureFlags::saveConfig([]), 'restore active');
$_SESSION['user'] = ['username' => 'bob', 'role' => 'manager'];
$_SESSION['project_id'] = 'p2';
check(AccessControl::allowed('size_studio', 'view'), 'manager can view');
check(!AccessControl::allowed('size_studio', 'use'), 'manager cannot use');
$_SESSION['user'] = ['username' => 'alice', 'role' => 'editor'];
check(AccessControl::allowed('size_studio', 'use'), 'named user can use');
$_SESSION['user'] = ['username' => 'bob', 'role' => 'viewer'];
check(!AccessControl::allowed('size_chart'), 'alias does not bypass audience');
$_SESSION['project_id'] = 'p1';
check(AccessControl::allowed('size_studio'), 'project grant');
check(!AccessControl::allowed('size_studio', 'use'), 'view grant does not override use restriction');
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
FeatureFlags::saveConfig(['size_studio' => 'disabled']);
check(!FeatureFlags::isAvailable('size_studio'), 'disabled also blocks main');
FeatureFlags::saveConfig(['seo_intel' => 'maintenance', 'vm_studio' => 'disabled']);
$_SESSION['user'] = ['username' => 'bob', 'role' => 'project_admin'];
check(!FeatureFlags::isAvailable('seo_intel_keywords'), 'child cannot bypass main-only parent');
check(!FeatureFlags::isAvailable('vm_layout'), 'child cannot bypass disabled parent');
file_put_contents(ACCESS_POLICY_FILE, 'broken');
$_SESSION['user'] = ['username' => 'bob', 'role' => 'project_admin'];
check(!AccessControl::allowed('products'), 'corrupt policies fail closed');
echo "$count access control checks passed\n";
