(() => {
  const fields = {
    temperature_2m: ['Temperature', 'Temperatura', '°C', [20, 25, 30, 35, 40], ['#3874bd', '#5abfa5', '#f2d064', '#ed8a49', '#ba3656']],
    precipitation: ['Rainfall', 'Dami ng ulan', 'mm/h', [0, 1, 5, 15, 30], ['#d9eef5', '#8ecbed', '#4385ce', '#664eaa', '#ac3c83']],
    precipitation_probability: ['Rain chance', 'Tsansa ng ulan', '%', [0, 25, 50, 75, 100], ['#d9eef5', '#8ecbed', '#4385ce', '#664eaa', '#ac3c83']],
    wind_speed_10m: ['Wind speed', 'Bilis ng hangin', 'km/h', [0, 15, 30, 50, 75], ['#414b9b', '#478ac1', '#5cbea7', '#dfd470', '#e67451']],
  };
  const layers = {
    risk: ['Climate risk', 'Panganib sa klima'], radar: ['Radar', 'Radar'],
    satellite: ['Satellite', 'Satellite'], fires: ['Fire hotspots', 'Mga posibleng sunog'],
  };
  const layer = $('#map-layer');
  const hour = $('#map-hour');
  const play = $('#map-play');
  const legend = $('#map-legend');
  const message = $('#map-msg');
  const source = $('#map-source');
  const expand = $('#map-expand');
  let report, raster, observation, windCanvas, windFrame = 0, timer, request = 0;
  let view = 'risk';
  const frame = () => Number(hour.value);
  const rgba = (hex) => hex.slice(1).match(/../g).map((v) => parseInt(v, 16));
  const palettes = Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, field[4].map(rgba)]));

  function color(name, value) {
    const stops = fields[name][3];
    const colors = palettes[name];
    if (value <= stops[0]) return colors[0];
    const upper = stops.findIndex((stop) => stop >= value);
    if (upper < 0) return colors.at(-1);
    const fraction = (value - stops[upper - 1]) / (stops[upper] - stops[upper - 1]);
    return colors[upper].map((channel, i) => Math.round(colors[upper - 1][i] + fraction * (channel - colors[upper - 1][i])));
  }

  function sample(x, y, get) {
    const edge = report.size - 1;
    const col = Math.min(edge - 1, Math.floor(x * edge));
    const row = Math.min(edge - 1, Math.floor(y * edge));
    const u = x * edge - col;
    const v = y * edge - row;
    const i = row * report.size + col;
    const values = [i, i + 1, i + report.size, i + report.size + 1].map((index) => get(report.grid[index]));
    if (!values.every(Number.isFinite)) return null;
    return values[0] * (1 - u) * (1 - v) + values[1] * u * (1 - v) + values[2] * (1 - u) * v + values[3] * u * v;
  }

  function renderRaster() {
    if (raster) { raster.remove(); raster = null; }
    if (!$('#map-smooth').checked) return;
    const canvas = el('canvas', { width: 84, height: 84 });
    const context = canvas.getContext('2d');
    if (!context) return;
    const pixels = context.createImageData(canvas.width, canvas.height);
    const h = frame();
    for (let y = 0; y < canvas.height; y++) {
      for (let x = 0; x < canvas.width; x++) {
        const value = sample(x / (canvas.width - 1), y / (canvas.height - 1), (p) => p[view][h]);
        if (value === null) continue;
        pixels.data.set([...color(view, value), 145], (y * canvas.width + x) * 4);
      }
    }
    context.putImageData(pixels, 0, 0);
    raster = L.imageOverlay(canvas.toDataURL(), report.bounds, { interactive: false }).addTo(map);
  }

  function stopWind() {
    cancelAnimationFrame(windFrame);
    windFrame = 0;
    windCanvas?.remove();
    windCanvas = null;
  }

  function renderWind() {
    stopWind();
    if (!fields[view] || !report || !$('#map-wind').checked || document.hidden) return;
    const [[south, west], [north, east]] = report.bounds;
    const origin = map.latLngToLayerPoint([north, west]);
    const end = map.latLngToLayerPoint([south, east]);
    const size = end.subtract(origin);
    if (size.x < 1 || size.y < 1) return;
    const visible = map.getPixelBounds();
    const topLeft = map.project([north, west]);
    const bottomRight = map.project([south, east]);
    if (!visible.intersects(L.bounds(topLeft, bottomRight))) return;
    const canvas = windCanvas = el('canvas', { className: 'map-wind-canvas', width: 380, height: 300 });
    canvas.style.width = size.x + 'px';
    canvas.style.height = size.y + 'px';
    map.getPane('overlayPane').append(canvas);
    L.DomUtil.setPosition(canvas, origin);
    const context = canvas.getContext('2d');
    if (!context) return;
    const spawn = () => ({ x: Math.random(), y: Math.random(), age: 20 + Math.random() * 60 });
    const particles = Array.from({ length: 100 }, spawn);
    const component = (p, h, trig) => Number.isFinite(p.wind_direction_10m[h]) && Number.isFinite(p.wind_speed_10m[h])
      ? trig(p.wind_direction_10m[h] * Math.PI / 180) * p.wind_speed_10m[h] : null;
    function tick() {
      context.globalCompositeOperation = 'destination-out';
      context.fillStyle = 'rgba(0,0,0,.18)';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.globalCompositeOperation = 'source-over';
      context.strokeStyle = 'rgba(255,255,255,.78)';
      context.beginPath();
      const h = frame();
      particles.forEach((point, i) => {
        const dx = sample(point.x, point.y, (p) => component(p, h, Math.sin));
        const dy = sample(point.x, point.y, (p) => component(p, h, Math.cos));
        const x = point.x - dx * .00018;
        const y = point.y + dy * .00018;
        if (dx === null || dy === null || x < 0 || x > 1 || y < 0 || y > 1 || --point.age < 0) { particles[i] = spawn(); return; }
        context.moveTo(point.x * canvas.width, point.y * canvas.height);
        context.lineTo(x * canvas.width, y * canvas.height);
        point.x = x;
        point.y = y;
      });
      context.stroke();
      windFrame = requestAnimationFrame(tick);
    }
    windFrame = requestAnimationFrame(tick);
  }

  function renderForecast() {
    if (!report || !fields[view]) return;
    const h = frame();
    const [en, fil, unit, stops, colors] = fields[view];
    const label = local(en, fil);
    const time = forecastTime(report.time[h]);
    $('#map-hour-label').textContent = time;
    hour.setAttribute('aria-valuetext', time);
    drawMarkers((town) => {
      const value = report.towns[town.id]?.[view]?.[h];
      return Number.isFinite(value) ? { text: label + ' · ' + value + ' ' + unit, color: 'rgb(' + color(view, value).join(',') + ')' }
        : { text: local('No forecast', 'Walang taya'), color: '#858c95' };
    });
    renderRaster();
    renderWind();
    legend.replaceChildren(...stops.map((stop, i) => {
      const swatch = el('i', { style: 'background:' + colors[i], 'aria-hidden': 'true' });
      return el('span', {}, swatch, String(stop) + ' ' + unit);
    }));
    source.textContent = 'Open-Meteo · ' + local('Hourly model forecast', 'Oras-oras na taya ng modelo') +
      (report.stale ? local(' · Cached forecast', ' · Nakaimbak na taya') : '') +
      ($('#map-smooth').checked ? local(' · Interpolated grid', ' · Interpolasyon ng grid') : '');
  }

  function pause() {
    clearInterval(timer);
    timer = null;
    play.textContent = local('Play', 'I-play');
  }

  async function observe(selection, token) {
    let overlay, detail;
    if (selection === 'radar') {
      const response = await fetch('https://api.rainviewer.com/public/weather-maps.json');
      if (!response.ok) throw new Error('Radar unavailable');
      const data = await response.json();
      const newest = data.radar?.past?.at(-1);
      if (!newest || data.host !== 'https://tilecache.rainviewer.com' || !/^\/v2\/radar\/[\w-]+$/.test(newest.path)) throw new Error('Radar unavailable');
      overlay = L.tileLayer(data.host + newest.path + '/256/{z}/{x}/{y}/2/1_0.png', {
        maxNativeZoom: 7, maxZoom: 18, opacity: .72, attribution: '<a href="https://www.rainviewer.com/">RainViewer</a>',
      });
      const stamp = new Date(newest.time * 1000 + 8 * 3600 * 1000).toISOString().slice(0, 16);
      detail = 'RainViewer · ' + forecastTime(stamp) + ' · ' +
        local('Coverage varies. Blank areas may have no radar coverage.', 'Nag-iiba ang saklaw. Maaaring walang datos sa mga blangkong lugar.');
    } else {
      const satellite = selection === 'satellite';
      const date = new Date(satellite ? Math.floor((Date.now() - 40 * 60000) / 600000) * 600000 : Date.now() - 86400000);
      const stamp = satellite ? date.toISOString().replace('.000', '') : date.toISOString().slice(0, 10);
      overlay = L.tileLayer.wms('https://gibs.earthdata.nasa.gov/wms/epsg3857/best/wms.cgi', {
        layers: satellite ? 'Himawari_AHI_Air_Mass' : 'VIIRS_NOAA20_Thermal_Anomalies_375m_All',
        time: stamp, transparent: true, format: 'image/png', opacity: satellite ? .73 : .9,
        attribution: '<a href="https://www.earthdata.nasa.gov/data/tools/gibs">NASA GIBS</a>',
      });
      detail = 'NASA GIBS · ' + (satellite ? 'Himawari-9 · ' + date.toISOString().slice(0, 16).replace('T', ' ') + ' UTC' :
        'VIIRS · ' + stamp + ' UTC · ' + local('Satellite heat detections may have other causes.', 'Maaaring iba ang sanhi ng init na nakita ng satellite.'));
    }
    if (token !== request) return;
    overlay.on('tileerror', () => {
      if (token === request) message.textContent = local('Some observation tiles could not load. Try the source links below.', 'Hindi na-load ang ilang larawan. Subukan ang mga link sa ibaba.');
    });
    observation = overlay.addTo(map);
    source.textContent = detail;
  }

  async function changeLayer() {
    const token = ++request;
    view = layer.value;
    pause();
    stopWind();
    raster?.remove();
    raster = null;
    observation?.remove();
    observation = null;
    legend.replaceChildren();
    message.textContent = source.textContent = '';
    $('#map-timeline').hidden = $('#map-smooth-label').hidden = $('#map-wind-label').hidden = true;
    drawMarkers();
    if (view === 'risk') {
      const counts = new Map();
      lastRows.forEach((row) => counts.set(row.level, (counts.get(row.level) || 0) + 1));
      legend.replaceChildren(...[...LEVELS, 'pending'].map((key) => {
        const swatch = key === 'pending' ? 'border-color:' + COLORS[key] : 'background:' + COLORS[key];
        return el('span', {}, el('i', { style: swatch, 'aria-hidden': 'true' }), t(key) + ' ' + (counts.get(key) || 0));
      }));
      source.textContent = local('Select a municipality to see its assessment.', 'Pumili ng munisipalidad upang makita ang pagsusuri.');
      if (counts.get('pending')) source.textContent += ' ' + local('Hollow markers are awaiting an assessment.', 'Wala pang pagsusuri ang mga markang bilog na walang laman.');
      return;
    }
    try {
      if (!fields[view]) { await observe(view, token); return; }
      message.textContent = local('Loading 48-hour forecast…', 'Nilo-load ang 48-oras na taya…');
      const data = await getMapForecast();
      if (token !== request) return;
      report = data;
      message.textContent = '';
      $('#map-timeline').hidden = $('#map-smooth-label').hidden = $('#map-wind-label').hidden = false;
      renderForecast();
    } catch (error) {
      if (token === request) message.textContent = local('Layer unavailable. Choose another layer or try again.', 'Hindi makuha ang layer. Pumili ng iba o subukan muli.');
    }
  }

  function labels() {
    expand.textContent = document.fullscreenElement ? local('Exit full screen', 'Lumabas sa buong screen') : local('Full screen', 'Buong screen');
    $('#map-layer-label').textContent = local('Map layer', 'Layer ng mapa');
    $('#map-town-label').textContent = local('Find on map', 'Hanapin sa mapa');
    $('#map-town').options[0].textContent = local('Choose a municipality', 'Pumili ng munisipalidad');
    $('#map-reset').textContent = local('Reset view', 'Ibalik ang mapa');
    for (const option of layer.options) {
      const names = fields[option.value] || layers[option.value];
      option.textContent = local(names[0], names[1]);
    }
    $('#map-smooth-label span').textContent = local('Smooth overlay', 'Makinis na overlay');
    $('#map-wind-label span').textContent = local('Animated wind', 'Gumagalaw na hangin');
  }

  document.addEventListener('bk:map-ready', () => {
    $('#map-controls').hidden = false;
    $('#map-town').append(...lastRows.map((town) => el('option', { value: town.id, textContent: town.name })));
    expand.hidden = !document.fullscreenEnabled;
    labels();
    changeLayer();
    map.on('moveend zoomend resize', renderWind);
  });
  expand.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await $('#risk-map').requestFullscreen();
    } catch (error) {
      message.textContent = local('Full screen is unavailable here.', 'Hindi available ang buong screen dito.');
    }
  });
  document.addEventListener('fullscreenchange', () => {
    labels();
    map?.invalidateSize();
  });
  document.addEventListener('bk:map-rows', () => {
    labels();
    if (fields[view] && report && !$('#map-timeline').hidden) {
      const token = request;
      renderForecast();
      getMapForecast().then((data) => {
        if (token !== request || data === report) return;
        if (report.time[0] !== data.time[0]) {
          hour.value = Math.max(0, data.time.indexOf(report.time[frame()]));
        }
        report = data;
        message.textContent = '';
        renderForecast();
      }).catch(() => {
        if (token === request) message.textContent = local('Could not refresh the forecast. Showing an earlier run.', 'Hindi ma-update ang taya. Naunang taya ang ipinapakita.');
      });
    }
    else if (view === 'risk') changeLayer();
    else drawMarkers();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { pause(); stopWind(); }
    else renderWind();
  });
  layer.addEventListener('change', changeLayer);
  $('#map-town').addEventListener('change', (event) => {
    const entry = townMarkers.get(Number(event.target.value));
    if (!entry) { map.closePopup(); return; }
    map.setView(entry.marker.getLatLng(), Math.max(map.getZoom(), 11.5), { animate: false });
    entry.marker.openPopup();
  });
  $('#map-reset').addEventListener('click', () => {
    $('#map-town').value = '';
    map.closePopup();
    resetMapView();
  });
  hour.addEventListener('input', renderForecast);
  $('#map-smooth').addEventListener('change', renderForecast);
  $('#map-wind').addEventListener('change', renderWind);
  play.addEventListener('click', () => {
    if (timer) { pause(); return; }
    play.textContent = local('Pause', 'I-pause');
    timer = setInterval(() => {
      hour.value = (frame() + 1) % report.time.length;
      renderForecast();
    }, 900);
  });
})();
