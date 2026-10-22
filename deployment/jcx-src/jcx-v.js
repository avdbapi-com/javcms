/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS video ads runtime (ADS-BRIEF 7.1-7.6, units U15 / U16). Readable source of static/jcx/jcx-v.js
   (build: node deployment/jcx-src/build.js, budget 7 KB gzip).
   Loaded by static/js/player.js on watch pages that carry #jcx-v (or the admin preview hint cookie jcxh=1) and by
   jcx.js for an out-stream item (U16). UD4: every video plays in the provider's iframe player, so video ads play
   only BEFORE it (pre-roll, rewarded pre-roll); no ad plays during or after the video.
   - gate(box, player_aaaa, done): the pre-roll gate fills the theme's player box BEFORE the provider iframe exists and
     always ends in done(); the iframe is then built by the unchanged adapter (never wrapped, sandboxed or changed).
   - outstream(node, item): muted in-content video, plays only while >= 50 % visible; 9:16 opens a tap-to-open dialog.
   - test(urls): resolves a VAST tag list for the admin "Test tag" button ({ok, n, ads} or {ok:0, why}).
   VAST 2/3/4 runs in jcx-a.js (loaded on demand) through the vendored vast-client 6.5.0; m3u8 plays natively or
   through the vendored hls.js 1.7.3 light, also from jcx-a.js. Nothing third-party loads unless the admin entered a VAST URL or a remote
   file. Counters go into the one jcx.js beacon per page view (1 rendered, 3 click, 5 start, 6-8 quartiles, 9 complete,
   10 skip, 11 error). Buttons carry data-jcx-act and are run by the head-snippet click shield (JCX._h.hooks), or by a
   listener on our own box where the page has no shield. Strings come from #jcx-v (the
   preview: jcx/pv 'vs'; out-stream: #jcx-d). Caps: only the item's own cap (How often); none = every video (owner
   decision UD5, 2026-10-16: no implicit limit). A cap is used at the first frame of the ad (the impression), never at
   the tap, and a blocked storage still shows the pre-roll (in-memory cap: one gate per page view). */
(function (w, d) {
  if (w.JcxV) return;
  var html = d.documentElement, sc = d.currentScript, SRC = (sc && sc.src) || '', BASE = SRC.replace(/static\/jcx\/jcx-v\.js[^/]*$/, ''), VQ = SRC.replace(/^[^?]*/, '');
  var cur, one, playing, MP = '(\\?|#|$)', OQ = [];
  var SVG = function (p) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>'; }, ST = ' fill="none" stroke="currentColor" stroke-width="2"', SP = '<path fill="currentColor" d="M3 9v6h4l5 5V4L7 9z"/>';
  var I = { play: SVG('<path fill="currentColor" d="M8 5v14l11-7z"/>'), pause: SVG('<path fill="currentColor" d="M6 5h4v14H6zm8 0h4v14h-4z"/>'),
    on: SVG(SP + '<path d="M16 8.5a5 5 0 0 1 0 7M19 5.5a9 9 0 0 1 0 13"' + ST + '/>'), off: SVG(SP + '<path d="M16 9l6 6m0-6-6 6"' + ST + '/>'),
    ext: SVG('<path d="M14 4h6v6m0-6-9 9m7 1v6H4V6h6"' + ST + '/>'), x: SVG('<path d="M6 6l12 12M18 6 6 18"' + ST + '/>') };

  /* ---------- helpers ---------- */
  function H() { var j = w.JCX; try { j = j || w.parent.JCX; } catch (e) {} return j && j._h; }
  function E(t, c, a, x) {
    var e = d.createElement(t), k;
    if (c) e.className = c;
    for (k in a) if (a[k] != null) e.setAttribute(k, a[k]);
    if (x != null) e.textContent = x;
    return e;
  }
  function B(c, k, l, ic, x) { var b = E('button', c, { type: 'button', 'data-jcx-act': k, 'aria-label': l }, x); if (ic) b.insertAdjacentHTML('afterbegin', ic); return b; }
  function fmt(s, m) { return String(s || '').replace(/\{(\w)\}/g, function (a, k) { return m[k] != null ? m[k] : a; }); }
  function safe(u) { try { u = new URL(u, location.href); return /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; } }
  function now() { return Date.now() / 1e3 | 0; }
  function cnt(id, e) { var h = H(); if (h && id) h.cnt(id, e); }
  function err(id, why) { var h = H(); cnt(id, 11); if (h) h.emit('error', { id: id, why: why }); }
  function pick(l) {
    var t = 0, a = new Uint32Array(1), r, i;
    l.forEach(function (x) { t += x.w || 10; });
    crypto.getRandomValues(a); r = a[0] / 4294967296 * t;
    for (i = 0; i < l.length; i++) if ((r -= l[i].w || 10) < 0) return l[i];
    return l[0];
  }
  function rm() { return matchMedia('(prefers-reduced-motion: reduce)').matches || html.classList.contains('jv-ts-reduced'); }
  function load(u) { return new Promise(function (y, n) { var s = E('script', 0, { src: u }); s.onload = y; s.onerror = n; d.head.appendChild(s); }); }
  function roll(i) { return (i.o && i.o.roll) || 'pre'; }
  /* a button whose centre the page covers or leaves off the screen (a theme's fixed bar over a landscape phone, a
     stage taller than the window) moves to the other edge of the stage (A9) */
  function fit(b) { var q = b.getBoundingClientRect(), e = d.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); if (!e || !b.contains(e)) b.classList.add('jcx-vf'); }
  function key(f) { d.addEventListener('keydown', f, true); return function () { d.removeEventListener('keydown', f, true); }; }
  /* our buttons: the head-snippet click shield runs JCX._h.hooks; pages without the shield use a listener on our box */
  function act(a, e) { var g = a.closest('.jcx-vg,.jcx-v'), o = (g && g._jv) || cur, k = a.getAttribute('data-jcx-act'); if (o && o[k]) o[k](a, e); }
  function hook() { var h = H(); if (h) 'play reward skip snd visit vtog vopen vclose'.split(' ').forEach(function (k) { h.hooks[k] = act; }); }
  function own(g) { g.addEventListener('click', function (e) { var a = e.target.closest('[data-jcx-act]'); if (a && !d.getElementById('jcx-h')) act(a, e); }); }
  function css() {
    if (d.getElementById('jcx-vcss')) return;
    d.head.appendChild(E('style', 0, { id: 'jcx-vcss' }, '.jcx-vg,.jcx-v{position:relative;width:100%;height:100%;min-height:160px;background:#000;color:#fff;overflow:hidden;font:14px/1.3 system-ui,sans-serif;text-align:start}'
      + ':is(.jcx-vg,.jcx-v) video,.jcx-vi{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.jcx-vg video{object-fit:contain}'
      + ':is(.jcx-vg,.jcx-v) :is(button,a){font:inherit;color:#fff;cursor:pointer;border:1px solid #fff8;background:#000a;border-radius:4px;min-height:40px;min-width:40px;padding:6px 12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;box-sizing:border-box;text-decoration:none}'
      + ':is(.jcx-vg,.jcx-v) svg{width:20px;height:20px}.jcx-vb{position:absolute;inset:0;margin:auto;width:84px;height:84px;border-radius:50%!important}.jcx-vb svg{width:44px;height:44px}'
      + '.jcx-vr{position:absolute;inset-inline:12px;bottom:12px;margin:auto;width:fit-content}'
      + '.jcx-vt{position:absolute;inset-inline:0;top:0;display:flex;gap:8px;align-items:center;padding:8px;pointer-events:none;background:linear-gradient(#0008,#0000)}'
      + '.jcx-vl{background:#fc0;color:#000;font-weight:700;border-radius:3px;padding:1px 6px;font-size:12px}.jcx-vt>span+span:not(:empty){background:#000b;border-radius:3px;padding:1px 6px}'
      /* B7: the stage is its own stacking context and keeps at least 16:9 of the screen width; Skip and the sound button
         are 44 px touch targets; the sound button sits top-start under the label */
      + '.jcx-vg{isolation:isolate;min-height:max(160px,min(100%,56.25vw))}.jcx-vg>.jcx-vm{position:absolute;top:40px;inset-inline-start:8px;min-height:44px}'
      + '.jcx-vk{position:absolute;inset-inline-end:0;bottom:56px;min-width:120px!important;min-height:44px!important}.jcx-vk[aria-disabled=true]{opacity:.8}'
      + '.jcx-vg>.jcx-vf.jcx-vk{bottom:auto;top:40px}.jcx-vg>.jcx-vf.jcx-vm{top:auto;bottom:56px}'
      + '.jcx-vu{position:absolute;inset-inline-start:8px;bottom:12px;display:flex;gap:8px}.jcx-vp{position:absolute;inset-inline-start:0;bottom:0;height:4px;width:0;background:#fc0}'
      + '.jcx-vs{position:absolute;clip-path:inset(50%)}.jcx-vq{position:absolute;inset:0;display:grid;place-items:center;margin:0;padding:16px;text-align:center}'
      + '.jcx-vr2{margin-inline-start:8px;border:1px solid;border-radius:4px;background:none;color:inherit;font:inherit}'
      + '.jcx-v{height:auto;min-height:0;margin:auto;border-radius:var(--jcx-radius,6px)}.jcx-v .jcx-vl{position:absolute;top:8px;inset-inline-start:8px}.jcx-v .jcx-vu{top:8px;bottom:auto;inset-inline:auto 8px}'
      + '.jcx-vh{position:absolute;inset-inline:8px;bottom:8px;display:flex;flex-wrap:wrap;gap:6px;justify-content:end}'
      /* in a feed card the film starts at the card's top like the theme's own thumbnails (a flex card centred it 31 px
         lower, R4r2-08; the 'Ad' badge sits on it like on a U12 card); the Play / Sound buttons go to the bottom-start
         corner (smaller on phones), off the film's own top-end logo (Q3), and the link row keeps clear of them */
      + '.jcx-c>.jcx-v{margin:0 0 auto}.jcx-c .jcx-v .jcx-vu{top:auto;bottom:8px;inset-inline:8px auto}.jcx-c .jcx-vh{inset-inline-start:auto;max-width:calc(100% - 104px)}'
      + '@media(max-width:767px){.jcx-c .jcx-vu :is(button,a){min-width:32px;min-height:32px;padding:4px}.jcx-c .jcx-vh a{min-height:32px;padding:4px 8px;font-size:13px}.jcx-c .jcx-vh{max-width:calc(100% - 92px)}}'
      + '.jcx-vd{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;background:#000c}.jcx-vd>.jcx-v{width:auto;height:min(90vh,640px);aspect-ratio:9/16;max-width:100vw}'));
  }

  /* ---------- media: MP4 -> WebM -> HLS; DASH and VPAID never run ----------
     HLS (hls.js light where Media Source exists, else native) runs in jcx-a.js, loaded on demand like the VAST part
     (fix round 3: moved there so this file keeps its 7 KB with the P3-B / P3-E / VO-04 changes). The ad <video> has
     preload=metadata, and every stage drops its source at skip / end. */
  function A() { return w.JcxA ? Promise.resolve() : load(BASE + 'static/jcx/jcx-a.js' + VQ); }
  /* the first source of an ad this browser plays: [url, hls] or the reason ('dash' | 'media'). HLS needs Media Source
     or native HLS; WebM needs WebM (none on iPhones before iOS 17.4) */
  function choose(v, a) {
    var l = [a.src, a.webm, a.hls].filter(Boolean), i, u, t;
    for (i = 0; i < l.length; i++) {
      u = safe(l[i]); t = i ? '' : String(a.type || '').toLowerCase();
      if (!u || RegExp('\\.mpd' + MP, 'i').test(u) || /dash/.test(t)) continue;
      if (RegExp('\\.m3u8' + MP, 'i').test(u) || /mpegurl/.test(t)) { if (w.MediaSource || w.ManagedMediaSource || v.canPlayType('application/vnd.apple.mpegurl')) return [u, 1]; continue; }
      t = RegExp('\\.webm' + MP, 'i').test(u) ? 'video/webm' : RegExp('\\.(mp4|m4v)' + MP, 'i').test(u) ? 'video/mp4' : t || 'video/mp4';
      if (v.canPlayType(t)) return [u];
    }
    return l.some(function (u) { return /\.mpd/i.test(u); }) || /dash/i.test(a.type) ? 'dash' : 'media';
  }
  function src(v, a) { var c = choose(v, a); return c.big ? Promise.reject(c) : c[1] ? A().then(function () { return JcxA.hls(v, c[0], BASE); }) : (v.src = c[0], Promise.resolve()); }
  /* B2: a pre-roll this browser can play: VAST (its media files are matched later), or an own file / pod entry in a
     format it plays. An item it cannot play is never drawn (item 112, WebM only, on older iPhones). (choose() gives a
     string, which has .big, for a reason) */
  function playable(i) { var m = i.m || {}; return i.k == 'vast' || (m.pod || [m]).some(function (p) { return !choose(E('video'), p).big; }); }
  /* VAST: one deadline for the whole tag list, 3.5 s or what is left of the 6 s after the tap (B3). The VAST code is
     jcx-a.js, loaded on demand with this file's own version string (build.js writes both at once) */
  function vast(urls, n, lim) {
    lim = lim || 3500;
    return Promise.race([A().then(function () { return JcxA.vast(urls, n, lim, BASE); }),
      new Promise(function (y, n) { setTimeout(n, lim, 'slow'); })]);
  }
  /* one item -> its ad list (a pod of up to 2) */
  function list(it, DL) {
    var m = it.m || {}, o = it.o || {}, sk = o.bumper ? 0 : o.skip != null ? +o.skip : 5, mx = o.bumper ? 6 : o.max_s, n = Math.min(o.pod || 1, 2);
    if (it.k == 'vast') return vast(m.vast || [], n, DL && Math.max(100, Math.min(3500, DL - Date.now()))).then(function (l) { return l.map(function (a) { a.id = it.id; a.sk = sk; a.mx = mx; a.u = a.u || it.u2; return a; }); });
    return Promise.resolve((m.pod || [m]).slice(0, 2).map(function (p) { return { id: it.id, src: p.src, webm: p.webm, hls: p.hls, type: p.type, sk: p.skip != null ? +p.skip : sk, mx: mx, u: it.u2 }; }));
  }
  /* ---------- one ad in a stage g; resolves 'complete' | 'skip' | error reason ---------- */
  /* ctx (one per break): b = non-skippable seconds left, m = muted, DL = the deadline for the first frame (6 s after the
     tap, B3), more = another candidate could still be tried, imp = the break had its impression, on1 = called at it */
  function play1(g, a, i, n, S, ctx) {
    return new Promise(function (res) {
      var v = E('video', 0, { playsinline: '', preload: 'metadata', 'aria-hidden': 'true' }), top = E('div', 'jcx-vt'), tm = E('span'),
        live = E('span', 'jcx-vs', { 'aria-live': 'polite' }), tools = E('div', 'jcx-vu'), fill = E('i', 'jcx-vp'), sb = B('jcx-vm', 'snd'),
        u = safe(a.u), pt = n > 1 ? fmt(S.pod, { i: i + 1, n: n }) : '', at, sk, q = 0, done, hl, t1, t2, off, imp, ns, sm = g.clientWidth < 420;
      v.muted = !!ctx.m; v.playsInline = true;
      /* the sound button sits top-start under the label; while the ad is muted it says "Turn sound on" (B6) */
      g.replaceChildren(v, top, tools, fill, live, sb);
      top.append(E('span', 'jcx-vl', 0, S.label), tm);
      live.textContent = pt || S.label;
      function snd() { sb.innerHTML = v.muted ? I.off : I.on; if (v.muted) sb.append(S.sound_on); sb.setAttribute('aria-label', v.muted ? S.sound_on : S.sound_off); }
      snd(); fit(sb);
      if (u) tools.appendChild(B('jcx-vc', 'visit', sm ? S.visit : null, I.ext, sm ? S.visit_short : S.visit));
      function tr(k, x) { try { if (a.tr) a.tr[k](x); } catch (e) {} }
      function fin(r) {
        if (done) return;
        done = 1; clearTimeout(t1); clearTimeout(t2); off();
        if (r == 'complete') { cnt(a.id, 9); tr('complete'); } else if (r == 'skip') { cnt(a.id, 10); tr('skip'); } else { err(a.id, r); tr('error', { ERRORCODE: 405 }); }
        if (ns) ctx.b -= Math.min(v.currentTime || 0, 15);
        ctx.m = v.muted;
        try { v.pause(); if (hl) hl.destroy(); v.removeAttribute('src'); v.load(); } catch (e) {}
        res(r);
      }
      function stall() { clearTimeout(t1); t1 = setTimeout(fin, 6e3, 'stall'); }
      /* a refused play (muted autoplay refused, or a refusal while muted) waits on the big Play button; its tap starts
         the ad inside the gesture (B6), with a fresh 6 s for the first frame */
      function tap() {
        if (done) return;
        clearTimeout(t1);
        var b = B('jcx-vb', 'play', S.play, I.play);
        g.appendChild(b);
        g._jv.play = function () { b.remove(); stall(); v.play().catch(function () { fin('media'); }); };
      }
      /* seconds until Skip is enabled; -1 = no Skip button (the ad ends by itself) */
      /* own files: the item's Skip setting; VAST: the tag's skipoffset (ours wins when shorter), none = no Skip */
      function lim(dd) {
        var s = a.rw ? 0 : a.tr ? (a.vs != null && a.vs >= 0 ? (a.sk > 0 ? Math.min(a.vs, a.sk) : a.vs) : null) : a.sk > 0 ? a.sk : null;
        if (dd > 30 && (s == null || s > 5)) s = 5;
        if (s == null) { ns = 1; s = Math.min(a.mx || 15, 15, Math.max(ctx.b, 1)); if (s >= dd - .25) s = -1; }
        return s;
      }
      g._jv = {
        snd: function () { v.muted = !v.muted; snd(); tr('setMuted', v.muted); },
        skip: function () { if (sk && sk.getAttribute('aria-disabled') == 'false') fin('skip'); },
        visit: function () { w.open(u, '_blank', 'noopener'); cnt(a.id, 3); tr('click'); v.pause(); }
      };
      off = key(function (e) { if (e.key == 'Escape' && sk && sk.getAttribute('aria-disabled') == 'false') { e.preventDefault(); fin('skip'); } });
      v.onerror = function () { fin('media'); };
      /* a stall in the middle of the ad gets 6 s; before the first frame the first-frame deadline rules */
      v.onwaiting = function () { if (imp) stall(); };
      /* the impression: the first frame. The break's cap is used here, never at the tap (P1-04) */
      v.onplaying = function () { clearTimeout(t1); if (!imp) { imp = 1; cnt(a.id, 1); tr('trackImpression'); if (!ctx.imp) { ctx.imp = 1; ctx.on1 && ctx.on1(); } } };
      v.onended = function () { fin('complete'); };
      v.ontimeupdate = function () {
        var dd = v.duration, c = v.currentTime, p, l, wt;
        if (!(dd > 0 && dd < 1e5)) return;
        if (at == null) {
          at = lim(dd);
          if (at >= 0) { g.appendChild(sk = B('jcx-vk', 'skip')); sk.setAttribute('aria-disabled', 'true'); fit(sk); }
          t2 = setTimeout(fin, (Math.min(dd, a.rw ? 30 : dd) + 5) * 1e3, 'slow');
        }
        tr('setProgress', c);
        p = c / dd;
        if (c > 0 && !q) { q = 1; cnt(a.id, 5); }
        while (q && q < 4 && p >= q / 4) cnt(a.id, 5 + q++);
        fill.style.width = Math.min(100, p * 100) + '%';
        l = Math.max(0, Math.ceil((a.rw ? Math.min(dd, 30) : dd) - c));
        tm.textContent = (pt ? pt + ' · ' : '') + (l / 60 | 0) + ':' + ('0' + l % 60).slice(-2);
        if (sk) {
          wt = Math.ceil(at - c);
          if (wt > 0) sk.textContent = fmt(S.skip_in, { n: wt });
          else if (sk.getAttribute('aria-disabled') == 'true') { sk.setAttribute('aria-disabled', 'false'); sk.textContent = live.textContent = S.skip; }
        }
        if (a.rw && c >= 30) fin('complete');
      };
      d.addEventListener('visibilitychange', function () { if (!d.hidden && !done && v.paused) v.play().catch(function () {}); });
      /* the first frame must come by the deadline: 3.5 s when another candidate can still be tried, else all that is
         left of the 6 s (the hls.js download is inside it); after the break's impression a later ad gets 6 s */
      t1 = setTimeout(fin, ctx.imp ? 6e3 : Math.max(0, Math.min(ctx.DL - Date.now(), ctx.more ? 3500 : 6e3)), 'stall');
      src(v, a).then(function (x) { hl = x; return v.play(); }).catch(function (e) {
        if (done) return;
        if (e && e.name == 'NotAllowedError') { if (v.muted) return tap(); v.muted = true; snd(); return v.play().catch(tap); }
        fin(typeof e == 'string' ? e : 'media');
      });
    });
  }
  /* an ad break: up to 2 ads, non-skippable time <= 30 s per break; o = ctx extras (DL, more, on1) */
  function pod(g, L, S, snd, o) {
    var ctx = Object.assign({ b: 30, m: !snd, DL: Date.now() + 6e3 }, o), i = 0;
    return (function nx(r) {
      if (i >= L.length) return Promise.resolve(r);
      var a = L[i++];
      if (a.cp) JcxA.comp(a.cp);
      return play1(g, a, i - 1, L.length, S, ctx).then(nx);
    })();
  }
  function stage(S, dir) { var g = E('div', 'jcx-vg', { role: 'region', 'aria-label': S.region, tabindex: '-1', dir: dir || null }); css(); own(g); return g; }

  /* ---------- the pre-roll gate (7.2) ---------- */
  /* c = the playable pre-roll candidates. One is drawn (weighted) and its ad list is fetched at once; an item that fails
     before the tap, or before its first frame after the tap, hands over to the next one: before the tap every
     candidate whose ad list fails hands over, and after the tap as many as the 6 s budget allows (a next one only while >= 1.2 s are left; VO-01: three remote
     items that failed at once used to end the chain with own files untried). B4 / P3-E: the hosts of an ad list that
     stalled or hung (its item's hosts and the resolved media files' hosts) are marked; an item whose every host is
     marked is skipped, and the ads of a resolved list (a pod, a VAST) whose file is on a marked host are left out, so a
     pod / VAST plays its other ad at once or hands over at once (a hanging zencdn inside pod 119 / VAST 118 used up the
     6 s and the own file was never tried). On a slow line (2g / 3g, Save-Data) the own files are drawn first (VO-04). While the gate is on, html.jcx-vgon holds
     the fixed units (sticky bar, corner, notification box: jcx.css, jcx-l.js) for at most 60 s, a U16 slot video waits
     (OQ) and 'jcx:gate' {on} is emitted at the start and the end (B7). */
  function run(box, c, rw, S, D, poster, fin, pvm) {
    var h = H(), g = stage(S, D.dir), st = 0, img, hr = rw && rw.o && rw.o.reward_h || 1, tried = [], pre, ads = Promise.resolve([]), DL, hb = [],
      cn = navigator.connection || {}, sl = cn.saveData || /2g|3g/.test(cn.effectiveType), pb = B('jcx-vb', 'play', S.play, I.play), r, vt, vb;
    /* the reward text with its plural form for h != 1 (i18n F6: "{h} hour" was printed for any number) */
    function rt(k) { return fmt(hr != 1 && S[k + '_n'] || S[k], { h: hr }); }
    function rb(x) { return B(x, 'reward', null, null, rt('reward')); }
    function vg(on) { html.classList.toggle('jcx-vgon', !!on); h.emit('gate', { on: on }); on || OQ.splice(0).forEach(function (n) { n._r && !n._u && n._go(); }); }
    function end(r) {
      if (g._e) return;
      g._e = 1; cur = g._jv = 0;
      vg(0);
      [].forEach.call(d.querySelectorAll('.jcx-vr2'), function (x) { x.remove(); });
      g.replaceChildren();
      fin(r);
    }
    /* the host of one source (an ad, a pod entry, a VAST tag URL); hs = the media hosts of an item (a VAST item: its
       tag's host); hb = remote hosts whose ad list just stalled or hung; ml = the media hosts of the list being played */
    function hn(p) { try { return new URL(p.src || p.hls || p.webm || p, location.href).host; } catch (e) {} }
    function hs(i) { var m = i.m || {}; return (m.vast || m.pod || [m]).map(hn); }
    function rest() { return c.filter(function (i) { return tried.indexOf(i) < 0 && hs(i).some(function (x) { return hb.indexOf(x) < 0; }); }); }
    function next() {
      var l = rest(), o = l.filter(function (i) { return i.k != 'vast' && hs(i)[0] == location.host; }), it, a;
      if (!l.length || DL && DL - Date.now() < 1200) return pre = 0;
      tried.push(it = pre = pick(sl && o[0] ? o : l));
      a = ads = list(it, DL);
      a.catch(function (why) { if (!st && a == ads) { err(it.id, why); next() || rw || end('error'); } });
      return it;
    }
    function go(snd) {
      if (st) return;
      st = 1; DL = Date.now() + 6e3;
      try { g.focus({ preventScroll: true }); } catch (e) {}
      (function at() {
        var it = pre, ml = [];
        ads.then(function (L) { L = L.filter(function (a) { return hb.indexOf(hn(a)) < 0; }); ml = L.map(hn); return L[0] ? pod(g, L, S, snd, { DL: DL, more: rest()[0], on1: function () { g._i = 1; pvm || h.shown(it); } }) : 'slow'; }, function (why) { if (it) err(it.id, why); return why; })
          .then(function (r) {
            if (r == 'stall' || r == 'slow') hs(it).concat(ml).forEach(function (x) { if (x && x != location.host && hb.indexOf(x) < 0) hb.push(x); });
            if (!g._i && DL - Date.now() >= 1200 && next()) return at();
            end('done');
          });
      })();
    }
    next();
    if (poster) { img = E('img', 'jcx-vi', { alt: '', src: poster, decoding: 'async' }); img.onerror = function () { this.remove(); }; g.appendChild(img); }
    g.appendChild(pb);
    if (rw) {
      g.appendChild(rb('jcx-vr'));
      img = d.querySelector('.jcx-s[data-jcx="P03"] .jcx-l');
      if (img) img.appendChild(rb('jcx-vr2'));
    }
    box.replaceChildren(g);
    /* P3-B (A9): a stage that ends below the window (velvet's tall header on a phone in landscape) gets Play in the
       middle of its visible part, when that part is at least 100 px high */
    r = g.getBoundingClientRect(); vt = Math.max(r.top, 0); vb = innerHeight - vt;
    if (r.bottom > innerHeight && vb >= 100) pb.style.cssText = 'margin:0 auto;bottom:auto;top:' + (vt - r.top + (vb - 84) / 2) + 'px';
    vg(1); setTimeout(vg, 6e4, 0);
    g._jv = cur = {
      play: function () { go(1); },
      reward: function () {
        if (st) return;
        st = 1;
        try { g.focus({ preventScroll: true }); } catch (e) {}
        list(rw).then(function (L) { L = L.slice(0, 1); L[0].rw = 1; return pod(g, L, S, 1); }).then(function (r) {
          var j = w.JCX;
          if (r == 'complete') {
            /* P3-A: the slot videos of this page stop, and a U16 box counts as paused by the visitor (_u), so neither its
               view observer nor the end of this gate (OQ) starts it again */
            if (j && !pvm) j.free(hr), d.querySelectorAll(':is(.jcx-s,.jcx-c) :is(.jcx-v,video)').forEach(function (v) { v._u = 1; v.pause && v.pause(); });
            g.replaceChildren(E('p', 'jcx-vq', { role: 'status' }, rt('reward_done')));
            return setTimeout(end, 2500, 'reward');
          }
          st = 0; pre ? go(1) : end('done');
        }, function () { end('error'); });
      }
    };
    if (pre && pre.o && pre.o.start == 'muted' && !rm()) go(0);
  }
  function conf() { try { return JSON.parse(d.getElementById('jcx-v').textContent) || {}; } catch (e) { return {}; } }
  /* T0 = the player's start: one budget of 6 s from it until the stage shows or the video starts (B3) */
  function gate(box, data, done, T0) {
    var D = conf(), S = D.s || {}, hs = d.getElementById('jcx-h'), ds = (hs && hs.dataset) || {}, api = D.api || (ds.b || '/') + 'index.php/jcx/',
      pvm = /(^|;\s*)jcxh=1/.test(d.cookie), vd = (data && data.vod_data) || {}, once, to;
    function fin(r) { if (!once) { once = 1; clearTimeout(to); done(r); } }
    to = setTimeout(fin, Math.max(500, (T0 || Date.now()) + 6e3 - Date.now()), 'slow');
    if (one) return fin('again');
    one = 1;
    /* jcx.js is deferred: wait for window.JCX (until the budget ends), then its applied c.json (c-play.json, no-cache) */
    new Promise(function (y, n) { (function f() { if (w.JCX) return JCX.cfg().then(y, n); if (once) return n('rt'); setTimeout(f, 50); })(); }).then(function (cfg) {
      var h = H();
      hook();
      if (!h || html.classList.contains('jcx-free') || h.ST.free > now()) return fin('free');
      /* the implicit 30-minute mark of older versions ('v') goes with the next save (UD5) */
      delete h.ST.v;
      return Promise.resolve(pvm && fetch(api + 'pv?t=play&th=' + encodeURIComponent(ds.th || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () {})).then(function (j) {
        var all, pre, rw, c, k;
        pvm = j && j.v && (j.it || []).filter(function (i) { return i.u == 'U15'; });
        if (pvm && !D.s) S = j.vs || {};
        if (pvm && pvm.length) {
          /* admin preview: the item chosen in the panel's "Play with" plays in the gate; caps and rules are ignored */
          all = pvm; k = +h.ss('jcx.pvv');
          c = all.filter(function (i) { return i.id == k; })[0] || all[0];
          if (roll(c) == 'reward') { rw = c; pre = all.filter(function (i) { return roll(i) == 'pre'; })[0]; } else pre = c;
          c = pre ? [pre] : [];
          d.addEventListener('jcx:pvplay', function () { location.reload(); });
        } else if (pvm) return fin('none'); /* the preview of an ad that is not a video ad: never the live pre-roll items (R4r3-03) */
        else {
          pvm = 0;
          /* every ON pre-roll of c-play.json that this browser plays and whose rules pass (#jcx-v's id list is only a
             hint, P3-03). Caps: only the item's own (UD5); with storage blocked it is judged without its cap (the
             in-memory cap: one gate per page view), so the in-app browsers get the pre-roll too (P1-03). The rewarded
             item needs storage (its free time is stored) */
          all = (cfg.it || []).filter(function (i) { return i.u == 'U15' && playable(i) && h.ok(Object.assign({}, i, h.SOK || { cap: 0 })); });
          c = all.filter(function (i) { return roll(i) == 'pre'; });
          rw = h.SOK && c.length && pick(all.filter(function (i) { return roll(i) == 'reward'; }));
        }
        if (once || (!c.length && !rw)) return fin('none');
        clearTimeout(to);
        run(box, c, rw, S, D, safe(vd.vod_pic || vd.vod_poster || ''), fin, pvm);
      });
    }).catch(function () { fin('error'); });
  }
  /* ---------- out-stream / in-feed / vertical / shoppable (U16) ---------- */
  function vid(it, S, n, dlg) {
    var m = it.m || {}, o = it.o || {}, v = E('video', 0, { playsinline: '', loop: '', preload: 'none', poster: safe(m.poster) || null, 'aria-hidden': 'true' }),
      tools = E('div', 'jcx-vu'), row = E('div', 'jcx-vh'), u = safe(it.u2), tg = B(0, 'vtog'), sb = B(0, 'snd'), q = 0, last = 0, ld;
    v.muted = true;
    function sync() {
      tg.innerHTML = v.paused ? I.play : I.pause; tg.setAttribute('aria-label', v.paused ? S.pl : S.pa);
      sb.innerHTML = v.muted ? I.off : I.on; sb.setAttribute('aria-label', v.muted ? S.so : S.sf);
    }
    v.onplay = v.onpause = v.onvolumechange = sync;
    v.ontimeupdate = function () {
      var p = v.currentTime / v.duration;
      if (!(p >= 0)) return;
      if (!q) { q = 1; cnt(it.id, 5); }
      while (q < 4 && p >= q / 4) cnt(it.id, 5 + q++);
      if (q == 4 && v.currentTime < last - 1) { q = 5; cnt(it.id, 9); }
      last = v.currentTime;
    };
    n._v = v;
    n._go = function () {
      if (playing && playing != v) playing.pause();
      playing = v;
      (ld = ld || src(v, it.m ? { src: m.src, webm: m.webm, hls: m.hls, type: m.type } : {})).then(function () { return v.play(); }).catch(function (e) { if (typeof e == 'string') err(it.id, e); });
    };
    n._jv = {
      vtog: function () { if (v.paused) { n._u = 0; n._go(); } else { n._u = 1; v.pause(); } },
      snd: function () { v.muted = !v.muted; sync(); }
    };
    n.appendChild(v);
    /* the slot / feed cell already carries the "Ad" label; the dialog needs its own */
    if (dlg) n.appendChild(E('span', 'jcx-vl', 0, S.l));
    tools.append(tg, sb); n.appendChild(tools);
    if (dlg) tools.appendChild(dlg);
    if (u) row.appendChild(E('a', 'jcx-vc', { href: u, rel: 'sponsored noopener', target: '_blank' }, it.cta || it.ti || new URL(u).hostname));
    (o.buttons || []).slice(0, 3).forEach(function (b) { var x = safe(b.url); if (x) row.appendChild(E('a', 0, { href: x, rel: 'sponsored noopener', target: '_blank' }, b.label)); });
    if (row.firstChild) n.appendChild(row);
    sync();
    return v;
  }
  function outstream(n, it) {
    var h = H() || {}, S = h.S || {}, m = it.m || {}, z = it.sz || [], W = (h.PH && z[2]) || z[0] || 16, Hh = (h.PH && z[3]) || z[1] || 9,
      vert = (it.o && it.o.vertical) || Hh > W, cell = n.closest('.jcx-c'), io;
    css(); hook(); own(n);
    n.className = 'jcx-v';
    n.setAttribute('role', 'region'); n.setAttribute('aria-label', S.r);
    /* += : keeps the first-paint clip of jcx.js (= wiped it and the poster became the LCP) */
    n.style.cssText += ';aspect-ratio:' + (cell ? '16/9' : W + '/' + Hh) + (cell ? '' : ';max-width:' + W + 'px');
    if (vert) {
      /* 9:16: the slot shows the poster; a tap opens the vertical player in a dialog (never sticky, never on its own) */
      if (m.poster) n.appendChild(E('img', 'jcx-vi', { alt: '', src: safe(m.poster), loading: 'lazy' }));
      n.appendChild(B('jcx-vb', 'vopen', S.pl, I.play));
      n._jv = { vopen: function (a) {
        var bk = E('div', 'jcx-vd', { role: 'dialog', 'aria-modal': 'true', 'aria-label': S.r }), bx = E('div', 'jcx-v'), x = B(0, 'vclose', S.x, I.x), off, v;
        function close() { if (v) v.pause(); bk.remove(); off(); a.focus(); }
        v = vid(it, S, bx, x);
        bx._jv.vclose = close;
        bk.addEventListener('click', function (e) { if (e.target == bk) close(); });
        off = key(function (e) { if (e.key == 'Escape') close(); });
        own(bk); bk.appendChild(bx); d.body.appendChild(bk);
        x.focus(); bx._go();
      } };
      return;
    }
    vid(it, S, n);
    /* muted; plays only while >= 50 % visible, pauses out of view; reduced motion: poster + Play. While the pre-roll
       gate runs it waits (OQ) and starts at the gate's end if still in view: it no longer takes the bandwidth of the
       pre-roll on a slow line (VO-04: 23 MB oceans.mp4 next to a 3G pre-roll) */
    io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (n._r = e.intersectionRatio >= .5) { if (!n._u && !rm()) html.classList.contains('jcx-vgon') ? OQ.push(n) : n._go(); } else n._v.pause(); });
    }, { threshold: [0, .5] });
    io.observe(n);
  }

  w.JcxV = Object.freeze({
    v: '1.0.0', gate: gate, outstream: outstream,
    /* the tag check (no page uses it today; the admin's Test tag button has its own) runs in jcx-a.js (fix round 3) */
    test: function (urls) { return A().then(function () { return JcxA.test([].concat(urls), BASE); }); }
  });
})(window, document);
