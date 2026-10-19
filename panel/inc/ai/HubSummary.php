<?php
/** Render source-backed widgets inside existing sales/SEO screens. Never merge overlapping sources. */
function maarkshoHubSummary($project,$domain){
    if(!is_array($project))return;require_once __DIR__.'/Bootstrap.php';
    $dir=getenv('MAARKSHO_AI_STATE_DIR')?:dirname(__DIR__,2).'/db/ai-private';
    if(!is_dir($dir))return;$hub=new AiHub(new AiStore());$sources=$hub->sources($project);$cards=[];
    foreach($sources as $source)foreach($source['kinds'] as $kind){if($domain==='sales'&&$kind!=='sales'||$domain==='seo'&&!in_array($kind,['posts','pages','products','gsc','ga4'],true))continue;$stats=$hub->stats($project,$source['id'],$kind);$cards[]=[$source,$kind,$stats];if(count($cards)>=8)break 2;}
    if(!$cards)return;$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    echo '<section style="margin:20px;padding:22px;background:#edf2ef;border:1px solid #d2dfd6;border-radius:20px;color:#294c3b"><h2 style="font-size:20px;font-weight:bold">داده‌های واقعی متصل به پروژه</h2><p>تفکیک منبع و فروشگاه حفظ شده؛ اعداد منابع مختلف با هم جمع نشده‌اند.</p><div style="display:flex;flex-wrap:wrap;gap:14px">';
    foreach($cards as [$source,$kind,$stats]){echo '<article style="padding:16px;border-radius:14px;background:white;flex:1;min-width:230px"><b>'.$h($source['name'].' / '.$source['store_id']).'</b><p>'.$h($kind).' · '.number_format($stats['records']).' رکورد</p>';
        foreach($stats['sales_by_currency_and_grain'] as $group=>$totals)echo '<p>'.$h($group).' · خالص '.number_format($totals['net'],2).' · مرجوعی '.number_format($totals['refund'],2).'</p>';
        if(in_array($kind,['posts','pages','products'],true))echo '<p>بدون عنوان SEO: '.$stats['seo_issues']['missing_seo_title'].' · بدون متا: '.$stats['seo_issues']['missing_seo_description'].' · Noindex: '.$stats['seo_issues']['noindex'].'</p>';
        echo '<small>آخرین دریافت: '.(!empty($source['last_received'][$kind])?$h(date('Y/m/d H:i',$source['last_received'][$kind])):'ثبت نشده').'</small><p><a href="/panel/data-hub.php?'.$h(http_build_query(['project'=>$project['id'],'source'=>$source['id'],'kind'=>$kind])).'">فیلتر، بررسی ردیف‌ها و منشأ ←</a></p></article>';
    }echo '</div></section>';
}
