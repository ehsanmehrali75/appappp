<?php
require_once __DIR__.'/WebsiteCatalogQueue.php';
/** A current-state view, at the same product/colour grain and with the same filters as the work queue. */
class ProjectOverview {
    public static function build($project,$now=null){
        $now=$now??time();$counts=array_fill_keys(array_keys(WebsiteCatalogQueue::FILTERS),0);$inventory=['in_stock'=>0,'out_of_stock'=>0,'unknown'=>0];$rejected=0;$pending=0;$ready=0;$priorities=[];
        foreach($project['products']??[] as $p){$inventory[WebsiteCatalogSync::inventory($p)]++;$wf=getProductWorkflowStatus($p,$project);if($wf==='rejected'||(!isProductOutOfStock($p)&&($p['status']??'')==='rejected'))$rejected++;if($wf==='pending_approval')$pending++;}
        $entries=WebsiteCatalogQueue::entries($project,['wc_stock'=>'in_stock']);
        foreach($entries as $entry){foreach($entry['tasks'] as $task)if(isset($counts[$task]))$counts[$task]++;if(in_array('pending_upload',$entry['tasks'],true)||in_array('content_upload',$entry['tasks'],true))$ready++;
            if(in_array('work',$entry['tasks'],true))$priorities[]=$entry;
        }
        $priorities=WebsiteCatalogQueue::sorted($priorities);
        $upcoming=[];$failed=0;$overdue=0;
        foreach($project['schedules']??[] as $s){$status=$s['status']??'pending';$publish=$s['publish_status']??'';$raw=$s['scheduledAt']??$s['scheduled_at']??0;$at=is_numeric($raw)?(int)$raw:(strtotime((string)$raw)?:0);
            if(in_array($status,['failed','error'],true)||$publish==='failed'){$failed++;continue;}
            if(in_array($status,['completed','sent','published','canceled','cancelled','rejected'],true)||in_array($publish,['published','cancelled','canceled'],true))continue;
            if($at>0&&$at<$now){$overdue++;continue;}if($at>=$now){$s['_at']=$at;$upcoming[]=$s;}
        }
        usort($upcoming,static fn($a,$b)=>$a['_at']<=>$b['_at']);
        return ['total'=>count($project['products']??[]),'inventory'=>$inventory,'counts'=>$counts,'ready'=>$ready,'rejected'=>$rejected,'pending'=>$pending,'priorities'=>array_slice($priorities,0,6),'upcoming'=>array_slice($upcoming,0,5),'failed'=>$failed,'overdue'=>$overdue];
    }
}
