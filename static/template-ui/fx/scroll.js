/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - parallax, pointer depth layers, scroll progress | JavCMS Community License 1.0 | original code
 * parallax : data-fx="parallax" data-fx-speed="0.15" (-1..1; negative = opposite). CSS scroll timeline when supported
 *            (off the main thread), otherwise a passive rAF fallback that only touches visible elements.
 * depth    : data-fx="depth" container; children data-fx-depth="0.2..1" drift with the pointer. data-fx-shift="24" (px)
 * progress : data-fx="progress" bar: scale 0..1 with page scroll (CSS scroll() timeline or rAF fallback). */
const D = document, W = window;
const supports = function (q) { try { return !!(W.CSS && CSS.supports && CSS.supports(q)); } catch (e) { return false; } };
const VIEW_TL = supports('animation-timeline: view()');
const SCROLL_TL = supports('animation-timeline: scroll()');

function jsParallax(list, ctx) {
  const items = list.map(function (el) { return { el: el, speed: ctx.num(el, 'speed', 0.15), top: 0, h: 0, vis: false, y: 0 }; });
  let vh = W.innerHeight, raf = 0;
  function measure() {
    vh = W.innerHeight;
    const sy = W.scrollY || W.pageYOffset;
    items.forEach(function (it) {
      const r = it.el.getBoundingClientRect();
      it.top = r.top + sy - it.y; it.h = r.height;
    });
  }
  function update() {
    raf = 0;
    const sy = W.scrollY || W.pageYOffset;
    items.forEach(function (it) {
      if (!it.vis) return;
      const p = (sy + vh - it.top) / (vh + it.h);
      const y = (0.5 - Math.min(1.2, Math.max(-0.2, p))) * 2 * it.speed * 0.32 * vh;
      if (Math.abs(y - it.y) < 0.1) return;
      it.y = y;
      it.el.style.translate = '0 ' + y.toFixed(1) + 'px';
    });
  }
  const req = function () { if (!raf) raf = requestAnimationFrame(update); };
  measure();
  items.forEach(function (it) { ctx.inView(it.el, function (v) { it.vis = v; if (v) req(); }, '20% 0px'); });
  W.addEventListener('scroll', req, { passive: true });
  W.addEventListener('resize', function () { measure(); req(); }, { passive: true });
  W.addEventListener('load', function () { measure(); req(); });
  req();
}

function jsProgress(list) {
  let raf = 0;
  function update() {
    raf = 0;
    const de = D.documentElement, max = Math.max(1, de.scrollHeight - W.innerHeight);
    const p = Math.min(1, Math.max(0, (W.scrollY || W.pageYOffset) / max));
    list.forEach(function (el) { el.style.setProperty('--fx-progress', p.toFixed(4)); });
  }
  const req = function () { if (!raf) raf = requestAnimationFrame(update); };
  W.addEventListener('scroll', req, { passive: true });
  W.addEventListener('resize', req, { passive: true });
  req();
}

function depthLayers(list, ctx) {
  if (!ctx.env.fine) return;
  const groups = list.map(function (el) {
    const kids = Array.prototype.slice.call(el.querySelectorAll('[data-fx-depth]')).map(function (n) {
      return { el: n, f: parseFloat(n.getAttribute('data-fx-depth')) || 0.5, x: 0, y: 0 };
    });
    return { el: el, kids: kids, shift: ctx.num(el, 'shift', 24), tx: 0, ty: 0, vis: false, rect: null };
  });
  let running = false;
  function run() {
    if (running) return;
    running = true;
    ctx.tick(function (t, dt) {
      const k = 1 - Math.exp(-dt / 140);
      let busy = false;
      groups.forEach(function (g) {
        g.kids.forEach(function (c) {
          const tx = g.tx * g.shift * c.f, ty = g.ty * g.shift * c.f;
          const dx = tx - c.x, dy = ty - c.y;
          if (Math.abs(dx) < 0.05 && Math.abs(dy) < 0.05) return;
          busy = true;
          c.x += dx * k; c.y += dy * k;
          c.el.style.translate = c.x.toFixed(2) + 'px ' + c.y.toFixed(2) + 'px';
        });
      });
      if (!busy) running = false;
      return busy;
    });
  }
  groups.forEach(function (g) { ctx.inView(g.el, function (v) { g.vis = v; g.rect = null; if (!v) { g.tx = g.ty = 0; run(); } }, '0px'); });
  W.addEventListener('scroll', function () { groups.forEach(function (g) { g.rect = null; }); }, { passive: true });
  D.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    groups.forEach(function (g) {
      if (!g.vis) return;
      const r = g.rect || (g.rect = g.el.getBoundingClientRect());
      g.tx = Math.max(-1, Math.min(1, (e.clientX - (r.left + r.width / 2)) / (r.width / 2)));
      g.ty = Math.max(-1, Math.min(1, (e.clientY - (r.top + r.height / 2)) / (r.height / 2)));
    });
    run();
  }, { passive: true });
}

export function init(items, ctx) {
  const par = [], dep = [], prog = [];
  items.forEach(function (it) {
    if (it.name === 'parallax') { it.el.style.setProperty('--fx-speed', String(ctx.num(it.el, 'speed', 0.15))); par.push(it.el); }
    else if (it.name === 'depth') dep.push(it.el);
    else if (it.name === 'progress') prog.push(it.el);
    ctx.status(it.name, it.el, 'active', it.name === 'parallax' ? (VIEW_TL ? 'css-view-timeline' : 'js') : it.name === 'progress' ? (SCROLL_TL ? 'css-scroll-timeline' : 'js') : '');
  });
  if (par.length && !VIEW_TL) jsParallax(par, ctx);
  if (prog.length && !SCROLL_TL) jsProgress(prog);
  if (dep.length) depthLayers(dep, ctx);
}
