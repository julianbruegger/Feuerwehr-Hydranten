<?php
require_once __DIR__ . '/db.php';

function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

function requireAuth(): int {
    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['message' => 'Nicht authentifiziert']);
        exit;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT department_id FROM auth_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        echo json_encode(['message' => 'Token ungültig oder abgelaufen']);
        exit;
    }

    return (int) $row['department_id'];
}

function jsonResponse(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): void {
    jsonResponse(['message' => $message], $status);
}

function corsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
