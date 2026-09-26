<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$user = getAuthUser();
if (!$user) jsonResponse(false, 'Не авторизован');

$raw      = file_get_contents('php://input');
$body     = json_decode($raw, true);
$password = (string)($body['password'] ?? '');

$db   = getDB();
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row  = $stmt->fetch();

if (!password_verify($password, $row['password_hash'])) {
    jsonResponse(false, 'Неверный пароль');
}

$db->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);

$_SESSION = [];
session_destroy();

jsonResponse(true, 'Аккаунт удалён');
