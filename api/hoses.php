<?php
/**
 * Hydrantennavigator – Hose Calculator API
 * GET /api/hoses.php?lat=47.04&lng=8.30[&hose_length=20&radius=1500]
 *
 * Designed for Hostpoint shared hosting (PHP + cURL, no persistent process).
 * Returns JSON with the number of hose sections to the nearest fire hydrant.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Parameters ────────────────────────────────────────────────────────────────

$lat        = filter_input(INPUT_GET, 'lat',         FILTER_VALIDATE_FLOAT);
$lng        = filter_input(INPUT_GET, 'lng',         FILTER_VALIDATE_FLOAT);
$hose_len   = filter_input(INPUT_GET, 'hose_length', FILTER_VALIDATE_FLOAT) ?: 20.0;
$radius     = filter_input(INPUT_GET, 'radius',      FILTER_VALIDATE_INT)   ?: 1500;

if ($lat === false || $lat === null || $lng === false || $lng === null) {
    http_response_code(400);
    exit(json_encode(['error' => 'lat und lng sind Pflichtparameter'], JSON_UNESCAPED_UNICODE));
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R    = 6_371_000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function curl_get(string $url, ?string $post_body = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Hydrantennavigator/1.0',
    ]);
    if ($post_body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $code !== 200) return null;
    return json_decode($raw, true) ?: null;
}

// ── Overpass (with mirror fallback) ──────────────────────────────────────────

$overpass_mirrors = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
];

$oql = <<<OQL
[out:json][timeout:15];
node["emergency"="fire_hydrant"](around:{$radius},{$lat},{$lng});
out body;
OQL;

$elements = null;
foreach ($overpass_mirrors as $mirror) {
    $result = curl_get($mirror, 'data=' . urlencode($oql));
    if (isset($result['elements'])) {
        $elements = $result['elements'];
        break;
    }
}

if ($elements === null) {
    http_response_code(502);
    exit(json_encode(['error' => 'Overpass API nicht erreichbar'], JSON_UNESCAPED_UNICODE));
}

if (empty($elements)) {
    exit(json_encode([
        'hoses' => null,
        'error' => "Keine Hydranten im Umkreis von {$radius} m gefunden",
    ], JSON_UNESCAPED_UNICODE));
}

// ── Sort by straight-line, pick nearest ──────────────────────────────────────

foreach ($elements as &$el) {
    $el['_dist'] = haversine($lat, $lng, $el['lat'], $el['lon']);
}
unset($el);
usort($elements, fn($a, $b) => $a['_dist'] <=> $b['_dist']);
$best = $elements[0];

// ── OSRM driving distance ─────────────────────────────────────────────────────

$osrm_url  = "https://router.project-osrm.org/route/v1/driving/"
           . "{$lng},{$lat};{$best['lon']},{$best['lat']}?overview=false";
$osrm      = curl_get($osrm_url);
$route_m   = ($osrm && $osrm['code'] === 'Ok' && !empty($osrm['routes']))
           ? (float) $osrm['routes'][0]['distance']
           : null;

$dist_m    = $route_m ?? $best['_dist'];
$hoses     = (int) ceil($dist_m / $hose_len);
$dist_type = $route_m ? 'road' : 'air';

// ── Response ──────────────────────────────────────────────────────────────────

$tags   = $best['tags'] ?? [];
$h_type = $tags['fire_hydrant:type'] ?? ($tags['fire_hydrant'] ?? 'unknown');

echo json_encode([
    'hoses'        => $hoses,
    'hose_length_m'=> $hose_len,
    'distance_m'   => (int) round($dist_m),
    'distance_type'=> $dist_type,
    'hydrant'      => [
        'lat'            => $best['lat'],
        'lng'            => $best['lon'],
        'type'           => $h_type,
        'air_distance_m' => (int) round($best['_dist']),
    ],
    'summary'      => "{$hoses} Schläuche à " . (int)$hose_len . " m ("
                    . (int)round($dist_m) . " m "
                    . ($route_m ? 'Fahrstrecke' : 'Luftlinie') . ")",
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
