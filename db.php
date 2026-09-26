<?php
/**
 * db.php — подключение к БД через PDO и общие функции (сессии, JSON-ответы).
 * Все настройки берутся из config.php (образец: config.example.php).
 */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'message' => 'Не найден config.php (скопируйте config.example.php)']));
}
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['ok' => false, 'message' => 'Ошибка подключения к БД']));
        }
    }
    return $pdo;
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => SESSION_DOMAIN,
        'secure'   => SESSION_SECURE,
        'httponly' => true,
        'samesite' => 'None',   // None обязателен при работе через WAF-прокси
    ]);
    session_start();
    if (empty($_SESSION['_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['_initiated'] = true;
    }
}

function getAuthUser(): ?array {
    startSecureSession();
    if (empty($_SESSION['user_id'])) return null;
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, first_name, last_name, email, phone, bio, role, created_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function jsonResponse(bool $ok, string $message = '', array $extra = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}