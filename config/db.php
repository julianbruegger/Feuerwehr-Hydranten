<?php
/**
 * db.php – Serverseitige Konfiguration
 *
 * WICHTIG: Diese Datei darf NIE direkt über HTTP erreichbar sein.
 * Sie wird durch config/.htaccess (Deny from all) geschützt.
 *
 * Passwort-Hash generieren (einmalig ausführen):
 *   php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_BCRYPT);"
 * Den Output unten eintragen und danach das Snippet löschen.
 */

// ─── Datenbankverbindung (Hostpoint) ───────────────────────────────────────
define('DB_HOST', 'ataciden.mysql.db.internal');
define('DB_NAME', 'ataciden_hydranten');
define('DB_USER', 'ataciden_hydran');
define('DB_PASS', 'RpXiMkEKwQ3ZKhC12Jz9');
define('DB_CHARSET', 'utf8mb4');

// ─── Admin-Passwort (bcrypt Hash) ─────────────────────────────────────────
// Ersetze dies mit dem Output von: password_hash('deinPasswort', PASSWORD_BCRYPT)
define('ADMIN_PASSWORD_HASH', '$2y$sdkfhslkhflkshflkshflkshflkshflkhsdlkfhsldfhslkfhlkshflkshflshjflkshflshflkshflkshflksfhkl$ERSETZE_DIESEN_HASH_MIT_DEINEM_ECHTEN_HASH');

// ─── Session-Sicherheitseinstellungen ─────────────────────────────────────
define('SESSION_LIFETIME', 3600);   // 1 Stunde in Sekunden
define('APP_NAME', 'Hydrantennavigator Admin');
