<?php
require __DIR__.'/../panel/inc/WebsiteExportPackage.php';
if(!class_exists('ZipArchive')||!function_exists('imagewebp'))throw new RuntimeException('This integration test requires GD/WebP and ZipArchive.');
$n=0;function weCheck($v,$why){global$n;if(!$v)throw new RuntimeException($why);$n++;}
$dir=sys_get_temp_dir().'/we-test-'.bin2hex(random_bytes(8));mkdir($dir);mkdir($dir.'/one');mkdir($dir.'/two');
$img=imagecreatetruecolor(1800,2400);imagefill($img,0,0,imagecolorallocate($img,60,100,160));imagefilledrectangle($img,0,0,140,140,imagecolorallocate($img,255,0,0));imagepng($img,$dir.'/one/photo.png');imagepng($img,$dir.'/two/photo.png');imagedestroy($img);
$chart=imagecreatetruecolor(400,600);imagefill($chart,0,0,imagecolorallocate($chart,255,255,255));imagepng($chart,$dir.'/chart.png');imagedestroy($chart);
$hash=hash_file('sha256',$dir.'/one/photo.png');
$p=['id'=>'one','sku'=>'A1','name'=>'پیراهن باکیفیت','color'=>'آبی','stock_total'=>3,'status'=>'active','image'=>'uploads/one/photo.png','gallery'=>['uploads/two/photo.png'],'size_chart'=>'uploads/chart.png','description'=>'توضیحات اصلی','images_list'=>[['url'=>'uploads/one/photo.png','source'=>'manual','approved'=>true],['url'=>'uploads/two/photo.png','source'=>'manual','approved'=>true]],'website_sync'=>['presence'=>'missing','has_size_chart'=>false,'has_description'=>false]];
$archive=array_replace($p,['id'=>'zero','sku'=>'ZERO','stock_total'=>0,'stock_online'=>0]);$pending=array_replace($p,['id'=>'pending','sku'=>'PENDING','status'=>'pending','images_list'=>[['url'=>'uploads/one/photo.png','source'=>'manual','approved'=>false]]]);$broken=array_replace($p,['id'=>'broken','sku'=>'BROKEN','image'=>'uploads/missing.png']);
$project=['id'=>'project','settings'=>[],'products'=>[$p,$archive,$pending,$broken]];
$export=(new WebsiteExportPackage($dir))->build($project,['one','zero','pending','broken']);
weCheck($export['exported_ids']===['one'],'only complete readable approved in-stock products acknowledged');
weCheck(count($export['warnings'])===3,'archive, pending approval and broken file reported');
$zip=new ZipArchive();weCheck($zip->open($dir.'/'.basename($export['zip_url']))===true,'valid zip');
$manifest=json_decode($zip->getFromName('manifest.json'),true);$folder=$manifest['products'][0]['folder'];
foreach(['main.jpg','main.webp','gallery_1.jpg','gallery_1.webp','size_chart.jpg','size_chart.webp','palette.jpg','palette.webp','توضیحات محصول.txt'] as $name)weCheck($zip->locateName($folder.'/'.$name)!==false,'complete derivative '.$name);
foreach(['main.jpg','main.webp','gallery_1.jpg','gallery_1.webp'] as $name){$size=getimagesizefromstring($zip->getFromName($folder.'/'.$name));weCheck($size[0]===1000&&$size[1]===1250,'photo target geometry '.$name);}
foreach(['size_chart.jpg','size_chart.webp'] as $name){$size=getimagesizefromstring($zip->getFromName($folder.'/'.$name));weCheck($size[0]===400&&$size[1]===600,'size guide retains original geometry '.$name);}
$size=getimagesizefromstring($zip->getFromName($folder.'/palette.webp'));weCheck($size[0]===24&&$size[1]===24,'palette geometry');
weCheck($zip->getFromName($folder.'/توضیحات محصول.txt')==='توضیحات اصلی','plain original description');
weCheck(hash_file('sha256',$dir.'/one/photo.png')===$hash&&getimagesize($dir.'/one/photo.png')[0]===1800,'original file and dimensions preserved');
WebsiteCatalogSync::resolve($project,'one','tester',$export['receipts']['one']);$state=WebsiteCatalogSync::state($project,$project['products'][0]);
weCheck($state['publication']==='resolved'&&!$state['size_chart_upload_pending']&&!$state['description_upload_pending'],'all exported assets leave upload queues after explicit completion');
$project['products'][0]['description']='ویرایش جدید';weCheck(WebsiteCatalogSync::state($project,$project['products'][0])['description_upload_pending'],'later description edit reopens upload');
try{WebsiteCatalogSync::resolve($project,'one','tester',$export['receipts']['one']);throw new LogicException('stale receipt accepted');}catch(RuntimeException $e){weCheck(true,'stale zip receipt rejected');}
$site=array_replace($p,['status'=>'pending','images_list'=>[['url'=>'uploads/one/photo.png','source'=>'site','approved'=>false],['url'=>'uploads/two/photo.png','source'=>'site','approved'=>false]]]);weCheck(WebsiteCatalogSync::pendingApprovalPhotos($site)===[],'site provenance needs no reapproval');
$zeroState=WebsiteCatalogSync::state($project,$archive);foreach(['needs_image','needs_description','needs_size_chart','image_upload_pending','size_chart_upload_pending','description_upload_pending','approval_pending'] as $key)weCheck(!$zeroState[$key],'out of stock excluded from '.$key);
weCheck($zeroState['publication']==='archived'&&$zeroState['local_image_urls'],'archive retains original photos');
$siteOnly=['id'=>'site','sku'=>'SITE','status'=>'pending','stock_total'=>2,'image'=>'https://shop.example/site.jpg','images_list'=>[['url'=>'https://shop.example/site.jpg','source'=>'manual','approved'=>false]]];
$siteProject=['id'=>'site-project','settings'=>[],'products'=>[$siteOnly]];
$observed=WebsiteCatalogSync::reconcile($siteProject,[['url'=>'https://shop.example/product/SITE/name','product_code'=>'SITE','gallery'=>['https://shop.example/site.jpg']]],['update_price'=>false]);
weCheck($observed['project']['products'][0]['status']==='active'&&$observed['project']['products'][0]['images_list'][0]['approved']===true,'observing same image on site approves existing record and clears product approval');
$mixed=$siteProject;$mixed['products'][0]['image']='uploads/manual.jpg';$mixed['products'][0]['images_list']=[['url'=>'uploads/manual.jpg','source'=>'manual','approved'=>false]];
$mixed=WebsiteCatalogSync::reconcile($mixed,[['url'=>'https://shop.example/product/SITE/name','product_code'=>'SITE','gallery'=>['https://shop.example/site.jpg']]],['update_price'=>false]);
weCheck($mixed['project']['products'][0]['status']==='pending'&&count(WebsiteCatalogSync::pendingApprovalPhotos($mixed['project']['products'][0]))===1,'website evidence never approves a different manual photo');
$zip->close();foreach(glob($dir.'/*') as $file){if(is_file($file))unlink($file);}unlink($dir.'/one/photo.png');unlink($dir.'/two/photo.png');rmdir($dir.'/one');rmdir($dir.'/two');rmdir($dir);
echo "$n website export integration checks passed\n";
