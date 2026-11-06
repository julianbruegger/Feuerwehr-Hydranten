#!/usr/bin/env bash
# Erzeugt statische Hydranten-/Barrieren-Kacheln aus einem OSM-PBF-Extrakt.
#
#   tools/hydrant-data/build.sh <extract.osm.pbf> <extract.poly> <out-dir> [min-hydrants]
#
# Benötigt: osmium-tool, python3 mit shapely.
# Wird wöchentlich von .github/workflows/hydrant-data.yml ausgeführt.
set -euo pipefail

PBF="$1"
POLY="$2"
OUT="$3"
MIN_HYDRANTS="${4:-0}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Nur Hydranten + Gewässer/Bahn/Brücken/Tunnel behalten (wie die Overpass-Abfragen)
osmium tags-filter "$PBF" \
  n/emergency=fire_hydrant \
  w/waterway=river,stream,canal \
  w/railway=rail,tram,subway,light_rail,narrow_gauge \
  w/bridge=yes w/tunnel=yes \
  -o "$WORK/filtered.osm.pbf" --overwrite

osmium export "$WORK/filtered.osm.pbf" \
  -f geojsonseq -a type,id \
  --geometry-types=point,linestring \
  -o "$WORK/filtered.geojsonseq" --overwrite

TS="$(osmium fileinfo -g header.option.osmosis_replication_timestamp "$PBF" 2>/dev/null || true)"

# Nur eigene Ausgaben ersetzen (z.B. data/.htaccess bleibt erhalten)
mkdir -p "$OUT"
rm -rf "$OUT/hydrants" "$OUT/barriers" "$OUT/manifest.json"
python3 "$HERE/build_tiles.py" "$WORK/filtered.geojsonseq" "$POLY" "$OUT" \
  --osm-timestamp "$TS" --min-hydrants "$MIN_HYDRANTS"
