#!/usr/bin/env python3
"""
Erzeugt statische Hydranten- und Barrieren-Kacheln aus einem OSM-Extrakt.

Eingabe:  GeoJSON-Sequence von `osmium export` (siehe build.sh) und das
          .poly-File des Extrakts (Geofabrik), das die Abdeckung definiert.
          Mehrere Länder: .poly-Files kommagetrennt angeben – die Abdeckung ist
          dann die Vereinigung aller Polygone, Grenzkacheln zwischen zwei
          Ländern sind damit ebenfalls statisch.
Ausgabe:  <out>/hydrants/{x}_{y}.json   {"v": V, "elements": [{id, lat, lon, tags}]}
          <out>/barriers/{x}_{y}.json   {"v": V, "w": [...], "r": [...], "p": [...]}
          <out>/manifest.json           Version + Liste der abgedeckten Kacheln

Kachel-Key "x_y" = floor(lng / 0.05) _ floor(lat / 0.05) – identisch zu
app.js und php/includes/hydrant_tiles.php.

Es werden nur Kacheln geschrieben, die vollständig im Extrakt-Polygon liegen.
Für Kacheln am Rand der abgedeckten Länder fehlen Daten jenseits der Grenze; sie lädt die App weiterhin
über /api/hydrants.php (Overpass).

Barrieren: w = Gewässer, r = Bahn, p = Brücken/Tunnel (Durchgänge). Jede Linie
ist eine flache Liste [lat, lon, lat, lon, ...]. Segmente werden jeder Kachel
zugeordnet, deren (leicht gepufferte) Fläche sie berühren.
"""

import argparse
import json
import math
import os
import sys
import time

from shapely.geometry import MultiPolygon, Polygon, box
from shapely.ops import unary_union
from shapely.prepared import prep

TILE_DEG = 0.05
BARRIER_BUFFER_DEG = 0.0005  # ~50 m: Brücken nahe der Kachelgrenze mitnehmen

TAG_WHITELIST = {
    'fire_hydrant:type', 'fire_hydrant:diameter', 'fire_hydrant:position',
    'addr:street', 'addr:housenumber', 'name', 'ref',
    'image', 'wikimedia_commons',
}
WATERWAYS = {'river', 'stream', 'canal'}
RAILWAYS = {'rail', 'tram', 'subway', 'light_rail', 'narrow_gauge'}


def tile_key(x, y):
    return f'{x}_{y}'


def parse_poly(path):
    """Liest ein Osmosis-.poly-File (Ringe mit '!' sind Löcher)."""
    with open(path, encoding='utf-8') as f:
        lines = [ln.strip() for ln in f if ln.strip()]
    outers, holes = [], []
    i = 1  # erste Zeile = Name
    while i < len(lines) and lines[i] != 'END':
        is_hole = lines[i].startswith('!')
        i += 1
        ring = []
        while lines[i] != 'END':
            lon, lat = map(float, lines[i].split()[:2])
            ring.append((lon, lat))
            i += 1
        i += 1
        (holes if is_hole else outers).append(ring)
    polys = []
    for outer in outers:
        shell = Polygon(outer)
        inner = [h for h in holes if shell.contains(Polygon(h))]
        polys.append(Polygon(outer, inner))
    return MultiPolygon(polys) if len(polys) > 1 else polys[0]


# Geofabrik-Polygone benachbarter Länder liegen an der Grenze nicht exakt
# aneinander. Schmale Lücken (< 2 × POLY_CLOSE_DEG, ~200 m) werden geschlossen,
# damit Grenzkacheln nicht an Slivern scheitern.
POLY_CLOSE_DEG = 0.001


def parse_polys(paths):
    """Vereinigung mehrerer .poly-Files (kommagetrennte Pfade)."""
    areas = [parse_poly(p) for p in paths.split(',') if p]
    if len(areas) == 1:
        return areas[0]
    return unary_union(areas).buffer(POLY_CLOSE_DEG).buffer(-POLY_CLOSE_DEG)


def covered_tiles(area):
    """Alle Kacheln, die vollständig im Polygon liegen."""
    p = prep(area)
    minx, miny, maxx, maxy = area.bounds
    out = set()
    for y in range(math.floor(miny / TILE_DEG), math.floor(maxy / TILE_DEG) + 1):
        for x in range(math.floor(minx / TILE_DEG), math.floor(maxx / TILE_DEG) + 1):
            if p.contains(box(x * TILE_DEG, y * TILE_DEG, (x + 1) * TILE_DEG, (y + 1) * TILE_DEG)):
                out.add((x, y))
    return out


def rows_from_tiles(tiles):
    """{y: [[x0, x1], ...]} – kompakte Darstellung der abgedeckten Kacheln."""
    by_row = {}
    for x, y in sorted(tiles, key=lambda t: (t[1], t[0])):
        ranges = by_row.setdefault(str(y), [])
        if ranges and ranges[-1][1] == x - 1:
            ranges[-1][1] = x
        else:
            ranges.append([x, x])
    return by_row


def classify_way(tags):
    """'p' (Brücke/Tunnel), 'w' (Gewässer), 'r' (Bahn) oder None – wie app.js."""
    is_passway = tags.get('bridge') == 'yes' or tags.get('tunnel') == 'yes'
    if is_passway and ('highway' in tags or tags.get('railway') in RAILWAYS):
        return 'p'
    if tags.get('waterway') in WATERWAYS:
        return 'p' if is_passway else 'w'
    if tags.get('railway') in RAILWAYS:
        return 'p' if is_passway else 'r'
    return None


def add_line(barriers, kind, coords, wanted):
    """Verteilt die Segmente einer Linie (lon, lat) auf die berührten Kacheln."""
    open_lines = {}  # Kachel → aktuelle Linie (flache Liste), wenn letztes Segment dort lag
    for i in range(len(coords) - 1):
        (lon1, lat1), (lon2, lat2) = coords[i], coords[i + 1]
        x0 = math.floor((min(lon1, lon2) - BARRIER_BUFFER_DEG) / TILE_DEG)
        x1 = math.floor((max(lon1, lon2) + BARRIER_BUFFER_DEG) / TILE_DEG)
        y0 = math.floor((min(lat1, lat2) - BARRIER_BUFFER_DEG) / TILE_DEG)
        y1 = math.floor((max(lat1, lat2) + BARRIER_BUFFER_DEG) / TILE_DEG)
        touched = set()
        for y in range(y0, y1 + 1):
            for x in range(x0, x1 + 1):
                if (x, y) not in wanted:
                    continue
                touched.add((x, y))
                line = open_lines.get((x, y))
                if line is None:
                    line = [round(lat1, 5), round(lon1, 5)]
                    barriers.setdefault((x, y), {'w': [], 'r': [], 'p': []})[kind].append(line)
                    open_lines[(x, y)] = line
                line.extend((round(lat2, 5), round(lon2, 5)))
        for t in list(open_lines):
            if t not in touched:
                del open_lines[t]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('geojsonseq', help='Ausgabe von osmium export -f geojsonseq')
    ap.add_argument('poly', help='.poly-File des Extrakts (mehrere kommagetrennt)')
    ap.add_argument('out', help='Ausgabeverzeichnis')
    ap.add_argument('--osm-timestamp', default='', help='Datenstand des Extrakts (ISO 8601)')
    ap.add_argument('--min-hydrants', type=int, default=0,
                    help='Abbrechen, wenn weniger Hydranten gefunden werden (Schutz vor kaputten Daten)')
    args = ap.parse_args()

    version = int(time.time())
    wanted = covered_tiles(parse_polys(args.poly))
    print(f'{len(wanted)} Kacheln vollständig im Extrakt', file=sys.stderr)

    hydrants = {}
    barriers = {}
    n_hydrants = 0
    n_lines = 0
    with open(args.geojsonseq, encoding='utf-8') as f:
        for raw in f:
            raw = raw.strip().lstrip('\x1e')
            if not raw:
                continue
            feat = json.loads(raw)
            geom = feat.get('geometry') or {}
            props = feat.get('properties') or {}
            tags = {k: v for k, v in props.items() if not k.startswith('@')}

            if geom.get('type') == 'Point' and tags.get('emergency') == 'fire_hydrant':
                lon, lat = geom['coordinates'][:2]
                t = (math.floor(lon / TILE_DEG), math.floor(lat / TILE_DEG))
                if t not in wanted:
                    continue
                hydrants.setdefault(t, []).append({
                    'id': int(props.get('@id', 0)),
                    'lat': round(lat, 7),
                    'lon': round(lon, 7),
                    'tags': {k: v for k, v in tags.items() if k in TAG_WHITELIST},
                })
                n_hydrants += 1
            elif geom.get('type') == 'LineString':
                kind = classify_way(tags)
                if kind:
                    add_line(barriers, kind, geom['coordinates'], wanted)
                    n_lines += 1

    print(f'{n_hydrants} Hydranten, {n_lines} Barrieren-/Durchgangslinien', file=sys.stderr)
    if n_hydrants < args.min_hydrants:
        print(f'FEHLER: nur {n_hydrants} Hydranten (< {args.min_hydrants}) – Abbruch', file=sys.stderr)
        sys.exit(1)

    hdir = os.path.join(args.out, 'hydrants')
    bdir = os.path.join(args.out, 'barriers')
    os.makedirs(hdir, exist_ok=True)
    os.makedirs(bdir, exist_ok=True)

    sep = (',', ':')
    for (x, y) in wanted:
        els = sorted(hydrants.get((x, y), []), key=lambda e: e['id'])
        with open(os.path.join(hdir, f'{tile_key(x, y)}.json'), 'w', encoding='utf-8') as f:
            json.dump({'v': version, 'elements': els}, f, ensure_ascii=False, separators=sep)
        b = barriers.get((x, y), {'w': [], 'r': [], 'p': []})
        with open(os.path.join(bdir, f'{tile_key(x, y)}.json'), 'w', encoding='utf-8') as f:
            json.dump({'v': version, **b}, f, separators=sep)

    manifest = {
        'v': version,
        'generated': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(version)),
        'osm_timestamp': args.osm_timestamp,
        'tile_deg': TILE_DEG,
        'tiles': len(wanted),
        'hydrants': n_hydrants,
        'rows': rows_from_tiles(wanted),
    }
    with open(os.path.join(args.out, 'manifest.json'), 'w', encoding='utf-8') as f:
        json.dump(manifest, f, separators=sep)
    print(f'Fertig: {len(wanted)} Kacheln nach {args.out} geschrieben (v={version})', file=sys.stderr)


if __name__ == '__main__':
    main()
