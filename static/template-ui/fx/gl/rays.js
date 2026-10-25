/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - rays background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* God rays: shimmering light shafts fanning down from a source above the block that follows the pointer; transparent. */
export default function (L) {
  return {
    scale: 0.6,
    fragment: L.HEAD + L.NOISE + [
      'void main(){',
      '  vec2 p = gl_FragCoord.xy / uRes.y;',
      '  float asp = uRes.x / uRes.y;',
      '  vec2 src = vec2(asp * (0.5 + (uMouse.x - 0.5) * 0.25 * uHover), 1.18);',
      '  vec2 d = p - src;',
      '  float ang = atan(d.x, -d.y);',
      '  float dist = length(d);',
      '  float t = uTime * 0.12;',
      '  float r1 = 0.5 + 0.5 * sin(ang * 22.0 + t * 1.3 + sin(ang * 6.0 - t) * 1.5);',
      '  float r2 = 0.5 + 0.5 * sin(ang * 35.0 - t * 1.8 + 1.3);',
      '  float r3 = vnoise(vec2(ang * 12.0 / uScale, t * 1.5));',
      '  float rays = pow(r1 * 0.6 + r2 * 0.4, 2.5) * (0.5 + 0.5 * r3);',
      '  float cone = smoothstep(1.1, 0.25, abs(ang));',
      '  float fall = exp(-dist * 0.9) * smoothstep(0.0, 0.25, dist);',
      '  float halo = exp(-dist * 2.0) * 0.3;',
      '  float a = clamp((rays * cone * fall * 1.5 + halo) * uIntensity, 0.0, 1.0);',
      '  vec3 c = mix(uC1, uC2, smoothstep(0.2, 1.4, dist));',
      '  c = mix(c, uC3, r2 * 0.35);',
      '  gl_FragColor = vec4(c * a, a);',
      '}'
    ].join('\n')
  };
}
