<?php
/**
 * admin/api.php – Gesicherte REST-API für sensible Einträge
 *
 * Alle Endpoints erfordern einen gültigen Bearer-Token.
 * GET    ?action=list            → alle Einträge der Feuerwehr
 * POST   ?action=create          → neuer Eintrag
 * POST   ?action=update&id=X     → Eintrag aktualisieren
 * POST   ?action=delete&id=X     → Eintrag löschen
 */

// Sicherheits-Header für AJAX
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/auth_helper.php';

// Token prüfen – bricht mit 401 ab wenn ungültig
$auth    = requireAuth();
$isAdmin = $auth['is_admin'];
$deptId  = $auth['dept_id'];
$action  = $_GET['action'] ?? '';

switch ($action) {

    // ── Alle Einträge abrufen ────────────────────────────────────────────
    case 'list':
        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'SELECT id, title, address, lat, lng, category, content, updated_at
                 FROM sensitive_notes
                 ORDER BY updated_at DESC'
            );
            $stmt->execute([]);
        } else {
            $stmt = getDb()->prepare(
                'SELECT id, title, address, lat, lng, category, content, updated_at
                 FROM sensitive_notes
                 WHERE department_id = ?
                 ORDER BY updated_at DESC'
            );
            $stmt->execute([$deptId]);
        }
        jsonResponse($stmt->fetchAll());
        break;

    // ── Neuen Eintrag erstellen ──────────────────────────────────────────
    case 'create':
        $data = json_decode(file_get_contents('php://input'), true);
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        if ($title === '' || $content === '')
            jsonResponse(['error' => 'Titel und Notiz sind Pflichtfelder'], 400);

        if ($isAdmin) {
            $deptId = (int) ($data['dept_id'] ?? 0);
            if ($deptId <= 0)
                jsonResponse(['error' => 'dept_id erforderlich'], 400);
        }

        $category = in_array($data['category'] ?? '', ['schluessel', 'gefahrgut', 'gebaeude', 'kontakt', 'sonstiges'])
            ? $data['category'] : 'sonstiges';
        $stmt = getDb()->prepare(
            'INSERT INTO sensitive_notes
             (department_id, title, address, lat, lng, category, content)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deptId,
            $title,
            trim($data['address'] ?? ''),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            $category,
            $content,
        ]);
        jsonResponse(['id' => (int) getDb()->lastInsertId()], 201);
        break;

    // ── Eintrag aktualisieren ────────────────────────────────────────────
    case 'update':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0)
            jsonResponse(['error' => 'Ungültige ID'], 400);
        $data = json_decode(file_get_contents('php://input'), true);
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        if ($title === '' || $content === '')
            jsonResponse(['error' => 'Titel und Notiz sind Pflichtfelder'], 400);

        $category = in_array($data['category'] ?? '', ['schluessel', 'gefahrgut', 'gebaeude', 'kontakt', 'sonstiges'])
            ? $data['category'] : 'sonstiges';
        if ($isAdmin) {
            $stmt = getDb()->prepare(
                'UPDATE sensitive_notes
                 SET title=?, address=?, lat=?, lng=?, category=?, content=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                trim($data['address'] ?? ''),
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
                $category,
                $content,
                $id,
            ]);
        } else {
            $stmt = getDb()->prepare(
                'UPDATE sensitive_notes
                 SET title=?, address=?, lat=?, lng=?, category=?, content=?
                 WHERE id=? AND department_id=?'
            );
            $stmt->execute([
                $title,
                trim($data['address'] ?? ''),
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
                $category,
                $content,
                $id,
                $deptId,
            ]);
        }
        jsonResponse(['ok' => true]);
        break;

    // ── Eintrag löschen ──────────────────────────────────────────────────
    case 'delete':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0)
            jsonResponse(['error' => 'Ungültige ID'], 400);
        if ($isAdmin) {
            getDb()->prepare('DELETE FROM sensitive_notes WHERE id=?')->execute([$id]);
        } else {
            getDb()->prepare('DELETE FROM sensitive_notes WHERE id=? AND department_id=?')
                ->execute([$id, $deptId]);
        }
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 400);
}
