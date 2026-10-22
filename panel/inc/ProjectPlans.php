<?php
/** Purchased capabilities are a server-side ceiling, independent of editable menu visibility. */
class ProjectPlans {
    public static function modules($plan) {
        $base=['dashboard','products','size_studio','prompthub','chat','settings','users','support','excel','extract'];
        $pro=array_merge($base,['extract','website_export','calendar','wp_crm','seo_classic','seo_intel','sale_analysis','vm_studio','auto_admin','ai_connect']);
        $default=($plan['id']??'')==='plan_starter'?$base:$pro;
        $modules=$plan['modules']??$default;
        if(!is_array($modules))throw new RuntimeException('امکانات پلن معتبر نیست.');
        $known=FeatureFlags::getCatalog();$out=['dashboard','settings','users','support'];
        foreach($modules as $key)if(is_string($key)&&isset($known[AccessControl::featureKey($key)]))$out[]=AccessControl::featureKey($key);
        return array_values(array_unique($out));
    }
    public static function allows($feature,$settings) {
        if(!isset($settings['subscription']['modules']))return true;
        $modules=$settings['subscription']['modules'];if(!is_array($modules))return false;
        $feature=AccessControl::featureKey($feature);
        if(in_array($feature,$modules,true))return true;
        $parent=AccessControl::parentFeature($feature);
        return $parent!==null&&self::allows($parent,$settings);
    }
    public static function find($id) {foreach(getSubscriptionPlans() as $plan)if(($plan['id']??'')===$id)return $plan;throw new RuntimeException('پلن انتخاب‌شده یافت نشد.');}
}
