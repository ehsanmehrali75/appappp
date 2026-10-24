<?php
/** Read-only projections of the credit ledger. Never infer provider money from tokens. */
class SaaSUsage {
    public static function project($state,$pid){
        $out=['charged'=>0,'reserved'=>0,'uncertain'=>0,'requests'=>0,'models'=>[]];
        foreach(['ehsan_jobs'=>'ehsan','aria_jobs'=>'aria','image_jobs'=>'nahal'] as $table=>$employee)foreach($state[$table]??[] as $id=>$job){
            if(($job['project']??'')!==$pid)continue;
            $profile=$job['model_profile']??[];$current=$state[$employee!=='nahal'?'central_providers':'image_models'][$job['model']??'']??[];
            // Historic jobs without an immutable profile cannot be attributed to a changed connection.
            if(!$profile&&($current['revision']??null)===($job['model_revision']??null))$profile=$current;
            $provider=$profile['provider']??'unknown';$model=$profile['model']??($job['model']??'unknown');$key=$employee.'|'.$provider.'|'.$model;
            if(!isset($out['models'][$key]))$out['models'][$key]=['employee'=>$employee,'provider'=>$provider,'model'=>$model,'requests'=>0,'charged'=>0,'reserved'=>0,'uncertain'=>0,'input_tokens'=>0,'output_tokens'=>0,'usage_reports'=>0,'token_reports'=>0];
            $row=&$out['models'][$key];$r=$state['credit_reservations'][$id]??[];$charged=(int)($r['charged']??0);$held=in_array($r['state']??'',['reserved','uncertain'],true)?(int)($r['credits']??0):0;$uncertain=($r['state']??'')==='uncertain'?$held:0;
            foreach(['charged'=>$charged,'reserved'=>$held,'uncertain'=>$uncertain,'requests'=>1] as $field=>$n){$row[$field]+=$n;$out[$field]+=$n;}
            $usage=$job['usage']??null;if(is_array($usage)&&$usage){$row['usage_reports']++;$tokens=false;foreach(['input_tokens'=>['input_tokens','prompt_tokens'],'output_tokens'=>['output_tokens','completion_tokens']] as $field=>$aliases)foreach($aliases as $alias)if(isset($usage[$alias])&&is_numeric($usage[$alias])&&$usage[$alias]>=0){$row[$field]+=(int)$usage[$alias];$tokens=true;break;}if($tokens)$row['token_reports']++;}
            unset($row);
        }
        return $out;
    }
    public static function render($state,$pid,$escape){
        $u=self::project($state,$pid);$h=$escape;
        echo '<p>مصرف تسویه‌شده: <b>'.number_format($u['charged']).'</b> کردیت · رزرو: '.number_format($u['reserved']).' · از آن، نامشخص: '.number_format($u['uncertain']).' · درخواست: '.$u['requests'].'</p>';
        echo '<p>از ابتدای ثبت دفتر مصرف؛ رزرو هنوز هزینهٔ قطعی نیست. توکن‌ها فقط از پاسخ سرویس‌دهنده هستند و معادل پول یا کردیت نیستند.</p>';
        foreach($u['models'] as $r)echo '<div class="saas-project"><strong>'.$h(['ehsan'=>'احسان','nahal'=>'نهال','aria'=>'آریا'][$r['employee']]).' / '.$h($r['provider']).' / '.$h($r['model']).'</strong><p>مصرف: '.number_format($r['charged']).' کردیت · درخواست: '.$r['requests'].' · پاسخ دارای آمار: '.$r['usage_reports'].'</p><p>'.($r['token_reports']?'توکن ورودی ثبت‌شده: '.number_format($r['input_tokens']).' · خروجی: '.number_format($r['output_tokens']).'؛ فقط '.$r['token_reports'].' پاسخ دارای آمار توکن محاسبه شده است.':'آمار توکن از سرویس‌دهنده ثبت نشده است.').'</p></div>';
    }
}
