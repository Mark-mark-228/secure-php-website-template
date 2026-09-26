<?php
require_once __DIR__ . '/db.php';

$user = getAuthUser();
if (!$user) jsonResponse(false, 'Не авторизован');

$user['createdAt'] = date('d.m.Y', strtotime($user['created_at']));
unset($user['created_at']);

jsonResponse(true, '', ['user' => $user]);
