<?php
/**
 * verify-email.php – Confirms a user's e-mail address.
 *
 * GET ?t=<token> → validates the verification token, marks the user verified,
 * issues a long-lived auth token, stores it in localStorage and redirects to
 * onboarding (or straight to /admin/ if the user already has a department).
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
    'SELECT id, user_id, email FROM email_verifications
     WHERE token = ? AND used_at IS NULL AND expires_at > NOW() AND user_id IS NOT NULL LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    verifyErrorPage('Dieser Bestätigungslink ist ungültig oder abgelaufen.');
}

$userId = (int) $row['user_id'];

// Mark verification used + user verified (idempotent-safe).
$db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
$db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL')
   ->execute([$userId]);

// Resolve the user's name + any existing membership (newest wins).
$uStmt = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
$uStmt->execute([$userId]);
$userName = $uStmt->fetchColumn() ?: 'Benutzer';

$mStmt = $db->prepare(
    'SELECT m.department_id, d.name AS dept_name
     FROM memberships m JOIN fire_departments d ON d.id = m.department_id
     WHERE m.user_id = ? ORDER BY m.created_at DESC LIMIT 1'
);
$mStmt->execute([$userId]);
$membership = $mStmt->fetch();

$deptId   = $membership ? (int) $membership['department_id'] : null;
$deptName = $membership ? $membership['dept_name'] : $userName;

$rec = createToken($deptId, false, 'register', null, null, $userId);
logLogin($deptId, $rec['id'], false, 'register');

$expiresMs = (time() + 365 * 24 * 3600) * 1000;
$redirect  = $membership ? '/admin/' : '/onboarding.html';

$tokenJson     = json_encode($rec['token']);
$deptNameJson  = json_encode($deptName);
$userNameJson  = json_encode($userName);
$expiresMsJson = json_encode($expiresMs);
$redirectJson  = json_encode($redirect);
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
        <div>E-Mail bestätigt…</div>
        <div class="spinner"></div>
    </div>
    <script>
        localStorage.setItem('hw_token',     <?= $tokenJson ?>);
        localStorage.setItem('hw_user_name', <?= $userNameJson ?>);
        localStorage.setItem('hw_dept_name', <?= $deptNameJson ?>);
        localStorage.setItem('hw_expires',   <?= $expiresMsJson ?>);
        localStorage.setItem('hw_is_admin',  '0');
        setTimeout(function () { location.replace(<?= $redirectJson ?>); }, 800);
    </script>
</body>
</html>
