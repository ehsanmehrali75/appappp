<?php
require_once __DIR__.'/WebsiteCatalogSync.php';
/** Create website derivatives only. Originals are read and never rewritten. */
class WebsiteExportPackage {
    private $uploads;
    private $host;
    public function __construct($uploads,$host='') {$this->uploads=rtrim(realpath($uploads)?:$uploads,'/').'/';$this->host=strtolower(explode(':',$host)[0]);}
    private function read($url) {
        $safe=ProductSizeChartCatalog::mediaUrl($url);if(!$safe)return false;
        $parts=parse_url($safe);$host=strtolower($parts['host']??'');$path=rawurldecode($parts['path']??'');
        if(!$host||$host===$this->host){
            $prefix=preg_replace('~^/(?:panel/)?uploads/~','',$path);$file=realpath($this->uploads.$prefix);
            return $file&&strpos($file,$this->uploads)===0&&is_file($file)&&filesize($file)<=25*1024*1024?file_get_contents($file):false;
        }
        if(!function_exists('curl_init'))return false;
        for($redirects=0;$redirects<4;$redirects++){
            $p=parse_url($safe);$host=$p['host']??'';$ips=gethostbynamel($host)?:[];
            $ips=array_values(array_filter($ips,fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)));
            if(!$ips)return false;
            $body='';$location='';$curl=curl_init($safe);$port=$p['port']??(($p['scheme']??'https')==='https'?443:80);
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Maarksho-WebsiteExport/1.0',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]],CURLOPT_WRITEFUNCTION=>static function($ch,$data)use(&$body){if(strlen($body)+strlen($data)>25*1024*1024)return 0;$body.=$data;return strlen($data);},CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);}]);
            $ok=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if($ok!==false&&$code===200)return $body;
            if(!in_array($code,[301,302,303,307,308],true)||!$location)return false;
            if(strpos($location,'//')===0)$location=($p['scheme']??'https').':'.$location;
            elseif(strpos($location,'/')===0)$location=WebsiteCatalogSync::origin($safe).$location;
            elseif(!preg_match('~^https?://~i',$location))$location=WebsiteCatalogSync::origin($safe).rtrim(dirname($p['path']??'/'),'/').'/'.$location;
            if(!WebsiteCatalogSync::url($location))return false;$safe=$location;
        }
        return false;
    }
    private static function name($name) {return trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1f]~u','_', (string)$name)," .\t\n\r")?:'product';}
    private static function color($product) {
        if(preg_match('/^#[a-f0-9]{6}$/i',$product['color_hex']??''))return $product['color_hex'];
        $map=['مشکی'=>'#000000','سیاه'=>'#000000','سفید'=>'#ffffff','قرمز'=>'#ff0000','آبی'=>'#0000ff','سبز'=>'#008000','طوسی'=>'#808080','خاکستری'=>'#808080','کرم'=>'#f2e8c9','زرد'=>'#ffff00','قهوه ای'=>'#8b4513','صورتی'=>'#ffc0cb','بنفش'=>'#800080','نارنجی'=>'#ffa500','سرمه ای'=>'#000080','یشمی'=>'#2e8b57','زیتونی'=>'#808000','بژ'=>'#f5f5dc','کاکی'=>'#f0e68c','آبی روشن'=>'#add8e6','فیروزه ای'=>'#40e0d0','لیمویی'=>'#fdfd96','نسکافه ای'=>'#b28e67','خردلی'=>'#e1ad01','کاپوچینو'=>'#7c533e','بادمجانی'=>'#4e2a5a'];
        return $map[str_replace('‌',' ',trim($product['color']??''))]??'#cccccc';
    }
    private static function pair($source,$resize) {
        $info=@getimagesizefromstring($source);if(!$info||$info[0]*$info[1]>40000000)throw new RuntimeException('تصویر نامعتبر یا بزرگ‌تر از ظرفیت پردازش است.');
        $memory=ini_get('memory_limit');
        if($memory&&$memory!=='-1'){
            $bytes=(float)$memory*(['g'=>1073741824,'m'=>1048576,'k'=>1024][strtolower(substr($memory,-1))]??1);
            if($info[0]*$info[1]*5+16*1024*1024>($bytes-memory_get_usage(true)))throw new RuntimeException('حافظهٔ هاست برای پردازش این تصویر کافی نیست؛ اصل تصویر حفظ شده است.');
        }
        $src=@imagecreatefromstring($source);if(!$src)throw new RuntimeException('خواندن تصویر ممکن نشد.');
        if($resize&&function_exists('exif_read_data')&&($info['mime']??'')==='image/jpeg'){
            $tmp=tempnam(sys_get_temp_dir(),'we-exif-');file_put_contents($tmp,$source);$exif=@exif_read_data($tmp);unlink($tmp);$orientation=(int)($exif['Orientation']??1);
            if(in_array($orientation,[2,4,5,7],true))imageflip($src,in_array($orientation,[2,5,7],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);
            if(in_array($orientation,[3,5,6,7,8],true)){$rotated=imagerotate($src,[3=>180,5=>-90,6=>-90,7=>90,8=>90][$orientation],0);imagedestroy($src);$src=$rotated;}
        }
        $width=$resize?1000:imagesx($src);$height=$resize?1250:imagesy($src);
        $dst=imagecreatetruecolor($width,$height);$white=imagecolorallocate($dst,255,255,255);imagefill($dst,0,0,$white);
        // Fit the complete garment; do not crop the approved composition.
        $ratio=min($width/imagesx($src),$height/imagesy($src));$w=max(1,(int)round(imagesx($src)*$ratio));$h=max(1,(int)round(imagesy($src)*$ratio));
        imagecopyresampled($dst,$src,(int)(($width-$w)/2),(int)(($height-$h)/2),0,0,$w,$h,imagesx($src),imagesy($src));imagedestroy($src);
        ob_start();$jpgOk=imagejpeg($dst,null,88);$jpg=ob_get_clean();ob_start();$webpOk=imagewebp($dst,null,85);$webp=ob_get_clean();imagedestroy($dst);
        if(!$jpgOk||!$webpOk||!$jpg||!$webp)throw new RuntimeException('ساخت هر دو فرمت تصویر ناموفق بود.');
        return ['jpg'=>$jpg,'webp'=>$webp];
    }
    public function build($project,$ids,$allowEmpty=false) {
        if(!class_exists('ZipArchive')||!function_exists('imagewebp')||!function_exists('imagejpeg'))throw new RuntimeException('برای خروجی دوفرمتی، افزونه‌های Zip و GD با پشتیبانی WebP باید روی هاست فعال باشند.');
        $filename='website_export_'.bin2hex(random_bytes(16)).'.zip';$path=$this->uploads.$filename;$zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ساخت ZIP ممکن نشد.');
        $manifest=[];$warnings=[];$exported=[];$receipts=[];
        try{
            foreach(($project['products']??[]) as $product){
                $id=(string)$product['id'];if(!in_array($id,$ids,true))continue;
                $sku=(string)($product['sku']??$id);$state=WebsiteCatalogSync::state($project,$product);
                if($state['archived']){$warnings[]=$sku.': ناموجود؛ از خروجی اجرایی کنار گذاشته شد.';continue;}
                $photos=WebsiteCatalogSync::photos($product);
                if(!$photos||WebsiteCatalogSync::pendingApprovalPhotos($product)){$warnings[]=$sku.': عکس نهایی موجود نیست یا هنوز تأیید نشده است.';continue;}
                $folder=self::name($sku).' - '.self::name($product['name']??$id).' - '.self::name($product['color']??'');
                $folder.=' - '.substr(hash('sha256',$id),0,8);$files=[];$assets=['images'];
                try{
                    foreach($photos as $index=>$url){$bytes=$this->read($url);if(!$bytes)throw new RuntimeException('دریافت عکس '.($index+1).' ناموفق بود.');foreach(self::pair($bytes,true) as $ext=>$data)$files[$folder.'/'.($index===0?'main':'gallery_'.$index).'.'.$ext]=$data;}
                    $chart=$state['chart'];
                    if($chart['image_url']){$bytes=$this->read($chart['image_url']);if(!$bytes)throw new RuntimeException('دریافت جدول سایز ناموفق بود.');foreach(self::pair($bytes,false) as $ext=>$data)$files[$folder.'/size_chart.'.$ext]=$data;$assets[]='chart';}
                    else $warnings[]=$sku.': جدول سایز در بسته نیست؛ ابتدا در استودیو تولید یا خروجی تصویر بگیرید.';
                    $description=trim((string)($product['description']??''));
                    if($description!==''){$files[$folder.'/توضیحات محصول.txt']=html_entity_decode(strip_tags($description),ENT_QUOTES,'UTF-8');$assets[]='description';}
                    else $warnings[]=$sku.': توضیحات هنوز تولید نشده است.';
                    $hex=self::color($product);$pal=imagecreatetruecolor(24,24);imagefill($pal,0,0,imagecolorallocate($pal,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))));ob_start();imagepng($pal);$palData=ob_get_clean();imagedestroy($pal);
                    foreach(self::pair($palData,false) as $ext=>$data)$files[$folder.'/palette.'.$ext]=$data;
                    foreach($files as $name=>$data)if(!$zip->addFromString($name,$data))throw new RuntimeException('افزودن فایل به ZIP ناموفق بود.');
                    $exported[]=$id;$receipts[$id]=['assets'=>$assets,'image_fingerprint'=>WebsiteCatalogSync::photoFingerprint($product),'all_image_fingerprint'=>hash('sha256',json_encode($photos)),'chart_fingerprint'=>WebsiteCatalogSync::chartFingerprint($chart),'description_fingerprint'=>hash('sha256',$description)];
                    $manifest[]=['id'=>$id,'sku'=>$sku,'folder'=>$folder,'photos'=>count($photos),'assets'=>$assets,'photo_dimensions'=>[1000,1250],'formats'=>['jpg','webp'],'palette_hex'=>$hex];
                }catch(RuntimeException $e){$warnings[]=$sku.': '.$e->getMessage();}
            }
            if(!$exported){
                if(!$allowEmpty)throw new RuntimeException(implode("\n",$warnings)?:'محصول آمادهٔ خروجی یافت نشد.');
                $zip->close();@unlink($path);return ['zip_url'=>null,'exported_ids'=>[],'warnings'=>$warnings,'receipts'=>[]];
            }
            $zip->addFromString('manifest.json',json_encode(['generated_at'=>date(DATE_ATOM),'products'=>$manifest,'warnings'=>$warnings],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            if(!$zip->close())throw new RuntimeException('نهایی‌سازی ZIP ناموفق بود.');
            return ['zip_url'=>'uploads/'.$filename,'exported_ids'=>$exported,'warnings'=>$warnings,'receipts'=>$receipts];
        }catch(Throwable $e){@$zip->close();@unlink($path);throw $e;}
    }
    /** Merge disk-backed chunks so a long selection does not keep every image in memory. */
    public function merge($parts,$warnings=[]) {
        if(!$parts)throw new RuntimeException(implode("\n",$warnings)?:'محصول آمادهٔ خروجی یافت نشد.');
        $filename='website_export_'.bin2hex(random_bytes(16)).'.zip';$path=$this->uploads.$filename;$zip=new ZipArchive();$temps=[];$products=[];
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ساخت ZIP نهایی ممکن نشد.');
        try{
            foreach($parts as $part){
                if(!preg_match('/^website_export_[a-f0-9]{32}\.zip$/',basename($part)))throw new RuntimeException('بستهٔ خروجی معتبر نیست.');
                $input=new ZipArchive();if($input->open($this->uploads.basename($part))!==true)throw new RuntimeException('بستهٔ خروجی موقت در دسترس نیست.');
                try{
                    $manifest=json_decode($input->getFromName('manifest.json'),true);$products=array_merge($products,$manifest['products']??[]);
                    for($i=0;$i<$input->numFiles;$i++){
                        $name=$input->getNameIndex($i);if($name==='manifest.json')continue;
                        $stream=$input->getStream($name);if(!$stream)throw new RuntimeException('خواندن فایل خروجی موقت ناموفق بود.');
                        $temporary=tempnam(sys_get_temp_dir(),'we-merge-');$temps[]=$temporary;$dest=fopen($temporary,'wb');$copied=stream_copy_to_stream($stream,$dest);fclose($stream);fclose($dest);
                        if($copied===false||!$zip->addFile($temporary,$name))throw new RuntimeException('ادغام فایل‌های خروجی ناموفق بود.');
                    }
                }finally{$input->close();}
            }
            $zip->addFromString('manifest.json',json_encode(['generated_at'=>date(DATE_ATOM),'products'=>$products,'warnings'=>$warnings],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            if(!$zip->close())throw new RuntimeException('ذخیرهٔ ZIP نهایی ناموفق بود.');
            return 'uploads/'.$filename;
        }catch(Throwable $e){@$zip->close();@unlink($path);throw $e;}
        finally{foreach($temps as $temporary)@unlink($temporary);}
    }

}
