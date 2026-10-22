<?php
/** Website data enriches existing products; ERP remains the product/variant authority. */
require_once __DIR__.'/ProductSizeChartCatalog.php';
require_once __DIR__.'/ImageProduction.php';
class WebsiteCatalogSync {
    /** Legacy imports may serialize nested metadata again; malformed values carry no evidence. */
    private static function metadataArray($value,$list=false) {
        if(is_string($value))$value=json_decode($value,true);
        if(!is_array($value))return [];
        // PHP 8.0 hosts have no array_is_list(); keep the same strict list rule.
        if($list&&$value!==[]&&array_keys($value)!==range(0,count($value)-1))return [];
        return $value;
    }
    private static function metadata($product) {
        $meta=self::metadataArray($product['website_sync']??[]);
        foreach(['image_urls','size_guide_urls','page_urls'] as $field) {
            $urls=[];
            foreach(self::metadataArray($meta[$field]??[],true) as $url) {
                $url=ProductSizeChartCatalog::mediaUrl($url);
                if($url!=='')$urls[$url]=true;
            }
            $meta[$field]=array_keys($urls);
        }
        foreach(['local_image_fingerprint','local_chart_fingerprint','local_description_fingerprint'] as $field)
            if(isset($meta[$field])&&!is_string($meta[$field]))$meta[$field]='';
        if(array_key_exists('manual_upload_receipt',$meta)) {
            $receipt=self::metadataArray($meta['manual_upload_receipt']);
            $receipt['assets']=array_values(array_filter(self::metadataArray($receipt['assets']??[],true),static fn($asset)=>is_string($asset)&&in_array($asset,['images','chart','description'],true)));
            foreach(['image_fingerprint','all_image_fingerprint','chart_fingerprint','description_fingerprint'] as $field)
                if(isset($receipt[$field])&&!is_string($receipt[$field]))$receipt[$field]='';
            $meta['manual_upload_receipt']=$receipt;
        }
        return $meta;
    }
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
    /** Website photos exclude references and social-only assets; portal rules remain untouched. */
    public static function photos($product,$localOnly=false) {
        $out=[];$site=self::metadata($product)['image_urls'];
        $social=ProductSizeChartCatalog::mediaUrl($product['social_image']??$product['socialImage']??'');
        $add=function($item) use (&$out,$localOnly,$site,$social) {
            $url=ProductSizeChartCatalog::mediaUrl(is_array($item)?($item['url']??$item['src']??$item['image']??''):$item);
            $kind=is_array($item)?strtolower((string)($item['type']??$item['image_type']??$item['category']??'')):'';
            if (function_exists('isValidProductImage') && !isValidProductImage($url)) return;
            if (!$url || $url===$social || in_array($kind,['social','social_image','reference','ref'],true)) return;
            if ($localOnly && ((is_array($item)&&($item['source']??'')==='site') || in_array($url,$site,true))) return;
            $out[$url]=true;
        };
        if (!empty($product['image'])) $add($product['image']);
        foreach (['gallery','images_list','images'] as $field) {
            $items=$product[$field]??[];if(is_string($items))$items=json_decode($items,true);
            if(is_array($items))foreach($items as $item)$add($item);
        }
        return array_keys($out);
    }
    /** The website is approval evidence for that URL, never for a different manual image. */
    public static function imageApproved($product,$url) {
        $url=ProductSizeChartCatalog::mediaUrl($url);if(!$url)return false;
        $items=$product['images_list']??[];if(is_string($items))$items=json_decode($items,true);$decision=null;
        foreach(is_array($items)?$items:[] as $item) {
            if(!is_array($item)||ProductSizeChartCatalog::mediaUrl($item['url']??$item['src']??'')!==$url)continue;
            if(($item['source']??'')==='site'||!empty($item['site_verified_at']))return true;
            if(array_key_exists('approved',$item))$decision=(bool)$item['approved'];
        }
        foreach(self::metadata($product)['image_urls'] as $siteUrl)if($siteUrl===$url)return true;
        if($decision!==null)return $decision;
        return !in_array($product['status']??'active',['pending','waiting_approval','rejected'],true)&&empty($product['portal_pending']);
    }
    public static function pendingApprovalPhotos($product,$includeSocial=false) {
        $urls=self::photos($product);
        if($includeSocial){$social=ProductSizeChartCatalog::mediaUrl($product['social_image']??$product['socialImage']??'');if($social)$urls[]=$social;}
        return array_values(array_filter(array_unique($urls),fn($url)=>!self::imageApproved($product,$url)));
    }
    public static function chartFingerprint($chart) {
        return hash('sha256',json_encode([$chart['image_url'],$chart['columns'],$chart['rows']],JSON_UNESCAPED_UNICODE));
    }
    public static function photoFingerprint($product) {
        return hash('sha256',json_encode(self::photos($product,true),JSON_UNESCAPED_UNICODE));
    }
    /** Online stock is a subset, not an amount to add to total stock. Unknown totals stay unknown. */
    public static function totalStock($product) {
        try {return self::number($product['stock_total']??null);}
        catch (RuntimeException $e) {return null;}
    }
    public static function inventory($product) {
        $wp=self::metadata($product)['wordpress']??[];if(!empty($wp['not_in_latest_snapshot']))return 'unknown';if(!empty($wp['origin'])&&($wp['stock_status']??'')==='outofstock')return 'out_of_stock';
        if(($product['status']??'')==='out_of_stock')return 'out_of_stock';
        // A known central total is authoritative; website/legacy stock can be stale.
        $total=$product['stock_total']??null;
        if(is_scalar($total)&&!is_bool($total)&&trim((string)$total)!=='') {
            if(mb_strpos(self::code($total),'ناموجود')!==false)return 'out_of_stock';
            try {return self::number($total,true)>0?'in_stock':'out_of_stock';}
            catch (RuntimeException $e) {}
        }
        $known=false;$positive=false;
        foreach(['stock_online','stock'] as $key) {
            $value=$product[$key]??null;if(!is_scalar($value)||trim((string)$value)==='')continue;
            $value=self::code($value);
            if(mb_strpos($value,'ناموجود')!==false)return 'out_of_stock';
            try {$quantity=self::number($value,true);}
            catch (RuntimeException $e) {continue;}
            $known=true;if($quantity>0)$positive=true;
        }
        return $positive?'in_stock':($known?'out_of_stock':(!empty($wp['origin'])&&($wp['stock_status']??'')==='instock'?'in_stock':'unknown'));
    }
    public static function state($project,$product) {
        $meta=self::metadata($product);$product['website_sync']=$meta;
        $hasDescription=trim((string)($product['description']??''))!=='';
        $chart=ProductSizeChartCatalog::forProduct($project,$product);
        $siteChart=($meta['has_size_chart']??null)===true;
        $hasChart=$chart['exists']||$siteChart;
        $imageExempt=ImageProduction::imageExempt($product);$chartExempt=self::chartExempt($project,$product);
        $localPhotos=self::photos($product,true);$allPhotos=self::photos($product);
        $sitePhotos=($meta['presence']??'')==='missing'?[]:array_values(array_filter($meta['image_urls']??[],fn($url)=>self::url($url)!==''));
        $hasImage=(bool)$allPhotos;
        $approved=!self::pendingApprovalPhotos($product);
        $newManual=!empty($product['has_new_manual_image'])||($localPhotos&&isset($meta['local_image_fingerprint'])&&!hash_equals($meta['local_image_fingerprint'],self::photoFingerprint($product)));
        $pub=$meta['publication']??'';
        if (($meta['presence']??'')==='missing') {
            if (!in_array($pub,['technical_pending','resolved'],true)) $pub=$hasImage?'pending_upload':'pending_image';
            if ($pub==='resolved' && (empty($product['uploaded_to_site'])||$newManual)) $pub=$hasImage?'pending_upload':'pending_image';
        } elseif (($meta['presence']??'')==='seen') {
            $pub=$sitePhotos?'uploaded':($hasImage?'pending_upload':'pending_image');
            if ($newManual||empty($product['uploaded_to_site'])) $pub=$hasImage?'pending_upload':'pending_image';
        } elseif (empty($product['uploaded_to_site'])) $pub=$hasImage?'pending_upload':'pending_image';
        elseif($pub==='')$pub='resolved';
        if(($meta['publication']??'')==='resolved'&&!empty($product['uploaded_to_site'])&&!$newManual)$pub='resolved';
        if($newManual&&$hasImage)$pub='pending_upload';
        if(!$hasImage)$pub='pending_image';
        if ($pub==='pending_upload' && !$approved) $pub='pending_approval';
        $websiteKnown=in_array($meta['presence']??'',['seen','missing'],true);
        $sizePending=$chart['exists']&&(($meta['presence']??'')==='missing'||(array_key_exists('has_size_chart',$meta)&&!$meta['has_size_chart'])||(!$websiteKnown&&empty($product['size_chart_uploaded'])));
        if($chart['exists']&&isset($meta['local_chart_fingerprint'])&&!hash_equals($meta['local_chart_fingerprint'],self::chartFingerprint($chart)))$sizePending=true;
        $receipt=$meta['manual_upload_receipt']??[];
        $receiptAssets=$receipt['assets']??[];
        if(in_array('chart',$receiptAssets,true)&&hash_equals($receipt['chart_fingerprint']??'',self::chartFingerprint($chart)))$sizePending=false;
        $descriptionPending=$hasDescription&&(($meta['presence']??'')==='missing'||(array_key_exists('has_description',$meta)&&!$meta['has_description'])||(isset($meta['local_description_fingerprint'])&&!hash_equals($meta['local_description_fingerprint'],hash('sha256',trim((string)$product['description'])))));
        if(in_array('description',$receiptAssets,true)&&hash_equals($receipt['description_fingerprint']??'',hash('sha256',trim((string)($product['description']??'')))))$descriptionPending=false;
        $archived=self::inventory($product)==='out_of_stock';
        $production=ImageProduction::state($project,$product,$hasImage,$archived);
        $issue=ImageProduction::issueType($product);$blocked=$issue!=='';
        if(!$hasImage&&!$archived)$pub=$production['stage'];
        return array_replace($meta,[
            'site_visible_with_images'=>($meta['presence']??'')==='seen'&&(bool)$sitePhotos,
            'site_main_image_url'=>in_array($meta['main_image_url']??'', $sitePhotos,true)?$meta['main_image_url']:($sitePhotos[0]??null),
            'site_size_availability'=>($meta['presence']??'')==='seen'?self::metadataArray($meta['size_availability_by_page']??[]):[],
            'site_page_urls'=>($meta['presence']??'')==='seen'?($meta['page_urls']??[]):[],
            'archived'=>$archived,
            'image_production'=>$production,
            'publication'=>$blocked?($issue==='technical'?'technical_pending':'inventory_issue'):($archived?'archived':($imageExempt?'image_not_required':$pub)),'issue_type'=>$issue,'has_final_image'=>$hasImage,'production_blocked'=>$blocked,'inventory'=>self::inventory($product),
            'low_priority'=>self::inventory($product)==='out_of_stock',
            'needs_description'=>!$archived&&!$hasDescription,
            'image_not_required'=>$imageExempt,'chart_not_required'=>$chartExempt,
            'needs_size_chart'=>!$archived&&!$chartExempt&&!$hasChart,
            'description_upload_pending'=>!$archived&&$descriptionPending,
            'size_chart_upload_pending'=>!$archived&&!$chartExempt&&$sizePending,
            'needs_image'=>!$archived&&!$blocked&&!$imageExempt&&!$hasImage,'has_local_image'=>(bool)$localPhotos,'local_image_urls'=>$localPhotos,'site_image_urls'=>$sitePhotos,
            'chart'=>$chart,'site_chart_urls'=>($meta['presence']??'')==='missing'?[]:($meta['size_guide_urls']??[]),
            'comparison_available'=>(bool)($localPhotos&&$sitePhotos)||($chart['has_local']&&$siteChart),
            'image_upload_pending'=>!$archived&&!$blocked&&!$imageExempt&&$pub==='pending_upload','approval_pending'=>!$archived&&!$approved&&$hasImage,
            'size_chart_export_needed'=>!$archived&&!$chartExempt&&$sizePending&&!$chart['export_ready'],
        ]);
    }
    public static function fingerprint($project) {
        return hash('sha256',json_encode([$project['id'],$project['products']??[],$project['settings']['website_catalog']??[],$project['settings']['size_charts']??[],!empty($project['settings']['prod_require_reference']),!empty($project['settings']['prod_require_image_request'])],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
    /** Preserve the complete model phrase; only casing and whitespace are normalized. */
    private static function modelName($value) {
        return is_string($value)?mb_strtolower(trim(preg_replace('/[\s\x{00a0}]+/u',' ',$value))):'';
    }
    private static function colorName($value) {
        return preg_replace('/\s*[-–—]\s*/u','-',str_replace(['ي','ك'],['ی','ک'],self::modelName($value)));
    }
    /** Strip only this product's explicit color suffix, never arbitrary model words. */
    private static function productModel($product) {
        $name=self::modelName($product['name']??'');$color=self::colorName($product['color']??'');
        if($color!=='') {
            $normalized=str_replace(['ي','ك'],['ی','ک'],$name);
            $suffix=implode('\s*[-–—]\s*',array_map(static fn($part)=>preg_quote($part,'/'),explode('-',$color)));
            if(preg_match('/^(.*?)\s+[-–—]\s*'.$suffix.'$/u',$normalized,$parts)&&trim($parts[1])!=='')return trim($parts[1]);
        }
        return $name;
    }
    private static function nameCandidates($row,$models,$products) {
        $name=self::modelName($row['name']??'');
        preg_match_all('/[\p{L}\p{N}]+/u',$name,$tokens);
        $possible=[];foreach(array_unique($tokens[0]) as $token)foreach($models[$token]??[] as $index=>$model)$possible[$index]=$model;
        $candidates=[];
        foreach($possible as $index=>$model)if(preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($model,'/').'(?![\p{L}\p{N}_])/u',$name))$candidates[$index]=true;
        $color=self::colorName($row['color']??'');$byColor=[];
        foreach($candidates as $index=>$_){$productColor=self::colorName($products[$index]['color']??'');if($color===''||$productColor===''||($color!==''&&$productColor!==''&&$color!==$productColor))unset($candidates[$index]);elseif($color!==''&&$productColor===$color)$byColor[$index]=true;}
        if($byColor)$candidates=$byColor;
        return $candidates;
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
        $codes=[];$urls=[];$models=[];
        foreach(($project['products']??[]) as $index=>$product) {
            foreach(['sku','product_code'] as $field)if(isset($product[$field])&&self::code($product[$field])!=='')$codes[self::code($product[$field])][$index]=true;
            foreach(($product['variants']??[]) as $variant)if(is_array($variant))foreach(['sku','product_code'] as $field)if(!empty($variant[$field]))$codes[self::code($variant[$field])][$index]=true;
            $model=self::productModel($product);
            if($model!==''&&preg_match('/[\p{L}\p{N}]+/u',$model,$first))$models[$first[0]][$index]=$model;
            $u=self::identityUrl($product['link']??$product['url']??'');if($u!=='')$urls[$u][$index]=true;
        }
        $counts=['matched'=>0,'matched_name'=>0,'ignored'=>0,'conflicts'=>0,'missing'=>0,'technical'=>0,'price'=>0,'stock'=>0,'images'=>0,'new'=>0];
        $covered=[];$groups=[];$changes=[];$warnings=[];
        foreach($rows as $row) {
            $candidates=($codes[self::code($row['product_code'])]??[])+($urls[self::identityUrl($row['url'])]??[]);
            // An exact code is not permission to move content between known colors.
            $rowColor=self::colorName($row['color']??'');$colorConflict=false;
            foreach($candidates as $index=>$_){$localColor=self::colorName($project['products'][$index]['color']??'');if($rowColor!==''&&$localColor!==''&&$rowColor!==$localColor)$colorConflict=true;}
            if($colorConflict){$counts['conflicts']++;foreach($candidates as $index=>$_)$covered[$index]=true;$warnings[]='رنگ کد '.self::code($row['product_code']).' با رنگ محصول پنل متفاوت است؛ اطلاعات منتقل نشد.';continue;}
            $byName=!$candidates;
            if($byName)$candidates=self::nameCandidates($row,$models,$project['products']??[]);
            foreach($candidates as $index=>$_)$covered[$index]=true;
            if(count($candidates)!==1){$counts[count($candidates)?'conflicts':'ignored']++;if($candidates)$warnings[]='تطبیق مبهم برای کد '.self::code($row['product_code']).'؛ هیچ‌یک تغییر نکردند.';continue;}
            $groups[key($candidates)][]=$row;$counts['matched']++;if($byName)$counts['matched_name']++;
        }
        if (!$groups) {
            if ($options['complete']) throw new RuntimeException('هیچ تطبیق قطعی با محصولات پروژه پیدا نشد؛ بررسی غیبت اعمال نشد. ابتدا کدها و دامنه را بررسی کنید.');
            return ['project'=>$project,'counts'=>$counts,'changes'=>[],'warnings'=>$warnings];
        }
        $now=time();
        foreach($groups as $index=>$group) {
            $product=&$project['products'][$index];$id=(string)$product['id'];
            $meta=self::metadata($product);$product['website_sync']=$meta;
            // Website presence cannot acknowledge edits made locally since the last check.
            $localBefore=[
                'local_image_fingerprint'=>self::photoFingerprint($product),
                'local_chart_fingerprint'=>self::chartFingerprint(ProductSizeChartCatalog::forProduct($project,$product)),
                'local_description_fingerprint'=>hash('sha256',trim((string)($product['description']??''))),
            ];
            $receiptFields=['local_image_fingerprint'=>['images','image_fingerprint'],'local_chart_fingerprint'=>['chart','chart_fingerprint'],'local_description_fingerprint'=>['description','description_fingerprint']];
            $receipt=$meta['manual_upload_receipt']??[];$unconfirmed=[];
            foreach($localBefore as $field=>$fingerprint) {
                if(!isset($meta[$field])||hash_equals($meta[$field],$fingerprint))continue;
                [$asset,$receiptField]=$receiptFields[$field];
                if(in_array($asset,$receipt['assets']??[],true)&&hash_equals($receipt[$receiptField]??'',$fingerprint))continue;
                $unconfirmed[$field]=$meta[$field];
            }
            $meta=array_replace($meta,['presence'=>'seen','origin'=>$origin,'checked_at'=>$now,'publication'=>'uploaded']);
            $siteImages=[];$pageUrls=[];$mainImages=[];$descriptions=[];$charts=[];$prices=[];$descriptionObserved=false;$chartObserved=false;$imageObserved=false;$availabilityObserved=false;$availabilityByPage=[];
            foreach($group as $row) {
                $code=self::code($row['product_code']);
                if(array_key_exists('category',$row)||array_key_exists('category_path',$row)) {
                    $meta['site_category_labels']=self::categories($row);
                    $meta['site_category_path']=implode('/',$meta['site_category_labels']);
                    $meta['site_primary_category']=$meta['site_category_labels']?end($meta['site_category_labels']):null;
                }
                // Website availability is observation only; never replace ERP quantities or variants.
                if(array_key_exists('size_availability',$row)) {
                    if(!is_array($row['size_availability'])||count($row['size_availability'])>300)throw new RuntimeException('ساختار موجودی سایزهای سایت معتبر نیست.');
                    $availability=[];foreach($row['size_availability'] as $entry){if(!is_array($entry)||!is_string($entry['size']??null)||strlen($entry['size'])>100||(!is_bool($entry['available']??null)&&($entry['available']??null)!==null))throw new RuntimeException('وضعیت موجودی سایز باید بله، خیر یا نامشخص باشد.');$availability[]=['size'=>$entry['size'],'available'=>$entry['available']??null];}
                    $availabilityObserved=true;$availabilityByPage[$row['url']]=$availability;
                }
                $pageUrls[self::url($row['url'])]=true;
                if(self::url($row['main_image']??'')!=='')$mainImages[self::url($row['main_image'])]=true;
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
                if(array_key_exists('gallery',$row)||array_key_exists('main_image',$row))$imageObserved=true;
                $images=$row['gallery']??[];if(!is_array($images))throw new RuntimeException('گالری نامعتبر است.');
                if(!empty($row['main_image']))array_unshift($images,$row['main_image']);
                foreach($images as $image)if(self::url($image)!=='')$siteImages[$image]=true;
            }
            if($availabilityObserved)$meta['size_availability_by_page']=$availabilityByPage;
            if($prices) {
                $unique=array_unique($prices,SORT_REGULAR);
                if(count($unique)===1){$product['price']=rtrim(rtrim(number_format($prices[0],4,'.',''),'0'),'.').' تومان';if($prices[0]===0.0)$product['price']='0 تومان';$counts['price']++;}
                else $warnings[]='قیمت‌های متفاوت برای محصول '.$id.'؛ قیمت مادر تغییر نکرد.';
            }
            // Local content is never erased by an empty or missing website field.
            if($descriptionObserved)$meta['has_description']=(bool)$descriptions;
            if($chartObserved){$meta['has_size_chart']=(bool)$charts;$meta['size_guide_urls']=array_values(array_unique($charts));$product['size_chart_uploaded']=(int)(bool)$charts;$product['size_chart_uploaded_at']=$charts?date('Y-m-d H:i:s',$now):null;}
            if($descriptions){$meta['has_description']=true;if(empty($product['description']))$product['description']=$descriptions[0];}
            if($charts){$meta['has_size_chart']=true;$meta['size_guide_urls']=array_values(array_unique($charts));if(!ProductSizeChartCatalog::forProduct($project,$product)['exists']&&preg_match('/\.(?:png|jpe?g|webp|gif)(?:[?#]|$)/i',$charts[0]))$product['size_chart']=$charts[0];}
            if(!$imageObserved)$siteImages=array_fill_keys($meta['image_urls']??[],true);
            if($imageObserved)$meta['image_checked_at']=$now;
            $meta['image_urls']=array_keys($siteImages);
            $meta['page_urls']=array_keys($pageUrls);
            if($imageObserved)$meta['main_image_url']=key($mainImages)?:key($siteImages)?:null;
            if(isset($meta['manual_upload_receipt']['assets'])){
                $observedAssets=array_merge($imageObserved?['images']:[],$descriptionObserved?['description']:[],$chartObserved?['chart']:[]);
                $meta['manual_upload_receipt']['assets']=array_values(array_diff($meta['manual_upload_receipt']['assets'],$observedAssets));
            }
            $oldImages=$product['images_list']??[];if(is_string($oldImages))$oldImages=json_decode($oldImages,true);
            if(!is_array($oldImages))throw new RuntimeException('ساختار تصاویر قبلی محصول نامعتبر است؛ برای حفظ تصاویر، ثبت متوقف شد.');
            $product['images_list']=$oldImages;
            $existing=[];foreach($product['images_list'] as $image){$u=is_array($image)?($image['url']??''):$image;if(is_string($u))$existing[$u]=true;}
            foreach(array_keys($siteImages) as $image)if(!isset($existing[$image])){$product['images_list'][]=['url'=>$image,'source'=>'site','approved'=>true,'uploaded_to_site'=>true,'created_at'=>$now];$counts['images']++;}
            foreach($product['images_list'] as &$imageItem) {
                if(is_array($imageItem)&&isset($siteImages[$imageItem['url']??''])){$imageItem['approved']=true;$imageItem['site_verified_at']=$now;}
            }unset($imageItem);
            // Only an explicit manual image outside website evidence can still require approval.
            $approvalProduct=$product;$approvalProduct['website_sync']=$meta;
            if($siteImages&&!self::pendingApprovalPhotos($approvalProduct,true)&&in_array($product['status']??'',['pending','waiting_approval','rejected'],true))$product['status']='active';
            if($imageObserved)$product['uploaded_to_site']=$siteImages&&!empty($product['has_new_manual_image'])?0:(int)(bool)$siteImages;
            if(!$siteImages)$meta['publication']=self::photos($product)?'pending_upload':'pending_image';
            // Populate an empty primary image from website evidence, including WebP; never replace an existing original.
            if(empty($product['image'])&&$siteImages)$product['image']=$meta['main_image_url']??key($siteImages);
            // Keep the original gallery, social image and reference gallery.
            if(empty($product['link']))$product['link']=$group[0]['url'];
            $product['website_sync']=$meta;
            if($descriptionObserved)$meta['local_description_fingerprint']=$unconfirmed['local_description_fingerprint']??hash('sha256',trim((string)($product['description']??'')));
            $meta['local_chart_fingerprint']=$unconfirmed['local_chart_fingerprint']??self::chartFingerprint(ProductSizeChartCatalog::forProduct($project,$product));
            $meta['local_image_fingerprint']=$unconfirmed['local_image_fingerprint']??self::photoFingerprint($product);
            $product['website_sync']=$meta;
            $changes[]=['id'=>$id,'name'=>$product['name']??$id,'action'=>'تکمیل اطلاعات سایت'];unset($product);
        }
        if($options['complete'])foreach($project['products'] as $index=>&$product) {
            if(isset($covered[$index]))continue;
            $linkedOrigin=self::origin($product['link']??$product['url']??'');if($linkedOrigin!==''&&$linkedOrigin!==$origin)continue;
            $id=(string)$product['id'];$meta=self::metadata($product);$product['website_sync']=$meta;
            $hasImage=(bool)self::photos($product);
            if(self::inventory($product)==='out_of_stock'){
                $meta=array_replace($meta,['presence'=>'missing','origin'=>$origin,'checked_at'=>$now,'has_description'=>false,'has_size_chart'=>false]);
                unset($meta['resolved_at'],$meta['resolved_by'],$meta['manual_upload_receipt']);
                $product['website_sync']=$meta;
                // Keep archived assets, but do not resurrect obsolete receipts on restock.
                $product['uploaded_to_site']=0;$product['size_chart_uploaded']=0;$product['size_chart_uploaded_at']=null;
                continue;
            }
            $publication=$hasImage&&(!empty($product['uploaded_to_site'])||($meta['publication']??'')==='technical_pending')?'technical_pending':($hasImage?'pending_upload':'pending_image');
            $meta=array_replace($meta,['presence'=>'missing','origin'=>$origin,'checked_at'=>$now,'publication'=>$publication,'has_description'=>false,'has_size_chart'=>false]);
            unset($meta['resolved_at'],$meta['resolved_by'],$meta['manual_upload_receipt']);
            $product['website_sync']=$meta;
            $product['uploaded_to_site']=0;$product['size_chart_uploaded']=0;$product['size_chart_uploaded_at']=null;$counts['missing']++;if($publication==='technical_pending')$counts['technical']++;
            $changes[]=['id'=>$id,'name'=>$product['name']??$id,'action'=>['technical_pending'=>'باگ فنی / منتظر آپلود','pending_upload'=>'منتظر آپلود','pending_image'=>'منتظر تولید عکس'][$publication]];
        }unset($product);
        $project['settings']['website_catalog']['origin']=$origin;
        $project['settings']['website_catalog']['last_sync']=['at'=>$now,'complete'=>(bool)$options['complete'],'rows'=>count($rows),'counts'=>$counts];
        return ['project'=>$project,'counts'=>$counts,'changes'=>$changes,'warnings'=>array_values(array_unique($warnings))];
    }
    /** Exact category labels, shared by filtering and project-level size-chart rules. */
    public static function categories($product) {
        $values=[];
        foreach(['categories','category','category_path'] as $field) {
            $raw=$product[$field]??[];
            if(is_string($raw)){$decoded=json_decode($raw,true);$raw=is_array($decoded)?$decoded:explode('/',$raw);}
            foreach((array)$raw as $value)if(is_string($value)&&trim($value)!=='')$values[]=trim($value);
        }
        $meta=self::metadata($product);
        foreach(['category_labels','site_category_labels'] as $field)foreach(self::metadataArray($meta[$field]??[],true) as $value)if(is_string($value)&&trim($value)!=='')$values[]=trim($value);
        return array_values(array_unique($values));
    }
    public static function chartExempt($project,$product) {
        $meta=self::metadata($product);
        if(!empty($meta['content_preferences']['chart_not_required']))return true;
        return (bool)array_intersect(self::categories($product),$project['settings']['website_catalog']['chart_exempt_categories']??[]);
    }
    public static function setContentPreference(&$project,$id,$value,$actor,$asset='image') {
        if(!is_string($id)||!in_array($asset,['image','chart'],true)||!in_array($value,['required','not_required'],true))throw new RuntimeException('تنظیم محتوای محصول معتبر نیست.');
        foreach($project['products']??[] as $index=>$product)if((string)$product['id']===$id) {
            $meta=self::metadata($product);
            $meta['content_preferences']=array_replace(self::metadataArray($meta['content_preferences']??[]),[$asset.'_not_required'=>$value==='not_required','at'=>time(),'by'=>$actor]);
            $project['products'][$index]['website_sync']=$meta;return $project['products'][$index];
        }
        throw new RuntimeException('محصول در این پروژه یافت نشد.');
    }
    /** Validate the whole selection before changing anything; callers save one batch. */
    public static function bulkManage(&$project,$ids,$operation,$actor) {
        $operations=['content_request','technical','inventory','clear_issue','image_not_required','image_required','chart_not_required','chart_required'];
        if(!is_string($operation)||!in_array($operation,$operations,true)||!is_array($ids)||!$ids||count($ids)>100)throw new RuntimeException('حداکثر ۱۰۰ محصول و یک اقدام معتبر انتخاب کنید.');
        foreach($ids as $id)if(!is_string($id)||$id==='')throw new RuntimeException('شناسهٔ محصول معتبر نیست.');
        $ids=array_values(array_unique($ids));$known=[];
        foreach($project['products']??[] as $product)$known[(string)$product['id']]=$product;
        foreach($ids as $id)if(!isset($known[$id]))throw new RuntimeException('یکی از محصولات در این پروژه یافت نشد؛ تغییری ثبت نشد.');
        $updated=[];$skipped=0;
        foreach($ids as $id) {
            if($operation==='content_request') {
                $product=$known[$id];$state=self::state($project,$product);
                if(!$state['needs_image']||!empty($product['content_gen_requested'])){$skipped++;continue;}
                $product['content_gen_requested']=true;$product['website_sync']=self::metadata($product);
                $product['website_sync']['image_request']=['at'=>time(),'by'=>$actor];
                foreach($project['products'] as $index=>$candidate)if((string)$candidate['id']===$id){$project['products'][$index]=$product;break;}
            } elseif(in_array($operation,['technical','inventory','clear_issue'],true)) {
                $product=self::setIssue($project,$id,$operation==='clear_issue'?'none':$operation,'',$actor);
            } else {
                $asset=strpos($operation,'chart_')===0?'chart':'image';
                $product=self::setContentPreference($project,$id,strpos($operation,'not_required')!==false?'not_required':'required',$actor,$asset);
            }
            $updated[]=$product;
        }
        return ['products'=>$updated,'updated'=>count($updated),'skipped'=>$skipped];
    }
    public static function setCategoryRule(&$project,$category,$value) {
        if(!is_string($category)||!in_array($value,['required','not_required'],true))throw new RuntimeException('قانون دسته‌بندی معتبر نیست.');
        $known=$project['settings']['website_catalog']['chart_exempt_categories']??[];
        foreach($project['products']??[] as $product)$known=array_merge($known,self::categories($product));
        if(!in_array($category,$known,true))throw new RuntimeException('دسته‌بندی در این پروژه یافت نشد.');
        $rules=$project['settings']['website_catalog']['chart_exempt_categories']??[];
        $rules=array_values(array_diff($rules,[$category]));if($value==='not_required')$rules[]=$category;
        $project['settings']['website_catalog']['chart_exempt_categories']=$rules;
    }
    public static function setIssue(&$project,$id,$type,$note,$actor) {
        if(!is_string($id)||!in_array($type,['technical','inventory','none'],true)||!is_string($note)||mb_strlen($note)>1000)throw new RuntimeException('نوع مشکل یا توضیح معتبر نیست؛ حداکثر توضیح ۱۰۰۰ نویسه است.');
        foreach($project['products']??[] as $index=>$product)if((string)$product['id']===$id) {
            $meta=self::metadata($product);
            if($type==='none') {
                $meta['last_review_resolution']=['previous'=>$meta['review_issue']??[],'at'=>time(),'by'=>$actor];unset($meta['review_issue']);
            } else $meta['review_issue']=['type'=>$type,'note'=>trim($note),'at'=>time(),'by'=>$actor];
            $project['products'][$index]['website_sync']=$meta;return $project['products'][$index];
        }
        throw new RuntimeException('محصول در این پروژه یافت نشد.');
    }
    public static function resolve(&$project,$id,$user,$receipt=null) {
        foreach($project['products'] as &$product)if((string)$product['id']===(string)$id) {
            $meta=self::metadata($product);$product['website_sync']=$meta;
            $derived=self::state($project,$product);
            if(empty(self::photos($product)))throw new RuntimeException('ابتدا عکس نهایی غیرسوشیال محصول را آماده کنید.');
            if(!empty($derived['approval_pending']))throw new RuntimeException('ابتدا تأیید تصویر در پورتال انجام شود.');
            if($derived['archived'])throw new RuntimeException('محصول ناموجود در صف انتشار نیست.');
            if(!in_array($derived['publication']??'',['technical_pending','pending_upload'],true)&&!$derived['size_chart_upload_pending']&&!$derived['description_upload_pending'])throw new RuntimeException('این محصول در صف رفع نقص انتشار نیست.');
            if($receipt!==null){
                if(!is_array($receipt)||!hash_equals($receipt['all_image_fingerprint']??'',hash('sha256',json_encode(self::photos($product))))||!hash_equals($receipt['chart_fingerprint']??'',self::chartFingerprint($derived['chart']))||!hash_equals($receipt['description_fingerprint']??'',hash('sha256',trim((string)($product['description']??'')))))throw new RuntimeException('محتوای محصول بعد از تهیهٔ ZIP تغییر کرده است؛ خروجی تازه بگیرید.');
                $meta['manual_upload_receipt']=$receipt+['at'=>time(),'by'=>$user];
                if(in_array('chart',$receipt['assets']??[],true)){$product['size_chart_uploaded']=1;$product['size_chart_uploaded_at']=date('Y-m-d H:i:s');}
            }
            $meta['publication']='resolved';$meta['resolved_at']=time();$meta['resolved_by']=$user;$meta['local_image_fingerprint']=self::photoFingerprint($product);
            $product['website_sync']=$meta;$product['uploaded_to_site']=1;$product['has_new_manual_image']=0;return;
        }
        throw new RuntimeException('محصول یافت نشد.');
    }
}
