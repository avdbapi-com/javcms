/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - iridescence background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Oil-slick / thin-film iridescence: two-level fbm domain warp coloured by an interference palette blended with the theme colours; opaque. */
export default function (L) {
  return {
    scale: 0.5,
    opaque: true,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 p = (2.0 * gl_FragCoord.xy - uRes) / min(uRes.x, uRes.y) * (0.85 / uScale);',
      '  float t = uTime * 0.12;',
      '  p += (uMouse - 0.5) * 0.4 * uHover;',
      '  vec2 q = vec2(fbm(p + vec2(0.0, t)), fbm(p + vec2(5.2, -t * 0.8)));',
      '  vec2 r = vec2(fbm(p + 2.6 * q + vec2(1.7, 9.2) + t * 0.5), fbm(p + 2.6 * q + vec2(8.3, 2.8) - t * 0.45));',
      '  float th = fbm(p + 2.2 * r);',
      '  vec3 film = 0.5 + 0.5 * cos(6.28318 * (th * 1.7 + length(r) * 0.6 + vec3(0.0, 0.33, 0.67) + t * 0.15));',
      '  vec3 tint = mix(mix(uC1, uC2, smoothstep(0.25, 0.75, th)), uC3, smoothstep(0.55, 1.1, length(q)));',
      '  vec3 col = mix(film, tint, 0.6);',
      '  col += pow(max(film.g, film.b), 6.0) * 0.18;',
      '  col = mix(uBase, col, clamp((0.45 + 0.55 * smoothstep(0.15, 0.85, th)) * uIntensity, 0.0, 1.0));',
      '  gl_FragColor = vec4(col, 1.0);',
      '}'
    ].join('\n')
  };
}
