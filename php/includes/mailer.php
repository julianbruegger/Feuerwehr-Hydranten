<?php
/**
 * mailer.php – Minimal, dependency-free SMTP sender.
 *
 * No Composer / PHPMailer available on the target shared hosting, so this is a
 * small self-contained SMTP client (fsockopen + STARTTLS/SSL + AUTH LOGIN).
 *
 * Configuration comes from constants defined in config/db.php (generated at
 * deploy time from GitHub secrets):
 *
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
 *   SMTP_SECURE ('tls' | 'ssl' | ''), SMTP_FROM, SMTP_FROM_NAME, APP_BASE_URL
 *
 * If SMTP_HOST is not configured, sendMail() falls back to appending the
 * message to cache/mail.log and returns true — so the whole register/invite
 * flow is fully testable locally without a real mail server.
 */

/** Is a real SMTP server configured? */
function mailerIsConfigured(): bool
{
    return defined('SMTP_HOST') && trim((string) SMTP_HOST) !== '';
}

/** Base URL for building links in e-mails (falls back to the current host). */
function appBaseUrl(): string
{
    if (defined('APP_BASE_URL') && trim((string) APP_BASE_URL) !== '') {
        return rtrim(APP_BASE_URL, '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function mailerDevLogPath(): string
{
    return __DIR__ . '/../../cache/mail.log';
}

/**
 * Sends an HTML e-mail. Returns true on success (or when the dev fallback
 * logs the message), false on SMTP failure.
 */
function sendMail(string $to, string $subject, string $htmlBody): bool
{
    if (!mailerIsConfigured()) {
        // ── Dev fallback: log instead of sending ──
        $log = mailerDevLogPath();
        @file_put_contents(
            $log,
            sprintf(
                "[%s] TO: %s\nSUBJECT: %s\n%s\n%s\n\n",
                date('c'),
                $to,
                $subject,
                str_repeat('-', 40),
                $htmlBody
            ),
            FILE_APPEND
        );
        return true;
    }

    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    $user   = defined('SMTP_USER') ? SMTP_USER : '';
    $pass   = defined('SMTP_PASS') ? SMTP_PASS : '';
    $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls';
    $from   = defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : $user;
    $fromNm = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Hydrantennavigator';

    $transport = ($secure === 'ssl') ? "ssl://$host" : $host;

    $fp = @fsockopen($transport, $port, $errno, $errstr, 15);
    if (!$fp) {
        error_log("SMTP connect failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($fp, 15);

    // Helper closures ---------------------------------------------------------
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // A line like "250 xxx" (space at pos 3) marks the end.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        $resp = $read();
        return str_starts_with(ltrim($resp), $expect);
    };

    try {
        $greeting = $read();
        if (!str_starts_with(ltrim($greeting), '220')) throw new RuntimeException('No 220 greeting');

        $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!$cmd("EHLO $ehloHost", '250')) throw new RuntimeException('EHLO rejected');

        if ($secure === 'tls') {
            if (!$cmd('STARTTLS', '220')) throw new RuntimeException('STARTTLS rejected');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed');
            }
            if (!$cmd("EHLO $ehloHost", '250')) throw new RuntimeException('EHLO after TLS rejected');
        }

        if ($user !== '') {
            if (!$cmd('AUTH LOGIN', '334')) throw new RuntimeException('AUTH LOGIN rejected');
            if (!$cmd(base64_encode($user), '334')) throw new RuntimeException('Username rejected');
            if (!$cmd(base64_encode($pass), '235')) throw new RuntimeException('Password rejected');
        }

        if (!$cmd("MAIL FROM:<$from>", '250')) throw new RuntimeException('MAIL FROM rejected');
        if (!$cmd("RCPT TO:<$to>", '250')) throw new RuntimeException('RCPT TO rejected');
        if (!$cmd('DATA', '354')) throw new RuntimeException('DATA rejected');

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = sprintf('%s <%s>', mb_encode_mimeheader_safe($fromNm), $from);
        $messageId  = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $ehloHost);

        $headers = [
            "From: $fromHeader",
            "To: <$to>",
            "Subject: $encSubject",
            'Date: ' . date('r'),
            "Message-ID: $messageId",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $body = chunk_split(base64_encode($htmlBody));
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $resp = $read();
        if (!str_starts_with(ltrim($resp), '250')) throw new RuntimeException('Message not accepted');

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('SMTP error: ' . $e->getMessage());
        @fclose($fp);
        return false;
    }
}

/** Encodes a header value as UTF-8 base64 only when it contains non-ASCII. */
function mb_encode_mimeheader_safe(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/** Small shared HTML e-mail template wrapper. */
function mailTemplate(string $heading, string $bodyHtml, string $buttonLabel, string $buttonUrl): string
{
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeLabel   = htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8');
    $safeUrl     = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;background:#0f0f14;font-family:Arial,Helvetica,sans-serif;color:#f1f1f5;">
  <div style="max-width:480px;margin:0 auto;padding:32px 24px;">
    <div style="font-size:26px;">🚒</div>
    <h1 style="font-size:20px;margin:12px 0 16px;">$safeHeading</h1>
    <div style="font-size:15px;line-height:1.6;color:#c8c8d4;">$bodyHtml</div>
    <p style="margin:28px 0;">
      <a href="$safeUrl" style="display:inline-block;background:#e63946;color:#fff;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 22px;border-radius:12px;">$safeLabel</a>
    </p>
    <p style="font-size:12px;color:#6a6a80;word-break:break-all;">$safeUrl</p>
  </div>
</body>
</html>
HTML;
}
