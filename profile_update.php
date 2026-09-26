<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$user = getAuthUser();
if (!$user) jsonResponse(false, 'Не авторизован');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) jsonResponse(false, 'Некорректный запрос');

function sanitize($val, $max = 255): string {
    return mb_substr(trim(strip_tags((string)($val ?? ''))), 0, $max);
}

$firstName = sanitize($body['firstName'], 50);
$lastName  = sanitize($body['lastName'],  50);
$email     = mb_strtolower(sanitize($body['email'], 255));
$phone     = sanitize($body['phone'],     20);
$bio       = sanitize($body['bio'],       300);

if (mb_strlen($firstName) < 2) jsonResponse(false, 'Имя слишком короткое');
if (mb_strlen($lastName)  < 2) jsonResponse(false, 'Фамилия слишком короткая');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Некорректный email');

$db = getDB();
try {
    $stmt = $db->prepare(
        'UPDATE users SET first_name=:fn, last_name=:ln, email=:em, phone=:ph, bio=:bio WHERE id=:id'
    );
    $stmt->execute([
        ':fn'  => $firstName,
        ':ln'  => $lastName,
        ':em'  => $email,
        ':ph'  => $phone ?: null,
        ':bio' => $bio ?: null,
        ':id'  => $user['id'],
    ]);
    $db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?,?,?)')
       ->execute([$user['id'], 'profile_update', $_SERVER['REMOTE_ADDR'] ?? '']);
    jsonResponse(true, 'Данные сохранены');
} catch (PDOException $e) {
    if ($e->getCode() === '23000') jsonResponse(false, 'Email уже занят');
    error_log('profile_update error: ' . $e->getMessage());
    jsonResponse(false, 'Ошибка сервера');
}
