<?php
require_once __DIR__.'/Aria.php';
/** A ready content approval is distinct from queue acceptance and actual publication. */
class AriaPublisher {
    private $accounts,$http;
    public function __construct($accounts=null,$http=null){$this->accounts=$accounts?:new SaaSAccounts();$this->http=$http?:[AiHttp::class,'json'];}
    public static function capability($p,$channel){
        if(in_array($channel,['telegram','bale'],true)){$c=$p['settings']['connections'][$channel]??[];return !empty($c['token'])&&!empty($c['chatId'])?'direct':'connection_required';}
        if(in_array($channel,['instagram_post','instagram_story'],true))return AiSocial::route($p['settings'],'instagram')?'external_chatgpt':'connection_required';
        return ['website'=>'website_publish_adapter_required','rubika'=>'rubika_contract_required','sms'=>'sms_recipient_cost_approval_required'][$channel]??'unsupported';
    }
    public function tick(){
        foreach($this->accounts->state()['aria_configs']??[] as $config){try{$processed=AiAccess::run($config['principal'],function($p)use($config){AiAccess::need('calendar','use');AiAccess::need('ai_connect','use');$member=SessionIdentity::member($p);if(!isMainAdmin()&&($member['role']??'')!=='project_admin')return false;$a=SaaSAccounts::projectAccount($p['id']);if(!$a||!SaaSAccounts::active($a)||empty($a['active_employees']['aria'][$p['id']])||!empty($p['settings']['backup_restore_paused'])||(int)($config['recovery_revision']??0)!==(int)($p['settings']['data_recovery_revision']??0))return false;
                foreach($p['settings']['aria_calendar']['plans']??[] as $plan){if($plan['state']!=='approved'||(int)$plan['recovery_revision']!==(int)($p['settings']['data_recovery_revision']??0))continue;
                    foreach($plan['entries'] as $item){if($item['state']==='queued_external'){$external=(new AiStore())->read()['social_jobs'][$item['receipt']['job']??'']??[];if(($external['project']??'')===$p['id']&&in_array($external['state']??'',['published','failed','uncertain'],true))ProjectOnboarding::mutate($p['id'],function(&$fresh)use($plan,$item,$external){$entry=&$fresh['settings']['aria_calendar']['plans'][$plan['id']]['entries'][$item['id']];if($entry['state']==='queued_external'){$entry['state']=$external['state'];$entry['receipt']=['job'=>$external['id'],'receipt'=>$external['receipt']??null,'verification'=>'external_reported'];}});continue;}if($item['state']==='dispatching'&&($item['dispatch_at']??0)<time()-180){$this->update($p['id'],$plan['id'],$item['id'],'uncertain');continue;}if($item['state']!=='ready'||strtotime($item['publish_at'])>time())continue;
                        $allowed=false;foreach((new Aria($this->accounts))->jobs($p) as $job)if($job['id']===$plan['id']&&!empty($job['report'])&&empty($job['task_paused_by_recovery']))$allowed=true;if(!$allowed)continue;
                        if(($item['approval']['by']??'')!=='project_auto_policy'&&!in_array($item['approval']['by']??'',array_column($p['users']??[],'username'),true)&&($item['approval']['by']??'')!==($config['principal']['username']??''))continue;if(empty($item['approval']['hash'])||!hash_equals($item['content_hash']??'',$item['approval']['hash']))continue;
                        if(($item['approval']['by']??'')==='project_auto_policy'&&!in_array($item['channel'],$config['auto_publish']??[],true))continue;
                        $cap=self::capability($p,$item['channel']);if(!in_array($cap,['direct','external_chatgpt'],true)){if(($item['blocked_reason']??'')!==$cap)ProjectOnboarding::mutate($p['id'],function(&$fresh)use($plan,$item,$cap){$fresh['settings']['aria_calendar']['plans'][$plan['id']]['entries'][$item['id']]['blocked_reason']=$cap;});continue;}
                        return $this->dispatch($p,$plan,$item,$cap);
                    }
                }return false;
            });if($processed)return true;}catch(Throwable $e){/* Authorization loss prevents external effects; never impersonate another project. */}}
        return false;
    }
    private function update($pid,$plan,$item,$state,$receipt=null){ProjectOnboarding::mutate($pid,function(&$p)use($plan,$item,$state,$receipt){$r=&$p['settings']['aria_calendar']['plans'][$plan]['entries'][$item];if(($r['state']??'')!=='dispatching')return;$r['state']=$state;$r['receipt']=$receipt;$r['finished_at']=time();});}
    private function dispatch($p,$plan,$item,$cap){
        $claim=ProjectOnboarding::mutate($p['id'],function(&$fresh)use($plan,$item){$r=&$fresh['settings']['aria_calendar']['plans'][$plan['id']]['entries'][$item['id']];if(($r['state']??'')!=='ready'||($r['content_hash']??'')!==$item['content_hash']||(int)$plan['recovery_revision']!==(int)($fresh['settings']['data_recovery_revision']??0)||!empty($fresh['settings']['backup_restore_paused']))return false;$r['state']='dispatching';$r['dispatch_at']=time();unset($r['blocked_reason']);return true;});if(!$claim)return false;
        $sent=false;try{
            if($cap==='external_chatgpt'){$sent=true;$out=(new AiSocial(new AiStore()))->enqueue($p,['platform'=>'instagram','kind'=>$item['channel']==='instagram_story'?'story':'post','caption'=>$item['caption'],'media'=>[$item['media']],'scheduled_at'=>strtotime($item['publish_at']),'request_id'=>'aria-'.$item['id']]);$this->update($p['id'],$plan['id'],$item['id'],'queued_external',['job'=>$out['id'],'verification'=>'not_published']);return true;}
            $c=$p['settings']['connections'][$item['channel']];$host=$item['channel']==='telegram'?'api.telegram.org':'tapi.bale.ai';$token=$c['token'];if(!preg_match('/^[A-Za-z0-9:_-]{10,300}$/D',$token))throw new RuntimeException('توکن معتبر نیست.');$photo=!empty($item['media']);$body=['chat_id'=>$c['chatId']];if($photo){if(mb_strlen($item['caption'])>1024)throw new RuntimeException('توضیح تصویر بیش از حد مجاز است.');$body+=['photo'=>$item['media'],'caption'=>$item['caption']];}else{if(mb_strlen($item['caption'])>4096)throw new RuntimeException('پیام بیش از حد مجاز است.');$body['text']=$item['caption'];}
            $sent=true;$r=call_user_func($this->http,'https://'.$host.'/bot'.$token.'/'.($photo?'sendPhoto':'sendMessage'),$body,[]);$receipt=$r['body']['result']['message_id']??null;
            if(($r['status']??0)===200&&!empty($r['body']['ok'])&&(is_int($receipt)||is_string($receipt))){$this->update($p['id'],$plan['id'],$item['id'],'published',['message_id'=>$receipt,'provider'=>$item['channel']]);}
            else{$status=(int)($r['status']??0);$definite=in_array($status,[400,401,403,404,422,429],true)||($status===200&&isset($r['body']['ok'])&&$r['body']['ok']===false);$this->update($p['id'],$plan['id'],$item['id'],$definite?'failed':'uncertain',['http_status'=>$status]);}
        }catch(Throwable $e){$this->update($p['id'],$plan['id'],$item['id'],$sent?'uncertain':'failed');}return true;
    }
}
