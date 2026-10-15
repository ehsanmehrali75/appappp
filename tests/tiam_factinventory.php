<?php
require __DIR__.'/../panel/inc/TiamInventory.php';
$count=0;
function verifyFact($value,$message){global $count;if(!$value)throw new RuntimeException($message);$count++;}
function rejectFact($fn,$message){try{$fn();}catch(RuntimeException $e){verifyFact(true,$message);return;}throw new LogicException($message);}
$cfg=TiamInventory::config([],['host'=>'192.0.2.1','database'=>'ExampleDB','view'=>'FactInventory','mapping'=>TiamInventory::factInventoryMapping()]);
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
echo "$count FactInventory schema checks passed\n";
