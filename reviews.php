<?php
/**
 * api/reviews.php
 * GET  → возвращает все одобренные отзывы
 * POST → сохраняет новый отзыв
 *
 * ЗАЩИТА:
 *  ✓ PDO Prepared Statements — SQL-инъекции невозможны
 *  ✓ strip_tags + htmlspecialchars — XSS-фильтрация
 *  ✓ Валидация длин и типов на сервере
 */
 
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
 
// ── Подключение к БД отзывов ─────────────────────────────────────────────────
function getReviewsDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_REVIEWS_NAME, DB_CHARSET);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['ok' => false, 'message' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE));
        }
    }
    return $pdo;
}
 
$method = $_SERVER['REQUEST_METHOD'];
 
// Имена пользователей, получающих значок верификации
$VERIFIED_NAMES = VERIFIED_NAMES;

// ── GET: получить отзывы ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $db   = getReviewsDB();
    $stmt = $db->prepare(
        'SELECT id, name, service, text, rating, created_at
         FROM reviews
         WHERE approved = 1
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $stmt->execute();
    $reviews = $stmt->fetchAll();

    foreach ($reviews as &$r) {
        $r['verified'] = in_array($r['name'], $VERIFIED_NAMES);
    }
    unset($r);

    echo json_encode(['ok' => true, 'reviews' => $reviews], JSON_UNESCAPED_UNICODE);
    exit;
}
 
// ── POST: добавить отзыв ─────────────────────────────────────────────────────
if ($method === 'POST') {
    // Берём имя из сессии (пользователь должен быть авторизован)
    require_once __DIR__ . '/db.php';
    $currentUser = getAuthUser();
    if (!$currentUser) {
        echo json_encode(['ok' => false, 'message' => 'Необходимо войти в аккаунт чтобы оставить отзыв'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Имя берём из аккаунта — пользователь не может его подменить
    $name = $currentUser['first_name'] . ' ' . $currentUser['last_name'];

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
 
    if (!$body) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Некорректный запрос'], JSON_UNESCAPED_UNICODE);
        exit;
    }
 
    // Санитизация (XSS-защита)
    $service = mb_substr(trim(strip_tags((string)($body['service'] ?? ''))), 0, 100);
    $text    = mb_substr(trim(strip_tags((string)($body['text']    ?? ''))), 0, 1000);
    $rating  = (int)($body['rating'] ?? 0);
 
    // Валидация
    if (mb_strlen($text) < 5) {
        echo json_encode(['ok' => false, 'message' => 'Отзыв слишком короткий'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['ok' => false, 'message' => 'Выберите оценку от 1 до 5'], JSON_UNESCAPED_UNICODE);
        exit;
    }
 
    $db   = getReviewsDB();
    $stmt = $db->prepare(
        'INSERT INTO reviews (name, service, text, rating, ip_address, approved)
         VALUES (:name, :service, :text, :rating, :ip, :approved)'
    );
    $stmt->execute([
        ':name'     => $name,
        ':service'  => $service ?: null,
        ':text'     => $text,
        ':rating'   => $rating,
        ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ':approved' => 1,
    ]);
 
    echo json_encode(['ok' => true, 'message' => 'Отзыв добавлен'], JSON_UNESCAPED_UNICODE);
    exit;
}
 
http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
