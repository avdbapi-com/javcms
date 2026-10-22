/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! Onyx Grid (Modern) - effects module | JavCMS theme | original code (JAVCMS), JavCMS Community License 1.0
 * Mechanical motion only (stepped / linear). Every effect is progressive enhancement and gated:
 *  - velocity marquees (O2): kit marquee playbackRate follows scroll speed + direction (fine pointer); fixed slow speed on touch
 *  - stepped odometers (O8), grid-line draw-in (O7), cursor image reveal (O5): anime.js lite, imported lazily
 *  - crosshair cursor with X/Y readout (O6): fine pointer, motion and power only
 * prefers-reduced-motion: none of them run. Save-Data / low memory / <= 4 cores: no crosshair, no cursor reveal. */
const D = document, W = window, ROOT = D.documentElement;
function mq(q) { try { return W.matchMedia(q); } catch (e) { return { matches: false }; } }
function store(k) { try { return W.localStorage.getItem(k); } catch (e) { return null; } }
const CFG = W.FX_CONFIG || {};
const reduced = CFG.reduced != null ? !!CFG.reduced : (store('fx.reduced') === '1' || mq('(prefers-reduced-motion: reduce)').matches);
const conn = navigator.connection || {};
const force = !!CFG.force || store('fx.force') === '1';
const lowPower = !force && (!!conn.saveData || mq('(prefers-reduced-data: reduce)').matches ||
  (navigator.deviceMemory > 0 && navigator.deviceMemory < 4) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 4) || store('fx.low') === '1');
const fine = CFG.fine != null ? !!CFG.fine : mq('(hover: hover) and (pointer: fine)').matches;
const rtl = ROOT.getAttribute('dir') === 'rtl';
const ANIME = new URL('../../../../static/vendor/fx/animejs@4.5.0/anime-lite.esm.min.js', import.meta.url).href;
let animeP = null;
function anime() { if (!animeP) animeP = import(ANIME).catch(function () { return null; }); return animeP; }
function idle(fn) { if (W.requestIdleCallback) W.requestIdleCallback(fn, { timeout: 1500 }); else setTimeout(fn, 120); }
function $$(s, c) { return Array.prototype.slice.call((c || D).querySelectorAll(s)); }
const fmt = (function () { let f = null; return function (n) { try { if (!f) f = new Intl.NumberFormat('en-US'); return f.format(n); } catch (e) { return String(n); } }; })();

/* O8 - stepped odometer counters (server text is the final value; pre-hidden only while waiting, 2.5 s CSS failsafe) */
function odometers() {
  const els = $$('[data-ox-odo]');
  if (!els.length) return;
  if (reduced || !('IntersectionObserver' in W)) { els.forEach(function (el) { el.setAttribute('data-ox-odo-done', ''); }); return; }
  const io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); run(e.target); } });
  }, { rootMargin: '0px 0px -5% 0px' });
  els.forEach(function (el) { io.observe(el); });
  function run(el) {
    const to = parseInt(el.getAttribute('data-ox-odo'), 10) || 0;
    const w = el.getBoundingClientRect().width;
    if (to < 10) { el.setAttribute('data-ox-odo-done', ''); return; }
    el.style.minWidth = Math.ceil(w) + 'px';
    anime().then(function (A) {
      el.setAttribute('data-ox-odo-done', '');
      if (!A || !A.animate) { el.textContent = fmt(to); return; }
      const o = { v: 0 };
      el.textContent = '0';
      A.animate(o, {
        v: to, duration: 960, ease: A.steps ? A.steps(16) : 'linear',
        onUpdate: function () { el.textContent = fmt(Math.round(o.v)); },
        onComplete: function () { el.textContent = fmt(to); el.style.minWidth = ''; }
      });
    });
  }
}

/* O7 - section rules draw in (scaleX in 5 steps) when they scroll into view; rules above the fold stay as they are */
function drawLines() {
  if (reduced || !('IntersectionObserver' in W)) return;
  const vh = W.innerHeight;
  const els = $$('.ox-sec-rule').filter(function (el) { return el.offsetParent !== null && el.getBoundingClientRect().top > vh * 0.92; });
  if (!els.length) return;
  els.forEach(function (el) { el.style.transform = 'scaleX(0)'; });
  const io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      const el = e.target;
      anime().then(function (A) {
        if (!A || !A.animate) { el.style.transform = ''; return; }
        A.animate(el, { scaleX: [0, 1], duration: 440, ease: A.steps ? A.steps(5) : 'linear', onComplete: function () { el.style.transform = ''; } });
      });
    });
  }, { rootMargin: '0px 0px -10% 0px' });
  els.forEach(function (el) { io.observe(el); });
  setTimeout(function () { els.forEach(function (el) { if (el.style.transform === 'scaleX(0)' && el.getBoundingClientRect().top < W.innerHeight) el.style.transform = ''; }); }, 4000);
}

/* O2 - marquee speed follows scroll velocity and direction (no restarts: WAAPI playbackRate on the kit's CSS animation) */
function velocityBands() {
  const bands = $$('[data-ox-velocity]');
  if (!bands.length || reduced) return;
  let tries = 0;
  // Cache the track animation: a reversed band (negative playbackRate) reaches time 0, "finishes" and drops out
  // of getAnimations(); pushing it far into its infinite timeline first keeps it alive in both directions.
  function animOf(t) {
    if (t.__oxA) return t.__oxA;
    const a = t.getAnimations ? t.getAnimations() : [];
    if (!a.length) return null;
    const an = a[0];
    try { const d = an.effect.getComputedTiming().duration; if (d > 0) an.currentTime = (an.currentTime || 0) + d * 2000; } catch (e) {}
    t.__oxA = an;
    return an;
  }
  function tracks() { return bands.map(function (b) { return b.querySelector('.fx-mq-track'); }).filter(Boolean); }
  function ready() {
    const list = tracks();
    if ((list.length < bands.length || !list.every(animOf)) && tries++ < 40) { setTimeout(ready, 150); return; }
    if (!list.length) return;
    if (!fine) { list.forEach(function (t) { const a = animOf(t); if (a) a.playbackRate = 0.6; }); return; }
    let lastY = W.scrollY, lastT = performance.now(), rate = 1, target = 1, dir = 1, raf = 0, rest = 0;
    function apply() { list.forEach(function (t) { const a = animOf(t); if (a && a.playbackRate !== rate) a.playbackRate = rate; }); }
    function tick() {
      raf = 0;
      rate += (target - rate) * 0.2;
      if (Math.abs(target - rate) < 0.02) rate = target;
      apply();
      if (rate !== target) raf = requestAnimationFrame(tick);
    }
    W.addEventListener('scroll', function () {
      const now = performance.now(), y = W.scrollY, dt = Math.max(16, now - lastT), v = (y - lastY) / dt;
      lastY = y; lastT = now;
      if (Math.abs(v) > 0.02) dir = v > 0 ? 1 : -1;
      target = dir * Math.min(7, 1 + Math.abs(v) * 2.6);
      if (!raf) raf = requestAnimationFrame(tick);
      clearTimeout(rest);
      rest = setTimeout(function () { target = dir; if (!raf) raf = requestAnimationFrame(tick); }, 180);
    }, { passive: true });
  }
  ready();
}

/* O5 - cursor image reveal on the category table (desktop): the newest poster of the hovered category follows the pointer */
function cursorReveal() {
  if (!fine || reduced || lowPower) return;
  const zone = D.querySelector('[data-ox-reveal-zone]');
  const box = zone && zone.querySelector('[data-ox-reveal]');
  const img = box && box.querySelector('img');
  if (!img) return;
  anime().then(function (A) {
    const follow = A && A.createAnimatable ? A.createAnimatable(box, { x: 420, y: 420, ease: 'out(3)' }) : null;
    let shown = false;
    function place(x, y, now) {
      if (follow) { follow.x(x, now ? 0 : undefined); follow.y(y, now ? 0 : undefined); }
      else box.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    }
    function hide() { if (shown) { shown = false; box.classList.remove('is-on'); } }
    zone.addEventListener('pointermove', function (e) {
      if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
      const row = e.target && e.target.closest ? e.target.closest('[data-ox-pic]') : null;
      if (!row) { hide(); return; }
      const pic = row.getAttribute('data-ox-pic');
      if (img.getAttribute('data-cur') !== pic) { img.classList.remove('is-broken'); box.classList.remove('is-broken'); img.setAttribute('data-cur', pic); img.src = pic; }
      const x = rtl ? e.clientX - 264 : e.clientX + 24, y = e.clientY - 150;
      place(x, y, !shown);
      if (!shown) { shown = true; box.classList.add('is-on'); }
    }, { passive: true });
    zone.addEventListener('pointerleave', hide);
    W.addEventListener('scroll', hide, { passive: true });
  });
}

/* O6 - crosshair cursor: two hairlines spanning the viewport + an X/Y mono readout; the real cursor stays */
function crosshair() {
  if (!fine || reduced || lowPower) return;
  function mk(cls) { const n = D.createElement('div'); n.className = cls; n.setAttribute('aria-hidden', 'true'); D.body.appendChild(n); return n; }
  const h = mk('ox-xh ox-xh-h'), v = mk('ox-xh ox-xh-v'), r = mk('ox-xh-r');
  let x = -20, y = -20, raf = 0, off = true;
  ROOT.classList.add('ox-xh-on', 'ox-xh-off');
  function pad(n) { n = Math.max(0, Math.round(n)); return ('000' + n).slice(-4); }
  function draw() {
    raf = 0;
    h.style.transform = 'translate3d(0,' + y + 'px,0)';
    v.style.transform = 'translate3d(' + x + 'px,0,0)';
    const rx = x + 140 > W.innerWidth ? x - 132 : x + 12;
    const ry = y + 34 > W.innerHeight ? y - 26 : y + 12;
    r.style.transform = 'translate3d(' + rx + 'px,' + ry + 'px,0)';
    r.textContent = 'X ' + pad(x) + ' / Y ' + pad(y);
  }
  function setOff(state) { if (state !== off) { off = state; ROOT.classList.toggle('ox-xh-off', off); } }
  D.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    x = e.clientX; y = e.clientY;
    const t = e.target;
    setOff(!!(t && t.closest && t.closest('iframe,input,textarea,select,[contenteditable],.ox-player-box,.ox-index,.tui-overlay')));
    if (!raf) raf = requestAnimationFrame(draw);
  }, { passive: true });
  D.addEventListener('pointerout', function (e) { if (!e.relatedTarget || e.relatedTarget.tagName === 'IFRAME') setOff(true); }, { passive: true });
  W.addEventListener('blur', function () { setOff(true); });
}

/* Card / table entrance (kit stagger, stepped wipe): armed on the first scroll, only for groups still below the fold,
   so nothing that is (or was) on screen is ever hidden and un-scrolled captures show the full page.
   At most one group per animation frame: its position is read while layout is still clean (start of the frame), then
   it is armed, so a throttled phone never pays several forced style recalcs in one scroll task. */
function lazyStagger() {
  if (reduced) return;
  const groups = $$('[data-ox-stagger]');
  if (!groups.length) return;
  let k = 0;
  function step() {
    while (k < groups.length) {
      const g = groups[k++];
      if (g.hasAttribute('data-fx') || g.getBoundingClientRect().top < W.innerHeight + 160) continue;
      g.setAttribute('data-fx', 'stagger');
      if (W.FX && W.FX.scan) W.FX.scan(g);
      break;
    }
    if (k < groups.length) W.requestAnimationFrame(step);
  }
  function arm() {
    W.removeEventListener('scroll', arm);
    W.requestAnimationFrame(step);
  }
  W.addEventListener('scroll', arm, { passive: true });
  D.addEventListener('focusin', function (e) {
    const g = e.target && e.target.closest ? e.target.closest('[data-fx~="stagger"]') : null;
    if (g) Array.prototype.forEach.call(g.children, function (c) { if (c.getAttribute('data-fx-in') !== 'done') c.setAttribute('data-fx-in', 'done'); });
  });
}

odometers();
lazyStagger();
function later() { velocityBands(); drawLines(); cursorReveal(); crosshair(); }
if (D.readyState === 'complete') idle(later); else W.addEventListener('load', function () { idle(later); }, { once: true });
