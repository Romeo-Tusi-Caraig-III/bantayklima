let forecastRequest;
let forecastLoaded = 0;
function getMapForecast() {
  if (!forecastRequest || Date.now() - forecastLoaded > 15 * 60 * 1000) {
    forecastLoaded = Date.now();
    forecastRequest = fetch('api/map-forecast.php').then((response) => {
      if (!response.ok) throw new Error('Forecast unavailable');
      return response.json();
    }).catch((error) => { forecastRequest = null; throw error; });
  }
  return forecastRequest;
}

const forecastTime = (stamp) => new Date(stamp + '+08:00').toLocaleString(lang === 'fil' ? 'fil-PH' : 'en-PH', {
  timeZone: 'Asia/Manila', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
}) + ' PHT';
const local = (en, fil) => lang === 'fil' ? fil : en;

(() => {
  let rows = [];
  let town;
  let timelineRequest = 0;
  let comparisonRequest = 0;

  function rainfall() {
    const box = $('#rainfall-body');
    box.replaceChildren(el('p', { className: 'small', textContent: town?.name || 'Laguna' }));
    const rain = town?.rainfall;
    if (!rain?.updated) {
      box.append(el('p', { textContent: local('Available after a climate assessment runs.', 'Makikita pagkatapos ng pagsusuri sa klima.') }));
    } else {
      box.append(el('p', { className: 'small', textContent: (rain.source === 'demo' ? local('Demo assessment · ', 'Halimbawang pagsusuri · ') : '') + rain.updated + ' PHT' }));
      for (const [days, ratio] of [[30, rain.days30], [90, rain.days90]]) {
        const difference = Number.isFinite(ratio) ? Math.round((ratio - 1) * 100) : null;
        const detail = difference === null ? local('No data', 'Walang datos') :
          difference === 0 ? local('Near typical', 'Malapit sa karaniwan') :
          Math.abs(difference) + '% ' + (difference < 0 ? local('below typical', 'mas mababa sa karaniwan') : local('above typical', 'mas mataas sa karaniwan'));
        box.append(el('div', { className: 'rain-window' },
          el('strong', { textContent: local('Last ' + days + ' days', 'Huling ' + days + ' araw') }),
          el('span', { className: difference < 0 ? 'rain-deficit' : '', textContent: detail })));
      }
      box.append(el('p', { className: 'small', textContent: local(
        'The 30-day rainfall comparison is one input to BantayKlima’s climate risk assessment.',
        'Ang paghahambing ng ulan sa 30 araw ay isa sa mga batayan ng pagsusuri ng BantayKlima.') }));
    }
    if (!town) return;
    const url = new URL('index.html', location.href);
    url.search = new URLSearchParams({ brief: '1', town: town.id, lang });
    const label = local('Open town briefing', 'Buksan ang buod ng bayan');
    box.append(el('div', { className: 'row briefing-actions' },
      el('a', { className: 'btn ghost', href: url.href, textContent: label }),
      el('button', { type: 'button', className: 'secondary', textContent: local('Copy briefing link', 'Kopyahin ang link'),
        onclick: async (event) => {
          try {
            await navigator.clipboard.writeText(url.href);
            event.target.textContent = local('Link copied', 'Nakopya ang link');
          } catch (error) {
            const field = el('input', { readOnly: true, value: url.href, 'aria-label': label });
            event.target.replaceWith(field);
            field.select();
          }
        } })));
  }

  async function timeline() {
    const sequence = ++timelineRequest;
    const box = $('#timeline-body');
    box.textContent = local('Loading updates…', 'Nilo-load ang mga abiso…');
    try {
      const response = await fetch('api/timeline.php' + (town ? '?town=' + town.id : ''));
      if (!response.ok) throw new Error('Timeline unavailable');
      const { events } = await response.json();
      if (sequence !== timelineRequest) return;
      box.replaceChildren();
      if (!events.length) box.textContent = local('No updates have been posted.', 'Wala pang nai-post na abiso.');
      for (const event of events) {
        const source = event.kind === 'pagasa' ? 'PAGASA' : 'BantayKlima';
        box.append(el('article', { className: 'timeline-entry' },
          el('p', { className: 'small', textContent: source + ' · ' + (event.area || 'Laguna') + ' · ' + (event.published_at || event.event_at + ' PHT') }),
          el('h3', { textContent: event.title }),
          el('p', { textContent: event.detail || '' })));
      }
    } catch (error) {
      if (sequence === timelineRequest) box.textContent = local('Updates are unavailable right now.', 'Hindi makuha ang mga abiso ngayon.');
    }
  }

  async function compare() {
    const sequence = ++comparisonRequest;
    const box = $('#compare-body');
    const first = rows.find((row) => row.id === Number($('#compare-first').value));
    const second = rows.find((row) => row.id === Number($('#compare-second').value));
    if (!first || !second || first === second) {
      box.textContent = local('Choose two different municipalities.', 'Pumili ng dalawang magkaibang bayan.');
      return;
    }
    box.textContent = local('Loading forecasts…', 'Nilo-load ang mga taya…');
    try {
      const forecast = await getMapForecast();
      if (sequence !== comparisonRequest) return;
      const heading = (text) => el('th', { textContent: text });
      const data = (row, i) => {
        const fields = forecast.towns[row.id];
        const show = (key, unit) => Number.isFinite(fields?.[key]?.[i]) ? fields[key][i] + unit : '—';
        return show('temperature_2m', ' °C') + ' · ' + show('precipitation_probability', '%') + ' · ' + show('wind_speed_10m', ' km/h');
      };
      const header = el('thead', {}, el('tr', {}, heading(local('Time', 'Oras')), heading(first.name), heading(second.name)));
      const values = el('tbody', {}, el('tr', {}, heading(local('Climate risk', 'Panganib sa klima')),
        el('td', { textContent: t(first.level) }), el('td', { textContent: t(second.level) })));
      forecast.time.forEach((stamp, i) => {
        if (i % 3 === 0) values.append(el('tr', {}, heading(forecastTime(stamp)),
          el('td', { textContent: data(first, i) }), el('td', { textContent: data(second, i) })));
      });
      box.replaceChildren(el('p', { className: 'small', textContent: local(
        'Temperature · rain chance · wind speed', 'Temperatura · tsansa ng ulan · bilis ng hangin') +
        (forecast.stale ? local(' · Cached forecast', ' · Nakaimbak na taya') : '') }),
      el('div', { className: 'scroll comparison-table' }, el('table', {}, header, values)));
    } catch (error) {
      if (sequence === comparisonRequest) box.textContent = local('Forecasts are unavailable. Try again shortly.', 'Hindi makuha ang taya. Subukan muli mamaya.');
    }
  }

  document.addEventListener('bk:insights', (event) => {
    const initial = !rows.length;
    ({ town, rows } = event.detail);
    $('#rainfall-title').textContent = local('Rainfall vs. typical', 'Ulan kumpara sa karaniwan');
    $('#timeline-title').textContent = local('Advisory timeline', 'Kasaysayan ng mga abiso');
    $('#timeline-source').textContent = local('Official PAGASA advisories', 'Mga opisyal na abiso ng PAGASA');
    $('#full-dashboard').textContent = local('Full dashboard', 'Buong dashboard');
    rainfall();
    timeline();
    if (isBriefing) return;
    $('#compare-title').textContent = local('Compare municipalities', 'Paghambingin ang mga bayan');
    $('#compare-first-label').textContent = local('First municipality', 'Unang bayan');
    $('#compare-second-label').textContent = local('Second municipality', 'Ikalawang bayan');
    $('#compare-submit').textContent = local('Compare', 'Paghambingin');
    if (initial) {
      for (const id of ['#compare-first', '#compare-second']) {
        $(id).replaceChildren(...rows.map((row) => el('option', { value: row.id, textContent: row.name })));
      }
      $('#compare-first').value = town?.id ?? rows[0]?.id;
      $('#compare-second').value = rows.find((row) => row.id !== town?.id)?.id;
    } else if ($('#compare-body').querySelector('table')) compare();
  });
  $('#compare-submit').addEventListener('click', compare);
})();
