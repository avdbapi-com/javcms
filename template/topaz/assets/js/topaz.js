/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* Topaz theme behaviour for JAVCMS. Vanilla JS, no dependencies (jQuery is used only for the
   token-aware account calls that secure-forms.js protects). Written from scratch for this theme. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  root.classList.add('tz-js');

  function text(key, fallback) {
    var t = window.TopazText || {}, m = window.TemplateMessages || {};
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
  function on(sel, type, fn) {
    document.addEventListener(type, function (e) {
      var t = e.target && e.target.closest ? e.target.closest(sel) : null;
      if (t) fn(e, t);
    });
  }
  function routes() { return (window.maccms && window.maccms.template_urls) || {}; }
  function base() { return (window.maccms && window.maccms.path) || ''; }

  /* 1. header: deeper glass after scrolling */
  var bar = document.getElementById('tz-bar');
  if (bar) {
    var onScroll = function () { bar.classList.toggle('is-scrolled', (window.scrollY || 0) > 10); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* 2. member state from the login cookies (pages are cached, so never rendered server-side) */
  (function () {
    var uid = cookie('user_id');
    if (!uid || uid === '0') return;
    var name = cookie('user_name');
    document.querySelectorAll('[data-tz-guest]').forEach(function (el) { el.hidden = true; });
    document.querySelectorAll('[data-tz-member]').forEach(function (el) { el.hidden = false; });
    if (name) document.querySelectorAll('[data-tz-user-name]').forEach(function (el) { el.textContent = name; });
    var idx = routes().user_index;
    document.querySelectorAll('[data-tz-acct]').forEach(function (el) { if (idx) el.setAttribute('href', idx); });
  })();

  /* 3. drawer */
  var drawer = document.getElementById('tz-drawer');
  var scrim = document.querySelector('.tz-scrim');
  var drawerOpener = null;
  function focusables(box) {
    return Array.prototype.filter.call(box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'), function (el) { return el.offsetParent !== null || el === document.activeElement; });
  }
  function setDrawer(open) {
    if (!drawer) return;
    var buttons = document.querySelectorAll('[data-tz-open="drawer"]');
    if (open) {
      drawerOpener = document.activeElement;
      drawer.removeAttribute('inert');
      drawer.setAttribute('aria-hidden', 'false');
      drawer.classList.add('is-open');
      if (scrim) { scrim.hidden = false; requestAnimationFrame(function () { scrim.classList.add('is-on'); }); }
      document.body.classList.add('tz-lock');
      buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
      var cur = drawer.querySelector('.tz-tiles a[aria-current]') || drawer.querySelector('[data-tz-close]');
      window.setTimeout(function () { (drawer.querySelector('[data-tz-close]') || cur).focus(); }, 60);
    } else {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      drawer.setAttribute('inert', '');
      if (scrim) { scrim.classList.remove('is-on'); window.setTimeout(function () { if (!drawer.classList.contains('is-open')) scrim.hidden = true; }, 260); }
      document.body.classList.remove('tz-lock');
      buttons.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
      if (drawerOpener && drawerOpener.focus) drawerOpener.focus();
      drawerOpener = null;
    }
  }
  on('[data-tz-open="drawer"]', 'click', function (e) { e.preventDefault(); setDrawer(!drawer.classList.contains('is-open')); });
  on('[data-tz-close]', 'click', function (e) { e.preventDefault(); setDrawer(false); });
  if (drawer) {
    drawer.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var list = focusables(drawer);
      if (!list.length) return;
      var first = list[0], last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  /* 4. pop menus (details): one at a time, close outside / Escape */
  var pops = Array.prototype.slice.call(document.querySelectorAll('details.tz-pop'));
  pops.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (d.open) pops.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
  document.addEventListener('click', function (e) {
    pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
  });

  /* 5. mobile search panel */
  var searchForm = document.getElementById('tz-search');
  var searchBtn = document.querySelector('[data-tz-open="search"]');
  function setSearch(open) {
    if (!searchForm || !searchBtn) return;
    searchForm.classList.toggle('is-open', open);
    searchBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var q = searchForm.querySelector('input[name="wd"]'); if (q) window.setTimeout(function () { q.focus(); }, 30); }
  }
  if (searchBtn) searchBtn.addEventListener('click', function () { setSearch(!searchForm.classList.contains('is-open')); });
  document.addEventListener('click', function (e) {
    if (searchForm && searchForm.classList.contains('is-open') && !searchForm.contains(e.target) && searchBtn && !searchBtn.contains(e.target)) setSearch(false);
  });

  /* 6. live search suggestions (GET ajax/suggest, debounced, rate-limited by the core) */
  (function () {
    if (!searchForm) return;
    var input = searchForm.querySelector('input[name="wd"]');
    var box = searchForm.querySelector('.tz-suggest');
    var list = searchForm.querySelector('.tz-sug-list');
    var status = searchForm.querySelector('[data-tz-sug-status]');
    var all = searchForm.querySelector('[data-tz-sug-all]');
    var endpoint = searchForm.getAttribute('data-suggest');
    var results = searchForm.getAttribute('data-results') || searchForm.getAttribute('action');
    if (!input || !box || !list || !endpoint) return;
    var timer = 0, ctrl = null, lastQ = '';
    var parser = window.DOMParser ? new DOMParser() : null;
    function plain(s) { if (!parser) return String(s || ''); var d = parser.parseFromString(String(s || ''), 'text/html'); return (d.documentElement.textContent || '').trim(); }
    function resultUrl(q) { return results + (results.indexOf('?') === -1 ? '?' : '&') + 'wd=' + encodeURIComponent(q); }
    function close() { box.hidden = true; input.setAttribute('aria-expanded', 'false'); }
    function open() { box.hidden = false; input.setAttribute('aria-expanded', 'true'); }
    function setStatus(msg) { status.textContent = msg || ''; }
    function render(items, q) {
      list.textContent = '';
      items.slice(0, 8).forEach(function (it, i) {
        var name = plain(it.name || it.en || '');
        if (!name) return;
        var a = document.createElement('a');
        a.className = 'tz-sug-item'; a.href = resultUrl(name); a.id = 'tz-sug-' + i; a.setAttribute('role', 'option');
        var th = document.createElement('span'); th.className = 'tz-sug-thumb';
        if (it.pic) { var img = document.createElement('img'); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.width = 88; img.height = 50; img.src = String(it.pic); th.appendChild(img); }
        var tx = document.createElement('span'); tx.className = 'tz-sug-txt';
        var b = document.createElement('b'); b.textContent = name; tx.appendChild(b);
        a.appendChild(th); a.appendChild(tx);
        list.appendChild(a);
      });
      if (!list.children.length) setStatus(text('topaz.no_suggestions', 'No matching videos')); else setStatus('');
      if (all) { all.hidden = false; all.href = resultUrl(q); }
      open();
    }
    function query(q) {
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = window.AbortController ? new AbortController() : null;
      list.textContent = ''; setStatus(text('ui.loading', 'Loading...')); if (all) all.hidden = true; open();
      var url = endpoint + '?mid=1&limit=8&wd=' + encodeURIComponent(q);
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (q !== lastQ) return; render((data && data.list && data.list.length) ? data.list : [], q); })
        .catch(function (err) { if (err && err.name === 'AbortError') return; setStatus(text('ui.connection_failed_try_again', '')); });
    }
    input.addEventListener('input', function () {
      var q = input.value.trim();
      window.clearTimeout(timer);
      if (q.length < 2) { lastQ = ''; close(); return; }
      timer = window.setTimeout(function () { lastQ = q; query(q); }, 320);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && !box.hidden && list.firstElementChild) { e.preventDefault(); list.firstElementChild.focus(); }
      else if (e.key === 'Escape') { if (!box.hidden) { e.stopPropagation(); close(); } }
    });
    list.addEventListener('keydown', function (e) {
      var cur = document.activeElement;
      if (e.key === 'ArrowDown') { e.preventDefault(); (cur.nextElementSibling || all || cur).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (cur.previousElementSibling) cur.previousElementSibling.focus(); else input.focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); input.focus(); }
    });
    if (all) all.addEventListener('keydown', function (e) { if (e.key === 'ArrowUp' && list.lastElementChild) { e.preventDefault(); list.lastElementChild.focus(); } });
    document.addEventListener('click', function (e) { if (!searchForm.contains(e.target)) close(); });
    searchForm.addEventListener('focusout', function () { window.setTimeout(function () { if (!searchForm.contains(document.activeElement)) close(); }, 120); });
  })();

  /* 7. Escape closes the top-most layer */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (drawer && drawer.classList.contains('is-open')) { setDrawer(false); return; }
    var openPop = pops.filter(function (d) { return d.open; })[0];
    if (openPop) { openPop.open = false; var s = openPop.querySelector('summary'); if (s) s.focus(); return; }
    if (searchForm && searchForm.classList.contains('is-open')) { setSearch(false); if (searchBtn) searchBtn.focus(); }
  });

  /* 8. hero slider: cross-fade + Ken Burns, autoplay (paused on hover, focus and hidden tab), swipe */
  document.querySelectorAll('[data-tz-hero]').forEach(function (hero) {
    var slides = Array.prototype.slice.call(hero.querySelectorAll('[data-tz-slide]'));
    var dots = Array.prototype.slice.call(hero.querySelectorAll('[data-tz-dot]'));
    if (!slides.length) return;
    var idx = 0, timer = 0, paused = false;
    var total = slides.length;
    slides.forEach(function (s, i) {
      s.setAttribute('aria-label', fill(text('topaz.slide_of', 'Slide {n} of {total}'), { n: i + 1, total: total }));
      if (i !== 0) s.setAttribute('inert', '');
    });
    function show(n) {
      idx = (n + total) % total;
      slides.forEach(function (s, i) {
        var on = i === idx;
        s.classList.toggle('is-active', on);
        s.setAttribute('aria-hidden', on ? 'false' : 'true');
        if (on) s.removeAttribute('inert'); else s.setAttribute('inert', '');
        if (on) { var lazy = s.querySelectorAll('img[loading="lazy"]'); lazy.forEach(function (im) { im.loading = 'eager'; }); }
      });
      dots.forEach(function (d, i) { if (i === idx) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
    }
    function next() { show(idx + 1); }
    function stop() { window.clearInterval(timer); timer = 0; }
    function start() { stop(); if (reduceMotion || total < 2 || paused || document.hidden) return; timer = window.setInterval(next, 5500); }
    var prevBtn = hero.querySelector('[data-tz-hero-prev]'), nextBtn = hero.querySelector('[data-tz-hero-next]');
    if (prevBtn) prevBtn.addEventListener('click', function () { show(idx - 1); start(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { show(idx + 1); start(); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i); start(); }); });
    hero.addEventListener('mouseenter', function () { paused = true; stop(); });
    hero.addEventListener('mouseleave', function () { paused = false; start(); });
    hero.addEventListener('focusin', function () { paused = true; stop(); });
    hero.addEventListener('focusout', function () { if (!hero.contains(document.activeElement)) { paused = false; start(); } });
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
    var x0 = null;
    hero.addEventListener('touchstart', function (e) { x0 = e.touches && e.touches[0] ? e.touches[0].clientX : null; }, { passive: true });
    hero.addEventListener('touchend', function (e) {
      if (x0 === null || !e.changedTouches || !e.changedTouches[0]) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) < 40) return;
      var rtl = root.getAttribute('dir') === 'rtl';
      show(idx + ((dx < 0) !== rtl ? 1 : -1)); start();
    }, { passive: true });
    show(0); start();
  });

  /* 9. collection pager: pages of (columns x rows) inside a 20-item grid */
  function columns(grid) {
    var tpl = window.getComputedStyle(grid).gridTemplateColumns || '';
    var n = tpl.split(' ').filter(function (t) { return t && t !== 'none'; }).length;
    return Math.max(1, n);
  }
  document.querySelectorAll('[data-tz-pager]').forEach(function (sec) {
    var grid = sec.querySelector('.tz-grid');
    var prev = sec.querySelector('[data-tz-prev]'), nextB = sec.querySelector('[data-tz-next]');
    var live = sec.querySelector('[data-tz-pager-status]');
    if (!grid) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.tz-card'));
    var first = 0, size = 10, announce = false;
    function layout() {
      var cols = columns(grid);
      size = cols * (cols <= 2 ? 3 : 2);
      first = Math.floor(first / size) * size;
      if (first >= cards.length) first = Math.max(0, (Math.ceil(cards.length / size) - 1) * size);
      cards.forEach(function (c, i) { c.hidden = !(i >= first && i < first + size); });
      var page = Math.floor(first / size) + 1, pages = Math.max(1, Math.ceil(cards.length / size));
      if (prev) prev.disabled = page <= 1;
      if (nextB) nextB.disabled = page >= pages;
      if (live && announce) live.textContent = fill(text('topaz.page_status', 'Page {current} of {total}'), { current: page, total: pages });
      if (window.TemplateGridFill) window.TemplateGridFill.run();
    }
    function go(step) {
      var pages = Math.max(1, Math.ceil(cards.length / size));
      var page = Math.min(pages - 1, Math.max(0, Math.floor(first / size) + step));
      first = page * size; announce = true; layout();
      var focusBtn = step > 0 ? nextB : prev;
      if (focusBtn && focusBtn.disabled) { (step > 0 ? prev : nextB || prev).focus(); }
    }
    if (prev) prev.addEventListener('click', function () { go(-1); });
    if (nextB) nextB.addEventListener('click', function () { go(1); });
    var rt = 0;
    window.addEventListener('resize', function () { window.clearTimeout(rt); rt = window.setTimeout(layout, 120); });
    layout();
  });

  /* 10. description clamp on the watch page */
  document.querySelectorAll('[data-tz-desc]').forEach(function (box) {
    var body = box.querySelector('.tz-desc-body p'), btn = box.querySelector('[data-tz-desc-btn]'), lbl = box.querySelector('[data-tz-desc-lbl]');
    if (!body || !btn) return;
    box.classList.add('is-clamped');
    if (body.scrollHeight - body.clientHeight < 4) { box.classList.remove('is-clamped'); return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      box.classList.toggle('is-clamped', !open);
      if (lbl) lbl.textContent = text(open ? 'topaz.show_less' : 'topaz.show_more', lbl.textContent);
    });
  });

  /* 11. favourites through user/ajax_ulog (secure-forms.js adds the token) */
  on('[data-tz-fav]', 'click', function (e, btn) {
    e.preventDefault();
    var r = routes();
    var uid = cookie('user_id');
    if (!uid || uid === '0') { window.location.href = r.user_login || (base() + '/index.php/user/login'); return; }
    if (!window.jQuery || !r.user_ajax_ulog || btn.getAttribute('aria-busy') === 'true') return;
    btn.setAttribute('aria-busy', 'true');
    window.jQuery.ajax({ url: r.user_ajax_ulog, type: 'get', dataType: 'json', data: { ac: 'set', mid: btn.getAttribute('data-mid') || 1, id: btn.getAttribute('data-id'), type: 2 } })
      .done(function (res) { if (res && Number(res.code) === 1) btn.setAttribute('aria-pressed', 'true'); toast(res && res.msg ? res.msg : '', res && Number(res.code) === 1 ? 'success' : 'error'); })
      .fail(function () { toast(text('ui.connection_failed_try_again', ''), 'error'); })
      .always(function () { btn.removeAttribute('aria-busy'); });
  });

  /* 12. share / copy link */
  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea'); ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
      document.body.removeChild(ta);
    });
  }
  on('[data-tz-share]', 'click', function (e, btn) {
    var url = btn.getAttribute('data-url') || window.location.href;
    if (navigator.share && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
      navigator.share({ title: document.title, url: url }).catch(function () {});
      return;
    }
    copyText(url).then(function () { toast(text('ui.link_copied', 'Link copied'), 'success'); }, function () { toast(text('ui.select_and_copy_the_address', ''), 'error'); });
  });
  on('[data-tz-copy]', 'click', function (e, btn) {
    var input = document.getElementById(btn.getAttribute('data-tz-copy'));
    if (!input) return;
    copyText(input.value || input.textContent || '').then(function () { toast(text('ui.link_copied', 'Link copied'), 'success'); }, function () { input.select && input.select(); });
  });

  /* 13. password eye */
  on('[data-tz-eye]', 'click', function (e, btn) {
    var input = document.getElementById(btn.getAttribute('data-tz-eye'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  /* 14. captcha: loaded only when the visitor focuses the field or taps the box (never on page view) */
  function loadCaptcha(field) {
    var img = field && field.querySelector('.tz-captcha img');
    if (!img || !img.hidden) return;
    var src = img.getAttribute('data-src');
    if (!src) return;
    img.hidden = false;
    img.src = src.split('?')[0] + '?' + Date.now();
    var box = field.querySelector('.tz-captcha'); if (box) box.classList.add('tz-captcha--on');
  }
  document.addEventListener('focusin', function (e) {
    var input = e.target && e.target.closest ? e.target.closest('[data-tz-captcha-input]') : null;
    if (input) loadCaptcha(input.closest('.tz-field'));
  });
  on('[data-tz-captcha-box]', 'click', function (e, box) { var img = box.querySelector('img'); if (img && img.hidden) loadCaptcha(box.closest('.tz-field')); });
  on('.tz-captcha img', 'keydown', function (e, img) {
    if ((e.key === 'Enter' || e.key === ' ') && !img.classList.contains('mac_verify_img')) { e.preventDefault(); img.click(); }
  });

  /* 15. avatar upload (user/info) */
  document.querySelectorAll('[data-tz-avatar]').forEach(function (form) {
    var status = form.querySelector('[data-tz-avatar-status]');
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

  /* 15b. keep the active chip in view inside horizontal rows, and keep keyboard focus fully visible there */
  function centerChips() {
    document.querySelectorAll('.tz-chipbar').forEach(function (row) {
      var cur = row.querySelector('[aria-current]');
      if (!cur || row.scrollWidth <= row.clientWidth) return;
      var b = row.getBoundingClientRect(), c = cur.getBoundingClientRect();
      if (c.left < b.left + 4 || c.right > b.right - 4) row.scrollLeft += (c.left + c.width / 2) - (b.left + b.width / 2);
    });
  }
  centerChips();
  /* chip widths change once the web font is in, so centre again then (the active chip was cut by a few px) */
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(centerChips);
  window.addEventListener('load', centerChips);
  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!el || !el.closest) return;
    var row = el.closest('.tz-chipbar,.tz-sortbar,.tz-uc-nav');
    if (!row || row.scrollWidth <= row.clientWidth) return;
    var b = row.getBoundingClientRect(), c = el.getBoundingClientRect();
    if (c.left < b.left + 4) row.scrollLeft -= (b.left + 4 - c.left);
    else if (c.right > b.right - 4) row.scrollLeft += (c.right - b.right + 4);
  });

  /* 16. back to top */
  on('[data-tz-top]', 'click', function () { window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' }); });

  /* 17. comments: load the token-aware MAC fragment when the panel comes near */
  document.querySelectorAll('[data-tz-comments]').forEach(function (box) {
    var started = false;
    function startComments() {
      if (started || !window.MAC || !window.MAC.Comment) return;
      started = true;
      try { window.MAC.Comment.Init(); window.MAC.Comment.Show(1); } catch (err) { box.textContent = text('comment_loading_failed', ''); }
    }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { io.disconnect(); startComments(); } }); }, { rootMargin: '600px 0px' });
      io.observe(box);
    } else { startComments(); }
  });

  /* 18. reveal on scroll (only for sections that start below the fold, so nothing flashes) */
  if (!reduceMotion && 'IntersectionObserver' in window) {
    var revealIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.remove('tz-pre'); revealIo.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -40px 0px' });
    var vh = window.innerHeight || 800;
    document.querySelectorAll('[data-tz-reveal]').forEach(function (el) {
      if (el.getBoundingClientRect().top > vh) { el.classList.add('tz-pre'); revealIo.observe(el); }
    });
    document.addEventListener('focusin', function (e) {
      var sec = e.target && e.target.closest ? e.target.closest('.tz-pre') : null;
      if (sec) { sec.classList.remove('tz-pre'); revealIo.unobserve(sec); }
    });
  }

  /* 19. pointer spotlight on cards (fine pointers only) */
  if (finePointer && !reduceMotion) {
    var raf = 0, lastEv = null;
    document.addEventListener('pointermove', function (e) {
      lastEv = e;
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        var card = lastEv.target && lastEv.target.closest ? lastEv.target.closest('.tz-card-link') : null;
        if (!card) return;
        var r = card.getBoundingClientRect();
        card.style.setProperty('--tz-mx', ((lastEv.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        card.style.setProperty('--tz-my', ((lastEv.clientY - r.top) / r.height * 100).toFixed(1) + '%');
      });
    }, { passive: true });
  }
})(window, document);
