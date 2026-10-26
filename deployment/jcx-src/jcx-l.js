/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* JavCMS ads: fixed units, loaded by jcx.js only when one is eligible (ADS-BRIEF 5.1, 5.7, 5.9, 6, 11.4):
   U03 sticky bar (dock), U04 side rails, U06 floating corner / slide-in, U07 notification-style box,
   U08 full-screen between pages and the U09 lightbox (their modal code is in jcx-m.js, loaded here on demand),
   U09 overlay / exit intent (non-modal bottom panel with safe limits on; lightbox only with safe limits off).
   The space rule (5.9, CF03, CF05) runs over every fixed unit in the order they appeared, at insert and again on
   scroll / resize / size changes (one rAF, all reads before all writes): a unit shows only when it touches neither
   .MacPlayer nor an earlier unit, phones (safe limits) show one at a time, all shown units together stay <= 30 %
   of the viewport; otherwise it is hidden (visibility) until the space is free again. A new unit starts hidden and
   is first checked by the ResizeObserver, which runs after the browser's own layout: setup never forces a layout
   (TBT, 11.3). Setup work runs when the page is idle after load; a unit's first show goes through the first-paint
   reveal of jcx.js (painted as a 2 px strip, then opened: never the LCP, and no input needed, repair round 3; only the
   full-screen lightbox still waits for the first input). The theme switcher is never covered (Q5): it is lifted
   above the dock with the theme's floating buttons, the whole column by one step so their order is kept (R4r2-01),
   and any other unit that would touch it waits hidden. A pasted network box marked [data-jcx-fx] counts as a shown
   unit (R3r2-06); the cookie bar #jv-cookie counts as a bottom bar, so the dock re-docks when it shows / hides (Q4). */
(function (w, d) {
  var J = w.JCX, h = J && J._h;
  if (!h || w.JcxL) return;
  var E = h.E, S = h.S, D = h.D, ST = h.ST, PH = h.PH, NOW = h.NOW, cfg = h.cfg(), safe = cfg.set.safe, html = d.documentElement, pr = D.pr || {},
    fx = [], lifted = [], q, dock, bt = -1, lb = 0, ro, M = 'static/jcx/jcx-m.js';
  function vw() { return w.innerWidth; }
  function vh() { return w.innerHeight; }
  function each(s, f) { d.querySelectorAll(s).forEach(f); }
  function rect(n) { return n.getBoundingClientRect(); }
  function hit(a, b) { return a && b && !(a.bottom <= b.top || a.top >= b.bottom || a.right <= b.left || a.left >= b.right); }
  function player() { var p = d.querySelector('.MacPlayer'); return p && rect(p); }
  function xbtn() { return E('button', 'jcx-x', { type: 'button', 'data-jcx-act': 'x', 'aria-label': S.x }, '×'); }
  /* fixed bars at the bottom edge: the profile's selectors, else measured once (bars up to 28 px above the edge); cached until resize */
  /* the theme's fixed / sticky bands (< 240 px tall) across the viewport at height y */
  function band(y, f) {
    [.25, .5, .75].forEach(function (x) {
      d.elementsFromPoint(vw() * x, y).forEach(function (n) {
        var r = rect(n);
        if (/fixed|sticky/.test(getComputedStyle(n).position) && r.height < 240 && !n.closest(':not(html)[class*="jcx-"],[data-jcx-fx],:not(html)[class*="jv-ts"]')) f(r);
      });
    });
  }
  function bottom() {
    var b = 0;
    if (bt < 0) {
      each('#jv-cookie' + (pr.bt ? ',' + pr.bt : ''), function (n) { var r = rect(n); if (r.height) b = Math.max(b, vh() - r.top); });
      pr.bt || [1, 26].forEach(function (y) { band(vh() - y, function (r) { if (r.height < 160 && r.width > vw() * .5) b = Math.max(b, vh() - r.top); }); });
      bt = Math.round(b);
    }
    return bt;
  }
  /* bottom of the header bands near the top edge (a header, a sticky wrapper such as crimson's div.ct-top, or a
     floating header 12 px below the edge such as honey-modern's header.nc-cap); a sticky <header> that elementsFromPoint
     skips (pointer-events:none, velvet-modern's header.vp-header) is read directly (R4r2-10, R1r2-07) */
  function top() {
    var b = 0, f = function (r) { b = Math.max(b, r.bottom); };
    band(20, f);
    each('header', function (n) { var r = rect(n); if (r.top <= 20 && r.bottom > 0 && r.height < 240 && /fixed|sticky/.test(getComputedStyle(n).position)) f(r); });
    return b;
  }
  /* can box r show next to the shown boxes `on`? */
  function vis(on, r, p) {
    var s = r.width * r.height;
    if (!s || hit(r, p) || (PH && safe && on[0])) return 0;
    on.forEach(function (o) { s += hit(r, o) ? 1e9 : o.width * o.height; });
    return s <= vw() * vh() * .3;
  }
  /* all reads first (bars, player, every unit, the switcher column for lift()), then all writes (R3r3-03: lift() read
     the boxes after this frame's writes, a forced layout of up to 340 ms at 4x CPU); a corner panel that has to move
     above the dock stays hidden for this round and is checked again in the next frame at its new place */
  function chk() {
    var o = bt, p = player(), on = [], kb = d.fullscreenElement || (w.visualViewport && visualViewport.height < vh() * .7), db, dh, sw = d.querySelector('.jv-ts-btn'), band, l, dy;
    bottom(); q = 0; sw = sw && rect(sw);
    fx = fx.filter(function (u) { return u.n.isConnected; });
    each('[data-jcx-fx]', function (n) { var r = rect(n); r.height && on.push(r); });
    /* the dock never waits for the switcher (lift() moves the switcher above it); every other unit does */
    fx.forEach(function (u) {
      /* the unit's box includes its close button, which stands out above it (14 px, 22 px on touch screens) */
      var r = rect(u.a), x = u.a.querySelector('.jcx-x'), q = x && rect(x);
      if (q) r = { top: Math.min(r.top, q.top), bottom: r.bottom, left: Math.min(r.left, q.left), right: Math.max(r.right, q.right), width: r.width, height: r.height };
      u.A = r.width * r.height;
      if ((u.ok = !kb && vis(on, r, p) && (u == dock || !hit(r, sw)))) on.push(r);
    });
    /* the dock moves to a new bottom offset: hidden this round, shown at its new place in the next frame */
    if (dock && o != bt) dock.ok = 0;
    dh = dock && dock.ok ? dock.a.offsetHeight : 0; /* layout height: the first-show reveal scales the unit */
    db = bt + dh + 'px';
    /* lift(), read half: the theme switcher and the theme's floating buttons go above the shown dock, only when the band
       changes. One step dy for the whole column (from its lowest button), so buttons stacked above each other keep
       their order and gaps (onyx: .to-top under the switcher, R4r2-01); a button already above band + dy stays; a lifted
       button's own shift _y is added back. By translate: a transform is no layout shift */
    band = dh ? bt + dh + 24 : 0;
    if (band != lb) {
      l = [].filter.call(d.querySelectorAll((pr.lf ? pr.lf + ',' : '') + '.jv-ts-btn'), function (n) { var r = rect(n); n._b = vh() - r.bottom - (n._y || 0); return r.height; });
      dy = band - Math.min.apply(0, l.map(function (n) { return n._b; })) + 4;
    }
    fx.forEach(function (u) {
      if (u.p && u.n.style.getPropertyValue('--jcx-dock-b') != db) { u.n.style.setProperty('--jcx-dock-b', db); u.ok = 0; sch(); }
      u.n.style.visibility = u.ok ? '' : 'hidden';
      /* a unit that waits hidden pauses the video its code put in it, and plays it again when it shows (R3r3-02) */
      if (u.ok != u.v) { u.v = u.ok; u.n.querySelectorAll('video').forEach(function (v) { u.ok ? v._p && v.play(v._p = 0).catch(Boolean) : v.paused || v.pause(v._p = 1); }); }
      /* first show: through the reveal of jcx.js (the unit is first painted as a 2 px strip: never the LCP, no input needed) */
      u.ok && u.f && (h.rv(u.n, u.A).then(u.f), u.f = 0);
    });
    if (dock && o != bt) { dock.n.style.setProperty('--jcx-dock-b', bt + 'px'); sch(); }
    if (l) {
      lb = band;
      lifted.splice(0).forEach(function (n) { n.style.translate = n._y = ''; });
      dy > 4 && l.forEach(function (n) { if (n._b < band + dy) { n._y = dy; n.style.translate = '0 -' + dy + 'px'; lifted.push(n); } });
      /* the switcher moved: the other units check it again in the next frame */
      sch();
    }
  }
  function sch() { q = q || requestAnimationFrame(chk); }
  /* register a unit (hidden until the space rule lets it show); f() runs once, at its first show: impression, timers */
  function room(n, a, f) {
    fx.push(n = { n: n, a: a || n, f: f });
    n.n.style.visibility = 'hidden';
    if (!ro) {
      ro = new ResizeObserver(chk);
      /* the cookie bar showing or hiding measures the bottom bars again (Q4); only then: a theme tab bar that slides away
         while the page scrolls must not be measured in its hidden state */
      each('#jv-cookie', function (c) { new ResizeObserver(function () { bt = -1; sch(); }).observe(c); });
      w.addEventListener('scroll', sch, { passive: true });
      w.addEventListener('resize', function () { bt = -1; sch(); });
      d.addEventListener('fullscreenchange', sch);
      if (w.visualViewport) visualViewport.addEventListener('resize', sch);
      each('.MacPlayer', function (p) { ro.observe(p); });
      /* a pasted box that marks itself [data-jcx-fx] (or a new box on <body>) can come at any time: check again */
      var mo = new MutationObserver(sch);
      mo.observe(d.body, { childList: true });
      mo.observe(html, { attributes: true, attributeFilter: ['data-jcx-fx'], subtree: true });
    }
    ro.observe(n.a);
    return n;
  }
  /* layers wait while a theme menu is open, the theme locked the page scroll, or (phones) the player is in view */
  function busy() {
    var p = player();
    return d.querySelector('[aria-expanded="true"][aria-controls]') || getComputedStyle(d.body).overflow == 'hidden'
      || d.fullscreenElement || (PH && p && Math.min(p.bottom, vh()) - Math.max(p.top, 0) > p.height * .3);
  }
  /* a fixed unit box: label, creative, close when x (non-modal: role complementary, no focus steal, Esc when focused) */
  function box(c, it, n, x) {
    var b = E('div', c, { role: 'complementary', 'aria-label': S.r });
    b.appendChild(h.lbl()); b.appendChild(n);
    if (x) { b.appendChild(xbtn()); b.addEventListener('keydown', function (e) { e.key == 'Escape' && J._act(b.querySelector('.jcx-x')); }); }
    b._jcx = it;
    return b;
  }
  /* ---------- U03 sticky bar ---------- */
  function sticky(it) {
    var z = h.size(it, PH), cm = (cfg.pl.P09 || {}).cm || 'session', k, inn, n;
    /* runtime clamp (also for a crafted c.json): phone ≤ 100 px hard, ≤ 60 px safe; computer ≤ 30 % / 20 % of the viewport */
    if (dock || z[1] > (PH ? (safe ? 60 : 100) : vh() * (safe ? .2 : .3)) || h.ss('jcx.k' + it.id) || ST.i[it.id + 'c'] > NOW) return;
    n = it.k == 'image' ? h.image(it, vw()) : h.build(it, null);
    if (!n) return;
    inn = box('jcx-ki', it, n, 1);
    k = E('div', 'jcx-k');
    k.appendChild(inn);
    k._close = function () {
      if (cm == '24h') { ST.i[it.id + 'c'] = NOW + 86400; h.save(); } else h.ss('jcx.k' + it.id, 1);
      k.remove(); dock = 0; sch();
    };
    d.body.appendChild(k);
    dock = room(k, inn, function () { h.mark(k, it); });
  }
  /* ---------- U04 rails (each gutter of the page's content column ≥ min_gutter; from below the fixed header to above the bottom bars) ---------- */
  function rails(it) {
    var m = d.querySelector(pr.rs || '#main-content'), r = m && rect(m), z = h.size(it, 0), cs = (it.m || {}).cards || [], t = top(), gut, done;
    if (!r || PH) return;
    gut = Math.min(r.left, vw() - r.right);
    if (gut < ((cfg.pl.P08 || {}).g || 180) || gut < (z[0] || 160) + 16 || (z[0] >= 300 && gut < 320) || vh() - t - bottom() - 60 < (z[1] || 600) + 20) return;
    (it.u == 'U04' ? ['s', 'e'] : ['e']).forEach(function (side, i) {
      var c = cs[i] || cs[0], n = it.k == 'image' ? h.image(c ? { u2: c.url || it.u2, alt: c.title || it.alt, m: { src: c.img }, sz: it.sz } : it, 0) : h.build(it, null), rl;
      if (!n) return;
      rl = box('jcx-r jcx-r' + side, it, n);
      rl.style.top = t + 12 + 'px';
      rl.style.setProperty('--jcx-g', Math.max(8, (gut - (z[0] || 160)) / 2) + 'px');
      d.body.appendChild(rl);
      room(rl, 0, function () { done || h.mark(rl, it, done = 1); });
    });
  }
  /* ---------- U06 / U07 / U09 panel ---------- */
  function panel(it, kind) {
    /* U07 card: 64 × 64 icon, title, text (CSS: site font, theme tokens; never an OS / chat look) */
    /* the picture is never refused for its width: CSS scales it into the box (phones: the box leaves room for the theme
       switcher, R6r3-01) */
    var o = it.o || {}, n = it.k == 'image' ? h.image(it, 0) : h.build(kind == '7' ? Object.assign({}, it, { br: it.tx || it.br }) : it, null), p;
    if (!n) return;
    p = box('jcx-p jcx-p' + kind + ' jcx-q' + (o.corner && o.corner != 'auto' ? o.corner : /start/.test(html.getAttribute('data-jv-switcher-pos')) ? 'end' : 'start'), it, n, 1);
    if (kind == '9') p.style.maxHeight = vh() * .25 + 'px';
    d.body.appendChild(p);
    room(p, 0, function () {
      h.mark(p, it);
      if (kind == '7') setTimeout(function () { p.isConnected && J._act(p.querySelector('.jcx-x')); }, 12e3);
      if (kind == '6') h.ss('jcx.s' + it.id, 1);
    }).p = 1;
  }
  /* ---------- triggers ---------- */
  function when(it, fn, def) {
    var o = it.o || {}, t = o.trigger || 'delay', fired, go = function () { fired || (busy() ? setTimeout(go, 1500) : fn(fired = 1)); };
    if (t == 'scroll' || it.u == 'U06') w.addEventListener('scroll', function sc() { if (w.scrollY + vh() >= html.scrollHeight * (o.scroll_pct || 50) / 100) { w.removeEventListener('scroll', sc); go(); } }, { passive: true });
    if (t == 'exit') d.addEventListener('mouseout', function ex(e) { if (!e.relatedTarget && e.clientY <= 0) { d.removeEventListener('mouseout', ex); go(); } });
    if (t != 'exit' && t != 'scroll' || it.u == 'U06') setTimeout(go, (it.dl || def) * 1e3);
  }
  /* setup after load, when the main thread is idle (no forced layout during the theme's first passes) */
  function idle(f) { var g = function () { (w.requestIdleCallback || setTimeout)(f, { timeout: 2e3 }); }; d.readyState == 'complete' ? g() : w.addEventListener('load', g); }
  /* one rail item per page view, by weight (R3r2-10: every P08 item built its own hidden rails) */
  function rail(it) { return it.u == 'U04' || it.p == 'P08'; }
  /* 11.4 hard limit "no sticky / pop-out video" (R3r3-01): a fixed unit never plays a video file (the model refuses one
     too): a video source becomes the item's poster or still picture, else the unit is not built */
  function nv(it) { var m = Object.assign({}, it.m), v = /\.(mp4|webm|m3u8)$/i; ['src', 'srcm'].forEach(function (k) { v.test(m[k]) && (m[k] = m.poster || m.still); }); return Object.assign({}, it, { m: m }); }
  /* the units no longer wait for an input (R4r3-06 / R4r3-01): room() shows each one through the reveal of jcx.js; only
     the full-screen lightbox (safe limits off) still waits for the first input (h.gate) */
  w.JcxL = function (list) {
    var r = list.filter(rail);
    list = list.filter(function (it) { return !rail(it); });
    if (r[0]) list.push(h.pick(r));
    list.forEach(function (it) {
      var u = it.u, o = it.o || {}, z = h.size(it, PH),
        m = function (f) { h.load(M).then(function () { w.JcxM && f(JcxM); }); };
      if (u != 'U08') it = nv(it);
      if (u == 'U03') idle(function () { sticky(it); });
      else if (rail(it)) idle(function () { rails(it); });
      else if (u == 'U06') { if (!h.ss('jcx.s' + it.id)) when(it, function () { panel(it, '6'); }, 20); }
      else if (u == 'U07') when(it, function () { panel(it, '7'); }, 15);
      /* hard limits: never before content on phones; page-load and welcome modes need safe limits off (computer only) */
      else if (u == 'U08') m(function (x) { x.u08(it, safe); });
      else if (u == 'U09' && !PH) when(it, function () { safe || o.mode != 'box' ? panel(it, '9') : h.gate(z[0] * z[1] || 1e9, function () { m(function (x) { x.modal(it, 0); }); }); }, 30);
    });
  };
})(window, document);
