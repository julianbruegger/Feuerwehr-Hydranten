<?php
/**
 * Overpass-Abfragen mit festem Zeitbudget und gestaffelten Mirrors.
 *
 * Der Hoster bricht Anfragen nach ~30 s ab. Früher wurden die Mirrors
 * nacheinander mit je 30 s Timeout abgefragt – der erste Mirror verbrauchte
 * das ganze Budget, die Fallbacks kamen nie zum Zug und es wurde nichts
 * gecacht (siehe Issue #27).
 *
 * Jetzt: Mirror 1 startet sofort; antwortet er nicht innerhalb von
 * OVERPASS_HEDGE_S (oder schlägt fehl), startet zusätzlich Mirror 2, dann 3.
 * Die erste gültige Antwort gewinnt, alles endet spätestens nach $budgetS.
 */

const OVERPASS_MIRRORS = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
];
const OVERPASS_HEDGE_S    = 4.0;
const OVERPASS_USER_AGENT = 'Hydrantennavigator/1.1 (+https://feuerwehr.julian-bruegger.ch)';

/**
 * Führt eine Overpass-Abfrage aus.
 *
 * Rückgabe: [
 *   'data'   => array|null   dekodierte Antwort (null bei Fehler)
 *   'raw'    => string|null  Rohantwort
 *   'mirror' => string|null  Host des erfolgreichen Mirrors
 *   'ms'     => int          Dauer in Millisekunden
 *   'errors' => string[]     Fehler der einzelnen Mirrors
 * ]
 */
function overpassQuery(string $oql, float $budgetS = 20.0, float $hedgeS = OVERPASS_HEDGE_S, ?array $mirrors = null): array
{
    $start    = microtime(true);
    $deadline = $start + $budgetS;
    $mirrors  = $mirrors ?? OVERPASS_MIRRORS;
    $mh       = curl_multi_init();
    $handles  = []; // spl_object_id => [CurlHandle, host]
    $next     = 0;
    $nextAt   = $start;
    $errors   = [];
    $result   = null;

    while (true) {
        $now = microtime(true);
        if ($now >= $deadline) {
            if ($handles) $errors[] = 'Zeitbudget erschöpft';
            break;
        }

        // Nächsten Mirror starten: sofort, wenn nichts läuft, sonst nach $hedgeS
        if ($next < count($mirrors) && (!$handles || $now >= $nextAt)) {
            $ch = curl_init($mirrors[$next]);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'data=' . urlencode($oql),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT_MS     => max(1000, (int) (($deadline - $now) * 1000)),
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => OVERPASS_USER_AGENT,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[spl_object_id($ch)] = [$ch, (string) parse_url($mirrors[$next], PHP_URL_HOST)];
            $next++;
            $nextAt = $now + $hedgeS;
        }

        if (!$handles) break; // alle Mirrors versucht und fehlgeschlagen

        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $host = $handles[$id][1] ?? '?';
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $raw  = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$id]);

            if ($info['result'] === CURLE_OK && $code === 200 && $raw) {
                $data = json_decode($raw, true);
                // Overpass meldet Laufzeitfehler (z.B. Timeout) mit HTTP 200 und
                // unvollständigen Daten im "remark" – solche Antworten nie cachen.
                $remark = is_array($data) ? (string) ($data['remark'] ?? '') : '';
                if (is_array($data) && isset($data['elements']) && stripos($remark, 'error') === false) {
                    $result = ['data' => $data, 'raw' => $raw, 'mirror' => $host];
                    break 2;
                }
                $errors[] = "{$host}: " . ($remark !== '' ? $remark : 'ungültige Antwort');
            } else {
                $errors[] = "{$host}: " . ($info['result'] !== CURLE_OK ? curl_strerror($info['result']) : "HTTP {$code}");
            }
            // Fehlgeschlagen → nächsten Mirror sofort starten
            $nextAt = microtime(true);
        }

        $until = $next < count($mirrors) ? min($deadline, $nextAt) : $deadline;
        $wait  = min(0.2, max(0.01, $until - microtime(true)));
        if (curl_multi_select($mh, $wait) === -1) usleep(10000);
    }

    foreach ($handles as [$ch]) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return [
        'data'   => $result['data'] ?? null,
        'raw'    => $result['raw'] ?? null,
        'mirror' => $result['mirror'] ?? null,
        'ms'     => (int) round((microtime(true) - $start) * 1000),
        'errors' => $errors,
    ];
}

/** Schreibt eine Zeile in cache/overpass.log (rotiert bei ~512 KB). */
function overpassLog(string $line): void
{
    $dir = __DIR__ . '/../../cache/';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $path = $dir . 'overpass.log';
    if (@filesize($path) > 512 * 1024) @rename($path, $path . '.1');
    @file_put_contents($path, date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
