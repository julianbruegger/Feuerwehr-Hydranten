<?php
/**
 * login.php – Feuerwehr-Login
 *
 * POST: name + password → validiert gegen DB → gibt Token zurück (JSON)
 * GET:  Liefert die Login-Seite (HTML)
 *
 * Der Token wird clientseitig in localStorage gespeichert (365 Tage).
 * Keine Server-Session nötig – alles token-basiert.
 */

require_once __DIR__ . '/config/auth_helper.php';

// ─── POST: Login-Anfrage verarbeiten ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Eingaben aus JSON-Body lesen
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? '');
    $pass = trim($body['password'] ?? '');

    if ($name === '' || $pass === '') {
        jsonResponse(['error' => 'Name und Passwort erforderlich'], 400);
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, password_hash FROM fire_departments WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $dept = $stmt->fetch();

    // Timing-sicherer Vergleich (verhindert User-Enumeration)
    $valid = $dept && password_verify($pass, $dept['password_hash']);

    if (!$valid) {
        // Kurze Pause verhindert Brute-Force
        sleep(1);
        jsonResponse(['error' => 'Ungültige Anmeldedaten'], 401);
    }

    // Token erstellen (365 Tage gültig → clientseitig in localStorage cached)
    $token = createToken((int) $dept['id']);
    jsonResponse([
        'token' => $token,
        'expires_in' => 365 * 24 * 3600,   // Sekunden → JS kann Ablauf setzen
        'dept_name' => $name,
    ]);
}

// ─── GET: Login-Seite ausgeben ─────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>🚒 Anmeldung – Hydrantennavigator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg: #0f0f14;
            --surface: #18181f;
            --card: #22222d;
            --red: #e63946;
            --text: #f1f1f5;
            --muted: #8a8a9a;
            --border: rgba(255, 255, 255, 0.08);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 36px 28px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.6);
        }

        .login-logo {
            text-align: center;
            font-size: 44px;
            margin-bottom: 8px;
        }

        .login-title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .login-sub {
            text-align: center;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 28px;
        }

        .field {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        input {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-family: inherit;
            font-size: 16px;
            padding: 13px 16px;
            outline: none;
            transition: border-color 0.2s;
        }

        input:focus {
            border-color: var(--red);
        }

        .btn {
            width: 100%;
            margin-top: 8px;
            padding: 14px;
            background: var(--red);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .error {
            background: rgba(230, 57, 70, 0.12);
            border: 1px solid var(--red);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            color: var(--red);
            margin-top: 14px;
            display: none;
        }

        .map-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
        }

        .map-link:hover {
            color: var(--text);
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-logo">🚒</div>
        <div class="login-title">Feuerwehr-Anmeldung</div>
        <div class="login-sub">Nur für autorisiertes Personal</div>

        <form id="loginForm" autocomplete="on">
            <div class="field">
                <label for="name">Feuerwehr</label>
                <input type="text" id="name" name="username" placeholder="z.B. FW Luzern" autocomplete="username"
                    required />
            </div>
            <div class="field">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required />
            </div>
            <button type="submit" class="btn" id="loginBtn">Anmelden</button>
            <div class="error" id="errorMsg"></div>
        </form>

        <a href="/" class="map-link">← Zurück zur Karte</a>
    </div>

    <script>
        // Bereits eingeloggt? → direkt zum Admin-Panel
        const cached = localStorage.getItem('hw_token');
        if (cached) {
            window.location.href = '/admin/';
        }

        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const err = document.getElementById('errorMsg');
            btn.disabled = true;
            btn.textContent = 'Anmelden…';
            err.style.display = 'none';

            try {
                const res = await fetch('/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: document.getElementById('name').value.trim(),
                        password: document.getElementById('password').value,
                    }),
                });
                const data = await res.json();

                if (!res.ok) {
                    err.textContent = data.error || 'Anmeldung fehlgeschlagen';
                    err.style.display = 'block';
                    return;
                }

                // Token für 365 Tage im localStorage speichern
                localStorage.setItem('hw_token', data.token);
                localStorage.setItem('hw_dept_name', data.dept_name);
                localStorage.setItem('hw_expires', Date.now() + data.expires_in * 1000);

                window.location.href = '/admin/';
            } catch {
                err.textContent = 'Serverfehler – bitte erneut versuchen';
                err.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Anmelden';
            }
        });
    </script>
</body>

</html>