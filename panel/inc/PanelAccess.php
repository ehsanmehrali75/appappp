<?php
/** Entry points are separate from project membership and feature permissions. */
class PanelAccess {
    public static function forMember($member) {
        $access=$member['panel_access']??null;
        if($access!==null) {
            if(is_string($access))$access=json_decode($access,true);
            return is_array($access)?array_values(array_intersect(['portal','admin'],$access)):[];
        }
        return in_array($member['role']??'', ['project_admin','manager','editor','owner','main_admin','superadmin'],true)?['portal','admin']:['portal'];
    }
    public static function allowed($panel) {
        if(isMainAdmin())return true;
        return in_array($panel,self::forMember($_SESSION['user']??[]),true);
    }
    public static function fromPost($post,$fallback) {
        if(!isset($post['panel_access_present'])&&!isset($post['panel_access']))return self::forMember($fallback);
        $access=$post['panel_access']??[];
        if(!is_array($access)||!$access)throw new InvalidArgumentException('حداقل یکی از دسترسی‌های پورتال یا پنل ادمین را انتخاب کنید.');
        foreach($access as $panel)if(!is_string($panel)||!in_array($panel,['portal','admin'],true))throw new InvalidArgumentException('نوع دسترسی به پنل معتبر نیست.');
        return array_values(array_unique($access));
    }
}
