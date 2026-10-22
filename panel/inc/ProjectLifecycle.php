<?php
/** Explicit reset/delete operations. Never save the stale, full project list after either operation. */
class ProjectLifecycle {
    private static function registry() {return dirname(__DIR__).'/db/deleted_projects.json';}
    public static function isDeleted($id) {
        $data=json_decode(@file_get_contents(self::registry())?:'{}',true);
        return is_array($data)&&isset($data[(string)$id]);
    }
    private static function statement($db,$sql,$params=[]) {
        $stmt=$db->prepare($sql);if(!$stmt)throw new RuntimeException('آماده‌سازی پاک‌سازی پروژه ناموفق بود.');
        if($params){$types=str_repeat('s',count($params));$stmt->bind_param($types,...$params);}
        if(!$stmt->execute())throw new RuntimeException('پاک‌سازی پایگاه داده ناموفق بود.');
        $result=$stmt->get_result();$rows=[];while($result&&$row=$result->fetch_assoc())$rows[]=$row;$stmt->close();return $rows;
    }
    private static function tables($db) {
        $sqlite=$db instanceof SQLite3ToMysqliWrapper;$result=$db->query($sqlite?"SELECT name FROM sqlite_master WHERE type='table'":"SHOW TABLES");$tables=[];
        while($result&&$row=$result->fetch_assoc()) {
            $name=reset($row);if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))continue;
            $cols=$db->query($sqlite?'PRAGMA table_info(`'.$name.'`)':'SHOW COLUMNS FROM `'.$name.'`');
            while($cols&&$col=$cols->fetch_assoc())$tables[$name][]=$col[$sqlite?'name':'Field'];
        }
        return $tables;
    }
    private static function assets($value,&$paths) {
        if(is_array($value)){foreach($value as $part)self::assets($part,$paths);return;}
        if(!is_string($value))return;
        if(substr($value,0,1)==='['||substr($value,0,1)==='{'){ $decoded=json_decode($value,true);if(is_array($decoded))self::assets($decoded,$paths); }
        $path=parse_url($value,PHP_URL_PATH);if(!is_string($path))return;
        if(preg_match('~^https?://~i',$value)) {
            $host=parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST);
            if(!$host||strcasecmp((string)parse_url($value,PHP_URL_HOST),$host)!==0)return;
        }
        foreach([dirname(__DIR__),dirname(__DIR__,2)] as $root) {
            $relative=preg_replace('~^/?panel/~','',ltrim($path,'/'));
            if(strpos($relative,'uploads/')!==0)continue;
            $real=realpath($root.'/'.$relative);$base=realpath($root.'/uploads');
            if($real&&$base&&strpos($real,$base.DIRECTORY_SEPARATOR)===0&&is_file($real)&&!is_link($root.'/'.$relative))$paths[$real]=true;
        }
    }
    private static function cleanWarehouse($data,$pid,&$removed) {
        $businesses=[$pid=>true];$stores=[];
        foreach(['projects','businesses'] as $key)foreach($data[$key]??[] as $row)if(is_array($row)&&((string)($row['project_id']??'')===$pid||(string)($row['id']??'')===$pid||(string)($row['slug']??'')===$pid))$businesses[(string)$row['id']]=true;
        foreach($data['stores']??[] as $row)if(is_array($row)&&isset($businesses[(string)($row['project_id']??'')]))$stores[(string)$row['id']]=true;
        foreach($data as $table=>$rows) {
            if(!is_array($rows))continue;$list=$rows===[]||array_keys($rows)===range(0,count($rows)-1);
            foreach($rows as $key=>$row) {
                if(!is_array($row))continue;
                $owned=(string)($row['project_id']??'')===$pid||isset($businesses[(string)($row['business_id']??'')])||isset($stores[(string)($row['store_id']??'')]);
                if(in_array($table,['projects','businesses'],true)&&isset($businesses[(string)($row['id']??'')]))$owned=true;
                if($owned){$removed[]=$row;unset($rows[$key]);}
            }
            $data[$table]=$list?array_values($rows):$rows;
        }
        return $data;
    }
    public static function run($pid,$delete,$confirmation='') {
        if(!isMainAdmin())AccessControl::deny();
        if(!is_string($pid)||$pid==='')throw new RuntimeException('پروژه را انتخاب کنید.');
        $project=getCurrentProject($pid,true);if(!$project)throw new RuntimeException('پروژه یافت نشد یا قبلاً حذف شده است.');
        if($delete&&(!is_string($confirmation)||trim($confirmation)!==trim($project['name'])))throw new RuntimeException('برای حذف کامل، نام پروژه را دقیق وارد کنید.');
        $lock=fopen(dirname(__DIR__).'/db/project-lifecycle.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('قفل مدیریت پروژه در دسترس نیست.');
        $backups=[];$paths=[];$privateFiles=[];$ownedFolders=[];$removed=[];$sharedModuleAssets=[];$all=getDB();
        try {
            $db=isSqlActive()?getConn():null;$tables=$db?self::tables($db):[];
            if($db&&!$db->begin_transaction())throw new RuntimeException('شروع تراکنش ناموفق بود.');
            $ledgerFile=dirname(__DIR__).'/db/project_asset_ledger.json';
            $backups[$ledgerFile]=is_file($ledgerFile)?file_get_contents($ledgerFile):null;
            $ledger=json_decode($backups[$ledgerFile]??'{}',true);if(!is_array($ledger))throw new RuntimeException('فهرست فایل‌های پروژه نامعتبر است.');
            $ownedAssets=[];self::assets($project,$ownedAssets);
            foreach($ledger[$pid]??[] as $oldPath) {
                if(!is_string($oldPath))continue;
                $real=realpath($oldPath);
                foreach([dirname(__DIR__).'/uploads',dirname(__DIR__,2).'/uploads'] as $base) {
                    $root=realpath($base);if($real&&$root&&strpos($real,$root.DIRECTORY_SEPARATOR)===0&&is_file($real))$ownedAssets[$real]=true;
                }
            }
            if($delete){$paths=$ownedAssets;unset($ledger[$pid]);}else $ledger[$pid]=array_keys($ownedAssets);
            foreach($ledger as $otherPid=>$files)if((string)$otherPid!==$pid)foreach($files as $path)if(is_string($path)&&($real=realpath($path)))$sharedModuleAssets[$real]=true;
            if(!AccessControl::writeJson($ledgerFile,$ledger))throw new RuntimeException('ثبت فهرست فایل‌های پروژه ناموفق بود.');

            // Remove private staged jobs owned by this project, never another project's files.
            foreach([['MAARKSHO_IMPORT_STATE_DIR','maarksho-import-'],['MAARKSHO_WEBSITE_UPLOAD_DIR','maarksho-site-upload-']] as [$env,$prefix]) {
                $dir=getenv($env)?:sys_get_temp_dir().'/'.$prefix.substr(hash('sha256',__DIR__),0,16);
                if(!is_dir($dir))continue;
                foreach(new DirectoryIterator($dir) as $entry) {
                    if(!$entry->isDir()||$entry->isLink()||!preg_match('/^[a-f0-9]{64}$/D',$entry->getFilename()))continue;
                    $state=json_decode(@file_get_contents($entry->getPathname().'/state.json')?:'null',true);
                    if(($state['project']??'')!==$pid)continue;
                    $ownedFolders[]=$entry->getPathname();
                    if(isset($tables['product_import_receipts'])&&$prefix==='maarksho-import-')self::statement($db,'DELETE FROM product_import_receipts WHERE job=?',[$entry->getFilename()]);
                    foreach(new DirectoryIterator($entry->getPathname()) as $file)if($file->isFile()&&!$file->isLink())$privateFiles[]=$file->getPathname();
                }
            }

            if($delete) {
                self::assets($project,$paths);
                foreach(['vm_data.json','seo_warehouse.json'] as $name) {
                    $file=dirname(__DIR__).'/db/'.$name;if(!is_file($file))continue;
                    $raw=file_get_contents($file);$data=json_decode($raw,true);if(!is_array($data))throw new RuntimeException('فایل دادهٔ ماژول نامعتبر است؛ حذف متوقف شد.');
                    $backups[$file]=$raw;$clean=self::cleanWarehouse($data,$pid,$removed);self::assets($clean,$sharedModuleAssets);
                    if(!AccessControl::writeJson($file,$clean))throw new RuntimeException('حذف دادهٔ ماژول ناموفق بود.');
                }
                self::assets($removed,$paths);$paths=array_diff_key($paths,$sharedModuleAssets);
                foreach($all['projects']??[] as $other)if((string)$other['id']!==$pid){$shared=[];self::assets($other,$shared);$paths=array_diff_key($paths,$shared);}
                // Ticket children must be removed before their project-owned parent rows.
                foreach(['support_tickets'=>['support_messages'],'ms_tickets'=>['ms_ticket_messages','ms_ticket_payments']] as $parent=>$children) {
                    if(!isset($tables[$parent]))continue;
                    $ids=self::statement($db,'SELECT id FROM `'.$parent.'` WHERE project_id=?',[$pid]);
                    if(isset($tables['ms_notifications'])&&in_array('link',$tables['ms_notifications'],true)) {
                        $ticketIds=array_fill_keys(array_map('strval',array_column($ids,'id')),true);
                        foreach(self::statement($db,'SELECT id,link FROM ms_notifications') as $notice) {
                            parse_str((string)parse_url($notice['link']??'',PHP_URL_QUERY),$query);
                            if(is_string($query['ticket']??null)&&isset($ticketIds[$query['ticket']]))self::statement($db,'DELETE FROM ms_notifications WHERE id=?',[$notice['id']]);
                        }
                    }
                    if($parent==='support_tickets'&&isset($tables['support_messages']))foreach($ids as $id) {
                        foreach(self::statement($db,'SELECT attachments FROM support_messages WHERE ticket_id=?',[$id['id']]) as $message)foreach(json_decode($message['attachments']?:'[]',true)?:[] as $attachment) {
                            if(!is_array($attachment)||!preg_match('/^[a-f0-9]{48}$/D',$attachment['id']??''))continue;
                            $dir=getenv('SUPPORT_STORAGE_DIR')?:dirname(__DIR__,3).'/.maarksho-support-'.substr(hash('sha256',__DIR__),0,12);
                            $privateFiles[]=$dir.'/'.$attachment['id'].'.upload';
                        }
                    }
                    foreach($ids as $id)foreach($children as $child)if(isset($tables[$child]))self::statement($db,'DELETE FROM `'.$child.'` WHERE ticket_id=?',[$id['id']]);
                }
                $shared=[];
                foreach($tables as $table=>$cols)if($table!=='projects'&&in_array('project_id',$cols,true)) {
                    self::assets(self::statement($db,'SELECT * FROM `'.$table.'` WHERE project_id=?',[$pid]),$paths);
                    self::assets(self::statement($db,'SELECT * FROM `'.$table.'` WHERE project_id<>?',[$pid]),$shared);
                    self::statement($db,'DELETE FROM `'.$table.'` WHERE project_id=?',[$pid]);
                }
                $paths=array_diff_key($paths,$shared,$sharedModuleAssets);
                if($db)self::statement($db,'DELETE FROM projects WHERE id=?',[$pid]);
                $registry=self::registry();$backups[$registry]=is_file($registry)?file_get_contents($registry):null;
                $deleted=json_decode($backups[$registry]??'{}',true);if(!is_array($deleted))throw new RuntimeException('فهرست حذف پروژه نامعتبر است.');
                $deleted[$pid]=time();if(!AccessControl::writeJson($registry,$deleted))throw new RuntimeException('ثبت حذف کامل ناموفق بود.');
            } else {
                foreach(['products','logs','schedules','scraping_jobs'] as $table)if(isset($tables[$table]))self::statement($db,'DELETE FROM `'.$table.'` WHERE project_id=?',[$pid]);
                $project['products']=[];$project['logs']=[];$project['schedules']=[];
                foreach(['schedules','schedule_slots'] as $key)$project['settings'][$key]=[];
                if(isset($project['settings']['task_manager_v3']))$project['settings']['task_manager_v3']['tasks']=[];
                unset($project['settings']['website_catalog']['last_sync']);
                if($db)self::statement($db,'UPDATE projects SET settings=? WHERE id=?',[json_encode($project['settings'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$pid]);
            }
            if(!$db) {
                foreach($all['projects'] as $key=>$row)if((string)$row['id']===$pid){if($delete)unset($all['projects'][$key]);else $all['projects'][$key]=$project;}
                $all['projects']=array_values($all['projects']);saveJsonDB($all);
            }
            if($db&&!$db->commit())throw new RuntimeException('ثبت عملیات پروژه ناموفق بود.');
        } catch(Throwable $e) {
            if(isset($db)&&$db)$db->rollback();foreach($backups as $file=>$raw){if($raw===null)@unlink($file);else file_put_contents($file,$raw,LOCK_EX);}throw $e;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
        $aiDir=getenv('MAARKSHO_AI_STATE_DIR')?:dirname(__DIR__).'/db/ai-private';
        if(is_dir($aiDir)){require_once __DIR__.'/ai/Store.php';try{(new AiStore($aiDir))->purgeProject($pid);}catch(Throwable $e){error_log('AI project cleanup failed: '.hash('sha256',$pid));}}
        require_once __DIR__.'/OnboardingCrawl.php';OnboardingCrawl::purge($pid);
        MaarkshoCache::delete('project_full_'.$pid);
        $failed=0;foreach(array_merge(array_keys($paths),$privateFiles) as $path)if(is_file($path)&&!@unlink($path)){$failed++;error_log('Project asset cleanup failed: '.hash('sha256',$path));}
        foreach($ownedFolders as $folder)if(is_dir($folder)&&!is_link($folder)&&count(scandir($folder))===2)@rmdir($folder);
        if(class_exists('TiamInventory'))@unlink(TiamInventory::path($pid,'job'));
        foreach(['website_catalog_preview','website_export_job','website_export_batch','website_export_receipt'] as $key)if(($_SESSION[$key]['project']??'')===$pid)unset($_SESSION[$key]);
        if($delete&&($_SESSION['project_id']??'')===$pid)unset($_SESSION['project_id']);
        return ['remaining_files'=>$failed];
    }
}
