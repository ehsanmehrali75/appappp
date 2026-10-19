<?php
require __DIR__.'/../panel/inc/WebsiteCatalogSync.php';
$n=0;function wfCheck($condition,$why){global $n;if(!$condition)throw new RuntimeException($why);$n++;}
$project=['id'=>'one','settings'=>['size_charts'=>[['id'=>'chart','columns'=>['سایز','قد'],'rows'=>[['M','70']]]]],'products'=>[]];
$p=['id'=>'p','sku'=>'123','status'=>'active','stock_total'=>10,'stock_online'=>0,'size_chart_template_id'=>'chart','website_sync'=>['presence'=>'seen','has_size_chart'=>false,'has_description'=>false,'image_urls'=>[]],'description'=>'متن پنل'];
$s=WebsiteCatalogSync::state($project,$p);
wfCheck(!$s['needs_size_chart']&&$s['size_chart_upload_pending']&&$s['size_chart_export_needed'],'saved studio data is produced, awaiting image export/upload');
wfCheck($s['chart']['rows']===[['M','70']]&&$s['inventory']==='in_stock','chart rows and total stock are available');
$other=$project;$other['settings']['size_charts']=[];
wfCheck(WebsiteCatalogSync::state($other,$p)['needs_size_chart'],'dangling or other-project chart id is not chart production');
$copy=$p;$copy['size_chart']='uploads/chart.jpg';$s=WebsiteCatalogSync::state($other,$copy);wfCheck(!$s['needs_size_chart']&&!$s['size_chart_export_needed']&&$s['size_chart_upload_pending'],'product image chart awaiting upload');
$copy=$p;$copy['image']='uploads/final.jpg';$s=WebsiteCatalogSync::state($project,$copy);wfCheck($s['publication']==='pending_upload'&&!$s['needs_image'],'primary photo ready to upload');
$copy['image']='';$copy['gallery']=['uploads/gallery.jpg'];wfCheck(WebsiteCatalogSync::state($project,$copy)['publication']==='pending_upload','gallery alone ready to upload');
$copy['gallery']=[];$copy['social_image']='uploads/social.jpg';$copy['reference_gallery']=['uploads/ref.jpg'];$s=WebsiteCatalogSync::state($project,$copy);wfCheck($s['needs_image']&&$s['publication']==='pending_image','social and references do not satisfy required website photo');
$copy['images_list']=[['url'=>'uploads/social.jpg','type'=>'social'],['url'=>'uploads/ref2.jpg','type'=>'reference']];wfCheck(WebsiteCatalogSync::photos($copy)===[],'typed social/reference list excluded');
$copy['images_list'][]=['url'=>'uploads/final.jpg','type'=>'gallery'];$copy['status']='pending';wfCheck(WebsiteCatalogSync::state($project,$copy)['publication']==='pending_approval','portal approval is required before new upload queue');
$copy['status']='active';$copy['stock_total']=0;$copy['stock_online']=0;wfCheck(WebsiteCatalogSync::state($project,$copy)['low_priority'],'zero stock lowers priority without erasing work');
unset($copy['stock_total'],$copy['stock_online']);wfCheck(WebsiteCatalogSync::state($project,$copy)['inventory']==='unknown','unknown inventory is not zero');
$copy['stock_total']='۵';wfCheck(WebsiteCatalogSync::state($project,$copy)['inventory']==='in_stock','Persian stock supported');
foreach([
    [['stock_total'=>0,'stock_online'=>5],'out_of_stock'],
    [['stock_total'=>-2,'stock_online'=>5],'out_of_stock'],
    [['stock_total'=>'۰','stock_online'=>'۷'],'out_of_stock'],
    [['stock_total'=>10,'stock_online'=>'ناموجود'],'in_stock'],
    [['stock_total'=>10,'stock'=>-1],'in_stock'],
    [['stock_total'=>'ناموجود','stock_online'=>10],'out_of_stock'],
    [['stock_total'=>'invalid','stock_online'=>3],'in_stock'],
    [['stock_total'=>'','stock_online'=>0],'out_of_stock'],
    [['stock_online'=>4],'in_stock'],
    [['stock_total'=>10,'status'=>'out_of_stock'],'out_of_stock'],
] as [$stock,$expectedInventory])wfCheck(WebsiteCatalogSync::inventory($stock)===$expectedInventory,'known central total takes precedence; legacy and explicit archive behavior retained');
$conflictingStock=$p;$conflictingStock['stock_total']=0;$conflictingStock['stock_online']=9;$stockState=WebsiteCatalogSync::state($project,$conflictingStock);
wfCheck($stockState['archived']&&!$stockState['needs_image']&&!$stockState['needs_size_chart']&&!$stockState['size_chart_upload_pending']&&!$stockState['needs_description'],'stale positive website stock cannot return zero central stock to execution queues');
$copy['website_sync']=['presence'=>'seen','has_size_chart'=>true,'size_guide_urls'=>['https://shop.example/chart.jpg'],'image_urls'=>['https://shop.example/photo.jpg']];$copy['image']='uploads/final.jpg';$copy['uploaded_to_site']=1;$s=WebsiteCatalogSync::state($project,$copy);wfCheck($s['comparison_available']&&count($s['local_image_urls'])===1&&count($s['site_image_urls'])===1,'both sources available separately without asserting visual equality');
$copy['has_new_manual_image']=1;wfCheck(WebsiteCatalogSync::state($project,$copy)['publication']==='pending_upload','new local upload is pending even when old site images exist');
$rows=[['url'=>'https://shop.example/product/123/test','product_code'=>'123','gallery'=>['https://shop.example/photo.jpg'],'size_guide'=>'/content/media/chartsize/Chart_31543.jpg','description'=>'متن سایت']];
$project['products']=[$p];$r=WebsiteCatalogSync::reconcile($project,$rows,['update_price'=>false]);$prod=$r['project']['products'][0];
wfCheck(empty($prod['size_chart'])&&$prod['size_chart_template_id']==='chart','website guide never replaces local studio chart even without render image');
wfCheck($prod['website_sync']['size_guide_urls']===['https://shop.example/content/media/chartsize/Chart_31543.jpg'],'relative website chart resolved and stored alongside studio');
$blank=$project;$blank['products'][0]['size_chart_template_id']='';$r=WebsiteCatalogSync::reconcile($blank,$rows,['update_price'=>false]);wfCheck($r['project']['products'][0]['size_chart']==='https://shop.example/content/media/chartsize/Chart_31543.jpg','site chart becomes main chart only when local chart absent');
$noChart=$rows;$noChart[0]['size_guide']=null;$r=WebsiteCatalogSync::reconcile($r['project'],$noChart,['update_price'=>false]);$s=WebsiteCatalogSync::state($r['project'],$r['project']['products'][0]);wfCheck($s['size_chart_upload_pending']&&!$s['needs_size_chart']&&$s['site_chart_urls']===[],'later missing site chart retains primary and needs reupload, stale website URL not shown');
$absent=$project;$absent['products'][]=['id'=>'a','sku'=>'absent','image'=>'uploads/ready.jpg','stock_total'=>3,'uploaded_to_site'=>0,'size_chart_template_id'=>'chart'];
$r=WebsiteCatalogSync::reconcile($absent,$rows,['complete'=>true,'update_price'=>false]);$s=WebsiteCatalogSync::state($r['project'],$r['project']['products'][1]);wfCheck($s['publication']==='pending_upload'&&$s['size_chart_upload_pending'],'missing site product with local assets routes to upload');
WebsiteCatalogSync::resolve($r['project'],'a','operator');wfCheck(WebsiteCatalogSync::state($r['project'],$r['project']['products'][1])['publication']==='resolved','operator upload confirmation recorded');
$r=WebsiteCatalogSync::reconcile($r['project'],$rows,['complete'=>true,'update_price'=>false]);wfCheck(WebsiteCatalogSync::state($r['project'],$r['project']['products'][1])['publication']==='technical_pending','next full snapshot missing confirmed upload reopens technical investigation');
$fingerprint=WebsiteCatalogSync::fingerprint($project);$project['settings']['size_charts'][0]['rows'][0][1]='80';wfCheck($fingerprint!==WebsiteCatalogSync::fingerprint($project),'studio edit invalidates pending reconciliation preview');
wfCheck(ProductSizeChartCatalog::mediaUrl('javascript:alert(1)')===''&&ProductSizeChartCatalog::mediaUrl('//evil.test/x')===''&&ProductSizeChartCatalog::mediaUrl('../private/x.png')==='','unsafe media URLs rejected');
$again=WebsiteCatalogSync::reconcile($r['project'],$rows,['complete'=>true,'update_price'=>false]);wfCheck(WebsiteCatalogSync::state($again['project'],$again['project']['products'][1])['publication']==='technical_pending','repeated full snapshot preserves technical investigation until operator fixes it');
wfCheck($prod['size_chart_uploaded']===1,'website chart observation updates existing studio upload flag');
wfCheck($r['project']['products'][1]['size_chart_uploaded']===0,'full absence clears studio upload flag');
$beforeEdit=WebsiteCatalogSync::reconcile($project,$rows,['update_price'=>false]);$edited=$beforeEdit['project'];$edited['settings']['size_charts'][0]['rows'][0][1]='100';wfCheck(WebsiteCatalogSync::state($edited,$edited['products'][0])['size_chart_upload_pending'],'editing studio content after website observation reopens upload work');
$edited=$beforeEdit['project'];$edited['products'][0]['image']='uploads/new-final.jpg';wfCheck(WebsiteCatalogSync::state($edited,$edited['products'][0])['publication']==='pending_upload','new photo after snapshot detected without volatile manual flag');
$awaiting=$beforeEdit['project'];$awaiting['products'][0]['image']='uploads/new-final.jpg';$awaiting['products'][0]['description']='توضیحات ویرایش‌شده در پنل';$awaiting['settings']['size_charts'][0]['rows'][0][1]='120';
$unselected=$rows;unset($unselected[0]['gallery'],$unselected[0]['size_guide'],$unselected[0]['description']);
foreach([$rows,$unselected] as $snapshot) {
    $refreshed=WebsiteCatalogSync::reconcile($awaiting,$snapshot,['update_price'=>false]);$state=WebsiteCatalogSync::state($refreshed['project'],$refreshed['project']['products'][0]);
    wfCheck($state['publication']==='pending_upload'&&$state['size_chart_upload_pending']&&$state['description_upload_pending'],'another snapshot cannot acknowledge local edits without upload confirmation, including unselected fields');
}
$receiptState=WebsiteCatalogSync::state($awaiting,$awaiting['products'][0]);
$receipt=['assets'=>['images','chart','description'],'all_image_fingerprint'=>hash('sha256',json_encode(WebsiteCatalogSync::photos($awaiting['products'][0]))),'chart_fingerprint'=>WebsiteCatalogSync::chartFingerprint($receiptState['chart']),'description_fingerprint'=>hash('sha256',trim($awaiting['products'][0]['description']))];
WebsiteCatalogSync::resolve($awaiting,'p','operator',$receipt);
foreach([$rows,$unselected] as $snapshot) {
    $refreshed=WebsiteCatalogSync::reconcile($awaiting,$snapshot,['update_price'=>false]);$state=WebsiteCatalogSync::state($refreshed['project'],$refreshed['project']['products'][0]);
    wfCheck($state['publication']==='uploaded'&&!$state['size_chart_upload_pending']&&!$state['description_upload_pending'],'valid explicit upload receipt accepts the edited local version across later positive or unselected observations');
}
$archivedReceipt=$awaiting;$archivedReceipt['products'][0]['stock_total']=0;$archivedReceipt['products'][0]['stock_online']=7;
$archivedReceipt['products'][]=['id'=>'other','sku'=>'OTHER','stock_total'=>3];
$archiveSnapshot=[['url'=>'https://shop.example/product/OTHER/name','product_code'=>'OTHER','gallery'=>[]]];
$archivedReceipt=WebsiteCatalogSync::reconcile($archivedReceipt,$archiveSnapshot,['complete'=>true,'update_price'=>false]);$absentArchive=$archivedReceipt['project']['products'][0];
wfCheck(WebsiteCatalogSync::state($archivedReceipt['project'],$absentArchive)['archived']&&$archivedReceipt['counts']['missing']===0,'missing archived product retains assets and stays outside execution counts');
wfCheck(!$absentArchive['uploaded_to_site']&&!$absentArchive['size_chart_uploaded']&&empty($absentArchive['website_sync']['manual_upload_receipt']),'observed absence invalidates old upload evidence even while archived');
$archivedReceipt['project']['products'][0]['stock_total']=5;$restocked=WebsiteCatalogSync::state($archivedReceipt['project'],$archivedReceipt['project']['products'][0]);
wfCheck($restocked['publication']==='pending_upload'&&$restocked['size_chart_upload_pending']&&$restocked['description_upload_pending'],'restocking cannot reuse upload confirmation invalidated by a later complete website snapshot');
$seenNoImage=$project;$seenNoImage['products'][0]['image']='uploads/local.jpg';$seenNoImage['products'][0]['website_sync']=['presence'=>'seen','image_urls'=>[]];WebsiteCatalogSync::resolve($seenNoImage,'p','operator');wfCheck(WebsiteCatalogSync::state($seenNoImage,$seenNoImage['products'][0])['publication']==='resolved','manual receipt also applies when product was seen but site gallery was empty');
$seenNoImage['products'][0]['image']='uploads/newer.jpg';wfCheck(WebsiteCatalogSync::state($seenNoImage,$seenNoImage['products'][0])['publication']==='pending_upload','new photo after receipt returns to upload queue');
$legacy=['id'=>'legacy','stock_total'=>5,'status'=>'pending','image'=>'https://shop.example/photo.jpg','size_chart'=>'https://shop.example/chart.jpg','description'=>'متن ثبت شده','uploaded_to_site'=>1,'images_list'=>[['url'=>'https://shop.example/photo.jpg','approved'=>false]]];
$legacy['website_sync']=['presence'=>'seen','has_size_chart'=>false,'has_description'=>false,'image_urls'=>['https://shop.example/photo.jpg'],'size_guide_urls'=>['https://shop.example/chart.jpg'],'manual_upload_receipt'=>['assets'=>['images','chart','description'],'chart_fingerprint'=>WebsiteCatalogSync::chartFingerprint(ProductSizeChartCatalog::forProduct($other,$legacy)),'description_fingerprint'=>hash('sha256',$legacy['description'])]];
$expected=WebsiteCatalogSync::state($other,$legacy);
$legacy['website_sync']['image_urls']=json_encode($legacy['website_sync']['image_urls']);$legacy['website_sync']['size_guide_urls']=json_encode($legacy['website_sync']['size_guide_urls']);
$legacy['website_sync']['manual_upload_receipt']['assets']=json_encode($legacy['website_sync']['manual_upload_receipt']['assets']);$legacy['website_sync']['manual_upload_receipt']=json_encode($legacy['website_sync']['manual_upload_receipt']);
$legacy['website_sync']=json_encode($legacy['website_sync']);
wfCheck(WebsiteCatalogSync::state($other,$legacy)===$expected,'serialized website metadata and nested lists preserve native metadata behavior');
wfCheck(WebsiteCatalogSync::photos($legacy,true)===[]&&WebsiteCatalogSync::imageApproved($legacy,$legacy['image']),'serialized website photo evidence is shared by local-photo and approval checks');
wfCheck(!$expected['size_chart_upload_pending']&&!$expected['description_upload_pending']&&!$expected['chart']['has_local'],'valid legacy receipt and site chart retain their upload and source evidence');
foreach([true,42,'broken-json','"https://shop.example/photo.jpg"','{"url":"https://shop.example/photo.jpg"}',[false,['url'=>'https://shop.example/photo.jpg'],'javascript:alert(1)']] as $invalid) {
    $bad=$legacy;$bad['website_sync']=['presence'=>'missing','image_urls'=>$invalid,'size_guide_urls'=>$invalid,'local_image_fingerprint'=>[],'local_chart_fingerprint'=>[],'local_description_fingerprint'=>[],'manual_upload_receipt'=>['assets'=>$invalid,'chart_fingerprint'=>[],'description_fingerprint'=>[]]];
    $state=WebsiteCatalogSync::state($other,$bad);
    wfCheck(WebsiteCatalogSync::photos($bad,true)===[$bad['image']]&&!WebsiteCatalogSync::imageApproved($bad,$bad['image']),'malformed metadata does not invent website photo approval');
    wfCheck($state['site_image_urls']===[]&&$state['site_chart_urls']===[]&&$state['size_chart_upload_pending']&&$state['description_upload_pending'],'malformed nested lists and fingerprints cannot suppress required upload work');
}
$bad['website_sync']=true;wfCheck(WebsiteCatalogSync::photos($bad,true)===[$bad['image']]&&!WebsiteCatalogSync::imageApproved($bad,$bad['image'])&&WebsiteCatalogSync::state($other,$bad)['site_image_urls']===[],'scalar website metadata is safely treated as absent');
$bad['website_sync']=['presence'=>'missing','manual_upload_receipt'=>['assets'=>'["chart","description"]','chart_fingerprint'=>[],'description_fingerprint'=>[]]];$state=WebsiteCatalogSync::state($other,$bad);
wfCheck($state['size_chart_upload_pending']&&$state['description_upload_pending'],'recognized receipt assets with malformed fingerprints do not count as upload evidence');
$bad['website_sync']=['image_urls'=>json_encode([$bad['image'],false,['url'=>$bad['image']],'javascript:alert(1)'])];$state=WebsiteCatalogSync::state($other,$bad);
wfCheck($state['site_image_urls']===[$bad['image']]&&WebsiteCatalogSync::imageApproved($bad,$bad['image']),'mixed legacy URL lists retain only valid scalar media URLs');
// An explicit review issue owns its queue until a human clears it.
$reviewProject=['id'=>'review','settings'=>['prod_require_reference'=>true],'products'=>[['id'=>'review-one','name'=>'Review','sku'=>'REV','stock_total'=>12,'image'=>'','reference_gallery'=>[],'images_list'=>[]]]];
WebsiteCatalogSync::setIssue($reviewProject,'review-one','technical','خطای فنی','reviewer');
$reviewState=WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0]);
wfCheck($reviewState['publication']==='technical_pending'&&!$reviewState['needs_image']&&!$reviewState['image_production']['can_produce_image'],'technical flag pauses image production even without a final photo');
WebsiteCatalogSync::setIssue($reviewProject,'review-one','inventory','اختلاف موجودی','reviewer');
$reviewState=WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0]);
wfCheck($reviewState['publication']==='inventory_issue'&&!$reviewState['image_production']['needs_reference']&&$reviewProject['products'][0]['stock_total']===12,'inventory flag has its own queue and never edits stock');
WebsiteCatalogSync::setIssue($reviewProject,'review-one','none','','reviewer');
$reviewState=WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0]);
wfCheck($reviewState['needs_image']&&$reviewState['image_production']['needs_reference'],'clearing an issue resumes the actual reference prerequisite');
WebsiteCatalogSync::setContentPreference($reviewProject,'review-one','not_required','reviewer');
$reviewState=WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0]);
wfCheck($reviewState['publication']==='image_not_required'&&!$reviewState['needs_image']&&!$reviewState['image_production']['needs_reference']&&!$reviewState['image_production']['can_produce_image'],'photo opt-out removes all image prerequisites');
$reviewRows=[['url'=>'https://shop.example/product/REV/name','product_code'=>'REV','main_image'=>null]];
$refreshed=WebsiteCatalogSync::reconcile($reviewProject,$reviewRows,['update_price'=>false]);
wfCheck(WebsiteCatalogSync::state($refreshed['project'],$refreshed['project']['products'][0])['image_not_required'],'website refresh retains human image preference');
WebsiteCatalogSync::setContentPreference($reviewProject,'review-one','required','reviewer');
wfCheck(WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0])['image_production']['needs_reference'],'opting back in resumes reference prerequisite');
$reviewProject['products'][0]['categories']=['group'=>'کفش','type'=>'دویدن'];
WebsiteCatalogSync::setCategoryRule($reviewProject,'کفش','not_required');
wfCheck(!WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0])['needs_size_chart'],'category exclusion removes chart creation task');
$reviewProject['products'][0]['size_chart']='https://shop.example/chart.jpg';
$chartState=WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0]);
wfCheck(!$chartState['size_chart_upload_pending']&&!$chartState['size_chart_export_needed']&&$chartState['chart']['exists'],'existing chart preserved while excluded from upload');
$otherReview=$reviewProject;unset($otherReview['settings']['website_catalog']);
wfCheck(!WebsiteCatalogSync::state($otherReview,$otherReview['products'][0])['chart_not_required'],'category rules are project scoped');
WebsiteCatalogSync::setCategoryRule($reviewProject,'کفش','required');
wfCheck(WebsiteCatalogSync::state($reviewProject,$reviewProject['products'][0])['size_chart_upload_pending'],'restoring category resumes actual chart upload');
echo "$n website workflow checks passed\n";
