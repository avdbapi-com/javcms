/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! Neon Nocturne (Modern) - "Rain on glass over neon bokeh" signature shader for the JavCMS FX kit (data-fx="shader", K1 hook)
 * Original GLSL ES 1.0 (JAVCMS, JavCMS Community License 1.0). Clean-room recipe (MODERN-CONCEPTS 7.6):
 *  - bokeh: 3 layers of hashed cells, one soft disc per cell (smoothstep edge), colour by hash from pink / violet / mint and a rare sodium,
 *    slow vertical drift and a slow breathing of each disc;
 *  - static droplets: a 24 x 14 cell grid with a random centre and radius per cell; normal = (uv - c) / r gives the refraction offset;
 *  - sliding drops: one per column, y = fract(h - t * speed), an elongated head plus a trail of tiny droplets from fract(y * 8), fading;
 *  - final: bokeh(uv + offset) * (1 - .15 * mask) + a rim highlight, cool tint and vignette. Light mode: a pastel dawn bokeh.
 * Render scale 0.5 (x DPR <= 1.5 by the kit). Phones get fewer sliding drops (uDrops) and 30 fps (kit). Mirrored for RTL (uDir).
 * Noise: the kit's hash() helper (LIB.NOISE). Colours come from --fx-c1..3 / --fx-base (uC1 pink, uC2 violet, uC3 mint, uBase). */
export default function (LIB) {
  var D = document, W = window;
  var coarse = false;
  try { coarse = W.matchMedia('(pointer: coarse)').matches; } catch (e) { coarse = false; }
  var frag = LIB.HEAD + LIB.NOISE + [
    'uniform float uLight; uniform float uDrops; uniform float uDir;',
    'vec2 hash2(vec2 p){ return vec2(hash(p), hash(p + vec2(17.3, 51.7))); }',
    'vec3 tube(float h){',
    '  vec3 c = uC1;',
    '  if (h > 0.38) c = uC2;',
    '  if (h > 0.72) c = uC3;',
    '  if (h > 0.93) c = vec3(1.0, 0.70, 0.28);',
    '  if (uLight > 0.5) c = mix(c, vec3(1.0), 0.55);',
    '  return c; }',
    'vec4 bokeh(vec2 uv, float t){',
    '  vec4 col = vec4(0.0);',
    '  for (int l = 0; l < 3; l++) {',
    '    float fl = float(l);',
    '    float s = 2.6 + fl * 2.2;',
    '    vec2 p = uv * s + vec2(fl * 7.13, t * (0.012 + 0.008 * fl) + fl * 3.7);',
    '    vec2 i = floor(p); vec2 f = fract(p);',
    '    for (int y = -1; y <= 1; y++) { for (int x = -1; x <= 1; x++) {',
    '      vec2 n = vec2(float(x), float(y));',
    '      vec2 id = i + n;',
    '      float h = hash(id + fl * 11.0);',
    '      if (h < 0.42) continue;',
    '      vec2 c = n + 0.15 + 0.7 * hash2(id + 5.0);',
    '      float r = 0.16 + 0.34 * hash(id + 9.0);',
    '      float d = length(f - c);',
    '      float breathe = 0.75 + 0.25 * sin(t * 0.35 + h * 6.28);',
    '      float disc = smoothstep(r, r * 0.55, d) * breathe;',
    '      float a = (0.22 + 0.5 * hash(id + 2.0)) * (1.0 - fl * 0.22);',
    '      col += vec4(tube(hash(id + 3.0)) * disc * a, disc * a);',
    '    } }',
    '  }',
    '  return col; }',
    'void main(){',
    '  vec2 uv = vUv; if (uDir < 0.0) uv.x = 1.0 - uv.x;',
    '  float asp = uRes.x / max(uRes.y, 1.0);',
    '  vec2 q = vec2(uv.x * asp, uv.y) * uScale;',
    '  float t = uTime;',
    '  /* static droplets */',
    '  vec2 g = vec2(uv.x * 24.0 * asp / 1.78, uv.y * 14.0);',
    '  vec2 gi = floor(g); vec2 gf = fract(g);',
    '  float dh = hash(gi + 0.5);',
    '  vec2 dc = 0.28 + 0.44 * hash2(gi + 2.5);',
    '  float dr = 0.09 + 0.2 * hash(gi + 7.5);',
    '  vec2 dv = (gf - dc) / dr;',
    '  float dl = length(dv);',
    '  float sm = step(0.42, dh) * (1.0 - smoothstep(0.86, 1.0, dl));',
    '  vec2 off = dv * 0.03 * sm * (1.0 - dl * dl * 0.5);',
    '  /* sliding drops */',
    '  float colsN = 18.0 * asp / 1.78;',
    '  float cx = uv.x * colsN; float ci = floor(cx); float cf = fract(cx);',
    '  float ch = hash(vec2(ci, 4.2));',
    '  float on = step(ch, uDrops);',
    '  float sp = 0.045 + 0.07 * hash(vec2(ci, 8.8));',
    '  float yy = fract(hash(vec2(ci, 1.3)) - t * sp);',
    '  float cxo = 0.5 + 0.2 * sin(t * 0.7 + ci);',
    '  vec2 hd = vec2((cf - cxo) * 4.2, (uv.y - yy) * 15.0);',
    '  float hl = length(hd);',
    '  float head = on * (1.0 - smoothstep(0.75, 1.0, hl));',
    '  float above = step(yy, uv.y) * on;',
    '  float ty = fract(uv.y * 8.0 + hash(vec2(ci, 6.6)));',
    '  vec2 tv = vec2((cf - cxo) * 5.0, (ty - 0.5) * 2.4);',
    '  float trail = above * (1.0 - smoothstep(0.5, 0.8, length(tv))) * exp(-(uv.y - yy) * 5.0) * step(0.3, hash(vec2(ci, floor(uv.y * 8.0))));',
    '  off += hd * 0.05 * head + tv * 0.012 * trail;',
    '  float mask = max(sm, max(head, trail));',
    '  /* compose */',
    '  vec3 base = uBase;',
    '  vec4 bk = bokeh(q + off, t);',
    '  vec3 col = base + bk.rgb * uIntensity;',
    '  if (uLight > 0.5) { float a = clamp(bk.a, 0.0, 1.0); col = mix(base, mix(base, bk.rgb / max(bk.a, 0.001), 0.42), a); }',
    '  col *= 1.0 - (uLight > 0.5 ? 0.05 : 0.15) * mask;',
    '  float rim = mask * (1.0 - smoothstep(0.55, 1.0, max(dl * sm, hl * head)));',
    '  col += (uLight > 0.5 ? vec3(-0.10, -0.12, -0.04) : vec3(1.0)) * rim * 0.09;',
    '  vec2 m = (uMouse - 0.5) * vec2(asp, 1.0); vec2 pp = (uv - 0.5) * vec2(asp, 1.0);',
    '  col += uC2 * 0.12 * uHover * exp(-dot(pp - m, pp - m) * 9.0);',
    '  col = mix(col, col * vec3(0.92, 0.96, 1.08), 0.35);',
    '  float vig = 1.0 - 0.55 * dot(pp, pp);',
    '  col *= uLight > 0.5 ? mix(1.0, vig, 0.3) : vig;',
    '  gl_FragColor = vec4(col, 1.0);',
    '}'
  ].join('\n');
  return {
    scale: 0.5,
    uniforms: { uLight: 0, uDrops: coarse ? 0.3 : 0.6, uDir: 1 },
    fragment: frag,
    frame: function (u) {
      var h = D.documentElement;
      u.uLight.value = h.getAttribute('data-color-mode') === 'light' ? 1 : 0;
      u.uDir.value = h.getAttribute('dir') === 'rtl' ? -1 : 1;
    }
  };
}
