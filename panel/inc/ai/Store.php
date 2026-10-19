<?php
/** Private, atomic state. No provider credentials or bearer tokens enter project settings. */
class AiStore {
    private $dir;
    public function __construct($dir=null) {
        $this->dir=$dir?:getenv('MAARKSHO_AI_STATE_DIR')?:dirname(__DIR__,2).'/db/ai-private';
        if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true)&&!is_dir($this->dir))throw new RuntimeException('فضای خصوصی هوش مصنوعی آماده نشد.');
        @chmod($this->dir,0700);
        if(!is_file($this->dir.'/.htaccess'))file_put_contents($this->dir.'/.htaccess',"Require all denied\n");
    }
    public function directory(){return $this->dir;}
    public function transaction($fn) {
        $lock=fopen($this->dir.'/state.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('قفل اتصال در دسترس نیست.');
        try {$file=$this->dir.'/state.json';$data=is_file($file)?json_decode(file_get_contents($file),true):[];if(!is_array($data))throw new RuntimeException('وضعیت اتصال قابل خواندن نیست.');$result=$fn($data);if(!AccessControl::writeJson($file,$data))throw new RuntimeException('ثبت وضعیت اتصال ناموفق بود.');@chmod($file,0600);return $result;}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function read(){return $this->transaction(static fn(&$s)=>$s);}
    public function putDocument($project,$id,$data) {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('شناسهٔ سند معتبر نیست.');
        $dir=$this->dir.'/p-'.hash('sha256',$project);if(!is_dir($dir))mkdir($dir,0700,true);
        if(!AccessControl::writeJson($dir.'/'.$id.'.json',$data))throw new RuntimeException('ذخیرهٔ سند ناموفق بود.');
        @chmod($dir.'/'.$id.'.json',0600);
    }
    public function document($project,$id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('شناسهٔ سند معتبر نیست.');
        $data=json_decode(@file_get_contents($this->dir.'/p-'.hash('sha256',$project).'/'.$id.'.json')?:'null',true);
        if(!is_array($data))throw new RuntimeException('سند یافت نشد.');return $data;
    }
    private function key() {
        $file=$this->dir.'/master.key';$f=fopen($this->dir.'/key.lock','c');flock($f,LOCK_EX);
        try {if(!is_file($file)){file_put_contents($file,random_bytes(32),LOCK_EX);chmod($file,0600);}$key=file_get_contents($file);if(strlen($key)!==32)throw new RuntimeException('کلید رمزگذاری معتبر نیست.');return $key;}
        finally{flock($f,LOCK_UN);fclose($f);}
    }
    public function encrypt($text){$iv=random_bytes(12);$cipher=openssl_encrypt($text,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('رمزگذاری ناموفق بود.');return base64_encode($iv.$tag.$cipher);}
    public function decrypt($encoded){$raw=base64_decode($encoded,true);if($raw===false||strlen($raw)<28)throw new RuntimeException('کلید سرویس معتبر نیست.');$value=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($value===false)throw new RuntimeException('بازکردن کلید سرویس ناموفق بود.');return $value;}
    public static function audit(&$state,$grant,$action,$details=[]) {$state['audit'][]=['at'=>time(),'project'=>$grant['project'],'owner'=>$grant['owner'],'action'=>$action,'details'=>$details];$state['audit']=array_slice($state['audit'],-3000);}
    public function purgeProject($pid) {
        $this->transaction(function(&$s)use($pid){unset($s['context'][$pid]);foreach(['grants','codes','tokens','schedules','datasets','reports'] as $key)foreach($s[$key]??[] as $id=>$row)if(($row['project']??'')===$pid)unset($s[$key][$id]);foreach($s['providers']??[] as $id=>$provider){$s['providers'][$id]['projects']=array_values(array_diff($provider['projects'],[$pid]));if(($provider['principal']['project']??'')===$pid)$s['providers'][$id]['enabled']=false;}});
        $dir=$this->dir.'/p-'.hash('sha256',$pid);foreach(glob($dir.'/*.json')?:[] as $file)if(!is_link($file))@unlink($file);if(is_dir($dir))@rmdir($dir);
    }
}
