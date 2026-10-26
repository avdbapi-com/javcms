/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Topaz Prism (Modern) - core behaviour (classic script, works without modules) | original work for JavCMS (JavCMS Community License 1.0)
   Crystal command palette (modal / bottom sheet, combobox + listbox, suggestions, recent searches), two-tier header collapse,
   vitrine feature selector, spectral converge trigger, segmented control thumb, stagger entrance, member state, favourites,
   share / copy, password eye, captcha on demand, avatar upload, comments, wordmark fit and theme-colour sync. jQuery is used
   only for the token-aware account calls that secure-forms.js protects. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var desktop = function () { return window.innerWidth >= 1024; };
  root.classList.add('tp-js');

  function text(key, fallback) {
    var t = window.TpText || {}, m = window.TemplateMessages || {};
    return typeof t[key] === 'string' ? t[key] : (typeof m[key] === 'string' ? m[key] : (fallback || ''));
  }
  function fill(str, values) {
    Object.keys(values || {}).forEach(function (k) { str = str.split('{' + k + '}').join(String(values[k])); });
    return str;
  }
  function toast(message, kind) {
    if (!message) return;
    if (window.TemplateUI && typeof window.TemplateUI.message === 'function') window.TemplateUI.message(String(message), { kind: kind || 'info' });
  }
  function cookie(name) {
    var parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length < 2) return '';
    try { return decodeURIComponent(parts.pop().split(';').shift()); } catch (e) { return ''; }
  }
  function on(sel, type, fn, capture) {
    document.addEventListener(type, function (e) {
      var t = e.target && e.target.closest ? e.target.closest(sel) : null;
      if (t) fn(e, t);
    }, !!capture);
  }
  function routes() { return (window.maccms && window.maccms.template_urls) || {}; }
  function base() { return (window.maccms && window.maccms.path) || ''; }
  function storeGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function storeSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  function focusables(box) {
    return Array.prototype.filter.call(box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'), function (el) {
      return !el.hidden && !el.closest('[hidden]') && (el.offsetWidth || el.offsetHeight || el === document.activeElement);
    });
  }
  /* Safari / WebKit: the palette opens and closes without the same-document morph. WebKit 26 crashes its renderer on the
     second open/close View Transition of the palette (tools/wk-probe.js --keys, 2026-10-12); Chromium keeps the morph. */
  var webkitVendor = /Apple Computer/i.test(navigator.vendor || '');
  function withTransition(update) {
    if (!reduceMotion && !webkitVendor && window.FX && typeof window.FX.transition === 'function') { try { window.FX.transition(update); return; } catch (e) { /* plain */ } }
    update();
  }

  /* 1. theme-colour meta + mode button labels follow data-color-mode */
  (function () {
    var meta = document.querySelector('meta[name="theme-color"][data-tp-theme-color]');
    function sync() {
      var light = root.getAttribute('data-color-mode') === 'light';
      if (meta) meta.setAttribute('content', meta.getAttribute(light ? 'data-light' : 'data-dark') || '');
      document.querySelectorAll('.tp-mode').forEach(function (b) {
        var label = b.getAttribute(light ? 'data-dark-label' : 'data-light-label');
        if (label) { b.setAttribute('aria-label', label); b.setAttribute('title', label); }
      });
    }
    sync();
    if (window.MutationObserver) new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });
    /* the shortcut pill reads ⌘K on Apple platforms (the handler accepts metaKey) */
    if (/Mac|iPhone|iPad/.test(navigator.platform || '')) document.querySelectorAll('.tp-pill kbd').forEach(function (k) { k.textContent = '⌘K'; });
  })();

  /* 2. two-tier header collapses to one glass tier after 40 px of scroll (hysteresis, rAF-throttled) */
  (function () {
    var hd = document.getElementById('tp-hd');
    if (!hd) return;
    var ticking = false, collapsed = false;
    function update() {
      ticking = false;
      var y = window.scrollY || window.pageYOffset || 0;
      var next = collapsed ? y > 12 : y > 40;
      if (next !== collapsed) { collapsed = next; hd.classList.toggle('is-collapsed', collapsed); }
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }, { passive: true });
    update();
  })();

  /* 3. wordmark fit: tracking down, then size down to 13 px, then the CSS ellipsis */
  (function () {
    var marks = document.querySelectorAll('[data-tp-fit]');
    if (!marks.length) return;
    function fit() {
      marks.forEach(function (el) {
        el.style.letterSpacing = ''; el.style.fontSize = '';
        if (el.scrollWidth <= el.clientWidth + 1) return;
        var steps = ['.2em', '.12em', '.06em', '0'];
        for (var i = 0; i < steps.length && el.scrollWidth > el.clientWidth + 1; i++) el.style.letterSpacing = steps[i];
        var size = parseFloat(window.getComputedStyle(el).fontSize) || 14;
        while (el.scrollWidth > el.clientWidth + 1 && size > 13) { size -= 1; el.style.fontSize = size + 'px'; }
      });
    }
    fit();
    var rt = 0;
    window.addEventListener('resize', function () { window.clearTimeout(rt); rt = window.setTimeout(fit, 150); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  })();

  /* 4. member state from the login cookies (pages are cached, so never rendered server-side) */
  (function () {
    var uid = cookie('user_id');
    if (!uid || uid === '0') return;
    var name = cookie('user_name');
    document.querySelectorAll('[data-tp-guest]').forEach(function (el) { el.hidden = true; });
    document.querySelectorAll('[data-tp-member]').forEach(function (el) { el.hidden = false; });
    if (name) document.querySelectorAll('[data-tp-user-name]').forEach(function (el) { el.textContent = name; });
    var idx = routes().user_index;
    document.querySelectorAll('[data-tp-acct]').forEach(function (el) { if (idx) el.setAttribute('href', idx); });
  })();

  /* 5. pop menus (details): one at a time, close outside / Escape */
  var pops = Array.prototype.slice.call(document.querySelectorAll('details.tp-pop'));
  pops.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) pops.forEach(function (o) { if (o !== d) o.open = false; }); });
  });
  document.addEventListener('click', function (e) { pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; }); });

  /* 6. the crystal command palette: centred modal on desktop, bottom sheet on phones */
  var pal = document.getElementById('tp-pal');
  var palOpener = null, palOpen = false, palHome = null, palList = null, palAll = null, palStatus = null, palInput = null, palForm = null;
  var palActive = -1, palTimer = 0, palCtrl = null, palLastQ = '', palItems = [];
  var palRenderRecent = function () {}; /* assigned by the palette block below (recent searches) */
  /* wheel events inside the open palette never reach the window: the kit's Lenis (smooth scroll) listens there, and
     during a View Transition the event path can miss the dialog so its prevent list would not apply */
  if (pal) pal.addEventListener('wheel', function (e) { e.stopPropagation(); }, { passive: true });
  function palSiblings() {
    return Array.prototype.filter.call(document.body.children, function (el) { return el !== pal && el.tagName !== 'SCRIPT' && el.tagName !== 'LINK' && el.tagName !== 'STYLE'; });
  }
  /* a reopened palette keeps the previous query and its results visible, but selected, so typing replaces it (launcher behaviour) */
  function palSelectStale() { if (palInput && palInput.value) { try { palInput.select(); } catch (e) { /* unsupported */ } } }
  function setPal(open, trigger) {
    if (!pal || open === palOpen) return;
    palOpen = open;
    var buttons = document.querySelectorAll('[data-tp-pal-open]');
    if (open) {
      palOpener = trigger || document.activeElement;
      palSiblings().forEach(function (el) { el.setAttribute('inert', ''); el.setAttribute('data-tp-inert', ''); });
      buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
      palRenderRecent();
      /* the scroll lock (root overflow) is toggled OUTSIDE the View Transition callback: WebKit's renderer crashes when the
         root overflow changes inside a same-document transition (tools/wk-probe.js, 2026-10-12) */
      root.classList.add('tp-locked');
      withTransition(function () {
        /* the View Transition runs this update callback asynchronously (after the old-state capture), so the palette is
           revealed and focused inside it: focusing from a timer could land while the element is still hidden */
        root.classList.add('tp-pal-open');
        pal.hidden = false;
        if (palInput) { try { palInput.focus({ preventScroll: true }); } catch (e) { palInput.focus(); } palSelectStale(); }
      });
      window.setTimeout(function () { if (palOpen && palInput && document.activeElement !== palInput && !pal.hidden) { palInput.focus(); palSelectStale(); } }, 120);
      try { document.dispatchEvent(new CustomEvent('tp:pal-open')); } catch (e) { /* old browsers */ }
    } else {
      root.classList.remove('tp-locked');
      withTransition(function () {
        pal.hidden = true;
        root.classList.remove('tp-pal-open');
      });
      document.querySelectorAll('[data-tp-inert]').forEach(function (el) { el.removeAttribute('inert'); el.removeAttribute('data-tp-inert'); });
      buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
      if (palOpener && palOpener.focus) palOpener.focus();
      palOpener = null;
    }
  }
  on('[data-tp-pal-open]', 'click', function (e, btn) { e.preventDefault(); setPal(true, btn); });
  on('[data-tp-pal-close]', 'click', function (e) { e.preventDefault(); setPal(false); });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      if (palOpen || !/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) { e.preventDefault(); setPal(!palOpen); }
    }
    if (e.key === '/' && !palOpen && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement && document.activeElement.tagName) || '')) { e.preventDefault(); setPal(true); }
  });
  if (pal) {
    palForm = pal.querySelector('[data-tp-pal-form]');
    palInput = pal.querySelector('[data-tp-pal-q]');
    palHome = pal.querySelector('[data-tp-pal-home]');
    palList = pal.querySelector('[data-tp-pal-list]');
    palAll = pal.querySelector('[data-tp-pal-all]');
    palStatus = pal.querySelector('[data-tp-pal-status]');
    var palBox = pal.querySelector('.tp-pal-box');
    var palBody = pal.querySelector('[data-tp-pal-body]');
    /* the dialog owns the Tab key: Safari's native Tab order skips links, so relying on first/last comparison let focus leave
       the dialog (and never reach the tiles and jump links); moving focus ourselves keeps every stop inside, in all engines */
    pal.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var list = focusables(pal);
      if (!list.length) return;
      e.preventDefault();
      var i = list.indexOf(document.activeElement);
      list[e.shiftKey ? (i <= 0 ? list.length - 1 : i - 1) : (i < 0 || i === list.length - 1 ? 0 : i + 1)].focus();
    });
    /* swipe down closes the sheet (phones) */
    var y0 = null, swiping = false;
    palBox.addEventListener('touchstart', function (e) {
      if (desktop() || !e.touches || !e.touches[0]) return;
      swiping = !palBody || palBody.scrollTop <= 0 || !palBody.contains(e.target);
      y0 = swiping ? e.touches[0].clientY : null;
    }, { passive: true });
    palBox.addEventListener('touchend', function (e) {
      if (y0 === null || !e.changedTouches || !e.changedTouches[0]) return;
      var dy = e.changedTouches[0].clientY - y0; y0 = null;
      if (dy > 90) setPal(false);
    }, { passive: true });

    /* suggestions (GET ajax/suggest, debounced 300 ms, rate-limited by the core) */
    var endpoint = palForm ? palForm.getAttribute('data-suggest') : '';
    var results = palForm ? (palForm.getAttribute('data-results') || palForm.getAttribute('action')) : '';
    var parser = window.DOMParser ? new DOMParser() : null;
    function plain(s) { if (!parser) return String(s || ''); var d = parser.parseFromString(String(s || ''), 'text/html'); return (d.documentElement.textContent || '').trim(); }
    function resultUrl(q) { return results + (results.indexOf('?') === -1 ? '?' : '&') + 'wd=' + encodeURIComponent(q); }
    function setStatus(msg) { if (palStatus) palStatus.textContent = msg || ''; }
    function showHome(show) {
      /* results arriving while the user tabs through the home sections (or vice versa) would hide the focused element and drop
         focus to the body, outside the trap: move it back to the input first */
      var ae = document.activeElement;
      if (ae && ae !== palInput && ((show && palList && palList.contains(ae)) || (!show && palHome && palHome.contains(ae)))) { try { palInput.focus({ preventScroll: true }); } catch (e) { palInput.focus(); } }
      if (palHome) palHome.hidden = !show;
      if (palList) palList.hidden = show;
      if (palAll) palAll.hidden = show;
      if (show) { setActive(-1); setStatus(''); }
      palInput.setAttribute('aria-expanded', show ? 'false' : 'true');
    }
    function setActive(i) {
      palItems.forEach(function (el, k) { el.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      palActive = i;
      if (i >= 0 && palItems[i]) {
        palInput.setAttribute('aria-activedescendant', palItems[i].id);
        if (palItems[i].scrollIntoView) palItems[i].scrollIntoView({ block: 'nearest' });
      } else palInput.removeAttribute('aria-activedescendant');
    }
    function render(items, q) {
      palList.textContent = '';
      palItems = [];
      items.slice(0, 8).forEach(function (it, i) {
        var name = plain(it.name || it.en || '');
        if (!name) return;
        var a = document.createElement('a');
        a.className = 'tp-pal-item'; a.href = it.link && /^\//.test(String(it.link)) ? String(it.link) : resultUrl(name); a.id = 'tp-pal-opt-' + i; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false'); a.tabIndex = -1;
        var th = document.createElement('span'); th.className = 'tp-pal-th';
        if (it.pic) { var img = document.createElement('img'); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.width = 96; img.height = 54; img.src = String(it.pic); th.appendChild(img); }
        var tx = document.createElement('span');
        var b = document.createElement('b'); b.textContent = name; tx.appendChild(b);
        var code = plain(it.remarks || it.code || '');
        if (code && !/^avdb-/i.test(code) && code.length <= 24) { var s = document.createElement('small'); s.textContent = code; tx.appendChild(s); }
        a.appendChild(th); a.appendChild(tx);
        a.addEventListener('click', function () { remember(name); });
        palList.appendChild(a);
        palItems.push(a);
      });
      if (palAll) { palAll.href = resultUrl(q); palAll.hidden = false; palAll.id = 'tp-pal-opt-all'; palList.appendChild(palAll); palItems.push(palAll); }
      setStatus(palItems.length <= 1 ? text('tp.no_suggestions', 'No matching videos') : '');
      showHome(false);
      setActive(-1);
    }
    function query(q) {
      if (palCtrl && palCtrl.abort) palCtrl.abort();
      palCtrl = window.AbortController ? new AbortController() : null;
      setStatus(text('ui.loading', 'Loading...'));
      var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'mid=1&limit=8&wd=' + encodeURIComponent(q);
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, signal: palCtrl ? palCtrl.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (q !== palLastQ) return; render((data && data.list && data.list.length) ? data.list : [], q); })
        .catch(function (err) { if (err && err.name === 'AbortError') return; setStatus(text('ui.connection_failed_try_again', '')); });
    }
    if (palInput && endpoint) {
      palInput.addEventListener('input', function () {
        var q = palInput.value.trim();
        window.clearTimeout(palTimer);
        if (q.length < 2) { palLastQ = ''; showHome(true); return; }
        palTimer = window.setTimeout(function () { palLastQ = q; query(q); }, 300);
      });
      palInput.addEventListener('keydown', function (e) {
        if (palHome && !palHome.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(palItems.length ? (palActive + 1) % palItems.length : -1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(palItems.length ? (palActive <= 0 ? palItems.length - 1 : palActive - 1) : -1); }
        else if (e.key === 'Enter' && palActive >= 0 && palItems[palActive]) { e.preventDefault(); palItems[palActive].click(); }
      });
    }
    if (palForm) palForm.addEventListener('submit', function () {
      var q = palInput ? palInput.value.trim() : '';
      if (!q) return;
      remember(q);
    });
    /* recent searches (localStorage, wrapped in try/catch) */
    var recentBox = pal.querySelector('[data-tp-recent]'), recentList = pal.querySelector('[data-tp-recent-list]');
    function recent() { try { var v = JSON.parse(storeGet('tp.recent') || '[]'); return Array.isArray(v) ? v.filter(function (s) { return typeof s === 'string' && s; }).slice(0, 6) : []; } catch (e) { return []; } }
    function remember(q) {
      q = String(q || '').trim().slice(0, 60);
      if (!q) return;
      var list = recent().filter(function (s) { return s.toLowerCase() !== q.toLowerCase(); });
      list.unshift(q);
      storeSet('tp.recent', JSON.stringify(list.slice(0, 6)));
    }
    function renderRecent() {
      if (!recentBox || !recentList) return;
      var list = recent();
      recentBox.hidden = !list.length;
      recentList.textContent = '';
      /* the auto-icon history glyph is rendered once on the server into a <template>; each chip clones it (no second sprite) */
      var recentIco = pal.querySelector('template[data-tp-recent-ico]');
      list.forEach(function (q, i) {
        var a = document.createElement('a');
        a.className = 'tp-chip'; a.href = resultUrl(q); a.style.setProperty('--tp-i', String(i));
        var sv = recentIco && recentIco.content && recentIco.content.firstElementChild ? recentIco.content.firstElementChild.cloneNode(true) : null;
        if (!sv) {
          sv = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); sv.setAttribute('class', 'tp-i'); sv.setAttribute('aria-hidden', 'true');
          var use = document.createElementNS('http://www.w3.org/2000/svg', 'use'); use.setAttribute('href', ((window.maccms && window.maccms.templatePath) || '') + 'assets/icons.svg#r-history'); sv.appendChild(use);
        }
        a.appendChild(sv); a.appendChild(document.createTextNode(q));
        recentList.appendChild(a);
      });
    }
    palRenderRecent = renderRecent;
    on('[data-tp-recent-clear]', 'click', function (e) { e.preventDefault(); storeSet('tp.recent', '[]'); renderRecent(); if (palInput) palInput.focus(); });
  }

  /* 7. Escape closes the top-most layer */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (palOpen) { e.preventDefault(); setPal(false); return; }
    var openPop = pops.filter(function (d) { return d.open; })[0];
    if (openPop) { openPop.open = false; var s = openPop.querySelector('summary'); if (s) s.focus(); }
  });

  /* 8. the vitrine feature selector: 5 diamond dots, 7 s autoplay paused on hover / focus / off-screen / hidden tab */
  document.querySelectorAll('[data-tp-vit]').forEach(function (vit) {
    var items = Array.prototype.slice.call(vit.querySelectorAll('[data-tp-feat]'));
    var imgs = Array.prototype.slice.call(vit.querySelectorAll('[data-tp-feat-img]'));
    var dots = Array.prototype.slice.call(vit.querySelectorAll('[data-tp-dot]'));
    var pause = vit.querySelector('[data-tp-pause]');
    var play = vit.querySelector('[data-tp-feat-play]');
    if (items.length < 2) return;
    var idx = 0, timer = 0, hover = false, visible = true, paused = false;
    function show(n, animate) {
      idx = (n + items.length) % items.length;
      items.forEach(function (it, i) {
        var onIt = i === idx;
        it.classList.toggle('is-on', onIt);
        if (onIt && animate) { var conv = it.querySelector('[data-tp-conv]'); if (conv) { conv.classList.remove('is-run'); void conv.offsetWidth; conv.classList.add('is-run'); } }
      });
      imgs.forEach(function (im, i) { if (i === idx && im.loading === 'lazy') im.loading = 'eager'; im.classList.toggle('is-on', i === idx); });
      dots.forEach(function (d, i) { if (i === idx) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
      if (play) { var link = items[idx].querySelector('a[href]'); if (link) { play.href = link.href; var t = items[idx].querySelector('.tp-conv-t'); if (t) play.setAttribute('aria-label', text('tp.watch_now', 'Watch now') + ': ' + t.textContent); } }
    }
    function stop() { window.clearInterval(timer); timer = 0; }
    function start() { stop(); if (reduceMotion || paused || hover || !visible || document.hidden) return; timer = window.setInterval(function () { show(idx + 1, true); }, 7000); }
    dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i, true); start(); }); });
    /* prev / next buttons and arrow keys on the dots group (Home / End jump; ArrowLeft / ArrowRight mirrored under dir=rtl) */
    function step(delta) { show(idx + delta, true); start(); if (dots[idx]) dots[idx].focus({ preventScroll: true }); }
    var prevBtn = vit.querySelector('[data-tp-feat-prev]'), nextBtn = vit.querySelector('[data-tp-feat-next]'), dotsBox = vit.querySelector('[data-tp-dots]');
    if (prevBtn) prevBtn.addEventListener('click', function () { show(idx - 1, true); start(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { show(idx + 1, true); start(); });
    if (dotsBox) dotsBox.addEventListener('keydown', function (e) {
      if (!e.target || !e.target.closest || !e.target.closest('[data-tp-dot]')) return;
      var rtl = root.getAttribute('dir') === 'rtl';
      if (e.key === 'ArrowRight') { e.preventDefault(); step(rtl ? -1 : 1); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); step(rtl ? 1 : -1); }
      else if (e.key === 'Home') { e.preventDefault(); step(-idx); }
      else if (e.key === 'End') { e.preventDefault(); step(items.length - 1 - idx); }
    });
    if (pause) pause.addEventListener('click', function () {
      paused = !paused;
      pause.setAttribute('aria-pressed', paused ? 'true' : 'false');
      var label = text(paused ? 'tp.play_features' : 'tp.pause_features', '');
      if (label) { pause.setAttribute('aria-label', label); pause.setAttribute('title', label); }
      start();
    });
    if (finePointer) { vit.addEventListener('mouseenter', function () { hover = true; stop(); }); vit.addEventListener('mouseleave', function () { hover = false; start(); }); }
    vit.addEventListener('focusin', function () { hover = true; stop(); });
    vit.addEventListener('focusout', function () { if (!vit.contains(document.activeElement)) { hover = false; start(); } });
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
    if ('IntersectionObserver' in window) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; if (visible) start(); else stop(); }, { threshold: 0.2 }).observe(vit);
    var x0 = null;
    vit.addEventListener('touchstart', function (e) { x0 = e.touches && e.touches[0] ? e.touches[0].clientX : null; }, { passive: true });
    vit.addEventListener('touchend', function (e) {
      if (x0 === null || !e.changedTouches || !e.changedTouches[0]) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) < 48) return;
      show(idx + ((dx < 0) !== (root.getAttribute('dir') === 'rtl') ? 1 : -1), true); start();
    }, { passive: true });
    show(0, false); start();
  });

  /* 9. spectral converge: the start state is applied by CSS before the first paint; the run class resolves it once */
  window.requestAnimationFrame(function () {
    document.querySelectorAll('[data-tp-conv]').forEach(function (el) { el.classList.add('is-run'); });
  });

  /* 10. images that fail: show the placeholder painted behind them */
  var brokenHosts = '.tp-cover, .tp-frame-in, .tp-row-thumb, .tp-pal-th, .tp-profile-cover, .tp-uc-row-thumb';
  function markBroken(img) { if (img && img.closest && img.closest(brokenHosts)) img.classList.add('is-broken'); }
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG') return;
    markBroken(img);
  }, true);
  /* images that already failed before this (deferred) script ran, e.g. the eager hero still */
  document.querySelectorAll('img').forEach(function (img) { if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) markBroken(img); });

  /* 11. segmented control: the glass thumb slides under the active / hovered / focused option */
  document.querySelectorAll('[data-tp-seg]').forEach(function (seg) {
    var thumb = seg.querySelector('.tp-seg-thumb');
    var links = Array.prototype.slice.call(seg.querySelectorAll('a'));
    var current = seg.querySelector('a[aria-current]');
    if (!thumb || !links.length) return;
    function place(a) {
      if (!a) { thumb.style.opacity = '0'; return; }
      thumb.style.opacity = '1';
      thumb.style.width = a.offsetWidth + 'px';
      thumb.style.translate = (a.offsetLeft - seg.clientLeft - 4 + (seg.scrollLeft ? 0 : 0)) + 'px 0';
    }
    function home() { place(current); }
    seg.classList.add('is-live');
    home();
    links.forEach(function (a) {
      if (finePointer) a.addEventListener('pointerenter', function () { place(a); });
      a.addEventListener('focus', function () { place(a); });
    });
    seg.addEventListener('pointerleave', home);
    seg.addEventListener('focusout', function (e) { if (!seg.contains(e.relatedTarget)) home(); });
    if (current && seg.scrollWidth > seg.clientWidth) {
      var b = seg.getBoundingClientRect(), c = current.getBoundingClientRect();
      if (c.left < b.left + 4 || c.right > b.right - 4) seg.scrollLeft += (c.left + c.width / 2) - (b.left + b.width / 2);
    }
    var rt = 0;
    window.addEventListener('resize', function () { window.clearTimeout(rt); rt = window.setTimeout(home, 120); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(home);
  });

  /* 12. facet entrance: grids that start below the fold fade in (.98 -> 1) with a short stagger; above-the-fold grids never hide */
  if (!reduceMotion && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.remove('tp-pre'); en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px' });
    var vh = window.innerHeight || 800;
    document.querySelectorAll('[data-tp-stagger]').forEach(function (el) {
      if (el.getBoundingClientRect().top > vh) { el.classList.add('tp-pre'); io.observe(el); }
    });
    document.addEventListener('focusin', function (e) {
      var sec = e.target && e.target.closest ? e.target.closest('.tp-pre') : null;
      if (sec) { sec.classList.remove('tp-pre'); sec.classList.add('is-in'); io.unobserve(sec); }
    });
  }

  /* 13. description clamp on the watch page */
  document.querySelectorAll('[data-tp-desc]').forEach(function (box) {
    var body = box.querySelector('.tp-desc-body p'), btn = box.querySelector('[data-tp-desc-btn]'), lbl = box.querySelector('[data-tp-desc-lbl]');
    if (!body || !btn) return;
    box.classList.add('is-clamped');
    if (body.scrollHeight - body.clientHeight < 4) { box.classList.remove('is-clamped'); return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      box.classList.toggle('is-clamped', !open);
      if (lbl) lbl.textContent = text(open ? 'tp.show_less' : 'tp.show_more', lbl.textContent);
    });
  });

  /* 14. favourites through user/ajax_ulog (secure-forms.js adds the token); success fires tp:fav for the star burst */
  on('[data-tp-fav]', 'click', function (e, btn) {
    e.preventDefault();
    var r = routes();
    var uid = cookie('user_id');
    if (!uid || uid === '0') { window.location.href = r.user_login || (base() + '/index.php/user/login'); return; }
    if (!window.jQuery || !r.user_ajax_ulog || btn.getAttribute('aria-busy') === 'true') return;
    btn.setAttribute('aria-busy', 'true');
    window.jQuery.ajax({ url: r.user_ajax_ulog, type: 'get', dataType: 'json', data: { ac: 'set', mid: btn.getAttribute('data-mid') || 1, id: btn.getAttribute('data-id'), type: 2 } })
      .done(function (res) {
        var ok = res && Number(res.code) === 1;
        if (ok) { btn.setAttribute('aria-pressed', 'true'); try { btn.dispatchEvent(new CustomEvent('tp:fav', { bubbles: true })); } catch (err) { /* old browsers */ } }
        toast(res && res.msg ? res.msg : '', ok ? 'success' : 'error');
      })
      .fail(function () { toast(text('ui.connection_failed_try_again', ''), 'error'); })
      .always(function () { btn.removeAttribute('aria-busy'); });
  });

  /* 15. share / copy link */
  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea'); ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
      document.body.removeChild(ta);
    });
  }
  on('[data-tp-share]', 'click', function (e, btn) {
    var url = btn.getAttribute('data-url') || window.location.href;
    if (navigator.share && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
    copyText(url).then(function () { toast(text('ui.link_copied', 'Link copied'), 'success'); }, function () { toast(text('ui.select_and_copy_the_address', ''), 'error'); });
  });
  on('[data-tp-copy]', 'click', function (e, btn) {
    var input = document.getElementById(btn.getAttribute('data-tp-copy'));
    if (!input) return;
    copyText(input.value || input.textContent || '').then(function () { toast(text('ui.link_copied', 'Link copied'), 'success'); }, function () { if (input.select) input.select(); });
  });

  /* 16. password eye */
  on('[data-tp-eye]', 'click', function (e, btn) {
    var input = document.getElementById(btn.getAttribute('data-tp-eye'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  /* 17. captcha: loaded only when the visitor focuses the field or taps the box (never on page view) */
  function loadCaptcha(field) {
    var img = field && field.querySelector('.tp-captcha img');
    if (!img || !img.hidden) return;
    var src = img.getAttribute('data-src');
    if (!src) return;
    img.hidden = false;
    img.src = src.split('?')[0] + '?' + Date.now();
    var box = field.querySelector('.tp-captcha'); if (box) box.classList.add('tp-captcha--on');
  }
  document.addEventListener('focusin', function (e) {
    var input = e.target && e.target.closest ? e.target.closest('[data-tp-captcha-input]') : null;
    if (input) loadCaptcha(input.closest('.tp-field'));
  });
  on('[data-tp-captcha-box]', 'click', function (e, box) { var img = box.querySelector('img'); if (img && img.hidden) loadCaptcha(box.closest('.tp-field')); });
  on('.tp-captcha img', 'keydown', function (e, img) {
    if ((e.key === 'Enter' || e.key === ' ') && !img.classList.contains('mac_verify_img')) { e.preventDefault(); img.click(); }
  });

  /* 18. avatar upload (user/info) */
  document.querySelectorAll('[data-tp-avatar]').forEach(function (form) {
    var status = form.querySelector('[data-tp-avatar-status]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = form.querySelector('input[type="file"]');
      function report(msg, kind) { if (status) status.textContent = msg; toast(msg, kind); }
      if (!input || !input.files || !input.files.length) { report(text('select_avatar_file', ''), 'error'); return; }
      if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { report(text('avatar_upload_failed', ''), 'error'); return; }
      report(text('template_ui_working', ''), 'info');
      fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || Number(res.code) !== 1) { report(res && typeof res.msg === 'string' ? res.msg : text('avatar_upload_failed', ''), 'error'); return; }
          report(text('avatar_updated', ''), 'success'); window.setTimeout(function () { window.location.reload(); }, 900);
        })
        .catch(function () { report(text('ui.connection_failed_try_again', ''), 'error'); });
    });
  });

  /* 19. keep the active chip in view inside horizontal rows, and keep keyboard focus fully visible there */
  function centerChips() {
    document.querySelectorAll('.tp-catchips, .tp-uc-nav, .tp-tabs, .tp-epnav').forEach(function (row) {
      var cur = row.querySelector('[aria-current]');
      if (!cur || row.scrollWidth <= row.clientWidth) return;
      var b = row.getBoundingClientRect(), c = cur.getBoundingClientRect();
      if (c.left < b.left + 4 || c.right > b.right - 4) row.scrollLeft += (c.left + c.width / 2) - (b.left + b.width / 2);
    });
  }
  centerChips();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(centerChips);
  window.addEventListener('load', centerChips);

  /* 19b. hot-search row: edge-fade state (tp.css "theme-polish T6"); is-ovf only while the row overflows, is-scrolled once it
     left its start, is-end at its end. scrollLeft is negative in RTL, so the distance from the start is Math.abs(). */
  document.querySelectorAll('.tp-hotrow').forEach(function (row) {
    var tick = 0;
    function fade() {
      tick = 0;
      var max = row.scrollWidth - row.clientWidth, ovf = max > 1, pos = Math.abs(row.scrollLeft);
      row.classList.toggle('is-ovf', ovf);
      row.classList.toggle('is-scrolled', ovf && pos > 1);
      row.classList.toggle('is-end', ovf && pos >= max - 1);
    }
    function queue() { if (!tick) tick = window.requestAnimationFrame(fade); }
    fade();
    row.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue, { passive: true });
    window.addEventListener('load', queue);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(queue);
  });
  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!el || !el.closest) return;
    var row = el.closest('.tp-catchips, .tp-uc-nav, .tp-tabs, .tp-epnav, .tp-seg, .tp-hotrow');
    if (!row || row.scrollWidth <= row.clientWidth) return;
    var b = row.getBoundingClientRect(), c = el.getBoundingClientRect();
    if (c.left < b.left + 4) row.scrollLeft -= (b.left + 4 - c.left);
    else if (c.right > b.right - 4) row.scrollLeft += (c.right - b.right + 4);
  });

  /* 20. back to top */
  on('[data-tp-top]', 'click', function () { window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' }); });

  /* 21. comments: load the token-aware MAC fragment when the panel comes near */
  document.querySelectorAll('[data-tp-comments]').forEach(function (box) {
    var started = false;
    function startComments() {
      if (started || !window.MAC || !window.MAC.Comment) return;
      started = true;
      try { window.MAC.Comment.Init(); window.MAC.Comment.Show(1); } catch (err) { box.textContent = text('comment_loading_failed', ''); }
    }
    if ('IntersectionObserver' in window) {
      var cio = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { cio.disconnect(); startComments(); } }); }, { rootMargin: '600px 0px' });
      cio.observe(box);
    } else { startComments(); }
  });

  /* 22. user lists: a removed row leaves before the page reloads (AutoAnimate plays the FLIP when loaded) */
  on('[data-tp-row-remove]', 'click', function (e, btn) {
    var row = btn.closest('[data-tp-row]');
    if (row) row.setAttribute('data-tp-leaving', '');
  });
})(window, document);
