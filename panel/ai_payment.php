<?php
require_once __DIR__.'/inc/ai/Bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{$id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('شناسهٔ پرداخت معتبر نیست.');$p=(new AiBilling(new AiStore()))->verify($id,$_GET['Authority']??'',$_GET['Status']??'');$message=$p['sandbox']?'پرداخت آزمایشی تأیید شد؛ اعتبار قابل مصرف اضافه نشد.':'پرداخت تأیید و اعتبار سرویس به کیف پول اضافه شد.';}catch(Throwable $e){http_response_code(400);$message=$e instanceof RuntimeException?$e->getMessage():'تأیید پرداخت انجام نشد.';}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اعتبار تحلیل هوشمند</title><link rel="stylesheet" href="/panel/assets/ai-connect.css"></head><body><main class="ai-shell narrow"><section class="ai-card"><h1>نتیجه پرداخت</h1><p><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><a href="/panel/ai-connect.php">بازگشت به اتصال و گزارش هوشمند</a></section></main></body></html>
