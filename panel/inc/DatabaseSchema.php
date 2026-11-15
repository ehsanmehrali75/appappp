<?php
/** Persistent schema revision: normal connections must not repeat table-rebuilding DDL. */
class DatabaseSchema {
    const VERSION = '2026-11-15-performance-1';
    public static function ready($conn) {
        $r=@$conn->query("SELECT revision FROM maarksho_schema_versions WHERE component='core'");
        return $r && (($r->fetch_assoc()['revision']??'')===self::VERSION);
    }
    public static function acquire($conn,$config) {
        if($conn instanceof SQLite3ToMysqliWrapper){
            $key=hash('sha256',realpath($config['sqlite_path'])?:$config['sqlite_path']);
            $lock=fopen(sys_get_temp_dir().'/maarksho-schema-'.$key.'.lock','c');
            if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('قفل به‌روزرسانی دیتابیس در دسترس نیست.');
            return $lock;
        }
        $name='maarksho_schema_'.substr(hash('sha256',$config['dbname']??''),0,40);
        $r=$conn->query("SELECT GET_LOCK('$name',30) AS acquired");
        if(!$r||(int)($r->fetch_assoc()['acquired']??0)!==1)throw new RuntimeException('به‌روزرسانی ساختار دیتابیس در حال اجراست؛ کمی بعد دوباره تلاش کنید.');
        return $name;
    }
    public static function release($conn,$lock) {
        if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        elseif(is_string($lock))$conn->query("SELECT RELEASE_LOCK('$lock')");
    }
    public static function complete($conn,$products,$schedules) {
        // Never mark a partially failed migration as complete.
        foreach(['super_users'=>'username,password','portal_users'=>'mobile','site_configs'=>'id,domain','proposals'=>'id,project_id',
            'projects'=>'id,name,settings,active_from,active_to','project_users'=>'project_id,username,password,role,permissions,mobile,telegram_chat_id,bale_chat_id,panel_access',
            'products'=>'id,project_id,'.implode(',',array_keys($products)),
            'schedules'=>'id,project_id,'.implode(',',array_keys($schedules)),
            'logs'=>'id,project_id,at','scraping_jobs'=>'id,project_id,status'] as $table=>$fields)
            if(!$conn->query("SELECT $fields FROM $table LIMIT 0"))throw new RuntimeException('ساختار دیتابیس کامل نشده است؛ مهاجرت مجدداً بررسی شود.');
        foreach(['products'=>['project_id'],'logs'=>['project_id','at'],'schedules'=>['project_id'],
            'scraping_jobs'=>['project_id','status']] as $table=>$columns){
            $name='idx_maarksho_'.$table.'_project';$list=implode(',',$columns);
            if($conn instanceof SQLite3ToMysqliWrapper)$ok=$conn->query("CREATE INDEX IF NOT EXISTS $name ON $table ($list)");
            else{$r=$conn->query("SHOW INDEX FROM $table WHERE Key_name='$name'");$ok=$r&&($r->num_rows>0||$conn->query("CREATE INDEX $name ON $table ($list)"));}
            if(!$ok)throw new RuntimeException('ساخت ایندکس پروژه ناموفق بود.');
        }
        if(!$conn->query('CREATE TABLE IF NOT EXISTS maarksho_schema_versions (component VARCHAR(50) PRIMARY KEY, revision VARCHAR(100))'))throw new RuntimeException('ثبت نسخهٔ ساختار ناموفق بود.');
        $version=self::VERSION;
        if(!$conn->query("REPLACE INTO maarksho_schema_versions (component,revision) VALUES ('core','$version')"))throw new RuntimeException('ثبت نسخهٔ ساختار ناموفق بود.');
    }
}
