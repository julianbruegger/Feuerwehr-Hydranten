<?php
/**
 * Hydranten-Kachel-Cache
 *
 * Die Welt wird in feste Kacheln à TILE_DEG° (~5.5 km × 3.8 km in der Schweiz)
 * eingeteilt. Jede Kachel wird einmal von Overpass geladen und dann lange als
 * JSON-Datei gecacht – Hydranten ändern sich kaum. Weil die Kacheln fest sind,
 * teilen sich alle Clients, Kartenausschnitte und Radien denselben Cache
 * (im Gegensatz zu "around:radius"-Abfragen, die fast nie wiederverwendbar sind).
 *
 * Fehlende Kacheln einer Anfrage werden mit EINER Overpass-Abfrage über ihre
 * gemeinsame Bounding-Box geholt und anschliessend auf die Kacheln verteilt.
 */

const HYDRANT_TILE_DEG       = 0.05;
const HYDRANT_TILE_FRESH_S   = 30 * 86400;   // 30 Tage frisch
const HYDRANT_TILE_EMPTY_S   = 86400;        // Leere Kacheln nur 1 Tag (evtl. fehlerhafte Antwort)
const HYDRANT_TILE_VERSION   = 'h2';         // Dateipräfix – erhöhen, um den Cache zu verwerfen
const HYDRANT_TILE_MAX_BATCH = 16;           // Max. Kacheln pro Anfrage

function hydrantTileDir(): string
{
    $dir = __DIR__ . '/../../cache/tiles/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Validiert einen Kachel-Key "x_y" und gibt [x, y] zurück (oder null). */
function hydrantTileParse(string $key): ?array
{
    if (!preg_match('/^(-?\d{1,4})_(-?\d{1,4})$/', $key, $m)) return null;
    $x = (int) $m[1];
    $y = (int) $m[2];
    $maxX = (int) (180 / HYDRANT_TILE_DEG);
    $maxY = (int) (90 / HYDRANT_TILE_DEG);
    if ($x < -$maxX || $x >= $maxX || $y < -$maxY || $y >= $maxY) return null;
    return [$x, $y];
}

function hydrantTileKey(int $x, int $y): string
{
    return "{$x}_{$y}";
}

/** Kachel-Keys, die eine Bounding-Box abdecken. */
function hydrantTilesForBbox(float $s, float $w, float $n, float $e): array
{
    $keys = [];
    $x0 = (int) floor($w / HYDRANT_TILE_DEG);
    $x1 = (int) floor($e / HYDRANT_TILE_DEG);
    $y0 = (int) floor($s / HYDRANT_TILE_DEG);
    $y1 = (int) floor($n / HYDRANT_TILE_DEG);
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $keys[] = hydrantTileKey($x, $y);
        }
    }
    return $keys;
}

/** Kachel-Keys, die einen Kreis um lat/lng abdecken. */
function hydrantTilesForRadius(float $lat, float $lng, float $radiusM): array
{
    $dLat = $radiusM / 111320;
    $dLng = $radiusM / (111320 * max(0.01, cos(deg2rad($lat))));
    return hydrantTilesForBbox($lat - $dLat, $lng - $dLng, $lat + $dLat, $lng + $dLng);
}

/** Liest eine Kachel aus dem Datei-Cache: ['ts' => int, 'elements' => array] oder null. */
function hydrantTileRead(string $key): ?array
{
    $path = hydrantTileDir() . HYDRANT_TILE_VERSION . "_{$key}.json";
    if (!is_file($path)) return null;
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data) || !isset($data['elements'])) return null;
    $data['ts'] = (int) ($data['ts'] ?? filemtime($path));
    return $data;
}

/** Kachel noch frisch? Leere Kacheln laufen schneller ab. */
function hydrantTileIsFresh(?array $tile): bool
{
    if (!$tile) return false;
    $ttl = empty($tile['elements']) ? HYDRANT_TILE_EMPTY_S : HYDRANT_TILE_FRESH_S;
    return (time() - $tile['ts']) < $ttl;
}

function hydrantTileWrite(string $key, array $elements, int $ts): void
{
    $path = hydrantTileDir() . HYDRANT_TILE_VERSION . "_{$key}.json";
    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = json_encode(['ts' => $ts, 'elements' => $elements], JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $path);
    }
}

/**
 * POST an die Overpass-Mirrors, gibt dekodierte Antwort oder null zurück.
 * Antworten mit "remark" (Timeout, Speicherfehler) sind unvollständig und werden
 * verworfen. Eine leere Antwort wird nur akzeptiert, wenn kein Mirror Daten liefert.
 */
function hydrantOverpassQuery(string $oql, int $timeout = 30): ?array
{
    $mirrors = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    ];
    $empty = null;
    foreach ($mirrors as $mirror) {
        $ch = curl_init($mirror);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($oql),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Hydrantennavigator/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$raw) continue;
        $data = json_decode($raw, true);
        if (!isset($data['elements']) || !is_array($data['elements'])) continue;
        if (!empty($data['remark'])) {
            error_log('[hydrant_tiles] Overpass-Remark von ' . $mirror . ': ' . $data['remark']);
            continue;
        }
        if ($data['elements']) return $data;
        $empty = $empty ?? $data;
    }
    return $empty;
}

/**
 * Lädt Hydranten für eine Bounding-Box von Overpass und schreibt sie in alle
 * übergebenen Kacheln (Kacheln ohne Hydranten werden als leer gecacht).
 * Gibt [key => elements] zurück oder null bei Fehler.
 */
function hydrantTilesFetchFromOverpass(array $keys, int $timeout = 30): ?array
{
    if (!$keys) return [];
    $s = INF; $w = INF; $n = -INF; $e = -INF;
    foreach ($keys as $key) {
        [$x, $y] = hydrantTileParse($key);
        $s = min($s, $y * HYDRANT_TILE_DEG);
        $w = min($w, $x * HYDRANT_TILE_DEG);
        $n = max($n, ($y + 1) * HYDRANT_TILE_DEG);
        $e = max($e, ($x + 1) * HYDRANT_TILE_DEG);
    }
    $bbox = sprintf('%.4f,%.4f,%.4f,%.4f', $s, $w, $n, $e);
    $oql  = "[out:json][timeout:" . max(10, $timeout - 5) . "][bbox:{$bbox}];node[\"emergency\"=\"fire_hydrant\"];out body qt;";

    $data = hydrantOverpassQuery($oql, $timeout);
    if ($data === null) return null;

    $wanted = array_fill_keys($keys, []);
    $all    = [];
    foreach ($data['elements'] as $el) {
        if (!isset($el['lat'], $el['lon'])) continue;
        $key = hydrantTileKey(
            (int) floor($el['lon'] / HYDRANT_TILE_DEG),
            (int) floor($el['lat'] / HYDRANT_TILE_DEG)
        );
        $all[$key][] = [
            'id'   => $el['id'],
            'lat'  => $el['lat'],
            'lon'  => $el['lon'],
            'tags' => $el['tags'] ?? (object) [],
        ];
    }

    $now = time();
    foreach ($keys as $key) {
        $wanted[$key] = $all[$key] ?? [];
        hydrantTileWrite($key, $wanted[$key], $now);
    }
    return $wanted;
}

/**
 * Liefert Hydranten für die gegebenen Kacheln – aus dem Cache, wenn frisch,
 * sonst von Overpass. Ist Overpass nicht erreichbar, werden veraltete
 * Cache-Einträge verwendet (besser alte Daten als keine).
 *
 * Rückgabe: ['tiles' => [key => ['ts' => int, 'elements' => array]], 'missing' => [keys]]
 */
function hydrantTilesGet(array $keys): array
{
    $result  = [];
    $stale   = [];
    $toFetch = [];

    foreach ($keys as $key) {
        $tile = hydrantTileRead($key);
        if (hydrantTileIsFresh($tile)) {
            $result[$key] = $tile;
        } else {
            if ($tile) $stale[$key] = $tile;
            $toFetch[] = $key;
        }
    }

    if ($toFetch) {
        // Gleichzeitige Anfragen serialisieren (schont Overpass-Rate-Limit und
        // verhindert, dass dieselbe Kachel mehrfach geladen wird).
        $lock = @fopen(hydrantTileDir() . '.overpass.lock', 'c');
        $locked = false;
        if ($lock) {
            for ($i = 0; $i < 40 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $i++) {
                usleep(250000);
            }
        }

        // Nach dem Warten erneut prüfen – evtl. hat ein anderer Request die Kacheln geladen
        $still = [];
        foreach ($toFetch as $key) {
            $tile = hydrantTileRead($key);
            if (hydrantTileIsFresh($tile)) {
                $result[$key] = $tile;
            } else {
                $still[] = $key;
            }
        }

        if ($still) {
            $fetched = hydrantTilesFetchFromOverpass($still);
            $now = time();
            foreach ($still as $key) {
                if ($fetched !== null) {
                    $result[$key] = ['ts' => $now, 'elements' => $fetched[$key]];
                } elseif (isset($stale[$key])) {
                    $result[$key] = $stale[$key];
                }
            }
        }

        if ($lock) {
            if ($locked) flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    $missing = array_values(array_diff($keys, array_keys($result)));
    return ['tiles' => $result, 'missing' => $missing];
}
