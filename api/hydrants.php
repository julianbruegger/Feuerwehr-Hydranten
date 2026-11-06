<?php
/**
 * Hydrantennavigator – Hydranten-Kacheln (Fallback ausserhalb der statischen Daten)
 * GET /api/hydrants.php?tiles=172_940,173_940,...
 *
 * Kachel-Key "x_y" = floor(lng / 0.05) _ floor(lat / 0.05).
 * Antwort: { "tiles": { "x_y": { "ts": 1700000000, "elements": [...] } }, "missing": [] }
 *
 * Innerhalb der Schweiz lädt der Browser statische Kacheln direkt
 * (/data/hydrants/{x}_{y}.json, siehe .github/workflows/hydrant-data.yml).
 *
 * Diese API wartet nie auf Overpass, wenn der Server das unterstützt
 * (PHP-FPM): Gecachte Kacheln werden sofort geliefert, Kacheln ohne Daten
 * stehen in "missing" und werden NACH der Antwort im Hintergrund geladen –
 * der Client fragt sie kurz darauf erneut an. Ohne PHP-FPM wird synchron
 * mit festem Zeitbudget geladen (deutlich unter dem 30-s-Limit des Hosters).
 */

require_once __DIR__ . '/../php/includes/hydrant_tiles.php';

const HYDRANT_SYNC_BUDGET_S       = 18.0;
const HYDRANT_BACKGROUND_BUDGET_S = 25.0;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$t0   = microtime(true);
$raw  = (string) (filter_input(INPUT_GET, 'tiles', FILTER_DEFAULT) ?? '');
$keys = [];
foreach (explode(',', $raw) as $key) {
    $key = trim($key);
    if ($key !== '' && hydrantTileParse($key) !== null) $keys[$key] = true;
}
$keys = array_keys($keys);

if (!$keys) {
    http_response_code(400);
    exit(json_encode(['error' => 'Parameter tiles fehlt oder ungültig'], JSON_UNESCAPED_UNICODE));
}
if (count($keys) > HYDRANT_TILE_MAX_BATCH) {
    http_response_code(400);
    exit(json_encode(['error' => 'Zu viele Kacheln (max. ' . HYDRANT_TILE_MAX_BATCH . ')'], JSON_UNESCAPED_UNICODE));
}

$res      = hydrantTilesGet($keys);
$tiles    = $res['tiles'];
$canDefer = function_exists('fastcgi_finish_request');
$fetchMs  = null;

// Ohne PHP-FPM: fehlende Kacheln jetzt laden (mit Zeitbudget)
if ($res['cold'] && !$canDefer) {
    $tf = microtime(true);
    foreach (hydrantTilesRefresh($res['cold'], HYDRANT_SYNC_BUDGET_S) as $key => $tile) {
        $tiles[$key] = $tile;
    }
    $fetchMs = (int) round((microtime(true) - $tf) * 1000);
}

$missing = array_values(array_diff($keys, array_keys($tiles)));

// Vollständige Antworten darf der Browser einen Tag cachen; Teilantworten nicht.
if ($missing) {
    header('Cache-Control: no-store');
    header('Retry-After: 2');
} else {
    header('Cache-Control: public, max-age=86400');
}

$timing = sprintf(
    'tiles;desc="hit=%d static=%d stale=%d cold=%d", total;dur=%d',
    count($res['tiles']) - count($res['stale']),
    $res['static'],
    count($res['stale']),
    count($res['cold']),
    (int) round((microtime(true) - $t0) * 1000)
);
if ($fetchMs !== null) $timing .= ", overpass;dur={$fetchMs}";
header('Server-Timing: ' . $timing);

$out = [];
foreach ($tiles as $key => $tile) {
    $out[$key] = ['ts' => $tile['ts'], 'elements' => $tile['elements']];
}
$json = json_encode(['tiles' => (object) $out, 'missing' => $missing], JSON_UNESCAPED_UNICODE);

if (!ini_get('zlib.output_compression') && strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false && function_exists('gzencode')) {
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    $json = gzencode($json, 6);
}
header('Content-Length: ' . strlen($json));
echo $json;

// Mit PHP-FPM: Antwort abschliessen, dann fehlende und veraltete Kacheln laden
$refresh = $canDefer ? array_merge($missing, $res['stale']) : [];
if ($refresh) {
    ignore_user_abort(true);
    @set_time_limit((int) HYDRANT_BACKGROUND_BUDGET_S + 15);
    fastcgi_finish_request();
    hydrantTilesRefresh($refresh, HYDRANT_BACKGROUND_BUDGET_S);
}
