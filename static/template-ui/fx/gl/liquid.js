/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - liquid background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Liquid chrome: iterative cosine domain warp mapped to metallic bands with specular glints; opaque. */
export default function (L) {
  return {
    scale: 0.6,
    opaque: true,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 uv = (2.0 * gl_FragCoord.xy - uRes) / min(uRes.x, uRes.y) * (1.25 / uScale);',
      '  float t = uTime * 0.35;',
      '  uv += (uMouse - 0.5) * 0.35 * uHover;',
      '  for (int i = 1; i < 8; i++) {',
      '    float fi = float(i);',
      '    uv.x += 0.6 / fi * cos(fi * 2.0 * uv.y + t + fi * 0.73);',
      '    uv.y += 0.6 / fi * cos(fi * 1.6 * uv.x - t * 0.85 + fi * 1.31);',
      '  }',
      '  float v = 0.5 + 0.5 * sin(uv.x + uv.y);',
      '  float b = 0.5 + 0.5 * cos(v * 9.42);',
      '  vec3 col = mix(uBase, uC1, smoothstep(0.1, 0.9, v));',
      '  col = mix(col, uC2, b * 0.5);',
      '  col += uC3 * pow(b, 14.0) * 0.55;',
      '  col += vec3(pow(b, 40.0) * 0.25);',
      '  col = mix(uBase, col, clamp(0.3 + 0.7 * uIntensity, 0.0, 1.0));',
      '  gl_FragColor = vec4(col, 1.0);',
      '}'
    ].join('\n')
  };
}
