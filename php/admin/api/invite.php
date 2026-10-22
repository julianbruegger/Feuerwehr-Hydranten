<?php
/**
 * invite.php – Invite a person into the authenticated department by e-mail.
 *
 * POST JSON { email }  (Authorization: Bearer <token>)
 *   → creates a pending invitation with a join code,
 *   → e-mails a link to /onboarding.html?code=… (register/log in, then join),
 *   → returns { ok: true, code }.  In dev the link is returned as `dev_link`.
 *
 * The invitee registers (or logs in) as a person and redeems the code via
 * /api/department-join to become a member of this department.
 */

require_once __DIR__ . '/../../../config/auth_helper.php';
require_once __DIR__ . '/../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$auth   = requireAuth();
$deptId = $auth['dept_id'];

if ($deptId === null) {
    jsonResponse(['error' => 'Nur Mitglieder einer Feuerwehr können einladen.'], 403);
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

// Create a pending invitation with a join code (valid 14 days).
$code      = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));
$db->prepare(
    'INSERT INTO invitations (department_id, email, token, created_by, expires_at)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$deptId, $email, $code, $auth['token_id'], $expiresAt]);

$link = appBaseUrl() . '/onboarding.html?code=' . $code;

$html = mailTemplate(
    'Einladung / Invitation',
    '<p>Du wurdest zur Feuerwehr <strong>' . htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') .
    '</strong> im Hydrantennavigator eingeladen.</p>' .
    '<p>Klicke auf den Button, um dich anzumelden oder zu registrieren und der Feuerwehr ' .
    'beizutreten. Dein Einladungscode:</p>' .
    '<p style="font-family:monospace;font-size:12px;word-break:break-all;color:#f4a261">' .
    htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>' .
    '<p style="color:#8a8a9a;font-size:13px">Click the button to sign in or register and join ' .
    'the department. Your invite code is shown above.</p>',
    'Beitreten / Join',
    $link
);

$sent = sendMail($email, 'Hydrantennavigator – Einladung', $html);

$resp = ['ok' => true, 'email' => $email, 'code' => $code];
if (!mailerIsConfigured()) {
    $resp['dev_link'] = $link;
} elseif (!$sent) {
    $resp['warning'] = 'Einladung erstellt, aber E-Mail konnte nicht gesendet werden.';
}

jsonResponse($resp);
