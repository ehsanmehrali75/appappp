<?php
class WebsiteExportBusy extends RuntimeException {}
/** Nonblocking, host-local slots shared by web and CLI export processes. */
class WebsiteExportCapacity {
    public static function acquire(){
        $value=getenv('MAARKSHO_EXPORT_CONCURRENCY');$limit=$value!==false&&ctype_digit($value)?max(1,min(8,(int)$value)):2;
        $dir=getenv('MAARKSHO_EXPORT_CAPACITY_DIR')?:sys_get_temp_dir().'/maarksho-exports-'.substr(hash('sha256',realpath(__DIR__)),0,20);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('فضای کنترل ظرفیت خروجی آماده نیست.');
        for($i=0;$i<$limit;$i++){
            $f=fopen($dir.'/slot-'.$i.'.lock','c');if(!$f)throw new RuntimeException('قفل ظرفیت خروجی در دسترس نیست.');@chmod($dir.'/slot-'.$i.'.lock',0600);
            if(flock($f,LOCK_EX|LOCK_NB))return $f;fclose($f);
        }
        throw new WebsiteExportBusy('ظرفیت پردازش خروجی پر است؛ کمی بعد دوباره تلاش می‌شود.');
    }
    public static function release($slot){if(is_resource($slot)){flock($slot,LOCK_UN);fclose($slot);}}
}
