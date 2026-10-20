<?php
/** Private, job-bound result parts; never apply a partial SQL snapshot. Caller holds job lock. */
class TiamResultUpload {
    const MAX_BYTES=33554432;
    private static function path($pid,$job,$index){return TiamInventory::path($pid,'result-'.$job['id'].'-'.$index);}
    public static function receive($pid,&$job,$body){
        $index=$body['index']??null;$chunks=$body['chunks']??null;$total=$body['total_rows']??null;$data=$body['data']??null;
        if(!is_int($index)||!is_int($chunks)||$chunks<1||$chunks>1024||$index<0||$index>=$chunks||!is_int($total)||$total<1||$total>50000||!is_string($data)||strlen($data)>524288)throw new RuntimeException('بخش ارسالی یا تعداد ردیف‌ها معتبر نیست.');
        $hash=hash('sha256',$data);if(!is_string($body['sha256']??null)||!hash_equals($hash,$body['sha256']))throw new RuntimeException('صحت بخش ارسالی تأیید نشد.');
        $rows=json_decode($data,true,512,JSON_THROW_ON_ERROR);if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>1000)throw new RuntimeException('ردیف‌های بخش معتبر نیستند.');
        foreach($rows as $row)if(!is_array($row))throw new RuntimeException('ساختار ردیف معتبر نیست.');
        $upload=$job['result_upload']??['chunks'=>$chunks,'total_rows'=>$total,'received'=>[],'rows'=>0,'bytes'=>0];
        if($upload['chunks']!==$chunks||$upload['total_rows']!==$total)throw new RuntimeException('مشخصات گزارش حین ارسال تغییر کرده است.');
        if(isset($upload['received'][$index])){if(!hash_equals($upload['received'][$index],$hash))throw new RuntimeException('محتوای بخش تکراری تغییر کرده است.');return;}
        if($index!==count($upload['received'])||$upload['rows']+count($rows)>$total||$upload['bytes']+strlen($data)>self::MAX_BYTES)throw new RuntimeException('ترتیب یا حجم کل گزارش معتبر نیست (حداکثر ۳۲ مگابایت).');
        $tmp=tempnam(TiamInventory::directory(),'result');chmod($tmp,0600);
        if(file_put_contents($tmp,$data)!==strlen($data)||!rename($tmp,self::path($pid,$job,$index))){@unlink($tmp);throw new RuntimeException('ذخیرهٔ بخش گزارش ناموفق بود.');}
        $upload['received'][$index]=$hash;$upload['rows']+=count($rows);$upload['bytes']+=strlen($data);$job['result_upload']=$upload;TiamInventory::write($pid,$job);
    }
    public static function rows($pid,$job){
        $upload=$job['result_upload']??[];
        if(empty($upload)||count($upload['received'])!==$upload['chunks']||$upload['rows']!==$upload['total_rows'])throw new RuntimeException('گزارش کامل دریافت نشده؛ هیچ موجودی تغییر نکرد.');
        $rows=[];for($i=0;$i<$upload['chunks'];$i++){$data=@file_get_contents(self::path($pid,$job,$i));if($data===false||!hash_equals($upload['received'][$i],hash('sha256',$data)))throw new RuntimeException('بخشی از گزارش مفقود یا تغییر کرده است.');foreach(json_decode($data,true,512,JSON_THROW_ON_ERROR) as $row)$rows[]=$row;}
        if(count($rows)!==$upload['total_rows'])throw new RuntimeException('تعداد ردیف‌های گزارش یکسان نیست.');return $rows;
    }
    public static function clear($pid,$job){foreach($job['result_upload']['received']??[] as $i=>$_)@unlink(self::path($pid,$job,$i));}
}
