/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! Crimson Ink (Modern) - motion layer (ES module) | JavCMS theme | original code (JavCMS Community License 1.0)
 * C3 panel slam: Motion 13.4.4 springs through inView (cards stagger 35 ms; phones: half distance; reduced motion: fade only).
 * C8 character-select rail: Embla 8.6 with a scroll-progress parallax of the inner lettering (off under reduced motion).
 * Libraries are imported lazily from /static/vendor/fx/ (relative to this module, so sub-directory installs work). */
const D = document, W = window, R = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;
const mm = (q) => { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
const reduced = mm('(prefers-reduced-motion: reduce)');
const coarse = mm('(pointer: coarse)');
const rtl = R.getAttribute('dir') === 'rtl';

function ready(fn) { if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', fn, { once: true }); else fn(); }
function afterPaint(fn) { W.requestAnimationFrame(() => W.requestAnimationFrame(() => W.setTimeout(fn, 0))); }
function clean(el) { el.style.removeProperty('transform'); el.style.removeProperty('opacity'); el.setAttribute('data-slam', 'done'); }

/* ---------------------------------------------------------------- C3 panel slam */
/* Nothing is ever pre-hidden: cards stay at rest until the reader actually scrolls. After the first scroll a card is
   "armed" (hidden) only while it sits just outside the viewport, then slams in (Motion spring) as it enters; a card that
   is already visible when first seen, or that leaves the arming zone again, stays/returns at rest. Full-page captures
   and sessions without scrolling therefore always show every card. The hero panels get a transform-only slam on load. */
function slam() {
  const all = Array.prototype.slice.call(D.querySelectorAll('[data-slam]'));
  if (!all.length) return;
  const k = coarse || W.innerWidth < 768 ? 0.5 : 1;
  const dx = (rtl ? 26 : -26) * k, dy = 34 * k, sk = rtl ? 7 : -7, rot = rtl ? 1.4 : -1.4;
  const FROM = 'translate(' + dx + 'px, ' + dy + 'px) skewX(' + sk + 'deg) rotate(' + rot + 'deg) scale(1.04)';
  /* explicit identity with the same function list: Motion turns a 'none' target into an all-zero list (scale(0)),
     and the last card of a staggered batch can commit that frame after `finished` resolves */
  const TO = 'translate(0px, 0px) skewX(0deg) rotate(0deg) scale(1)';
  if (!reduced) {
    const vh = W.innerHeight;
    let n = 0;
    all.forEach((el) => {
      if (el.getAttribute('data-slam') !== 'hero' || !el.animate) return;
      const r = el.getBoundingClientRect();
      if (r.top < vh && r.bottom > 0) el.animate([{ transform: 'translate(' + ((rtl ? 18 : -18) * k) + 'px, ' + (14 * k) + 'px) skewX(' + (sk * 0.8) + 'deg)' }, { transform: 'none' }], { duration: 380, delay: 80 * n++, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    });
  }
  const cards = all.filter((el) => el.getAttribute('data-slam') !== 'hero');
  if (!cards.length || !('IntersectionObserver' in W)) return;
  const vis = (el) => { const r = el.getBoundingClientRect(); return r.bottom > 0 && r.top < W.innerHeight; };
  const start = async () => {
    let animate = null, stagger = null;
    if (!reduced) {
      try { const Mo = await import(VENDOR + 'motion@13.4.4/motion.min.js'); animate = Mo.animate; stagger = Mo.stagger; } catch (e) { return; }
    }
    let queue = [], raf = 0;
    const flush = () => {
      raf = 0;
      const batch = queue; queue = [];
      batch.forEach((el) => el.setAttribute('data-slam', 'run'));
      let finished = false;
      const done = () => { if (finished) return; finished = true; batch.forEach(clean); W.requestAnimationFrame(() => W.requestAnimationFrame(() => batch.forEach(clean))); };
      W.setTimeout(done, 450 + batch.length * 35 + 900);
      try {
        if (animate) {
          const ctl = animate(batch, { opacity: [0, 1], transform: [FROM, TO] },{ type: 'spring', bounce: 0.35, visualDuration: 0.22, delay: stagger(0.035) });
          if (ctl && ctl.finished && ctl.finished.then) ctl.finished.then(done, done); else if (ctl && typeof ctl.then === 'function') ctl.then(done, done);
        } else {
          let left = batch.length;
          batch.forEach((el, i) => { const a = el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 220, delay: i * 25, easing: 'linear', fill: 'backwards' }); a.onfinish = () => { if (--left <= 0) done(); }; });
        }
      } catch (e) { done(); }
    };
    const fire = new IntersectionObserver((ents) => {
      ents.forEach((en) => {
        const el = en.target;
        if (!en.isIntersecting || el.getAttribute('data-slam') !== 'armed') return;
        fire.unobserve(el); queue.push(el);
        if (!raf) raf = W.requestAnimationFrame(flush);
      });
    }, { rootMargin: '0px 0px -4% 0px', threshold: 0.08 });
    const arm = new IntersectionObserver((ents) => {
      ents.forEach((en) => {
        const el = en.target, st = el.getAttribute('data-slam') || '';
        if (en.isIntersecting) {
          if (st !== '') return;
          /* the observer's own rect (no getBoundingClientRect): REVIEW-icons-crimson-modern-r4 P1-1 */
          const br = en.boundingClientRect;
          if (br.height > 0 && br.bottom > 0 && br.top < W.innerHeight) { el.setAttribute('data-slam', 'done'); arm.unobserve(el); return; }
          el.setAttribute('data-slam', 'armed'); fire.observe(el);
        } else if (st === 'armed') { el.setAttribute('data-slam', ''); fire.unobserve(el); }
        else if (st === 'done') arm.unobserve(el);
      });
    }, { rootMargin: '30% 0px 30% 0px', threshold: 0 });
    /* no layout reads on the first scroll: measuring all cards here forced every content-visibility chapter to render in one
       93-234 ms task on a throttled phone (r4 P1-1); the arm observer sorts visible / off-screen cards itself */
    cards.forEach((el) => arm.observe(el));
    W.addEventListener('beforeprint', () => cards.forEach(clean));
  };
  W.addEventListener('scroll', function once() { W.removeEventListener('scroll', once); start(); }, { passive: true });
}

/* ---------------------------------------------------------------- C8 character-select rail */
async function rail() {
  const box = D.querySelector('[data-ci-rail]');
  if (!box) return;
  const vp = box.querySelector('.ci-rail-vp');
  const prev = box.querySelector('[data-ci-prev]'), next = box.querySelector('[data-ci-next]');
  const inners = Array.prototype.slice.call(box.querySelectorAll('[data-ci-par]'));
  if (!vp) return;
  const sync = (canPrev, canNext) => {
    if (prev) { prev.hidden = false; prev.setAttribute('aria-disabled', canPrev ? 'false' : 'true'); }
    if (next) { next.hidden = false; next.setAttribute('aria-disabled', canNext ? 'false' : 'true'); }
  };
  /* keyboard arrows (focus is on a card link inside the rail); RTL swaps the keys */
  const keys = (go) => vp.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' || e.altKey || e.ctrlKey || e.metaKey) return;
    e.preventDefault(); go((e.key === 'ArrowRight') !== rtl);
  });
  const native = () => {
    const step = (dir) => vp.scrollBy({ left: dir * vp.clientWidth * 0.8 * (rtl ? -1 : 1), behavior: reduced ? 'auto' : 'smooth' });
    const upd = () => { const max = vp.scrollWidth - vp.clientWidth - 2, x = Math.abs(vp.scrollLeft); sync(x > 2, x < max); };
    if (prev) prev.addEventListener('click', () => { if (prev.getAttribute('aria-disabled') !== 'true') step(-1); });
    if (next) next.addEventListener('click', () => { if (next.getAttribute('aria-disabled') !== 'true') step(1); });
    vp.addEventListener('scroll', upd, { passive: true }); upd();
    keys((fwd) => step(fwd ? 1 : -1));
  };
  if (reduced) { native(); return; }
  let Embla;
  try { Embla = (await import(VENDOR + 'embla-carousel@8.6.0/embla-carousel.esm.min.js')).default; } catch (e) { native(); return; }
  box.classList.add('is-embla');
  const api = Embla(vp, { align: 'start', containScroll: 'trimSnaps', dragFree: true, direction: rtl ? 'rtl' : 'ltr', duration: 26 });
  const upd = () => sync(api.canScrollPrev(), api.canScrollNext());
  if (prev) prev.addEventListener('click', () => api.scrollPrev());
  if (next) next.addEventListener('click', () => api.scrollNext());
  keys((fwd) => { if (fwd) api.scrollNext(); else api.scrollPrev(); });
  api.on('select', upd).on('reInit', upd).on('init', upd); upd();
  /* parallax: the lettering lags behind the card as the rail moves (transform only, rAF-batched). Each card's shift is
     clamped to the free room its lettering has inside the card (measured at rest, again on resize / font load), so long
     or multi-line names (skewed, any locale) never get their first or last letters cut off at the card edge. */
  const PAR = 26, EDGE = 3 + 2; /* card border + safety */
  let pr = 0, room = [];
  const measure = () => {
    room = inners.map((el) => {
      const card = el.parentNode.getBoundingClientRect(), cur = parseFloat(el.style.getPropertyValue('--par')) || 0;
      let l = Infinity, r = -Infinity;
      el.querySelectorAll('.ci-char-name > span, .ci-char-n').forEach((c) => {
        const rg = D.createRange(); rg.selectNodeContents(c);
        const b = c.classList.contains('ci-char-n') ? c.getBoundingClientRect() : rg.getBoundingClientRect();
        if (b.width) { l = Math.min(l, b.left - cur); r = Math.max(r, b.right - cur); }
      });
      return l === Infinity ? [0, 0] : [Math.max(0, l - card.left - EDGE), Math.max(0, card.right - EDGE - r)];
    });
  };
  const par = () => {
    pr = 0;
    const p = api.scrollProgress(), n = Math.max(1, inners.length - 1);
    for (let i = 0; i < inners.length; i++) {
      const d = Math.max(-1, Math.min(1, i / n - p)), rm = room[i] || [0, 0];
      const v = Math.max(-rm[0], Math.min(rm[1], d * (rtl ? PAR : -PAR)));
      inners[i].style.setProperty('--par', v.toFixed(1) + 'px');
    }
  };
  const remeasure = () => { measure(); par(); };
  api.on('scroll', () => { if (!pr) pr = W.requestAnimationFrame(par); }).on('reInit', remeasure);
  remeasure();
  if (D.fonts && D.fonts.ready) D.fonts.ready.then(remeasure);
}

ready(() => afterPaint(() => { slam(); rail(); }));
