<?php
/**
 * verify-email.php – Confirms a department's e-mail address.
 *
 * GET ?t=<token> → validates the verification token, marks the department
 * verified, issues a long-lived auth token, stores it in localStorage and
 * redirects to the admin panel. Mirrors magic-login.php's client pattern.
 */
require_once __DIR__ . '/config/auth_helper.php';

$token = trim($_GET['t'] ?? '');

function verifyErrorPage(string $msg): void
{
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Bestätigung fehlgeschlagen</title>
        <style>
            body { margin:0; background:#0f0f14; color:#f1f1f5; font-family:system-ui,sans-serif;
                   display:flex; align-items:center; justify-content:center; min-height:100dvh; padding:20px; }
            .box { text-align:center; max-width:360px; }
            .box h1 { font-size:20px; margin:0 0 10px; }
            .box p { color:#8a8a9a; font-size:14px; line-height:1.6; }
            a { color:#e63946; text-decoration:none; font-weight:600; display:inline-block; margin-top:18px; }
        </style>
    </head>
    <body>
        <div class="box">
            <div style="font-size:40px">⚠️</div>
            <h1>Bestätigung fehlgeschlagen</h1>
            <p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
            <a href="/register.html">← Erneut registrieren</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    verifyErrorPage('Der Link ist ungültig.');
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT id, department_id, email FROM email_verifications
     WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    verifyErrorPage('Dieser Bestätigungslink ist ungültig oder abgelaufen.');
}

$deptId = (int) $row['department_id'];

// Mark verification used + department verified (idempotent-safe).
$db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
$db->prepare('UPDATE fire_departments SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL')
   ->execute([$deptId]);

// Resolve name + issue a login token.
$nameStmt = $db->prepare('SELECT name FROM fire_departments WHERE id = ? LIMIT 1');
$nameStmt->execute([$deptId]);
$deptName = $nameStmt->fetchColumn() ?: 'Feuerwehr';

$rec = createToken($deptId, false, 'register');
logLogin($deptId, $rec['id'], false, 'register');

$expiresMs = (time() + 365 * 24 * 3600) * 1000;

$tokenJson     = json_encode($rec['token']);
$deptNameJson  = json_encode($deptName);
$expiresMsJson = json_encode($expiresMs);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>E-Mail bestätigt…</title>
    <style>
        body { margin:0; background:#0f0f14; color:#f1f1f5; font-family:system-ui,sans-serif;
               display:flex; align-items:center; justify-content:center; min-height:100dvh; }
        .msg { text-align:center; }
        .check { font-size:44px; margin-bottom:10px; }
        .spinner { width:34px; height:34px; border:3px solid rgba(255,255,255,0.1);
                   border-top-color:#4ade80; border-radius:50%;
                   animation:spin .7s linear infinite; margin:14px auto 0; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="msg">
        <div class="check">✅</div>
        <div>E-Mail bestätigt – du wirst angemeldet…</div>
        <div class="spinner"></div>
    </div>
    <script>
        localStorage.setItem('hw_token',     <?= $tokenJson ?>);
        localStorage.setItem('hw_dept_name', <?= $deptNameJson ?>);
        localStorage.setItem('hw_expires',   <?= $expiresMsJson ?>);
        localStorage.setItem('hw_is_admin',  '0');
        setTimeout(function () { location.replace('/admin/'); }, 800);
    </script>
</body>
</html>
