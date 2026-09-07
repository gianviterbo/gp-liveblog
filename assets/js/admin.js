/* GP Liveblog — wp-admin control room. */
(function () {
  'use strict';
  if (!window.GPLB) return;
  var cfg = window.GPLB;
  var headers = { 'X-WP-Nonce': cfg.nonce };
  var REST = cfg.rest;

  var main = document.querySelector('.gplb-admin-main');
  if (!main) return;
  var lbId = parseInt(main.getAttribute('data-liveblog'), 10) || 0;

  var rowsEl = document.getElementById('gplbAdminRows');
  var statusEl = document.getElementById('gplbAdminStatus');
  var toolType = 'update';

  function setStatus(msg, err) {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-err', !!err);
    if (msg) setTimeout(function () { if (statusEl.textContent === msg) statusEl.textContent = ''; }, 4000);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function renderRows(entries) {
    if (!rowsEl) return;
    rowsEl.innerHTML = '';
    if (!entries.length) {
      rowsEl.innerHTML = '<div class="gplb-admin-row"><span></span><span>No entries yet.</span><span></span><span></span><span></span></div>';
      return;
    }
    entries.forEach(function (e) {
      var row = document.createElement('div');
      row.className = 'gplb-admin-row';
      row.innerHTML =
        '<span class="gplb-admin-cell-time">' + esc(e.ts_h) + '</span>' +
        '<span class="gplb-admin-cell-txt">' + (e.type === 'note' ? '🔒 ' : '') + esc(String(e.raw || '').slice(0, 160)) + '</span>' +
        '<span class="gplb-admin-cell-who">' + esc(e.author) + '</span>' +
        '<span class="gplb-admin-cell-type"><span>' + esc(e.type) + '</span></span>' +
        '<span class="gplb-admin-actions">' +
        '<button type="button" class="button gplb-edit" data-id="' + e.id + '">' + esc(cfg.i18n.edit) + '</button>' +
        '<button type="button" class="button gplb-del" data-id="' + e.id + '">' + esc(cfg.i18n.delete) + '</button>' +
        '</span>';
      rowsEl.appendChild(row);
    });
    rowsEl.querySelectorAll('.gplb-del').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Delete this entry?')) return;
        fetch(REST + '/entries/' + b.getAttribute('data-id'), { method: 'DELETE', headers: headers })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(); setStatus(d.ok ? 'Deleted ✓' : 'Failed', !d.ok); })
          .catch(function () { setStatus('Network error', true); });
      });
    });
    rowsEl.querySelectorAll('.gplb-edit').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id');
        var txtEl = b.closest('.gplb-admin-row').querySelector('.gplb-admin-cell-txt');
        var cur = txtEl.getAttribute('data-raw') || txtEl.textContent.replace(/^🔒\s*/, '');
        var next = prompt('Edit entry text:', cur);
        if (next === null) return;
        fetch(REST + '/entries/' + id, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify({ text: next }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(); setStatus(d.ok ? 'Saved ✓' : 'Failed', !d.ok); })
          .catch(function () { setStatus('Network error', true); });
      });
    });
  }

  function load() {
    if (!rowsEl) return;
    rowsEl.textContent = 'Loading…';
    fetch(REST + '/liveblogs/' + lbId + '/entries?t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.entries) renderRows(d.entries);
        else rowsEl.textContent = 'Could not load entries.';
      })
      .catch(function () { rowsEl.textContent = 'Could not load entries.'; });
  }

  /* composer */
  var tools = document.querySelectorAll('.gplb-admin-composer .gplb-tool');
  tools.forEach(function (t) {
    t.addEventListener('click', function () {
      tools.forEach(function (x) { x.classList.remove('is-on'); });
      t.classList.add('is-on');
      toolType = t.getAttribute('data-type');
      document.getElementById('gplbAdminUrlRow').hidden = !(toolType === 'link' || toolType === 'social');
      document.getElementById('gplbAdminImgRow').hidden = (toolType !== 'image');
    });
  });

  var imgAtt = 0;
  var imgInput = document.getElementById('gplbAdminImg');
  if (imgInput) imgInput.addEventListener('change', function () {
    var f = this.files[0];
    if (!f) return;
    var fd = new FormData();
    fd.append('file', f);
    setStatus('Uploading…');
    fetch(REST + '/upload-image', { method: 'POST', headers: headers, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.attachment_id) { imgAtt = d.attachment_id; setStatus('Image ready ✓'); }
        else setStatus(d.message || 'Upload failed', true);
      })
      .catch(function () { setStatus('Upload failed', true); });
  });

  function publish() {
    var ta = document.getElementById('gplbAdminText');
    var text = ta.value.trim();
    var payload = { type: toolType, text: text };
    var tsVal = document.getElementById('gplbAdminTs').value.trim();
    if (tsVal && tsVal !== 'now') payload.ts = tsVal;
    if (toolType === 'image') {
      if (!imgAtt) { setStatus('Attach an image first', true); return; }
      payload.image_id = imgAtt;
    }
    if (toolType === 'link' || toolType === 'social') {
      var u = document.getElementById('gplbAdminUrl').value.trim();
      if (!u) { setStatus('Paste a URL', true); return; }
      payload.url = u;
    }
    var pubBtn = document.getElementById('gplbAdminPublish');
    pubBtn.disabled = true;
    setStatus('Publishing…');
    fetch(REST + '/liveblogs/' + lbId + '/entries', {
      method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        pubBtn.disabled = false;
        if (d.ok && d.entry) {
          ta.value = '';
          var urlEl = document.getElementById('gplbAdminUrl'); if (urlEl) urlEl.value = '';
          imgAtt = 0;
          setStatus('Posted ✓');
          load();
        } else { setStatus(d.message || 'Failed', true); }
      })
      .catch(function () { pubBtn.disabled = false; setStatus('Network error', true); });
  }

  var pubBtn = document.getElementById('gplbAdminPublish');
  if (pubBtn) {
    pubBtn.addEventListener('click', publish);
    var ta2 = document.getElementById('gplbAdminText');
    ta2.addEventListener('keydown', function (ev) {
      if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') publish();
    });
  }

  var endBtn = document.getElementById('gplbEndBtn');
  if (endBtn) endBtn.addEventListener('click', function () {
    if (!confirm('End this liveblog? Feed pauses; entries stay readable.')) return;
    fetch(REST + '/liveblogs/' + endBtn.getAttribute('data-id') + '/end', { method: 'POST', headers: headers })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else setStatus('Failed', true); })
      .catch(function () { setStatus('Network error', true); });
  });
  var startBtn = document.getElementById('gplbStartBtn');
  if (startBtn) startBtn.addEventListener('click', function () {
    fetch(REST + '/liveblogs/' + startBtn.getAttribute('data-id') + '/start', { method: 'POST', headers: headers })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else setStatus('Failed', true); })
      .catch(function () { setStatus('Network error', true); });
  });

  /* self-refresh every 15s */
  load();
  setInterval(load, 15000);
})();
