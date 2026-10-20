<?php
/** Membership writes never re-save products, schedules or project settings. */
class ProjectMembers {
    public static function save($projects, $usernames) {
        if (!isSqlActive()) {
            $db=getJsonDB();
            foreach($db['projects'] as &$project) foreach($projects as $changed) if($project['id']===$changed['id']) $project['users']=$changed['users'];
            unset($project);saveJsonDB($db);return;
        }
        $conn=getConn();if(!$conn->begin_transaction())throw new RuntimeException('شروع ثبت عضویت ناموفق بود.');
        try {
            usort($projects,static fn($a,$b)=>strcmp($a['id'],$b['id']));
            foreach($projects as $project) {
                $pid=$project['id'];
                if(ProjectLifecycle::isDeleted($pid))throw new RuntimeException('پروژه حذف شده است.');
                $sql='SELECT id FROM projects WHERE id = ?';if(!($conn instanceof SQLite3ToMysqliWrapper))$sql.=' FOR UPDATE';
                $q=$conn->prepare($sql);$q->bind_param('s',$pid);if(!$q->execute()||!$q->get_result()->fetch_assoc())throw new RuntimeException('پروژه یافت نشد.');$q->close();
                foreach(array_unique($usernames) as $name) {
                    $q=$conn->prepare('SELECT role, permissions FROM project_users WHERE project_id = ? AND username = ?');$q->bind_param('ss',$pid,$name);if(!$q->execute())throw new RuntimeException('بررسی عضویت ناموفق بود.');$old=$q->get_result()->fetch_assoc();$q->close();
                    if($old){$old['permissions']=json_decode($old['permissions']??'[]',true)?:[];if(!ProjectPermissions::canManageMember($old))throw new RuntimeException('دسترسی تغییر این عضو را ندارید.');}
                    $member=null;foreach($project['users'] as $u)if($u['username']===$name){$member=$u;break;}
                    if($member){
                        $q=$conn->prepare('REPLACE INTO project_users (project_id, username, password, role, permissions, mobile, telegram_chat_id, bale_chat_id, panel_access) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $permissions=json_encode($member['permissions']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$mobile=$member['mobile']??'';$telegram=$member['telegram_chat_id']??'';$bale=$member['bale_chat_id']??'';
                        $panelAccess=isset($member['panel_access'])?json_encode($member['panel_access'],JSON_THROW_ON_ERROR):null;
                        $q->bind_param('sssssssss',$pid,$name,$member['password'],$member['role'],$permissions,$mobile,$telegram,$bale,$panelAccess);
                    }else{$q=$conn->prepare('DELETE FROM project_users WHERE project_id = ? AND username = ?');$q->bind_param('ss',$pid,$name);}
                    if(!$q->execute())throw new RuntimeException('ثبت عضویت ناموفق بود.');$q->close();
                }
            }
            if(!$conn->commit())throw new RuntimeException('ثبت نهایی عضویت ناموفق بود.');
        }catch(Throwable $e){$conn->rollback();throw $e;}
        foreach($projects as $project)if(class_exists('MaarkshoCache'))MaarkshoCache::delete('project_full_'.$project['id']);
    }
}
