/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - mesh background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Animated gradient mesh: four colour blobs on Lissajous paths, softly warped, with film grain; one blob leans to the pointer; opaque. */
export default function (L) {
  return {
    scale: 0.5,
    opaque: true,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 uv = vUv;',
      '  float asp = uRes.x / uRes.y;',
      '  vec2 p = vec2(uv.x * asp, uv.y);',
      '  float t = uTime * 0.11;',
      '  p += 0.09 * vec2(gnoise(p * 1.3 / uScale + t), gnoise(p * 1.3 / uScale - t + 4.7));',
      '  vec2 c1 = vec2(asp * (0.22 + 0.18 * sin(t * 1.1)), 0.30 + 0.20 * cos(t * 0.9));',
      '  vec2 c2 = vec2(asp * (0.78 + 0.16 * cos(t * 0.8)), 0.36 + 0.24 * sin(t * 1.3));',
      '  vec2 c3 = vec2(asp * (0.50 + 0.28 * sin(t * 0.6 + 1.0)), 0.78 + 0.16 * cos(t * 1.2));',
      '  vec2 c4 = vec2(asp * (0.12 + 0.12 * cos(t * 1.4)), 0.88 + 0.08 * sin(t));',
      '  c2 = mix(c2, vec2(uMouse.x * asp, uMouse.y), 0.25 * uHover);',
      '  float w1 = 1.0 / (0.03 + dot(p - c1, p - c1) * 4.0);',
      '  float w2 = 1.0 / (0.03 + dot(p - c2, p - c2) * 4.0);',
      '  float w3 = 1.0 / (0.03 + dot(p - c3, p - c3) * 4.0);',
      '  float w4 = 1.4 / (0.03 + dot(p - c4, p - c4) * 4.0);',
      '  vec3 col = (uC1 * w1 + uC2 * w2 + uC3 * w3 + uBase * w4) / (w1 + w2 + w3 + w4);',
      '  col = mix(uBase, col, clamp(0.3 + 0.7 * uIntensity, 0.0, 1.0));',
      '  col += (hash(gl_FragCoord.xy + fract(uTime * 7.0) * 61.0) - 0.5) * 0.045;',
      '  gl_FragColor = vec4(col, 1.0);',
      '}'
    ].join('\n')
  };
}
