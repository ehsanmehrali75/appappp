<?php
/** URL-scoped reviews. Original files and the rejected images remain in the library. */
class ProductImageReview {
    public static function enabled($p) { return isset($p['website_sync']['photo_reviews']); }
    public static function key($url) { return hash('sha256', $url); }
    public static function revision($p) {
        return hash('sha256', json_encode([WebsiteCatalogSync::photos($p), $p['website_sync']['photo_reviews'] ?? null, $p['images_list'] ?? [], $p['status'] ?? '', $p['extractedAt'] ?? 0]));
    }
    public static function rows($p) {
        $out=[];
        foreach(WebsiteCatalogSync::photos($p) as $url) {
            $key=self::key($url);$saved=$p['website_sync']['photo_reviews']??[];$row=$saved[$key]??null;
            // A backup restore relocates local files and rewrites URL values, not hashed keys.
            if(!is_array($row))foreach($saved as $candidate)if(is_array($candidate)&&ProductSizeChartCatalog::mediaUrl($candidate['url']??'')===$url){$row=$candidate;break;}
            if(!is_array($row)) {
                // Once individual review starts, newly added URLs must earn their own approval.
                $baseline=$p;if(self::enabled($p)){unset($baseline['website_sync']['photo_reviews']);$baseline['status']='pending';}
                $approved=WebsiteCatalogSync::imageApproved($baseline,$url);
                $row=['state'=>$approved?'approved':(!self::enabled($p)&&($p['status']??'')==='rejected'?'rejected':'pending'),'stages'=>[]];
            }
            $out[$key]=$row+['url'=>$url];
        }
        return $out;
    }
    public static function aggregate(&$p) {
        $states=array_column(self::rows($p),'state');
        $p['status']=in_array('approved',$states,true)?'active':(in_array('pending',$states,true)?'pending':($states?'rejected':'no_image'));
    }
    private static function available($row,$settings,$stage,$user) {
        if(!hasPermission('approve_content','use')||$row['state']!=='pending')return false;
        if(empty($settings['prod_approval_split_enabled']))return $stage===0;
        if(!in_array($stage,[1,2],true)||!ProductApprovalFlow::responsible($settings,$stage,$user))return false;
        if(($row['stages'][$stage]['state']??'')==='approved')return false;
        if(ProductApprovalFlow::enabled($settings)) {
            $order=ProductApprovalFlow::order($settings);
            if($stage===$order[1]&&($row['stages'][$order[0]]['state']??'')!=='approved')return false;
        }
        return true;
    }
    public static function view($p,$project) {
        $settings=$project['settings'];$user=$_SESSION['user']['username']??'';$rows=[];
        foreach(self::rows($p) as $key=>$row) {
            $row['key']=$key;$row['available']=[];
            foreach(empty($settings['prod_approval_split_enabled'])?[0]:[1,2] as $stage)
                if(self::available($row,$settings,$stage,$user))$row['available'][]=$stage;
            $rows[]=$row;
        }
        return ['revision'=>self::revision($p),'rows'=>$rows,'split'=>!empty($settings['prod_approval_split_enabled']),'status'=>$p['status']??''];
    }
    public static function decide(&$p,$project,$key,$stage,$reject,$reason,$revision) {
        if(!hasPermission('approve_content','use'))throw new RuntimeException('مجوز بررسی تصاویر را ندارید.');
        if(!is_string($key)||!is_string($revision)||!hash_equals(self::revision($p),$revision))throw new RuntimeException('تصاویر یا بررسی‌ها تغییر کرده‌اند؛ گالری را دوباره باز کنید.');
        if(!is_string($reason)||mb_strlen($reason)>2000||($reject&&trim($reason)===''))throw new RuntimeException('برای رد تصویر توضیح بنویسید (حداکثر ۲۰۰۰ نویسه).');
        $rows=self::rows($p);$keys=$key==='all'?array_keys($rows):[$key];$user=$_SESSION['user']['username']??'';$changed=0;
        foreach($keys as $id) {
            if(!isset($rows[$id]))throw new RuntimeException('تصویر متعلق به این محصول نیست.');
            $row=$rows[$id];
            if(!self::available($row,$project['settings'],$stage,$user)) {
                if($key==='all')continue;
                throw new RuntimeException('این تصویر یا مرحله قابل بررسی نیست؛ تصویر ردشده باید اصلاح و جایگزین شود.');
            }
            $row['by']=$user;$row['at']=time();$row['reason']=trim($reason);
            $row['history'][]=['by'=>$user,'at'=>time(),'stage'=>$stage,'decision'=>$reject?'rejected':'approved','reason'=>trim($reason)];
            if($reject)$row['state']='rejected';
            elseif($stage===0)$row['state']='approved';
            else {
                $votes=array_values(array_unique(array_merge($row['stages'][$stage]['users']??[],[$user])));
                $row['stages'][$stage]=['users'=>$votes,'state'=>count($votes)>=max(1,(int)($project['settings']['prod_approval_stage'.$stage.'_min']??1))?'approved':'pending'];
                if(($row['stages'][1]['state']??'')==='approved'&&($row['stages'][2]['state']??'')==='approved')$row['state']='approved';
            }
            $rows[$id]=$row;$changed++;
        }
        if(!$changed)throw new RuntimeException('تصویر قابل بررسی در این مرحله وجود ندارد.');
        $p['website_sync']['photo_reviews']=array_replace($p['website_sync']['photo_reviews']??[],$rows);
        self::aggregate($p);
    }
}
