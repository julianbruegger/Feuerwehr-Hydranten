<?php
/**
 * logout.php – Token serverseitig löschen
 *
 * Erwartet POST mit JSON {token: "..."} oder direkter GET → leitet zu login.php
 * Clientseitig löscht die JS-Seite den localStorage selbst.
 */

require_once __DIR__ . '/config/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode(file_get_contents('php://input'), true);
    $token = $body['token'] ?? '';

    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $db = getDb();
        $db->prepare('DELETE FROM auth_tokens WHERE token = ?')->execute([$token]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// GET → einfach zur Login-Seite weiterleiten
header('Location: /login.php');
exit;
