<?php
/**
 * config.example.php — пример настроек.
 * Скопируйте файл в config.php и заполните своими значениями.
 * Файл config.php добавлен в .gitignore и не попадает в репозиторий.
 */

// ── База данных ──────────────────────────────────────────────────────────────
define('DB_HOST',         'localhost');
define('DB_NAME',         'site_users');       // БД пользователей
define('DB_REVIEWS_NAME', 'site_reviews');     // БД отзывов
define('DB_USER',         'your_db_user');
define('DB_PASS',         'your_db_password');
define('DB_CHARSET',      'utf8mb4');

// ── Сайт и сессии ────────────────────────────────────────────────────────────
define('SITE_NAME',              'Example Company');
define('SITE_DOMAIN',            'example.com');
define('SESSION_NAME',           'site_session');
define('SESSION_SECURE',         true);          // true, если сайт работает по HTTPS
define('SESSION_DOMAIN',         SITE_DOMAIN);
define('MAX_LOGIN_ATTEMPTS',     5);
define('LOGIN_LOCKOUT_MINUTES',  15);

// ── Почта (SMTP) для писем с кодом подтверждения ─────────────────────────────
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'no-reply@example.com');
define('SMTP_PASS', 'your_smtp_password');

// ── Отзывы: имена, которым показывается значок «проверено» ───────────────────
define('VERIFIED_NAMES', ['Example Company']);
