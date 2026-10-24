<?php
require_once __DIR__.'/ProjectPurchases.php';
class TransferPurchases extends ProjectPurchases {
    public static function destination($site){
        $c=$site['bank_transfer']??[];
        if(empty($c['enabled'])||!preg_match('/^[0-9]{16}$/D',$c['card']??'')||trim($c['holder']??'')==='')throw new RuntimeException('پرداخت کارت‌به‌کارت هنوز تنظیم نشده است.');
        return array_intersect_key($c,array_flip(['card','iban','holder','bank','instructions']));
    }
    public function startTransfer($input,$site){
        $destination=self::destination($site);$plan=ProjectPlans::find($input['plan_id']??'');$cycle=$input['billing_cycle']??'';
        if(!in_array($cycle,['monthly','yearly'],true))throw new RuntimeException('دورهٔ اشتراک معتبر نیست.');
        $amount=(int)($plan['price_'.$cycle]??0);if($amount<=0)throw new RuntimeException('قیمت پلن تنظیم نشده است.');
        $name=trim($input['customer_name']??'');$business=trim($input['customer_business']??'');if($name===''||$business===''||mb_strlen($name)>150||mb_strlen($business)>150)throw new RuntimeException('نام خریدار و پروژه لازم است.');
        $account=self::account($input);$nonce=$input['checkout_nonce']??'';if(!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new RuntimeException('شناسهٔ خرید معتبر نیست.');
        $key=hash('sha256',json_encode([$account['username'],$nonce,$plan['id'],$cycle,$business,'transfer']));
        $order=$this->store->transaction(function(&$s)use($key,$plan,$cycle,$amount,$name,$business,$account,$destination){
            foreach($s['orders']??[] as $row)if(($row['request_key']??'')===$key)return $row['receipt'];
            $id='ORD-'.date('Ymd').'-'.bin2hex(random_bytes(8));$o=['order_id'=>$id,'customer_name'=>$name,'customer_business'=>$business,'customer_mobile'=>$account['mobile'],'plan_id'=>$plan['id'],'plan_name'=>$plan['name'],'billing_cycle'=>$cycle,'amount'=>$amount,'payment_gateway'=>'bank_transfer','status'=>'pending','created_at'=>date('Y-m-d H:i:s')];
            $s['orders'][$id]=['request_key'=>$key,'receipt'=>$o,'account'=>$account,'plan_snapshot'=>$plan,'modules'=>ProjectPlans::modules($plan),'destination'=>$destination];return $o;
        });saveOrder($order);return $order;
    }
    public function order($id){$s=$this->store->read();$r=$s['orders'][$id]??null;if(!$r||($r['receipt']['payment_gateway']??'')!=='bank_transfer')throw new RuntimeException('سفارش یافت نشد.');return $r;}
    public function upload($id,$input,$file){
        $last=faToEn(trim($input['last_four']??''));$ref=trim($input['reference']??'');$date=trim($input['paid_date']??'');
        if(!preg_match('/^[0-9]{4}$/D',$last)||$ref===''||mb_strlen($ref)>100||$date===''||mb_strlen($date)>100)throw new RuntimeException('چهار رقم آخر کارت، شماره پیگیری و زمان واریز را وارد کنید.');
        if(($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>5*1024*1024)throw new RuntimeException('تصویر رسید با حجم حداکثر ۵ مگابایت لازم است.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
        $info=@getimagesize($file['tmp_name']);if(!$ext||!$info||$info[0]*$info[1]>40000000)throw new RuntimeException('رسید باید تصویر معتبر JPG، PNG یا WebP باشد.');
        $path='receipt-'.bin2hex(random_bytes(24)).'.'.$ext;$full=$this->store->directory().'/'.$path;
        if(!move_uploaded_file($file['tmp_name'],$full))throw new RuntimeException('ذخیرهٔ رسید انجام نشد.');chmod($full,0600);
        try{$o=$this->store->transaction(function(&$s)use($id,$last,$ref,$date,$path,$mime){$row=&$s['orders'][$id];if(!is_array($row)||($row['receipt']['payment_gateway']??'')!=='bank_transfer'||!in_array($row['receipt']['status']??'',['pending','rejected'],true))throw new RuntimeException('این درخواست قابل تغییر نیست.');$row['evidence']=['file'=>$path,'mime'=>$mime,'last_four'=>$last,'reference'=>$ref,'paid_date'=>$date,'submitted_at'=>time()];$row['receipt']['status']='awaiting_review';return $row['receipt'];});}catch(Throwable $e){@unlink($full);throw $e;}saveOrder($o);return $o;
    }
    public function review($id,$approve,$note){
        if(!isMainAdmin())AccessControl::deny();$note=trim($note);if(mb_strlen($note)>2000||(!$approve&&$note===''))throw new RuntimeException('برای رد پرداخت، علت را وارد کنید.');
        $o=$this->store->transaction(function(&$s)use($id,$approve,$note){$row=&$s['orders'][$id];if(!is_array($row)||($row['receipt']['payment_gateway']??'')!=='bank_transfer')throw new RuntimeException('سفارش یافت نشد.');if(($row['receipt']['status']??'')==='completed'){if(!$approve)throw new RuntimeException('پرداخت تأییدشده قابل رد نیست.');return $this->provision($row,$id);}if(($row['receipt']['status']??'')!=='awaiting_review'||empty($row['evidence']))throw new RuntimeException('رسید آمادهٔ بررسی نیست.');$row['review']=['by'=>$_SESSION['user']['username'],'at'=>time(),'note'=>$note,'approved'=>$approve];if(!$approve){$row['receipt']['status']='rejected';return $row['receipt'];}$row['verified_at']=time();$row['receipt']['status']='completed';$row['receipt']['paid_at']=date('Y-m-d H:i:s');$row['receipt']['ref_code']=$row['evidence']['reference'];return $this->provision($row,$id);});saveOrder($o);return $o;
    }
    public function receiptPath($row){$name=$row['evidence']['file']??'';if(!preg_match('/^receipt-[a-f0-9]{48}\.(jpg|png|webp)$/D',$name))throw new RuntimeException('رسید موجود نیست.');return $this->store->directory().'/'.$name;}
}
