// Admin only: tabs, confirm buttons, and the announcement form (SMS length, who will be texted).
(function () {
  var tabs = ['home', 'announce', 'pagasa', 'people', 'settings', 'activity'];
  var posted = document.body.dataset.posted === '1';

  function current() {
    var h = (location.hash || '').slice(1);
    if (posted) { posted = false; h = document.body.dataset.start || 'home'; }
    var parts = h.split('/');
    return tabs.indexOf(parts[0]) > -1 ? parts : ['home'];
  }
  function show() {
    var parts = current(), h = parts[0];
    var workspace = document.querySelector('.admin-workspace');
    workspace.dataset.view = h;
    document.querySelectorAll('[data-tab]').forEach(function (el) { el.hidden = el.dataset.tab !== h; });
    document.querySelectorAll('a[data-go]').forEach(function (a) {
      if (a.dataset.go === h) {
        a.setAttribute('aria-current', 'page');
        document.getElementById('admin-section-title').textContent = a.textContent;
      } else {
        a.removeAttribute('aria-current');
      }
    });
    var anchor = parts[1] && document.getElementById(parts[1]);
    if (anchor && anchor.dataset.tab === h) {
      anchor.focus({ preventScroll: true });
      anchor.scrollIntoView({ block: 'start' });
    } else {
      if (location.hash.slice(1) !== h) history.replaceState(null, '', '#' + h);
      window.scrollTo(0, 0);
    }
  }
  window.addEventListener('hashchange', show);
  show();

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
  });

  var form = document.getElementById('ann-form');
  if (!form) return;
  var title = form.elements.title, body = document.getElementById('ann-body'), area = document.getElementById('ann-area');
  var send = document.getElementById('ann-send'), sendText = document.getElementById('ann-send-text');
  var count = document.getElementById('ann-count'), btn = document.getElementById('ann-btn');
  var audience = JSON.parse(form.dataset.audience || '{}'), live = form.dataset.live === '1';
  var emailAudience = JSON.parse(form.dataset.emailAudience || '{}'), emailLive = form.dataset.emailLive === '1';
  var email = document.getElementById('ann-email'), emailText = document.getElementById('ann-email-text');
  var preview = document.getElementById('ann-preview');
  var approved = false, submitting = false;

  function smsLength() { return Array.from('BantayKlima: ' + title.value.trim() + '. ' + body.value.trim()).length; }
  function update() {
    var n = audience[area.value] || 0;
    sendText.textContent = 'Also send by SMS to ' + n + ' ' + (n === 1 ? 'person' : 'people') + ' in ' + area.options[area.selectedIndex].text +
      (live ? '' : ' (SMS is not connected yet, so it will only be recorded)');
    var len = smsLength();
    if (send.checked) {
      var parts = len <= 160 ? 1 : 2;
      count.className = 'small count-line' + (len > 306 ? ' over' : '');
      count.textContent = len > 306
        ? 'The text is ' + (len - 306) + ' characters too long (limit 306). Shorten it or untick SMS.'
        : 'Text length: ' + len + ' of 306. Each person uses ' + parts + ' credit' + (parts > 1 ? 's' : '') + '.';
    } else {
      count.className = 'small count-line';
      count.textContent = email.checked ? 'Email selected. SMS is off.' : 'Dashboard only.';
    }
    var en = emailAudience[area.value] || 0;
    emailText.textContent = 'Also send by email to ' + en + ' ' + (en === 1 ? 'person' : 'people') + ' in ' + area.options[area.selectedIndex].text +
      (emailLive ? '' : ' (email is not connected yet, so it will only be recorded)');
    btn.disabled = send.checked && len > 306;
  }
  [title, body, area, send, email].forEach(function (el) { el.addEventListener('input', update); el.addEventListener('change', update); });
  form.addEventListener('submit', function (e) {
    if (submitting) { e.preventDefault(); return; }
    if (approved) {
      approved = false;
      submitting = true;
      btn.disabled = true;
      btn.textContent = 'Publishing…';
      return;
    }
    e.preventDefault();
    document.getElementById('ann-preview-area').textContent = area.options[area.selectedIndex].text;
    document.getElementById('ann-preview-title').textContent = title.value.trim();
    document.getElementById('ann-preview-body').textContent = body.value.trim();
    var channels = document.getElementById('ann-preview-channels');
    channels.replaceChildren();
    [['Dashboard', 'Visible in the selected area'],
      ['SMS', channelText(send.checked, live, audience[area.value] || 0)],
      ['Email', channelText(email.checked, emailLive, emailAudience[area.value] || 0)]
    ].forEach(function (entry) {
      var term = document.createElement('dt'), value = document.createElement('dd');
      term.textContent = entry[0];
      value.textContent = entry[1];
      channels.append(term, value);
    });
    preview.showModal();
  });
  function channelText(selected, connected, count) {
    if (!selected) return 'Off';
    if (!count) return 'No eligible recipients';
    return count + ' eligible ' + (count === 1 ? 'recipient' : 'recipients') +
      (connected ? ' · send on publish' : ' · recorded only, channel disconnected');
  }
  document.getElementById('ann-edit').addEventListener('click', function () { preview.close(); });
  document.getElementById('ann-publish').addEventListener('click', function () {
    if (!form.reportValidity()) return;
    approved = true;
    preview.close();
    form.requestSubmit(btn);
  });
  window.addEventListener('pageshow', function () {
    approved = submitting = false;
    btn.textContent = 'Preview announcement';
    update();
  });
  update();
})();

(function () {
  var g = document.getElementById('preset-gmail'), f = document.getElementById('email-form');
  if (!g || !f) return;
  g.addEventListener('click', function () {
    f.elements.smtp_host.value = 'smtp.gmail.com'; f.elements.smtp_port.value = '465'; f.elements.smtp_secure.value = 'ssl';
    f.elements.smtp_user.focus();
  });
})();
