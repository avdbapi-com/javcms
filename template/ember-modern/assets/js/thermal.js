/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Ember Thermal (Modern) - behaviour layer | original code (JavCMS Community License 1.0, JavCMS project)
 * Vanilla ES2017, no dependencies (jQuery is used only through the shared MAC runtime for favourites).
 * Images + mode-aware placeholders, theme-color, wordmark fit, header mega panel and dropdowns, phone sheets
 * (focus trap + inert), live search suggestions, recent searches, dock behaviour, hero channels, thermal-vision
 * reveal, description expand, favourites, copy, avatar upload, captcha on demand, history, IR boot layer,
 * centre-band flare on touch, password toggle, cold-trail 404 pointer, jump countdown. */
(function (W, D) {
  'use strict';
  var ROOT = D.documentElement;
  var TPL = (W.maccms && W.maccms.templatePath) || '/';
  var URLS = (W.maccms && W.maccms.template_urls) || {};
  var PH = { dark: TPL + 'assets/brand/ph-dark.svg', light: TPL + 'assets/brand/ph-light.svg' };
  function mq(q) { try { return W.matchMedia(q); } catch (e) { return { matches: false, addEventListener: function () {} }; } }
  var REDUCED = mq('(prefers-reduced-motion: reduce)').matches;
  var FINE = mq('(hover: hover) and (pointer: fine)').matches;
  function mode() { return ROOT.getAttribute('data-color-mode') === 'light' ? 'light' : 'dark'; }
  function msg(key, fb) { var m = W.TemplateMessages || {}; return m[key] || fb || key; }
  function toast(text, kind) { if (W.TemplateUI && typeof W.TemplateUI.message === 'function') W.TemplateUI.message(text, { kind: kind || 'info' }); }
  function store(k, v) { try { if (v === undefined) return W.localStorage.getItem(k); W.localStorage.setItem(k, v); } catch (e) { return null; } return null; }
  function $(s, r) { return (r || D).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); }

  /* ---------- images: mode-aware placeholder on error or missing poster ---------- */
  function isPh(src) { return /\/assets\/brand\/ph-(dark|light)\.svg/.test(src || ''); }
  function toPh(img) {
    if (!img || img.tagName !== 'IMG') return;
    /* card-sized images: hide the broken image and let the box's CSS placeholder (same artwork) show; only the
       monitor screens swap the <img> itself, because the thermal-vision filter needs real image content */
    if (!(img.closest && img.closest('.t-screen,.t-ch,.t-sugg'))) { img.setAttribute('data-broken', '1'); return; }
    img.setAttribute('data-ph', '1');
    img.removeAttribute('srcset');
    var want = PH[mode()];
    if (img.getAttribute('src') !== want) img.setAttribute('src', want);
  }
  function managed(img) { return !!(img.closest && img.closest('.t-thumb,.t-screen,.t-ch,.t-hr-thumb,.t-sugg,.t-avatar,.t-cover')); }
  D.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && managed(t) && !t.hasAttribute('data-ph') && !t.hasAttribute('data-broken')) toPh(t); }, true);
  function initImages(root) {
    $$('.t-thumb img,.t-screen img,.t-ch img,.t-hr-thumb img,.t-sugg img', root).forEach(function (img) {
      if (img.hasAttribute('data-ph') || img.hasAttribute('data-broken')) return;
      var src = img.getAttribute('src') || '';
      if (src === '' || src === '/' || isPh(src) || /\/nopic\.gif(?:[?#]|$)/i.test(src)) { toPh(img); return; }
      if (img.complete && img.naturalWidth === 0 && (img.getAttribute('loading') !== 'lazy' || img.currentSrc)) toPh(img);
    });
  }
  function swapPlaceholders() { $$('img[data-ph]').forEach(function (img) { img.setAttribute('src', PH[mode()]); }); }

  /* ---------- theme-color follows the colour mode; the owner's colour (SiteHead meta with data-jv-head) wins in both modes ---------- */
  function themeColor() {
    var m = $('meta[name="theme-color"]');
    if (m && !$('meta[name="theme-color"][data-jv-head]')) m.setAttribute('content', mode() === 'light' ? '#fff5ec' : '#0b0710');
  }
  new MutationObserver(function () { themeColor(); swapPlaceholders(); }).observe(ROOT, { attributes: true, attributeFilter: ['data-color-mode'] });

  /* RTL pages: an English (untranslated) card title in a 2-line clamp gets dir="ltr", so its "..." ends the
     sentence instead of eating its first letters. Titles with any RTL letter keep the page direction. */
  function markLtrTitles() {
    if ((ROOT.getAttribute('dir') || (D.body && D.body.getAttribute('dir'))) !== 'rtl') return;
    var rtl = /[\u0590-\u08ff\ufb1d-\ufdff\ufe70-\ufeff]/;
    $$('.t-ct a span, .t-hrow .t-hr-t').forEach(function (el) {
      var t = el.textContent;
      if (/[A-Za-z]/.test(t) && !rtl.test(t)) el.setAttribute('dir', 'ltr');
    });
  }

  /* ---------- wordmark fit: tracking down to 0, then size down to 13px, then ellipsis (CSS) ---------- */
  function fitWords() {
    $$('[data-t-fit]').forEach(function (el) {
      el.style.letterSpacing = ''; el.style.fontSize = '';
      var box = el.parentElement, mark = box && box.querySelector('.t-mark');
      if (!box || el.offsetParent === null) return;
      if (el.scrollWidth <= el.clientWidth + 1) return; /* not truncated: keep the designed size */
      /* .t-mark is an <svg>: SVG elements have no offsetWidth, so measure its box */
      var avail = box.clientWidth - (mark ? mark.getBoundingClientRect().width + 10 : 0) - 2;
      if (avail <= 0 || el.scrollWidth <= avail + 1) return;
      el.style.letterSpacing = '0';
      var size = parseFloat(W.getComputedStyle(el).fontSize) || 20;
      var guard = 0;
      while (el.scrollWidth > avail + 1 && size > 13 && guard++ < 20) { size -= 1; el.style.fontSize = size + 'px'; }
    });
  }

  /* ---------- generic disclosure helpers ---------- */
  var lastToggle = null;
  function focusables(root) {
    return $$('a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,[tabindex]:not([tabindex="-1"]),summary', root)
      .filter(function (el) { return el.offsetParent !== null || el === D.activeElement; });
  }

  /* mega panel (desktop categories) */
  function setMega(open, focusBack) {
    var btn = $('[data-t-mega]'), panel = $('#t-mega');
    if (!btn || !panel) return;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var first = panel.querySelector('a'); if (first && focusBack !== false && lastKey) first.focus(); }
    else if (focusBack) btn.focus();
  }
  function megaOpen() { var p = $('#t-mega'); return !!(p && !p.hidden); }

  /* sheets (phone categories / search): focus trap + inert page behind */
  var openSheet = null, sheetTrigger = null;
  function setInert(on, except) {
    $$('body > *').forEach(function (el) {
      if (el === except || el.tagName === 'SCRIPT') return;
      if (on) { if (!el.hasAttribute('inert')) { el.setAttribute('inert', ''); el.setAttribute('data-t-inert', ''); } }
      else if (el.hasAttribute('data-t-inert')) { el.removeAttribute('inert'); el.removeAttribute('data-t-inert'); }
    });
  }
  function showSheet(id, trigger) {
    var sheet = D.getElementById(id);
    if (!sheet) return;
    if (openSheet && openSheet !== sheet) hideSheet(false);
    openSheet = sheet; sheetTrigger = trigger || D.activeElement;
    sheet.hidden = false;
    ROOT.classList.add('t-sheet-open');
    setInert(true, sheet);
    $$('[data-t-sheet="' + id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    var target = sheet.querySelector('input[type="search"]') || sheet.querySelector('[data-t-close].t-ibtn') || focusables(sheet)[0];
    if (target) setTimeout(function () { try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); } }, 30);
    if (sheet.id === 't-search-sheet') renderRecent();
  }
  function hideSheet(restore) {
    if (!openSheet) return;
    var sheet = openSheet;
    openSheet = null;
    sheet.hidden = true;
    ROOT.classList.remove('t-sheet-open');
    setInert(false);
    $$('[data-t-sheet="' + sheet.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    if (restore !== false && sheetTrigger && sheetTrigger.focus) { try { sheetTrigger.focus({ preventScroll: true }); } catch (e) { sheetTrigger.focus(); } }
    sheetTrigger = null;
  }

  /* language / user dropdowns */
  function closeDetails(except) { $$('details.t-lang[open]').forEach(function (d) { if (d !== except) d.open = false; }); }

  /* ---------- live search suggestions (contract 2.10: decode names, textContent, link to /search/?wd=) ---------- */
  var decoder = null;
  function decode(s) {
    s = String(s || '');
    if (s.indexOf('&') < 0) return s;
    try { decoder = decoder || new DOMParser(); return decoder.parseFromString(s, 'text/html').documentElement.textContent || s; } catch (e) { return s; }
  }
  function searchUrl(q) {
    var base = URLS.search || (((W.maccms && W.maccms.path) || '') + '/index.php/vod/search');
    return base + (base.indexOf('?') < 0 ? '?' : '&') + 'wd=' + encodeURIComponent(q);
  }
  function initSuggest(form) {
    var input = form.querySelector('input[name="wd"]'), list = form.querySelector('.t-sugg'), ring = form.querySelector('.t-scan-ring');
    if (!input || !list) return;
    var timer = 0, lastQ = '', lastAt = 0, active = -1, ctl = null;
    function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
    function items() { return $$('a', list); }
    function mark(i) {
      var all = items();
      all.forEach(function (a, k) { a.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      active = i;
      if (all[i]) { input.setAttribute('aria-activedescendant', all[i].id); all[i].scrollIntoView({ block: 'nearest' }); }
    }
    function render(rows, q) {
      list.textContent = '';
      if (!rows.length) {
        var p = D.createElement('p'); p.textContent = msg('emt.no_suggestions', 'No heat signature for this search'); list.appendChild(p);
      }
      rows.slice(0, 8).forEach(function (r, k) {
        var name = decode(r.name || r.en || '');
        if (!name) return;
        var a = D.createElement('a');
        a.href = searchUrl(name); a.id = input.id + '-opt-' + k; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false');
        var img = D.createElement('img'); img.alt = ''; img.width = 64; img.height = 36; img.loading = 'lazy'; img.decoding = 'async';
        var pic = String(r.pic || '');
        if (/^(https?:)?\/\//i.test(pic) || pic.charAt(0) === '/') img.src = pic; else img.src = PH[mode()];
        var dot = D.createElement('i'); dot.className = 't-dot'; dot.setAttribute('aria-hidden', 'true');
        var t = D.createElement('span'); t.className = 't-s-txt'; t.textContent = name;
        a.appendChild(img); a.appendChild(t); a.appendChild(dot);
        list.appendChild(a);
      });
      list.hidden = false; input.setAttribute('aria-expanded', 'true'); active = -1;
    }
    function fetchRows(q) {
      if (!URLS.suggest || !W.fetch) return;
      lastAt = Date.now();
      if (ctl && ctl.abort) ctl.abort();
      ctl = W.AbortController ? new AbortController() : null;
      W.fetch(URLS.suggest + '?mid=1&limit=10&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: ctl ? ctl.signal : undefined })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { if (input.value.trim() !== q) return; render(j && Number(j.code) === 1 && Array.isArray(j.list) ? j.list : [], q); })
        .catch(function () {});
    }
    input.addEventListener('input', function () {
      var q = input.value.trim();
      if (ring) { ring.classList.add('is-typing'); clearTimeout(ring._t); ring._t = setTimeout(function () { ring.classList.remove('is-typing'); }, 900); }
      clearTimeout(timer);
      if (q.length < 2) { close(); lastQ = ''; return; }
      if (q === lastQ) return;
      var wait = Math.max(300, 300 - (Date.now() - lastAt));
      timer = setTimeout(function () { lastQ = q; fetchRows(q); }, wait);
    });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      var all = items();
      if (e.key === 'ArrowDown') { e.preventDefault(); if (all.length) mark((active + 1) % all.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (all.length) mark((active - 1 + all.length) % all.length); }
      else if (e.key === 'Enter' && active >= 0 && all[active]) { e.preventDefault(); W.location.href = all[active].href; }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } /* keep the typed text (type=search clears on Esc) */
    });
    input.addEventListener('blur', function () { setTimeout(function () { if (!form.contains(D.activeElement)) close(); }, 160); });
    form.addEventListener('submit', function (e) {
      var q = input.value.trim();
      if (!q) { e.preventDefault(); input.focus(); return; }
      saveRecent(q);
    });
  }

  /* ---------- recent searches (per browser, localStorage) ---------- */
  function readRecent() { try { var a = JSON.parse(store('emt.recent') || '[]'); return Array.isArray(a) ? a.filter(function (x) { return typeof x === 'string'; }).slice(0, 8) : []; } catch (e) { return []; } }
  function saveRecent(q) { var a = readRecent().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); }); a.unshift(q.slice(0, 60)); store('emt.recent', JSON.stringify(a.slice(0, 8))); }
  function renderRecent() {
    var box = $('[data-t-recent]'); if (!box) return;
    var chips = box.querySelector('.t-chips'), a = readRecent();
    chips.textContent = '';
    a.forEach(function (q) { var l = D.createElement('a'); l.href = searchUrl(q); l.textContent = q; chips.appendChild(l); });
    box.hidden = !a.length;
  }

  /* ---------- dock: hidden while typing, idle glow ring ---------- */
  function initDock() {
    var dock = $('.t-dock');
    if (!dock) return;
    D.addEventListener('focusin', function (e) { var t = e.target; if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') && !/^(button|submit|checkbox|radio|file|range)$/.test(t.type || '')) ROOT.classList.add('t-input-focus'); });
    D.addEventListener('focusout', function () { setTimeout(function () { var a = D.activeElement; if (!a || !/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) ROOT.classList.remove('t-input-focus'); }, 60); });
    var idle = 0;
    function poke() { ROOT.classList.remove('t-dock-idle'); clearTimeout(idle); idle = setTimeout(function () { ROOT.classList.add('t-dock-idle'); }, 2600); }
    W.addEventListener('scroll', poke, { passive: true });
    W.addEventListener('touchstart', poke, { passive: true });
    poke();
  }

  /* ---------- hero channels: CH 01-05, auto-advance 6 s, pause on hover/focus/off-screen ---------- */
  function initChannels() {
    var box = $('[data-t-channels]');
    if (!box) return;
    var hero = box.closest('.t-hero') || D;
    var chans = $$('.t-ch', box), pause = box.querySelector('[data-t-pause]');
    var screen = $('.t-monitor .t-screen', hero);
    if (!chans.length || !screen) return;
    var cur = 0, timer = 0, visible = true, hovered = false, paused = REDUCED, MS = 6000;
    box.style.setProperty('--ch-ms', MS + 'ms');
    function all(attr) { return $$('[' + attr + ']', hero); }
    var F = {
      title: all('data-ch-title'), code: all('data-ch-code'), dur: all('data-ch-dur'), heat: all('data-ch-heat'), views: all('data-ch-views'),
      num: all('data-ch-num'), watch: all('data-ch-watch'), detail: all('data-ch-detail')
    };
    function setText(list, v) {
      list.forEach(function (el) { el.textContent = v || ''; var wrap = el.closest('[data-ch-opt]'); if (wrap) wrap.hidden = !v; });
    }
    function show(i, user) {
      i = (i + chans.length) % chans.length;
      var c = chans[i], d = c.dataset;
      chans.forEach(function (b, k) { b.setAttribute('aria-pressed', k === i ? 'true' : 'false'); });
      cur = i;
      setText(F.title, d.title); setText(F.code, d.code); setText(F.dur, d.dur); setText(F.views, d.views); setText(F.num, d.num);
      screen.setAttribute('aria-label', (screen.getAttribute('data-sr-prefix') || '') + ': ' + (d.title || ''));
      F.heat.forEach(function (el) { el.className = el.className.replace(/\bheat-\d\b/g, '').trim() + ' heat-' + (d.tier || 0); });
      F.title.forEach(function (el) { if (el.tagName === 'A') el.href = d.detail; });
      F.watch.forEach(function (el) { el.href = d.play; });
      F.detail.forEach(function (el) { el.href = d.detail; });
      screen.href = d.play;
      $$('img', screen).forEach(function (img) { img.removeAttribute('data-ph'); img.removeAttribute('data-broken'); img.src = d.pic; });
      if (!REDUCED) { screen.classList.add('is-ir'); setTimeout(function () { screen.classList.remove('is-ir'); }, 320); }
      restartBar();
      if (user) schedule();
    }
    function restartBar() {
      box.classList.remove('is-run');
      if (paused || REDUCED) return;
      void box.offsetWidth;
      box.classList.add('is-run');
    }
    function running() { return !paused && visible && !hovered && !D.hidden; }
    function schedule() {
      clearTimeout(timer);
      box.classList.toggle('is-paused', !running());
      if (!running()) return;
      timer = setTimeout(function () { if (running()) show(cur + 1, false); schedule(); }, MS);
    }
    chans.forEach(function (b, k) { b.addEventListener('click', function () { show(k, true); }); });
    var prev = box.querySelector('[data-t-prev]'), next = box.querySelector('[data-t-next]');
    if (prev) prev.addEventListener('click', function () { show(cur - 1, true); });
    if (next) next.addEventListener('click', function () { show(cur + 1, true); });
    if (pause) {
      pause.setAttribute('aria-pressed', paused ? 'true' : 'false');
      pause.addEventListener('click', function () { paused = !paused; pause.setAttribute('aria-pressed', paused ? 'true' : 'false'); restartBar(); schedule(); });
    }
    var mon = box.closest('.t-monitor') || box;
    mon.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') { hovered = true; schedule(); } });
    mon.addEventListener('pointerleave', function () { hovered = false; schedule(); });
    mon.addEventListener('focusin', function () { hovered = true; schedule(); });
    mon.addEventListener('focusout', function () { hovered = false; schedule(); });
    if ('IntersectionObserver' in W) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; schedule(); }, { threshold: 0.2 }).observe(mon);
    D.addEventListener('visibilitychange', schedule);
    /* keyboard arrows move between channels (Home/End jump; RTL mirrors left/right) */
    box.addEventListener('keydown', function (e) {
      var i = chans.indexOf(e.target);
      if (i < 0) return;
      var rtl = ROOT.getAttribute('dir') === 'rtl', k = e.key, to = -1;
      if (k === 'ArrowRight' || k === 'ArrowDown') to = i + (rtl && k === 'ArrowRight' ? -1 : 1);
      else if (k === 'ArrowLeft' || k === 'ArrowUp') to = i + (rtl && k === 'ArrowLeft' ? 1 : -1);
      else if (k === 'Home') to = 0;
      else if (k === 'End') to = chans.length - 1;
      else return;
      e.preventDefault();
      to = (to + chans.length) % chans.length;
      show(to, true);
      chans[to].focus();
    });
    restartBar(); schedule();
  }

  /* ---------- thermal-vision reveal: the IR duplicate fades to true colour once the image is ready ---------- */
  function initReveal() {
    $$('.t-screen.is-ir').forEach(function (sc) {
      if (REDUCED) { sc.classList.remove('is-ir'); return; }
      var img = sc.querySelector('.t-true');
      function go() { setTimeout(function () { sc.classList.remove('is-ir'); }, 260); }
      if (!img || img.complete) go(); else { img.addEventListener('load', go, { once: true }); img.addEventListener('error', go, { once: true }); setTimeout(go, 2500); }
    });
  }

  /* ---------- description expand ---------- */
  function initExpand() {
    $$('[data-t-readmore]').forEach(function (b) {
      var t = D.getElementById(b.getAttribute('aria-controls'));
      if (!t) return;
      if (t.scrollHeight <= t.clientHeight + 4) { t.classList.add('is-short'); b.hidden = true; }
      b.addEventListener('click', function () {
        var open = b.getAttribute('aria-expanded') !== 'true';
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        t.classList.toggle('is-open', open);
      });
    });
  }

  /* ---------- favourites (user/ajax_ulog ac=set type=2; secure-forms.js adds the token) ---------- */
  function favorite(button) {
    var logged = W.MAC && W.MAC.User && String(W.MAC.User.IsLogin) === '1';
    if (!logged) { if (URLS.user_login) W.location.href = URLS.user_login; return; }
    if (!W.jQuery || !URLS.user_ajax_ulog || button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    W.jQuery.ajax({ url: URLS.user_ajax_ulog, type: 'GET', dataType: 'json', cache: false,
      data: { ac: 'set', mid: button.getAttribute('data-mid') || 1, id: button.getAttribute('data-id'), type: 2 } })
      .done(function (r) {
        var ok = r && Number(r.code) === 1;
        toast(r && r.msg ? String(r.msg) : msg('favorites', 'Favorites'), ok ? 'success' : 'error');
        if (ok) { button.setAttribute('aria-pressed', 'true'); D.dispatchEvent(new CustomEvent('t:fav', { detail: { el: button } })); }
      })
      .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); })
      .always(function () { button.removeAttribute('aria-busy'); });
  }

  /* ---------- legacy copy target (browser page) ---------- */
  function copyText(text) {
    function done() { toast(msg('ui.link_copied', 'Link copied'), 'success'); }
    function legacy() {
      var area = D.createElement('textarea'); area.value = text; area.setAttribute('readonly', ''); area.style.position = 'fixed'; area.style.opacity = '0';
      D.body.appendChild(area); area.select(); var ok = false; try { ok = D.execCommand('copy'); } catch (e) {} D.body.removeChild(area);
      if (ok) done(); else toast(msg('ui.select_and_copy_the_address', 'Select and copy the address'), 'error');
    }
    if (navigator.clipboard && W.isSecureContext) navigator.clipboard.writeText(text).then(done, legacy); else legacy();
  }

  /* ---------- avatar upload (user/info) ---------- */
  function initAvatar() {
    $$('.gallery-avatar-form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        var input = form.querySelector('input[type="file"]'), button = form.querySelector('button[type="submit"]'), status = form.querySelector('.gallery-avatar-status');
        function report(text, kind) { if (status) status.textContent = text; toast(text, kind); }
        if (!input || !input.files.length) { report(msg('select_avatar_file'), 'error'); if (input) input.focus(); return; }
        if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { report(msg('avatar_upload_failed'), 'error'); input.focus(); return; }
        var endpoint = new URL(form.action, location.href);
        if (endpoint.origin !== location.origin) return;
        if (button) button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        if (status) status.textContent = msg('template_ui_working');
        fetch(endpoint.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { if (!r.ok) throw new Error('upload'); return r.json(); })
          .then(function (result) {
            if (result && typeof result.__token__ === 'string') $$('input[name="__token__"]').forEach(function (t) { t.value = result.__token__; });
            if (Number(result && result.code) !== 1) { report(result && typeof result.msg === 'string' ? result.msg : msg('ui.connection_failed_try_again'), 'error'); return; }
            report(msg('avatar_updated'), 'success');
            W.location.reload();
          })
          .catch(function () { report(msg('ui.connection_failed_try_again'), 'error'); })
          .then(function () { if (button) button.disabled = false; form.removeAttribute('aria-busy'); });
      });
    });
  }

  /* ---------- captcha (contract 2.9): requested only when the visitor reaches the field, never on page view ---------- */
  function loadVerify(img, fresh) {
    var src = img && img.getAttribute('data-src');
    if (!src || (!fresh && img.hasAttribute('data-loaded'))) return;
    img.setAttribute('data-loaded', '');
    img.src = src + (src.indexOf('?') < 0 ? '?' : '&') + 'r=' + Date.now();
  }
  D.addEventListener('focusin', function (e) {
    var t = e.target, field = t && t.tagName === 'INPUT' && t.closest ? t.closest('.verification-field') : null;
    if (field) loadVerify(field.querySelector('img.t-verify'), false);
  });

  /* ---------- IR boot layer under the player iframe ---------- */
  function initIrBoot() {
    var layer = $('.t-irboot');
    if (!layer) return;
    var box = layer.parentElement;
    function done() { layer.classList.add('is-done'); }
    setTimeout(done, 4000);
    function hook(f) { if (f && !f._t) { f._t = 1; f.addEventListener('load', function () { setTimeout(done, 400); }, { once: true }); } }
    $$('iframe', box).forEach(hook);
    if ('MutationObserver' in W) {
      var mo = new MutationObserver(function () { $$('iframe', box).forEach(hook); });
      mo.observe(box, { childList: true, subtree: true });
      setTimeout(function () { mo.disconnect(); }, 8000);
    }
  }

  /* ---------- touch: the card crossing the viewport's centre band flares (graft from Ember Noir "ignite") ---------- */
  var flareIO = null;
  function initFlare(root) {
    if (FINE || !('IntersectionObserver' in W)) return;
    if (!flareIO) flareIO = new IntersectionObserver(function (en) {
      en.forEach(function (x) { x.target.classList.toggle('is-flare', x.isIntersecting); });
    }, { rootMargin: '-40% 0px -40% 0px', threshold: 0 });
    $$('.t-grid > .t-card', root).forEach(function (c) { if (!c._fl) { c._fl = 1; flareIO.observe(c); } });
  }

  /* ---------- password show/hide ---------- */
  function initPwd() {
    $$('[data-t-pwd]').forEach(function (b) {
      var input = D.getElementById(b.getAttribute('aria-controls'));
      if (!input) return;
      b.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.setAttribute('aria-pressed', show ? 'true' : 'false');
        b.setAttribute('aria-label', show ? msg('emt.hide_password', 'Hide password') : msg('emt.show_password', 'Show password'));
      });
    });
  }

  /* ---------- 404: the cold numerals warm where the pointer comes near ---------- */
  function init404() {
    var n = $('.t-404num');
    if (!n || !FINE || REDUCED) return;
    var raf = 0, ev = null;
    W.addEventListener('pointermove', function (e) {
      ev = e; if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = 0; var r = n.getBoundingClientRect();
        n.style.setProperty('--px', (ev.clientX - r.left) + 'px'); n.style.setProperty('--py', (ev.clientY - r.top) + 'px');
      });
    }, { passive: true });
  }

  /* ---------- keep a focused strip item clear of the strip's edge fade ---------- */
  D.addEventListener('focusin', function (e) {
    var t = e.target, strip = t && t.closest ? t.closest('.t-sorts, .t-sibs') : null;
    if (!strip || strip.scrollWidth <= strip.clientWidth + 1) return;
    var s = strip.getBoundingClientRect(), r = t.getBoundingClientRect(), pad = 16, dx = 0;
    if (r.right > s.right - pad) dx = r.right - (s.right - pad); else if (r.left < s.left + pad) dx = r.left - (s.left + pad);
    if (dx) strip.scrollLeft += dx;
  });

  /* ---------- events ---------- */
  var lastKey = false;
  D.addEventListener('keydown', function () { lastKey = true; }, true);
  D.addEventListener('pointerdown', function () { lastKey = false; }, true);
  D.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var code = t.closest('img.t-verify');
    if (code) { if (code.classList.contains('mac_verify_img')) code.setAttribute('data-loaded', ''); else loadVerify(code, true); return; }
    var megaBtn = t.closest('[data-t-mega]');
    /* a keyboard click (Enter/Space: detail 0) moves focus into the panel; a pointer click leaves focus on the button */
    if (megaBtn) { e.preventDefault(); setMega(!megaOpen(), e.detail === 0); return; }
    if (megaOpen() && !t.closest('#t-mega')) setMega(false);
    var sheetBtn = t.closest('[data-t-sheet]');
    if (sheetBtn) { e.preventDefault(); var id = sheetBtn.getAttribute('data-t-sheet'); if (openSheet && openSheet.id === id) hideSheet(); else showSheet(id, sheetBtn); return; }
    if (t.closest('[data-t-close]')) { e.preventDefault(); hideSheet(); return; }
    closeDetails(t.closest('details.t-lang'));
    var fav = t.closest('[data-t-fav]');
    if (fav) { e.preventDefault(); favorite(fav); return; }
    var copyTarget = t.closest('[data-copy-target]');
    if (copyTarget) { var input = D.getElementById(copyTarget.getAttribute('data-copy-target')); if (input) { e.preventDefault(); copyText(input.value || input.textContent || ''); } return; }
    var top = t.closest('[data-t-top]');
    if (top) { e.preventDefault(); W.scrollTo({ top: 0, behavior: REDUCED ? 'auto' : 'smooth' }); var main = D.getElementById('main-content'); if (main) { try { main.focus({ preventScroll: true }); } catch (err) {} } }
  });
  D.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (openSheet) { e.preventDefault(); hideSheet(); return; }
      if (megaOpen()) { setMega(false, true); return; }
      var open = $('details.t-lang[open]');
      if (open) { open.open = false; var s = open.querySelector('summary'); if (s) s.focus(); }
      return;
    }
    if (e.key === 'Tab' && openSheet) {
      var f = focusables(openSheet);
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && D.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && D.activeElement === last) { e.preventDefault(); first.focus(); }
      return;
    }
    /* the mega panel sits after the header tools in the DOM: Tab order behaves as if it followed its button */
    if (e.key === 'Tab' && megaOpen()) {
      var mp = $('#t-mega'), mb = $('[data-t-mega]'), links = focusables(mp), a = D.activeElement;
      if (!mb || !links.length) return;
      if (a === mb && !e.shiftKey) { e.preventDefault(); links[0].focus(); }
      else if (a === links[0] && e.shiftKey) { e.preventDefault(); mb.focus(); }
      else if (a === links[links.length - 1] && !e.shiftKey) {
        var order = focusables(D.body).filter(function (el) { return !mp.contains(el); }), next = order[order.indexOf(mb) + 1];
        e.preventDefault(); setMega(false); if (next) next.focus(); else mb.focus();
      }
    }
  });
  D.addEventListener('focusin', function (e) {
    if (megaOpen()) { var p = $('#t-mega'), b = $('[data-t-mega]'); if (p && !p.contains(e.target) && e.target !== b) setMega(false); }
    var det = $('details.t-lang[open]');
    if (det && !det.contains(e.target)) det.open = false;
  });
  var rsT = 0, rsW = 0;
  W.addEventListener('resize', function () {
    if (W.innerWidth === rsW) return;
    clearTimeout(rsT);
    rsT = setTimeout(function () { rsW = W.innerWidth; fitWords(); if (openSheet && W.innerWidth >= 1024) hideSheet(false); }, 120);
  });

  function ready() {
    rsW = W.innerWidth;
    themeColor();
    initImages();
    markLtrTitles();
    if (D.readyState !== 'complete') W.addEventListener('load', function () { initImages(); }, { once: true });
    fitWords();
    if (D.fonts && D.fonts.ready) D.fonts.ready.then(fitWords);
    /* late font swaps (Bricolage arrives after first paint) and the final layout re-run the wordmark fit */
    if (D.fonts && D.fonts.addEventListener) D.fonts.addEventListener('loadingdone', fitWords);
    W.addEventListener('load', fitWords, { once: true });
    $$('form[data-t-search]').forEach(initSuggest);
    initDock();
    initChannels();
    initReveal();
    initExpand();
    initAvatar();
    initIrBoot();
    initFlare();
    initPwd();
    init404();
    var entry = $('.gallery-history-entry');
    if (entry && W.MAC && W.MAC.History && typeof W.MAC.History.Set === 'function') {
      try { W.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (err) {}
    }
    var wait = $('#wait');
    if (wait) { var ring = $('.t-ring'); if (ring) ring.style.setProperty('--wait', (parseInt(wait.textContent, 10) || 3) + 's'); }
    if ('MutationObserver' in W) {
      new MutationObserver(function (list) {
        list.forEach(function (m) { $$('.t-card', m.target).length && initFlare(m.target); });
      }).observe(D.body, { childList: true, subtree: true });
    }
  }
  if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', ready); else ready();
})(window, document);
