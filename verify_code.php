<?php
/**
 * verify_code.php — Проверка 8-символьного кода подтверждения email
 * Структура БД: is_verified, verify_token, verify_token_expires
 */

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, 'Метод не поддерживается');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) jsonResponse(false, 'Некорректный запрос');

$email = mb_strtolower(trim((string)($body['email'] ?? '')));
$code  = strtoupper(trim((string)($body['code'] ?? '')));

if (!$email || !$code) {
    jsonResponse(false, 'Введите код из письма');
}

if (!preg_match('/^[A-Z2-9]{8}$/', $code)) {
    jsonResponse(false, 'Код должен состоять из 8 символов (заглавные буквы и цифры)');
}

$db = getDB();

// Проверяем код — он должен совпадать, email не подтверждён, срок не истёк
$stmt = $db->prepare(
    'SELECT id FROM users
     WHERE email = ?
       AND verify_token = ?
       AND verify_token_expires > NOW()
       AND is_verified = 0
     LIMIT 1'
);
$stmt->execute([$email, $code]);
$user = $stmt->fetch();

if (!$user) {
    // Проверяем — может код верный но истёк?
    $stmtCheck = $db->prepare(
        'SELECT id FROM users WHERE email = ? AND verify_token = ? AND is_verified = 0 LIMIT 1'
    );
    $stmtCheck->execute([$email, $code]);
    if ($stmtCheck->fetch()) {
        jsonResponse(false, 'Код устарел (прошло более 15 минут). Зарегистрируйтесь заново для получения нового кода.');
    }
    jsonResponse(false, 'Неверный код. Проверьте письмо и попробуйте снова.');
}

// Подтверждаем email
$db->prepare(
    'UPDATE users SET is_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ?'
)->execute([$user['id']]);

$db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)')
   ->execute([$user['id'], 'email_verified', $_SERVER['REMOTE_ADDR'] ?? '']);

// Сразу логиним пользователя
startSecureSession();
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

jsonResponse(true, 'Email подтверждён! Добро пожаловать!');
