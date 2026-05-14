<?php
require_once __DIR__ . '/../includes/auth.php';

corsHeaders();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$lat = filter_var($_GET['lat'] ?? '', FILTER_VALIDATE_FLOAT);
$lng = filter_var($_GET['lng'] ?? '', FILTER_VALIDATE_FLOAT);
$hoseLengthM = filter_var($_GET['hose_length'] ?? 20, FILTER_VALIDATE_FLOAT) ?: 20.0;
$radius = filter_var($_GET['radius'] ?? 1500, FILTER_VALIDATE_INT) ?: 1500;

if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    jsonError('lat und lng sind Pflichtparameter');
}

// Fetch hydrants from Overpass
$oql = "[out:json][timeout:15];\nnode[\"emergency\"=\"fire_hydrant\"](around:{$radius},{$lat},{$lng});\nout body;";

$overpassEndpoints = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
];

$elements = null;
foreach ($overpassEndpoints as $endpoint) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . urlencode($oql),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $body) {
        $data = json_decode($body, true);
        if (isset($data['elements'])) {
            $elements = $data['elements'];
            break;
        }
    }
}

if ($elements === null) {
    jsonError('Overpass API nicht erreichbar', 502);
}

if (count($elements) === 0) {
    jsonResponse(['hoses' => null, 'error' => "Keine Hydranten im Umkreis von {$radius} m gefunden"]);
}

// Haversine distance
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000;
    $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1); $Δλ = deg2rad($lng2 - $lng1);
    $a = sin($Δφ/2)**2 + cos($φ1)*cos($φ2)*sin($Δλ/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

foreach ($elements as &$el) {
    $el['_dist'] = haversine($lat, $lng, $el['lat'], $el['lon']);
}
unset($el);
usort($elements, fn($a, $b) => $a['_dist'] <=> $b['_dist']);
$best = $elements[0];

// Try OSRM routing
$routeDistM = null;
$osrmUrl = "https://router.project-osrm.org/route/v1/driving/{$lng},{$lat};{$best['lon']},{$best['lat']}?overview=false";
$ch = curl_init($osrmUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
$osrmBody = curl_exec($ch);
$osrmCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($osrmCode === 200 && $osrmBody) {
    $osrm = json_decode($osrmBody, true);
    if (($osrm['code'] ?? '') === 'Ok' && isset($osrm['routes'][0]['distance'])) {
        $routeDistM = (float) $osrm['routes'][0]['distance'];
    }
}

$distM = $routeDistM ?? $best['_dist'];
$hoses = (int) ceil($distM / $hoseLengthM);
$tags = $best['tags'] ?? [];
$hType = $tags['fire_hydrant:type'] ?? $tags['fire_hydrant'] ?? 'unknown';

$distFormatted = $distM >= 1000
    ? number_format($distM / 1000, 1, '.', '') . ' km'
    : round($distM) . ' m';
$distTypeLabel = $routeDistM ? 'Fahrstrecke' : 'Luftlinie';

jsonResponse([
    'hoses'          => $hoses,
    'hose_length_m'  => $hoseLengthM,
    'distance_m'     => (int) round($distM),
    'distance_type'  => $routeDistM ? 'road' : 'air',
    'hydrant'        => [
        'lat'            => $best['lat'],
        'lng'            => $best['lon'],
        'type'           => $hType,
        'air_distance_m' => (int) round($best['_dist']),
    ],
    'summary'        => "{$hoses} Schläuche à " . round($hoseLengthM) . " m ({$distFormatted} {$distTypeLabel})",
]);
