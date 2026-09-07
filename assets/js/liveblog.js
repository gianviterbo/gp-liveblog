/* GP Liveblog — frontend runtime.
 *  - Live page: poll for new entries (append on top).
 *  - Floating LIVE button + role-aware panel (all pages while live).
 *  - Collapsible embeds.
 *  - Coverage-card bridge: insert LIVE COVERAGE button into the Special
 *    Coverage card when gplbCoverage is present.
 */
(function () {
  'use strict';
  if (!window.GPLB) return;
  var cfg = window.GPLB;
  var i18n = cfg.i18n || {};
  var REST = cfg.rest;
  var headers = { 'X-WP-Nonce': cfg.nonce };
  var since = {};     // liveblogId -> last seen entry id
  var active = cfg.active || [];
  var timer = null;
  var currentLb = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function host(url) { try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return url; } }
  function fmtTime() { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }

  /* ── entry rendering (mirrors server renderer) ── */
  function renderEntry(e) {
    var chip = esc(e.type);
    var body = '';
    if (e.type === 'image' && e.meta && e.meta.image_url) {
      body += e.raw ? '<p>' + esc(e.raw) + '</p>' : '';
      body += '<figure><img src="' + esc(e.meta.image_url) + '" alt="" loading="lazy"></figure>';
    } else if (e.type === 'link' || e.type === 'social') {
      var card = (e.meta && e.meta.link_card) ? e.meta.link_card : null;
      var url = (e.type === 'social') ? (e.meta.social_url || '') : (e.meta.link_url || '');
      body += e.raw ? '<p>' + esc(e.raw) + '</p>' : '';
      if (card && card.title) {
        body += '<a class="gplb-card' + (e.type === 'social' ? ' gplb-card--social' : '') + '" href="' + esc(url) + '" rel="nofollow noopener" target="_blank">'
          + (card.image ? '<span class="gplb-card-img"><img src="' + esc(card.image) + '" alt="" loading="lazy"></span>' : '')
          + '<span class="gplb-card-body"><span class="gplb-card-host">' + (e.type === 'social' ? '𝕏 ' : '') + esc(card.site || host(url)) + '</span>'
          + '<span class="gplb-card-title">' + esc(card.title) + '</span>'
          + (card.description ? '<span class="gplb-card-desc">' + esc(card.description) + '</span>' : '')
          + '</span></a>';
      } else {
        body += '<a class="gplb-card" href="' + esc(url) + '" rel="nofollow noopener" target="_blank">' + esc(url) + '</a>';
      }
    } else {
      body += esc(e.raw);
    }
    return '<article class="gplb-entry gplb-' + esc(e.type) + (e.type === 'note' ? ' gplb-note' : '') + '" data-id="' + e.id + '" data-type="' + esc(e.type) + '">'
      + '<header class="gplb-entry-head"><span class="gplb-who">' + esc(e.author) + '</span><span class="gplb-chip gplb-chip-' + esc(e.type) + '">' + (e.type === 'note' ? '🔒 ' : '') + chip + '</span><time class="gplb-time">' + esc(e.ts_h) + '</time></header>'
      + '<div class="gplb-entry-body">' + body + '</div></article>';
  }

  /* ── timeline polling (live page + embed feed + panel share this) ── */
  function poll(lbId, feedEl, isLivePage) {
    var url = REST + '/liveblogs/' + lbId + '/entries?after_id=' + (since[lbId] || 0) + '&t=' + Date.now();
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.entries) return;
        var target = isLivePage ? (function () {
          var tl = document.getElementById('gplbTimeline');
          return (tl && lbId === (parseInt(tl.getAttribute('data-id'), 10) || 0)) ? tl : null;
        })() : document.getElementById('gplbFeedList');
        if (!target) return;
        var prev = since[lbId] || 0;
        var fresh = d.entries.filter(function (e) { return e.id > prev; });
        fresh.forEach(function (e) { since[lbId] = Math.max(since[lbId] || 0, e.id); });
        if (!fresh.length) { if (isLivePage && d.live === false) document.body.classList.add('gplb-ended'); return; }
        // API returns newest-first. Build a fragment in that same order, then a
        // single insertBefore above the container's current first child (or
        // append when empty) — either way newest ends up on top.
        var frag = document.createDocumentFragment();
        fresh.forEach(function (e) {
          var node = document.createElement('div');
          node.innerHTML = renderEntry(e);
          var first = node.firstElementChild;
          if (!first) return;
          first.classList.add('gplb-new');
          frag.appendChild(first);
        });
        var empty = target.querySelector('.gplb-empty');
        if (empty) empty.remove();
        target.insertBefore(frag, target.firstChild);
        if (isLivePage && d.live === false) document.body.classList.add('gplb-ended');
      })
      .catch(function () { /* transient — next tick */ });
  }

  function startPolling(lbId, interval) {
    stopPolling();
    if (!lbId) return;
    timer = setInterval(function () { poll(lbId, null, true); }, interval || 20000);
  }
  function stopPolling() { if (timer) { clearInterval(timer); timer = null; } }

  function watchBeat(lbId) {
    if (!lbId) return;
    fetch(REST + '/liveblogs/' + lbId + '/watch', { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && typeof d.watching === 'number') {
          document.querySelectorAll('[data-gplb-watching]').forEach(function (el) {
            el.textContent = Number(d.watching).toLocaleString();
          });
          var w = document.getElementById('gplbWatching');
          if (w) w.textContent = Number(d.watching).toLocaleString();
        }
      })
      .catch(function () {});
  }

  /* ── live page: seed since from first entry, start poll ── */
  function initLivePage() {
    var tl = document.getElementById('gplbTimeline');
    if (!tl) return;
    var id = parseInt(tl.getAttribute('data-id'), 10) || cfg.current || 0;
    if (!id) return;
    var first = tl.querySelector('.gplb-entry');
    since[id] = first ? parseInt(first.getAttribute('data-id'), 10) || 0 : 0;
    if (cfg.canPost) document.body.classList.add('gplb-canpost');
    startPolling(id, 20000);
    watchBeat(id);
    setInterval(function () { watchBeat(id); }, 30000);
  }

  /* ── floating panel ── */
  function initFloat() {
    var wrap = document.getElementById('gplbFloat');
    if (!wrap || !active.length) return;
    wrap.hidden = false;
    var panel = document.getElementById('gplbPanel');
    var btn = document.getElementById('gplbFloatBtn');
    var titleEl = document.getElementById('gplbPanelTitle');
    var subEl = document.getElementById('gplbPanelSub');
    var openLink = document.getElementById('gplbOpenFull');
    var composer = document.getElementById('gplbComposer');
    var feedList = document.getElementById('gplbFeedList');

    currentLb = active[0];
    titleEl.textContent = currentLb.title;
    subEl.textContent = currentLb.subtitle || '';
    openLink.href = currentLb.permalink;
    since[currentLb.id] = 0;

    // composer visible only to editors/admins
    if (cfg.canPost) {
      composer.hidden = false;
      document.body.classList.add('gplb-canpost');
    }

    // seed recent entries + poll the panel feed on the same cadence
    poll(currentLb.id, null, false);
    setInterval(function () {
      if (!panel.hidden && currentLb) poll(currentLb.id, null, false);
    }, 20000);

    btn.addEventListener('click', function () {
      var open = panel.hidden;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) watchBeat(currentLb.id);
    });
    document.getElementById('gplbPanelX').addEventListener('click', function () {
      panel.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    });

    // composer tool switching + url/image rows
    var toolType = 'update';
    var tools = composer.querySelectorAll('.gplb-tool');
    tools.forEach(function (t) {
      t.addEventListener('click', function () {
        tools.forEach(function (x) { x.classList.remove('is-on'); });
        t.classList.add('is-on');
        toolType = t.getAttribute('data-type');
        document.getElementById('gplbUrlRow').hidden = !(toolType === 'link' || toolType === 'social');
        document.getElementById('gplbImgRow').hidden = (toolType !== 'image');
        var ph = toolType === 'link' || toolType === 'social' ? '' : (toolType === 'note' ? '🔒 Team note (staff only)…' : '');
        var ta = document.getElementById('gplbText');
        ta.placeholder = ph || i18n.postUpdate;
      });
    });

    var imgAtt = 0;
    document.getElementById('gplbImg').addEventListener('change', function () {
      var f = this.files[0];
      if (!f) return;
      var fd = new FormData();
      fd.append('file', f);
      setStatus('Uploading…', false);
      fetch(REST + '/upload-image', { method: 'POST', headers: headers, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.attachment_id) {
            imgAtt = d.attachment_id;
            setStatus('Image ready ✓', false);
          } else { setStatus(d.message || 'Upload failed', true); }
        })
        .catch(function () { setStatus('Upload failed', true); });
    });

    document.getElementById('gplbPublish').addEventListener('click', postFromPanel);
    document.getElementById('gplbText').addEventListener('keydown', function (ev) {
      if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') postFromPanel();
    });

    function setStatus(msg, err) {
      var s = document.getElementById('gplbStatus');
      s.textContent = msg;
      s.classList.toggle('is-err', !!err);
    }
    function postFromPanel() {
      if (!currentLb || !cfg.canPost) return;
      var ta = document.getElementById('gplbText');
      var text = ta.value.trim();
      var payload = { type: toolType, text: text };
      if (toolType === 'image') {
        if (!imgAtt) { setStatus('Attach an image first', true); return; }
        payload.image_id = imgAtt;
      }
      if (toolType === 'link' || toolType === 'social') {
        var u = document.getElementById('gplbUrl').value.trim();
        if (!u) { setStatus(i18n.placeholder, true); return; }
        payload.url = u;
      }
      var btn2 = document.getElementById('gplbPublish');
      btn2.disabled = true;
      setStatus('Publishing…', false);
      fetch(REST + '/liveblogs/' + currentLb.id + '/entries', {
        method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn2.disabled = false;
          if (d.ok && d.entry) {
            ta.value = '';
            if (document.getElementById('gplbUrl')) document.getElementById('gplbUrl').value = '';
            imgAtt = 0;
            setStatus('Posted ✓', false);
            // immediately show it
            var node = document.createElement('div');
            node.innerHTML = renderEntry(d.entry);
            var el = node.firstElementChild;
            el.classList.add('gplb-new');
            feedList.insertBefore(el, feedList.firstChild);
            since[currentLb.id] = d.entry.id;
          } else { setStatus((d.message) || 'Failed', true); }
        })
        .catch(function () { btn2.disabled = false; setStatus('Network error', true); });
    }
  }

  /* ── in-page composer (live page; Admins/Editors only — markup is
     server-rendered just for them, this wires it) ── */
  function initLiveComposer() {
    var timeline = document.getElementById('gplbTimeline');
    var text = document.getElementById('gplbLiveText');
    if (!timeline || !text || !cfg.canPost) return; // viewers never get the markup
    var id = parseInt(timeline.getAttribute('data-id'), 10) || cfg.current || 0;
    if (!id) return;
    var status = document.getElementById('gplbLiveStatus');
    var publish = document.getElementById('gplbLivePublish');
    var urlRow = document.getElementById('gplbLiveUrlRow');
    var url = document.getElementById('gplbLiveUrl');
    var imgRow = document.getElementById('gplbLiveImgRow');
    var img = document.getElementById('gplbLiveImg');
    var tools = timeline.closest('.gplb-single').querySelectorAll('.gplb-tool');
    var toolType = 'update';

    function setStatus(msg, err) {
      status.textContent = msg;
      status.classList.toggle('is-err', !!err);
    }

    tools.forEach(function (t) {
      t.addEventListener('click', function () {
        tools.forEach(function (x) { x.classList.remove('is-on'); });
        t.classList.add('is-on');
        toolType = t.getAttribute('data-type');
        urlRow.hidden = !(toolType === 'link' || toolType === 'social');
        imgRow.hidden = (toolType !== 'image');
        text.placeholder = (toolType === 'link' || toolType === 'social') ? ''
          : (toolType === 'note' ? '🔒 Team note (staff only)…' : i18n.postUpdate);
      });
    });

    var imgAtt = 0;
    img.addEventListener('change', function () {
      var f = this.files[0];
      if (!f) return;
      var fd = new FormData();
      fd.append('file', f);
      setStatus('Uploading…', false);
      fetch(REST + '/upload-image', { method: 'POST', headers: headers, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.attachment_id) {
            imgAtt = d.attachment_id;
            setStatus('Image ready ✓', false);
          } else { setStatus(d.message || 'Upload failed', true); }
        })
        .catch(function () { setStatus('Upload failed', true); });
    });

    function post() {
      var payload = { type: toolType, text: text.value.trim() };
      if (toolType === 'image') {
        if (!imgAtt) { setStatus('Attach an image first', true); return; }
        payload.image_id = imgAtt;
      }
      if (toolType === 'link' || toolType === 'social') {
        var u = url.value.trim();
        if (!u) { setStatus(i18n.placeholder, true); return; }
        payload.url = u;
      }
      publish.disabled = true;
      setStatus('Publishing…', false);
      fetch(REST + '/liveblogs/' + id + '/entries', {
        method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          publish.disabled = false;
          if (d.ok && d.entry) {
            text.value = '';
            url.value = '';
            imgAtt = 0;
            setStatus('Posted ✓', false);
            var empty = timeline.querySelector('.gplb-empty');
            if (empty) empty.remove();
            var node = document.createElement('div');
            node.innerHTML = renderEntry(d.entry);
            var el = node.firstElementChild;
            if (el) {
              el.classList.add('gplb-new');
              timeline.insertBefore(el, timeline.firstChild);
              since[id] = d.entry.id; // keep the poll from re-inserting it
            }
          } else { setStatus((d.message) || 'Failed', true); }
        })
        .catch(function () { publish.disabled = false; setStatus('Network error', true); });
    }
    publish.addEventListener('click', post);
    text.addEventListener('keydown', function (ev) {
      if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') post();
    });
  }

  /* ── embed toggles ── */
  function initEmbeds() {
    document.querySelectorAll('.gplb-embed-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var panel = btn.parentElement.querySelector('.gplb-embed-panel');
        var open = panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
  }

  /* ── coverage card bridge (theme Special Coverage) ── */
  function initCoverage() {
    if (!window.gplbCoverage) return;
    var c = window.gplbCoverage;
    function inject() {
      var card = document.querySelector('.gp-coverage-card .cov-body, .gp-coverage-card');
      if (!card) return;
      var existing = card.querySelector('.gplb-coverage-btn');
      if (existing) return;
      var a = document.createElement('a');
      a.className = 'gplb-coverage-btn gplb-embed-toggle';
      a.href = c.url;
      a.innerHTML = '<span class="gplb-live-dot"></span><span class="gplb-embed-label">LIVE COVERAGE</span><span aria-hidden="true">↗</span>';
      a.style.cssText = 'margin-top:12px;text-decoration:none;font-size:13px;';
      var wrap = document.createElement('div');
      wrap.className = 'gplb-coverage-wrap';
      wrap.appendChild(a);
      card.appendChild(wrap);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', inject);
    } else { inject(); }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initLivePage();
    initLiveComposer();
    initFloat();
    initEmbeds();
    initCoverage();
  });
})();
