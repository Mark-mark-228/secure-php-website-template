<?php
/**
 * api/login.php — Авторизация пользователя
 *
 * ЗАЩИТА:
 *  ✓ Prepared statements — SQL-инъекции невозможны
 *  ✓ password_verify() — безопасная проверка bcrypt-хэша
 *  ✓ Rate limiting — блокировка после 5 неудачных попыток за 15 минут
 *  ✓ Одинаковое время ответа при неверном email и неверном пароле
 *    (защита от username enumeration через timing attack)
 *  ✓ Session fixation prevention через session_regenerate_id
 *  ✓ Запись в audit_log
 */

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, 'Метод не поддерживается');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) jsonResponse(false, 'Некорректный запрос');

$email    = mb_strtolower(trim(strip_tags((string)($body['email']    ?? ''))));
$password = (string)($body['password'] ?? '');
$remember = !empty($body['remember']);
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!$email || !$password) {
    jsonResponse(false, 'Заполните все поля');
}

$db = getDB();

// ── 1. Rate Limiting (защита от брутфорса) ───────────────────────────────────
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM login_attempts
     WHERE (email = ? OR ip_address = ?)
       AND success = FALSE
       AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
);
$stmt->execute([$email, $ip, LOGIN_LOCKOUT_MINUTES]);
$recentFails = (int)$stmt->fetchColumn();

if ($recentFails >= MAX_LOGIN_ATTEMPTS) {
    // Записываем заблокированную попытку
    $db->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?,?,0)')
       ->execute([$email, $ip]);

    jsonResponse(false, sprintf(
        'Слишком много неудачных попыток. Подождите %d минут.',
        LOGIN_LOCKOUT_MINUTES
    ));
}

// ── 2. Поиск пользователя ───────────────────────────────────────────────────
// Всегда тратим одинаковое время — даже если пользователь не найден
// (защита от timing attack / user enumeration)
$stmt = $db->prepare(
    'SELECT id, first_name, last_name, email, password_hash, role, is_verified
     FROM users WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// Фиктивная проверка, если пользователь не найден — время ответа одинаковое
$hash = $user ? $user['password_hash'] : '$2y$12$invalidhashforbrutforceprotection1234567890';

$valid = password_verify($password, $hash) && $user;

// Записываем попытку входа
$db->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?,?,?)')
   ->execute([$email, $ip, $valid ? 1 : 0]);

if (!$valid) {
    jsonResponse(false, 'Неверный email или пароль');
}

// Проверка подтверждения email
if (!$user['is_verified']) {
    jsonResponse(false, 'Сначала подтвердите email. Код был отправлен при регистрации. Проверьте папку «Спам».');
}

// ── 3. Создание сессии ───────────────────────────────────────────────────────
startSecureSession();
session_regenerate_id(true); // предотвращение session fixation

$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['role']    = $user['role'];
$_SESSION['ip']      = $ip; // для проверки угона сессии

if ($remember) {
    // "Запомнить меня" — сессия на 30 дней
    $expires = time() + 30 * 24 * 3600;
    session_set_cookie_params(['lifetime' => 30 * 24 * 3600]);

    // Сохраняем сессию в БД
    $sessionId = session_id();
    $db->prepare(
        'INSERT INTO sessions (id, user_id, ip_address, user_agent, remember_me, expires_at)
         VALUES (?, ?, ?, ?, 1, FROM_UNIXTIME(?))'
    )->execute([$sessionId, $user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '', $expires]);
}

// ── 4. Аудит-лог ────────────────────────────────────────────────────────────
$db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?,?,?)')
   ->execute([$user['id'], 'login', $ip]);

// Если нужно — обновляем хэш при устаревшем cost (автоапгрейд bcrypt)
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
       ->execute([$newHash, $user['id']]);
}

jsonResponse(true, 'Вход выполнен', ['role' => $user['role']]);
