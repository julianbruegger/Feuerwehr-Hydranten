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
    HOSE_LENGTH_M: 20,    // Meter pro Schlauch
    SEARCH_RADIUS_M: 1000,  // Suchradius in Metern
    MAX_RESULTS: 15,    // Maximale Anzahl angezeigter Hydranten
    ROAD_FACTOR: 1.3,   // Straßenfaktor (Luftlinie → geschätzte Gehstrecke)
};

/** Overpass API Endpunkt */
const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

// ─────────────────────────────────────────
// App-Zustand
// ─────────────────────────────────────────

const state = {
    map: null,         // Leaflet Map Instanz
    userPos: null,         // { lat, lng } – GPS-Standort
    firePos: null,         // { lat, lng } – Manuell gesetzte Brandposition (oder null)
    hydrants: [],           // Array von Hydrant-Objekten (mit Distanz)
    markers: {
        user: null,         // Leaflet Marker für Benutzer
        fire: null,         // Leaflet Marker für Brandposition
        accuracy: null,         // Genauigkeitskreis
        hydrants: [],           // Leaflet Marker für Hydranten
    },
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
    btnRefresh: document.getElementById('btnRefresh'),
    modeBanner: document.getElementById('modeBanner'),
    btnCancelFire: document.getElementById('btnCancelFire'),
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

/** Erstellt ein rotes Hydrant-Icon */
function createHydrantIcon(rank, isNearest) {
    const color = isNearest ? '#e63946' : '#8a8a9a';
    const scale = isNearest ? 1.15 : 1;
    return L.divIcon({
        className: '',
        html: `<div style="
      width:${Math.round(34 * scale)}px;
      height:${Math.round(34 * scale)}px;
      background:${isNearest ? 'rgba(230,57,70,0.15)' : 'rgba(24,24,31,0.9)'};
      border:2.5px solid ${color};
      border-radius:50%;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:${isNearest ? '14' : '12'}px;
      font-weight:700;
      color:${color};
      font-family:Inter,sans-serif;
      box-shadow:0 2px 10px rgba(0,0,0,0.5)${isNearest ? ',0 0 12px rgba(230,57,70,0.4)' : ''};
      backdrop-filter:blur(8px);
    ">${rank}</div>`,
        iconSize: [Math.round(34 * scale), Math.round(34 * scale)],
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

    // OpenStreetMap Tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(state.map);

    // Zoom-Steuerung oben links (verhindert Überschneidung mit FABs rechts)
    L.control.zoom({ position: 'topleft' }).addTo(state.map);

    // Kartenklick → Brandposition setzen (wenn Modus aktiv)
    state.map.on('click', onMapClick);
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
    const isFirstFix = state.userPos === null;

    state.userPos = newPos;

    // Marker/Kreis aktualisieren
    updateUserMarker(newPos, accuracy);

    if (isFirstFix) {
        // Erste Position → Karte zentrieren & Hydranten laden
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

async function fetchHydrants() {
    const pos = getSourcePosition();
    if (!pos) return;

    const radius = parseInt(dom.searchRadius.value, 10) || DEFAULTS.SEARCH_RADIUS_M;

    setStatus('Hydranten laden…', 'loading');
    dom.btnRefresh.classList.add('loading');

    // Overpass QL Query: Alle Feuerhydranten im Radius
    const query = `
    [out:json][timeout:25];
    node["emergency"="fire_hydrant"](around:${radius},${pos.lat},${pos.lng});
    out body;
  `;

    try {
        const response = await fetch(OVERPASS_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'data=' + encodeURIComponent(query),
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        processHydrantData(data.elements || []);
        setStatus(state.firePos ? 'Brandposition gesetzt' : 'Bereit', state.firePos ? 'fire' : 'ready');
    } catch (error) {
        console.error({ error }, 'Overpass-Abruf fehlgeschlagen');
        showEmptyState(`Fehler beim Laden: ${error.message}`);
        setStatus('Ladefehler', 'error');
    } finally {
        dom.btnRefresh.classList.remove('loading');
    }
}

function processHydrantData(elements) {
    // Rohe OSM-Knoten zu internen Hydrant-Objekten umwandeln
    state.hydrants = elements.map((el) => ({
        id: el.id,
        lat: el.lat,
        lng: el.lon,
        tags: el.tags || {},
        distM: 0,   // wird in sortAndRenderHydrants gesetzt
    }));

    sortAndRenderHydrants();
}

// ─────────────────────────────────────────
// Sortierung, Berechnung & Rendering
// ─────────────────────────────────────────

function sortAndRenderHydrants() {
    const pos = getSourcePosition();
    if (!pos || state.hydrants.length === 0) {
        showEmptyState('Keine Hydranten in der Nähe gefunden.');
        clearHydrantMarkers();
        return;
    }

    const hoseLength = parseFloat(dom.hoseLength.value) || DEFAULTS.HOSE_LENGTH_M;

    // Luftlinien-Distanz berechnen & Ergebnis sortieren
    state.hydrants.forEach((h) => {
        h.distM = haversineDistance(pos, { lat: h.lat, lng: h.lng });
    });

    state.hydrants.sort((a, b) => a.distM - b.distM);

    const visible = state.hydrants.slice(0, DEFAULTS.MAX_RESULTS);

    clearHydrantMarkers();
    renderHydrantList(visible, hoseLength);
    renderHydrantMarkers(visible, pos, hoseLength);
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

/**
 * Berechnet die Anzahl der benötigten Schläuche.
 * Verwendet Luftlinie × Straßenfaktor, aufgerundet.
 */
function calcHoseSections(distAirlineM, hoseLengthM) {
    const estimatedRoadDist = distAirlineM * DEFAULTS.ROAD_FACTOR;
    return Math.ceil(estimatedRoadDist / hoseLengthM);
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
        const sections = calcHoseSections(h.distM, hoseLength);
        const label = getHydrantLabel(h.tags);
        const address = getHydrantAddress(h.tags);
        const hoseLabel = hoseLength + 'm Schläuche';

        const li = document.createElement('li');
        li.className = `hydrant-item${isNearest ? ' nearest' : ''}`;
        li.dataset.id = h.id;
        li.setAttribute('role', 'button');
        li.setAttribute('tabindex', '0');

        li.innerHTML = `
      <div class="hydrant-rank">${rank}</div>
      <div class="hydrant-info">
        <div class="hydrant-name">${label}</div>
        <div class="hydrant-address">${address || formatDistance(h.distM) + ' entfernt'}</div>
      </div>
      <div class="hydrant-hose">
        <span class="hose-count">${sections}</span>
        <span class="hose-label">Schläuche</span>
        <span class="hose-dist">${formatDistance(h.distM)}</span>
      </div>
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
        const sections = calcHoseSections(h.distM, hoseLength);
        const label = getHydrantLabel(h.tags);
        const address = getHydrantAddress(h.tags);

        const popupContent = `
      <b>${label}</b><br/>
      ${address ? address + '<br/>' : ''}
      📏 Entfernung: <b>${formatDistance(h.distM)}</b><br/>
      🧯 Schläuche: <b>${sections}×</b> (à ${hoseLength} m)
    `;

        const marker = L.marker([h.lat, h.lng], {
            icon: createHydrantIcon(index + 1, isNearest),
            zIndexOffset: isNearest ? 500 : 0,
        })
            .addTo(state.map)
            .bindPopup(popupContent, { closeButton: false, maxWidth: 220 });

        marker.on('click', () => selectHydrant(h));
        state.markers.hydrants.push(marker);
    });
}

function clearHydrantMarkers() {
    state.markers.hydrants.forEach((m) => state.map.removeLayer(m));
    state.markers.hydrants = [];
}

// ─────────────────────────────────────────
// Hydrant auswählen (Fokus auf Karte + Liste)
// ─────────────────────────────────────────

function selectHydrant(hydrant) {
    state.selectedHydrantId = hydrant.id;

    // Karte zu Hydrant fliegen
    state.map.flyTo([hydrant.lat, hydrant.lng], 17, { duration: 0.8 });

    // Marker-Popup öffnen
    const markerIndex = state.hydrants.indexOf(hydrant);
    if (markerIndex >= 0 && markerIndex < state.markers.hydrants.length) {
        state.markers.hydrants[markerIndex].openPopup();
    }

    // Listenelement hervorheben
    document.querySelectorAll('.hydrant-item').forEach((el) => {
        el.classList.toggle('selected', el.dataset.id === String(hydrant.id));
    });
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

/** Sheet öffnen und Leaflet-Karte invalidieren */
function expandSheet() {
    dom.bottomSheet.classList.remove('collapsed');
    // Kurz warten bis CSS-Transition fertig ist, dann Karte neu berechnen
    setTimeout(() => state.map && state.map.invalidateSize(), 360);
}

/** Sheet zuklappen */
function collapseSheet() {
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
    let touchStartY = 0;
    let touchStartTime = 0;

    // Tipp auf Handle → auf-/zuklappen
    dom.sheetHandle.addEventListener('click', toggleSheet);

    // Swipe auf Handle: nach oben → öffnen, nach unten → schließen
    dom.sheetHandle.addEventListener('touchstart', (e) => {
        touchStartY = e.touches[0].clientY;
        touchStartTime = Date.now();
    }, { passive: true });

    dom.sheetHandle.addEventListener('touchend', (e) => {
        const deltaY = touchStartY - e.changedTouches[0].clientY;
        const elapsed = Date.now() - touchStartTime;
        // Nur als Swipe werten wenn ≥ 20px in ≤ 350ms
        if (Math.abs(deltaY) < 20 || elapsed > 350) return;
        if (deltaY > 0) expandSheet();
        else collapseSheet();
    }, { passive: true });

    // Swipe-down auf dem Sheet-Inhalt schließt das Sheet (wenn ganz oben gescrollt)
    const contentEl = document.getElementById('sheetContent');
    let contentTouchY = 0;
    contentEl.addEventListener('touchstart', (e) => {
        contentTouchY = e.touches[0].clientY;
    }, { passive: true });
    contentEl.addEventListener('touchend', (e) => {
        if (contentEl.scrollTop > 0) return; // Nur wenn ganz oben
        const deltaY = contentTouchY - e.changedTouches[0].clientY;
        if (deltaY < -50) collapseSheet();
    }, { passive: true });
}

// ─────────────────────────────────────────
// Event-Listener
// ─────────────────────────────────────────

function initEventListeners() {
    // "Mein Standort" zentrieren
    dom.btnMyLocation.addEventListener('click', () => {
        if (state.userPos) {
            state.map.flyTo([state.userPos.lat, state.userPos.lng], 16, { duration: 0.8 });
        } else {
            startLocationWatch();
        }
    });

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
    dom.btnRefresh.addEventListener('click', fetchHydrants);

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
 */
async function loadWtpMarkers() {
    const token = localStorage.getItem('hw_token');
    const expires = parseInt(localStorage.getItem('hw_expires') || '0', 10);
    if (!token || Date.now() > expires) return;  // Nicht eingeloggt → keine WTP-Marker

    try {
        const res = await fetch('/admin/wtp_api.php?action=map_data', {
            headers: { 'Authorization': `Bearer ${token}` },
        });
        if (!res.ok) return;
        const plans = await res.json();
        if (!Array.isArray(plans)) return;

        plans.forEach((plan) => {
            if (!plan.lat || !plan.lng) return;
            L.marker([plan.lat, plan.lng], { icon: createWtpIcon(), zIndexOffset: 200 })
                .addTo(state.map)
                .bindPopup(
                    `<b style="color:#38bdf8">💧 ${plan.name}</b><br/>
                     <a href="/admin/wtp.html" target="_blank"
                        style="color:#38bdf8;font-size:12px">Plan öffnen →</a>`,
                    { closeButton: false, maxWidth: 200 }
                );
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

