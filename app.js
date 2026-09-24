/**
 * Hydrantennavigator – app.js
 *
 * Zuständigkeiten:
 *  - Karteninitialisierung (Leaflet)
 *  - Standortermittlung (Geolocation API)
 *  - Hydrantenabruf (OpenStreetMap Overpass API)
 *  - Sortierung nach Entfernung & Schlauchberechnung
 *  - UI-Updates: Bottom-Sheet, Marker, Karte
 */

'use strict';

// ─────────────────────────────────────────
// Konstanten
// ─────────────────────────────────────────

/** Standardwerte */
const DEFAULTS = {
    HOSE_LENGTH_M: 20,          // Meter pro Schlauch
    SEARCH_RADIUS_M: 2000,      // Suchradius in Metern (groß genug für Routen-Umwege)
    MAX_RESULTS: 15,            // Maximale Anzahl angezeigter Hydranten
    MAX_OSRM_CANDIDATES: 50,    // Maximale Hydranten-Kandidaten für OSRM-Routing
};

const VIEWPORT_MIN_ZOOM = 13;    // Unterhalb dieses Zooms keine Viewport-Hydranten
const VIEWPORT_MAX_TILES = 48;   // Sicherheitsgrenze für Kacheln pro Viewport-Abruf

async function serverProxyFetch(type, pos, radius) {
    const params = new URLSearchParams({
        type,
        lat: pos.lat.toFixed(6),
        lng: pos.lng.toFixed(6),
        radius: String(radius),
    });
    const res = await fetch(`/api/overpass.php?${params}`);
    if (!res.ok) throw new Error(`Proxy ${res.status}`);
    return await res.json();
}

// ─────────────────────────────────────────
// Hydranten-Kacheln (Speicher + IndexedDB)
// ─────────────────────────────────────────
//
// Hydranten werden in festen Kacheln à TILE_DEG° geladen (identisch zum Server,
// siehe php/includes/hydrant_tiles.php). Dadurch teilen sich Standortsuche,
// Kartenausschnitt und alle Radien denselben Cache. Kacheln werden dauerhaft in
// IndexedDB gespeichert; ältere Kacheln werden sofort angezeigt und im
// Hintergrund aktualisiert (stale-while-revalidate).

const TILE_DEG        = 0.05;                     // ~5.5 × 3.8 km in der Schweiz
const TILE_FRESH_MS   = 7 * 24 * 60 * 60 * 1000;  // danach im Hintergrund aktualisieren
const TILE_MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000; // danach verwerfen
const TILE_BATCH      = 16;                       // Max. Kacheln pro Server-Anfrage

const _tileMem      = new Map(); // key → { ts, elements }
const _tileInflight = new Map(); // key → Promise<{ ts, elements } | null>
let _tileDbPromise  = null;

function tileKeyFor(lat, lng) {
    return `${Math.floor(lng / TILE_DEG)}_${Math.floor(lat / TILE_DEG)}`;
}

function tileKeysForBounds(s, w, n, e) {
    const keys = [];
    for (let y = Math.floor(s / TILE_DEG); y <= Math.floor(n / TILE_DEG); y++) {
        for (let x = Math.floor(w / TILE_DEG); x <= Math.floor(e / TILE_DEG); x++) {
            keys.push(`${x}_${y}`);
        }
    }
    return keys;
}

function tileKeysForRadius(pos, radiusM) {
    const dLat = radiusM / 111320;
    const dLng = radiusM / (111320 * Math.max(0.01, Math.cos(toRad(pos.lat))));
    return tileKeysForBounds(pos.lat - dLat, pos.lng - dLng, pos.lat + dLat, pos.lng + dLng);
}

function _tileDb() {
    if (!_tileDbPromise) {
        _tileDbPromise = new Promise((resolve) => {
            try {
                const req = indexedDB.open('hw-hydrants', 1);
                req.onupgradeneeded = () => req.result.createObjectStore('tiles');
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
                req.onblocked = () => resolve(null);
            } catch { resolve(null); }
        });
    }
    return _tileDbPromise;
}

async function _tileDbGetMany(keys) {
    const db = await _tileDb();
    const out = new Map();
    if (!db || keys.length === 0) return out;
    return new Promise((resolve) => {
        try {
            const store = db.transaction('tiles', 'readonly').objectStore('tiles');
            let pending = keys.length;
            keys.forEach((key) => {
                const req = store.get(key);
                req.onsuccess = () => {
                    if (req.result) out.set(key, req.result);
                    if (--pending === 0) resolve(out);
                };
                req.onerror = () => { if (--pending === 0) resolve(out); };
            });
        } catch { resolve(out); }
    });
}

async function _tileDbPut(entries) {
    const db = await _tileDb();
    if (!db) return;
    try {
        const store = db.transaction('tiles', 'readwrite').objectStore('tiles');
        entries.forEach(([key, tile]) => store.put(tile, key));
    } catch { /* Speicher voll oder deaktiviert – ignorieren */ }
}

/** Lädt Kacheln vom Server (in Batches) und legt sie in Speicher + IndexedDB ab. */
function _fetchTilesFromServer(keys, { reload = false } = {}) {
    const promises = [];
    for (let i = 0; i < keys.length; i += TILE_BATCH) {
        const batch = keys.slice(i, i + TILE_BATCH);
        const p = fetch(`/api/hydrants.php?tiles=${batch.join(',')}`, reload ? { cache: 'reload' } : {})
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => {
                const tiles = data.tiles || {};
                const fetchedAt = Date.now();
                const entries = Object.entries(tiles).map(([key, t]) => {
                    // Frische ab Abrufzeit zählen (der Server hat eine eigene, längere Frist)
                    const tile = { ts: fetchedAt, elements: t.elements || [] };
                    _tileMem.set(key, tile);
                    return [key, tile];
                });
                _tileDbPut(entries);
                return tiles;
            });
        batch.forEach((key) => {
            const kp = p.then(() => _tileMem.get(key) || null).catch(() => null);
            _tileInflight.set(key, kp);
            kp.finally(() => { if (_tileInflight.get(key) === kp) _tileInflight.delete(key); });
            promises.push(kp);
        });
    }
    return promises;
}

/**
 * Liefert Hydranten-Kacheln. `onTile(key, elements)` wird aufgerufen, sobald
 * eine Kachel verfügbar ist (sofort aus dem Cache, später vom Server), damit
 * die Karte schrittweise rendern kann.
 * Rückgabe: { tiles: Map<key, elements>, fromCache: boolean, failed: string[] }
 */
async function getHydrantTiles(keys, { onTile = null, force = false } = {}) {
    const tiles = new Map();
    const deliver = (key, tile) => {
        tiles.set(key, tile.elements);
        if (onTile) onTile(key, tile.elements);
    };
    const now = Date.now();
    const toLoad = [];
    const toRevalidate = [];

    // 1. Speicher
    const needDb = [];
    keys.forEach((key) => {
        const t = _tileMem.get(key);
        if (t && !force) {
            deliver(key, t);
            if (now - t.ts > TILE_FRESH_MS) toRevalidate.push(key);
        } else {
            needDb.push(key);
        }
    });

    // 2. IndexedDB
    if (needDb.length && !force) {
        const fromDb = await _tileDbGetMany(needDb);
        needDb.forEach((key) => {
            const t = fromDb.get(key);
            if (t && now - t.ts < TILE_MAX_AGE_MS) {
                _tileMem.set(key, t);
                deliver(key, t);
                if (now - t.ts > TILE_FRESH_MS) toRevalidate.push(key);
            } else {
                toLoad.push(key);
            }
        });
    } else {
        toLoad.push(...needDb);
    }

    const fromCache = toLoad.length === 0;

    // 3. Server (laufende Anfragen für dieselbe Kachel wiederverwenden)
    const waits = [];
    const fresh = [];
    toLoad.forEach((key) => {
        const inflight = !force && _tileInflight.get(key);
        if (inflight) waits.push(inflight.then((t) => [key, t]));
        else fresh.push(key);
    });
    _fetchTilesFromServer(fresh, { reload: force }).forEach((p, i) => {
        waits.push(p.then((t) => [fresh[i], t]));
    });
    const failed = [];
    await Promise.all(waits.map((p) => p.then(([key, t]) => {
        if (t) deliver(key, t); else failed.push(key);
    })));

    // 4. Veraltete Kacheln im Hintergrund aktualisieren
    const revalidate = toRevalidate.filter((key) => !_tileInflight.has(key));
    if (revalidate.length) {
        _fetchTilesFromServer(revalidate).forEach((p, i) => {
            p.then((t) => { if (t && onTile) onTile(revalidate[i], t.elements); });
        });
    }

    return { tiles, fromCache, failed };
}

// Alte localStorage-Hydranten-Caches (vor dem Kachel-Cache) entfernen
try {
    Object.keys(localStorage)
        .filter((key) => key.startsWith('hw_hydrants_'))
        .forEach((key) => localStorage.removeItem(key));
} catch { /* ignorieren */ }

// ─────────────────────────────────────────
// App-Zustand
// ─────────────────────────────────────────

const state = {
    map: null,         // Leaflet Map Instanz
    userPos: null,         // { lat, lng } – GPS-Standort
    firePos: null,         // { lat, lng } – Manuell gesetzte Brandposition (oder null)
    hasTruePosition: false, // true sobald echter GPS-Fix vorliegt (nicht nur Fallback)
    hydrants: [],           // Array von Hydrant-Objekten (mit Distanz)
    barrierSegments: [],   // Liniensegmente von Gewässern & Bahnlinien
    passwaySegments: [],   // Liniensegmente von Brücken & Tunneln
    markers: {
        user: null,         // Leaflet Marker für Benutzer
        fire: null,         // Leaflet Marker für Brandposition
        accuracy: null,         // Genauigkeitskreis
        hydrants: [],           // Leaflet Marker für Hydranten (Top-Ergebnisse)
        allHydrants: [],        // Leaflet Marker für alle Hydranten (Overlay)
        viewportHydrants: [],   // Leaflet Marker für Viewport-geladene Hydranten
        route: null,            // Leaflet Polyline für Route zum Hydranten
    },
    showAllHydrants: false,
    viewportHydrantIds: new Set(), // OSM-Node-IDs bereits als Viewport-Marker geladen
    viewportTiles: new Map(),      // Kachel-Key → { layer, ids, elements, useIcons }
    viewportWantedTiles: new Set(), // Kacheln im aktuellen (erweiterten) Kartenausschnitt
    fireMode: false,        // Ist Brandpositions-Modus aktiv?
    selectedHydrantId: null,        // Aktuell ausgewählter Hydrant
    watchId: null,         // GPS-Watch-ID
};

// ─────────────────────────────────────────
// DOM-Referenzen
// ─────────────────────────────────────────

const dom = {
    map: document.getElementById('map'),
    statusBadge: document.getElementById('statusBadge'),
    statusText: document.getElementById('statusText'),
    btnMyLocation: document.getElementById('btnMyLocation'),
    btnSetFire: document.getElementById('btnSetFire'),
    btnToggleAll: document.getElementById('btnToggleAll'),
    btnBasemap: document.getElementById('btnBasemap'),
    btnRefresh: document.getElementById('btnRefresh'),
    btnAdmin: document.getElementById('btnAdmin'),
    modeBanner: document.getElementById('modeBanner'),
    btnCancelFire: document.getElementById('btnCancelFire'),
    locationAlert: document.getElementById('locationAlert'),
    locationAlertText: document.getElementById('locationAlertText'),
    btnLocationRetry: document.getElementById('btnLocationRetry'),
    sheetHandle: document.getElementById('sheetHandle'),
    bottomSheet: document.getElementById('bottomSheet'),
    hoseLength: document.getElementById('hoseLength'),
    searchRadius: document.getElementById('searchRadius'),
    hydrantList: document.getElementById('hydrantList'),
    emptyState: document.getElementById('emptyState'),
};

// ─────────────────────────────────────────
// Karten-Icons
// ─────────────────────────────────────────

/** Erstellt ein Hydrant-Icon, optional mit Barriere-Warnung */
function createHydrantIcon(rank, isNearest, barrierTypes) {
    const hasBarrier = barrierTypes && barrierTypes.size > 0;
    const color = isNearest ? '#e63946' : '#8a8a9a';
    const borderColor = hasBarrier ? '#f4a261' : color;
    const scale = isNearest ? 1.15 : 1;
    const size = Math.round(34 * scale);

    // Kleines Barriere-Badge oben rechts
    const barrierBadge = hasBarrier ? `<div style="
      position:absolute; top:-4px; right:-4px;
      width:16px; height:16px;
      background:#f4a261;
      border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-size:9px; line-height:1;
      box-shadow:0 1px 4px rgba(0,0,0,0.5);
    ">${barrierTypes.has('water') && barrierTypes.has('rail') ? '⚠' : barrierTypes.has('water') ? '🌊' : '🚂'}</div>` : '';

    return L.divIcon({
        className: '',
        html: `<div style="position:relative; width:${size}px; height:${size}px;">
      <div style="
        width:${size}px; height:${size}px;
        background:${isNearest ? 'rgba(230,57,70,0.15)' : 'rgba(24,24,31,0.9)'};
        border:2.5px solid ${borderColor};
        border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        font-size:${isNearest ? '14' : '12'}px;
        font-weight:700;
        color:${borderColor};
        font-family:Inter,sans-serif;
        box-shadow:0 2px 10px rgba(0,0,0,0.5)${isNearest ? ',0 0 12px rgba(230,57,70,0.4)' : ''};
        backdrop-filter:blur(8px);
      ">${rank}</div>
      ${barrierBadge}
    </div>`,
        iconSize: [size, size],
        iconAnchor: [Math.round(17 * scale), Math.round(17 * scale)],
        popupAnchor: [0, -Math.round(20 * scale)],
    });
}

/** Benutzerstandort-Icon */
const USER_ICON = L.divIcon({
    className: '',
    html: `<div style="
    width:20px; height:20px;
    background:#4ade80;
    border:3px solid #fff;
    border-radius:50%;
    box-shadow:0 0 0 3px rgba(74,222,128,0.3), 0 2px 8px rgba(0,0,0,0.5);
  "></div>`,
    iconSize: [20, 20],
    iconAnchor: [10, 10],
});

/** Brandpositions-Icon */
const FIRE_ICON = L.divIcon({
    className: '',
    html: `<div style="
    width:30px; height:30px;
    background:rgba(230,57,70,0.15);
    border:2.5px solid #e63946;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    box-shadow:0 0 14px rgba(230,57,70,0.5);
  ">🔥</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
});

// ─────────────────────────────────────────
// Karteninitialisierung
// ─────────────────────────────────────────

function initMap() {
    state.map = L.map('map', {
        zoomControl: false,
        attributionControl: true,
    }).setView([47.0409, 8.3005], 15); // Neubad Luzern

    state.layers = {
        osm: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
            maxZoom: 19,
        }),
        swisstopo: L.tileLayer('https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg', {
            attribution: '© <a href="https://www.swisstopo.admin.ch">swisstopo</a>',
            maxNativeZoom: 18,
            maxZoom: 19,
            crossOrigin: true,
        }),
        satellite: L.tileLayer('https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg', {
            attribution: '© <a href="https://www.swisstopo.admin.ch">swisstopo Luftbild</a>',
            maxNativeZoom: 19,
            maxZoom: 21,
            crossOrigin: true,
        }),
    };
    state.currentBasemap = 'osm';
    state.layers.osm.addTo(state.map);
    ['swisstopo', 'satellite'].forEach(name => {
        state.layers[name].on('tileerror', (e) => {
            console.warn(`[Swisstopo/${name}] Kachel fehlgeschlagen:`, e.tile.src);
        });
    });

    // Zoom-Steuerung oben links (verhindert Überschneidung mit FABs rechts)
    L.control.zoom({ position: 'topleft' }).addTo(state.map);

    // Kartenklick → Brandposition setzen (wenn Modus aktiv)
    state.map.on('click', onMapClick);

    // Viewport-Hydranten: beim Verschieben/Zoomen nachladen
    state.map.on('moveend', onMapMoveEnd);
    // Sofort Hydranten der Startansicht zeigen (nicht erst nach GPS-Fix)
    loadViewportHydrants();

}

// ─────────────────────────────────────────
// Geolocation
// ─────────────────────────────────────────

function startLocationWatch() {
    if (!navigator.geolocation) {
        setStatus('GPS nicht verfügbar', 'error');
        return;
    }

    setStatus('Standort ermitteln…', 'loading');

    // Einmaliger Abruf für sofortigen Start
    navigator.geolocation.getCurrentPosition(onPositionUpdate, onPositionError, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 5000,
    });

    // Kontinuierliche Überwachung für Updates
    state.watchId = navigator.geolocation.watchPosition(onPositionUpdate, onPositionError, {
        enableHighAccuracy: true,
        timeout: 20000,
        maximumAge: 10000,
    });
}

function onPositionUpdate(position) {
    const { latitude, longitude, accuracy } = position.coords;
    const newPos = { lat: latitude, lng: longitude };
    // Erster echter Fix oder Übergang vom Fallback zur echten Position
    const isFirstFix = !state.hasTruePosition;

    state.userPos = newPos;
    state.hasTruePosition = true;
    hideLocationAlert();

    updateUserMarker(newPos, accuracy);

    if (isFirstFix) {
        state.map.setView([newPos.lat, newPos.lng], 16);
        fetchHydrants();
    }

    setStatus('Bereit', 'ready');
}

function onPositionError(error) {
    const messages = {
        1: 'Standortzugriff verweigert',
        2: 'Standort nicht verfügbar',
        3: 'GPS-Zeitüberschreitung',
    };
    setStatus(messages[error.code] || 'GPS-Fehler', 'error');
    showLocationAlert(error.code);

    // Fallback: Hydranten an der aktuellen Kartenansicht laden,
    // damit die App ohne Standort nutzbar bleibt
    if (!state.userPos && !state.firePos) {
        const center = state.map.getCenter();
        state.userPos = { lat: center.lat, lng: center.lng };
        fetchHydrants();
    }
}

// ─────────────────────────────────────────
// Standort-Alert
// ─────────────────────────────────────────

function showLocationAlert(errorCode) {
    dom.btnMyLocation.classList.add('fab--needs-location');

    if (errorCode === 1) {
        // Zugriff verweigert – Nutzer muss in Browser-Einstellungen manuell freigeben
        dom.locationAlertText.textContent = '📍 Standortzugriff verweigert – bitte in den Browser-Einstellungen aktivieren';
        dom.btnLocationRetry.textContent = 'Erneut versuchen';
    } else {
        dom.locationAlertText.textContent = '📍 Standort konnte nicht ermittelt werden';
        dom.btnLocationRetry.textContent = 'Erneut versuchen';
    }
    dom.locationAlert.hidden = false;
}

function hideLocationAlert() {
    dom.locationAlert.hidden = true;
    dom.btnMyLocation.classList.remove('fab--needs-location');
}

function retryLocation() {
    hideLocationAlert();
    setStatus('Standort ermitteln…', 'loading');
    // maximumAge: 0 erzwingt einen frischen Fix (kein Cache) und löst
    // auf Android erneut den Berechtigungs-Dialog aus, falls er auf "einmalig" stand
    navigator.geolocation.getCurrentPosition(onPositionUpdate, onPositionError, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 0,
    });
}

function updateUserMarker(pos, accuracy) {
    // Genauigkeitskreis
    if (state.markers.accuracy) {
        state.markers.accuracy.setLatLng([pos.lat, pos.lng]).setRadius(accuracy);
    } else {
        state.markers.accuracy = L.circle([pos.lat, pos.lng], {
            radius: accuracy,
            className: 'accuracy-circle',
        }).addTo(state.map);
    }

    // Benutzermarker
    if (state.markers.user) {
        state.markers.user.setLatLng([pos.lat, pos.lng]);
    } else {
        state.markers.user = L.marker([pos.lat, pos.lng], {
            icon: USER_ICON,
            zIndexOffset: 1000,
        }).addTo(state.map).bindTooltip('Mein Standort', { direction: 'top' });
    }
}

// ─────────────────────────────────────────
// Brandpositions-Modus
// ─────────────────────────────────────────

function enterFireMode() {
    state.fireMode = true;
    dom.modeBanner.hidden = false;
    dom.btnSetFire.classList.add('active');
    dom.bottomSheet.classList.remove('expanded');
    setStatus('Brandposition setzen', 'fire');
}

function exitFireMode() {
    state.fireMode = false;
    dom.modeBanner.hidden = true;
    dom.btnSetFire.classList.remove('active');
    if (state.firePos) {
        setStatus('Brandposition gesetzt', 'fire');
    } else {
        setStatus('Bereit', 'ready');
    }
}

function clearFirePosition() {
    state.firePos = null;
    if (state.markers.fire) {
        state.map.removeLayer(state.markers.fire);
        state.markers.fire = null;
    }
}

function onMapClick(e) {
    if (!state.fireMode) return;

    const pos = { lat: e.latlng.lat, lng: e.latlng.lng };
    state.firePos = pos;

    // Marker setzen oder verschieben
    if (state.markers.fire) {
        state.markers.fire.setLatLng([pos.lat, pos.lng]);
    } else {
        state.markers.fire = L.marker([pos.lat, pos.lng], { icon: FIRE_ICON, zIndexOffset: 900 })
            .addTo(state.map)
            .bindTooltip('Brandposition', { direction: 'top' });
    }

    exitFireMode();
    // Hydranten für die neue Brandposition vom Server laden
    fetchHydrants();
}

// ─────────────────────────────────────────
// Overpass API – Hydrantenabruf
// ─────────────────────────────────────────

function getSourcePosition() {
    return state.firePos || state.userPos;
}

let _hydrantRequestId = 0;

async function fetchHydrants({ force = false } = {}) {
    const requestId = ++_hydrantRequestId;
    const pos = getSourcePosition();
    if (!pos) return;

    const radius = parseInt(dom.searchRadius.value, 10) || DEFAULTS.SEARCH_RADIUS_M;

    // Barrieren + Brücken parallel im Hintergrund laden (eigener Radius-Cap)
    fetchBarriers(pos, Math.min(radius, 1500));

    dom.btnRefresh.classList.add('loading');
    setStatus('Hydranten laden…', 'loading');

    try {
        const { tiles, fromCache, failed } = await getHydrantTiles(tileKeysForRadius(pos, radius), { force });
        // Inzwischen neuere Anfrage gestartet (z.B. Brandposition verschoben)?
        if (requestId !== _hydrantRequestId) return;
        if (tiles.size === 0) throw new Error('Server nicht erreichbar');

        const elements = [];
        tiles.forEach((els) => els.forEach((el) => {
            if (haversineDistance(pos, { lat: el.lat, lng: el.lon }) <= radius) elements.push(el);
        }));
        processHydrantData(elements);

        if (failed.length) {
            setStatus('Teilweise geladen', 'error');
        } else if (state.firePos) {
            setStatus('Brandposition gesetzt', 'fire');
        } else {
            setStatus(fromCache ? 'Bereit (Cache)' : 'Bereit', 'ready');
        }
    } catch (error) {
        console.error({ error }, 'Hydranten-Abruf fehlgeschlagen');
        showEmptyState(`Fehler beim Laden: ${error.message}`);
        setStatus('Ladefehler', 'error');
    } finally {
        if (requestId === _hydrantRequestId) dom.btnRefresh.classList.remove('loading');
    }
}

async function fetchBarriers(pos, radius) {
    try {
        const data = await serverProxyFetch('barriers', pos, radius);
        processBarrierData(data.elements || []);
        sortAndRenderHydrants(); // Neu rendern mit Barriere-Infos
    } catch (e) {
        console.warn('Barrieren konnten nicht geladen werden', e);
    }
}

function processHydrantData(elements) {
    state.hydrants = elements.map((el) => ({
        id: el.id,
        lat: el.lat,
        lng: el.lon,
        tags: el.tags || {},
        distM: 0,
        barrierTypes: new Set(),
    }));
    sortAndRenderHydrants();
}

function processBarrierData(elements) {
    state.barrierSegments = [];
    state.passwaySegments = [];
    elements.filter(el => el.type === 'way').forEach(way => {
        const tags = way.tags || {};
        const isPassway = tags.bridge === 'yes' || tags.tunnel === 'yes';
        const isBarrier = tags.waterway || tags.railway;
        if (!isPassway && !isBarrier) return;
        const geom = way.geometry || [];
        for (let i = 0; i < geom.length - 1; i++) {
            const seg = { lat1: geom[i].lat, lng1: geom[i].lon, lat2: geom[i + 1].lat, lng2: geom[i + 1].lon };
            if (isPassway) state.passwaySegments.push(seg);
            else state.barrierSegments.push({ ...seg, type: tags.waterway ? 'water' : 'rail' });
        }
    });
}

// ─────────────────────────────────────────
// Sortierung, Berechnung & Rendering
// ─────────────────────────────────────────

async function sortAndRenderHydrants() {
    const pos = getSourcePosition();
    if (!pos || state.hydrants.length === 0) {
        showEmptyState('Keine Hydranten in der Nähe gefunden.');
        clearHydrantMarkers();
        return;
    }

    const hoseLength = parseFloat(dom.hoseLength.value) || DEFAULTS.HOSE_LENGTH_M;

    // Phase 1: Sofort nach Luftlinie rendern
    state.hydrants.forEach((h) => {
        h.distM = haversineDistance(pos, { lat: h.lat, lng: h.lng });
        h.barrierTypes = getBarrierCrossings(pos, { lat: h.lat, lng: h.lng });
        h.routeDistM = null;
        h.routeDurationS = Infinity;
    });
    state.hydrants.sort((a, b) => a.distM - b.distM);

    clearHydrantMarkers();
    renderHydrantList(state.hydrants.slice(0, DEFAULTS.MAX_RESULTS), hoseLength);
    renderHydrantMarkers(state.hydrants.slice(0, DEFAULTS.MAX_RESULTS), pos, hoseLength);
    if (state.showAllHydrants) renderAllHydrantMarkers();

    // Phase 2: Top-N nach Luftlinie per OSRM Table API routen, dann neu sortieren.
    // Kandidaten-Cap verhindert viele API-Batches bei dichter Bebauung im großen Radius.
    // Die nach Luftlinie nächsten N Hydranten decken in der Praxis auch die straßenmäßig
    // nächsten ab — außer bei starken Barrieren, wo ein größerer Luftlinienradius hilft.
    const candidates = state.hydrants.slice(0, DEFAULTS.MAX_OSRM_CANDIDATES);
    try {
        const coords = [`${pos.lng},${pos.lat}`, ...candidates.map(h => `${h.lng},${h.lat}`)].join(';');
        const res = await fetch(
            `${OSRM_BASE}/table/v1/driving/${coords}?sources=0&annotations=duration,distance`
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.code !== 'Ok') throw new Error(data.message);

        const durations = data.durations[0];
        const distances = data.distances?.[0];
        candidates.forEach((h, i) => {
            h.routeDurationS = durations[i + 1] ?? Infinity;
            h.routeDistM = distances?.[i + 1] ?? null;
        });

        candidates.sort((a, b) => {
            if (!isFinite(a.routeDurationS) && !isFinite(b.routeDurationS)) return a.distM - b.distM;
            if (!isFinite(a.routeDurationS)) return 1;
            if (!isFinite(b.routeDurationS)) return -1;
            return a.routeDurationS - b.routeDurationS;
        });
    } catch (e) {
        console.warn('OSRM table routing fehlgeschlagen, Luftlinie wird beibehalten', e);
    }

    clearHydrantMarkers();
    renderHydrantList(candidates.slice(0, DEFAULTS.MAX_RESULTS), hoseLength);
    renderHydrantMarkers(candidates.slice(0, DEFAULTS.MAX_RESULTS), pos, hoseLength);
    if (state.showAllHydrants) renderAllHydrantMarkers();
}

/** Berechnet den Schnittpunkt zweier Liniensegmente (gibt {lat,lng} oder null zurück) */
function getIntersectionPoint(a1, a2, b1, b2) {
    const dx1 = a2.lng - a1.lng, dy1 = a2.lat - a1.lat;
    const dx2 = b2.lng - b1.lng, dy2 = b2.lat - b1.lat;
    const denom = dx1 * dy2 - dy1 * dx2;
    if (Math.abs(denom) < 1e-12) return null;
    const dx3 = b1.lng - a1.lng, dy3 = b1.lat - a1.lat;
    const t = (dx3 * dy2 - dy3 * dx2) / denom;
    const u = (dx3 * dy1 - dy3 * dx1) / denom;
    if (t > 0.01 && t < 0.99 && u >= 0 && u <= 1) {
        return { lat: a1.lat + t * dy1, lng: a1.lng + t * dx1 };
    }
    return null;
}

/** Nächste Distanz (Meter) von Punkt p zum Liniensegment a→b */
function distPointToSegmentM(p, a, b) {
    const dx = b.lng - a.lng, dy = b.lat - a.lat;
    const lenSq = dx * dx + dy * dy;
    if (lenSq < 1e-18) return haversineDistance(p, a);
    const t = Math.max(0, Math.min(1, ((p.lng - a.lng) * dx + (p.lat - a.lat) * dy) / lenSq));
    return haversineDistance(p, { lat: a.lat + t * dy, lng: a.lng + t * dx });
}

/** Prüft ob am Kreuzungspunkt eine Brücke oder ein Tunnel vorhanden ist (≤ 25 m) */
function crossingIsPassable(point) {
    return state.passwaySegments.some(seg =>
        distPointToSegmentM(point,
            { lat: seg.lat1, lng: seg.lng1 },
            { lat: seg.lat2, lng: seg.lng2 }
        ) <= 25
    );
}

/** Gibt Set mit Barriere-Typen zurück, die den Luftlinienweg kreuzen und keine Brücke/Tunnel haben */
function getBarrierCrossings(from, to) {
    const types = new Set();
    state.barrierSegments.forEach(seg => {
        const crossing = getIntersectionPoint(from, to,
            { lat: seg.lat1, lng: seg.lng1 },
            { lat: seg.lat2, lng: seg.lng2 }
        );
        if (crossing && !crossingIsPassable(crossing)) {
            types.add(seg.type);
        }
    });
    return types;
}

/** Haversine-Formel – Luftlinie in Metern */
function haversineDistance(a, b) {
    const R = 6371000;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const sinDLat = Math.sin(dLat / 2);
    const sinDLng = Math.sin(dLng / 2);
    const c = sinDLat * sinDLat + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * sinDLng * sinDLng;
    return R * 2 * Math.atan2(Math.sqrt(c), Math.sqrt(1 - c));
}

function toRad(deg) { return deg * Math.PI / 180; }

/** Berechnet die Anzahl der benötigten Schläuche. */
function calcHoseSections(distM, hoseLengthM) {
    return Math.ceil(distM / hoseLengthM);
}

function formatDistance(m) {
    return m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`;
}

function getHydrantLabel(tags) {
    // Typ des Hydranten aus OSM-Tags lesen
    const typeMap = {
        underground: 'Unterflurhydrant',
        pillar: 'Überflurhydrant',
        wall: 'Wandhydrant',
        pond: 'Teich',
    };
    return typeMap[tags?.fire_hydrant?.type] || typeMap[tags?.['fire_hydrant:type']] || 'Hydrant';
}

function getHydrantAddress(tags) {
    const parts = [];
    if (tags?.['addr:street']) parts.push(tags['addr:street']);
    if (tags?.['addr:housenumber']) parts.push(tags['addr:housenumber']);
    if (tags?.name) parts.push(tags.name);
    return parts.join(' ') || '';
}

function getOsmImageUrl(tags) {
    if (tags?.image && /^https?:\/\//i.test(tags.image)) return tags.image;
    if (tags?.wikimedia_commons) {
        const file = tags.wikimedia_commons.replace(/^File:/i, '');
        return `https://commons.wikimedia.org/wiki/Special:FilePath/${encodeURIComponent(file)}`;
    }
    return null;
}

// ─────────────────────────────────────────
// Rendering – Bottom-Sheet-Liste
// ─────────────────────────────────────────

function renderHydrantList(hydrants, hoseLength) {
    if (hydrants.length === 0) {
        showEmptyState('Keine Hydranten in der Nähe gefunden.');
        return;
    }

    dom.emptyState.hidden = true;
    dom.hydrantList.hidden = false;
    dom.hydrantList.innerHTML = '';

    hydrants.forEach((h, index) => {
        const rank = index + 1;
        const isNearest = index === 0;
        const effectiveDist = h.routeDistM ?? h.distM;
        const sections = Math.ceil(effectiveDist / hoseLength);
        const label = getHydrantLabel(h.tags);
        const address = getHydrantAddress(h.tags);
        const distLabel = h.routeDistM != null
            ? `🚗 ${formatDistance(h.routeDistM)}`
            : `~ ${formatDistance(h.distM)}`;

        const li = document.createElement('li');
        li.className = `hydrant-item${isNearest ? ' nearest' : ''}`;
        li.dataset.id = h.id;
        li.setAttribute('role', 'button');
        li.setAttribute('tabindex', '0');

        const barrierBadges = h.barrierTypes?.size > 0
            ? [...h.barrierTypes].map(t => `<span class="barrier-badge">${t === 'water' ? '🌊' : '🚂'}</span>`).join('')
            : '';

        const imageUrl = getOsmImageUrl(h.tags);
        const imageHtml = imageUrl
            ? `<figure class="hydrant-photo"><img src="${imageUrl}" alt="" loading="lazy" onerror="this.parentElement.remove()"/></figure>`
            : '';

        li.innerHTML = `
      <div class="hydrant-rank">${rank}</div>
      <div class="hydrant-info">
        <div class="hydrant-name">${label}${barrierBadges}</div>
        <div class="hydrant-address">${address || distLabel + ' entfernt'}</div>
      </div>
      <div class="hydrant-hose">
        <span class="hose-count">${sections}</span>
        <span class="hose-label">Schläuche</span>
        <span class="hose-dist">${distLabel}</span>
      </div>
      ${imageHtml}
    `;

        li.addEventListener('click', () => selectHydrant(h));
        li.addEventListener('keydown', (e) => e.key === 'Enter' && selectHydrant(h));

        dom.hydrantList.appendChild(li);
    });

    // Sheet öffnen wenn es Ergebnisse gibt
    expandSheet();
}

function showEmptyState(message) {
    dom.emptyState.textContent = message;
    dom.emptyState.hidden = false;
    dom.hydrantList.hidden = true;
}

// ─────────────────────────────────────────
// Rendering – Kartenmarker
// ─────────────────────────────────────────

function renderHydrantMarkers(hydrants, sourcePos, hoseLength) {
    hydrants.forEach((h, index) => {
        const isNearest = index === 0;
        const label = getHydrantLabel(h.tags);
        const address = getHydrantAddress(h.tags);

        const barrierWarning = h.barrierTypes?.size > 0
            ? `<span style="color:#f4a261">⚠ Kreuzung: ${[...h.barrierTypes].map(t => t === 'water' ? '🌊 Gewässer' : '🚂 Bahn').join(', ')}</span><br/>`
            : '';
        const effectiveDist = h.routeDistM ?? h.distM;
        const sections = calcHoseSections(effectiveDist, hoseLength);
        const distInfo = h.routeDistM != null
            ? `🚗 Fahrstrecke: <b>${formatDistance(h.routeDistM)}</b>`
            : `📏 Luftlinie: <b>~ ${formatDistance(h.distM)}</b>`;

        const popupImageUrl = getOsmImageUrl(h.tags);
        const popupImageHtml = popupImageUrl
            ? `<img src="${popupImageUrl}" style="width:100%;max-height:110px;object-fit:cover;border-radius:4px;margin-top:5px;display:block" loading="lazy" onerror="this.remove()"/>`
            : '';

        const popupContent = `
      <b>${label}</b><br/>
      ${address ? address + '<br/>' : ''}
      ${distInfo}<br/>
      🧯 Schläuche: <b>${sections}×</b> (à ${hoseLength} m)<br/>
      ${barrierWarning}
      ${popupImageHtml}
    `;

        const marker = L.marker([h.lat, h.lng], {
            icon: createHydrantIcon(index + 1, isNearest, h.barrierTypes),
            zIndexOffset: isNearest ? 500 : 0,
        })
            .addTo(state.map)
            .bindPopup(popupContent, { closeButton: false, maxWidth: 240 });

        marker.on('click', () => selectHydrant(h));
        state.markers.hydrants.push(marker);
    });
}

function clearHydrantMarkers() {
    state.markers.hydrants.forEach((m) => state.map.removeLayer(m));
    state.markers.hydrants = [];
    clearRoute();
}

// ─────────────────────────────────────────
// Alle-Hydranten-Overlay
// ─────────────────────────────────────────

const ALL_HYDRANT_ICON = L.divIcon({
    className: '',
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="26" viewBox="0 0 20 26">
      <!-- cap -->
      <rect x="5" y="0" width="10" height="3" rx="1.5" fill="#e63946"/>
      <!-- neck -->
      <rect x="8" y="3" width="4" height="2" fill="#c1121f"/>
      <!-- body -->
      <rect x="3" y="5" width="14" height="13" rx="3" fill="#e63946"/>
      <!-- middle band -->
      <rect x="3" y="11" width="14" height="2.5" fill="#c1121f"/>
      <!-- left outlet -->
      <rect x="0" y="9" width="3" height="4" rx="1" fill="#c1121f"/>
      <!-- right outlet -->
      <rect x="17" y="9" width="3" height="4" rx="1" fill="#c1121f"/>
      <!-- base -->
      <rect x="5" y="18" width="10" height="3" rx="1" fill="#c1121f"/>
      <rect x="3" y="21" width="14" height="3" rx="1.5" fill="#9d0208"/>
      <!-- bolt highlight -->
      <circle cx="10" cy="8" r="2" fill="#c1121f"/>
      <circle cx="10" cy="8" r="1" fill="#e63946"/>
    </svg>`,
    iconSize: [20, 26],
    iconAnchor: [10, 26],
});

function renderAllHydrantMarkers() {
    clearAllHydrantMarkers();
    const topIds = new Set(state.markers.hydrants.map((_, i) => {
        const el = dom.hydrantList.querySelectorAll('.hydrant-item')[i];
        return el ? el.dataset.id : null;
    }));

    state.hydrants.forEach(h => {
        if (state.selectedHydrantId === h.id) return;
        // Skip hydrants already visible as viewport markers
        if (state.viewportHydrantIds.has(h.id)) return;
        const marker = L.marker([h.lat, h.lng], {
            icon: ALL_HYDRANT_ICON,
            zIndexOffset: -100,
        }).addTo(state.map);
        marker.on('click', () => selectHydrant(h));
        state.markers.allHydrants.push(marker);
    });
}

function clearAllHydrantMarkers() {
    state.markers.allHydrants.forEach(m => state.map.removeLayer(m));
    state.markers.allHydrants = [];
}

function toggleAllHydrants() {
    state.showAllHydrants = !state.showAllHydrants;
    dom.btnToggleAll.classList.toggle('active', state.showAllHydrants);
    if (state.showAllHydrants) {
        renderAllHydrantMarkers();
    } else {
        clearAllHydrantMarkers();
    }
}

// ─────────────────────────────────────────
// Viewport-basiertes Hydrant-Nachladen
// ─────────────────────────────────────────

// Kacheln werden einzeln gerendert, sobald sie verfügbar sind (Cache → sofort).
// Unterhalb VIEWPORT_ICON_ZOOM werden leichte Canvas-Punkte statt SVG-Icons
// gezeichnet, damit auch tausende Hydranten flüssig bleiben.

const VIEWPORT_ICON_ZOOM = 16;
let _viewportRenderer = null;

function _viewportUseIcons() {
    return state.map.getZoom() >= VIEWPORT_ICON_ZOOM;
}

function clearViewportHydrantMarkers() {
    state.viewportTiles.forEach(t => state.map.removeLayer(t.layer));
    state.viewportTiles.clear();
    state.markers.viewportHydrants = [];
    state.viewportHydrantIds.clear();
}

function _rebuildViewportIndex() {
    state.markers.viewportHydrants = [];
    state.viewportHydrantIds.clear();
    state.viewportTiles.forEach(t => {
        t.layer.eachLayer(m => state.markers.viewportHydrants.push(m));
        t.ids.forEach(id => state.viewportHydrantIds.add(id));
    });
}

function renderViewportTile(key, elements) {
    const wanted = state.viewportWantedTiles;
    if (!wanted.has(key)) return;

    const useIcons = _viewportUseIcons();
    const existing = state.viewportTiles.get(key);
    if (existing && existing.elements === elements && existing.useIcons === useIcons) return;
    if (existing) state.map.removeLayer(existing.layer);

    if (!_viewportRenderer) _viewportRenderer = L.canvas({ padding: 0.5 });

    const layer = L.layerGroup();
    const ids = [];
    elements.forEach(el => {
        const h = { id: el.id, lat: el.lat, lng: el.lon, tags: el.tags || {} };
        const marker = useIcons
            ? L.marker([h.lat, h.lng], { icon: ALL_HYDRANT_ICON, zIndexOffset: -100 })
            : L.circleMarker([h.lat, h.lng], {
                renderer: _viewportRenderer,
                radius: 4,
                weight: 1.5,
                color: '#ffffff',
                fillColor: '#e63946',
                fillOpacity: 0.95,
            });
        // Falls der Hydrant auch in der Standortliste ist, dessen Objekt verwenden (Popup/Distanz)
        marker.on('click', () => selectHydrant(state.hydrants.find(x => x.id === h.id) || h));
        layer.addLayer(marker);
        ids.push(el.id);
    });
    layer.addTo(state.map);
    state.viewportTiles.set(key, { layer, ids, elements, useIcons });
    _rebuildViewportIndex();
}

let _viewportDebounce = null;

function onMapMoveEnd() {
    clearTimeout(_viewportDebounce);
    _viewportDebounce = setTimeout(loadViewportHydrants, 150);
}

async function loadViewportHydrants() {
    if (state.map.getZoom() < VIEWPORT_MIN_ZOOM) {
        state.viewportWantedTiles = new Set();
        clearViewportHydrantMarkers();
        return;
    }

    // Sichtbarer Bereich + Rand, damit beim Verschieben schon Daten da sind
    const b = state.map.getBounds().pad(0.25);
    const keys = tileKeysForBounds(b.getSouth(), b.getWest(), b.getNorth(), b.getEast());
    if (keys.length > VIEWPORT_MAX_TILES) return;
    state.viewportWantedTiles = new Set(keys);

    // Kacheln ausserhalb des Bereichs entfernen, Darstellungsart ggf. wechseln
    const useIcons = _viewportUseIcons();
    let changed = false;
    state.viewportTiles.forEach((t, key) => {
        if (!state.viewportWantedTiles.has(key)) {
            state.map.removeLayer(t.layer);
            state.viewportTiles.delete(key);
            changed = true;
        } else if (t.useIcons !== useIcons) {
            renderViewportTile(key, t.elements);
        }
    });
    if (changed) _rebuildViewportIndex();

    // Mitte zuerst laden – dort schaut der Benutzer hin
    const c = state.map.getCenter();
    const centerKey = tileKeyFor(c.lat, c.lng);
    keys.sort((k1, k2) => (k1 === centerKey ? -1 : k2 === centerKey ? 1 : 0));

    const missing = keys.filter(k => !state.viewportTiles.has(k));
    if (missing.length === 0) return;
    try {
        await getHydrantTiles(missing, { onTile: renderViewportTile });
    } catch (e) {
        console.warn('[Viewport] Hydranten laden fehlgeschlagen:', e);
    }
}

function toggleBasemap() {
    const cycle = { osm: 'swisstopo', swisstopo: 'satellite', satellite: 'osm' };
    const titles = { osm: 'Swisstopo Karte', swisstopo: 'Luftbild', satellite: 'OpenStreetMap' };
    const next = cycle[state.currentBasemap];
    state.map.removeLayer(state.layers[state.currentBasemap]);
    state.layers[next].addTo(state.map);
    state.currentBasemap = next;
    dom.btnBasemap.classList.toggle('active', next !== 'osm');
    dom.btnBasemap.title = titles[next];
}

// ─────────────────────────────────────────
// OSRM Routing
// ─────────────────────────────────────────

const OSRM_BASE = 'https://router.project-osrm.org';

async function fetchRoute(from, to) {
    try {
        const url = `${OSRM_BASE}/route/v1/driving/${from.lng},${from.lat};${to.lng},${to.lat}?overview=full&geometries=geojson`;
        const res = await fetch(url);
        if (!res.ok) return null;
        const data = await res.json();
        if (data.code !== 'Ok' || !data.routes?.length) return null;
        return data.routes[0];
    } catch (e) {
        console.error('OSRM routing failed', e);
        return null;
    }
}

function drawRoute(route) {
    clearRoute();
    // OSRM GeoJSON coordinates are [lng, lat] → Leaflet needs [lat, lng]
    const latLngs = route.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
    state.markers.route = L.polyline(latLngs, {
        color: '#60a5fa',
        weight: 5,
        opacity: 0.85,
        lineJoin: 'round',
        lineCap: 'round',
    }).addTo(state.map);

    // Karte so zoomen dass Route + Quelle sichtbar sind
    state.map.fitBounds(state.markers.route.getBounds(), { padding: [60, 60], maxZoom: 17 });
}

function clearRoute() {
    if (state.markers.route) {
        state.map.removeLayer(state.markers.route);
        state.markers.route = null;
    }
}

// ─────────────────────────────────────────
// Hydrant auswählen (Fokus auf Karte + Liste)
// ─────────────────────────────────────────

async function selectHydrant(hydrant) {
    state.selectedHydrantId = hydrant.id;

    // Marker-Popup öffnen
    const markerIndex = state.hydrants.indexOf(hydrant);
    if (markerIndex >= 0 && markerIndex < state.markers.hydrants.length) {
        state.markers.hydrants[markerIndex].openPopup();
    }

    // Listenelement hervorheben
    document.querySelectorAll('.hydrant-item').forEach((el) => {
        el.classList.toggle('selected', el.dataset.id === String(hydrant.id));
    });

    // Route berechnen und auf Karte zeichnen
    const pos = getSourcePosition();
    if (!pos) return;

    const route = await fetchRoute(pos, { lat: hydrant.lat, lng: hydrant.lng });
    if (!route) {
        state.map.flyTo([hydrant.lat, hydrant.lng], 17, { duration: 0.8 });
        return;
    }

    drawRoute(route);

    // Popup mit echter Fahrstrecke aktualisieren
    if (markerIndex >= 0 && markerIndex < state.markers.hydrants.length) {
        const hoseLength = parseFloat(dom.hoseLength.value) || DEFAULTS.HOSE_LENGTH_M;
        const label = getHydrantLabel(hydrant.tags);
        const address = getHydrantAddress(hydrant.tags);
        const sections = calcHoseSections(route.distance, hoseLength);
        const barrierWarning = hydrant.barrierTypes?.size > 0
            ? `<span style="color:#f4a261">⚠ Kreuzung: ${[...hydrant.barrierTypes].map(t => t === 'water' ? '🌊 Gewässer' : '🚂 Bahn').join(', ')}</span><br/>`
            : '';
        const updatedPopup = `
      <b>${label}</b><br/>
      ${address ? address + '<br/>' : ''}
      📏 Luftlinie: <b>${formatDistance(hydrant.distM)}</b><br/>
      🚗 Fahrtstrecke: <b>${formatDistance(route.distance)}</b><br/>
      🧯 Schläuche: <b>${sections}×</b> (à ${hoseLength} m)<br/>
      ${barrierWarning}
    `;
        state.markers.hydrants[markerIndex].getPopup().setContent(updatedPopup).update();
    }
}

// ─────────────────────────────────────────
// Status-Badge
// ─────────────────────────────────────────

function setStatus(text, type = 'default') {
    dom.statusText.textContent = text;
    dom.statusBadge.className = `topbar__status topbar__status--${type}`;
}

// ─────────────────────────────────────────
// Bottom-Sheet – Auf-/Zuklappen
// ─────────────────────────────────────────

function expandSheet() {
    dom.bottomSheet.style.height = Math.round(window.innerHeight * 0.65) + 'px';
    dom.bottomSheet.classList.remove('collapsed');
    setTimeout(() => state.map && state.map.invalidateSize(), 360);
}

function collapseSheet() {
    dom.bottomSheet.style.height = '84px';
    dom.bottomSheet.classList.add('collapsed');
    setTimeout(() => state.map && state.map.invalidateSize(), 360);
}

/** Sheet-Zustand umschalten */
function toggleSheet() {
    if (dom.bottomSheet.classList.contains('collapsed')) {
        expandSheet();
    } else {
        collapseSheet();
    }
}

function initSheetDrag() {
    const sheet = dom.bottomSheet;
    const handle = dom.sheetHandle;

    const SNAP_COLLAPSED = 84;
    const snapMid = () => Math.round(window.innerHeight * 0.40);
    const snapExpanded = () => Math.round(window.innerHeight * 0.65);

    let dragStartY = 0;
    let dragStartH = 0;
    let isDragging = false;
    let lastY = 0;
    let lastTime = 0;
    let velocity = 0; // px/ms, positive = upward

    function snapTo(h) {
        const snaps = [SNAP_COLLAPSED, snapMid(), snapExpanded()];
        const projected = h + velocity * 120;
        const target = snaps.reduce((a, b) =>
            Math.abs(b - projected) < Math.abs(a - projected) ? b : a
        );
        sheet.style.transition = '';
        sheet.style.height = target + 'px';
        sheet.classList.toggle('collapsed', target === SNAP_COLLAPSED);
        setTimeout(() => state.map && state.map.invalidateSize(), 360);
    }

    handle.addEventListener('pointerdown', (e) => {
        dragStartY = e.clientY;
        dragStartH = sheet.offsetHeight;
        isDragging = false;
        lastY = e.clientY;
        lastTime = Date.now();
        velocity = 0;
        handle.setPointerCapture(e.pointerId);
    });

    handle.addEventListener('pointermove', (e) => {
        const now = Date.now();
        const dt = now - lastTime;
        if (dt > 0) velocity = (lastY - e.clientY) / dt;
        lastY = e.clientY;
        lastTime = now;
        const deltaY = dragStartY - e.clientY;
        if (!isDragging && Math.abs(deltaY) < 6) return;
        isDragging = true;
        const newH = Math.max(SNAP_COLLAPSED, Math.min(snapExpanded(), dragStartH + deltaY));
        sheet.style.transition = 'none';
        sheet.style.height = newH + 'px';
    });

    handle.addEventListener('pointerup', () => {
        if (!isDragging) {
            const h = sheet.offsetHeight;
            sheet.style.transition = '';
            if (h <= SNAP_COLLAPSED + 10) {
                sheet.style.height = snapMid() + 'px';
                sheet.classList.remove('collapsed');
            } else {
                sheet.style.height = SNAP_COLLAPSED + 'px';
                sheet.classList.add('collapsed');
            }
            setTimeout(() => state.map && state.map.invalidateSize(), 360);
            return;
        }
        snapTo(sheet.offsetHeight);
    });

    const contentEl = document.getElementById('sheetContent');
    let contentTouchY = 0;
    contentEl.addEventListener('touchstart', (e) => {
        contentTouchY = e.touches[0].clientY;
    }, { passive: true });
    contentEl.addEventListener('touchend', (e) => {
        if (contentEl.scrollTop > 0) return;
        const deltaY = contentTouchY - e.changedTouches[0].clientY;
        if (deltaY < -50) {
            sheet.style.transition = '';
            sheet.style.height = SNAP_COLLAPSED + 'px';
            sheet.classList.add('collapsed');
            setTimeout(() => state.map && state.map.invalidateSize(), 360);
        }
    }, { passive: true });
}

// ─────────────────────────────────────────
// Event-Listener
// ─────────────────────────────────────────

function initEventListeners() {
    // "Mein Standort" – zentrieren wenn GPS vorliegt, sonst neuen Fix anfordern
    dom.btnMyLocation.addEventListener('click', () => {
        if (state.hasTruePosition && state.userPos) {
            state.map.flyTo([state.userPos.lat, state.userPos.lng], 16, { duration: 0.8 });
        } else {
            retryLocation();
        }
    });

    dom.btnLocationRetry.addEventListener('click', retryLocation);

    // Brandposition-Modus an/aus
    dom.btnSetFire.addEventListener('click', () => {
        if (state.fireMode) {
            exitFireMode();
        } else if (state.firePos) {
            // Brandposition löschen
            clearFirePosition();
            fetchHydrants();
            setStatus('Bereit', 'ready');
            dom.btnSetFire.classList.remove('active');
        } else {
            enterFireMode();
        }
    });

    // Brandmodus abbrechen via Banner
    dom.btnCancelFire.addEventListener('click', exitFireMode);

    // Hydranten neu laden
    dom.btnToggleAll.addEventListener('click', toggleAllHydrants);
    dom.btnBasemap.addEventListener('click', toggleBasemap);
    dom.btnRefresh.addEventListener('click', () => fetchHydrants({ force: true }));

    // Admin / Login button
    const hwToken = localStorage.getItem('hw_token');
    const hwExpires = parseInt(localStorage.getItem('hw_expires') || '0', 10);
    if (hwToken && Date.now() < hwExpires) {
        dom.btnAdmin.title = 'Admin-Bereich';
        dom.btnAdmin.classList.add('active');
    }
    dom.btnAdmin.addEventListener('click', () => {
        const tok = localStorage.getItem('hw_token');
        const exp = parseInt(localStorage.getItem('hw_expires') || '0', 10);
        window.location.href = (tok && Date.now() < exp) ? '/admin/' : '/login.php';
    });

    // Einstellungen geändert → neu berechnen
    dom.hoseLength.addEventListener('change', () => {
        // Nur neu rendern, keine API-Anfrage
        sortAndRenderHydrants();
    });

    dom.searchRadius.addEventListener('change', () => {
        // Neuer Radius → API-Anfrage
        fetchHydrants();
    });
}

// ─────────────────────────────────────────
// URL-Parameter – automatische Brandposition
// ─────────────────────────────────────────

/**
 * Liest optionale URL-Parameter und setzt die Brandposition beim Seitenaufruf.
 * Unterstützte Parameter: ?lat=47.039900&lng=8.181200
 * Gibt true zurück wenn eine gültige Position gefunden wurde.
 */
function applyUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const lat = parseFloat(params.get('lat'));
    const lng = parseFloat(params.get('lng'));

    // Abbruch wenn Parameter fehlen oder Werte außerhalb des gültigen WGS84-Bereichs
    if (isNaN(lat) || isNaN(lng)) return false;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return false;

    const pos = { lat, lng };
    state.firePos = pos;

    // Karte direkt auf Brandposition zentrieren
    state.map.setView([lat, lng], 16);

    // Feuer-Marker setzen
    state.markers.fire = L.marker([lat, lng], { icon: FIRE_ICON, zIndexOffset: 900 })
        .addTo(state.map)
        .bindTooltip(`Brandposition (${lat.toFixed(5)}, ${lng.toFixed(5)})`, { direction: 'top' });

    // FAB-Button als aktiv markieren
    dom.btnSetFire.classList.add('active');
    setStatus('Brandposition gesetzt', 'fire');

    return true;
}

// ─────────────────────────────────────────
// WasserTransportPlan – Kartenmarker
// ─────────────────────────────────────────

/** Blaues Wasser-Icon für WasserTransportPläne */
function createWtpIcon() {
    return L.divIcon({
        className: '',
        html: `<div style="
            width:34px; height:34px;
            background:rgba(56,189,248,0.15);
            border:2.5px solid #38bdf8;
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:16px;
            box-shadow:0 0 12px rgba(56,189,248,0.4), 0 2px 8px rgba(0,0,0,0.5);
        ">💧</div>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
        popupAnchor: [0, -20],
    });
}

/**
 * Lädt WasserTransportPlan-Positionen und zeigt sie auf der Karte.
 * Nur sichtbar wenn der Benutzer eingeloggt ist (Token im localStorage).
 * Beim Klick auf einen WTP-Marker werden die Kartenobjekte des Plans angezeigt.
 */

// Aktive Plan-Overlay-Layer (Fahrzeuge, Schläuche, Texte)
const wtpOverlayLayers = [];

function clearWtpOverlay() {
    wtpOverlayLayers.forEach(layer => state.map.removeLayer(layer));
    wtpOverlayLayers.length = 0;
}

function showWtpPlanObjects(plan) {
    clearWtpOverlay();

    (plan.map_objects || []).forEach(o => {
        const emoji = o.type === 'TLF' ? '🚒' : '🚛';
        const color = o.type === 'TLF' ? '#38bdf8' : '#f97316';
        const icon = L.divIcon({
            className: '',
            html: `<div style="
                background:rgba(0,0,0,.8);border:2px solid ${color};
                border-radius:8px;padding:3px 8px;font-size:13px;
                color:#fff;white-space:nowrap;
                box-shadow:0 2px 8px rgba(0,0,0,.6);
            ">${emoji} ${escHtmlMap(o.name)}</div>`,
            iconAnchor: [0, 0],
        });
        const marker = L.marker([o.lat, o.lng], { icon, zIndexOffset: 300 }).addTo(state.map);
        wtpOverlayLayers.push(marker);
    });

    (plan.map_hoses || []).forEach(h => {
        if (!h.coordinates?.length) return;
        const line = L.polyline(h.coordinates, {
            color: '#ef4444', weight: 4, opacity: 0.85
        }).addTo(state.map);
        wtpOverlayLayers.push(line);
    });

    (plan.map_texts || []).forEach(t => {
        const icon = L.divIcon({
            className: '',
            html: `<div style="
                background:rgba(0,0,0,.8);border:1px solid rgba(74,222,128,.6);
                border-radius:6px;padding:3px 8px;font-size:12px;
                color:#4ade80;white-space:nowrap;
                box-shadow:0 2px 8px rgba(0,0,0,.6);
            ">${escHtmlMap(t.label)}</div>`,
            iconAnchor: [0, 0],
        });
        const marker = L.marker([t.lat, t.lng], { icon, zIndexOffset: 300 }).addTo(state.map);
        wtpOverlayLayers.push(marker);
    });
}

function escHtmlMap(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function loadWtpMarkers() {
    const token = localStorage.getItem('hw_token');
    const expires = parseInt(localStorage.getItem('hw_expires') || '0', 10);
    if (!token || Date.now() > expires) return;

    try {
        const res = await fetch('/admin/wtp_api.php?action=map_data', {
            headers: { 'Authorization': `Bearer ${token}` },
        });
        if (!res.ok) return;
        const plans = await res.json();
        if (!Array.isArray(plans)) return;

        plans.forEach((plan) => {
            if (!plan.lat || !plan.lng) return;
            const marker = L.marker([plan.lat, plan.lng], { icon: createWtpIcon(), zIndexOffset: 200 })
                .addTo(state.map)
                .bindPopup(
                    `<b style="color:#38bdf8">💧 ${plan.name}</b><br/>
                     <a href="/admin/wtp.html" target="_blank"
                        style="color:#38bdf8;font-size:12px">Plan öffnen →</a>`,
                    { closeButton: false, maxWidth: 200 }
                );

            marker.on('popupopen', () => showWtpPlanObjects(plan));
            marker.on('popupclose', () => clearWtpOverlay());
        });
    } catch (err) {
        console.error({ err }, 'WTP-Marker konnten nicht geladen werden');
    }
}

// ─────────────────────────────────────────
// Start
// ─────────────────────────────────────────

function init() {
    initMap();
    initSheetDrag();
    initEventListeners();

    // URL-Parameter prüfen: wenn Brandposition angegeben, sofort Hydranten laden
    const hasUrlFirePos = applyUrlParams();
    if (hasUrlFirePos) {
        startLocationWatch();
        fetchHydrants();
    } else {
        startLocationWatch();
    }

    // WasserTransportPlan-Marker laden (nur wenn eingeloggt)
    loadWtpMarkers();
}

document.addEventListener('DOMContentLoaded', init);

