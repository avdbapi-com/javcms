/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS ads: admin preview and visual place picker (ADS-BRIEF 4.4, 4.7, 8.1). Loaded by the head snippet only when
   the browser has the preview hint cookie jcxh=1 or the URL fragment #jcx-pick=<nonce>. All data comes from the
   no-store endpoint index.php/jcx/pv behind the HMAC cookie jcxp; visitors never load this file.
   The anchor resolver below follows the same grammar and order as the PHP JcxPlacer (8.1): hook marker > admin
   override > theme profile > default map; it never counts our own boxes. */
(function (w, d) {
  if (w.JCXP) return;
  var hs = d.getElementById('jcx-h'), ds = hs ? hs.dataset : {}, B = ds.b || '/', API = B + 'index.php/jcx/', T = ds.t, TH = ds.th;
  var hash = location.hash, pick = hash.indexOf('#jcx-pick=') == 0, P = {}, PV = {}, RE = /^(?:@player|@grid:\d{1,2}|@section-of-grid:\d{1,2}|[A-Za-z0-9_#.\-\[\]= ]{1,200})$/;
  var OWN = '.jcx-s,.jcx-c,.jcx-pv,.jcx-pp,.jcx-pk';
  function qp(k) { var m = hash.match(new RegExp('[#&]' + k + '=([^&]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function E(t, c, a, x) { var e = d.createElement(t), k; if (c) e.className = c; for (k in a) a[k] != null && e.setAttribute(k, a[k]); if (x != null) e.textContent = x; return e; }
  function own(n) { return n.closest && n.closest(OWN); }
  function hidden(n) { return !!n.closest('[hidden]'); }
  function grid(n) { return [].filter.call(d.querySelectorAll('[data-grid-fill]'), function (g) { return !hidden(g) && !own(g); })[n - 1] || null; }
  /* resolve one anchor: returns [{el, pos}] (in-feed: several "before child" positions) or null */
  function resolve(a) {
    var at = a.at || '', el = null, m, i, k;
    if (!RE.test(at)) return null;
    if (at == '@player') { [].some.call(d.scripts, function (s) { if (s.textContent.slice(0, 400).indexOf('player_aaaa') > -1) { el = s.parentElement; return 1; } }); }
    else if ((m = at.match(/^@grid:(\d+)$/))) el = grid(+m[1] || 1);
    else if ((m = at.match(/^@section-of-grid:(\d+)$/))) {
      k = +m[1] || 1; var g = grid(k), prev = k > 1 ? grid(k - 1) : null;
      if (g) { el = g; for (var p = g.parentElement; p && p.tagName != 'MAIN' && p.id != 'main-content' && p.tagName != 'BODY'; p = p.parentElement) { if (prev && p.contains(prev)) break; el = p; } }
    } else {
      try { el = [].filter.call(d.querySelectorAll(at), function (n) { return !own(n); })[Math.max(1, a.nth || 1) - 1] || null; } catch (e) { return null; }
    }
    if (!el) return null;
    if (a.every) {
      var kids = [].filter.call(el.children, function (c) { return !own(c); }), out = [];
      if (kids.length <= a.every) return null;
      for (i = a.every; i < kids.length && out.length < (a.max || 1); i += a.every) out.push({ el: kids[i], pos: 'beforebegin', cell: kids[0] });
      return out;
    }
    return [{ el: el, pos: a.pos || 'afterend' }];
  }
  /* all places of this page: marker first, then the merged map from jcx/pv */
  function places() {
    var out = {}, mk = {};
    [].forEach.call(d.querySelectorAll('div[data-jcx-at]'), function (n) { var p = n.getAttribute('data-jcx-at'); if (/^P(0[1-9]|1[0-6]|03d)$/.test(p) && !mk[p]) mk[p] = [{ el: n, pos: 'afterend' }]; });
    Object.keys(PV.anchors || {}).forEach(function (p) {
      var a = PV.anchors[p], r = mk[p] || resolve(a);
      if (!r && a.fallback) { var b = Object.assign({}, a, a.fallback); delete b.nth; r = resolve(b); }
      if (r) out[p] = r;
    });
    Object.keys(mk).forEach(function (p) { out[p] = mk[p]; });
    return out;
  }
  w.JCXP = { resolve: resolve, places: places, setAnchors: function (a) { PV.anchors = a; } };
  /* ---------- shared UI ---------- */
  var css = '.jcx-pv{outline:2px dashed #f2a33a;outline-offset:2px;position:relative;min-height:40px}.jcx-pv[data-empty]{display:grid;place-items:center;font:600 12px/1.3 system-ui,sans-serif;color:#f2a33a}'
    + '.jcx-pc{display:inline-block;margin:2px auto;padding:1px 6px;border-radius:4px;background:#f2a33a;color:#111;font:600 11px/16px system-ui,sans-serif}.jcx-pi{margin:6px auto}'
    + '.jcx-pp{position:fixed;z-index:2147483600;inset-block-end:12px;inset-inline-start:12px;width:min(320px,calc(100vw - 24px));max-height:60vh;overflow:auto;padding:10px;border-radius:10px;background:#111;color:#eee;font:13px/1.4 system-ui,sans-serif;box-shadow:0 8px 30px rgb(0 0 0/.5)}'
    + '.jcx-pp b{display:block;margin:6px 0 2px}.jcx-pp button,.jcx-pp select,.jcx-pp a{margin:2px 4px 2px 0;padding:3px 8px;border:1px solid #555;border-radius:6px;background:#222;color:#eee;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}'
    + '.jcx-pk{display:grid;place-items:center;width:100%;min-height:60px;margin:8px 0;border:2px dashed #3a9df2;border-radius:8px;background:rgb(58 157 242/.12);color:inherit;font:600 14px/1.3 system-ui,sans-serif;cursor:pointer}'
    + '.jcx-pk[disabled]{border-color:#888;color:#888;cursor:not-allowed}.jcx-pk:focus{outline:3px solid #3a9df2}'
    + '.jcx-pp .jcx-ph{display:block;width:100%;margin:0;text-align:start}.jcx-ppc>:not(.jcx-ph){display:none!important}'
    + '.jcx-pp.jcx-ppt{inset-block:12px auto}.jcx-pp.jcx-ppe{inset-inline:auto 12px}'
    + '.jcx-pf{position:fixed;inset:0;z-index:2147483500;width:100%;height:100%;border:0;background:transparent;color-scheme:light}';
  function style() { d.head.appendChild(E('style', 0, { id: 'jcx-pcss' }, css)); }
  function insert(r, n) {
    if (r.cell) { var t = r.cell; n = E(t.tagName.toLowerCase(), t.className + ' jcx-c jcx-pv', { 'data-jcx': n.getAttribute('data-jcx') }); }
    r.el.insertAdjacentElement(r.pos, n);
    return n;
  }
  function name(p) { return (PV.s || {})['pl_' + p] || p; }
  /* the plain name of an item (jcx/pv nm without the "Example Xnn · " prefix), else its code */
  function nm(it) { return String(it.nm || '').replace(/^[^·]*X\d\d\s*·\s*/, '') || (it.x || '#' + it.id) + ' ' + it.u; }
  /* network / pasted code in the preview runs in a sandboxed frame (R2-F7): it can never reach this page or the admin */
  var SBX = 'allow-scripts allow-popups allow-popups-to-escape-sandbox';
  function boxed(it) { return it.c || it.cm ? Object.assign({}, it, { md: 2 }) : it; }
  var cf;
  function code(it) {
    var f = E('iframe', 'jcx-pf', { title: PV.fs.r, sandbox: SBX }), x = function (e) {
      if (e && e.type == 'keydown' && e.key != 'Escape') return;
      if (e && e.type == 'message' && e.source != f.contentWindow) return;
      f.remove(); removeEventListener('keydown', x); removeEventListener('message', x); cf = 0;
    };
    cf && cf();
    /* window.JcxT inside the sealed frame: the code's visitor-language strings (jcx/pv tk, CONTRACT-3 E8) */
    f.srcdoc = '<!doctype html><meta name="color-scheme" content="light"><base target="_blank"><style>html,body{margin:0;min-height:100%;background:transparent}</style>'
      + '<script>window.JcxT=function(k,f){return(' + JSON.stringify(PV.tk || {}).replace(/</g, '\\u003c') + ')[String(k).replace("ui.","")]||f||""}</' + 'script>'
      + (innerWidth < 768 && it.cm ? it.cm : it.c || it.cm)
      + '<script>addEventListener("click",function(){setTimeout(function(){parent.postMessage("jcx-pf","*")},300)},true)</' + 'script>';
    d.body.appendChild(f);
    addEventListener('keydown', x); addEventListener('message', x);
    cf = x;
    info2.textContent = PV.s.pv_code_hint || 'Click anywhere on the page to see what this code does. Esc closes it.';
    /* fold the panel at every width and keep it at the bottom edge: inside the sealed frame the X34 card draws at the
       top-end corner (CONTRACT-3), so the code's own box is not under it; the header keeps the hint as its tooltip */
    fold(); pan0.classList.remove('jcx-ppt'); pan0.firstChild.title = info2.textContent;
  }
  /* after a Show press (R6r3-02 / R4r3-02): the panel folds, and the folded bar goes to the first screen corner that
     touches no shown ad box (bottom start, top start, bottom end, top end), checked again while the layer appears */
  function fold() { pan0.classList.add('jcx-ppc'); pan0.firstChild.setAttribute('aria-expanded', 'false'); }
  function park(n) {
    var ads = [].map.call(d.querySelectorAll('.jcx-ki,.jcx-p,.jcx-r,[data-jcx-fx]'), function (b) { return getComputedStyle(b).visibility != 'hidden' && b.getBoundingClientRect(); }).filter(function (r) { return r && r.width; });
    fold();
    [0, 1, 2, 3].some(function (i) {
      pan0.classList.toggle('jcx-ppt', i & 1); pan0.classList.toggle('jcx-ppe', i > 1);
      var r = pan0.getBoundingClientRect();
      return !ads.some(function (a) { return !(a.bottom <= r.top || a.top >= r.bottom || a.right <= r.left || a.left >= r.right); });
    });
    if (n < 12) setTimeout(park, 250, n + 1);
  }
  function seal(n) { if (n) [].forEach.call(n.tagName == 'IFRAME' ? [n] : n.querySelectorAll('iframe[srcdoc]'), function (f) { f.sandbox = SBX; }); return n; }
  var FIXP = { P08: 1, P09: 1, P10: 1, P11: 1, P15h: 1, P15b: 1 };
  /* ---------- picker (4.4): numbered "Put it here" buttons; a click posts {jcx:'pick', place, n} to the parent ---------- */
  function picker() {
    var n = qp('jcx-pick'), fw = +qp('w') || 0, fh = +qp('h') || 0, i = 0, list = E('div', 'jcx-pp', { role: 'list' }), pl = places();
    style();
    var send = function (p) { if (w.parent !== w) w.parent.postMessage({ jcx: 'pick', place: p, n: n }, location.origin); };
    Object.keys(pl).forEach(function (p) {
      var r = pl[p][0], b = E('button', 'jcx-pk', { type: 'button', 'data-jcx': p }, ++i + '. ' + (PV.s.put_here || 'Put it here') + ' · ' + name(p));
      if (fh) b.style.minHeight = Math.min(fh, 300) + 'px';
      b = insert(r.cell ? { el: r.el, pos: r.pos } : r, b);
      var room = b.parentElement ? b.parentElement.clientWidth : 0;
      if (fw && room && fw > room) { b.disabled = true; b.title = fw + 'px > ' + room + 'px'; }
      b.addEventListener('click', function () { send(p); });
    });
    ['P08', 'P09', 'P10'].forEach(function (p) { var b = E('button', 0, { type: 'button' }, name(p)); b.addEventListener('click', function () { send(p); }); list.appendChild(b); });
    d.body.appendChild(list);
    if (w.parent !== w) w.parent.postMessage({ jcx: 'picker', places: Object.keys(pl), n: n }, location.origin);
  }
  /* ---------- preview (4.7): every place outlined, items stacked with chips, layers on demand, density, CLS ---------- */
  /* page movement counts from 0.5 s after the panel has built the page: the preview's own stacked boxes and chips move
     the page while they are inserted, a visitor never gets that (R4r2-07) */
  var cls = 0, info2, pan0, T0 = 1 / 0;
  try { new PerformanceObserver(function (l) { l.getEntries().forEach(function (e) { if (!e.hadRecentInput && e.startTime > T0) cls += e.value; }); }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
  /* chip: state + plain name (+ size and place when the name does not say them already); the internal codes (#id, Xnn,
     Uxx) only in the tooltip (R4r2-06) */
  function chip(it) {
    var z = it.sz || [], t = nm(it), s = z[0] + '×' + z[1];
    if (z[0] && t.indexOf(s) < 0) t += ' · ' + s;
    if (it.p && t.indexOf(name(it.p)) < 0) t += ' · ' + name(it.p);
    return E('span', 'jcx-pc', { title: (it.x || '#' + it.id) + ' · ' + it.u, 'data-jcx-x': it.x || '', 'data-jcx-id': it.id }, (it.ex ? PV.s.example_chip || 'Example' : it.st ? PV.s.pv_on || 'On' : PV.s.pv_off || 'Off') + ' · ' + t);
  }
  /* share of the screen covered by ad boxes: stacked = every box as the preview shows it (added up, capped at 100 %);
     real = what a visitor gets (in each slot only its first item, plus the shown fixed units), the union of the boxes
     on the screen, measured on a 16 px grid, so overlapping boxes count once (R3r2-05) */
  function density(real) {
    var a = 0, W = innerWidth, H = innerHeight, rs = [], x, y, n = 0;
    [].forEach.call(d.querySelectorAll('.jcx-s,.jcx-c,.jcx-k,.jcx-p,.jcx-r,.jcx-m'), function (b) {
      if (getComputedStyle(b).display == 'none' || getComputedStyle(b).visibility == 'hidden') return;
      var r = b.getBoundingClientRect(), f = real && b.querySelector('.jcx-pi');
      if (f) { var q = f.getBoundingClientRect(); r = { left: r.left, right: r.right, top: r.top, bottom: Math.min(r.bottom, q.bottom) }; }
      if (real && b.hasAttribute('data-empty')) return;
      rs.push(r);
      a += Math.max(0, Math.min(r.right, W) - Math.max(r.left, 0)) * Math.max(0, Math.min(r.bottom, H) - Math.max(r.top, 0));
    });
    if (!real) return Math.min(100, Math.round(100 * a / (W * H)));
    for (y = 8; y < H; y += 16) for (x = 8; x < W; x += 16) if (rs.some(function (r) { return x >= r.left && x < r.right && y >= r.top && y < r.bottom; })) n++;
    return Math.round(100 * n / (Math.ceil(W / 16) * Math.ceil(H / 16)));
  }
  function preview() {
    var h = w.JCX._h, items = (PV.it || []).filter(function (it) { return h.ok(it, 1); }), pl = places(), pan = E('div', 'jcx-pp', { role: 'region', 'aria-label': PV.s.pv_panel }), lay = [], info;
    /* the preview never waits for the LCP guard (R4r3-01): nothing here is measured by Chrome for a visitor */
    h.go && h.go();
    style();
    Object.keys(pl).forEach(function (p) {
      if (FIXP[p]) return;
      var mine = items.filter(function (it) { return it.p == p || (p == 'P03' && it.p == 'P04' && !pl.P04); }), cells = pl[p].slice(0, p == 'P05' ? 2 : 1);
      cells.forEach(function (r, i) {
        var box = d.querySelectorAll('[data-jcx="' + p + '"]:is(.jcx-s,.jcx-c)')[i] || E('div', 'jcx-s jcx-pv', { 'data-jcx': p, role: 'region', 'aria-label': PV.fs.r });
        if (!box.isConnected) box = insert(r, box);
        box.classList.add('jcx-pv'); box._jcx = 1;
        /* in-feed: the items are shared out over the cells and stacked inside each one (4.7 4b: every item shows) */
        var list = mine.filter(function (it, j) { return j % cells.length == i; });
        /* an empty place always says which place it is, also a server-printed box whose only child is the "Ad" label (R4r3-04) */
        if (!list.length) { box.setAttribute('data-empty', ''); box.querySelector('img,video,iframe,a,.jcx-pi,.jcx-pe') || box.appendChild(E('span', 'jcx-pe', 0, (PV.s.pv_empty_place || '%s (empty)').replace('%s', name(p)))); }
        list.forEach(function (it) {
          var n = seal(h.build(boxed(it), box)), wrap = E('div', 'jcx-pi');
          wrap.appendChild(chip(it));
          if (n) wrap.appendChild(n); else wrap.appendChild(E('div', 0, 0, '—'));
          box.appendChild(wrap);
        });
      });
    });
    /* U14 (no place): the "Sponsored" label on the target's cards and on its own page, each with the chip */
    items.forEach(function (it) {
      if (it.u != 'U14' || !it.tg) return;
      var re = RegExp('/' + it.tg + '(/|-sv)'), h1 = d.querySelector('h1'), c = 0, vis = [];
      [].forEach.call(d.querySelectorAll('a[href]'), function (a) {
        if (own(a) || !re.test(a.pathname) || !a.querySelector('img')) return;
        a.rel = (a.rel + ' sponsored').trim(); a.appendChild(E('span', 'jcx-sp', 0, PV.fs.sp));
        if (a.getClientRects().length && a.getBoundingClientRect().width) vis.push(a);
      });
      /* the chip goes after a card the admin can see: one in a video grid first (sliders rotate their cards away) */
      if ((c = vis.filter(function (a) { return a.closest('[data-grid-fill]'); })[0] || vis[0])) c.insertAdjacentElement('afterend', chip(it));
      if (h1 && re.test(location.pathname)) { h1.appendChild(E('span', 'jcx-sp', 0, PV.fs.sp)); c || h1.insertAdjacentElement('afterend', chip(it)); }
    });
    if (/^item-/.test(PV.scope || '') && items[0]) [].forEach.call(d.querySelectorAll('.jcx-s[data-jcx="' + items[0].p + '"][data-jcx-i]'), function (b) {
      if (b.getAttribute('data-jcx-i') != items[0].id) [].slice.call(b.children).forEach(function (c) { c.classList.contains('jcx-l') || c.classList.contains('jcx-pi') || c.remove(); });
    });
    items.forEach(function (it) { if (FIXP[it.p] && it.p != 'P11' || it.u == 'U03' || it.u == 'U04') lay.push(it); });
    var vid = items.filter(function (it) { return it.p == 'P11'; });
    /* the header folds the panel (folded at first on phones, where it would cover the player and its Play button) */
    var ph0 = innerWidth < 768, hd = E('button', 'jcx-ph', { type: 'button', 'aria-expanded': !ph0 });
    hd.appendChild(E('b', 0, 0, PV.s.pv_panel + ' · ' + (PV.s['pv_pg_' + T] || T) + ' · ' + TH + ' ▾'));
    hd.addEventListener('click', function () { hd.setAttribute('aria-expanded', !pan.classList.toggle('jcx-ppc')); });
    if (ph0) pan.classList.add('jcx-ppc');
    pan.appendChild(hd); pan0 = pan;
    info = E('div'); pan.appendChild(info);
    info2 = E('div', 0, { role: 'status' });
    if (lay.length) {
      pan.appendChild(E('b', 0, 0, PV.s.pv_layers));
      var gut = function () {
        var m = (PV.ui && [].concat(PV.ui.rs || []).map(function (s) { try { return d.querySelector(s); } catch (e) {} }).filter(Boolean)[0]) || d.getElementById('main-content'), r = m && m.getBoundingClientRect();
        return r ? Math.round(Math.min(r.left, innerWidth - r.right)) : 0;
      };
      lay.forEach(function (it) {
        var b = E('button', 0, { type: 'button', 'data-jcx-id': it.id, 'data-jcx-x': it.x || '', title: (it.x || '#' + it.id) + ' · ' + it.u }, (PV.s.pv_show || 'Show') + ': ' + nm(it)), why = '', ph = innerWidth < 768, z = it.sz || [];
        if (it.d == 2 && !ph) why = PV.s.pv_phone_only; else if (it.d == 1 && ph) why = PV.s.pv_pc_only;
        else if (it.u == 'U04' || it.p == 'P08') { var g = gut(), need = Math.max(180, (z[0] || 160) + 16); if (ph || g < need) why = (PV.s.pv_rails_no || '').replace('%1', need).replace('%2', Math.max(0, g)); }
        if (why) { b.disabled = true; b.title = why; b.textContent += ' — ' + why; }
        b.addEventListener('click', function () {
          if (it.u == 'U08' && !(it.o && it.o.trigger == 'load')) info2.textContent = PV.s.pv_u08_hint || '';
          /* dl 0.1 s: jcx-l reads 0 as "unit default" (15-30 s); the session "shown" memory is ignored in preview */
          var c = Object.assign({}, it, { dl: .1, cap: 0, o: Object.assign({}, it.o, { trigger: it.o && it.o.trigger == 'link' ? 'link' : 'delay' }) });
          try { sessionStorage.removeItem('jcx.s' + it.id); } catch (e) {}
          /* P15 network code (U10 click script, U11 push prompt): it runs as the page would run it, still sealed in a
             sandboxed frame (R2-F7), but the frame is the whole screen, transparent and on top, so the code's own box
             shows where the code puts it (a corner) and the first click reaches its click handler. The frame goes
             at the first click inside it (after the code had 300 ms to act) or with Esc (R6r2-01 / R1r2-01). */
          if (it.c || it.cm) return code(it);
          (w.JcxL ? Promise.resolve() : new Promise(function (r) { var s = E('script', 0, { src: B + 'static/jcx/jcx-l.js' }); s.onload = s.onerror = r; d.head.appendChild(s); })).then(function () { w.JcxL && JcxL([c]); park(0); });
        });
        pan.appendChild(b);
      });
      pan.appendChild(info2);
    }
    if (vid.length) {
      pan.appendChild(E('b', 0, 0, PV.s.pv_play_with));
      var sel = E('select', 0, { 'aria-label': PV.s.pv_play_with });
      vid.forEach(function (it) { sel.appendChild(E('option', 0, { value: it.id }, nm(it))); });
      sel.addEventListener('change', function () { try { sessionStorage.setItem('jcx.pvv', sel.value); } catch (e) {} h.emit('pvplay', { id: +sel.value }); });
      var pvv = 0;
      try { pvv = +sessionStorage.getItem('jcx.pvv'); if (!vid.some(function (it) { return it.id == pvv; })) sessionStorage.setItem('jcx.pvv', pvv = vid[0].id); } catch (e) { pvv = vid[0].id; }
      sel.value = pvv;
      pan.appendChild(sel);
    }
    pan.appendChild(E('b', 0, 0, PV.s.pv_theme));
    var ts = E('select', 0, { 'aria-label': PV.s.pv_theme });
    (PV.themes || []).forEach(function (t) { var o = E('option', 0, { value: t }, t); if (t == TH) o.selected = true; ts.appendChild(o); });
    ts.addEventListener('change', function () { location.href = API + 'pv?theme=' + encodeURIComponent(ts.value) + '&n=' + encodeURIComponent(PV.tnonce) + '&r=' + encodeURIComponent(location.pathname + location.search); });
    pan.appendChild(ts);
    /* page chooser (9.4): jump to the sample home / list / detail / watch / search pages of jcx/pv */
    if (PV.pages) {
      pan.appendChild(E('b', 0, 0, PV.s.pv_page));
      var ps = E('select', 0, { 'aria-label': PV.s.pv_page });
      ['home', 'list', 'detail', 'play', 'search'].forEach(function (k) { if (PV.pages[k]) { var o = E('option', 0, { value: PV.pages[k] }, PV.s['pv_pg_' + k] || k); if (k == T) o.selected = true; ps.appendChild(o); } });
      ps.addEventListener('change', function () { location.href = ps.value; });
      pan.appendChild(ps);
    }
    var btn =function (t, f) { var b = E('button', 0, { type: 'button' }, t); b.addEventListener('click', f); pan.appendChild(b); };
    pan.appendChild(E('b'));
    btn(PV.s.pv_phone, function () { w.open(location.href, 'jcxphone', 'width=390,height=844'); });
    btn(PV.s.pv_reset_caps, function () { try { localStorage.removeItem('jcx.f'); Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('jcx.') == 0) sessionStorage.removeItem(k); }); } catch (e) {} location.reload(); });
    btn(PV.s.pv_exit, function () { fetch(API + 'pv?exit=1&th=1', { credentials: 'same-origin' }).then(function () { location.reload(); }); });
    /* P3-01: the live ads (pre-roll included) stay off in this browser while the preview runs */
    if (PV.s.pv_paused) pan.appendChild(E('div', 0, 0, PV.s.pv_paused));
    d.body.appendChild(pan);
    /* plain words: each figure with its verdict against the limits (phone 25 / 30 %, computer 40 / 50 %, movement 0.1).
       In the stacked scopes the stacked figure is not a verdict ("—" + the note); the coloured figure is the one a
       visitor gets (R4r2-07, R3r2-05) */
    var ph = innerWidth < 768, st = /^(ex|onoff|all)$/.test(PV.scope || ''), upd = function () {
      var dn = density(1), lim = ph ? [25, 30] : [40, 50], ok = dn < lim[0], c = cls < .1;
      info.textContent = '';
      info.appendChild(E('div', 0, { style: 'color:' + (ok ? '#7bd88f' : dn >= lim[1] ? '#ff6b6b' : '#f2a33a') },
        (st ? PV.s.pv_density + ': — (' + (PV.s.pv_stacked || '') + ') · ' + (PV.s.pv_real || 'As visitors see it') : PV.s.pv_density) + ': ' + dn + ' % · ' + (ok ? PV.s.pv_ok || 'OK' : PV.s.pv_too_much || 'Too much: visitors may leave')));
      /* the stacked preview moves the page itself while it inserts every example: no verdict there */
      info.appendChild(E('div', 0, { style: 'color:' + (st ? '#ccc' : c ? '#7bd88f' : '#f2a33a') }, PV.s.pv_cls + ': ' + cls.toFixed(3) + (st ? '' : ' · ' + (c ? PV.s.pv_cls_ok || 'OK' : PV.s.pv_cls_bad || 'Too much movement'))));
    };
    T0 = performance.now() + 500;
    /* no verdict before the boxes have settled: the first reading counted every stacked slot whole and said "98 % · Too
       much" for about 1.5 s (R6r3-03); the first figure comes after the page's load event + 0.6 s */
    info.textContent = PV.s.pv_density + ': …';
    var go2 = function () { setTimeout(function () { upd(); addEventListener('scroll', upd, { passive: true }); setInterval(upd, 2e3); }, 600); };
    d.readyState == 'complete' ? go2() : addEventListener('load', go2);
  }
  function boot() {
    /* preview / picker on a page without the runtime: jcx.css is not in the head there */
    if (!d.querySelector('link[href*="static/jcx/jcx.css"]')) d.head.appendChild(E('link', 0, { rel: 'stylesheet', href: B + 'static/jcx/jcx.css' }));
    /* the hint is read before the request: jcx/pv clears an expired jcxp / jcxh in its own answer (P3-C / VO-03) */
    var hint = /(^|; )jcxh=1/.test(d.cookie);
    fetch(API + 'pv?t=' + encodeURIComponent(T) + '&th=' + encodeURIComponent(TH) + '&al=' + encodeURIComponent(qp('al') || ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(function (j) {
        /* P3-02: an expired preview (no v) from a page with the hint cookie: the hint goes and the page reloads with the
           live ads (only when the cookie is gone now, so it never loops; after the reload jcx-p.js is not loaded) */
        if (!j || !j.v) { if (!pick && hint) { d.cookie = 'jcxh=;path=' + B + ';max-age=0'; /(^|; )jcxh=1/.test(d.cookie) || location.reload(); } return; }
        PV = j; PV.s = j.s || {}; PV.fs = j.fs || {};
        if (pick) return picker();
        var go = function () { w.JCX.ready.then(preview); };
        if (w.JCX) return go();
        w.JCXD = { pv: 1, t: T, th: TH, l: d.documentElement.lang || 'en', dir: d.documentElement.dir || 'ltr', b: B, api: API, cfg: '', c: 0, s: PV.fs, pr: PV.ui || {}, v: {} };
        var s = E('script', 0, { src: B + 'static/jcx/jcx.js' }); s.onload = go; d.head.appendChild(s);
      }).catch(function () {});
  }
  d.readyState == 'loading' ? d.addEventListener('DOMContentLoaded', boot) : boot();
})(window, document);
