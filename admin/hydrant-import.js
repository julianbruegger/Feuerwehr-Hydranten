/**
 * hydrant-import.js – Hydranten-Dateien im Browser einlesen
 *
 * Unterstützt:
 *  - CSV (Trennzeichen ; , oder Tab, Spaltennamen werden erkannt)
 *  - GeoJSON (Point / MultiPoint)
 *  - INTERLIS-Transfer (.xtf, z.B. SIA405_Wasser_2015 → Klasse "Hydrant")
 *
 * Koordinaten werden automatisch erkannt: WGS84, LV95 (EPSG:2056) oder
 * LV03 (EPSG:21781). LV95/LV03 werden mit den Näherungsformeln von swisstopo
 * umgerechnet (Genauigkeit ~1 m).
 *
 * Ergebnis: { items: [{ lat, lng, type, ref, address, notes, external_id }], skipped, warnings }
 */

'use strict';

(function (root) {

    // ── Koordinaten ────────────────────────────────────────────────────────

    /** LV95 (E/N in m) → WGS84 */
    function lv95ToWgs84(e, n) {
        const y = (e - 2600000) / 1e6;
        const x = (n - 1200000) / 1e6;
        const lng = 2.6779094 + 4.728982 * y + 0.791484 * y * x + 0.1306 * y * x * x - 0.0436 * y * y * y;
        const lat = 16.9023892 + 3.238272 * x - 0.270978 * y * y - 0.002528 * x * x
            - 0.0447 * y * y * x - 0.0140 * x * x * x;
        return { lat: lat * 100 / 36, lng: lng * 100 / 36 };
    }

    const inRange = (v, lo, hi) => v >= lo && v <= hi;

    /**
     * Wandelt ein Koordinatenpaar (beliebige Reihenfolge) in WGS84 um.
     * `hint` = 'lonlat' (GeoJSON) oder 'latlon' für WGS84-Werte.
     */
    function toWgs84(a, b, hint = 'lonlat') {
        if (!isFinite(a) || !isFinite(b)) return null;
        // LV95
        if (inRange(a, 2.4e6, 2.9e6) && inRange(b, 1.0e6, 1.4e6)) return lv95ToWgs84(a, b);
        if (inRange(b, 2.4e6, 2.9e6) && inRange(a, 1.0e6, 1.4e6)) return lv95ToWgs84(b, a);
        // LV03 (y = Ost ~600'000, x = Nord ~200'000)
        if (inRange(a, 4.0e5, 9.0e5) && inRange(b, 0.5e5, 3.5e5)) return lv95ToWgs84(a + 2e6, b + 1e6);
        if (inRange(b, 4.0e5, 9.0e5) && inRange(a, 0.5e5, 3.5e5)) return lv95ToWgs84(b + 2e6, a + 1e6);
        // WGS84
        let lat = hint === 'latlon' ? a : b;
        let lng = hint === 'latlon' ? b : a;
        // In der Schweiz: Breite ~45–48, Länge ~5–11 → offensichtlich vertauscht?
        if (inRange(lng, 40, 60) && inRange(lat, 0, 20)) [lat, lng] = [lng, lat];
        if (!inRange(lat, -90, 90) || !inRange(lng, -180, 180)) return null;
        return { lat, lng };
    }

    // ── Attribute ──────────────────────────────────────────────────────────

    function mapType(v) {
        const s = String(v || '').toLowerCase();
        if (!s) return 'other';
        if (/ober|über|ueber|pillar|säule|saeule/.test(s)) return 'pillar';
        if (/unter|underground/.test(s)) return 'underground';
        if (/wand|wall/.test(s)) return 'wall';
        if (/teich|weiher|pond|becken|löschwasser|loeschwasser|reservoir/.test(s)) return 'pond';
        return 'other';
    }

    /** Ausser Betrieb / aufgehoben → nicht importieren (wäre im Einsatz irreführend). */
    function isOutOfService(v) {
        return /aufgehoben|stillgelegt|ausser.?betrieb|außer.?betrieb|abgebrochen|entfernt|rückgebaut|rueckgebaut|out.?of.?service|removed/i
            .test(String(v || ''));
    }

    const norm = (k) => String(k).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]/g, '');

    const ALIASES = {
        lat:     ['lat', 'latitude', 'breite', 'breitengrad', 'wgs84lat', 'ywgs84'],
        lng:     ['lon', 'lng', 'long', 'longitude', 'laenge', 'lange', 'laengengrad', 'wgs84lon', 'xwgs84'],
        e:       ['e', 'east', 'easting', 'ost', 'rechtswert', 'ekoord', 'koorde', 'koordinatee', 'lv95e', 'c1', 'x', 'xkoord', 'xcoord'],
        n:       ['n', 'north', 'northing', 'nord', 'hochwert', 'nkoord', 'koordn', 'koordinaten', 'lv95n', 'c2', 'y', 'ykoord', 'ycoord'],
        ref:     ['ref', 'nr', 'nummer', 'namenummer', 'name', 'bezeichnung', 'hydrantnr', 'hydrantennr', 'hydrantennummer', 'hydrantnummer', 'objektnummer'],
        type:    ['type', 'typ', 'art', 'hydrantentyp', 'hydranttyp', 'hydrantart', 'bauart', 'fire_hydranttype', 'firehydranttype'],
        address: ['address', 'adresse', 'strasse', 'standort', 'lage', 'ort', 'addrfull'],
        notes:   ['notes', 'note', 'notiz', 'bemerkung', 'bemerkungen', 'kommentar', 'dimension', 'durchmesser'],
        status:  ['status', 'zustand', 'betriebsstatus'],
        id:      ['id', 'objectid', 'objid', 'oid', 'tid', 'uuid', 'fid', 'gid', 'objekt_id', 'objektid'],
    };

    function findKey(keys, field) {
        const normed = keys.map(norm);
        for (const alias of ALIASES[field]) {
            const i = normed.indexOf(alias);
            if (i >= 0) return keys[i];
        }
        return null;
    }

    function num(v) {
        if (typeof v === 'number') return v;
        const s = String(v ?? '').trim().replace(/['\s]/g, '').replace(',', '.');
        return s === '' ? NaN : Number(s);
    }

    /** Baut einen Import-Datensatz aus einem Attribut-Objekt (+ optionaler Geometrie). */
    function buildItem(props, coord, result) {
        const keys = Object.keys(props);
        const get = (field) => { const k = findKey(keys, field); return k ? props[k] : null; };

        let pos = coord;
        if (!pos) {
            const lat = num(get('lat')), lng = num(get('lng'));
            if (isFinite(lat) && isFinite(lng)) pos = toWgs84(lat, lng, 'latlon');
            else pos = toWgs84(num(get('e')), num(get('n')));
        }
        if (!pos) { result.skipped++; return; }

        const status = get('status');
        if (isOutOfService(status)) { result.outOfService++; return; }

        const notes = [get('notes'), status && !/in.?betrieb/i.test(status) ? `Status: ${status}` : null]
            .filter(Boolean).join(' · ');
        const id = get('id');

        result.items.push({
            lat: Math.round(pos.lat * 1e7) / 1e7,
            lng: Math.round(pos.lng * 1e7) / 1e7,
            type: mapType(get('type')),
            ref: get('ref') != null ? String(get('ref')).trim() || null : null,
            address: get('address') != null ? String(get('address')).trim() || null : null,
            notes: notes || null,
            external_id: id != null && String(id).trim() !== '' ? String(id).trim() : null,
        });
    }

    const newResult = (format) => ({ format, items: [], skipped: 0, outOfService: 0, warnings: [] });

    // ── CSV ────────────────────────────────────────────────────────────────

    function splitCsvLine(line, delim) {
        const out = [];
        let cur = '', q = false;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (q) {
                if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; }
                else if (c === '"') q = false;
                else cur += c;
            } else if (c === '"') q = true;
            else if (c === delim) { out.push(cur); cur = ''; }
            else cur += c;
        }
        out.push(cur);
        return out.map(s => s.trim());
    }

    function parseCsv(text) {
        const result = newResult('CSV');
        const lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(l => l.trim() !== '');
        if (lines.length < 2) { result.warnings.push('CSV enthält keine Datenzeilen'); return result; }
        const first = lines[0];
        const delim = [';', '\t', ','].sort((a, b) => first.split(b).length - first.split(a).length)[0];
        const header = splitCsvLine(first, delim);

        const hasLatLng = findKey(header, 'lat') && findKey(header, 'lng');
        const hasEN = findKey(header, 'e') && findKey(header, 'n');
        if (!hasLatLng && !hasEN) {
            result.warnings.push(`Keine Koordinaten-Spalten erkannt (gefunden: ${header.join(', ')}). ` +
                'Erwartet z.B. lat/lon oder E/N (LV95).');
            return result;
        }
        for (let i = 1; i < lines.length; i++) {
            const cells = splitCsvLine(lines[i], delim);
            const props = {};
            header.forEach((h, j) => { props[h] = cells[j] ?? ''; });
            buildItem(props, null, result);
        }
        return result;
    }

    // ── GeoJSON ────────────────────────────────────────────────────────────

    function parseGeoJson(text) {
        const result = newResult('GeoJSON');
        const data = JSON.parse(text);
        const features = data.type === 'FeatureCollection' ? data.features
            : data.type === 'Feature' ? [data] : [];
        features.forEach((f) => {
            const g = f && f.geometry;
            const props = Object.assign({}, f && f.properties);
            if (f && f.id != null && props.id == null) props.id = f.id;
            const points = !g ? [] : g.type === 'Point' ? [g.coordinates]
                : g.type === 'MultiPoint' ? g.coordinates : [];
            if (points.length === 0) { result.skipped++; return; }
            points.forEach((c, i) => {
                const p = points.length > 1 && props.id != null ? Object.assign({}, props, { id: `${props.id}_${i}` }) : props;
                buildItem(p, toWgs84(num(c[0]), num(c[1]), 'lonlat'), result);
            });
        });
        return result;
    }

    // ── INTERLIS XTF (2.3 und 2.4) ─────────────────────────────────────────

    function parseXtf(text) {
        const result = newResult('INTERLIS');
        const doc = new DOMParser().parseFromString(text, 'application/xml');
        if (doc.getElementsByTagName('parsererror').length) {
            result.warnings.push('XTF-Datei konnte nicht gelesen werden (ungültiges XML)');
            return result;
        }
        const local = (el) => (el.localName || el.nodeName).split(':').pop();
        const all = doc.getElementsByTagName('*');
        for (const el of all) {
            // 2.3: <SIA405_Wasser_2015_LV95.SIA405_Wasser.Hydrant TID="…">, 2.4: <ns:Hydrant ili:tid="…">
            if (!/(^|\.)Hydrant$/i.test(local(el))) continue;
            const props = {};
            for (const child of el.children) {
                if (child.children.length === 0) props[local(child)] = child.textContent.trim();
            }
            props.id = el.getAttribute('TID') || el.getAttribute('ili:tid') ||
                [...el.attributes].find(a => /tid$/i.test(a.name))?.value || null;

            // Geometrie: erstes C1/C2 (bzw. c1/c2) unterhalb des Objekts
            let c1 = null, c2 = null;
            for (const d of el.getElementsByTagName('*')) {
                const n = local(d).toLowerCase();
                if (n === 'c1' && c1 === null) c1 = num(d.textContent);
                if (n === 'c2' && c2 === null) c2 = num(d.textContent);
            }
            const coord = c1 !== null && c2 !== null ? toWgs84(c1, c2) : null;
            if (!coord) { result.skipped++; continue; }
            buildItem(props, coord, result);
        }
        if (result.items.length === 0 && result.skipped === 0) {
            result.warnings.push('Keine Objekte der Klasse "Hydrant" in der XTF-Datei gefunden');
        }
        return result;
    }

    // ── Einstieg ───────────────────────────────────────────────────────────

    function parseHydrantFile(name, text) {
        const ext = String(name).toLowerCase().split('.').pop();
        const trimmed = text.trimStart();
        if (ext === 'xtf' || ext === 'xml' || trimmed.startsWith('<')) return parseXtf(text);
        if (ext === 'geojson' || ext === 'json' || trimmed.startsWith('{')) return parseGeoJson(text);
        return parseCsv(text);
    }

    const api = { parseHydrantFile, parseCsv, parseGeoJson, parseXtf, toWgs84, lv95ToWgs84, mapType };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.HydrantImport = api;

})(typeof window !== 'undefined' ? window : globalThis);
