<?php
/** Website data enriches existing products; ERP remains the product/variant authority. */
class WebsiteCatalogSync {
    public static function code($value) {
        return trim(strtr((string)$value, array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789'))));
    }
    public static function url($value) {
        if (!is_string($value) || !filter_var($value,FILTER_VALIDATE_URL)) return '';
        $parts=parse_url($value);
        if (!in_array(strtolower($parts['scheme']??''),['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) return '';
        return $value;
    }
    public static function origin($url) {
        $valid=self::url($url);if($valid==='')return '';
        $p=parse_url($valid);
        return $p ? strtolower($p['scheme'].'://'.$p['host']).(isset($p['port'])?':'.$p['port']:'') : '';
    }
    private static function identityUrl($url) {
        $valid=self::url($url);if($valid==='')return '';
        $p=parse_url($valid); if(!$p)return '';
        $path=rawurldecode($p['path']??'/');
        if(preg_match('~/product/([0-9]+)(?:/|$)~',$path,$m))$path='/product/'.$m[1];
        return self::origin($url).rtrim($path,'/');
    }
    public static function isWebsiteRows($rows) {
        if(!is_array($rows)||!$rows||array_keys($rows)!==range(0,count($rows)-1))return false;
        foreach($rows as $row)if(!is_array($row)||!isset($row['url'])||!isset($row['product_code'])||isset($row['_erp_contract'])||isset($row['ProCode'])||isset($row['StockTedad'])||self::url($row['url'])==='')return false;
        return true;
    }
    public static function looksLikeWebsite($rows) {
        if(!is_array($rows)||!isset($rows[0]))return false;
        foreach($rows as $row)if(is_array($row)&&array_key_exists('url',$row)&&isset($row['product_code'])&&!isset($row['ProCode'])&&!isset($row['_erp_contract'])&&!isset($row['StockTedad']))return true;
        return false;
    }
    private static function number($value,$allowNegative=false) {
        if(!is_scalar($value)||is_bool($value))throw new RuntimeException('مقدار عددی نامعتبر است.');
        $text=str_replace([',','٬',' '],['','',''],self::code($value));
        $text=preg_replace('/[\s\x{200c}\x{00a0}]/u','',str_replace('٫','.',$text));
        if(!is_numeric($text)||!is_finite((float)$text)||(!$allowNegative&&((float)$text)<0))throw new RuntimeException('قیمت یا موجودی نامعتبر است.');
        return (float)$text;
    }
    private static function price($row,$unit) {
        $value=$row['price'];if(!is_scalar($value))throw new RuntimeException('قیمت نامعتبر است.');
        $text=self::code($value);$hasRial=mb_stripos($text,'ریال')!==false||preg_match('/\b(?:rial|irr)\b/i',$text);$hasToman=mb_stripos($text,'تومان')!==false||preg_match('/\b(?:toman|irt)\b/i',$text);
        if($unit==='auto') {
            if($hasRial)$unit='rial';elseif($hasToman)$unit='toman';else $unit=$row['price_unit']??'';
        }
        if(!in_array($unit,['rial','toman'],true))throw new RuntimeException('واحد قیمت عددی مشخص نیست؛ ریال یا تومان را انتخاب کنید.');
        $number=self::number(trim(preg_replace('/(?:ریال|تومان|rial|toman|irr|irt)/iu','',$text)));
        return $unit==='rial'?$number/10:$number;
    }
    public static function state($project,$product) {
        $meta=$product['website_sync']??[];
        $hasDescription=trim((string)($product['description']??''))!=='';
        $hasChart=function_exists('hasProductSizeChart')?hasProductSizeChart($product):(!empty($product['size_chart'])||!empty($product['size_chart_template_id']));
        $hasImage=function_exists('hasProductFinalImages')?hasProductFinalImages($product):!empty($product['image']);
        if (($meta['presence']??'')==='missing' && !in_array($meta['publication']??'', ['technical_pending','resolved'], true)) $meta['publication']=$hasImage?'pending_upload':'pending_image';
        if (($meta['publication']??'')==='resolved' && empty($product['uploaded_to_site'])) $meta['publication']=$hasImage?'pending_upload':'pending_image';
        if (($meta['presence']??'')==='seen' && empty($product['uploaded_to_site']) && $hasImage) $meta['publication']='pending_upload';
        return array_replace($meta,[
            'needs_description'=>!$hasDescription,
            'needs_size_chart'=>!$hasChart,
            'description_upload_pending'=>$hasDescription&&array_key_exists('has_description',$meta)&&!$meta['has_description'],
            'size_chart_upload_pending'=>$hasChart&&array_key_exists('has_size_chart',$meta)&&!$meta['has_size_chart'],
            'needs_image'=>!$hasImage,
        ]);
    }
    public static function fingerprint($project) {
        return hash('sha256',json_encode([$project['id'],$project['products']??[],$project['settings']['website_catalog']??[]],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
    public static function reconcile($project,$rows,$options=[]) {
        if(!self::isWebsiteRows($rows)||count($rows)>50000)throw new RuntimeException('فایل باید آرایهٔ خروجی سایت با url و product_code باشد (حداکثر ۵۰۰۰۰ ردیف).');
        $options=array_replace(['complete'=>false,'update_price'=>true,'update_stock'=>true,'price_unit'=>'auto'],$options);
        $origins=[];$seenRows=[];
        foreach($rows as $row) {
            $origin=self::origin($row['url']);$code=self::code($row['product_code']);
            if(!$origin||$code==='')throw new RuntimeException('کد یا آدرس یک ردیف نامعتبر است.');
            if(isset($seenRows[$code]))throw new RuntimeException('کد تکراری در خروجی سایت؛ ابتدا فایل را بررسی کنید.');
            $seenRows[$code]=true;$origins[$origin]=true;
        }
        if(count($origins)!==1)throw new RuntimeException('خروجی باید متعلق به یک دامنهٔ سایت باشد.');
        $origin=key($origins);$previousOrigin=$project['settings']['website_catalog']['origin']??'';
        if($previousOrigin!==''&&$origin!==$previousOrigin)throw new RuntimeException('دامنهٔ فایل با سایت ثبت‌شدهٔ این پروژه متفاوت است.');
        $codes=[];$urls=[];
        foreach(($project['products']??[]) as $index=>$product) {
            foreach(['sku','product_code'] as $field)if(isset($product[$field])&&self::code($product[$field])!=='')$codes[self::code($product[$field])][$index]=true;
            foreach(($product['variants']??[]) as $variant)if(is_array($variant))foreach(['sku','product_code'] as $field)if(!empty($variant[$field]))$codes[self::code($variant[$field])][$index]=true;
            $u=self::identityUrl($product['link']??$product['url']??'');if($u!=='')$urls[$u][$index]=true;
        }
        $counts=['matched'=>0,'ignored'=>0,'conflicts'=>0,'missing'=>0,'technical'=>0,'price'=>0,'stock'=>0,'images'=>0,'new'=>0];
        $covered=[];$groups=[];$changes=[];$warnings=[];
        foreach($rows as $row) {
            $candidates=($codes[self::code($row['product_code'])]??[])+($urls[self::identityUrl($row['url'])]??[]);
            foreach($candidates as $index=>$_)$covered[$index]=true;
            if(count($candidates)!==1){$counts[count($candidates)?'conflicts':'ignored']++;if($candidates)$warnings[]='تطبیق مبهم برای کد '.self::code($row['product_code']).'؛ هیچ‌یک تغییر نکردند.';continue;}
            $groups[key($candidates)][]=$row;$counts['matched']++;
        }
        if (!$groups) {
            if ($options['complete']) throw new RuntimeException('هیچ تطبیق قطعی با محصولات پروژه پیدا نشد؛ بررسی غیبت اعمال نشد. ابتدا کدها و دامنه را بررسی کنید.');
            return ['project'=>$project,'counts'=>$counts,'changes'=>[],'warnings'=>$warnings];
        }
        $now=time();
        foreach($groups as $index=>$group) {
            $product=&$project['products'][$index];$id=(string)$product['id'];
            $meta=$product['website_sync']??[];
            $meta=array_replace($meta,['presence'=>'seen','origin'=>$origin,'checked_at'=>$now,'publication'=>'uploaded']);
            $siteImages=[];$descriptions=[];$charts=[];$prices=[];$descriptionObserved=false;$chartObserved=false;
            foreach($group as $row) {
                $code=self::code($row['product_code']);
                if($options['update_price']&&array_key_exists('price',$row)&&$row['price']!==null&&$row['price']!=='')$prices[] = self::price($row,$options['price_unit']);
                if($options['update_stock'])foreach(['stock_online','stock_total'] as $field)if(array_key_exists($field,$row)&&$row[$field]!==null&&$row[$field]!=='') {
                    $stock=self::number($row[$field],true);
                    if(!empty($product['variants'])&&self::code($product['sku']??'')!==$code){$warnings[]='موجودی کد تنوع '.$code.' بدون تجمیع تیام جایگزین موجودی مادر نشد.';continue;}
                    $product[$field]=$stock;$counts['stock']++;
                }
                if(array_key_exists('description',$row)){if($row['description']!==null&&!is_string($row['description']))throw new RuntimeException('توضیحات ردیف سایت باید متن باشد.');$descriptionObserved=true;}
                if(array_key_exists('size_guide',$row)){if($row['size_guide']!==null&&!is_string($row['size_guide']))throw new RuntimeException('راهنمای سایز باید لینک یا مقدار خالی باشد.');$chartObserved=true;}
                if(isset($row['description'])&&is_string($row['description'])&&trim($row['description'])!=='')$descriptions[]=trim($row['description']);
                if(!empty($row['size_guide'])&&is_string($row['size_guide'])) {
                    $candidate=$row['size_guide'];if(substr($candidate,0,1)==='/')$candidate=$origin.$candidate;
                    if(self::url($candidate)!=='')$charts[]=$candidate;
                }
                $images=$row['gallery']??[];if(!is_array($images))throw new RuntimeException('گالری نامعتبر است.');
                if(!empty($row['main_image']))array_unshift($images,$row['main_image']);
                foreach($images as $image)if(self::url($image)!=='')$siteImages[$image]=true;
            }
            if($prices) {
                $unique=array_unique($prices,SORT_REGULAR);
                if(count($unique)===1){$product['price']=rtrim(rtrim(number_format($prices[0],4,'.',''),'0'),'.').' تومان';if($prices[0]===0.0)$product['price']='0 تومان';$counts['price']++;}
                else $warnings[]='قیمت‌های متفاوت برای محصول '.$id.'؛ قیمت مادر تغییر نکرد.';
            }
            // Local content is never erased by an empty or missing website field.
            if($descriptionObserved)$meta['has_description']=(bool)$descriptions;
            if($chartObserved)$meta['has_size_chart']=(bool)$charts;
            if($descriptions){$meta['has_description']=true;if(empty($product['description']))$product['description']=$descriptions[0];}
            if($charts){$meta['has_size_chart']=true;$meta['size_guide_urls']=array_values(array_unique($charts));if(empty($product['size_chart'])&&empty($product['size_chart_template_id'])&&preg_match('/\.(?:png|jpe?g|webp|gif)(?:[?#]|$)/i',$charts[0]))$product['size_chart']=$charts[0];}
            $meta['image_urls']=array_keys($siteImages);
            $oldImages=$product['images_list']??[];if(is_string($oldImages))$oldImages=json_decode($oldImages,true);
            if(!is_array($oldImages))throw new RuntimeException('ساختار تصاویر قبلی محصول نامعتبر است؛ برای حفظ تصاویر، ثبت متوقف شد.');
            $product['images_list']=$oldImages;
            $existing=[];foreach($product['images_list'] as $image){$u=is_array($image)?($image['url']??''):$image;if(is_string($u))$existing[$u]=true;}
            foreach(array_keys($siteImages) as $image)if(!isset($existing[$image])){$product['images_list'][]=['url'=>$image,'source'=>'site','approved'=>true,'uploaded_to_site'=>true,'created_at'=>$now];$counts['images']++;}
            $product['uploaded_to_site']=1;
            // Keep the original primary image, gallery, social image and reference gallery.
            if(empty($product['link']))$product['link']=$group[0]['url'];
            $product['website_sync']=$meta;
            $changes[]=['id'=>$id,'name'=>$product['name']??$id,'action'=>'تکمیل اطلاعات سایت'];unset($product);
        }
        if($options['complete'])foreach($project['products'] as $index=>&$product) {
            if(isset($covered[$index]))continue;
            $linkedOrigin=self::origin($product['link']??$product['url']??'');if($linkedOrigin!==''&&$linkedOrigin!==$origin)continue;
            $id=(string)$product['id'];$meta=$product['website_sync']??[];
            $hasImage=function_exists('hasProductFinalImages')?hasProductFinalImages($product):!empty($product['image']);
            $publication=!empty($product['uploaded_to_site'])&&$hasImage?'technical_pending':($hasImage?'pending_upload':'pending_image');
            $meta=array_replace($meta,['presence'=>'missing','origin'=>$origin,'checked_at'=>$now,'publication'=>$publication]);
            unset($meta['resolved_at'],$meta['resolved_by']);
            $product['website_sync']=$meta;
            $product['uploaded_to_site']=0;$counts['missing']++;if($publication==='technical_pending')$counts['technical']++;
            $changes[]=['id'=>$id,'name'=>$product['name']??$id,'action'=>['technical_pending'=>'باگ فنی / منتظر آپلود','pending_upload'=>'منتظر آپلود','pending_image'=>'منتظر تولید عکس'][$publication]];
        }unset($product);
        $project['settings']['website_catalog']['origin']=$origin;
        $project['settings']['website_catalog']['last_sync']=['at'=>$now,'complete'=>(bool)$options['complete'],'rows'=>count($rows),'counts'=>$counts];
        return ['project'=>$project,'counts'=>$counts,'changes'=>$changes,'warnings'=>array_values(array_unique($warnings))];
    }
    public static function resolve(&$project,$id,$user) {
        foreach($project['products'] as &$product)if((string)$product['id']===(string)$id) {
            $meta=$product['website_sync']??[];
            $derived=self::state($project,$product);
            if(!in_array($derived['publication']??'',['technical_pending','pending_upload'],true))throw new RuntimeException('این محصول در صف رفع نقص انتشار نیست.');
            $meta['publication']='resolved';$meta['resolved_at']=time();$meta['resolved_by']=$user;
            $product['website_sync']=$meta;$product['uploaded_to_site']=1;return;
        }
        throw new RuntimeException('محصول یافت نشد.');
    }
}
