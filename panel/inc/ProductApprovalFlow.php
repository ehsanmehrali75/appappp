<?php
/** Optional sequential image approvals. Stage 1 is style; stage 2 is conformity. */
class ProductApprovalFlow {
    /** Interpret historic partial reviews using the current immediate-rejection rule. */
    public static function normalizeRejections(&$project){
        foreach($project['products']??[] as $index=>$product){
            if(($product['stage1_decision']??'')==='rejected'||($product['stage2_decision']??'')==='rejected')$project['products'][$index]['status']='rejected';
        }
    }
    public static function enabled($settings){return !empty($settings['prod_approval_split_enabled'])&&in_array($settings['prod_approval_order']??'parallel',['style_first','conformity_first'],true);}
    public static function order($settings){return ($settings['prod_approval_order']??'')==='conformity_first'?[2,1]:[1,2];}
    public static function approved($p,$settings,$stage){return ($p['stage'.$stage.'_decision']??'')!=='rejected'&&(($p['stage'.$stage.'_decision']??'')==='approved'||count(array_unique($p['approvals_stage'.$stage]??[]))>=max(1,(int)($settings['prod_approval_stage'.$stage.'_min']??1)));}
    public static function duration($p,$settings){
        $image=!empty($p['is_manual_image'])||!empty($p['uploaded_by']);$value=(int)($settings[$image?'approval_timeout_image':'approval_timeout_library']??$settings['approval_timeout']??2880);
        if(($settings['approval_timeout_unit']??'')!=='minutes'&&$value>0&&$value<=120)$value*=60;
        return max(0,$value)*60;
    }
    public static function state($p,$settings,$now=null){
        $now=$now??time();$order=self::order($settings);$flow=$p['website_sync']['approval_flow']??[];
        $generation=hash('sha256',json_encode([$p['image']??'',$p['extractedAt']??0]));if(($flow['generation']??'')!==$generation)$flow=['generation'=>$generation];
        $start=max(0,(int)($p['extractedAt']??0));
        $first=$order[0];$second=$order[1];
        if(self::approved($p,$settings,$first)&&empty($flow['approved_'.$first]))$flow['approved_'.$first]=max($start,(int)($settings['prod_approval_sequence_since']??$now));
        $stages=[];foreach([1,2] as $stage){$approved=self::approved($p,$settings,$stage);$rejected=($p['stage'.$stage.'_decision']??'')==='rejected';$blocked=$stage===$second&&!self::approved($p,$settings,$first);
            $at=$stage===$first?$start:(int)($flow['approved_'.$first]??0);$duration=self::duration($p,$settings);
            $stages[$stage]=['label'=>$stage===1?'استایل':'انطباق','state'=>$rejected?'rejected':($approved?'approved':($blocked?'blocked':'pending')),'started_at'=>$at,'deadline'=>!$blocked&&$duration>0&&$at>0?$at+$duration:0];}
        return ['flow'=>$flow,'stages'=>$stages,'first'=>$first,'second'=>$second];
    }
    public static function allowed($p,$settings,$stage){
        if(!self::enabled($settings))return true;
        foreach([1,2] as $s)if(($p['stage'.$s.'_decision']??'')==='rejected')return false;
        return (self::state($p,$settings)['stages'][$stage]['state']??'blocked')==='pending';
    }
    public static function record(&$p,$settings,$now=null){
        if(!self::enabled($settings))return;$now=$now??time();$state=self::state($p,$settings,$now);$flow=$state['flow'];
        foreach([1,2] as $s)if(self::approved($p,$settings,$s)&&(($p['website_sync']['approval_flow']['generation']??'')!==$flow['generation']||empty($p['website_sync']['approval_flow']['approved_'.$s])))$flow['approved_'.$s]=$now;
        $p['website_sync']['approval_flow']=$flow;
    }
    public static function autoAdvance(&$p,$project,$now){
        $settings=$project['settings'];
        if(($p['stage1_decision']??'')==='rejected'||($p['stage2_decision']??'')==='rejected')return;
        $state=self::state($p,$settings,$now);
        foreach(self::order($settings) as $s){$phase=$state['stages'][$s];if($phase['state']!=='pending')continue;
            if(!$phase['deadline']||$now<$phase['deadline'])return;
            $p['stage'.$s.'_decision']='approved';$p['approvals_stage'.$s][]='سیستم (پایان مهلت تأیید)';self::record($p,$settings,$now);refreshProductSplitStatus($p,$project);return;}
    }
    public static function responsible($settings,$stage,$username){
        if(isMainAdmin()||isProjectAdmin())return true;
        $names=array_filter(array_map('trim',preg_split('/[,،]+/u',mb_strtolower($settings['prod_approval_stage'.$stage.'_users']??''))));
        return !$names||in_array(mb_strtolower($username),$names,true);
    }
    public static function visiblePending($p,$project){
        if(!self::enabled($project['settings']))return true;
        if(isMainAdmin()||isProjectAdmin())return true;
        if(!hasPermission('approve_content','use'))return false;
        foreach(self::order($project['settings']) as $stage)if(self::allowed($p,$project['settings'],$stage)&&self::responsible($project['settings'],$stage,$_SESSION['user']['username']??''))return true;
        return false;
    }
    public static function decide(&$p,&$project,$stage,$reject,$comment,$username){
        $settings=$project['settings'];
        if(!hasPermission('approve_content','use'))throw new RuntimeException('دسترسی تأیید تصاویر را ندارید.');
        if(!in_array($stage,[1,2],true)){$state=self::state($p,$settings);$stage=self::allowed($p,$settings,$state['first'])?$state['first']:$state['second'];}
        if(!self::allowed($p,$settings,$stage))throw new RuntimeException('این مرحله هنوز باز نشده یا قبلاً تعیین تکلیف شده است؛ ابتدا مرحلهٔ دارای اولویت را تأیید کنید.');
        if(!self::responsible($settings,$stage,$username))throw new RuntimeException('مسئول تأیید این مرحله نیستید.');
        if($reject){if(trim($comment)==='')throw new RuntimeException('علت رد تصویر را وارد کنید.');$p['stage'.$stage.'_decision']='rejected';$p['approvals_stage'.$stage]=[];$p['rejection_reason']=$comment;$p['rejectionReason']=$comment;$p['rejection_by']=$username;$p['rejection_side']=$stage===1?'استایل':'انطباق';$p['rejections_history'][]=['user'=>$username,'reason'=>$comment,'side'=>$p['rejection_side'],'at'=>time()];}
        else{$p['approvals_stage'.$stage]=array_values(array_unique(array_merge($p['approvals_stage'.$stage]??[],[$username])));if(count($p['approvals_stage'.$stage])>=max(1,(int)($settings['prod_approval_stage'.$stage.'_min']??1)))$p['stage'.$stage.'_decision']='approved';}
        if ($reject) {
            $p['rejection_at']=time();
            $pending=$project['settings']['sms_rejected_pending_list']??[];
            if (!in_array($p['id'],$pending,true)) $pending[]=$p['id'];
            $project['settings']['sms_rejected_pending_list']=$pending;
        }
        if (trim($comment)!=='') $p['userFeedback'][]=['user'=>'Portal Approver ('.$username.')','text'=>$comment,'at'=>time(),'category'=>$reject?'negative':'positive'];
        $project['logs'][]=['at'=>time(),'type'=>$reject?'رد محتوا':'تایید جزئی محتوا','productName'=>mb_substr($p['name']??'محصول',0,100),'platform'=>'SYSTEM'];
        self::record($p,$settings);refreshProductSplitStatus($p,$project);
        if (($p['status']??'')==='active') unset($p['rejectionReason']);
    }
    public static function html($p,$project){
        if(!self::enabled($project['settings']??[])||($p['status']??'')!=='pending')return '';
        $state=self::state($p,$project['settings']);$html='<div class="approval-deadlines" data-approval-product="'.htmlspecialchars((string)($p['id']??''),ENT_QUOTES,'UTF-8').'">';
        foreach(self::order($project['settings']) as $s){$phase=$state['stages'][$s];$label=$phase['label'];$text=['approved'=>'تأیید شده','rejected'=>'رد شده','blocked'=>'پس از تأیید '.$state['stages'][$state['first']]['label'].' فعال می‌شود','pending'=>'بدون محدودیت زمانی'][$phase['state']];
            $html.='<div data-approval-stage="'.$s.'" data-phase="'.$phase['state'].'"><strong>'.$label.'</strong> <span'.($phase['state']==='pending'&&$phase['deadline']?' data-approval-deadline="'.$phase['deadline'].'"':'').'>'.$text.'</span></div>';}
        return $html.'</div>';
    }
}
