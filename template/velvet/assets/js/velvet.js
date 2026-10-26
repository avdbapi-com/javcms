/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*
 * Velvet theme behaviour (vanilla JS, no dependencies besides the optional MAC runtime).
 * Banner slider, Shuffle batches, header popovers, client-side login state, watch history,
 * search suggestions, image fallbacks and a few light effects (spotlight, magnet, reveal).
 */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var maccms = window.maccms || {};
  var tplPath = maccms.templatePath || ((document.currentScript && document.currentScript.src) || '').replace(/assets\/js\/velvet\.js.*$/, '');
  var placeholder = tplPath + 'assets/img/placeholder.svg';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  function text(key, fallback) {
    var pools = [window.VelvetText || {}, window.TemplateMessages || {}];
    for (var i = 0; i < pools.length; i++) { if (typeof pools[i][key] === 'string' && pools[i][key]) return pools[i][key]; }
    return fallback || '';
  }
  function toast(message, kind) {
    if (!message) return;
    if (window.TemplateUI && window.TemplateUI.message) { window.TemplateUI.message(message, { kind: kind || 'info' }); return; }
    var status = document.getElementById('gallery-status');
    if (!status) return;
    status.textContent = message;
    status.classList.add('is-visible');
    window.setTimeout(function () { status.classList.remove('is-visible'); }, 3500);
  }
  function cookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var index = parts[i].indexOf('=');
      if (parts[i].slice(0, index) === name) { try { return decodeURIComponent(parts[i].slice(index + 1).replace(/\+/g, ' ')); } catch (e) { return parts[i].slice(index + 1); } }
    }
    return '';
  }
  function decodeEntities(value) {
    try { return new DOMParser().parseFromString(String(value || ''), 'text/html').documentElement.textContent || ''; } catch (e) { return String(value || ''); }
  }
  function sameOrigin(url) {
    try { var u = new URL(url, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; }
  }

  /* ---------- images: skeleton off on load, placeholder on error ---------- */
  function imageDone(img) {
    var holder = img.closest && img.closest('.vv-thumb, .vv-cover, .vv-slide-media');
    if (holder) holder.classList.add('is-loaded');
  }
  function imageFailed(img) {
    if (img.classList.contains('vv-slide-bg')) { img.style.visibility = 'hidden'; return; }
    if (img.dataset.vvFallback === '1') { imageDone(img); return; }
    if (!img.closest('.vv-thumb, .vv-cover, .vv-slide-media, .vv-history-list, .vv-suggest, .vv-person, .vv-uface-wrap, .vv-topic, .vv-article, .vv-profile') && !img.classList.contains('vv-uface')) return;
    img.dataset.vvFallback = '1';
    img.removeAttribute('srcset');
    img.src = placeholder;
  }
  document.addEventListener('load', function (e) { if (e.target && e.target.tagName === 'IMG') imageDone(e.target); }, true);
  document.addEventListener('error', function (e) { if (e.target && e.target.tagName === 'IMG') imageFailed(e.target); }, true);
  function scanImages(scope) {
    var images = (scope || document).querySelectorAll('img');
    for (var i = 0; i < images.length; i++) {
      var img = images[i];
      var src = img.getAttribute('src') || '';
      if (!src || src === '/' || /\/nopic\.gif(?:[?#]|$)/i.test(src)) { imageFailed(img); continue; }
      if (img.complete) { if (img.naturalWidth) imageDone(img); else if (img.getAttribute('loading') !== 'lazy') imageFailed(img); }
    }
  }

  /* ---------- popovers: one open at a time, outside click and Escape close ---------- */
  function closePops(except) {
    var open = document.querySelectorAll('details.vv-pop[open]');
    for (var i = 0; i < open.length; i++) if (open[i] !== except) open[i].open = false;
  }
  function initPopovers() {
    var pops = document.querySelectorAll('details.vv-pop');
    for (var i = 0; i < pops.length; i++) {
      pops[i].addEventListener('toggle', function (event) {
        if (event.target.open) { closePops(event.target); closeSearch(); }
      });
    }
    document.addEventListener('click', function (event) {
      if (!event.target.closest) return;
      if (!event.target.closest('details.vv-pop')) closePops(null);
      if (!event.target.closest('.vv-userbox')) hideUserMenu();
      if (!event.target.closest('#vv-search') && !event.target.closest('.vv-search-toggle')) { hideSuggest(); if (window.innerWidth < 768) closeSearch(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      var open = document.querySelector('details.vv-pop[open]');
      if (open) { open.open = false; var summary = open.querySelector('summary'); if (summary) summary.focus(); }
      hideUserMenu();
      hideSuggest();
      if (closeSearch()) { var toggle = document.querySelector('.vv-search-toggle'); if (toggle) toggle.focus(); }
    });
    window.addEventListener('blur', function () {
      window.setTimeout(function () {
        var active = document.activeElement;
        if (active && active.tagName === 'IFRAME') { closePops(null); hideUserMenu(); hideSuggest(); }
      }, 0);
    });
  }

  /* ---------- mobile search panel ---------- */
  var header = null;
  function closeSearch() {
    if (!header || !header.classList.contains('is-search-open')) return false;
    header.classList.remove('is-search-open');
    var toggle = header.querySelector('.vv-search-toggle');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    return true;
  }
  function initSearchToggle() {
    header = document.getElementById('vv-header');
    if (!header) return;
    var toggle = header.querySelector('.vv-search-toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
      var open = !header.classList.contains('is-search-open');
      header.classList.toggle('is-search-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { closePops(null); var input = header.querySelector('.vv-search input'); if (input) input.focus(); }
    });
  }

  /* ---------- search suggestions (ajax/suggest, debounced) ---------- */
  var suggestBox = null, suggestTimer = 0, suggestSeq = 0;
  function hideSuggest() { if (suggestBox) { suggestBox.hidden = true; suggestBox.replaceChildren(); } }
  function initSuggest() {
    var input = document.querySelector('input[data-vv-suggest]');
    if (!input || !window.fetch) return;
    var form = input.form;
    suggestBox = form.querySelector('.vv-suggest');
    if (!suggestBox) return;
    var base = (maccms.path || '') + '/index.php/ajax/suggest';
    function searchUrl(query) {
      try { var u = new URL(form.getAttribute('action') || '/', location.href); u.searchParams.set('wd', query); return u.href; } catch (e) { return ''; }
    }
    function render(list) {
      suggestBox.replaceChildren();
      if (!list.length) { hideSuggest(); return; }
      list.slice(0, 8).forEach(function (row) {
        var name = decodeEntities(row.name || row.en || '');
        if (!name) return;
        var href = searchUrl(name);
        if (!href) return;
        var a = document.createElement('a');
        a.href = href;
        a.setAttribute('role', 'option');
        var img = document.createElement('img');
        img.alt = '';
        img.loading = 'lazy';
        img.width = 64; img.height = 36;
        var pic = typeof row.pic === 'string' && /^(https?:)?\/\//i.test(row.pic) ? row.pic : (typeof row.pic === 'string' && row.pic.charAt(0) === '/' ? row.pic : '');
        img.src = pic || placeholder;
        var label = document.createElement('span');
        label.textContent = name;
        a.appendChild(img);
        a.appendChild(label);
        suggestBox.appendChild(a);
      });
      suggestBox.hidden = !suggestBox.children.length;
    }
    input.addEventListener('input', function () {
      window.clearTimeout(suggestTimer);
      var query = input.value.trim();
      if (query.length < 2) { hideSuggest(); return; }
      suggestTimer = window.setTimeout(function () {
        var seq = ++suggestSeq;
        fetch(base + '?mid=1&limit=8&wd=' + encodeURIComponent(query), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) { if (seq === suggestSeq && data && Number(data.code) === 1 && Array.isArray(data.list)) render(data.list); else if (seq === suggestSeq) hideSuggest(); })
          .catch(function () { hideSuggest(); });
      }, 320);
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !suggestBox.hidden) { event.preventDefault(); event.stopPropagation(); hideSuggest(); return; }
      if (suggestBox.hidden) return;
      var items = suggestBox.querySelectorAll('a');
      if (!items.length) return;
      var current = suggestBox.querySelector('a.is-focus');
      var index = Array.prototype.indexOf.call(items, current);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (current) current.classList.remove('is-focus');
        index = event.key === 'ArrowDown' ? (index + 1) % items.length : (index <= 0 ? items.length - 1 : index - 1);
        items[index].classList.add('is-focus');
        items[index].scrollIntoView({ block: 'nearest' });
      } else if (event.key === 'Enter' && current) {
        event.preventDefault();
        location.href = current.href;
      }
    });
  }

  function initSearchGuard() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.matches || !form.matches('form[role="search"]')) return;
      var field = form.querySelector('input[name="wd"]');
      if (!field || field.value.trim() !== '') return;
      event.preventDefault();
      field.value = '';
      field.classList.remove('is-shake');
      void field.offsetWidth;
      field.classList.add('is-shake');
      field.focus();
    });
  }

  /* ---------- login state from cookies (pages are cached: never rendered server-side) ---------- */
  var userMenu = null;
  function hideUserMenu() { if (userMenu) userMenu.hidden = true; }
  function initUser() {
    var id = cookie('user_id');
    var name = cookie('user_name');
    var links = document.querySelectorAll('[data-vv-user]');
    userMenu = document.querySelector('.vv-user-menu');
    if (!id || id === '0') return;
    root.classList.add('vv-logged-in');
    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      var center = sameOrigin(link.getAttribute('data-center') || '');
      if (center) link.setAttribute('href', center);
      link.classList.add('is-user');
      var label = link.querySelector('[data-vv-user-name]');
      if (label && name) { label.textContent = name; link.setAttribute('title', name); }
    }
    var box = document.querySelector('.vv-userbox');
    if (box && userMenu) {
      box.addEventListener('mouseenter', function () { if (finePointer) userMenu.hidden = false; });
      box.addEventListener('mouseleave', function () { userMenu.hidden = true; });
      box.addEventListener('focusin', function () { userMenu.hidden = false; });
      box.addEventListener('focusout', function (event) { if (!box.contains(event.relatedTarget)) userMenu.hidden = true; });
    }
    var logout = document.querySelectorAll('[data-vv-logout]');
    for (var j = 0; j < logout.length; j++) {
      logout[j].addEventListener('click', function () {
        if (window.MAC && window.MAC.User && typeof window.MAC.User.Logout === 'function') { window.MAC.User.Logout(); return; }
        location.href = (maccms.path || '') + '/index.php/user/logout';
      });
    }
  }

  /* ---------- watch history (MAC.History cookie) ---------- */
  function readHistory() {
    try { return window.MAC && window.MAC.History ? window.MAC.History.Read() : []; } catch (e) { return []; }
  }
  function renderHistory(panel) {
    var list = panel.querySelector('.vv-history-list');
    if (!list) return;
    list.replaceChildren();
    var rows = readHistory();
    var clr = panel.querySelector('.vv-history-clear');
    if (clr) clr.hidden = !rows.length;
    if (!rows.length) {
      var empty = document.createElement('li');
      empty.className = 'vv-history-empty';
      empty.textContent = text('history_empty', '');
      list.appendChild(empty);
      return;
    }
    rows.forEach(function (row) {
      var href = sameOrigin(row.link);
      if (!href) return;
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = href;
      var img = document.createElement('img');
      img.alt = '';
      img.loading = 'lazy';
      img.width = 72; img.height = 41;
      img.src = /^(https?:)?\/\//i.test(row.pic || '') || String(row.pic || '').charAt(0) === '/' ? row.pic : placeholder;
      var label = document.createElement('span');
      label.textContent = row.name;
      a.appendChild(img);
      a.appendChild(label);
      li.appendChild(a);
      list.appendChild(li);
    });
  }
  function initHistory() {
    var entry = document.querySelector('.vv-history-entry');
    if (entry && window.MAC && window.MAC.History) {
      try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (e) {}
    }
    var panel = document.querySelector('.vv-history');
    if (!panel) return;
    panel.addEventListener('toggle', function () { if (panel.open) renderHistory(panel); });
    var clear = panel.querySelector('.vv-history-clear');
    if (clear) clear.addEventListener('click', function () {
      try { if (window.MAC && window.MAC.History) window.MAC.History.Clear(); } catch (e) {}
      renderHistory(panel);
    });
  }

  /* ---------- banner slider ---------- */
  function initBanner() {
    var banners = document.querySelectorAll('[data-vv-banner]');
    Array.prototype.forEach.call(banners, function (banner) {
      var slides = banner.querySelectorAll('[data-vv-slide]');
      if (!slides.length) { banner.parentNode.removeChild(banner); return; }
      if (slides.length < 2) return;
      var dots = banner.querySelector('[data-vv-dots]');
      var current = 0, timer = 0, paused = false;
      var dotButtons = [];
      Array.prototype.forEach.call(slides, function (slide, index) {
        slide.setAttribute('aria-label', (index + 1) + ' / ' + slides.length);
        if (dots) {
          var dot = document.createElement('button');
          dot.type = 'button';
          dot.className = 'vv-dot' + (index === 0 ? ' is-active' : '');
          dot.setAttribute('aria-label', (index + 1) + ' / ' + slides.length);
          dot.addEventListener('click', function () { go(index); restart(); });
          dots.appendChild(dot);
          dotButtons.push(dot);
        }
        if (index !== 0) slide.setAttribute('aria-hidden', 'true');
      });
      function go(index) {
        current = (index + slides.length) % slides.length;
        for (var i = 0; i < slides.length; i++) {
          var on = i === current;
          slides[i].classList.toggle('is-active', on);
          if (on) slides[i].removeAttribute('aria-hidden'); else slides[i].setAttribute('aria-hidden', 'true');
          var links = slides[i].querySelectorAll('a');
          for (var j = 0; j < links.length; j++) { if (!links[j].classList.contains('vv-slide-media')) { if (on) links[j].removeAttribute('tabindex'); else links[j].setAttribute('tabindex', '-1'); } }
          if (dotButtons[i]) dotButtons[i].classList.toggle('is-active', on);
        }
      }
      function restart() {
        window.clearInterval(timer);
        if (reduced) return;
        timer = window.setInterval(function () { if (!paused && !document.hidden) go(current + 1); }, 6000);
      }
      var prev = banner.querySelector('[data-vv-prev]');
      var next = banner.querySelector('[data-vv-next]');
      if (prev) prev.addEventListener('click', function () { go(current - 1); restart(); });
      if (next) next.addEventListener('click', function () { go(current + 1); restart(); });
      banner.addEventListener('mouseenter', function () { paused = true; });
      banner.addEventListener('mouseleave', function () { paused = false; });
      banner.addEventListener('focusin', function () { paused = true; });
      banner.addEventListener('focusout', function () { paused = false; });
      var startX = null, startY = null;
      banner.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { startX = e.touches[0].clientX; startY = e.touches[0].clientY; } }, { passive: true });
      banner.addEventListener('touchend', function (e) {
        if (startX === null || !e.changedTouches.length) return;
        var dx = e.changedTouches[0].clientX - startX, dy = e.changedTouches[0].clientY - startY;
        startX = null;
        if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
        var rtl = document.documentElement.getAttribute('dir') === 'rtl';
        go(current + ((dx < 0) !== rtl ? 1 : -1));
        restart();
      }, { passive: true });
      go(0);
      restart();
    });
  }

  /* ---------- Shuffle: rotate the pre-rendered batches of a module ---------- */
  function initShuffle() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-vv-shuffle]');
      if (!button) return;
      var module = button.closest('[data-vv-module]');
      if (!module) return;
      var batches = module.querySelectorAll('[data-vv-batch]');
      if (batches.length < 2) return;
      var current = 0;
      for (var i = 0; i < batches.length; i++) if (!batches[i].hidden) { current = i; break; }
      var next = (current + 1) % batches.length;
      batches[current].hidden = true;
      batches[current].classList.remove('is-shown');
      batches[next].hidden = false;
      batches[next].classList.add('is-shown');
      scanImages(batches[next]);
      button.classList.remove('is-spin');
      void button.offsetWidth;
      button.classList.add('is-spin');
      if (window.TemplateGridFill) window.TemplateGridFill.run();
    });
  }

  /* ---------- favorites (user/ajax_ulog through secure-forms.js) ---------- */
  function initFavorites() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-vv-fav]');
      if (!button) return;
      event.preventDefault();
      var routes = maccms.template_urls || {};
      if (!cookie('user_id') || cookie('user_id') === '0') { location.href = routes.user_login || ((maccms.path || '') + '/index.php/user/login'); return; }
      if (!window.jQuery || !routes.user_ajax_ulog || button.getAttribute('aria-busy') === 'true') return;
      button.setAttribute('aria-busy', 'true');
      window.jQuery.ajax({ url: routes.user_ajax_ulog, type: 'get', dataType: 'json', data: { ac: 'set', mid: button.getAttribute('data-mid') || 1, id: button.getAttribute('data-id'), type: 2 } })
        .done(function (r) {
          if (r && Number(r.code) === 1) { button.classList.add('is-on'); button.setAttribute('aria-pressed', 'true'); }
          toast(r && r.msg ? String(r.msg) : '', r && Number(r.code) === 1 ? 'success' : 'error');
        })
        .fail(function () { toast(text('ui.connection_failed_try_again', ''), 'error'); })
        .always(function () { button.removeAttribute('aria-busy'); });
    });
  }

  /* ---------- copy link ---------- */
  function initCopy() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-copy-target]');
      if (!button) return;
      var input = document.getElementById(button.getAttribute('data-copy-target'));
      if (!input) return;
      var value = input.value || input.textContent || '';
      function fallback() {
        try { input.focus(); input.select(); toast(document.execCommand('copy') ? text('ui.link_copied', '') : text('ui.select_and_copy_the_address', '')); }
        catch (e) { toast(text('ui.select_and_copy_the_address', '')); }
      }
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(function () { toast(text('ui.link_copied', ''), 'success'); }, fallback);
      else fallback();
    });
  }

  /* ---------- description clamp ---------- */
  function initClamp() {
    var blocks = document.querySelectorAll('[data-vv-clamp]');
    Array.prototype.forEach.call(blocks, function (block) {
      block.classList.add('is-clamped');
      if (block.scrollHeight <= block.clientHeight + 4) { block.classList.remove('is-clamped'); return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'vv-more-toggle';
      button.setAttribute('aria-expanded', 'false');
      button.textContent = text('velvet.show_more', '+');
      button.addEventListener('click', function () {
        var open = block.classList.toggle('is-clamped') === false;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? text('velvet.show_less', '-') : text('velvet.show_more', '+');
      });
      block.parentNode.insertBefore(button, block.nextSibling);
    });
  }

  /* ---------- avatar upload (user/info) ---------- */
  function initAvatar() {
    var forms = document.querySelectorAll('.gallery-avatar-form');
    Array.prototype.forEach.call(forms, function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true' || !window.fetch) return;
        var input = form.querySelector('input[type="file"]');
        var button = form.querySelector('button[type="submit"]');
        var status = form.querySelector('.gallery-avatar-status');
        function report(message, kind) { if (status) status.textContent = message; toast(message, kind); }
        if (!input || !input.files.length) { report(text('select_avatar_file', ''), 'error'); return; }
        if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { report(text('avatar_upload_failed', ''), 'error'); return; }
        var endpoint = sameOrigin(form.action);
        if (!endpoint) return;
        form.setAttribute('aria-busy', 'true');
        if (button) button.disabled = true;
        fetch(endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { if (!r.ok) throw new Error('upload'); return r.json(); })
          .then(function (result) {
            if (result && typeof result.__token__ === 'string') { var tokens = document.querySelectorAll('input[name="__token__"]'); for (var i = 0; i < tokens.length; i++) tokens[i].value = result.__token__; }
            if (!result || Number(result.code) !== 1) { report(result && typeof result.msg === 'string' ? result.msg : text('ui.connection_failed_try_again', ''), 'error'); return; }
            report(text('avatar_updated', ''), 'success');
            window.location.reload();
          })
          .catch(function () { report(text('ui.connection_failed_try_again', ''), 'error'); })
          .then(function () { form.removeAttribute('aria-busy'); if (button) button.disabled = false; });
      });
    });
  }

  /* ---------- effects: card spotlight, magnet buttons, scroll reveal ---------- */
  function initSpotlight() {
    if (!finePointer || reduced) return;
    var frame = 0, pending = null;
    document.addEventListener('pointermove', function (event) {
      var thumb = event.target.closest && event.target.closest('.vv-thumb, .vv-cover');
      if (!thumb) return;
      pending = { el: thumb, x: event.clientX, y: event.clientY };
      if (frame) return;
      frame = window.requestAnimationFrame(function () {
        frame = 0;
        if (!pending) return;
        var rect = pending.el.getBoundingClientRect();
        pending.el.style.setProperty('--mx', (pending.x - rect.left) + 'px');
        pending.el.style.setProperty('--my', (pending.y - rect.top) + 'px');
      });
    }, { passive: true });
  }
  function initMagnet() {
    if (!finePointer || reduced) return;
    var buttons = document.querySelectorAll('[data-vv-magnet]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('pointermove', function (event) {
        var rect = button.getBoundingClientRect();
        var x = (event.clientX - rect.left - rect.width / 2) / rect.width;
        var y = (event.clientY - rect.top - rect.height / 2) / rect.height;
        button.style.transform = 'translate(' + (x * 8).toFixed(1) + 'px,' + (y * 6).toFixed(1) + 'px)';
      });
      button.addEventListener('pointerleave', function () { button.style.transform = ''; });
    });
  }
  function initReveal() {
    if (reduced || !('IntersectionObserver' in window)) return;
    var items = document.querySelectorAll('[data-vv-reveal]');
    if (!items.length) return;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
    var armed = false;
    // Only content that is still below the fold when the visitor starts scrolling is animated,
    // so the first paint (and any screenshot taken without scrolling) always shows everything.
    window.addEventListener('scroll', function arm() {
      if (armed) return;
      armed = true;
      window.removeEventListener('scroll', arm);
      var limit = window.innerHeight + 40;
      // read every position first, then write: one style/layout pass instead of one per item
      var below = Array.prototype.filter.call(items, function (item) { return item.getBoundingClientRect().top > limit; });
      below.forEach(function (item, index) {
        // a class, not an inline custom property: an inherited --var would restyle every descendant
        item.classList.add('vv-pre');
        if (index % 2) item.classList.add('vv-pre-d');
        observer.observe(item);
      });
    }, { passive: true });
  }

  /* ---------- keep the active category tab visible (phone tab strip) ---------- */
  function initNavScroll() {
    var list = document.querySelector('[data-vv-navlist]');
    if (!list) return;
    var fade = function () {
      var over = list.scrollWidth > list.clientWidth + 2;
      list.classList.toggle('is-overflow', over);
      list.classList.toggle('is-end', !over || Math.abs(list.scrollLeft) + list.clientWidth >= list.scrollWidth - 4);
    };
    // desktop: long category names (some languages) step the padding/font down before scrolling is needed
    var desk = window.matchMedia ? window.matchMedia('(min-width: 1024px)') : null;
    var tooWide = function () { return list.scrollWidth > list.clientWidth + 2; };
    var fit = function () {
      list.classList.remove('is-tight', 'is-tighter');
      if (desk && desk.matches && tooWide()) {
        list.classList.add('is-tight');
        if (tooWide()) { list.classList.remove('is-tight'); list.classList.add('is-tighter'); }
      }
      fade();
    };
    list.addEventListener('scroll', fade, { passive: true });
    window.addEventListener('resize', fit);
    fit();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
    list.addEventListener('wheel', function (event) {
      if (list.scrollWidth <= list.clientWidth + 2 || Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
      var step = event.deltaY * (event.deltaMode === 1 ? 16 : 1) * (root.getAttribute('dir') === 'rtl' ? -1 : 1);
      var before = list.scrollLeft;
      list.scrollLeft += step;
      if (list.scrollLeft !== before) event.preventDefault();
    }, { passive: false });
    // keyboard focus: centre the focused tab so the end fade never hides it
    list.addEventListener('focusin', function (event) {
      var item = event.target && event.target.closest ? event.target.closest('li') : null;
      if (!item || list.scrollWidth <= list.clientWidth + 2) return;
      var lb = list.getBoundingClientRect(), fb = item.getBoundingClientRect();
      list.scrollLeft += (fb.left + fb.width / 2) - (lb.left + lb.width / 2);
    });
    // phone chip rows (sort / category filters) scroll sideways too: keep a keyboard-focused chip fully visible
    document.addEventListener('focusin', function (event) {
      var chip = event.target, row = chip && chip.closest ? chip.closest('.vv-chips, .vv-catrail') : null;
      if (!row || row.scrollWidth <= row.clientWidth + 2) return;
      var rb = row.getBoundingClientRect(), cb = chip.getBoundingClientRect();
      if (cb.left >= rb.left + 8 && cb.right <= rb.right - 8) return;
      row.scrollLeft += (cb.left + cb.width / 2) - (rb.left + rb.width / 2);
    });
    var active = list.querySelector('a.is-active');
    if (!active || list.scrollWidth <= list.clientWidth + 2) return;
    var lr = list.getBoundingClientRect(), ir = active.parentNode.getBoundingClientRect();
    list.scrollLeft += (ir.left + ir.width / 2) - (lr.left + lr.width / 2);
    fade();
  }

  /* ---------- dark mode: brighten only an admin logo that is too dark for the navy header ---------- */
  function lin(v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function initLogoTone() {
    var key = 'vv.logoDim';
    var img = document.querySelector('.vv-header .vv-logo-img');
    function store(value) { try { if (value) window.localStorage.setItem(key, value); else window.localStorage.removeItem(key); } catch (e) {} }
    if (!img) { root.classList.remove('vv-logo-dim'); store(''); return; }
    var src = img.currentSrc || img.src;
    var cached = null;
    try { cached = window.localStorage.getItem(key); } catch (e) {}
    if (cached && cached === src) { root.classList.add('vv-logo-dim'); return; }
    function measure() {
      var dim = false;
      try {
        var canvas = document.createElement('canvas');
        canvas.width = 48; canvas.height = 48;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, 48, 48);
        var data = ctx.getImageData(0, 0, 48, 48).data, sum = 0, count = 0;
        for (var i = 0; i < data.length; i += 4) {
          if (data[i + 3] < 128) continue;
          sum += 0.2126 * lin(data[i]) + 0.7152 * lin(data[i + 1]) + 0.0722 * lin(data[i + 2]);
          count++;
        }
        dim = count > 20 && sum / count < 0.14;
      } catch (e) { dim = false; }
      root.classList.toggle('vv-logo-dim', dim);
      store(dim ? src : '');
    }
    if (img.complete && img.naturalWidth) measure(); else img.addEventListener('load', measure);
  }

  /* ---------- auto-icons: count-up on views/score, favourite burst, back to top ---------- */
  var lowPower = function () { return reduced || root.getAttribute('data-ai-power') === 'low' || root.getAttribute('data-fx-power') === 'low'; };
  function countUp(el) {
    var raw = (el.textContent || '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d+)?$/.test(raw)) return;
    var end = parseFloat(raw), dec = raw.indexOf('.') > -1 ? raw.split('.')[1].length : 0;
    var fmt = function (v) { return dec ? v.toFixed(dec) : Math.round(v).toLocaleString('en-US'); };
    if (!(end > 0) || lowPower()) { if (end > 0) el.textContent = fmt(end); return; }
    var t0 = 0, dur = 900;
    el.style.fontVariantNumeric = 'tabular-nums';
    function step(t) {
      if (!t0) t0 = t;
      var k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(end * e);
      if (k < 1) window.requestAnimationFrame(step); else el.textContent = fmt(end);
    }
    window.requestAnimationFrame(step);
  }
  function initCountUp() {
    var els = document.querySelectorAll('[data-vv-count]');
    if (!els.length || !('IntersectionObserver' in window)) return;
    var seen = new WeakSet();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting || seen.has(entry.target)) return;
        seen.add(entry.target); io.unobserve(entry.target);
        // mac_hits rewrites the number after its AJAX call; count up to whatever is shown then
        window.setTimeout(function () { countUp(entry.target); }, entry.target.classList.contains('mac_hits') ? 450 : 0);
      });
    }, { threshold: 0.4 });
    Array.prototype.forEach.call(els, function (el) { io.observe(el); });
  }
  function initFavBurst() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-vv-fav]');
      if (!button || lowPower()) return;
      button.classList.remove('is-burst');
      void button.offsetWidth;
      button.classList.add('is-burst');
      window.setTimeout(function () { button.classList.remove('is-burst'); }, 600);
    });
  }
  function initToTop() {
    document.addEventListener('click', function (event) {
      var link = event.target.closest && event.target.closest('[data-vv-top]');
      if (!link) return;
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
      var target = document.querySelector('.skip-link') || document.querySelector('.vv-logo');
      if (target && target.focus) { try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); } }
    });
  }

  function init() {
    scanImages(document);
    initPopovers();
    initSearchToggle();
    initSuggest();
    initSearchGuard();
    initLogoTone();
    initUser();
    initHistory();
    initBanner();
    initShuffle();
    initFavorites();
    initCopy();
    initClamp();
    initAvatar();
    initSpotlight();
    initMagnet();
    initReveal();
    initNavScroll();
    initCountUp();
    initFavBurst();
    initToTop();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window, document);
