/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Ember Thermal (Modern) - "Thermal Flow" shader for the JavCMS FX kit WebGL host (K1 custom-shader hook)
 * Original GLSL ES 1.0 (JavCMS Community License 1.0, JavCMS project). A domain-warped gradient-noise heat field mapped through the 8-stop
 * thermal ramp, hotter toward the bottom, cooler on the text side, a pointer hotspot (mouse, or a tap on touch screens)
 * and one layer of rising sparks. Light mode maps the same field onto "thermal paper".
 * Noise: the kit's hash/gnoise helpers (LIB.NOISE). Mirrored for RTL through uDir. */
export default function (LIB) {
  var D = document, W = window;
  var coarse = false;
  try { coarse = W.matchMedia('(pointer: coarse)').matches; } catch (e) { coarse = false; }
  var light = -1, wired = false;
  var frag = LIB.HEAD + LIB.NOISE + [
    'uniform float uLight; uniform float uDir; uniform float uOct; uniform float uSparks;',
    'float warp(vec2 p){ return 0.5 * gnoise(p) + 0.25 * gnoise(mat2(1.6, 1.2, -1.2, 1.6) * p + vec2(1.7, 9.2)); }',
    'float fbm2(vec2 p){ float s = 0.0; float a = 0.5; mat2 m = mat2(1.6, 1.2, -1.2, 1.6);',
    '  for (int i = 0; i < 5; i++) { if (float(i) >= uOct) break; s += a * gnoise(p); p = m * p + vec2(1.7, 9.2); a *= 0.5; }',
    '  return s; }',
    'vec3 rampD(float t){ t = clamp(t, 0.0, 1.0) * 7.0;',
    '  vec3 c = mix(vec3(0.043, 0.027, 0.063), vec3(0.141, 0.063, 0.290), clamp(t, 0.0, 1.0));',
    '  c = mix(c, vec3(0.357, 0.086, 0.588), clamp(t - 1.0, 0.0, 1.0));',
    '  c = mix(c, vec3(0.690, 0.125, 0.498), clamp(t - 2.0, 0.0, 1.0));',
    '  c = mix(c, vec3(0.933, 0.231, 0.231), clamp(t - 3.0, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.478, 0.071), clamp(t - 4.0, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.773, 0.192), clamp(t - 5.0, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.953, 0.769), clamp(t - 6.0, 0.0, 1.0));',
    '  return c; }',
    'vec3 rampL(float t){ t = clamp(t, 0.0, 1.0) * 5.0;',
    '  vec3 c = mix(vec3(0.937, 0.910, 1.000), vec3(1.000, 0.961, 0.925), clamp(t, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.835, 0.710), clamp(t - 1.0, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.604, 0.322), clamp(t - 2.0, 0.0, 1.0));',
    '  c = mix(c, vec3(0.973, 0.380, 0.243), clamp(t - 3.0, 0.0, 1.0));',
    '  c = mix(c, vec3(1.000, 0.773, 0.192), clamp(t - 4.0, 0.0, 1.0));',
    '  return c; }',
    'void main(){',
    '  vec2 uv = vUv; if (uDir < 0.0) uv.x = 1.0 - uv.x;',
    '  vec2 asp = vec2(uRes.x / uRes.y, 1.0);',
    '  vec2 p = (uv - 0.5) * asp * 1.3 * uScale;',
    '  float t = uTime;',
    '  vec2 q = vec2(warp(p + vec2(0.0, -t * 0.12)), warp(p + vec2(5.2, 1.3) - vec2(0.0, t * 0.09)));',
    '  float h = fbm2(p + 1.6 * q + vec2(0.0, -t * 0.18)) * 0.62 + 0.45;',
    '  h += 0.30 * (1.0 - uv.y) - 0.12;',
    '  vec2 mp = uMouse; if (uDir < 0.0) mp.x = 1.0 - mp.x;',
    '  vec2 m = (mp - 0.5) * asp * 1.3 * uScale;',
    '  vec2 dm = p - m;',
    '  h += 0.45 * uHover * exp(-6.0 * dot(dm, dm));',
    '  h *= mix(0.55, 1.0, smoothstep(0.05, 0.55, uv.x));',
    '  h *= uIntensity;',
    '  float spark = 0.0;',
    '  if (uSparks > 0.5) {',
    '    vec2 g = vec2(p.x * 11.0, p.y * 11.0 - t * 2.2);',
    '    vec2 id = floor(g); vec2 f = fract(g) - 0.5;',
    '    float r = hash(id);',
    '    float life = fract(r * 7.3 + t * 0.25);',
    '    spark = step(0.9, r) * smoothstep(0.12, 0.0, length(f - vec2(sin(t * 2.0 + r * 6.283) * 0.25, 0.0))) * (1.0 - life);',
    '    spark *= smoothstep(0.2, 0.62, uv.x);',
    '  }',
    '  vec3 dark = rampD(h) + spark * vec3(1.0, 0.82, 0.45) * 1.2;',
    '  vec3 paper = rampL(h) + spark * vec3(0.95, 0.45, 0.1) * 0.6;',
    '  gl_FragColor = vec4(mix(dark, paper, uLight), 1.0);',
    '}'
  ].join('\n');

  function wire(h) {
    if (wired || !h || !h.el) return;
    wired = true;
    /* touch screens: a tap lights a short-lived hotspot (the kit host only follows mouse/pen) */
    var timer = 0;
    h.el.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'mouse') return;
      var r = h.el.getBoundingClientRect();
      h.mt[0] = (e.clientX - r.left) / r.width; h.mt[1] = 1 - (e.clientY - r.top) / r.height;
      h.mouse[0] = h.mt[0]; h.mouse[1] = h.mt[1];
      h.ht = 1;
      clearTimeout(timer);
      timer = setTimeout(function () { h.ht = 0; }, 1400);
    }, { passive: true });
  }

  return {
    scale: coarse ? 0.45 : 0.5,
    uniforms: { uLight: 0, uDir: 1, uOct: coarse ? 3 : 4, uSparks: coarse ? 0 : 1 },
    fragment: frag,
    frame: function (u, h) {
      if (!wired) { wire(h); h.fps = Math.min(h.fps, coarse ? 30 : 45); }
      var target = D.documentElement.getAttribute('data-color-mode') === 'light' ? 1 : 0;
      if (light < 0) light = target;
      light += (target - light) * 0.12;
      if (Math.abs(target - light) < 0.002) light = target;
      u.uLight.value = light;
      u.uDir.value = D.documentElement.getAttribute('dir') === 'rtl' ? -1 : 1;
    }
  };
}
