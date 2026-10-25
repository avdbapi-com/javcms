/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Honey Nectar (Modern) - liquid honey | original GLSL for the JavCMS FX kit (K1 shader module) | JavCMS Community License 1.0
 * A slow, viscous height field h = fbm(p*1.3 + flow) + .35*fbm(p*3.1 - t*.03) (gradient noise from the kit library,
 * evaluated on a low-frequency, gently domain-warped plane so the honey seems to creep). Normals: 2 forward differences
 * on phones, 4 central differences on desktop. Colour mix(c1, c2, smoothstep(.2,.9,h)); a broad sheen plus a crisp
 * specular glint pow(.,24..60) in cream from the top-end corner (mirrored in RTL), a soft caustic shimmer
 * (.85 + .15*sin(h*12 + t*.2), damped) and a warm translucent glow in the shallows.
 * Light mode: brighter, more translucent honey (#ffd98a -> #fff3d6). Motion is very slow: nothing flashes.
 * Clean-room: no Shadertoy or React Bits code. */
export default function (LIB) {
  var W = window, ROOT = document.documentElement;
  var phone = false;
  try { phone = W.matchMedia('(max-width: 767px), (pointer: coarse)').matches; } catch (e) { phone = false; }
  var OCT = phone ? 2 : 3;

  var FRAG = [
    'uniform float uLight;uniform float uDir;',
    'float gfbm(vec2 p){float s=0.0;float a=0.55;mat2 m=mat2(1.6,1.2,-1.2,1.6);for(int i=0;i<' + OCT + ';i++){s+=a*gnoise(p);p=m*p+vec2(3.1,1.7);a*=0.45;}return s;}',
    'float field(vec2 p,float t){return gfbm(p*1.3+vec2(t*0.02,-t*0.015))+0.35*gfbm(p*3.1*0.55-vec2(t*0.03,t*0.012));}',
    'void main(){',
    '  float t=uTime;',
    '  vec2 uv=vUv;',
    '  vec2 p=vec2((uv.x-0.5)*uRes.x/uRes.y,uv.y-0.5)*1.05*uScale;',
    '  vec2 warp=vec2(gnoise(p*0.6+vec2(0.0,t*0.016)),gnoise(p*0.6+vec2(5.2,-t*0.012)));',
    '  p+=warp*0.32;',
    '  float e=0.035;',
    phone
      ? '  float h=field(p,t);float hx=field(p+vec2(e,0.0),t);float hy=field(p+vec2(0.0,e),t);vec2 g=vec2(hx-h,hy-h)/e;'
      : '  float hx0=field(p-vec2(e,0.0),t);float hx1=field(p+vec2(e,0.0),t);float hy0=field(p-vec2(0.0,e),t);float hy1=field(p+vec2(0.0,e),t);float h=(hx0+hx1+hy0+hy1)*0.25;vec2 g=vec2(hx1-hx0,hy1-hy0)/(2.0*e);',
    '  h=h*0.62+0.5;',
    '  vec3 n=normalize(vec3(-g*0.2,1.0));',
    '  vec2 lp=vec2(0.55*uDir,0.6)+(uMouse-0.5)*vec2(0.5,0.4)*uHover;',
    '  vec3 L=normalize(vec3(lp,0.75));',
    '  vec3 H=normalize(L+vec3(0.0,0.0,1.0));',
    '  float thick=smoothstep(0.2,0.9,h);',
    '  vec3 deep=mix(uC1*mix(0.55,0.93,uLight),uC1,smoothstep(0.0,0.45,thick));',
    '  vec3 col=mix(deep,uC2,thick);',
    '  float shim=0.85+0.15*sin(h*12.0+t*0.2);',
    '  col*=mix(1.0,shim,mix(0.55,0.25,uLight));',
    '  float diff=clamp(dot(n,L),0.0,1.0);',
    '  col*=mix(0.78,1.06,diff);',
    '  float nh=max(dot(n,H),0.0);',
    '  float sheen=pow(nh,24.0);',
    '  float glint=pow(nh,180.0);',
    '  col+=uC3*(sheen*mix(0.42,0.3,uLight)+glint*mix(0.75,0.5,uLight))*uIntensity;',
    '  float glow=pow(1.0-thick,2.0)*smoothstep(0.15,0.6,h);',
    '  col+=vec3(1.0,0.52,0.12)*glow*mix(0.12,0.05,uLight);',
    '  float rim=pow(1.0-clamp(n.z,0.0,1.0),1.5);',
    '  col+=uC3*rim*mix(0.35,0.2,uLight);',
    '  float side=mix(uv.x,1.0-uv.x,step(uDir,0.0));',
    '  float vig=smoothstep(1.25,0.35,length((uv-vec2(0.55,0.5))*vec2(1.0,1.25)));',
    '  col*=mix(mix(0.72,1.0,vig)*mix(0.82,1.0,smoothstep(0.0,0.55,side)),mix(0.95,1.0,vig),uLight);',
    '  col=mix(col,uBase,mix(0.06,0.0,uLight)*(1.0-vig));',
    '  gl_FragColor=vec4(clamp(col,0.0,1.0),1.0);',
    '}'
  ].join('\n');

  return {
    scale: 0.5,
    uniforms: { uLight: ROOT.getAttribute('data-color-mode') === 'light' ? 1 : 0, uDir: (ROOT.getAttribute('dir') || '') === 'rtl' ? -1 : 1 },
    fragment: LIB.HEAD + LIB.NOISE + FRAG,
    frame: function (u) {
      var target = ROOT.getAttribute('data-color-mode') === 'light' ? 1 : 0;
      u.uLight.value += (target - u.uLight.value) * 0.12;
      if (Math.abs(target - u.uLight.value) < 0.002) u.uLight.value = target;
      u.uDir.value = (ROOT.getAttribute('dir') || '') === 'rtl' ? -1 : 1;
    }
  };
}
