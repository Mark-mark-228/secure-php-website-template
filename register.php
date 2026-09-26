<?php
/**
 * register.php — Регистрация с отправкой 8-символьного кода на email
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

function sanitize(mixed $val, int $maxLen = 255): string {
    return mb_substr(trim(strip_tags((string)($val ?? ''))), 0, $maxLen);
}

$firstName = sanitize($body['firstName'] ?? '', 50);
$lastName  = sanitize($body['lastName']  ?? '', 50);
$email     = sanitize($body['email']     ?? '', 255);
$phone     = sanitize($body['phone']     ?? '', 20);
$password  = (string)($body['password'] ?? '');

$errors = [];
if (mb_strlen($firstName) < 2) $errors[] = 'Имя слишком короткое';
if (mb_strlen($lastName)  < 2) $errors[] = 'Фамилия слишком короткая';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email';
if (mb_strlen($password) < 8)                  $errors[] = 'Пароль слишком короткий (минимум 8 символов)';
if (mb_strlen($password) > 128)                $errors[] = 'Пароль слишком длинный';
if (!preg_match('/[a-zа-яё]/ui', $password))   $errors[] = 'Пароль должен содержать строчную букву';
if (!preg_match('/[A-ZА-ЯЁ]/u',  $password))   $errors[] = 'Пароль должен содержать заглавную букву';
if (!preg_match('/\d/',           $password))   $errors[] = 'Пароль должен содержать цифру';
if ($errors) jsonResponse(false, implode('. ', $errors));

$emailNorm    = mb_strtolower($email);
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Генерируем 8-символьный код: заглавные буквы + цифры
// Исключены похожие символы: O/0, I/1
function generateCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

$code    = generateCode();
$expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$db = getDB();

try {
    $stmt = $db->prepare('SELECT id, is_verified FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$emailNorm]);
    $existing = $stmt->fetch();

    if ($existing && $existing['is_verified']) {
        jsonResponse(false, 'Пользователь с таким email уже зарегистрирован');
    }

    if ($existing && !$existing['is_verified']) {
        // Обновляем данные и выдаём новый код
        $db->prepare(
            'UPDATE users SET first_name=:fn, last_name=:ln, phone=:phone,
             password_hash=:hash, verify_token=:code, verify_token_expires=:expires
             WHERE id=:id'
        )->execute([
            ':fn'      => $firstName,
            ':ln'      => $lastName,
            ':phone'   => $phone ?: null,
            ':hash'    => $passwordHash,
            ':code'    => $code,
            ':expires' => $expires,
            ':id'      => $existing['id'],
        ]);
    } else {
        $db->prepare(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, is_verified, verify_token, verify_token_expires)
             VALUES (:fn, :ln, :email, :phone, :hash, 0, :code, :expires)'
        )->execute([
            ':fn'      => $firstName,
            ':ln'      => $lastName,
            ':email'   => $emailNorm,
            ':phone'   => $phone ?: null,
            ':hash'    => $passwordHash,
            ':code'    => $code,
            ':expires' => $expires,
        ]);

        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO notification_settings (user_id) VALUES (?)')->execute([$userId]);
        $db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?, ?, ?)')
           ->execute([$userId, 'register', $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    // Красивое HTML письмо с кодом
    $siteDomain = SITE_DOMAIN;
    $subject = 'Код подтверждения — ' . SITE_DOMAIN;

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

        <tr>
          <td style="background:linear-gradient(135deg,#007bff,#0056b3);padding:32px 40px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:1px;">🔐 Подтверждение email</h1>
            <p style="margin:8px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">{$siteDomain}</p>
          </td>
        </tr>

        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 8px;color:#1a1a2e;font-size:16px;">Здравствуйте, <strong>{$firstName}</strong>!</p>
            <p style="margin:0 0 28px;color:#555;font-size:14px;line-height:1.6;">
              Вы регистрируетесь на сайте <strong>{$siteDomain}</strong>.<br>
              Введите код ниже на странице регистрации.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0">
              <tr><td align="center" style="padding:0 0 28px;">
                <div style="display:inline-block;background:#f0f7ff;border:2px dashed #007bff;border-radius:12px;padding:24px 40px;">
                  <p style="margin:0 0 8px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:2px;">Ваш код подтверждения</p>
                  <p style="margin:0;font-size:38px;font-weight:800;letter-spacing:8px;color:#007bff;font-family:monospace;">{$code}</p>
                </div>
              </td></tr>
            </table>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e1;border-radius:8px;margin-bottom:24px;">
              <tr><td style="padding:14px 18px;">
                <p style="margin:0;color:#b45309;font-size:13px;line-height:1.8;">
                  ⏱ <strong>Код действует 15 минут</strong><br>
                  🔡 Только <strong>заглавные латинские буквы и цифры</strong><br>
                  📁 Если письмо не пришло — проверьте папку <strong>«Спам»</strong><br>
                  ❌ Если вы не регистрировались — просто проигнорируйте это письмо
                </p>
              </td></tr>
            </table>

            <p style="margin:0;color:#aaa;font-size:12px;text-align:center;">
              Это автоматическое письмо, не отвечайте на него.<br>
              © 2025 {$siteDomain}
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    require_once __DIR__ . '/mailer.php';
    sendMail($emailNorm, $firstName . ' ' . $lastName, $subject, $htmlBody);

    jsonResponse(true, 'Код отправлен на вашу почту. Введите его ниже. Код действует 15 минут.');

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonResponse(false, 'Пользователь с таким email уже зарегистрирован');
    }
    error_log('register.php PDO error: ' . $e->getMessage());
    jsonResponse(false, 'Ошибка сервера. Попробуйте позже');
}
