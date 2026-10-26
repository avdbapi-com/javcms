/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - WebGL backgrounds engine | JavCMS Community License 1.0 | original code; renderer OGL 1.0.11 (Unlicense, vendored)
 * data-fx="aurora|silk|liquid|threads|iridescence|particles|galaxy|waves|orb|mesh|rays|grid" on any block.
 * - The CSS gradient fallback (fx.css ::before) is always painted first; the canvas fades in over it.
 * - ONE WebGL context per page: a single canvas moves to the most visible background; the others keep their fallback.
 * - Lazy: context + shader only when a background is near the viewport, compiled in an idle callback.
 * - Paused when off-screen or the tab is hidden; 60 fps cap on desktop, 30 on touch; DPR <= 1.5 (x shader scale);
 *   automatic quality drop on slow frames, CSS fallback if still slow, on context loss, or without WebGL.
 * Options (attribute > CSS custom property > default):
 *   colors     data-fx-colors="#f0f,#0ff,#ff0[,#base]"   --fx-c1 --fx-c2 --fx-c3 --fx-base (else --fx-accent* / --fx-bg)
 *   speed      data-fx-speed="1"        --fx-bg-speed
 *   intensity  data-fx-intensity="1"    --fx-bg-intensity
 *   scale      data-fx-scale="1"        --fx-bg-scale       (pattern size)
 *   data-fx-interactive="false" (no pointer reaction)  data-fx-fps="30"  data-fx-quality="0.5..1"  data-fx-static (always CSS)
 *   grid only: data-fx-sun="false" */
const D = document, W = window;
const VERT = 'attribute vec2 position;attribute vec2 uv;varying vec2 vUv;void main(){vUv=uv;gl_Position=vec4(position,0.0,1.0);}';
const HEAD = [
  '#ifdef GL_FRAGMENT_PRECISION_HIGH', 'precision highp float;', '#else', 'precision mediump float;', '#endif',
  'uniform float uTime;uniform vec2 uRes;uniform vec2 uMouse;uniform float uHover;',
  'uniform vec3 uC1;uniform vec3 uC2;uniform vec3 uC3;uniform vec3 uBase;uniform float uIntensity;uniform float uScale;',
  'varying vec2 vUv;', ''
].join('\n');
const NOISE = [
  'float hash(vec2 p){p=fract(p*vec2(127.13,311.71));p+=dot(p,p+19.37);return fract(p.x*p.y);}',
  'float vnoise(vec2 p){vec2 i=floor(p);vec2 f=fract(p);vec2 u=f*f*(3.0-2.0*f);',
  'return mix(mix(hash(i),hash(i+vec2(1.0,0.0)),u.x),mix(hash(i+vec2(0.0,1.0)),hash(i+vec2(1.0,1.0)),u.x),u.y);}',
  'vec2 grad2(vec2 p){float a=hash(p)*6.2831853;return vec2(cos(a),sin(a));}',
  'float gnoise(vec2 p){vec2 i=floor(p);vec2 f=fract(p);vec2 u=f*f*f*(f*(f*6.0-15.0)+10.0);',
  'return mix(mix(dot(grad2(i),f),dot(grad2(i+vec2(1.0,0.0)),f-vec2(1.0,0.0)),u.x),',
  'mix(dot(grad2(i+vec2(0.0,1.0)),f-vec2(0.0,1.0)),dot(grad2(i+vec2(1.0,1.0)),f-vec2(1.0,1.0)),u.x),u.y);}',
  'float fbm(vec2 p){float s=0.0;float a=0.5;mat2 m=mat2(1.6,1.2,-1.2,1.6);for(int i=0;i<4;i++){s+=a*vnoise(p);p=m*p;a*=0.5;}return s;}',
  ''
].join('\n');
const LIB = { VERT: VERT, HEAD: HEAD, NOISE: NOISE };
const MAX_POINTS = 360;

let C = null, OGL = null, R = null, glPromise = null, glOff = '';
const hosts = new Map();
const defs = {}, progs = {};
let active = null, raf = 0, lastDraw = 0, clock = 0, quality = 1, ema = 0, frames = 0;
let rectDirty = true, activeRect = null, listening = false;

function idle(fn) { if (W.requestIdleCallback) W.requestIdleCallback(fn, { timeout: 1200 }); else setTimeout(fn, 60); }

function readColors(h) {
  const el = h.el, cs = getComputedStyle(el);
  const v = function (n) { return cs.getPropertyValue(n).trim(); };
  const list = (el.getAttribute('data-fx-colors') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  const c = function (i, names, d) {
    let s = list[i];
    for (let k = 0; !s && k < names.length; k++) s = v(names[k]);
    const rgb = C.color(s, d);
    return [rgb[0], rgb[1], rgb[2]];
  };
  h.tc = [
    c(0, ['--fx-c1', '--fx-accent'], [1, 0.24, 0.5]),
    c(1, ['--fx-c2', '--fx-accent-2'], [0.49, 0.36, 1]),
    c(2, ['--fx-c3', '--fx-accent-3'], [0.13, 0.83, 0.93]),
    c(3, ['--fx-base', '--fx-bg'], [0.04, 0.05, 0.09])
  ];
  if (!h.cc) h.cc = h.tc.map(function (x) { return x.slice(); });
}

function option(el, attr, prop, d) {
  const a = parseFloat(el.getAttribute('data-fx-' + attr));
  if (isFinite(a)) return a;
  return C.cssNum(el, prop, d);
}

/* data-fx="shader": a theme-owned shader module (same origin, .js) in the kit's definition format. */
function shaderUrl(el) {
  try {
    const u = new URL(el.getAttribute('data-fx-shader') || '', D.baseURI);
    if (u.origin !== W.location.origin || !/\.js$/i.test(u.pathname)) return '';
    return u.href; // the theme adds its own ?v=
  } catch (e) { return ''; }
}

function register(el, name) {
  if (hosts.has(el)) return;
  if (el.hasAttribute('data-fx-static')) { C.status(name, el, 'static', 'data-fx-static'); return; }
  if (glOff) { C.status(name, el, 'static', glOff); return; }
  let key = name;
  if (name === 'shader') { key = shaderUrl(el); if (!key) { C.status(name, el, 'static', 'bad data-fx-shader'); return; } }
  const h = {
    el: el, name: name, key: key, prog: null, preparing: false, vis: false, area: 0, shown: false,
    tc: null, cc: null, mouse: [0.5, 0.5], mt: [0.5, 0.5], hover: 0, ht: 0,
    speed: option(el, 'speed', '--fx-bg-speed', 1),
    intensity: option(el, 'intensity', '--fx-bg-intensity', 1),
    scale: option(el, 'scale', '--fx-bg-scale', 1),
    q: Math.min(1, Math.max(0.3, option(el, 'quality', '--fx-bg-quality', 1))),
    fps: C.num(el, 'fps', C.env.fine ? 60 : 30),
    interactive: C.bool(el, 'interactive', true),
    unsub: []
  };
  hosts.set(el, h);
  readColors(h);
  h.unsub.push(C.inView(el, function (v) { if (v) prepare(h); }, '60% 0px'));
  h.unsub.push(C.inView(el, function (v, e) {
    h.vis = v;
    h.area = v && e ? e.intersectionRect.width * e.intersectionRect.height : 0;
    if (h === active) rectDirty = true;
    pick();
  }, '0px', [0, 0.05, 0.2, 0.4, 0.6, 0.8, 1]));
  C.status(name, el, 'pending');
}

function unregister(el) {
  const h = hosts.get(el);
  if (!h) return;
  h.unsub.forEach(function (f) { f(); });
  hosts.delete(el);
  if (active === h) { detach(h); active = null; pick(); }
  el.removeAttribute('data-fx-gl');
}

function ensureGL() {
  if (glPromise) return glPromise;
  glPromise = import('../../vendor/fx/ogl@1.0.11/ogl-core.min.js').then(function (mod) {
    OGL = mod;
    const canvas = D.createElement('canvas');
    canvas.className = 'fx-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    const attrs = {
      alpha: true, premultipliedAlpha: true, antialias: false, depth: false, stencil: false,
      preserveDrawingBuffer: !!C.cfg.preserve, powerPreference: 'low-power', failIfMajorPerformanceCaveat: !C.env.force
    };
    /* All shaders are GLSL ES 1.0, so WebGL1 is enough everywhere (and asking for WebGL2 first makes Firefox log a
       warning on systems where WebGL2 is blocked). */
    const v = 1;
    let gl = null;
    try { gl = canvas.getContext('webgl', attrs); } catch (e) { gl = null; }
    if (!gl) return false;
    const renderer = new OGL.Renderer({ canvas: canvas, dpr: 1, alpha: true, premultipliedAlpha: true, depth: false, antialias: false, webgl: v, preserveDrawingBuffer: !!C.cfg.preserve });
    renderer.gl.clearColor(0, 0, 0, 0);
    canvas.addEventListener('webglcontextlost', function () { giveUp('context-lost'); }, false);
    R = { renderer: renderer, gl: renderer.gl, canvas: canvas, v: v };
    D.documentElement.setAttribute('data-fx-webgl', 'on');
    C.env.webgl = 'webgl' + v;
    return true;
  }).catch(function (e) {
    if (W.console) console.warn('[fx] WebGL engine:', e);
    return false;
  }).then(function (ok) {
    if (!ok && !glOff) giveUp(C.env.force ? 'no-webgl' : 'no-webgl-or-software-renderer');
    return ok;
  });
  return glPromise;
}

function loadDef(name) {
  if (!defs[name]) defs[name] = import(name.indexOf('/') >= 0 ? name : './gl/' + name + '.js' + C.q).then(function (m) { return m.default(LIB); });
  return defs[name];
}

function compile(name, def) {
  if (progs[name]) return progs[name];
  progs[name] = new Promise(function (resolve, reject) {
    idle(function () {
      if (!R) { reject(new Error('no-gl')); return; }
      try {
        const gl = R.gl;
        const uniforms = {
          uTime: { value: 0 }, uRes: { value: [1, 1] }, uMouse: { value: [0.5, 0.5] }, uHover: { value: 0 },
          uC1: { value: [1, 0, 0] }, uC2: { value: [0, 1, 0] }, uC3: { value: [0, 0, 1] }, uBase: { value: [0, 0, 0] },
          uIntensity: { value: 1 }, uScale: { value: 1 }, uDpr: { value: 1 }, uSize: { value: 3 }
        };
        const extra = def.uniforms || {};
        Object.keys(extra).forEach(function (k) { uniforms[k] = { value: extra[k] }; });
        const program = new OGL.Program(gl, {
          vertex: def.vertex || VERT, fragment: def.fragment, uniforms: uniforms,
          transparent: !!def.additive, depthTest: false, depthWrite: false, cullFace: false
        });
        if (!gl.getProgramParameter(program.program, gl.LINK_STATUS)) throw new Error('shader-link-failed');
        if (def.additive) program.setBlendFunc(gl.ONE, gl.ONE);
        let geometry;
        if (def.points) {
          const pos = new Float32Array(MAX_POINTS * 3), rnd = new Float32Array(MAX_POINTS * 4);
          for (let i = 0; i < MAX_POINTS; i++) {
            pos[i * 3] = Math.random() * 2 - 1; pos[i * 3 + 1] = Math.random() * 2 - 1; pos[i * 3 + 2] = Math.random();
            for (let k = 0; k < 4; k++) rnd[i * 4 + k] = Math.random();
          }
          geometry = new OGL.Geometry(gl, { position: { size: 3, data: pos }, random: { size: 4, data: rnd } });
        } else geometry = new OGL.Triangle(gl);
        const mesh = new OGL.Mesh(gl, { geometry: geometry, program: program, mode: def.points ? gl.POINTS : gl.TRIANGLES });
        resolve({ program: program, mesh: mesh, geometry: geometry, def: def });
      } catch (e) { reject(e); }
    });
  });
  return progs[name];
}

function prepare(h) {
  if (h.prog || h.preparing || glOff) return;
  h.preparing = true;
  ensureGL().then(function (ok) {
    if (!ok) return null;
    return loadDef(h.key || h.name).then(function (def) { h.def = def; return compile(h.key || h.name, def); });
  }).then(function (p) {
    if (!p || !hosts.has(h.el)) return;
    h.prog = p;
    pick();
  }).catch(function (e) {
    h.preparing = false;
    C.status(h.name, h.el, 'static', 'error: ' + String(e && e.message || e).slice(0, 80));
    if (W.console) console.warn('[fx] ' + h.name + ':', e);
  });
}

function pick() {
  let best = null;
  hosts.forEach(function (h) { if (h.vis && h.prog && (!best || h.area > best.area)) best = h; });
  if (best !== active) {
    if (active) detach(active);
    active = best;
    if (best) attach(best);
  }
  if (active && !D.hidden) start(); else stop();
}

function attach(h) {
  if (!R) return;
  h.el.insertBefore(R.canvas, h.el.firstChild);
  rectDirty = true;
  quality = 1; ema = 0; frames = 0;
  resize(h);
  h.shown = false;
  if (h.ro) h.ro.disconnect();
  if ('ResizeObserver' in W) {
    h.ro = new ResizeObserver(function () { rectDirty = true; if (active === h) resize(h); });
    h.ro.observe(h.el);
  }
  C.status(h.name, h.el, 'active', 'webgl' + R.v);
  listen();
}

function detach(h) {
  h.el.removeAttribute('data-fx-gl');
  h.shown = false;
  if (h.ro) { h.ro.disconnect(); h.ro = null; }
}

function resize(h) {
  if (!R) return;
  const r = h.el.getBoundingClientRect();
  const w = Math.max(1, Math.round(r.width)), hh = Math.max(1, Math.round(r.height));
  let dpr = Math.min(W.devicePixelRatio || 1, 1.5) * (h.def && h.def.scale || 1) * quality * h.q;
  const maxPx = 1.5e6;
  if (w * hh * dpr * dpr > maxPx) dpr = Math.sqrt(maxPx / (w * hh));
  dpr = Math.max(0.25, dpr);
  R.renderer.dpr = dpr;
  R.renderer.setSize(w, hh);
  R.canvas.style.width = '100%';
  R.canvas.style.height = '100%';
  h.dpr = dpr; h.w = w; h.h = hh;
  if (h.prog) {
    const u = h.prog.program.uniforms;
    u.uRes.value = [w * dpr, hh * dpr];
    u.uDpr.value = dpr;
    if (h.def.points) h.prog.geometry.setDrawRange(0, Math.round(Math.min(MAX_POINTS, Math.max(60, (w * hh) / (C.env.fine ? 4200 : 6500)))));
  }
}

function listen() {
  if (listening) return;
  listening = true;
  W.addEventListener('pointermove', function (e) {
    const h = active;
    if (!h || !h.interactive || (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen')) return;
    if (rectDirty || !activeRect) { activeRect = h.el.getBoundingClientRect(); rectDirty = false; }
    const r = activeRect;
    const x = (e.clientX - r.left) / r.width, y = 1 - (e.clientY - r.top) / r.height;
    const inside = x >= 0 && x <= 1 && y >= 0 && y <= 1;
    h.mt[0] = Math.min(1.2, Math.max(-0.2, x)); h.mt[1] = Math.min(1.2, Math.max(-0.2, y));
    h.ht = inside ? 1 : 0;
  }, { passive: true });
  W.addEventListener('scroll', function () { rectDirty = true; }, { passive: true, capture: true });
  D.documentElement.addEventListener('pointerleave', function () { if (active) active.ht = 0; });
  C.onVisible(function (v) { if (v) pick(); else stop(); });
  C.onTheme(function () { hosts.forEach(readColors); });
  try {
    const mq = W.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = function () { if (mq.matches) giveUp('reduced-motion'); };
    if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
  } catch (e) { /* ignore */ }
}

function start() { if (!raf && active) { lastDraw = 0; raf = requestAnimationFrame(frame); } }
function stop() { if (raf) { cancelAnimationFrame(raf); raf = 0; } }

function frame(t) {
  raf = 0;
  const h = active;
  if (!h || !R || D.hidden) return;
  raf = requestAnimationFrame(frame);
  if (lastDraw && t - lastDraw < 1000 / h.fps - 2) return;
  const dt = lastDraw ? Math.min(t - lastDraw, 100) : 1000 / h.fps;
  lastDraw = t;
  clock += Math.min(dt, 50) * 0.001 * h.speed;
  if (clock > 3000) clock -= 3000;
  const km = 1 - Math.exp(-dt / 180), kh = 1 - Math.exp(-dt / 320), kc = 1 - Math.exp(-dt / 260);
  h.mouse[0] += (h.mt[0] - h.mouse[0]) * km;
  h.mouse[1] += (h.mt[1] - h.mouse[1]) * km;
  h.hover += (h.ht - h.hover) * kh;
  for (let i = 0; i < 4; i++) for (let k = 0; k < 3; k++) h.cc[i][k] += (h.tc[i][k] - h.cc[i][k]) * kc;
  const u = h.prog.program.uniforms;
  u.uTime.value = clock;
  u.uMouse.value = h.mouse;
  u.uHover.value = h.hover;
  u.uC1.value = h.cc[0]; u.uC2.value = h.cc[1]; u.uC3.value = h.cc[2]; u.uBase.value = h.cc[3];
  u.uIntensity.value = h.intensity;
  u.uScale.value = h.scale;
  if (h.def.frame) h.def.frame(u, h);
  R.renderer.render({ scene: h.prog.mesh });
  if (!h.shown) { h.shown = true; h.el.setAttribute('data-fx-gl', 'on'); }
  /* adaptive quality: persistently slow frames -> lower resolution -> give up */
  const target = 1000 / h.fps;
  ema = ema ? ema * 0.94 + dt * 0.06 : target;
  if (++frames > 120 && ema > target * 1.7) {
    frames = 0;
    if (quality > 0.5) { quality *= 0.75; resize(h); C.status(h.name, h.el, 'active', 'quality ' + quality.toFixed(2)); }
    else giveUp('slow-frames');
  }
}

function giveUp(reason) {
  glOff = reason;
  stop();
  hosts.forEach(function (h) {
    h.el.removeAttribute('data-fx-gl');
    h.unsub.forEach(function (f) { f(); });
    if (h.ro) h.ro.disconnect();
    C.status(h.name, h.el, 'static', reason);
  });
  hosts.clear();
  active = null;
  D.documentElement.setAttribute('data-fx-webgl', 'off');
  C.env.webgl = 'off';
  if (R) {
    const canvas = R.canvas;
    setTimeout(function () { if (canvas.parentNode) canvas.parentNode.removeChild(canvas); }, 1100);
    try { const ext = R.gl.getExtension('WEBGL_lose_context'); if (ext && reason !== 'context-lost') ext.loseContext(); } catch (e) { /* ignore */ }
    R = null;
  }
}

export function init(items, ctx) {
  C = ctx;
  for (let i = 0; i < items.length; i++) register(items[i].el, items[i].name);
}

export function refresh(el, ctx, items) {
  C = ctx;
  unregister(el);
  for (let i = 0; items && i < items.length; i++) register(items[i].el, items[i].name);
}

export function info() {
  return { webgl: R ? 'webgl' + R.v : glOff || 'pending', active: active ? active.name : null, hosts: hosts.size, quality: quality, dpr: active ? active.dpr : 0 };
}

export function sample() {
  /* Debug helper for tests: renders one frame and returns the share of lit pixels on a 48x27 grid + 5 probes. */
  if (!R || !active || !active.prog) return null;
  R.renderer.render({ scene: active.prog.mesh });
  const gl = R.gl, w = gl.drawingBufferWidth, h = gl.drawingBufferHeight;
  const buf = new Uint8Array(w * h * 4);
  gl.readPixels(0, 0, w, h, gl.RGBA, gl.UNSIGNED_BYTE, buf);
  let lit = 0, n = 0;
  for (let gy = 0; gy < 27; gy++) for (let gx = 0; gx < 48; gx++) {
    const x0 = Math.floor((gx / 48) * w), y0 = Math.floor((gy / 27) * h), x1 = Math.floor(((gx + 1) / 48) * w), y1 = Math.floor(((gy + 1) / 27) * h);
    let any = false;
    for (let y = y0; y < y1 && !any; y++) for (let x = x0; x < x1; x++) { const i = (y * w + x) * 4; if (buf[i + 3] > 8 || buf[i] + buf[i + 1] + buf[i + 2] > 24) { any = true; break; } }
    if (any) lit++;
    n++;
  }
  const probe = function (fx, fy) { const i = (Math.floor(fy * (h - 1)) * w + Math.floor(fx * (w - 1))) * 4; return [buf[i], buf[i + 1], buf[i + 2], buf[i + 3]]; };
  return { lit: +(lit / n).toFixed(3), probes: [probe(0.5, 0.5), probe(0.25, 0.75), probe(0.75, 0.25)] };
}
