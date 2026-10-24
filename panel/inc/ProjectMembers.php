<?php
/** Membership writes never re-save products, schedules or project settings. */
class ProjectMembers {
    private static $stage='start', $diagnostic=[];

    private static function schema($conn) {
        if($conn instanceof SQLite3ToMysqliWrapper)return;
        self::$stage='membership_schema';
        $result=$conn->query('SHOW FULL COLUMNS FROM project_users');
        if(!$result)throw new RuntimeException('ساختار عضویت قابل بررسی نیست.');
        $columns=[];$unicode=true;while($row=$result->fetch_assoc()){$columns[$row['Field']]=strtolower($row['Type']);if(!empty($row['Collation'])&&strpos($row['Collation'],'utf8mb4_')!==0)$unicode=false;}
        $status=$conn->query("SHOW TABLE STATUS LIKE 'project_users'");if(!$status)throw new RuntimeException('بررسی ذخیره‌ساز عضویت ناموفق بود.');$table=$status->fetch_assoc();
        if(strcasecmp($table['Engine']??'','InnoDB')!==0&&!$conn->query('ALTER TABLE project_users ENGINE=InnoDB'))throw new RuntimeException('فعال‌سازی تراکنش عضویت ناموفق بود.');
        if(!$unicode&&!$conn->query('ALTER TABLE project_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'))throw new RuntimeException('فعال‌سازی ذخیرهٔ فارسی ناموفق بود.');
        $changes=[];
        if(!isset($columns['panel_access']))$changes[]='ADD COLUMN panel_access TEXT DEFAULT NULL';
        if(preg_match('/^(?:var)?char\((\d+)\)$/',$columns['password']??'',$match)&&(int)$match[1]<255)$changes[]='MODIFY COLUMN password VARCHAR(255)';
        if(preg_match('/^(?:enum|set)\(/',$columns['role']??'')||(preg_match('/^(?:var)?char\((\d+)\)$/',$columns['role']??'',$match)&&(int)$match[1]<50))$changes[]='MODIFY COLUMN role VARCHAR(50)';
        if(preg_match('/^(?:var)?char\(/',$columns['permissions']??''))$changes[]='MODIFY COLUMN permissions TEXT';
        foreach($changes as $change)if(!$conn->query('ALTER TABLE project_users '.$change))throw new RuntimeException('به‌روزرسانی ساختار عضویت ناموفق بود.');
    }

    /** Run schema changes before opening a project-creation transaction. */
    public static function prepareStorage() {if(isSqlActive())self::schema(getConn());}

    public static function save($projects, $usernames) {
        if(class_exists('SaaSAccounts'))SaaSAccounts::validateMembers($projects);
        self::$stage='start';self::$diagnostic=[];
        if (!isSqlActive()) {
            $db=getJsonDB();
            foreach($db['projects'] as &$project)foreach($projects as $changed)if($project['id']===$changed['id'])$project['users']=$changed['users'];
            unset($project);saveJsonDB($db);return;
        }
        $conn=getConn();$transaction=false;
        try {
            self::schema($conn); // MySQL DDL must finish before the membership transaction.
            $names=array_values(array_unique($usernames));$existing=[];
            self::$stage='membership_scope';
            foreach($names as $name){
                $q=$conn->prepare('SELECT project_id FROM project_users WHERE username = ?');if(!$q)throw new RuntimeException('بررسی پروژه‌های عضویت ناموفق بود.');
                $q->bind_param('s',$name);if(!$q->execute())throw new RuntimeException('بررسی پروژه‌های عضویت ناموفق بود.');
                $result=$q->get_result();while($row=$result->fetch_assoc())$existing[$row['project_id']]=true;$q->close();
            }
            $projects=array_values(array_filter($projects,static function($p)use($existing,$names){
                if(isset($existing[$p['id']]))return true;
                foreach($p['users']??[] as $u)if(in_array($u['username']??null,$names,true))return true;
                return false;
            }));
            if(!$projects)throw new RuntimeException('هیچ پروژه‌ای برای ثبت عضویت انتخاب نشده است.');
            self::$stage='begin';if(!$conn->begin_transaction())throw new RuntimeException('شروع ثبت عضویت ناموفق بود.');$transaction=true;
            usort($projects,static fn($a,$b)=>strcmp($a['id'],$b['id']));
            foreach($projects as $project) {
                $pid=$project['id'];self::$stage='project_lock';
                if(ProjectLifecycle::isDeleted($pid))throw new RuntimeException('پروژه حذف شده است.');
                $sql='SELECT id FROM projects WHERE id = ?';if(!($conn instanceof SQLite3ToMysqliWrapper))$sql.=' FOR UPDATE';
                $q=$conn->prepare($sql);if(!$q)throw new RuntimeException('قفل پروژه آماده نیست.');
                $q->bind_param('s',$pid);if(!$q->execute()||!$q->get_result()->fetch_assoc())throw new RuntimeException('پروژه یافت نشد.');$q->close();
                foreach($names as $name) {
                    self::$stage='member_authorization';
                    $q=$conn->prepare('SELECT role, permissions FROM project_users WHERE project_id = ? AND username = ?');if(!$q)throw new RuntimeException('بررسی عضویت ناموفق بود.');
                    $q->bind_param('ss',$pid,$name);if(!$q->execute())throw new RuntimeException('بررسی عضویت ناموفق بود.');$old=$q->get_result()->fetch_assoc();$q->close();
                    if($old){$old['permissions']=json_decode($old['permissions']??'[]',true)?:[];if(!ProjectPermissions::canManageMember($old))throw new RuntimeException('دسترسی تغییر این عضو را ندارید.');}
                    $member=null;foreach($project['users'] as $u)if($u['username']===$name){$member=$u;break;}
                    if($member){
                        self::$stage='member_upsert';
                        $sql='INSERT INTO project_users (project_id, username, password, role, permissions, mobile, telegram_chat_id, bale_chat_id, panel_access) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        if($conn instanceof SQLite3ToMysqliWrapper)$sql=str_replace('INSERT INTO','INSERT OR REPLACE INTO',$sql);
                        else $sql.=' ON DUPLICATE KEY UPDATE password=VALUES(password), role=VALUES(role), permissions=VALUES(permissions), mobile=VALUES(mobile), telegram_chat_id=VALUES(telegram_chat_id), bale_chat_id=VALUES(bale_chat_id), panel_access=VALUES(panel_access)';
                        $q=$conn->prepare($sql);if(!$q)throw new RuntimeException('آماده‌سازی ثبت عضویت ناموفق بود.');
                        $permissions=json_encode($member['permissions']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$mobile=$member['mobile']??'';$telegram=$member['telegram_chat_id']??'';$bale=$member['bale_chat_id']??'';
                        $panelAccess=isset($member['panel_access'])?json_encode($member['panel_access'],JSON_THROW_ON_ERROR):null;
                        $q->bind_param('sssssssss',$pid,$name,$member['password'],$member['role'],$permissions,$mobile,$telegram,$bale,$panelAccess);
                    }else{
                        if(!$old)continue;
                        self::$stage='member_remove';$q=$conn->prepare('DELETE FROM project_users WHERE project_id = ? AND username = ?');if(!$q)throw new RuntimeException('آماده‌سازی حذف عضویت ناموفق بود.');$q->bind_param('ss',$pid,$name);
                    }
                    if(!$q->execute())throw new RuntimeException('ثبت عضویت ناموفق بود.');$q->close();
                }
            }
            self::$stage='commit';if(!$conn->commit())throw new RuntimeException('ثبت نهایی عضویت ناموفق بود.');$transaction=false;
        }catch(Throwable $e){
            self::$diagnostic=['stage'=>self::$stage,'driver'=>$conn instanceof SQLite3ToMysqliWrapper?'sqlite':'mysql','errno'=>$conn instanceof SQLite3ToMysqliWrapper?0:(int)$conn->errno,'sqlstate'=>$conn instanceof SQLite3ToMysqliWrapper?'':$conn->sqlstate];
            if($transaction)$conn->rollback();throw $e;
        }
        foreach($projects as $project)if(class_exists('MaarkshoCache'))MaarkshoCache::delete('project_full_'.$project['id']);
    }

    public static function report($error) {
        $id=bin2hex(random_bytes(6));
        error_log('Project membership write failed: '.json_encode(['tracking_id'=>$id]+self::$diagnostic+['exception'=>get_class($error),'reason'=>$error instanceof RuntimeException&&$error->getFile()===__FILE__?$error->getMessage():'operation_failed','file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_UNESCAPED_UNICODE));
        return $id;
    }
}
