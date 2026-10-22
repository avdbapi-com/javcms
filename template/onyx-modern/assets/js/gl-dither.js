/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * Contains third-party code under its own license, named in NOTICE. This license covers the JavCMS parts only.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! Onyx Grid (Modern) - "Dither Bands" signature shader for the JavCMS FX kit (data-fx="shader") | original GLSL (JAVCMS), JavCMS Community License 1.0
 * A slow 3-octave fbm field cut into contour bands, printed as a 1-bit 4x4 Bayer ordered dither (rendered at 0.5x; the large
 * login/404 plates pass data-fx-quality="0.5" = 0.25x; shown with image-rendering:pixelated). The third contour prints in volt; in light mode it gets an ink underlay.
 * The pointer pushes the field while it hovers. Colours: uC1 = volt (--fx-c1), uC2 = ink dots (--fx-c2), uBase = paper.
 *
 * Contains the 2D simplex noise of webgl-noise:
 *   Description : Array and textureless GLSL 2D simplex noise function.
 *        Author : Ian McEwan, Ashima Arts.  Maintainer : stegu
 *       License : Copyright (C) 2011 Ashima Arts. All rights reserved. Distributed under the MIT License.
 *                 https://github.com/ashima/webgl-noise  https://github.com/stegu/webgl-noise
 */
const SNOISE = [
  '// webgl-noise 2D simplex noise | Copyright (C) 2011 Ashima Arts | MIT License',
  'vec3 mod289(vec3 x){return x-floor(x*(1.0/289.0))*289.0;}',
  'vec2 mod289(vec2 x){return x-floor(x*(1.0/289.0))*289.0;}',
  'vec3 permute(vec3 x){return mod289(((x*34.0)+10.0)*x);}',
  'float snoise(vec2 v){',
  '  const vec4 C=vec4(0.211324865405187,0.366025403784439,-0.577350269189626,0.024390243902439);',
  '  vec2 i=floor(v+dot(v,C.yy));vec2 x0=v-i+dot(i,C.xx);',
  '  vec2 i1=(x0.x>x0.y)?vec2(1.0,0.0):vec2(0.0,1.0);',
  '  vec4 x12=x0.xyxy+C.xxzz;x12.xy-=i1;',
  '  i=mod289(i);',
  '  vec3 p=permute(permute(i.y+vec3(0.0,i1.y,1.0))+i.x+vec3(0.0,i1.x,1.0));',
  '  vec3 m=max(0.5-vec3(dot(x0,x0),dot(x12.xy,x12.xy),dot(x12.zw,x12.zw)),0.0);',
  '  m=m*m;m=m*m;',
  '  vec3 x=2.0*fract(p*C.www)-1.0;vec3 h=abs(x)-0.5;vec3 ox=floor(x+0.5);vec3 a0=x-ox;',
  '  m*=1.79284291400159-0.85373472095314*(a0*a0+h*h);',
  '  vec3 g;g.x=a0.x*x0.x+h.x*x0.y;g.yz=a0.yz*x12.xz+h.yz*x12.yw;',
  '  return 130.0*dot(m,g);',
  '}',
  ''
].join('\n');

const BODY = [
  'uniform float uLight;',
  'float bayer2(vec2 a){a=floor(a);return fract(a.x*0.5+a.y*a.y*0.75);}',
  'float bayer4(vec2 a){return bayer2(0.5*a)*0.25+bayer2(a);}',
  'float field(vec2 p){float s=0.0;float a=0.5;for(int i=0;i<3;i++){s+=a*snoise(p);p=p*2.1+vec2(3.1,1.7);a*=0.5;}return s;}',
  'void main(){',
  '  vec2 asp=vec2(uRes.x/uRes.y,1.0);',
  '  vec2 p=(vUv-0.5)*asp*1.6*uScale;',
  '  vec2 m=(uMouse-0.5)*asp*1.6*uScale;',
  '  vec2 dv=p-m;float d2=dot(dv,dv);',
  '  p+=0.38*dv*exp(-5.0*d2)*uHover;',
  '  float t=uTime;',
  '  float n=field(p*0.45+vec2(t*0.04,-t*0.03))*0.5+0.5;',
  '  float b=n*5.0;',
  '  float fr=fract(b);',
  '  float dist=min(fr,1.0-fr);',
  '  float line=1.0-smoothstep(0.02,0.09,dist);',
  '  float shade=pow(n,3.0)*0.75*uIntensity;',
  '  float bit=step(bayer4(gl_FragCoord.xy),max(shade,line));',
  '  float band=1.0-step(0.5,abs(floor(b+0.5)-2.0));',
  '  float voltLine=line*band;',
  '  vec3 col=mix(uBase,mix(uC2,uC1,voltLine),bit);',
  '  if(uLight>0.5){',
  '    float wide=(1.0-smoothstep(0.02,0.16,dist))*band;',
  '    float core=(1.0-smoothstep(0.02,0.06,dist))*band;',
  '    col=mix(col,uC2,step(0.5,wide));',
  '    col=mix(col,uC1,step(0.5,core));',
  '  }',
  '  gl_FragColor=vec4(col,1.0);',
  '}'
].join('\n');

export default function (LIB) {
  return {
    scale: 0.5,
    uniforms: { uLight: 0 },
    fragment: LIB.HEAD + SNOISE + BODY,
    frame: function (u) { u.uLight.value = document.documentElement.getAttribute('data-color-mode') === 'light' ? 1 : 0; }
  };
}
