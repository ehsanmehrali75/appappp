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
    check(!FeatureFlags::isAvailable('size_chart'), 'legacy alias retains main administrator restriction');
    check(!AccessControl::save([]), 'non-main cannot save policy');
    check(!FeatureFlags::saveConfig([]), 'non-main cannot change feature states');
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
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
FeatureFlags::saveConfig([]);
AccessControl::save(['vm_studio' => ['use' => ['users' => 'alice']], 'seo_intel' => ['view' => ['users' => 'alice']]]);
$_SESSION['user'] = ['username' => 'bob', 'role' => 'manager'];
check(AccessControl::allowed('vm_layout', 'view'), 'parent use restriction does not hide child');
check(!AccessControl::allowed('vm_layout', 'use'), 'child operation inherits parent use restriction');
check(!AccessControl::allowed('seo_intel_keywords', 'view'), 'child inherits parent view audience');
check(!AccessControl::allowed('vm_layout', 'invalid'), 'unknown access modes denied');
$_SESSION['user'] = ['username' => 'alice', 'role' => 'manager'];
check(AccessControl::allowed('vm_layout', 'use'), 'matching parent use grant retained');
check(AccessControl::allowed('seo_intel_keywords', 'view'), 'matching parent view grant retained');
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
AccessControl::save(['products' => ['view' => ['roles' => 'manager'], 'use' => ['users' => 'alice']]]);
$_SESSION['user'] = ['username' => 'bob', 'role' => 'manager'];
check(AccessControl::allowed('reference_photo', 'view'), 'reference-only operator inherits product view audience');
check(!AccessControl::allowed('reference_photo', 'use'), 'granular reference permission cannot bypass product use audience');
$_SESSION['user'] = ['username' => 'alice', 'role' => 'editor'];
check(!AccessControl::allowed('approve_content', 'use'), 'parent use grant cannot bypass parent view denial');
foreach (['size_chart' => 'size_studio', 'vm' => 'vm_studio', 'seo' => 'seo_classic', 'sale' => 'sale_analysis', 'prompt_hub' => 'prompthub'] as $alias => $canonical) {
    check(AccessControl::featureKey($alias) === $canonical, 'canonical feature name: ' . $alias);
    check(AccessControl::moduleVisibility($canonical, ['active_modules' => [$alias]]) === 'active', 'legacy project module remains enabled: ' . $alias);
    check(AccessControl::moduleVisibility($alias, ['module_visibility' => [$canonical => 'admin_only']]) === 'admin_only', 'canonical restriction covers legacy project name: ' . $alias);
}
check(AccessControl::moduleVisibility('size_chart', ['module_visibility' => ['size_chart' => 'active', 'size_studio' => 'disabled']]) === 'disabled', 'conflicting module names retain stricter state');
check(AccessControl::moduleVisibility('vm_layout', ['active_modules' => ['vm']]) === 'active', 'legacy parent module enables its children');
check(AccessControl::moduleVisibility('vm_layout', ['module_visibility' => ['vm_studio' => 'admin_only', 'vm_layout' => 'active']]) === 'admin_only', 'child project setting cannot bypass main-only parent');
check(AccessControl::moduleVisibility('reference_photo', ['module_visibility' => ['products' => 'disabled', 'reference_photo' => 'active']]) === 'disabled', 'granular project setting cannot bypass products module');
check(AccessControl::moduleVisibility('products', ['module_visibility' => ['products' => 'unknown']]) === 'disabled', 'invalid visibility denied');
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
$before = file_get_contents(ACCESS_POLICY_FILE);
check(!AccessControl::save(['products' => 'broken']), 'malformed submitted policy rejected');
check(file_get_contents(ACCESS_POLICY_FILE) === $before, 'malformed policy does not replace saved rules');
check(!FeatureFlags::saveConfig(['products' => 'unknown']), 'invalid submitted feature state rejected');
foreach ([['products' => ['view' => 'broken']], ['products' => ['view' => ['roles' => 'manager']]], ['products' => ['view' => ['users' => [null]]]]] as $broken) {
    file_put_contents(ACCESS_POLICY_FILE, json_encode($broken));
    $_SESSION['user'] = ['username' => 'bob', 'role' => 'project_admin'];
    check(!AccessControl::allowed('products'), 'malformed stored policy fails closed');
}
file_put_contents(ACCESS_POLICY_FILE, 'broken');
$_SESSION['user'] = ['username' => 'bob', 'role' => 'project_admin'];
check(!AccessControl::allowed('products'), 'corrupt policies fail closed');
unlink(ACCESS_POLICY_FILE);
// Simulate a fresh request: malformed flags must keep ordinary users out while
// preserving an administrator's ability to repair configuration.
$cache = new ReflectionProperty(FeatureFlags::class, 'cache');
$cache->setAccessible(true);
file_put_contents(FEATURE_FLAGS_FILE, 'broken');
$cache->setValue(null, null);
check(!FeatureFlags::isAvailable('products'), 'corrupt flags do not reopen administrator-only sections');
$_SESSION['user'] = ['username' => 'owner', 'role' => 'main_admin'];
check(FeatureFlags::isAvailable('products'), 'administrator can inspect after flags corruption');
FeatureFlags::saveConfig([]);
file_put_contents(FEATURE_FLAGS_FILE, json_encode(['size_studio' => 'active', 'size_chart' => 'disabled', 'calendar' => 'broken']));
$cache->setValue(null, null);
$_SESSION['user'] = ['username' => 'bob', 'role' => 'project_admin'];
check(!FeatureFlags::isAvailable('size_studio'), 'legacy flag name cannot reopen canonical disabled section');
check(!FeatureFlags::isAvailable('calendar'), 'invalid stored feature state restricted to administrators');
file_put_contents(ACCESS_POLICY_FILE, json_encode(['size_chart' => ['view' => ['users' => ['alice']]]]));
check(!AccessControl::allowed('size_studio'), 'legacy audience applies to canonical feature');
$_SESSION['user'] = ['username' => 'alice', 'role' => 'editor'];
check(AccessControl::allowed('size_studio'), 'legacy audience retains explicitly granted user');
file_put_contents(ACCESS_POLICY_FILE, json_encode(['size_chart' => ['view' => ['users' => ['alice']]], 'size_studio' => ['view' => ['users' => ['bob']]]]));
check(!AccessControl::allowed('size_studio'), 'conflicting persisted aliases keep both audience restrictions');
echo "$count access control checks passed\n";
