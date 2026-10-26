/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - waves background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Topographic wave lines: horizontal contour lines bent by gradient noise, a bump follows the pointer; transparent. */
export default function (L) {
  return {
    scale: 1,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 uv = vUv;',
      '  float asp = uRes.x / uRes.y;',
      '  vec2 p = vec2(uv.x * asp, uv.y) / uScale;',
      '  float t = uTime * 0.16;',
      '  float n = gnoise(vec2(p.x * 1.1 + t, p.y * 0.8 - t * 0.5)) * 1.2 + gnoise(vec2(p.x * 2.4 - t * 0.7, p.y * 1.9 + t * 0.3)) * 0.5;',
      '  vec2 m = vec2(uMouse.x * asp, uMouse.y) / uScale;',
      '  float md = length(p - m);',
      '  n += uHover * 0.55 * exp(-md * md * 6.0);',
      '  float lines = 26.0;',
      '  float y = (uv.y + n * 0.16) * lines;',
      '  float dl = 0.5 - abs(fract(y) - 0.5);',
      '  float dpx = dl * uRes.y / lines;',
      '  float line = 1.0 - smoothstep(0.5, 1.6, dpx);',
      '  float glow = exp(-dpx * 0.35) * 0.18;',
      '  vec3 c = mix(uC1, uC2, smoothstep(0.0, 1.0, uv.x + n * 0.2));',
      '  c = mix(c, uC3, smoothstep(0.35, 1.0, n));',
      '  float vig = smoothstep(0.0, 0.3, uv.y) * smoothstep(1.0, 0.7, uv.y);',
      '  float a = clamp((line + glow) * (0.3 + 0.7 * vig) * uIntensity, 0.0, 1.0);',
      '  gl_FragColor = vec4(c * a, a);',
      '}'
    ].join('\n')
  };
}
