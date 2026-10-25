/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - pointer effects | JavCMS Community License 1.0 | original code
 * spotlight : soft light that follows the pointer inside the element (::after). Vars --fx-spot-size --fx-spot-color --fx-spot-blend
 * glow      : on a grid/list: the borders of all children light up around the pointer (::before ring). Vars --fx-glow-size --fx-glow-color --fx-glow-width
 * tilt      : 3D tilt + glare. data-fx-max="8" (deg) data-fx-scale="1.03" data-fx-glare="true" data-fx-perspective="900"; children data-fx-depth="20" (px) pop out
 * magnet    : element is pulled toward the pointer. data-fx-strength="0.35" data-fx-radius="70" (px around the element); children data-fx-magnet-inner move more
 * Mouse / pen only (fine pointers). Tilt and magnet are skipped under reduced motion by the loader. */
const D = document, W = window;
let ctxRef = null, installed = false;
let px = -1e4, py = -1e4, hasPointer = false, raf = 0;
let hotSpot = null, hotGlow = null, curTilt = null;
const glowGroups = new Set();
const tiltState = new Map();
const magnets = new Set();
let magnetRectsDirty = true, glowRectsDirty = true;

function isMouse(e) { return !e.pointerType || e.pointerType === 'mouse' || e.pointerType === 'pen'; }
function schedule() { if (!raf) raf = requestAnimationFrame(frame); }

function setHot(el, prev) {
  if (prev && prev !== el) prev.removeAttribute('data-fx-hot');
  if (el && el !== prev) el.setAttribute('data-fx-hot', '');
  return el;
}

function onMove(e) {
  if (!isMouse(e)) return;
  px = e.clientX; py = e.clientY; hasPointer = true;
  route(e.target);
  schedule();
}
function route(t) {
  const c = t && t.closest ? t : null;
  hotSpot = setHot(c ? c.closest('[data-fx~="spotlight"]') : null, hotSpot);
  const tilt = c ? c.closest('[data-fx~="tilt"]') : null;
  if (tilt !== curTilt) { if (curTilt) leaveTilt(curTilt); curTilt = tilt; if (tilt) enterTilt(tilt); }
  if (glowGroups.size) {
    let g = null;
    if (glowRectsDirty) { glowGroups.forEach(function (s) { s.rect = s.el.getBoundingClientRect(); }); glowRectsDirty = false; }
    glowGroups.forEach(function (s) {
      const r = s.rect, m = s.margin;
      if (!g && px > r.left - m && px < r.right + m && py > r.top - m && py < r.bottom + m) g = s;
    });
    const el = g ? g.el : null;
    if (el !== (hotGlow && hotGlow.el)) {
      if (hotGlow) { hotGlow.el.removeAttribute('data-fx-hot'); }
      hotGlow = g;
      if (g) g.el.setAttribute('data-fx-hot', '');
    }
  }
}
function onLeave(e) {
  if (e.relatedTarget) return;
  hasPointer = false;
  hotSpot = setHot(null, hotSpot);
  if (hotGlow) { hotGlow.el.removeAttribute('data-fx-hot'); hotGlow = null; }
  if (curTilt) { leaveTilt(curTilt); curTilt = null; }
  magnets.forEach(function (m) { m.tx = 0; m.ty = 0; });
  startTick();
}
function onScroll() {
  magnetRectsDirty = true; glowRectsDirty = true;
  tiltState.forEach(function (s) { s.rectDirty = true; });
  if (!hasPointer) return;
  if (!raf) raf = requestAnimationFrame(function () {
    raf = 0;
    const t = D.elementFromPoint(px, py);
    route(t);
    frame();
  });
}

function frame() {
  raf = 0;
  /* read */
  const spotRect = hotSpot ? hotSpot.getBoundingClientRect() : null;
  let glowItems = null;
  if (hotGlow) {
    glowItems = [];
    const kids = hotGlow.el.children, reach = hotGlow.reach;
    for (let i = 0; i < kids.length; i++) {
      const r = kids[i].getBoundingClientRect();
      const near = r.width && px > r.left - reach && px < r.right + reach && py > r.top - reach && py < r.bottom + reach;
      glowItems.push([kids[i], r, near]);
    }
  }
  /* write */
  if (spotRect) {
    hotSpot.style.setProperty('--fx-mx', (px - spotRect.left).toFixed(1) + 'px');
    hotSpot.style.setProperty('--fx-my', (py - spotRect.top).toFixed(1) + 'px');
  }
  if (glowItems) {
    for (let i = 0; i < glowItems.length; i++) {
      const it = glowItems[i], el = it[0];
      if (it[2]) {
        el.style.setProperty('--fx-gx', (px - it[1].left).toFixed(1) + 'px');
        el.style.setProperty('--fx-gy', (py - it[1].top).toFixed(1) + 'px');
        el.__fxFar = false;
      } else if (!el.__fxFar) {
        el.style.setProperty('--fx-gx', '-999px');
        el.style.setProperty('--fx-gy', '-999px');
        el.__fxFar = true;
      }
    }
  }
  if (curTilt) aimTilt(curTilt);
  if (magnets.size) aimMagnets();
}

/* ------------------------------------------------------------ tilt */
function enterTilt(el) {
  let s = tiltState.get(el);
  if (!s) {
    const c = ctxRef;
    s = {
      el: el, rx: 0, ry: 0, sc: 1, tx: 0, ty: 0, ts: 1,
      max: c.num(el, 'max', 8), scale: c.num(el, 'scale', 1.03), persp: c.num(el, 'perspective', 900),
      glare: null, depth: null, rect: null, rectDirty: true, active: false
    };
    if (c.bool(el, 'glare', true)) {
      s.glare = D.createElement('i');
      s.glare.className = 'fx-glare';
      s.glare.setAttribute('aria-hidden', 'true');
    }
    const d = el.querySelectorAll('[data-fx-depth]');
    s.depth = d.length ? Array.prototype.slice.call(d) : null;
    tiltState.set(el, s);
  }
  if (s.glare && !s.glare.parentNode) el.appendChild(s.glare);
  s.active = true; s.rectDirty = true; s.ts = s.scale;
  el.setAttribute('data-fx-active', '');
  if (s.depth) {
    el.style.transformStyle = 'preserve-3d';
    s.depth.forEach(function (n) { n.style.translate = '0 0 ' + (parseFloat(n.getAttribute('data-fx-depth')) || 20) + 'px'; n.style.transition = 'translate .4s cubic-bezier(.16,1,.3,1)'; });
  }
  startTick();
}
function leaveTilt(el) {
  const s = tiltState.get(el);
  if (!s) return;
  s.active = false; s.tx = 0; s.ty = 0; s.ts = 1;
  if (s.depth) s.depth.forEach(function (n) { n.style.translate = ''; });
  startTick();
}
function aimTilt(el) {
  const s = tiltState.get(el);
  if (!s || !s.active) return;
  if (s.rectDirty || !s.rect) { s.rect = el.getBoundingClientRect(); s.rectDirty = false; }
  const r = s.rect;
  const nx = Math.min(1, Math.max(0, (px - r.left) / r.width));
  const ny = Math.min(1, Math.max(0, (py - r.top) / r.height));
  s.tx = (0.5 - ny) * 2 * s.max;
  s.ty = (nx - 0.5) * 2 * s.max;
  if (s.glare) {
    el.style.setProperty('--fx-glx', (nx * 100).toFixed(1) + '%');
    el.style.setProperty('--fx-gly', (ny * 100).toFixed(1) + '%');
  }
  startTick();
}

/* ------------------------------------------------------------ magnet */
function aimMagnets() {
  if (magnetRectsDirty) {
    magnets.forEach(function (m) {
      if (!m.visible) return;
      const r = m.el.getBoundingClientRect();
      m.cx = r.left + r.width / 2 - m.x; m.cy = r.top + r.height / 2 - m.y; m.hw = r.width / 2; m.hh = r.height / 2;
    });
    magnetRectsDirty = false;
  }
  magnets.forEach(function (m) {
    if (!m.visible) return;
    const dx = px - m.cx, dy = py - m.cy;
    const inside = Math.abs(dx) < m.hw + m.radius && Math.abs(dy) < m.hh + m.radius;
    m.tx = inside && hasPointer ? dx * m.strength : 0;
    m.ty = inside && hasPointer ? dy * m.strength : 0;
  });
  startTick();
}

/* ------------------------------------------------------------ shared spring tick */
let ticking = false;
function startTick() {
  if (ticking || !ctxRef) return;
  ticking = true;
  ctxRef.tick(function (t, dt) {
    let busy = false;
    const k = 1 - Math.exp(-dt / 85), km = 1 - Math.exp(-dt / 110);
    tiltState.forEach(function (s) {
      const el = s.el;
      if (!el.isConnected) { tiltState.delete(el); return; }
      const drx = s.tx - s.rx, dry = s.ty - s.ry, ds = s.ts - s.sc;
      if (Math.abs(drx) < 0.01 && Math.abs(dry) < 0.01 && Math.abs(ds) < 0.0005) {
        if (!s.active && el.hasAttribute('data-fx-active')) {
          el.style.transform = ''; el.style.transformStyle = '';
          el.removeAttribute('data-fx-active');
          s.rx = s.ry = 0; s.sc = 1;
        }
        return;
      }
      busy = true;
      s.rx += drx * k; s.ry += dry * k; s.sc += ds * k;
      el.style.transform = 'perspective(' + s.persp + 'px) rotateX(' + s.rx.toFixed(2) + 'deg) rotateY(' + s.ry.toFixed(2) + 'deg) scale(' + s.sc.toFixed(4) + ')';
    });
    magnets.forEach(function (m) {
      const dx = m.tx - m.x, dy = m.ty - m.y;
      if (Math.abs(dx) < 0.05 && Math.abs(dy) < 0.05) {
        if ((m.x || m.y) && !m.tx && !m.ty) {
          m.x = m.y = 0; m.el.style.translate = '';
          if (m.inner) m.inner.forEach(function (n) { n.style.translate = ''; });
          magnetRectsDirty = true;
        }
        return;
      }
      busy = true;
      m.x += dx * km; m.y += dy * km;
      m.el.style.translate = m.x.toFixed(2) + 'px ' + m.y.toFixed(2) + 'px';
      if (m.inner) m.inner.forEach(function (n) { n.style.translate = (m.x * 0.45).toFixed(2) + 'px ' + (m.y * 0.45).toFixed(2) + 'px'; });
    });
    if (!busy) ticking = false;
    return busy;
  });
}

function install(ctx) {
  if (installed) return;
  installed = true;
  D.addEventListener('pointermove', onMove, { passive: true });
  D.addEventListener('pointerover', function (e) { if (isMouse(e)) { px = e.clientX; py = e.clientY; hasPointer = true; route(e.target); schedule(); } }, { passive: true });
  D.addEventListener('pointerout', onLeave, { passive: true });
  W.addEventListener('blur', function () { onLeave({ relatedTarget: null }); });
  W.addEventListener('scroll', onScroll, { passive: true, capture: true });
  W.addEventListener('resize', function () { onScroll(); }, { passive: true });
}

export function init(items, ctx) {
  ctxRef = ctx;
  if (!ctx.env.fine) return;
  for (let i = 0; i < items.length; i++) {
    const it = items[i], el = it.el;
    if (it.name === 'glow') {
      glowGroups.add({ el: el, rect: null, margin: ctx.num(el, 'margin', 60), reach: Math.max(120, ctx.cssNum(el, '--fx-glow-size', 240)) });
      glowRectsDirty = true;
    } else if (it.name === 'magnet') {
      const m = {
        el: el, x: 0, y: 0, tx: 0, ty: 0, cx: 0, cy: 0, hw: 0, hh: 0, visible: false,
        strength: ctx.num(el, 'strength', 0.35), radius: ctx.num(el, 'radius', 70),
        inner: null
      };
      const inner = el.querySelectorAll('[data-fx-magnet-inner]');
      if (inner.length) m.inner = Array.prototype.slice.call(inner);
      magnets.add(m);
      ctx.inView(el, function (v) { m.visible = v; magnetRectsDirty = true; if (!v) { m.tx = m.ty = 0; startTick(); } }, '100px');
    }
    ctx.status(it.name, el, 'active');
  }
  install(ctx);
}
