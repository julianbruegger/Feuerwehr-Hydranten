#!/usr/bin/env python3
"""
Hydrantennavigator – unified server
  - Serves static files (app, index.html, …)
  - GET /api/hoses?lat=47.04&lng=8.30[&hose_length=20&radius=1000]
    Returns JSON with the number of hose sections needed to reach
    the nearest drivable fire hydrant. Designed for Apple Shortcuts.
"""

import http.server
import json
import math
import os
import urllib.parse
import urllib.request

PORT            = 8080
HOSE_LEN_M      = 20     # default hose section length in metres
SEARCH_RADIUS_M = 1500   # Overpass search radius
OSRM_BASE       = "https://router.project-osrm.org"
OVERPASS_URLS   = [
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
]


# ── helpers ──────────────────────────────────────────────────────────────────

def haversine(lat1, lng1, lat2, lng2):
    R = 6_371_000
    d = math.radians
    a = math.sin(d(lat2 - lat1) / 2) ** 2 + \
        math.cos(d(lat1)) * math.cos(d(lat2)) * \
        math.sin(d(lng2 - lng1) / 2) ** 2
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def overpass_fetch(query):
    payload = urllib.parse.urlencode({"data": query}).encode()
    for url in OVERPASS_URLS:
        try:
            req = urllib.request.Request(url, data=payload, method="POST")
            with urllib.request.urlopen(req, timeout=18) as r:
                if r.status == 200:
                    return json.loads(r.read())
        except Exception:
            continue
    raise RuntimeError("Alle Overpass-Endpunkte nicht erreichbar")


def nearest_hydrants(lat, lng, radius):
    query = f"""
[out:json][timeout:15];
node["emergency"="fire_hydrant"](around:{radius},{lat},{lng});
out body;
"""
    data = overpass_fetch(query)
    elements = data.get("elements", [])
    for el in elements:
        el["_dist"] = haversine(lat, lng, el["lat"], el["lon"])
    elements.sort(key=lambda e: e["_dist"])
    return elements


def osrm_route_distance(from_lat, from_lng, to_lat, to_lng):
    url = (f"{OSRM_BASE}/route/v1/driving/"
           f"{from_lng},{from_lat};{to_lng},{to_lat}?overview=false")
    try:
        with urllib.request.urlopen(url, timeout=10) as r:
            d = json.loads(r.read())
        if d.get("code") == "Ok" and d.get("routes"):
            return d["routes"][0]["distance"]
    except Exception:
        pass
    return None


# ── request handler ───────────────────────────────────────────────────────────

class Handler(http.server.SimpleHTTPRequestHandler):

    def do_GET(self):
        if urllib.parse.urlparse(self.path).path == "/api/hoses":
            self._api_hoses()
        else:
            super().do_GET()

    def _api_hoses(self):
        qs = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)
        try:
            lat        = float(qs["lat"][0])
            lng        = float(qs["lng"][0])
            hose_len   = float(qs.get("hose_length", [HOSE_LEN_M])[0])
            radius     = int(qs.get("radius", [SEARCH_RADIUS_M])[0])
        except (KeyError, ValueError, IndexError):
            return self._json({"error": "lat und lng sind Pflichtparameter"}, 400)

        try:
            hydrants = nearest_hydrants(lat, lng, radius)
        except Exception as e:
            return self._json({"error": str(e)}, 502)

        if not hydrants:
            return self._json({
                "hoses": None,
                "error": f"Keine Hydranten im Umkreis von {radius} m gefunden"
            })

        best = hydrants[0]
        route_m = osrm_route_distance(lat, lng, best["lat"], best["lon"])
        dist_m  = route_m if route_m else best["_dist"]
        hoses   = math.ceil(dist_m / hose_len)

        tags = best.get("tags", {})
        h_type = tags.get("fire_hydrant:type") or tags.get("fire_hydrant", "")

        self._json({
            "hoses":           hoses,
            "hose_length_m":   hose_len,
            "distance_m":      round(dist_m),
            "distance_type":   "road" if route_m else "air",
            "hydrant": {
                "lat":            best["lat"],
                "lng":            best["lon"],
                "type":           h_type or "unknown",
                "air_distance_m": round(best["_dist"]),
            },
            "summary": f"{hoses} Schläuche à {int(hose_len)} m ({round(dist_m)} m Fahrstrecke)"
        })

    def _json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False, indent=2).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        print(f"  {self.address_string()}  {fmt % args}")


# ── main ──────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    os.chdir(os.path.dirname(os.path.abspath(__file__)) or ".")
    with http.server.ThreadingHTTPServer(("", PORT), Handler) as srv:
        import socket
        local_ip = socket.gethostbyname(socket.gethostname())
        print(f"\n  Hydrantennavigator läuft")
        print(f"  Lokal:   http://localhost:{PORT}")
        print(f"  Netzwerk: http://{local_ip}:{PORT}")
        print(f"\n  Apple Shortcut API:")
        print(f"  http://{local_ip}:{PORT}/api/hoses?lat=LAT&lng=LNG")
        print(f"  Optionale Parameter: &hose_length=20 &radius=1500\n")
        srv.serve_forever()
