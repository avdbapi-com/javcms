/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Neon Nocturne (Modern) - effects module (ES module) | original work for JavCMS (JavCMS Community License 1.0)
 * N11 shop-window carousel with distance glow (Embla 8.6 + Autoplay, imported lazily on the home page only),
 * N7 split-flap departures board (per-character rotateX flaps, staggered), N2 step-print ghost trail (pointer-direction
 * variables on hovered cards, rAF-batched, fine pointer only), N4 Tilt Neon XROT/YROT axis tilt (pointer, lerped),
 * N15 rain-streak skeleton end state. Everything is progressive enhancement: reduced motion -> static; Save-Data /
 * low power -> no ghosts, no tilt; touch -> no ghosts, no tilt. The kit (fx.js) owns the WebGL host, marquee, rail, morph, vt. */
const D = document, W = window, ROOT = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mq = (q) => { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
const store = (k) => { try { return W.localStorage.getItem(k); } catch (e) { return null; } };
const CFG = W.FX_CONFIG || {};
const REDUCED = CFG.reduced != null ? !!CFG.reduced : (mq('(prefers-reduced-motion: reduce)') || store('fx.reduced') === '1');
const FORCE = !!CFG.force || store('fx.force') === '1';
const LOW = !FORCE && (ROOT.classList.contains('nn-low') || store('fx.low') === '1');
const FINE = CFG.fine != null ? !!CFG.fine : mq('(hover: hover) and (pointer: fine)');
const RTL = ROOT.getAttribute('dir') === 'rtl';
const LANG = (ROOT.getAttribute('lang') || 'en').slice(0, 2).toLowerCase();
const NO_TILT_LANG = ['ja', 'zh', 'ko', 'th', 'ar', 'hi', 'ru'].indexOf(LANG) >= 0;
const idle = (fn, t) => (W.requestIdleCallback ? W.requestIdleCallback(fn, { timeout: t || 1500 }) : setTimeout(fn, 120));
const $$ = (s, c) => Array.prototype.slice.call((c || D).querySelectorAll(s));
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const text = (k, f) => { const p = W.NnText || {}; return typeof p[k] === 'string' && p[k] ? p[k] : (f || ''); };

/* ---------- N11 shop windows: Embla loop + drag + autoplay (6 s, pause button) + glow by distance from the centre ---------- */
function windows() {
  const box = D.querySelector('[data-nn-windows]');
  if (!box) return;
  const viewport = box.querySelector('[data-nn-embla-viewport]');
  const slides = $$('.nn-win', box);
  if (!viewport || slides.length < 2) { box.classList.add('is-static'); return; }
  const prev = box.querySelector('[data-nn-win-prev]'), next = box.querySelector('[data-nn-win-next]'), pause = box.querySelector('[data-nn-win-pause]');
  const glow = () => {
    const r = viewport.getBoundingClientRect(), mid = r.left + r.width / 2, half = Math.max(1, r.width / 2);
    slides.forEach((s) => {
      const b = s.getBoundingClientRect();
      const d = Math.abs((b.left + b.width / 2) - mid) / half;
      const g = clamp(1 - d * 1.15, 0, 1);
      s.style.setProperty('--glow', g.toFixed(3));
      s.classList.toggle('is-centre', g > 0.82);
    });
  };
  let raf = 0;
  const schedule = () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; glow(); }); };
  glow();
  const start = () => Promise.all([
    import(VENDOR + 'embla-carousel@8.6.0/embla-carousel.esm.min.js'),
    REDUCED ? Promise.resolve(null) : import(VENDOR + 'embla-carousel-autoplay@8.6.0/embla-carousel-autoplay.esm.min.js')
  ]).then(([E, A]) => {
    const Embla = E.default || E;
    const plugins = [];
    let auto = null;
    if (A && !REDUCED) {
      const Autoplay = A.default || A;
      auto = Autoplay({ delay: 6000, stopOnInteraction: false, stopOnMouseEnter: true, stopOnFocusIn: true });
      plugins.push(auto);
    }
    box.classList.add('is-embla');
    const api = Embla(viewport, { loop: true, align: 'center', containScroll: false, direction: RTL ? 'rtl' : 'ltr', duration: REDUCED ? 0 : 22, skipSnaps: false }, plugins);
    api.on('scroll', schedule); api.on('settle', schedule); api.on('reInit', schedule);
    schedule();
    if (prev) prev.addEventListener('click', () => api.scrollPrev());
    if (next) next.addEventListener('click', () => api.scrollNext());
    viewport.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); RTL ? api.scrollPrev() : api.scrollNext(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); RTL ? api.scrollNext() : api.scrollPrev(); }
    });
    if (pause) {
      if (!auto) { pause.hidden = true; }
      else {
        const sync = (playing) => {
          pause.setAttribute('aria-pressed', playing ? 'false' : 'true');
          pause.classList.toggle('is-paused', !playing);
          const label = playing ? text('nn.pause', 'Pause') : text('nn.play', 'Play');
          pause.setAttribute('aria-label', label); pause.setAttribute('title', label);
        };
        let user = false;
        pause.addEventListener('click', () => { if (auto.isPlaying()) { user = true; auto.stop(); sync(false); } else { user = false; auto.play(); sync(true); } });
        api.on('autoplay:play', () => sync(true));
        api.on('autoplay:stop', () => { if (!user) sync(false); });
        D.addEventListener('visibilitychange', () => { if (D.hidden) auto.stop(); else if (!user) auto.play(); });
        sync(auto.isPlaying());
      }
    }
    W.NN = W.NN || {}; W.NN.embla = api;
  }).catch(() => { box.classList.add('is-static'); });
  if ('IntersectionObserver' in W) {
    const io = new IntersectionObserver((en) => { if (en[0].isIntersecting) { io.disconnect(); idle(start, 1200); } }, { rootMargin: '200px 0px' });
    io.observe(box);
  } else idle(start, 1200);
}

/* ---------- N7 split-flap: rank + code characters flip like departures tiles when the board scrolls into view ---------- */
function flaps() {
  const rows = $$('[data-nn-board] [data-nn-row]');
  if (!rows.length) return;
  const cells = $$('[data-nn-flap]');
  if (REDUCED || !('IntersectionObserver' in W)) { cells.forEach((c) => c.setAttribute('data-nn-flap-done', '')); return; }
  const NARROW = mq('(max-width: 767px)'); // phones: only the rank column flips (concept 7.9), the rest stays static text
  const prepare = (cell) => {
    if (cell.hasAttribute('data-nn-flap-ready')) return;
    const str = (cell.textContent || '').trim();
    cell.setAttribute('aria-label', str);
    cell.setAttribute('data-nn-flap-ready', '');
    cell.textContent = '';
    const wrap = D.createElement('span');
    wrap.className = 'nn-flap-w'; wrap.setAttribute('aria-hidden', 'true');
    Array.prototype.forEach.call(str, (ch, i) => {
      const s = D.createElement('span');
      s.className = 'nn-flap-c'; s.style.setProperty('--c', String(i)); s.textContent = ch === ' ' ? ' ' : ch;
      wrap.appendChild(s);
    });
    cell.appendChild(wrap);
  };
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      const row = e.target;
      $$('[data-nn-flap]', row).forEach((c) => { if (NARROW && !c.classList.contains('nn-board-rank')) { c.setAttribute('data-nn-flap-done', ''); return; } prepare(c); });
      row.classList.add('is-flip');
    });
  }, { rootMargin: '0px 0px -8% 0px' });
  rows.forEach((r, i) => { r.style.setProperty('--row', String(i)); io.observe(r); });
}

/* ---------- N2 step-print ghosts: the two thumbnail copies trail behind the pointer's direction of travel ---------- */
function ghosts() {
  if (!FINE || REDUCED || LOW) return;
  let card = null, lx = 0, ly = 0, raf = 0, gx = 0, gy = 0, tx = 0, ty = 0, settle = 0;
  const apply = () => {
    raf = 0;
    if (!card) return;
    gx += (tx - gx) * 0.3; gy += (ty - gy) * 0.3;
    card.style.setProperty('--gx', gx.toFixed(1) + 'px');
    card.style.setProperty('--gy', gy.toFixed(1) + 'px');
    if (Math.abs(tx - gx) > 0.3 || Math.abs(ty - gy) > 0.3) raf = requestAnimationFrame(apply);
  };
  D.addEventListener('pointermove', (e) => {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    const c = e.target && e.target.closest ? e.target.closest('.nn-card, .nn-win') : null;
    if (c !== card) {
      if (card) { card.style.removeProperty('--gx'); card.style.removeProperty('--gy'); }
      card = c; gx = gy = tx = ty = 0; lx = e.clientX; ly = e.clientY;
      return;
    }
    if (!card) return;
    const dx = e.clientX - lx, dy = e.clientY - ly;
    lx = e.clientX; ly = e.clientY;
    tx = clamp(tx * 0.6 - dx * 0.9, -16, 16);
    ty = clamp(ty * 0.6 - dy * 0.9, -16, 16);
    clearTimeout(settle);
    settle = setTimeout(() => { tx = ty = 0; if (!raf) raf = requestAnimationFrame(apply); }, 90);
    if (!raf) raf = requestAnimationFrame(apply);
  }, { passive: true });
  D.addEventListener('pointerleave', () => { if (card) { card.style.removeProperty('--gx'); card.style.removeProperty('--gy'); card = null; } });
}

/* ---------- N4 Tilt Neon axis tilt: XROT / YROT follow the pointer (+-15), lerped; fine pointer only ---------- */
function tilt() {
  const els = $$('[data-nn-tilt]');
  if (!els.length || !FINE || REDUCED || LOW || NO_TILT_LANG || ROOT.classList.contains('nn-save')) return;
  let px = 0.5, py = 0.5, cx = 0, cy = 0, raf = 0, active = false;
  const vis = new Set();
  const step = () => {
    raf = 0;
    const txr = (0.5 - py) * 30, tyr = (px - 0.5) * 30 * (RTL ? -1 : 1);
    cx += (txr - cx) * 0.08; cy += (tyr - cy) * 0.08;
    const v = '"XROT" ' + cx.toFixed(2) + ', "YROT" ' + cy.toFixed(2);
    vis.forEach((el) => { el.style.fontVariationSettings = v; });
    if (Math.abs(txr - cx) > 0.05 || Math.abs(tyr - cy) > 0.05) raf = requestAnimationFrame(step);
  };
  if ('IntersectionObserver' in W) {
    const io = new IntersectionObserver((entries) => { entries.forEach((e) => { if (e.isIntersecting) vis.add(e.target); else vis.delete(e.target); }); });
    els.forEach((el) => io.observe(el));
  } else els.forEach((el) => vis.add(el));
  W.addEventListener('pointermove', (e) => {
    if (!vis.size) return;
    px = clamp(e.clientX / W.innerWidth, 0, 1); py = clamp(e.clientY / W.innerHeight, 0, 1);
    active = true;
    if (!raf) raf = requestAnimationFrame(step);
  }, { passive: true });
  D.addEventListener('pointerleave', () => { if (!active) return; px = 0.5; py = 0.5; if (!raf) raf = requestAnimationFrame(step); });
}

/* ---------- N15: posters that finished loading drop the rain-streak skeleton (the kit sets data-fx-loaded) ---------- */
function streaks() {
  if (!('MutationObserver' in W)) return;
  const done = (el) => { if (el.getAttribute('data-fx-loaded') === 'ok') el.classList.add('is-loaded'); };
  $$('[data-fx~="skeleton"]').forEach(done);
  new MutationObserver((m) => { m.forEach((r) => { if (r.type === 'attributes' && r.target.getAttribute) done(r.target); }); })
    .observe(D.body, { attributes: true, subtree: true, attributeFilter: ['data-fx-loaded'] });
}

function init() {
  windows();
  flaps();
  ghosts();
  tilt();
  streaks();
  ROOT.classList.add('nn-fx');
}
if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', init); else init();
