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
 */

require_once __DIR__ . '/../php/includes/hydrant_tiles.php';
hydrantApiGuard();

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

$cacheDir  = __DIR__ . '/../cache/';
$latStr    = number_format($snapLat, 3, '.', '');
$lngStr    = number_format($snapLng, 3, '.', '');
$cachePath = $cacheDir . "{$type}_{$latStr}_{$lngStr}_{$radius}.json";

// Hydranten/Barrieren ändern sich selten → 7 Tage frisch; ältere Einträge
// werden nur noch als Fallback verwendet, wenn Overpass nicht erreichbar ist.
$hasStale = file_exists($cachePath);
if ($hasStale && (time() - filemtime($cachePath)) < 7 * 86400) {
    header('Cache-Control: public, max-age=86400');
    echo file_get_contents($cachePath);
    exit;
}

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

// ── Build Overpass query ──────────────────────────────────────────────────────

if ($type === 'hydrants') {
    $oql = "[out:json][timeout:15];node[\"emergency\"=\"fire_hydrant\"](around:{$radius},{$snapLat},{$snapLng});out body;";
} else {
    $barrierRadius = min($radius, 1500);
    $oql = <<<OQL
[out:json][timeout:15];
(
  way["waterway"~"^(river|stream|canal)$"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["railway"~"^(rail|tram|subway|light_rail|narrow_gauge)$"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["highway"]["bridge"="yes"](around:{$barrierRadius},{$snapLat},{$snapLng});
  way["highway"]["tunnel"="yes"](around:{$barrierRadius},{$snapLat},{$snapLng});
);
out geom;
OQL;
}

// ── Overpass (gemeinsamer Helper: Mirrors, Zeitbudget, Circuit Breaker) ─────

$data = hydrantOverpassQuery($oql);
$raw  = $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

if ($raw === null && $hasStale) {
    echo file_get_contents($cachePath);
    exit;
}
if ($raw === null) {
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: ' . HYDRANT_OVERPASS_BACKOFF_S);
    exit(json_encode(['error' => 'Overpass nicht erreichbar'], JSON_UNESCAPED_UNICODE));
}

// ── Cache and return ──────────────────────────────────────────────────────────

file_put_contents($cachePath, $raw, LOCK_EX);
header('Cache-Control: public, max-age=86400');
echo $raw;
