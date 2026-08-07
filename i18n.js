/**
 * i18n.js — Minimal, dependency-free DE/EN localisation for the
 * Hydrantennavigator public pages (landing, register, login).
 *
 * Usage in HTML:
 *   <span data-i18n="hero.title"></span>                 → textContent
 *   <span data-i18n-html="footer.disclaimer"></span>     → innerHTML (allows <a>, <br>)
 *   <input data-i18n-attr="placeholder:reg.namePh">      → sets an attribute
 *                (multiple attrs: "placeholder:key;aria-label:key2")
 *
 * Language switch:
 *   Any element with [data-lang-toggle] cycles DE⇄EN.
 *   Elements with [data-lang="de"] / [data-lang="en"] set that language.
 *   The chosen language is stored in localStorage under "hw_lang".
 */
(function () {
  'use strict';

  const STORE_KEY = 'hw_lang';

  const DICT = {
    de: {
      // ── Navigation / header ──
      'nav.openMap': 'Karte öffnen',
      'nav.login': 'Anmelden',
      'nav.register': 'Feuerwehr registrieren',
      'nav.toMap': 'Zur Karte',
      'meta.title': 'Hydrantennavigator – Hydranten finden & Schläuche berechnen',
      'meta.description': 'Hydrantennavigator – den nächsten Hydranten finden und die Schlauchlänge berechnen.',

      // ── Hero ──
      'hero.badge': 'Für Feuerwehren',
      'hero.title': 'Den nächsten Hydranten finden. In Sekunden.',
      'hero.subtitle': 'Der Hydrantennavigator zeigt Einsatzkräften im Feld den nächstgelegenen Hydranten und berechnet die benötigte Anzahl Schläuche – live, mobil und offline-tauglich.',
      'hero.ctaMap': 'Karte öffnen',
      'hero.ctaRegister': 'Feuerwehr registrieren',
      'hero.note': 'Kein Login nötig, um die Karte zu nutzen.',

      // ── Features ──
      'feat.heading': 'Funktionen',
      'feat.sub': 'Alles, was du für die schnelle Wasserversorgung im Einsatz brauchst.',
      'feat.map.t': 'Live-Hydrantenkarte',
      'feat.map.d': 'Zeigt Hydranten in der Umgebung auf einer Vollbildkarte – live aus OpenStreetMap via Overpass-API.',
      'feat.modes.t': 'Zwei Suchmodi',
      'feat.modes.d': 'Suche ab deinem eigenen GPS-Standort oder ab einer manuell gesetzten Brandposition.',
      'feat.hose.t': 'Schlauchrechner',
      'feat.hose.d': 'Berechnet die benötigten Schläuche zum nächsten Hydranten über echtes Strassen-Routing (OSRM), mit Luftlinie als Fallback.',
      'feat.config.t': 'Konfigurierbar',
      'feat.config.d': 'Schlauchlänge (z. B. 20 m / 25 m) und Suchradius bequem über ein Menü anpassbar.',
      'feat.pwa.t': 'Mobil & PWA',
      'feat.pwa.d': 'Optimiert für das Handy im Feld – installierbar auf dem Startbildschirm.',
      'feat.siri.t': 'Apple Shortcut & Siri',
      'feat.siri.d': 'Ein REST-Endpunkt liefert eine sprechfertige Zusammenfassung – frag Siri freihändig nach der Schlauchzahl.',
      'feat.power.t': 'Stromversorgungskarte',
      'feat.power.d': 'Separate Ansicht für Trafostationen und Unterwerke – ideal zur Koordination mit dem Netzbetreiber.',
      'feat.admin.t': 'Admin-Bereich',
      'feat.admin.d': 'Pro Feuerwehr: sensible Objekte, Wassertransportpläne, Gebäude-Zuordnung und Magic-Links für Fahrzeug-Tablets.',

      // ── How it works ──
      'how.heading': 'So funktioniert es',
      'how.s1.t': '1 · Standort oder Brandposition',
      'how.s1.d': 'Standardmässig wird um deinen GPS-Standort gesucht. Tippe auf das Flammen-Symbol, um stattdessen den genauen Brandort auf der Karte zu markieren.',
      'how.s2.t': '2 · Nächster Hydrant',
      'how.s2.d': 'Alle gefundenen Hydranten erscheinen nach Entfernung sortiert – der nächste wird rot hervorgehoben.',
      'how.s3.t': '3 · Schläuche berechnen',
      'how.s3.d': 'Die App rechnet Fahrstrecke bzw. Luftlinie in die benötigte Anzahl Schläuche um – anpassbar an eure Schlauchlänge.',

      // ── Departments / onboarding ──
      'depts.heading': 'Für deine Feuerwehr',
      'depts.sub': 'Verwalte eigene Einsatzdaten – sensible Objekte, Wassertransportpläne und mehr.',
      'depts.create.t': 'Feuerwehr erstellen',
      'depts.create.d': 'Registriere deine Feuerwehr mit E-Mail und Passwort. Nach der Bestätigung per E-Mail erhältst du Zugriff auf den Admin-Bereich.',
      'depts.create.cta': 'Jetzt registrieren',
      'depts.invite.t': 'Eingeladen werden',
      'depts.invite.d': 'Du gehörst schon zu einer registrierten Feuerwehr? Bitte eine Administratorin oder einen Administrator um eine Einladung – du erhältst per E-Mail einen Zugangslink.',
      'depts.invite.cta': 'Anmelden',

      // ── Footer ──
      'footer.disclaimer': 'Hydranten- und Infrastrukturdaten stammen von <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>-Mitwirkenden und <a href="https://www.swisstopo.admin.ch" target="_blank" rel="noopener">swisstopo</a>. Entfernungen und Schlauchzahlen sind Schätzwerte – immer vor Ort prüfen. Kein offizielles Einsatzmittel.',
      'footer.github': 'Auf GitHub ansehen',

      // ── Register page ──
      'reg.title': 'Feuerwehr registrieren',
      'reg.sub': 'Erstelle ein Konto für deine Feuerwehr',
      'reg.name': 'Name der Feuerwehr',
      'reg.namePh': 'z. B. FW Luzern',
      'reg.email': 'E-Mail',
      'reg.emailPh': 'admin@feuerwehr.ch',
      'reg.password': 'Passwort',
      'reg.passwordPh': 'Mindestens 8 Zeichen',
      'reg.submit': 'Registrieren',
      'reg.submitting': 'Wird gesendet…',
      'reg.haveAccount': 'Bereits registriert?',
      'reg.loginLink': 'Anmelden',
      'reg.backMap': '← Zur Karte',
      'reg.successTitle': 'Fast geschafft!',
      'reg.successBody': 'Wir haben dir eine Bestätigungs-E-Mail geschickt. Öffne den Link darin, um deine Feuerwehr zu aktivieren.',
      'reg.errGeneric': 'Registrierung fehlgeschlagen. Bitte erneut versuchen.',
      'reg.errFields': 'Bitte alle Felder ausfüllen.',
      'reg.errEmail': 'Bitte eine gültige E-Mail-Adresse angeben.',
      'reg.errPassword': 'Das Passwort muss mindestens 8 Zeichen lang sein.',
      'reg.devLink': 'Entwicklungsmodus – Bestätigungslink:',

      // ── Login page (progressive enhancement of login.php) ──
      'login.title': 'Feuerwehr-Anmeldung',
      'login.sub': 'Nur für autorisiertes Personal',
      'login.dept': 'Feuerwehr',
      'login.password': 'Passwort',
      'login.submit': 'Anmelden',
      'login.submitting': 'Anmelden…',
      'login.noAccount': 'Noch keine Feuerwehr?',
      'login.registerLink': 'Registrieren',
      'login.backMap': '← Zur Karte',
    },

    en: {
      // ── Navigation / header ──
      'nav.openMap': 'Open map',
      'nav.login': 'Log in',
      'nav.register': 'Register a department',
      'nav.toMap': 'To the map',
      'meta.title': 'Hydrant Navigator – Find hydrants & calculate hoses',
      'meta.description': 'Hydrant Navigator – find the nearest fire hydrant and calculate the hose length.',

      // ── Hero ──
      'hero.badge': 'For fire brigades',
      'hero.title': 'Find the nearest hydrant. In seconds.',
      'hero.subtitle': 'Hydrant Navigator shows crews in the field the closest fire hydrant and calculates how many hose sections are needed — live, mobile and offline-capable.',
      'hero.ctaMap': 'Open map',
      'hero.ctaRegister': 'Register a department',
      'hero.note': 'No login required to use the map.',

      // ── Features ──
      'feat.heading': 'Features',
      'feat.sub': 'Everything you need for fast water supply during an operation.',
      'feat.map.t': 'Live hydrant map',
      'feat.map.d': 'Shows nearby fire hydrants on a full-screen map — sourced live from OpenStreetMap via the Overpass API.',
      'feat.modes.t': 'Two search modes',
      'feat.modes.d': 'Search from your own GPS location or from a manually set fire position.',
      'feat.hose.t': 'Hose calculator',
      'feat.hose.d': 'Computes the hose sections to the nearest hydrant using real road routing (OSRM), with straight-line distance as a fallback.',
      'feat.config.t': 'Configurable',
      'feat.config.d': 'Adjust hose length (e.g. 20 m / 25 m) and search radius easily from a menu.',
      'feat.pwa.t': 'Mobile & PWA',
      'feat.pwa.d': 'Optimised for a phone in the field — installable to the home screen.',
      'feat.siri.t': 'Apple Shortcut & Siri',
      'feat.siri.d': 'A REST endpoint returns a ready-to-speak summary — ask Siri for the number of hoses hands-free.',
      'feat.power.t': 'Power-supply map',
      'feat.power.d': 'A separate view for transformer stations and substations — ideal for coordinating with the grid operator.',
      'feat.admin.t': 'Admin panel',
      'feat.admin.d': 'Per department: sensitive objects, water-transport plans, building mapping and magic links for vehicle tablets.',

      // ── How it works ──
      'how.heading': 'How it works',
      'how.s1.t': '1 · Location or fire position',
      'how.s1.d': 'By default the app searches around your GPS location. Tap the flame icon to mark the exact fire location on the map instead.',
      'how.s2.t': '2 · Nearest hydrant',
      'how.s2.d': 'All hydrants found appear sorted by distance — the closest one is highlighted in red.',
      'how.s3.t': '3 · Calculate hoses',
      'how.s3.d': 'The app converts road distance (or straight line) into the number of hose sections needed — adjustable to your hose length.',

      // ── Departments / onboarding ──
      'depts.heading': 'For your fire department',
      'depts.sub': 'Manage your own operational data — sensitive objects, water-transport plans and more.',
      'depts.create.t': 'Create a department',
      'depts.create.d': 'Register your fire department with an email and password. After confirming by email you get access to the admin panel.',
      'depts.create.cta': 'Register now',
      'depts.invite.t': 'Get invited',
      'depts.invite.d': 'Already part of a registered department? Ask an administrator for an invitation — you will receive an access link by email.',
      'depts.invite.cta': 'Log in',

      // ── Footer ──
      'footer.disclaimer': 'Hydrant and infrastructure data comes from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors and <a href="https://www.swisstopo.admin.ch" target="_blank" rel="noopener">swisstopo</a>. Distances and hose counts are estimates — always verify on-site. Not an official emergency-response tool.',
      'footer.github': 'View on GitHub',

      // ── Register page ──
      'reg.title': 'Register a department',
      'reg.sub': 'Create an account for your fire brigade',
      'reg.name': 'Department name',
      'reg.namePh': 'e.g. FW Luzern',
      'reg.email': 'Email',
      'reg.emailPh': 'admin@firebrigade.org',
      'reg.password': 'Password',
      'reg.passwordPh': 'At least 8 characters',
      'reg.submit': 'Register',
      'reg.submitting': 'Sending…',
      'reg.haveAccount': 'Already registered?',
      'reg.loginLink': 'Log in',
      'reg.backMap': '← Back to map',
      'reg.successTitle': 'Almost done!',
      'reg.successBody': 'We have sent you a confirmation email. Open the link inside it to activate your department.',
      'reg.errGeneric': 'Registration failed. Please try again.',
      'reg.errFields': 'Please fill in all fields.',
      'reg.errEmail': 'Please enter a valid email address.',
      'reg.errPassword': 'The password must be at least 8 characters long.',
      'reg.devLink': 'Development mode – confirmation link:',

      // ── Login page ──
      'login.title': 'Fire brigade login',
      'login.sub': 'Authorised personnel only',
      'login.dept': 'Fire department',
      'login.password': 'Password',
      'login.submit': 'Log in',
      'login.submitting': 'Logging in…',
      'login.noAccount': 'No department yet?',
      'login.registerLink': 'Register',
      'login.backMap': '← Back to map',
    },
  };

  function detectLang() {
    const stored = localStorage.getItem(STORE_KEY);
    if (stored === 'de' || stored === 'en') return stored;
    const nav = (navigator.language || 'de').toLowerCase();
    return nav.startsWith('de') ? 'de' : 'en';
  }

  function t(key, lang) {
    lang = lang || currentLang;
    const table = DICT[lang] || DICT.de;
    return table[key] != null ? table[key] : (DICT.de[key] != null ? DICT.de[key] : key);
  }

  function applyAll(root) {
    root = root || document;

    root.querySelectorAll('[data-i18n]').forEach((el) => {
      el.textContent = t(el.getAttribute('data-i18n'));
    });

    root.querySelectorAll('[data-i18n-html]').forEach((el) => {
      el.innerHTML = t(el.getAttribute('data-i18n-html'));
    });

    root.querySelectorAll('[data-i18n-attr]').forEach((el) => {
      el.getAttribute('data-i18n-attr').split(';').forEach((pair) => {
        const [attr, key] = pair.split(':').map((s) => s.trim());
        if (attr && key) el.setAttribute(attr, t(key));
      });
    });

    // Reflect the active language on toggle buttons and <html>.
    document.documentElement.setAttribute('lang', currentLang);
    root.querySelectorAll('[data-lang-label]').forEach((el) => {
      el.textContent = currentLang === 'de' ? 'EN' : 'DE';
    });
    root.querySelectorAll('[data-lang]').forEach((el) => {
      el.setAttribute('aria-pressed', el.getAttribute('data-lang') === currentLang ? 'true' : 'false');
    });
  }

  function setLang(lang) {
    if (lang !== 'de' && lang !== 'en') return;
    currentLang = lang;
    localStorage.setItem(STORE_KEY, lang);
    applyAll(document);
    document.dispatchEvent(new CustomEvent('i18n:changed', { detail: { lang } }));
  }

  function toggleLang() {
    setLang(currentLang === 'de' ? 'en' : 'de');
  }

  let currentLang = detectLang();

  function wire() {
    document.querySelectorAll('[data-lang-toggle]').forEach((el) => {
      el.addEventListener('click', (e) => { e.preventDefault(); toggleLang(); });
    });
    document.querySelectorAll('[data-lang]').forEach((el) => {
      el.addEventListener('click', (e) => { e.preventDefault(); setLang(el.getAttribute('data-lang')); });
    });
    applyAll(document);
  }

  // Public API
  window.I18N = { t, setLang, toggleLang, applyAll, get lang() { return currentLang; } };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
