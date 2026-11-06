<?php
/**
 * Hydranten-Kacheln
 *
 * Die Welt wird in feste Kacheln à HYDRANT_TILE_DEG° (~5.5 km × 3.8 km in der
 * Schweiz) eingeteilt. Quellen, in dieser Reihenfolge:
 *
 *  1. Statische Kacheln data/hydrants/{x}_{y}.json – wöchentlich per GitHub
 *     Action aus dem Geofabrik-Extrakt erzeugt (ganze Schweiz). Der Browser
 *     lädt diese direkt (ohne PHP); hier werden sie nur für serverseitige
 *     Berechnungen (z.B. /api/hoses) gelesen.
 *  2. Datei-Cache cache/tiles/h_{x}_{y}.json – von Overpass geladen, 30 Tage
 *     frisch, danach weiter als Fallback nutzbar.
 *  3. Overpass – nur für Kacheln ausserhalb der statischen Daten. Fehlende
 *     Kacheln einer Anfrage werden mit EINER Abfrage über ihre gemeinsame
 *     Bounding-Box geholt und anschliessend auf die Kacheln verteilt.
 */

require_once __DIR__ . '/overpass.php';

const HYDRANT_TILE_DEG       = 0.05;
const HYDRANT_TILE_FRESH_S   = 30 * 86400;   // 30 Tage frisch
const HYDRANT_TILE_MAX_BATCH = 16;           // Max. Kacheln pro Anfrage

/** Tags, die die App tatsächlich verwendet (alles andere wird verworfen). */
const HYDRANT_TAG_WHITELIST = [
    'fire_hydrant:type', 'fire_hydrant:diameter', 'fire_hydrant:position',
    'addr:street', 'addr:housenumber', 'name', 'ref',
    'image', 'wikimedia_commons',
];

function hydrantTileDir(): string
{
    $dir = __DIR__ . '/../../cache/tiles/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function hydrantStaticDir(): string
{
    return __DIR__ . '/../../data/hydrants/';
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

/** Reduziert OSM-Tags auf die Whitelist. */
function hydrantSlimTags(array $tags): array
{
    return array_intersect_key($tags, array_flip(HYDRANT_TAG_WHITELIST));
}

/**
 * Liest eine Kachel – zuerst die statische Datei, dann den Overpass-Cache.
 * Rückgabe: ['ts' => int, 'elements' => array, 'static' => bool] oder null.
 */
function hydrantTileRead(string $key): ?array
{
    foreach ([[hydrantStaticDir() . "{$key}.json", true], [hydrantTileDir() . "h_{$key}.json", false]] as [$path, $static]) {
        if (!is_file($path)) continue;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['elements'])) continue;
        // Leere Tags würden sonst als [] statt {} ausgegeben
        foreach ($data['elements'] as &$el) {
            if (empty($el['tags'])) $el['tags'] = new stdClass();
        }
        unset($el);
        return [
            'ts'       => (int) ($data['ts'] ?? $data['v'] ?? filemtime($path)),
            'elements' => $data['elements'],
            'static'   => $static,
        ];
    }
    return null;
}

/** Statische Kacheln sind immer aktuell (werden wöchentlich neu erzeugt). */
function hydrantTileIsFresh(?array $tile): bool
{
    return $tile !== null && (!empty($tile['static']) || (time() - $tile['ts']) < HYDRANT_TILE_FRESH_S);
}

function hydrantTileWrite(string $key, array $elements, int $ts): void
{
    $path = hydrantTileDir() . "h_{$key}.json";
    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = json_encode(['ts' => $ts, 'elements' => $elements], JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $path);
    }
}

/**
 * Lädt Hydranten für die Bounding-Box der Kacheln von Overpass und schreibt
 * sie in alle übergebenen Kacheln (Kacheln ohne Hydranten werden als leer
 * gecacht). Gibt [key => elements] zurück oder null bei Fehler.
 */
function hydrantTilesFetchFromOverpass(array $keys, float $budgetS = 20.0): ?array
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
    $qt   = max(5, (int) floor($budgetS) - 2);
    $oql  = "[out:json][timeout:{$qt}][bbox:{$bbox}];node[\"emergency\"=\"fire_hydrant\"];out body qt;";

    $res = overpassQuery($oql, $budgetS);
    $count = $res['data'] !== null ? count($res['data']['elements']) : 0;
    overpassLog(sprintf(
        'hydrants tiles=%d ms=%d %s%s',
        count($keys),
        $res['ms'],
        $res['data'] !== null ? "ok mirror={$res['mirror']} elements={$count}" : 'FAIL',
        $res['errors'] ? ' errors=' . implode(' | ', $res['errors']) : ''
    ));
    if ($res['data'] === null) return null;

    $all = [];
    foreach ($res['data']['elements'] as $el) {
        if (!isset($el['lat'], $el['lon'])) continue;
        $key = hydrantTileKey(
            (int) floor($el['lon'] / HYDRANT_TILE_DEG),
            (int) floor($el['lat'] / HYDRANT_TILE_DEG)
        );
        $all[$key][] = [
            'id'   => $el['id'],
            'lat'  => round($el['lat'], 7),
            'lon'  => round($el['lon'], 7),
            'tags' => (object) hydrantSlimTags($el['tags'] ?? []),
        ];
    }

    $now    = time();
    $wanted = [];
    foreach ($keys as $key) {
        $wanted[$key] = $all[$key] ?? [];
        hydrantTileWrite($key, $wanted[$key], $now);
    }
    return $wanted;
}

/**
 * Liest Kacheln ausschliesslich aus statischen Dateien und Cache (nie Overpass).
 *
 * Rückgabe: [
 *   'tiles' => [key => tile]   alle Kacheln mit Daten (frisch oder veraltet)
 *   'cold'  => [keys]          Kacheln ohne jegliche Daten
 *   'stale' => [keys]          Kacheln mit veralteten Daten (in 'tiles' enthalten)
 *   'static'=> int             Anzahl aus statischen Dateien
 * ]
 */
function hydrantTilesGet(array $keys): array
{
    $tiles = []; $cold = []; $stale = []; $static = 0;
    foreach ($keys as $key) {
        $tile = hydrantTileRead($key);
        if ($tile === null) {
            $cold[] = $key;
            continue;
        }
        $tiles[$key] = $tile;
        if (!empty($tile['static'])) $static++;
        if (!hydrantTileIsFresh($tile)) $stale[] = $key;
    }
    return ['tiles' => $tiles, 'cold' => $cold, 'stale' => $stale, 'static' => $static];
}

/**
 * Lädt Kacheln von Overpass nach. Jede Kachel hat ein eigenes Lock: Kacheln,
 * die gerade von einer anderen Anfrage geladen werden, werden übersprungen
 * (nicht abgewartet), damit sich Anfragen für verschiedene Gebiete nie
 * gegenseitig blockieren.
 *
 * Rückgabe: [key => tile] der erfolgreich geladenen Kacheln.
 */
function hydrantTilesRefresh(array $keys, float $budgetS = 20.0): array
{
    if (!$keys) return [];
    $lockDir = hydrantTileDir() . 'locks/';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0750, true);

    $locks = [];
    $todo  = [];
    foreach ($keys as $key) {
        $fh = @fopen($lockDir . "h_{$key}.lock", 'c');
        if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
            $locks[] = $fh;
            // Evtl. hat eine andere Anfrage die Kachel inzwischen geladen
            if (!hydrantTileIsFresh(hydrantTileRead($key))) $todo[] = $key;
        } elseif ($fh) {
            fclose($fh);
        }
    }

    $result = [];
    if ($todo) {
        $fetched = hydrantTilesFetchFromOverpass($todo, $budgetS);
        if ($fetched !== null) {
            $now = time();
            foreach ($fetched as $key => $elements) {
                $result[$key] = ['ts' => $now, 'elements' => $elements, 'static' => false];
            }
        }
    }

    foreach ($locks as $fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $result;
}

/**
 * Synchron: Kacheln aus Cache liefern und fehlende/veraltete innerhalb von
 * $budgetS von Overpass nachladen. Ist Overpass nicht erreichbar, werden
 * veraltete Kacheln verwendet (besser alte Daten als keine).
 *
 * Rückgabe: ['tiles' => [key => tile], 'missing' => [keys]]
 */
function hydrantTilesLoad(array $keys, float $budgetS = 20.0): array
{
    $res = hydrantTilesGet($keys);
    $tiles = $res['tiles'];
    $refresh = array_merge($res['cold'], $res['stale']);
    if ($refresh) {
        foreach (hydrantTilesRefresh($refresh, $budgetS) as $key => $tile) {
            $tiles[$key] = $tile;
        }
    }
    $missing = array_values(array_diff($keys, array_keys($tiles)));
    return ['tiles' => $tiles, 'missing' => $missing];
}
