/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! Velvet Premiere (Modern) - effects layer (ES module) | original work for JavCMS (JavCMS Community License 1.0)
 * V2 following spotlight (simplex-noise wander), V3 rose-petal burst (canvas-confetti), V6 ticket-tab FLIP highlight
 * (motion-mini), AutoAnimate review list, and the CSS-drape parting when the curtain shader does not run.
 * Vendored libraries are imported lazily and only on pages that need them. */
const D = document, W = window, ROOT = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mq = (q) => { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
const store = (k) => { try { return W.localStorage.getItem(k); } catch (e) { return null; } };
const FXENV = (W.FX && W.FX.env) || {};
const REDUCED = FXENV.reduced != null ? FXENV.reduced : (mq('(prefers-reduced-motion: reduce)') || store('fx.reduced') === '1');
const LOW = FXENV.lowPower != null ? FXENV.lowPower : (!!(navigator.connection && navigator.connection.saveData) || (navigator.deviceMemory > 0 && navigator.deviceMemory < 4) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 4));
const FINE = mq('(hover: hover) and (pointer: fine)');
const EASE = [0.65, 0, 0.35, 1];
let motionMini = null;
const lib = (path) => import(VENDOR + path);
const motion = () => motionMini || (motionMini = lib('motion@13.4.4/motion-mini.min.js'));
const idle = (fn, t) => (W.requestIdleCallback ? W.requestIdleCallback(fn, { timeout: t || 1500 }) : setTimeout(fn, 120));

/* ---------- V6 ticket tabs: the notched ticket slides under the hovered / focused / active tab ---------- */
function initTabs() {
  const strips = D.querySelectorAll('.vp-tabs');
  if (!strips.length) return;
  strips.forEach((strip) => {
    const tabs = Array.prototype.slice.call(strip.querySelectorAll('.vp-tab'));
    const active = strip.querySelector('.vp-tab[aria-current]') || null;
    if (!tabs.length || !active) return;
    const hl = D.createElement('span');
    hl.className = 'vp-tabs-hl';
    hl.setAttribute('aria-hidden', 'true');
    strip.insertBefore(hl, strip.firstChild);
    let at = null, x = 0, w = 0, seq = 0;
    const geom = (tab) => ({ x: tab.offsetLeft, w: tab.offsetWidth });
    const place = (tab, animateIt) => {
      const g = geom(tab);
      if (at) at.classList.remove('is-hl');
      tab.classList.add('is-hl');
      at = tab;
      const from = { x: x, w: w };
      x = g.x; w = g.w;
      hl.style.transform = 'translateX(' + x + 'px)';
      hl.style.width = w + 'px';
      if (!animateIt || REDUCED || !from.w || (from.x === x && from.w === w)) return;
      const run = ++seq;
      strip.classList.add('is-moving');
      motion().then((m) => {
        const a = m.animate(hl, { transform: ['translateX(' + from.x + 'px)', 'translateX(' + x + 'px)'], width: [from.w + 'px', w + 'px'] }, { duration: 0.45, ease: EASE });
        const done = () => { if (run === seq) strip.classList.remove('is-moving'); };
        if (a && a.finished && a.finished.then) a.finished.then(done, done); else setTimeout(done, 460);
      }).catch(() => strip.classList.remove('is-moving'));
    };
    place(active, false);
    strip.classList.add('is-live');
    tabs.forEach((tab) => {
      if (FINE) tab.addEventListener('pointerenter', () => place(tab, true));
      tab.addEventListener('focus', () => place(tab, true));
    });
    strip.addEventListener('pointerleave', () => place(active, true));
    strip.addEventListener('focusout', (e) => { if (!strip.contains(e.relatedTarget)) place(active, true); });
    let rt = 0;
    W.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { x = 0; w = 0; place(at || active, false); }, 150); });
    if (D.fonts && D.fonts.ready) D.fonts.ready.then(() => place(at || active, false));
  });
}

/* ---------- V2 the following spotlight on the stage (pointer on desktop, simplex wander on touch / idle) ---------- */
function initSpotlight() {
  const stage = D.querySelector('[data-vp-stage]');
  if (!stage) return;
  const spot = stage.querySelector('.vp-spot');
  const beam = stage.querySelector('.vp-beam');
  const screen = () => stage.querySelector('.vp-feature.is-active .vp-screen') || stage.querySelector('.vp-screen');
  if (!spot || !beam) return;
  let rect = stage.getBoundingClientRect();
  const home = () => {
    const s = screen();
    if (!s) return { x: rect.width / 2, y: rect.height * 0.62 };
    const r = s.getBoundingClientRect();
    return { x: r.left - rect.left + r.width / 2, y: r.top - rect.top + r.height * 0.92 };
  };
  /* the custom properties go on the two lit elements only: set on the stage they re-styled every card inside it 30x a second */
  const set = (p) => {
    spot.style.setProperty('--vp-sx', p.x.toFixed(1) + 'px');
    spot.style.setProperty('--vp-sy', p.y.toFixed(1) + 'px');
    const dx = p.x - rect.width / 2, dy = Math.max(60, p.y);
    beam.style.setProperty('--vp-beam-a', (-Math.atan2(dx, dy) * 180 / Math.PI).toFixed(2) + 'deg');
  };
  set(home());
  if (REDUCED || LOW) return;
  lib('simplex-noise@4.0.3/simplex-noise.esm.min.js').then((mod) => {
    const noise = mod.createNoise2D();
    let cur = home(), target = home(), pointer = null, lastMove = 0, raf = 0, last = 0, visible = true, t0 = performance.now();
    const premiere = ROOT.classList.contains('vp-premiere');
    if (premiere) { cur = { x: rect.width * (ROOT.dir === 'rtl' ? 0.85 : 0.15), y: rect.height * 0.55 }; }
    const tick = (now) => {
      raf = 0;
      if (!visible || D.hidden) return;
      raf = requestAnimationFrame(tick);
      if (now - last < 33) return;
      const dt = last ? Math.min(now - last, 100) : 33;
      last = now;
      const h = home();
      const idleFor = now - lastMove;
      if (pointer && idleFor < 2600) target = pointer;
      else {
        const t = (now - t0) / 1000;
        target = { x: h.x + noise(t * 0.11, 1.7) * rect.width * 0.18, y: h.y + noise(2.3, t * 0.09) * rect.height * 0.07 };
      }
      const k = 1 - Math.exp(-dt / (pointer && idleFor < 2600 ? 140 : 700));
      cur = { x: cur.x + (target.x - cur.x) * k, y: cur.y + (target.y - cur.y) * k };
      set(cur);
    };
    const start = () => { if (!raf && visible && !D.hidden) { last = 0; raf = requestAnimationFrame(tick); } };
    if (FINE) {
      stage.addEventListener('pointermove', (e) => {
        if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
        pointer = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        lastMove = performance.now();
      }, { passive: true });
      stage.addEventListener('pointerleave', () => { pointer = null; });
    }
    stage.addEventListener('vp:feature', () => { cur = { x: cur.x + (ROOT.dir === 'rtl' ? -1 : 1) * rect.width * 0.22, y: cur.y }; });
    const measure = () => { rect = stage.getBoundingClientRect(); };
    W.addEventListener('resize', measure, { passive: true });
    W.addEventListener('scroll', () => { if (visible) measure(); }, { passive: true });
    new IntersectionObserver((entries) => { visible = entries[0].isIntersecting; if (visible) { measure(); start(); } }, { threshold: 0 }).observe(stage);
    D.addEventListener('visibilitychange', start);
    start();
  }).catch(() => {});
}

/* ---------- the CSS drapes part when the curtain shader does not take over (no WebGL, refused, failed) ---------- */
function initParting() {
  const stage = D.querySelector('[data-vp-stage]');
  if (!stage || !ROOT.classList.contains('vp-premiere')) return;
  let finished = false;
  const part = () => {
    if (finished) return;
    finished = true;
    stage.classList.add('is-parted');
    try { W.sessionStorage.setItem('vp.premiere', '1'); } catch (e) { /* private mode */ }
  };
  const glOn = () => stage.getAttribute('data-fx-gl') === 'on';
  const refused = () => {
    if (ROOT.getAttribute('data-fx-webgl') === 'off') return true;
    const st = W.FX && W.FX.status ? W.FX.status() : [];
    for (let i = st.length - 1; i >= 0; i--) if (st[i].name === 'shader') return st[i].state === 'static' || st[i].state === 'error';
    return false;
  };
  const watch = (deadline) => {
    if (finished || glOn()) return;
    if (refused() || performance.now() > deadline) { part(); return; }
    setTimeout(() => watch(deadline), 250);
  };
  const begin = () => watch(performance.now() + 5000);
  if (W.FX && W.FX.loaded) W.FX.loaded.then(begin); else setTimeout(begin, 3500);
  new MutationObserver(() => { if (ROOT.getAttribute('data-fx-webgl') === 'off' && !stage.classList.contains('is-parted')) part(); }).observe(ROOT, { attributes: true, attributeFilter: ['data-fx-webgl'] });
}

/* ---------- V3 rose petals when a title is added to favourites ---------- */
let confettiP = null;
function petals(button) {
  if (REDUCED) return;
  if (!confettiP) confettiP = lib('canvas-confetti@1.9.4/confetti.esm.min.js');
  confettiP.then((mod) => {
    const confetti = mod.default || mod;
    const r = button.getBoundingClientRect();
    let shape = null;
    try { shape = confetti.shapeFromPath ? confetti.shapeFromPath({ path: 'M0 -10C6 -7 7.5 3 0 10C-7.5 3 -6 -7 0 -10Z' }) : null; } catch (e) { shape = null; }
    confetti({
      particleCount: LOW ? 12 : 24, spread: 70, startVelocity: 22, gravity: 0.6, drift: (Math.random() * 0.8) - 0.4, ticks: 160, scalar: 1.1,
      colors: ['#ff9db5', '#f4b6a0', '#fff1d6'], shapes: shape ? [shape, 'circle'] : ['circle'],
      origin: { x: (r.left + r.width / 2) / W.innerWidth, y: (r.top + r.height / 2) / W.innerHeight }, disableForReducedMotion: true, zIndex: 2147483000
    });
  }).catch(() => {});
}
function initPetals() {
  D.addEventListener('vp:fav', (e) => { if (e.target && e.target.getBoundingClientRect) petals(e.target); });
}

/* ---------- reviews: the list animates when it changes (AutoAnimate) ---------- */
function initReviews() {
  const box = D.querySelector('.vp-reviews .mac_comment');
  if (!box || REDUCED) return;
  const go = () => lib('auto-animate@0.10.0/auto-animate.esm.min.js').then((m) => { (m.default || m.autoAnimate)(box, { duration: 260, easing: 'cubic-bezier(.65,0,.35,1)' }); }).catch(() => {});
  if ('IntersectionObserver' in W) {
    const io = new IntersectionObserver((entries) => { if (entries[0].isIntersecting) { io.disconnect(); idle(go); } }, { rootMargin: '400px 0px' });
    io.observe(box);
  } else idle(go);
}

initTabs();
initSpotlight();
initParting();
initPetals();
initReviews();
