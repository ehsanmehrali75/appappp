<?php
/** Shared workspace context bar; no project selection or permission mutation while rendering. */
class WorkspaceView {
    public static function h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
    public static function icon($name){
        $paths=['grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>','key'=>'<circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 8-8M16 7l3 3M18 5l3 3"/>','arrow'=>'<path d="M19 12H5m6-6-6 6 6 6"/>','box'=>'<path d="m12 3 9 5v8l-9 5-9-5V8l9-5Zm0 9v9M3 8l9 4 9-4M7.5 5.5l9 5"/>','search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>','building'=>'<path d="M4 21V7l8-4 8 4v14M9 21v-5h6v5M8 9h1m6 0h1M8 12h1m6 0h1M2 21h20"/>','logout'=>'<path d="M10 4H4v16h6m4-12 4 4-4 4m-6-4h10"/>'];
        return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['grid']).'</svg>';
    }
    public static function toolbar($hidden){
        $central=basename($_SERVER['SCRIPT_FILENAME']??'')==='admin.php'&&isMainAdmin()&&ProjectSelection::centralTab($_GET['tab']??'');$pid=$_SESSION['project_id']??'';
        $html='<nav class="project-toolbar" aria-label="میزکار و پروژهٔ فعال"><div class="project-toolbar-inner"><a class="workspace-home" href="/panel/workspace.php">'.self::icon('grid').'<span>میزکارها</span></a><span class="workspace-divider" aria-hidden="true"></span><form class="workspace-switch" method="post" action="/panel/workspace.php">'.$hidden.'<span class="workspace-context-icon">'.self::icon($central?'building':'box').'</span><label for="workspace-project-select"><span>فضای کار فعال</span><select id="workspace-project-select" name="select_project">';
        if(isMainAdmin())$html.='<option value="__central__" '.($central||$pid===''?'selected':'').'>مدیریت مرکزی</option>';
        foreach(ProjectSelection::projects() as $p)$html.='<option value="'.self::h($p['id']).'" '.(!$central&&$pid===$p['id']?'selected':'').'>'.self::h($p['name']).'</option>';
        $html.='</select></label><button class="workspace-switch-button" type="submit">تغییر پروژه '.self::icon('arrow').'</button></form><div class="workspace-shortcuts">';
        if(!$central&&$pid!==''&&canAccessManagement()&&(canAccessFeature('ai_connect','view')||canAccessFeature('settings','view')||canAccessFeature('extract','view')||canAccessFeature('wp_crm','view')))$html.='<a class="workspace-backup-link" href="/panel/connections.php?project='.rawurlencode($pid).'">'.self::icon('grid').'<span>مرکز اتصال‌ها</span></a>';
        if(!$central&&$pid!==''&&canAccessManagement()&&canAccessFeature('wp_crm','view'))$html.='<a class="workspace-key-link" href="/panel/wp-crm.php?project='.rawurlencode($pid).'&view=connection#api-key">'.self::icon('key').'<span>اتصال وردپرس <small>و کلید API</small></span></a>';
        if(!$central&&$pid!==''&&canAccessManagement()&&(isMainAdmin()||isProjectAdmin()))$html.='<a class="workspace-backup-link" href="/panel/backups.php?project='.rawurlencode($pid).'">'.self::icon('box').'<span>بک‌آپ و بازیابی</span></a>';
        if(isUser())$html.='<a class="workspace-backup-link" href="/panel/account.php">'.self::icon('box').'<span>حساب و اشتراک</span></a>';
        if(isMainAdmin())$html.='<a class="workspace-backup-link" href="/panel/saas-admin.php">'.self::icon('building').'<span>حساب‌ها و مدل‌های مرکزی</span></a>';
        if(!$central&&$pid!==''&&canAccessFeature('ai_connect','view'))$html.='<a class="workspace-backup-link" href="/panel/my-employees.php?project='.rawurlencode($pid).'">'.self::icon('grid').'<span>کارمندهای هوشمند</span></a><a class="workspace-backup-link" href="/panel/ehsan.php?project='.rawurlencode($pid).'">احسان / تحلیل جامع</a>';
        return $html.'</div></div></nav>';
    }
}
