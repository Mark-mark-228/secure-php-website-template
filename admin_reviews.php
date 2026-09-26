<?php
/**
 * admin_reviews.php — Админ-панель управления отзывами
 * Авторизация через сессию (db.php → users таблица, role = 'admin')
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Подключение к БД отзывов
function getReviewsDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_REVIEWS_NAME, DB_CHARSET),
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['ok' => false, 'message' => 'Ошибка БД'], JSON_UNESCAPED_UNICODE));
        }
    }
    return $pdo;
}

// Проверка что пользователь залогинен и является администратором
function requireAdmin(): void {
    $user = getAuthUser();

    if (!$user) {
        http_response_code(401);
        die(json_encode([
            'ok'      => false,
            'message' => 'Необходимо войти в аккаунт'
        ], JSON_UNESCAPED_UNICODE));
    }

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die(json_encode([
            'ok'      => false,
            'message' => 'Доступ запрещён — недостаточно прав'
        ], JSON_UNESCAPED_UNICODE));
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: получить все отзывы включая скрытые ────────────────────────────────
if ($method === 'GET') {
    requireAdmin();

    $db   = getReviewsDB();
    $stmt = $db->query(
        'SELECT id, name, service, text, rating, approved, ip_address, created_at
         FROM reviews
         ORDER BY created_at DESC
         LIMIT 500'
    );
    echo json_encode([
        'ok'      => true,
        'reviews' => $stmt->fetchAll()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: действия (approve / hide / delete) ─────────────────────────────────
if ($method === 'POST') {
    requireAdmin();

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!$body) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Некорректный запрос'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = (string)($body['action'] ?? '');
    $id     = (int)($body['id']     ?? 0);

    if (!$id && $action !== 'list') {
        echo json_encode(['ok' => false, 'message' => 'ID не указан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = getReviewsDB();

    // Одобрить
    if ($action === 'approve') {
        $db->prepare('UPDATE reviews SET approved = 1 WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'Отзыв одобрен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Скрыть
    if ($action === 'hide') {
        $db->prepare('UPDATE reviews SET approved = 0 WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'Отзыв скрыт'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Удалить
    if ($action === 'delete') {
        $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'Отзыв удалён'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);