<?php
require_once __DIR__ . '/db.php';

startSecureSession();

if (!empty($_SESSION['user_id'])) {
    $db = getDB();
    $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([session_id()]);
    $db->prepare('INSERT INTO audit_log (user_id, action, ip_address) VALUES (?,?,?)')
       ->execute([$_SESSION['user_id'], 'logout', $_SERVER['REMOTE_ADDR'] ?? '']);
}

$_SESSION = [];
session_destroy();

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

jsonResponse(true, 'Выход выполнен');
