<?php
/** Support workflow, isolated from the legacy customer payment/receipt workflow. */
class SupportAccessDenied extends RuntimeException {}

class SupportTickets {
    private $db; private $dir; private $clock;
    const STATUSES = ['pending_review'=>'در انتظار بررسی','waiting_approval'=>'در انتظار تأیید هزینه','approved'=>'هزینه تأیید شده / آمادهٔ اجرا','queued'=>'در صف اجرا','in_progress'=>'در حال اجرا','answered'=>'پاسخ داده شده','resolved'=>'حل شده','cancelled'=>'لغو شده'];
    const CATEGORIES = ['access'=>'ورود و دسترسی','tiam'=>'اتصال تیام و موجودی','size_chart'=>'استودیو جدول سایز','bug'=>'گزارش خطا','feature'=>'تغییر یا قابلیت جدید','billing'=>'مالی و هزینه‌ها','other'=>'سایر موارد'];
    public function __construct($db = null, $dir = null, $clock = null) {
        $this->db = $db ?: getConn();
        if (!$this->db) throw new RuntimeException('پایگاه دادهٔ پشتیبانی در دسترس نیست.');
        $this->dir = $dir ?: (getenv('SUPPORT_STORAGE_DIR') ?: dirname(__DIR__, 3) . '/.maarksho-support-' . substr(hash('sha256', __DIR__),0,12));
        if (!is_dir($this->dir) && !mkdir($this->dir,0700,true)) throw new RuntimeException('فضای خصوصی پیوست‌ها قابل نوشتن نیست.');
        $this->clock = $clock ?: fn()=>time();
        $this->exec('CREATE TABLE IF NOT EXISTS support_tickets (id VARCHAR(64) PRIMARY KEY, project_id VARCHAR(128) NOT NULL, creator VARCHAR(100) NOT NULL, subject VARCHAR(255) NOT NULL, category VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, quote_amount BIGINT DEFAULT 0, quote_version INT DEFAULT 0, quote_expires_at BIGINT DEFAULT 0, accepted_at BIGINT DEFAULT 0, estimate TEXT, finish_at BIGINT DEFAULT 0, cancel_reason VARCHAR(50), created_at BIGINT, updated_at BIGINT)');
        $this->exec('CREATE TABLE IF NOT EXISTS support_messages (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id VARCHAR(64) NOT NULL, sender VARCHAR(100) NOT NULL, sender_type VARCHAR(20) NOT NULL, body TEXT NOT NULL, attachments TEXT, created_at BIGINT)');
    }
    public function now() { return ($this->clock)(); }
    public function directory() { return $this->dir; }
    private function statement($sql,$params=[]) {
        $stmt=$this->db->prepare($sql); if(!$stmt) throw new RuntimeException('آماده‌سازی پایگاه دادهٔ پشتیبانی ناموفق بود.');
        if($params) { $types='';foreach($params as $p)$types.=is_int($p)?'i':'s'; $stmt->bind_param($types,...$params); }
        if(!$stmt->execute()) { $stmt->close(); throw new RuntimeException('عملیات پایگاه دادهٔ پشتیبانی ناموفق بود.'); }
        return $stmt;
    }
    private function exec($sql,$params=[]) {
        if(strpos($sql,'CREATE TABLE')===0){if(!$this->db->query($sql))throw new RuntimeException('ایجاد جدول پشتیبانی ناموفق بود.');return;}
        $s=$this->statement($sql,$params);$s->close();
    }
    private function rows($sql,$params=[]) { $s=$this->statement($sql,$params);$r=$s->get_result();$rows=[];while($r && $row=$r->fetch_assoc())$rows[]=$row;$s->close();return $rows; }
    private function locked($fn) {
        $f=fopen($this->dir.'/workflow.lock','c');if(!$f || !flock($f,LOCK_EX))throw new RuntimeException('قفل پشتیبانی در دسترس نیست.');
        try { if(!$this->db->begin_transaction())throw new RuntimeException('شروع تراکنش ناموفق بود.');$result=$fn();if(!$this->db->commit())throw new RuntimeException('ثبت تغییرات ناموفق بود.');return $result; }
        catch(Throwable $e){$this->db->rollback();throw $e;}
        finally{flock($f,LOCK_UN);fclose($f);}
    }
    private function expireLocked() {
        $now=$this->now();
        $this->exec("UPDATE support_tickets SET status='cancelled',cancel_reason='approval_timeout',updated_at=? WHERE status='waiting_approval' AND quote_expires_at<=?",[$now,$now]);
    }
    public function expire() { return $this->locked(function(){ $this->expireLocked(); }); }
    private function row($id) { $row=$this->rows('SELECT * FROM support_tickets WHERE id=?',[$id]);if(!$row)throw new RuntimeException('تیکت یافت نشد.');return $row[0]; }
    private function authorize($t,$actor,$admin,$project=null) {
        if(!$admin && ($t['creator']!==$actor || $t['project_id']!==$project))throw new SupportAccessDenied('دسترسی به این تیکت مجاز نیست.');
    }
    public function list($actor,$admin,$project=null,$page=1) {
        $this->expire();
        $limit=' LIMIT 50 OFFSET '.((max(1,min(10000,(int)$page))-1)*50);
        if($admin && !$project)return $this->rows('SELECT * FROM support_tickets ORDER BY updated_at DESC,id DESC'.$limit);
        if($admin)return $this->rows('SELECT * FROM support_tickets WHERE project_id=? ORDER BY updated_at DESC,id DESC'.$limit,[$project]);
        return $this->rows('SELECT * FROM support_tickets WHERE creator=? AND project_id=? ORDER BY updated_at DESC,id DESC'.$limit,[$actor,$project]);
    }
    public function get($id,$actor,$admin,$project=null) {
        $this->expire();$t=$this->row($id);$this->authorize($t,$actor,$admin,$project);
        $t['messages']=$this->rows('SELECT * FROM support_messages WHERE ticket_id=? ORDER BY id ASC',[$id]);
        foreach($t['messages'] as &$m)$m['attachments']=json_decode($m['attachments']?:'[]',true)?:[];unset($m);
        return $t;
    }
    private function message($id,$actor,$admin,$body,$files=[]) {
        if(mb_strlen($body)>20000 || (!$files && trim($body)===''))throw new RuntimeException('متن یا پیوست لازم است؛ حداکثر متن ۲۰۰۰۰ نویسه است.');
        $this->exec('INSERT INTO support_messages (ticket_id,sender,sender_type,body,attachments,created_at) VALUES (?,?,?,?,?,?)',[$id,$actor,$admin?'admin':'user',$body,json_encode($files,JSON_UNESCAPED_UNICODE),$this->now()]);
    }
    public function create($project,$actor,$subject,$category,$body,$files=[]) {
        $subject=trim($subject);if($subject==='' || mb_strlen($subject)>200 || !isset(self::CATEGORIES[$category]) || trim($body)==='')throw new RuntimeException('موضوع، دسته‌بندی و توضیحات معتبر لازم است.');
        return $this->locked(function()use($project,$actor,$subject,$category,$body,$files){
            $id='SUP-'.strtoupper(bin2hex(random_bytes(8)));$now=$this->now();
            $this->exec('INSERT INTO support_tickets (id,project_id,creator,subject,category,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$id,$project,$actor,$subject,$category,'pending_review',$now,$now]);
            $this->message($id,$actor,false,$body,$files);return $id;
        });
    }
    public function reply($id,$actor,$admin,$project,$body,$files=[]) {
        return $this->locked(function()use($id,$actor,$admin,$project,$body,$files){
            $this->expireLocked();$t=$this->row($id);$this->authorize($t,$actor,$admin,$project);
            if(in_array($t['status'],['cancelled','resolved'],true))throw new RuntimeException('تیکت بسته شده و قابل ویرایش نیست.');
            $this->message($id,$actor,$admin,$body,$files);
            $status=$t['status'];if(!$admin && $status==='answered')$status='pending_review';
            if($admin && $status==='pending_review')$status='answered';
            $this->exec('UPDATE support_tickets SET status=?,updated_at=? WHERE id=?',[$status,$this->now(),$id]);
        });
    }
    public function offer($id,$actor,$admin,$amount,$estimate,$finishAt) {
        if(!$admin)throw new SupportAccessDenied('فقط ادمین اصلی می‌تواند هزینه تعیین کند.');
        if(!preg_match('/^[0-9]{1,12}$/D',(string)$amount))throw new RuntimeException('مبلغ باید عدد صحیح و نامنفی به تومان باشد.');
        if(mb_strlen($estimate)>500 || (trim($estimate)==='' && !$finishAt) || ($finishAt && $finishAt<=$this->now()))throw new RuntimeException('زمان اجرا یا تاریخ اتمام معتبر وارد کنید.');
        return $this->locked(function()use($id,$actor,$amount,$estimate,$finishAt){
            $this->expireLocked();$t=$this->row($id);
            if(!in_array($t['status'],['pending_review','answered','waiting_approval'],true) || (int)$t['accepted_at']>0)throw new RuntimeException('در این وضعیت نمی‌توان پیشنهاد هزینه را تغییر داد.');
            $amount=(int)$amount;$version=(int)$t['quote_version']+1;$now=$this->now();$expiry=$amount>0?$now+48*3600:0;$status=$amount>0?'waiting_approval':'approved';
            $this->exec('UPDATE support_tickets SET quote_amount=?,quote_version=?,quote_expires_at=?,estimate=?,finish_at=?,status=?,updated_at=? WHERE id=?',[$amount,$version,$expiry,$estimate,(int)$finishAt,$status,$now,$id]);
            $this->message($id,$actor,true,($amount>0?'پیشنهاد شمارهٔ '.$version.'؛ هزینه: '.number_format($amount).' تومان؛ مهلت پذیرش ۴۸ ساعت.':'اجرای رایگان تأیید شد.').' زمان اجرا: '.$estimate.($finishAt?'؛ اتمام: '.(new DateTimeImmutable('@'.$finishAt))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i'):''));
        });
    }
    public function decide($id,$actor,$project,$version,$accept) {
        // Expiry is committed before a rejected/late decision can throw.
        $this->expire();
        return $this->locked(function()use($id,$actor,$project,$version,$accept){
            $t=$this->row($id);$this->authorize($t,$actor,false,$project);$now=$this->now();
            if($t['status']!=='waiting_approval' || (int)$t['quote_version']!==(int)$version || (int)$t['quote_expires_at']<=$now)throw new RuntimeException('پیشنهاد تغییر کرده یا مهلت پذیرش آن تمام شده است.');
            $this->exec('UPDATE support_tickets SET status=?,accepted_at=?,cancel_reason=?,updated_at=? WHERE id=?',[$accept?'approved':'cancelled',$accept?$now:0,$accept?'':'cost_rejected',$now,$id]);
            $this->message($id,$actor,false,$accept?'هزینهٔ پیشنهاد شمارهٔ '.$version.' به مبلغ '.number_format($t['quote_amount']).' تومان را پذیرفتم.':'هزینه را نپذیرفتم؛ درخواست لغو شد.');
        });
    }
    public static function allowedTransitions($status) {
        return ['pending_review'=>['answered','resolved','cancelled'],'answered'=>['pending_review','resolved','cancelled'],
            'approved'=>['queued','in_progress','answered','resolved','cancelled'],'queued'=>['in_progress','answered','resolved','cancelled'],
            'in_progress'=>['answered','resolved','cancelled'],'waiting_approval'=>['cancelled']][$status]??[];
    }
    public function transition($id,$actor,$admin,$status) {
        if(!$admin)throw new SupportAccessDenied('تغییر وضعیت فقط برای ادمین اصلی مجاز است.');
        return $this->locked(function()use($id,$actor,$status){
            $this->expireLocked();$t=$this->row($id);
            if(!in_array($status,self::allowedTransitions($t['status']),true))throw new RuntimeException('تغییر وضعیت مجاز نیست؛ اجرای هزینه‌دار نیاز به پذیرش کاربر دارد.');
            $reason=$status==='cancelled'?'admin_cancelled':($t['cancel_reason']??'');
            $this->exec('UPDATE support_tickets SET status=?,cancel_reason=?,updated_at=? WHERE id=?',[$status,$reason,$this->now(),$id]);
            $this->message($id,$actor,true,'وضعیت به «'.self::STATUSES[$status].'» تغییر کرد.');
        });
    }
}
