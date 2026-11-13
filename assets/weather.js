(() => {
  const words = {
    en: {
      title: 'Local weather', refresh: 'Refresh', loading: 'Loading weather…', error: 'Weather is temporarily unavailable. Try refreshing.',
      stale: 'Showing an earlier forecast. Open-Meteo could not be reached.', feels: 'Feels like', humidity: 'Humidity', wind: 'Wind',
      hourly: 'Rain chance · next 24 hours', rain: 'Rain', daily: '5-day outlook · low / high', source: 'Weather model data', asOf: 'Model time',
      conditions: ['Clear', 'Partly cloudy', 'Overcast', 'Fog', 'Drizzle', 'Rain', 'Snow', 'Showers', 'Thunderstorms', 'Unavailable'],
    },
    fil: {
      title: 'Lokal na panahon', refresh: 'I-refresh', loading: 'Nilo-load ang lagay ng panahon…', error: 'Hindi makuha ang lagay ng panahon. Subukang i-refresh.',
      stale: 'Naunang taya ang ipinapakita. Hindi makakonekta sa Open-Meteo.', feels: 'Ramdam na init', humidity: 'Halumigmig', wind: 'Hangin',
      hourly: 'Tsansa ng ulan · susunod na 24 oras', rain: 'Ulan', daily: 'Taya sa 5 araw · mababa / mataas', source: 'Datos mula sa modelo ng panahon', asOf: 'Oras ng modelo',
      conditions: ['Maaliwalas', 'Bahagyang maulap', 'Maulap', 'Hamog', 'Ambon', 'Ulan', 'Niyebe', 'Bugso ng ulan', 'Bagyo na may pagkulog', 'Walang datos'],
    },
  };
  const status = $('#weather-status');
  const body = $('#weather-body');
  const refresh = $('#weather-refresh');
  const locale = () => lang === 'fil' ? 'fil-PH' : 'en-PH';
  const time = (stamp, options) => new Date(stamp + '+08:00').toLocaleString(locale(), { ...options, timeZone: 'Asia/Manila' });
  const format = (value, unit) => Number.isFinite(value) ? Math.round(value) + unit : '—';
  let town, report, pending, lastLoaded = 0, hasSelection = false;

  function condition(code) {
    if (code === 0) return 0;
    if ([1, 2].includes(code)) return 1;
    if (code === 3) return 2;
    if ([45, 48].includes(code)) return 3;
    if (code >= 51 && code <= 57) return 4;
    if (code >= 61 && code <= 67) return 5;
    if (code >= 71 && code <= 77) return 6;
    if (code >= 80 && code <= 86) return 7;
    if (code >= 95 && code <= 99) return 8;
    return 9;
  }

  function render() {
    document.dispatchEvent(new CustomEvent('bk:weather-state', { detail: { town, report, pending: Boolean(pending) } }));
    const w = words[lang];
    $('#weather-title').textContent = w.title;
    $('#weather-place').textContent = town ? town.name + ', Laguna' : '';
    refresh.textContent = w.refresh;
    refresh.disabled = !town || Boolean(pending);
    status.textContent = pending || !hasSelection ? w.loading : report ? (report.stale ? w.stale : '') : w.error;
    status.hidden = !status.textContent;
    $('#weather-source').textContent = w.source;
    if (!report) { body.replaceChildren(); return; }

    const { current, hourly, daily } = report.forecast;
    const metrics = [
      [w.feels, format(current.apparent_temperature, '°C')],
      [w.humidity, format(current.relative_humidity_2m, '%')],
      [w.wind, format(current.wind_speed_10m, ' km/h')],
    ];
    const hour = current.time.slice(0, 13);
    const samples = hourly.time.map((stamp, i) => [stamp, hourly.precipitation_probability[i]])
      .filter(([stamp]) => stamp.slice(0, 13) >= hour).slice(0, 24).filter((_, i) => i % 3 === 0);
    const days = daily.time.map((date, i) => {
      const low = format(daily.temperature_2m_min[i], '°');
      const high = format(daily.temperature_2m_max[i], '°');
      return el('div', { className: 'forecast-day' },
        el('strong', { textContent: time(date + 'T12:00', { weekday: 'short' }) }),
        el('strong', { textContent: low + ' / ' + high }),
        el('span', { textContent: w.rain + ' ' + format(daily.precipitation_probability_max[i], '%') }));
    });

    body.replaceChildren(
      el('div', { className: 'weather-current' },
        el('div', {}, el('strong', { className: 'weather-temperature', textContent: format(current.temperature_2m, '°C') }),
          el('span', { className: 'weather-conditions', textContent: w.conditions[condition(current.weather_code)] })),
        el('dl', { className: 'weather-metrics' }, ...metrics.map(([label, value]) =>
          el('div', {}, el('dt', { textContent: label }), el('dd', { textContent: value }))))),
      el('h3', { className: 'weather-subhead', textContent: w.hourly }),
      el('div', { className: 'rain-chart' }, ...samples.map(([stamp, chance]) =>
        el('div', { className: 'rain-hour' }, el('strong', { textContent: format(chance, '%') }),
          el('span', { className: 'rain-track', 'aria-hidden': 'true' }, el('i', { style: 'height:' + (Number.isFinite(chance) ? Math.min(100, Math.max(0, chance)) : 0) + '%' })),
          el('span', { textContent: time(stamp, { hour: 'numeric' }) })))),
      el('h3', { className: 'weather-subhead', textContent: w.daily }),
      el('div', { className: 'forecast-strip' }, ...days));
    $('#weather-source').textContent = w.source + ' · ' + w.asOf + ' ' +
      time(current.time, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) + ' PHT';
  }

  async function update() {
    if (!town || pending) return;
    const request = new AbortController();
    pending = request;
    render();
    try {
      const response = await fetch('api/weather.php?town=' + town.id, { signal: request.signal, cache: 'no-store' });
      if (!response.ok) throw new Error('Weather request failed');
      const result = await response.json();
      if (pending === request) report = result;
    } catch (error) {
      if (pending === request && report) {
        const modelTime = Date.parse(report.forecast.current.time + '+08:00');
        if (Date.now() - modelTime < 3 * 60 * 60 * 1000) report.stale = true;
        else report = null;
      }
    } finally {
      if (pending === request) {
        pending = null;
        lastLoaded = Date.now();
        render();
      }
    }
  }

  document.addEventListener('bk:weather-town', ({ detail }) => {
    hasSelection = true;
    if (detail?.id !== town?.id) {
      pending?.abort();
      pending = null;
      town = detail;
      report = null;
      lastLoaded = 0;
    }
    render();
    if (Date.now() - lastLoaded >= 5 * 60 * 1000) update();
  });
  refresh.addEventListener('click', update);
  render();
})();
