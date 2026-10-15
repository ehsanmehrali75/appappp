<?php
class SupportUploads {
    public static function receive($input,$directory) {
        if(!$input || empty($input['name']))return [];
        $names=is_array($input['name'])?$input['name']:[$input['name']];
        if(count($names)>3)throw new RuntimeException('حداکثر سه پیوست مجاز است.');
        $allowed=['png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'webp'=>['image/webp'],
            'txt'=>['text/plain'],'log'=>['text/plain'],'json'=>['application/json','text/plain']];
        if(!class_exists('finfo'))throw new RuntimeException('افزونهٔ PHP fileinfo برای بررسی امن پیوست‌ها لازم است.');
        $files=[];$total=0;$finfo=new finfo(FILEINFO_MIME_TYPE);
        try {
            foreach($names as $i=>$name){
                $error=is_array($input['error'])?$input['error'][$i]:$input['error'];if($error===UPLOAD_ERR_NO_FILE)continue;
                if($error!==UPLOAD_ERR_OK)throw new RuntimeException('آپلود پیوست ناموفق بود؛ محدودیت حجم هاست را بررسی کنید.');
                $tmp=is_array($input['tmp_name'])?$input['tmp_name'][$i]:$input['tmp_name'];
                $size=filesize($tmp);$total+=$size;
                if($size>3*1024*1024 || $total>6*1024*1024)throw new RuntimeException('هر پیوست حداکثر ۳ و مجموع پیوست‌ها حداکثر ۶ مگابایت است.');
                $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=$finfo->file($tmp);
                if(!isset($allowed[$ext]) || !in_array($mime,$allowed[$ext],true))throw new RuntimeException('پیوست فقط PNG، JPG، WebP یا فایل متنی LOG، TXT، JSON قابل قبول است.');
                if(strpos($mime,'image/')===0 && !getimagesize($tmp))throw new RuntimeException('ساختار تصویر معتبر نیست.');
                $id=bin2hex(random_bytes(24));$target=$directory.'/'.$id.'.upload';
                if(!move_uploaded_file($tmp,$target))throw new RuntimeException('ذخیرهٔ خصوصی پیوست ناموفق بود.');chmod($target,0600);
                $files[]=['id'=>$id,'name'=>mb_substr(preg_replace('/[\x00-\x1f\x7f]/','',basename($name)),0,200),'mime'=>$mime,'size'=>$size];
            }
            return $files;
        }catch(Throwable $e){self::remove($files,$directory);throw $e;}
    }
    public static function remove($files,$directory){foreach($files as $f)if(preg_match('/^[a-f0-9]{48}$/D',$f['id']))@unlink($directory.'/'.$f['id'].'.upload');}
}
