/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - orb background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* A living orb: noise-distorted glowing rim with a swirling core; it swells and shifts toward the pointer on hover; transparent. */
export default function (L) {
  return {
    scale: 0.75,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 p = (2.0 * gl_FragCoord.xy - uRes) / min(uRes.x, uRes.y) / uScale;',
      '  float t = uTime * 0.45;',
      '  p -= (uMouse - 0.5) * 0.12 * uHover;',
      '  float r = length(p);',
      '  float ang = r > 0.0001 ? atan(p.y, p.x) : 0.0;',
      '  vec2 cs = vec2(cos(ang), sin(ang));',
      '  float n1 = gnoise(cs * 1.4 + vec2(t * 0.7, -t * 0.4));',
      '  float n2 = gnoise(cs * 3.2 - vec2(t * 0.9, t * 0.5));',
      '  float R = 0.6 + (0.06 + 0.07 * uHover) * n1 + 0.025 * n2;',
      '  float e = r - R;',
      '  float rim = exp(-e * e * (220.0 - 90.0 * uHover));',
      '  float halo = exp(-abs(e) * 5.5) * 0.42;',
      '  float inner = smoothstep(R, R - 0.55, r);',
      '  float sw = gnoise(p * 2.4 + vec2(t * 0.8, -t * 0.6) + n1 * smoothstep(0.05, 0.5, r));',
      '  vec3 rc = mix(uC1, uC2, 0.5 + 0.5 * sin(ang + t * 0.8));',
      '  rc = mix(rc, uC3, 0.5 + 0.5 * sin(ang * 2.0 - t * 1.2));',
      '  vec3 ic = mix(uC2, uC1, 0.5 + 0.5 * sw);',
      '  vec3 col = rc * (rim * 1.25 + halo) + ic * inner * (0.14 + 0.2 * uHover) * (0.6 + 0.4 * sw);',
      '  float a = clamp(rim * 1.25 + halo + inner * (0.16 + 0.18 * uHover), 0.0, 1.0) * uIntensity;',
      '  col = min(col * uIntensity, vec3(a));',
      '  gl_FragColor = vec4(col, a);',
      '}'
    ].join('\n')
  };
}
