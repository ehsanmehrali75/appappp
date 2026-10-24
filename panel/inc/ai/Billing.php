<?php
/** Rial integer ledger, reserved before API calls; payment verification is server-to-server. */
class AiBilling {
    private $store;
    private $http;
    public function __construct($store,$http=null){$this->store=$store;$this->http=$http?:[AiHttp::class,'json'];}
    public static function available($state,$owner){$wallet=$state['wallets'][$owner]??[];return (int)($wallet['balance']??0)-(int)($wallet['reserved']??0);}
    public function checkout($principal,$amount) {
        if(SaaSAccounts::projectAccount($principal['project']))throw new RuntimeException('شارژ این حساب از بسته‌های کردیت حساب مشتری انجام می‌شود.');
        if(!is_int($amount)||$amount<10000||$amount>100000000)throw new RuntimeException('شارژ باید بین ۱۰ هزار و ۱۰۰ میلیون ریال باشد.');
        $settings=getSiteSettings();if(empty($settings['zarinpal_enabled'])||empty($settings['zarinpal_merchant_id']))throw new RuntimeException('درگاه پرداخت هنوز توسط ادمین تنظیم نشده است.');
        $id=bin2hex(random_bytes(16));$sandbox=!empty($settings['zarinpal_sandbox']);$currency=strtoupper($settings['zarinpal_currency']??'IRT');$gatewayAmount=$currency==='IRT'?(int)ceil($amount/10):$amount;$credit=$currency==='IRT'?$gatewayAmount*10:$amount;
        if(!in_array($currency,['IRR','IRT'],true))throw new RuntimeException('واحد پول درگاه معتبر نیست.');
        $gateway=$sandbox?'https://sandbox.zarinpal.com':'https://payment.zarinpal.com';
        $payment=['id'=>$id,'owner'=>$principal['owner'],'project'=>$principal['project'],'amount'=>$gatewayAmount,'credit'=>$credit,'currency'=>$currency,'sandbox'=>$sandbox,'merchant'=>$settings['zarinpal_merchant_id'],'state'=>'pending','created'=>time()];
        $this->store->transaction(static function(&$s)use($id,$payment){$s['payments'][$id]=$payment;});
        $result=call_user_func($this->http,$gateway.'/pg/v4/payment/request.json',['merchant_id'=>$payment['merchant'],'amount'=>$gatewayAmount,'currency'=>$currency,'description'=>'شارژ اعتبار تحلیل هوشمند '.$id,'callback_url'=>AiOAuth::base().'/panel/ai_payment.php?id='.$id]);
        $data=$result['body']['data']??[];if(($result['status']??0)!==200||($data['code']??0)!==100||!is_string($data['authority']??null)||!preg_match('/^[a-zA-Z0-9_-]{10,100}$/D',$data['authority']))throw new RuntimeException('درخواست پرداخت پذیرفته نشد.');
        $this->store->transaction(static function(&$s)use($id,$data){$s['payments'][$id]['authority']=$data['authority'];});
        return $gateway.'/pg/StartPay/'.$data['authority'];
    }
    public function verify($id,$authority,$status) {
        $state=$this->store->read();$p=$state['payments'][$id]??null;
        if(!$p||!is_string($authority)||!hash_equals($p['authority']??'',$authority)||$status!=='OK')throw new RuntimeException('پرداخت لغو شده یا معتبر نیست.');
        if($p['state']==='paid')return $p;
        $url=($p['sandbox']?'https://sandbox.zarinpal.com':'https://payment.zarinpal.com').'/pg/v4/payment/verify.json';
        $result=call_user_func($this->http,$url,['merchant_id'=>$p['merchant'],'amount'=>$p['amount'],'authority'=>$authority]);$data=$result['body']['data']??[];
        if(($result['status']??0)!==200||!in_array($data['code']??0,[100,101],true)||empty($data['ref_id']))throw new RuntimeException('پرداخت توسط درگاه تأیید نشد.');
        return $this->store->transaction(static function(&$s)use($id,$data){$p=&$s['payments'][$id];if($p['state']==='paid')return $p;
            foreach($s['payments'] as $otherId=>$other)if($otherId!==$id&&($other['state']??'')==='paid'&&($other['sandbox']??false)===$p['sandbox']&&(string)($other['ref_id']??'')===(string)$data['ref_id'])throw new RuntimeException('تراکنش قبلاً ثبت شده است.');
            $p['state']='paid';$p['ref_id']=(string)$data['ref_id'];$p['paid_at']=time();
            // Sandbox is a test ledger and must never mint spendable production credit.
            if(!$p['sandbox']){$owner=$p['owner'];$s['wallets'][$owner]['balance']=(int)($s['wallets'][$owner]['balance']??0)+$p['credit'];$s['ledger'][]=['owner'=>$owner,'project'=>$p['project'],'kind'=>'topup','amount'=>$p['credit'],'payment'=>$id,'at'=>time()];}
            return $p;
        });
    }
    public static function reserve(&$s,$owner,$project,$amount,$key) {
        if(isset($s['reservations'][$key]))throw new RuntimeException('این اجرای گزارش قبلاً ثبت شده است.');
        if($amount<0||self::available($s,$owner)<$amount)throw new RuntimeException('اعتبار کافی نیست؛ کیف پول را شارژ کنید.');
        $s['wallets'][$owner]['reserved']=(int)($s['wallets'][$owner]['reserved']??0)+$amount;
        $s['reservations'][$key]=['owner'=>$owner,'project'=>$project,'amount'=>$amount,'state'=>'reserved','at'=>time()];
    }
    public static function settle(&$s,$key,$charge,$unknown=false) {
        if(!isset($s['reservations'][$key]))throw new RuntimeException('رزرو یافت نشد.');$r=&$s['reservations'][$key];if(!$r||$r['state']!=='reserved')throw new RuntimeException('رزرو مصرف معتبر نیست.');
        if($unknown){$r['state']='uncertain';return;}
        if(!is_int($charge)||$charge<0||$charge>$r['amount'])throw new RuntimeException('مصرف از رزرو بیشتر است؛ بررسی مالی لازم است.');
        $owner=$r['owner'];$s['wallets'][$owner]['reserved']-=$r['amount'];$s['wallets'][$owner]['balance']=(int)($s['wallets'][$owner]['balance']??0)-$charge;$r['state']='settled';$r['charge']=$charge;
        $s['ledger'][]=['owner'=>$owner,'project'=>$r['project'],'kind'=>'usage','amount'=>-$charge,'reservation'=>$key,'at'=>time()];
    }
}
