/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - horizontal scroll-snap rail | JavCMS Community License 1.0 | original code
 * <div data-fx="rail" data-fx-labels="Previous|Next">
 *   <div data-fx-rail-track> cards... </div>              (or simply the first child)
 *   [<button data-fx-rail-prev>...</button><button data-fx-rail-next>...</button>]   created when missing
 *   [<span class="fx-rail-bar"></span>]                    optional progress bar (--fx-rail-progress 0..1)
 * </div>
 * Native scroll + snap (touch swipe, trackpad, keyboard); arrows page by ~85% of the width; mouse drag on desktop;
 * RTL aware; arrows get aria-disabled at the ends; edge fades via data-fx-at="start|mid|end".
 * Vars: --fx-rail-cols (4.2 / 3.2 / 2.15 by width) --fx-rail-gap --fx-rail-item --fx-rail-pad --fx-rail-fade --fx-rail-btn-size */
const D = document;
const ICON = { prev: 'm15 18-6-6 6-6', next: 'm9 18 6-6-6-6' };

function button(kind, label) {
  const b = D.createElement('button');
  b.type = 'button';
  b.className = 'fx-rail-btn';
  b.setAttribute('data-fx-rail-' + kind, '');
  b.setAttribute('aria-label', label);
  b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + ICON[kind] + '"/></svg>';
  return b;
}

function setup(rail, ctx) {
  if (rail.hasAttribute('data-fx-rail')) return;
  const track = rail.querySelector('[data-fx-rail-track]') || rail.firstElementChild;
  if (!track) return;
  track.setAttribute('data-fx-rail-track', '');
  const labels = ctx.attr(rail, 'labels', 'Previous|Next').split('|');
  let prev = rail.querySelector('[data-fx-rail-prev]'), next = rail.querySelector('[data-fx-rail-next]');
  if (!prev) { prev = button('prev', labels[0] || 'Previous'); rail.appendChild(prev); }
  if (!next) { next = button('next', labels[1] || 'Next'); rail.appendChild(next); }
  const sign = function () { return ctx.rtl(track) ? -1 : 1; };

  function go(d) {
    const step = Math.max(120, track.clientWidth * 0.85);
    track.scrollBy({ left: d * sign() * step, behavior: ctx.env.reduced ? 'auto' : 'smooth' });
  }
  prev.addEventListener('click', function () { if (prev.getAttribute('aria-disabled') !== 'true') go(-1); });
  next.addEventListener('click', function () { if (next.getAttribute('aria-disabled') !== 'true') go(1); });

  let raf = 0;
  function update() {
    raf = 0;
    const max = track.scrollWidth - track.clientWidth;
    const pos = Math.abs(track.scrollLeft);
    /* 8 px tolerance: a scroll-snap padding can park the first / last card a few px from the hard edge */
    const none = max <= 2, atStart = pos <= 8, atEnd = pos >= max - 8;
    if (none) rail.setAttribute('data-fx-noscroll', ''); else rail.removeAttribute('data-fx-noscroll');
    /* a focused arrow that is about to be hidden hands focus to the other arrow (keyboard users keep their place) */
    const a = D.activeElement, pOff = none || atStart, nOff = none || atEnd;
    if (!none && ((a === prev && pOff) || (a === next && nOff))) { try { (a === prev ? next : prev).focus({ preventScroll: true }); } catch (e) {} }
    prev.setAttribute('aria-disabled', String(pOff));
    next.setAttribute('aria-disabled', String(nOff));
    rail.setAttribute('data-fx-at', none ? 'none' : atStart ? 'start' : atEnd ? 'end' : 'mid');
    rail.style.setProperty('--fx-rail-progress', none ? '1' : (pos / max).toFixed(4));
  }
  const req = function () { if (!raf) raf = requestAnimationFrame(update); };
  track.addEventListener('scroll', req, { passive: true });
  if ('ResizeObserver' in window) new ResizeObserver(req).observe(track);
  else window.addEventListener('resize', req, { passive: true });

  /* mouse drag (desktop only; touch keeps native swipe) */
  let drag = null;
  const stopClick = function (e) { e.preventDefault(); e.stopPropagation(); };
  track.addEventListener('pointerdown', function (e) {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    drag = { x: e.clientX, sl: track.scrollLeft, moved: false, id: e.pointerId };
  });
  track.addEventListener('pointermove', function (e) {
    if (!drag) return;
    const dx = e.clientX - drag.x;
    if (!drag.moved && Math.abs(dx) > 6) {
      drag.moved = true;
      try { track.setPointerCapture(drag.id); } catch (err) { /* ignore */ }
      track.setAttribute('data-fx-dragging', '');
    }
    if (drag.moved) track.scrollLeft = drag.sl - dx;
  });
  const end = function () {
    if (!drag) return;
    const moved = drag.moved;
    drag = null;
    if (!moved) return;
    track.removeAttribute('data-fx-dragging');
    track.addEventListener('click', stopClick, true);
    setTimeout(function () { track.removeEventListener('click', stopClick, true); }, 60);
  };
  track.addEventListener('pointerup', end);
  track.addEventListener('pointercancel', end);
  track.addEventListener('dragstart', function (e) { if (drag) e.preventDefault(); });

  rail.setAttribute('data-fx-rail', 'on');
  update();
  ctx.status('rail', rail, 'active');
}

export function init(items, ctx) {
  for (let i = 0; i < items.length; i++) setup(items[i].el, ctx);
}
