const COLORS = { normal: '#2f7d6d', watch: '#e8a33d', warning: '#e06c2f', severe: '#b3261e', pending: '#888888' };
const LEVELS = ['normal', 'watch', 'warning', 'severe'];
const LEAFLET_JS = 'assets/vendor/leaflet/leaflet.js'; // served from this site, not a third-party CDN
const LEAFLET_CSS = 'assets/vendor/leaflet/leaflet.css';
const PDFJS = 'assets/vendor/pdfjs/pdf.min.js', PDFJS_WORKER = 'assets/vendor/pdfjs/pdf.worker.min.js';
// Words that change with the language. (The "Why?" reasons and server warnings stay in English.)
const LABELS = {
  en: { normal: 'Normal', watch: 'Watch', warning: 'Warning', severe: 'Severe', pending: 'Pending', drought: 'Drought', flood: 'Flood', none: '',
        why: 'Why?', history: 'See history', emergency: 'Emergency', updated: 'Last assessed: ', never: 'No assessment has been run yet.',
        noNotes: 'No announcements yet.', all: 'All of Laguna', other: 'Filipino', showMap: 'Show map',
        mapFail: 'The map could not load. The list of municipalities still works.', histFail: 'Could not load the history.',
        noHistory: 'No history yet.', heroTitle: 'Laguna climate early warning', heroSub: 'See local forecasts, climate risks, and official advisories for Laguna.',
        townLabel: 'Municipality', riskHeading: 'Climate risk', highest: 'Highest risk first', alphabetical: 'Alphabetical',
        pickTown: 'Choose your municipality', ofTowns: 'municipalities in Laguna today', pickHint: 'Choose your municipality above, or tap one in the list below.',
        notesH: 'Announcements', pagasaH: 'PAGASA status', townsH: 'All municipalities', mapH: 'Laguna weather map', search: 'Search a municipality',
        allLevels: 'All', noMatch: 'No municipality matches your search.', tapHint: 'Tap a municipality to see its details.',
        rainfall: 'Rainfall', issued: 'issued', noAdvisory: 'No PAGASA status has been entered yet.', readPdf: 'Read', downloadPdf: 'Download', noDocs: 'No PAGASA PDF has been published yet.',
        pdfH: 'PAGASA documents', pdfLoading: 'Loading the PDF…', pdfPage: 'Page ', pdfOf: ' of ', pdfFail: 'This PDF could not be shown here. Use the buttons to open or download it.', openTab: 'Open in a new tab',
        close: 'Close', forYou: 'For your town', ctaTitle: 'Get SMS alerts for your town', ctaText: 'Create a free account. We text you when your town’s risk changes and when your LGU posts an announcement. No codes, no email confirmation.',
        register: 'Create account', login: 'Log in', account: 'My account', logout: 'Log out', hi: 'Hi, ', ctaOn: 'SMS alerts are on for ', ctaOnText: 'You get risk alerts and announcements for your municipality. Change this in your account.', ctaOff: 'SMS alerts are off',
        ctaOffText: 'Turn them on in your account to get texts for your municipality.', welcome: 'Your account is ready. Alerts are enabled for ', netFail: 'Something went wrong. Please check your connection and try again.', loadFail: 'Could not load data. Is the server running?' },
  fil: { normal: 'Normal', watch: 'Bantay', warning: 'Babala', severe: 'Matindi', pending: 'Hindi pa nasusuri', drought: 'Tagtuyot', flood: 'Baha', none: '',
         why: 'Bakit?', history: 'Tingnan ang kasaysayan', emergency: 'Emergency', updated: 'Huling pagsusuri: ', never: 'Wala pang pagsusuri.',
         noNotes: 'Wala pang anunsyo.', all: 'Buong Laguna', other: 'English', showMap: 'Ipakita ang mapa',
         mapFail: 'Hindi na-load ang mapa. Gumagana pa rin ang listahan ng munisipalidad.', histFail: 'Hindi na-load ang kasaysayan.',
         noHistory: 'Wala pang kasaysayan.', heroTitle: 'Maagang babala sa klima ng Laguna', heroSub: 'Tingnan ang lokal na panahon, panganib sa klima at mga abiso sa Laguna.',
         townLabel: 'Munisipalidad', riskHeading: 'Panganib sa klima', highest: 'Pinakamataas na panganib muna', alphabetical: 'Ayon sa alpabeto',
         pickTown: 'Piliin ang iyong munisipalidad', ofTowns: 'munisipalidad sa Laguna ngayon', pickHint: 'Piliin ang iyong munisipalidad sa itaas, o pumili sa listahan sa ibaba.',
         notesH: 'Mga anunsyo', pagasaH: 'Katayuan ng PAGASA', townsH: 'Lahat ng munisipalidad', mapH: 'Mapa ng panahon sa Laguna', search: 'Maghanap ng munisipalidad',
         allLevels: 'Lahat', noMatch: 'Walang munisipalidad na tugma sa hinanap mo.', tapHint: 'Pindutin ang munisipalidad para sa detalye.',
         rainfall: 'Ulan', issued: 'inilabas', noAdvisory: 'Wala pang naipasok na katayuan ng PAGASA.', readPdf: 'Basahin', downloadPdf: 'I-download', noDocs: 'Wala pang nailathalang PDF ng PAGASA.',
         pdfH: 'Mga dokumento ng PAGASA', pdfLoading: 'Nilo-load ang PDF…', pdfPage: 'Pahina ', pdfOf: ' ng ', pdfFail: 'Hindi maipakita rito ang PDF. Gamitin ang mga button para buksan o i-download.', openTab: 'Buksan sa bagong tab',
         close: 'Isara', forYou: 'Para sa bayan mo', ctaTitle: 'Kumuha ng SMS alert para sa bayan mo', ctaText: 'Gumawa ng libreng account. Itetext ka namin kapag nagbago ang panganib sa bayan mo at kapag may anunsyo ang LGU. Walang code, walang email confirmation.',
         register: 'Gumawa ng account', login: 'Mag-log in', account: 'Aking account', logout: 'Mag-log out', hi: 'Kumusta, ', ctaOn: 'Bukas ang SMS alert para sa ', ctaOnText: 'Makakatanggap ka ng alerto at anunsyo para sa iyong munisipalidad. Mababago mo ito sa account mo.', ctaOff: 'Sarado ang SMS alert',
         ctaOffText: 'Buksan ito sa account mo para makatanggap ng text para sa munisipalidad mo.', welcome: 'Handa na ang account mo. Nakabukas ang mga alerto para sa ', netFail: 'May nangyaring mali. Pakisuri ang koneksyon at subukan ulit.', loadFail: 'Hindi ma-load ang datos. Gumagana ba ang server?' },
};

const $ = (sel) => document.querySelector(sel);
let lang = 'en', lastData = null, me = null, meLoaded = false, levelFilter = '', query = '';
try { lang = localStorage.getItem('bk-lang') === 'fil' ? 'fil' : 'en'; } catch (e) { /* storage blocked: stay English */ }
const dashboardParams = new URLSearchParams(location.search);
const isBriefing = dashboardParams.get('brief') === '1';
if (isBriefing && ['en', 'fil'].includes(dashboardParams.get('lang'))) lang = dashboardParams.get('lang');
const t = (key) => (LABELS[lang][key] !== undefined ? LABELS[lang][key] : key);

// Small helper that builds elements with textContent, so server text can never inject HTML.
function el(tag, props = {}, ...kids) {
  const node = document.createElement(tag);
  Object.entries(props).forEach(([k, v]) => { if (k === 'role' || k.startsWith('aria-')) node.setAttribute(k, v); else node[k] = v; });
  kids.forEach((k) => node.append(k));
  return node;
}

function applyStaticText() {
  $('#hero-title').textContent = t('heroTitle');
  $('#hero-sub').textContent = t('heroSub');
  $('#h-notes').textContent = t('notesH'); $('#h-pagasa').textContent = t('pagasaH'); $('#h-towns').textContent = t('townsH'); $('#h-map').textContent = t('mapH');
  $('#q').placeholder = t('search'); $('#q').setAttribute('aria-label', t('search'));
  $('#town').options[0].textContent = t('pickTown');
  $('#town-label').textContent = t('townLabel');
  $('#town').setAttribute('aria-label', t('townLabel'));
  $('#sort-towns').setAttribute('aria-label', lang === 'fil' ? 'Ayusin ang listahan' : 'Sort municipalities');
  $('#sort-towns').options[0].textContent = t('alphabetical');
  $('#sort-towns').options[1].textContent = t('highest');
  $('#map-btn').textContent = t('showMap');
  const links = lang === 'fil' ? ['Panahon', 'Mapa ng panahon', 'Paghambingin ang mga bayan', 'Mga abiso'] :
    ['Weather', 'Weather map', 'Compare towns', 'Advisories'];
  ['#jump-weather', '#jump-map', '#jump-compare', '#jump-updates'].forEach((id, i) => { $(id).textContent = links[i]; });
}

/* ---------- loading the dashboard ---------- */
let riskLoad = 0;
async function load() {
  const sequence = ++riskLoad;
  try {
    const res = await fetch('api/risk.php?lang=' + lang);
    if (!res.ok) throw new Error('Risk data unavailable');
    const data = await res.json();
    if (sequence !== riskLoad) return;
    $('#warn').hidden = !data.warning;
    $('#warn').textContent = data.warning || '';
    renderAdvisory(data.advisory);
    fillTowns(data.rows);
    lastData = data;
    renderMy();
    renderChips(data.rows);
    renderTowns();
    $('#updated').textContent = data.updated ? t('updated') + data.updated : t('never');
    if (!isBriefing) {
      try { setupMap(data.rows); } catch (err) { $('#map-msg').textContent = t('mapFail'); }
    }
  } catch (err) {
    if (sequence !== riskLoad) return;
    $('#advisory').textContent = t('loadFail');
    if (!lastData) {
      $('#mycard').textContent = t('loadFail');
      document.dispatchEvent(new CustomEvent('bk:weather-town', { detail: null }));
      document.dispatchEvent(new CustomEvent('bk:briefing-unavailable'));
    }
  }
  if (!isBriefing) { loadNotes(); loadDocs(); }
}

/* ---------- who is logged in ---------- */
async function loadMe() {
  try { me = (await (await fetch('api/me.php')).json()).user; } catch (err) { me = null; }
  meLoaded = true;
  const nav = $('#nav-auth');
  nav.replaceChildren();
  if (me) {
    nav.append(el('span', { className: 'hello', textContent: t('hi') + me.name }), el('a', { href: 'account.php', textContent: t('account') }), el('a', { href: 'logout.php', textContent: t('logout') }));
    if (!$('#town').value && lastData) { $('#town').value = preferredTown(); renderMy(); loadNotes(); }
  } else {
    nav.append(el('a', { href: 'login.php', textContent: t('login') }),
      el('a', { id: 'nav-register', href: registrationLink(), className: 'solid', textContent: t('register') }));
  }
  renderCta();
  showWelcome();
}

function showWelcome() {
  const q = new URLSearchParams(location.search);
  if (!me || !q.has('welcome')) return;
  const box = $('#welcome');
  box.textContent = me.sms_optin ? t('welcome') + me.town + '.' :
    (lang === 'fil' ? 'Handa na ang account mo. Maaari mong buksan ang mga alerto sa iyong account.' : 'Your account is ready. You can turn on alerts in My account.');
  box.hidden = false;
  setTown(me.municipality_id);
  history.replaceState(null, '', location.pathname);
}

function savedTown() { try { return localStorage.getItem('bk-town') || ''; } catch (e) { return ''; } }
function preferredTown() { return dashboardParams.get('town') || savedTown() || String(me?.municipality_id || ''); }
function setTown(id) { try { if (!isBriefing) localStorage.setItem('bk-town', String(id)); } catch (e) { /* ignore */ } $('#town').value = String(id); }

function renderCta() {
  if (!meLoaded || isBriefing) return;
  const box = $('#cta');
  box.replaceChildren();
  if (me) {
    box.hidden = false;
    box.append(el('div', {}, el('h2', { textContent: me.sms_optin ? t('ctaOn') + me.town : t('ctaOff') }),
      el('p', { className: 'small', textContent: t(me.sms_optin ? 'ctaOnText' : 'ctaOffText') })),
      el('a', { className: 'btn ghost', href: 'account.php', textContent: t('account') }));
  } else {
    box.hidden = false;
    box.append(el('div', {}, el('h2', { textContent: t('ctaTitle') }), el('p', { className: 'small', textContent: t('ctaText') })),
      el('span', { className: 'row' }, el('a', { className: 'btn', href: registrationLink(), textContent: t('register') }), el('a', { className: 'btn ghost', href: 'login.php', textContent: t('login') })));
  }
}

function registrationLink() {
  const town = $('#town').value;
  return 'register.php' + (town ? '?town=' + encodeURIComponent(town) : '');
}

/* ---------- the gauge and the "my town" card ---------- */
function gauge(level) {
  return el('div', { className: 'gauge' + (LEVELS.includes(level) ? '' : ' empty'), role: 'img', 'aria-label': t(level) },
    ...LEVELS.map((l) => el('div', { className: 'seg s-' + l + (l === level ? ' on' : '') }, el('i'), el('span', { textContent: t(l) }))));
}

function renderMy() {
  const box = $('#mycard');
  const id = Number($('#town').value);
  const r = lastData && lastData.rows.find((x) => x.id === id);
  if (!lastData) { box.hidden = true; return; }
  const signUp = $('#nav-register');
  if (signUp) signUp.href = registrationLink();
  renderCta();
  box.hidden = false;
  const weatherTown = r || lastData.rows.find((town) => town.name === 'Santa Cruz');
  document.dispatchEvent(new CustomEvent('bk:weather-town', { detail: weatherTown }));
  document.dispatchEvent(new CustomEvent('bk:insights', { detail: { town: weatherTown, rows: lastData.rows, advisory: lastData.advisory, hotline: lastData.hotline } }));
  if (map) refreshMapSelection();
  const heading = el('p', { className: 'section-label', textContent: t('riskHeading') });
  if (!r) { // no town chosen yet: show Laguna as a whole
    const counts = {};
    lastData.rows.forEach((x) => { counts[x.level] = (counts[x.level] || 0) + 1; });
    const total = lastData.rows.length;
    const levels = [...LEVELS, 'pending'];
    box.className = 'card mycard';
    box.replaceChildren(
      heading,
      el('h2', { textContent: lastData.province || 'Laguna' }),
      el('p', { className: 'small', textContent: total + ' ' + t('ofTowns') }),
      el('div', { className: 'mix', 'aria-hidden': 'true' },
        ...levels.filter((l) => counts[l]).map((l) => el('span', { style: 'width:' + (counts[l] / total * 100) + '%;background:' + COLORS[l] }))),
      el('div', { className: 'risk-counts' }, ...levels.map((l) => el('button', {
        type: 'button', className: 'risk-count', disabled: !counts[l],
        onclick: () => { filterTowns(l); $('#municipalities').focus(); },
      }, el('strong', { textContent: counts[l] || 0 }), el('span', { textContent: t(l) })))));
    return;
  }
  box.className = 'card mycard lv-' + r.level;
  const why = r.reasons.length ? el('details', {}, el('summary', { textContent: t('why') }), el('ul', {}, ...r.reasons.map((x) => el('li', { textContent: x })))) : '';
  box.replaceChildren(
    heading,
    el('h2', { textContent: r.name }),
    el('div', { className: 'big-level' }, el('span', { className: 'level-word ' + (LEVELS.includes(r.level) ? 'lv-' + r.level + '-t' : ''), textContent: t(r.level) }),
      r.hazard !== 'none' ? el('span', { className: 'hazard', textContent: t(r.hazard) }) : ''),
    gauge(r.level),
    el('p', { className: 'advice', textContent: r.advice }), why, contactLine(r, lastData.hotline),
    el('button', { type: 'button', className: 'link-btn', textContent: t('history'), onclick: (ev) => openHistory(r, ev.target) }));
}

$('#town').addEventListener('change', () => { setTown($('#town').value || ''); renderMy(); renderTowns(); if (!isBriefing) loadNotes(); });

/* ---------- announcements ---------- */
let notesLoad = 0;
async function loadNotes() {
  const sequence = ++notesLoad;
  const box = $('#notes');
  try {
    const town = Number($('#town').value) || 0;
    const data = await (await fetch('api/announcements.php' + (town ? '?town=' + town : ''))).json();
    if (sequence !== notesLoad) return;
    box.replaceChildren();
    if (!data.announcements.length) { box.append(el('p', { className: 'small', textContent: t('noNotes') })); return; }
    data.announcements.forEach((a) => box.append(el('div', { className: 'note' + (town && a.municipality_id === town ? ' mine' : '') },
      el('p', { className: 'small', textContent: (a.town || t('all')) + ' · ' + a.created_at }),
      el('h3', { textContent: a.title }),
      el('p', { textContent: a.body }))));
  } catch (err) {
    if (sequence === notesLoad) box.textContent = t('netFail');
  }
}

/* ---------- PAGASA status and readable PDFs ---------- */
function renderAdvisory(a) {
  const box = $('#advisory');
  box.replaceChildren();
  if (!a) { box.append(el('p', { className: 'small', textContent: t('noAdvisory') })); return; }
  const when = new Date(a.month).toLocaleDateString(lang === 'fil' ? 'fil-PH' : 'en-PH', { month: 'long', year: 'numeric' });
  box.append(el('p', { className: 'statusline' }, el('strong', { textContent: a.alert_status }),
    el('span', { className: 'small', textContent: t('rainfall') + ': ' + a.rainfall_status.replace(/_/g, ' ') + ' · ' + when + (a.issued ? ' · ' + t('issued') + ' ' + a.issued : '') })));
  if (a.notes) box.append(el('p', { className: 'small', textContent: a.notes }));
}

async function loadDocs() {
  const box = $('#docs');
  try {
    const data = await (await fetch('api/documents.php')).json();
    box.replaceChildren();
    if (!data.documents.length) { box.append(el('p', { className: 'small', textContent: t('noDocs') })); return; }
    data.documents.forEach((d) => box.append(el('div', { className: 'doc' },
      el('span', {}, el('b', { textContent: d.title }), el('span', { className: 'small', textContent: [d.month, d.size].filter(Boolean).join(' · ') })),
      el('span', { className: 'row' },
        el('button', { type: 'button', textContent: t('readPdf'), onclick: (ev) => openReader(d, ev.target) }),
        el('a', { className: 'btn ghost', href: d.download, textContent: t('downloadPdf') })))));
  } catch (err) { /* leave it empty */ }
}

// Pages are drawn with the bundled PDF.js, so the PDF reads the same on every phone (many phone browsers cannot show a PDF inside a page).
let pdfjsPromise = null;
function loadPdfjs() {
  if (window.pdfjsLib) return Promise.resolve();
  if (!pdfjsPromise) pdfjsPromise = loadScript(PDFJS).then(() => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; });
  return pdfjsPromise;
}

async function openReader(d, trigger) {
  const pages = el('div', { className: 'reader' });
  const status = el('span', { className: 'small', textContent: t('pdfLoading') });
  const bar = el('div', { className: 'reader-bar' }, status,
    el('a', { className: 'btn ghost', href: d.url, target: '_blank', rel: 'noopener', textContent: t('openTab') }),
    el('a', { className: 'btn ghost', href: d.download, textContent: t('downloadPdf') }));
  openModal(d.title, el('div', {}, bar, pages), trigger, true);
  try {
    await loadPdfjs();
    const pdf = await window.pdfjsLib.getDocument({ url: d.url, isEvalSupported: false }).promise;
    const width = Math.max(280, $('#modal-body').clientWidth - 4);
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const count = Math.min(pdf.numPages, 40);
    for (let n = 1; n <= count; n++) {
      status.textContent = t('pdfPage') + n + t('pdfOf') + pdf.numPages;
      const page = await pdf.getPage(n);
      const base = page.getViewport({ scale: 1 });
      const vp = page.getViewport({ scale: (width / base.width) * ratio });
      const canvas = el('canvas', { width: Math.floor(vp.width), height: Math.floor(vp.height), 'aria-label': t('pdfPage') + n });
      pages.append(canvas);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }
    status.textContent = pdf.numPages + (lang === 'fil' ? ' pahina' : (pdf.numPages === 1 ? ' page' : ' pages'));
  } catch (err) {
    status.textContent = t('pdfFail');
    status.className = 'err';
  }
}

/* ---------- the municipality list ---------- */
function renderChips(rows) {
  const counts = {};
  rows.forEach((r) => { counts[r.level] = (counts[r.level] || 0) + 1; });
  const mk = (value, label, cls) => el('button', { type: 'button', className: 'chip' + (cls ? ' ' + cls : ''), textContent: label, 'aria-pressed': String(levelFilter === value),
    onclick: () => { filterTowns(value); $('#chips [aria-pressed="true"]').focus(); } });
  $('#chips').replaceChildren(mk('', t('allLevels') + ' ' + rows.length),
    ...[...LEVELS, 'pending'].filter((l) => counts[l]).map((l) => mk(l, counts[l] + ' ' + t(l))));
}

function filterTowns(level) {
  levelFilter = level;
  renderChips(lastData.rows);
  renderTowns();
}

function phoneLink(c) {
  return el('a', { href: 'tel:' + c.phone.replace(/[^0-9+]/g, ''), textContent: c.phone });
}

function contactLine(r, hotline) {
  const line = el('p', { className: 'small' }, t('emergency') + ': ', phoneLink({ phone: hotline || '911' }));
  r.contacts.forEach((c) => line.append('; ' + c.label + ' ', phoneLink(c)));
  return line;
}

function renderTowns() {
  if (!lastData) return;
  const sel = Number($('#town').value);
  const q = query.trim().toLowerCase();
  const rows = lastData.rows.filter((r) => (!levelFilter || r.level === levelFilter) && (!q || r.name.toLowerCase().includes(q)));
  if ($('#sort-towns').value === 'risk') rows.sort((a, b) => LEVELS.indexOf(b.level) - LEVELS.indexOf(a.level));
  $('#towns').replaceChildren(...rows.map((r) => el('li', { className: r.id === sel ? 'sel' : '' },
    el('button', { type: 'button', className: 'town', onclick: () => { selectTownFromMapOrList(r.id); } },
      el('span', { className: 'tn', textContent: r.name }),
      el('span', { className: 'town-status' }, el('span', { className: 'badge b-' + r.level, textContent: t(r.level) }), r.hazard !== 'none' ? ' ' + t(r.hazard) : '')))));
  const empty = $('#towns-empty');
  empty.hidden = rows.length > 0;
  empty.textContent = t('noMatch');
}
$('#q').addEventListener('input', (e) => { query = e.target.value; renderTowns(); });
$('#sort-towns').addEventListener('change', renderTowns);

function selectTownFromMapOrList(id) {
  setTown(id);
  renderMy();
  renderTowns();
  loadNotes();
  $('#mycard').focus();
}

function fillTowns(rows) {
  const select = $('#town');
  if (select.options.length > 1) return;
  rows.forEach((r) => select.append(el('option', { value: r.id, textContent: r.name })));
  const saved = preferredTown();
  if (saved && rows.some((r) => String(r.id) === saved)) select.value = saved;
}

/* ---------- the map: loaded on demand, and never required ---------- */
let map, markerLayer, leafletPromise, mapStarted = false, lastRows = [];
const townMarkers = new Map();

function loadScript(src) {
  return new Promise((ok, fail) => {
    const s = document.createElement('script');
    s.src = src; s.onload = ok; s.onerror = () => fail(new Error('script'));
    document.head.append(s);
  });
}

function loadLeaflet() {
  if (window.L) return Promise.resolve();
  if (!leafletPromise) {
    document.head.append(el('link', { rel: 'stylesheet', href: LEAFLET_CSS }));
    leafletPromise = loadScript(LEAFLET_JS);
  }
  return leafletPromise;
}

// On a slow or data-saving connection, ask first instead of downloading the map library and tiles.
function slowConnection() {
  const c = navigator.connection;
  return !!(c && (c.saveData || /2g$/.test(c.effectiveType || '')));
}

function setupMap(rows) {
  lastRows = rows;
  if (map) { document.dispatchEvent(new CustomEvent('bk:map-rows')); return; }
  if (mapStarted) return;
  $('#map-btn').textContent = t('showMap');
  if (slowConnection()) { $('#map-btn').hidden = false; return; }
  startMap();
}

function startMap() {
  mapStarted = true;
  $('#map-btn').hidden = true;
  $('#map-msg').textContent = '';
  loadLeaflet().then(() => {
    $('#map').hidden = false;
    map = L.map('map', { zoomSnap: .25, scrollWheelZoom: false });
    resetMapView();
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { className: 'laguna-basemap', maxZoom: 18, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    drawMarkers();
    document.dispatchEvent(new CustomEvent('bk:map-ready'));
  }).catch(() => {
    mapStarted = false; leafletPromise = null;
    $('#map-msg').textContent = t('mapFail');
    $('#map-btn').hidden = false;
  });
}

function resetMapView() {
  if (lastRows.length) {
    map.fitBounds(lastRows.map((row) => [row.lat, row.lng]), { padding: [24, 24], maxZoom: 11, animate: false });
  } else map.setView([14.2, 121.35], 10);
}

function refreshMapSelection() {
  const selected = Number($('#town').value);
  townMarkers.forEach(({ pin }, id) => pin.classList.toggle('map-pin-selected', id === selected));
}

function drawMarkers(forecastValue) {
  lastRows.forEach((r) => {
    const weather = forecastValue?.(r);
    if (!townMarkers.has(r.id)) {
      const pin = el('span', { className: 'map-pin', 'aria-hidden': 'true' });
      const metric = el('p', { className: 'map-popup-metric' });
      const status = el('span', { className: 'badge' });
      const advice = el('p', { className: 'small' });
      const openTown = el('button', { type: 'button', className: 'link-btn', onclick: async () => {
        if (document.fullscreenElement === $('#risk-map')) await document.exitFullscreen().catch(() => {});
        map.closePopup();
        selectTownFromMapOrList(r.id);
      } });
      const popup = el('div', { className: 'map-popup' }, el('h3', { textContent: r.name }), metric, status, advice, openTown);
      const marker = L.marker([r.lat, r.lng], {
        title: r.name, icon: L.divIcon({ className: 'town-marker', html: pin, iconSize: [28, 28], iconAnchor: [14, 14] }),
      }).bindTooltip(el('span', { textContent: r.name }), { direction: 'top', offset: [0, -12] })
        .bindPopup(popup, { offset: [0, -8] }).addTo(markerLayer);
      marker.getElement().setAttribute('aria-label', r.name);
      townMarkers.set(r.id, { marker, pin, metric, status, advice, openTown });
    }
    const entry = townMarkers.get(r.id);
    entry.pin.style.setProperty('--pin-color', weather?.color || COLORS[r.level]);
    entry.pin.classList.toggle('map-pin-pending', !weather && r.level === 'pending');
    entry.metric.hidden = !weather;
    entry.metric.textContent = weather?.text || '';
    entry.status.className = 'badge b-' + r.level;
    entry.status.textContent = t(r.level) + (r.hazard !== 'none' ? ' · ' + t(r.hazard) : '');
    entry.advice.textContent = r.advice;
    entry.openTown.textContent = t('forYou');
  });
  refreshMapSelection();
}

$('#map-btn').addEventListener('click', startMap);

/* ---------- risk history for one municipality ---------- */
let lastFocus = null;

function openModal(title, body, trigger, wide) {
  lastFocus = trigger || null;
  $('#modal-box').classList.toggle('wide', !!wide);
  $('#modal-title').textContent = title;
  $('#modal-body').replaceChildren(body);
  $('#modal').hidden = false;
  $('#modal-close').focus();
}
function closeModal() {
  $('#modal').hidden = true;
  if (lastFocus) lastFocus.focus();
}
$('#modal-close').addEventListener('click', closeModal);
$('#modal').addEventListener('click', (e) => { if (e.target === $('#modal')) closeModal(); });
document.addEventListener('keydown', (e) => {
  if ($('#modal').hidden) return;
  if (e.key === 'Escape') closeModal();
  if (e.key === 'Tab') { e.preventDefault(); $('#modal-close').focus(); } // keep keyboard focus inside the open dialog
});

const SVG_NS = 'http://www.w3.org/2000/svg';
function svg(tag, attrs = {}, text) {
  const n = document.createElementNS(SVG_NS, tag);
  Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v));
  if (text !== undefined) n.textContent = text;
  return n;
}

function drawChart(h) {
  const W = 320, H = 180, pad = { l: 30, r: 10, t: 12, b: 26 };
  const n = h.points.length;
  const maxScore = Math.max(8, h.levels.severe + 1, ...h.points.map((p) => p.score));
  const x = (i) => pad.l + (n === 1 ? (W - pad.l - pad.r) / 2 : i * (W - pad.l - pad.r) / (n - 1));
  const y = (s) => H - pad.b - (s / maxScore) * (H - pad.t - pad.b);
  const chart = svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': h.name, class: 'chart' });

  chart.append(svg('line', { x1: pad.l, y1: y(0), x2: W - pad.r, y2: y(0), stroke: '#999' }));
  Object.entries(h.levels).forEach(([name, value]) => {
    chart.append(svg('line', { x1: pad.l, y1: y(value), x2: W - pad.r, y2: y(value), stroke: COLORS[name], 'stroke-dasharray': '4 3' }));
    chart.append(svg('text', { x: pad.l + 2, y: y(value) - 3, 'font-size': 9, fill: 'var(--ink)' }, t(name)));
  });
  chart.append(svg('polyline', { points: h.points.map((p, i) => x(i) + ',' + y(p.score)).join(' '), fill: 'none', stroke: 'var(--ink)', 'stroke-width': 1.5 }));
  h.points.forEach((p, i) => chart.append(svg('circle', { cx: x(i), cy: y(p.score), r: 4, fill: COLORS[p.level] }, undefined)));
  chart.append(svg('text', { x: pad.l, y: H - 8, 'font-size': 9, fill: 'var(--mute)' }, h.points[0].label));
  if (n > 1) chart.append(svg('text', { x: W - pad.r, y: H - 8, 'font-size': 9, fill: 'var(--mute)', 'text-anchor': 'end' }, h.points[n - 1].label));
  return chart;
}

async function openHistory(row, trigger) {
  const body = el('div', {}, el('p', { textContent: '…' }));
  openModal(row.name, body, trigger);
  try {
    const h = await (await fetch('api/history.php?id=' + row.id)).json();
    if (!h.points || !h.points.length) { body.replaceChildren(el('p', { textContent: t('noHistory') })); return; }
    body.replaceChildren(
      h.source === 'demo' ? el('p', { className: 'err', textContent: 'DEMO DATA' }) : '',
      drawChart(h),
      el('div', { className: 'scroll' }, el('table', {},
        el('tbody', {}, ...h.points.slice(-8).reverse().map((p) => el('tr', {},
          el('td', { textContent: p.when }),
          el('td', {}, el('span', { className: 'badge b-' + p.level, textContent: t(p.level) }), p.hazard !== 'none' ? ' ' + t(p.hazard) : ''),
          el('td', { textContent: 'Score ' + p.score })))))));
  } catch (err) {
    body.replaceChildren(el('p', { textContent: t('histFail') }));
  }
}

/* ---------- dashboard preferences ---------- */
function applyLangButton() {
  document.documentElement.lang = lang === 'fil' ? 'fil' : 'en'; // so screen readers use the right voice
  $('#lang').textContent = t('other');
  $('#lang').setAttribute('aria-label', t('other'));
  applyStaticText();
}

$('#lang').addEventListener('click', () => {
  lang = lang === 'en' ? 'fil' : 'en';
  try { localStorage.setItem('bk-lang', lang); } catch (e) { /* ignore */ }
  applyLangButton();
  renderCta();
  load();
  if (!isBriefing) loadMe();
});

document.addEventListener('DOMContentLoaded', () => {
  applyLangButton();
  load();
  if (!isBriefing) loadMe();
  setInterval(load, 5 * 60 * 1000);
});
