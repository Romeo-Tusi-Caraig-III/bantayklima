(() => {
  let town, advisory, hotline, weather;
  const text = (en, fil) => local(en, fil);

  function panel(title, status, state, ...content) {
    return el('section', { className: 'today-panel' },
      el('h3', {}, title, el('span', { className: 'freshness ' + state, textContent: status })), ...content);
  }

  function forecastPanel() {
    const title = text('Weather forecast', 'Taya ng panahon');
    const report = weather?.town?.id === town.id ? weather.report : null;
    if (!report) {
      return panel(title, weather?.pending ? text('Loading', 'Nilo-load') : text('Unavailable', 'Walang datos'), 'notice',
        el('p', { textContent: text('Check the local weather panel for an updated forecast.', 'Tingnan ang lokal na panahon para sa bagong taya.') }));
    }
    const { current, hourly } = report.forecast;
    const start = Math.floor(Date.now() / 3600000) * 3600000;
    const upcoming = hourly.time.map((stamp, i) => [stamp, hourly.precipitation_probability[i]])
      .filter(([stamp, chance]) => {
        const when = Date.parse(stamp + '+08:00');
        return Number.isFinite(chance) && when >= start && when < start + 24 * 3600000;
      });
    const next = upcoming.find(([, chance]) => chance >= 60);
    let summary = text('No upcoming hourly rain-chance data is available.', 'Walang oras-oras na datos tungkol sa tsansa ng ulan.');
    if (next) {
      summary = text(
        `Consider bringing rain gear. The first hour with at least a 60% chance of rain is ${forecastTime(next[0])} (${next[1]}%).`,
        `Magdala ng panangga sa ulan. Ang unang oras na may 60% o higit pang tsansa ng ulan ay ${forecastTime(next[0])} (${next[1]}%).`);
    } else if (upcoming.length) {
      const peak = upcoming.reduce((best, hour) => hour[1] > best[1] ? hour : best);
      summary = text(`Peak rain chance in the available hours: ${peak[1]}% at ${forecastTime(peak[0])}.`,
        `Pinakamataas na tsansa ng ulan sa mga oras na may datos: ${peak[1]}% sa ${forecastTime(peak[0])}.`);
    }
    return panel(title, report.stale ? text('Earlier forecast', 'Naunang taya') : text('Available', 'May datos'), report.stale ? 'notice' : 'available',
      el('p', { textContent: summary }),
      el('p', { className: 'small', textContent: 'Open-Meteo · ' + text('Model time ', 'Oras ng modelo ') + forecastTime(current.time) }));
  }

  function render() {
    if (!town) return;
    $('#today-title').textContent = text('What should I do today?', 'Ano ang dapat kong gawin ngayon?');
    $('#today-town').textContent = town.name + ', Laguna';
    const assessment = town.assessment;
    const flags = assessment ? [
      assessment.demo && text('Demo', 'Halimbawa'), assessment.stale && text('Out of date', 'Luma na'),
      assessment.incomplete && text('Incomplete', 'Kulang ang datos'),
    ].filter(Boolean) : [];
    const risk = panel(text('BantayKlima climate assessment', 'Pagsusuri sa klima ng BantayKlima'),
      assessment ? flags.join(' · ') || text('Available', 'May datos') : text('Pending', 'Hindi pa nasusuri'),
      !assessment || flags.length ? 'notice' : 'available',
      el('p', { textContent: assessment && !flags.length ? t(town.level) + ' · ' + town.advice :
        text('Check official advisories for guidance.', 'Tingnan ang mga opisyal na abiso para sa gabay.') }),
      el('p', { className: 'small', textContent: assessment ? text('Assessed ', 'Sinuri noong ') + assessment.updated + ' PHT' : '' }));
    const official = panel('PAGASA', advisory ? (advisory.stale ? text('Older advisory', 'Lumang abiso') : text('On file', 'Naitala')) :
      text('No advisory on file', 'Walang nakatalang abiso'), !advisory || advisory.stale ? 'notice' : 'available',
      el('p', { textContent: advisory ? advisory.alert_status + ' · ' + advisory.rainfall_status.replace(/_/g, ' ') :
        text('See PAGASA for the latest official guidance.', 'Tingnan ang PAGASA para sa pinakabagong opisyal na gabay.') }),
      el('p', { className: 'small' },
        advisory ? (advisory.issued ? text('Issued ', 'Inilabas noong ') + advisory.issued :
          text('Recorded ', 'Naitala noong ') + advisory.recorded + ' PHT') + ' · ' : '',
        el('a', { href: $('#pagasa-link').href, target: '_blank', rel: 'noopener', textContent: text('Official advisories', 'Mga opisyal na abiso') })));
    $('#today-body').replaceChildren(el('div', { className: 'today-panels' }, forecastPanel(), risk, official),
      contactLine(town, hotline));
  }

  document.addEventListener('bk:insights', ({ detail }) => { ({ town, advisory, hotline } = detail); render(); });
  document.addEventListener('bk:weather-state', ({ detail }) => { weather = detail; render(); });
  document.addEventListener('bk:briefing-unavailable', () => {
    $('#today-body').textContent = text('The town briefing is unavailable right now.', 'Hindi makuha ang buod ng bayan ngayon.');
  });
})();
