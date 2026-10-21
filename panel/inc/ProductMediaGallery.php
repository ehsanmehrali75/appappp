<?php
class ProductMediaGallery {
    public static function urls($p){
        $urls=[];$add=static function($item)use(&$urls){$url=ProductSizeChartCatalog::entryUrl($item);if($url!==''&&isValidProductImage($url))$urls[$url]=true;};
        $add($p['image']??'');foreach(['gallery','images_list','images'] as $key){$items=$p[$key]??[];if(is_string($items))$items=json_decode($items,true);if(is_array($items))foreach($items as $item)$add($item);}
        $add($p['social_image']??$p['socialImage']??'');$items=$p['reference_gallery']??[];if(is_string($items))$items=json_decode($items,true);foreach(is_array($items)?$items:[] as $item)$add($item);
        return array_keys($urls);
    }
    public static function render($p){
        $urls=self::urls($p);if(!$urls)$urls=['https://placehold.co/400x500?text=Maarksho'];
        echo '<div class="product-media-carousel" data-media-gallery dir="ltr"><div class="product-media-track" tabindex="0" aria-label="تصاویر محصول">';
        foreach($urls as $i=>$url)echo '<img src="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" loading="lazy" referrerpolicy="no-referrer" alt="'.htmlspecialchars(($p['name']??'محصول').' — '.($i===0?'تصویر اصلی':'تصویر '.($i+1)),ENT_QUOTES,'UTF-8').'">';
        echo '</div>';if(count($urls)>1)echo '<div class="product-media-controls"><button type="button" data-media-step="-1" aria-label="تصویر قبلی">‹</button><span data-media-count>۱ / '.count($urls).'</span><button type="button" data-media-step="1" aria-label="تصویر بعدی">›</button></div>';echo '</div>';
    }
}
