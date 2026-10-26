/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Velvet Premiere (Modern) - velvet curtains | original GLSL for the JavCMS FX kit (K1 shader module) | JavCMS Community License 1.0
 * Two velvet panels hang at the sides of the stage; the centre stays transparent so the CSS stage shows through.
 * Folds: sin(local * k * PI + noise) plus a warped harmonic; k grows 7 -> 12 as a panel gathers; sheen pow(1 - |cos|, 3); idle sway that is
 * strongest at the hem; a soft billow near the pointer; scalloped valance; rose-gold hem; a light-mode branch.
 * First visit per session: the panels part from 22% to 12% of the width (phones and tablets 10% -> 6%). */
export default function (LIB) {
  var D = document, W = window, ROOT = D.documentElement;
  var mq = function (q) { try { return W.matchMedia(q).matches; } catch (e) { return false; } };
  var compact = mq('(max-width: 1023px)');
  var phone = mq('(max-width: 767px)');
  var openW = compact ? 0.06 : 0.12;
  var closedW = compact ? 0.10 : 0.22;
  var premiere = ROOT.classList.contains('vp-premiere');
  var dur = phone ? 1000 : 1400, delay = 500, start = -1, done = !premiere;

  var FRAG = [
    'uniform float uOpen;uniform float uLight;uniform float uGather;uniform float uDpr;',
    'void main(){',
    '  float dpr=max(uDpr,.25);vec2 res=uRes/dpr;vec2 px=vUv*res;float Wd=res.x;float Ht=res.y;float t=uTime;',
    '  float side=step(.5,vUv.x);float xs=mix(px.x,Wd-px.x,side);float yUp=vUv.y;',
    '  float wpx=uOpen*Wd;',
    '  float sway=sin(t*.3+yUp*1.5+side*2.1)*.04*(1.-yUp);',
    '  float my=uMouse.y*Ht;',
    '  float bill=uHover*16.*exp(-pow((xs-wpx)/90.,2.)-pow((px.y-my)/170.,2.));',
    '  wpx=wpx*(1.+sway)+bill;',
    '  float local=xs/max(wpx,1.);',
    '  float k=mix(7.,12.,uGather);',
    '  float n=vnoise(vec2(yUp*2.+t*.1,side*5.3));',
    '  float warp=.55*sin(local*3.3+side*2.4+yUp*.8)+.25*sin(local*7.1+1.7);',
    '  float ph=local*k*3.14159265+(n-.5)*1.2+side*1.7+warp;',
    '  float folds=sin(ph)*.72+sin(ph*1.93+1.3)*.28;float sheen=pow(1.-abs(cos(ph)),3.)*.85;float shade=.32+.68*(.5+.5*folds);',
    '  float vert=smoothstep(0.,.25,yUp)*(1.-.35*smoothstep(.75,1.,yUp));',
    '  vec3 col=mix(uC1,uC2,shade*(.55+.45*vert));',
    '  col+=sheen*uC3*.35*uIntensity*(.6+.4*vert);',
    '  col+=smoothstep(.82,1.,local)*uC3*.12;',
    '  vec3 hemCol=mix(vec3(.957,.714,.627),vec3(.639,.086,.29),uLight);',
    '  float hem=1.-smoothstep(9.,12.,px.y);',
    '  col=mix(col,hemCol*(.8+.2*folds),hem*.75);',
    '  float aa=1.2/max(wpx,1.);float inside=1.-smoothstep(1.-aa,1.+aa,local);',
    '  float shadow=(1.-inside)*(1.-smoothstep(1.,1.+46./max(wpx,1.),local))*mix(.42,.14,uLight);',
    '  float yTop=Ht-px.y;float sc=mod(px.x,60.)-30.;',
    '  float edge=4.+22.*sqrt(max(0.,1.-sc*sc/900.));',
    '  float inVal=1.-smoothstep(edge-.8,edge+.8,yTop);',
    '  float vf=sin(px.x/60.*12.5663706);',
    '  vec3 valCol=mix(uC1,uC2,.42+.3*vf)*(.72+.28*smoothstep(edge,0.,yTop));',
    '  valCol=mix(valCol,hemCol,(1.-smoothstep(1.,2.6,abs(yTop-edge+1.6)))*.85*step(yTop,edge+1.));',
    '  float valShadow=(1.-inVal)*(1.-smoothstep(edge,edge+14.,yTop))*mix(.35,.12,uLight);',
    '  float a=inside;vec3 c=col*a;',
    '  a=a+max(shadow,valShadow)*(1.-a);',
    '  c=mix(c,valCol,inVal);a=mix(a,1.,inVal);',
    '  gl_FragColor=vec4(c,a);',
    '}'
  ].join('\n');

  return {
    scale: phone ? 0.75 : 1,
    uniforms: { uOpen: premiere ? closedW : openW, uLight: 0, uGather: premiere ? 0 : 1 },
    fragment: LIB.HEAD + LIB.NOISE + FRAG,
    frame: function (u, h) {
      u.uLight.value = ROOT.getAttribute('data-color-mode') === 'light' ? 1 : 0;
      if (done) return;
      var now = (W.performance && performance.now) ? performance.now() : Date.now();
      if (start < 0) start = now + delay;
      var p = Math.min(1, Math.max(0, (now - start) / dur));
      var e = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
      u.uOpen.value = closedW + (openW - closedW) * e;
      u.uGather.value = e;
      if (p >= 1) {
        done = true;
        if (h && h.el) h.el.classList.add('is-parted');
        try { W.sessionStorage.setItem('vp.premiere', '1'); } catch (err) { /* private mode */ }
      }
    }
  };
}
