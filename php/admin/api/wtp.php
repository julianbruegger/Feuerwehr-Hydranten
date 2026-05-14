<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$deptId = requireAuth();
$db     = getDb();
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

// Files are stored in php/uploads/ relative to this file
$uploadsDir = __DIR__ . '/../../uploads/';

$allowedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$maxFileSize = 20 * 1024 * 1024;

if ($method === 'GET') {
    if ($action === 'list') {
        $stmt = $db->prepare(
            'SELECT p.id, p.name, p.description, p.lat, p.lng, p.updated_at,
                    COUNT(f.id) AS file_count
             FROM wasser_transport_plans p
             LEFT JOIN wtp_files f ON f.plan_id = p.id
             WHERE p.department_id = ?
             GROUP BY p.id
             ORDER BY p.updated_at DESC'
        );
        $stmt->execute([$deptId]);
        jsonResponse($stmt->fetchAll());
    }

    if ($action === 'map_data') {
        $stmt = $db->prepare(
            'SELECT id, name, lat, lng
             FROM wasser_transport_plans
             WHERE department_id = ? AND lat IS NOT NULL AND lng IS NOT NULL'
        );
        $stmt->execute([$deptId]);
        jsonResponse($stmt->fetchAll());
    }

    if ($action === 'files') {
        $planId = (int) ($_GET['plan_id'] ?? 0);
        $check = $db->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute([$planId, $deptId]);
        if (!$check->fetch()) jsonError('Nicht gefunden', 404);

        $stmt = $db->prepare(
            'SELECT id, original_name, mime_type, file_size, uploaded_at
             FROM wtp_files WHERE plan_id = ? ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$planId]);
        jsonResponse($stmt->fetchAll());
    }

    if ($action === 'download') {
        $fileId = (int) ($_GET['file_id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT f.stored_name, f.original_name, f.mime_type
             FROM wtp_files f
             JOIN wasser_transport_plans p ON p.id = f.plan_id
             WHERE f.id = ? AND p.department_id = ?'
        );
        $stmt->execute([$fileId, $deptId]);
        $file = $stmt->fetch();
        if (!$file) jsonError('Datei nicht gefunden', 404);

        $path = $uploadsDir . $file['stored_name'];
        if (!file_exists($path)) jsonError('Datei nicht auf dem Server gefunden', 404);

        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . rawurlencode($file['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('Content-Type: application/json; charset=utf-8', true, 200); // override
        // Actually send the file
        header_remove('Content-Type');
        header('Content-Type: ' . $file['mime_type']);
        readfile($path);
        exit;
    }

    jsonError('Unbekannte Aktion');
}

if ($method === 'POST') {
    // File upload (multipart)
    if ($action === 'upload') {
        $planId = (int) ($_GET['plan_id'] ?? 0);
        $check = $db->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute([$planId, $deptId]);
        if (!$check->fetch()) jsonError('Nicht gefunden', 404);

        if (empty($_FILES['file'])) jsonError('Keine Datei empfangen');
        $upload = $_FILES['file'];

        if ($upload['error'] !== UPLOAD_ERR_OK) jsonError('Upload-Fehler');
        if ($upload['size'] > $maxFileSize) jsonError('Datei zu groß (max 20 MB)');

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($upload['tmp_name']);
        if (!in_array($mimeType, $allowedMimeTypes)) jsonError('Dateityp nicht erlaubt');

        $ext        = pathinfo($upload['name'], PATHINFO_EXTENSION);
        $storedName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
        $destPath   = $uploadsDir . $storedName;

        if (!move_uploaded_file($upload['tmp_name'], $destPath)) jsonError('Speichern fehlgeschlagen', 500);

        $stmt = $db->prepare(
            'INSERT INTO wtp_files (plan_id, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$planId, $upload['name'], $storedName, $mimeType, $upload['size']]);
        jsonResponse(['id' => (int) $db->lastInsertId()], 201);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'create') {
        $name = trim($body['name'] ?? '');
        if (!$name) jsonError('Name ist Pflichtfeld');
        $stmt = $db->prepare(
            'INSERT INTO wasser_transport_plans (department_id, name, description, lat, lng)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deptId, $name,
            trim($body['description'] ?? ''),
            isset($body['lat']) ? (float) $body['lat'] : null,
            isset($body['lng']) ? (float) $body['lng'] : null,
        ]);
        jsonResponse(['id' => (int) $db->lastInsertId()], 201);
    }

    if ($action === 'update') {
        $id   = (int) ($_GET['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        if ($id <= 0) jsonError('Ungültige ID');
        if (!$name) jsonError('Name ist Pflichtfeld');
        $stmt = $db->prepare(
            'UPDATE wasser_transport_plans
             SET name=?, description=?, lat=?, lng=?
             WHERE id=? AND department_id=?'
        );
        $stmt->execute([
            $name,
            trim($body['description'] ?? ''),
            isset($body['lat']) ? (float) $body['lat'] : null,
            isset($body['lng']) ? (float) $body['lng'] : null,
            $id, $deptId,
        ]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('Ungültige ID');

        // Delete physical files first
        $files = $db->prepare('SELECT stored_name FROM wtp_files WHERE plan_id = ?');
        $files->execute([$id]);
        foreach ($files->fetchAll() as $f) {
            $path = $uploadsDir . $f['stored_name'];
            if (file_exists($path)) unlink($path);
        }
        $db->prepare('DELETE FROM wtp_files WHERE plan_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM wasser_transport_plans WHERE id=? AND department_id=?')->execute([$id, $deptId]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'file_delete') {
        $fileId = (int) ($_GET['id'] ?? 0);
        if ($fileId <= 0) jsonError('Ungültige ID');
        $stmt = $db->prepare(
            'SELECT f.stored_name FROM wtp_files f
             JOIN wasser_transport_plans p ON p.id = f.plan_id
             WHERE f.id = ? AND p.department_id = ?'
        );
        $stmt->execute([$fileId, $deptId]);
        $file = $stmt->fetch();
        if (!$file) jsonError('Nicht gefunden', 404);

        $path = $uploadsDir . $file['stored_name'];
        if (file_exists($path)) unlink($path);
        $db->prepare('DELETE FROM wtp_files WHERE id = ?')->execute([$fileId]);
        jsonResponse(['ok' => true]);
    }

    jsonError('Unbekannte Aktion');
}

jsonError('Method not allowed', 405);
