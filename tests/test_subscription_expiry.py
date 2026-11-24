"""Account-backed project expiry: actual settings POST, shared period and stale-form safety."""
import json,re,html,subprocess,unittest
from test_saas_accounts import SaaSAccounts as Base
from test_http_security import PHP
class SubscriptionExpiry(unittest.TestCase):
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    php=Base.php
    def raw(self,code):
        r=subprocess.run([PHP,'-r',"require $argv[1].'/panel/inc/ai/Bootstrap.php';SessionIdentity::beginGlobal('fixture_owner');$svc=new SaaSAccounts();"+code,str(self.case.app)],capture_output=True,text=True,timeout=30)
        self.assertEqual(r.returncode,0,r.stderr+r.stdout);return json.loads(r.stdout)
    def form(self):
        opener,csrf=self.case.login();status,page=self.case.request(opener,'/panel/admin.php?tab=settings');self.assertEqual(status,200)
        fields={'save_settings':'1','app_csrf':csrf,'_project_context':'p'}
        for name in ['active_to','active_to_original','subscription_expiry_version']:
            match=re.search(r'<input[^>]*name="'+name+r'"[^>]*value="([^"]*)"',page)
            if match:fields[name]=html.unescape(match[1])
        return opener,fields
    def test_real_settings_post_extends_shared_account_and_survives_reload(self):
        setup=self.php("$second=$svc->createProject($id,'Second project','expiry-second');echo json_encode([$id,$second,$svc->account($id)['wallets']]);")
        opener,fields=self.form();fields['active_to']='2027-03-21'
        status,page=self.case.request(opener,'/panel/admin.php?tab=settings',fields)
        self.assertEqual(status,200);self.assertRegex(page,r'name="active_to" value="2027-03-21"')
        r=self.raw("$a=SaaSAccounts::projectAccount('p');$dates=[];foreach(array_keys($a['projects']) as $pid)$dates[]=getCurrentProject($pid,true)['activeTo'];$audit=array_values(array_filter($svc->state()['audit'],fn($e)=>$e['action']==='account_expiry_changed'));echo json_encode([$dates,(new DateTimeImmutable('@'.$a['expires_at']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s'),$a['wallets'],count($audit)]);")
        self.assertEqual(r,[['2027-03-21','2027-03-21'],'2027-03-21 23:59:59',setup[2],1])
    def test_expired_account_can_be_manually_extended_without_buying_or_charging(self):
        r=self.php("$old=time()-3600;$svc->change(function(&$s)use($id,$old){$s['accounts'][$id]['expires_at']=$old;});$before=$svc->account($id);$svc->setProjectExpiry('p','2027-01-31',$old);$after=$svc->account($id);echo json_encode([SaaSAccounts::active($after),$before['wallets']===$after['wallets'],$before['limits']===$after['limits'],empty($svc->state()['orders'])]);")
        self.assertEqual(r,[True,True,True,True])
    def test_project_admin_cannot_grant_free_subscription_time(self):
        r=self.php("$old=$svc->account($id)['expires_at'];SessionIdentity::beginPassword('customer','Customer-pass-123!','p');SessionIdentity::select(getCurrentProject('p',true));$blocked=false;try{$svc->setProjectExpiry('p','2027-01-31',$old);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,SaaSAccounts::projectAccount('p')['expires_at']===$old]);")
        self.assertEqual(r,[True,True])
    def test_invalid_calendar_dates_do_not_change_the_account(self):
        r=self.php("$old=$svc->account($id)['expires_at'];$blocked=0;foreach(['2027-02-30','2027-13-01','2027-2-01','', '0000-01-01'] as $date){try{$svc->setProjectExpiry('p',$date,$old);}catch(RuntimeException $e){$blocked++;}}echo json_encode([$blocked,$svc->account($id)['expires_at']===$old]);")
        self.assertEqual(r,[5,True])
    def test_stale_manual_request_cannot_overwrite_a_paid_renewal(self):
        r=self.php("$old=$svc->account($id)['expires_at'];$new=$old+30*86400;$svc->change(function(&$s)use($id,$new){$s['accounts'][$id]['expires_at']=$new;});$blocked=false;try{$svc->setProjectExpiry('p','2027-01-31',$old);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$svc->account($id)['expires_at']===$new]);")
        self.assertEqual(r,[True,True])
    def test_unchanged_date_on_an_old_settings_form_preserves_new_account_expiry(self):
        self.php("echo json_encode(true);")
        opener,fields=self.form()
        new=self.raw("$a=SaaSAccounts::projectAccount('p');$new=$a['expires_at']+30*86400;$svc->change(function(&$s)use($a,$new){$s['accounts'][$a['id']]['expires_at']=$new;});echo json_encode($new);")
        self.assertEqual(self.case.request(opener,'/panel/admin.php?tab=settings',fields)[0],200)
        self.assertEqual(self.raw("echo json_encode(SaaSAccounts::projectAccount('p')['expires_at']);"),new)
    def test_changed_date_on_an_old_form_is_rejected(self):
        self.php("echo json_encode(true);");opener,fields=self.form();fields['active_to']='2027-03-21'
        new=self.raw("$a=SaaSAccounts::projectAccount('p');$new=$a['expires_at']+30*86400;$svc->change(function(&$s)use($a,$new){$s['accounts'][$a['id']]['expires_at']=$new;});echo json_encode($new);")
        status,page=self.case.request(opener,'/panel/admin.php?tab=settings',fields)
        self.assertEqual(status,200);self.assertIn('مدت اشتراک تغییر کرده است',page)
        self.assertEqual(self.raw("echo json_encode(SaaSAccounts::projectAccount('p')['expires_at']);"),new)
    def test_legacy_project_date_still_saves_without_creating_an_account(self):
        opener,fields=self.form();fields['active_to']='2031-02-03'
        status,page=self.case.request(opener,'/panel/admin.php?tab=settings',fields)
        self.assertEqual(status,200);self.assertRegex(page,r'name="active_to" value="2031-02-03"')
        self.assertEqual(self.raw("echo json_encode([getCurrentProject('p',true)['activeTo'],SaaSAccounts::projectAccount('p')]);"),['2031-02-03',None])
    def test_failed_project_save_does_not_extend_the_account(self):
        import sqlite3
        old=self.php("echo json_encode($svc->account($id)['expires_at']);");opener,fields=self.form();fields['active_to']='2027-03-21'
        def cleanup():
            with sqlite3.connect(self.case.database) as db:db.execute('DROP TRIGGER IF EXISTS expiry_save_failure')
        self.addCleanup(cleanup)
        with sqlite3.connect(self.case.database) as db:db.execute("CREATE TRIGGER expiry_save_failure BEFORE UPDATE ON projects BEGIN SELECT RAISE(FAIL,'fixture save failure'); END")
        status,page=self.case.request(opener,'/panel/admin.php?tab=settings',fields)
        self.assertEqual(status,200);self.assertTrue('ذخیرهٔ تنظیمات ناموفق بود' in page,'Expected explicit settings-save failure message')
        self.assertEqual(self.raw("echo json_encode(SaaSAccounts::projectAccount('p')['expires_at']);"),old)
    def test_failed_commit_does_not_extend_the_account(self):
        import sqlite3
        old=self.php("echo json_encode($svc->account($id)['expires_at']);");opener,fields=self.form();fields['active_to']='2027-03-21'
        def cleanup():
            with sqlite3.connect(self.case.database) as db:
                db.execute('DROP TRIGGER IF EXISTS expiry_commit_failure');db.execute('DROP TABLE IF EXISTS expiry_commit_guard')
        self.addCleanup(cleanup)
        with sqlite3.connect(self.case.database) as db:
            db.execute('CREATE TABLE expiry_commit_guard (project_id TEXT REFERENCES projects(id) DEFERRABLE INITIALLY DEFERRED)')
            db.execute("CREATE TRIGGER expiry_commit_failure AFTER UPDATE ON projects BEGIN INSERT INTO expiry_commit_guard VALUES ('nonexistent-fixture-project'); END")
        status,page=self.case.request(opener,'/panel/admin.php?tab=settings',fields)
        self.assertEqual(status,200);self.assertTrue('ذخیرهٔ تنظیمات ناموفق بود' in page,'Expected explicit commit failure message')
        self.assertEqual(self.raw("echo json_encode(SaaSAccounts::projectAccount('p')['expires_at']);"),old)

del Base
