<?php
/**
 * Hydranten-Kachel-Cache vorwärmen (nur CLI, z.B. als monatlicher Cronjob).
 *
 *   php php/cli/prewarm_hydrants.php                    # ganze Schweiz
 *   php php/cli/prewarm_hydrants.php 46.9 8.1 47.2 8.6  # eigene BBox (S W N E)
 *
 * Lädt die Hydranten blockweise (5×5 Kacheln pro Overpass-Abfrage) und
 * überspringt Blöcke, deren Kacheln noch frisch sind. Danach muss beim ersten
 * Aufruf eines Gebiets nie mehr auf Overpass gewartet werden.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/hydrant_tiles.php';

[$s, $w, $n, $e] = count($argv) === 5
    ? array_map('floatval', array_slice($argv, 1))
    : [45.8, 5.9, 47.85, 10.5]; // Schweiz

const BLOCK = 5; // Kacheln pro Blockseite

$x0 = (int) floor($w / HYDRANT_TILE_DEG);
$x1 = (int) floor($e / HYDRANT_TILE_DEG);
$y0 = (int) floor($s / HYDRANT_TILE_DEG);
$y1 = (int) floor($n / HYDRANT_TILE_DEG);

$done = 0; $skipped = 0; $failed = 0;
for ($by = $y0; $by <= $y1; $by += BLOCK) {
    for ($bx = $x0; $bx <= $x1; $bx += BLOCK) {
        $keys = [];
        for ($y = $by; $y < min($by + BLOCK, $y1 + 1); $y++) {
            for ($x = $bx; $x < min($bx + BLOCK, $x1 + 1); $x++) {
                $tile = hydrantTileRead(hydrantTileKey($x, $y));
                if (!hydrantTileIsFresh($tile)) {
                    $keys[] = hydrantTileKey($x, $y);
                }
            }
        }
        if (!$keys) { $skipped++; continue; }

        $res = hydrantTilesFetchFromOverpass($keys, 90);
        if ($res === null) {
            $failed++;
            fwrite(STDERR, "Block {$bx}_{$by}: Overpass-Fehler\n");
            sleep(10);
            continue;
        }
        $count = array_sum(array_map('count', $res));
        echo "Block {$bx}_{$by}: " . count($keys) . " Kacheln, {$count} Hydranten\n";
        $done++;
        sleep(2); // Overpass-Fair-Use
    }
}

echo "Fertig: {$done} Blöcke geladen, {$skipped} übersprungen, {$failed} fehlgeschlagen\n";
exit($failed > 0 ? 1 : 0);
