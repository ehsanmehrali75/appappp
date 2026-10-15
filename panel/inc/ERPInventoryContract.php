<?php
/** Shared row/product format for manual ERP intake and the SQL bridge. */
class ERPInventoryContract {
    const VERSION='maarksho.erp.inventory.v1';
    public static function row($item) {
        $size=(string)($item['size']??''); $color=(string)($item['color']??'');
        $row=['_erp_contract'=>self::VERSION,'name'=>$item['name'],'ProDes'=>$item['name'],
            'sku'=>$item['sku'],'product_code'=>$item['sku'],'ProCode'=>$item['sku'],
            'ModelCode'=>$item['model']??$item['sku'],'ColorCode'=>$item['color_code']??'',
            'color'=>$color,'Color'=>$color,'size'=>$size,'sizes'=>$size!==''?[$size]:[],
            'stock_tedad'=>$item['stock'],'StockTedad'=>$item['stock'],'stock_online'=>$item['stock'],'stock_total'=>$item['stock'],
            'anbar_code'=>$item['warehouse']??'','AnbarCode'=>$item['warehouse']??'',
            'anbar_name'=>$item['warehouse_name']??($item['warehouse']??''),'AnbarName'=>$item['warehouse_name']??($item['warehouse']??'')];
        if (isset($item['price'])) { $row['NetItem']=$item['price']; $row['price']=$item['price'].' تومان'; }
        if (array_key_exists('category_path',$item)) { $row['category_path']=$item['category_path'];$row['category']=$item['category_path']; }
        foreach (['category'=>'Category','subcategory'=>'SubCategory','product_type'=>'ProductType','grade'=>'Grade','source_updated_at'=>'LastUpdateDateTime'] as $field=>$column) if(isset($item[$field]))$row[$column]=$item[$field];
        return $row;
    }
    public static function mapping($rows) {
        $columns=['sku'=>'ProCode','name'=>'ProDes','stock'=>'StockTedad','model'=>'ModelCode','size'=>'size','color'=>'Color','color_code'=>'ColorCode','warehouse'=>'AnbarCode','warehouse_name'=>'AnbarName','price'=>'NetItem','category_path'=>'category_path','category'=>'Category','subcategory'=>'SubCategory','product_type'=>'ProductType','grade'=>'Grade','source_updated_at'=>'LastUpdateDateTime'];
        $map=[];
        foreach($columns as $field=>$column)if(in_array($field,['sku','name','stock'],true)||array_key_exists($column,$rows[0]??[]))$map[$field]=$column;
        return $map;
    }
    public static function parentSku($variants) {
        $ordered=array_values($variants);
        usort($ordered,static function($a,$b){return strnatcasecmp((string)($a['size']??''),(string)($b['size']??''));});
        if(!$ordered)return '';
        $count=count($ordered);$preferred=$count>2?'3':($count===2?'2':null);
        if($preferred!==null)foreach($ordered as $variant)if((string)($variant['size']??'')===$preferred)return $variant['sku'];
        return $ordered[min($count-1,$count>2?2:($count===2?1:0))]['sku'];
    }
    public static function product($product) {
        $product['variants']=is_array($product['variants']??null)?$product['variants']:[];
        $warehouses=[];$details=[];
        foreach(($product['tiam_warehouses']??[]) as $record){
            $code=(string)($record['warehouse']??'');$label=(string)($record['tiam_attributes']['warehouse_name']??$code);
            if($label==='')$label=$code;
            $warehouses[$label]=($warehouses[$label]??0)+(float)$record['inventory'];
            $details[]=['warehouse'=>$label,'warehouse_code'=>$code,'size'=>$record['size']??'','color'=>$product['color']??'','stock'=>(float)$record['inventory'],'sku'=>$record['sku']];
        }
        $product['warehouses']=$warehouses;$product['warehouses_stock']=$warehouses;$product['warehouse_details']=$details;
        usort($product['variants'],static fn($a,$b)=>strnatcasecmp((string)($a['size']??''),(string)($b['size']??'')));
        $product['sizes']=array_values(array_unique(array_filter(array_column($product['variants'],'size'),static fn($size)=>(string)$size!=='')));
        if($product['variants'])$product['sku']=self::parentSku($product['variants']);
        foreach(($product['tiam_warehouses']??[]) as $record)if(!empty($record['tiam_attributes']['category_path'])) {
            $product['category_path']=$record['tiam_attributes']['category_path'];
            $segments=array_map('trim',explode('/',$product['category_path']));
            $product['categories']=['gender'=>$segments[0]??'','group'=>$segments[1]??'','type'=>$segments[2]??''];break;
        }
        $product['has_image']=!empty($product['image'])&&strpos($product['image'],'placehold')===false;
        $product['media_status']=$product['has_image']?'ok':'missing';
        return $product;
    }
}
