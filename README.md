# 🚒 Feuerwehr-Hydranten — Hydrantennavigator

> A mobile-first web app that helps fire brigades find the **nearest fire hydrant**, calculate the **number of hose sections** needed to reach a fire, and manage operational data such as water-transport plans, power-supply infrastructure and sensitive objects.

> ⚠️ **Status: In active development.** Features, database schema and APIs may still change. Not yet recommended for production use without review.

---

## ✨ Features

- **🗺️ Live hydrant map** — Displays nearby fire hydrants on a full-screen [Leaflet](https://leafletjs.com/) map, sourced live from OpenStreetMap via the Overpass API.
- **📍 Two search modes** — Search from your **own GPS location** or from a manually **set fire position**.
- **📏 Hose calculator** — Computes how many hose sections are required to reach the nearest hydrant, using real **road routing** (OSRM) and falling back to straight-line (haversine) distance when routing is unavailable.
- **⚙️ Configurable** — Adjustable hose length (e.g. 20 m / 25 m) and search radius via a swipe-up bottom sheet.
- **📱 Mobile / PWA-friendly** — Optimised for use on a phone in the field; installable to the home screen.
- **🍏 Apple Shortcut & Siri integration** — A REST endpoint returns a ready-to-speak summary so you can ask Siri for the number of hoses hands-free (see [`SHORTCUT.md`](SHORTCUT.md)).
- **⚡ Power-supply map** — Separate view for transformer stations and substations, useful for coordinating with the grid operator during an incident.
- **🔒 Admin panel** (per fire department, password-protected):
  - **Sensitive entries** — Notes for keys/access, hazardous materials, building info and contacts, pinned to the map.
  - **Water-transport plans (WasserTransportPläne)** — Manage plans, notes and uploaded files.
  - **Building ↔ transformer-station mapping.**
  - **Magic links** — Generate device-specific login links (e.g. for a vehicle tablet).

---

## 🧱 Tech stack

| Layer | Technology |
|---|---|
| Frontend | Vanilla JavaScript, HTML, CSS, [Leaflet](https://leafletjs.com/) |
| Backend | PHP (designed for [Hostpoint](https://www.hostpoint.ch/) shared hosting — no persistent process required) |
| Database | MariaDB / MySQL |
| Geodata | OpenStreetMap **Overpass API** (hydrants), **OSRM** (routing) |
| Local dev | Docker Compose (Apache + PHP + MariaDB) |
| Testing | Playwright |

---

## 📂 Project structure

```
.
├── index.html            # Main hydrant navigator (SPA entry)
├── app.js                # Map, geolocation, hydrant fetch & hose logic
├── stromversorgung.html  # Power-supply (transformer/substation) map
├── stromversorgung.js
├── info.html             # In-app help / instructions
├── login.php             # Auth pages
├── style.css
├── api/                  # Public API (hoses calculator, Overpass proxy)
├── php/                  # Backend: API + admin API + shared includes (db, auth)
├── admin/                # Admin panel (UI + APIs)
├── config/               # SQL schemas, migrations, helpers (db.php is gitignored)
├── docker/               # Dockerfile, Apache config, DB init
├── docker-compose.yml    # Local development stack
├── .htaccess             # Apache routing / SPA fallback
└── SHORTCUT.md           # Apple Shortcut setup guide
```

---

## 🚀 Getting started (local development)

Requires [Docker](https://www.docker.com/) with Compose.

```bash
git clone https://github.com/julianbruegger/feuerwehr-hydranten.git
cd feuerwehr-hydranten
docker compose up --build
```

Then open **http://localhost:8080**.

The stack starts:
- **web** — Apache + PHP serving the app
- **db** — MariaDB 10.11 (database `hydranten`, initialised from `docker/init.sql`)

> **Note:** `config/db.php` holds database credentials and is intentionally **not** committed (`.gitignore`). For local Docker the connection is provided via the `DB_*` environment variables in `docker-compose.yml`.

---

## 🌐 Deployment (Hostpoint)

The backend is written for PHP shared hosting and needs no server management:

1. Upload the project files to your webspace.
2. Create the database and run the SQL in [`config/`](config/) (via phpMyAdmin):
   - `schema.sql`, then any relevant `schema_*.sql` and `migration_*.sql`.
3. Create `config/db.php` with your database credentials.
4. Ensure `cURL` is enabled (default on Hostpoint) — it's used for Overpass/OSRM requests.

The public hose API is then reachable at:

```
https://<your-domain>/api/hoses?lat=47.04&lng=8.30
```

---

## 🔌 API reference

### `GET /api/hoses`

Returns the number of hose sections to the nearest fire hydrant.

| Parameter | Required | Default | Description |
|---|---|---|---|
| `lat` | ✅ | — | Latitude (WGS84) |
| `lng` | ✅ | — | Longitude (WGS84) |
| `hose_length` | ❌ | `20` | Length per hose section in metres |
| `radius` | ❌ | `1500` | Hydrant search radius in metres |

**Example**

```
GET /api/hoses?lat=47.0409&lng=8.3005&hose_length=20
```

```json
{
  "hoses": 5,
  "hose_length_m": 20,
  "distance_m": 420,
  "distance_type": "road",
  "hydrant": { "lat": 47.039, "lng": 8.301, "type": "underground", "air_distance_m": 310 },
  "summary": "5 Schläuche à 20 m (420 m Fahrstrecke)"
}
```

`distance_type` is `"road"` when OSRM routing succeeded, or `"air"` when only straight-line distance was available.

📄 For the full Apple Shortcut / Siri walkthrough, see [`SHORTCUT.md`](SHORTCUT.md).

---

## 🗺️ Roadmap / status

This project is **still in development**. Planned and in-progress work includes hardening the admin/auth flow, refining the water-transport and power-supply modules, and improving offline resilience. Feedback and issues are welcome.

---

## 📜 License & disclaimer

Hydrant and infrastructure data comes from [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors. Distances and hose counts are **estimates** based on available map data and routing — always verify on-site. Not an official emergency-response tool.
