/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * Contains third-party code under its own license, named in NOTICE. This license covers the JavCMS parts only.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - morphing SVG icons | JavCMS Community License 1.0 | original code; icon geometry from Lucide (ISC, see THIRD-PARTY.txt);
 * spring morphing by morphicons 1.7.1 (MIT, vendored in static/vendor/fx).
 * <button data-fx="morph" data-fx-icons="menu close" aria-expanded="false"><svg></svg> Menu</button>
 *   data-fx-icons="<off> <on>"          icon names for the two states (see ICONS below)
 *   data-fx-morph-state="aria-expanded" what drives the state: aria-expanded | aria-pressed | aria-checked | color
 *                                        (color = html[data-color-mode]; default for [data-color-toggle] buttons)
 *   data-fx-morph-map="dark:sun light:moon"  explicit map for the colour mode
 *   data-fx-morph-toggle                 clicking flips aria-pressed itself (stand-alone toggles)
 *   data-fx-spring="snappy|smooth|bouncy"
 * The element keeps working without this module (static icon); reduced motion swaps instantly. */
const D = document;
const P = function (d) { return ['path', { d: d }]; };
const ICONS = {
  menu: [P('M4 5h16'), P('M4 12h16'), P('M4 19h16')],
  close: [P('M18 6 6 18'), P('m6 6 12 12')],
  sun: [['circle', { cx: 12, cy: 12, r: 4 }], P('M12 2v2'), P('M12 20v2'), P('m4.93 4.93 1.41 1.41'), P('m17.66 17.66 1.41 1.41'), P('M2 12h2'), P('M20 12h2'), P('m6.34 17.66-1.41 1.41'), P('m19.07 4.93-1.41 1.41')],
  moon: [P('M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401')],
  play: [P('M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z')],
  pause: [['rect', { x: 14, y: 3, width: 5, height: 18, rx: 1 }], ['rect', { x: 5, y: 3, width: 5, height: 18, rx: 1 }]],
  search: [P('m21 21-4.34-4.34'), ['circle', { cx: 11, cy: 11, r: 8 }]],
  plus: [P('M5 12h14'), P('M12 5v14')],
  minus: [P('M5 12h14')],
  check: [P('M20 6 9 17l-5-5')],
  copy: [['rect', { width: 14, height: 14, x: 8, y: 8, rx: 2, ry: 2 }], P('M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2')],
  'chevron-down': [P('m6 9 6 6 6-6')],
  'chevron-up': [P('m18 15-6-6-6 6')],
  grid: [['rect', { width: 7, height: 7, x: 3, y: 3, rx: 1 }], ['rect', { width: 7, height: 7, x: 14, y: 3, rx: 1 }], ['rect', { width: 7, height: 7, x: 14, y: 14, rx: 1 }], ['rect', { width: 7, height: 7, x: 3, y: 14, rx: 1 }]],
  list: [P('M3 5h.01'), P('M3 12h.01'), P('M3 19h.01'), P('M8 5h13'), P('M8 12h13'), P('M8 19h13')],
  volume: [P('M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z'), P('M16 9a5 5 0 0 1 0 6'), P('M19.364 18.364a9 9 0 0 0 0-12.728')],
  'volume-off': [P('M11 4.702a.7.7 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.7.7 0 0 0 11 19.298z'), P('m16.5 14.5 5-5'), P('m16.5 9.5 5 5')],
  eye: [P('M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0'), ['circle', { cx: 12, cy: 12, r: 3 }]],
  'eye-off': [P('M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49'), P('M14.084 14.158a3 3 0 0 1-4.242-4.242'), P('M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143'), P('m2 2 20 20')],
  heart: [P('M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5')],
  'arrow-left': [P('m12 19-7-7 7-7'), P('M19 12H5')],
  'arrow-right': [P('M5 12h14'), P('m12 5 7 7-7 7')]
};
const SVGNS = 'http://www.w3.org/2000/svg';

function stateOf(el, src) {
  if (src === 'color') return D.documentElement.getAttribute('data-color-mode') === 'light' ? 'light' : 'dark';
  return el.getAttribute(src) === 'true' ? 'on' : 'off';
}

function setup(el, ctx, createMorph) {
  if (el.__fxMorph) return;
  const names = ctx.attr(el, 'icons', '').split(/\s+/).filter(Boolean);
  let src = ctx.attr(el, 'morph-state', '');
  if (!src) src = el.hasAttribute('data-color-toggle') ? 'color' : el.hasAttribute('aria-expanded') ? 'aria-expanded' : el.hasAttribute('aria-checked') ? 'aria-checked' : 'aria-pressed';
  const map = {};
  if (src === 'color') {
    const spec = ctx.attr(el, 'morph-map', names.length >= 2 ? 'dark:' + names[0] + ' light:' + names[1] : 'dark:sun light:moon');
    spec.split(/\s+/).forEach(function (pair) { const kv = pair.split(':'); if (kv[1]) map[kv[0]] = kv[1]; });
  } else {
    map.off = names[0] || 'menu';
    map.on = names[1] || 'close';
  }
  let svg = el.querySelector('svg');
  if (!svg) {
    svg = D.createElementNS(SVGNS, 'svg');
    el.insertBefore(svg, el.firstChild);
  }
  let path = svg.querySelectorAll('*').length === 1 ? svg.querySelector('path') : null;
  if (!path) {
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    path = D.createElementNS(SVGNS, 'path');
    svg.appendChild(path);
  }
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  svg.classList.add('fx-morph-svg');
  let cur = stateOf(el, src);
  const iconFor = function (s) { return ICONS[map[s]] || ICONS[map.off] || ICONS.menu; };
  const m = createMorph(path, iconFor(cur), { reducedMotion: ctx.env.reduced ? 'always' : 'user' });
  el.__fxMorph = m;
  const spring = ctx.attr(el, 'spring', 'snappy');
  const sync = function () {
    const s = stateOf(el, src);
    if (s === cur) return;
    cur = s;
    m.morphTo(iconFor(s), spring);
  };
  if (src === 'color') new MutationObserver(sync).observe(D.documentElement, { attributes: true, attributeFilter: ['data-color-mode'] });
  else new MutationObserver(sync).observe(el, { attributes: true, attributeFilter: [src] });
  if (el.hasAttribute('data-fx-morph-toggle') && src !== 'color') {
    el.addEventListener('click', function () { el.setAttribute(src, el.getAttribute(src) === 'true' ? 'false' : 'true'); });
  }
  ctx.status('morph', el, 'active', map.off + '/' + map.on);
}

export function init(items, ctx) {
  return import('../../vendor/fx/morphicons@1.7.1/morphicons.esm.min.js').then(function (mod) {
    for (let i = 0; i < items.length; i++) {
      try { setup(items[i].el, ctx, mod.createMorph); } catch (e) { ctx.status('morph', items[i].el, 'error', String(e && e.message || e).slice(0, 120)); }
    }
  }, function (e) {
    for (let i = 0; i < items.length; i++) ctx.status('morph', items[i].el, 'error', 'morphicons failed to load');
  });
}

export { ICONS };
