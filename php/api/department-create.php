<?php
/**
 * department-create.php – Create a new fire department and become its owner.
 *
 * POST JSON { name }  (Authorization: Bearer <token>)
 *   → creates a fire_departments row,
 *   → creates an owner membership for the current user,
 *   → scopes the caller's token to the new department,
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
$name = trim($body['name'] ?? '');

if ($name === '') {
    jsonResponse(['error' => 'Name der Feuerwehr erforderlich.'], 400);
}
if (strtolower($name) === 'admin') {
    jsonResponse(['error' => 'Dieser Name ist reserviert.'], 409);
}

$db = getDb();

$stmt = $db->prepare('SELECT id FROM fire_departments WHERE name = ? LIMIT 1');
$stmt->execute([$name]);
if ($stmt->fetch()) {
    jsonResponse(['error' => 'Diese Feuerwehr existiert bereits.'], 409);
}

$db->beginTransaction();
try {
    $db->prepare('INSERT INTO fire_departments (name) VALUES (?)')->execute([$name]);
    $deptId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO memberships (user_id, department_id, role) VALUES (?, ?, ?)')
       ->execute([$userId, $deptId, 'owner']);

    setTokenDepartment($auth['token_id'], $deptId);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    jsonResponse(['error' => 'Feuerwehr konnte nicht erstellt werden.'], 500);
}

jsonResponse(['ok' => true, 'department_id' => $deptId, 'dept_name' => $name]);
