/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Onyx Grid (Modern) - behaviour layer | JavCMS theme | original code (JAVCMS), JavCMS Community License 1.0
 * Vanilla ES5, no dependencies. Runs deferred, before the FX kit modules (so it can tag desktop-only decrypt headings).
 * Header dropdowns, INDEX overlay (focus trap + inert), phone search, member state, history + VIEWED stamps, featured
 * slider (stepped wipe), suggestions, clamp, share, SAVED stamp, lazy comments, lazy captcha, theme-color, wordmark fit. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var mm = function (q) { try { return window.matchMedia(q); } catch (e) { return { matches: false }; } };
  var reduce = mm('(prefers-reduced-motion: reduce)').matches;
  var fine = mm('(hover: hover) and (pointer: fine)').matches;
  var base = (window.maccms && window.maccms.path) || '';
  var lang = (root.getAttribute('lang') || 'en').toLowerCase();
  var complexScript = /^(ja|zh|ko|th|ar|hi)/.test(lang);
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function txt(key, fallback) { var a = window.OxText || {}, b = window.TemplateMessages || {}; return a[key] || b[key] || fallback || key; }
  function on(el, ev, fn, opt) { if (el) el.addEventListener(ev, fn, opt || false); }

  /* 0. Desktop-only decrypt of big headings (the phone LCP heading is never animated). Runs before fx.js scans. */
  /* Only short single-line headings: the proportional display face would otherwise re-wrap while glyphs cycle
     (layout shift); during the run the heading is nowrap + clipped, so the line box never changes. */
  if (!reduce && !complexScript && mm('(min-width: 1024px)').matches && fine) {
    $$('[data-ox-desk-scramble]').forEach(function (el) {
      if (el.hasAttribute('data-fx') || el.textContent.trim().length > 16) return;
      var cs = window.getComputedStyle(el), lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.1;
      if (el.getBoundingClientRect().height > lh * 1.5) return;
      el.classList.add('ox-scr');
      el.setAttribute('data-fx', 'scramble'); el.setAttribute('data-fx-trigger', 'load'); el.setAttribute('data-fx-duration', '0.7');
      window.setTimeout(function () { el.classList.remove('ox-scr'); }, 4000);
    });
  }

  /* 0b. Pages whose shader plate is desktop-only (login / register / reset): phones keep the static dither plate. */
  if (!mm('(min-width: 1024px)').matches) $$('[data-ox-desk-gl]').forEach(function (el) { el.setAttribute('data-fx-static', ''); });

  /* 1. Broken posters: hide the image so the themed dither placeholder (CSS background) shows; works for late images too. */
  var FRAMES = '.ox-thumb,.ox-cover,.ox-feat-media,.ox-mini-th,.ox-reveal,.ox-sg-th';
  function broken(img) { var f = img.closest ? img.closest(FRAMES) : null; if (f) f.classList.add('is-broken'); img.classList.add('is-broken'); }
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG') broken(t); }, true);
  $$('.ox-thumb img,.ox-cover img,.ox-feat-media img,.ox-mini-th img').forEach(function (img) { if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) broken(img); });

  /* 2. <details class="ox-pop"> dropdowns: one open at a time; outside click / Escape closes and returns focus. */
  var pops = $$('details[data-ox-pop]');
  pops.forEach(function (d) {
    on(d, 'toggle', function () { if (d.open) pops.forEach(function (o) { if (o !== d) o.removeAttribute('open'); }); });
  });
  on(document, 'click', function (e) { pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.removeAttribute('open'); }); });
  on(document, 'focusin', function (e) { pops.forEach(function (d) { if (d.open && !d.contains(e.target)) d.removeAttribute('open'); }); });

  /* 3. Phone search row. */
  var header = $('[data-ox-header]'), msearch = $('.ox-msearch');
  function closeSearch() { if (header) header.classList.remove('is-search'); if (msearch) msearch.setAttribute('aria-expanded', 'false'); }
  on(msearch, 'click', function () {
    var open = !header.classList.contains('is-search');
    header.classList.toggle('is-search', open);
    msearch.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var q = $('#ox-q'); if (q) q.focus(); }
  });

  /* 4. Full-screen INDEX overlay: stepped wipe, decrypting items, focus trap, inert page, Escape, focus return. */
  var overlay = $('[data-ox-index]'), menuBtn = $('[data-ox-menu]'), lastFocus = null;
  var behind = function () { return $$('body > *').filter(function (n) { return n !== overlay && n.tagName !== 'SCRIPT' && n.tagName !== 'STYLE'; }); };
  function decrypt(list) {
    if (reduce || complexScript) return;
    list.forEach(function (el, i) {
      window.setTimeout(function () { try { el.dispatchEvent(new Event('pointerenter')); } catch (e) {} }, 40 + i * 45);
    });
  }
  function focusables(box) { return $$('a[href]:not([tabindex="-1"]),button:not([disabled]):not([tabindex="-1"]),input:not([tabindex="-1"]),select,textarea,[tabindex]:not([tabindex="-1"])', box).filter(function (n) { return n.offsetParent !== null || n === document.activeElement; }); }
  function openIndex() {
    if (!overlay) return;
    lastFocus = document.activeElement;
    overlay.hidden = false;
    overlay.classList.remove('is-out');
    if (!reduce) { overlay.classList.remove('is-in'); void overlay.offsetWidth; overlay.classList.add('is-in'); }
    behind().forEach(function (n) { n.setAttribute('inert', ''); n.setAttribute('aria-hidden', 'true'); });
    root.classList.add('ox-locked');
    document.body.style.overflow = 'hidden';
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
    var close = $('[data-ox-close]', overlay);
    if (close) close.focus();
    decrypt($$('[data-fx~="scramble"]', overlay));
  }
  function closeIndex() {
    if (!overlay || overlay.hidden) return;
    var done = function () {
      overlay.hidden = true;
      overlay.classList.remove('is-in', 'is-out');
      behind().forEach(function (n) { n.removeAttribute('inert'); n.removeAttribute('aria-hidden'); });
      root.classList.remove('ox-locked');
      document.body.style.overflow = '';
      if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); decrypt($$('[data-fx~="scramble"]', menuBtn)); }
      if (lastFocus && lastFocus.focus) lastFocus.focus(); else if (menuBtn) menuBtn.focus();
    };
    if (reduce) { done(); return; }
    overlay.classList.remove('is-in'); void overlay.offsetWidth; overlay.classList.add('is-out');
    window.setTimeout(done, 240);
  }
  on(menuBtn, 'click', function () { if (overlay && overlay.hidden) openIndex(); else closeIndex(); });
  $$('[data-ox-close]').forEach(function (b) { on(b, 'click', closeIndex); });
  on(overlay, 'keydown', function (e) {
    if (e.key !== 'Tab') return;
    var f = focusables(overlay); if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  on(overlay, 'click', function (e) { var a = e.target.closest ? e.target.closest('a[href]') : null; if (a && a.getAttribute('href').charAt(0) !== '#') { root.classList.remove('ox-locked'); } });
  mm('(min-width: 1024px)').addEventListener && mm('(min-width: 1024px)').addEventListener('change', function (q) { if (q.matches) { closeIndex(); closeSearch(); } });

  on(document, 'keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (overlay && !overlay.hidden) { closeIndex(); return; }
    var openPop = pops.filter(function (d) { return d.open; })[0];
    if (openPop) { openPop.removeAttribute('open'); var s = $('summary', openPop); if (s) s.focus(); return; }
    if (header && header.classList.contains('is-search')) { closeSearch(); if (msearch) msearch.focus(); }
  });

  /* 5. Member state from the login cookies (pages are cached, so this runs on the client). */
  function cookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].indexOf('=');
      if (parts[i].slice(0, p) === name) { try { return decodeURIComponent(parts[i].slice(p + 1).replace(/\+/g, ' ')); } catch (err) { return parts[i].slice(p + 1); } }
    }
    return '';
  }
  var uid = cookie('user_id');
  if (uid && uid !== '0' && uid !== 'deleted') {
    $$('.hd-guest').forEach(function (el) { el.hidden = true; });
    $$('.hd-member').forEach(function (el) { el.hidden = false; });
    var uname = cookie('user_name');
    $$('[data-user-name]').forEach(function (el) { el.textContent = uname || txt('ui.member_center', 'My account'); });
  }
  $$('[data-logout]').forEach(function (b) { on(b, 'click', function (e) { e.preventDefault(); if (window.MAC && window.MAC.User) window.MAC.User.Logout(); }); });

  /* 6. Viewing history (same cookie as the MAC runtime) + VIEWED stamps on cards. */
  var entry = $('[data-history-entry]');
  if (entry && window.MAC && window.MAC.History && window.MAC.History.Set) {
    try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (err) {}
  }
  /* direction of a title: rtl when it contains Arabic/Hebrew letters (also when it starts with a Latin code), else ltr */
  function tdir(str) { return /[\u0590-\u08FF\uFB1D-\uFDFF\uFE70-\uFEFF]/.test(String(str)) ? 'rtl' : 'ltr'; }
  function readHistory() { try { return (window.MAC && window.MAC.History) ? (window.MAC.History.Read() || []) : []; } catch (err) { return []; } }
  function renderHistory() {
    var rows = readHistory();
    $$('[data-history-list]').forEach(function (box) {
      box.textContent = '';
      if (!rows.length) { var p = document.createElement('p'); p.className = 'history-empty'; p.textContent = txt('history_empty', 'No browsing history'); box.appendChild(p); return; }
      rows.slice(0, 6).forEach(function (row) { var a = document.createElement('a'); a.href = row.link; a.textContent = row.name; a.dir = tdir(row.name); box.appendChild(a); });
    });
    var seen = {};
    rows.forEach(function (r) { try { seen[new URL(r.link, location.href).pathname] = 1; } catch (e) {} });
    $$('.ox-card-a').forEach(function (a) {
      var path; try { path = new URL(a.href).pathname; } catch (e) { return; }
      var thumb = $('.ox-thumb', a);
      if (seen[path] && thumb && !$('.ox-viewed', thumb)) { var s = document.createElement('span'); s.className = 'ox-viewed'; s.textContent = txt('ox.viewed', 'Viewed'); thumb.appendChild(s); }
    });
  }
  renderHistory();
  $$('[data-history-clear]').forEach(function (b) { on(b, 'click', function () { if (window.MAC && window.MAC.History) window.MAC.History.Clear(); renderHistory(); $$('.ox-viewed').forEach(function (s) { s.remove(); }); }); });

  /* 7. Featured slider (bento cell A): arrows, keyboard, stepped wipe; no autoplay (so no pause control needed). */
  $$('[data-ox-feature]').forEach(function (box) {
    var slides = $$('[data-ox-slide]', box), now = $('[data-ox-slide-now]', box), cur = 0, n = slides.length;
    if (n < 2) return;
    var rtl = root.getAttribute('dir') === 'rtl';
    function go(i) {
      var next = (i % n + n) % n;
      if (next === cur) return;
      var prev = slides[cur];
      cur = next;
      var s = slides[cur];
      s.hidden = false;
      s.classList.add('is-active');
      prev.classList.remove('is-active');
      if (!reduce) { s.classList.remove('is-in'); void s.offsetWidth; s.classList.add('is-in'); s.style.zIndex = '2'; prev.style.zIndex = '1'; }
      var img = $('img', s); if (img && img.loading === 'lazy') img.loading = 'eager';
      window.setTimeout(function () { if (slides[cur] !== prev) prev.hidden = true; prev.style.zIndex = ''; s.style.zIndex = ''; s.classList.remove('is-in'); }, reduce ? 0 : 380);
      if (now) now.textContent = (cur + 1 < 10 ? '0' : '') + (cur + 1);
    }
    on($('.ox-feat-prev', box), 'click', function () { go(cur - 1); });
    on($('.ox-feat-next', box), 'click', function () { go(cur + 1); });
    on(box, 'keydown', function (e) {
      if (e.key === 'ArrowLeft') { go(cur + (rtl ? 1 : -1)); e.preventDefault(); }
      else if (e.key === 'ArrowRight') { go(cur + (rtl ? -1 : 1)); e.preventDefault(); }
    });
    var sx = null;
    on(box, 'touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    on(box, 'touchend', function (e) { if (sx === null) return; var dx = e.changedTouches[0].clientX - sx; sx = null; if (Math.abs(dx) > 50) go(cur + ((dx < 0) !== rtl ? 1 : -1)); }, { passive: true });
  });

  /* 8. Descriptions collapse with a toggle. On desktop detail pages the text runs in two columns: the collapsed state caps
     both columns (overflow goes sideways, hence the scrollWidth test), and WebKit keeps the capped column height after the
     class change unless the columns are re-laid out (rebalance). */
  function rebalance(el) { el.style.columnCount = '1'; void el.offsetWidth; el.style.columnCount = ''; }
  $$('[data-clamp]').forEach(function (box) {
    var body = $('.desc-body', box), btn = $('.desc-toggle', box);
    if (!body || !btn) return;
    body.classList.add('is-clamped');
    if (body.scrollHeight <= body.clientHeight + 4 && body.scrollWidth <= body.clientWidth + 4) { body.classList.remove('is-clamped'); rebalance(body); return; }
    btn.hidden = false;
    on(btn, 'click', function () {
      var open = body.classList.toggle('is-clamped') === false;
      rebalance(body);
      btn.textContent = btn.getAttribute(open ? 'data-less' : 'data-more');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* 9. Share panel toggle (watch page). */
  $$('[data-ox-share]').forEach(function (b) {
    on(b, 'click', function () {
      var p = document.getElementById(b.getAttribute('aria-controls')); if (!p) return;
      var open = p.hidden; p.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var i = $('input', p); if (i) { i.focus(); i.select(); } }
    });
  });

  /* 10. SAVED stamp: the MAC runtime adds .disabled to the favourite link on success. */
  $$('[data-ox-save]').forEach(function (a) {
    if (!window.MutationObserver) return;
    new MutationObserver(function () {
      if (a.classList.contains('disabled') && !a.classList.contains('is-saved')) {
        a.classList.add('is-saved');
        var l = $('[data-ox-save-l]', a); if (l) l.textContent = txt('ox.saved', 'Saved');
      }
    }).observe(a, { attributes: true, attributeFilter: ['class'] });
  });

  /* 10b. The MAC runtime rewrites .mac_hits with the live count as a bare number: put the thousands separator back
     (same format as the server's number_format). The class must stay: it is what counts the view. */
  if (window.MutationObserver) $$('.mac_hits').forEach(function (el) {
    new MutationObserver(function () {
      var raw = el.textContent.trim();
      if (/^\d{4,}$/.test(raw)) el.textContent = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }).observe(el, { childList: true, characterData: true, subtree: true });
  });

  /* 11. Comments = LOG: loaded when the section comes near the viewport (never on page view); typewriter for a new post. */
  var logBox = $('[data-ox-lazy-comments]'), logLoaded = false;
  function loadLog() { if (logLoaded || !logBox || !window.MAC || !window.MAC.Comment) return; logLoaded = true; window.MAC.Comment.Show(1); }
  if (logBox) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { io.disconnect(); loadLog(); } }, { rootMargin: '1200px 0px' });
      io.observe(logBox);
    } else loadLog();
    if (/#comments$/.test(location.hash)) loadLog();
    var fresh = false;
    on(logBox, 'click', function (e) { if (e.target.closest && e.target.closest('.comment_submit')) fresh = true; });
    if (window.MutationObserver) new MutationObserver(function () {
      if (!fresh) return;
      var list = $('.ox-log-list', logBox); if (list) { fresh = false; list.classList.add('is-fresh'); }
    }).observe(logBox, { childList: true });
  }

  /* 12. Page jump: Enter submits through MAC.PageGo. */
  var pageInput = document.getElementById('page');
  on(pageInput, 'keydown', function (e) { if (e.key !== 'Enter') return; e.preventDefault(); var go = $('.mac_page_go'); if (go) go.click(); });

  /* 13. Live search suggestions: ruled list [thumb] TITLE · CODE (debounced). */
  $$('form.ox-search,form.ox-bigsearch').forEach(function (form) {
    var input = $('input[name="wd"]', form), box = null, timer = 0, last = '', active = -1;
    if (!input || !window.fetch) return;
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
    function hide() { if (box) { box.hidden = true; box.textContent = ''; } active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    function decode(s) { try { return new DOMParser().parseFromString(String(s), 'text/html').documentElement.textContent; } catch (err) { return String(s); } }
    function render(list) {
      if (!list.length) { hide(); return; }
      if (!box) { box = document.createElement('div'); box.className = 'ox-suggest'; box.id = 'ox-sg-' + Math.random().toString(36).slice(2, 7); box.setAttribute('role', 'listbox'); form.appendChild(box); input.setAttribute('aria-controls', box.id); }
      box.textContent = ''; active = -1; input.removeAttribute('aria-activedescendant');
      list.slice(0, 6).forEach(function (row) {
        var name = decode(decode(row.name || row.en || '')); if (!name) return;
        var code = /^[A-Z0-9]+(?:-[A-Z0-9]+)+/i.exec(name);
        var a = document.createElement('a'), th = document.createElement('span'), t = document.createElement('span'), c = document.createElement('span');
        a.href = form.getAttribute('action') + (form.getAttribute('action').indexOf('?') > -1 ? '&' : '?') + 'wd=' + encodeURIComponent(code ? code[0] : name);
        a.setAttribute('role', 'option'); a.tabIndex = -1; a.id = box.id + '-o' + box.children.length; a.setAttribute('aria-selected', 'false');
        th.className = 'ox-sg-th';
        if (row.pic && /^https?:\/\//i.test(row.pic)) { var im = document.createElement('img'); im.alt = ''; im.width = 64; im.height = 36; im.loading = 'lazy'; im.src = row.pic; im.style.width = '100%'; im.style.height = '100%'; im.style.objectFit = 'cover'; th.appendChild(im); }
        t.className = 'ox-sg-t'; t.textContent = code ? name.slice(code[0].length).replace(/^[\s\-:]+/, '') || name : name; t.dir = tdir(t.textContent);
        var wrap = document.createElement('span'); wrap.style.minWidth = '0'; wrap.style.display = 'grid';
        if (code) { c.className = 'ox-sg-c'; c.textContent = code[0]; wrap.appendChild(c); }
        wrap.appendChild(t);
        a.appendChild(th); a.appendChild(wrap);
        box.appendChild(a);
      });
      box.hidden = false; input.setAttribute('aria-expanded', 'true');
    }
    input.setAttribute('autocomplete', 'off');
    on(input, 'input', function () {
      window.clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { hide(); return; }
      timer = window.setTimeout(function () {
        if (q === last) return; last = q;
        fetch(base + '/index.php/ajax/suggest?mid=1&limit=6&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (input.value.trim() === q) render(d && Number(d.code) === 1 && Array.isArray(d.list) ? d.list : []); })
          .catch(hide);
      }, 320);
    });
    on(input, 'keydown', function (e) {
      if (!box || box.hidden) return;
      var links = $$('a', box);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach(function (a, i) { a.classList.toggle('is-active', i === active); a.setAttribute('aria-selected', i === active ? 'true' : 'false'); }); input.setAttribute('aria-activedescendant', links[active].id);
      } else if (e.key === 'Enter' && active > -1 && links[active]) { e.preventDefault(); location.href = links[active].href; }
      else if (e.key === 'Escape') { hide(); }
    });
    on(input, 'blur', function () { window.setTimeout(hide, 180); });
  });

  /* 14. Account pages: the captcha image is requested only when the visitor reaches the field. */
  function verifyLoad(e) {
    var row = e.target && e.target.closest ? e.target.closest('.verify-row') : null, img = row ? row.querySelector('img[data-src]') : null;
    if (img && img.hidden) { img.hidden = false; img.src = img.getAttribute('data-src').split('?')[0] + '?' + Date.now(); }
  }
  on(document, 'focusin', verifyLoad);
  on(document, 'pointerdown', verifyLoad);

  /* 15. theme-color follows the colour mode. */
  var meta = $('meta[data-ox-theme-color]');
  function syncTheme() { if (meta) meta.setAttribute('content', root.getAttribute('data-color-mode') === 'light' ? meta.getAttribute('data-light') : meta.getAttribute('data-dark')); }
  syncTheme();
  if (window.MutationObserver) new MutationObserver(syncTheme).observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });

  /* 16. Wordmark fit: tracking down to 0, then 1px steps down to 13px, then ellipsis (CSS). */
  function fit() {
    $$('[data-ox-fit]').forEach(function (w) {
      var t = $('.ox-word-t', w), cell = w.parentNode; if (!t || !cell) return;
      t.style.letterSpacing = ''; t.style.fontSize = '';
      /* measure the label's own box (a fixed allowance for the mark stopped too early for 17-18 character names) */
      if (t.scrollWidth <= t.clientWidth + 1) return;
      t.style.letterSpacing = '0';
      var size = parseFloat(window.getComputedStyle(t).fontSize) || 21;
      while (t.scrollWidth > t.clientWidth + 1 && size > 13) { size -= 1; t.style.fontSize = size + 'px'; }
    });
  }
  fit();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  var ft = 0; on(window, 'resize', function () { window.clearTimeout(ft); ft = window.setTimeout(fit, 120); });

  /* 17. Back to top. */
  $$('[data-ox-top]').forEach(function (a) { on(a, 'click', function (e) { e.preventDefault(); window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); var m = document.getElementById('main-content'); if (m) { m.setAttribute('tabindex', '-1'); m.focus({ preventScroll: true }); } }); });

  /* 18. Horizontal strips keep the focused tab fully in view. */
  $$('.ox-tabsrow,.ox-sortrow,.uc-tabs').forEach(function (strip) {
    on(strip, 'focusin', function (e) { if (e.target && e.target.scrollIntoView) e.target.scrollIntoView({ block: 'nearest', inline: 'nearest' }); });
    var cur = $('[aria-current]', strip);
    if (cur && strip.scrollWidth > strip.clientWidth) { var r1 = cur.getBoundingClientRect(), r2 = strip.getBoundingClientRect(); strip.scrollLeft += (r1.left + r1.width / 2) - (r2.left + r2.width / 2); }
  });
})(window, document);
