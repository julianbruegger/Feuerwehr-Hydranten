<?php
/**
 * admin/wtp_api.php – WasserTransportPlan API
 *
 * Alle Endpoints erfordern gültigen Bearer-Token.
 *
 * GET  ?action=list                   → alle Pläne der Feuerwehr
 * GET  ?action=map_data               → vereinfachte Liste für Kartenmarker (öffentliche Coords + Name)
 * POST ?action=create                 → neuen Plan erstellen
 * POST ?action=update&id=X            → Plan aktualisieren
 * POST ?action=delete&id=X            → Plan + alle Dateien löschen
 * POST ?action=upload&plan_id=X       → Datei hochladen (multipart/form-data)
 * GET  ?action=download&file_id=X     → Datei herunterladen (Auth-geschützt)
 * POST ?action=file_delete&file_id=X  → Einzelne Datei löschen
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/auth_helper.php';

define('UPLOAD_DIR', __DIR__ . '/../uploads/wtp/');
// Erlaubte Dateitypen für Upload
define('ALLOWED_MIME', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
]);
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB

/**
 * Gibt den Auth-Token aus Header ODER GET-Parameter zurück.
 * GET-Parameter wird nur für den Download-Endpoint benötigt
 * (Browser-Links können keine Custom-Header senden).
 */
function getToken()
{
    // Bearer-Header (bevorzugt)
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $header, $m))
        return $m[1];
    // GET-Fallback für Download-Links
    return isset($_GET['token']) && preg_match('/^[a-f0-9]{64}$/', $_GET['token'])
        ? $_GET['token'] : null;
}

$action = $_GET['action'] ?? '';

// map_data: Token aus GET/Header akzeptieren, keine harte Auth-Pflicht
if ($action === 'map_data') {
    serveMapData();
}

function ensureWtpObjectsSchema()
{
    $db = getDb();
    try {
        $db->query('SELECT 1 FROM wtp_map_objects LIMIT 1');
        return;
    } catch (PDOException $e) {
        // table missing – create it
    }
    $schemaFile = __DIR__ . '/../config/schema_wtp_objects.sql';
    $schema = file_get_contents($schemaFile);
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    $statements = array_filter(array_map('trim', explode(';', $schema)), fn($s) => strlen($s) > 0);
    foreach ($statements as $stmt) {
        $db->exec($stmt);
    }
}

// Alle anderen Endpoints: Token aus Header ODER GET-Param prüfen
$tokenStr = getToken();
$authInfo = $tokenStr ? validateToken($tokenStr) : null;
if ($authInfo === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Nicht authentifiziert']);
    exit;
}
$isAdmin = $authInfo['is_admin'];
$deptId  = $authInfo['dept_id'];

try {
    ensureWtpObjectsSchema();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
    exit;
}

switch ($action) {

    // ── Alle Pläne abrufen ───────────────────────────────────────────────
    case 'list':
        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'SELECT p.id, p.name, p.description, p.lat, p.lng, p.updated_at,
                        COUNT(f.id) AS file_count
                 FROM wasser_transport_plans p
                 LEFT JOIN wtp_files f ON f.plan_id = p.id
                 GROUP BY p.id
                 ORDER BY p.updated_at DESC'
            );
            $stmt->execute([]);
        } else {
            $stmt = getDb()->prepare(
                'SELECT p.id, p.name, p.description, p.lat, p.lng, p.updated_at,
                        COUNT(f.id) AS file_count
                 FROM wasser_transport_plans p
                 LEFT JOIN wtp_files f ON f.plan_id = p.id
                 WHERE p.department_id = ?
                 GROUP BY p.id
                 ORDER BY p.updated_at DESC'
            );
            $stmt->execute([$deptId]);
        }
        jsonResponse($stmt->fetchAll());
        break;

    // ── Dateien eines Plans abrufen ──────────────────────────────────────
    case 'files':
        $planId = (int) ($_GET['plan_id'] ?? 0);
        $check = $isAdmin
            ? getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=?')
            : getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute($isAdmin ? [$planId] : [$planId, $deptId]);
        if (!$check->fetch())
            jsonResponse(['error' => 'Nicht gefunden'], 404);

        $stmt = getDb()->prepare(
            'SELECT id, original_name, mime_type, file_size, uploaded_at
             FROM wtp_files WHERE plan_id = ? ORDER BY uploaded_at DESC'
        );
        $stmt->execute([$planId]);
        jsonResponse($stmt->fetchAll());
        break;

    // ── Plan erstellen ───────────────────────────────────────────────────
    case 'create':
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        if ($name === '')
            jsonResponse(['error' => 'Plan-Name erforderlich'], 400);

        if ($isAdmin) {
            $deptId = (int) ($data['dept_id'] ?? 0);
            if ($deptId <= 0)
                jsonResponse(['error' => 'dept_id erforderlich'], 400);
        }

        $stmt = getDb()->prepare(
            'INSERT INTO wasser_transport_plans (department_id, name, description, lat, lng)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deptId,
            $name,
            trim($data['description'] ?? ''),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        ]);
        jsonResponse(['id' => (int) getDb()->lastInsertId()], 201);
        break;

    // ── Plan aktualisieren ───────────────────────────────────────────────
    case 'update':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0)
            jsonResponse(['error' => 'Ungültige ID'], 400);
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        if ($name === '')
            jsonResponse(['error' => 'Plan-Name erforderlich'], 400);

        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'UPDATE wasser_transport_plans SET name=?, description=?, lat=?, lng=? WHERE id=?'
            );
            $stmt->execute([
                $name,
                trim($data['description'] ?? ''),
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
                $id,
            ]);
        } else {
            $stmt = getDb()->prepare(
                'UPDATE wasser_transport_plans
                 SET name=?, description=?, lat=?, lng=?
                 WHERE id=? AND department_id=?'
            );
            $stmt->execute([
                $name,
                trim($data['description'] ?? ''),
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
                $id,
                $deptId,
            ]);
        }
        jsonResponse(['ok' => true]);
        break;

    // ── Plan löschen (inkl. Dateien) ─────────────────────────────────────
    case 'delete':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0)
            jsonResponse(['error' => 'Ungültige ID'], 400);
        // Dateien vom Dateisystem löschen
        $stmt = getDb()->prepare('SELECT stored_name FROM wtp_files WHERE plan_id=?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $row) {
            @unlink(UPLOAD_DIR . $row['stored_name']);
        }
        // DB-Eintrag löschen (Cascade löscht auch wtp_files)
        if ($isAdmin) {
            getDb()->prepare('DELETE FROM wasser_transport_plans WHERE id=?')->execute([$id]);
        } else {
            getDb()->prepare('DELETE FROM wasser_transport_plans WHERE id=? AND department_id=?')
                ->execute([$id, $deptId]);
        }
        jsonResponse(['ok' => true]);
        break;

    // ── Datei hochladen ──────────────────────────────────────────────────
    case 'upload':
        $planId = (int) ($_GET['plan_id'] ?? 0);
        $check = $isAdmin
            ? getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=?')
            : getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute($isAdmin ? [$planId] : [$planId, $deptId]);
        if (!$check->fetch())
            jsonResponse(['error' => 'Nicht gefunden'], 404);

        if (empty($_FILES['file']))
            jsonResponse(['error' => 'Keine Datei'], 400);
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK)
            jsonResponse(['error' => 'Upload-Fehler ' . $file['error']], 400);
        if ($file['size'] > MAX_FILE_SIZE)
            jsonResponse(['error' => 'Datei zu groß (max 20 MB)'], 400);

        // MIME-Typ prüfen (serverseitig, nicht clientseitig)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, ALLOWED_MIME, true)) {
            jsonResponse(['error' => 'Dateityp nicht erlaubt: ' . $mimeType], 400);
        }

        // Sicherer Dateiname (UUID-basiert)
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $storedName = bin2hex(random_bytes(16)) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $destPath = UPLOAD_DIR . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonResponse(['error' => 'Speicherfehler'], 500);
        }

        $stmt = getDb()->prepare(
            'INSERT INTO wtp_files (plan_id, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$planId, $file['name'], $storedName, $mimeType, $file['size']]);
        jsonResponse(['id' => (int) getDb()->lastInsertId(), 'name' => $file['name']], 201);
        break;

    // ── Datei herunterladen (Auth-geschützt) ─────────────────────────────
    case 'download':
        $fileId = (int) ($_GET['file_id'] ?? 0);
        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'SELECT stored_name, original_name, mime_type FROM wtp_files WHERE id = ?'
            );
            $stmt->execute([$fileId]);
        } else {
            $stmt = getDb()->prepare(
                'SELECT f.stored_name, f.original_name, f.mime_type
                 FROM wtp_files f
                 JOIN wasser_transport_plans p ON p.id = f.plan_id
                 WHERE f.id = ? AND p.department_id = ?'
            );
            $stmt->execute([$fileId, $deptId]);
        }
        $row = $stmt->fetch();
        if (!$row)
            jsonResponse(['error' => 'Datei nicht gefunden'], 404);

        $path = UPLOAD_DIR . $row['stored_name'];
        if (!file_exists($path))
            jsonResponse(['error' => 'Datei fehlt auf Server'], 404);

        header('Content-Type: ' . $row['mime_type']);
        header('Content-Disposition: inline; filename="' . addslashes($row['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;

    // ── Einzelne Datei löschen ───────────────────────────────────────────
    case 'file_delete':
        // Frontend sendet file-ID als &id= Parameter
        $fileId = (int) ($_GET['id'] ?? $_GET['file_id'] ?? 0);
        if ($fileId <= 0)
            jsonResponse(['error' => 'Ungültige Datei-ID'], 400);
        if ($isAdmin) {
            $stmt = getDb()->prepare('SELECT stored_name FROM wtp_files WHERE id=?');
            $stmt->execute([$fileId]);
        } else {
            $stmt = getDb()->prepare(
                'SELECT f.stored_name FROM wtp_files f
                 JOIN wasser_transport_plans p ON p.id = f.plan_id
                 WHERE f.id=? AND p.department_id=?'
            );
            $stmt->execute([$fileId, $deptId]);
        }
        $row = $stmt->fetch();
        if (!$row)
            jsonResponse(['error' => 'Nicht gefunden'], 404);

        @unlink(UPLOAD_DIR . $row['stored_name']);
        getDb()->prepare('DELETE FROM wtp_files WHERE id=?')->execute([$fileId]);
        jsonResponse(['ok' => true]);
        break;

    // ── Kartenobjekte abrufen ────────────────────────────────────────────
    case 'map_objects':
        $planId = (int) ($_GET['plan_id'] ?? 0);
        if ($planId <= 0) jsonResponse(['error' => 'plan_id erforderlich'], 400);
        $check = $isAdmin
            ? getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=?')
            : getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute($isAdmin ? [$planId] : [$planId, $deptId]);
        if (!$check->fetch()) jsonResponse(['error' => 'Nicht gefunden'], 404);

        $objs = getDb()->prepare('SELECT id, type, name, lat, lng FROM wtp_map_objects WHERE plan_id=? ORDER BY id');
        $objs->execute([$planId]);
        $hoses = getDb()->prepare('SELECT id, coordinates FROM wtp_map_hoses WHERE plan_id=? ORDER BY id');
        $hoses->execute([$planId]);
        $texts = getDb()->prepare('SELECT id, label, lat, lng FROM wtp_map_texts WHERE plan_id=? ORDER BY id');
        $texts->execute([$planId]);

        $hosesData = array_map(function ($h) {
            $h['coordinates'] = json_decode($h['coordinates'], true);
            return $h;
        }, $hoses->fetchAll());

        jsonResponse([
            'objects' => $objs->fetchAll(),
            'hoses'   => $hosesData,
            'texts'   => $texts->fetchAll(),
        ]);
        break;

    // ── Kartenobjekte speichern (ersetzt alle bestehenden) ───────────────
    case 'save_map_objects':
        $planId = (int) ($_GET['plan_id'] ?? 0);
        if ($planId <= 0) jsonResponse(['error' => 'plan_id erforderlich'], 400);
        $check = $isAdmin
            ? getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=?')
            : getDb()->prepare('SELECT id FROM wasser_transport_plans WHERE id=? AND department_id=?');
        $check->execute($isAdmin ? [$planId] : [$planId, $deptId]);
        if (!$check->fetch()) jsonResponse(['error' => 'Nicht gefunden'], 404);

        $data = json_decode(file_get_contents('php://input'), true);
        $db   = getDb();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM wtp_map_objects WHERE plan_id=?')->execute([$planId]);
            $db->prepare('DELETE FROM wtp_map_hoses WHERE plan_id=?')->execute([$planId]);
            $db->prepare('DELETE FROM wtp_map_texts WHERE plan_id=?')->execute([$planId]);

            $stmtObj = $db->prepare(
                'INSERT INTO wtp_map_objects (plan_id, type, name, lat, lng) VALUES (?,?,?,?,?)'
            );
            foreach ((array)($data['objects'] ?? []) as $o) {
                $type = in_array($o['type'], ['TLF', 'Motorspritze'], true) ? $o['type'] : 'TLF';
                $stmtObj->execute([
                    $planId,
                    $type,
                    mb_substr(trim($o['name'] ?? $type), 0, 255),
                    (float) $o['lat'],
                    (float) $o['lng'],
                ]);
            }

            $stmtHose = $db->prepare(
                'INSERT INTO wtp_map_hoses (plan_id, coordinates) VALUES (?,?)'
            );
            foreach ((array)($data['hoses'] ?? []) as $h) {
                if (!is_array($h['coordinates']) || count($h['coordinates']) < 2) continue;
                $stmtHose->execute([$planId, json_encode($h['coordinates'])]);
            }

            $stmtTxt = $db->prepare(
                'INSERT INTO wtp_map_texts (plan_id, label, lat, lng) VALUES (?,?,?,?)'
            );
            foreach ((array)($data['texts'] ?? []) as $t) {
                $label = mb_substr(trim($t['label'] ?? ''), 0, 500);
                if ($label === '') continue;
                $stmtTxt->execute([$planId, $label, (float) $t['lat'], (float) $t['lng']]);
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
        }
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 400);
}

// ── Öffentliche Kartendaten ──────────────────────────────────────────────────
// Gibt nur Name + Koordinaten zurück, keine sensiblen Notizen/Dateien.
// Benötigt gültigen Token um die Daten der eigenen Feuerwehr abzurufen.
function serveMapData()
{
    $token    = getToken();
    $authInfo = $token ? validateToken($token) : null;
    if ($authInfo === null)
        jsonResponse([], 200); // Nicht eingeloggt → leere Liste

    $isAdmin = $authInfo['is_admin'];
    $deptId  = $authInfo['dept_id'];

    if ($isAdmin) {
        $stmt = getDb()->prepare(
            'SELECT id, name, lat, lng FROM wasser_transport_plans
             WHERE lat IS NOT NULL AND lng IS NOT NULL'
        );
        $stmt->execute([]);
    } else {
        $stmt = getDb()->prepare(
            'SELECT id, name, lat, lng FROM wasser_transport_plans
             WHERE department_id = ? AND lat IS NOT NULL AND lng IS NOT NULL'
        );
        $stmt->execute([$deptId]);
    }
    $plans = $stmt->fetchAll();

    // Kartenobjekte pro Plan anhängen
    $db = getDb();
    foreach ($plans as &$plan) {
        $pid = $plan['id'];

        $objs = $db->prepare('SELECT type, name, lat, lng FROM wtp_map_objects WHERE plan_id=? ORDER BY id');
        $objs->execute([$pid]);

        $hoses = $db->prepare('SELECT coordinates FROM wtp_map_hoses WHERE plan_id=? ORDER BY id');
        $hoses->execute([$pid]);

        $texts = $db->prepare('SELECT label, lat, lng FROM wtp_map_texts WHERE plan_id=? ORDER BY id');
        $texts->execute([$pid]);

        $hosesData = array_map(function ($h) {
            $h['coordinates'] = json_decode($h['coordinates'], true);
            return $h;
        }, $hoses->fetchAll());

        $plan['map_objects'] = $objs->fetchAll();
        $plan['map_hoses']   = $hosesData;
        $plan['map_texts']   = $texts->fetchAll();
    }
    unset($plan);

    jsonResponse($plans);
}
