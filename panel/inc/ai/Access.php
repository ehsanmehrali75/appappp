<?php
class AiAccess {
    public static function principal($project) {
        if(!isUser()||!SessionIdentity::select($project))throw new RuntimeException('دسترسی پروژه مجاز نیست.');
        $identity=$_SESSION['auth_identity']??[];
        $kind=$identity['kind']??'';$username=$_SESSION['user']['username']??'';
        $owner=hash('sha256',$kind."\0".$username."\0".(in_array($kind,['system','global'],true)?'global':($identity['origin_project']??$project['id'])));
        if(isset($identity['grants']))$identity['grants']=array_intersect_key($identity['grants'],[$project['id']=>true]);
        return ['project'=>(string)$project['id'],'owner'=>$owner,'username'=>$username,'role'=>$_SESSION['user']['role']??'','identity'=>$identity];
    }
    public static function run($principal,$fn) {
        // Core product loading may call getVoterId(), which starts a session.
        // Start before impersonation so it cannot replace the verified grant midway.
        $reclose=PHP_SAPI!=='cli'&&session_status()!==PHP_SESSION_ACTIVE;
        if($reclose&&!session_start())throw new RuntimeException('نشست اتصال آماده نشد.');
        $saved=$_SESSION??[];
        try {
            $_SESSION=['auth_identity'=>$principal['identity'],'user'=>['username'=>$principal['username'],'role'=>$principal['role']],'project_id'=>$principal['project']];
            SessionIdentity::refresh();
            $project=getCurrentProject($principal['project'],true);
            if(!$project||empty($_SESSION['user'])||!SessionIdentity::select($project))throw new RuntimeException('حساب یا مجوز پروژه تغییر کرده؛ اتصال دوباره لازم است.');
            return $fn($project);
        } finally {$_SESSION=$saved;if($reclose&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
    }
    public static function pageAllowed(){return ModuleProjectAccess::allowed('products','products','ai_connect','view');}
    public static function write(){if(!ModuleProjectAccess::allowed('products','products','ai_connect','use'))throw new RuntimeException('مجوز استفاده از اتصال هوشمند فعال نیست.');self::need('products','use');}
    public static function need($feature,$mode='view'){if(!canAccessFeature($feature,$mode))throw new RuntimeException('دسترسی به این داده یا عملیات مجاز نیست.');}
    public static function scopes($requested) {
        $known=['project.read','content.write','reports.write','datasets.write','social.write'];$scopes=array_values(array_unique(array_filter(explode(' ',trim($requested)))));
        if(!$scopes||array_diff($scopes,$known)||!in_array('project.read',$scopes,true))throw new RuntimeException('دامنهٔ دسترسی معتبر نیست.');
        if(array_intersect($scopes,['content.write','reports.write','datasets.write','social.write']))self::write();
        return $scopes;
    }
    public static function scope($grant,$scope){if(!in_array($scope,$grant['scopes']??[],true))throw new RuntimeException('این اتصال اجازهٔ عملیات درخواستی را ندارد.');}
}
