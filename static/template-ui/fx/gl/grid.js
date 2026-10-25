/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - grid background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Retro synthwave floor: perspective grid scrolling toward the viewer, glowing horizon and a striped sun (data-fx-sun="false" hides it); transparent. */
export default function (L) {
  return {
    scale: 1,
    uniforms: { uSun: 1 },
    frame: function (u, h) { if (h.sun == null) h.sun = h.el.getAttribute('data-fx-sun') === 'false' ? 0 : 1; u.uSun.value = h.sun; },
    fragment: L.HEAD + L.NOISE + [
      'uniform float uSun;',
      'void main(){',
      '  vec2 uv = vUv;',
      '  float asp = uRes.x / uRes.y;',
      '  float hor = 0.44 + (uMouse.y - 0.5) * 0.05 * uHover;',
      '  vec3 col = vec3(0.0);',
      '  float a = 0.0;',
      '  if (uSun > 0.5) {',
      '    vec2 sp = vec2((uv.x - 0.5) * asp, uv.y - hor - 0.15);',
      '    float sd = length(sp);',
      '    float rad = 0.2 * uScale;',
      '    float disc = smoothstep(rad + 0.004, rad - 0.004, sd) * step(hor, uv.y);',
      '    float band = fract(sp.y * 24.0 / uScale + uTime * 0.12);',
      '    float gap = sp.y < 0.0 ? step(band, clamp(-sp.y * 3.4 / uScale, 0.0, 0.85)) : 0.0;',
      '    float sun = disc * (1.0 - gap);',
      '    vec3 sc = mix(uC1, uC3, smoothstep(-rad, rad, sp.y));',
      '    col += sc * sun;',
      '    a += sun;',
      '    float sg = exp(-max(sd - rad, 0.0) * 9.0) * 0.32 * step(hor - 0.01, uv.y);',
      '    col += uC1 * sg * (1.0 - sun);',
      '    a += sg * (1.0 - sun);',
      '  }',
      '  float hd = abs(uv.y - hor);',
      '  float hg = exp(-hd * uRes.y * 0.08) * 0.9 + exp(-hd * 10.0) * 0.22;',
      '  col += mix(uC1, uC2, 0.5) * hg;',
      '  a += hg;',
      '  if (uv.y < hor) {',
      '    float y = hor - uv.y;',
      '    float z = 0.32 / y;',
      '    float xw = (uv.x - 0.5 - (uMouse.x - 0.5) * 0.08 * uHover) * asp * z / uScale;',
      '    float zw = z / uScale + uTime * 0.55;',
      '    float gx = abs(fract(xw * 2.4) - 0.5);',
      '    float gz = abs(fract(zw * 0.9) - 0.5);',
      '    float pxX = 2.4 * asp * z / uScale / uRes.x;',
      '    float pxZ = 0.9 * 0.32 / (y * y) / uScale / uRes.y;',
      '    float lx = 1.0 - smoothstep(0.5, 1.5, (0.5 - gx) / max(pxX, 0.0001));',
      '    float lz = 1.0 - smoothstep(0.5, 1.5, (0.5 - gz) / max(pxZ, 0.0001));',
      '    float fx = 1.0 - smoothstep(0.1, 0.3, pxX);',
      '    float fz = 1.0 - smoothstep(0.07, 0.22, pxZ);',
      '    float g = max(lx * fx, lz * fz) * smoothstep(0.0, 0.08, y);',
      '    g *= 0.55 + 0.45 * smoothstep(0.0, 0.5, y);',
      '    col += mix(uC2, uC3, 0.35) * g * 1.1;',
      '    a += g;',
      '    float fg = exp(-y * 5.0) * 0.12;',
      '    col += uC2 * fg;',
      '    a += fg;',
      '  }',
      '  a = clamp(a * uIntensity, 0.0, 1.0);',
      '  col = min(col * uIntensity, vec3(a));',
      '  gl_FragColor = vec4(col, a);',
      '}'
    ].join('\n')
  };
}
