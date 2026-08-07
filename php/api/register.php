<?php
/**
 * register.php – Create a new fire department (self-service onboarding).
 *
 * POST JSON { name, email, password }
 *   → creates a fire_departments row (unverified),
 *   → e-mails a verification link (verify-email.php?t=…),
 *   → returns { ok: true }.  In dev (no SMTP configured) the link is also
 *     returned as `dev_link` and written to cache/mail.log.
 *
 * Reuses the production auth stack (config/auth_helper.php) so tokens created
 * later on verification are consistent with login.php / magic-login.php.
 */

require_once __DIR__ . '/../../config/auth_helper.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name  = trim($body['name'] ?? '');
$email = trim($body['email'] ?? '');
$pass  = (string) ($body['password'] ?? '');

// ── Validation ───────────────────────────────────────────────────────────────
if ($name === '' || $email === '' || $pass === '') {
    jsonResponse(['error' => 'Alle Felder sind erforderlich.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Ungültige E-Mail-Adresse.'], 400);
}
if (strlen($pass) < 8) {
    jsonResponse(['error' => 'Das Passwort muss mindestens 8 Zeichen lang sein.'], 400);
}
if (strtolower($name) === 'admin') {
    jsonResponse(['error' => 'Dieser Name ist reserviert.'], 409);
}

$db = getDb();

// Name is the login identifier — must be unique.
$stmt = $db->prepare('SELECT id FROM fire_departments WHERE name = ? LIMIT 1');
$stmt->execute([$name]);
if ($stmt->fetch()) {
    jsonResponse(['error' => 'Diese Feuerwehr ist bereits registriert.'], 409);
}

// ── Create the (unverified) department ───────────────────────────────────────
$hash = password_hash($pass, PASSWORD_BCRYPT);
$db->prepare('INSERT INTO fire_departments (name, email, password_hash) VALUES (?, ?, ?)')
   ->execute([$name, $email, $hash]);
$deptId = (int) $db->lastInsertId();

// ── Verification token + link ────────────────────────────────────────────────
$token     = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
$db->prepare(
    'INSERT INTO email_verifications (department_id, email, token, expires_at) VALUES (?, ?, ?, ?)'
)->execute([$deptId, $email, $token, $expiresAt]);

$link = appBaseUrl() . '/verify-email.php?t=' . $token;

$html = mailTemplate(
    'E-Mail bestätigen / Confirm your e-mail',
    '<p>Danke für die Registrierung von <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') .
    '</strong> beim Hydrantennavigator.</p>' .
    '<p>Bitte bestätige deine E-Mail-Adresse, um den Zugang zu aktivieren.</p>' .
    '<p style="color:#8a8a9a;font-size:13px">Thanks for registering. Please confirm your e-mail address to activate access.</p>',
    'E-Mail bestätigen / Confirm',
    $link
);

$sent = sendMail($email, 'Hydrantennavigator – E-Mail bestätigen', $html);

$resp = ['ok' => true];
if (!mailerIsConfigured()) {
    // Dev convenience: surface the link so the flow is testable without SMTP.
    $resp['dev_link'] = $link;
} elseif (!$sent) {
    // Account exists but the mail failed — let the user know they can retry later.
    $resp['warning'] = 'E-Mail konnte nicht gesendet werden. Bitte später erneut versuchen.';
}

jsonResponse($resp);
