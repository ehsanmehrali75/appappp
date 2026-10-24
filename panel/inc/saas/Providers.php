<?php
require_once __DIR__.'/Accounts.php';
/** Central provider registry. Personal ChatGPT grants and customer wallets are separate. */
class SaaSProviders {
    private $accounts,$transport;
    public function __construct($accounts=null,$transport=null){$this->accounts=$accounts?:new SaaSAccounts();$this->transport=$transport?:[AiHttp::class,'json'];}
    public static function definitions(){return [
        'openai'=>['name'=>'OpenAI','base'=>'https://api.openai.com/v1','protocol'=>'responses','image_edit'=>true],
        'anthropic'=>['name'=>'Claude / Anthropic','base'=>'https://api.anthropic.com/v1','protocol'=>'messages','image_edit'=>false],
        'avalai'=>['name'=>'AvalAI','base'=>'https://api.avalai.ir/v1','protocol'=>'chat_completions','image_edit'=>true],
    ];}
    private static function central(){if(!isMainAdmin())throw new RuntimeException('تنظیم ارائه‌دهندگان فقط برای تیم مرکزی مجاز است.');}
    public function save($input){self::central();$id=$input['id']??'';$provider=$input['provider']??'';$model=trim($input['model']??'');$limit=(int)($input['max_output_tokens']??1024);
        if(!SaaSAccounts::validId($id)||!isset(self::definitions()[$provider])||!preg_match('~^[A-Za-z0-9._:/-]{2,160}$~D',$model)||$limit<1||$limit>32768)throw new RuntimeException('شناسه، ارائه‌دهنده، مدل و سقف خروجی معتبر لازم است.');
        $old=$this->accounts->state()['central_providers'][$id]??[];$key=trim($input['key']??'');
        if($key!==''&&(strlen($key)<20||strlen($key)>500||preg_match('/[\r\n]/',$key)))throw new RuntimeException('کلید API معتبر نیست.');
        if(!$key&&(($old['provider']??'')!==$provider||empty($old['key'])))throw new RuntimeException('برای ارائه‌دهندهٔ جدید کلید لازم است.');
        $encrypted=$key!==''?$this->accounts->store()->encrypt($key):$old['key'];
        $credits=(int)($input['credits']??0);if(!empty($input['ehsan_enabled'])&&($credits<1||$credits>1000000))throw new RuntimeException('تعرفهٔ اجرای احسان لازم است.');
        $description=trim($input['description']??'');if(mb_strlen($description)>500)throw new RuntimeException('معرفی مدل طولانی است.');
        $instructions=trim($input['ehsan_instructions']??'');if(mb_strlen($instructions)>4000)throw new RuntimeException('راهنمای تخصصی مدل طولانی است.');
        $row=['ehsan_instructions'=>$instructions,'description'=>$description,'ehsan_default'=>!empty($input['ehsan_default']),'credits'=>$credits,'ehsan_enabled'=>!empty($input['ehsan_enabled']),'id'=>$id,'provider'=>$provider,'model'=>$model,'key'=>$encrypted,'max_output_tokens'=>$limit,'enabled'=>!empty($input['enabled']),'revision'=>bin2hex(random_bytes(16)),'updated_at'=>time()];
        $this->accounts->change(function(&$s)use($id,$row){if($row['ehsan_default'])foreach($s['central_providers']??[] as $other=>$value)$s['central_providers'][$other]['ehsan_default']=false;$s['central_providers'][$id]=$row;});
    }
    public function catalog(){self::central();$rows=$this->accounts->state()['central_providers']??[];foreach($rows as &$r){unset($r['key']);$r['configured']=true;}return $rows;}
    public static function request($provider,$model,$key,$prompt,$maxOutput=1024){
        $d=self::definitions()[$provider]??null;if(!$d||$maxOutput<1||$maxOutput>32768||!is_string($prompt)||strlen($prompt)>100000)throw new RuntimeException('درخواست مدل معتبر نیست.');
        if($provider==='anthropic')return ['url'=>$d['base'].'/messages','headers'=>['x-api-key: '.$key,'anthropic-version: 2023-06-01'],'body'=>['model'=>$model,'max_tokens'=>$maxOutput,'messages'=>[['role'=>'user','content'=>$prompt]]]];
        if($provider==='openai')return ['url'=>$d['base'].'/responses','headers'=>['Authorization: Bearer '.$key],'body'=>['model'=>$model,'input'=>$prompt,'max_output_tokens'=>$maxOutput,'store'=>false]];
        return ['url'=>$d['base'].'/chat/completions','headers'=>['Authorization: Bearer '.$key],'body'=>['model'=>$model,'messages'=>[['role'=>'user','content'=>$prompt]],'max_tokens'=>$maxOutput]];
    }
    public static function normalize($provider,$body){$text='';
        if($provider==='anthropic'){foreach($body['content']??[] as $part)if(($part['type']??'')==='text')$text.=$part['text']??'';}
        elseif($provider==='openai'){foreach($body['output']??[] as $item)if(($item['type']??'')==='message')foreach($item['content']??[] as $part)if(($part['type']??'')==='output_text')$text.=$part['text']??'';}
        elseif($provider==='avalai')$text=$body['choices'][0]['message']['content']??'';
        else throw new RuntimeException('ارائه‌دهنده معتبر نیست.');
        if(!is_string($text)||$text==='')throw new RuntimeException('سرویس خروجی متنی معتبر نداد.');
        return ['text'=>$text,'usage'=>$body['usage']??null,'provider_request_id'=>$body['id']??null];
    }
    /** A read-only model lookup proves access only; it does not prove generation or upstream balance. */
    public function probe($id){self::central();$r=$this->accounts->state()['central_providers'][$id]??null;if(!$r||!$r['enabled'])throw new RuntimeException('اتصال فعال یافت نشد.');$d=self::definitions()[$r['provider']];$key=$this->accounts->store()->decrypt($r['key']);$headers=$r['provider']==='anthropic'?['x-api-key: '.$key,'anthropic-version: 2023-06-01']:['Authorization: Bearer '.$key];
        $success=false;$status=0;try{$out=call_user_func($this->transport,$d['base'].'/models/'.rawurlencode($r['model']),null,$headers);$status=(int)($out['status']??0);$success=$status===200&&($out['body']['id']??'')===$r['model'];}catch(Throwable $e){}
        $result=['success'=>$success,'http_status'=>$status,'at'=>time(),'generation_tested'=>false,'balance_verified'=>false,'revision'=>$r['revision']];
        $this->accounts->change(function(&$s)use($id,$r,$result){if(($s['central_providers'][$id]['revision']??'')!==$r['revision'])throw new RuntimeException('تنظیمات هنگام تست تغییر کرد.');$s['central_providers'][$id]['probe']=$result;});return $result;
    }
}
