<?php
/**
 * auth_helper.php – Wiederverwendbare Auth-Hilfsfunktionen
 *
 * Enthält: Datenbankverbindung, Token-Validierung, Session-Schutz.
 * Wird von login.php, logout.php und admin/api.php eingebunden.
 */

require_once __DIR__ . '/../config/db.php';

// ─── PDO-Verbindung ────────────────────────────────────────────────────────

function getDb()
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

// ─── Token-Validierung ─────────────────────────────────────────────────────

/**
 * Prüft einen Bearer-Token aus dem Authorization-Header oder POST-Body.
 * Gibt die Department-ID zurück, oder null wenn ungültig/abgelaufen.
 */
function validateToken($token): ?array
{
    if (strlen($token) !== 64)
        return null;

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, department_id, user_id, is_admin FROM auth_tokens
         WHERE token = ? AND expires_at > NOW() AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) return null;

    $db->prepare('UPDATE auth_tokens SET last_seen_at = NOW() WHERE id = ?')
       ->execute([$row['id']]);

    return [
        'dept_id'  => $row['department_id'] !== null ? (int) $row['department_id'] : null,
        'user_id'  => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'is_admin' => (bool) $row['is_admin'],
        'token_id' => (int) $row['id'],
    ];
}

/**
 * Scopes an existing token to a department (after the user creates or joins
 * one during onboarding), so no re-login is needed.
 */
function setTokenDepartment(int $tokenId, int $departmentId): void
{
    getDb()->prepare('UPDATE auth_tokens SET department_id = ? WHERE id = ?')
           ->execute([$departmentId, $tokenId]);
}

/**
 * Liest den Token aus dem Authorization-Header (Bearer) oder aus dem
 * X-Auth-Token-Header. Gibt null zurück wenn keiner vorhanden.
 */
function getTokenFromRequest()
{
    // Authorization: Bearer <token>
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $header, $m)) {
        return $m[1];
    }
    // Fallback: POST body (für einfachere Clients)
    return isset($_POST['token']) && preg_match('/^[a-f0-9]{64}$/', $_POST['token'])
        ? $_POST['token']
        : null;
}

/**
 * Prüft Request-Auth und gibt Department-ID zurück.
 * Bricht mit 401 JSON ab wenn nicht authentifiziert.
 */
function requireAuth(): array
{
    $token = getTokenFromRequest();
    $auth  = $token ? validateToken($token) : null;

    if ($auth === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Nicht authentifiziert']);
        exit;
    }
    return $auth;
}

// ─── Token erstellen ───────────────────────────────────────────────────────

/**
 * Erstellt einen neuen Langzeit-Token (365 Tage) für eine Feuerwehr.
 * Gibt den Token-String zurück.
 */
function createToken(
    ?int $departmentId,
    bool $isAdmin = false,
    string $source = 'password',
    ?string $label = null,
    ?string $expiresAt = null,
    ?int $userId = null
): array {
    $token     = bin2hex(random_bytes(32));
    $expiresAt = $expiresAt ?? date('Y-m-d H:i:s', strtotime('+365 days'));

    $db = getDb();
    $db->prepare(
        'INSERT INTO auth_tokens (department_id, user_id, is_admin, source, label, token, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$departmentId, $userId, $isAdmin ? 1 : 0, $source, $label, $token, $expiresAt]);

    return ['token' => $token, 'id' => (int) $db->lastInsertId()];
}

function logLogin(?int $departmentId, int $tokenId, bool $isAdmin, string $source): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) $ip = trim(explode(',', $ip)[0]);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
    getDb()->prepare(
        'INSERT INTO login_log (department_id, token_id, is_admin, source, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$departmentId, $tokenId, $isAdmin ? 1 : 0, $source, $ip, $ua]);
}

// ─── JSON-Antwort ─────────────────────────────────────────────────────────

function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
