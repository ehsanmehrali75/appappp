<?php
/** Private, bounded attempt counters; usernames and passwords are never written to disk. */
class LoginThrottle {
    public static function check($username,$success=false) {
        $dir=getenv('MAARKSHO_AUTH_STATE_DIR')?:sys_get_temp_dir().'/maarksho-auth-'.substr(hash('sha256',__DIR__),0,16);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))return false;
        $ip=$_SERVER['REMOTE_ADDR']??'local';$now=time();
        foreach([[$ip,30],[$ip.'|'.mb_strtolower(trim($username)),10]] as [$key,$limit]) {
            $path=$dir.'/'.hash('sha256',$key).'.json';$fp=fopen($path,'c+');if(!$fp)return false;chmod($path,0600);
            if(!flock($fp,LOCK_EX)){fclose($fp);return false;}
            try {
                $data=json_decode(stream_get_contents($fp),true)?:[];$attempts=array_values(array_filter($data,fn($at)=>is_int($at)&&$at>$now-900));
                if($success)$attempts=[];
                elseif(count($attempts)>=$limit)return false;
                else $attempts[]=$now;
                rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($attempts));fflush($fp);
            } finally {flock($fp,LOCK_UN);fclose($fp);}
        }
        return true;
    }
}
