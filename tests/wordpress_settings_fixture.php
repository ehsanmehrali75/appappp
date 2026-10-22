<?php
/** Render-only WordPress doubles. No real WordPress connection or remote publication. */
define('ABSPATH',__DIR__);
function add_action(...$a){}function add_filter(...$a){}function register_activation_hook(...$a){}function register_deactivation_hook(...$a){}
function current_user_can($cap){return true;}function get_option($name,$default=[]){return $name==='maarksho_bridge_v5'?['read_token'=>str_repeat('fixture-',8),'kinds'=>['products','inventory','sales','posts','pages']]:$default;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function wp_nonce_field($name){echo '<input type="hidden" name="_wpnonce" value="fixture">';}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/../integrations/wordpress/maarksho-crm-sync/maarksho-crm-sync.php';
echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>';
Maarksho_Data_Bridge::page();echo '</body></html>';
