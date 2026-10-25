/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Neon Nocturne (Modern) - core behaviour | original work for JavCMS (JavCMS Community License 1.0)
 * Classic deferred script (works without ES modules): header fit, popovers (categories, language, account), the search
 * combobox with suggestions, the glass side drawer (focus trap, inert page, Escape), client-side login state (pages are
 * cached for everyone), watch history, theme-color, placeholder swap on mode change, wordmark fit, neon signs that light
 * up when they enter the view, favourites, share/copy, report, clamp, search history, back-to-top and touch glow.
 * Effects that need modules (Embla windows, split-flap board, step-print ghosts, sign tilt) live in nn-fx.js. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var maccms = window.maccms || {};
  var mq = function (q) { try { return window.matchMedia(q); } catch (e) { return { matches: false, addListener: function () {} }; } };
  var reduced = mq('(prefers-reduced-motion: reduce)').matches;
  var fine = mq('(hover: hover) and (pointer: fine)').matches;
  var rtl = (root.getAttribute('dir') || '').toLowerCase() === 'rtl';
  // 2026-10-12 repair (phone perf): reveal each grid as one block instead of staggering every card (68 animated cards on the home).
  // This classic deferred script runs before the fx.js module scans [data-fx], so the kit never sees the per-card stagger on phones.
  if (mq('(max-width: 767px)').matches) {
    var stag = document.querySelectorAll('[data-fx~="stagger"]');
    for (var si = 0; si < stag.length; si++) { stag[si].setAttribute('data-fx', (stag[si].getAttribute('data-fx') || '').replace(/\bstagger\b/, 'reveal').replace(/\s+/g, ' ').trim()); }
  }

  function text(key, fallback) {
    var pools = [window.NnText || {}, window.TemplateMessages || {}];
    for (var i = 0; i < pools.length; i++) { if (typeof pools[i][key] === 'string' && pools[i][key]) return pools[i][key]; }
    return fallback || '';
  }
  function toast(message, kind) {
    if (!message) return;
    if (window.TemplateUI && window.TemplateUI.message) { window.TemplateUI.message(String(message), { kind: kind || 'info' }); return; }
    var status = document.getElementById('gallery-status');
    if (status) status.textContent = String(message);
  }
  function cookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var at = parts[i].indexOf('=');
      if (parts[i].slice(0, at) === name) { try { return decodeURIComponent(parts[i].slice(at + 1).replace(/\+/g, ' ')); } catch (e) { return parts[i].slice(at + 1); } }
    }
    return '';
  }
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* blocked */ } }
  };
  function decodeEntities(value) {
    try { return new DOMParser().parseFromString(String(value || ''), 'text/html').documentElement.textContent || ''; } catch (e) { return String(value || ''); }
  }
  function sameOrigin(url) {
    try { var u = new URL(url, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; }
  }
  function placeholder() { return window.nnPh ? window.nnPh() : ''; }
  function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }

  /* ---------- images: broken posters already fell back in the head; catch src="/" and finished failures ---------- */
  function scanImages() {
    each(document.querySelectorAll('.nn-shot img, .nn-win-shot img, .nn-cover img, .nn-bthumb img'), function (img) {
      var src = img.getAttribute('src') || '';
      if (!src || src === '/' || (img.complete && !img.naturalWidth && img.getAttribute('loading') !== 'lazy')) { if (window.nnImg) window.nnImg(img); }
    });
  }

  /* ---------- colour mode: theme-color meta + placeholder variant + session "lit" flag ---------- */
  function initMode() {
    /* the owner's colour (SiteHead meta with data-jv-head) wins in both modes */
    var meta = document.querySelector('meta[name="theme-color"][data-jv-head]') ? null : document.querySelector('meta[name="theme-color"]');
    var sync = function () {
      var light = root.getAttribute('data-color-mode') === 'light';
      if (meta) meta.setAttribute('content', light ? '#f5f2fa' : '#07060c');
      each(document.querySelectorAll('img[data-nn-fallback]:not([data-nn-face])'), function (img) { var ph = placeholder(); if (ph && img.getAttribute('src') !== ph) img.src = ph; });
    };
    sync();
    if ('MutationObserver' in window) new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });
    window.setTimeout(function () { try { window.sessionStorage.setItem('nn.lit', '1'); } catch (e) { /* private mode */ } }, 1200);
  }

  /* ---------- header: compact / collapsed fit, wordmark fit ---------- */
  var header = null;
  function fitWordmarks() {
    each(document.querySelectorAll('[data-nn-fit]'), function (el) {
      el.style.letterSpacing = ''; el.style.fontSize = '';
      if (!el.clientWidth || el.scrollWidth <= el.clientWidth + 1) return;
      var spacing = parseFloat(window.getComputedStyle(el).letterSpacing) || 0;
      while (spacing > 0 && el.scrollWidth > el.clientWidth + 1) { spacing = Math.max(0, spacing - 0.5); el.style.letterSpacing = spacing + 'px'; }
      var size = parseFloat(window.getComputedStyle(el).fontSize) || 22;
      while (size > 13 && el.scrollWidth > el.clientWidth + 1) { size -= 1; el.style.fontSize = size + 'px'; }
    });
  }
  function initHeader() {
    header = document.getElementById('nn-header');
    if (!header) return;
    var bar = header.querySelector('.nn-bar-in');
    var nav = header.querySelector('.nn-nav');
    var over = function () { return bar && bar.scrollWidth > bar.clientWidth + 1 || (nav && nav.scrollWidth > nav.clientWidth + 1); };
    var fit = function () {
      header.classList.remove('is-compact', 'is-collapsed');
      if (window.innerWidth < 1024) { fitWordmarks(); return; }
      if (over()) { header.classList.add('is-compact'); if (over()) header.classList.add('is-collapsed'); }
      fitWordmarks();
    };
    fit();
    var timer = 0;
    window.addEventListener('resize', function () { window.clearTimeout(timer); timer = window.setTimeout(fit, 120); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  }

  /* ---------- popovers: language <details>, categories panel, account menu ---------- */
  var closers = [];
  function closeAll(except) { closers.forEach(function (c) { if (c.el !== except) c.close(); }); }
  function initPopovers() {
    each(document.querySelectorAll('details.nn-pop'), function (d) {
      closers.push({ el: d, close: function () { d.open = false; } });
      d.addEventListener('toggle', function () { if (d.open) closeAll(d); });
      d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && d.open) { e.stopPropagation(); d.open = false; var s = d.querySelector('summary'); if (s) s.focus(); } });
    });
    var catsBtn = document.querySelector('[data-nn-cats]');
    var cats = document.getElementById('nn-cats');
    if (catsBtn && cats) {
      var closeCats = function () { if (cats.hidden) return; cats.hidden = true; catsBtn.setAttribute('aria-expanded', 'false'); };
      closers.push({ el: cats, close: closeCats });
      catsBtn.addEventListener('click', function () {
        if (!cats.hidden) { closeCats(); return; }
        closeAll(cats);
        cats.hidden = false;
        catsBtn.setAttribute('aria-expanded', 'true');
      });
      catsBtn.parentNode.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !cats.hidden) { e.stopPropagation(); closeCats(); catsBtn.focus(); } });
      catsBtn.parentNode.addEventListener('focusout', function (e) { if (e.relatedTarget && !catsBtn.parentNode.contains(e.relatedTarget)) closeCats(); });
    }
    document.addEventListener('click', function (e) {
      closers.forEach(function (c) { if (c.el && !c.el.contains(e.target) && !(c.owner && c.owner.contains(e.target))) c.close(); });
    });
    if (catsBtn && cats) closers[closers.length - 1].owner = catsBtn.parentNode;
  }

  /* ---------- search: drop toggle (phones / compact header) + combobox suggestions (ajax/suggest) ---------- */
  var suggestBox = null, suggestInput = null, suggestTimer = 0, suggestSeq = 0;
  function hideSuggest() {
    if (suggestBox) { suggestBox.hidden = true; while (suggestBox.firstChild) suggestBox.removeChild(suggestBox.firstChild); }
    if (suggestInput) { suggestInput.setAttribute('aria-expanded', 'false'); suggestInput.removeAttribute('aria-activedescendant'); }
  }
  function initSearch() {
    var form = document.getElementById('nn-search');
    var btn = document.querySelector('[data-nn-search-open]');
    if (!form) return;
    var input = form.querySelector('input[data-nn-suggest]');
    if (btn && header) {
      var closeDrop = function () { if (!header.classList.contains('is-search')) return; header.classList.remove('is-search'); btn.setAttribute('aria-expanded', 'false'); hideSuggest(); };
      closers.push({ el: form, owner: btn, close: closeDrop });
      btn.addEventListener('click', function () {
        if (header.classList.contains('is-search')) { closeDrop(); return; }
        closeAll(form);
        header.classList.add('is-search');
        btn.setAttribute('aria-expanded', 'true');
        if (input) window.setTimeout(function () { input.focus(); }, 30);
      });
      form.addEventListener('keydown', function (e) { if (e.key === 'Escape' && header.classList.contains('is-search') && (!suggestBox || suggestBox.hidden)) { closeDrop(); btn.focus(); } });
    }
    if (!input || !window.fetch) return;
    suggestBox = form.querySelector('.nn-suggest');
    suggestInput = input;
    if (!suggestBox) return;
    var base = (maccms.path || '') + '/index.php/ajax/suggest';
    var searchUrl = function (q) { try { var u = new URL(form.getAttribute('action') || '/', location.href); u.searchParams.set('wd', q); return u.href; } catch (e) { return ''; } };
    var render = function (list) {
      hideSuggest();
      list.slice(0, 8).forEach(function (row, i) {
        var name = decodeEntities(row.name || row.en || '');
        var href = name ? searchUrl(name) : '';
        if (!href) return;
        var a = document.createElement('a');
        a.href = href; a.id = 'nn-sug-' + i; a.tabIndex = -1;
        a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false');
        var img = document.createElement('img');
        img.alt = ''; img.loading = 'lazy'; img.width = 64; img.height = 36;
        var pic = typeof row.pic === 'string' && (/^https?:\/\//i.test(row.pic) || row.pic.charAt(0) === '/') ? row.pic : '';
        img.src = pic || placeholder();
        var label = document.createElement('span');
        label.textContent = name;
        a.appendChild(img); a.appendChild(label);
        suggestBox.appendChild(a);
      });
      suggestBox.hidden = !suggestBox.children.length;
      input.setAttribute('aria-expanded', suggestBox.hidden ? 'false' : 'true');
    };
    input.addEventListener('input', function () {
      window.clearTimeout(suggestTimer);
      var q = input.value.trim();
      if (q.length < 2) { hideSuggest(); return; }
      suggestTimer = window.setTimeout(function () {
        var seq = ++suggestSeq;
        fetch(base + '?mid=1&limit=8&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) { if (seq !== suggestSeq) return; if (data && Number(data.code) === 1 && Array.isArray(data.list)) render(data.list); else hideSuggest(); })
          .catch(function () { hideSuggest(); });
      }, 320);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !suggestBox.hidden) { e.preventDefault(); e.stopPropagation(); hideSuggest(); return; }
      if (suggestBox.hidden) return;
      var items = suggestBox.querySelectorAll('a');
      if (!items.length) return;
      var current = suggestBox.querySelector('a.is-focus');
      var index = Array.prototype.indexOf.call(items, current);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (current) { current.classList.remove('is-focus'); current.setAttribute('aria-selected', 'false'); }
        index = e.key === 'ArrowDown' ? (index + 1) % items.length : (index <= 0 ? items.length - 1 : index - 1);
        items[index].classList.add('is-focus');
        items[index].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', items[index].id);
        items[index].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && current) { e.preventDefault(); location.href = current.href; }
    });
    input.addEventListener('blur', function () { window.setTimeout(function () { if (!form.contains(document.activeElement)) hideSuggest(); }, 160); });
  }
  /* empty searches never submit; remember real ones (search history chips on the search page) */
  var HKEY = 'nn.searches';
  function historyList() { try { var v = JSON.parse(store.get(HKEY) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return typeof x === 'string' && x; }) : []; } catch (e) { return []; } }
  function initSearchForms() {
    each(document.querySelectorAll('form[data-nn-search]'), function (f) {
      f.addEventListener('submit', function (e) {
        var input = f.querySelector('input[name="wd"]');
        var q = input ? input.value.trim() : '';
        if (!q) { e.preventDefault(); if (input) input.focus(); return; }
        var list = historyList().filter(function (x) { return x !== q; });
        list.unshift(q.slice(0, 60));
        store.set(HKEY, JSON.stringify(list.slice(0, 10)));
      });
    });
    each(document.querySelectorAll('[data-nn-history-box]'), function (box) {
      var ul = box.querySelector('ul');
      var url = box.getAttribute('data-search-url') || '';
      var list = historyList();
      if (!ul || !list.length) return;
      list.forEach(function (q) {
        var li = document.createElement('li'), a = document.createElement('a');
        a.className = 'nn-chip';
        try { var u = new URL(url || location.href, location.href); u.searchParams.set('wd', q); a.href = u.href; } catch (err) { return; }
        a.textContent = q;
        li.appendChild(a); ul.appendChild(li);
      });
      box.hidden = false;
      var clear = box.querySelector('[data-nn-clear-history]');
      if (clear) clear.addEventListener('click', function () { try { window.localStorage.removeItem(HKEY); } catch (e) { /* blocked */ } box.hidden = true; });
    });
  }

  /* ---------- drawer (side door from the inline-end edge) ---------- */
  function initDrawer() {
    var drawer = document.getElementById('nn-drawer');
    var openers = document.querySelectorAll('[data-nn-drawer-open]');
    if (!drawer || !openers.length) return;
    var trigger = null, closeTimer = 0;
    var behind = function () { return [document.getElementById('main-content'), document.querySelector('.nn-footer'), document.querySelector('.nn-bar'), document.querySelector('.nn-skip'), document.querySelector('.nn-gotop'), document.querySelector('.nn-dock')].filter(Boolean); };
    var focusables = function () { return Array.prototype.filter.call(drawer.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'), function (el) { return el.offsetParent !== null || el === document.activeElement; }); };
    var open = function (from) {
      window.clearTimeout(closeTimer);
      trigger = from || null;
      closeAll(null);
      drawer.hidden = false;
      root.classList.add('nn-lock');
      behind().forEach(function (el) { el.setAttribute('inert', ''); el.setAttribute('aria-hidden', 'true'); });
      each(openers, function (b) { b.setAttribute('aria-expanded', 'true'); });
      window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { drawer.classList.add('is-open'); }); });
      var first = drawer.querySelector('.nn-drawer-close');
      if (first) first.focus({ preventScroll: true });
    };
    var close = function () {
      if (drawer.hidden) return;
      drawer.classList.remove('is-open');
      behind().forEach(function (el) { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); });
      each(openers, function (b) { b.setAttribute('aria-expanded', 'false'); });
      root.classList.remove('nn-lock');
      closeTimer = window.setTimeout(function () { drawer.hidden = true; }, reduced ? 0 : 380);
      if (trigger && trigger.focus) trigger.focus({ preventScroll: true });
    };
    each(openers, function (b) { b.addEventListener('click', function () { if (drawer.hidden || !drawer.classList.contains('is-open')) open(b); else close(); }); });
    drawer.addEventListener('click', function (e) { if (e.target.closest('[data-nn-drawer-close]')) close(); });
    drawer.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key !== 'Tab') return;
      var list = focusables();
      if (!list.length) return;
      var first = list[0], last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    window.addEventListener('resize', function () { if (!drawer.hidden && window.innerWidth >= 1024 && !(header && header.classList.contains('is-collapsed'))) close(); });
  }

  /* ---------- login state from cookies ---------- */
  function initUser() {
    var id = cookie('user_id'), name = cookie('user_name');
    if (!id || id === '0') return;
    root.classList.add('nn-logged');
    each(document.querySelectorAll('[data-nn-user]'), function (link) {
      var center = sameOrigin(link.getAttribute('data-center') || '');
      if (center) link.setAttribute('href', center);
      link.classList.add('is-user');
      if (name) { link.setAttribute('title', name); var label = link.querySelector('[data-nn-user-label]'); if (label) label.textContent = name; }
    });
    each(document.querySelectorAll('[data-nn-user-name]'), function (el) { el.textContent = name || text('ui.my_account', ''); });
    var box = document.querySelector('[data-nn-account]');
    var menu = box ? box.querySelector('.nn-user-menu') : null;
    if (box && menu) {
      var show = function () { menu.hidden = false; renderHistory(); };
      var hide = function () { menu.hidden = true; };
      closers.push({ el: box, close: hide });
      box.addEventListener('mouseenter', function () { if (fine) show(); });
      box.addEventListener('mouseleave', hide);
      box.addEventListener('focusin', show);
      box.addEventListener('focusout', function (e) { if (!box.contains(e.relatedTarget)) hide(); });
      box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); var l = box.querySelector('[data-nn-user]'); if (l) l.focus(); } });
    }
    each(document.querySelectorAll('[data-nn-logout]'), function (btn) {
      btn.addEventListener('click', function () {
        if (window.MAC && window.MAC.User && typeof window.MAC.User.Logout === 'function') { window.MAC.User.Logout(); return; }
        location.href = (maccms.path || '') + '/index.php/user/logout';
      });
    });
  }

  /* ---------- watch history (MAC.History cookie) ---------- */
  function readHistory() { try { return window.MAC && window.MAC.History ? window.MAC.History.Read() || [] : []; } catch (e) { return []; } }
  function renderHistory() {
    var list = document.querySelector('[data-nn-history]');
    if (!list) return;
    while (list.firstChild) list.removeChild(list.firstChild);
    var rows = readHistory().slice(0, 5);
    if (!rows.length) { var li0 = document.createElement('li'); li0.className = 'nn-history-empty'; li0.textContent = text('history_empty', ''); list.appendChild(li0); return; }
    rows.forEach(function (row) {
      var href = sameOrigin(row.link);
      if (!href) return;
      var li = document.createElement('li'), a = document.createElement('a'), img = document.createElement('img'), span = document.createElement('span');
      a.href = href;
      img.alt = ''; img.loading = 'lazy'; img.width = 56; img.height = 32;
      img.src = /^https?:\/\//i.test(row.pic || '') || String(row.pic || '').charAt(0) === '/' ? row.pic : placeholder();
      span.textContent = decodeEntities(row.name);
      a.appendChild(img); a.appendChild(span); li.appendChild(a); list.appendChild(li);
    });
  }
  function initHistory() {
    var entry = document.querySelector('[data-nn-history-entry]');
    if (entry && window.MAC && window.MAC.History) { try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (e) { /* cookie blocked */ } }
  }

  /* ---------- neon signs light up (flicker-on) when they first enter the view; a few keep a slow buzz ---------- */
  function initSigns() {
    var signs = document.querySelectorAll('.nn-vsign, .nn-hsign');
    if (!signs.length) return;
    var light = function (el) { if (!el.classList.contains('is-lit')) el.classList.add('is-lit'); };
    if (reduced || !('IntersectionObserver' in window)) { each(signs, light); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        light(en.target);
      });
    }, { rootMargin: '0px 0px -12% 0px' });
    each(signs, function (s) { io.observe(s); });
    /* infinite CSS animations (buzz, breathing glows) pause while off-screen */
    var anims = document.querySelectorAll('.nn-anim');
    if (anims.length) {
      var io2 = new IntersectionObserver(function (entries) { entries.forEach(function (en) { en.target.classList.toggle('is-off', !en.isIntersecting); }); });
      each(anims, function (a) { io2.observe(a); });
    }
  }

  /* ---------- favourites: MAC.Ulog handles a.mac_ulog; the heart flicker + spark ring follow its "disabled" class ---------- */
  function initFavorites() {
    document.addEventListener('click', function (e) {
      var fav = e.target.closest && e.target.closest('a.mac_ulog');
      if (!fav) return;
      e.preventDefault();
      if (!cookie('user_id') || cookie('user_id') === '0') { var routes = maccms.template_urls || {}; location.href = routes.user_login || ((maccms.path || '') + '/index.php/user/login'); e.stopImmediatePropagation(); }
    }, true);
    if (!('MutationObserver' in window)) return;
    each(document.querySelectorAll('a.mac_ulog'), function (fav) {
      new MutationObserver(function () {
        if (fav.classList.contains('disabled') && !fav.classList.contains('is-on')) {
          fav.classList.add('is-on', 'is-burst');
          fav.setAttribute('aria-pressed', 'true');
          window.setTimeout(function () { fav.classList.remove('is-burst'); }, 900);
        }
      }).observe(fav, { attributes: true, attributeFilter: ['class'] });
    });
  }

  /* ---------- share / copy, report, clamp, back to top ---------- */
  function copyText(value) {
    var done = function () { toast(text('ui.link_copied', ''), 'success'); };
    var fallback = function () {
      var t = document.createElement('textarea');
      t.value = value; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
      document.body.removeChild(t);
      if (ok) done(); else window.prompt(text('copy_manually', ''), value);
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(done, fallback); else fallback();
  }
  function initActions() {
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-nn-share],[data-nn-report],[data-nn-top]') : null;
      if (!el) return;
      if (el.hasAttribute('data-nn-share')) {
        e.preventDefault();
        var url = sameOrigin(el.getAttribute('data-url') || location.href) || location.href;
        var title = el.getAttribute('data-title') || document.title;
        if (navigator.share && !fine) { navigator.share({ title: title, url: url }).catch(function () { /* dismissed */ }); } else { copyText(url); }
      } else if (el.hasAttribute('data-nn-report')) {
        if (window.MAC && window.MAC.Gbook && typeof window.MAC.Gbook.Report === 'function') { e.preventDefault(); window.MAC.Gbook.Report(el.getAttribute('data-name') || '', el.getAttribute('data-id') || ''); }
      } else if (el.hasAttribute('data-nn-top')) {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
        var skip = document.getElementById('main-content');
        if (skip) { skip.setAttribute('tabindex', '-1'); skip.focus({ preventScroll: true }); }
      }
    });
    var top = document.querySelector('.nn-gotop[data-nn-top]');
    if (top) {
      var ticking = false;
      var onScroll = function () { if (ticking) return; ticking = true; window.requestAnimationFrame(function () { ticking = false; var y = window.pageYOffset || root.scrollTop; top.classList.toggle('is-on', y > 900 && (y + window.innerHeight < root.scrollHeight - 140 || document.activeElement === top)); }); };
      window.addEventListener('scroll', onScroll, { passive: true });
      top.addEventListener('focus', function () { top.classList.add('is-on'); });
      top.addEventListener('blur', onScroll);
      onScroll();
    }
  }
  function initClamp() {
    each(document.querySelectorAll('[data-nn-clamp]'), function (block) {
      block.classList.add('is-clamped');
      if (block.scrollHeight <= block.clientHeight + 4) { block.classList.remove('is-clamped'); return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'nn-more nn-clamp-btn';
      button.setAttribute('aria-expanded', 'false');
      if (block.id) button.setAttribute('aria-controls', block.id);
      var more = text('ui.view_more', '+'), less = text('nn.show_less', '-');
      button.textContent = more;
      button.addEventListener('click', function () {
        var open = block.classList.toggle('is-clamped') === false;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? less : more;
      });
      block.parentNode.insertBefore(button, block.nextSibling);
    });
  }

  /* ---------- touch: a tap lights the card's neon outline briefly ---------- */
  function initTouchGlow() {
    document.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'touch' || !e.target.closest) return;
      var card = e.target.closest('.nn-card, .nn-win');
      if (!card) return;
      card.classList.add('is-tap');
      window.setTimeout(function () { card.classList.remove('is-tap'); }, 450);
    }, { passive: true });
  }

  /* ---------- horizontal chip rows: current chip in view, vertical wheel scrolls them ---------- */
  function initRows() {
    var wheel = function (e) {
      var el = e.currentTarget;
      if (e.ctrlKey || el.scrollWidth <= el.clientWidth + 2 || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
      var before = el.scrollLeft;
      el.scrollLeft += (rtl ? -1 : 1) * e.deltaY;
      if (el.scrollLeft !== before) e.preventDefault();
    };
    var center = function () {
      each(document.querySelectorAll('[data-nn-row]'), function (row) {
        var on = row.querySelector('[aria-current="page"], .is-on');
        if (!on || row.scrollWidth <= row.clientWidth + 2) return;
        var rr = row.getBoundingClientRect(), ir = on.getBoundingClientRect();
        row.scrollLeft += (ir.left + ir.width / 2) - (rr.left + rr.width / 2);
      });
    };
    var reveal = function (e) {
      var row = e.currentTarget, a = e.target && e.target.closest ? e.target.closest('a,button') : null;
      if (!a || row.scrollWidth <= row.clientWidth + 2) return;
      var rr = row.getBoundingClientRect(), ir = a.getBoundingClientRect(), pad = 14;
      if (ir.right > rr.right - pad) row.scrollLeft += ir.right - rr.right + pad;
      else if (ir.left < rr.left + pad) row.scrollLeft -= rr.left - ir.left + pad;
    };
    each(document.querySelectorAll('[data-nn-row]'), function (row) { if (fine) row.addEventListener('wheel', wheel, { passive: false }); row.addEventListener('focusin', reveal); });
    center();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(center);
  }

  /* ---------- back links use history when we came from this site ---------- */
  function initBack() {
    document.addEventListener('click', function (e) {
      var back = e.target.closest && e.target.closest('[data-nn-back]');
      if (!back) return;
      try { if (document.referrer && new URL(document.referrer).origin === location.origin && window.history.length > 1) { e.preventDefault(); window.history.back(); } } catch (err) { /* follow the link */ }
    });
  }

  function init() {
    scanImages();
    initMode();
    initHeader();
    initPopovers();
    initSearch();
    initSearchForms();
    initDrawer();
    initUser();
    initHistory();
    initSigns();
    initFavorites();
    initActions();
    initClamp();
    initTouchGlow();
    initRows();
    initBack();
    window.NN = { toast: toast, text: text, placeholder: placeholder, fitWordmarks: fitWordmarks, copy: copyText };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window, document);
