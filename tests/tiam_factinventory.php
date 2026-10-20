<?php
require __DIR__.'/../panel/inc/TiamInventory.php';
$count=0;
function verifyFact($value,$message){global $count;if(!$value)throw new RuntimeException($message);$count++;}
function rejectFact($fn,$message){try{$fn();}catch(RuntimeException $e){verifyFact(true,$message);return;}throw new LogicException($message);}
$cfg=TiamInventory::config([],['host'=>'192.0.2.1','database'=>'ExampleDB','view'=>'FactInventory','mapping'=>TiamInventory::factInventoryMapping(false)]);
$rows=[['ShopCode'=>1,'ShopName'=>'شعبهٔ نمونه','ProCode'=>'0012','ProDes'=>'کالای نمونه','Category'=>'پوشاک','SubCategory'=>'لباس','ProductType'=>'نوع نمونه','Grade'=>'نامشخص','StockTedad'=>'1.125','LastUpdateDateTime'=>'2026-10-15 12:00:00']];
$rows[]=array_replace($rows[0],['ShopCode'=>2,'ShopName'=>'شعبهٔ دوم','StockTedad'=>'2.375']);
$rows[]=array_replace($rows[0],['ProCode'=>'0013','StockTedad'=>'0.000']);
$items=TiamInventory::normalize($rows,$cfg);
$p=['id'=>'fact-test','products'=>[]];$result=TiamInventory::merge($p,$items,$cfg);
verifyFact(count($result['products'])===2,'different product codes with identical descriptions stay separate');
$first=$result['products'][0];verifyFact($first['sku']==='0012','leading zero preserved');
verifyFact(abs($first['stock_total']-3.5)<1e-9,'decimal stock sums across branches');
verifyFact($result['products'][1]['stock_total']===0.0,'zero stock is valid');
verifyFact($first['sizes']===[] && $cfg['mapping']['color']==='','Grade never inferred as size or color');
verifyFact($first['tiam_warehouses'][1]['tiam_attributes']['warehouse_name']==='شعبهٔ دوم','branch labels preserved');
verifyFact($first['tiam_warehouses'][0]['tiam_attributes']['category']==='پوشاک','source category preserved');
verifyFact($first['tiam_warehouses'][0]['tiam_attributes']['grade']==='نامشخص','unknown Grade preserved');
// Simulate product columns being loaded from SQL: custom product fields are absent, variants JSON persists.
$roundtrip=$first;unset($roundtrip['tiam_key'],$roundtrip['tiam_warehouses']);$roundtrip['variants']=json_decode(json_encode($first['variants']),true);
$roundtrip=TiamInventory::restoreProduct($roundtrip);
verifyFact($roundtrip['tiam_key']===$first['tiam_key'] && $roundtrip['tiam_warehouses']===$first['tiam_warehouses'],'source identity and metadata survive SQL-style reload');
$roundtrip['stock_total']='3.5';$roundtrip['stock_online']='3.5';unset($roundtrip['inventory'],$roundtrip['quantity']);
$stable=TiamInventory::merge(['id'=>'fact-test','products'=>[$roundtrip,$result['products'][1]]],$items,$cfg);
verifyFact($stable['counts']['unchanged']===2,'SQL stock strings and transient aliases do not create false updates');
$roundtrip['image']='keep.jpg';$roundtrip['description']='edited';$roundtrip['price']='500';
$renamed=$rows;foreach($renamed as &$row)$row['ProDes']='نام اصلاح‌شده';unset($row);
$next=TiamInventory::merge(['id'=>'fact-test','products'=>[$roundtrip,$result['products'][1]]],TiamInventory::normalize($renamed,$cfg),$cfg);
verifyFact($next['products'][0]['id']===$first['id'],'name change retains product identity');
verifyFact($next['products'][0]['image']==='keep.jpg' && $next['products'][0]['price']==='500','missing source price/images do not overwrite edits');
$bad=$rows;$bad[0]['StockTedad']=null;rejectFact(fn()=>TiamInventory::normalize($bad,$cfg),'NULL stock cannot become zero');
$bad=$rows;$bad[0]['ProCode']=null;rejectFact(fn()=>TiamInventory::normalize($bad,$cfg),'NULL code rejected');
$duplicate=[$rows[0],array_replace($rows[0],['Grade'=>'other'])];rejectFact(fn()=>TiamInventory::normalize($duplicate,$cfg),'unknown Grade multiplicity cannot silently double stock');
$legacy=['id'=>'legacy','name'=>'کالای نمونه','variants'=>[]];rejectFact(fn()=>TiamInventory::merge(['id'=>'fact-test','products'=>[$legacy]],$items,$cfg),'ambiguous legacy name matching is blocked');
// Confirmed 12-column view: ProCode changes per size; ProDes identifies the model.
$sizedCfg=TiamInventory::config([],['host'=>'192.0.2.1','database'=>'ExampleDB','view'=>'FactInventory','mapping'=>TiamInventory::factInventoryMapping('sizes')]);
verifyFact(TiamInventory::factInventoryMapping()===TiamInventory::factInventoryMapping('sizes'),'description and color are the standard mapping without physical model/color codes');
verifyFact($sizedCfg['mapping']['model']==='ProDes'&&$sizedCfg['mapping']['size']==='Size'&&$sizedCfg['mapping']['color']==='Color','confirmed schema selects model name, true size and true color');
verifyFact($sizedCfg['mapping']['price']===''&&empty($sizedCfg['mapping']['color_code']),'missing price and color code are not fabricated');
$template=['ShopCode'=>10001,'ShopName'=>'لاله پارک','ProCode'=>'85592','ProDes'=>'Grace Fit W','Category'=>'پوشاک','SubCategory'=>'پایین پوش','ProductType'=>'ساده','Grade'=>'1','StockTedad'=>'1.000','LastUpdateDateTime'=>'2026-10-20 10:30:00.357000','Size'=>'XL','Color'=>'زرد'];
$observed=[$template,array_replace($template,['ProCode'=>'84888','ProDes'=>'Cloud Peak U','Category'=>'کفش','SubCategory'=>'کتونی','Size'=>'41','Color'=>'سياه- سياه']),array_replace($template,['ProCode'=>'84891','ProDes'=>'Cloud Peak U','Category'=>'کفش','SubCategory'=>'کتونی','Size'=>'44','Color'=>'سياه- سياه','StockTedad'=>'2.000']),array_replace($template,['ProCode'=>'84934','ProDes'=>'Cloud Runner 3 U','Category'=>'کفش','SubCategory'=>'کتونی','Size'=>'38','Color'=>'سياه-سفيد','StockTedad'=>'2.000']),array_replace($template,['ProCode'=>'84937','ProDes'=>'Cloud Runner 3 U','Category'=>'کفش','SubCategory'=>'کتونی','Size'=>'41','Color'=>'سياه-سفيد','StockTedad'=>'2.000'])];
$sized=TiamInventory::merge(['id'=>'sized','products'=>[]],TiamInventory::normalize($observed,$sizedCfg),$sizedCfg);
verifyFact(count($sized['products'])===3,'five supplied rows form three model/color products, not five SKU products');
$peak=$sized['products'][1];$runner=$sized['products'][2];verifyFact($peak['stock_total']===3.0&&$runner['stock_total']===4.0,'sample stock totals are 3 for Peak and 4 for Runner');
verifyFact(array_column($peak['variants'],'sku','size')===[41=>'84888',44=>'84891'],'real SKU remains attached to each size');
verifyFact($sized['products'][0]['sizes']===['XL'],'Size used rather than Grade');
$observed[]=array_replace($observed[1],['ShopCode'=>10002,'ShopName'=>'شعبهٔ تست','Color'=>'سیاه-سیاه','StockTedad'=>'2.000']);
$observed[]=array_replace($observed[1],['ProCode'=>'99999','Color'=>'سفید','StockTedad'=>'0.000']);
$peak['image']='uploads/peak-original.webp';$peak['description']='Existing description';$peak['size_chart']='uploads/peak-chart.png';$peak['price']='50000';$peak['name']='Cloud Peak U - سیاه- سیاه';
$synced=TiamInventory::merge(['id'=>'sized','products'=>[$peak]],TiamInventory::normalize($observed,$sizedCfg),$sizedCfg);$retained=null;
foreach($synced['products'] as $product)if($product['id']===$peak['id'])$retained=$product;
verifyFact($retained!==null&&$retained['stock_total']===5.0&&count($retained['variants'])===2,'branch totals and normalized compound color keep one product');
foreach(['image','description','size_chart','price'] as $field)verifyFact($retained[$field]===$peak[$field],'preserve local '.$field.' absent from SQL view');
verifyFact(count($synced['products'])===4,'second color remains a separate product including zero stock');
verifyFact(count($retained['warehouse_details'])===3,'branch/size detail rows retained');
echo "$count FactInventory schema checks passed\n";
