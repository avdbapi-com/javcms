/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * Contains third-party code under its own license, named in NOTICE. This license covers the JavCMS parts only.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Crimson Ink (Modern) - "Screentone Burst" WebGL shader for the FX kit host (data-fx="shader") | JavCMS theme
 * Original GLSL (JavCMS Community License 1.0, JavCMS project): radial speed-line sectors that cross-fade to a new random set 2.5x per second
 * (smooth, never a hard toggle: WCAG 2.3.1 general-flash safe in both colour modes) and converge on a focus
 * point (the featured panel; desktop: drifts toward the pointer) over a 45-degree halftone whose dot size follows simplex
 * noise. Two-colour print: ink + spot red on paper, inverted in night mode (colours come from --fx-c1/--fx-c2/--fx-base).
 * Third-party snippets: 2D simplex noise by Ian McEwan / Ashima Arts, maintained by Stefan Gustavson (MIT, header below);
 * hash11 "Hash without Sine" by Dave Hoskins (MIT). GLSL ES 1.0, constant loops, no textures. */
const SNOISE = [
  '// Description : Array and textureless GLSL 2D simplex noise function.',
  '//      Author : Ian McEwan, Ashima Arts.',
  '//  Maintainer : stegu',
  '//     License : Copyright (C) 2011 Ashima Arts. All rights reserved.',
  '//               Distributed under the MIT License. See LICENSE file.',
  '//               https://github.com/ashima/webgl-noise',
  '//               https://github.com/stegu/webgl-noise',
  'vec3 mod289(vec3 x){return x-floor(x*(1.0/289.0))*289.0;}',
  'vec2 mod289(vec2 x){return x-floor(x*(1.0/289.0))*289.0;}',
  'vec3 permute(vec3 x){return mod289(((x*34.0)+10.0)*x);}',
  'float snoise(vec2 v){',
  ' const vec4 C=vec4(0.211324865405187,0.366025403784439,-0.577350269189626,0.024390243902439);',
  ' vec2 i=floor(v+dot(v,C.yy)); vec2 x0=v-i+dot(i,C.xx);',
  ' vec2 i1=(x0.x>x0.y)?vec2(1.0,0.0):vec2(0.0,1.0);',
  ' vec4 x12=x0.xyxy+C.xxzz; x12.xy-=i1;',
  ' i=mod289(i);',
  ' vec3 p=permute(permute(i.y+vec3(0.0,i1.y,1.0))+i.x+vec3(0.0,i1.x,1.0));',
  ' vec3 m=max(0.5-vec3(dot(x0,x0),dot(x12.xy,x12.xy),dot(x12.zw,x12.zw)),0.0); m=m*m; m=m*m;',
  ' vec3 x=2.0*fract(p*C.www)-1.0; vec3 h=abs(x)-0.5; vec3 ox=floor(x+0.5); vec3 a0=x-ox;',
  ' m*=1.79284291400159-0.85373472095314*(a0*a0+h*h);',
  ' vec3 g; g.x=a0.x*x0.x+h.x*x0.y; g.yz=a0.yz*x12.xz+h.yz*x12.yw;',
  ' return 130.0*dot(m,g);',
  '}',
  '// Hash without Sine (c) Dave Hoskins, MIT License',
  'float hash11(float p){p=fract(p*0.1031);p*=p+33.33;p*=p+p;return fract(p);}',
  ''
].join('\n');

export default function (LIB) {
  let coarse = false;
  try { coarse = window.matchMedia('(pointer: coarse)').matches; } catch (e) { coarse = false; }
  let focus = null, light = -1, frameNo = 0;
  return {
    scale: coarse ? 0.5 : 0.75,
    uniforms: { uLight: 0, uFocus: [0.33, 0.5], uCell: coarse ? 9 : 7, uDpr: 1 },
    fragment: LIB.HEAD + SNOISE + [
      'uniform float uLight; uniform vec2 uFocus; uniform float uCell; uniform float uDpr;',
      'void main(){',
      '  vec2 asp = vec2(uRes.x / uRes.y, 1.0);',
      '  vec2 p = (vUv - 0.5) * asp;',
      '  vec2 fo = mix(uFocus, clamp(uMouse, 0.0, 1.0), uHover * 0.3);',
      '  vec2 c = p - (fo - 0.5) * asp;',
      '  float ang = atan(c.y, c.x);',
      '  float rad = length(c);',
      '  float s = (ang + 3.14159265) / 6.2831853 * 120.0;',
      '  float sector = floor(s);',
      '  float ft = uTime * 2.5; float flick = floor(ft); float fw = smoothstep(0.0, 1.0, fract(ft));',
      '  float on = mix(step(0.44, hash11(sector * 13.7 + flick * 7.31)), step(0.44, hash11(sector * 13.7 + (flick + 1.0) * 7.31)), fw);',
      '  float len = mix(0.12 + 0.5 * hash11(sector * 3.1 + flick * 1.97), 0.12 + 0.5 * hash11(sector * 3.1 + (flick + 1.0) * 1.97), fw);',
      '  float wid = smoothstep(0.5, 0.26, abs(fract(s) - 0.5)) * (0.55 + 0.45 * hash11(sector * 5.3));',
      '  float ray = on * smoothstep(len, len + 0.24, rad) * wid;',
      '  vec2 q = mat2(0.70710678, -0.70710678, 0.70710678, 0.70710678) * (gl_FragCoord.xy / max(1.0, uCell * uDpr));',
      '  vec2 fc = fract(q) - 0.5;',
      '  float tone = (0.5 + 0.5 * snoise(p * 2.0 + vec2(uTime * 0.05, -uTime * 0.035))) * smoothstep(1.2, 0.1, rad);',
      '  float r = tone * 0.5;',
      '  float aa = 1.2 / max(1.0, uCell * uDpr);',
      '  float dotm = 1.0 - smoothstep(r - aa, r + aa, length(fc));',
      '  float redAmt = mix(0.62, 0.86, uLight) * uIntensity;',
      '  float inkAmt = mix(0.42, 0.88, uLight) * uIntensity;',
      '  vec3 col = mix(uBase, uC1, dotm * (1.0 - ray) * redAmt);',
      '  col = mix(col, uC2, ray * inkAmt);',
      '  float hole = smoothstep(0.07, 0.0, rad);',
      '  col = mix(col, uBase, hole);',
      '  gl_FragColor = vec4(col, 1.0);',
      '}'
    ].join('\n'),
    frame: function (u, h) {
      if (focus === null || (++frameNo % 180) === 0) {
        const raw = (h && h.el && h.el.getAttribute('data-ci-focus')) || '0.33,0.5';
        const parts = raw.split(',');
        const fx = parseFloat(parts[0]), fy = parseFloat(parts[1]);
        focus = [isFinite(fx) ? fx : 0.33, isFinite(fy) ? 1 - fy : 0.5];
      }
      u.uFocus.value = focus;
      const l = document.documentElement.getAttribute('data-color-mode') === 'light' ? 1 : 0;
      if (l !== light) { light = l; }
      u.uLight.value = light;
      if (h && h.dpr) u.uDpr.value = h.dpr;
    }
  };
}
