<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$deptId = requireAuth();
$db = getDb();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$allowedCategories = ['schluessel', 'gefahrgut', 'gebaeude', 'kontakt', 'sonstiges'];

if ($method === 'GET') {
    $stmt = $db->prepare(
        'SELECT id, title, address, lat, lng, category, content, updated_at
         FROM sensitive_notes
         WHERE department_id = ?
         ORDER BY updated_at DESC'
    );
    $stmt->execute([$deptId]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'create') {
        $title   = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        if (!$title || !$content) jsonError('Titel und Notiz sind Pflichtfelder');
        $category = in_array($body['category'] ?? '', $allowedCategories) ? $body['category'] : 'sonstiges';
        $address  = trim($body['address'] ?? '');
        $lat      = isset($body['lat']) ? (float) $body['lat'] : null;
        $lng      = isset($body['lng']) ? (float) $body['lng'] : null;

        $stmt = $db->prepare(
            'INSERT INTO sensitive_notes (department_id, title, address, lat, lng, category, content)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$deptId, $title, $address, $lat, $lng, $category, $content]);
        jsonResponse(['id' => (int) $db->lastInsertId()], 201);
    }

    if ($action === 'update') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('Ungültige ID');
        $title   = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        if (!$title || !$content) jsonError('Titel und Notiz sind Pflichtfelder');
        $category = in_array($body['category'] ?? '', $allowedCategories) ? $body['category'] : 'sonstiges';
        $address  = trim($body['address'] ?? '');
        $lat      = isset($body['lat']) ? (float) $body['lat'] : null;
        $lng      = isset($body['lng']) ? (float) $body['lng'] : null;

        $stmt = $db->prepare(
            'UPDATE sensitive_notes
             SET title=?, address=?, lat=?, lng=?, category=?, content=?
             WHERE id=? AND department_id=?'
        );
        $stmt->execute([$title, $address, $lat, $lng, $category, $content, $id, $deptId]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('Ungültige ID');
        $stmt = $db->prepare('DELETE FROM sensitive_notes WHERE id=? AND department_id=?');
        $stmt->execute([$id, $deptId]);
        jsonResponse(['ok' => true]);
    }

    jsonError('Unbekannte Aktion');
}

jsonError('Method not allowed', 405);
