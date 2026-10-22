/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS ads: rarer formats, loaded on demand (ADS-BRIEF 5.1; split out of jcx.js / jcx-l.js in repair round 2 so
   both stay inside their 11.3 budgets, R3r2-07): the U13 recommendation widget / carousel / collection and the
   multi-card native (loaded by jcx.js when such an item is built), and the U08 full-screen between pages / U09
   lightbox (loaded by jcx-l.js when such an item is eligible), and the U14 "Sponsored" labels. Budget: 2 KB gzip (build.js). */
(function (w, d) {
  var J = w.JCX, h = J && J._h;
  if (!h || w.JcxM) return;
  var E = h.E, S = h.S, ST = h.ST, html = d.documentElement, modalOpen;
  function xbtn() { return E('button', 'jcx-x', { type: 'button', 'data-jcx-act': 'x', 'aria-label': S.x }, '×'); }
  /* ---------- U13 widget / carousel / collection: n = the box jcx.js already put in the slot ---------- */
  function widget(n, it) {
    var cs = (it.m || {}).cards || [], lay = (it.o || {}).layout || 'grid', car = lay == 'carousel', tr = E('div', 'jcx-wt', { tabindex: car ? 0 : null });
    n.className = 'jcx-w jcx-w-' + lay;
    if (car) { n.setAttribute('role', 'group'); n.setAttribute('aria-roledescription', S.ca); }
    n.appendChild(E('span', 'jcx-wh', 0, S.ln));
    cs.forEach(function (c, i) { var a = h.card(it, c); if (car) a.setAttribute('aria-label', S.sl.replace('{i}', i + 1).replace('{n}', cs.length) + ': ' + (c.title || '')); tr.appendChild(a); });
    n.appendChild(tr);
    if (car) ['pv', 'nx'].forEach(function (k) { n.appendChild(E('button', 'jcx-wb jcx-w' + k, { type: 'button', 'data-jcx-act': k, 'aria-label': S[k] }, k == 'pv' ? '‹' : '›')); });
  }
  /* ---------- U05 expandable: n = the box jcx.js put in the slot, n._a = the first picture it built, n._r = the room ---------- */
  function expand(n, it) {
    var o = it.o || {}, z = h.size(it, h.PH), big = h.image({ u2: it.u2, alt: it.alt, m: { src: o.expand_img }, sz: [z[0], o.expand_h || 250] }, n._r);
    n.className = 'jcx-e';
    n.appendChild(n._a);
    /* the hidden big picture and the button come one task later: the first-paint reveal of jcx.js waits only for the
       pictures that are in the box when it looks, and a hidden lazy picture never loads */
    if (big) setTimeout(function () { big.hidden = 1; n.appendChild(big); n.appendChild(E('button', 'jcx-eb', { type: 'button', 'data-jcx-act': 'ex', 'aria-expanded': 'false' }, S.ex)); });
  }
  /* ---------- a picture item whose source is a video (U18 phone video in the masthead, X56): n = the box jcx.js put in
     the slot (n._a its link, n._s the source, n._z the size). Muted, looping, no sound ever; it starts 3 s after it is
     built, not while the page loads (TBT on phones); with a poster the reveal of jcx.js waits for the poster only
     (v._d), else for the first frame ---------- */
  function vid(n) {
    var v = E('video', 0, { src: n._s, poster: (n._jcx.m || {}).poster, playsinline: '', loop: '', muted: '', preload: 'none', width: n._z[0], height: n._z[1] });
    v.muted = 1; v._d = !!v.poster; v.style.cssText = 'display:block;margin:auto;max-width:100%;height:auto'; n._a.style.display = 'block';
    n._a.appendChild(v); n.appendChild(n._a);
    setTimeout(function () { v.play().catch(Boolean); }, v._d ? 3e3 : 0);
  }
  /* ---------- U17 HTML5: the sandboxed frame (no same-origin), sized to the creative ---------- */
  function h5(n, it) {
    var z = h.size(it, h.PH), f = E('iframe', 0, { title: S.r, width: z[0] || 300, height: z[1] || 250, loading: 'lazy', scrolling: 'no', sandbox: 'allow-scripts allow-popups allow-popups-to-escape-sandbox', style: 'display:block;margin:auto' });
    f.src = it.h5 + '#w=' + z[0] + '&h=' + z[1] + '&dir=' + h.D.dir + (h.rm() ? '&rm=1' : '') + (it.u2 ? '&u=' + encodeURIComponent(it.u2) : '');
    n.appendChild(f);
  }
  /* ---------- U18 brand takeover: the page background on wide screens ---------- */
  function wall(o) { d.body.style.backgroundImage = 'url("' + o.wallpaper + '")'; html.classList.add('jcx-wp'); }
  /* ---------- U08 (and U09 lightbox with safe limits off): a modal <dialog> (showModal: the page behind is inert,
     so Tab stays inside; Esc = cancel; close() returns the focus) ----------
     The creative is scaled by CSS to the phone width (no width test), so a 320 × 480 creative fits a 320 px phone.
     Returns 1 only when the dialog opened; the caller lets the visitor's link go otherwise. */
  function modal(it, go) {
    /* U08 video: a muted looping <video> (build() has no plain video kind, so it must be made before the !n test) */
    var n = it.k == 'video' ? E('video', 0, { src: (it.m || {}).src, poster: (it.m || {}).poster, playsinline: '', autoplay: h.rm() ? null : '', loop: '' }) : it.k == 'image' ? h.image(it, 0) : h.build(it, null), m, b, f;
    if (!n || modalOpen) return;
    n.muted = 1;
    modalOpen = 1;
    m = E('dialog', 'jcx-m', { 'aria-label': S.r }); b = E('div', 'jcx-mb');
    b.appendChild(h.lbl()); b.appendChild(n); b.appendChild(f = xbtn());
    if (go) b.appendChild(E('button', 'jcx-go', { type: 'button', 'data-jcx-act': 'go' }, S.c));
    m.appendChild(b); m._jcx = it;
    m._close = function () { m.close(); m.remove(); modalOpen = 0; html.style.overflow = ''; if (go) location.href = go; };
    m.addEventListener('click', function (e) { if (e.target == m) J._act(f); });
    m.addEventListener('cancel', function () { J._act(f); });
    d.body.appendChild(m); m.showModal(); html.style.overflow = 'hidden';
    h.mark(m, it); f.focus();
    if (h.SOK && it.u == 'U08') { ST.i[it.id + 'p'] = ST.pv; h.save(); }
    return 1;
  }
  h.hooks.go = function (a, e, b) { J._act(b.querySelector('.jcx-x')); };
  /* U05 expand / collapse (jcx.js loads this file when it builds an expandable) and the carousel arrows (moved here
     from jcx.js in repair round 3) */
  h.hooks.ex = function ex(a, e, box) {
    var t = box.querySelectorAll(':scope>a'), o = a.getAttribute('aria-expanded') != 'true';
    t[0].hidden = o; t[1].hidden = !o;
    a.setAttribute('aria-expanded', o); a.textContent = o ? S.co : S.ex;
    clearTimeout(box._t); if (o) box._t = setTimeout(function () { a.getAttribute('aria-expanded') == 'true' && ex(a, 0, box); }, 8e3);
  };
  h.hooks.pv = h.hooks.nx = function (a, e, box) {
    var t = box.querySelector('.jcx-wt');
    t.scrollBy({ left: (a.getAttribute('data-jcx-act') == 'nx' ? 1 : -1) * (h.D.dir == 'rtl' ? -1 : 1) * t.clientWidth * .8, behavior: h.rm() ? 'auto' : 'smooth' });
  };
  /* U08: between pages = after a click / tap on an internal link (the next page opens from "Continue" or close);
     preventDefault only after the dialog is built and open, so a creative that cannot show never swallows the tap.
     "Page opens" / welcome (safe limits off, computer only) waits for the LCP guard like every first-screen ad. */
  function u08(it, safe) {
    var o = it.o || {}, last = ST.i[it.id + 'p'] || 0, z = h.size(it, 0);
    if ((o.trigger == 'load' || o.welcome) && !safe && !h.PH) {
      if (!(ST.i[it.id + 'w'] > h.NOW)) { if (o.welcome) { ST.i[it.id + 'w'] = h.NOW + 7 * 86400; h.save(); } setTimeout(function () { h.gate(z[0] * z[1] || 1e9, function () { modal(it, 0); }); }, 1e3); }
      return;
    }
    if (ST.pv - last < 5 && last) return;
    d.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented || modalOpen || e.ctrlKey || e.metaKey || e.shiftKey || a.target == '_blank' || a.hasAttribute('download')
        || a.closest('[data-jcx],.jcx-p,.jcx-k,.jcx-r,.MacPlayer,:not(html)[class*="jv-ts"],[hreflang]') || a.origin != location.origin || (a.hash && a.pathname == location.pathname)) return;
      if (h.ok(it) && modal(it, a.href)) e.preventDefault();
    });
  }
  /* ---------- U14: "Sponsored" + rel=sponsored on own cards and the page whose link matches (jcx.js scan) ---------- */
  function u14(cfg) {
    cfg.it.forEach(function (it) {
      if (it.u != 'U14' || !it.tg || !h.ok(it)) return;
      var re = RegExp('/' + it.tg + '(/|-sv)'), h1 = d.querySelector('h1');
      d.querySelectorAll('a[href]').forEach(function (a) {
        /* own cards only (a link with a picture): never a skip link, a menu entry or a text link (they moved the page) */
        if (a._js || a.closest('.jcx-s,.jcx-c,.jcx-p') || !re.test(a.pathname) || !a.querySelector('img')) return;
        a._js = 1; a.rel = (a.rel + ' sponsored').trim();
        a.appendChild(E('span', 'jcx-sp', 0, S.sp));
      });
      if (re.test(location.pathname) && h1 && !h1._js) { h1._js = 1; h1.appendChild(E('span', 'jcx-sp', 0, S.sp)); }
    });
  }
  w.JcxM = { widget: widget, expand: expand, vid: vid, h5: h5, wall: wall, modal: modal, u08: u08, u14: u14 };
})(window, document);
