/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - cursor glow | JavCMS Community License 1.0 | original code
 * data-fx="cursor" on <html>/<body>; data-fx-cursor="glow|ring|both" (default glow).
 * A soft light (and/or a ring that grows over links) trails the pointer. The real cursor is never hidden.
 * Fine pointers only; off for reduced motion and low power; hidden over iframes and when the pointer leaves.
 * Vars: --fx-cursor-size (520px) --fx-cursor-color --fx-cursor-blend (screen; multiply in light mode) --fx-cursor-ring */
const D = document;
const LINK = 'a[href],button,[role="button"],label,summary,select,input[type="submit"],input[type="button"],input[type="checkbox"],input[type="radio"],[data-fx-cursor-link]';
const TEXT = 'input:not([type]),input[type="text"],input[type="search"],input[type="email"],input[type="password"],input[type="url"],input[type="number"],textarea,[contenteditable="true"]';

export function init(items, ctx) {
  const env = ctx.env, host = items[0] && items[0].el;
  if (!env.fine || env.reduced || env.lowPower || D.querySelector('.fx-cursor')) { ctx.status('cursor', host, 'static', 'gate'); return; }
  const mode = ctx.attr(host, 'cursor', 'glow');
  const box = D.createElement('div');
  box.className = 'fx-cursor';
  box.setAttribute('aria-hidden', 'true');
  const parts = {};
  if (mode === 'glow' || mode === 'both') { parts.glow = D.createElement('i'); parts.glow.className = 'fx-cursor-glow'; box.appendChild(parts.glow); }
  if (mode === 'ring' || mode === 'both') {
    parts.ring = D.createElement('i'); parts.ring.className = 'fx-cursor-ring'; box.appendChild(parts.ring);
    parts.dot = D.createElement('i'); parts.dot.className = 'fx-cursor-dot'; box.appendChild(parts.dot);
  }
  D.body.appendChild(box);
  let tx = 0, ty = 0, gx = 0, gy = 0, rx = 0, ry = 0, on = false, running = false;
  const put = function (n, x, y) { if (n) n.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0)'; };
  function run() {
    if (running) return;
    running = true;
    ctx.tick(function (t, dt) {
      const kg = 1 - Math.exp(-dt / 140), kr = 1 - Math.exp(-dt / 55);
      gx += (tx - gx) * kg; gy += (ty - gy) * kg;
      rx += (tx - rx) * kr; ry += (ty - ry) * kr;
      put(parts.glow, gx, gy); put(parts.ring, rx, ry); put(parts.dot, tx, ty);
      const busy = Math.abs(tx - gx) > 0.3 || Math.abs(ty - gy) > 0.3 || Math.abs(tx - rx) > 0.3 || Math.abs(ty - ry) > 0.3;
      if (!busy) running = false;
      return busy;
    });
  }
  D.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    tx = e.clientX; ty = e.clientY;
    if (!on) { on = true; gx = rx = tx; gy = ry = ty; box.setAttribute('data-on', ''); }
    run();
  }, { passive: true });
  D.addEventListener('pointerover', function (e) {
    const t = e.target;
    if (!t || !t.closest) return;
    const s = t.closest(TEXT) ? 'text' : t.closest(LINK) ? 'link' : '';
    if (box.getAttribute('data-state') !== s) { if (s) box.setAttribute('data-state', s); else box.removeAttribute('data-state'); }
  }, { passive: true });
  D.addEventListener('pointerout', function (e) { if (!e.relatedTarget) { on = false; box.removeAttribute('data-on'); } }, { passive: true });
  D.addEventListener('pointerdown', function () { box.setAttribute('data-down', ''); }, { passive: true });
  D.addEventListener('pointerup', function () { box.removeAttribute('data-down'); }, { passive: true });
  window.addEventListener('blur', function () { on = false; box.removeAttribute('data-on'); });
  ctx.status('cursor', host, 'active', mode);
}
