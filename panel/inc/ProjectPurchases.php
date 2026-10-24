<?php
require_once __DIR__.'/ProjectOnboarding.php';
require_once __DIR__.'/SystemAdmins.php';
require_once __DIR__.'/ProjectMembers.php';
require_once __DIR__.'/ai/Store.php';
/** A private purchase ledger binds bank verification, account and one project to the same order. */
class ProjectPurchases {
    protected $store;
    public function __construct($store=null){$this->store=$store?:new AiStore(dirname(__DIR__).'/db/purchases');}
    public static function account($input) {
        $member=isUser()?SessionIdentity::member(getCurrentProject()):null;
        if($member){$username=$member['username'];$password=$member['password'];if(!password_get_info($password)['algo'])$password=password_hash($password,PASSWORD_DEFAULT);$mobile=trim($member['mobile']??'')?:trim($input['customer_mobile']??'');}
        else{
            $username=trim($input['account_username']??'');$raw=$input['account_password']??'';
            if(!preg_match('/^[A-Za-z0-9_.-]{3,60}$/D',$username)||!is_string($raw)||strlen($raw)<10||strlen($raw)>72)throw new RuntimeException('نام کاربری ۳ تا ۶۰ کاراکتر و رمز حداقل ۱۰ کاراکتر لازم است.');
            foreach(SaaSAccounts::snapshot()['accounts']??[] as $existingAccount)if(($existingAccount['owner']??'')===$username)throw new RuntimeException('این حساب قبلاً ثبت شده؛ ابتدا وارد حساب خود شوید.');
            foreach(getDB()['projects']??[] as $p)foreach($p['users']??[] as $u)if(($u['username']??'')===$username)throw new RuntimeException('این نام کاربری قبلاً ثبت شده؛ ابتدا با حساب خود وارد شوید.');
            foreach(getDB()['super_users']??[] as $u)if(($u['username']??'')===$username)throw new RuntimeException('نام کاربری در دسترس نیست.');
            if(isset(SystemAdmins::all()[$username]))throw new RuntimeException('نام کاربری در دسترس نیست.');
            $password=password_hash($raw,PASSWORD_DEFAULT);$mobile=trim($input['customer_mobile']??'');
        }
        $mobile=preg_replace('/[^0-9]/','',faToEn($mobile));if(!preg_match('/^09[0-9]{9}$/D',$mobile))throw new RuntimeException('شماره همراه معتبر وارد کنید.');
        return ['username'=>$username,'password'=>$password,'mobile'=>$mobile,'role'=>'project_admin','permissions'=>['all'],'panel_access'=>['admin','portal']];
    }
    public function start($input,$site,$callback,$request=null) {
        if(isUser()&&!isMainAdmin()&&(new SaaSAccounts())->owned())throw new RuntimeException('اشتراک حساب دارید؛ برای تمدید، ارتقا یا ساخت پروژه از صفحهٔ حساب و اشتراک اقدام کنید.');
        if(empty($site['zarinpal_enabled'])||empty($site['zarinpal_merchant_id']))throw new RuntimeException('درگاه پرداخت آماده نیست؛ با پشتیبانی تماس بگیرید.');
        if(!empty($site['zarinpal_sandbox'])&&!isMainAdmin())throw new RuntimeException('درگاه در حالت آزمایشی است؛ خرید واقعی هنوز فعال نشده است.');
        if(($input['payment_gateway']??'zarinpal')!=='zarinpal')throw new RuntimeException('درگاه پرداخت معتبر نیست.');
        $plan=ProjectPlans::find($input['plan_id']??'');$cycle=$input['billing_cycle']??'';if(!in_array($cycle,['monthly','yearly'],true))throw new RuntimeException('دورهٔ اشتراک معتبر نیست.');
        $amount=(int)($plan['price_'.$cycle]??0);if($amount<=0)throw new RuntimeException('قیمت این پلن برای خرید آنلاین تنظیم نشده است.');
        $name=trim($input['customer_name']??'');$business=trim($input['customer_business']??'');if($name===''||$business===''||mb_strlen($name)>150||mb_strlen($business)>150)throw new RuntimeException('نام خریدار و نام پروژه را وارد کنید.');
        $currency=strtoupper($site['zarinpal_currency']??'IRT');if(!in_array($currency,['IRR','IRT'],true))throw new RuntimeException('واحد پول درگاه معتبر نیست.');
        if($amount<1500)throw new RuntimeException('حداقل مبلغ خرید آنلاین ۱۵۰۰ تومان است؛ قیمت پلن را اصلاح کنید یا کارت‌به‌کارت را انتخاب کنید.');
        $gatewayAmount=$amount*($currency==='IRR'?10:1);
        $account=self::account($input);
        $nonce=$input['checkout_nonce']??bin2hex(random_bytes(16));if(!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new RuntimeException('شناسهٔ درخواست معتبر نیست.');
        $requestKey=hash('sha256',json_encode([$account['username'],$nonce,$plan['id'],$cycle,$business,$currency]));
        $id='ORD-'.date('Ymd').'-'.bin2hex(random_bytes(8));
        $order=['order_id'=>$id,'customer_name'=>$name,'customer_mobile'=>$account['mobile'],'customer_business'=>$business,'plan_id'=>$plan['id'],'plan_name'=>$plan['name'],'billing_cycle'=>$cycle,'amount'=>$amount,'gateway_amount'=>$gatewayAmount,'gateway_currency'=>$currency,'payment_gateway'=>'zarinpal','status'=>'pending','created_at'=>date('Y-m-d H:i:s')];
        $reused=$this->store->transaction(function(&$s)use($order,$account,$plan,$site,$requestKey){
            foreach($s['orders']??[] as $row)if(($row['request_key']??'')===$requestKey&&($row['receipt']['status']??'')!=='failed'){
                if(!empty($row['project_id']))throw new RuntimeException('این خرید قبلاً تکمیل شده است؛ رسید را باز کنید.');
                if(empty($row['payment_url'])){if(($row['prepared_at']??0)<time()-120)continue;throw new RuntimeException('درخواست پرداخت قبلی در حال آماده‌سازی است؛ کمی بعد دوباره تلاش کنید.');}
                return ['order'=>$row['receipt'],'payment_url'=>$row['payment_url']];
            }
            $s['orders'][$order['order_id']]=['prepared_at'=>time(),'request_key'=>$requestKey,'receipt'=>$order,'account'=>$account,'plan_snapshot'=>$plan,'modules'=>ProjectPlans::modules($plan),'gateway'=>array_intersect_key($site,array_flip(['zarinpal_merchant_id','zarinpal_sandbox','zarinpal_currency']))];return null;
        });
        if($reused)return $reused;
        saveOrder($order);
        $request=$request?:'zarinpalRequestPayment';$res=$request($gatewayAmount,'اشتراک '.$plan['name'], $callback.'?payment_callback=zarinpal&order_id='.rawurlencode($id),$account['mobile'],'',$id,$site);
        if(empty($res['success'])||empty($res['authority'])||empty($res['payment_url'])){
            $order['status']='failed';$this->store->transaction(function(&$s)use($id,$order){$s['orders'][$id]['receipt']=$order;});saveOrder($order);throw new RuntimeException('اتصال درگاه انجام نشد؛ مبلغی در پنل ثبت نشده است. '.mb_substr((string)($res['error']??''),0,300).' (کد: '.(int)($res['code']??0).')');
        }
        $order['authority']=$res['authority'];$this->store->transaction(function(&$s)use($id,$order,$res){$s['orders'][$id]['receipt']=$order;$s['orders'][$id]['payment_url']=$res['payment_url'];});saveOrder($order);
        return ['order'=>$order,'payment_url'=>$res['payment_url']];
    }
    public function confirm($id,$authority,$status,$verify=null) {
        $verify=$verify?:'zarinpalVerifyPayment';
        $result=$this->store->transaction(function(&$s)use($id,$authority,$status,$verify){
            if(!isset($s['orders'][$id]))throw new RuntimeException('سفارش خرید جدید یافت نشد؛ برای رسید قدیمی با پشتیبانی تماس بگیرید.');
            $row=&$s['orders'][$id];$o=&$row['receipt'];
            if(!is_string($authority)||$authority===''||empty($o['authority'])||!hash_equals($o['authority'],$authority))throw new RuntimeException('شناسهٔ درگاه با سفارش مطابقت ندارد.');
            if(!empty($row['project_id'])){if(ProjectLifecycle::isDeleted($row['project_id'])||!getCurrentProject($row['project_id'],true))throw new RuntimeException('پروژهٔ این رسید قبلاً حذف شده است؛ پروژهٔ دیگری خودکار ساخته نمی‌شود.');return $o+['project_id'=>$row['project_id']];}
            if(strtoupper($status)!=='OK')throw new RuntimeException('پرداخت لغو شد یا ناموفق بود؛ پروژه‌ای ساخته نشد.');
            if(empty($row['verified_at'])){
                $r=$verify($authority,$o['gateway_amount']??$o['amount'],$row['gateway']);if(empty($r['success'])||empty($r['ref_id']))throw new RuntimeException('پرداخت توسط بانک تأیید نشد. '.mb_substr((string)($r['error']??''),0,300).' (کد: '.(int)($r['code']??0).')');
                $row['verified_at']=time();$o['status']='completed';$o['ref_code']=(string)$r['ref_id'];$o['paid_at']=date('Y-m-d H:i:s');
            }
            return $this->provision($row,$id);
        });
        saveOrder($result);return $result;
    }
    protected function provision(&$row,$id){
        $o=&$row['receipt'];
            // Deterministic ID and transaction prevent a repeated callback from duplicating projects or membership.
            $pid='buy_'.substr(hash('sha256',$id),0,24);
            if(ProjectLifecycle::isDeleted($pid))throw new RuntimeException('پروژهٔ این سفارش قبلاً حذف شده است؛ با پشتیبانی تماس بگیرید.');
            $existing=getCurrentProject($pid,true);
            if(!$existing){
                $p=['id'=>$pid,'name'=>$o['customer_business'],'activeFrom'=>date('Y-m-d'),'activeTo'=>date('Y-m-d',strtotime($o['billing_cycle']==='yearly'?'+1 year':'+1 month')),'users'=>[$row['account']],'products'=>[],'logs'=>[],'settings'=>['platform_type'=>'custom','sourceUrl'=>'','active_modules'=>$row['modules'],'subscription'=>['order_id'=>$id,'plan_id'=>$o['plan_id'],'plan_name'=>$o['plan_name'],'modules'=>$row['modules'],'verified_at'=>$row['verified_at']],'onboarding'=>['created_at'=>time(),'revision'=>0,'steps'=>[]]]];
                ProjectMembers::prepareStorage();
                if(!db_save_project($p))throw new RuntimeException('پرداخت تأیید شد ولی ساخت پروژه تکمیل نشد؛ دوباره همین رسید را باز کنید.');
            }elseif(($existing['settings']['subscription']['order_id']??'')!==$id)throw new RuntimeException('شناسهٔ پروژه با سفارش مطابقت ندارد.');
            require_once __DIR__.'/saas/Accounts.php';$account=(new SaaSAccounts())->provision($o,$row['account'],$row['plan_snapshot']??ProjectPlans::find($o['plan_id']),$pid,strtotime($existing['activeTo']??($o['billing_cycle']==='yearly'?'+1 year':'+1 month')));
            ProjectOnboarding::mutate($pid,function(&$p)use($account){$p['settings']['subscription']['account_id']=$account['id'];});
            $row['project_id']=$pid;$o['project_id']=$pid;return $o;
    }

}
