<?php
/** Per-host configuration. Environment variables take precedence over the existing .env file. */
class DatabaseConfig {
    public static function load() {
        $values=[];$envFile=dirname(__DIR__,2).'/.env';
        if(is_readable($envFile))foreach(file($envFile,FILE_IGNORE_NEW_LINES) as $line) {
            if(!preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/',$line,$m))continue;
            $values[$m[1]]=trim($m[2],"\"'");
        }
        $get=static function($key,$default='')use($values){$value=getenv($key);return $value!==false?$value:($values[$key]??$default);};
        $config=['driver'=>$get('MAARKSHO_DB_DRIVER','mysql'),'host'=>$get('MAARKSHO_DB_HOST',$get('DB_HOST','localhost')),
            'dbname'=>$get('MAARKSHO_DB_NAME',$get('DB_NAME','maarksho_flow')),'user'=>$get('MAARKSHO_DB_USER',$get('DB_USER','maarksho_flow')),
            'pass'=>$get('MAARKSHO_DB_PASS',$get('DB_PASS')),'sqlite_path'=>$get('MAARKSHO_SQLITE_PATH',__DIR__.'/../db/maarksho_flow.sqlite'),
            'allow_fallback'=>$get('MAARKSHO_DB_ALLOW_FALLBACK','0')==='1'];
        $local=__DIR__.'/../db/database.local.php';
        if(is_file($local)){$overrides=require $local;if(is_array($overrides))$config=array_replace($config,$overrides);}
        if(!in_array($config['driver'],['mysql','sqlite','json'],true))throw new RuntimeException('نوع پایگاه داده در تنظیمات هاست معتبر نیست.');
        return $config;
    }
}
