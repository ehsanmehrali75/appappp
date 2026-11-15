"""External handoff is guarded locally; these tests never publish or call a social provider."""
import unittest
from test_real_connections import RealConnections as Base

class SocialDispatchGuards(unittest.TestCase):
    setUpClass=classmethod(Base.setUpClass.__func__)
    tearDownClass=classmethod(Base.tearDownClass.__func__)
    setUp=Base.setUp
    php=Base.php
    def social(self,code):
        return self.php("require_once $argv[1].'/panel/inc/ProjectOnboarding.php';ProjectOnboarding::mutate('p',function(&$p){$p['settings']['connections']['telegram']=['enabled'=>true,'chatgpt_destination'=>'fixture-channel-one','proxy'=>['bypass_method'=>'chatgpt']];});$p=getCurrentProject('p',true);$grant=AiAccess::principal($p);$grant['scopes']=['social.write'];$social=new AiSocial($store);$input=['request_id'=>'audit-social-job','platform'=>'telegram','kind'=>'post','caption'=>'Confirmed fixture text','media'=>[],'scheduled_at'=>time()-1];"+code)
    def test_destination_change_blocks_claim_even_with_cached_project(self):
        r=self.social("$job=$social->create($p,$grant,$input);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['connections']['telegram']['chatgpt_destination']='different-channel';});$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,$store->read()['social_jobs'][$job['id']]['state']]);")
        self.assertEqual(r,[True,'queued'])
    def test_restore_pause_blocks_claim(self):
        r=self.social("$job=$social->create($p,$grant,$input);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['backup_restore_paused']=true;});$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)
    def test_restore_epoch_blocks_old_queue_after_resuming(self):
        r=self.social("$job=$social->create($p,$grant,$input);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;$p['settings']['backup_restore_paused']=false;});$blocked=false;try{$social->claim(getCurrentProject('p',true),$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)
    def test_explicitly_disabled_route_is_not_available(self):
        r=self.social("$p['settings']['connections']['telegram']['enabled']=false;echo json_encode(AiSocial::route($p['settings'],'telegram'));")
        self.assertFalse(r)
    def test_missing_destination_does_not_queue_an_ambiguous_account(self):
        r=self.social("ProjectOnboarding::mutate('p',function(&$p){unset($p['settings']['connections']['telegram']['chatgpt_destination']);});$blocked=false;try{$social->create(getCurrentProject('p',true),$grant,$input);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$blocked,count($store->read()['social_jobs']??[])]);")
        self.assertEqual(r,[True,0])
    def test_expired_handoff_is_uncertain_and_late_receipt_is_only_evidence(self):
        r=self.social("$job=$social->create($p,$grant,$input);$claim=$social->claim($p,$grant,$job['id']);$store->transaction(function(&$s)use($job){$s['social_jobs'][$job['id']]['lease_until']=time()-1;});$result=$social->ack($p,$grant,['id'=>$job['id'],'lease'=>$claim['lease'],'state'=>'published','receipt'=>'external-real-receipt-fixture']);$row=$store->read()['social_jobs'][$job['id']];echo json_encode([$result['state'],$row['state'],$row['late_receipt']['state']??null]);")
        self.assertEqual(r,['uncertain','uncertain','published'])
    def test_repeated_claim_maintenance_never_requeues_an_expired_handoff(self):
        r=self.social("$job=$social->create($p,$grant,$input);$social->claim($p,$grant,$job['id']);$store->transaction(function(&$s)use($job){$s['social_jobs'][$job['id']]['lease_until']=time()-20;});$one=$social->reconcileStale();$two=$social->reconcileStale();$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode([$one,$two,$blocked,$store->read()['social_jobs'][$job['id']]['state']]);")
        self.assertEqual(r,[1,0,True,'uncertain'])
    def test_selected_approved_photo_is_allowed_when_a_sibling_was_rejected(self):
        r=self.social("$a='https://shop.fixture/front.jpg';$b='https://shop.fixture/back.jpg';foreach($p['products'] as &$prod)if($prod['id']==='one'){$prod['image']=$a;$prod['gallery']=[$b];$prod['website_sync']['photo_reviews']=[ProductImageReview::key($a)=>['url'=>$a,'state'=>'rejected'],ProductImageReview::key($b)=>['url'=>$b,'state'=>'approved']];}unset($prod);saveProject($p,$p['products']);$input['product_id']='one';$input['media']=[$b];$allowed=false;try{$social->create(getCurrentProject('p',true),$grant,$input);$allowed=true;}catch(RuntimeException $e){}echo json_encode($allowed);")
        self.assertTrue(r)
    def test_withdrawn_photo_approval_blocks_a_previously_queued_job(self):
        r=self.social("$url='https://shop.fixture/front.jpg';foreach($p['products'] as &$prod)if($prod['id']==='one'){$prod['image']=$url;$prod['gallery']=[];$prod['images_list']=[['url'=>$url,'source'=>'manual','approved'=>true]];}unset($prod);saveProject($p,$p['products']);$input['product_id']='one';$input['media']=[$url];$job=$social->create(getCurrentProject('p',true),$grant,$input);$changed=getCurrentProject('p',true);foreach($changed['products'] as &$prod)if($prod['id']==='one')$prod['website_sync']['photo_reviews']=[ProductImageReview::key($url)=>['url'=>$url,'state'=>'rejected']];unset($prod);saveProject($changed,$changed['products']);$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)


    def test_schedule_edit_revokes_a_queued_handoff(self):
        r=self.social("$schedule=['id'=>'audit-schedule','platform'=>'telegram','caption'=>'Original','mediaUrl'=>'','scheduledAt'=>time()-10,'approvalStatus'=>'approved','status'=>'pending'];saveProject($p,null,[$schedule]);$p=getCurrentProject('p',true);$job=$social->enqueue($p,array_merge($input,['schedule_id'=>'audit-schedule']));$schedule['caption']='Changed after approval';saveProject($p,null,[$schedule]);$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)
    def test_old_epoch_receipt_cannot_mark_a_restored_schedule_complete(self):
        r=self.social("$schedule=['id'=>'audit-schedule','platform'=>'telegram','caption'=>'Original','mediaUrl'=>'','scheduledAt'=>time()-10,'approvalStatus'=>'approved','status'=>'pending'];saveProject($p,null,[$schedule]);$p=getCurrentProject('p',true);$job=$social->enqueue($p,array_merge($input,['schedule_id'=>'audit-schedule']));$claim=$social->claim($p,$grant,$job['id']);$social->ack($p,$grant,['id'=>$job['id'],'lease'=>$claim['lease'],'state'=>'published','receipt'=>'fixture-provider-receipt']);ProjectOnboarding::mutate('p',function(&$p){$p['settings']['data_recovery_revision']=1;});$social->reconcile(getCurrentProject('p',true));echo json_encode(getCurrentProject('p',true)['schedules'][0]['status']);")
        self.assertEqual(r,'pending')
    def test_unbound_legacy_queue_requires_reapproval(self):
        r=self.social("$job=$social->create($p,$grant,$input);$store->transaction(function(&$s)use($job){unset($s['social_jobs'][$job['id']]['recovery_revision'],$s['social_jobs'][$job['id']]['destination_fingerprint']);});$blocked=false;try{$social->claim($p,$grant,$job['id']);}catch(RuntimeException $e){$blocked=true;}echo json_encode($blocked);")
        self.assertTrue(r)

# Imported fixture class is a helper, not a second copy of its test suite.
del Base
