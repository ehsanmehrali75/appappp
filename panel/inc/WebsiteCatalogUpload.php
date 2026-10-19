<?php
/** Stage bounded requests so a 10 MiB JSON file does not depend on PHP's POST size limit. */
class WebsiteCatalogUpload {
    public const MAX_BYTES=10*1024*1024;
    public const CHUNK_BYTES=256*1024;
    private static function directory() {
        $dir=getenv('MAARKSHO_WEBSITE_UPLOAD_DIR')?:sys_get_temp_dir().'/maarksho-site-upload-'.substr(hash('sha256',__DIR__),0,16);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('فضای موقت دریافت سایت قابل نوشتن نیست.');
        return $dir;
    }
    private static function write($file,$value) {
        if(!AccessControl::writeJson($file,$value))throw new RuntimeException('ذخیرهٔ بخش فایل سایت ناموفق بود.');
    }
    private static function state($token,$project,$owner) {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('شناسهٔ فایل معتبر نیست.');
        $path=self::directory().'/'.$token;
        $state=json_decode(@file_get_contents($path.'/state.json')?:'null',true);
        if(!$state||$state['project']!==$project||!hash_equals($state['owner'],$owner)||$state['expires']<time())throw new RuntimeException('فایل منقضی شده یا متعلق به این نشست و پروژه نیست.');
        return [$path,$state];
    }
    public static function begin($project,$owner,$bytes) {
        if(!is_int($bytes)||$bytes<1||$bytes>self::MAX_BYTES)throw new RuntimeException('حداکثر اندازهٔ JSON ده مگابایت است.');
        $dir=self::directory();
        foreach(new DirectoryIterator($dir) as $entry) {
            if(!$entry->isDir()||!preg_match('/^[a-f0-9]{64}$/D',$entry->getFilename()))continue;
            $old=json_decode(@file_get_contents($entry->getPathname().'/state.json')?:'null',true);
            if($old&&($old['expires']??PHP_INT_MAX)<time()) {foreach(glob($entry->getPathname().'/*.json') as $file)@unlink($file);@rmdir($entry->getPathname());break;}
        }
        $token=bin2hex(random_bytes(32));$path=$dir.'/'.$token;
        if(!mkdir($path,0700))throw new RuntimeException('ساخت فضای موقت ناموفق بود.');
        self::write($path.'/state.json',['project'=>$project,'owner'=>$owner,'bytes'=>$bytes,'expires'=>time()+900,'hashes'=>[]]);
        return ['upload_token'=>$token,'chunk_bytes'=>self::CHUNK_BYTES];
    }
    public static function append($token,$project,$owner,$index,$encoded) {
        [$path,$state]=self::state($token,$project,$owner);
        if(!is_int($index)||$index<0||!is_string($encoded)||strlen($encoded)>4*ceil(self::CHUNK_BYTES/3))throw new RuntimeException('بخش فایل معتبر نیست.');
        $data=base64_decode($encoded,true);$chunks=(int)ceil($state['bytes']/self::CHUNK_BYTES);
        $expected=$index===$chunks-1?$state['bytes']-$index*self::CHUNK_BYTES:self::CHUNK_BYTES;
        if($data===false||$index>=$chunks||strlen($data)!==$expected)throw new RuntimeException('حجم یا محتوای بخش فایل معتبر نیست.');
        $hash=hash('sha256',$data);$next=count($state['hashes']);
        if($index<$next) {
            if(!hash_equals($state['hashes'][$index],$hash))throw new RuntimeException('محتوای بخش تکراری تغییر کرده است.');
        } else {
            if($index!==$next)throw new RuntimeException('بخش‌های فایل باید به‌ترتیب ارسال شوند.');
            // JSON strings preserve arbitrary UTF-8 bytes through base64 without splitting a character.
            self::write($path.'/'.$index.'.json',['data'=>$encoded]);
            $state['hashes'][]=$hash;self::write($path.'/state.json',$state);
        }
        return ['uploaded'=>count($state['hashes']),'chunks'=>$chunks];
    }
    public static function read($token,$project,$owner) {
        [$path,$state]=self::state($token,$project,$owner);
        if(count($state['hashes'])!==(int)ceil($state['bytes']/self::CHUNK_BYTES))throw new RuntimeException('ارسال فایل هنوز کامل نشده است.');
        $raw='';
        foreach($state['hashes'] as $index=>$hash) {
            $part=json_decode(@file_get_contents($path.'/'.$index.'.json')?:'null',true);
            $data=base64_decode($part['data']??'',true);
            if($data===false||!hash_equals($hash,hash('sha256',$data)))throw new RuntimeException('بخش ذخیره‌شدهٔ فایل معتبر نیست؛ فایل را دوباره ارسال کنید.');
            $raw.=$data;
        }
        if(strlen($raw)!==$state['bytes'])throw new RuntimeException('حجم فایل کامل نیست.');
        return $raw;
    }
}
