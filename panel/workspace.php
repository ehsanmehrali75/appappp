<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/ProjectOnboarding.php';
header('Cache-Control: no-store');
if(!isUser())redirect('/?action=login');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    RequestSecurity::enforce();$id=$_POST['select_project']??'';
    if($id==='__central__'){if(!isMainAdmin())AccessControl::deny();unset($_SESSION['project_id'],$_SESSION['project_selection_required']);redirect('/panel/workspace.php?central=1');}
    $p=is_string($id)?getCurrentProject($id,true):null;
    if(!$p||!SessionIdentity::select($p))AccessControl::deny();
    unset($_SESSION['project_selection_required']);
    $setup=$p['settings']['onboarding']??[];if(!empty($setup['created_at'])&&empty($setup['started_at'])&&empty($setup['paused_at'])&&empty($setup['finished_at'])&&(isMainAdmin()||isProjectAdmin()||hasPermission('settings','use')))redirect(ProjectOnboarding::url($id));
    redirect(canAccessManagement()?'/panel/admin.php?project='.rawurlencode($id):'/panel/portal.php?project='.rawurlencode($id));
}
function ws_h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>میزکار مرکزی و انتخاب پروژه</title><link rel="stylesheet" href="/panel/assets/workspace-shell.css"></head><body><main class="workspace-shell"><header><h1>میزکار شما</h1><a href="/logout.php">خروج</a></header><p>برای شروع، پروژهٔ مورد نظر را صریحاً انتخاب کنید. اطلاعات هر پروژه در محیط خودش نمایش داده می‌شود.</p>
<?php if(isMainAdmin()):?><section><h2>مدیریت مرکزی</h2><p>مدیریت سراسری از میزکار پروژه‌ها جداست.</p><form method="post"><button name="select_project" value="__central__">انتخاب مدیریت مرکزی</button></form><?php if(isset($_GET['central'])):?><nav><a href="/panel/admin.php?tab=projects">مدیریت پروژه‌ها</a> · <a href="/panel/admin.php?tab=feature_flags">مجوزها و امکانات سامانه</a> · <a href="/panel/admin.php?tab=markshow_org">سازمان مرکزی</a> · <a href="/panel/admin.php?tab=main_settings">تنظیمات سراسری</a></nav><?php endif;?></section><?php endif;?>
<div class="project-grid"><?php foreach(ProjectSelection::projects() as $p):if(!isMainAdmin()&&!SessionIdentity::member($p))continue;?><section><h2><?=ws_h($p['name'])?></h2><form method="post"><button name="select_project" value="<?=ws_h($p['id'])?>">ورود به پروژه</button></form></section><?php endforeach;?></div></main></body></html>
