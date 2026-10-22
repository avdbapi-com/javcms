/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - silk background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Flowing silk folds: sine interference on rotated coordinates, sheen on the crests, film grain; opaque. */
export default function (L) {
  return {
    scale: 0.7,
    opaque: true,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 p = (gl_FragCoord.xy / uRes.y) * (2.4 / uScale);',
      '  float t = uTime * 0.32;',
      '  p = mat2(0.8253, 0.5646, -0.5646, 0.8253) * p;',
      '  float w1 = sin(p.x * 1.6 + sin(p.y * 1.25 + t) * 1.5 + t * 0.55);',
      '  float w2 = sin(p.y * 2.2 + w1 * 1.7 - t * 0.75);',
      '  float w3 = sin((p.x + p.y) * 0.9 + w2 * 1.25 + t * 0.35);',
      '  float shade = 0.5 + 0.5 * (w1 + w2 + w3) / 3.0;',
      '  float crest = pow(1.0 - abs(w2), 5.0);',
      '  float sheen = crest * 0.55 + pow(shade, 6.0) * 0.6;',
      '  vec3 col = mix(uBase, uC1, smoothstep(0.0, 0.85, shade));',
      '  col = mix(col, uC2, smoothstep(0.55, 1.0, shade) * 0.55);',
      '  col += uC3 * sheen * 0.32;',
      '  vec2 q = gl_FragCoord.xy / uRes - uMouse;',
      '  q.x *= uRes.x / uRes.y;',
      '  col += uC3 * exp(-dot(q, q) * 7.0) * 0.14 * uHover;',
      '  col = mix(uBase, col, clamp(0.35 + 0.65 * uIntensity, 0.0, 1.0));',
      '  col += (hash(gl_FragCoord.xy + fract(uTime) * 91.0) - 0.5) * 0.03;',
      '  gl_FragColor = vec4(col, 1.0);',
      '}'
    ].join('\n')
  };
}
