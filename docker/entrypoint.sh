#!/bin/sh
# Generates config/db.php for local development from the container's DB_* env
# vars (see docker-compose.yml). On Hostpoint this file is produced by the
# deploy workflow instead — it is gitignored and never committed.
#
# SMTP is intentionally left empty in dev, so the mailer falls back to writing
# magic/verification links to cache/mail.log instead of sending real e-mail.
set -e

CONFIG=/var/www/html/config/db.php

if [ ! -f "$CONFIG" ]; then
  cat > "$CONFIG" <<'EOF'
<?php
define('DB_HOST',    getenv('DB_HOST') ?: 'db');
define('DB_NAME',    getenv('DB_NAME') ?: 'hydranten');
define('DB_USER',    getenv('DB_USER') ?: 'hydranten');
define('DB_PASS',    getenv('DB_PASS') ?: 'hydranten');
define('DB_CHARSET', 'utf8mb4');
define('SESSION_LIFETIME', 3600);
define('APP_NAME',   'Hydrantennavigator (dev)');
// Dev admin password: "admin"
define('ADMIN_PASSWORD_HASH', '$2y$12$/UEcVflczxMW3FuOu9aX1.3qmZHuw.kKDBKFwBEWgbhBqbiV1QAUi');
// No SMTP in dev → links are logged to cache/mail.log
define('SMTP_HOST', '');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost:8080');
EOF
fi

# Ensure the cache dir (mail.log dev fallback) is writable by Apache.
mkdir -p /var/www/html/cache
chmod -R 0777 /var/www/html/cache 2>/dev/null || true

exec "$@"
