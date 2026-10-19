<?php
class AiOAuth {
    private $store;
    public function __construct($store){$this->store=$store;}
    public static function base(){return rtrim(getenv('MAARKSHO_AI_PUBLIC_BASE')?:'https://maarksho.com','/');}
    public static function b64($value){return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    public static function validRedirect($url) {
        $p=is_string($url)?parse_url($url):false;
        return $p&&($p['scheme']??'')==='https'&&!empty($p['host'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&strlen($url)<1000;
    }
    public function createClient($name,$redirects,$owner,$public=false) {
        if(!is_string($name)||trim($name)===''||mb_strlen($name)>100||!is_array($redirects)||!$redirects||count($redirects)>5)throw new RuntimeException('نام اتصال و آدرس بازگشت لازم است.');
        foreach($redirects as $url)if(!self::validRedirect($url))throw new RuntimeException('آدرس بازگشت باید HTTPS و دقیق باشد.');
        $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));
        $this->store->transaction(function(&$s)use($id,$secret,$name,$redirects,$owner,$public){if(count(array_filter($s['clients']??[],static fn($c)=>$c['owner']===$owner))>=30)throw new RuntimeException('حداکثر ۳۰ اپ برای هر حساب مجاز است.');$s['clients'][$id]=['id'=>$id,'name'=>trim($name),'redirect_uris'=>array_values(array_unique($redirects)),'owner'=>$owner,'public'=>(bool)$public,'secret_hash'=>hash('sha256',$secret),'created'=>time()];});
        return ['client_id'=>$id,'client_secret'=>$secret];
    }
    public function client($id){$s=$this->store->read();$c=$s['clients'][$id]??null;if(!$c)throw new RuntimeException('اپ ثبت نشده است؛ ابتدا در پنل یک اتصال بسازید.');return $c;}
    public function request($args) {
        $client=$this->client($args['client_id']??'');
        if(($args['response_type']??'')!=='code'||!in_array($args['redirect_uri']??'', $client['redirect_uris'],true)||!is_string($args['state']??null)||strlen($args['state'])<8||strlen($args['state'])>512)throw new RuntimeException('درخواست اتصال معتبر نیست.');
        $challenge=$args['code_challenge']??'';
        if($challenge!==''&&(!is_string($challenge)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)||($args['code_challenge_method']??'')!=='S256'))throw new RuntimeException('روش PKCE معتبر نیست.');
        // Confidential GPT Actions clients may use their secret; MCP uses S256.
        if($challenge===''&&!empty($client['public']))throw new RuntimeException('PKCE لازم است.');
        if(isset($args['resource'])&&$args['resource']!==self::base().'/panel/ai_mcp.php')throw new RuntimeException('منبع OAuth معتبر نیست.');
        return $client;
    }
    public function authorize($args,$principal,$scopes) {
        $client=$this->request($args);$code=bin2hex(random_bytes(32));$gid=bin2hex(random_bytes(16));
        $this->store->transaction(function(&$s)use($principal,$scopes,$args,$client,$code,$gid){
            foreach($s['codes']??[] as $key=>$c)if($c['expires']<time())unset($s['codes'][$key]);
            if(count(array_filter($s['grants']??[],static fn($g)=>$g['owner']===$principal['owner']&&$g['project']===$principal['project']&&!$g['revoked']))>=100)throw new RuntimeException('اتصال‌های قبلی را در پنل لغو کنید.');
            $s['grants'][$gid]=$principal+['id'=>$gid,'client'=>$client['id'],'scopes'=>$scopes,'created'=>time(),'revoked'=>false];
            $s['codes'][hash('sha256',$code)]=['grant'=>$gid,'client'=>$client['id'],'redirect'=>$args['redirect_uri'],'challenge'=>$args['code_challenge']??'','project'=>$principal['project'],'expires'=>time()+300];
            AiStore::audit($s,$principal,'oauth_consent',['client'=>$client['id'],'scopes'=>$scopes]);
        });return $code;
    }
    public function exchange($input) {
        return $this->store->transaction(function(&$s)use($input){
            $client=$s['clients'][$input['client_id']??'']??null;if(!$client)throw new RuntimeException('invalid_client');
            $secret=$input['client_secret']??'';$secretValid=is_string($secret)&&hash_equals($client['secret_hash'],hash('sha256',$secret));
            $kind=$input['grant_type']??'';if(empty($client['public'])&&!$secretValid)throw new RuntimeException('invalid_client');
            if($kind==='authorization_code') {
                $key=hash('sha256',(string)($input['code']??''));$code=$s['codes'][$key]??null;
                if(!$code||$code['expires']<time()||$code['client']!==$client['id']||($input['redirect_uri']??'')!==$code['redirect'])throw new RuntimeException('invalid_grant');
                if($code['challenge']!==''){$v=$input['code_verifier']??'';if(!is_string($v)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$v)||!hash_equals($code['challenge'],self::b64(hash('sha256',$v,true))))throw new RuntimeException('invalid_grant');}
                elseif(!$secretValid)throw new RuntimeException('invalid_client');
                $grant=$s['grants'][$code['grant']]??null;unset($s['codes'][$key]);
            } elseif($kind==='refresh_token') {
                $key=hash('sha256',(string)($input['refresh_token']??''));$old=$s['tokens'][$key]??null;
                if(!$old||$old['type']!=='refresh'||$old['expires']<time()||$old['client']!==$client['id'])throw new RuntimeException('invalid_grant');
                $grant=$s['grants'][$old['grant']]??null;unset($s['tokens'][$key]);
            } else throw new RuntimeException('unsupported_grant_type');
            if(!$grant||$grant['revoked'])throw new RuntimeException('invalid_grant');
            AiAccess::run($grant,static function($p){if(!AiAccess::pageAllowed())throw new RuntimeException('invalid_grant');});
            $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));
            $s['tokens'][hash('sha256',$access)]=['grant'=>$grant['id'],'client'=>$client['id'],'project'=>$grant['project'],'type'=>'access','expires'=>time()+3600];
            $s['tokens'][hash('sha256',$refresh)]=['grant'=>$grant['id'],'client'=>$client['id'],'project'=>$grant['project'],'type'=>'refresh','expires'=>time()+30*86400];
            foreach($s['tokens'] as $id=>$t)if($t['expires']<time())unset($s['tokens'][$id]);foreach($s['codes']??[] as $id=>$c)if($c['expires']<time())unset($s['codes'][$id]);
            return ['access_token'=>$access,'refresh_token'=>$refresh,'token_type'=>'Bearer','expires_in'=>3600,'scope'=>implode(' ',$grant['scopes'])];
        });
    }
    public function bearer($token) {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('invalid_token');
        $s=$this->store->read();$t=$s['tokens'][hash('sha256',$token)]??null;$g=$s['grants'][$t['grant']??'']??null;
        if(!$t||$t['type']!=='access'||$t['expires']<time()||!$g||$g['revoked'])throw new RuntimeException('invalid_token');
        return $g;
    }
    public function revoke($id,$owner) {$this->store->transaction(function(&$s)use($id,$owner){if(($s['grants'][$id]['owner']??'')!==$owner)throw new RuntimeException('دسترسی اتصال مجاز نیست.');$s['grants'][$id]['revoked']=true;});}
}
