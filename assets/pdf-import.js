// Admin only: read the text of an already published PAGASA PDF in the browser and put it in the import form.
// The normal "check before saving" step then runs on that text.
(function () {
  var buttons = document.querySelectorAll('[data-read-pdf]');
  if (!buttons.length) return;
  var box = document.getElementById('table-text');
  var msg = document.getElementById('pdf-msg');
  var base = (document.currentScript && document.currentScript.src || '').replace(/[^\/]*$/, '');

  function say(text, bad) { msg.textContent = text; msg.className = bad ? 'err' : 'small'; msg.scrollIntoView({ block: 'nearest' }); }

  function readPdf(id) {
    if (typeof pdfjsLib === 'undefined') { say('The PDF reader did not load. Paste the table text instead.', true); return; }
    say('Reading the PDF...');
    pdfjsLib.GlobalWorkerOptions.workerSrc = base + 'vendor/pdfjs/pdf.worker.min.js';
    pdfjsLib.getDocument({ url: '../file.php?id=' + encodeURIComponent(id), isEvalSupported: false, disableFontFace: true }).promise.then(function (pdf) {
      var pages = Math.min(pdf.numPages, 10), text = '', chain = Promise.resolve();
      for (var n = 1; n <= pages; n++) {
        (function (num) {
          chain = chain.then(function () { return pdf.getPage(num); })
            .then(function (page) { return page.getTextContent(); })
            .then(function (content) {
              content.items.forEach(function (it) { text += it.str + (it.hasEOL ? '\n' : ' '); });
              text += '\n';
            });
        })(n);
      }
      return chain.then(function () { return text; });
    }).then(function (text) {
      if (text.replace(/\s+/g, '').length < 40) { say('No text found in this PDF (it may be a scan). Paste the table text or enter the status by hand.', true); return; }
      box.value = text;
      var form = box.form;
      if (form.elements.alert_status.value.trim() === '') { say('PDF read. Fill in the alert status, then press "Read table".', true); return; }
      say('PDF read. Checking the table...');
      form.requestSubmit();
    }).catch(function () { say('Could not read this PDF. Paste the table text instead.', true); });
  }

  buttons.forEach(function (b) { b.addEventListener('click', function () { readPdf(b.dataset.readPdf); }); });
})();
