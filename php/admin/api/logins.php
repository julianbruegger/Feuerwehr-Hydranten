<?php
/**
 * logins.php – Login event history (admin only)
 *
 * GET ?limit=100&offset=0&dept_id=<optional>
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

$limit  = min((int) ($_GET['limit']  ?? 100), 500);
$offset = max((int) ($_GET['offset'] ?? 0),   0);
$deptId = isset($_GET['dept_id']) ? (int) $_GET['dept_id'] : null;

$db = getDb();

if ($deptId) {
    $stmt = $db->prepare(
        'SELECT l.id, l.department_id, d.name AS dept_name,
                l.token_id, l.is_admin, l.source, l.ip, l.user_agent, l.created_at
         FROM login_log l
         LEFT JOIN fire_departments d ON d.id = l.department_id
         WHERE l.department_id = ?
         ORDER BY l.created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$deptId, $limit, $offset]);
} else {
    $stmt = $db->prepare(
        'SELECT l.id, l.department_id, d.name AS dept_name,
                l.token_id, l.is_admin, l.source, l.ip, l.user_agent, l.created_at
         FROM login_log l
         LEFT JOIN fire_departments d ON d.id = l.department_id
         ORDER BY l.created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$limit, $offset]);
}

$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['is_admin'] = (bool) $r['is_admin'];
}
jsonResponse($rows);
