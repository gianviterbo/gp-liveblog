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

  /* ── anonymous visitor id (persists per browser for reactions/viewers) ── */
  var vid = '';
  try {
    vid = localStorage.getItem('gplb_vid') || '';
    if (!vid) {
      vid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
      localStorage.setItem('gplb_vid', vid);
    }
  } catch (e) { vid = 'v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12); }

  function ownReaction(eid) {
    try { return localStorage.getItem('gplb_react_' + eid) || ''; } catch (e) { return ''; }
  }
  function setOwnReaction(eid, emoji) {
    try { if (emoji) { localStorage.setItem('gplb_react_' + eid, emoji); } else { localStorage.removeItem('gplb_react_' + eid); } } catch (e) {}
  }

  /* ── entry rendering (mirrors server renderer) ── */
  var REACTIONS = ['like', 'smile', 'laugh', 'sad', 'dislike', 'doubt', 'angry'];
  var REACTION_ICO = { like: '👍', smile: '😊', laugh: '😂', sad: '😢', dislike: '👎', doubt: '🤔', angry: '😡' };

  function renderEntry(e) {
    var chip = esc(e.type);
    var body = '';
    if (e.type === 'image' && e.meta && e.meta.image_url) {
      body += e.raw ? '<p>' + esc(e.raw) + '</p>' : '';
      body += '<figure><img src="' + esc(e.meta.image_url) + '" alt="" loading="lazy"></figure>';
    } else if (e.type === 'link' || e.type === 'social') {
      var card = (e.meta && e.meta.link_card) ? e.meta.link_card : null;
      var url = (e.type === 'social') ? (e.meta.social_url || '') : (e.meta.link_url || '');
      var media = (e.meta && e.meta.media) ? e.meta.media : null;
      body += e.raw ? '<p>' + esc(e.raw) + '</p>' : '';
      if (media && media.type && media.embed) {
        var t = media.image ? '<img src="' + esc(media.image) + '" alt="" loading="lazy">' : '';
        body += '<div class="gplb-media' + (media.type === 'youtube' ? ' gplb-media--yt' : '') + '" data-media="' + esc(media.type) + '" data-embed="' + esc(media.embed) + '">'
          + '<a class="gplb-media-thumb" href="' + esc(url) + '" target="_blank" rel="noopener nofollow">' + t + '<span class="gplb-media-play" aria-hidden="true">▶</span></a>'
          + '<a class="gplb-media-meta" href="' + esc(url) + '" target="_blank" rel="noopener nofollow"><span class="gplb-card-host">' + esc(media.author || (media.type === 'youtube' ? 'YouTube' : media.type)) + '</span><span class="gplb-media-title">' + esc(media.title || '') + '</span></a></div>';
      } else if (card && card.title) {
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
    var react = '';
    if (e.type !== 'note') {
      var totals = e.reactions || {};
      var mine = ownReaction(e.id);
      react = '<footer class="gplb-react" data-entry="' + e.id + '">';
      REACTIONS.forEach(function (k) {
        var n = totals[k] || 0;
        react += '<button type="button" class="gplb-react-btn' + (mine === k ? ' is-me' : '') + '" data-emoji="' + k + '" aria-label="' + k + '" title="' + k + '"><span class="gplb-react-ico" aria-hidden="true">' + REACTION_ICO[k] + '</span><span class="gplb-react-n">' + n + '</span></button>';
      });
      var rtot = 0; for (var kk in totals) { rtot += totals[kk] || 0; }
      react += '<span class="gplb-react-total" hidden>' + rtot + '</span></footer>';
    }
    return '<article class="gplb-entry gplb-' + esc(e.type) + (e.type === 'note' ? ' gplb-note' : '') + '" data-id="' + e.id + '" data-type="' + esc(e.type) + '">'
      + '<header class="gplb-entry-head"><span class="gplb-who">' + esc(e.author) + '</span><span class="gplb-chip gplb-chip-' + esc(e.type) + '">' + (e.type === 'note' ? '🔒 ' : '') + chip + '</span><time class="gplb-time">' + esc(e.ts_h) + '</time></header>'
      + '<div class="gplb-entry-body">' + body + '</div>' + react + '</article>';
  }

  /* ── reactions: one per visitor per entry; click toggles/switches ── */
  function bindReactions(root) {
    var footers = (root && root.querySelectorAll) ? root.querySelectorAll('.gplb-react') : [];
    footers.forEach(function (foot) {
      if (foot.getAttribute('data-bound')) return;
      foot.setAttribute('data-bound', '1');
      var eid = parseInt(foot.getAttribute('data-entry'), 10) || 0;
      foot.querySelectorAll('.gplb-react-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var emoji = btn.getAttribute('data-emoji');
          var cur = ownReaction(eid);
          var remove = (cur === emoji);
          fetch(REST + '/liveblogs/' + currentLbId() + '/entries/' + eid + '/reactions', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
            body: JSON.stringify({ emoji: emoji, visitor: vid, remove: remove })
          })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (d && d.totals) {
                var map = d.totals.map || {};
                foot.querySelectorAll('.gplb-react-btn').forEach(function (b) {
                  var k = b.getAttribute('data-emoji');
                  b.querySelector('.gplb-react-n').textContent = map[k] || 0;
                  b.classList.toggle('is-me', d.reacted === k);
                });
                setOwnReaction(eid, d.reacted || '');
                bumpReactionTotals(d.totals.total);
              }
            })
            .catch(function () {});
        });
      });
    });
  }
  function currentLbId() {
    var tl = document.getElementById('gplbTimeline');
    if (tl) { var id = parseInt(tl.getAttribute('data-id'), 10) || cfg.current || 0; if (id) return id; }
    return (active[0] && active[0].id) || 0;
  }
  function bumpReactionTotals(total) {
    var el = document.querySelector('[data-gplb-stat="reactions"]');
    if (el && typeof total === 'number') { el.textContent = Number(total).toLocaleString(); }
  }

  /* ── media cards: YouTube plays inline on click ── */
  function initMediaPlay() {
    document.addEventListener('click', function (ev) {
      var thumb = ev.target.closest ? ev.target.closest('.gplb-media--yt .gplb-media-thumb') : null;
      if (!thumb) return;
      var box = thumb.closest('.gplb-media');
      if (!box) return;
      ev.preventDefault();
      var src = box.getAttribute('data-embed') || '';
      if (!src) return;
      var iframe = document.createElement('iframe');
      iframe.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
      iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
      iframe.setAttribute('allowfullscreen', '');
      iframe.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;';
      box.classList.add('gplb-media--playing');
      box.querySelector('.gplb-media-thumb').style.display = 'none';
      var wrap = document.createElement('div');
      wrap.style.cssText = 'position:relative;aspect-ratio:16/9;background:#000;';
      wrap.appendChild(iframe);
      box.insertBefore(wrap, box.querySelector('.gplb-media-meta'));
    });
  }

  /* ── stats chips: refresh via the public stats endpoint ── */
  function initStatsPoll() {
    var box = document.getElementById('gplbStats');
    var tl = document.getElementById('gplbTimeline');
    var id = tl ? (parseInt(tl.getAttribute('data-id'), 10) || cfg.current || 0) : 0;
    if (!box || !id) return;
    function refresh() {
      fetch(REST + '/liveblogs/' + id + '/stats')
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d) return;
          ['viewers', 'watching', 'peak', 'reactions', 'entries'].forEach(function (k) {
            var el = box.querySelector('[data-gplb-stat="' + k + '"]');
            if (el && typeof d[k] === 'number') { el.textContent = Number(d[k]).toLocaleString(); }
          });
          var liveOnly = box.querySelectorAll('.gplb-stat-liveonly');
          liveOnly.forEach(function (el) { el.hidden = !d.live; });
        })
        .catch(function () {});
    }
    refresh();
    setInterval(refresh, 30000);
  }

  /* ── pinned video: render + keep in sync via the entries poll ── */
  function applyPinned(pinned) {
    var box = document.getElementById('gplbPinned');
    if (!box) return;
    if (!pinned || !pinned.embed) {
      if (box.querySelector('.gplb-pinned-frame')) { box.innerHTML = ''; }
      return;
    }
    var sig = pinned.type + '|' + pinned.id;
    if (box.getAttribute('data-sig') === sig && box.querySelector('.gplb-pinned-frame')) { return; }
    var src = pinned.embed;
    var iframe = '<iframe title="' + esc(pinned.type === 'youtube' ? 'YouTube' : pinned.type) + '" src="' + esc(src) + '" width="100%" height="100%" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>';
    var removeBtn = cfg.canPost ? '<button type="button" class="gplb-pinned-x" id="gplbPinnedRemove">Remove</button>' : '';
    box.setAttribute('data-sig', sig);
    box.innerHTML = '<div class="gplb-pinned-frame" id="gplbPinnedFrame"><div class="gplb-pinned-head"><span class="gplb-pinned-label"><span aria-hidden="true">🎥</span> Live video</span>' + removeBtn + '</div><div class="gplb-pinned-ratio">' + iframe + '</div></div>';
    var rm = document.getElementById('gplbPinnedRemove');
    if (rm) rm.addEventListener('click', function () { unpinVideo(); });
  }
  function pinVideo(url) {
    return fetch(REST + '/liveblogs/' + currentLbId() + '/video', {
      method: 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
      body: JSON.stringify({ url: url })
    }).then(function (r) { return r.json(); });
  }
  function unpinVideo() {
    return pinVideo('').then(function (d) { if (d && d.ok) { applyPinned(null); } return d; });
  }
  function initVideoPin() {
    var pinBtn = document.getElementById('gplbLiveVideoPin');
    var status = document.getElementById('gplbLiveVideoStatus');
    var input = document.getElementById('gplbLiveVideoUrl');
    function setStatus(msg, err) {
      if (!status) return;
      status.textContent = msg || '';
      status.classList.toggle('is-err', !!err);
    }
    // static remove button (server-rendered frame)
    var staticRm = document.getElementById('gplbPinnedRemove');
    if (staticRm) staticRm.addEventListener('click', function () { unpinVideo().then(function () { setStatus('Video removed', false); }); });
    if (!pinBtn) return;
    pinBtn.addEventListener('click', function () {
      var u = (input.value || '').trim();
      if (!u) { setStatus('Paste a YouTube / TikTok / Instagram link', true); return; }
      pinBtn.disabled = true;
      setStatus('Pinning…', false);
      pinVideo(u).then(function (d) {
        pinBtn.disabled = false;
        if (d && d.ok) {
          input.value = '';
          applyPinned(d.pinned);
          setStatus(d.pinned ? 'Video pinned ✓' : 'Video removed', false);
        } else { setStatus((d && d.message) || 'Not supported — use YouTube, TikTok or Instagram', true); }
      }).catch(function () { pinBtn.disabled = false; setStatus('Network error', true); });
    });
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
        bindReactions(target);
        if (isLivePage && d.pinned !== undefined) applyPinned(d.pinned);
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
    fetch(REST + '/liveblogs/' + lbId + '/watch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ visitor: vid })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d) return;
        if (typeof d.watching === 'number') {
          document.querySelectorAll('[data-gplb-watching]').forEach(function (el) {
            el.textContent = Number(d.watching).toLocaleString();
          });
          var w = document.getElementById('gplbWatching');
          if (w) w.textContent = Number(d.watching).toLocaleString();
        }
        if (typeof d.viewers === 'number') {
          var v = document.querySelector('[data-gplb-stat="viewers"]');
          if (v) v.textContent = Number(d.viewers).toLocaleString();
          var pk = document.querySelector('[data-gplb-stat="peak"]');
          if (pk && typeof d.peak === 'number') pk.textContent = Number(d.peak).toLocaleString();
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
              bindReactions(el);
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
      // The card's body may be filled by the theme AFTER DOMContentLoaded,
      // so only treat a rendered .cov-cta as a valid anchor.
      var body = document.querySelector('.gp-coverage-card .cov-body');
      if (!body) return false;
      var cta = body.querySelector('.cov-cta');
      if (!cta) return false;
      if (body.querySelector('.gplb-coverage-btn')) return true; // already there (server hook / earlier run)
      var a = document.createElement('a');
      a.className = 'gplb-coverage-btn gplb-embed-toggle';
      a.href = c.url;
      a.innerHTML = '<span class="gplb-live-dot"></span><span class="gplb-embed-label">LIVE COVERAGE</span><span aria-hidden="true">↗</span>';
      // inline, right beside the CTA — same row, same height via CSS
      cta.parentNode.insertBefore(a, cta.nextSibling);
      return true;
    }
    if (inject()) return;
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      if (inject() || tries > 20) { clearInterval(timer); } // ~10s of retries
    }, 500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initLivePage();
    initLiveComposer();
    initFloat();
    initEmbeds();
    initCoverage();
    bindReactions(document);
    initMediaPlay();
    initStatsPoll();
    initVideoPin();
  });
})();
