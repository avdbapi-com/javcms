/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Topaz Prism (Modern) - effects layer (ES module) | original work for JavCMS (JavCMS Community License 1.0)
 * T3 sparkle glints (theme-local scheduler), T4 crystal layered parallax (Atropos), T7 star sparkle burst (canvas-confetti),
 * T8 digit roll (NumberFlow), T10 Zdog gem (404 / empty states) and AutoAnimate for the palette results and user lists.
 * Vendored libraries are imported lazily and only on pages that need them; everything degrades to the static markup. */
const D = document, W = window, ROOT = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mq = (q) => { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
const store = (k) => { try { return W.localStorage.getItem(k); } catch (e) { return null; } };
const ENV = (W.FX && W.FX.env) || {};
const REDUCED = ENV.reduced != null ? ENV.reduced : (mq('(prefers-reduced-motion: reduce)') || store('fx.reduced') === '1');
const LOW = ENV.lowPower != null ? ENV.lowPower : (!!(navigator.connection && navigator.connection.saveData) || (navigator.deviceMemory > 0 && navigator.deviceMemory < 4) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 4) || store('fx.low') === '1');
const FINE = mq('(hover: hover) and (pointer: fine)');
const PHONE = mq('(max-width: 767px)');
const lib = (path) => import(VENDOR + path);
const idle = (fn, t) => (W.requestIdleCallback ? W.requestIdleCallback(fn, { timeout: t || 1800 }) : setTimeout(fn, 160));
const inView = (el, fn, margin) => {
  if (!('IntersectionObserver' in W)) { fn(); return; }
  const io = new IntersectionObserver((en) => { if (en[0].isIntersecting) { io.disconnect(); fn(); } }, { rootMargin: margin || '120px 0px' });
  io.observe(el);
};

/* ---------- T3 sparkle glints: CSS 4-point stars placed by a small scheduler (max 2 at a time on phones) ---------- */
const SPARK = { live: 0, max: PHONE ? 2 : 5 };
function spark(host, x, y, kind) {
  if (REDUCED || LOW || SPARK.live >= SPARK.max || !host) return;
  const s = D.createElement('i');
  s.className = 'tp-spark' + (kind ? ' tp-spark--' + kind : '');
  s.style.left = x + '%'; s.style.top = y + '%'; s.style.marginLeft = '-7px'; s.style.marginTop = '-7px';
  host.appendChild(s);
  SPARK.live++;
  setTimeout(() => { s.remove(); SPARK.live--; }, 950);
}
function schedule(host, corners, minMs, maxMs, kind) {
  if (REDUCED || LOW) return;
  let timer = 0, visible = true;
  const tick = () => {
    timer = 0;
    if (!visible || D.hidden) return;
    const c = corners[(Math.random() * corners.length) | 0];
    spark(host, c.x, c.y, kind || (Math.random() < 0.25 ? 'warm' : (Math.random() < 0.5 ? 'ice' : '')));
    timer = setTimeout(tick, minMs + Math.random() * (maxMs - minMs));
  };
  const start = () => { if (!timer && visible && !D.hidden) timer = setTimeout(tick, 500); };
  if ('IntersectionObserver' in W) new IntersectionObserver((en) => { visible = en[0].isIntersecting; if (visible) start(); else { clearTimeout(timer); timer = 0; } }).observe(host);
  D.addEventListener('visibilitychange', start);
  start();
}
function initSparkles() {
  const FRAME = [{ x: 3, y: 7 }, { x: 96, y: 6 }, { x: 4, y: 92 }, { x: 95, y: 90 }, { x: 50, y: 3 }];
  D.querySelectorAll('[data-tp-frame] [data-tp-sparks], [data-tp-cover] [data-tp-sparks]').forEach((h) => schedule(h, FRAME, 1600, 3200));
  if (FINE) {
    const logo = D.querySelector('.tp-hd .tp-logo');
    if (logo) schedule(logo, [{ x: 6, y: 18 }, { x: 14, y: 70 }], 12000, 20000, 'ice');
    D.addEventListener('pointerover', (e) => {
      const card = e.target.closest && e.target.closest('.tp-facet, .tp-cut');
      if (!card) return;
      if (e.relatedTarget && card.contains(e.relatedTarget)) return;
      const now = performance.now();
      if (card.__sparkAt && now - card.__sparkAt < 900) return;
      card.__sparkAt = now;
      const host = card.querySelector('.tp-cover') || card;
      const corners = [{ x: 8, y: 14 }, { x: 90, y: 12 }, { x: 10, y: 84 }, { x: 88, y: 82 }];
      const c = corners[(Math.random() * 4) | 0];
      spark(host, c.x, c.y, Math.random() < 0.3 ? 'warm' : '');
    }, true);
  }
}

/* ---------- T4 crystal layered parallax: Atropos on the vitrine frame and the detail cover (fine pointer only) ---------- */
function initAtropos() {
  if (!FINE || REDUCED || LOW) return;
  const els = D.querySelectorAll('.atropos[data-tp-frame], .atropos[data-tp-cover]');
  if (!els.length) return;
  idle(() => {
    if (!D.querySelector('link[data-tp-atropos]')) {
      const l = D.createElement('link'); l.rel = 'stylesheet'; l.href = VENDOR + 'atropos@2.0.2/atropos.min.css'; l.setAttribute('data-tp-atropos', ''); D.head.appendChild(l);
    }
    lib('atropos@2.0.2/atropos.esm.min.js').then((m) => {
      const Atropos = m.default || m;
      els.forEach((el) => { try { Atropos({ el: el, activeOffset: 28, shadow: false, highlight: false, rotateXMax: 6, rotateYMax: 6, duration: 450, rotateTouch: false }); el.classList.add('is-atropos'); } catch (e) { /* static frame */ } });
    }).catch(() => {});
  });
}

/* ---------- T8 digit roll: NumberFlow on the library placard, counts and user stats (plain number elsewhere) ---------- */
function initNumbers() {
  const els = Array.prototype.slice.call(D.querySelectorAll('[data-tp-nf]'));
  if (!els.length || REDUCED || LOW || !(W.CSS && CSS.registerProperty) || !('IntersectionObserver' in W)) return;
  let loader = null;
  const io = new IntersectionObserver((entries) => {
    const hit = entries.filter((e) => e.isIntersecting).map((e) => e.target);
    if (!hit.length) return;
    hit.forEach((el) => io.unobserve(el));
    (loader || (loader = lib('number-flow@0.6.2/number-flow.esm.min.js'))).then(() => {
      hit.forEach((el) => {
        const n = parseInt(el.getAttribute('data-tp-nf'), 10);
        if (!(n >= 0) || el.querySelector('number-flow')) return;
        const nf = D.createElement('number-flow');
        try { nf.locales = ROOT.lang || 'en'; nf.format = { useGrouping: true }; } catch (e) { /* defaults */ }
        const start = n > 20 ? Math.round(n * 0.94) : 0;
        nf.update(start);
        /* NumberFlow's rolling digits live in a shadow root without an accessible name: keep the server-rendered number
           for screen readers (visually hidden) and hide the animated element from the accessibility tree */
        const sr = D.createElement('span');
        sr.className = 'tp-sr';
        sr.textContent = el.textContent.trim();
        nf.setAttribute('aria-hidden', 'true');
        el.textContent = '';
        el.appendChild(sr);
        el.appendChild(nf);
        requestAnimationFrame(() => requestAnimationFrame(() => nf.update(n)));
      });
    }).catch(() => {});
  }, { rootMargin: '40px 0px' });
  els.forEach((el) => io.observe(el));
}

/* ---------- AutoAnimate: palette results, user lists, comments ---------- */
let aaP = null;
function aa(el) {
  if (REDUCED || !el || el.__tpAa) return;
  el.__tpAa = true;
  (aaP || (aaP = lib('auto-animate@0.10.0/auto-animate.esm.min.js'))).then((m) => (m.default || m.autoAnimate)(el, { duration: 240, easing: 'cubic-bezier(.16,1,.3,1)' })).catch(() => {});
}
function initAutoAnimate() {
  D.addEventListener('tp:pal-open', () => { aa(D.querySelector('[data-tp-pal-list]')); aa(D.querySelector('[data-tp-recent-list]')); });
  D.querySelectorAll('[data-tp-aa]').forEach((el) => inView(el, () => aa(el)));
  D.querySelectorAll('[data-tp-comments]').forEach((el) => inView(el, () => idle(() => aa(el)), '300px 0px'));
}

/* ---------- T7 star sparkle burst when a title is added to favourites ---------- */
function initBurst() {
  D.addEventListener('tp:fav', (e) => {
    if (REDUCED || !e.target || !e.target.getBoundingClientRect) return;
    lib('canvas-confetti@1.9.4/confetti.esm.min.js').then((m) => {
      const confetti = m.default || m;
      const r = e.target.getBoundingClientRect();
      confetti({
        particleCount: LOW ? 10 : 22, spread: 75, startVelocity: 24, gravity: 0.7, ticks: 140, scalar: 0.8, shapes: ['star'],
        colors: ['#ff6b9a', '#ffd36b', '#7dffb2', '#72d7ff', '#b18cff', '#ffffff'],
        origin: { x: (r.left + r.width / 2) / W.innerWidth, y: (r.top + r.height / 2) / W.innerHeight }, disableForReducedMotion: true, zIndex: 2147483000
      });
    }).catch(() => {});
  });
}

/* ---------- T10 Zdog gem: a faceted pseudo-3D stone for the 404 and the empty states (drag to spin) ---------- */
function loadScript(src) {
  return new Promise((res, rej) => { const s = D.createElement('script'); s.src = src; s.async = true; s.onload = res; s.onerror = rej; D.head.appendChild(s); });
}
function initZdog() {
  const boxes = Array.prototype.slice.call(D.querySelectorAll('[data-tp-zdog]'));
  if (!boxes.length) return;
  inView(boxes[0], () => {
    (W.Zdog ? Promise.resolve() : loadScript(VENDOR + 'zdog@1.1.3/zdog.dist.min.js')).then(() => {
      const Z = W.Zdog;
      if (!Z) return;
      boxes.forEach((box) => buildGem(Z, box));
    }).catch(() => {});
  }, '200px 0px');
}
function buildGem(Z, box) {
  const canvas = D.createElement('canvas');
  canvas.setAttribute('aria-hidden', 'true');
  box.appendChild(canvas);
  const light = ROOT.getAttribute('data-color-mode') === 'light';
  const col = light ? { top: '#b9e6f7', side: '#5cb8e0', deep: '#0a6a9e', line: '#5b6b7d', glint: '#ffffff' } : { top: '#e6f8ff', side: '#72d7ff', deep: '#1b7fb8', line: '#dfe7ee', glint: '#ffffff' };
  const illo = new Z.Illustration({ element: canvas, dragRotate: !REDUCED, resize: true, rotate: { x: -0.55, y: 0.35 } });
  const gem = new Z.Anchor({ addTo: illo });
  const R = 44, crownY = -6, tableY = -24, apexY = 56, n = 8;
  const ring = (r, y, off) => { const a = []; for (let i = 0; i < n; i++) { const t = (i / n) * Math.PI * 2 + (off || 0); a.push({ x: Math.cos(t) * r, y: y, z: Math.sin(t) * r }); } return a; };
  const table = ring(R * 0.56, tableY), girdle = ring(R, crownY);
  new Z.Shape({ addTo: gem, path: table, closed: true, fill: true, stroke: 1, color: col.top });
  for (let i = 0; i < n; i++) { const j = (i + 1) % n; new Z.Shape({ addTo: gem, path: [table[i], table[j], girdle[j], girdle[i]], closed: true, fill: true, stroke: 1, color: i % 2 ? col.side : col.top, backface: col.side }); }
  const apex = { x: 0, y: apexY, z: 0 };
  for (let i = 0; i < n; i++) { const j = (i + 1) % n; new Z.Shape({ addTo: gem, path: [girdle[i], girdle[j], apex], closed: true, fill: true, stroke: 1, color: i % 2 ? col.deep : col.side, backface: col.deep }); }
  new Z.Shape({ addTo: gem, path: girdle, closed: true, stroke: 1.5, fill: false, color: col.line });
  new Z.Shape({ addTo: gem, path: table, closed: true, stroke: 1, fill: false, color: col.line });
  new Z.Shape({ addTo: gem, path: [{ x: -10, y: tableY - 1, z: 8 }, { x: -2, y: tableY - 1, z: 2 }], stroke: 2, color: col.glint });
  box.classList.add('is-live');
  illo.updateRenderGraph();
  const sparks = box.querySelector('[data-tp-sparks]');
  if (sparks) schedule(sparks, [{ x: 12, y: 18 }, { x: 86, y: 14 }, { x: 80, y: 84 }, { x: 16, y: 78 }, { x: 50, y: 4 }], 1200, 2600, 'ice');
  if (REDUCED) return;
  let visible = true, raf = 0;
  const tick = () => { raf = 0; if (!visible || D.hidden) return; if (!illo.isDragging) gem.rotate.y += 0.011; illo.updateRenderGraph(); raf = requestAnimationFrame(tick); };
  const start = () => { if (!raf && visible && !D.hidden) raf = requestAnimationFrame(tick); };
  if ('IntersectionObserver' in W) new IntersectionObserver((en) => { visible = en[0].isIntersecting; if (visible) start(); }).observe(box);
  D.addEventListener('visibilitychange', start);
  start();
}

initSparkles();
initAtropos();
initNumbers();
initAutoAnimate();
initBurst();
initZdog();
