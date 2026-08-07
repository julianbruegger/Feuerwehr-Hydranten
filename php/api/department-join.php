<?php
/**
 * department-join.php – Join an existing department using an invite code.
 *
 * POST JSON { code }  (Authorization: Bearer <token>)
 *   → validates a pending invitation code,
 *   → creates a member membership for the current user,
 *   → scopes the caller's token to that department,
 *   → returns { ok: true, department_id, dept_name }.
 */
require_once __DIR__ . '/../../config/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$auth   = requireAuth();
$userId = $auth['user_id'];

if ($userId === null) {
    jsonResponse(['error' => 'Bitte zuerst als Person anmelden.'], 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$code = trim($body['code'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $code)) {
    jsonResponse(['error' => 'Ungültiger Einladungscode.'], 400);
}

$db = getDb();

$stmt = $db->prepare(
    'SELECT id, department_id FROM invitations
     WHERE token = ? AND status = ? AND expires_at > NOW() LIMIT 1'
);
$stmt->execute([$code, 'pending']);
$inv = $stmt->fetch();

if (!$inv) {
    jsonResponse(['error' => 'Dieser Einladungscode ist ungültig oder abgelaufen.'], 404);
}

$deptId = (int) $inv['department_id'];

$nameStmt = $db->prepare('SELECT name FROM fire_departments WHERE id = ? LIMIT 1');
$nameStmt->execute([$deptId]);
$deptName = $nameStmt->fetchColumn() ?: 'Feuerwehr';

$db->beginTransaction();
try {
    // Idempotent: joining twice just keeps the existing membership.
    $db->prepare(
        'INSERT INTO memberships (user_id, department_id, role) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id'
    )->execute([$userId, $deptId, 'member']);

    $db->prepare('UPDATE invitations SET status = ?, accepted_at = NOW() WHERE id = ?')
       ->execute(['accepted', $inv['id']]);

    setTokenDepartment($auth['token_id'], $deptId);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    jsonResponse(['error' => 'Beitritt fehlgeschlagen.'], 500);
}

jsonResponse(['ok' => true, 'department_id' => $deptId, 'dept_name' => $deptName]);
