<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$deptId = requireAuth();
$db = getDb();
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

$allowedOsmTypes = ['node', 'way', 'relation'];

if ($method === 'GET') {
    if ($action === 'map_data') {
        $stmt = $db->prepare(
            'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                    building_name, building_address, building_lat, building_lng
             FROM substation_assignments
             WHERE department_id = ? AND building_lat IS NOT NULL AND building_lng IS NOT NULL'
        );
        $stmt->execute([$deptId]);
        jsonResponse($stmt->fetchAll());
    }

    $osmType = $_GET['osm_type'] ?? '';
    $osmId   = (int) ($_GET['osm_id'] ?? 0);

    if ($osmType && $osmId > 0) {
        if (!in_array($osmType, $allowedOsmTypes)) jsonError('Ungültiger osm_type');
        $stmt = $db->prepare(
            'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                    building_name, building_address, building_lat, building_lng, notes, updated_at
             FROM substation_assignments
             WHERE department_id = ? AND substation_osm_type = ? AND substation_osm_id = ?
             ORDER BY building_name ASC'
        );
        $stmt->execute([$deptId, $osmType, $osmId]);
        jsonResponse($stmt->fetchAll());
    }

    $stmt = $db->prepare(
        'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                building_name, building_address, building_lat, building_lng, notes, updated_at
         FROM substation_assignments
         WHERE department_id = ?
         ORDER BY substation_osm_id ASC, building_name ASC'
    );
    $stmt->execute([$deptId]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'create') {
        $osmType      = trim($body['substation_osm_type'] ?? '');
        $osmId        = (int) ($body['substation_osm_id'] ?? 0);
        $buildingName = trim($body['building_name'] ?? '');
        if (!in_array($osmType, $allowedOsmTypes) || $osmId <= 0) jsonError('Ungültige Trafostation-Angabe');
        if (!$buildingName) jsonError('Gebäudename ist Pflichtfeld');

        $stmt = $db->prepare(
            'INSERT INTO substation_assignments
             (department_id, substation_osm_type, substation_osm_id, substation_name,
              building_name, building_address, building_lat, building_lng, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deptId, $osmType, $osmId,
            trim($body['substation_name'] ?? ''),
            $buildingName,
            trim($body['building_address'] ?? ''),
            isset($body['building_lat']) ? (float) $body['building_lat'] : null,
            isset($body['building_lng']) ? (float) $body['building_lng'] : null,
            trim($body['notes'] ?? ''),
        ]);
        jsonResponse(['id' => (int) $db->lastInsertId()], 201);
    }

    if ($action === 'update') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('Ungültige ID');
        $stmt = $db->prepare(
            'UPDATE substation_assignments
             SET building_name=?, building_address=?, building_lat=?, building_lng=?, notes=?
             WHERE id=? AND department_id=?'
        );
        $stmt->execute([
            trim($body['building_name'] ?? ''),
            trim($body['building_address'] ?? ''),
            isset($body['building_lat']) ? (float) $body['building_lat'] : null,
            isset($body['building_lng']) ? (float) $body['building_lng'] : null,
            trim($body['notes'] ?? ''),
            $id, $deptId,
        ]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('Ungültige ID');
        $stmt = $db->prepare('DELETE FROM substation_assignments WHERE id=? AND department_id=?');
        $stmt->execute([$id, $deptId]);
        jsonResponse(['ok' => true]);
    }

    jsonError('Unbekannte Aktion');
}

jsonError('Method not allowed', 405);
