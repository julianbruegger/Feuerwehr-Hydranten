<?php
/**
 * Hydrantennavigator – Hydranten-Kacheln
 * GET /api/hydrants.php?tiles=172_940,173_940,...
 *
 * Kachel-Key "x_y" = floor(lng / 0.05) _ floor(lat / 0.05).
 * Antwort: { "tiles": { "x_y": { "ts": 1700000000, "elements": [...] } }, "missing": [] }
 *
 * Kacheln werden serverseitig 30 Tage gecacht (siehe php/includes/hydrant_tiles.php).
 */

require_once __DIR__ . '/../php/includes/hydrant_tiles.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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

$res = hydrantTilesGet($keys);

if (!$res['tiles']) {
    http_response_code(502);
    exit(json_encode(['error' => 'Overpass nicht erreichbar', 'missing' => $res['missing']], JSON_UNESCAPED_UNICODE));
}

// Vollständige Antworten darf der Browser einen Tag cachen; Teilantworten nicht.
header($res['missing'] ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=86400');

$json = json_encode([
    'tiles'   => (object) $res['tiles'],
    'missing' => $res['missing'],
], JSON_UNESCAPED_UNICODE);

if (!ini_get('zlib.output_compression') && strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false && function_exists('gzencode')) {
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    $json = gzencode($json, 6);
}
echo $json;
