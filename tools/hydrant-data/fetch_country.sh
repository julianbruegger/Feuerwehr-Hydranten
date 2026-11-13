#!/usr/bin/env bash
# Lädt den Geofabrik-Extrakt eines Landes, filtert ihn auf die relevanten OSM-Objekte
# und löscht den Roh-Extrakt wieder (spart Platz auf dem Runner).
#
#   tools/hydrant-data/fetch_country.sh <geofabrik-path> <id> <out-dir> <min-hydrants>
#   z.B.: fetch_country.sh europe/germany germany build/countries 50000
#
# Ergebnis: <out-dir>/<id>.osm.pbf (gefiltert) und <out-dir>/<id>.poly
set -euo pipefail

GPATH="$1"; ID="$2"; OUT="$3"; MIN="${4:-0}"
BASE="https://download.geofabrik.de/${GPATH}"
mkdir -p "$OUT"
RAW="$OUT/$ID.raw.osm.pbf"

curl -fsSL --retry 3 -o "$RAW" "${BASE}-latest.osm.pbf"
curl -fsSL --retry 3 -o "$OUT/$ID.poly" "${BASE}.poly"

osmium tags-filter "$RAW" \
  n/emergency=fire_hydrant \
  w/waterway=river,stream,canal \
  w/railway=rail,tram,subway,light_rail,narrow_gauge \
  w/bridge=yes w/tunnel=yes \
  -o "$OUT/$ID.osm.pbf" --overwrite
# Zeitstempel des Roh-Extrakts in den gefilterten Extrakt retten
TS="$(osmium fileinfo -g header.option.osmosis_replication_timestamp "$RAW" 2>/dev/null || true)"
rm -f "$RAW"

# Plausibilitätsprüfung pro Land: kaputte/leere Extrakte nicht weiterverarbeiten
osmium tags-filter "$OUT/$ID.osm.pbf" n/emergency=fire_hydrant -o "$OUT/$ID.count.osm.pbf" --overwrite
N="$(osmium fileinfo -e -g data.count.nodes "$OUT/$ID.count.osm.pbf")"
rm -f "$OUT/$ID.count.osm.pbf"
echo "$ID: $N Hydranten (Minimum $MIN), Stand ${TS:-unbekannt}"
if [ "$N" -lt "$MIN" ]; then
  echo "FEHLER: $ID hat nur $N Hydranten (< $MIN) – Abbruch" >&2
  exit 1
fi
