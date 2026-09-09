/* GP Liveblog — wp-admin control room. */
(function () {
  'use strict';
  if (!window.GPLB) return;
  var cfg = window.GPLB;
  var headers = { 'X-WP-Nonce': cfg.nonce };
  var healDone = false;
  /* Cookie-check 403 (stale nonce) -> reload once; the control room is
     staff-only, so a 403 with code rest_cookie_invalid_nonce is always rot. */
  function api(url, opts) {
    opts = opts || {};
    return api(url, opts).then(function (r) {
      if (r.status === 403 && !healDone) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (d.code === 'rest_cookie_invalid_nonce') {
            healDone = true;
            location.reload();
            throw new Error('session refresh');
          }
          return r;
        });
      }
      return r;
    });
  }
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

  function renderRows(entries, threads) {
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
        '<span class="gplb-admin-cell-txt" data-raw="' + esc(e.raw || '') + '">' + (e.type === 'note' ? '🔒 ' : '') + esc(String(e.raw || '').slice(0, 160)) + '</span>' +
        '<span class="gplb-admin-cell-who">' + esc(e.author) + '</span>' +
        '<span class="gplb-admin-cell-type"><span>' + esc(e.type) + '</span></span>' +
        '<span class="gplb-admin-actions">' +
        '<button type="button" class="button gplb-edit" data-id="' + e.id + '">' + esc(cfg.i18n.edit) + '</button>' +
        '<button type="button" class="button gplb-del" data-id="' + e.id + '">' + esc(cfg.i18n.delete) + '</button>' +
        '</span>';
      rowsEl.appendChild(row);
      // Threaded replies under this update (backend management).
      var reps = (threads && threads[e.id]) || [];
      reps.forEach(function (r) {
        var sub = document.createElement('div');
        sub.className = 'gplb-admin-row gplb-admin-row--reply';
        sub.innerHTML =
          '<span class="gplb-admin-cell-time">' + esc(r.ts_h) + '</span>' +
          '<span class="gplb-admin-cell-txt" data-raw="' + esc(r.raw || '') + '"><span class="gplb-reply-mark">↳</span> ' + (r.public_replies !== undefined && !r.public_replies && r.type === 'reply' ? '' : '') + esc(String(r.raw || '').slice(0, 140)) + '</span>' +
          '<span class="gplb-admin-cell-who">' + esc(r.author) + '</span>' +
          '<span class="gplb-admin-cell-type"><span class="gplb-chip-reply">reply</span></span>' +
          '<span class="gplb-admin-actions">' +
          '<button type="button" class="button gplb-edit" data-id="' + r.id + '">' + esc(cfg.i18n.edit) + '</button>' +
          '<button type="button" class="button gplb-del" data-id="' + r.id + '">' + esc(cfg.i18n.delete) + '</button>' +
          '</span>';
        rowsEl.appendChild(sub);
      });
    });
    rowsEl.querySelectorAll('.gplb-del').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Delete this entry?')) return;
        api(REST + '/entries/' + b.getAttribute('data-id'), { method: 'DELETE', headers: headers })
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
        api(REST + '/entries/' + id, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify({ text: next }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.ok) load(); setStatus(d.ok ? 'Saved ✓' : 'Failed', !d.ok); })
          .catch(function () { setStatus('Network error', true); });
      });
    });
  }

  function load() {
    if (!rowsEl) return;
    rowsEl.textContent = 'Loading…';
    api(REST + '/liveblogs/' + lbId + '/entries?t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.entries) renderRows(d.entries, d.threads);
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
    api(REST + '/upload-image', { method: 'POST', headers: headers, body: fd })
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
    api(REST + '/liveblogs/' + lbId + '/entries', {
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

  var lockBtn = document.getElementById('gplbLockBtn');
  if (lockBtn) lockBtn.addEventListener('click', function () {
    var action = lockBtn.getAttribute('data-locked') === '1' ? 'unlock' : 'lock';
    lockBtn.disabled = true;
    api(REST + '/liveblogs/' + lockBtn.getAttribute('data-id') + '/' + action, { method: 'POST', headers: headers })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else { lockBtn.disabled = false; setStatus('Failed', true); } })
      .catch(function () { lockBtn.disabled = false; setStatus('Network error', true); });
  });

  var endBtn = document.getElementById('gplbEndBtn');
  if (endBtn) endBtn.addEventListener('click', function () {
    if (!confirm('End this liveblog? Feed pauses; entries stay readable.')) return;
    api(REST + '/liveblogs/' + endBtn.getAttribute('data-id') + '/end', { method: 'POST', headers: headers })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else setStatus('Failed', true); })
      .catch(function () { setStatus('Network error', true); });
  });
  var startBtn = document.getElementById('gplbStartBtn');
  if (startBtn) startBtn.addEventListener('click', function () {
    api(REST + '/liveblogs/' + startBtn.getAttribute('data-id') + '/start', { method: 'POST', headers: headers })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else setStatus('Failed', true); })
      .catch(function () { setStatus('Network error', true); });
  });

  /* self-refresh every 15s */
  load();
  setInterval(load, 15000);

  /* ── analytics + pinned video (v0.2.0) ── */
  var statsEl = document.getElementById('gplbAdminStats');
  function fmt(n) { return Number(n || 0).toLocaleString(); }
  function loadStats() {
    if (!statsEl) return;
    api(REST + '/liveblogs/' + lbId + '/stats')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || typeof d !== 'object' || d.viewers === undefined) return;
        statsEl.innerHTML =
          '<span>👁 <b>' + fmt(d.viewers) + '</b> viewers</span>' +
          '<span class="' + (d.live ? '' : 'is-dim') + '">● <b>' + fmt(d.watching) + '</b> now</span>' +
          '<span>⚡ <b>' + fmt(d.peak) + '</b> peak</span>' +
          '<span>❤ <b>' + fmt(d.reactions) + '</b> reactions</span>' +
          '<span>✍ <b>' + fmt(d.entries) + '</b> updates</span>';
      })
      .catch(function () {});
  }
  var videoUrlEl = document.getElementById('gplbAdminVideoUrl');
  var videoStatusEl = document.getElementById('gplbAdminVideoStatus');
  function videoStatus(msg, err) {
    if (!videoStatusEl) return;
    videoStatusEl.textContent = msg || '';
    videoStatusEl.classList.toggle('is-err', !!err);
    if (msg) setTimeout(function () { videoStatusEl.textContent = ''; }, 4000);
  }
  function videoPost(url) {
    return api(REST + '/liveblogs/' + lbId + '/video', {
      method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify({ url: url })
    }).then(function (r) { return r.json(); });
  }
  var videoPinBtn = document.getElementById('gplbAdminVideoPin');
  if (videoPinBtn) videoPinBtn.addEventListener('click', function () {
    var u = (videoUrlEl.value || '').trim();
    if (!u) { videoStatus('Paste a YouTube/TikTok/IG link', true); return; }
    videoPost(u).then(function (d) {
      if (d && d.ok) { videoUrlEl.value = ''; videoStatus(d.pinned ? 'Video pinned ✓' : 'Failed', !d.pinned); }
      else videoStatus((d && d.message) || 'Not supported', true);
    }).catch(function () { videoStatus('Network error', true); });
  });
  var videoClearBtn = document.getElementById('gplbAdminVideoClear');
  if (videoClearBtn) videoClearBtn.addEventListener('click', function () {
    videoPost('').then(function (d) { if (d && d.ok) videoStatus('Video removed'); }).catch(function () { videoStatus('Network error', true); });
  });
  loadStats();
  setInterval(loadStats, 30000);
})();
