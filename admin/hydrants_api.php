<?php
/**
 * admin/hydrants_api.php – Eigene Hydranten der Feuerwehr
 *
 * Für Gebiete ohne (vollständige) OSM-Daten: Hydranten aus dem Werkkataster
 * importieren oder manuell erfassen. Die Daten sind nur für eingeloggte
 * Mitglieder der jeweiligen Feuerwehr sichtbar (Admin: alle).
 *
 * GET  ?action=map_data[&dept_id=X]  → Hydranten für die Karte (ohne Login: leere Liste)
 * GET  ?action=list[&dept_id=X]      → alle Felder, für den Editor
 * POST ?action=create                → { lat, lng, type, ref, address, notes [, department_id] }
 * POST ?action=update&id=X           → gleiche Felder
 * POST ?action=delete&id=X
 * POST ?action=import                → { items: [...], mode: merge|replace, first: bool [, department_id] }
 * POST ?action=delete_imported       → alle importierten Hydranten löschen [department_id]
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/auth_helper.php';

const HYDRANT_TYPES      = ['underground', 'pillar', 'wall', 'pond', 'other'];
const HYDRANT_IMPORT_MAX = 2000; // Datensätze pro Import-Anfrage

function ensureHydrantSchema(): void
{
    $db = getDb();
    try {
        $db->query('SELECT 1 FROM department_hydrants LIMIT 1');
        return;
    } catch (PDOException $e) {
        // Tabelle fehlt – anlegen
    }
    $schema = file_get_contents(__DIR__ . '/../config/schema_hydrants.sql');
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        $db->exec($stmt);
    }
}

function hydrantStr($v, int $max): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return mb_substr($v, 0, $max);
}

/** Validiert ein Hydranten-Objekt aus dem Request. Gibt Felder oder null zurück. */
function hydrantFields(array $in): ?array
{
    $lat = filter_var($in['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($in['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }
    $type = in_array($in['type'] ?? '', HYDRANT_TYPES, true) ? $in['type'] : 'underground';
    return [
        'lat'     => round($lat, 7),
        'lng'     => round($lng, 7),
        'type'    => $type,
        'ref'     => hydrantStr($in['ref'] ?? null, 100),
        'address' => hydrantStr($in['address'] ?? null, 255),
        'notes'   => hydrantStr($in['notes'] ?? null, 500),
    ];
}

/**
 * Feuerwehr, auf die sich eine Anfrage bezieht.
 * Mitglieder: immer die eigene. Admin: die angefragte (bei Lesezugriff optional = alle).
 */
function hydrantDept(array $auth, $requested, bool $required): ?int
{
    if (!$auth['is_admin']) {
        if ($auth['dept_id'] === null) jsonResponse(['error' => 'Keine Feuerwehr zugeordnet'], 403);
        return $auth['dept_id'];
    }
    $id = (int) ($requested ?? 0);
    if ($id > 0) return $id;
    if ($auth['dept_id'] !== null) return $auth['dept_id'];
    if ($required) jsonResponse(['error' => 'Feuerwehr auswählen'], 400);
    return null;
}

$action = $_GET['action'] ?? '';
$token  = getTokenFromRequest();
$auth   = $token ? validateToken($token) : null;

if ($auth === null) {
    // Karte ohne Login: einfach keine eigenen Hydranten
    if ($action === 'map_data') jsonResponse([]);
    jsonResponse(['error' => 'Nicht authentifiziert'], 401);
}

try {
    ensureHydrantSchema();
} catch (Exception $e) {
    jsonResponse(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
}

$db   = getDb();
$body = [];
if (in_array($action, ['create', 'update', 'delete', 'import', 'delete_imported'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'POST erforderlich'], 405);
    $raw  = file_get_contents('php://input');
    $body = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) jsonResponse(['error' => 'Ungültiger JSON-Body'], 400);
}

switch ($action) {

    // ── Karte: kompakt im Overpass-Format ────────────────────────────────
    case 'map_data':
    case 'list':
        $dept = hydrantDept($auth, $_GET['dept_id'] ?? null, false);
        $sql  = 'SELECT id, department_id, lat, lng, type, ref, address, notes, source, updated_at
                 FROM department_hydrants' . ($dept !== null ? ' WHERE department_id = ?' : '') . '
                 ORDER BY id';
        $stmt = $db->prepare($sql);
        $stmt->execute($dept !== null ? [$dept] : []);
        $rows = $stmt->fetchAll();

        if ($action === 'list') jsonResponse($rows);

        $elements = array_map(function ($r) {
            $tags = ['emergency' => 'fire_hydrant', 'fire_hydrant:type' => $r['type'], 'source' => 'department'];
            if ($r['ref'])     $tags['ref'] = $r['ref'];
            if ($r['address']) $tags['addr:full'] = $r['address'];
            if ($r['notes'])   $tags['note'] = $r['notes'];
            return ['id' => 'd' . $r['id'], 'lat' => (float) $r['lat'], 'lon' => (float) $r['lng'], 'tags' => $tags];
        }, $rows);
        jsonResponse($elements);
        break;

    // ── Einzelne Hydranten ───────────────────────────────────────────────
    case 'create':
        $f = hydrantFields($body);
        if (!$f) jsonResponse(['error' => 'Ungültige Koordinaten'], 400);
        $dept = hydrantDept($auth, $body['department_id'] ?? null, true);
        $db->prepare(
            'INSERT INTO department_hydrants (department_id, lat, lng, type, ref, address, notes, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\')'
        )->execute([$dept, $f['lat'], $f['lng'], $f['type'], $f['ref'], $f['address'], $f['notes']]);
        jsonResponse(['id' => (int) $db->lastInsertId()], 201);
        break;

    case 'update':
        $id = (int) ($_GET['id'] ?? 0);
        $f  = hydrantFields($body);
        if ($id <= 0 || !$f) jsonResponse(['error' => 'Ungültige Daten'], 400);
        $sql = 'UPDATE department_hydrants SET lat=?, lng=?, type=?, ref=?, address=?, notes=? WHERE id=?';
        $params = [$f['lat'], $f['lng'], $f['type'], $f['ref'], $f['address'], $f['notes'], $id];
        if (!$auth['is_admin']) { $sql .= ' AND department_id=?'; $params[] = $auth['dept_id']; }
        $db->prepare($sql)->execute($params);
        jsonResponse(['ok' => true]);
        break;

    case 'delete':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(['error' => 'Ungültige ID'], 400);
        $sql = 'DELETE FROM department_hydrants WHERE id=?';
        $params = [$id];
        if (!$auth['is_admin']) { $sql .= ' AND department_id=?'; $params[] = $auth['dept_id']; }
        $db->prepare($sql)->execute($params);
        jsonResponse(['ok' => true]);
        break;

    // ── Import (vom Browser bereits geparst, in Blöcken) ─────────────────
    case 'import':
        $dept  = hydrantDept($auth, $body['department_id'] ?? null, true);
        $items = $body['items'] ?? null;
        if (!is_array($items) || count($items) > HYDRANT_IMPORT_MAX) {
            jsonResponse(['error' => 'items fehlt oder zu viele (max. ' . HYDRANT_IMPORT_MAX . ')'], 400);
        }

        $db->beginTransaction();
        try {
            $removed = 0;
            if (($body['mode'] ?? 'merge') === 'replace' && !empty($body['first'])) {
                $del = $db->prepare('DELETE FROM department_hydrants WHERE department_id=? AND source=\'import\'');
                $del->execute([$dept]);
                $removed = $del->rowCount();
            }

            // Wiederholter Import aktualisiert statt dupliziert: Schlüssel ist die
            // ID aus der Quelle, sonst die gerundete Position.
            $stmt = $db->prepare(
                'INSERT INTO department_hydrants
                    (department_id, lat, lng, type, ref, address, notes, source, external_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'import\', ?)
                 ON DUPLICATE KEY UPDATE
                    lat=VALUES(lat), lng=VALUES(lng), type=VALUES(type), ref=VALUES(ref),
                    address=VALUES(address), notes=VALUES(notes), source=\'import\''
            );
            $ok = 0; $skipped = 0;
            foreach ($items as $item) {
                $f = is_array($item) ? hydrantFields($item) : null;
                if (!$f) { $skipped++; continue; }
                $ext = hydrantStr($item['external_id'] ?? null, 100)
                    ?? sprintf('pos:%.6f,%.6f', $f['lat'], $f['lng']);
                $stmt->execute([$dept, $f['lat'], $f['lng'], $f['type'], $f['ref'], $f['address'], $f['notes'], $ext]);
                $ok++;
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Import fehlgeschlagen: ' . $e->getMessage()], 500);
        }
        jsonResponse(['imported' => $ok, 'skipped' => $skipped, 'removed' => $removed]);
        break;

    case 'delete_imported':
        $dept = hydrantDept($auth, $body['department_id'] ?? null, true);
        $del = $db->prepare('DELETE FROM department_hydrants WHERE department_id=? AND source=\'import\'');
        $del->execute([$dept]);
        jsonResponse(['removed' => $del->rowCount()]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 400);
}
