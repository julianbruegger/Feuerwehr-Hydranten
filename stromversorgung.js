'use strict';

// ─────────────────────────────────────────
// Konstanten
// ─────────────────────────────────────────

const DEFAULTS = {
    SEARCH_RADIUS_M: 2000,
    MAX_RESULTS: 30,
};

const OVERPASS_ENDPOINTS = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
];
let _overpassIndex = 0;

async function overpassFetch(query, { retries = 2 } = {}) {
    for (let attempt = 0; attempt <= retries; attempt++) {
        const url = OVERPASS_ENDPOINTS[_overpassIndex % OVERPASS_ENDPOINTS.length];
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'data=' + encodeURIComponent(query),
            });
            if (res.status === 429 || res.status === 504 || res.status === 502) {
                _overpassIndex++;
                if (attempt < retries) {
                    await new Promise(r => setTimeout(r, 800 * (attempt + 1)));
                    continue;
                }
                throw new Error(`HTTP ${res.status}`);
            }
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return await res.json();
        } catch (e) {
            if (attempt < retries) {
                _overpassIndex++;
                await new Promise(r => setTimeout(r, 800 * (attempt + 1)));
            } else {
                throw e;
            }
        }
    }
}

// ─────────────────────────────────────────
// Cache (localStorage, 24h TTL)
// ─────────────────────────────────────────

const CACHE_TTL_MS   = 24 * 60 * 60 * 1000;
const CACHE_GRID_DEG = 0.005;

function _cacheKey(pos, radius) {
    const lat = Math.round(pos.lat / CACHE_GRID_DEG) * CACHE_GRID_DEG;
    const lng = Math.round(pos.lng / CACHE_GRID_DEG) * CACHE_GRID_DEG;
    return `hw_substations_${lat.toFixed(3)}_${lng.toFixed(3)}_${radius}`;
}

function getCached(pos, radius) {
    try {
        const raw = localStorage.getItem(_cacheKey(pos, radius));
        if (!raw) return null;
        const { ts, elements } = JSON.parse(raw);
        if (Date.now() - ts > CACHE_TTL_MS) return null;
        return elements;
    } catch { return null; }
}

function setCache(pos, radius, elements) {
    try {
        for (const key of Object.keys(localStorage)) {
            if (!key.startsWith('hw_substations_')) continue;
            try {
                const { ts } = JSON.parse(localStorage.getItem(key));
                if (Date.now() - ts > CACHE_TTL_MS) localStorage.removeItem(key);
            } catch { localStorage.removeItem(key); }
        }
        localStorage.setItem(_cacheKey(pos, radius), JSON.stringify({ ts: Date.now(), elements }));
    } catch { /* localStorage voll – ignorieren */ }
}

// ─────────────────────────────────────────
// App-Zustand
// ─────────────────────────────────────────

const state = {
    map: null,
    userPos: null,
    substations: [],
    markers: {
        user: null,
        accuracy: null,
        substations: [],
        allSubstations: [],
        buildings: [],
    },
    showAllSubstations: false,
    watchId: null,
    assignments: [],   // Gebäude-Zuordnungen vom Admin (wenn eingeloggt)
};

// ─────────────────────────────────────────
// DOM-Referenzen
// ─────────────────────────────────────────

const dom = {
    statusBadge:   document.getElementById('statusBadge'),
    statusText:    document.getElementById('statusText'),
    btnMyLocation: document.getElementById('btnMyLocation'),
    btnToggleAll:  document.getElementById('btnToggleAll'),
    btnBasemap:    document.getElementById('btnBasemap'),
    btnRefresh:    document.getElementById('btnRefresh'),
    sheetHandle:   document.getElementById('sheetHandle'),
    bottomSheet:   document.getElementById('bottomSheet'),
    searchRadius:  document.getElementById('searchRadius'),
    substationList: document.getElementById('substationList'),
    emptyState:    document.getElementById('emptyState'),
};

// ─────────────────────────────────────────
// Hilfsfunktionen
// ─────────────────────────────────────────

function haversineM(a, b) {
    const R = 6_371_000;
    const dLat = (b.lat - a.lat) * Math.PI / 180;
    const dLng = (b.lng - a.lng) * Math.PI / 180;
    const s = Math.sin(dLat / 2) ** 2
        + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
}

function formatDist(m) {
    return m < 1000 ? `${Math.round(m)} m` : `${(m / 1000).toFixed(1)} km`;
}

function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ─────────────────────────────────────────
// Typen-Metadaten für Trafostationen
// ─────────────────────────────────────────

function substationMeta(tags) {
    const power   = tags.power || '';
    const sub     = tags.substation || '';
    const cabinet = tags.street_cabinet || '';

    if (power === 'substation') {
        if (sub === 'transmission')
            return { label: 'Übertragungsstation', color: '#e63946', glow: 'rgba(230,57,70,0.4)', icon: '⚡' };
        if (sub === 'minor_distribution' || sub === 'distribution')
            return { label: 'Trafostation', color: '#f59e0b', glow: 'rgba(245,158,11,0.35)', icon: '⚡' };
        return { label: 'Umspannwerk', color: '#f59e0b', glow: 'rgba(245,158,11,0.35)', icon: '⚡' };
    }
    if (power === 'transformer')
        return { label: 'Transformator', color: '#fb923c', glow: 'rgba(251,146,60,0.35)', icon: 'T' };
    if (power === 'cable_distribution_cabinet')
        return { label: 'Verteilerkasten (VK)', color: '#94a3b8', glow: 'rgba(148,163,184,0.25)', icon: 'VK' };
    if (power === 'switch' || power === 'switchgear')
        return { label: 'Schalter/Trennstelle', color: '#818cf8', glow: 'rgba(129,140,248,0.25)', icon: '⇄' };
    // man_made=street_cabinet with street_cabinet=power/electrical
    if (cabinet === 'power' || cabinet === 'electrical' || cabinet === 'energy')
        return { label: 'Verteilerkasten (VK)', color: '#94a3b8', glow: 'rgba(148,163,184,0.25)', icon: 'VK' };
    return { label: 'Stromanlage', color: '#f59e0b', glow: 'rgba(245,158,11,0.35)', icon: '⚡' };
}

function substationDisplayName(tags) {
    // VKs often have their designation in the name (e.g. "VK Industriestrasse 1") or ref
    return tags.name || tags.ref || tags['ref:vk'] || tags.operator || '';
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
// Marker-Icons
// ─────────────────────────────────────────

function createSubstationIcon(tags, rank, isTop) {
    const { color, glow, icon } = substationMeta(tags);
    const size = isTop ? 38 : 30;
    return L.divIcon({
        className: '',
        html: `<div style="
            width:${size}px; height:${size}px;
            background:${isTop ? `rgba(245,158,11,0.15)` : 'rgba(24,24,31,0.9)'};
            border:2.5px solid ${color};
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:${isTop ? 15 : 12}px; font-weight:700; color:${color};
            font-family:Inter,sans-serif;
            box-shadow:0 2px 10px rgba(0,0,0,0.5)${isTop ? `,0 0 14px ${glow}` : ''};
            backdrop-filter:blur(8px);
        ">${icon}</div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -(size / 2 + 4)],
    });
}

const ALL_SUBSTATION_ICON = L.divIcon({
    className: '',
    html: `<div style="
        width:10px; height:10px;
        background:rgba(245,158,11,0.5);
        border:1.5px solid #f59e0b;
        border-radius:50%;
    "></div>`,
    iconSize: [10, 10],
    iconAnchor: [5, 5],
});

const USER_ICON = L.divIcon({
    className: '',
    html: `<div style="
        width:20px; height:20px;
        background:#4ade80;
        border:3px solid #fff;
        border-radius:50%;
        box-shadow:0 0 0 3px rgba(74,222,128,0.3),0 2px 8px rgba(0,0,0,0.5);
    "></div>`,
    iconSize: [20, 20],
    iconAnchor: [10, 10],
});

const BUILDING_ICON = L.divIcon({
    className: '',
    html: `<div style="
        width:28px; height:28px;
        background:rgba(56,189,248,0.15);
        border:2px solid #38bdf8;
        border-radius:6px;
        display:flex; align-items:center; justify-content:center;
        font-size:14px;
        box-shadow:0 2px 8px rgba(0,0,0,0.4);
        backdrop-filter:blur(6px);
    ">🏠</div>`,
    iconSize: [28, 28],
    iconAnchor: [14, 14],
    popupAnchor: [0, -16],
});

// ─────────────────────────────────────────
// Karteninitialisierung
// ─────────────────────────────────────────

function initMap() {
    state.map = L.map('map', { zoomControl: false, attributionControl: true })
        .setView([47.0409, 8.3005], 15);

    state.layers = {
        osm: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
            maxZoom: 19,
        }),
        swisstopo: L.tileLayer('https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.swisstopo.admin.ch">swisstopo</a>',
            maxZoom: 19,
        }),
    };
    state.layers.osm.addTo(state.map);

    L.control.zoom({ position: 'topleft' }).addTo(state.map);
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

    navigator.geolocation.getCurrentPosition(onPositionUpdate, onPositionError, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 5000,
    });

    state.watchId = navigator.geolocation.watchPosition(onPositionUpdate, onPositionError, {
        enableHighAccuracy: true,
        timeout: 20000,
        maximumAge: 10000,
    });
}

function onPositionUpdate(position) {
    const { latitude, longitude, accuracy } = position.coords;
    const newPos = { lat: latitude, lng: longitude };
    const isFirst = state.userPos === null;
    state.userPos = newPos;

    updateUserMarker(newPos, accuracy);

    if (isFirst) {
        state.map.setView([newPos.lat, newPos.lng], 16);
        fetchSubstations();
    }
    setStatus('Bereit', 'ready');
}

function onPositionError(error) {
    const msgs = { 1: 'Standortzugriff verweigert', 2: 'Standort nicht verfügbar', 3: 'GPS-Zeitüberschreitung' };
    setStatus(msgs[error.code] || 'GPS-Fehler', 'error');

    if (!state.userPos) {
        const center = state.map.getCenter();
        state.userPos = { lat: center.lat, lng: center.lng };
        fetchSubstations();
    }
}

function updateUserMarker(pos, accuracy) {
    if (state.markers.accuracy) {
        state.markers.accuracy.setLatLng([pos.lat, pos.lng]).setRadius(accuracy);
    } else {
        state.markers.accuracy = L.circle([pos.lat, pos.lng], {
            radius: accuracy,
            className: 'accuracy-circle',
        }).addTo(state.map);
    }

    if (state.markers.user) {
        state.markers.user.setLatLng([pos.lat, pos.lng]);
    } else {
        state.markers.user = L.marker([pos.lat, pos.lng], { icon: USER_ICON, zIndexOffset: 1000 })
            .addTo(state.map)
            .bindTooltip('Mein Standort', { direction: 'top' });
    }
}

// ─────────────────────────────────────────
// Overpass-Abfrage
// ─────────────────────────────────────────

async function fetchSubstations() {
    const pos = state.userPos;
    if (!pos) return;

    const radius = parseInt(dom.searchRadius.value, 10) || DEFAULTS.SEARCH_RADIUS_M;
    dom.btnRefresh.classList.add('loading');

    const cached = getCached(pos, radius);
    if (cached) {
        setStatus('Bereit (Cache)', 'ready');
        dom.btnRefresh.classList.remove('loading');
        processData(cached);
        return;
    }

    setStatus('Trafostationen laden…', 'loading');

    // ways mit "out center" damit Mittelpunkt-Koordinaten zurückgegeben werden.
    // Neben den Standard-power=*-Tags auch man_made=street_cabinet für Verteilerkästen,
    // die von lokalen Netzbetreibern (z.B. Steiner Energie Malters) möglicherweise
    // anders eingetragen wurden.
    const query = `
[out:json][timeout:30];
(
  node["power"~"^(substation|transformer|cable_distribution_cabinet|switch|switchgear)$"](around:${radius},${pos.lat},${pos.lng});
  way["power"~"^(substation|transformer|cable_distribution_cabinet)$"](around:${radius},${pos.lat},${pos.lng});
  node["man_made"="street_cabinet"]["street_cabinet"~"^(power|electrical|energy)$"](around:${radius},${pos.lat},${pos.lng});
);
out center;
`;

    try {
        const data = await overpassFetch(query);
        setCache(pos, radius, data.elements || []);
        processData(data.elements || []);
        setStatus('Bereit', 'ready');
    } catch (err) {
        console.error('Overpass-Fehler', err);
        showEmptyState(`Fehler beim Laden: ${err.message}`);
        setStatus('Ladefehler', 'error');
    } finally {
        dom.btnRefresh.classList.remove('loading');
    }
}

function processData(elements) {
    state.substations = elements
        .map((el) => {
            // Nodes haben lat/lon direkt; Ways haben ein center-Objekt
            const lat = el.type === 'node' ? el.lat : el.center?.lat;
            const lng = el.type === 'node' ? el.lon : el.center?.lon;
            if (lat == null || lng == null) return null;
            return {
                id: `${el.type}/${el.id}`,
                osmType: el.type,
                osmId: el.id,
                lat,
                lng,
                tags: el.tags || {},
                distM: 0,
            };
        })
        .filter(Boolean);

    renderSubstations();
}

// ─────────────────────────────────────────
// Rendering
// ─────────────────────────────────────────

function renderSubstations() {
    const pos = state.userPos;

    // Distanzen berechnen und sortieren
    if (pos) {
        state.substations.forEach(s => { s.distM = haversineM(pos, s); });
        state.substations.sort((a, b) => a.distM - b.distM);
    }

    clearSubstationMarkers();

    const top = state.substations.slice(0, DEFAULTS.MAX_RESULTS);

    top.forEach((s, i) => {
        const isTop = i === 0;
        const meta  = substationMeta(s.tags);
        const name  = substationDisplayName(s.tags);

        // Zugehörige Gebäude aus Admin-Daten
        const assigned = state.assignments.filter(
            a => a.substation_osm_type === s.osmType && String(a.substation_osm_id) === String(s.osmId)
        );
        const buildingList = assigned.length > 0
            ? `<br/><span style="color:#38bdf8;font-size:11px">🏠 ${assigned.map(a => esc(a.building_name)).join(', ')}</span>`
            : '';

        const voltageInfo = s.tags.voltage ? `<br/>⚡ ${esc(s.tags.voltage)} V` : '';
        const operatorInfo = s.tags.operator ? `<br/>🏢 ${esc(s.tags.operator)}` : '';
        const popupImageUrl = getOsmImageUrl(s.tags);
        const popupImageHtml = popupImageUrl
            ? `<img src="${popupImageUrl}" style="width:100%;max-height:110px;object-fit:cover;border-radius:4px;margin-top:5px;display:block" loading="lazy" onerror="this.remove()"/>`
            : '';
        const popup = `
            <b style="color:${meta.color}">${meta.icon} ${esc(name || meta.label)}</b><br/>
            <span style="font-size:12px;color:#8a8a9a">${meta.label}</span>
            ${voltageInfo}${operatorInfo}
            <br/>📏 ${formatDist(s.distM)}
            ${buildingList}
            ${popupImageHtml}
        `;

        const marker = L.marker([s.lat, s.lng], {
            icon: createSubstationIcon(s.tags, i + 1, isTop),
            zIndexOffset: isTop ? 500 : 0,
        })
            .addTo(state.map)
            .bindPopup(popup, { closeButton: false, maxWidth: 240 });

        marker.on('click', () => focusSubstation(s, i));
        state.markers.substations.push(marker);
    });

    renderList(top);

    if (state.showAllSubstations) renderAllSubstationMarkers();
}

function clearSubstationMarkers() {
    state.markers.substations.forEach(m => state.map.removeLayer(m));
    state.markers.substations = [];
}

function renderAllSubstationMarkers() {
    clearAllSubstationMarkers();
    const topIds = new Set(state.substations.slice(0, DEFAULTS.MAX_RESULTS).map(s => s.id));
    state.substations.forEach(s => {
        if (topIds.has(s.id)) return;
        const m = L.marker([s.lat, s.lng], { icon: ALL_SUBSTATION_ICON, zIndexOffset: -100 })
            .addTo(state.map);
        state.markers.allSubstations.push(m);
    });
}

function clearAllSubstationMarkers() {
    state.markers.allSubstations.forEach(m => state.map.removeLayer(m));
    state.markers.allSubstations = [];
}

function toggleAllSubstations() {
    state.showAllSubstations = !state.showAllSubstations;
    dom.btnToggleAll.classList.toggle('active', state.showAllSubstations);
    if (state.showAllSubstations) renderAllSubstationMarkers();
    else clearAllSubstationMarkers();
}

function toggleBasemap() {
    const useSwisstopo = !state.map.hasLayer(state.layers.swisstopo);
    if (useSwisstopo) {
        state.map.removeLayer(state.layers.osm);
        state.layers.swisstopo.addTo(state.map);
    } else {
        state.map.removeLayer(state.layers.swisstopo);
        state.layers.osm.addTo(state.map);
    }
    dom.btnBasemap.classList.toggle('active', useSwisstopo);
}

function focusSubstation(s, index) {
    state.map.flyTo([s.lat, s.lng], 18, { duration: 0.7 });

    // Listenelement hervorheben
    document.querySelectorAll('.substation-item').forEach(el => {
        el.classList.toggle('selected', el.dataset.id === s.id);
    });

    if (index < state.markers.substations.length) {
        state.markers.substations[index].openPopup();
    }
}

// ─────────────────────────────────────────
// Bottom-Sheet Liste
// ─────────────────────────────────────────

function renderList(substations) {
    if (substations.length === 0) {
        showEmptyState('Keine Trafostationen im Suchradius gefunden.');
        return;
    }

    dom.emptyState.hidden = true;
    dom.substationList.hidden = false;

    dom.substationList.innerHTML = substations.map((s, i) => {
        const meta = substationMeta(s.tags);
        const name = substationDisplayName(s.tags);
        const voltageStr = s.tags.voltage ? ` · ${s.tags.voltage} V` : '';
        const operatorStr = s.tags.operator ? `<br/><span class="hydrant-detail" style="color:var(--text-secondary)">${esc(s.tags.operator)}</span>` : '';
        const assigned = state.assignments.filter(
            a => a.substation_osm_type === s.osmType && String(a.substation_osm_id) === String(s.osmId)
        );
        const buildingStr = assigned.length > 0
            ? `<br/><span class="hydrant-detail" style="color:#38bdf8">🏠 ${assigned.map(a => esc(a.building_name)).join(', ')}</span>`
            : '';

        const imageUrl = getOsmImageUrl(s.tags);
        const imageHtml = imageUrl
            ? `<figure class="hydrant-photo"><img src="${imageUrl}" alt="" loading="lazy" onerror="this.parentElement.remove()"/></figure>`
            : '';

        return `<li class="hydrant-item${i === 0 ? ' hydrant-item--nearest' : ''}" data-id="${esc(s.id)}" data-index="${i}">
            <div class="hydrant-rank" style="background:${meta.color};color:#0f0f14">${i + 1}</div>
            <div class="hydrant-info">
                <div class="hydrant-name" style="color:${meta.color}">${meta.icon} ${esc(name || meta.label)}</div>
                <div class="hydrant-detail">${meta.label}${voltageStr}</div>
                ${operatorStr}${buildingStr}
            </div>
            <div class="hydrant-distance">${formatDist(s.distM)}</div>
            ${imageHtml}
        </li>`;
    }).join('');
}

function showEmptyState(msg) {
    dom.emptyState.textContent = msg;
    dom.emptyState.hidden = false;
    dom.substationList.hidden = true;
}

// ─────────────────────────────────────────
// Admin-Overlay – Gebäude-Zuordnungen
// ─────────────────────────────────────────

async function loadAssignments() {
    const token   = localStorage.getItem('hw_token');
    const expires = parseInt(localStorage.getItem('hw_expires') || '0', 10);
    if (!token || Date.now() > expires) return;

    try {
        const res = await fetch('/admin/substations_api.php?action=map_data', {
            headers: { 'Authorization': `Bearer ${token}` },
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!Array.isArray(data)) return;

        state.assignments = data;

        // Gebäude-Pins mit Koordinaten auf Karte zeichnen
        state.markers.buildings.forEach(m => state.map.removeLayer(m));
        state.markers.buildings = [];

        data.forEach(a => {
            if (!a.building_lat || !a.building_lng) return;
            const m = L.marker([a.building_lat, a.building_lng], {
                icon: BUILDING_ICON,
                zIndexOffset: 200,
            })
                .addTo(state.map)
                .bindPopup(
                    `<b style="color:#38bdf8">🏠 ${esc(a.building_name)}</b><br/>
                     ${a.building_address ? esc(a.building_address) + '<br/>' : ''}
                     <span style="font-size:11px;color:#8a8a9a">
                       ⚡ ${esc(a.substation_name || `${a.substation_osm_type}/${a.substation_osm_id}`)}
                     </span>`,
                    { closeButton: false, maxWidth: 220 }
                );
            state.markers.buildings.push(m);
        });

        // Liste neu rendern mit Gebäude-Infos
        renderSubstations();
    } catch (err) {
        console.warn('Gebäude-Zuordnungen konnten nicht geladen werden', err);
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
// Bottom-Sheet Drag
// ─────────────────────────────────────────

function expandSheet() {
    dom.bottomSheet.classList.remove('collapsed');
    setTimeout(() => state.map && state.map.invalidateSize(), 360);
}

function collapseSheet() {
    dom.bottomSheet.classList.add('collapsed');
    setTimeout(() => state.map && state.map.invalidateSize(), 360);
}

function toggleSheet() {
    dom.bottomSheet.classList.contains('collapsed') ? expandSheet() : collapseSheet();
}

function initSheetDrag() {
    let touchStartY = 0, touchStartTime = 0;

    dom.sheetHandle.addEventListener('click', toggleSheet);

    dom.sheetHandle.addEventListener('touchstart', (e) => {
        touchStartY = e.touches[0].clientY;
        touchStartTime = Date.now();
    }, { passive: true });

    dom.sheetHandle.addEventListener('touchend', (e) => {
        const deltaY = touchStartY - e.changedTouches[0].clientY;
        if (Math.abs(deltaY) < 20 || Date.now() - touchStartTime > 350) return;
        if (deltaY > 0) expandSheet(); else collapseSheet();
    }, { passive: true });

    const contentEl = document.getElementById('sheetContent');
    let contentTouchY = 0;
    contentEl.addEventListener('touchstart', e => { contentTouchY = e.touches[0].clientY; }, { passive: true });
    contentEl.addEventListener('touchend', e => {
        if (contentEl.scrollTop > 0) return;
        if (contentTouchY - e.changedTouches[0].clientY < -50) collapseSheet();
    }, { passive: true });
}

// ─────────────────────────────────────────
// Event-Listener
// ─────────────────────────────────────────

function initEventListeners() {
    dom.btnMyLocation.addEventListener('click', () => {
        if (state.userPos) {
            state.map.flyTo([state.userPos.lat, state.userPos.lng], 16, { duration: 0.8 });
        } else {
            startLocationWatch();
        }
    });

    dom.btnToggleAll.addEventListener('click', toggleAllSubstations);
    dom.btnBasemap.addEventListener('click', toggleBasemap);

    dom.btnRefresh.addEventListener('click', () => {
        // Cache für aktuelle Position löschen damit frische Daten geladen werden
        const pos = state.userPos;
        const radius = parseInt(dom.searchRadius.value, 10) || DEFAULTS.SEARCH_RADIUS_M;
        if (pos) {
            try { localStorage.removeItem(_cacheKey(pos, radius)); } catch { /* ignore */ }
        }
        fetchSubstations();
    });

    dom.searchRadius.addEventListener('change', fetchSubstations);

    // Klick auf Listenelement → Karte fokussieren
    dom.substationList.addEventListener('click', (e) => {
        const item = e.target.closest('.substation-item, .hydrant-item');
        if (!item) return;
        const index = parseInt(item.dataset.index, 10);
        if (isNaN(index)) return;
        const s = state.substations[index];
        if (s) focusSubstation(s, index);
    });
}

// ─────────────────────────────────────────
// Start
// ─────────────────────────────────────────

function init() {
    initMap();
    initSheetDrag();
    initEventListeners();
    startLocationWatch();
    loadAssignments();
}

document.addEventListener('DOMContentLoaded', init);
