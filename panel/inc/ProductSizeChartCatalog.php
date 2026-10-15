<?php
/** Read only the current project's explicitly linked Size Studio chart. */
class ProductSizeChartCatalog {
    /** Imported gallery entries may store a URL together with media metadata. */
    public static function entryUrl($value) {
        if (is_array($value)) $value=$value['url']??$value['src']??$value['image']??'';
        return self::mediaUrl($value);
    }
    public static function mediaUrl($value) {
        if (!is_string($value)) return '';
        $value=trim($value);
        if ($value==='' || preg_match('~(?:placehold|[\x00-\x20\\\\])~i',$value)) return '';
        if (preg_match('~^https?://~i',$value)) {
            $p=parse_url($value);
            return filter_var($value,FILTER_VALIDATE_URL)&&!isset($p['user'])&&!isset($p['pass'])?$value:'';
        }
        if (strpos($value,'//')===0 || strpos($value,'..')!==false) return '';
        if (strpos($value,'uploads/')===0) return '/panel/'.$value;
        return preg_match('~^/(?:panel/)?uploads/~',$value)?$value:'';
    }
    public static function forProduct($project,$product) {
        $id=(string)($product['size_chart_template_id']??'');$chart=null;
        foreach(($project['settings']['size_charts']??[]) as $candidate) {
            if ($id!=='' && is_array($candidate) && (string)($candidate['id']??'')===$id) {$chart=$candidate;break;}
        }
        $direct=self::mediaUrl($product['size_chart']??$product['sizeChart']??'');
        $studioImage=self::mediaUrl($chart['image_path']??'');
        $data=$chart && !empty($chart['columns']) && !empty($chart['rows']) && is_array($chart['columns']) && is_array($chart['rows']);
        $image=$studioImage?:$direct;
        $exists=$image!=='' || $data;
        $meta=$product['website_sync']??[];
        if(is_string($meta))$meta=json_decode($meta,true);
        $siteUrls=is_array($meta)?($meta['size_guide_urls']??[]):[];
        if(is_string($siteUrls))$siteUrls=json_decode($siteUrls,true);
        $siteUrls=is_array($siteUrls)&&$siteUrls===array_values($siteUrls)?array_values(array_filter(array_map([self::class,'mediaUrl'],$siteUrls))):[];
        $siteOnly=$image!=='' && in_array($image,$siteUrls,true) && !$data && $studioImage==='';
        return ['exists'=>$exists,'has_local'=>$exists&&!$siteOnly,'image_url'=>$image,'chart_id'=>$chart?$id:'','source'=>$chart?'studio':($siteOnly?'site':'product'),
            'columns'=>$data?$chart['columns']:[],'rows'=>$data?$chart['rows']:[],
            'studio_url'=>'/panel/size/?project='.rawurlencode((string)($project['id']??'')).($chart?'&chart_id='.rawurlencode($id):'&product_id='.rawurlencode((string)($product['id']??''))),
            'export_ready'=>$image!==''];
    }
}
