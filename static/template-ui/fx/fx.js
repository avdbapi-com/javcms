/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit 1.0 - loader + core | JavCMS Community License 1.0 | original code (see LICENSE.txt)
 * Usage: <link rel="stylesheet" href="/static/template-ui/fx/fx.css">
 *        <script>if('noModule' in HTMLScriptElement.prototype)document.documentElement.classList.add('fx-js')</script>
 *        <script type="module" src="/static/template-ui/fx/fx.js?v=1"></script>
 * Then mark elements: data-fx="reveal", data-fx="aurora", data-fx="split tilt", ...
 * Only the modules that the page needs are imported, after first paint / idle. */
const D = document, W = window, ROOT = D.documentElement;
const SELF = new URL(import.meta.url);
const Q = SELF.search; // "?v=..." is propagated to every kit module (cache busting)
const CFG = Object.assign({}, W.FX_CONFIG || {});
const T0 = performance.now();

function mq(q) {
  try { return W.matchMedia(q); } catch (e) { return { matches: false }; }
}
function store(k) { try { return W.localStorage.getItem(k); } catch (e) { return null; } }

const nav = navigator, conn = nav.connection || {};
const env = {
  reduced: CFG.reduced != null ? !!CFG.reduced : (store('fx.reduced') === '1' || mq('(prefers-reduced-motion: reduce)').matches),
  saveData: !!conn.saveData || mq('(prefers-reduced-data: reduce)').matches,
  lowMemory: nav.deviceMemory > 0 && nav.deviceMemory < 4,
  lowCpu: nav.hardwareConcurrency > 0 && nav.hardwareConcurrency <= 4,
  force: !!CFG.force || store('fx.force') === '1',
  fine: CFG.fine != null ? !!CFG.fine : mq('(hover: hover) and (pointer: fine)').matches,
  coarse: mq('(pointer: coarse)').matches,
  dpr: Math.min(W.devicePixelRatio || 1, 2),
  webgl: null
};
env.lowPower = !env.force && (env.saveData || env.lowMemory || env.lowCpu || !!CFG.low || store('fx.low') === '1');

/* name: [module, gate, tier]
 * gate 0 always | 1 motion | 2 motion + fine pointer | 3 motion + power (WebGL) | 4 motion + power + fine pointer | 5 fine pointer
 * tier 1 after first paint | 2 after load + idle */
const G = ['gl', 3, 2];
const REG = {
  aurora: G, silk: G, liquid: G, threads: G, iridescence: G, particles: G, galaxy: G, waves: G, orb: G, mesh: G, rays: G, grid: G, shader: G,
  reveal: ['reveal', 1, 1], stagger: ['reveal', 1, 1],
  split: ['text', 1, 1], scramble: ['text', 1, 1], rotate: ['text', 1, 1], type: ['text', 1, 1], count: ['text', 1, 1], glitch: ['text', 1, 1],
  spotlight: ['pointer', 5, 1], glow: ['pointer', 5, 1], tilt: ['pointer', 2, 1], magnet: ['pointer', 2, 1],
  parallax: ['scroll', 1, 2], depth: ['scroll', 2, 2], progress: ['scroll', 0, 1],
  marquee: ['marquee', 1, 1], rail: ['rail', 0, 1],
  vt: ['vt', 1, 1], 'theme-reveal': ['vt', 1, 1],
  skeleton: ['skeleton', 0, 1],
  burst: ['micro', 1, 1], copy: ['micro', 0, 1], ripple: ['micro', 1, 1],
  morph: ['morph', 0, 1],
  smooth: ['smooth', 4, 2], cursor: ['cursor', 4, 2],
  shiny: [], gradient: [], beam: [], ring: [], grain: []
};
/* CSS-animated effects: paused (data-fx-off) while off-screen */
const PAUSE = { shiny: 1, gradient: 1, beam: 1, ring: 1, grain: 1, marquee: 1, skeleton: 1, glitch: 1 };
/* effects that may be pre-hidden by fx.css (html.fx-js) until their module takes over */
const PREHIDE = { reveal: 1, stagger: 1, split: 1, count: 1 };

const log = [];
function describe(el) {
  if (!el || !el.tagName) return '';
  return el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
}
function status(name, el, state, reason) {
  log.push({ name: name, state: state, reason: reason || '', el: describe(el), t: Math.round(performance.now() - T0) });
}

/* ---------- shared helpers handed to every module (ctx) ---------- */
function attr(el, n, d) { const v = el.getAttribute('data-fx-' + n); return v == null || v === '' ? d : v; }
function num(el, n, d) { const v = parseFloat(attr(el, n, '')); return isFinite(v) ? v : d; }
function bool(el, n, d) { const v = attr(el, n, null); return v == null ? d : !(v === 'false' || v === '0' || v === 'off'); }
function css(el, prop, d) {
  const v = W.getComputedStyle(el).getPropertyValue(prop);
  return v && v.trim() ? v.trim() : d;
}
function cssNum(el, prop, d) { const v = parseFloat(css(el, prop, '')); return isFinite(v) ? v : d; }
function rtl(el) { return W.getComputedStyle(el || ROOT).direction === 'rtl'; }

let cctx = null;
function color(str, fallback) {
  if (!str) return fallback;
  str = String(str).trim();
  let m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(str);
  if (m) {
    let h = m[1];
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16) / 255, parseInt(h.slice(2, 4), 16) / 255, parseInt(h.slice(4, 6), 16) / 255, 1];
  }
  if (!cctx) {
    const c = D.createElement('canvas'); c.width = c.height = 1;
    cctx = c.getContext('2d', { willReadFrequently: true });
    if (!cctx) return fallback;
  }
  cctx.fillStyle = '#010203';
  cctx.fillStyle = str;
  if (cctx.fillStyle === '#010203' && !/^#010203$/i.test(str)) return fallback;
  cctx.clearRect(0, 0, 1, 1);
  cctx.fillRect(0, 0, 1, 1);
  const d = cctx.getImageData(0, 0, 1, 1).data;
  return [d[0] / 255, d[1] / 255, d[2] / 255, d[3] / 255];
}

const ios = {};
function inView(el, cb, margin, threshold) {
  margin = margin || '0px'; threshold = threshold || 0;
  if (!('IntersectionObserver' in W)) { setTimeout(function () { cb(true, null); }, 0); return function () {}; }
  const key = margin + '|' + threshold;
  let rec = ios[key];
  if (!rec) {
    const map = new Map();
    rec = ios[key] = {
      map: map,
      io: new IntersectionObserver(function (entries) {
        for (let i = 0; i < entries.length; i++) {
          const f = map.get(entries[i].target);
          if (f) f(entries[i].isIntersecting, entries[i]);
        }
      }, { rootMargin: margin, threshold: threshold })
    };
  }
  rec.map.set(el, cb);
  rec.io.observe(el);
  return function () { rec.map.delete(el); rec.io.unobserve(el); };
}

const tickers = new Set();
let rafId = 0, lastT = 0;
function loop(t) {
  rafId = 0;
  const dt = lastT ? Math.min(t - lastT, 100) : 16.7;
  lastT = t;
  tickers.forEach(function (f) { if (f(t, dt) === false) tickers.delete(f); });
  if (tickers.size) rafId = requestAnimationFrame(loop); else lastT = 0;
}
function tick(f) {
  tickers.add(f);
  if (!rafId) rafId = requestAnimationFrame(loop);
  return function () { tickers.delete(f); };
}

const visSubs = new Set();
D.addEventListener('visibilitychange', function () { visSubs.forEach(function (f) { f(!D.hidden); }); });
function onVisible(f) { visSubs.add(f); return function () { visSubs.delete(f); }; }

const themeSubs = new Set();
let themeMo = null, themeRaf = 0;
function onTheme(f) {
  themeSubs.add(f);
  if (!themeMo && 'MutationObserver' in W) {
    themeMo = new MutationObserver(function () {
      if (themeRaf) return;
      themeRaf = requestAnimationFrame(function () { themeRaf = 0; themeSubs.forEach(function (g) { g(); }); });
    });
    themeMo.observe(ROOT, { attributes: true, attributeFilter: ['data-color-mode', 'data-theme', 'class', 'style', 'dir'] });
  }
  return function () { themeSubs.delete(f); };
}

let live = null;
function announce(text) {
  if (!live) {
    live = D.createElement('div');
    live.className = 'fx-sr';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    D.body.appendChild(live);
  }
  live.textContent = '';
  setTimeout(function () { live.textContent = text; }, 30);
}

function idle(fn, timeout) {
  if (W.requestIdleCallback) W.requestIdleCallback(fn, { timeout: timeout || 1500 });
  else setTimeout(fn, 120);
}
function afterPaint(fn) { requestAnimationFrame(function () { setTimeout(fn, 0); }); }
function afterLoad(fn) {
  let done = false;
  const go = function () { if (!done) { done = true; idle(fn, 2000); } };
  if (D.readyState === 'complete') go();
  else { W.addEventListener('load', go, { once: true }); setTimeout(go, 4000); }
}

/* Same-document View Transition helper with a no-op fallback. */
function transition(update, types) {
  if (env.reduced || !D.startViewTransition) { update(); return Promise.resolve(false); }
  try {
    const vt = types && types.length ? D.startViewTransition({ update: update, types: types }) : D.startViewTransition(update);
    return vt.finished.then(function () { return true; }, function () { return false; });
  } catch (e) {
    try { const vt2 = D.startViewTransition(update); return vt2.finished.then(function () { return true; }, function () { return false; }); } catch (e2) { update(); return Promise.resolve(false); }
  }
}

const ctx = {
  q: Q, base: new URL('./', SELF).href, env: env, cfg: CFG, t0: T0,
  attr: attr, num: num, bool: bool, css: css, cssNum: cssNum, rtl: rtl, color: color,
  inView: inView, tick: tick, onVisible: onVisible, onTheme: onTheme, idle: idle, announce: announce,
  status: status, transition: transition, mq: mq,
  load: function (name) { return load(name); }
};

/* ---------- loader ---------- */
const mods = {}, timings = {};
function load(name) {
  if (!mods[name]) mods[name] = import(new URL('./' + name + '.js' + Q, SELF).href);
  return mods[name];
}

function gate(g) {
  if (!g) return '';
  if (env.reduced && g !== 5) return 'reduced-motion';
  if ((g === 2 || g === 4 || g === 5) && !env.fine) return 'coarse-pointer';
  if ((g === 3 || g === 4) && env.lowPower) return 'low-power';
  return '';
}

function settle(el, name) {
  if (!PREHIDE[name]) return;
  el.setAttribute('data-fx-in', 'static');
  if (name === 'stagger') for (let i = 0; i < el.children.length; i++) el.children[i].setAttribute('data-fx-in', 'static');
}

const done = new WeakMap();
const tierQ = { 1: {}, 2: {} };
let tierReady = { 1: false, 2: false };
let pending = 0, readyResolve, loadedResolve;
const ready = new Promise(function (r) { readyResolve = r; });
const loaded = new Promise(function (r) { loadedResolve = r; });

function names(el) { return (el.getAttribute('data-fx') || '').trim().split(/\s+/).filter(Boolean); }

function collect(el, out) {
  const list = names(el);
  let set = done.get(el);
  for (let i = 0; i < list.length; i++) {
    const name = list[i];
    if (set && set.has(name)) continue;
    if (!set) { set = new Set(); done.set(el, set); }
    set.add(name);
    const r = REG[name];
    if (!r) { status(name, el, 'unknown'); continue; }
    if (PAUSE[name]) pauser(el);
    if (!r[0]) { status(name, el, 'css'); continue; }
    const why = gate(r[1]);
    if (why) { settle(el, name); status(name, el, 'static', why); continue; }
    const bucket = out[r[2]][r[0]] || (out[r[2]][r[0]] = []);
    bucket.push({ el: el, name: name });
  }
}

function scan(root) {
  root = root || ROOT;
  const out = { 1: {}, 2: {} };
  if (root.nodeType === 1 && root.hasAttribute('data-fx')) collect(root, out);
  if (root.querySelectorAll) {
    const all = root.querySelectorAll('[data-fx]');
    for (let i = 0; i < all.length; i++) {
      if (all[i].closest('[data-fx-clone]')) continue;
      collect(all[i], out);
    }
  }
  [1, 2].forEach(function (tier) {
    Object.keys(out[tier]).forEach(function (mod) {
      const items = out[tier][mod];
      if (tierReady[tier]) run(mod, items);
      else {
        const arr = tierQ[tier][mod] || (tierQ[tier][mod] = []);
        arr.push.apply(arr, items);
      }
    });
  });
}

function run(mod, items) {
  pending++;
  return load(mod).then(function (m) {
    /* one macrotask per module: keeps every init task short on slow phones */
    return new Promise(function (resolve) {
      setTimeout(function () {
        const t = performance.now();
        try { m.init(items, ctx); } catch (e) { fail(mod, items, e); }
        timings[mod] = Math.round((timings[mod] || 0) + performance.now() - t);
        resolve();
      }, 0);
    });
  }, function (e) { fail(mod, items, e); }).then(function () {
    pending--;
  });
}

function fail(mod, items, e) {
  for (let i = 0; i < items.length; i++) { settle(items[i].el, items[i].name); status(items[i].name, items[i].el, 'error', String(e && e.message || e).slice(0, 120)); }
  if (W.console && console.warn) console.warn('[fx] ' + mod + ':', e);
}

function flush(tier) {
  tierReady[tier] = true;
  const q = tierQ[tier]; tierQ[tier] = {};
  return Promise.all(Object.keys(q).map(function (mod) { return run(mod, q[mod]); }));
}

function pauser(el) {
  inView(el, function (v) { if (v) el.removeAttribute('data-fx-off'); else el.setAttribute('data-fx-off', ''); }, '120px');
}

/* Re-read data-fx of one element (e.g. after switching data-fx="aurora" to "waves"). Modules with their own
   refresh() (the WebGL engine) rebuild; for the others only names that were not there before are initialised. */
function refresh(el) {
  const old = done.get(el) || new Set();
  done.delete(el);
  const out = { 1: {}, 2: {} };
  collect(el, out);
  const seen = {};
  [1, 2].forEach(function (tier) {
    Object.keys(out[tier]).forEach(function (mod) {
      const items = out[tier][mod];
      seen[mod] = 1;
      load(mod).then(function (m) {
        if (m.refresh) m.refresh(el, ctx, items);
        else {
          const fresh = items.filter(function (it) { return !old.has(it.name); });
          if (fresh.length) m.init(fresh, ctx);
        }
      });
    });
  });
  /* a module whose effect was removed from the element (e.g. background switched off) can clean up */
  old.forEach(function (name) {
    const r = REG[name];
    if (r && r[0] && !seen[r[0]] && mods[r[0]]) { seen[r[0]] = 1; mods[r[0]].then(function (m) { if (m.refresh) m.refresh(el, ctx, []); }, function () {}); }
  });
  return names(el);
}

/* ---------- start ---------- */
ROOT.classList.add('fx-live');
ROOT.setAttribute('data-fx-motion', env.reduced ? 'reduce' : 'full');
ROOT.setAttribute('data-fx-power', env.lowPower ? 'low' : 'high');
ROOT.setAttribute('data-fx-pointer', env.fine ? 'fine' : 'coarse');

/* Script ran so late that the CSS failsafe already revealed pre-hidden content: skip entrances. */
if (ROOT.classList.contains('fx-js') && T0 > 2800) {
  const late = D.querySelectorAll('[data-fx~="reveal"],[data-fx~="split"],[data-fx~="count"],[data-fx~="stagger"],[data-fx~="stagger"] > *');
  for (let i = 0; i < late.length; i++) if (!late[i].hasAttribute('data-fx-in')) late[i].setAttribute('data-fx-in', 'late');
}

function start() {
  scan(ROOT);
  afterPaint(function () {
    flush(1).then(function () { readyResolve(true); });
  });
  afterLoad(function () {
    flush(2).then(function () { loadedResolve(true); });
  });
  if ('MutationObserver' in W && D.body) {
    let added = [], raf = 0;
    new MutationObserver(function (records) {
      for (let i = 0; i < records.length; i++) {
        const nodes = records[i].addedNodes;
        for (let j = 0; j < nodes.length; j++) {
          const n = nodes[j];
          if (n.nodeType !== 1) continue;
          const cls = typeof n.className === 'string' ? n.className : '';
          if (cls.indexOf('fx-') === 0) continue;
          if (n.hasAttribute('data-fx') || (n.firstElementChild && n.querySelector('[data-fx]'))) added.push(n);
        }
      }
      if (added.length && !raf) {
        raf = requestAnimationFrame(function () {
          raf = 0;
          const list = added; added = [];
          for (let k = 0; k < list.length; k++) if (list[k].isConnected && !list[k].closest('[data-fx-clone]')) scan(list[k]);
        });
      }
    }).observe(D.body, { childList: true, subtree: true });
  }
}

/* One loader per page, even if a theme includes fx.js twice with different ?v= queries. */
const BOOTED = !!W.__fxBooted;
W.__fxBooted = true;
if (!BOOTED) {
  if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}

if (!BOOTED) W.FX = {
  version: '1.0.0',
  env: env,
  ctx: ctx,
  ready: ready,
  loaded: loaded,
  scan: function (root) { scan(root || ROOT); },
  refresh: refresh,
  status: function () { return log.slice(); },
  timings: function () { return Object.assign({}, timings); },
  transition: transition,
  burst: function (el, opts) { return load('micro').then(function (m) { return m.burst(el, opts, ctx); }); },
  load: load
};
