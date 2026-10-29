<?php
/** Official Bot API v3 text contract: https://rubika.ir/botapi/methods */
class RubikaBot {
    public static function request($token,$chat,$text){if(!is_string($token)||!preg_match('/^[A-Za-z0-9_-]{10,300}$/D',$token)||!is_scalar($chat)||trim((string)$chat)===''||!is_string($text)||trim($text)===''||mb_strlen($text)>4096)throw new RuntimeException('توکن، مقصد یا متن روبیکا معتبر نیست.');return ['url'=>'https://botapi.rubika.ir/v3/'.$token.'/sendMessage','body'=>['chat_id'=>(string)$chat,'text'=>$text]];}
    public static function receipt($status,$body){if($status!==200||!is_array($body)||(isset($body['status'])&&$body['status']!=='OK'))return null;$id=$body['data']['message_id']??$body['message_id']??null;return (is_int($id)&&$id>0)||(is_string($id)&&trim($id)!==''&&strlen($id)<300)?$id:null;}
}
