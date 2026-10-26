/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* Honey theme behaviour (vanilla JS, no dependencies). Popovers, history, account state, search suggestions,
   category sheet, slider, share/copy, clamp, back-to-top and small effects. Written for JAVCMS. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var msgs = window.TemplateMessages || {};
  var finePointer = window.matchMedia && window.matchMedia('(pointer:fine)').matches;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function t(key, fallback) { return msgs[key] || fallback || key; }
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function toast(text, kind) { if (window.TemplateUI && TemplateUI.message) { TemplateUI.message(text, { kind: kind || 'info' }); } }
  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/+^]/g, '\\$&') + '=([^;]*)'));
    if (!m) return '';
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  }
  var base = (window.maccms && maccms.path) || '';

  /* ---------- split headings (reactbits-style blur-in). html.hn-anim (inline script in public/include) hides
     .hn-split before the first paint, so the server-rendered heading never blinks; every heading that is not
     animated (reduced motion, below the fold, late script, nested markup) is revealed at once. ---------- */
  (function () {
    var heads = $$('.hn-split');
    try {
      var late = !!(window.performance && performance.now && performance.now() > 2200), fold = window.innerHeight * 0.98;
      heads.forEach(function (h) {
        var target = h.querySelector('a, span:not(.hn-badge)') || h;
        if (reduced || late || !root.classList.contains('hn-anim') || h.getBoundingClientRect().top > fold || target.children.length) { h.classList.add('is-in'); return; }
        var words = target.textContent.split(/(\s+)/), i = 0; target.textContent = '';
        words.forEach(function (w) { if (/^\s+$/.test(w) || !w) { target.appendChild(document.createTextNode(w)); return; } var s = document.createElement('span'); s.className = 'hn-w'; s.style.setProperty('--i', i++); s.textContent = w; target.appendChild(s); });
        requestAnimationFrame(function () { requestAnimationFrame(function () { h.classList.add('is-in'); }); });
      });
    } catch (e) { heads.forEach(function (h) { h.classList.add('is-in'); }); }
  })();

  /* ---------- popovers (details[data-hn-pop]) ---------- */
  var pops = $$('details[data-hn-pop]');
  function closePops(except) { pops.forEach(function (d) { if (d !== except && d.open) d.open = false; }); }
  pops.forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) { closePops(d); if (d.hasAttribute('data-hn-history')) renderHistory(); } });
  });
  document.addEventListener('click', function (e) {
    pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
    var s = $('[data-hn-search]');
    if (s && !s.contains(e.target)) hideSuggest();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = pops.filter(function (d) { return d.open; });
    if (open.length) { open.forEach(function (d) { d.open = false; var sm = d.querySelector('summary'); if (sm) sm.focus(); }); }
    closeSheet();
  });
  window.addEventListener('blur', function () { setTimeout(function () { if (document.activeElement && document.activeElement.tagName === 'IFRAME') { closePops(); hideSuggest(); } }, 0); });
  var more = $('.hn-more');
  if (more && finePointer) {
    var timer = 0, byHover = false;
    more.addEventListener('mouseenter', function () { clearTimeout(timer); if (!more.open) { more.open = true; byHover = true; } });
    more.addEventListener('mouseleave', function () { clearTimeout(timer); byHover = false; timer = setTimeout(function () { more.open = false; }, 260); });
    /* the click that follows a hover-open must not toggle the panel shut again */
    var moreSum = more.querySelector('summary');
    if (moreSum) moreSum.addEventListener('click', function (e) { if (byHover) { e.preventDefault(); byHover = false; } });
  }

  /* ---------- header fit (compact / tight) ---------- */
  var bar = $('.hn-bar'), header = $('#hn-header');
  function fit() {
    if (!bar || !header) return;
    header.classList.remove('is-compact', 'is-tight');
    if (window.innerWidth < 1024) return;
    var nav = $('.hn-nav', bar);
    function over() { return bar.scrollWidth > bar.clientWidth + 1 || (nav && nav.scrollWidth > nav.clientWidth + 1); }
    if (over()) header.classList.add('is-compact');
    if (over()) header.classList.add('is-tight');
  }
  /* phone tab bar: a label that does not fit uses two lines (multi-word) and/or a smaller size, never "..." */
  function fitTabs() {
    $$('.hn-tabbar a > span:last-child, .hn-tabbar button > span:last-child').forEach(function (s) {
      s.classList.remove('is-2l');
      s.style.fontSize = '';
      if (!s.offsetWidth) return;
      function over() { return s.scrollWidth > s.clientWidth + 1; }
      if (!over()) return;
      if (/\s/.test(s.textContent.trim())) s.classList.add('is-2l');
      for (var px = 10.5; over() && px >= 9; px -= 0.5) s.style.fontSize = px + 'px';
    });
  }
  /* narrow phones: a search pill too narrow for one word of its placeholder hides it (no "Se...") until it opens */
  function fitPlaceholder() {
    var sf = $('[data-hn-search]'), si = sf && $('input[name="wd"]', sf);
    if (!si || sf.classList.contains('is-open')) return;
    var cs = getComputedStyle(si), avail = si.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    var c = fitPlaceholder.c || (fitPlaceholder.c = document.createElement('canvas').getContext('2d'));
    if (!c) return;
    c.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
    sf.classList.toggle('is-ph-cut', avail < 56 && c.measureText(si.getAttribute('placeholder') || '').width > avail);
  }
  function fitAll() { fit(); fitTabs(); fitPlaceholder(); }
  fitAll();
  var fitTimer = 0;
  window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fitAll, 120); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitAll);
  // late web-font swaps (Quicksand is wider than the fallback) change label widths after fonts.ready resolved
  if (document.fonts && document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', fitAll);
  window.addEventListener('load', fitAll);

  /* ---------- account state (cookies set by the core on login) ---------- */
  var uid = cookie('user_id'), uname = cookie('user_name');
  if (uid && uid !== '0') {
    $$('[data-hn-guest]').forEach(function (a) { a.hidden = true; });
    $$('[data-hn-member]').forEach(function (a) { a.hidden = false; });
    var av = $('.hn-avatar');
    if (av) { av.classList.add('is-in'); var inner = $('.hn-avatar-in', av); if (inner && uname) { inner.textContent = uname.trim().charAt(0).toUpperCase(); } }
    var nm = $('[data-hn-account-name] span'); if (nm && uname) nm.textContent = uname;
    var meUrl = window.maccms && maccms.template_urls && maccms.template_urls.user_index;
    if (meUrl) $$('[data-hn-me]').forEach(function (a) { a.href = meUrl; });
  }

  /* ---------- viewing history (MAC.History cookie) ---------- */
  var setEl = $('[data-hn-history-set]');
  if (setEl && window.MAC && MAC.History && MAC.History.Set) {
    try { MAC.History.Set(setEl.getAttribute('data-name'), setEl.getAttribute('data-link'), setEl.getAttribute('data-pic')); } catch (e) {}
  }
  function renderHistory() {
    var list = $('[data-hn-history-list]'), empty = $('[data-hn-history-empty]');
    if (!list) return;
    list.textContent = '';
    var rows = [];
    try { rows = (window.MAC && MAC.History && MAC.History.Read) ? MAC.History.Read() : []; } catch (e) { rows = []; }
    rows.forEach(function (row) {
      var li = document.createElement('li'), a = document.createElement('a'), span = document.createElement('span');
      a.href = row.link;
      if (row.pic && /^(https?:\/\/|\/(?!\/))/.test(row.pic)) { var img = document.createElement('img'); img.src = row.pic; img.alt = ''; img.loading = 'lazy'; img.width = 72; img.height = 40; a.appendChild(img); }
      span.textContent = row.name; span.dir = 'auto'; a.appendChild(span); li.appendChild(a); list.appendChild(li);
    });
    if (empty) empty.hidden = rows.length > 0;
    var clr = $('[data-hn-history-clear]'); if (clr) clr.hidden = rows.length === 0;
  }
  var clearBtn = $('[data-hn-history-clear]');
  if (clearBtn) clearBtn.addEventListener('click', function (e) { e.preventDefault(); try { MAC.History.Clear(); } catch (err) {} renderHistory(); });

  /* ---------- search: blank guard, hot words, live suggestions ---------- */
  var form = $('[data-hn-search]'), input = form ? $('input[name="wd"]', form) : null, box = form ? $('.hn-suggest', form) : null;
  var listBox = box ? $('[data-hn-suggest-list]', box) : null, hot = box ? $('[data-hn-hot]', box) : null;
  var reqId = 0, sugTimer = 0, active = -1;
  // hiding also cancels the debounce and any request in flight, so a late answer cannot re-open the panel
  function hideSuggest() { clearTimeout(sugTimer); reqId++; if (box) { box.hidden = true; active = -1; } }
  function links() { return box ? $$('a', box) : []; }
  function showHot() { if (!box || !hot) return; if (listBox) listBox.textContent = ''; box.hidden = false; }
  function decode(s) { var d = new DOMParser().parseFromString('<p>' + String(s) + '</p>', 'text/html'); return d.body.textContent || ''; }
  function suggest(q) {
    var my = ++reqId;
    fetch(base + '/index.php/ajax/suggest?mid=1&limit=8&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (my !== reqId || !listBox || !input || input.value.trim() !== q || !form.contains(document.activeElement)) return;
        listBox.textContent = '';
        var rows = (data && data.code == 1 && Array.isArray(data.list)) ? data.list : [];
        if (!rows.length) { if (hot) { box.hidden = false; } else { hideSuggest(); } return; }
        var title = document.createElement('p'); title.className = 'hn-suggest-title'; title.textContent = t('honey.suggestions', 'Suggestions'); listBox.appendChild(title);
        rows.slice(0, 8).forEach(function (row) {
          var name = decode(row.name || ''); if (!name) return;
          var act = form.getAttribute('action') || (base + '/search/');
          var a = document.createElement('a'); a.href = act + (act.indexOf('?') === -1 ? '?' : '&') + 'wd=' + encodeURIComponent(name);
          a.innerHTML = '<svg class="hn-i" aria-hidden="true"><use href="' + (window.HONEY_TPL || '/') + 'assets/img/icons.svg?v=20261009-8#search"></use></svg>';
          var sp = document.createElement('span'); sp.textContent = name; sp.dir = 'auto'; a.appendChild(sp); listBox.appendChild(a);
        });
        if (hot) hot.hidden = true;
        box.hidden = false; active = -1;
      })
      .catch(function () {});
  }
  if (form && input) {
    form.addEventListener('submit', function (e) {
      if (!input.value.trim()) { e.preventDefault(); form.classList.remove('is-shake'); void form.offsetWidth; form.classList.add('is-shake'); input.focus(); }
    });
    // narrow phones: the pill grows over the bar while the field has focus (CSS <= 599px); it collapses a moment
    // after focus leaves the form, so a tap on the search button or a suggestion still lands where it was aimed
    var openT = 0;
    form.addEventListener('focusin', function (e) { clearTimeout(openT); if (e.target === input) form.classList.add('is-open'); });
    form.addEventListener('focusout', function () { clearTimeout(openT); openT = setTimeout(function () { if (!form.contains(document.activeElement)) form.classList.remove('is-open'); }, 250); });
    // keyboard: Tab off the last control (or Shift+Tab off the first) must un-hide the header tools before the browser
    // picks the next stop, otherwise History / Language / colour mode are skipped on phones
    form.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab' || !form.classList.contains('is-open')) return;
      var t = Array.prototype.filter.call(form.querySelectorAll('a[href],button,input,[tabindex]'), function (x) { return x.tabIndex >= 0 && x.getClientRects().length; });
      if ((!e.shiftKey && e.target === t[t.length - 1]) || (e.shiftKey && e.target === t[0])) { clearTimeout(openT); form.classList.remove('is-open'); }
    });
    input.addEventListener('focus', function () { if (!input.value.trim()) showHot(); });
    input.addEventListener('input', function () {
      clearTimeout(sugTimer);
      var q = input.value.trim();
      if (hot) hot.hidden = !!q;
      if (q.length < 2) { if (!q && hot) { showHot(); } else { hideSuggest(); } return; }
      sugTimer = setTimeout(function () { suggest(q); }, 300);
    });
    input.addEventListener('keydown', function (e) {
      var items = links();
      if (e.key === 'Escape') { e.preventDefault(); var wasOpen = box && !box.hidden; hideSuggest(); if (wasOpen) e.stopPropagation(); return; }
      if (!box || box.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = e.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1);
        items.forEach(function (a, i) { a.classList.toggle('is-on', i === active); });
      } else if (e.key === 'Enter' && active >= 0 && items[active]) { e.preventDefault(); location.href = items[active].href; }
    });
  }

  /* ---------- category strip: keep the active tab visible ---------- */
  var strip = $('[data-hn-strip]');
  if (strip) {
    var on = $('.is-on', strip);
    if (on && on !== strip.firstElementChild) {
      var sr = strip.getBoundingClientRect(), or = on.getBoundingClientRect();
      strip.scrollLeft += (or.left + or.width / 2) - (sr.left + sr.width / 2);
    }
    // start fade only once the strip is scrolled; keyboard focus is scrolled clear of both fades
    var markStrip = function () { strip.classList.toggle('is-scrolled', Math.abs(strip.scrollLeft) > 2); };
    strip.addEventListener('scroll', markStrip, { passive: true });
    markStrip();
    strip.addEventListener('focusin', function (e) {
      var t = e.target;
      try { if (!t.matches(':focus-visible')) return; } catch (err) { /* engines without :focus-visible */ }
      var rtl = getComputedStyle(strip).direction === 'rtl';
      var srx = strip.getBoundingClientRect(), tr = t.getBoundingClientRect();
      var lo = srx.left + (rtl ? 88 : 30), hi = srx.right - (rtl ? 30 : 88);
      if (tr.left < lo) strip.scrollLeft -= (lo - tr.left);
      else if (tr.right > hi) strip.scrollLeft += (tr.right - hi);
    });
  }

  /* ---------- captcha: request /verify/ only once the visitor starts using the form ---------- */
  function hnLoadVerify(scope) {
    $$('img[data-hn-src]', scope).forEach(function (img) { var s = img.getAttribute('data-hn-src'); img.removeAttribute('data-hn-src'); img.src = s; });
  }
  ['focusin', 'pointerdown'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var f = e.target && e.target.closest ? e.target.closest('form') : null;
      if (f && f.querySelector('img[data-hn-src]')) hnLoadVerify(f);
    }, true);
  });

  /* ---------- categories sheet (phones) ---------- */
  var sheet = $('#hn-sheet'), lastFocus = null;
  function openSheet(btn) { if (!sheet) return; lastFocus = btn || document.activeElement; sheet.hidden = false; document.body.style.overflow = 'hidden'; var c = $('[data-hn-sheet-close]', sheet); if (c) c.focus(); }
  function closeSheet() { if (!sheet || sheet.hidden) return; sheet.hidden = true; document.body.style.overflow = ''; if (lastFocus && lastFocus.focus) lastFocus.focus(); }
  $$('[data-hn-sheet-open]').forEach(function (b) { b.addEventListener('click', function () { openSheet(b); }); });
  if (sheet) {
    sheet.addEventListener('click', function (e) { if (e.target === sheet || e.target.closest('[data-hn-sheet-close]')) closeSheet(); });
    // modal dialog: Tab / Shift+Tab stay inside the sheet while it is open
    sheet.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab' || sheet.hidden) return;
      var f = $$('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])', sheet).filter(function (el) { return el.getClientRects().length > 0; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    document.addEventListener('focusin', function (e) { if (!sheet.hidden && !sheet.contains(e.target)) { var c = $('[data-hn-sheet-close]', sheet); if (c) c.focus(); } });
  }

  /* ---------- home slider (scroll-snap + arrows + dots + autoplay) ---------- */
  $$('[data-hn-slider]').forEach(function (slider) {
    var track = $('.hn-slides', slider), slides = $$('.hn-slide', slider), dots = $('[data-hn-dots]', slider);
    if (!track || slides.length < 2) { $$('.hn-slider-arrow', slider).forEach(function (b) { b.hidden = true; }); return; }
    var idx = 0, auto = 0, rtl = getComputedStyle(slider).direction === 'rtl';
    var dotBtns = slides.map(function (s, i) {
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', (i + 1) + ' / ' + slides.length);
      b.addEventListener('click', function () { go(i, true); }); if (dots) dots.appendChild(b); return b;
    });
    function mark(i) { idx = i; dotBtns.forEach(function (b, k) { if (k === i) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current'); }); }
    function go(i, user) {
      i = (i + slides.length) % slides.length;
      var w = track.clientWidth; track.scrollTo({ left: (rtl ? -1 : 1) * i * w, behavior: reduced ? 'auto' : 'smooth' }); mark(i);
      if (user) restart();
    }
    var st = 0;
    track.addEventListener('scroll', function () { clearTimeout(st); st = setTimeout(function () { var w = track.clientWidth || 1; mark(Math.round(Math.abs(track.scrollLeft) / w) % slides.length); }, 80); }, { passive: true });
    var prev = $('[data-hn-prev]', slider), next = $('[data-hn-next]', slider);
    if (prev) prev.addEventListener('click', function () { go(idx - 1, true); });
    if (next) next.addEventListener('click', function () { go(idx + 1, true); });
    function stop() { clearInterval(auto); auto = 0; }
    function restart() { stop(); if (!reduced) auto = setInterval(function () { if (!document.hidden) go(idx + 1); }, 6000); }
    slider.addEventListener('mouseenter', stop); slider.addEventListener('mouseleave', restart);
    slider.addEventListener('focusin', stop);
    mark(0); restart();
  });

  /* ---------- back to top ---------- */
  var topBtns = $$('[data-hn-top]'), totop = $('.hn-totop');
  topBtns.forEach(function (b) { b.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' }); }); });
  if (totop) {
    var ticking = false;
    window.addEventListener('scroll', function () { if (ticking) return; ticking = true; requestAnimationFrame(function () { totop.classList.toggle('is-on', window.scrollY > 600); ticking = false; }); }, { passive: true });
  }

  /* ---------- share + copy ---------- */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); } document.body.removeChild(ta);
    });
  }
  $$('[data-hn-share]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = b.getAttribute('data-url') || location.href, title = b.getAttribute('data-title') || document.title;
      if (navigator.share && !finePointer) { navigator.share({ title: title, url: url }).catch(function () {}); return; }
      copyText(url).then(function () { toast(t('ui.link_copied', 'Link copied'), 'success'); }, function () { toast(t('ui.select_and_copy_the_address', url)); });
    });
  });
  $$('[data-copy-target]').forEach(function (b) {
    b.addEventListener('click', function () { var el = document.getElementById(b.getAttribute('data-copy-target')); if (!el) return; copyText(el.value || el.textContent || '').then(function () { toast(t('ui.link_copied', 'Link copied'), 'success'); }, function () { el.select && el.select(); }); });
  });
  /* favourites use MAC.Ulog (home.js); keep the fallback href from navigating */
  document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a.mac_ulog'); if (a) e.preventDefault(); }, true);

  /* ---------- clamp long descriptions, expandable phone titles ---------- */
  $$('[data-hn-clamp]').forEach(function (sec) {
    var p = $('p', sec); if (!p) return;
    if (p.scrollHeight <= p.clientHeight + 2) { sec.classList.remove('is-clamp'); return; }
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'hn-clamp-btn'; btn.setAttribute('aria-expanded', 'false'); btn.textContent = t('honey.show_more', 'Show more');
    btn.addEventListener('click', function () { var open = sec.classList.toggle('is-clamp'); btn.setAttribute('aria-expanded', open ? 'false' : 'true'); btn.textContent = open ? t('honey.show_more', 'Show more') : t('honey.show_less', 'Show less'); });
    sec.appendChild(btn);
  });
  $$('[data-hn-title]').forEach(function (h) { h.addEventListener('click', function () { if (window.innerWidth < 768) h.classList.toggle('is-open'); }); });

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

  /* ---------- effects: split headings, scroll reveal, magnet, spotlight ---------- */
  var vh = window.innerHeight;
  if (!reduced) {
    var later = $$('.hn-section, .hn-card, .hn-meta').filter(function (el) { return el.getBoundingClientRect().top > vh; });
    if ('IntersectionObserver' in window && later.length) {
      var started = false;
      var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }); }, { rootMargin: '0px 0px -8% 0px' });
      window.addEventListener('scroll', function first() {
        if (started) return; started = true; window.removeEventListener('scroll', first);
        later.forEach(function (el) { if (el.getBoundingClientRect().top > window.innerHeight) { el.classList.add('hn-reveal'); io.observe(el); } });
      }, { passive: true });
    }
    if (finePointer) {
      $$('.hn-magnet').forEach(function (b) {
        b.addEventListener('pointermove', function (e) { var r = b.getBoundingClientRect(); var x = (e.clientX - r.left - r.width / 2) / r.width, y = (e.clientY - r.top - r.height / 2) / r.height; b.style.transform = 'translate(' + (x * 8).toFixed(1) + 'px,' + (y * 6).toFixed(1) + 'px)'; });
        b.addEventListener('pointerleave', function () { b.style.transform = ''; });
      });
      document.addEventListener('pointermove', function (e) {
        var card = e.target.closest && e.target.closest('.hn-spot'); if (!card) return;
        var r = card.getBoundingClientRect(); card.style.setProperty('--mx', (e.clientX - r.left) + 'px'); card.style.setProperty('--my', (e.clientY - r.top) + 'px');
      }, { passive: true });
    }
  }
  root.classList.add('hn-js');
})(window, document);
