/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Honey Nectar (Modern) effects module | original code (JavCMS Community License 1.0) for JavCMS
 * - NC.celebrate(button): hexagon confetti (canvas-confetti, ISC) + a honey drip on the favourite button
 * - NC.bloom(el, open): spring bloom of the phone honeycomb menu (motion-mini, MIT; CSS springs without it)
 * - loads the fanned card stack (nectar-stack.js, Swiper) on the home page only
 * Everything is skipped under prefers-reduced-motion; low-power devices get fewer particles. */
const D = document, W = window, ROOT = D.documentElement;
const V = '?v=20261012c';
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mq = (q) => { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
const reduced = mq('(prefers-reduced-motion: reduce)') || !ROOT.classList.contains('nc-anim');
const low = ROOT.classList.contains('nc-low');

let confettiP = null;
function loadConfetti() {
  if (!confettiP) confettiP = import(VENDOR + 'canvas-confetti@1.9.4/confetti.esm.min.js').then((m) => m.default).catch(() => null);
  return confettiP;
}
let hexShape = null;
function celebrate(btn) {
  if (!btn) return;
  btn.classList.remove('is-dripping'); void btn.offsetWidth; btn.classList.add('is-dripping');
  setTimeout(() => btn.classList.remove('is-dripping'), 1800);
  if (reduced) return;
  loadConfetti().then((confetti) => {
    if (!confetti) return;
    const r = btn.getBoundingClientRect();
    if (!hexShape && confetti.shapeFromPath) {
      try { hexShape = confetti.shapeFromPath({ path: 'M10 0L18.66 5V15L10 20L1.34 15V5Z' }); } catch (e) { hexShape = null; }
    }
    confetti({
      particleCount: low ? 12 : 30, spread: 74, startVelocity: low ? 22 : 30, gravity: 0.9, decay: 0.92, scalar: hexShape ? 1.05 : 0.85, ticks: 140,
      colors: ['#ffb21e', '#ffd36b', '#fff4df', '#e08a0a'], shapes: hexShape ? [hexShape, 'circle'] : ['circle'],
      origin: { x: (r.left + r.width / 2) / W.innerWidth, y: (r.top + r.height / 2) / W.innerHeight },
      disableForReducedMotion: true, zIndex: 120
    });
  });
}

let motionP = null;
function loadMotion() {
  if (!motionP) motionP = import(VENDOR + 'motion@13.4.4/motion-mini.min.js').catch(() => null);
  return motionP;
}
function bloom(el, open) {
  if (!el || !open || reduced) return;
  loadMotion().then((m) => {
    if (!m || !m.animate || !el.classList.contains('is-open')) return;
    const rtl = (ROOT.getAttribute('dir') || '') === 'rtl';
    Array.prototype.forEach.call(el.querySelectorAll('.nc-cell'), (c, i) => {
      const cs = getComputedStyle(c);
      const x = parseFloat(cs.getPropertyValue('--cx')) * (rtl ? -1 : 1) || 0, y = parseFloat(cs.getPropertyValue('--cy')) || 0;
      if (!x && !y && W.innerWidth < 360) return;
      try {
        const a = m.animate(c, { transform: ['translate(0px, 0px) scale(0.3)', 'translate(' + x + 'px, ' + y + 'px) scale(1)'], opacity: [0, 1] },
          { type: 'spring', stiffness: 120, damping: 16, mass: 0.9, delay: i * 0.032 });
        /* Motion commits the end state inline; hand control back to the stylesheet (closing, RTL, resize) */
        const clear = () => { c.style.removeProperty('transform'); c.style.removeProperty('opacity'); };
        if (a && a.finished && a.finished.then) a.finished.then(clear, clear); else setTimeout(clear, 1600);
      } catch (e) { /* CSS spring stays */ }
    });
  });
}

W.NC = Object.assign(W.NC || {}, { celebrate: celebrate, bloom: bloom });

/* fanned card stack: only the home page has one */
const stack = D.querySelector('[data-nc-stack]');
if (stack) {
  const start = () => import('./nectar-stack.js' + V).then((m) => m.init(stack, { reduced: reduced, low: low })).catch((e) => { if (W.console) console.warn('[nectar] stack:', e); });
  if (D.readyState === 'complete') start(); else W.addEventListener('load', start, { once: true });
}
