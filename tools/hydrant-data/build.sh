#!/usr/bin/env bash
# Erzeugt statische Hydranten-/Barrieren-Kacheln aus einem oder mehreren OSM-PBF-Extrakten.
#
#   tools/hydrant-data/build.sh <extract.osm.pbf[,more.osm.pbf]> <extract.poly[,more.poly]> <out-dir> [min-hydrants]
#
# Mehrere Länder: PBF- und .poly-Dateien kommagetrennt angeben. Die PBFs werden
# einzeln gefiltert (klein) und danach zusammengeführt – so müssen die grossen
# Roh-Extrakte nie gleichzeitig verarbeitet werden.
#
# Benötigt: osmium-tool, python3 mit shapely.
# Wird wöchentlich von .github/workflows/hydrant-data.yml ausgeführt.
set -euo pipefail

PBFS="$1"
POLY="$2"
OUT="$3"
MIN_HYDRANTS="${4:-0}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

IFS=',' read -r -a INPUTS <<< "$PBFS"
FILTERED=()
TS=""
i=0
for PBF in "${INPUTS[@]}"; do
  # Nur Hydranten + Gewässer/Bahn/Brücken/Tunnel behalten (wie die Overpass-Abfragen)
  osmium tags-filter "$PBF" \
    n/emergency=fire_hydrant \
    w/waterway=river,stream,canal \
    w/railway=rail,tram,subway,light_rail,narrow_gauge \
    w/bridge=yes w/tunnel=yes \
    -o "$WORK/filtered_$i.osm.pbf" --overwrite
  FILTERED+=("$WORK/filtered_$i.osm.pbf")
  # Ältester Datenstand aller Extrakte (ISO 8601 sortiert lexikografisch)
  T="$(osmium fileinfo -g header.option.osmosis_replication_timestamp "$PBF" 2>/dev/null || true)"
  if [ -n "$T" ] && { [ -z "$TS" ] || [[ "$T" < "$TS" ]]; }; then TS="$T"; fi
  i=$((i + 1))
done

if [ "${#FILTERED[@]}" -gt 1 ]; then
  osmium merge "${FILTERED[@]}" -o "$WORK/filtered.osm.pbf" --overwrite
else
  mv "${FILTERED[0]}" "$WORK/filtered.osm.pbf"
fi

osmium export "$WORK/filtered.osm.pbf" \
  -f geojsonseq -a type,id \
  --geometry-types=point,linestring \
  -o "$WORK/filtered.geojsonseq" --overwrite

# Nur eigene Ausgaben ersetzen (z.B. data/.htaccess bleibt erhalten)
mkdir -p "$OUT"
rm -rf "$OUT/hydrants" "$OUT/barriers" "$OUT/manifest.json"
python3 "$HERE/build_tiles.py" "$WORK/filtered.geojsonseq" "$POLY" "$OUT" \
  --osm-timestamp "$TS" --min-hydrants "$MIN_HYDRANTS"
