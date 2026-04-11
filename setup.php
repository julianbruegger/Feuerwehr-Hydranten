<?php
/**
 * setup.php – Einmalige Einrichtung: Feuerwehr-Konto erstellen
 *
 * WICHTIG: Diese Datei nach der Einrichtung SOFORT LÖSCHEN!
 * Oder via .htaccess sperren: Deny from all
 *
 * Aufruf: https://deine-domain.ch/setup.php
 * Nach dem Ausfüllen wird das Passwort als bcrypt-Hash gespeichert.
 */

// ── Einfacher Zugriffsschutz für die Setup-Seite selbst ──────────────────────
// Ändere dieses Passwort auf etwas Eigenes, bevor du die Datei hochlädst.
const SETUP_KEY = 'setup-geheim-2024';

require_once __DIR__ . '/config/auth_helper.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Setup-Key prüfen
    if (($_POST['setup_key'] ?? '') !== SETUP_KEY) {
        $error = 'Falscher Setup-Schlüssel.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $pass2 = trim($_POST['password2'] ?? '');

        if ($name === '' || $pass === '') {
            $error = 'Name und Passwort erforderlich.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwörter stimmen nicht überein.';
        } elseif (strlen($pass) < 12) {
            $error = 'Passwort muss mindestens 12 Zeichen lang sein.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $db = getDb();

                // Prüfen ob Name schon existiert
                $check = $db->prepare('SELECT id FROM fire_departments WHERE name = ?');
                $check->execute([$name]);
                if ($check->fetch()) {
                    $error = "Feuerwehr \"$name\" existiert bereits.";
                } else {
                    $db->prepare('INSERT INTO fire_departments (name, password_hash) VALUES (?, ?)')->execute([$name, $hash]);
                    $message = "✅ Feuerwehr \"$name\" wurde erfolgreich erstellt. <strong>Lösche setup.php jetzt!</strong>";
                }
            } catch (Exception $e) {
                $error = 'Datenbankfehler: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Setup – Hydrantennavigator</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #0f0f14;
            color: #f1f1f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .card {
            background: #18181f;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 28px 24px;
            width: 100%;
            max-width: 400px;
        }

        h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .warn {
            font-size: 12px;
            color: #f4a261;
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-size: 11px;
            color: #8a8a9a;
            font-weight: 600;
            text-transform: uppercase;
            margin: 12px 0 4px;
        }

        input {
            width: 100%;
            background: #22222d;
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 8px;
            color: #f1f1f5;
            font-size: 15px;
            padding: 10px 12px;
            outline: none;
        }

        button {
            width: 100%;
            margin-top: 18px;
            padding: 12px;
            background: #e63946;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .msg {
            margin-top: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
        }

        .msg.ok {
            background: rgba(74, 222, 128, 0.1);
            border: 1px solid #4ade80;
            color: #4ade80;
        }

        .msg.err {
            background: rgba(230, 57, 70, 0.1);
            border: 1px solid #e63946;
            color: #e63946;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>🔧 Feuerwehr-Konto erstellen</h1>
        <p class="warn">⚠️ Diese Datei nach der Einrichtung sofort löschen!</p>
        <form method="POST">
            <label>Setup-Schlüssel</label>
            <input type="password" name="setup_key" required />
            <label>Feuerwehr-Name</label>
            <input type="text" name="name" placeholder="z.B. FW Luzern" required />
            <label>Passwort (min. 12 Zeichen)</label>
            <input type="password" name="password" required />
            <label>Passwort bestätigen</label>
            <input type="password" name="password2" required />
            <button type="submit">Konto erstellen</button>
        </form>
        <?php if ($message): ?>
            <div class="msg ok">
                <?= $message ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="msg err">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>