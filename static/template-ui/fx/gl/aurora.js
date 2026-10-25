/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - aurora background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Curtains of light hanging from the top: 3 noise-driven bands of varying length with fine rays and a bright fringe; transparent overlay. */
export default function (L) {
  return {
    scale: 0.7,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 uv = vUv;',
      '  float asp = uRes.x / max(uRes.y, 1.0);',
      '  float t = uTime * 0.1;',
      '  vec3 col = vec3(0.0);',
      '  float a = 0.0;',
      '  for (int k = 0; k < 3; k++) {',
      '    float fk = float(k);',
      '    float x = uv.x * asp * (0.7 + 0.3 * fk) / uScale + fk * 3.7;',
      '    float n = fbm(vec2(x * 0.8 + t * (1.0 + 0.4 * fk), t * 0.5 + fk * 9.1));',
      '    float n2 = vnoise(vec2(x * 2.2 - t * 0.7, fk * 4.0 + t * 0.3));',
      '    float top = 0.8 - 0.12 * fk + (n - 0.5) * 0.7 + (n2 - 0.5) * 0.12 + (uMouse.y - 0.5) * 0.06 * uHover;',
      '    float d = top - uv.y;',
      '    float len = 0.22 + 0.38 * n2;',
      '    float band = smoothstep(-0.03, 0.04, d) * exp(-max(d, 0.0) / len * 1.6);',
      '    float rays = 0.3 + 0.7 * fbm(vec2(x * 9.0 + t * 1.2, uv.y * 0.6 - t * 0.3 + fk));',
      '    float m = band * rays * (1.0 - 0.18 * fk);',
      '    vec3 ca = k == 0 ? uC1 : (k == 1 ? uC2 : uC3);',
      '    vec3 cb = k == 0 ? uC2 : (k == 1 ? uC3 : uC1);',
      '    vec3 c = mix(ca, cb, smoothstep(0.25, 0.75, n2));',
      '    c = mix(c, vec3(1.0), smoothstep(0.035, 0.0, abs(d)) * 0.3);',
      '    col += c * m;',
      '    a += m;',
      '  }',
      '  float glow = exp(-length((uv - uMouse) * vec2(asp, 1.0)) * 4.0) * 0.12 * uHover;',
      '  col += uC2 * glow;',
      '  a += glow;',
      '  a = clamp(a * uIntensity * 0.85, 0.0, 1.0);',
      '  col = min(col * uIntensity * 0.85, vec3(a));',
      '  gl_FragColor = vec4(col, a);',
      '}'
    ].join('\n')
  };
}
