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

    // Eingaben aus JSON-Body lesen (E-Mail ist die Login-Identität)
    $body  = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($body['email'] ?? $body['name'] ?? ''));
    $pass  = trim($body['password'] ?? '');

    if ($email === '' || $pass === '') {
        jsonResponse(['error' => 'E-Mail und Passwort erforderlich'], 400);
    }

    // Admin login — uses ADMIN_PASSWORD_HASH constant from config/db.php
    if ($email === 'admin') {
        if (!defined('ADMIN_PASSWORD_HASH') || !password_verify($pass, ADMIN_PASSWORD_HASH)) {
            sleep(1);
            jsonResponse(['error' => 'Ungültige Anmeldedaten'], 401);
        }
        $rec = createToken(null, true, 'admin');
        logLogin(null, $rec['id'], true, 'admin');
        jsonResponse([
            'token'      => $rec['token'],
            'expires_in' => 365 * 24 * 3600,
            'dept_name'  => 'Admin',
            'user_name'  => 'Admin',
            'is_admin'   => true,
            'has_dept'   => true,
            'redirect'   => '/admin/',
        ]);
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, name, password_hash, email_verified_at FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Timing-sicherer Vergleich (verhindert User-Enumeration)
    $hashToCheck = $user['password_hash'] ?? '$2y$10$invalidhashfortimingprotection00000000000000000000000';
    $valid = $user && password_verify($pass, $hashToCheck);

    if (!$valid) {
        sleep(1);
        jsonResponse(['error' => 'Ungültige Anmeldedaten'], 401);
    }
    if (empty($user['email_verified_at'])) {
        jsonResponse(['error' => 'Bitte bestätige zuerst deine E-Mail-Adresse.'], 403);
    }

    // Aktive Feuerwehr = neueste Mitgliedschaft (falls vorhanden)
    $mStmt = $db->prepare(
        'SELECT m.department_id, d.name AS dept_name
         FROM memberships m JOIN fire_departments d ON d.id = m.department_id
         WHERE m.user_id = ? ORDER BY m.created_at DESC LIMIT 1'
    );
    $mStmt->execute([(int) $user['id']]);
    $mem = $mStmt->fetch();

    $deptId   = $mem ? (int) $mem['department_id'] : null;
    $deptName = $mem ? $mem['dept_name'] : $user['name'];

    $rec = createToken($deptId, false, 'password', null, null, (int) $user['id']);
    logLogin($deptId, $rec['id'], false, 'password');
    jsonResponse([
        'token'      => $rec['token'],
        'expires_in' => 365 * 24 * 3600,
        'dept_name'  => $deptName,
        'user_name'  => $user['name'],
        'has_dept'   => (bool) $mem,
        'redirect'   => $mem ? '/admin/' : '/onboarding.html',
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
    <button data-lang-toggle aria-label="Sprache wechseln / Switch language"
        style="position:fixed;top:16px;right:16px;background:var(--card);border:1px solid var(--border);color:var(--muted);font:600 13px 'Inter',sans-serif;padding:8px 13px;border-radius:999px;cursor:pointer">
        <span data-lang-label>EN</span>
    </button>

    <div class="login-card">
        <div class="login-logo">🚒</div>
        <div class="login-title" data-i18n="login.title">Feuerwehr-Anmeldung</div>
        <div class="login-sub" data-i18n="login.sub">Nur für autorisiertes Personal</div>

        <form id="loginForm" autocomplete="on">
            <div class="field">
                <label for="email" data-i18n="login.email">E-Mail</label>
                <input type="email" id="email" name="email" placeholder="du@feuerwehr.ch" autocomplete="username email"
                    required />
            </div>
            <div class="field">
                <label for="password" data-i18n="login.password">Passwort</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required />
            </div>
            <button type="submit" class="btn" id="loginBtn" data-i18n="login.submit">Anmelden</button>
            <div class="error" id="errorMsg"></div>
        </form>

        <div style="text-align:center;margin-top:20px;font-size:13px;color:var(--muted)">
            <span data-i18n="login.noAccount">Noch keine Feuerwehr?</span>
            <a href="/register.html" style="color:var(--text);text-decoration:none;font-weight:600"
                data-i18n="login.registerLink">Registrieren</a>
        </div>
        <a href="/map" class="map-link" data-i18n="login.backMap">← Zurück zur Karte</a>
    </div>

    <script src="/i18n.js"></script>
    <script>
        // Bereits eingeloggt? → zum Admin-Panel, oder zum Onboarding wenn noch keine Feuerwehr
        const cached = localStorage.getItem('hw_token');
        if (cached) {
            const hasDept = localStorage.getItem('hw_has_dept') === '1'
                || localStorage.getItem('hw_is_admin') === '1';
            window.location.href = hasDept ? '/admin/' : '/onboarding.html';
        }

        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            const err = document.getElementById('errorMsg');
            btn.disabled = true;
            btn.textContent = window.I18N ? I18N.t('login.submitting') : 'Anmelden…';
            err.style.display = 'none';

            try {
                const res = await fetch('/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: document.getElementById('email').value.trim(),
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
                localStorage.setItem('hw_user_name', data.user_name || data.dept_name);
                localStorage.setItem('hw_expires', Date.now() + data.expires_in * 1000);
                localStorage.setItem('hw_is_admin', data.is_admin ? '1' : '0');
                localStorage.setItem('hw_has_dept', data.has_dept ? '1' : '0');

                window.location.href = data.redirect || '/admin/';
            } catch {
                err.textContent = 'Serverfehler – bitte erneut versuchen';
                err.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = window.I18N ? I18N.t('login.submit') : 'Anmelden';
            }
        });
    </script>
</body>

</html>