<?php
require_once __DIR__.'/ProjectPlans.php';
/** Resumable setup writes only project settings; imports and integration tests keep their existing engines. */
class ProjectOnboarding {
    public static function steps($p) {
        $s=$p['settings']??[];$products=ProjectPlans::allows('products',$s)&&AccessControl::moduleVisibility('products',$s)==='active';$out=['identity'=>['name'=>'کسب‌وکار و بستر','why'=>'نوع سیستم، مسیر اتصال موجودی و ابزارهای مناسب پروژه را مشخص می‌کند.']];
        if($products)$out['inventory']=['name'=>'محصولات و موجودی','why'=>'این منبع، مرجع موجودی و تنوع‌های رنگ و سایز است؛ عکس و توضیح سایت بعداً به آن اضافه می‌شوند.'];
        if(ProjectPlans::allows('extract',$s))$out['crawl']=['name'=>'اولین بررسی وب‌سایت','why'=>'تصاویر، توضیحات، دسته‌بندی و جدول سایز وب‌سایت برای مقایسه با کتابخانه دریافت می‌شوند. کراول جایگزین موجودی مرجع نیست.'];
        if(ProjectPlans::allows('ai_connect',$s))$out['ai']=['name'=>'ChatGPT و هوش مصنوعی','why'=>'برای پرسش از داده‌های همین پروژه و ثبت محتوا متصل شوید. API جداگانه فقط برای اجرای خودکار و گزارش‌های زمان‌بندی‌شده هزینه دارد.'];
        if(ProjectPlans::allows('calendar',$s))$out['social']=['name'=>'شبکه‌های اجتماعی','why'=>'کانال‌های مورد استفاده و روش انتشار را مشخص کنید تا تقویم محتوا به مقصد درست وصل شود.'];
        if(ProjectPlans::allows('seo_intel',$s)||ProjectPlans::allows('seo_classic',$s))$out['seo']=['name'=>'سئو و آمار سایت','why'=>'Search Console و GA4، جست‌وجو و بازدید را به اطلاعات فروش و محصولات اضافه می‌کنند. دسترسی حساب گوگل و دریافت واقعی هر منبع جداگانه تست می‌شود.'];
        if($products)$out['workflow']=['name'=>'تیم و گردش تأیید','why'=>'نیاز به عکس رفرنس، درخواست تولید و ترتیب تأیید تصویر را مطابق کار تیم تنظیم کنید.'];
        $out['review']=['name'=>'مرور و شروع کار','why'=>'وضعیت تنظیمات، اتصال‌های تست‌شده و موارد باقی‌مانده را ببینید. هر بخش را بعداً هم می‌توانید تکمیل کنید.'];return $out;
    }
    public static function url($id,$step='') {return '/panel/onboarding.php?project='.rawurlencode($id).($step!==''?'&step='.rawurlencode($step):'');}
    public static function landing($fallback) {
        $id=$_SESSION['purchase_project_id']??'';unset($_SESSION['purchase_project_id']);
        if($id){$p=getCurrentProject($id,true);if($p&&SessionIdentity::select($p)){unset($_SESSION['purchase_project_id']);return self::url($id);}}
        $p=getCurrentProject();$s=$p['settings']['onboarding']??[];
        if($p&&!empty($s['created_at'])&&empty($s['started_at'])&&empty($s['paused_at'])&&empty($s['finished_at'])&&(isMainAdmin()||isProjectAdmin()||hasPermission('settings','use')))return self::url($p['id']);
        return $fallback;
    }
    public static function website($url) {
        $url=trim((string)$url);if($url==='')return '';
        if(!preg_match('~^https?://~i',$url))$url='https://'.$url;
        $v=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($v['scheme']??'',['http','https'],true)||empty($v['host'])||isset($v['user'])||isset($v['pass'])||isset($v['fragment'])||isset($v['query'])||!in_array((int)($v['port']??443),[80,443],true)||strtolower($v['host'])==='localhost'||strpos($v['host'],'.')===false||(filter_var($v['host'],FILTER_VALIDATE_IP)&&!filter_var($v['host'],FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)))throw new RuntimeException('آدرس عمومی وب‌سایت را بدون نام کاربری، رمز یا پارامتر وارد کنید.');
        return rtrim($url,'/');
    }
    public static function mutate($id,$fn) {
        if(isSqlActive()){
            $c=getConn();$c->begin_transaction();try{$q=$c->prepare('SELECT name, settings FROM projects WHERE id = ?'.($c instanceof SQLite3ToMysqliWrapper?'':' FOR UPDATE'));$q->bind_param('s',$id);$q->execute();$row=$q->get_result()->fetch_assoc();if(!$row)throw new RuntimeException('پروژه یافت نشد.');$p=['id'=>$id,'name'=>$row['name'],'settings'=>json_decode($row['settings'],true)?:[]];$result=$fn($p);$json=json_encode($p['settings'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$q=$c->prepare('UPDATE projects SET name = ?, settings = ? WHERE id = ?');$q->bind_param('sss',$p['name'],$json,$id);if(!$q->execute())throw new RuntimeException('ثبت تنظیمات ناموفق بود.');$c->commit();}catch(Throwable $e){$c->rollback();throw $e;}
        }else{$db=getJsonDB();$found=false;foreach($db['projects'] as &$p)if($p['id']===$id){$result=$fn($p);$found=true;break;}unset($p);if(!$found)throw new RuntimeException('پروژه یافت نشد.');saveJsonDB($db);}
        if(class_exists('MaarkshoCache'))MaarkshoCache::delete('project_full_'.$id);return $result;
    }
    public static function save($project,$step,$input,$action='save') {
        if(!isset(self::steps($project)[$step]))throw new RuntimeException('مرحله معتبر نیست.');
        return self::mutate($project['id'],function(&$p)use($step,$input,$action){
            $state=$p['settings']['onboarding']??['created_at'=>time(),'revision'=>0,'steps'=>[]];
            if((int)($input['revision']??-1)!==(int)($state['revision']??0))throw new RuntimeException('تنظیمات در صفحهٔ دیگری تغییر کرده است؛ صفحه را تازه کنید.');
            if($action==='save'){
                if($step==='identity'){
                    $platform=$input['platform_type']??'';if(!in_array($platform,['wordpress','tiam','custom'],true))throw new RuntimeException('بستر پروژه را انتخاب کنید.');
                    $name=trim($input['project_name']??'');if($name===''||mb_strlen($name)>150)throw new RuntimeException('نام پروژه را وارد کنید.');
                    $p['name']=$name;$p['settings']['platform_type']=$platform;$p['settings']['sourceUrl']=self::website($input['website_url']??'');
                    if($platform==='wordpress')$p['settings']['wordpress']['site_url']=$p['settings']['sourceUrl'];
                }elseif($step==='inventory'){
                    if(!in_array($input['inventory_method']??'',['automatic','manual'],true))throw new RuntimeException('روش دریافت موجودی را انتخاب کنید.');
                    $p['settings']['onboarding_inventory_method']=$input['inventory_method'];
                }elseif($step==='crawl'){
                    if(!in_array($input['crawl_method']??'',['desktop','server','later'],true))throw new RuntimeException('روش دریافت سایت را انتخاب کنید.');$p['settings']['onboarding_crawl_method']=$input['crawl_method'];
                }elseif($step==='ai'){
                    $mode=$input['ai_mode']??'none';if(!in_array($mode,['none','chatgpt','chatgpt_api'],true))throw new RuntimeException('روش اتصال هوش مصنوعی معتبر نیست.');$p['settings']['onboarding_ai_mode']=$mode;
                }elseif($step==='social'){
                    $channels=$input['social_channels']??[];if(!is_array($channels)||array_diff($channels,['instagram','telegram','bale','wordpress']))throw new RuntimeException('شبکه معتبر نیست.');$p['settings']['onboarding_social_channels']=array_values(array_unique($channels));
                }elseif($step==='seo'){$p['settings']['onboarding_seo_requested']=isset($input['seo_requested']);
                }elseif($step==='workflow'){
                    foreach(['prod_require_reference','prod_require_image_request','prod_approval_split_enabled'] as $k)$p['settings'][$k]=isset($input[$k])?1:0;
                    $order=$input['prod_approval_order']??'parallel';if(!in_array($order,['parallel','style_first','conformity_first'],true))throw new RuntimeException('ترتیب تأیید معتبر نیست.');
                    if(($p['settings']['prod_approval_order']??'parallel')!==$order)$p['settings']['prod_approval_sequence_since']=time();$p['settings']['prod_approval_order']=$order;
                }
                $state['steps'][$step]=['state'=>'configured','at'=>time()];
            }elseif($action==='skip')$state['steps'][$step]=['state'=>'skipped','at'=>time()];
            elseif($action==='finish')$state['finished_at']=time();
            elseif($action==='pause')$state['paused_at']=time();
            else throw new RuntimeException('عملیات معتبر نیست.');
            $state['started_at']=$state['started_at']??time();$keys=array_keys(self::steps($p));$state['current_step']=in_array($action,['save','skip'],true)?$keys[min(array_search($step,$keys,true)+1,count($keys)-1)]:$step;$state['revision']=(int)($state['revision']??0)+1;$p['settings']['onboarding']=$state;return $state;
        });
    }
    public static function crawlStatus($pid) {
        $counts=['pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'cancelled'=>0];
        if(isSqlActive()){$q=getConn()->prepare("SELECT status, COUNT(*) AS total FROM scraping_jobs WHERE project_id = ? AND type IN ('onboarding_discovery','onboarding_category','onboarding_product') GROUP BY status");$q->bind_param('s',$pid);$q->execute();$rows=$q->get_result();while($row=$rows->fetch_assoc())if(isset($counts[$row['status']]))$counts[$row['status']]=(int)$row['total'];}
        else foreach(getJsonDB()['scraping_jobs']??[] as $job)if(($job['project_id']??'')===$pid&&strpos($job['type']??'','onboarding_')===0&&isset($counts[$job['status']??'']))$counts[$job['status']]++;
        return $counts;
    }
    public static function queue($project) {
        if(!ProjectPlans::allows('extract',$project['settings'])||!canAccessFeature('extract','use'))throw new RuntimeException('دسترسی کراول در این پروژه فعال نیست.');
        $url=self::website($project['settings']['sourceUrl']??'');if($url==='')throw new RuntimeException('ابتدا آدرس سایت را ذخیره کنید.');
        // Use the existing discovery queue; no crawling runs in this browser request.
        if(isSqlActive()){$c=getConn();$q=$c->prepare("SELECT id FROM scraping_jobs WHERE project_id = ? AND url = ? AND status IN ('pending','processing') LIMIT 1");$q->bind_param('ss',$project['id'],$url);$q->execute();if($q->get_result()->fetch_assoc())return false;$type='onboarding_discovery';$q=$c->prepare("INSERT INTO scraping_jobs(project_id,url,type,status) VALUES (?,?,?,'pending')");$q->bind_param('sss',$project['id'],$url,$type);if(!$q->execute())throw new RuntimeException('ثبت صف ناموفق بود.');}
        else{$db=getJsonDB();foreach($db['scraping_jobs']??[] as $j)if(($j['project_id']??'')===$project['id']&&($j['url']??'')===$url&&in_array($j['status']??'',['pending','processing'],true))return false;$db['scraping_jobs'][]=['id'=>bin2hex(random_bytes(12)),'project_id'=>$project['id'],'url'=>$url,'type'=>'onboarding_discovery','status'=>'pending','created_at'=>date('Y-m-d H:i:s')];saveJsonDB($db);}return true;
    }
}
