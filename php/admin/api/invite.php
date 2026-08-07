<?php
/**
 * invite.php – Invite a member into the authenticated department by e-mail.
 *
 * POST JSON { email }  (Authorization: Bearer <token>)
 *   → mints a device token for the caller's department,
 *   → e-mails a magic-login link (magic-login.php?t=…) to the invitee,
 *   → records the invitation.  In dev the link is returned as `dev_link`.
 *
 * The invitee clicks the link and is logged straight into the department via
 * the existing magic-login.php — no separate acceptance page needed.
 */

require_once __DIR__ . '/../../../config/auth_helper.php';
require_once __DIR__ . '/../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$auth   = requireAuth();
$deptId = $auth['dept_id'];

if ($deptId === null) {
    jsonResponse(['error' => 'Nur Feuerwehr-Konten können Mitglieder einladen.'], 403);
}

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($body['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Ungültige E-Mail-Adresse.'], 400);
}

$db = getDb();

// Resolve department name for the e-mail body.
$nameStmt = $db->prepare('SELECT name FROM fire_departments WHERE id = ? LIMIT 1');
$nameStmt->execute([$deptId]);
$deptName = $nameStmt->fetchColumn() ?: 'Feuerwehr';

// Mint a device token (magic-login uses auth_tokens directly) valid 14 days.
$expiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));
$rec = createToken($deptId, false, 'invite', 'Einladung: ' . $email, $expiresAt);

// Track the invitation.
$db->prepare(
    'INSERT INTO invitations (department_id, email, token, created_by, expires_at)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$deptId, $email, $rec['token'], $auth['token_id'], $expiresAt]);

$link = appBaseUrl() . '/magic-login.php?t=' . $rec['token'];

$html = mailTemplate(
    'Einladung / Invitation',
    '<p>Du wurdest zur Feuerwehr <strong>' . htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') .
    '</strong> im Hydrantennavigator eingeladen.</p>' .
    '<p>Klicke auf den Button, um dich mit diesem Gerät anzumelden.</p>' .
    '<p style="color:#8a8a9a;font-size:13px">You have been invited. Click the button to sign in on this device.</p>',
    'Anmelden / Sign in',
    $link
);

$sent = sendMail($email, 'Hydrantennavigator – Einladung', $html);

$resp = ['ok' => true, 'email' => $email];
if (!mailerIsConfigured()) {
    $resp['dev_link'] = $link;
} elseif (!$sent) {
    $resp['warning'] = 'Einladung erstellt, aber E-Mail konnte nicht gesendet werden.';
}

jsonResponse($resp);
