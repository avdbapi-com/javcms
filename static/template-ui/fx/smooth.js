/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - optional smooth wheel scrolling | JavCMS Community License 1.0 | original code; uses Lenis 1.3.26 (MIT, vendored)
 * data-fx="smooth" on <html> or <body>. Desktop with a fine pointer only; never with reduced motion or low power.
 * Touch scrolling stays native. Nested scrollers, rails, iframes, dialogs and [data-lenis-prevent] scroll natively.
 * Stops while the page is scroll-locked (overflow:hidden on html/body, e.g. an open modal). data-fx-lerp="0.1". */
const D = document;
// Lenis's own root classes (lenis, lenis-smooth, lenis-stopped, lenis-scrolling, lenis-autoToggle).
const LENIS_TOKEN = /^lenis(-|$)/;

export function init(items, ctx) {
  const env = ctx.env, el = items[0] && items[0].el;
  if (!env.fine || env.reduced || env.lowPower || window.__fxLenis) { ctx.status('smooth', el, 'static', 'gate'); return; }
  return import('../../vendor/fx/lenis@1.3.26/lenis.esm.min.js').then(function (mod) {
    const Lenis = mod.default;
    const lenis = new Lenis({
      autoRaf: true,
      lerp: ctx.num(el, 'lerp', 0.1),
      smoothWheel: true,
      syncTouch: false,
      anchors: false,
      autoResize: true,
      prevent: function (node) {
        return !!(node && node.closest && node.closest('[data-lenis-prevent],[data-fx-rail-track],iframe,video,dialog,[aria-modal="true"],.tui-dialog,textarea,select,[data-fx-noscroll-smooth]'));
      }
    });
    window.__fxLenis = lenis;
    if (window.FX) window.FX.lenis = lenis;

    // Scroll lock: stop Lenis while html/body is overflow:hidden (an open modal) and start it again afterwards.
    // The check runs at most once per frame and Lenis is only called when the lock state really changed. Lenis
    // rewrites the root className on every stop()/start(), so calling it from every class/style mutation re-entered
    // this observer and hung the page (CORE-REQUESTS-topaz-modern.md, 2026-10-12). Mutations that only touch
    // Lenis's own class tokens, or inline styles that do not change overflow, are ignored before any style read.
    let queued = false, locked = false;
    const check = function () {
      queued = false;
      const h = getComputedStyle(D.documentElement).overflowY, b = D.body ? getComputedStyle(D.body).overflowY : '';
      const now = h === 'hidden' || b === 'hidden';
      if (now === locked) return;
      locked = now;
      if (now) lenis.stop(); else lenis.start();
    };
    const classes = function (s) { return String(s || '').split(/\s+/).filter(function (t) { return t && !LENIS_TOKEN.test(t); }).sort().join(' '); };
    const overflow = function (s) { const m = String(s || '').match(/overflow(?:-y)?\s*:\s*[^;]+/g); return m ? m.join(';') : ''; };
    const relevant = function (r) {
      if (r.attributeName === 'class') return classes(r.oldValue) !== classes(r.target.getAttribute('class'));
      if (r.attributeName === 'style') return overflow(r.oldValue) !== overflow(r.target.getAttribute('style'));
      return true;
    };
    const mo = new MutationObserver(function (records) {
      if (queued) return;
      for (let i = 0; i < records.length; i++) {
        if (relevant(records[i])) { queued = true; requestAnimationFrame(check); return; }
      }
    });
    const opts = { attributes: true, attributeFilter: ['style', 'class'], attributeOldValue: true };
    mo.observe(D.documentElement, opts);
    if (D.body) mo.observe(D.body, opts);
    check();
    ctx.status('smooth', el, 'active', 'lenis');
  }, function () { ctx.status('smooth', el, 'error', 'lenis failed to load'); });
}
