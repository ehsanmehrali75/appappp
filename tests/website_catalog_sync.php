<?php
require __DIR__.'/../panel/inc/WebsiteCatalogSync.php';
function hasProductFinalImages($p){return !empty($p['image'])||!empty($p['social_image'])||!empty($p['gallery'])||!empty($p['images_list']);}
function hasProductSizeChart($p){return !empty($p['size_chart'])||!empty($p['size_chart_template_id']);}
$n=0;function wcCheck($v,$why){global$n;if(!$v)throw new RuntimeException($why);$n++;}
$base=['id'=>'web-test','settings'=>[],'products'=>[
 ['id'=>'one','sku'=>'59164','name'=>'Local name','image'=>'uploads/local.png','social_image'=>'uploads/social.png','gallery'=>['uploads/gallery.png'],'reference_gallery'=>['uploads/ref.png'],'images_list'=>[['url'=>'uploads/local.png','source'=>'manual']],'description'=>'Local description','size_chart'=>'uploads/chart.png','stock_total'=>9,'stock_online'=>3,'color'=>'ERP Blue','sizes'=>['1','2'],'variants'=>[['sku'=>'59163','size'=>'1'],['sku'=>'59164','size'=>'2']],'uploaded_to_site'=>1,'status'=>'pending'],
 ['id'=>'two','sku'=>'MISSING','name'=>'Missing ready','image'=>'uploads/two.png','uploaded_to_site'=>1],
 ['id'=>'three','sku'=>'NEWERP','name'=>'ERP blank','image'=>'','uploaded_to_site'=>0],
 ['id'=>'four','sku'=>'READY','name'=>'Ready','image'=>'uploads/ready.png','uploaded_to_site'=>0],
]];
$row=['url'=>'https://shop.example/product/59164/name','product_code'=>'59164','name'=>'Website name','price'=>'59,796,000 ریال','sizes'=>['x'],'color'=>'Website color','gallery'=>['https://shop.example/p.jpg'],'main_image'=>'https://shop.example/p.jpg','description'=>'','size_guide'=>null];
$unknown=array_replace($row,['product_code'=>'UNKNOWN','url'=>'https://shop.example/product/999/no','price'=>'bad price ignored']);
$r=WebsiteCatalogSync::reconcile($base,[$row,$unknown]);$p=$r['project']['products'][0];
wcCheck(count($r['project']['products'])===4&&$r['counts']['new']===0,'never create unknown products');
wcCheck($r['counts']['ignored']===1&&$r['counts']['matched']===1,'match exact code, ignore unknown');
foreach(['image','social_image','gallery','reference_gallery','description','size_chart','stock_total','stock_online','color','sizes','variants','status','name'] as $key)wcCheck($p[$key]===$base['products'][0][$key],"preserve $key");
wcCheck($p['price']==='5979600 تومان','rial to toman exactly once');
wcCheck(count($p['images_list'])===2&&$p['images_list'][1]['source']==='site'&&$p['images_list'][1]['approved'],'append final site image');
wcCheck($r['project']['products'][1]['uploaded_to_site']===1,'partial snapshot does not mark absence');
$r2=WebsiteCatalogSync::reconcile($r['project'],[$row]);wcCheck(count($r2['project']['products'][0]['images_list'])===2,'image deduplication across imports');
$full=WebsiteCatalogSync::reconcile($base,[$row],['complete'=>true]);
wcCheck($full['counts']['technical']===1&&$full['counts']['missing']===3,'full snapshot reconciles existing products only');
wcCheck($full['project']['products'][1]['website_sync']['publication']==='technical_pending','previously uploaded photo missing => technical queue');
wcCheck($full['project']['products'][2]['website_sync']['publication']==='pending_image','ERP blank => photo queue');
wcCheck($full['project']['products'][3]['website_sync']['publication']==='pending_upload','ready photo => upload queue');
wcCheck($full['project']['products'][0]['stock_total']===9,'absence never zeroes inventory');
WebsiteCatalogSync::resolve($full['project'],'two','tester');wcCheck($full['project']['products'][1]['uploaded_to_site']===1&&$full['project']['products'][1]['website_sync']['resolved_by']==='tester','manual completion recorded');
$fullAgain=WebsiteCatalogSync::reconcile($full['project'],[$row],['complete'=>true]);wcCheck($fullAgain['project']['products'][1]['website_sync']['publication']==='technical_pending','later full absence reopens issue');
$stock=array_replace($row,['stock_online'=>0,'stock_total'=>0,'price'=>'0 تومان']);$zero=WebsiteCatalogSync::reconcile($base,[$stock]);wcCheck($zero['project']['products'][0]['stock_total']===0.0&&$zero['project']['products'][0]['price']==='0 تومان','explicit numeric zeros update');
$guide=array_replace($row,['size_guide'=>'/chart.jpg','description'=>'New description']);$empty=$base;$empty['products'][0]['description']='';$empty['products'][0]['size_chart']='';$g=WebsiteCatalogSync::reconcile($empty,[$guide]);wcCheck($g['project']['products'][0]['size_chart']==='https://shop.example/chart.jpg'&&$g['project']['products'][0]['description']==='New description','fill missing description/chart');
$state=WebsiteCatalogSync::state($r['project'],$p);wcCheck($state['description_upload_pending']&&$state['size_chart_upload_pending']&&!$state['needs_description'],'existing local content missing on site => upload, not generation');
$state=WebsiteCatalogSync::state($base,$base['products'][2]);wcCheck($state['needs_description']&&$state['needs_size_chart']&&$state['needs_image'],'empty ERP content enters appropriate queues');
$ambiguous=$base;$ambiguous['products'][]=array_replace($base['products'][0],['id'=>'duplicate']);$amb=WebsiteCatalogSync::reconcile($ambiguous,[$row,array_replace($row,['product_code'=>'MISSING','url'=>'https://shop.example/product/MISSING/a'])],['complete'=>true]);wcCheck($amb['counts']['conflicts']===1&&empty($amb['project']['products'][0]['website_sync']),'ambiguous candidates not updated or marked absent');
$variant=array_replace($row,['product_code'=>'59163','url'=>'https://shop.example/product/59163/a','stock_total'=>2]);$vr=WebsiteCatalogSync::reconcile($base,[$variant]);wcCheck($vr['project']['products'][0]['stock_total']===9&&count($vr['warnings'])===1,'variant row cannot overwrite aggregated parent stock');
$cases=[[$row,$row],[array_replace($row,['stock_total'=>'invalid'])],[array_replace($row,['price'=>'125000'])]];
foreach($cases as $rows){try{WebsiteCatalogSync::reconcile($base,$rows);throw new LogicException('invalid input accepted');}catch(RuntimeException $e){wcCheck(true,'invalid duplicate/value/unit rejected');}}
wcCheck(!WebsiteCatalogSync::isWebsiteRows([['url'=>'','product_code'=>'A','ProCode'=>'A']]),'legacy ERP not mistaken for website input');
$partialFields=$row;unset($partialFields['description'],$partialFields['size_guide']);$pf=WebsiteCatalogSync::reconcile($r['project'],[$partialFields]);wcCheck(array_key_exists('has_description',$pf['project']['products'][0]['website_sync'])&&$pf['project']['products'][0]['website_sync']['has_description']===false,'omitted fields do not invent new website completeness');
$photo=$full['project']['products'][2];$photo['image']='uploads/now-created.jpg';wcCheck(WebsiteCatalogSync::state($full['project'],$photo)['publication']==='pending_upload','newly created photo leaves image-production queue');
$negative=array_replace($row,['stock_total'=>-2]);$neg=WebsiteCatalogSync::reconcile($base,[$negative]);wcCheck($neg['project']['products'][0]['stock_total']===-2.0,'explicit signed stock supported like ERP');
$badWebsite=[['url'=>'broken','product_code'=>'ABC']];wcCheck(WebsiteCatalogSync::looksLikeWebsite($badWebsite)&&!WebsiteCatalogSync::isWebsiteRows($badWebsite),'malformed website rows cannot fall through to insert import');
$ignoredOnly=WebsiteCatalogSync::reconcile($base,[$unknown]);wcCheck($ignoredOnly['project']===$base,'only unknown website rows leave project entirely unchanged');
try{WebsiteCatalogSync::reconcile($base,[$unknown],['complete'=>true]);throw new LogicException('zero-match complete snapshot accepted');}catch(RuntimeException $e){wcCheck(true,'zero-match snapshot cannot mark whole project absent');}
$known=WebsiteCatalogSync::reconcile($g['project'],[$partialFields]);wcCheck($known['project']['products'][0]['website_sync']['has_description']&&$known['project']['products'][0]['website_sync']['has_size_chart'],'omitted optional fields preserve previously observed website content');
$stringImages=$base;$stringImages['products'][0]['images_list']=json_encode($stringImages['products'][0]['images_list']);$si=WebsiteCatalogSync::reconcile($stringImages,[$row]);wcCheck(count($si['project']['products'][0]['images_list'])===2&&$si['project']['products'][0]['images_list'][0]['source']==='manual','legacy JSON-string image list preserved');
require __DIR__.'/../panel/inc/TiamInventory.php';
$cfg=['mapping'=>['sku'=>'Code','name'=>'Name','stock'=>'Qty','color'=>'Color']];
$tiam=TiamInventory::merge($r['project'],TiamInventory::normalize([['Code'=>'59164','Name'=>'Local name','Qty'=>12,'Color'=>'ERP Blue']],$cfg),$cfg);
$tiamProduct=array_values(array_filter($tiam['products'],fn($p)=>$p['id']==='one'))[0];
wcCheck($tiamProduct['website_sync']===$r['project']['products'][0]['website_sync'],'later Tiam sync preserves website metadata');
wcCheck($tiamProduct['image']==='uploads/local.png'&&count($tiamProduct['images_list'])===2,'later Tiam sync preserves local and website images');
// Presence/photo evidence is independent from missing descriptions and size charts.
$mediaBase=['id'=>'sportland','settings'=>[],'products'=>[['id'=>'sp','sku'=>'88273','name'=>'ON Stride U','stock_total'=>12,'image'=>'uploads/high-resolution-original.png','description'=>'']]];
$mediaRow=['url'=>'https://www.sportland.ir/product/88273/on-stride-u','product_code'=>'88273','main_image'=>'https://www.sportland.ir/content/media/product/88273/pro_68419.webp','gallery'=>['https://www.sportland.ir/content/media/product/88273/pro_68419.webp','https://www.sportland.ir/content/media/product/88273/pro_68420.webp','https://www.sportland.ir/content/media/product/88273/pro_68421.webp','https://www.sportland.ir/content/media/product/88273/pro_68422.webp'],'description'=>'','size_guide'=>null];
$mediaResult=WebsiteCatalogSync::reconcile($mediaBase,[$mediaRow]);$mediaProduct=$mediaResult['project']['products'][0];$mediaState=WebsiteCatalogSync::state($mediaResult['project'],$mediaProduct);
wcCheck($mediaState['site_visible_with_images'],'site presence and photo evidence survive missing chart and description');
wcCheck($mediaState['site_main_image_url']===$mediaRow['main_image']&&count($mediaState['site_image_urls'])===4,'original main and complete gallery available for comparison');
wcCheck($mediaState['site_page_urls']===[$mediaRow['url']],'website product link separate from panel link');
wcCheck($mediaState['needs_size_chart']&&$mediaState['needs_description'],'missing content tasks coexist with site photo presence');
wcCheck($mediaProduct['image']===$mediaBase['products'][0]['image']&&$mediaProduct['stock_total']===12,'original image and ERP stock preserved');
$mediaRow['size_guide']='https://www.sportland.ir/content/media/chartsize/Chart_88273.jpg';
$mediaWithChart=WebsiteCatalogSync::reconcile($mediaResult['project'],[$mediaRow]);$mediaChartState=WebsiteCatalogSync::state($mediaWithChart['project'],$mediaWithChart['project']['products'][0]);
wcCheck($mediaChartState['site_chart_urls']===[$mediaRow['size_guide']]&&!$mediaChartState['needs_size_chart'],'site chart shown independently and fills a missing primary chart');
$mediaRow['main_image']=null;$mediaRow['gallery']=[];
$mediaEmpty=WebsiteCatalogSync::reconcile($mediaWithChart['project'],[$mediaRow]);$mediaEmptyState=WebsiteCatalogSync::state($mediaEmpty['project'],$mediaEmpty['project']['products'][0]);
wcCheck(!$mediaEmptyState['site_visible_with_images']&&$mediaEmptyState['site_main_image_url']===null,'seen page without current images must not be called visually present');
// Model phrases remain contiguous; prefixes/suffixes do not change product identity.
$modelBase=['id'=>'models','settings'=>[],'products'=>[['id'=>'aero','sku'=>'ERP-123','name'=>'Aero   Pro W','color'=>'سفید-سبز','stock_total'=>8,'image'=>'','images_list'=>[]]]];
$modelRow=['url'=>'https://shop.example/product/987/model','product_code'=>'987','name'=>'نیم تنه ورزشی زنانه اسپورتلند Aero Pro W جدید','color'=>'سفید-سبز','main_image'=>'https://shop.example/main.webp','gallery'=>['https://shop.example/main.webp','https://shop.example/gallery.webp'],'size_guide'=>'https://shop.example/chart.webp'];
$modelResult=WebsiteCatalogSync::reconcile($modelBase,[$modelRow]);$modelProduct=$modelResult['project']['products'][0];
wcCheck($modelResult['counts']['matched_name']===1&&$modelResult['counts']['new']===0,'whole model phrase matches despite website prefix/suffix and different codes');
wcCheck($modelProduct['name']==='Aero   Pro W'&&$modelProduct['sku']==='ERP-123'&&$modelProduct['stock_total']===8,'model matching preserves ERP identity and stock');
wcCheck($modelProduct['image']==='https://shop.example/main.webp'&&count($modelProduct['images_list'])===2&&$modelProduct['images_list'][0]['approved'],'WebP primary and gallery imported as approved site evidence');
wcCheck($modelProduct['size_chart']==='https://shop.example/chart.webp','WebP size guide can populate an empty chart');
foreach(['Aero Extra Pro W','Aero Pro Extra W','Aero Pro Wide','SuperAero Pro W','Aero Pro W2','Aero-Pro-W'] as $wrong) {
    $bad=WebsiteCatalogSync::reconcile($modelBase,[array_replace($modelRow,['name'=>$wrong])]);
    wcCheck($bad['counts']['matched']===0,'never fuzzy-match noncontiguous/partial model '.$wrong);
}
$case=WebsiteCatalogSync::reconcile($modelBase,[array_replace($modelRow,['name'=>'اسپورتلند AERO PRO W'])]);
wcCheck($case['counts']['matched_name']===1,'model case and whitespace are normalized');
$duplicate=$modelBase;$duplicate['products'][]=array_replace($modelBase['products'][0],['id'=>'duplicate','sku'=>'ERP-456']);
$conflict=WebsiteCatalogSync::reconcile($duplicate,[$modelRow]);
wcCheck($conflict['counts']['conflicts']===1&&$conflict['counts']['matched']===0&&empty($conflict['project']['products'][0]['website_sync']),'ambiguous model is reported without changing products');
$colors=$modelBase;$colors['products'][]=array_replace($modelBase['products'][0],['id'=>'white','sku'=>'ERP-456','color'=>'سفید']);
$colorResult=WebsiteCatalogSync::reconcile($colors,[$modelRow]);
wcCheck($colorResult['counts']['matched_name']===1&&empty($colorResult['project']['products'][1]['website_sync']),'compound color is one exact color and disambiguates same-model products');
$exact=WebsiteCatalogSync::reconcile($modelBase,[array_replace($modelRow,['product_code'=>'ERP-123','name'=>'Unrelated website title'])]);
wcCheck($exact['counts']['matched']===1&&$exact['counts']['matched_name']===0,'exact code has priority over model-name fallback');
$preserve=$modelBase;$preserve['products'][0]['image']='uploads/own-original.png';
$kept=WebsiteCatalogSync::reconcile($preserve,[$modelRow]);
wcCheck($kept['project']['products'][0]['image']==='uploads/own-original.png','WebP website image never replaces existing original');
// The unavailable lead size has a different SKU from the grouped ERP product.
$grandBase=['id'=>'grand','settings'=>[],'products'=>[['id'=>'grand-white','sku'=>'37976','name'=>'Grand Court 2.0 M - سفید','color'=>'سفيد','stock_total'=>37,'sizes'=>['41','42','43','44','45','46','47'],'variants'=>[['sku'=>'37976','size'=>'41']],'image'=>'','images_list'=>[]]]];
$grandRow=['url'=>'https://www.sportland.ir/product/37975/example','product_code'=>'37975','name'=>'کتونی روزانه مردانه آدیداس Adidas Grand Court 2.0 M','color'=>'سفید','main_image'=>'https://www.sportland.ir/content/media/product/37975/pro_57948.webp','gallery'=>[],'size_availability'=>[['size'=>'40','available'=>false],['size'=>'41','available'=>true]]];
$grand=WebsiteCatalogSync::reconcile($grandBase,[$grandRow],['complete'=>true]);$gp=$grand['project']['products'][0];$gs=WebsiteCatalogSync::state($grand['project'],$gp);
wcCheck($grand['counts']['matched_name']===1&&$grand['counts']['missing']===0&&$gs['site_visible_with_images'],'lead size unavailable still matches exact model and color with approved images');
wcCheck($gp['sku']==='37976'&&$gp['stock_total']===37&&$gp['sizes']===$grandBase['products'][0]['sizes']&&$gp['variants']===$grandBase['products'][0]['variants'],'site availability cannot replace ERP quantities, identity or sizes');
wcCheck($gs['site_size_availability'][$grandRow['url']][0]['available']===false,'site size availability retained separately');
foreach(['سیاه','سفید-سبز',null] as $wrongColor){$bad=WebsiteCatalogSync::reconcile($grandBase,[array_replace($grandRow,['color'=>$wrongColor])]);wcCheck($bad['counts']['matched']===0,'suffix removal requires exact observed color');}
$otherModel=WebsiteCatalogSync::reconcile($grandBase,[array_replace($grandRow,['name'=>'Adidas Grand Court Extra 2.0 M'])]);wcCheck($otherModel['counts']['matched']===0,'color suffix never enables fuzzy model matching');
$allOut=$grandRow;$allOut['size_availability']=[['size'=>'40','available'=>false],['size'=>'41','available'=>false]];$sold=WebsiteCatalogSync::reconcile($grandBase,[$allOut]);wcCheck(WebsiteCatalogSync::state($sold['project'],$sold['project']['products'][0])['site_visible_with_images'],'sold-out sizes never erase website photos');
echo "$n website catalog checks passed\n";
