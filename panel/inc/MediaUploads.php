<?php
/** Validate bytes before any handler changes product data; originals are never re-encoded here. */
class MediaUploads {
    public static function validateFile($tmp,$name,$imageOnly=false) {
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $dangerous=['php','php3','php4','php5','php7','php8','phtml','pht','phar','phps','shtml','html','htm','svg','svgz','js','mjs','cgi','pl','py','sh','exe','bat','cmd','com','htaccess'];
        if(in_array($ext,$dangerous,true)||preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i',$name))throw new RuntimeException('این نوع فایل مجاز نیست. برای تصویر از JPG، PNG، WebP یا GIF استفاده کنید.');
        if(!is_file($tmp)||filesize($tmp)>32*1024*1024)throw new RuntimeException('فایل در دسترس نیست یا حجم آن بیشتر از ۳۲ مگابایت است.');
        $images=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        if($imageOnly||isset($images[$ext])) {
            if(!isset($images[$ext])||!class_exists('finfo'))throw new RuntimeException('فرمت تصویر مجاز نیست یا افزونهٔ fileinfo فعال نیست.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$info=@getimagesize($tmp);
            if($mime!==$images[$ext]||!$info||($info['mime']??'')!==$mime||$info[0]*$info[1]>100000000)throw new RuntimeException('محتوای فایل با تصویر معتبر مطابقت ندارد یا ابعاد آن بیش از حد بزرگ است.');
        }
        return true;
    }
    public static function validateRequest($files,$path='') {
        foreach($files as $field=>$file) {
            if(!is_array($file)||!isset($file['name'],$file['tmp_name'],$file['error']))throw new RuntimeException('ساختار فایل ارسالی معتبر نیست.');
            $walk=static function($name,$tmp,$error)use(&$walk,$field,$path){
                if(is_array($name)) {
                    foreach($name as $key=>$value)$walk($value,$tmp[$key]??null,$error[$key]??UPLOAD_ERR_NO_FILE);
                    return;
                }
                if($error===UPLOAD_ERR_NO_FILE)return;
                if($error!==UPLOAD_ERR_OK||!is_string($tmp)||!is_string($name))throw new RuntimeException('آپلود فایل کامل نشد؛ محدودیت حجم هاست را بررسی کنید.');
                $imageOnly=(bool)preg_match('/image|photo|gallery|reference|chart|logo|icon|avatar/i',$field);
                if($field==='file'&&strpos($path,'/size/')!==false&&($_POST['action']??'')!=='td_scs_font_upload')$imageOnly=true;
                self::validateFile($tmp,$name,$imageOnly);
            };
            $walk($file['name'],$file['tmp_name'],$file['error']);
        }
    }
}
