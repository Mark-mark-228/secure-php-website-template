<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$user = getAuthUser();
if (!$user) jsonResponse(false, 'Не авторизован');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$db = getDB();
$db->prepare(
    'INSERT INTO notification_settings (user_id, news, orders, security)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE news=VALUES(news), orders=VALUES(orders), security=VALUES(security)'
)->execute([
    $user['id'],
    !empty($body['news'])     ? 1 : 0,
    !empty($body['orders'])   ? 1 : 0,
    !empty($body['security']) ? 1 : 0,
]);

jsonResponse(true, 'Настройки сохранены');
