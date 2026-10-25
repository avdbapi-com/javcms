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
/*! JavCMS visitor theme switcher | JavCMS Community License 1.0 (project-owned) | palette icon: Lucide (ISC) */
/* Injected by the core (app\common\util\ThemeSwitcher) before </body> with a data-jv-theme payload.
   The server renders the theme from the jv_theme cookie; localStorage 'javcms.theme' mirrors it.
   Where the button shows (payload "show", site.theme_switcher_show; a payload without it = "always"):
     always  the floating button on every screen, from the first one
     header  the button the server printed in the theme's header ([data-jv-ts-slot], before the light/dark button);
             the floating button takes over wherever that header button is not shown (closed phone menu, no room)
     end     the floating button only at the end of the page (1.2.0)
   No dependencies, no innerHTML, nothing runs on scroll. */
(function (window, document) {
  'use strict';
  if (window.__jvTs) return;
  // never inside frames; a header button printed into a framed page would do nothing there
  if (window.top !== window.self) { dropSlot(); return; }
  window.__jvTs = 1;

  // A header button this script cannot serve (framed page, broken payload, the stylesheet failed): never a dead control
  // and nothing moves (CLS 0). It keeps its box but is not drawn, not hit-tested and no Tab stop: inert, and hidden with
  // its icon (a theme rule such as pointer-events:all keeps hidden SVG shapes clickable). The server prints it inert and
  // hidden until it is marked live; an older cached page has no such rule, so the same is done here.
  function dropSlot() {
    var s = document.querySelector('button[data-jv-ts-slot]');
    if (!s) return;
    s.removeAttribute('data-jv-ts-live');
    s.setAttribute('inert', '');
    var nodes = [s].concat(Array.prototype.slice.call(s.querySelectorAll('*')));
    for (var i = 0; i < nodes.length; i++) {
      if (!nodes[i].style) continue;
      nodes[i].style.setProperty('visibility', 'hidden', 'important');
      nodes[i].style.setProperty('pointer-events', 'none', 'important');
    }
  }
  var script = document.currentScript || document.querySelector('script[data-jv-theme]');
  var P;
  try { P = JSON.parse(script.getAttribute('data-jv-theme')); } catch (e) { dropSlot(); return; }
  if (!P || P.v !== 1 || !P.themes || !P.themes.length) { dropSlot(); return; }
  var SHOW = P.show === 'header' || P.show === 'end' ? P.show : 'always';
  // the 1.2.1 hide rules (typing, cookie notice, big fixed layers, late players) are for the visible modes;
  // "end" keeps its 1.2.0 behaviour exactly
  var extra = SHOW !== 'end';

  var COOKIE = 'jv_theme', STORE = 'javcms.theme', RESTORED = 'javcms.theme.restored';
  var SLUG = /^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/;
  var root = document.documentElement;
  var T = P.t || {};
  var offered = {}, byslug = {};
  P.themes.forEach(function (t) { byslug[t.slug] = t; if (!t.locked) offered[t.slug] = 1; });
  var cookiePath = P.base && P.base !== '/' ? P.base.replace(/\/+$/, '') : '/';

  function store(kind) { try { return window[kind]; } catch (e) { return null; } }
  function sget(kind, k) { var s = store(kind); try { return s ? s.getItem(k) : null; } catch (e) { return null; } }
  function sset(kind, k, v) { var s = store(kind); try { if (s) { v === null ? s.removeItem(k) : s.setItem(k, v); return true; } } catch (e) {} return false; }
  function readCookie() {
    var parts = document.cookie ? document.cookie.split(';') : [];
    for (var i = 0; i < parts.length; i++) {
      var kv = parts[i].replace(/^\s+/, ''), eq = kv.indexOf('=');
      if (eq > 0 && kv.slice(0, eq) === COOKIE) { try { return decodeURIComponent(kv.slice(eq + 1)); } catch (e) { return ''; } }
    }
    return '';
  }
  function writeCookie(slug) {
    var tail = '; Path=' + cookiePath + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    document.cookie = slug ? COOKIE + '=' + encodeURIComponent(slug) + '; Max-Age=31536000' + tail : COOKIE + '=; Max-Age=0' + tail;
  }

  // ---- persistence (spec 3.4 as amended by the review: B1 chosen from the cookie, B2 default = no cookie)
  var raw = readCookie();
  var chosen = SLUG.test(raw) && offered[raw] && raw !== P.default ? raw : '';
  var mirror = sget('localStorage', STORE);
  if (mirror && (!SLUG.test(mirror) || !offered[mirror] || mirror === P.default)) { sset('localStorage', STORE, null); mirror = null; }
  if (!P.preview) {
    if (!chosen && mirror && P.current === P.default && sget('sessionStorage', RESTORED) !== '1' && sset('sessionStorage', RESTORED, '1')) {
      // The cookie expired or was evicted while the browser kept the choice: restore once per tab session.
      writeCookie(mirror);
      if (readCookie() === mirror) { location.reload(); return; }
    }
    if (chosen && mirror !== chosen) sset('localStorage', STORE, chosen);
  }
  var active = P.preview ? P.current : (chosen || P.default);

  // Back/forward cache: a restored page in another theme than the current choice reloads.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted || P.preview) return;
    var c = readCookie(), want = SLUG.test(c) && offered[c] ? c : P.default;
    if (want !== P.current) location.reload();
  });

  // ---- helpers
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function svg(paths) {
    var ns = 'http://www.w3.org/2000/svg', s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
    paths.forEach(function (d) {
      var n = document.createElementNS(ns, d[0]);
      for (var k in d[1]) if (Object.prototype.hasOwnProperty.call(d[1], k)) n.setAttribute(k, d[1][k]);
      s.appendChild(n);
    });
    return s;
  }
  var ICON_PALETTE = [
    ['path', { d: 'M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z' }],
    ['circle', { cx: '13.5', cy: '6.5', r: '.5', fill: 'currentColor' }], ['circle', { cx: '17.5', cy: '10.5', r: '.5', fill: 'currentColor' }],
    ['circle', { cx: '6.5', cy: '12.5', r: '.5', fill: 'currentColor' }], ['circle', { cx: '8.5', cy: '7.5', r: '.5', fill: 'currentColor' }]
  ];
  var ICON_X = [['path', { d: 'M18 6 6 18' }], ['path', { d: 'm6 6 12 12' }]];
  var ICON_CHECK = [['path', { d: 'M20 6 9 17l-5-5' }]];
  function reducedFlag() { return sget('localStorage', 'fx.reduced') === '1'; }
  function mode() { return root.getAttribute('data-color-mode') === 'light' ? 'light' : 'dark'; }
  function visible(n) {
    if (!n || !n.getBoundingClientRect) return false;
    if (n.checkVisibility) return n.checkVisibility({ opacityProperty: true, visibilityProperty: true });
    var r = n.getBoundingClientRect(), cs = getComputedStyle(n);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && +cs.opacity !== 0;
  }
  function hits(a, b, pad) { return a.left - pad < b.right && b.left < a.right + pad && a.top - pad < b.bottom && b.top < a.bottom + pad; }
  // a box that can cover anything: an empty live region (a theme's status line, 0 px high) never does
  function solid(b) { return b.width > 0 && b.height > 0; }

  // ---- floating button
  root.setAttribute('data-jv-switcher-pos', P.pos === 'bottom-start' ? 'bottom-start' : 'bottom-end');
  root.setAttribute('data-jv-switcher-show', SHOW);
  if (reducedFlag()) root.classList.add('jv-ts-reduced');
  var wrap = el('div', 'jv-ts');
  wrap.setAttribute('data-jv-ts', '');
  wrap.setAttribute('lang', P.lang || 'en');
  wrap.setAttribute('dir', P.dir === 'rtl' ? 'rtl' : 'ltr');
  var btn = el('button', 'jv-ts-btn');
  btn.type = 'button';
  btn.setAttribute('aria-haspopup', 'dialog');
  btn.setAttribute('aria-expanded', 'false');
  btn.setAttribute('aria-controls', 'jv-ts');
  var curName = (byslug[P.current] || byslug[active] || P.themes[0]).name;
  btn.setAttribute('aria-label', (T.open || 'Change theme') + ': ' + curName);
  btn.title = T.open || 'Change theme';
  btn.appendChild(svg(ICON_PALETTE));
  // shown once the stylesheet has loaded (no unstyled flash; position:fixed = no layout shift). Inline display:none as well:
  // a theme rule such as button{display:inline-flex} (javcms-modern) beats the hidden attribute, and a button rendered
  // before the stylesheet would then fade out from full opacity when its first-screen state applies (over a player too)
  btn.hidden = true;
  btn.style.setProperty('display', 'none', 'important');
  wrap.appendChild(btn);
  var status = el('div', 'jv-ts-sr');
  status.setAttribute('role', 'status');
  wrap.appendChild(status);
  var spacer = el('div', 'jv-ts-spacer');
  spacer.setAttribute('aria-hidden', 'true');

  // ---- header button (header mode): printed by the server, so it is laid out with the header (no shift). The widget
  // only decides, on every check, whether it is usable here; where it is not, the floating button is used instead.
  var slot = SHOW === 'header' ? document.querySelector('button[data-jv-ts-slot]') : null;
  var useHead = false, ready = false, pending = false;
  // the control that opened the panel (focus returns to it, aria-expanded follows it, the panel is placed next to it)
  var opener = btn;

  // ---- obstruction checks (one rAF-coalesced function; review A2/A4/A6, C5)
  // "end" (kit-polish): the button never sits over page content. It is shown only while the reserved spacer at the
  // page end is fully in view (the spacer is sized to the button's real band, including theme offsets and the ad
  // engine's lift above its sticky bar). Elsewhere it is "parked": transparent and click-through, but it stays in the
  // Tab order and becomes visible while it has keyboard focus.
  // "always" / "header": never parked. It still steps aside for players, scroll-locking layers, covering openers,
  // toasts, the cookie notice and typing; the spacer stays, so at the page end it lies on blank space.
  // failed: the stylesheet did not load (the widget is gone, the header button dropped in place): nothing to check any more
  var queued = 0, dodge = false, panelOpen = false, atEnd = false, spacerH = 0, rearm = 0, recheckT = 0, failed = false;
  function schedule() { if (!queued) { queued = 1; (window.requestAnimationFrame || setTimeout)(check); } }
  // nothing runs on scroll: while the button steps aside for something that may go away without an event (a toast, a
  // focused element scrolled away), it looks again in a moment. One pending re-check at a time, whatever the number of
  // checks in between (a timer per check piled up into parallel chains: fix round 2, perf P3-A)
  function recheck() { if (!recheckT) recheckT = setTimeout(function () { recheckT = 0; schedule(); }, 800); }
  function later() {
    schedule(); setTimeout(schedule, 360); setTimeout(schedule, 1200);
    // a player made by a tap (the pre-roll gate builds the provider frame) or resized by it (theatre mode); one
    // re-arm for a burst of clicks / keys (typing never re-arms per key)
    if (extra && ready) { clearTimeout(rearm); rearm = setTimeout(function () { watchPlayers(true); }, 420); }
  }
  function locked() {
    if (root.classList.contains('jv-ts-lock')) return false;
    var h = getComputedStyle(root).overflowY, b = document.body ? getComputedStyle(document.body).overflowY : '';
    return h === 'hidden' || h === 'clip' || b === 'hidden' || b === 'clip';
  }
  function covered() {
    var r = btn.getBoundingClientRect();
    if (!r.width) return false;
    var openers = document.querySelectorAll('[aria-expanded="true"][aria-controls]');
    for (var i = 0; i < openers.length; i++) {
      if (wrap.contains(openers[i]) || openers[i] === slot) continue;
      var ids = (openers[i].getAttribute('aria-controls') || '').split(/\s+/);
      for (var j = 0; j < ids.length; j++) {
        var target = ids[j] && document.getElementById(ids[j]);
        if (target && !wrap.contains(target) && visible(target)) {
          var tr = target.getBoundingClientRect();
          if (extra && !solid(tr)) continue;
          if (hits(r, tr, 8)) return true;
          // a drawer / sheet that does not lock the scroll and does not reach the corner (javcms side menu + scrim)
          if (extra && tr.height >= window.innerHeight * 0.5 && getComputedStyle(target).position === 'fixed') return true;
        }
      }
    }
    var toasts = document.querySelectorAll('.gallery-status:not([hidden]), .tui-notices > *');
    for (var k = 0; k < toasts.length; k++) {
      var tb = toasts[k].getBoundingClientRect();
      // visible modes: an empty status line (crimson-modern / ember print one without styling it) covers nothing
      if ((!extra || solid(tb)) && visible(toasts[k]) && hits(r, tb, 8)) { recheck(); return true; }
    }
    if (extra) {
      // the cookie notice (z above the button) over the corner: the button waits until it is answered
      var c = document.getElementById('jv-cookie');
      var cb = c ? c.getBoundingClientRect() : null;
      if (c && visible(c) && solid(cb) && hits(r, cb, 4)) return true;
    }
    return false;
  }
  // typing: on a touch screen the keyboard is up while a text field has focus; with a mouse only a field the button
  // would cover counts (a comment box at the bottom corner)
  var coarse = window.matchMedia ? window.matchMedia('(pointer: coarse)') : null;
  function textField(a) {
    if (!a || a === document.body || a.nodeType !== 1) return false;
    if (a.isContentEditable) return true;
    if (a.nodeName === 'TEXTAREA') return !a.readOnly && !a.disabled;
    if (a.nodeName !== 'INPUT' || a.readOnly || a.disabled) return false;
    return !/^(button|submit|reset|checkbox|radio|range|color|file|image|hidden)$/i.test(a.type || 'text');
  }
  function typing() {
    var a = document.activeElement;
    if (!textField(a)) return false;
    if (coarse && coarse.matches) return true;
    return hits(btn.getBoundingClientRect(), a.getBoundingClientRect(), 16);
  }
  // Keyboard focus on the floating button (:focus-visible; not the focus a click or tap leaves, e.g. after the panel was
  // closed with the pointer; a browser without it counts every focus). Visible modes: only this focus keeps the button
  // from being hidden or handed over (WCAG 2.4.3 / 2.4.7); a pointer focus nobody uses does not (fix round 4, SVVP4-1).
  function kbdFocus() {
    if (document.activeElement !== btn) return false;
    try { return btn.matches(':focus-visible'); } catch (e) { return true; }
  }
  // keyboard focus on a page element that lies (partly) under the button (WCAG 2.4.11 / 2.4.12): the button steps
  // aside until the focus moves on; a mouse click that focuses something (no :focus-visible) does not count. A frame
  // (an ad creative, an embed) never matches :focus-visible while its own content has the focus: it counts as well.
  function obscures() {
    var a = document.activeElement;
    if (!a || a === document.body || a === root || a === btn || a === slot || wrap.contains(a)) return false;
    var kb = a.nodeName === 'IFRAME';
    if (!kb) { try { kb = a.matches(':focus-visible'); } catch (e) { kb = false; } }
    if (!kb) return false;
    var ar = a.getBoundingClientRect();
    if (!solid(ar) || !hits(btn.getBoundingClientRect(), ar, 0)) return false;
    // look again in a moment, so the button comes back once the element has scrolled away (one pending re-check)
    recheck();
    return true;
  }
  // Reserved space: at the page end the button band (+12 px) must lie over the spacer. "tail" is what follows the
  // spacer in the document (body padding kept for a theme's bottom bar, margins): it stays blank, so the spacer
  // only covers the rest, and the end observer shrinks its root by the same tail (see watchEnd).
  var tail = -1, ownBg = null;
  // Some themes hide the spacer and keep the old 80 px reserve inside their footer instead (not always at its very end).
  // The reserve now follows the button band (theme offsets, ad lift), so the spacer is shown again at full height;
  // when the theme gives it no background of its own it continues the background colour of the element above it.
  function spacerLook() {
    var cs = getComputedStyle(spacer);
    if (cs.display === 'none') { spacer.style.setProperty('display', 'block', 'important'); cs = getComputedStyle(spacer); }
    if (ownBg === null) ownBg = cs.backgroundImage === 'none' && /^(transparent|rgba\(0, 0, 0, 0\))$/.test(cs.backgroundColor);
    if (!ownBg) return;
    var prev = spacer.previousElementSibling, bg = '';
    while (prev && !bg) {
      var ps = getComputedStyle(prev);
      if (ps.display !== 'none' && ps.position !== 'fixed' && ps.position !== 'absolute' && prev.getBoundingClientRect().height > 0) bg = ps.backgroundColor;
      else prev = prev.previousElementSibling;
    }
    var m = bg && bg.match(/rgba?\([^)]*\)/);
    var opaque = m && !/rgba\([^)]*,\s*0(\.\d+)?\)$/.test(bg) ? bg : '';
    if (spacer.style.backgroundColor !== opaque) spacer.style.backgroundColor = opaque;
  }
  function size() {
    if (btn.hidden) return;
    if (useHead) {
      // the header button is in use: no floating button, nothing to reserve at the page end
      if (spacerH !== 0) { spacerH = 0; spacer.style.height = '0px'; }
      return;
    }
    spacerLook();
    var r = btn.getBoundingClientRect();
    if (!r.height) return;
    var s = spacer.getBoundingClientRect();
    var t = Math.min(200, Math.max(0, Math.floor(root.scrollHeight - (s.bottom + (window.pageYOffset || 0)))));
    var h = Math.max(80, Math.ceil(window.innerHeight - r.top + 12 - t));
    if (Math.abs(h - spacerH) > 1) { spacerH = h; spacer.style.height = h + 'px'; }
    if (Math.abs(t - tail) > 4) { tail = t; if (endIo) { endIo.disconnect(); endIo = null; watchEnd(); } }
  }
  // The colour of the page behind the button decides the focus ring colour (one ring, 3:1+ on any page).
  function pageTone() {
    var c = '', nodes = [document.body, root];
    for (var i = 0; i < nodes.length && !c; i++) {
      var v = nodes[i] ? getComputedStyle(nodes[i]).backgroundColor : '';
      var m = v && v.match(/rgba?\(([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)(?:[ ,/]+([\d.]+))?/);
      if (m && (m[4] === undefined || +m[4] > 0.5)) c = m;
    }
    var tone;
    if (c) {
      var lum = function (x) { x = x / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
      tone = 0.2126 * lum(+c[1]) + 0.7152 * lum(+c[2]) + 0.0722 * lum(+c[3]) > 0.18 ? 'light' : 'dark';
    } else tone = /dark/.test(getComputedStyle(root).colorScheme || '') ? 'dark' : 'light';
    if (wrap.getAttribute('data-jv-bg') !== tone) wrap.setAttribute('data-jv-bg', tone);
  }
  // Header button usable here? It must be laid out (the theme or the no-room CSS may hide it at this width, or it sits
  // in a closed phone menu), drawn (no hidden / transparent ancestor), on screen, and nothing else may be drawn over its
  // centre. On screen: inside the screen width, and its centre inside the screen height; a header that scrolls away
  // with the page takes it out of view, and the floating button stands in until it is back, so a switcher control is
  // always on screen (fix round 2, SVVF-P3-7; an observer reports it, nothing runs on scroll). Our own flag
  // (data-jv-ts-off) only sets visibility, so it never decides the answer: the parent's visibility and the element at
  // its centre are read instead.
  // Two covers do not count, so a keyboard user always reaches it with Tab: the part of the header that holds the focus
  // or held it a moment ago (honey-modern widens its search over the tool row while the search or one of its
  // suggestions has focus, and closes it 260 ms after the focus has left), and anything while the header button itself
  // has the focus. The focus only overrides covers, never the on-screen test (fix round 3, SVVF3-P2-1): a focused header
  // button that a static header has scrolled away hands over to the floating button like any other (headState() keeps
  // its focus).
  // A theme whose header buttons transition visibility (JAVCMS Classic: the shared contrast.css forces visibility into
  // every element's transition list, its toggle lasts .2s) reports a button that has just become visible (made live at
  // the stylesheet load, or our flag removed) as hidden for the first frames of that transition (a discrete value keeps
  // its start value at the start). It counts as visible: a check in such a frame flagged it off (data-jv-ts-off cancels
  // the transition), and the floating button kept the corner until the next check, which may never come on a fixed
  // header (fix round 4).
  function showing(n) {
    try {
      return !!n.getAnimations && n.getAnimations().some(function (a) { return a.transitionProperty === 'visibility' && a.playState === 'running'; });
    } catch (e) { return false; }
  }
  function headUsable() {
    if (!slot || !slot.isConnected || (slot.closest && slot.closest('[inert]'))) return false;
    var r = slot.getBoundingClientRect();
    slotOn = onScreen(r, window.innerWidth, window.innerHeight);
    if (r.width < 8 || r.height < 8 || !slot.getClientRects().length) return false;
    var cs = getComputedStyle(slot);
    if (+cs.opacity === 0 || cs.display === 'none' || !visible(slot.parentElement)) return false;
    if (cs.visibility !== 'visible' && !slot.hasAttribute('data-jv-ts-off') && !showing(slot)) return false;
    if (!slotOn) return false;
    if (document.activeElement === slot) return true;
    var at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    if (at && at !== slot && !slot.contains(at) && !at.contains(slot) && !focusCover(at)) return false;
    return true;
  }
  // On screen (the rule of headUsable()): at least 8 px, inside the screen width, its centre inside the screen height.
  function onScreen(r, vw, vh) {
    var y = r.top + r.height / 2;
    return r.width >= 8 && r.height >= 8 && r.left >= -1 && r.right <= vw + 1 && y > 0 && y < vh;
  }
  // the header button leaving the screen (a header that scrolls away) or coming back: one observer, no scroll listener.
  // Its first report normally repeats what headState() has just measured in the stylesheet load handler (slotOn): then it
  // is skipped, so no second full check (forced style / layout) runs while the page is still being built (fix round 4,
  // SVVP4-2). When the page moved in between (a restored scroll position, a jump to an anchor), the box the observer
  // measured gives another answer and the check runs. Any doubt (no recorded answer, no root bounds) runs it too.
  var slotIo = null, slotOn = null;
  function watchSlot() {
    if (!slot || slotIo || !('IntersectionObserver' in window)) return;
    var seen = false;
    slotIo = new IntersectionObserver(function (entries) {
      if (!seen) {
        seen = true;
        var e = entries[entries.length - 1], rb = e.rootBounds;
        if (slotOn !== null && rb && onScreen(e.boundingClientRect, rb.width, rb.height) === slotOn) return;
      }
      schedule();
    }, { threshold: [0, 0.5, 1] });
    slotIo.observe(slot);
  }
  // the cover belongs to the part of the page that holds the focus (or held it less than 0.7 s ago): the innermost
  // element around the cover that also holds that element does not hold the header button
  var recent = null, recentT = 0;
  function focusCover(at) {
    var held = [document.activeElement];
    if (recent && new Date().getTime() - recentT < 700) held.push(recent);
    for (var k = 0; k < held.length; k++) {
      var f = held[k];
      if (!f || f === document.body || f === root || !f.isConnected) continue;
      for (var n = at; n && n !== document.body && n !== root; n = n.parentElement) {
        if (n.contains(f)) { if (!n.contains(slot)) return true; break; }
      }
    }
    return false;
  }
  function focusLeft(e) {
    recent = e.target; recentT = new Date().getTime();
    schedule();
    // the moment is over: a cover that is still there counts again
    if (slot) setTimeout(schedule, 750);
  }
  // A control that has the keyboard focus is never hidden by the hand-over (the focus would drop to <body>, WCAG 2.4.3 /
  // 2.4.7; fix round 3, SVVP3-1 and SVVF3-P2-1): while the floating button has it, it stays the switcher even where the
  // header button is usable again; a focused header button scrolled out of view keeps it (it is only marked once the
  // focus has left) while the floating button stands in. Both hand over at the next check after the focus has moved on
  // (the blur / focusout listeners schedule one). The focus a click or tap left on the floating button (close() returns
  // it) does not keep it: where the header button is usable it takes over at once (fix round 4, SVVP4-1).
  function headState() {
    var ok = headUsable() && !kbdFocus();
    useHead = ok;
    if (ok) { slot.removeAttribute('data-jv-ts-off'); btn.setAttribute('data-jv-headon', ''); return; }
    btn.removeAttribute('data-jv-headon');
    // laid out but covered or pushed out of the screen: never half usable (visibility only: no layout change)
    var laid = slot.isConnected && slot.getClientRects().length > 0;
    if (!laid) slot.removeAttribute('data-jv-ts-off');
    else if (document.activeElement !== slot) slot.setAttribute('data-jv-ts-off', '');
  }
  function check() {
    queued = 0;
    if (panelOpen || failed) return;
    if (slot) headState();
    size();
    pageTone();
    // a button that has focus is never hidden (focus would drop to <body>, e.g. when a theme toast shows on Esc). Visible
    // modes: the keyboard focus (kbdFocus()), so after a pointer close it steps aside for a player again (fix round 4)
    var held = extra ? kbdFocus() : document.activeElement === btn;
    var hide = !held && (dodge || locked() || covered() || (extra && (typing() || obscures())));
    if (hide) btn.setAttribute('data-jv-hide', ''); else btn.removeAttribute('data-jv-hide');
    if (SHOW !== 'end' || atEnd) btn.removeAttribute('data-jv-park'); else btn.setAttribute('data-jv-park', '');
  }
  // "At the end" = the spacer lies fully inside the viewport minus the tail. One observer, nothing runs on scroll.
  var endIo = null;
  function watchEnd() {
    if (endIo) return;
    if (!('IntersectionObserver' in window)) { atEnd = true; return; }
    endIo = new IntersectionObserver(function (entries) {
      var e = entries[entries.length - 1], v = e.isIntersecting && e.intersectionRatio > 0.99;
      if (v !== atEnd) { atEnd = v; schedule(); }
    }, { rootMargin: '0px 0px ' + (-Math.max(0, tail - 2)) + 'px 0px', threshold: [0, 0.99, 1] });
    endIo.observe(spacer);
  }

  // Player dodge: one IntersectionObserver whose root box is shrunk to the button area (+8 px).
  // keep (visible modes): a re-arm keeps the current answer until the new observer reports (no flash over a player);
  // the theme's player box (.MacPlayer) counts too, so the gate / poster and the frame made after the tap are covered.
  // Visible modes: an ad of the ad engine's in-content slots (.jcx-s, a creative in the page flow, no player controls)
  // is page content like a picture; the pre-roll inside the player box still counts.
  var io = null;
  function watchPlayers(keep) {
    if (io) { io.disconnect(); io = null; }
    var was = dodge;
    if (!keep) dodge = false;
    if (!('IntersectionObserver' in window) || btn.hidden) { if (keep && was) { dodge = false; schedule(); } return; }
    var vw = window.innerWidth, players = [];
    var list = document.querySelectorAll(extra ? 'iframe, video, .MacPlayer' : 'iframe, video');
    for (var i = 0; i < list.length; i++) {
      if (wrap.contains(list[i]) || (extra && list[i].closest && list[i].closest('.jcx-s'))) continue;
      if (list[i].getBoundingClientRect().width > vw * 0.5) players.push(list[i]);
    }
    var r = btn.getBoundingClientRect(), vh = window.innerHeight;
    if (!players.length || !r.width) { if (keep && was) { dodge = false; schedule(); } return; }
    var m = [-(Math.max(0, r.top - 8)), -(Math.max(0, vw - r.right - 8)), -(Math.max(0, vh - r.bottom - 8)), -(Math.max(0, r.left - 8))];
    var state = new Map();
    // first screen: the button stays transparent until this observer has answered (settle(): never drawn over a player)
    if (btn.hasAttribute('data-jv-settle')) waitPlayers = true;
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { state.set(e.target, e.isIntersecting); });
      var any = false; state.forEach(function (v) { if (v) any = true; });
      if (any !== dodge) { dodge = any; schedule(); }
      if (waitPlayers) { waitPlayers = false; settledIf(); }
    }, { rootMargin: m.map(function (v) { return Math.round(v) + 'px'; }).join(' '), threshold: 0 });
    players.forEach(function (p) { io.observe(p); });
  }
  var rt = 0;
  // visible modes: a re-arm after a resize keeps the current answer until the new observer reports (no flash over a
  // player); "end" as in 1.2.0
  function onResize() { clearTimeout(rt); rt = setTimeout(function () { if (panelOpen) place(); watchPlayers(extra); schedule(); }, 200); }

  // ---- panel (built on first open)
  var scrim, panel, group, radios = [], inerted = [], colorObs = null;
  function img(theme) { return theme.preview + 'preview-' + mode() + '.webp'; }
  function build() {
    scrim = el('div', 'jv-ts-scrim');
    scrim.addEventListener('click', close);
    panel = el('div', 'jv-ts-panel');
    panel.id = 'jv-ts';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'jv-ts-title');
    panel.setAttribute('aria-describedby', 'jv-ts-hint');
    panel.tabIndex = -1;
    panel.appendChild(el('div', 'jv-ts-handle'));
    var head = el('div', 'jv-ts-head');
    var h = el('h2', 'jv-ts-title', T.title || 'Site theme'); h.id = 'jv-ts-title';
    var x = el('button', 'jv-ts-close'); x.type = 'button'; x.setAttribute('aria-label', T.close || 'Close'); x.title = T.close || 'Close';
    x.appendChild(svg(ICON_X)); x.addEventListener('click', close);
    head.appendChild(h); head.appendChild(x); panel.appendChild(head);
    var hint = el('p', 'jv-ts-hint', T.hint || ''); hint.id = 'jv-ts-hint'; panel.appendChild(hint);
    group = el('div', 'jv-ts-grid');
    group.setAttribute('role', 'radiogroup');
    group.setAttribute('aria-labelledby', 'jv-ts-title');
    P.themes.forEach(function (t, i) {
      var b = el('button', 'jv-ts-card');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('data-slug', t.slug);
      var on = t.slug === active;
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      if (t.locked) b.setAttribute('aria-disabled', 'true');
      var fig = el('span', 'jv-ts-shot');
      var im = el('img');
      im.alt = ''; im.width = 160; im.height = 90; im.loading = 'lazy'; im.decoding = 'async';
      im.addEventListener('error', function () { fig.classList.add('jv-ts-noshot'); });
      im.src = img(t);
      fig.appendChild(im);
      if (on) { var tick = el('span', 'jv-ts-tick'); tick.appendChild(svg(ICON_CHECK)); fig.appendChild(tick); }
      b.appendChild(fig);
      var name = el('bdi', 'jv-ts-name', t.name); b.appendChild(name);
      var chips = el('span', 'jv-ts-chips');
      var did = 'jv-ts-k' + i, kind = el('span', 'jv-ts-chip', t.kind === 'modern' ? (T.modern || 'Modern style') : (T.classic || 'Classic style'));
      kind.id = did; chips.appendChild(kind);
      var desc = [did];
      if (t.slug === P.default && !t.locked) { var d = el('span', 'jv-ts-chip jv-ts-chip-def', T.default || 'Site default'); d.id = did + 'd'; chips.appendChild(d); desc.push(d.id); }
      if (t.locked || (P.preview && t.slug === P.current)) { var pv = el('span', 'jv-ts-chip jv-ts-chip-cur', T.preview || 'Theme preview'); pv.id = did + 'p'; chips.appendChild(pv); desc.push(pv.id); }
      else if (t.slug === P.current) { var c = el('span', 'jv-ts-chip jv-ts-chip-cur', T.current || 'Current theme'); c.id = did + 'c'; chips.appendChild(c); desc.push(c.id); }
      b.setAttribute('aria-describedby', desc.join(' '));
      b.appendChild(chips);
      b.tabIndex = on ? 0 : -1;
      b.addEventListener('click', function () { choose(t); });
      group.appendChild(b);
      radios.push(b);
    });
    if (!radios.some(function (r) { return r.tabIndex === 0; })) radios[0].tabIndex = 0;
    panel.appendChild(group);
    panel.addEventListener('keydown', keys);
    wrap.appendChild(scrim);
    wrap.appendChild(panel);
  }
  function focusables() {
    return Array.prototype.filter.call(panel.querySelectorAll('button:not([disabled])'), function (n) {
      return n.tabIndex >= 0 && n.getClientRects().length;
    });
  }
  function keys(e) {
    if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key === 'Tab') {
      var f = focusables(); if (!f.length) return;
      var first = f[0], last = f[f.length - 1], a = document.activeElement;
      if (e.shiftKey && (a === first || !panel.contains(a))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (a === last || !panel.contains(a))) { e.preventDefault(); first.focus(); }
      return;
    }
    var idx = radios.indexOf(document.activeElement);
    if (idx < 0) return;
    var rtl = P.dir === 'rtl', next = -1;
    if (e.key === 'ArrowDown' || e.key === (rtl ? 'ArrowLeft' : 'ArrowRight')) next = (idx + 1) % radios.length;
    else if (e.key === 'ArrowUp' || e.key === (rtl ? 'ArrowRight' : 'ArrowLeft')) next = (idx - 1 + radios.length) % radios.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = radios.length - 1;
    if (next >= 0) {
      e.preventDefault();
      radios.forEach(function (r) { r.tabIndex = -1; });
      radios[next].tabIndex = 0; radios[next].focus();
    }
  }
  function swapShots() {
    radios.forEach(function (r) {
      var t = byslug[r.getAttribute('data-slug')], im = r.querySelector('img');
      if (t && im) { r.querySelector('.jv-ts-shot').classList.remove('jv-ts-noshot'); im.src = img(t); }
    });
  }
  // The header button can lose its box while the panel is open (resized into a width where the theme has no room for
  // it): the floating button, which takes over there, becomes the opener (aria-expanded, placement, focus return).
  function keepOpener() {
    if (opener === btn || opener.getClientRects().length) return;
    opener.setAttribute('aria-expanded', 'false');
    opener = btn;
    if (panelOpen) btn.setAttribute('aria-expanded', 'true');
  }
  function place() {
    keepOpener();
    if (window.matchMedia('(max-width: 767px), (pointer: coarse) and (max-height: 500px)').matches) {
      panel.style.left = panel.style.right = panel.style.bottom = panel.style.top = panel.style.maxHeight = '';
      return;
    }
    var r = opener.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
    if (opener !== btn && r.top + r.height / 2 < vh / 2) {
      // a header button: the panel opens below it and ends above the bottom bars and the sticky ad bar; the floating
      // button (laid out, lifted above them by the ad engine and the theme offsets) marks that band
      var top = Math.max(8, r.bottom + 10), fb = btn.getBoundingClientRect();
      var floor = fb.height ? Math.min(vh - 16, fb.bottom) : vh - 16;
      panel.style.top = top + 'px';
      panel.style.bottom = 'auto';
      panel.style.maxHeight = Math.max(240, floor - top) + 'px';
    } else {
      var bottom = Math.max(8, vh - r.top + 10);
      panel.style.top = '';
      panel.style.bottom = bottom + 'px';
      panel.style.maxHeight = Math.max(240, vh - bottom - 16) + 'px';
    }
    if (r.left + r.width / 2 > vw / 2) { panel.style.right = Math.max(16, vw - r.right) + 'px'; panel.style.left = 'auto'; }
    else { panel.style.left = Math.max(16, r.left) + 'px'; panel.style.right = 'auto'; }
  }
  function open(from) {
    if (panelOpen) return;
    if (!panel) build();
    panelOpen = true;
    opener = from || btn;
    place();
    inerted = [];
    Array.prototype.forEach.call(document.body.children, function (n) {
      if (n === wrap || n.nodeName === 'SCRIPT') return;
      inerted.push([n, n.hasAttribute('inert'), n.getAttribute('aria-hidden')]);
      n.setAttribute('inert', ''); n.setAttribute('aria-hidden', 'true');
    });
    root.classList.add('jv-ts-lock');
    wrap.classList.add('jv-ts-open');
    opener.setAttribute('aria-expanded', 'true');
    if (window.MutationObserver) {
      colorObs = new MutationObserver(swapShots);
      colorObs.observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });
    }
    var target = radios.filter(function (r) { return r.tabIndex === 0; })[0] || panel;
    try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
    reveal(target);
    document.addEventListener('keydown', docKeys, true);
  }
  // The focused card is brought into view inside the grid, which scrolls on its own (the page behind is locked and does
  // not move): the current theme can be far down the list (fix round 3, SVVP3-4; WCAG 2.4.7 / 2.4.11). Both boxes move
  // with the panel's opening animation alike, so their difference is exact.
  function reveal(card) {
    if (!group || !card || card === panel || !group.contains(card)) return;
    var g = group.getBoundingClientRect(), c = card.getBoundingClientRect(), pad = 8;
    if (c.top < g.top + pad) group.scrollTop -= g.top + pad - c.top;
    else if (c.bottom > g.bottom - pad) group.scrollTop += Math.min(c.bottom - (g.bottom - pad), c.top - (g.top + pad));
  }
  function docKeys(e) {
    if (!panelOpen) return;
    if ((e.key === 'Escape' || e.key === 'Esc')) { e.preventDefault(); e.stopPropagation(); close(); }
    else if (e.key === 'Tab' && !panel.contains(document.activeElement)) { e.preventDefault(); (focusables()[0] || panel).focus(); }
  }
  function close() {
    if (!panelOpen) return;
    panelOpen = false;
    document.removeEventListener('keydown', docKeys, true);
    if (colorObs) { colorObs.disconnect(); colorObs = null; }
    inerted.forEach(function (x) {
      if (!x[1]) x[0].removeAttribute('inert');
      if (x[2] === null) x[0].removeAttribute('aria-hidden'); else x[0].setAttribute('aria-hidden', x[2]);
    });
    inerted = [];
    wrap.classList.remove('jv-ts-open');
    root.classList.remove('jv-ts-lock');
    keepOpener();
    opener.setAttribute('aria-expanded', 'false');
    check();
    // Focus always returns to the control that opened the dialog (the floating or the header button); a header button
    // that is no longer usable hands over to the floating button, which check() has just shown
    if (opener !== btn && !useHead) opener = btn;
    // the focused button is never hidden (check() rule): a hide flag must not keep the focus from coming back, nor the
    // hand-over (a header button that became usable while the panel was open, e.g. after a resize: the floating button
    // keeps the role until the focus moves on)
    if (extra && opener === btn) btn.removeAttribute('data-jv-hide');
    if (opener === btn && btn.hasAttribute('data-jv-headon')) { btn.removeAttribute('data-jv-headon'); useHead = false; }
    var back = opener;
    // a button that is just being shown may still be invisible for a frame (a theme can force its own transition
    // list on every element, e.g. static/template-ui/contrast.css): try again on the next frames until it takes focus
    var tries = 0;
    var refocus = function () {
      try { back.focus({ preventScroll: true }); } catch (e) { back.focus(); }
      if (document.activeElement !== back && !panelOpen && ++tries < 20) (window.requestAnimationFrame || setTimeout)(refocus);
    };
    refocus();
  }
  function choose(t) {
    if (t.locked) return;
    if (t.slug === active && !P.preview) { close(); return; }
    if (t.slug === P.default) { writeCookie(''); sset('localStorage', STORE, null); }
    else { writeCookie(t.slug); sset('localStorage', STORE, t.slug); }
    sset('sessionStorage', RESTORED, null);
    radios.forEach(function (r) { r.disabled = true; });
    status.textContent = T.switching || 'Switching theme…';
    location.reload();
  }

  btn.addEventListener('click', function () { panelOpen ? close() : open(btn); });
  btn.addEventListener('focus', schedule);
  btn.addEventListener('blur', schedule);
  if (slot) {
    slot.addEventListener('click', function (e) {
      e.preventDefault();
      // an older cached page draws the header button before the stylesheet is there: open as soon as it is
      if (!ready) { pending = true; return; }
      // a focused header button that its header has scrolled away (Enter): the panel opens at the floating button, the
      // control on screen, and the focus comes back to it
      panelOpen ? close() : open(useHead ? slot : btn);
    });
  }
  // The header button goes live (drawn, hit-tested, a Tab stop: the server's CSS hides it and prints it inert until then)
  // once the stylesheet has loaded, i.e. once it can open its panel: never a dead control when this script does not run
  // or its stylesheet does not arrive, and nothing moves (fix round 3, SVVF3-P3-2 / SVVP3-3).
  function slotLive() {
    if (!slot) return;
    slot.removeAttribute('inert');
    slot.setAttribute('data-jv-ts-live', '');
  }

  // ---- first screen (visible modes). The button is laid out but transparent (data-jv-settle: click-through, still a Tab
  // stop that shows itself on keyboard focus) until everything that decides its place has answered, so it appears once,
  // at its final place, as early as possible (fix round 2: no fixed wait):
  //   players   the first report of the player observer (a player under the corner keeps it hidden, D2);
  //   ad engine (#jcx-h, while its script runs) its sticky bar: the engine shows the bar and lifts the button above it in
  //             the same frame, in its first or second layout round after inserting the bar; a bar it keeps hidden
  //             (watch pages, while the player is in view) brings no lift. The wait ends at the lift, or a few frames
  //             after the bar arrived without one (followBar); without a bar yet 450 ms after the stylesheet on watch
  //             pages, and on the other pages when the engine has built its bar or shown that none comes (followEngine;
  //             fix round 4, SVVF4-P3-1: a fixed 450 ms ended before a late bar, and the drawn button jumped up).
  // Never longer than 1.5 s. A page without players and ads shows the button at once.
  var settleT = 0, adsT = 0, waitPlayers = false, waitAds = false, barSeen = false, dcl = false, engineLater = false;
  var raf = window.requestAnimationFrame ? function (f) { window.requestAnimationFrame(f); } : function (f) { setTimeout(f, 16); };
  // before DOMContentLoaded the ad engine's deferred script (after this one in the page) may not have run yet; after
  // it, a page without window.JCX has no running ad engine (blocked by an ad blocker, failed): nothing to wait for
  document.addEventListener('DOMContentLoaded', function () {
    dcl = true;
    if (!waitAds || barSeen) return;
    if (!window.JCX) adsDone();
    else if (engineLater) { engineLater = false; followEngine(); }
  });
  function settled() {
    clearTimeout(settleT); clearTimeout(adsT);
    waitPlayers = waitAds = false;
    // decided now (players, lift): the state is applied before the button is drawn
    if (btn.hasAttribute('data-jv-settle')) { btn.removeAttribute('data-jv-settle'); check(); }
  }
  function settledIf() { if (btn.hasAttribute('data-jv-settle') && !waitPlayers && !waitAds) settled(); }
  function adsDone() { if (waitAds) { waitAds = false; clearTimeout(adsT); settledIf(); } }
  function followBar() {
    if (barSeen || !waitAds) return;
    barSeen = true;
    clearTimeout(adsT);
    var n = 0;
    var step = function () {
      if (!waitAds) return;
      if (btn.style.translate || ++n >= 6) { adsDone(); return; }
      raf(step);
    };
    raf(step);
  }
  // The ad engine on other pages (static/jcx/jcx.js, jcx-l.js) fetches its settings after the load event (at most 2.5 s),
  // loads jcx-l.js only when a fixed unit is due, and builds the bar in an idle callback. Each step is followed with what
  // the engine offers (JCX.ready, load, the script element, an idle callback after the engine's): no polling; a bar that
  // arrives on the way is followed by followBar() (mutation observer).
  function followEngine() {
    var J = window.JCX;
    // its deferred script runs before DOMContentLoaded (handler above)
    if (!J) { engineLater = true; return; }
    if (!J.ready || typeof J.ready.then !== 'function') { adsT = setTimeout(adsDone, 450); return; }
    J.ready.then(engineLoaded, engineLoaded);
  }
  function engineLoaded() {
    if (!waitAds || barSeen) return;
    // the engine continues after the page's load event (its units are set up after it)
    var go = function () { setTimeout(engineUnits, 0); };
    if (document.readyState === 'complete') go(); else window.addEventListener('load', go);
  }
  function engineUnits() {
    if (!waitAds || barSeen) return;
    if (document.querySelector('.jcx-k')) { followBar(); return; }
    // jcx-l.js is requested (synchronously, before JCX.ready answers) only when a fixed unit is due on this page view:
    // without it no bar comes
    var s = document.querySelector('script[src*="static/jcx/jcx-l.js"]');
    if (!s) { adsDone(); return; }
    if (window.JcxL) engineIdle();
    else { s.addEventListener('load', engineIdle); s.addEventListener('error', adsDone); }
  }
  function engineIdle() {
    if (!waitAds || barSeen) return;
    // jcx-l.js asks for an idle callback after the load event before it builds the bar; one asked for after it runs after it
    if (document.readyState !== 'complete') { window.addEventListener('load', engineIdle); return; }
    var f = function () { if (waitAds && !barSeen) { if (document.querySelector('.jcx-k')) followBar(); else adsDone(); } };
    if (window.requestIdleCallback) window.requestIdleCallback(f, { timeout: 2000 }); else setTimeout(f, 0);
  }
  function settle() {
    if (!btn.hasAttribute('data-jv-settle')) return;
    settleT = setTimeout(settled, 1500);
    var head = document.getElementById('jcx-h');
    waitAds = !btn.style.translate && !!head && (!!window.JCX || !dcl);
    if (waitAds) {
      if (document.querySelector('.jcx-k')) followBar();
      else if (head.getAttribute('data-t') === 'play') adsT = setTimeout(adsDone, 450);
      else followEngine();
    }
    settledIf();
  }
  // An engine move of the drawn button after the first screen (a bar after the cap, the bar closed, the cookie notice
  // answered) fades it in at its new place instead of a jump (fix round 4, SVVF4-P3-1). The move stays instant: the engine
  // measures the button's box, a transition of translate gives it a box in mid-move (a wrong lift after a re-dock). Not
  // while transparent anyway, with reduced motion or with the keyboard focus on it.
  var lastTr = '', moveN = 0;
  function moved() {
    var tr = btn.style.translate || '';
    if (tr === lastTr) return;
    lastTr = tr;
    if (!extra || btn.hidden || panelOpen || failed || kbdFocus() || root.classList.contains('jv-ts-reduced')) return;
    if (btn.hasAttribute('data-jv-settle') || btn.hasAttribute('data-jv-hide') || btn.hasAttribute('data-jv-headon')) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var n = ++moveN;
    btn.setAttribute('data-jv-move', '');
    raf(function () { raf(function () { if (n === moveN) btn.removeAttribute('data-jv-move'); }); });
  }

  // ---- mount
  function mount() {
    if (!document.body || document.querySelector('[data-jv-ts]')) return;
    document.body.appendChild(spacer);
    document.body.appendChild(wrap);
    var link = el('link');
    link.rel = 'stylesheet';
    link.href = P.css;
    link.addEventListener('load', function () {
      if (SHOW === 'end') btn.setAttribute('data-jv-park', '');
      // visible modes: transparent until its place is decided (settle(); same task, so nothing is painted before)
      if (extra) btn.setAttribute('data-jv-settle', '');
      btn.hidden = false;
      btn.style.removeProperty('display');
      ready = true;
      if (slot) { slotLive(); headState(); watchSlot(); }
      size();
      if (SHOW === 'end') watchEnd();
      watchPlayers();
      if (extra) settle();
      schedule();
      if (pending && slot) { pending = false; open(slot); }
    });
    link.addEventListener('error', function () { failed = true; wrap.remove(); spacer.remove(); dropSlot(); });
    (document.head || root).appendChild(link);
    document.addEventListener('click', later, true);
    document.addEventListener('keydown', later, true);
    if (extra) {
      document.addEventListener('focusin', schedule, true);
      document.addEventListener('focusout', focusLeft, true);
    }
    window.addEventListener('resize', onResize);
    // visible modes: the re-arm at load keeps the current answer until the new observer reports; "end" as in 1.2.0
    window.addEventListener('load', function () { watchPlayers(extra); schedule(); });
    if (window.MutationObserver) {
      var mo = new MutationObserver(schedule);
      mo.observe(root, { attributes: true, attributeFilter: ['class', 'style', 'data-color-mode'] });
      mo.observe(document.body, { attributes: true, attributeFilter: ['class', 'style'] });
      // the ad engine lifts the button above its sticky bar with an inline translate: re-size the spacer (and, on the
      // first screen, fade the button in at its lifted place in the next frame; later, fade it in at its new place)
      new MutationObserver(function () {
        schedule();
        moved();
        if (btn.style.translate && waitAds) raf(adsDone);
      }).observe(btn, { attributes: true, attributeFilter: ['style'] });
      if (extra) {
        // frames and videos added after load (pre-roll gate, provider frames): arm the player dodge for them too. The
        // ad engine's in-content slots (.jcx-s) never count as players, so their creatives re-arm nothing; the re-arm
        // (it measures the players) waits for an idle moment, so it never lands in a scrolling frame.
        var pt = 0, idle = window.requestIdleCallback;
        var player = function (n) { return !(n.closest && n.closest('.jcx-s')) && !wrap.contains(n); };
        var rearmLate = function () {
          if (!ready) return;
          var go = function () { watchPlayers(true); schedule(); };
          if (idle) idle(go, { timeout: 600 }); else go();
        };
        new MutationObserver(function (records) {
          var hit = false;
          for (var i = 0; i < records.length; i++) {
            var added = records[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
              var n = added[j];
              if (n.nodeType !== 1) continue;
              // the sticky ad bar arrives during the first-screen wait: follow the engine's decision (lift, or bar hidden)
              if (waitAds && n.classList && n.classList.contains('jcx-k')) followBar();
              if (hit) continue;
              if (/^(IFRAME|VIDEO)$/.test(n.nodeName)) hit = player(n);
              else if (n.querySelector && n.querySelector('iframe, video')) hit = Array.prototype.some.call(n.querySelectorAll('iframe, video'), player);
            }
          }
          if (hit) { clearTimeout(pt); pt = setTimeout(rearmLate, 200); }
        }).observe(document.body, { childList: true, subtree: true });
      }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})(window, document);
