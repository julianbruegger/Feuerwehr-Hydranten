<?php
/**
 * sessions.php – Active token management + magic-link creation (admin only)
 *
 * GET  ?action=list              → all tokens (joined with dept name)
 * POST ?action=create_magic      → body {dept_id, label?}
 * POST ?action=revoke&id=X       → mark token as revoked
 */
require_once __DIR__ . '/../../../config/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$auth = requireAuth();
if (!$auth['is_admin']) {
    jsonResponse(['error' => 'Nur für Admins'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    $db   = getDb();
    $rows = $db->query(
        'SELECT t.id, t.department_id, d.name AS dept_name,
                t.source, t.label, t.is_admin,
                t.created_at, t.expires_at, t.last_seen_at, t.revoked_at
         FROM auth_tokens t
         LEFT JOIN fire_departments d ON d.id = t.department_id
         ORDER BY t.created_at DESC
         LIMIT 500'
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['is_admin']  = (bool) $r['is_admin'];
        $r['is_active'] = !$r['revoked_at'] && strtotime($r['expires_at']) > time();
    }
    jsonResponse($rows);
}

if ($method === 'POST' && $action === 'create_magic') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $deptId = isset($body['dept_id']) ? (int) $body['dept_id'] : null;
    $label  = isset($body['label'])   ? trim($body['label'])   : null;

    if (!$deptId) {
        jsonResponse(['error' => 'dept_id fehlt'], 400);
    }

    $db   = getDb();
    $dept = $db->prepare('SELECT name FROM fire_departments WHERE id = ? LIMIT 1');
    $dept->execute([$deptId]);
    if (!$dept->fetch()) {
        jsonResponse(['error' => 'Feuerwehr nicht gefunden'], 404);
    }

    // Magic links never expire (99 years)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+99 years'));
    $rec = createToken($deptId, false, 'magic', $label, $expiresAt);

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url   = "{$proto}://{$host}/magic-login.php?t={$rec['token']}";

    jsonResponse(['token' => $rec['token'], 'url' => $url, 'id' => $rec['id']]);
}

if ($method === 'POST' && $action === 'revoke') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$id) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int) ($body['id'] ?? 0);
    }
    if (!$id) jsonResponse(['error' => 'id fehlt'], 400);

    getDb()->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE id = ?')->execute([$id]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unbekannte Aktion'], 400);
