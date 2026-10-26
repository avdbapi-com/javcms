/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* JavCMS video ads: the VAST part (ADS-BRIEF 7.4). Readable source of static/jcx/jcx-a.js (build:
   node deployment/jcx-src/build.js, budget 1.5 KB gzip). Split out of jcx-v.js in the video fix round (2026-10-16,
   ADS-VIDEO-TRIAGE P1-04 / P2-01: fallback chain, budgets) so jcx-v.js keeps its 7 KB budget, like jcx-m.js was split
   out of jcx.js in repair round 2. jcx-v.js loads it (with its own version string) only when a VAST item is drawn.
   VAST 2/3/4 runs through the vendored vast-client 6.5.0 (import() on demand, memory storage, no cookies).
   - JcxA.vast(urls, n, lim, base): up to 5 tag URLs in fallback order, wrappers up to 5 deep, all inside lim ms;
     resolves the first <= n linear ads [{src, type, vs, u, tr, cp}] or rejects with the reason (vast | vpaid | dash | media).
     DASH and VPAID never run.
   - JcxA.comp(cp): a VAST companion, only in the slot under the player (P03) when it exists and the picture fits.
   - JcxA.hls(v, u, base): an m3u8 source on video v (moved here from jcx-v.js in fix round 3, budgets). HLS goes
     through hls.js light wherever Media Source exists, so the level is capped to the player size and only about 8 s
     are buffered (ADS-VIDEO-TEST F1: native HLS took 1080p for a 640 x 360 box); native HLS only without Media
     Source (older iPhones). Resolves the hls.js instance (or 0), rejects 'hls'.
   - JcxA.test(urls, base): JcxV.test() (moved here in fix round 3): {ok, n, ads} or {ok: 0, why}. */
(function (w, d) {
  if (w.JcxA) return;
  function E(t, a) { var e = d.createElement(t), k; for (k in a) if (a[k] != null) e.setAttribute(k, a[k]); return e; }
  function safe(u) { try { u = new URL(u, location.href); return /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; } }
  /* the media file to play: MP4 first, then WebM, then HLS, nearest to 720p, no file above 2.5 Mbit/s when others exist */
  function media(fs) {
    var v = E('video'), ok = [], vp, da;
    fs.forEach(function (f) {
      var t = String(f.mimeType || '').toLowerCase();
      if (/vpaid/i.test(f.apiFramework || '') || /javascript|flash/.test(t)) vp = 1;
      else if (/dash/.test(t)) da = 1;
      else if (f.fileURL && (/mpegurl/.test(t) || v.canPlayType(t))) ok.push(f);
    });
    ok.sort(function (a, b) { return rank(a) - rank(b); });
    return ok[0] || (vp ? 'vpaid' : da ? 'dash' : 'media');
  }
  function rank(f) { var t = f.mimeType || ''; return (/mp4/.test(t) ? 0 : /webm/.test(t) ? 1e4 : 2e4) + Math.abs((f.height || 720) - 720) + (f.bitrate > 2500 ? 5e3 : 0); }
  function vast(urls, n, lim, base) {
    var t0 = Date.now(), why = 'vast';
    return import(base + 'static/jcx/lib/vc-6.5.0.mjs').then(function (L) {
      var m = {}, c = new L.VASTClient(0, 0, { getItem: function (k) { return m[k]; }, setItem: function (k, v) { m[k] = v; } }), i = 0;
      function next() {
        var u = safe(urls[i++] || ''), left = lim - (Date.now() - t0);
        if (!u || i > 5 || left < 100) throw why;
        return c.get(u, { timeout: left, wrapperLimit: 5 }).then(function (r) {
          var out = [];
          (r.ads || []).forEach(function (ad) {
            var lin, cp, f;
            (ad.creatives || []).forEach(function (x) { if (x.type == 'linear' && !lin) lin = x; if (x.type == 'companion' && !cp) cp = x; });
            if (!lin || out.length >= n) return;
            f = media(lin.mediaFiles || []);
            if (typeof f == 'string') { why = f; return; }
            out.push({ src: f.fileURL, type: f.mimeType, vs: lin.skipDelay, u: (lin.videoClickThroughURLTemplate || {}).url, tr: new L.VASTTracker(c, ad, lin), cp: cp });
          });
          return out.length ? out : next();
        }, next);
      }
      return next();
    });
  }
  function hls(v, u, base) {
    var nat = v.canPlayType('application/vnd.apple.mpegurl');
    if (nat && !(w.MediaSource || w.ManagedMediaSource)) { v.src = u; return Promise.resolve(); }
    return (w.Hls ? Promise.resolve() : new Promise(function (y, n) { var s = E('script', { src: base + 'static/jcx/lib/hls-1.7.3.light.min.js' }); s.onload = y; s.onerror = n; d.head.appendChild(s); })).then(function () {
      if (!w.Hls || !Hls.isSupported()) { if (nat) return v.src = u, 0; throw 'hls'; }
      var x = new Hls({ maxBufferLength: 8, maxMaxBufferLength: 10, maxBufferSize: 8e6, capLevelToPlayerSize: true });
      x.on(Hls.Events.ERROR, function (e, z) { if (z.fatal) v.dispatchEvent(new Event('error')); });
      x.loadSource(u); x.attachMedia(v);
      return x;
    });
  }
  function comp(cp) {
    var s = d.querySelector('.jcx-s[data-jcx="P03"]'), v, r, a;
    if (!s || !cp) return;
    (cp.variations || []).some(function (x) {
      r = (x.staticResources || []).filter(function (z) { return /^image\//.test(z.creativeType || 'image/'); })[0];
      return r && x.width <= s.clientWidth && x.height <= s.clientHeight - 20 && (v = x);
    });
    if (!v || !safe(r.url)) return;
    a = E('a', { href: safe(v.companionClickThroughURLTemplate) || null, rel: 'sponsored noopener', target: '_blank' });
    a.appendChild(E('img', { src: safe(r.url), width: v.width, height: v.height, alt: v.altText || '' }));
    [].slice.call(s.children).forEach(function (c) { if (!c.classList.contains('jcx-l')) c.remove(); });
    s.appendChild(a);
  }
  function test(urls, base) {
    return vast(urls, 2, 3500, base).then(function (l) { return { ok: 1, n: l.length, ads: l.map(function (a) { return { src: a.src, type: a.type, skip: a.vs }; }) }; },
      function (why) { return { ok: 0, why: typeof why == 'string' ? why : 'vast' }; });
  }
  w.JcxA = Object.freeze({ vast: vast, comp: comp, hls: hls, test: test });
})(window, document);
