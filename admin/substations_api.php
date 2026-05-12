<?php
/**
 * admin/substations_api.php – API für Gebäude-Trafostation-Zuordnungen
 *
 * Alle Endpoints erfordern einen gültigen Bearer-Token.
 *
 * GET    ?action=list                              → alle Zuordnungen der Feuerwehr
 * GET    ?action=list&osm_type=node&osm_id=12345   → Zuordnungen einer bestimmten Station
 * GET    ?action=map_data                          → Zuordnungen mit Koordinaten (für Kartenoverlay)
 * POST   ?action=create                            → neue Zuordnung
 * POST   ?action=update&id=X                       → Zuordnung aktualisieren
 * POST   ?action=delete&id=X                       → Zuordnung löschen
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/auth_helper.php';

function ensureSchema()
{
    $db = getDb();

    // Check if table exists
    try {
        $db->query('SELECT 1 FROM substation_assignments LIMIT 1');
        return;
    } catch (PDOException $e) {
        // Table doesn't exist, continue to create it
    }

    // Check if fire_departments exists (required foreign key)
    try {
        $db->query('SELECT 1 FROM fire_departments LIMIT 1');
    } catch (PDOException $e) {
        throw new Exception('fire_departments table not found. Database schema not initialized.');
    }

    $schemaFile = __DIR__ . '/../config/schema_substations.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: $schemaFile");
    }

    $schema = file_get_contents($schemaFile);
    if (!$schema) {
        throw new Exception("Failed to read schema file: $schemaFile");
    }

    // Strip single-line SQL comments, then split by semicolon
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($stmt) => strlen($stmt) > 0
    );

    foreach ($statements as $stmt) {
        try {
            $db->exec($stmt);
        } catch (PDOException $e) {
            throw new Exception("Failed to execute SQL: " . $e->getMessage());
        }
    }
}

try {
    $auth    = requireAuth();
    $isAdmin = $auth['is_admin'];
    $deptId  = $auth['dept_id'];
    $action  = $_GET['action'] ?? '';
    ensureSchema();
} catch (Exception $e) {
    jsonResponse(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
}

try { switch ($action) {

    // ── Alle Zuordnungen abrufen ─────────────────────────────────────────
    case 'list':
        $osmType = $_GET['osm_type'] ?? '';
        $osmId   = (int) ($_GET['osm_id'] ?? 0);

        if ($osmType !== '' && $osmId > 0) {
            $allowedTypes = ['node', 'way', 'relation'];
            if (!in_array($osmType, $allowedTypes, true)) {
                jsonResponse(['error' => 'Ungültiger osm_type'], 400);
            }
            if ($isAdmin) {
                $stmt = getDb()->prepare(
                    'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                            building_name, building_address, building_lat, building_lng, notes, updated_at
                     FROM substation_assignments
                     WHERE substation_osm_type = ? AND substation_osm_id = ?
                     ORDER BY building_name ASC'
                );
                $stmt->execute([$osmType, $osmId]);
            } else {
                $stmt = getDb()->prepare(
                    'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                            building_name, building_address, building_lat, building_lng, notes, updated_at
                     FROM substation_assignments
                     WHERE department_id = ? AND substation_osm_type = ? AND substation_osm_id = ?
                     ORDER BY building_name ASC'
                );
                $stmt->execute([$deptId, $osmType, $osmId]);
            }
        } else {
            if ($isAdmin) {
                $stmt = getDb()->prepare(
                    'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                            building_name, building_address, building_lat, building_lng, notes, updated_at
                     FROM substation_assignments
                     ORDER BY substation_osm_id ASC, building_name ASC'
                );
                $stmt->execute([]);
            } else {
                $stmt = getDb()->prepare(
                    'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                            building_name, building_address, building_lat, building_lng, notes, updated_at
                     FROM substation_assignments
                     WHERE department_id = ?
                     ORDER BY substation_osm_id ASC, building_name ASC'
                );
                $stmt->execute([$deptId]);
            }
        }
        jsonResponse($stmt->fetchAll());
        break;

    // ── Kartendaten (nur Einträge mit Koordinaten) ───────────────────────
    case 'map_data':
        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                        building_name, building_address, building_lat, building_lng
                 FROM substation_assignments
                 WHERE building_lat IS NOT NULL AND building_lng IS NOT NULL'
            );
            $stmt->execute([]);
        } else {
            $stmt = getDb()->prepare(
                'SELECT id, substation_osm_type, substation_osm_id, substation_name,
                        building_name, building_address, building_lat, building_lng
                 FROM substation_assignments
                 WHERE department_id = ? AND building_lat IS NOT NULL AND building_lng IS NOT NULL'
            );
            $stmt->execute([$deptId]);
        }
        jsonResponse($stmt->fetchAll());
        break;

    // ── Neue Zuordnung erstellen ─────────────────────────────────────────
    case 'create':
        $data = json_decode(file_get_contents('php://input'), true);

        $osmType      = trim($data['substation_osm_type'] ?? '');
        $osmId        = (int) ($data['substation_osm_id'] ?? 0);
        $buildingName = trim($data['building_name'] ?? '');

        $allowedTypes = ['node', 'way', 'relation'];
        if (!in_array($osmType, $allowedTypes, true) || $osmId <= 0) {
            jsonResponse(['error' => 'Ungültige Trafostation-Angabe'], 400);
        }
        if ($buildingName === '') {
            jsonResponse(['error' => 'Gebäudename ist Pflichtfeld'], 400);
        }

        if ($isAdmin) {
            $deptId = (int) ($data['dept_id'] ?? 0);
            if ($deptId <= 0)
                jsonResponse(['error' => 'dept_id erforderlich'], 400);
        }

        $stmt = getDb()->prepare(
            'INSERT INTO substation_assignments
             (department_id, substation_osm_type, substation_osm_id, substation_name,
              building_name, building_address, building_lat, building_lng, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deptId,
            $osmType,
            $osmId,
            trim($data['substation_name'] ?? '') ?: null,
            $buildingName,
            trim($data['building_address'] ?? '') ?: null,
            isset($data['building_lat']) && $data['building_lat'] !== '' ? (float) $data['building_lat'] : null,
            isset($data['building_lng']) && $data['building_lng'] !== '' ? (float) $data['building_lng'] : null,
            trim($data['notes'] ?? '') ?: null,
        ]);
        jsonResponse(['id' => (int) getDb()->lastInsertId()], 201);
        break;

    // ── Zuordnung aktualisieren ──────────────────────────────────────────
    case 'update':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(['error' => 'Ungültige ID'], 400);

        $data         = json_decode(file_get_contents('php://input'), true);
        $buildingName = trim($data['building_name'] ?? '');
        if ($buildingName === '') jsonResponse(['error' => 'Gebäudename ist Pflichtfeld'], 400);

        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'UPDATE substation_assignments
                 SET substation_name=?, building_name=?, building_address=?,
                     building_lat=?, building_lng=?, notes=?
                 WHERE id=?'
            );
            $stmt->execute([
                trim($data['substation_name'] ?? '') ?: null,
                $buildingName,
                trim($data['building_address'] ?? '') ?: null,
                isset($data['building_lat']) && $data['building_lat'] !== '' ? (float) $data['building_lat'] : null,
                isset($data['building_lng']) && $data['building_lng'] !== '' ? (float) $data['building_lng'] : null,
                trim($data['notes'] ?? '') ?: null,
                $id,
            ]);
        } else {
            $stmt = getDb()->prepare(
                'UPDATE substation_assignments
                 SET substation_name=?, building_name=?, building_address=?,
                     building_lat=?, building_lng=?, notes=?
                 WHERE id=? AND department_id=?'
            );
            $stmt->execute([
                trim($data['substation_name'] ?? '') ?: null,
                $buildingName,
                trim($data['building_address'] ?? '') ?: null,
                isset($data['building_lat']) && $data['building_lat'] !== '' ? (float) $data['building_lat'] : null,
                isset($data['building_lng']) && $data['building_lng'] !== '' ? (float) $data['building_lng'] : null,
                trim($data['notes'] ?? '') ?: null,
                $id,
                $deptId,
            ]);
        }
        jsonResponse(['ok' => true]);
        break;

    // ── Zuordnung löschen ────────────────────────────────────────────────
    case 'delete':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(['error' => 'Ungültige ID'], 400);

        if ($isAdmin) {
            getDb()->prepare('DELETE FROM substation_assignments WHERE id=?')->execute([$id]);
        } else {
            getDb()->prepare('DELETE FROM substation_assignments WHERE id=? AND department_id=?')
                ->execute([$id, $deptId]);
        }
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 400);
} } catch (Exception $e) {
    jsonResponse(['error' => 'Serverfehler: ' . $e->getMessage()], 500);
}
