/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS ads runtime (ADS-BRIEF 5): reads #jcx-d, fetches c.json, filters (dates, weekdays, hours, device, caps,
   members, countries, closed memory), weighted rotation, fills the slot boxes the placer printed (image / picture,
   native card, expandable, text link, HTML5 iframe, network code lazy / box), consent hold, one stats beacon per
   page view, the LCP guard (first-paint reveal). Fixed units (sticky bar, rails, corner, notification box, overlay) live in jcx-l.js,
   loaded only when one of them is eligible; widgets / carousels / collections, U14 sponsored labels and the
   full-screen modal in jcx-m.js, out-stream video in jcx-v.js, both loaded on demand.
   Built-in units load nothing from third parties and set no cookies; localStorage 'jcx.f' holds counts only. */
(function (w, d) {
  if (w.JCX) return;
  var html = d.documentElement, D;
  try { D = w.JCXD || JSON.parse(d.getElementById('jcx-d').textContent); } catch (e) { return; }
  /* PH: the phone layout, also a phone in landscape (P2-05: the same query as jcx.css and the placer's critical style) */
  var S = D.s || {}, B = D.b || '/', PH = w.matchMedia('(max-width:767px),(pointer:coarse) and (max-height:500px)').matches, NOW = Date.now() / 1e3 | 0;
  var cfg = { it: [], set: {}, pl: {} }, byId = {}, used = {}, counts = {}, hooks = {}, held = [], consentOk = 0;
  var FIX = { U03: 1, U04: 1, U06: 1, U07: 1, U08: 1, U09: 1 }, INC = { P03: 1, P05: 1, P06: 1, P13: 1, P16: 1 }, phoneIn = 0, f5 = 0, lastY = -1e5, dk = 0, MJ = 'static/jcx/jcx-m.js';
  var SPN = 'sponsored noopener', RM = '(prefers-reduced-motion:reduce)';
  /* storage: blocked storage hides every capped unit (fail-safe, 5.5) */
  var ST = {}, SOK = 1;
  try { ST = JSON.parse(localStorage.getItem('jcx.f') || '{}') || {}; } catch (e) { SOK = 0; }
  ST.i = ST.i || {}; ST.pv = (ST.pv || 0) + 1;
  function save() {
    var k, v, t = NOW - 7 * 86400;
    for (k in ST.i) { v = ST.i[k]; if (v.filter) { v = ST.i[k] = v.filter(function (x) { return x > t; }).slice(-20); if (!v.length) delete ST.i[k]; } else if (v < NOW) delete ST.i[k]; }
    try { SOK && localStorage.setItem('jcx.f', JSON.stringify(ST)); } catch (e) { SOK = 0; }
  }
  save();
  function ss(k, v) { try { if (v == null) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) {} }
  /* helpers */
  function E(t, c, a, x) {
    var e = d.createElement(t), k;
    if (c) e.className = c;
    for (k in a) if (a[k] != null) e.setAttribute(k, a[k]);
    if (x != null) e.textContent = x;
    return e;
  }
  function ga(e, k) { return e.getAttribute(k); }
  function each(s, f, r) { [].forEach.call((r || d).querySelectorAll(s), f); }
  function emit(n, det) { d.dispatchEvent(new CustomEvent('jcx:' + n, { detail: det })); }
  function cnt(id, ev) { var k = id + '.' + ev; counts[k] = (counts[k] || 0) + 1; }
  function ck(n) { var m = d.cookie.match(new RegExp('(?:^|;\\s*)' + n + '=([^;]*)')); return m ? m[1] : ''; }
  function lbl(t) { return E('span', 'jcx-l', 0, t || S.l); }
  function vh() { return w.innerHeight; }
  function rm() { return w.matchMedia(RM).matches || html.classList.contains('jv-ts-reduced'); }
  /* LCP guard (11.3, R3r2-01 / R3r2-03, repair round 3): no ad may make the page's LCP later or be the LCP element, for
     the page API and for Chrome's own (field) LCP, and no first-screen box may wait empty for an input (owner decision,
     R4r3-06 / R3r3-04). Chrome records an element for the LCP at its FIRST paint, with the size it has then, and never
     again when it grows. So a creative that could be the largest paint (area >= the page's largest paint so far, LC) is
     painted at 1 % scale first and shown at full size once its pictures / poster / first frame are decoded and painted
     (two frames): it shows without any input and is never an LCP candidate. Smaller
     creatives and everything after the first input (tap, key, wheel: Chrome stops the LCP there) show at once. No frame
     document is built (a srcdoc frame cost 70-110 ms at 4x CPU). gate() (wait for the first input) is kept only for
     the U08 page-load / welcome modal (safe limits off). */
  var LC = 0, GQ = [];
  function go() { LC = 1 / 0; GQ.splice(0).forEach(function (f) { setTimeout(f); }); }
  try { new PerformanceObserver(function (l) { LC = Math.max(LC, l.getEntries().pop().size); }).observe({ type: 'largest-contentful-paint', buffered: true }); } catch (e) { go(); }
  ['pointerdown', 'keydown', 'wheel'].forEach(function (t) { w.addEventListener(t, go, { capture: true, passive: true, once: true }); });
  /* a = the creative's area in px² (1e9 = unknown); -1 = not in the first screen */
  function gate(a, f) { a < LC ? f() : GQ.push(f); }
  /* what must be decoded before the clip opens: a picture; for a video its poster and, unless reduced motion keeps it
     still, its first frame (a frame painted after the poster was a new LCP candidate: oceans.mp4, U16 X49), at most 3 s */
  /* (a lazy picture is made eager first: decode() of a lazy picture that has not started loading rejects at once, and
     the box opened before its picture had painted: X51 poster on the list page) */
  function ld(m) {
    return m.decode ? (m.loading = 'eager', m.decode()) : Promise.race([new Promise(function (r) { setTimeout(r, 3e3); }), Promise.all([m.poster && ld(E('img', 0, { src: m.poster })), rm() || m._d || new Promise(function (r) { m.readyState > 1 ? r() : m.onloadeddata = m.onerror = r; })])]);
  }
  /* reveal creative n (already built; the caller inserts it in the same task, so its first paint is scaled) in block box
     b (the slot, or the fixed unit itself; default n); resolves when it is open. n._w = a promise of a creative built by
     a file loaded on demand (jcx-v.js / jcx-m.js). The box is painted at 1 % scale (CSS scale: every picture and text
     inside paints, tiny; a 2 px clip strip let a picture below the strip be reported at full size), then it is shown at
     full size at once (a scale transition made the busy frames of heavy themes longer) */
  async function rv(n, a, b) {
    /* k: a creative that could be the largest paint; a smaller one shows at once, but its label (resolve = mark) still
       waits until the box has content: a box built later, or network code that draws after its script loaded (X19) */
    var s = (b || n).style, k = a >= LC;
    /* scaled from its top edge: a box whose lower part is below the fold still paints (small) inside the screen; scaled
       from its centre it painted outside and its first paint came at full size later (crimson watch, U16 poster) */
    if (k) s.scale = .01, s.transformOrigin = '50% 0';
    await n._w;
    await new Promise(function (r) { var o = new ResizeObserver(function () { n.offsetHeight && (o.disconnect(), r()); }); o.observe(n); });
    if (!k) return;
    await Promise.allSettled([].map.call(n.querySelectorAll('img,video'), ld));
    /* text: after one layout the web font it needs is loading (text waiting for its font is not painted, and its later
       first paint was a full-size LCP candidate: crimson text link); then two frames, so the small box has painted */
    await new Promise(function (r) { requestAnimationFrame(r); });
    await d.fonts.ready;
    await new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); });
    s.scale = '';
  }
  /* eligibility (5.4, 5.5); pv = preview: page / language rules only. D.c = the page's category and its parent (the
     server checks both, P3-04; a page cached before that change has one number) */
  /* c.json carries the effective cap [n, hours, gap minutes, not on page view 1]: unit defaults are filled in by the build */
  function capped(it) { var c = it.cap; return c && (c[0] || c[2] || c[3]); }
  function ok(it, pv) {
    if (!it || (it.pg && it.pg.indexOf(D.t) < 0) || (it.lg && it.lg.indexOf(D.l) < 0) || (it.ct && D.c && !it.ct.some(function (c) { return D.c.indexOf ? D.c.indexOf(c) > -1 : c == D.c; }))) return 0;
    if (pv) return 1;
    var t = new Date(Date.now() + (cfg.tz || 0) * 6e4), h = t.getUTCHours(), a, b, c, sh;
    if (!((it.d || 3) & (PH ? 2 : 1)) || (it.s && it.s > NOW) || (it.e && it.e <= NOW)) return 0;
    if (it.dy && !(it.dy & (1 << (t.getUTCDay() + 6) % 7))) return 0;
    if (it.hr) { a = +it.hr.slice(0, 2); b = +it.hr.slice(3); if (a < b ? h < a || h >= b : h < a && h >= b) return 0; }
    if ((it.who == 1 && +ck('user_id')) || (it.who == 2 && (cfg.set.vip || []).indexOf(+ck('group_id')) > -1)) return 0;
    if (it.cc) { c = ss('jcx.cc'); if (!c || (it.cc.indexOf(c) > -1) == !!it.cx) return 0; }
    if (capped(it)) {
      if (!SOK) return 0;
      c = it.cap; sh = ST.i[it.id] || [];
      if ((c[3] && ST.pv < 2) || (c[0] && sh.filter(function (x) { return x > NOW - (c[1] || 24) * 3600; }).length >= c[0]) || (c[2] && sh[0] && sh[sh.length - 1] > NOW - c[2] * 60)) return 0;
    }
    return !(ST.i[it.id + 'c'] > NOW);
  }
  function shown(it) { if (capped(it) && SOK) { (ST.i[it.id] = ST.i[it.id] || []).push(NOW); save(); } }
  function pick(list) {
    var t = 0, r, i;
    list = list.filter(function (x) { return !used[x.id]; });
    list.forEach(function (x) { t += x.w || 10; });
    r = crypto.getRandomValues(new Uint32Array(1))[0] / 4294967296 * t;
    for (i = 0; i < list.length; i++) { r -= list[i].w || 10; if (r < 0) return list[i]; }
    return list[0];
  }
  /* creatives */
  /* the runtime checks the link scheme again (R2-F8): http(s) or a path on this site, never javascript: / data: / //host */
  function link(u, c) { return E('a', c, { href: /^(https?:\/\/|\/(?!\/))/i.test(u) ? u : '#', rel: SPN, target: '_blank' }); }
  /* our iframes (network code box, HTML5): lazy */
  function frame(z) { return E('iframe', 0, { title: S.r, width: z[0] || 300, height: z[1] || 250, loading: 'lazy', scrolling: 'no' }); }
  function size(it, ph) { var z = it.sz || [0, 0, 0, 0]; return ph && z[2] || !z[0] ? [z[2], z[3]] : [z[0], z[1]]; }
  /* no source = no creative (the caller keeps the box / lets a link go). A video source under reduced motion shows its
     poster, a still picture (Q1: no looping film without a pause control) */
  function image(it, room) {
    var m = it.m || {}, z0 = it.sz || [], ph = PH && m.srcm, src = ph ? m.srcm : m.src || m.srcm, z = size(it, ph), a = link(it.u2), p = E('picture'), n;
    if (!src || room && z[0] > room + 1 && (ph || !m.srcm || z0[2] > room)) return;
    if (room && z[0] > room + 1) { src = m.srcm; z = [z0[2], z0[3]]; }
    /* a video source: jcx-m.js builds the muted looping film (its poster first, the film starts a little later, so it
       never runs while the page is still loading: phone masthead X56, TBT) */
    if (/\.(mp4|webm)$/.test(src)) { if (!rm() || !m.poster) { n = later(it, 'm', 'vid'); n._s = src; n._z = z; n._a = a; return n; } src = m.poster; }
    n = E('img', 0, { src: src, width: z[0] || null, height: z[1] || null, alt: it.alt || '', loading: 'lazy', decoding: 'async' });
    if (m.still) p.appendChild(E('source', 0, { media: RM, srcset: m.still }));
    p.appendChild(n); a.appendChild(p);
    return a;
  }
  function card(it, c) {
    c = c || { img: (it.m || {}).src, title: it.ti || it.tx, url: it.u2, brand: it.br };
    var tp = d.querySelector('template[data-jcx-card]'), a, f;
    if (tp) {
      a = tp.content.firstElementChild.cloneNode(1);
      each('[data-jcx-f]', function (f) {
        var k = ga(f, 'data-jcx-f');
        if (k == 'img') f.src = c.img || ''; else if (k == 'url') { f.href = c.url || '#'; f.rel = SPN; f.target = '_blank'; } else f.textContent = c[k] || '';
      }, a);
      return a;
    }
    a = link(c.url, 'jcx-n'); f = E('span', 'jcx-ni');
    if (c.img) f.appendChild(E('img', 0, { src: c.img, alt: '', loading: 'lazy' }));
    a.appendChild(f); a.appendChild(E('span', 'jcx-nt', 0, c.title || ''));
    if (c.brand) a.appendChild(E('span', 'jcx-nb', 0, c.brand));
    return a;
  }
  /* network / admin code: lazy = scripts re-created in order, one item at a time, 8 s per external script; box = srcdoc iframe */
  var Q = Promise.resolve();
  function clone(s) { var n = d.createElement('script'); [].forEach.call(s.attributes, function (a) { n.setAttribute(a.name, a.value); }); n.text = s.text; return n; }
  function code(box, h, md, z) {
    if (!h) return;
    if (md == 2) {
      var f = frame(z);
      f.srcdoc = '<!doctype html><base target="_top"><style>body{margin:0}</style>' + h;
      return box.appendChild(f);
    }
    /* the promise ends when the code's nodes are in (the reveal of a first-screen box waits for it) */
    return Q = Q.then(function () {
      var t = E('div'), p = Promise.resolve();
      t.innerHTML = h;
      [].slice.call(t.childNodes).forEach(function (n) {
        p = p.then(function () {
          if (n.nodeName != 'SCRIPT') { box.appendChild(n); if (n.querySelectorAll) each('script', function (s) { s.parentNode.replaceChild(clone(s), s); }, n); return; }
          var s = clone(n);
          return new Promise(function (r) { if (s.src && !s.async) { s.onload = s.onerror = r; setTimeout(r, 8e3); } else r(); box.appendChild(s); });
        });
      });
      return p;
    }).catch(function () {});
  }
  /* a box filled by a file loaded on demand: jcx-v.js (U16 out-stream), jcx-m.js (U13 widget / carousel / collection,
     U05 expandable). No class until the file names it: "jcx-m" was the modal's class, so an empty widget box was a
     full-screen dim layer until jcx-m.js arrived */
  function later(it, f, k) {
    var n = E('div');
    n._jcx = it;
    /* a file that did not load leaves the box empty: its promise never ends, so it is never revealed or labelled */
    n._w = load('static/jcx/jcx-' + f + '.js').then(function () { var o = w['Jcx' + f.toUpperCase()]; return o ? o[k](n, it) : new Promise(Boolean); });
    return n;
  }
  /* creative node for a slot; undefined = does not fit (the box keeps its label, 5.4). slot._r = its width from the
     IntersectionObserver entry (no layout read after an earlier fill of the same batch wrote, R3r3-03) */
  function build(it, slot) {
    var room = slot ? slot._r || slot.clientWidth : 0, z = size(it, PH), o = it.o || {}, n, x, c;
    if (it.u == 'U16') return later(it, 'v', 'outstream');
    if (it.k == 'image') {
      n = image(it, room);
      /* U05 expandable (its second picture, button and toggle) and the U18 page background are built by jcx-m.js
         (repair round 3, jcx.js budget) */
      if (n && it.u == 'U05' && o.expand_img) { x = later(it, 'm', 'expand'); x._a = n; x._r = room; n = x; }
      if (n && o.wallpaper && w.innerWidth >= 1400) load(MJ).then(function () { w.JcxM && JcxM.wall(o); });
      return n;
    }
    if (it.k == 'native') return it.u == 'U13' || ((it.m || {}).cards || [])[1] ? later(it, 'm', 'widget') : card(it);
    if (it.k == 'link') { n = link(it.u2, 'jcx-a'); n.textContent = it.tx || it.ti || it.u2; return n; }
    /* U17 HTML5: its sandboxed frame is built by jcx-m.js (it fits or not is known here) */
    if (it.k == 'html5') return room && z[0] > room + 1 ? 0 : later(it, 'm', 'h5');
    if (it.c || it.cm) {
      n = E('div', 'jcx-cd'); c = PH && it.cm ? it.cm : it.c || it.cm;
      if (cfg.set.consent && it.k == 'code' && !consentOk) { held.push(function () { code(n, c, it.md, z); }); emit('hold', { id: it.id }); }
      else n._w = code(n, c, it.md, z);
      return n;
    }
  }
  /* slots */
  /* the server prints a single own creative inside <noscript> (JS off); with JS it is inserted here, through the LCP
     guard (reveal). A slot hidden on this device never gets here (no intersection), so its creative is never downloaded.
     top / y = the slot's top in the viewport / in the page, from the IntersectionObserver entry (no layout read). */
  function fill(slot, top, y) {
    if (slot._jcx) return;
    slot._jcx = 1;
    var p = ga(slot, 'data-jcx'), x = ga(slot, 'data-jcx-x'), sid = +ga(slot, 'data-jcx-i'), ns = slot.querySelector('noscript'), list, it, n, z, a, q, wd;
    /* phones, safe limits (11.4, R3r2-09, R3r3-07): server-printed slots count; an eligible sticky bar counts as one of
       the 3 in-content ads; at most one in-content ad per screen height (a later one closer than that collapses, only
       while it is below the viewport: nothing seen moves) */
    if (PH && cfg.set.safe && INC[p]) {
      if (p != 'P05') { if (y - lastY < vh() && top > vh()) return gone(slot, top); lastY = y; }
      if (p != 'P05' || !f5) { phoneIn++; f5 = f5 || p == 'P05'; }
      if (phoneIn > 3 - dk) return gone(slot, top);
    }
    if (sid) {
      it = byId[sid] || { id: sid };
      if (!ns) return mark(slot, it);
      n = E('div'); n.innerHTML = ns.textContent; n = n.lastChild;
    } else {
      list = cfg.it.filter(function (i) { return (i.p == p || i.p == x) && !FIX[i.u] && !i.sv && ok(i); });
      while (list.length && !n) { it = pick(list); if (!it) break; n = build(it, slot); if (!n) { list.splice(list.indexOf(it), 1); it = 0; } }
      if (!it) return gone(slot, top);
    }
    used[it.id] = 1;
    z = size(it, PH);
    a = rv(n, top < vh() ? z[0] * z[1] || 1e9 : -1, slot);
    q = slot.querySelector('.jcx-cv'); q ? slot.replaceChild(n, q) : slot.appendChild(n);
    q = n.querySelector('[width]'); wd = q ? +ga(q, 'width') : z[0];
    /* the label lines up with the creative shown (its own width: a narrow slot may get the phone picture), not with
       the widest one the place may rotate (R4r2-09). A place that rotates creatives of different heights reserves the
       tallest: jcx.css centres a shorter creative in the box (R4r3-09) */
    if (wd) slot.style.setProperty('--jcx-wd', wd + 'px'), slot.style.setProperty('--jcx-wm', wd + 'px');
    a.then(function () { mark(slot, it); });
  }
  /* a slot with nothing for this visitor collapses only while below the viewport and never seen (CF23) */
  /* (fill runs when the slot comes within 300 px, before it is in view, so "below the viewport" = never seen) */
  function gone(s, top) { if (top > vh()) s.style.display = 'none'; }
  function mark(n, it) {
    shown(it); cnt(it.id, 1); emit('fill', { id: it.id, place: ga(n, 'data-jcx') || it.p });
    n.setAttribute('data-jcx-f', it.id); n._jcx = n._jcx || it;
    n.addEventListener('click', function (e) { if (e.target.closest('a,iframe')) { cnt(it.id, 3); emit('click', { id: it.id }); } });
    n._vid = it.id; vio.observe(n);
  }
  var vio = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      var t = e.target;
      if (e.intersectionRatio >= .5) t._vt = t._vt || setTimeout(function () { cnt(t._vid, 2); emit('view', { id: t._vid }); vio.unobserve(t); }, 1e3);
      else { clearTimeout(t._vt); t._vt = 0; }
    });
  }, { threshold: [0, .5] });
  /* every box comes from the entries (scrollY is read once, before any write): a fill() never reads a layout that an
     earlier fill() of the same batch made dirty (R3r3-03) */
  var io = new IntersectionObserver(function (es) {
    var sy = w.scrollY;
    es.forEach(function (e) { var t = e.target, b = e.boundingClientRect; if (e.isIntersecting) { io.unobserve(t); t._r = b.width; fill(t, b.top, b.top + sy); } });
  }, { rootMargin: '300px 0px' });
  function scan() {
    each('.jcx-s,.jcx-c', function (s) { if (!s._jq) { s._jq = 1; io.observe(s); } });
    /* held code: consent (CF11) or visitor rules on P15 / parse-mode items; parse-mode runs in a box after load */
    each('template[data-jcx-hold]', function (t) {
      if (t._jq) return; t._jq = 1;
      var it = byId[+ga(t, 'data-jcx-i')], run = function () { var b = E('div', 'jcx-cd'); t.parentNode.insertBefore(b, t.nextSibling); code(b, t.innerHTML, +ga(t, 'data-jcx-md'), size(it, PH)); shown(it); cnt(it.id, 1); };
      if (!it || !((+ga(t, 'data-jcx-dv') || 3) & (PH ? 2 : 1)) || !ok(it)) return;
      cfg.set.consent && !consentOk ? (held.push(run), emit('hold', { id: it.id })) : run();
    });
    /* U14 "Sponsored" labels: jcx-m.js */
    if (cfg.it.some(function (it) { return it.u == 'U14' && it.tg && ok(it); })) load(MJ).then(function () { w.JcxM && JcxM.u14(cfg); });
  }
  /* actions from the head-snippet click shield (5.6): x close; the others are hooks of jcx-l.js, jcx-v.js and jcx-m.js
     (ex / co expand, pv / nx carousel: moved there in repair round 3 to keep the jcx.js budget) */
  function act(a, e) {
    var k = ga(a, 'data-jcx-act'), box = a.closest('.jcx-k,.jcx-p,.jcx-m,.jcx-r,.jcx-e,.jcx-w,.jcx-s,.jcx-c');
    if (hooks[k]) return hooks[k](a, e, box);
    if (box && k == 'x') close(box);
  }
  function close(box) {
    var it = box._jcx;
    if (it && it.id) { cnt(it.id, 4); emit('close', { id: it.id }); }
    box._close ? box._close() : box.remove();
  }
  var loaded = {};
  function load(p) {
    return loaded[p] = loaded[p] || new Promise(function (r) { var s = E('script', 0, { src: B + p + '?v=' + ((D.v || {})[p.slice(15, 16)] || '') }); s.onload = s.onerror = r; d.head.appendChild(s); });
  }
  /* one aggregated beacon per page view: "id.event.count,..." (≤ 40 triples, ≤ 50 each) */
  var sent;
  function beacon() {
    var k, b = [];
    if (sent || !cfg.set.stats) return;
    for (k in counts) b.length < 40 && b.push(k + '.' + Math.min(counts[k], 50));
    if (b.length) { sent = 1; navigator.sendBeacon(D.api + 'b', new Blob([b.join()])); }
  }
  d.addEventListener('visibilitychange', function () { d.visibilityState == 'hidden' && beacon(); });
  /* a click into one of our frames (first-screen creative, HTML5, code box) moves the focus into it: count it on its box */
  w.addEventListener('blur', function () { var f = d.activeElement; f && f.tagName == 'IFRAME' && f.click(); });
  w.addEventListener('pagehide', beacon);
  function start() {
    /* the admin preview (hint cookie jcxh) runs no live item, slot or fixed unit: its panel shows only the previewed
       ones (R4r2-02, R1r2-02: a live sticky bar no longer hides the one being checked) */
    if (ck('jcxh')) return;
    /* free time (rewarded ad) also when no rewarded item is on any more: the critical style folds the boxes (R3r2-11) */
    if (html.classList.contains('jcx-free') || ST.free > NOW) return html.classList.add('jcx-free');
    cfg.it.forEach(function (it) { byId[it.id] = it; });
    var fx = cfg.it.filter(function (it) { return (FIX[it.u] || it.p == 'P08') && ok(it); });
    dk = PH && fx.some(function (it) { return it.u == 'U03'; });
    /* the creatives load after the page's own pictures (LD), so they never take the bandwidth of the page's LCP picture
       on a slow phone (onyx home: +1.25 s LCP when they loaded at once) */
    LD.then(function () {
      scan();
      if (fx[0]) load('static/jcx/jcx-l.js').then(function () { w.JcxL && JcxL(fx); });
    });
  }
  var res, ready = new Promise(function (r) { res = r; });
  var api = {
    v: '1.0.0', ready: ready,
    consent: function (b) { if (b && !consentOk) { consentOk = 1; held.splice(0).forEach(function (f) { f(); }); } },
    /* P3-A: on the current page the boxes keep their space (jcx-free1: hidden, no layout shift; jcx-v.js stops their
       videos), and no box fills any more (its observer stops); the next page view folds them before the
       first paint (the head snippet's jcx.f free check) */
    free: function (h) { ST.free = NOW + (h || 1) * 3600; save(); html.classList.add('jcx-free1'); io.disconnect(); },
    refresh: scan,
    on: function (n, f) { d.addEventListener('jcx:' + n, function (e) { f(e.detail, e); }); },
    cfg: function () { return ready; },
    _act: act,
    _h: { E: E, lbl: lbl, image: image, card: card, build: build, load: load, ok: ok, shown: shown, gate: gate, rv: rv, go: go, pick: pick, cnt: cnt, emit: emit, rm: rm, size: size, ss: ss, save: save, mark: mark, hooks: hooks, S: S, D: D, ST: ST, SOK: SOK, PH: PH, NOW: NOW, cfg: function () { return cfg; } }
  };
  w.JCX = Object.freeze(api);
  /* visitor-language strings for pasted / example code (the X34 push mock, CONTRACT-3 E8): the placer prints the
     ui.jcx_* / template_ui_* keys that the code of the ON items names (#jcx-d k); 'ui.jcx_x' and 'jcx_x' both work; an
     unknown key gives the caller's fallback, else '' */
  w.JcxT = function (k, f) { return (D.k || {})[String(k).replace('ui.', '')] || f || ''; };
  (w.JCXQ || []).forEach(function (q) { act(q[0], q[1]); });
  /* jcx.css comes as a <link> in the head from the placer (and from jcx-p.js in the preview) */
  if (D.pv) return res(cfg);
  /* LD: the page's load event, at most 2.5 s; c.json waits for it too (the ad requests stay out of the page's own
     loading), except on watch pages, where the pre-roll gate needs it before the player */
  var LD = Promise.race([new Promise(function (r) { d.readyState == 'complete' ? r() : w.addEventListener('load', r); }), new Promise(function (r) { setTimeout(r, 2500); })]);
  Promise.resolve(D.t != 'play' && LD).then(function () { return fetch(D.cfg, { cache: 'no-cache', credentials: 'same-origin' }); }).then(function (r) { return r.json(); }).then(function (c) {
    cfg = c; cfg.set = c.set || {}; cfg.pl = c.pl || {}; cfg.it = c.it || [];
    consentOk = !cfg.set.consent;
    /* IAB TCF v2: tcloaded / useractioncomplete with purpose 1 releases held code */
    if (!consentOk) try { w.__tcfapi('addEventListener', 2, function (t) { try { /^[tu]/.test(t.eventStatus) && t.purpose.consents[1] && api.consent(1); } catch (e) {} }); } catch (e) {}
    if (cfg.set.geo && !ss('jcx.cc') && cfg.it.some(function (it) { return it.cc; })) return fetch(D.api + 'geo').then(function (r) { return r.json(); }).then(function (j) { ss('jcx.cc', j.cc || '-'); }, function () {});
  }).then(function () { start(); res(cfg); }).catch(function () { res(cfg); });
})(window, document);
