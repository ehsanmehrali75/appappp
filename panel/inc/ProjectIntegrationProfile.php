<?php
/** A project's normal website and ERP belong to the same platform; explicit overrides are optional. */
class ProjectIntegrationProfile {
    public static function get($project){
        $s=$project['settings']??[];$platform=$s['platform_type']??'custom';
        if(!in_array($platform,['wordpress','tiam','custom'],true))$platform='custom';
        $website=$s['website_adapter']??$platform;if(!in_array($website,['wordpress','tiam','custom'],true))$website=$platform;
        $authority=$s['inventory_authority']??($platform==='custom'?'project_inventory':$platform);
        return ['platform'=>$platform,'website_adapter'=>$website,'inventory_authority'=>$authority,'price_authority'=>$s['price_authority']??$authority];
    }
}
