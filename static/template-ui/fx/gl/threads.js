/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - threads background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* A bundle of luminous threads that converge at the edges and sway; the pointer pulls them; transparent. */
export default function (L) {
  return {
    scale: 0.75,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 uv = vUv;',
      '  float t = uTime * 0.28;',
      '  float px = 1.0 / uRes.y;',
      '  float env = sin(3.14159 * clamp(uv.x, 0.0, 1.0));',
      '  env = env * env * (3.0 - 2.0 * env);',
      '  vec3 col = vec3(0.0);',
      '  float a = 0.0;',
      '  for (int i = 0; i < 16; i++) {',
      '    float fi = float(i) / 15.0;',
      '    float y = 0.5',
      '      + env * (0.17 * sin(uv.x * 3.0 / uScale + t + fi * 2.2)',
      '             + 0.08 * sin(uv.x * 7.0 / uScale - t * 1.35 + fi * 4.9)',
      '             + 0.035 * sin(uv.x * 13.0 / uScale + t * 0.8 + fi * 8.3))',
      '      + (fi - 0.5) * 0.38 * env;',
      '    float dm = uv.x - uMouse.x;',
      '    y += (uMouse.y - y) * 0.35 * uHover * exp(-dm * dm * 18.0) * env;',
      '    float d = abs(uv.y - y) / px;',
      '    float w = 0.6 + 1.1 * env;',
      '    float core = 1.0 - smoothstep(w, w + 1.2, d);',
      '    float glow = exp(-d * 0.11) * 0.16;',
      '    float m = (core * 0.9 + glow) * (0.3 + 0.7 * env);',
      '    vec3 c = mix(uC1, uC2, fi);',
      '    c = mix(c, uC3, 0.35 + 0.35 * sin(fi * 6.2831 + t));',
      '    col += c * m;',
      '    a += m;',
      '  }',
      '  a = clamp(a * 0.42 * uIntensity, 0.0, 1.0);',
      '  col = min(col * 0.42 * uIntensity, vec3(a));',
      '  gl_FragColor = vec4(col, a);',
      '}'
    ].join('\n')
  };
}
