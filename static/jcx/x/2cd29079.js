/* JavCMS example X34: the soft prompt a web-push network shows before it asks the browser, in the site's own look
 * (site name + icon, the theme's colours through the --jcx-* tokens of jcx.css).
 * It never asks the browser for anything: no Notification / Push API, no service worker, no network request.
 * Texts: the visitor's language through the ad engine's string helper (window.JcxT, catalog keys ui.jcx_push_*; the build
 * prints only the keys it finds written out in this file: jcx_push_title, jcx_push_text, jcx_push_later, jcx_push_allow,
 * jcx_push_demo_title, jcx_push_demo_text),
 * English when the engine has none.
 * Round 3 (B7, R4r3-10): the card is PLACED ONCE and then only shown or hidden, so its fixed box never moves (no
 * layout shift). Before its first show it measures the screen: the theme's fixed and sticky bars (header, bottom tab
 * bar), the theme switcher, the cookie bar, the video player, every frame / video on the page, the ad engine's units
 * (sticky bar, corner box, side rails, also while they wait hidden; 26 px kept free around them and their close
 * buttons) and the admin preview panel. Once it has been shown, the engine's units wait for it (it is a shown fixed unit
 * for the engine), so from then on only the units a visitor sees count. It takes the first free corner: bottom-start, bottom-end (phones: only these
 * two, in the lower half of the screen, never the centre), then top-end, top-start on computers. While something comes
 * into its place (a bar, the player while scrolling) it hides, and shows again in the same place when the place is free.
 * A new place is chosen only after the screen width changes (rotation), while hidden. Its box carries data-jcx-fx, so
 * the ad engine counts it as a shown fixed unit (on phones one at a time); on phones it does not appear while one of
 * the engine's fixed units is on the screen. z-index 85: under the theme switcher (90). It never becomes the page's
 * largest contentful paint: its first paint is a 2 px strip that then opens (clip-path).
 * It also hides while a theme menu or dialog is open (html.jcx-hold, or an aria-modal box on screen).
 * "Not now" / "Close" / Esc hide it for the rest of the browser session. */
(function () {
  'use strict';
  var W = window, D = document, KEY = 'jcx-x34-closed';
  try { if (W.sessionStorage && sessionStorage.getItem(KEY)) return; } catch (e) { }
  /* the engine's string helper JcxT(key, fallback) (CONTRACT-3 E8); in a box frame the parent page's one */
  function T(k, en) {
    var f = W.JcxT;
    try { if (typeof f !== 'function' && W.parent !== W) f = W.parent.JcxT; } catch (e) { }
    try { var s = typeof f === 'function' ? f(k, en) : ''; return typeof s === 'string' && s && s !== k ? s : en; } catch (e) { return en; }
  }
  function F(w, sz, lh) { return 'font-family:inherit;font-weight:' + w + ';font-size:' + sz + 'px;line-height:' + lh + ';'; }
  function el(tag, css, text) { var e = D.createElement(tag); if (css) e.style.cssText = css; if (text) e.textContent = text; return e; }
  function siteName() {
    var m = D.querySelector('meta[property="og:site_name"]');
    var n = m && m.getAttribute('content');
    return (n && n.trim()) || location.hostname.replace(/^www\./, '') || 'This site';
  }
  function siteIcon() {
    var links = [].slice.call(D.querySelectorAll('link[rel~="icon"],link[rel="apple-touch-icon"]'));
    var best = links.filter(function (l) { return /\.(svg|png)(\?|$)/i.test(l.getAttribute('href') || ''); })[0] || links[0];
    return best ? best.href : '';
  }
  function lum(c) {
    var m = String(c).match(/[\d.]+/g);
    if (!m || m.length < 3) return 0;
    var v = m.slice(0, 3).map(function (x) { x = x / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function hit(a, b) { return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top; }
  function grow(r, g) { return { left: r.left - g, right: r.right + g, top: r.top - g, bottom: r.bottom + g }; }
  var GAP = 12, UGAP = 26, EDGE = 32;
  // boxes that must stay free even while hidden (the engine's units wait with visibility:hidden, then appear in place)
  var UNITS = '.jcx-k,.jcx-p,.jcx-r,.jcx-m';
  var KEEP = '.jcx-pp,.jv-ts-btn,[class*="jv-ts-"],#jv-cookie,.MacPlayer';

  function run() {
    var name = siteName(), icon = siteIcon(), shown = false, ever = false, free = 0, timer = 0, q = 0, done = false, misses = 0, last = 0;
    var P = null; // the one place: {x, y, w, h, side, b (px from the bottom edge), V (screen width it was made for)}
    // in the admin preview the pasted code runs in a sealed full-screen frame that cannot see the page: take the top-end corner
    var framed = false;
    try { framed = W.parent !== W && !W.frameElement; } catch (e) { framed = true; }
    var tTitle = T('jcx_push_title', 'Get a note when new videos are added?');
    // transition / animation none: a theme rule such as `div{transition:all .3s}` would slide the box in from where it was measured
    var card = el('div', 'position:fixed;z-index:85;box-sizing:border-box;display:none;width:340px;transition:none;animation:none;' +
      'padding:14px;border-radius:var(--jcx-radius,12px);background:var(--jcx-bg,#1d1d23);color:var(--jcx-fg,#f4f4f6);' +
      'box-shadow:0 0 0 1px var(--jcx-line,rgba(255,255,255,.14)),0 12px 32px -8px rgba(0,0,0,.45);' + F(400, 14, 1.35) + 'text-align:start');
    card.className = 'jcx-x34';
    card.setAttribute('role', 'complementary');
    card.setAttribute('aria-label', name + ': ' + tTitle);

    var row = el('div', 'display:flex;gap:12px;align-items:flex-start');
    var tile = el('span', 'flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:10px;overflow:hidden;' +
      'background:var(--jcx-accent,#ffb347);color:var(--jcx-bg,#1d1d23);' + F(700, 18, 1));
    tile.setAttribute('aria-hidden', 'true');
    if (icon) {
      var img = el('img', 'display:block;width:28px;height:28px;object-fit:contain');
      img.alt = ''; img.src = icon;
      img.onerror = function () { img.remove(); tile.textContent = name.charAt(0).toUpperCase(); };
      tile.style.background = 'color-mix(in srgb,var(--jcx-fg,#fff) 10%,transparent)';
      tile.appendChild(img);
    } else { tile.textContent = name.charAt(0).toUpperCase(); }
    row.appendChild(tile);

    var col = el('div', 'flex:1;min-width:0');
    col.appendChild(el('span', 'display:block;' + F(700, 12, 1.3) + 'opacity:.72;white-space:nowrap;overflow:hidden;text-overflow:ellipsis', name));
    var title = el('strong', 'display:block;margin-top:2px;' + F(700, 15, 1.3), tTitle);
    var msg = el('p', 'margin:3px 0 0;' + F(400, 13, 1.35) + 'opacity:.8', T('jcx_push_text', 'You can turn it off at any time.'));
    col.appendChild(title); col.appendChild(msg); row.appendChild(col); card.appendChild(row);

    var btn = 'box-sizing:border-box;min-height:36px;padding:0 16px;border:0;border-radius:999px;' + F(700, 14, 1) + 'cursor:pointer;';
    var bar = el('div', 'display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:10px');
    var later = el('button', btn + 'background:transparent;color:inherit;box-shadow:inset 0 0 0 1px var(--jcx-line,rgba(255,255,255,.3))', T('jcx_push_later', 'Not now'));
    var allow = el('button', btn + 'background:var(--jcx-accent,#ffb347);color:#111', T('jcx_push_allow', 'Allow'));
    later.type = allow.type = 'button';
    bar.appendChild(later); bar.appendChild(allow); card.appendChild(bar);
    var ring = el('style');
    ring.textContent = '.jcx-x34 button:focus-visible{outline:2px solid currentColor;outline-offset:2px}';
    card.appendChild(ring);

    function close() {
      try { if (W.sessionStorage) sessionStorage.setItem(KEY, '1'); } catch (e) { }
      clearInterval(timer);
      D.removeEventListener('keydown', onKey);
      W.removeEventListener('resize', sch);
      W.removeEventListener('scroll', sch);
      card.remove();
    }
    function onKey(e) { if (e.key === 'Escape' && shown) close(); }
    later.addEventListener('click', close);
    allow.addEventListener('click', function () {
      done = true;
      title.textContent = T('jcx_push_demo_title', 'Nothing was asked');
      msg.textContent = T('jcx_push_demo_text', 'A push network would ask your browser here.');
      msg.style.display = '';
      // the card keeps its corner: it is anchored at its bottom (or top) edge, a text change only changes its height
      allow.remove(); later.textContent = T('template_ui_close', 'Close'); sch();
    });

    /* ---- measuring ---- */
    function vw() { return D.documentElement.clientWidth || W.innerWidth; }
    function vh() { return W.innerHeight; }
    /* computed position per element, cached for 5 s (renewed with the fixed list): the sampling below meets the same
       ancestors again and again, and getComputedStyle for each of them made the first placement a long task on phones */
    var posCache = new WeakMap();
    function isFixed(n) {
      var v = posCache.get(n);
      if (v === undefined) { var p = getComputedStyle(n).position; v = p === 'fixed' || p === 'sticky'; posCache.set(n, v); }
      return v;
    }
    function fixedOrSticky(n) {
      for (var i = 0; n && n !== D.body && n !== D.documentElement && i < 12; n = n.parentElement, i++) {
        if (isFixed(n)) return n;
      }
      return null;
    }
    function boxOf(n) { var r = n.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(n).display !== 'none' ? r : null; }
    /* KEEP boxes + 12 px; the engine's units (with their close buttons, which stand out above the box) + 26 px.
       waiting = also the units that wait hidden: they count when the place is chosen (they appear in place later); once
       the card has been shown they wait for it (it is a shown fixed unit for the engine), so then only shown units count */
    function keepRects(waiting) {
      var out = [];
      [].forEach.call(D.querySelectorAll(KEEP), function (n) {
        if (card.contains(n)) return;
        var r = boxOf(n);
        if (r) out.push(grow(r, GAP));
      });
      [].forEach.call(D.querySelectorAll(UNITS), function (n) {
        var r = boxOf(n);
        if (!r || (!waiting && (getComputedStyle(n).visibility === 'hidden' || +getComputedStyle(n).opacity < 0.05))) return;
        var u = { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
        [].forEach.call(n.querySelectorAll('.jcx-x'), function (x) {
          var b = boxOf(x);
          if (b) { u.left = Math.min(u.left, b.left); u.right = Math.max(u.right, b.right); u.top = Math.min(u.top, b.top); u.bottom = Math.max(u.bottom, b.bottom); }
        });
        out.push(grow(u, UGAP));
      });
      return out;
    }
    /* the page's own fixed / sticky boxes (header, tab bar, back-to-top, chat buttons ...): found by a walk of the top 6
       levels under <body>, again every 5 s (new ones), so a round button is never missed between two sample points */
    var fixedList = [], fixedAt = 0;
    /* waiting = also the small ones that are hidden now (a back-to-top or chat button fades in after a scroll): they count
       when the place is chosen, so the card does not sit where such a button appears later */
    function fixedBoxes(waiting) {
      if (Date.now() - fixedAt > 5000) {
        fixedAt = Date.now(); fixedList = []; posCache = new WeakMap();
        (function walk(n, depth) {
          for (var c = n.firstElementChild; c; c = c.nextElementSibling) {
            if (c === card || /^(SCRIPT|STYLE|LINK|META|SOURCE|BR|TEMPLATE|NOSCRIPT)$/.test(c.tagName)) continue;
            if (isFixed(c)) { fixedList.push(c); continue; }
            // fixed bars and buttons sit near the top of the tree: no need to look inside lists, links, text or media
            if (depth < 4 && !/^(UL|OL|P|A|SPAN|H[1-6]|FORM|TABLE|VIDEO|IFRAME|IMG|PICTURE|SVG|svg|BUTTON|LABEL|INPUT|SELECT|TEXTAREA)$/.test(c.tagName)) walk(c, depth + 1);
          }
        })(D.body, 0);
      }
      var out = [], V = vw(), H = vh();
      fixedList.forEach(function (n) {
        if (!n.isConnected) return;
        var s = getComputedStyle(n), r = n.getBoundingClientRect();
        if (s.display === 'none' || !r.width || !r.height) return;
        var off = s.visibility === 'hidden' || +s.opacity < 0.05;
        if (off && !(waiting && r.width * r.height < V * H * 0.1)) return;
        // a hidden button often slides in by a few px while it fades in: keep 24 px more around it
        if (r.width * r.height < V * H * 0.9) out.push(grow(r, off ? GAP + 24 : GAP));
      });
      return out;
    }
    /* what is in the way of rectangle r: fixed / sticky boxes (the walk above + 5 hit tests over r for deeper ones),
       every frame / video / embed and the player (read directly: a few elements, no hit testing) + the KEEP boxes.
       A box as large as the screen is a page background, not a bar. sample = also the frames / videos; probe = also the
       5 hit tests (only after a scroll / resize: a full 40 px grid of hit tests was a 70 ms task on phones). */
    var MEDIA = 'iframe,video,embed,object,.MacPlayer';
    function blockers(r, keep, waiting, sample, probe) {
      var out = keep.concat(fixedBoxes(waiting)), V = vw(), H = vh();
      if (sample) {
        [].forEach.call(D.querySelectorAll(MEDIA), function (n) {
          if (card.contains(n)) return;
          var br = n.getBoundingClientRect();
          if (br.width > 0 && br.height > 0 && br.bottom > 0 && br.top < H && br.width * br.height < V * H * 0.9 && hit(r, grow(br, GAP)) && getComputedStyle(n).visibility !== 'hidden') out.push(grow(br, GAP));
        });
        var g = grow(r, GAP), seen = new Set();
        if (probe !== false) [[g.left + 2, g.top + 2], [g.right - 2, g.top + 2], [g.left + 2, g.bottom - 2], [g.right - 2, g.bottom - 2], [(g.left + g.right) / 2, (g.top + g.bottom) / 2]].forEach(function (pt) {
          var px = pt[0], py = pt[1];
          if (px < 0 || py < 0 || px >= V || py >= H) return;
          // the card is hidden while a place is chosen: the top element is enough; a shown card is on top of its place
          (shown ? D.elementsFromPoint(px, py) : [D.elementFromPoint(px, py)]).forEach(function (n) {
            if (!n || seen.has(n) || n === card || card.contains(n)) return;
            seen.add(n);
            var b = fixedOrSticky(n);
            if (!b) return;
            var br = b.getBoundingClientRect();
            if (br.width * br.height < V * H * 0.9) out.push(grow(br, GAP));
          });
        });
      }
      return out.filter(function (k) { return hit(r, k); });
    }
    function held() {
      if (D.documentElement.classList.contains('jcx-hold')) return true;
      // only a box that is really on the screen (an off-canvas drawer waits outside it, or is see-through)
      return [].some.call(D.querySelectorAll('[aria-modal="true"]'), function (n) {
        if (card.contains(n)) return false;
        var r = n.getBoundingClientRect(), s;
        if (!(r.width > 0 && r.height > 0 && r.right > 0 && r.bottom > 0 && r.left < vw() && r.top < vh())) return false;
        s = getComputedStyle(n);
        return s.visibility !== 'hidden' && +s.opacity > 0.05;
      });
    }
    function engineUnitOnScreen() {
      return [].some.call(D.querySelectorAll(UNITS), function (n) {
        var r = n.getBoundingClientRect(), s = getComputedStyle(n);
        return r.width > 0 && r.height > 0 && s.display !== 'none' && s.visibility !== 'hidden' && r.bottom > 0 && r.top < vh();
      });
    }
    /* the card's height at width w (phones: a smaller title, then no second line, so it stays within 160 px) */
    function measure(w) {
      var phone = vw() < 768;
      card.style.width = w + 'px';
      // measured where it is, invisible (visibility:hidden is never painted and never hit-tested): its left / top / bottom
      // change only in show(), while it is display:none, so nothing can animate or shift
      if (!shown) { card.style.visibility = 'hidden'; card.style.display = 'block'; }
      title.style.fontSize = '15px'; msg.style.fontSize = '13px';
      if (!done) msg.style.display = '';
      if (phone && card.offsetHeight > 160) title.style.fontSize = '14px';
      if (phone && card.offsetHeight > 160) msg.style.fontSize = '12px';
      if (phone && card.offsetHeight > 160 && !done) msg.style.display = 'none';
      var h = card.offsetHeight;
      if (!shown) { card.style.display = 'none'; card.style.visibility = ''; }
      return h;
    }
    /* first free corner. Phones: bottom-start / bottom-end only, first a narrow card that leaves the switcher's corner free,
       then the full width; the card stays in the lower half (never the centre). Computers: + top-end / top-start. */
    var plan = null;
    function planStart() {
      var V = vw(), phone = V < 768, rtl = getComputedStyle(D.documentElement).direction === 'rtl';
      var widths = phone ? [V - 32 - 64, V - 32].map(function (x) { return Math.min(340, x); }).filter(function (x, i, a) { return x >= 260 && a.indexOf(x) === i; }) : [340];
      if (!widths.length) widths = [Math.max(200, V - 32)];
      var corners = framed ? [['top', 'end']] : phone ? [['bottom', 'start'], ['bottom', 'end']] : [['bottom', 'start'], ['bottom', 'end'], ['top', 'end'], ['top', 'start']];
      plan = { V: V, rtl: rtl, list: [], i: 0, hs: {} };
      widths.forEach(function (w) { corners.forEach(function (c) { plan.list.push([w, c[0], c[1]]); }); });
    }
    /* one corner per call (the whole search in one go was a 160-230 ms task on a phone at 4x CPU): a place, null when no
       corner is free, undefined while corners are left */
    function planStep() {
      var t = plan.list[plan.i++], V = vw(), H = vh(), w = t[0], side = t[1];
      if (V !== plan.V) { plan = null; return undefined; }
      // the card's height at this width is measured in a task of its own (a forced layout), the corner in the next one
      if (!plan.hs[w]) { plan.hs[w] = measure(w); plan.i--; return undefined; }
      var h = plan.hs[w], keep = keepRects(true);
      var x = (t[2] === 'start') !== plan.rtl ? 16 : V - 16 - w;
      var y = side === 'bottom' ? H - EDGE - h : (framed ? 16 : EDGE);
      for (var k = 0; k < 6; k++) {
        if (y < 8 || y + h > H - 8) break;
        if (!framed && (side === 'bottom' ? y < H / 2 : y + h > H / 2)) break;
        var r = { left: x, right: x + w, top: y, bottom: y + h };
        var b = blockers(r, keep, true, true);
        if (!b.length) { plan = null; return { x: x, y: y, w: w, h: h, side: side, b: H - y - h, V: V }; }
        y = side === 'bottom' ? Math.min.apply(0, b.map(function (o) { return o.top; })) - h
          : Math.max.apply(0, b.map(function (o) { return o.bottom; }));
      }
      if (plan.i >= plan.list.length) { plan = null; return null; }
      return undefined;
    }
    /* where the placed card is now (a bottom card follows the bottom edge, e.g. when a phone's address bar hides) */
    function placeRect() {
      var r = shown ? card.getBoundingClientRect() : null;
      if (r && r.width) return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
      var top = P.side === 'bottom' ? vh() - P.b - P.h : P.y;
      return { left: P.x, right: P.x + P.w, top: top, bottom: top + P.h };
    }
    var moved = true;
    function placeFree() {
      // frames / videos are read on every check (a slot can fill with a frame under the card without a scroll); the hit
      // tests for deep fixed boxes only after a scroll / resize
      var r = placeRect(), probe = moved || !ever;
      moved = false;
      return r.top >= 8 && r.bottom <= vh() - 8 && !blockers(r, keepRects(!ever), !ever, true, probe).length;
    }
    function hide() {
      free = 0;
      if (!shown) return;
      shown = false;
      card.style.display = 'none';
      card.removeAttribute('data-jcx-fx');
    }
    function show() {
      measure(P.w);
      card.style.width = P.w + 'px';
      card.style.left = P.x + 'px';
      if (P.side === 'bottom') { card.style.top = 'auto'; card.style.bottom = P.b + 'px'; } else { card.style.bottom = 'auto'; card.style.top = P.y + 'px'; }
      var first = !ever;
      shown = ever = true;
      if (first) card.style.clipPath = 'inset(0 0 calc(100% - 2px))';
      card.style.display = 'block';
      card.setAttribute('data-jcx-fx', '');
      if (first) reveal();
    }
    /* never the page's LCP (CONTRACT-3, engine reveal): Chrome records an element for the LCP at its first paint with the
       size it has then. The card is first painted as a 2 px strip, then opened with clip-path (paint only: no layout shift).
       Once is enough: the card is built once and only shown / hidden afterwards. */
    function reveal() {
      var raf = W.requestAnimationFrame || function (f) { return setTimeout(f, 16); };
      var rm = W.matchMedia && W.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var im = card.querySelector('img');
      var ready = im && im.decode ? im.decode().catch(function () { }) : { then: function (f) { f(); } };
      ready.then(function () {
        raf(function () { raf(function () { if (!rm) card.style.transition = 'clip-path .3s'; card.style.clipPath = ''; }); });
      });
    }
    function check() {
      q = 0;
      if (!card.isConnected) return;
      var V = vw(), phone = V < 768;
      // a new screen width (rotation, a resized window): hide, then find a new place (a hidden card cannot shift)
      if (P && P.V !== V) { hide(); P = null; ever = false; }
      // an open theme menu / dialog (the engine's html.jcx-hold, or any aria-modal box on screen): stay hidden, keep the place
      if (held()) { hide(); return; }
      // phones: never next to an engine unit that a visitor already sees (one fixed unit at a time)
      var wait = !shown && phone && !framed && engineUnitOnScreen(), fresh = false;
      if (wait) plan = null;
      if (!P && !wait) {
        if (!plan) planStart();
        var got = planStep();
        // more corners to try: the next one in a later task
        if (got === undefined) { setTimeout(sch, 0); return; }
        P = got; fresh = !!got;
      }
      if (!P || wait || (!fresh && !placeFree())) {
        hide();
        // until it has been seen once, the place may still change (nothing on screen moves)
        if (!ever) P = null;
        if (!P && ++misses > 40) { clearInterval(timer); timer = 0; }
        return;
      }
      misses = 0;
      // two free checks in a row before it appears (no flicker while the page is still moving)
      if (!shown && ++free >= 2) show();
    }
    /* at most one measure every 400 ms; scroll and resize restart the slow timer after it gave up (40 misses) */
    function sch(e) {
      if (e && e.type) moved = true;
      if (e && e.type && !timer && card.isConnected) { misses = 0; timer = setInterval(sch, 1000); }
      if (q) return;
      var wait = Math.max(0, last + 400 - Date.now());
      q = setTimeout(function () { (W.requestAnimationFrame || setTimeout)(function () { last = Date.now(); check(); }); }, wait);
    }

    // ink for the accent button: dark or light, whichever reads on the theme's accent
    D.body.appendChild(card);
    try { card.style.display = 'block'; allow.style.color = lum(getComputedStyle(allow).backgroundColor) > 0.179 ? '#111' : '#fff'; card.style.display = 'none'; } catch (e) { }
    D.addEventListener('keydown', onKey);
    W.addEventListener('resize', sch);
    W.addEventListener('scroll', sch, { passive: true });
    timer = setInterval(sch, 1000);
    sch();
  }
  // after the page and the ad engine have placed their own boxes
  function later() { setTimeout(run, 1500); }
  if (D.readyState === 'complete') later(); else W.addEventListener('load', later);
})();
