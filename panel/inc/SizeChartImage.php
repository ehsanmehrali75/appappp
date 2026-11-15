<?php
/** Hard byte limit for size-chart derivatives; never crop a table to meet the limit. */
class SizeChartImage {
    public const MAX_BYTES = 400000;
    public static function decode($data) {
        if(!is_string($data)||strlen($data)>33554432)throw new RuntimeException('دادهٔ تصویر جدول سایز معتبر نیست یا بیش از حد بزرگ است.');
        if(strpos($data,'data:')===0) {
            if(!preg_match('~^data:image/(?:png|jpeg|jpg|webp);base64,(.*)$~sD',$data,$m))throw new RuntimeException('فرمت تصویر جدول سایز مجاز نیست.');
            $data=$m[1];
        }
        $bytes=base64_decode(str_replace(' ','+',$data),true);
        if($bytes===false||$bytes==='')throw new RuntimeException('تصویر جدول سایز قابل خواندن نیست.');
        return $bytes;
    }
    public static function compress($bytes,$format='jpeg') {
        if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg')||($format==='webp'&&!function_exists('imagewebp')))throw new RuntimeException('فشرده‌سازی جدول سایز به افزونهٔ GD نیاز دارد.');
        if(!is_string($bytes)||strlen($bytes)>25165824||!in_array($format,['jpeg','webp'],true))throw new RuntimeException('ورودی فشرده‌سازی جدول سایز معتبر نیست.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||$info[0]<1||$info[1]<1||$info[0]*$info[1]>32000000)throw new RuntimeException('ابعاد تصویر جدول سایز معتبر نیست یا بسیار بزرگ است.');
        $limit=ini_get('memory_limit');
        if($limit&&$limit!=='-1') {
            $memory=(float)$limit*(['g'=>1073741824,'m'=>1048576,'k'=>1024][strtolower(substr($limit,-1))]??1);
            if($info[0]*$info[1]*8+16777216>$memory-memory_get_usage(true))throw new RuntimeException('حافظهٔ هاست برای فشرده‌سازی کافی نیست؛ ابعاد خروجی را کمتر کنید.');
        }
        $src=@imagecreatefromstring($bytes);if(!$src)throw new RuntimeException('تصویر جدول سایز قابل خواندن نیست.');
        try {
            if(($info['mime']??'')==='image/jpeg'&&strpos($bytes,"Exif\0\0")!==false&&function_exists('exif_read_data')) {
                $temp=tempnam(sys_get_temp_dir(),'scs-exif-');if($temp===false)throw new RuntimeException('خواندن جهت تصویر ممکن نشد.');
                try {if(file_put_contents($temp,$bytes)!==strlen($bytes))throw new RuntimeException('خواندن جهت تصویر ممکن نشد.');$exif=@exif_read_data($temp);}
                finally {unlink($temp);}
                $orientation=(int)($exif['Orientation']??1);
                if(in_array($orientation,[2,4,5,7],true))imageflip($src,in_array($orientation,[2,5,7],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);
                if(in_array($orientation,[3,5,6,7,8],true)) {
                    $rotated=imagerotate($src,[3=>180,5=>-90,6=>-90,7=>90,8=>90][$orientation],0);
                    if(!$rotated)throw new RuntimeException('اصلاح جهت تصویر ناموفق بود.');imagedestroy($src);$src=$rotated;
                }
                $info[0]=imagesx($src);$info[1]=imagesy($src);
            }
            $ratio=min(1,3200/max($info[0],$info[1]));
            for($step=0;$step<28;$step++,$ratio*=0.82) {
                $w=max(1,(int)round($info[0]*$ratio));$h=max(1,(int)round($info[1]*$ratio));
                $dst=imagecreatetruecolor($w,$h);if(!$dst)throw new RuntimeException('ساخت تصویر فشرده ناموفق بود.');
                try {
                    imagefill($dst,0,0,imagecolorallocate($dst,255,255,255));
                    imagecopyresampled($dst,$src,0,0,0,0,$w,$h,$info[0],$info[1]);
                    foreach([90,82,74,66] as $quality) {
                        ob_start();$ok=$format==='webp'?imagewebp($dst,null,$quality):imagejpeg($dst,null,$quality);$out=ob_get_clean();
                        if($ok&&is_string($out)&&strlen($out)>0&&strlen($out)<=self::MAX_BYTES)return $out;
                    }
                } finally {imagedestroy($dst);}
                if(min($w,$h)<=128)break;
            }
        } finally {imagedestroy($src);}
        throw new RuntimeException('فشرده‌سازی تا سقف ۴۰۰ کیلوبایت ممکن نشد؛ تصویر ذخیره نشد.');
    }
    public static function save($directory,$bytes,$prefix='psizechart_') {
        $out=self::compress($bytes); // Always enforce on the server, even for small client payloads.
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix))throw new RuntimeException('نام فایل تصویر معتبر نیست.');
        $directory=rtrim($directory,'/').'/';
        if(!is_dir($directory)&&!mkdir($directory,0755,true)&&!is_dir($directory))throw new RuntimeException('فضای تصویر قابل نوشتن نیست.');
        $name=$prefix.bin2hex(random_bytes(16)).'.jpg';$tmp=$directory.$name.'.tmp';
        try {
            if(file_put_contents($tmp,$out,LOCK_EX)!==strlen($out)||filesize($tmp)>self::MAX_BYTES)throw new RuntimeException('نوشتن تصویر فشرده ناموفق بود.');
            if(!chmod($tmp,0644)||!rename($tmp,$directory.$name))throw new RuntimeException('ثبت تصویر فشرده ناموفق بود.');
        } finally {if(is_file($tmp))unlink($tmp);}
        $info=getimagesizefromstring($out);
        return ['url'=>'uploads/'.$name,'bytes'=>strlen($out),'width'=>$info[0],'height'=>$info[1]];
    }
}
