/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - particles background shader | JavCMS Community License 1.0 | original GLSL ES 1.0 */
/* Drifting, twinkling GPU points (gl.POINTS) in the three theme colours, parallax with the pointer; additive, transparent. data-fx-size="3" (px). */
export default function (L) {
  return {
    scale: 1,
    points: true,
    additive: true,
    frame: function (u, h) { if (h.size == null) h.size = parseFloat(h.el.getAttribute('data-fx-size')) || 3.5; u.uSize.value = h.size * (h.scale || 1); },
    vertex: [
      'attribute vec3 position;',
      'attribute vec4 random;',
      'uniform float uTime;',
      'uniform vec2 uMouse;',
      'uniform float uSize;',
      'uniform float uDpr;',
      'uniform vec3 uC1;',
      'uniform vec3 uC2;',
      'uniform vec3 uC3;',
      'varying vec3 vColor;',
      'varying float vAlpha;',
      'void main(){',
      '  vec3 p = position;',
      '  float t = uTime * (0.25 + random.y * 0.35);',
      '  p.x += sin(t + random.x * 6.2831) * 0.035 * (0.5 + random.z);',
      '  p.y += cos(t * 0.8 + random.w * 6.2831) * 0.045 * (0.5 + random.x);',
      '  p.y = mod(p.y + 1.0 + uTime * 0.012 * (0.3 + random.z), 2.0) - 1.0;',
      '  p.xy -= (uMouse - 0.5) * 0.08 * (0.25 + p.z);',
      '  gl_Position = vec4(p.xy, 0.0, 1.0);',
      '  gl_PointSize = uSize * uDpr * (0.45 + p.z * 1.1) * (0.7 + random.w * 0.7);',
      '  vColor = random.x < 0.34 ? uC1 : (random.x < 0.67 ? uC2 : uC3);',
      '  vAlpha = (0.3 + 0.7 * p.z) * (0.55 + 0.45 * sin(uTime * (0.8 + random.y * 2.2) + random.z * 6.2831));',
      '}'
    ].join('\n'),
    fragment: [
      'precision mediump float;',
      'varying vec3 vColor;',
      'varying float vAlpha;',
      'uniform float uIntensity;',
      'void main(){',
      '  vec2 c = gl_PointCoord - 0.5;',
      '  float a = smoothstep(0.5, 0.0, length(c));',
      '  a = min(1.0, a * a * 1.5 * vAlpha * uIntensity);',
      '  gl_FragColor = vec4(vColor * a, a);',
      '}'
    ].join('\n')
  };
}
