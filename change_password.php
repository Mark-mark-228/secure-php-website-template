<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$user = getAuthUser();
if (!$user) jsonResponse(false, 'Не авторизован');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$currentPassword = (string)($body['currentPassword'] ?? '');
$newPassword     = (string)($body['newPassword'] ?? '');

if (!$currentPassword || !$newPassword) jsonResponse(false, 'Заполните все поля');
if (mb_strlen($newPassword) < 8)        jsonResponse(false, 'Новый пароль слишком короткий');
if (mb_strlen($newPassword) > 128)      jsonResponse(false, 'Пароль слишком длинный');

$db   = getDB();
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row  = $stmt->fetch();

if (!password_verify($currentPassword, $row['password_hash'])) {
    jsonResponse(false, 'Неверный текущий пароль');
}

$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
$db->prepare('DELETE FROM sessions WHERE user_id = ? AND id != ?')->execute([$user['id'], session_id()]);
$db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?,?,?)')
   ->execute([$user['id'], 'password_change', $_SERVER['REMOTE_ADDR'] ?? '']);

jsonResponse(true, 'Пароль изменён');
