/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Velvet Premiere (Modern) - core behaviour | original work for JavCMS (JavCMS Community License 1.0)
 * Classic deferred script (no modules needed): header states, curtain-drop drawer, programme card, search drop and
 * suggestions, popovers, client-side login state, watch history, the stage's programme tabs, favourites, clamp,
 * avatar upload, programme hover thumbnail, wordmark fit and the theme-color meta. Effects live in vp-fx.js. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var maccms = window.maccms || {};
  var tplPath = maccms.templatePath || '';
  var mq = function (q) { try { return window.matchMedia(q); } catch (e) { return { matches: false, addListener: function () {} }; } };
  var reduced = mq('(prefers-reduced-motion: reduce)').matches;
  var fine = mq('(hover: hover) and (pointer: fine)').matches;

  function text(key, fallback) {
    var pools = [window.VpText || {}, window.TemplateMessages || {}];
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
  function decodeEntities(value) {
    try { return new DOMParser().parseFromString(String(value || ''), 'text/html').documentElement.textContent || ''; } catch (e) { return String(value || ''); }
  }
  function sameOrigin(url) {
    try { var u = new URL(url, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; }
  }
  function placeholder() { return tplPath + 'assets/brand/' + (root.getAttribute('data-color-mode') === 'light' ? 'ph-light.svg' : 'ph-dark.svg'); }
  function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }

  /* ---------- images: placeholder on error (late and AJAX images too) ---------- */
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG' || img.getAttribute('data-vp-fallback')) return;
    if (!img.closest('.vp-thumb, .vp-screen, .vp-cover, .vp-spill, .vp-pr-thumb, .vp-pr-float, .vp-history-list, .vp-suggest, .vp-cameo, .vp-person, .vp-poster')) return;
    if (img.closest('.ai-av-img')) return;
    if (window.vpImg) window.vpImg(img);
  }, true);
  function scanImages() {
    each(document.querySelectorAll('.vp-thumb img, .vp-screen img, .vp-cover img, .vp-spill img'), function (img) {
      var src = img.getAttribute('src') || '';
      if (!src || src === '/' || (img.complete && !img.naturalWidth && img.getAttribute('loading') !== 'lazy')) { if (window.vpImg) window.vpImg(img); }
    });
  }

  /* ---------- theme-color meta follows the colour mode ---------- */
  function initThemeColor() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta || document.querySelector('meta[name="theme-color"][data-jv-head]')) return; /* the owner's colour (SiteHead) wins in both modes */
    var sync = function () { meta.setAttribute('content', root.getAttribute('data-color-mode') === 'light' ? '#fbf6f1' : '#12060c'); };
    sync();
    if ('MutationObserver' in window) new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['data-color-mode'] });
  }

  /* ---------- header: shrink on scroll, fit long languages, wordmark fit ---------- */
  var header = null;
  function initHeader() {
    header = document.getElementById('vp-header');
    if (!header) return;
    var ticking = false, last = -1, lastY = 0, tucked = false, dock = document.querySelector('.vp-dock');
    var onScroll = function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        ticking = false;
        var y = window.pageYOffset || root.scrollTop;
        var on = y > 24;
        if (on !== last) { last = on; header.classList.toggle('is-scrolled', on); }
        if (dock) {
          var tuck = y > 320 && y > lastY + 4 && (y + window.innerHeight) < (document.documentElement.scrollHeight - 160);
          if (y < lastY - 4 || y <= 320) tuck = false; else if (Math.abs(y - lastY) <= 4) tuck = tucked;
          if (tuck !== tucked) { tucked = tuck; dock.classList.toggle('is-tucked', tuck); }
          lastY = y;
        }
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    var bar = header.querySelector('.vp-bar-in');
    var fit = function () {
      header.classList.remove('is-tight', 'is-tighter');
      if (window.innerWidth < 1024 || !bar) return;
      var over = function () {
        var start = header.querySelector('.vp-bar-start'), end = header.querySelector('.vp-bar-end');
        return (start && start.scrollWidth > start.clientWidth + 1) || (end && end.scrollWidth > end.clientWidth + 1);
      };
      if (over()) { header.classList.add('is-tight'); if (over()) header.classList.add('is-tighter'); }
    };
    fitWordmarks();
    fit();
    var timer = 0;
    window.addEventListener('resize', function () { window.clearTimeout(timer); timer = window.setTimeout(function () { fit(); fitWordmarks(); }, 120); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { fit(); fitWordmarks(); });
  }
  function fitWordmarks() {
    each(document.querySelectorAll('[data-vp-fit]'), function (el) {
      el.style.letterSpacing = ''; el.style.fontSize = '';
      if (!el.clientWidth || el.scrollWidth <= el.clientWidth + 1) return;
      var spacing = parseFloat(window.getComputedStyle(el).letterSpacing) || 0;
      while (spacing > 0 && el.scrollWidth > el.clientWidth + 1) { spacing = Math.max(0, spacing - 0.5); el.style.letterSpacing = spacing + 'px'; }
      var size = parseFloat(window.getComputedStyle(el).fontSize) || 20;
      while (size > 13 && el.scrollWidth > el.clientWidth + 1) { size -= 1; el.style.fontSize = size + 'px'; }
    });
  }

  /* ---------- popovers (language <details>), programme card, account menu, search drop ---------- */
  var closers = [];
  function closeAll(except) { closers.forEach(function (c) { if (c.el !== except) c.close(); }); }
  function initPopovers() {
    each(document.querySelectorAll('details.vp-pop'), function (d) {
      closers.push({ el: d, close: function () { d.open = false; } });
      d.addEventListener('toggle', function () { if (d.open) closeAll(d); });
    });
    var catsBtn = document.querySelector('[data-vp-cats]');
    var card = document.getElementById('vp-cats-card');
    if (catsBtn && card) {
      var closeCats = function () { if (card.hidden) return; card.hidden = true; card.classList.remove('is-open'); catsBtn.setAttribute('aria-expanded', 'false'); };
      closers.push({ el: card, close: closeCats });
      catsBtn.addEventListener('click', function () {
        if (!card.hidden) { closeCats(); return; }
        closeAll(card);
        card.hidden = false;
        catsBtn.setAttribute('aria-expanded', 'true');
        window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { card.classList.add('is-open'); }); });
      });
      catsBtn.parentNode.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !card.hidden) { e.stopPropagation(); closeCats(); catsBtn.focus(); } });
    }
    var searchBtn = document.querySelector('[data-vp-search-open]');
    var drop = document.getElementById('vp-search');
    if (searchBtn && drop) {
      var closeSearch = function () { if (drop.hidden) return; drop.hidden = true; searchBtn.setAttribute('aria-expanded', 'false'); hideSuggest(); };
      closers.push({ el: drop, close: closeSearch });
      searchBtn.addEventListener('click', function () {
        if (!drop.hidden) { closeSearch(); return; }
        closeAll(drop);
        drop.hidden = false;
        searchBtn.setAttribute('aria-expanded', 'true');
        var input = drop.querySelector('input[type="search"]');
        if (input) { input.focus(); try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) {} }
      });
      drop.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (!suggestBox || suggestBox.hidden)) { closeSearch(); searchBtn.focus(); } });
    }
    document.addEventListener('click', function (e) {
      closers.forEach(function (c) {
        if (c.el.hidden && c.el.tagName !== 'DETAILS') return;
        var owner = c.el.tagName === 'DETAILS' ? c.el : c.el.parentNode;
        if (c.el.id === 'vp-search') owner = header;
        if (owner && !owner.contains(e.target)) c.close();
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      each(document.querySelectorAll('details.vp-pop[open]'), function (d) { d.open = false; var s = d.querySelector('summary'); if (s) s.focus(); });
    });
  }

  /* ---------- search suggestions (ajax/suggest) ---------- */
  var suggestBox = null, suggestInput = null, suggestTimer = 0, suggestSeq = 0;
  function hideSuggest() {
    if (suggestBox) { suggestBox.hidden = true; while (suggestBox.firstChild) suggestBox.removeChild(suggestBox.firstChild); }
    if (suggestInput) { suggestInput.setAttribute('aria-expanded', 'false'); suggestInput.removeAttribute('aria-activedescendant'); }
  }
  function initSuggest() {
    var input = document.querySelector('input[data-vp-suggest]');
    if (!input || !window.fetch) return;
    var form = input.form;
    suggestBox = form ? form.querySelector('.vp-suggest') : null;
    if (!suggestBox) return;
    suggestInput = input;
    var base = (maccms.path || '') + '/index.php/ajax/suggest';
    var searchUrl = function (q) { try { var u = new URL(form.getAttribute('action') || '/', location.href); u.searchParams.set('wd', q); return u.href; } catch (e) { return ''; } };
    var render = function (list) {
      hideSuggest();
      list.slice(0, 8).forEach(function (row, i) {
        var name = decodeEntities(row.name || row.en || '');
        var href = name ? searchUrl(name) : '';
        if (!href) return;
        var a = document.createElement('a');
        a.href = href;
        a.id = 'vp-sug-' + i;
        a.tabIndex = -1;
        a.setAttribute('role', 'option');
        a.setAttribute('aria-selected', 'false');
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
  }
  function initSearchGuard() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.matches || !form.matches('form[role="search"]')) return;
      var field = form.querySelector('input[name="wd"]');
      if (!field || field.value.trim() !== '') return;
      e.preventDefault();
      field.value = '';
      field.classList.remove('is-shake'); void field.offsetWidth; field.classList.add('is-shake');
      field.focus();
    });
  }

  /* ---------- the curtain-drop drawer (focus trap, inert page, Escape) ---------- */
  function initDrawer() {
    var drawer = document.getElementById('vp-drawer');
    var openers = document.querySelectorAll('[data-vp-drawer-open]');
    if (!drawer || !openers.length) return;
    var trigger = null, closeTimer = 0;
    var behind = function () { return [document.getElementById('main-content'), document.querySelector('.vp-footer'), document.querySelector('.vp-bar'), document.getElementById('vp-search')].filter(Boolean); };
    var focusables = function () { return Array.prototype.filter.call(drawer.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'), function (el) { return el.offsetParent !== null || el === document.activeElement; }); };
    var open = function (from) {
      window.clearTimeout(closeTimer);
      trigger = from || null;
      closeAll(null);
      drawer.hidden = false;
      root.classList.add('vp-lock');
      behind().forEach(function (el) { el.setAttribute('inert', ''); el.setAttribute('aria-hidden', 'true'); });
      each(openers, function (b) { b.setAttribute('aria-expanded', 'true'); });
      window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { drawer.classList.add('is-open'); }); });
      var first = drawer.querySelector('.vp-drawer-close');
      if (first) first.focus({ preventScroll: true });
      var to = from && from.getAttribute && from.getAttribute('data-vp-drawer-to');
      var target = to ? document.getElementById(to) : null;
      var box = drawer.querySelector('.vp-drawer-scroll');
      if (box) box.scrollTop = 0;
      if (target && box) { window.requestAnimationFrame(function () { box.scrollTop = Math.max(0, target.offsetTop - 12); }); }
    };
    var close = function () {
      if (drawer.hidden) return;
      drawer.classList.remove('is-open');
      behind().forEach(function (el) { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); });
      each(openers, function (b) { b.setAttribute('aria-expanded', 'false'); });
      root.classList.remove('vp-lock');
      closeTimer = window.setTimeout(function () { drawer.hidden = true; }, reduced ? 0 : 620);
      if (trigger && trigger.focus) trigger.focus({ preventScroll: true });
    };
    each(openers, function (b) { b.addEventListener('click', function () { if (drawer.hidden || !drawer.classList.contains('is-open')) open(b); else close(); }); });
    drawer.addEventListener('click', function (e) { if (e.target.closest('[data-vp-drawer-close]')) close(); });
    drawer.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key !== 'Tab') return;
      var list = focusables();
      if (!list.length) return;
      var first = list[0], last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    window.addEventListener('resize', function () { if (!drawer.hidden && window.innerWidth >= 1024 && !(header && header.classList.contains('is-tighter'))) close(); });
  }

  /* ---------- login state from cookies (listing pages are cached for everyone) ---------- */
  function initUser() {
    var id = cookie('user_id'), name = cookie('user_name');
    if (!id || id === '0') return;
    root.classList.add('vp-logged-in');
    each(document.querySelectorAll('[data-vp-user]'), function (link) {
      var center = sameOrigin(link.getAttribute('data-center') || '');
      if (center) link.setAttribute('href', center);
      link.classList.add('is-user');
      if (name) { link.setAttribute('title', name); var label = link.querySelector('[data-vp-user-label]'); if (label) label.textContent = name; }
    });
    each(document.querySelectorAll('[data-vp-user-name]'), function (el) { el.textContent = name || text('ui.my_account', ''); });
    var box = document.querySelector('[data-vp-account]');
    var menu = box ? box.querySelector('.vp-user-menu') : null;
    if (box && menu) {
      var show = function () { menu.hidden = false; renderHistory(); };
      var hide = function () { menu.hidden = true; };
      closers.push({ el: menu, close: hide });
      box.addEventListener('mouseenter', function () { if (fine) show(); });
      box.addEventListener('mouseleave', hide);
      box.addEventListener('focusin', show);
      box.addEventListener('focusout', function (e) { if (!box.contains(e.relatedTarget)) hide(); });
      box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); var l = box.querySelector('[data-vp-user]'); if (l) l.focus(); } });
    }
    each(document.querySelectorAll('[data-vp-logout]'), function (btn) {
      btn.addEventListener('click', function () {
        if (window.MAC && window.MAC.User && typeof window.MAC.User.Logout === 'function') { window.MAC.User.Logout(); return; }
        location.href = (maccms.path || '') + '/index.php/user/logout';
      });
    });
  }

  /* ---------- watch history (MAC.History cookie) ---------- */
  function readHistory() { try { return window.MAC && window.MAC.History ? window.MAC.History.Read() || [] : []; } catch (e) { return []; } }
  function renderHistory() {
    var list = document.querySelector('[data-vp-history]');
    if (!list) return;
    while (list.firstChild) list.removeChild(list.firstChild);
    var rows = readHistory().slice(0, 5);
    if (!rows.length) { var li0 = document.createElement('li'); li0.className = 'vp-history-empty'; li0.textContent = text('history_empty', ''); list.appendChild(li0); return; }
    rows.forEach(function (row) {
      var href = sameOrigin(row.link);
      if (!href) return;
      var li = document.createElement('li'), a = document.createElement('a'), img = document.createElement('img'), span = document.createElement('span');
      a.href = href;
      img.alt = ''; img.loading = 'lazy'; img.width = 64; img.height = 36;
      img.src = /^https?:\/\//i.test(row.pic || '') || String(row.pic || '').charAt(0) === '/' ? row.pic : placeholder();
      span.textContent = decodeEntities(row.name);
      a.appendChild(img); a.appendChild(span); li.appendChild(a); list.appendChild(li);
    });
  }
  function initHistory() {
    var entry = document.querySelector('[data-vp-history-entry]');
    if (entry && window.MAC && window.MAC.History) { try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (e) {} }
  }

  /* ---------- the stage: programme tabs I-V with a 7 s dwell ---------- */
  function initFeatures() {
    var stage = document.querySelector('[data-vp-stage]');
    var tabsBox = stage ? stage.querySelector('[data-vp-ftabs]') : null;
    if (!tabsBox) return;
    var tabs = tabsBox.querySelectorAll('[role="tab"]');
    var panels = stage.querySelectorAll('[data-vp-feature]');
    var pause = tabsBox.querySelector('[data-vp-ftabs-pause]');
    if (tabs.length < 2 || panels.length !== tabs.length) return;
    each(panels, function (p, i) { p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', 'vp-ftab-' + (i + 1)); });
    var current = 0, timer = 0, userPaused = reduced, hover = false, focusIn = false, visible = true;
    var dwell = 7000;
    var preload = function (i) { var img = panels[i] && panels[i].querySelector('img[loading="lazy"]'); if (img) img.removeAttribute('loading'); };
    var go = function (index, focus) {
      index = (index + tabs.length) % tabs.length;
      if (index === current) { if (focus) tabs[index].focus(); return; }
      var old = panels[current], next = panels[index];
      preload(index);
      tabs[current].setAttribute('aria-selected', 'false'); tabs[current].setAttribute('tabindex', '-1');
      tabs[index].setAttribute('aria-selected', 'true'); tabs[index].setAttribute('tabindex', '0');
      if (focus) tabs[index].focus();
      if (reduced) { old.hidden = true; old.classList.remove('is-active'); next.hidden = false; next.classList.add('is-active'); }
      else {
        old.classList.add('is-leaving');
        next.hidden = false; next.classList.add('is-entering', 'is-active');
        window.setTimeout(function () { old.hidden = true; old.classList.remove('is-leaving', 'is-active'); next.classList.remove('is-entering'); }, 520);
        stage.dispatchEvent(new CustomEvent('vp:feature', { detail: { index: index } }));
      }
      current = index;
      schedule();
    };
    var running = function () { return !userPaused && !hover && !focusIn && visible && !document.hidden; };
    var schedule = function () {
      window.clearTimeout(timer);
      tabsBox.classList.remove('is-running');
      if (!running()) return;
      void tabsBox.offsetWidth;
      tabsBox.style.setProperty('--vp-dwell', dwell + 'ms');
      tabsBox.classList.add('is-running');
      preload((current + 1) % tabs.length);
      timer = window.setTimeout(function () { go(current + 1, false); }, dwell);
    };
    each(tabs, function (tab, i) {
      tab.addEventListener('click', function () { go(i, false); });
      tab.addEventListener('keydown', function (e) {
        var rtl = root.getAttribute('dir') === 'rtl';
        if (e.key === 'ArrowRight') { e.preventDefault(); go(current + (rtl ? -1 : 1), true); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); go(current + (rtl ? 1 : -1), true); }
        else if (e.key === 'Home') { e.preventDefault(); go(0, true); }
        else if (e.key === 'End') { e.preventDefault(); go(tabs.length - 1, true); }
      });
    });
    if (pause) {
      if (reduced) { pause.hidden = true; tabsBox.querySelector('.vp-ftab-progress').hidden = true; }
      pause.addEventListener('click', function () {
        userPaused = !userPaused;
        pause.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
        var label = userPaused ? text('vp.play', 'Play') : text('vp.pause', 'Pause');
        pause.setAttribute('aria-label', label); pause.setAttribute('title', label);
        schedule();
      });
    }
    stage.addEventListener('mouseenter', function () { if (fine) { hover = true; schedule(); } });
    stage.addEventListener('mouseleave', function () { hover = false; schedule(); });
    stage.addEventListener('focusin', function () { focusIn = true; schedule(); });
    stage.addEventListener('focusout', function (e) { if (!stage.contains(e.relatedTarget)) { focusIn = false; schedule(); } });
    document.addEventListener('visibilitychange', schedule);
    if ('IntersectionObserver' in window) new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; schedule(); }, { threshold: 0.35 }).observe(stage);
    schedule();
  }

  /* ---------- favourites (user/ajax_ulog through secure-forms.js) ---------- */
  function initFavorites() {
    document.addEventListener('click', function (e) {
      var button = e.target.closest && e.target.closest('[data-vp-fav]');
      if (!button) return;
      e.preventDefault();
      var routes = maccms.template_urls || {};
      if (!cookie('user_id') || cookie('user_id') === '0') { location.href = routes.user_login || ((maccms.path || '') + '/index.php/user/login'); return; }
      if (!window.jQuery || !routes.user_ajax_ulog || button.getAttribute('aria-busy') === 'true') return;
      button.setAttribute('aria-busy', 'true');
      window.jQuery.ajax({ url: routes.user_ajax_ulog, type: 'get', dataType: 'json', data: { ac: 'set', mid: button.getAttribute('data-mid') || 1, id: button.getAttribute('data-id'), type: 2 } })
        .done(function (r) {
          var ok = r && Number(r.code) === 1;
          if (ok) { button.classList.add('is-on'); button.setAttribute('aria-pressed', 'true'); button.dispatchEvent(new CustomEvent('vp:fav', { bubbles: true })); }
          toast(r && r.msg ? String(r.msg) : '', ok ? 'success' : 'error');
        })
        .fail(function () { toast(text('ui.connection_failed_try_again', ''), 'error'); })
        .always(function () { button.removeAttribute('aria-busy'); });
    });
  }

  /* ---------- copy (fallback when the FX kit is not running) ---------- */
  function initCopy() {
    document.addEventListener('click', function (e) {
      var button = e.target.closest && e.target.closest('[data-vp-copy]');
      if (!button || (window.FX && button.matches('[data-fx~="copy"]'))) return;
      var source = document.querySelector(button.getAttribute('data-fx-copy-target') || button.getAttribute('data-vp-copy') || '#none');
      var value = source ? (source.value || source.textContent || '') : location.href;
      var fallback = function () {
        try { if (source && source.select) { source.focus(); source.select(); } toast(document.execCommand('copy') ? text('ui.link_copied', '') : text('ui.select_and_copy_the_address', ''), 'success'); }
        catch (err) { toast(text('ui.select_and_copy_the_address', '')); }
      };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(function () { toast(text('ui.link_copied', ''), 'success'); }, fallback);
      else fallback();
    });
  }

  /* ---------- synopsis clamp ---------- */
  function initClamp() {
    each(document.querySelectorAll('[data-vp-clamp]'), function (block) {
      block.classList.add('is-clamped');
      if (block.scrollHeight <= block.clientHeight + 4) { block.classList.remove('is-clamped'); return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'vp-more';
      button.setAttribute('aria-expanded', 'false');
      button.textContent = text('vp.show_more', '+');
      button.addEventListener('click', function () {
        var open = block.classList.toggle('is-clamped') === false;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? text('vp.show_less', '-') : text('vp.show_more', '+');
      });
      block.parentNode.insertBefore(button, block.nextSibling);
    });
  }

  /* ---------- avatar upload (user/info) ---------- */
  function initAvatar() {
    each(document.querySelectorAll('.gallery-avatar-form'), function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (form.getAttribute('aria-busy') === 'true' || !window.fetch) return;
        var input = form.querySelector('input[type="file"]'), button = form.querySelector('button[type="submit"]'), status = form.querySelector('.gallery-avatar-status');
        var report = function (m, kind) { if (status) status.textContent = m; toast(m, kind); };
        if (!input || !input.files.length) { report(text('select_avatar_file', ''), 'error'); return; }
        if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { report(text('avatar_upload_failed', ''), 'error'); return; }
        var endpoint = sameOrigin(form.action);
        if (!endpoint) return;
        form.setAttribute('aria-busy', 'true'); if (button) button.disabled = true;
        fetch(endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { if (!r.ok) throw new Error('upload'); return r.json(); })
          .then(function (res) {
            if (res && typeof res.__token__ === 'string') each(document.querySelectorAll('input[name="__token__"]'), function (t) { t.value = res.__token__; });
            if (!res || Number(res.code) !== 1) { report(res && typeof res.msg === 'string' ? res.msg : text('ui.connection_failed_try_again', ''), 'error'); return; }
            report(text('avatar_updated', ''), 'success');
            window.location.reload();
          })
          .catch(function () { report(text('ui.connection_failed_try_again', ''), 'error'); })
          .then(function () { form.removeAttribute('aria-busy'); if (button) button.disabled = false; });
      });
    });
  }

  /* ---------- back to top (footer medallion) ---------- */
  function initToTop() {
    each(document.querySelectorAll('[data-vp-totop]'), function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var main = document.getElementById('main-content');
        var lenis = window.FX && window.FX.lenis;
        if (lenis && typeof lenis.scrollTo === 'function' && !reduced) lenis.scrollTo(0); else window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
        if (main) { if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1'); main.focus({ preventScroll: true }); }
      });
    });
  }

  /* ---------- the programme: a small still floats beside the pointer (decorative, desktop) ---------- */
  function initProgrammeFloat() {
    var list = document.querySelector('[data-vp-programme]');
    if (!list || !fine) return;
    var float = null, img = null;
    var build = function () {
      float = document.createElement('div');
      float.className = 'vp-pr-float';
      float.setAttribute('aria-hidden', 'true');
      img = document.createElement('img');
      img.alt = ''; img.width = 224; img.height = 126;
      img.addEventListener('error', function () { if (img.getAttribute('data-ph') !== '1') { img.setAttribute('data-ph', '1'); img.src = placeholder(); } });
      float.appendChild(img);
      document.body.appendChild(float);
    };
    var raf = 0, x = 0, y = 0, on = false;
    var place = function () { raf = 0; var rtl = root.getAttribute('dir') === 'rtl'; float.style.transform = 'translate3d(' + (rtl ? x - 248 : x + 24) + 'px,' + (y - 63) + 'px,0)'; };
    list.addEventListener('pointermove', function (e) {
      if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
      var row = e.target.closest && e.target.closest('[data-vp-thumb]');
      if (!row) { if (on) { on = false; float.classList.remove('is-on'); } return; }
      if (!float) build();
      var src = row.getAttribute('data-vp-thumb');
      if (img.getAttribute('data-src') !== src) { img.setAttribute('data-src', src); img.removeAttribute('data-ph'); img.src = src; }
      x = e.clientX; y = e.clientY;
      if (!on) { on = true; float.classList.add('is-on'); }
      if (!raf) raf = window.requestAnimationFrame(place);
    }, { passive: true });
    list.addEventListener('pointerleave', function () { on = false; if (float) float.classList.remove('is-on'); });
    window.addEventListener('scroll', function () { if (on && float) { on = false; float.classList.remove('is-on'); } }, { passive: true });
  }

  /* ---------- keep keyboard focus (and the current tab / chip) visible in sideways-scrolling chip rows ---------- */
  function initChipFocus() {
    var reveal = function (row, el) {
      if (!row || !el || row.scrollWidth <= row.clientWidth + 2) return;
      var rb = row.getBoundingClientRect(), cb = el.getBoundingClientRect();
      if (cb.left >= rb.left + 8 && cb.right <= rb.right - 8) return;
      row.scrollLeft += (cb.left + cb.width / 2) - (rb.left + rb.width / 2);
    };
    var current = function () {
      each(document.querySelectorAll('.vp-chips, .vp-tabs'), function (row) { reveal(row, row.querySelector('[aria-current], .is-active')); });
    };
    current();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(current);
    document.addEventListener('focusin', function (e) {
      var el = e.target;
      reveal(el && el.closest ? el.closest('.vp-chips, .vp-tabs') : null, el);
    });
  }

  function init() {
    scanImages();
    initThemeColor();
    initHeader();
    initPopovers();
    initSuggest();
    initSearchGuard();
    initDrawer();
    initUser();
    initHistory();
    initFeatures();
    initFavorites();
    initCopy();
    initClamp();
    initAvatar();
    initProgrammeFloat();
    initChipFocus();
    initToTop();
    window.VP = { toast: toast, text: text, placeholder: placeholder, fitWordmarks: fitWordmarks };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window, document);
