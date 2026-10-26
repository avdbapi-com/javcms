/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - micro-interactions | JavCMS Community License 1.0 | original code
 * burst  : data-fx="burst" on a favourite/like button. With aria-pressed it bursts only when it becomes "true"
 *          (the theme toggles the attribute, also after an AJAX call); without aria-pressed on every click;
 *          data-fx-burst="manual" = only FX.burst(el). data-fx-count="14". Colours: --fx-burst-1/2/3 (default accents).
 * copy   : data-fx="copy" data-fx-copy="text" | data-fx-copy-target="#input" (default: page URL)
 *          data-fx-copied="Copied!" (translated label) [data-fx-copy-label] child is swapped; .fx-copy-idle/.fx-copy-ok
 *          children toggle; data-fx-state="copied|failed" for 1.8 s; the label is announced politely to screen readers.
 * ripple : data-fx="ripple" ink ripple from the press point (keyboard: from the centre). --fx-ripple-color */
const D = document;
let layer = null;

function getLayer() {
  if (!layer || !layer.isConnected) {
    layer = D.createElement('div');
    layer.className = 'fx-layer';
    layer.setAttribute('aria-hidden', 'true');
    D.body.appendChild(layer);
  }
  return layer;
}
function colorsOf(el, ctx) {
  return [
    ctx.css(el, '--fx-burst-1', ctx.css(el, '--fx-accent', '#ff3d81')),
    ctx.css(el, '--fx-burst-2', ctx.css(el, '--fx-accent-2', '#7c5cff')),
    ctx.css(el, '--fx-burst-3', ctx.css(el, '--fx-accent-3', '#22d3ee'))
  ];
}

export function burst(el, opts, ctx) {
  if (!el || ctx.env.reduced || !el.animate) return false;
  opts = opts || {};
  const r = el.getBoundingClientRect();
  const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
  const cols = colorsOf(el, ctx);
  const n = opts.count || ctx.num(el, 'count', 14);
  const L = getLayer();
  const ease = 'cubic-bezier(.16,1,.3,1)';
  const ring = D.createElement('i');
  ring.className = 'fx-bring';
  ring.style.setProperty('--c', cols[0]);
  L.appendChild(ring);
  const grow = Math.max(r.width, r.height) * 0.22 + 2;
  ring.animate([
    { transform: 'translate(' + cx + 'px,' + cy + 'px) scale(.2)', opacity: 0.9 },
    { transform: 'translate(' + cx + 'px,' + cy + 'px) scale(' + grow.toFixed(2) + ')', opacity: 0 }
  ], { duration: 560, easing: ease }).onfinish = function () { ring.remove(); };
  for (let i = 0; i < n; i++) {
    const p = D.createElement('i');
    const spark = i % 3 === 2;
    p.className = 'fx-bp' + (spark ? ' is-spark' : '');
    p.style.setProperty('--c', cols[i % 3]);
    p.style.setProperty('--s', (5 + Math.random() * 5).toFixed(1) + 'px');
    L.appendChild(p);
    const a = (i / n) * Math.PI * 2 + (Math.random() - 0.5) * 0.5;
    const dist = Math.max(r.width, r.height) * 0.5 + 16 + Math.random() * 30;
    const dx = Math.cos(a) * dist, dy = Math.sin(a) * dist;
    const rot = spark ? ' rotate(' + (a + Math.PI / 2).toFixed(3) + 'rad)' : '';
    p.animate([
      { transform: 'translate(' + cx + 'px,' + cy + 'px)' + rot + ' scale(.3)', opacity: 1 },
      { transform: 'translate(' + (cx + dx).toFixed(1) + 'px,' + (cy + dy).toFixed(1) + 'px)' + rot + ' scale(1)', opacity: 1, offset: 0.55 },
      { transform: 'translate(' + (cx + dx * 1.18).toFixed(1) + 'px,' + (cy + dy * 1.18 + 10).toFixed(1) + 'px)' + rot + ' scale(0)', opacity: 0 }
    ], { duration: 640 + Math.random() * 260, easing: ease }).onfinish = function () { p.remove(); };
  }
  try { el.animate([{ scale: '1' }, { scale: '1.28' }, { scale: '.92' }, { scale: '1' }], { duration: 520, easing: 'ease-out' }); } catch (e) { /* older engines: no pop */ }
  return true;
}

export function ripple(el, e, ctx) {
  if (ctx.env.reduced || !el.animate) return;
  const r = el.getBoundingClientRect();
  const fromPointer = e && e.clientX != null && (e.clientX || e.clientY) && e.detail !== 0;
  const x = fromPointer ? e.clientX - r.left : r.width / 2, y = fromPointer ? e.clientY - r.top : r.height / 2;
  const size = Math.ceil(Math.hypot(Math.max(x, r.width - x), Math.max(y, r.height - y)) * 2);
  const s = D.createElement('span');
  s.className = 'fx-ripple';
  s.setAttribute('aria-hidden', 'true');
  s.style.width = s.style.height = size + 'px';
  s.style.left = (x - size / 2) + 'px';
  s.style.top = (y - size / 2) + 'px';
  el.appendChild(s);
  s.animate([{ transform: 'scale(0)', opacity: 0.32 }, { transform: 'scale(1)', opacity: 0 }], { duration: 700, easing: 'cubic-bezier(.16,1,.3,1)' }).onfinish = function () { s.remove(); };
}

function legacyCopy(text) {
  const ta = D.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none';
  D.body.appendChild(ta);
  ta.select();
  let ok = false;
  try { ok = D.execCommand('copy'); } catch (e) { ok = false; }
  ta.remove();
  return ok;
}

function setupCopy(el, ctx) {
  el.addEventListener('click', function (e) {
    const sel = el.getAttribute('data-fx-copy-target');
    let text = el.getAttribute('data-fx-copy');
    if (text == null && sel) {
      let t = null;
      try { t = D.querySelector(sel); } catch (err) { t = null; }
      if (t) text = t.value != null ? t.value : t.textContent;
    }
    if (text == null) text = location.href;
    ripple(el, e, ctx);
    const finish = function (ok) {
      el.setAttribute('data-fx-state', ok ? 'copied' : 'failed');
      const label = el.querySelector('[data-fx-copy-label]');
      const msg = ok ? (el.getAttribute('data-fx-copied') || 'Copied') : (el.getAttribute('data-fx-copy-failed') || 'Copy failed');
      if (label) { if (label.__fxOrig == null) label.__fxOrig = label.textContent; label.textContent = msg; }
      ctx.announce(msg);
      clearTimeout(el.__fxT);
      el.__fxT = setTimeout(function () {
        el.removeAttribute('data-fx-state');
        if (label && label.__fxOrig != null) label.textContent = label.__fxOrig;
      }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { finish(true); }, function () { finish(legacyCopy(text)); });
    } else finish(legacyCopy(text));
  });
}

function setupBurst(el, ctx) {
  const mode = ctx.attr(el, 'burst', 'auto');
  if (mode === 'manual') return;
  if (el.hasAttribute('aria-pressed')) {
    let last = el.getAttribute('aria-pressed');
    new MutationObserver(function () {
      const v = el.getAttribute('aria-pressed');
      if (v === 'true' && last !== 'true') burst(el, null, ctx);
      last = v;
    }).observe(el, { attributes: true, attributeFilter: ['aria-pressed'] });
  } else {
    el.addEventListener('click', function () { burst(el, null, ctx); });
  }
}

export function init(items, ctx) {
  for (let i = 0; i < items.length; i++) {
    const it = items[i], el = it.el;
    if (it.name === 'copy') setupCopy(el, ctx);
    else if (it.name === 'burst') setupBurst(el, ctx);
    else if (it.name === 'ripple') {
      el.addEventListener('pointerdown', function (e) { if (e.button === 0) ripple(el, e, ctx); });
      el.addEventListener('click', function (e) { if (e.detail === 0) ripple(el, null, ctx); });
    }
    ctx.status(it.name, el, 'active');
  }
}
