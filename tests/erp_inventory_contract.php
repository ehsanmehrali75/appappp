<?php
require __DIR__.'/../panel/inc/TiamInventory.php';
$n=0;function erpCheck($value,$message){global$n;if(!$value)throw new RuntimeException($message);$n++;}
$cfg=['mapping'=>TiamInventory::factInventoryMapping(true),'price_unit'=>'rial'];
$base=['ProCode'=>'A1','ProDes'=>'Test model','ModelCode'=>'MODEL-A','Size'=>'1','Color'=>'Blue','ColorCode'=>'C1','AnbarCode'=>'10001','AnbarName'=>'Test branch','StockTedad'=>'0.000','NetItem'=>'125000','CategoryPath'=>'Women/Clothes/Set','Category'=>'Clothes','SubCategory'=>'Set','ProductType'=>'Zip','Grade'=>'unknown','LastUpdateDateTime'=>'2026-10-15 12:00:00'];
$rows=[$base,array_replace($base,['ProCode'=>'A2','Size'=>'2','StockTedad'=>'3.125']),array_replace($base,['ProCode'=>'A3','Size'=>'3','StockTedad'=>'1.000']),array_replace($base,['ProCode'=>'A2','Size'=>'2','AnbarCode'=>'10002','AnbarName'=>'Test branch2','StockTedad'=>'1.875']),array_replace($base,['ProCode'=>'B2','Size'=>'2','ColorCode'=>'C2','StockTedad'=>'2.000'])];
$items=TiamInventory::normalize($rows,$cfg);$result=TiamInventory::merge(['id'=>'erp-test','products'=>[]],$items,$cfg);$p=$result['products'][0];
erpCheck(count($result['products'])===2,'stable color codes separate products even if display names coincide');
erpCheck($p['sku']==='A3','manual parent SKU rule chooses actual SKU of size 3');
erpCheck($p['sizes']===['1','2','3'],'manual sorted sizes');
erpCheck($p['variants'][1]['sku']==='A2' && $p['variants'][1]['inventory']===5.0,'correct per-size SKU and branch sum');
erpCheck($p['stock_total']===6.0,'zero and decimal stock preserved');
erpCheck($p['warehouse_details'][0]['stock']===0.0,'manual warehouse details retain zero');
erpCheck($p['warehouses_stock']['Test branch2']===1.875,'warehouse totals use expected application field');
erpCheck($p['category_path']==='Women/Clothes/Set' && $p['categories']['gender']==='Women','manual hierarchy shape is present only from explicit path');
erpCheck($p['media_status']==='missing' && $p['status']==='pending','new image-less product follows manual review shape');
$export=array_map([ERPInventoryContract::class,'row'],$items);
erpCheck($export[0]['StockTedad']===0.0 && $export[0]['ProCode']==='A1','manual row aliases preserve SKU and zero');
erpCheck($export[1]['NetItem']===12500.0,'exported ERP price is already toman');
$roundCfg=['mapping'=>ERPInventoryContract::mapping($export),'price_unit'=>'toman'];
$repeat=TiamInventory::merge(['id'=>'erp-test','products'=>[]],TiamInventory::normalize($export,$roundCfg),$roundCfg);
erpCheck($repeat['products']==$result['products'],'SQL-to-manual row roundtrip produces identical application products');
erpCheck(ERPInventoryContract::parentSku([['sku'=>'S1','size'=>'1'],['sku'=>'S2','size'=>'2']])==='S2','two-size manual parent selection');
$one=$base;unset($one['ModelCode']);$badCfg=$cfg;
try{TiamInventory::normalize([$one],$badCfg);throw new LogicException('missing model accepted');}catch(RuntimeException $e){erpCheck(true,'incomplete upgraded schema cannot silently merge models');}
$noPath=$items[0];$noPath['category_path']='';erpCheck(array_key_exists('category_path',ERPInventoryContract::row($noPath)),'nullable optional column remains present across exported rows');
echo "$n ERP contract checks passed\n";
