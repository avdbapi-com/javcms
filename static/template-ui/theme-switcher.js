/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * Contains third-party code under its own license, named in NOTICE. This license covers the JavCMS parts only.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS visitor theme switcher | JavCMS Community License 1.0 (project-owned) | palette icon: Lucide (ISC) */
/* Injected by the core (app\common\util\ThemeSwitcher) before </body> with a data-jv-theme payload.
   The server renders the theme from the jv_theme cookie; localStorage 'javcms.theme' mirrors it.
   No dependencies, no innerHTML, nothing runs on scroll. */
(function (window, document) {
  'use strict';
  if (window.__jvTs || window.top !== window.self) return;
  window.__jvTs = 1;

  var script = document.currentScript || document.querySelector('script[data-jv-theme]');
  var P;
  try { P = JSON.parse(script.getAttribute('data-jv-theme')); } catch (e) { return; }
  if (!P || P.v !== 1 || !P.themes || !P.themes.length) return;

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

  // ---- floating button
  root.setAttribute('data-jv-switcher-pos', P.pos === 'bottom-start' ? 'bottom-start' : 'bottom-end');
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
  btn.hidden = true; // shown once the stylesheet has loaded (no unstyled flash; position:fixed = no layout shift)
  wrap.appendChild(btn);
  var status = el('div', 'jv-ts-sr');
  status.setAttribute('role', 'status');
  wrap.appendChild(status);
  var spacer = el('div', 'jv-ts-spacer');
  spacer.setAttribute('aria-hidden', 'true');

  // ---- obstruction checks (one rAF-coalesced function; review A2/A4/A6, C5)
  // kit-polish: the button never sits over page content. It is shown only while the reserved spacer at the
  // page end is fully in view (the spacer is sized to the button's real band, including theme offsets and the
  // ad engine's lift above its sticky bar). Elsewhere it is "parked": transparent and click-through, but it
  // stays in the Tab order and becomes visible while it has keyboard focus.
  var queued = 0, dodge = false, panelOpen = false, atEnd = false, spacerH = 0;
  function schedule() { if (!queued) { queued = 1; (window.requestAnimationFrame || setTimeout)(check); } }
  function later() { schedule(); setTimeout(schedule, 360); setTimeout(schedule, 1200); }
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
      if (wrap.contains(openers[i])) continue;
      var ids = (openers[i].getAttribute('aria-controls') || '').split(/\s+/);
      for (var j = 0; j < ids.length; j++) {
        var target = ids[j] && document.getElementById(ids[j]);
        if (target && !wrap.contains(target) && visible(target) && hits(r, target.getBoundingClientRect(), 8)) return true;
      }
    }
    var toasts = document.querySelectorAll('.gallery-status:not([hidden]), .tui-notices > *');
    for (var k = 0; k < toasts.length; k++) {
      if (visible(toasts[k]) && hits(r, toasts[k].getBoundingClientRect(), 8)) { setTimeout(schedule, 800); return true; }
    }
    return false;
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
  function check() {
    queued = 0;
    if (panelOpen) return;
    size();
    pageTone();
    // a button that has focus is never hidden (focus would drop to <body>, e.g. when a theme toast shows on Esc)
    var hide = document.activeElement !== btn && (dodge || locked() || covered());
    if (hide) btn.setAttribute('data-jv-hide', ''); else btn.removeAttribute('data-jv-hide');
    if (atEnd) btn.removeAttribute('data-jv-park'); else btn.setAttribute('data-jv-park', '');
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
  var io = null;
  function watchPlayers() {
    if (io) { io.disconnect(); io = null; }
    dodge = false;
    if (!('IntersectionObserver' in window) || btn.hidden) return;
    var vw = window.innerWidth, players = [];
    var list = document.querySelectorAll('iframe, video');
    for (var i = 0; i < list.length; i++) if (list[i].getBoundingClientRect().width > vw * 0.5) players.push(list[i]);
    if (!players.length) return;
    var r = btn.getBoundingClientRect(), vh = window.innerHeight;
    if (!r.width) return;
    var m = [-(Math.max(0, r.top - 8)), -(Math.max(0, vw - r.right - 8)), -(Math.max(0, vh - r.bottom - 8)), -(Math.max(0, r.left - 8))];
    var state = new Map();
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { state.set(e.target, e.isIntersecting); });
      var any = false; state.forEach(function (v) { if (v) any = true; });
      if (any !== dodge) { dodge = any; schedule(); }
    }, { rootMargin: m.map(function (v) { return Math.round(v) + 'px'; }).join(' '), threshold: 0 });
    players.forEach(function (p) { io.observe(p); });
  }
  var rt = 0;
  function onResize() { clearTimeout(rt); rt = setTimeout(function () { if (panelOpen) place(); watchPlayers(); schedule(); }, 200); }

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
  function place() {
    if (window.matchMedia('(max-width: 767px), (pointer: coarse) and (max-height: 500px)').matches) {
      panel.style.left = panel.style.right = panel.style.bottom = panel.style.maxHeight = '';
      return;
    }
    var r = btn.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
    var bottom = Math.max(8, vh - r.top + 10);
    panel.style.bottom = bottom + 'px';
    panel.style.maxHeight = Math.max(240, vh - bottom - 16) + 'px';
    if (r.left + r.width / 2 > vw / 2) { panel.style.right = Math.max(16, vw - r.right) + 'px'; panel.style.left = 'auto'; }
    else { panel.style.left = Math.max(16, r.left) + 'px'; panel.style.right = 'auto'; }
  }
  function open() {
    if (panelOpen) return;
    if (!panel) build();
    panelOpen = true;
    place();
    inerted = [];
    Array.prototype.forEach.call(document.body.children, function (n) {
      if (n === wrap || n.nodeName === 'SCRIPT') return;
      inerted.push([n, n.hasAttribute('inert'), n.getAttribute('aria-hidden')]);
      n.setAttribute('inert', ''); n.setAttribute('aria-hidden', 'true');
    });
    root.classList.add('jv-ts-lock');
    wrap.classList.add('jv-ts-open');
    btn.setAttribute('aria-expanded', 'true');
    if (window.MutationObserver) {
      colorObs = new MutationObserver(swapShots);
      colorObs.observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });
    }
    var target = radios.filter(function (r) { return r.tabIndex === 0; })[0] || panel;
    try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
    document.addEventListener('keydown', docKeys, true);
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
    btn.setAttribute('aria-expanded', 'false');
    check();
    // Focus always returns to the floating button (the control that opened the dialog).
    var refocus = function () { try { btn.focus({ preventScroll: true }); } catch (e) { btn.focus(); } };
    refocus();
    if (document.activeElement !== btn) setTimeout(refocus, 0);
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

  btn.addEventListener('click', function () { panelOpen ? close() : open(); });
  btn.addEventListener('focus', schedule);
  btn.addEventListener('blur', schedule);

  // ---- mount
  function mount() {
    if (!document.body || document.querySelector('[data-jv-ts]')) return;
    document.body.appendChild(spacer);
    document.body.appendChild(wrap);
    var link = el('link');
    link.rel = 'stylesheet';
    link.href = P.css;
    link.addEventListener('load', function () { btn.setAttribute('data-jv-park', ''); btn.hidden = false; size(); watchEnd(); watchPlayers(); schedule(); });
    link.addEventListener('error', function () { wrap.remove(); spacer.remove(); });
    (document.head || root).appendChild(link);
    document.addEventListener('click', later, true);
    document.addEventListener('keydown', later, true);
    window.addEventListener('resize', onResize);
    window.addEventListener('load', function () { watchPlayers(); schedule(); });
    if (window.MutationObserver) {
      var mo = new MutationObserver(schedule);
      mo.observe(root, { attributes: true, attributeFilter: ['class', 'style', 'data-color-mode'] });
      mo.observe(document.body, { attributes: true, attributeFilter: ['class', 'style'] });
      // the ad engine lifts the button above its sticky bar with an inline translate: re-size the spacer
      mo.observe(btn, { attributes: true, attributeFilter: ['style'] });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})(window, document);
