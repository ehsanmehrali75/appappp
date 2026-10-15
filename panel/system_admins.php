<?php
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/SystemAdmins.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isMainAdmin()) AccessControl::deny();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$body = json_decode(file_get_contents('php://input', false, null, 0, 16384), true);
if (!is_array($body) || empty($body['csrf']) || !hash_equals($_SESSION['system_admin_csrf'] ?? '', $body['csrf'])) { http_response_code(403); echo json_encode(['error'=>'نشست معتبر نیست؛ صفحه را دوباره باز کنید.'], JSON_UNESCAPED_UNICODE); exit; }
try {
    SystemAdmins::create($body['username'] ?? '', $body['password'] ?? '', !empty($body['unrestricted']));
    echo json_encode(['status'=>'created'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
