<?php
/**
 * register.php – Create a personal account (self-service onboarding).
 *
 * POST JSON { name, email, password }
 *   → creates a users row (unverified),
 *   → e-mails a verification link (verify-email.php?t=…),
 *   → returns { ok: true }.  In dev (no SMTP configured) the link is also
 *     returned as `dev_link` and written to cache/mail.log.
 *
 * After verifying, the user lands on /onboarding.html to create or join a
 * department. Reuses the production auth stack (config/auth_helper.php).
 */

require_once __DIR__ . '/../../config/auth_helper.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name  = trim($body['name'] ?? '');
$email = strtolower(trim($body['email'] ?? ''));
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

$db = getDb();

// E-mail is the login identifier — must be unique.
$stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    jsonResponse(['error' => 'Diese E-Mail-Adresse ist bereits registriert.'], 409);
}

// ── Create the (unverified) user ─────────────────────────────────────────────
$hash = password_hash($pass, PASSWORD_BCRYPT);
$db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
   ->execute([$name, $email, $hash]);
$userId = (int) $db->lastInsertId();

// ── Verification token + link ────────────────────────────────────────────────
$token     = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
$db->prepare(
    'INSERT INTO email_verifications (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)'
)->execute([$userId, $email, $token, $expiresAt]);

$link = appBaseUrl() . '/verify-email.php?t=' . $token;

$html = mailTemplate(
    'E-Mail bestätigen / Confirm your e-mail',
    '<p>Hallo ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>' .
    '<p>danke für deine Registrierung beim Hydrantennavigator. Bitte bestätige deine ' .
    'E-Mail-Adresse. Danach kannst du eine Feuerwehr erstellen oder einer beitreten.</p>' .
    '<p style="color:#8a8a9a;font-size:13px">Please confirm your e-mail address. ' .
    'Afterwards you can create a fire department or join one.</p>',
    'E-Mail bestätigen / Confirm',
    $link
);

$sent = sendMail($email, 'Hydrantennavigator – E-Mail bestätigen', $html);

$resp = ['ok' => true];
if (!mailerIsConfigured()) {
    // Dev convenience: surface the link so the flow is testable without SMTP.
    $resp['dev_link'] = $link;
} elseif (!$sent) {
    $resp['warning'] = 'E-Mail konnte nicht gesendet werden. Bitte später erneut versuchen.';
}

jsonResponse($resp);
