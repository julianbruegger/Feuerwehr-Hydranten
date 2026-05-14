<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$token = getBearerToken();
if ($token) {
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM auth_tokens WHERE token = ?');
    $stmt->execute([$token]);
}

jsonResponse(['ok' => true]);
