<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$name = trim($body['name'] ?? '');
$password = trim($body['password'] ?? '');

if (!$name || !$password) {
    jsonError('Name und Passwort erforderlich');
}

$db = getDb();
$stmt = $db->prepare('SELECT id, password_hash FROM fire_departments WHERE name = ? LIMIT 1');
$stmt->execute([$name]);
$dept = $stmt->fetch();

// Always run hash check to prevent timing-based user enumeration
$hashToCheck = $dept['password_hash'] ?? '$2y$10$invalidhashfortimingprotection00000000000000000000000';
$valid = $dept && password_verify($password, $hashToCheck);

if (!$valid) {
    sleep(1);
    jsonError('Ungültige Anmeldedaten', 401);
}

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));

$stmt = $db->prepare(
    'INSERT INTO auth_tokens (department_id, token, expires_at) VALUES (?, ?, ?)'
);
$stmt->execute([$dept['id'], $token, $expiresAt]);

jsonResponse([
    'token'      => $token,
    'expires_in' => 365 * 24 * 3600,
    'dept_name'  => $name,
]);
