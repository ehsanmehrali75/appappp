<?php
/** Metadata-only chooser: the central desk never loads a default project's library. */
class ProjectSelection {
    public static function centralTab($tab){return is_string($tab)&&(in_array($tab,['projects','feature_flags','system_debug','main_settings','main_orders','main_plans','storage_monitor'],true)||strpos($tab,'markshow_')===0);}
    private static function metadata($p){$s=is_array($p['settings']??null)?$p['settings']:(json_decode($p['settings']??'{}',true)?:[]);return ['id'=>$p['id'],'name'=>$p['name'],'platform'=>$s['platform_type']??'','domain'=>parse_url($s['wordpress']['site_url']??$s['sourceUrl']??'',PHP_URL_HOST)?:''];}
    public static function projects(){
        $out=[];
        if(isSqlActive()){$r=getConn()->query('SELECT id,name,settings FROM projects ORDER BY name');if($r)while($p=$r->fetch_assoc())if(!ProjectLifecycle::isDeleted($p['id'])&&(isMainAdmin()||SessionIdentity::member($p)))$out[]=self::metadata($p);}
        else foreach(getJsonDB()['projects']??[] as $p)if(!ProjectLifecycle::isDeleted($p['id'])&&(isMainAdmin()||SessionIdentity::member($p)))$out[]=self::metadata($p);
        return $out;
    }
}
