/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - galaxy background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Flight through a star field: 4 zooming hashed star layers with twinkle over a slow fbm nebula; pointer parallax; transparent. */
export default function (L) {
  return {
    scale: 0.75,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 p = (gl_FragCoord.xy - 0.5 * uRes) / uRes.y;',
      '  float t = uTime;',
      '  float ca = cos(t * 0.01);',
      '  float sa = sin(t * 0.01);',
      '  p = mat2(ca, sa, -sa, ca) * p;',
      '  vec3 col = vec3(0.0);',
      '  float a = 0.0;',
      '  float n = fbm(p * 1.8 / uScale + vec2(t * 0.012, -t * 0.008));',
      '  float n2 = fbm(p * 3.1 / uScale - vec2(t * 0.01, 0.0) + n);',
      '  vec3 neb = mix(uC1, uC2, n2) * smoothstep(0.36, 0.92, n) * 0.85;',
      '  col += neb;',
      '  a += max(max(neb.r, neb.g), neb.b);',
      '  for (int L = 0; L < 4; L++) {',
      '    float fl = float(L);',
      '    float depth = fract(fl * 0.25 + t * 0.018);',
      '    float sc = mix(26.0, 4.0, depth) / uScale;',
      '    float fade = smoothstep(0.0, 0.25, depth) * smoothstep(1.0, 0.82, depth);',
      '    vec2 q = p * sc + vec2(fl * 37.17, fl * 11.31) - (uMouse - 0.5) * (0.4 + depth * 1.6);',
      '    vec2 id = floor(q);',
      '    vec2 gv = fract(q) - 0.5;',
      '    float h = hash(id + fl * 7.0);',
      '    if (h > 0.42) {',
      '      vec2 off = (vec2(h, fract(h * 47.3)) - 0.5) * 0.7;',
      '      float d = length(gv - off);',
      '      float size = 0.035 + 0.075 * fract(h * 131.7) * depth;',
      '      float s = smoothstep(size, 0.0, d) + (0.015 / max(d, 0.015)) * 0.1 * depth;',
      '      float tw = 0.6 + 0.4 * sin(t * (1.0 + fract(h * 7.1) * 3.0) + h * 40.0);',
      '      vec3 c = mix(vec3(1.0), mix(uC3, uC2, fract(h * 23.0)), 0.35);',
      '      float m = s * tw * fade;',
      '      col += c * m;',
      '      a += m;',
      '    }',
      '  }',
      '  a = clamp(a * uIntensity, 0.0, 1.0);',
      '  col = min(col * uIntensity, vec3(a));',
      '  gl_FragColor = vec4(col, a);',
      '}'
    ].join('\n')
  };
}
