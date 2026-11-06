<?php
/**
 * Hydrantennavigator – Overpass API Proxy with Server-Side Cache
 * (Hydranten lädt die Karte über /api/hydrants.php – Kachel-Cache.)
 * GET /api/overpass.php?type=hydrants&lat=47.04&lng=8.30[&radius=2000]
 * GET /api/overpass.php?type=barriers&lat=47.04&lng=8.30[&radius=2000]
 *
 * Designed for Hostpoint shared hosting (PHP + cURL, no persistent process).
 * Caches Overpass responses server-side for 7 days to reduce API load
 * and share cache across all clients hitting the same grid cell.
 * Within Switzerland the map loads barriers from static tiles
 * (/data/barriers/{x}_{y}.json); this proxy is the fallback elsewhere.
 * Overpass is queried with a fixed time budget (php/includes/overpass.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Parameters ────────────────────────────────────────────────────────────────

$type   = filter_input(INPUT_GET, 'type',   FILTER_DEFAULT)      ?? '';
$lat    = filter_input(INPUT_GET, 'lat',    FILTER_VALIDATE_FLOAT);
$lng    = filter_input(INPUT_GET, 'lng',    FILTER_VALIDATE_FLOAT);
$radius = filter_input(INPUT_GET, 'radius', FILTER_VALIDATE_INT) ?: 2000;

// Validate type whitelist
if (!in_array($type, ['hydrants', 'barriers'], true)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Ungültiger type. Erlaubt: hydrants, barriers'], JSON_UNESCAPED_UNICODE));
}

// Validate coordinates
if ($lat === false || $lat === null || $lng === false || $lng === null) {
    http_response_code(400);
    exit(json_encode(['error' => 'lat und lng sind Pflichtparameter'], JSON_UNESCAPED_UNICODE));
}
if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
    http_response_code(400);
    exit(json_encode(['error' => 'Koordinaten außerhalb des gültigen Bereichs'], JSON_UNESCAPED_UNICODE));
}

// Clamp radius to 200–5000
$radius = max(200, min(5000, (int) $radius));

// Snap lat/lng to 0.005° grid (~500 m) — same as client-side cache key
$snapLat = round($lat / 0.005) * 0.005;
$snapLng = round($lng / 0.005) * 0.005;

// ── Cache ─────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../php/includes/overpass.php';

const PROXY_FRESH_S            = 7 * 86400;
const PROXY_SYNC_BUDGET_S      = 18.0;
const PROXY_BACKGROUND_BUDGET_S = 25.0;

$cacheDir  = __DIR__ . '/../cache/';
$latStr    = number_format($snapLat, 3, '.', '');
$lngStr    = number_format($snapLng, 3, '.', '');
$cachePath = $cacheDir . "{$type}_{$latStr}_{$lngStr}_{$radius}.json";

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

// ── Build Overpass query ──────────────────────────────────────────────────────

$qt = (int) PROXY_SYNC_BUDGET_S - 2;
if ($type === 'hydrants') {
    $oql = "[out:json][timeout:{$qt}];node[\"emergency\"=\"fire_hydrant\"](around:{$radius},{$snapLat},{$snapLng});out body;";
} else {
    $barrierRadius = min($radius, 1500);
    $oql = <<<OQL
[out:json][timeout:{$qt}];
(
  way["waterway"~"^(river|stream|canal)$"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["railway"~"^(rail|tram|subway|light_rail|narrow_gauge)$"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["highway"]["bridge"="yes"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["highway"]["tunnel"="yes"](around:{$barrierRadius},{$snapLat},{$snapLng});
);
out geom;
OQL;
}

/** Lädt von Overpass und schreibt den Cache. Gibt die Rohantwort oder null zurück. */
function proxyFetch(string $oql, string $cachePath, string $type, float $budgetS): ?string
{
    $res = overpassQuery($oql, $budgetS);
    overpassLog(sprintf(
        'proxy type=%s ms=%d %s%s',
        $type,
        $res['ms'],
        $res['raw'] !== null ? "ok mirror={$res['mirror']}" : 'FAIL',
        $res['errors'] ? ' errors=' . implode(' | ', $res['errors']) : ''
    ));
    if ($res['raw'] === null) return null;
    $tmp = $cachePath . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $res['raw']) !== false) @rename($tmp, $cachePath);
    return $res['raw'];
}

/** Antwort senden und (mit PHP-FPM) die Verbindung schliessen. */
function proxySend(string $body, string $cacheControl): void
{
    header('Cache-Control: ' . $cacheControl);
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

// ── Aus dem Cache liefern ─────────────────────────────────────────────────────
// Hydranten/Barrieren ändern sich selten → 7 Tage frisch. Ältere Einträge
// werden sofort geliefert und (mit PHP-FPM) nach der Antwort aktualisiert.

if (is_file($cachePath)) {
    $fresh = (time() - filemtime($cachePath)) < PROXY_FRESH_S;
    proxySend((string) file_get_contents($cachePath), $fresh ? 'public, max-age=86400' : 'no-cache');
    if (!$fresh && function_exists('fastcgi_finish_request')) {
        ignore_user_abort(true);
        @set_time_limit((int) PROXY_BACKGROUND_BUDGET_S + 15);
        $lock = @fopen($cachePath . '.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            proxyFetch($oql, $cachePath, $type, PROXY_BACKGROUND_BUDGET_S);
            flock($lock, LOCK_UN);
        }
        if ($lock) fclose($lock);
    }
    exit;
}

// ── Kein Cache: synchron mit Zeitbudget laden ─────────────────────────────────

$raw = proxyFetch($oql, $cachePath, $type, PROXY_SYNC_BUDGET_S);
if ($raw === null) {
    http_response_code(504);
    header('Cache-Control: no-store');
    exit(json_encode(['error' => 'Overpass nicht erreichbar'], JSON_UNESCAPED_UNICODE));
}
proxySend($raw, 'public, max-age=86400');
