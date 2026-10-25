/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Ember Thermal (Modern) - effects module | original code (JavCMS Community License 1.0, JavCMS project)
 * E3 kinetic heat type (Splitting 1.1, MIT): section titles expand from wdth 75 / wght 500 to wdth 100 / wght 800
 *    when they reveal; per-letter stagger on fine pointers, whole-word on touch; never per-letter for ja/zh/ko/th/ar/hi/ru.
 * E5 heat haze (Motion 13.4.4, MIT; imported on fine-pointer devices only, on the first hover): SVG feTurbulence
 *    displacement on the hero / detail cover while hovered.
 * E7 spark burst (canvas-confetti 1.9.4, ISC): rising sparks in ramp colours when a favourite is added.
 * Detail heat gauge fill, category H1 width expansion. Everything respects reduced motion and low power. */
const D = document, W = window, ROOT = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mq = (q) => { try { return W.matchMedia(q); } catch (e) { return { matches: false }; } };
const REDUCED = mq('(prefers-reduced-motion: reduce)').matches;
const FINE = mq('(hover: hover) and (pointer: fine)').matches;
const conn = navigator.connection || {};
const LOW = !!conn.saveData || mq('(prefers-reduced-data: reduce)').matches || (navigator.deviceMemory > 0 && navigator.deviceMemory < 4) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 4);
const LANG = (ROOT.getAttribute('lang') || 'en').toLowerCase().slice(0, 2);
const NO_LETTERS = ['ja', 'zh', 'ko', 'th', 'ar', 'hi', 'ru'].indexOf(LANG) >= 0;
const idle = (fn, t) => (W.requestIdleCallback ? W.requestIdleCallback(fn, { timeout: t || 1500 }) : setTimeout(fn, 80));
ROOT.classList.add('t-live');

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = D.createElement('script');
    s.src = src; s.async = true; s.onload = resolve; s.onerror = reject;
    D.head.appendChild(s);
  });
}

/* ---------------------------------------------------------------- E3 kinetic heat type */
let splitReady = null;
function splitting() {
  if (!splitReady) splitReady = W.Splitting ? Promise.resolve(W.Splitting) : loadScript(VENDOR + 'splitting@1.1.0/splitting-lite.min.js').then(() => W.Splitting);
  return splitReady;
}
function heat(el) { el.classList.add('is-hot'); }
function prepTitle(el) {
  const inner = el.firstElementChild;
  if (!inner || el.dataset.tKinDone) return Promise.resolve();
  el.dataset.tKinDone = '1';
  if (!FINE || REDUCED || NO_LETTERS || LOW) return Promise.resolve();
  return splitting().then((Split) => {
    if (!Split) return;
    const text = inner.textContent;
    const sr = D.createElement('span'); sr.className = 'sr-only'; sr.textContent = text;
    inner.setAttribute('aria-hidden', 'true');
    el.insertBefore(sr, inner);
    Split({ target: inner, by: 'chars' });
  }).catch(() => {});
}
/* phones: section titles may wrap (long languages), so the condensed start state is applied only while a title is still
   below the viewport and only when it keeps the title's height; a title that would gain a line when it heats up stays in
   its final state (zero layout shift) */
function coolSafely(el) {
  if (el.getBoundingClientRect().top < W.innerHeight) return;
  el.classList.add('t-kin-measure');
  const h = el.offsetHeight;
  el.classList.add('t-kin-cold');
  if (el.offsetHeight !== h) el.classList.remove('t-kin-cold');
  void el.offsetHeight;
  el.classList.remove('t-kin-measure');
}
function initKinetic() {
  const titles = Array.prototype.slice.call(D.querySelectorAll('[data-t-kin]'));
  if (!titles.length) return;
  if (REDUCED || !('IntersectionObserver' in W)) { titles.forEach(heat); return; }
  if (mq('(max-width: 767px)').matches) {
    const near = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { near.unobserve(en.target); coolSafely(en.target); } }), { rootMargin: '0px 0px 100% 0px' });
    titles.forEach((t) => near.observe(t));
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (!en.isIntersecting) return;
      io.unobserve(en.target);
      const el = en.target;
      prepTitle(el).then(() => requestAnimationFrame(() => requestAnimationFrame(() => heat(el))));
    });
  }, { rootMargin: '0px 0px -12% 0px', threshold: 0.2 });
  titles.forEach((t) => io.observe(t));
}
function initExpandH1() {
  const els = D.querySelectorAll('[data-t-expand]');
  if (!els.length) return;
  requestAnimationFrame(() => requestAnimationFrame(() => els.forEach(heat)));
}

/* ---------------------------------------------------------------- E5 heat haze (fine pointer only) */
let motionP = null;
function initHaze() {
  if (!FINE || REDUCED || LOW) return;
  const turb = D.querySelector('#t-haze feTurbulence');
  const screens = D.querySelectorAll('[data-t-haze]');
  if (!turb || !screens.length) return;
  let anim = null, active = 0;
  function start(sc) {
    active++;
    sc.classList.add('is-haze');
    if (!motionP) motionP = import(VENDOR + 'motion@13.4.4/motion.min.js');
    motionP.then((M) => {
      if (!sc.classList.contains('is-haze')) return;
      if (anim) anim.stop();
      anim = M.animate(0, 1, {
        duration: 2.4, repeat: Infinity, repeatType: 'mirror', ease: 'easeInOut',
        onUpdate: (v) => { turb.setAttribute('baseFrequency', (0.010 + v * 0.006).toFixed(4) + ' ' + (0.032 + v * 0.02).toFixed(4)); }
      });
    }).catch(() => sc.classList.remove('is-haze'));
  }
  function stop(sc) {
    sc.classList.remove('is-haze');
    if (--active <= 0) { active = 0; if (anim) { anim.stop(); anim = null; } }
  }
  screens.forEach((sc) => {
    sc.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') start(sc); });
    sc.addEventListener('pointerleave', () => stop(sc));
  });
}

/* ---------------------------------------------------------------- E7 spark burst (favourite added) */
let confettiP = null;
function burst(el) {
  if (REDUCED || !el) return;
  if (!confettiP) confettiP = import(VENDOR + 'canvas-confetti@1.9.4/confetti.esm.min.js');
  confettiP.then((m) => {
    const confetti = m.default;
    const r = el.getBoundingClientRect();
    const n = LOW ? 12 : (FINE ? 28 : 20);
    confetti({
      particleCount: n, spread: 58, startVelocity: 22, gravity: -0.35, decay: 0.92, drift: 0, ticks: 110, scalar: 0.7, shapes: ['circle'],
      colors: ['#fff3c4', '#ffc531', '#ff7a12', '#ee3b3b', '#ff4f9e'], angle: 90,
      origin: { x: (r.left + r.width / 2) / W.innerWidth, y: (r.top + r.height / 2) / W.innerHeight },
      disableForReducedMotion: true, zIndex: 2147482000
    });
  }).catch(() => {});
}
D.addEventListener('t:fav', (e) => burst(e.detail && e.detail.el));

/* ---------------------------------------------------------------- detail heat gauge */
function initGauge() {
  const g = D.querySelectorAll('[data-t-gauge]');
  if (!g.length) return;
  const set = (el) => el.classList.add('is-on');
  if (REDUCED || !('IntersectionObserver' in W)) { g.forEach(set); return; }
  const io = new IntersectionObserver((en) => en.forEach((x) => { if (x.isIntersecting) { io.unobserve(x.target); set(x.target); } }), { threshold: 0.3 });
  g.forEach((el) => io.observe(el));
}

/* kinetic rotating word: once the kit's rotate has built its word stack, the gradient moves from the host to the words */
function initRotateMark() {
  const host = D.querySelector('.t-kw-g');
  if (!host) return;
  const mark = () => { if (host.querySelector('.fx-rot')) { host.classList.add('is-rot'); return true; } return false; };
  if (mark()) return;
  const mo = new MutationObserver(() => { if (mark()) mo.disconnect(); });
  mo.observe(host, { childList: true });
  setTimeout(() => mo.disconnect(), 10000);
}

initRotateMark();
initExpandH1();
initKinetic();
initGauge();
idle(initHaze, 2500);
