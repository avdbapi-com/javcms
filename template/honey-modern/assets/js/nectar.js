/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Honey Nectar (Modern) behaviour layer | original code (JavCMS Community License 1.0) for JavCMS | vanilla, no dependencies
   capsule header, popovers, header + wordmark fit, account state, history, search suggestions, captcha on focus,
   hex FAB radial bloom, bottom sheet, share, clamp, melting type, drip and honeycomb fallbacks. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var msgs = window.TemplateMessages || {};
  var mq = function (q) { try { return window.matchMedia(q).matches; } catch (e) { return false; } };
  var fine = mq('(hover: hover) and (pointer: fine)');
  var reduced = mq('(prefers-reduced-motion: reduce)') || !root.classList.contains('nc-anim');
  var rtl = (root.getAttribute('dir') || '').toLowerCase() === 'rtl';
  var base = (window.maccms && maccms.path) || '';
  var ICO = (window.NC_TPL || '/') + 'assets/icons.svg?v=20261012c#';
  function t(key, fallback) { return msgs[key] || fallback || key; }
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function toast(text, kind) { if (window.TemplateUI && TemplateUI.message) TemplateUI.message(text, { kind: kind || 'info' }); }
  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/+^]/g, '\\$&') + '=([^;]*)'));
    if (!m) return '';
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  }
  function icon(id) { return '<svg class="nc-i" aria-hidden="true"><use href="' + ICO + id + '"></use></svg>'; }
  function focusables(scope) {
    return $$('a[href],button:not([disabled]),input:not([type="hidden"]):not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])', scope)
      .filter(function (el) { return el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; });
  }
  var cap = $('[data-nc-cap]'), bar = cap ? $('.nc-cap-bar', cap) : null;

  /* ---------- theme-color follows the colour mode; the owner's colour (SiteHead meta with data-jv-head) wins in both modes ---------- */
  var themeMeta = $('meta[name="theme-color"][data-jv-head]') ? null : $('meta[name="theme-color"]');
  function syncThemeColor() { if (themeMeta) themeMeta.setAttribute('content', root.getAttribute('data-color-mode') === 'light' ? '#fff8ec' : '#100b05'); }
  if (window.MutationObserver) new MutationObserver(syncThemeColor).observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });

  /* ---------- popovers (details[data-nc-pop]) ---------- */
  var pops = $$('details[data-nc-pop]');
  function closePops(except) { pops.forEach(function (d) { if (d !== except && d.open) d.open = false; }); }
  pops.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) { closePops(d); hideSuggest(); if (d.hasAttribute('data-nc-account')) renderHistory(); } });
  });
  document.addEventListener('click', function (e) {
    pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
    if (form && !form.contains(e.target)) { hideSuggest(); closeSearch(false); }
  });
  window.addEventListener('blur', function () { setTimeout(function () { var a = document.activeElement; if (a && a.tagName === 'IFRAME') { closePops(); hideSuggest(); } }, 0); });
  $$('[data-nc-hoverpop]').forEach(function (d) {
    if (!fine) return;
    var tm = 0, hoverAt = 0, sum = $('summary', d);
    d.addEventListener('mouseenter', function () { clearTimeout(tm); if (!d.open) { d.open = true; hoverAt = Date.now(); } });
    d.addEventListener('mouseleave', function () { clearTimeout(tm); tm = setTimeout(function () { if (!d.contains(document.activeElement)) d.open = false; }, 260); });
    /* a click right after the hover opened it must not toggle it shut again */
    if (sum) sum.addEventListener('click', function (e) { if (d.open && Date.now() - hoverAt < 700) e.preventDefault(); });
  });
  /* Escape closes an open popover and returns focus to its summary */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    pops.forEach(function (d) {
      if (!d.open) return;
      var had = d.contains(document.activeElement);
      d.open = false;
      var sm = $('summary', d);
      if (had && sm) sm.focus();
    });
  });

  /* ---------- capsule: shrink on scroll; FAB steps aside while scrolling down, typing, or covering focus ---------- */
  var fabWrap = $('[data-nc-fabwrap]'), lastY = window.pageYOffset || 0, ticking = false;
  function onScroll() {
    if (ticking) return; ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      var y = window.pageYOffset || 0;
      if (cap) cap.classList.toggle('is-scrolled', y > 24);
      if (fabWrap && !bloomOpen) {
        if (y > lastY + 14 && y > 260) fabWrap.classList.add('is-away');
        else if (y < lastY - 10 || y < 120) fabWrap.classList.remove('is-away');
      }
      lastY = y;
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  document.addEventListener('focusin', function (e) {
    if (!fabWrap) return;
    var el = e.target;
    if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && !el.closest('[data-nc-search]')) { fabWrap.classList.add('is-away'); return; }
    if (bloomOpen || !el || !el.getBoundingClientRect || fabWrap.contains(el)) return;
    var r = el.getBoundingClientRect(), f = fabWrap.getBoundingClientRect();
    var hit = r.right > f.left - 8 && r.left < f.right + 8 && r.bottom > f.top - 8 && r.top < f.bottom + 8;
    fabWrap.classList.toggle('is-away', hit);
  });
  /* a keyboard user who reaches the FAB always sees it */
  if (fabWrap) fabWrap.addEventListener('focusin', function () { fabWrap.classList.remove('is-away', 'is-off'); });
  /* the FAB never covers the player: it steps aside while the player crosses the bottom band it sits in */
  /* the same holds for rows marked data-nc-fab-avoid (watch meta row, action row, the category fact) */
  var fabAvoid = $$('.nc-player-frame, [data-nc-fab-avoid]');
  if (fabWrap && fabAvoid.length && 'IntersectionObserver' in window) {
    var pio = null, pioH = 0, pioIn = [];
    var watchPlayer = function () {
      if (pio && pioH === window.innerHeight) return;
      pioH = window.innerHeight;
      if (pio) pio.disconnect();
      pioIn = [];
      pio = new IntersectionObserver(function (es) {
        es.forEach(function (en) { var k = pioIn.indexOf(en.target); if (en.isIntersecting && k < 0) pioIn.push(en.target); else if (!en.isIntersecting && k > -1) pioIn.splice(k, 1); });
        if (!bloomOpen && !fabWrap.contains(document.activeElement)) fabWrap.classList.toggle('is-off', pioIn.length > 0);
      }, { rootMargin: '-' + Math.max(0, pioH - 120) + 'px 0px 0px 0px' });
      fabAvoid.forEach(function (el) { pio.observe(el); });
    };
    watchPlayer();
    window.addEventListener('resize', watchPlayer);
  }

  /* ---------- back to top (footer hex): smooth unless reduced, then focus lands on the skip link ---------- */
  $$('[data-nc-totop]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var L = window.__fxLenis;
      if (L && typeof L.scrollTo === 'function' && !reduced) L.scrollTo(0); else window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
      var skip = $('.nc-cap .nc-brand') || $('.nc-skip');
      if (skip) setTimeout(function () { skip.focus({ preventScroll: true }); }, reduced ? 0 : 700);
    });
  });

  /* ---------- header fit: brand keeps its width; optional nav items step out; else the FAB takes over ---------- */
  var nav = $('[data-nc-nav]'), tools = cap ? $('.nc-tools', cap) : null, brand = cap ? $('.nc-brand', cap) : null;
  var word = brand ? $('[data-nc-fit]', brand) : null;
  function brandNatural() {
    if (!brand) return 0;
    var w = 0;
    $$('.nc-mark, .nc-brand-img', brand).forEach(function (m) { w += m.getBoundingClientRect().width; });
    if (word) w += word.scrollWidth + 9;
    var cs = getComputedStyle(brand);
    return w + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
  }
  function fitWord() {
    if (!word) return;
    word.style.letterSpacing = ''; word.style.fontSize = '';
    if (!word.offsetParent) return;
    var over = function () { return word.scrollWidth > word.clientWidth + 1; };
    if (!over()) return;
    var ls = [-0.02, -0.03, -0.04];
    for (var i = 0; i < ls.length && over(); i++) word.style.letterSpacing = ls[i] + 'em';
    var px = parseFloat(getComputedStyle(word).fontSize) || 22;
    while (over() && px > 13) { px -= 1; word.style.fontSize = px + 'px'; }
  }
  function fitHeader() {
    if (!cap || !bar) return;
    cap.classList.remove('is-tight');
    root.classList.remove('nc-compact');
    $$('.nc-opt.is-cut', nav || document).forEach(function (a) { a.classList.remove('is-cut'); });
    if (word) { word.style.letterSpacing = ''; word.style.fontSize = ''; }
    if (window.innerWidth >= 1024 && nav && tools) {
      var cs = getComputedStyle(bar);
      var avail = bar.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 2 * (parseFloat(cs.columnGap) || 10);
      var need = function () { return nav.scrollWidth + 2 * Math.max(brandNatural(), tools.scrollWidth + 8); };
      if (need() > avail) cap.classList.add('is-tight');
      var opts = $$('.nc-opt', nav).reverse();
      for (var i = 0; i < opts.length && need() > avail; i++) opts[i].classList.add('is-cut');
      if (need() > avail) root.classList.add('nc-compact');
    }
    fitWord();
  }
  var fitT = 0;
  function fitSoon() { clearTimeout(fitT); fitT = setTimeout(function () { fitHeader(); fitChips(); }, 120); }
  window.addEventListener('resize', fitSoon);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitHeader);
  if (document.fonts && document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', fitSoon);
  window.addEventListener('load', fitHeader);
  fitHeader();

  /* ---------- account state (cookies set by the core on login) ---------- */
  var uid = cookie('user_id'), uname = cookie('user_name');
  if (uid && uid !== '0') {
    $$('[data-nc-guest]').forEach(function (a) { a.hidden = true; });
    $$('[data-nc-member]').forEach(function (a) { a.hidden = false; });
    var av = $('[data-nc-avatar]');
    if (av && uname) { av.textContent = uname.trim().charAt(0).toUpperCase(); }
    $$('[data-nc-account-name]').forEach(function (n) { if (uname) n.textContent = uname; });
    var meUrl = window.maccms && maccms.template_urls && maccms.template_urls.user_index;
    if (meUrl) $$('[data-nc-me]').forEach(function (a) { a.href = meUrl; });
  }

  /* ---------- viewing history (MAC.History cookie) ---------- */
  var setEl = $('[data-nc-history-set]');
  if (setEl && window.MAC && MAC.History && MAC.History.Set) {
    try { MAC.History.Set(setEl.getAttribute('data-name'), setEl.getAttribute('data-link'), setEl.getAttribute('data-pic')); } catch (e) { /* ignore */ }
  }
  function renderHistory() {
    var rows = [];
    try { rows = (window.MAC && MAC.History && MAC.History.Read) ? MAC.History.Read() : []; } catch (e) { rows = []; }
    $$('[data-nc-history-list]').forEach(function (list) {
      list.textContent = '';
      rows.slice(0, list.classList.contains('nc-histlist-lg') ? 20 : 6).forEach(function (row) {
        var li = document.createElement('li'), a = document.createElement('a'), span = document.createElement('span');
        a.href = row.link;
        if (row.pic && /^(https?:\/\/|\/(?!\/))/.test(row.pic)) { var img = document.createElement('img'); img.src = row.pic; img.alt = ''; img.loading = 'lazy'; img.width = 64; img.height = 36; a.appendChild(img); }
        span.textContent = row.name; span.dir = 'auto'; a.appendChild(span); li.appendChild(a); list.appendChild(li);
      });
    });
    $$('[data-nc-history-empty]').forEach(function (e) { e.hidden = rows.length > 0; });
    $$('[data-nc-history-clear]').forEach(function (b) { b.hidden = rows.length === 0; });
  }
  $$('[data-nc-history-clear]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); try { MAC.History.Clear(); } catch (err) { /* ignore */ } renderHistory(); });
  });

  /* ---------- search: pour open, blank guard, hot words, live suggestions ---------- */
  var form = $('[data-nc-search]'), input = form ? $('input[name="wd"]', form) : null, box = form ? $('.nc-suggest', form) : null;
  var listBox = box ? $('[data-nc-suggest-list]', box) : null, hot = box ? $('[data-nc-hot]', box) : null;
  var sugTitle = box ? $('[data-nc-suggest-title]', box) : null;
  var reqId = 0, sugTimer = 0, active = -1, closeT = 0;
  /* combobox state: aria-expanded, aria-controls (hot words or suggestions) and aria-activedescendant */
  function links() { return box ? $$('a', box).filter(function (a) { return a.getClientRects().length > 0; }) : []; }
  function clearActive() {
    active = -1;
    if (input) input.removeAttribute('aria-activedescendant');
    if (box) $$('a', box).forEach(function (a) { a.classList.remove('is-on'); a.setAttribute('aria-selected', 'false'); });
  }
  function setPopup(open, id) {
    if (!input) return;
    input.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (id) input.setAttribute('aria-controls', id);
    clearActive();
  }
  function hideSuggest() { clearTimeout(sugTimer); reqId++; if (box) { box.hidden = true; setPopup(false); } }
  function openSearch() { if (!form) return; clearTimeout(closeT); form.classList.add('is-open'); closePops(); }
  function closeSearch(blur) { if (!form || !form.classList.contains('is-open')) return; form.classList.remove('is-open'); hideSuggest(); if (blur && input) input.blur(); }
  function showHot() {
    if (!box || !hot) return;
    if (listBox) { listBox.textContent = ''; listBox.hidden = true; }
    if (sugTitle) sugTitle.hidden = true;
    hot.hidden = false; box.hidden = false;
    setPopup(true, 'nc-hot-list');
  }
  function decode(s) { var d = new DOMParser().parseFromString('<p>' + String(s) + '</p>', 'text/html'); return d.body.textContent || ''; }
  function suggest(q) {
    var my = ++reqId;
    fetch(base + '/index.php/ajax/suggest?mid=1&limit=8&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (my !== reqId || !listBox || !input || input.value.trim() !== q || !form.contains(document.activeElement)) return;
        listBox.textContent = '';
        var rows = (data && data.code == 1 && Array.isArray(data.list)) ? data.list : [];
        if (!rows.length) { if (hot) showHot(); else hideSuggest(); return; }
        var act = form.getAttribute('action') || (base + '/search/');
        rows.slice(0, 8).forEach(function (row, i) {
          var name = decode(row.name || ''); if (!name) return;
          var a = document.createElement('a'); a.href = act + (act.indexOf('?') === -1 ? '?' : '&') + 'wd=' + encodeURIComponent(name);
          a.id = 'nc-sug-' + i; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false');
          a.innerHTML = icon('search');
          var sp = document.createElement('span'); sp.textContent = name; sp.dir = 'auto'; a.appendChild(sp); listBox.appendChild(a);
        });
        if (!listBox.firstChild) { if (hot) showHot(); else hideSuggest(); return; }
        if (hot) hot.hidden = true;
        if (sugTitle) sugTitle.hidden = false;
        listBox.hidden = false; box.hidden = false;
        setPopup(true, 'nc-suggest-list');
      })
      .catch(function () { /* offline: no suggestions */ });
  }
  if (form && input) {
    form.addEventListener('submit', function (e) {
      if (!input.value.trim()) { e.preventDefault(); form.classList.remove('is-shake'); void form.offsetWidth; form.classList.add('is-shake'); openSearch(); input.focus(); }
    });
    form.addEventListener('focusin', function () { openSearch(); });
    form.addEventListener('focusout', function () { clearTimeout(closeT); closeT = setTimeout(function () { if (!form.contains(document.activeElement)) closeSearch(false); }, 260); });
    input.addEventListener('focus', function () { if (!input.value.trim()) showHot(); });
    input.addEventListener('input', function () {
      clearTimeout(sugTimer);
      var q = input.value.trim();
      if (q.length < 2) { if (!q && hot) showHot(); else hideSuggest(); return; }
      /* typing hides the hot words; an empty popup closes until suggestions arrive */
      if (hot && !hot.hidden) { hot.hidden = true; if (!listBox || listBox.hidden) { box.hidden = true; setPopup(false); } }
      sugTimer = setTimeout(function () { suggest(q); }, 300);
    });
    input.addEventListener('keydown', function (e) {
      var items = links();
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if (box && !box.hidden) hideSuggest(); else closeSearch(true); return; }
      if (!box || box.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = e.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1);
        items.forEach(function (a, i) { a.classList.toggle('is-on', i === active); a.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
        if (items[active].id) input.setAttribute('aria-activedescendant', items[active].id);
        try { items[active].scrollIntoView({ block: 'nearest' }); } catch (err) { /* old engines */ }
      } else if (e.key === 'Enter' && active >= 0 && items[active]) { e.preventDefault(); location.href = items[active].href; }
    });
    var x = $('[data-nc-search-close]', form);
    if (x) x.addEventListener('click', function (e) { e.preventDefault(); closeSearch(true); });
  }

  /* ---------- captcha: request /verify/ only once the visitor starts using the form ---------- */
  function loadVerify(scope) {
    $$('img[data-nc-src]', scope).forEach(function (img) { var s = img.getAttribute('data-nc-src'); img.removeAttribute('data-nc-src'); img.src = s; });
  }
  ['focusin', 'pointerdown'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var f = e.target && e.target.closest ? e.target.closest('form') : null;
      if (f && f.querySelector('img[data-nc-src]')) loadVerify(f);
    }, true);
  });

  /* ---------- modal helper: inert page, focus trap, Escape, focus return ---------- */
  var shells = function () { return $$('body > header, body > main, body > footer, body > .nc-fab-wrap'); };
  function setInert(on, keep) {
    shells().forEach(function (el) {
      if (keep && (el === keep || el.contains(keep))) return;
      if (on) { el.setAttribute('inert', ''); el.setAttribute('aria-hidden', 'true'); } else { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); }
    });
  }
  function trap(scope, e, extra) {
    if (e.key !== 'Tab') return;
    var f = focusables(scope);
    if (extra) f = [extra].concat(f);
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (f.indexOf(document.activeElement) < 0) { e.preventDefault(); first.focus(); }
  }

  /* ---------- hex FAB: radial honeycomb bloom ---------- */
  var fab = $('[data-nc-fab]'), bloom = $('[data-nc-bloom]'), bloomOpen = false, bloomT = 0;
  function openBloom() {
    if (!fab || !bloom || bloomOpen) return;
    bloomOpen = true; clearTimeout(bloomT);
    if (fabWrap) fabWrap.classList.remove('is-away');
    bloom.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    setInert(true, fabWrap);
    document.documentElement.style.overflow = 'hidden';
    requestAnimationFrame(function () { requestAnimationFrame(function () { bloom.classList.add('is-open'); if (window.NC && NC.bloom) NC.bloom(bloom, true); }); });
    var first = $('.nc-cell', bloom);
    setTimeout(function () { if (bloomOpen && first) first.focus({ preventScroll: true }); }, reduced ? 0 : 160);
  }
  function closeBloom(restore) {
    if (!bloomOpen) return;
    bloomOpen = false;
    bloom.classList.remove('is-open');
    fab.setAttribute('aria-expanded', 'false');
    setInert(false);
    document.documentElement.style.overflow = '';
    bloomT = setTimeout(function () { if (!bloomOpen) bloom.hidden = true; }, reduced ? 0 : 480);
    if (restore !== false) fab.focus({ preventScroll: true });
  }
  if (fab && bloom) {
    fab.addEventListener('click', function () { if (bloomOpen) closeBloom(); else openBloom(); });
    bloom.addEventListener('click', function (e) { if (e.target.closest('[data-nc-bloom-close]')) closeBloom(); });
    document.addEventListener('keydown', function (e) {
      if (!bloomOpen) return;
      if (e.key === 'Escape') { e.preventDefault(); closeBloom(); return; }
      trap(bloom, e, fab);
    });
  }

  /* ---------- bottom sheet (categories + languages, history) with swipe-down ---------- */
  var sheet = $('[data-nc-sheet]'), sheetOpen = false, sheetFrom = null, sheetT = 0;
  function openSheet(pane, from) {
    if (!sheet) return;
    clearTimeout(sheetT);
    var wasBloom = bloomOpen;
    if (bloomOpen) closeBloom(false);
    sheetFrom = wasBloom ? fab : (from || document.activeElement);
    $$('[data-nc-pane]', sheet).forEach(function (p) { p.hidden = p.getAttribute('data-nc-pane') !== pane; });
    var title = $('[data-nc-sheet-title]', sheet);
    if (title) title.textContent = title.getAttribute(pane === 'history' ? 'data-history' : 'data-cats') || title.textContent;
    if (pane === 'history') renderHistory();
    sheet.hidden = false; sheetOpen = true;
    sheet.style.removeProperty('--nc-drag');
    setInert(true, null);
    document.documentElement.style.overflow = 'hidden';
    requestAnimationFrame(function () { requestAnimationFrame(function () { sheet.classList.add('is-open'); }); });
    var c = $('[data-nc-sheet-close].nc-sheet-x', sheet);
    setTimeout(function () { if (sheetOpen && c) c.focus({ preventScroll: true }); }, reduced ? 0 : 200);
  }
  function closeSheet() {
    if (!sheet || !sheetOpen) return;
    sheetOpen = false;
    sheet.classList.remove('is-open');
    sheet.classList.remove('is-dragging');
    setInert(false);
    document.documentElement.style.overflow = '';
    sheetT = setTimeout(function () { if (!sheetOpen) sheet.hidden = true; }, reduced ? 0 : 520);
    if (sheetFrom && sheetFrom.focus) sheetFrom.focus({ preventScroll: true });
  }
  $$('[data-nc-sheet-open]').forEach(function (b) { b.addEventListener('click', function () { openSheet(b.getAttribute('data-nc-sheet-open') || 'cats', b); }); });
  if (sheet) {
    sheet.addEventListener('click', function (e) { if (e.target.closest('[data-nc-sheet-close]')) closeSheet(); });
    document.addEventListener('keydown', function (e) {
      if (!sheetOpen) return;
      if (e.key === 'Escape') { e.preventDefault(); closeSheet(); return; }
      trap($('[data-nc-sheet-panel]', sheet), e);
    });
    var panel = $('[data-nc-sheet-panel]', sheet), startY = 0, dy = 0, dragging = false;
    $$('[data-nc-sheet-grab], .nc-sheet-head', sheet).forEach(function (h) {
      h.addEventListener('pointerdown', function (e) {
        if (e.target.closest('button')) return;
        dragging = true; startY = e.clientY; dy = 0; sheet.classList.add('is-dragging');
        try { h.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      });
      h.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        dy = Math.max(0, e.clientY - startY);
        sheet.style.setProperty('--nc-drag', dy + 'px');
      });
      var end = function () {
        if (!dragging) return;
        dragging = false; sheet.classList.remove('is-dragging');
        if (dy > Math.min(120, (panel ? panel.offsetHeight : 400) * 0.25)) closeSheet();
        else sheet.style.removeProperty('--nc-drag');
      };
      h.addEventListener('pointerup', end);
      h.addEventListener('pointercancel', end);
    });
  }

  /* ---------- share: native share sheet on touch devices; elsewhere the kit copy button confirms ---------- */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { if (document.execCommand('copy')) resolve(); else reject(); } catch (e) { reject(e); } document.body.removeChild(ta);
    });
  }
  $$('[data-nc-share]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var url = b.getAttribute('data-url') || location.href, title = b.getAttribute('data-title') || document.title;
      if (navigator.share && !fine) { e.stopImmediatePropagation(); navigator.share({ title: title, url: url }).catch(function () { /* cancelled */ }); return; }
      if (!window.FX) { copyText(url).then(function () { toast(t('ui.link_copied', 'Link copied'), 'success'); }, function () { toast(t('ui.select_and_copy_the_address', url)); }); }
    });
  });
  $$('[data-copy-target]').forEach(function (b) {
    b.addEventListener('click', function () { var el = document.getElementById(b.getAttribute('data-copy-target')); if (!el) return; copyText(el.value || el.textContent || '').then(function () { toast(t('ui.link_copied', 'Link copied'), 'success'); }, function () { if (el.select) el.select(); }); });
  });

  /* ---------- favourites: MAC.Ulog does the request; success adds .disabled -> honey celebration ---------- */
  document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a.mac_ulog'); if (a) e.preventDefault(); }, true);
  if (window.MutationObserver) {
    $$('[data-nc-fav]').forEach(function (b) {
      var done = false;
      new MutationObserver(function () {
        if (!done && b.classList.contains('disabled')) {
          done = true; b.classList.add('is-faved'); b.setAttribute('aria-pressed', 'true');
          if (window.NC && NC.celebrate) NC.celebrate(b);
        }
      }).observe(b, { attributes: true, attributeFilter: ['class'] });
    });
  }

  /* ---------- view counter: the MAC runtime rewrites it as a raw number; keep the thousands separators ---------- */
  $$('.mac_hits').forEach(function (el) {
    function fmt() {
      var raw = (el.textContent || '').trim();
      if (/^\d{4,}$/.test(raw)) { var s = Number(raw).toLocaleString('en-US'); if (s !== raw) el.textContent = s; }
    }
    fmt();
    if (window.MutationObserver) new MutationObserver(fmt).observe(el, { childList: true, characterData: true, subtree: true });
  });

  /* ---------- clamp long descriptions; expandable phone watch titles ---------- */
  $$('[data-nc-clamp]').forEach(function (sec) {
    var p = $('p', sec); if (!p) return;
    if (p.scrollHeight <= p.clientHeight + 2) { sec.classList.remove('is-clamp'); return; }
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'nc-linkbtn nc-clamp-btn'; btn.setAttribute('aria-expanded', 'false'); btn.textContent = t('nc.show_more', 'Show more');
    btn.addEventListener('click', function () { var closed = sec.classList.toggle('is-clamp'); btn.setAttribute('aria-expanded', closed ? 'false' : 'true'); btn.textContent = closed ? t('nc.show_more', 'Show more') : t('nc.show_less', 'Show less'); });
    sec.appendChild(btn);
  });
  $$('[data-nc-title]').forEach(function (h) { h.addEventListener('click', function () { if (window.innerWidth < 768) h.classList.toggle('is-open'); }); });

  /* ---------- category chips: keep the current chip in view ---------- */
  function fitChips() {
    $$('[data-nc-chips]').forEach(function (strip) {
      var on = $('.is-on', strip);
      if (!on || on === strip.firstElementChild) return;
      var sr = strip.getBoundingClientRect(), or = on.getBoundingClientRect();
      strip.scrollLeft += (or.left + or.width / 2) - (sr.left + sr.width / 2);
    });
  }
  fitChips();

  /* ---------- avatar upload (user/info) ---------- */
  $$('.gallery-avatar-form').forEach(function (fm) {
    var pick = $('input[type="file"]', fm), note = $('.gallery-avatar-status', fm);
    if (pick && note) pick.addEventListener('change', function () { note.textContent = pick.files && pick.files.length ? pick.files[0].name : ''; });
    fm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (fm.getAttribute('aria-busy') === 'true') return;
      var file = $('input[type="file"]', fm), btn = $('button[type="submit"]', fm), status = $('.gallery-avatar-status', fm);
      function report(text, kind) { if (status) status.textContent = text; toast(text, kind); }
      if (!file || !file.files.length) { report(t('select_avatar_file'), 'error'); return; }
      if (!/^image\/(jpeg|png|gif|webp)$/.test(file.files[0].type)) { report(t('avatar_upload_failed'), 'error'); return; }
      var endpoint = new URL(fm.action, location.href); if (endpoint.origin !== location.origin) return;
      if (btn) btn.disabled = true; fm.setAttribute('aria-busy', 'true'); if (status) status.textContent = t('template_ui_working');
      fetch(endpoint.href, { method: 'POST', body: new FormData(fm), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('upload'); return r.json(); })
        .then(function (res) {
          if (res && typeof res.__token__ === 'string') $$('input[name="__token__"]').forEach(function (i) { i.value = res.__token__; });
          if (Number(res && res.code) !== 1) { report(res && typeof res.msg === 'string' ? res.msg : t('ui.connection_failed_try_again'), 'error'); return; }
          report(t('avatar_updated'), 'success'); location.reload();
        })
        .catch(function () { report(t('ui.connection_failed_try_again'), 'error'); })
        .then(function () { if (btn) btn.disabled = false; fm.removeAttribute('aria-busy'); });
    });
  });

  /* ---------- melting type: hero/page titles melt after the first paint; section titles melt one at a time in view ---------- */
  (function () {
    if (!root.classList.contains('nc-melt')) return;
    var loadT = $$('[data-nc-melt="hero"], [data-nc-melt="load"]');
    var viewT = fine ? $$('[data-nc-melt="view"]') : [];
    if (!fine) $$('[data-nc-melt="load"], [data-nc-melt="view"]').forEach(function (h) { h.classList.add('is-melted'); });
    /* reserve the final (widest) height so a title that re-wraps while melting never moves the content below it */
    function reserve(list) {
      list.forEach(function (h) { h.style.transition = 'none'; h.classList.add('is-melted'); });
      var hs = list.map(function (h) { return h.getBoundingClientRect().height; });
      list.forEach(function (h, i) { h.style.minHeight = Math.ceil(hs[i]) + 'px'; h.classList.remove('is-melted'); });
      void root.offsetHeight;
      list.forEach(function (h) { h.style.transition = ''; });
    }
    try { reserve(viewT.concat(loadT.filter(function (h) { return !h.classList.contains('nc-feature'); }))); } catch (e) { /* ignore */ }
    requestAnimationFrame(function () { requestAnimationFrame(function () { setTimeout(function () { loadT.forEach(function (h) { h.classList.add('is-melted'); }); }, 140); }); });
    if (!viewT.length) return;
    if (!('IntersectionObserver' in window)) { viewT.forEach(function (h) { h.classList.add('is-melted'); }); return; }
    var queue = [], busy = false;
    function next() {
      if (!queue.length) { busy = false; return; }
      busy = true;
      var h = queue.shift(); h.classList.add('is-melted');
      setTimeout(next, 520);
    }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); queue.push(en.target); if (!busy) next(); } });
    }, { rootMargin: '0px 0px -12% 0px' });
    viewT.forEach(function (h) { io.observe(h); });
  })();

  /* ---------- drip dividers without scroll timelines: stretch once when in view ---------- */
  if (root.classList.contains('nc-io')) {
    var drips = $$('[data-nc-drips], .nc-drips');
    if (drips.length) {
      var dio = new IntersectionObserver(function (es) { es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); dio.unobserve(en.target); } }); }, { rootMargin: '0px 0px -10% 0px' });
      drips.forEach(function (d) { dio.observe(d); });
    }
  }

  /* ---------- honeycomb on touch: the honey pours once when the comb comes into view ---------- */
  var hive = $('[data-nc-hive]');
  if (hive && !fine && !reduced && 'IntersectionObserver' in window) {
    var hio = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting) { $$('.nc-hexcell', hive).forEach(function (c) { c.classList.add('is-poured'); }); hio.disconnect(); } });
    }, { threshold: 0.35 });
    hio.observe(hive);
  }

  root.classList.add('nc-js');
})(window, document);
