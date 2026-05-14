<?php
// ── Database credentials ──────────────────────────────────────────────────────
// Edit these directly, or override with environment variables.
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'feuerwehr';
$dbUser = getenv('DB_USER') ?: 'your_db_user';
$dbPass = getenv('DB_PASS') ?: 'your_db_password';
// ─────────────────────────────────────────────────────────────────────────────

function getDb(): PDO {
    global $dbHost, $dbName, $dbUser, $dbPass;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}
