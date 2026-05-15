<?php
/**
 * magic-login.php – Silent login via magic link token
 *
 * GET ?t=<token> → validates token, then returns an HTML page that stores
 * the token in localStorage and redirects to the map.
 */
require_once __DIR__ . '/config/auth_helper.php';

$token = trim($_GET['t'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    die('Ungültiger Link.');
}

$auth = validateToken($token);
if (!$auth) {
    http_response_code(401);
    die('Dieser Link ist ungültig oder wurde widerrufen.');
}

// Resolve dept name
$deptName = 'Feuerwehr';
if ($auth['dept_id']) {
    $row = getDb()->prepare('SELECT name FROM fire_departments WHERE id = ? LIMIT 1');
    $row->execute([$auth['dept_id']]);
    $deptName = $row->fetchColumn() ?: $deptName;
}

// Fetch expiry for localStorage
$expRow = getDb()->prepare('SELECT expires_at FROM auth_tokens WHERE id = ? LIMIT 1');
$expRow->execute([$auth['token_id']]);
$expiresAt = $expRow->fetchColumn();
$expiresMs = $expiresAt ? (strtotime($expiresAt) * 1000) : (PHP_INT_MAX);

logLogin($auth['dept_id'], $auth['token_id'], (bool) $auth['is_admin'], 'magic');

$tokenJson     = json_encode($token);
$deptNameJson  = json_encode($deptName);
$isAdminJson   = $auth['is_admin'] ? 'true' : 'false';
$expiresMsJson = json_encode($expiresMs);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Anmeldung…</title>
    <style>
        body {
            margin: 0;
            background: #0f0f14;
            color: #f1f1f5;
            font-family: system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100dvh;
            font-size: 16px;
        }
        .msg { text-align: center; }
        .spinner {
            width: 36px; height: 36px;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: #e63946;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="msg">
        <div class="spinner"></div>
        <div>Anmelden…</div>
    </div>
    <script>
        localStorage.setItem('hw_token',     <?= $tokenJson ?>);
        localStorage.setItem('hw_dept_name', <?= $deptNameJson ?>);
        localStorage.setItem('hw_expires',   <?= $expiresMsJson ?>);
        localStorage.setItem('hw_is_admin',  <?= $isAdminJson ?> ? '1' : '0');
        location.replace('/');
    </script>
</body>
</html>
