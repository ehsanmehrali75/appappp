<?php
/** Legacy API retained only to explain migration; all ingestion uses project adapters. */
class SyncEngine {
    public static function runSync($businessId,$source='all',$trigger='manual') {
        return ['success'=>false,'status'=>'connection_required','error'=>'دریافت فقط از اتصال واقعی پروژه در مرکز اتصال‌ها انجام می‌شود.','total_records'=>0];
    }
}
