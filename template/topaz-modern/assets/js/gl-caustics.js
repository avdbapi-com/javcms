/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * Contains third-party code under its own license, named in NOTICE. This license covers the JavCMS parts only.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Topaz Prism (Modern) - caustics with spectral fringes | original GLSL for the JavCMS FX kit (K1 shader module) | JavCMS Community License 1.0
 * Light playing through crystal: an animated Voronoi (cell points drift on sine paths) whose cell edges (F2 - F1) become
 * bright caustic lines, at two scales. On desktop the red, green and blue channels are sampled slightly apart along the
 * radial direction, which gives the edges a spectral "fire". Phones render one scale and one channel. Light mode paints
 * pale ice-blue caustics on the white gallery background. Hash: Dave Hoskins "Hash without Sine" (MIT, 2014). */
export default function (LIB) {
  var W = window, D = document, ROOT = D.documentElement;
  var mq = function (q) { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
  var phone = mq('(max-width: 767px)') || !mq('(hover: hover) and (pointer: fine)');

  var FRAG = [
    'uniform float uLight;uniform float uPhone;',
    'vec2 tpHash(vec2 p){vec3 p3=fract(vec3(p.xyx)*vec3(.1031,.1030,.0973));p3+=dot(p3,p3.yzx+33.33);return fract((p3.xx+p3.yz)*p3.zy);}',
    'float tpCell(vec2 p,float t){vec2 i=floor(p);vec2 f=fract(p);float f1=8.;float f2=8.;',
    ' for(int y=-1;y<=1;y++){for(int x=-1;x<=1;x++){vec2 g=vec2(float(x),float(y));vec2 h=tpHash(i+g);vec2 o=g+.5+sin(t*.3+h*6.2832)*.35;vec2 r=o-f;float d=dot(r,r);if(d<f1){f2=f1;f1=d;}else if(d<f2){f2=d;}}}',
    ' float e=sqrt(f2)-sqrt(f1);return pow(1.-smoothstep(0.,.12,e),3.);}',
    'float tpCaustic(vec2 p,float t){return .65*tpCell(p*3.,t)+.35*tpCell(p*5.3+2.1,t*1.3);}',
    'void main(){vec2 uv=vUv;float asp=uRes.x/max(uRes.y,1.);vec2 d=uv-.5;vec2 p=vec2(d.x*asp,d.y)*max(uScale,.2);float t=uTime;',
    ' p+=(uMouse-.5)*.05*uHover;',
    ' vec3 c;',
    ' if(uPhone>.5){float v=tpCell(p*3.,t);c=vec3(v);}',
    ' else{vec2 f=d*.006;c=vec3(tpCaustic(p+f,t),tpCaustic(p,t),tpCaustic(p-f,t));}',
    ' float vig=smoothstep(1.05,.28,length(d*vec2(1.,1.45)));',
    ' vec3 tint=mix(uC1,vec3(1.),.35);',
    ' vec3 dark=uBase+c*tint*.55*uIntensity*vig;',
    ' vec3 light=uBase+(uC1-uBase)*c*.26*uIntensity*vig;',
    ' gl_FragColor=vec4(mix(dark,light,uLight),1.);}'
  ].join('\n');

  return {
    scale: 0.5,
    uniforms: { uLight: ROOT.getAttribute('data-color-mode') === 'light' ? 1 : 0, uPhone: phone ? 1 : 0 },
    fragment: LIB.HEAD + FRAG,
    frame: function (u) { u.uLight.value = ROOT.getAttribute('data-color-mode') === 'light' ? 1 : 0; }
  };
}
