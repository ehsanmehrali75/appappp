<?php
require __DIR__.'/../panel/inc/WebsiteCatalogSync.php';
$n=0;function ipCheck($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
$base=['id'=>'p','products'=>[],'settings'=>[]];$product=['id'=>'a','sku'=>'A','stock_total'=>12,'status'=>'active'];
foreach([false,true] as $requestRequired)foreach([false,true] as $referenceRequired) {
    $project=$base;$project['settings']=['prod_require_reference'=>$referenceRequired,'prod_require_image_request'=>$requestRequired];
    $p=$product;$state=WebsiteCatalogSync::state($project,$p);$flow=$state['image_production'];
    ipCheck($flow['needs_image_request']===$requestRequired,'request gate is per-project');
    ipCheck($flow['needs_reference']===!$requestRequired,'without request gate, reference work is immediately visible');
    ipCheck($flow['can_produce_image']===(!$requestRequired&&!$referenceRequired),'only required prerequisites block production');
    $p['content_gen_requested']=true;$state=WebsiteCatalogSync::state($project,$p);$flow=$state['image_production'];
    ipCheck(!$flow['needs_image_request']&&$flow['needs_reference'],'request completion advances to reference');
    ipCheck($flow['can_produce_image']===!$referenceRequired,'optional reference appears beside production');
    $p['reference_gallery']=['uploads/ref.jpg'];$state=WebsiteCatalogSync::state($project,$p);$flow=$state['image_production'];
    ipCheck(!$flow['needs_reference']&&$flow['reference_ready']&&$flow['can_produce_image'],'real reference advances to production');
    ipCheck($state['publication']==='pending_image'&&$state['needs_image'],'reference never masquerades as the final image');
    $p['image']='uploads/final.jpg';$state=WebsiteCatalogSync::state($project,$p);$flow=$state['image_production'];
    ipCheck(!$flow['needs_image_request']&&!$flow['needs_reference']&&!$flow['can_produce_image'],'a final image leaves all production prerequisite queues');
    unset($p['image']);$p['stock_total']=0;$state=WebsiteCatalogSync::state($project,$p);$flow=$state['image_production'];
    ipCheck($state['archived']&&!$flow['needs_image_request']&&!$flow['needs_reference']&&!$flow['reference_ready']&&!$flow['can_produce_image'],'out of stock is archived, retaining references');
}
$p=$product;$p['has_reference']=true;$p['reference_gallery']=['javascript:alert(1)','uploads/placeholder.jpg'];
ipCheck(!ImageProduction::references($p),'stale flag and invalid/placeholder URLs cannot complete reference work');
$p['reference_gallery']=json_encode([['url'=>'uploads/ref.jpg']]);ipCheck(ImageProduction::references($p)===['/panel/uploads/ref.jpg'],'serialized reference metadata supported');
$p=$product;$p['images_list']=[['url'=>'uploads/ref.jpg','type'=>'reference']];ipCheck(count(ImageProduction::references($p))===1&&!WebsiteCatalogSync::photos($p),'typed reference counted but not a final image');
$one=$base;$one['settings']['prod_require_reference']=true;$two=$base;
ipCheck(WebsiteCatalogSync::state($one,$product)['publication']==='needs_reference'&&WebsiteCatalogSync::state($two,$product)['publication']==='pending_image','project requirements do not leak to another project');
$hash=WebsiteCatalogSync::fingerprint($base);$base['settings']['prod_require_image_request']=true;ipCheck($hash!==WebsiteCatalogSync::fingerprint($base),'changing prerequisites invalidates a stale reconciliation preview');
$base['settings']['prod_content_gen_users']='requester';ipCheck(ImageProduction::canRequest($base,['username'=>'requester','role'=>'viewer'])&&!ImageProduction::canRequest($base,['username'=>'photographer','role'=>'manager']),'reference operator cannot impersonate designated requester');
echo "$n image production checks passed\n";
