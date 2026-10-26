/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* JAV themes UI (shared by jav and jav-modern). Plain DOM, no third-party code. */
(function () {
  'use strict';
  var doc = document, root = doc.documentElement;
  var text = window.galleryText || {};
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
  function cookie(name) {
    var m = doc.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
    if (!m) return '';
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  }
  /* helpers used by the account page scripts */
  var lastNotice = 0;
  window.galleryNotice = function (message, options) {
    if (window.TemplateUI) { if (lastNotice && TemplateUI.close) TemplateUI.close(lastNotice); lastNotice = TemplateUI.message(message, options || {}); return lastNotice; }
    window.alert(String(message)); return 0;
  };
  window.galleryConfirm = function (message, options) { if (window.TemplateUI) return TemplateUI.confirm(message, options || {}); return Promise.resolve(window.confirm(String(message))); };
  window.galleryFormat = function (message, values) { values = values || {}; return String(message).replace(/\{([a-zA-Z0-9_]+)\}/g, function (m, key) { return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : m; }); };
  function placeholder() {
    if (window.maccms && maccms.noPic) return maccms.noPic;
    return ((window.maccms && maccms.templatePath) || '/').replace(/\/?$/, '/') + 'statics/img/poster-placeholder.svg';
  }

  /* ---- images: skeleton off when loaded, neutral placeholder on error (also for late/AJAX images) ---- */
  function loaded(img) { var box = img.closest && img.closest('.stui-vodlist__thumb,.poster-frame'); if (box) box.classList.add('is-loaded'); }
  function broken(img) {
    /* auto-icon avatars keep their generated face underneath (auto-icon.js hides the broken picture) */
    if (img.closest && img.closest('.ai-av')) { loaded(img); return; }
    if (img.dataset.fallback === '1') { loaded(img); return; }
    img.dataset.fallback = '1';
    img.src = placeholder();
  }
  doc.addEventListener('load', function (e) { if (e.target && e.target.tagName === 'IMG') loaded(e.target); }, true);
  doc.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.closest && t.closest('.stui-vodlist__thumb,.poster-frame,.gallery-performer-grid,.stui-vodlist__media')) broken(t); }, true);
  function scanImages() {
    $all('.stui-vodlist__thumb img,.poster-frame img').forEach(function (img) {
      if (img.complete) { if (img.naturalWidth) loaded(img); else if (img.getAttribute('src')) broken(img); }
    });
  }
  /* legacy background thumbnails (data-original) used by actor/topic/article templates */
  function legacyLazy() {
    var items = $all('[data-original]');
    if (!items.length) return;
    function load(el) {
      var src = el.getAttribute('data-original'); el.removeAttribute('data-original');
      if (!src || !/^(https?:)?\//i.test(src) || /\/nopic\.gif/i.test(src)) src = placeholder();
      if (el.tagName === 'IMG') { el.src = src; return; }
      var probe = new Image();
      probe.onload = function () { el.style.backgroundImage = 'url("' + src.replace(/"/g, '%22') + '")'; el.classList.add('is-loaded'); };
      probe.onerror = function () { el.style.backgroundImage = 'url("' + placeholder() + '")'; el.classList.add('is-loaded'); };
      probe.src = src;
    }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); load(en.target); } }); }, { rootMargin: '300px' });
      items.forEach(function (el) { io.observe(el); });
    } else { items.forEach(load); }
  }

  /* ---- header dropdowns: one open at a time, outside click and Escape close ---- */
  function panelOf(btn) { var id = btn.getAttribute('aria-controls'); return id ? doc.getElementById(id) : null; }
  function closeAll(except) {
    $all('[data-dropdown][aria-expanded="true"]').forEach(function (btn) {
      if (btn === except) return;
      btn.setAttribute('aria-expanded', 'false');
      var p = panelOf(btn); if (p && !p.hasAttribute('data-panel-css')) p.hidden = true;
      root.classList.remove('has-open-' + (btn.getAttribute('data-dropdown') || 'menu'));
    });
  }
  function setupDropdowns() {
    $all('[data-dropdown]').forEach(function (btn) {
      var p = panelOf(btn); if (!p) return;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var open = btn.getAttribute('aria-expanded') !== 'true';
        closeAll(btn);
        btn.setAttribute('aria-expanded', String(open));
        if (!p.hasAttribute('data-panel-css')) p.hidden = !open;
        root.classList.toggle('has-open-' + (btn.getAttribute('data-dropdown') || 'menu'), open);
        if (open) { var f = p.querySelector('input[type="search"],input[name="wd"]'); if (f) setTimeout(function () { f.focus(); }, 30); }
      });
    });
    doc.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      if (e.target.closest('[data-dropdown]')) return;
      $all('[data-dropdown][aria-expanded="true"]').forEach(function (btn) {
        var p = panelOf(btn);
        if (p && !p.contains(e.target)) closeAll();
      });
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var open = doc.querySelector('[data-dropdown][aria-expanded="true"]');
      if (open) { closeAll(); open.focus(); }
    });
  }

  /* ---- language: select navigates; ?ui_lang is kept on internal links ---- */
  function setupLanguage() {
    var sel = doc.getElementById('avdb-ui-lang');
    if (sel) sel.addEventListener('change', function () {
      var alt = $all('link[rel="alternate"][hreflang]').filter(function (l) { return l.hreflang === sel.value; })[0];
      var url = new URL(alt ? alt.href : location.href);
      url.searchParams.set('ui_lang', sel.value);
      location.assign(url.href);
    });
    var lang = new URL(location.href).searchParams.get('ui_lang');
    if (!lang) return;
    $all('a[href]').forEach(function (a) {
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href) || a.hasAttribute('hreflang')) return;
      try {
        var u = new URL(href, location.href);
        if (u.origin === location.origin && !/\.(xml|jpg|jpeg|png|gif|webp|svg|css|js)$/i.test(u.pathname) && !u.searchParams.has('ui_lang')) { u.searchParams.set('ui_lang', lang); a.href = u.href; }
      } catch (e) {}
    });
  }

  /* ---- viewing history (cookie "recente" written on the watch page) ---- */
  function setupHistory() {
    var list = doc.getElementById('stui_history');
    if (!list) return;
    var entries = [];
    try { entries = JSON.parse(cookie('recente') || '[]'); } catch (e) { entries = []; }
    if (!Array.isArray(entries)) entries = [];
    list.textContent = '';
    entries.slice(0, 10).forEach(function (en) {
      if (!en || !en.vod_url) return;
      try {
        var u = new URL(en.vod_url, location.href);
        if (u.origin !== location.origin) return;
        var li = doc.createElement('li'), a = doc.createElement('a');
        a.href = u.href; a.textContent = String(en.vod_name || u.pathname);
        li.appendChild(a); list.appendChild(li);
      } catch (e) {}
    });
    if (!list.children.length) { var li = doc.createElement('li'); li.className = 'is-empty'; li.textContent = text.no_history || ''; list.appendChild(li); }
    $all('.historyclean').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        doc.cookie = 'recente=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        list.textContent = ''; var li = doc.createElement('li'); li.className = 'is-empty'; li.textContent = text.no_history || ''; list.appendChild(li);
      });
    });
  }

  /* ---- account link shows the signed-in name (client side, page cache safe) ---- */
  function setupAccount() {
    var name = cookie('user_name'), id = cookie('user_id');
    if (!id || !name) return;
    $all('[data-account-link]').forEach(function (a) {
      a.setAttribute('title', name); a.setAttribute('aria-label', name);
      var label = a.querySelector('[data-account-name]'); if (label) label.textContent = name;
    });
  }

  /* ---- watch page tabs (playback / downloads, sources) ---- */
  function tabs(listSel, panelSel) {
    $all(listSel).forEach(function (list) {
      var items = $all(':scope > li', list), scope = list.closest('.stui-player__side');
      items.forEach(function (li, idx) {
        var a = li.querySelector('a'); if (!a) return;
        a.addEventListener('click', function (e) {
          e.preventDefault();
          items.forEach(function (o) { o.classList.toggle('active', o === li); });
          if (scope) $all(panelSel, scope).forEach(function (p, pi) { p.classList.toggle('active', pi === idx); });
        });
      });
    });
  }

  /* ---- description clamp ---- */
  function setupDescription() {
    $all('[data-clamp]').forEach(function (body) {
      var btn = body.parentNode.querySelector('.desc-toggle');
      body.classList.add('is-clamped');
      if (body.scrollHeight <= body.clientHeight + 4) { body.classList.remove('is-clamped'); return; }
      if (!btn) return;
      btn.hidden = false;
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', String(open));
        body.classList.toggle('is-clamped', !open);
        btn.textContent = open ? (text['jav.show_less'] || '-') : (text['jav.show_more'] || '+');
      });
    });
  }

  /* ---- filter rows: "show more" ---- */
  function setupFilters() {
    $all('[data-filter-more]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = btn.closest('[data-filter-row]'); if (!row) return;
        var open = row.classList.toggle('is-collapsed') === false;
        btn.setAttribute('aria-expanded', String(open));
        btn.textContent = open ? (text['jav.show_less'] || '-') : (text['jav.show_more'] || '+');
      });
    });
  }

  /* ---- account forms (user pages): Enter submits, status line, token refresh ---- */
  function setupForms() {
    $all('input:not([type=hidden]):not([type=button]):not([type=submit]):not([type=reset]):not([type=image]),textarea').forEach(function (input) {
      if (input.getAttribute('aria-label') || (input.labels && input.labels.length)) return;
      var name = input.getAttribute('placeholder') || input.getAttribute('title') || '';
      if (name) input.setAttribute('aria-label', name);
    });
    $all('.gallery-account form').forEach(function (form) {
      var button = form.querySelector('#btn_submit');
      if (button && button.type === 'button') {
        form.addEventListener('submit', function (e) { e.preventDefault(); button.click(); });
        form.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !e.target.closest('button,input[type="button"],input[type="submit"]')) { e.preventDefault(); button.click(); }
        });
      }
      if (!form.querySelector('.gallery-form-status')) {
        var st = doc.createElement('p'); st.className = 'gallery-form-status'; st.setAttribute('role', 'status'); st.setAttribute('aria-live', 'polite'); form.appendChild(st);
      }
    });
    if (!window.jQuery) return;
    jQuery(doc).ajaxError(function (ev, xhr, settings) {
      if (!/user\//.test(settings.url || '')) return;
      $all('.gallery-form-status').forEach(function (s) { s.textContent = text.request_failed || ''; });
    });
    jQuery(doc).ajaxSuccess(function (ev, xhr, settings, data) {
      if (data && data.__token__) $all('input[name="__token__"]').forEach(function (i) { i.value = data.__token__; });
    });
    if (window.TemplateUI && TemplateUI.load) {
      var busy = new WeakMap();
      jQuery(doc).ajaxSend(function (ev, xhr, settings) {
        if (/user\//.test(settings.url || '') && String(settings.type || '').toUpperCase() === 'POST') busy.set(xhr, TemplateUI.load((window.TemplateMessages || {}).template_ui_working));
      });
      jQuery(doc).ajaxComplete(function (ev, xhr) { var id = busy.get(xhr); if (id) { TemplateUI.close(id); busy.delete(xhr); } });
    }
  }

  /* ---- misc: logo fallback, copy buttons, back to top ---- */
  function setupMisc() {
    $all('img[data-gallery-logo-fallback]').forEach(function (img) {
      function fb() { var p = img.getAttribute('data-gallery-logo-fallback'); if (!p) return; img.removeAttribute('data-gallery-logo-fallback'); img.src = p; }
      img.addEventListener('error', fb, { once: true });
      if (img.complete && !img.naturalWidth) fb();
    });
    $all('[data-gallery-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = doc.getElementById(btn.getAttribute('data-gallery-copy')); if (!input) return;
        input.focus(); input.select();
        var ok = false;
        try { if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(input.value); ok = true; } else { ok = doc.execCommand('copy'); } } catch (e) {}
        if (window.TemplateUI) TemplateUI.message(ok ? btn.getAttribute('data-copy-success') : btn.getAttribute('data-copy-failure'));
      });
    });
    var top = doc.querySelector('[data-to-top]');
    if (top) {
      top.hidden = false;
      var tick = false;
      window.addEventListener('scroll', function () {
        if (tick) return; tick = true;
        requestAnimationFrame(function () { top.classList.toggle('is-visible', window.scrollY > 900); tick = false; });
      }, { passive: true });
      top.addEventListener('click', function (e) {
        e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' });
        /* the button hides at the top: move keyboard focus to the content instead of losing it */
        var main = doc.getElementById('main-content') || doc.querySelector('main');
        if (main) { if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1'); try { main.focus({ preventScroll: true }); } catch (err) { main.focus(); } }
      });
    }
  }

  /* captcha images load on demand (focus of the code field, or a click / Enter on the image), never on page view */
  function setupCaptcha() {
    function load(img, refresh) {
      var src = img.getAttribute('data-src');
      if (src) { img.removeAttribute('data-src'); img.setAttribute('data-base', src); img.src = src; return; }
      if (!refresh) return;
      var base = img.getAttribute('data-base') || String(img.src).split('?')[0];
      img.src = base + (base.indexOf('?') < 0 ? '?' : '&') + '_=' + Date.now();
    }
    doc.addEventListener('focusin', function (e) {
      var f = e.target;
      if (!f || !f.matches || !f.matches('input[name="verify"]')) return;
      var box = f.closest('.gallery-gbook-actions,.gallery-verification-row,form');
      var img = box && box.querySelector('img[data-verify]');
      if (img) load(img, false);
    });
    doc.addEventListener('click', function (e) {
      var img = e.target && e.target.closest && e.target.closest('img[data-verify]');
      if (img) load(img, true);
    });
    if (window.MAC && MAC.Verify && typeof MAC.Verify.Refresh === 'function') {
      var refresh = MAC.Verify.Refresh;
      MAC.Verify.Refresh = function () {
        refresh.apply(this, arguments);
        $all('img[data-verify]').forEach(function (img) { if (!img.hasAttribute('data-src')) load(img, true); });
      };
    }
  }

  /* legacy lists (actor/topic/article sub-pages) that come back empty get a short note */
  function emptyLists() {
    $all('ul.stui-vodlist,ul.stui-vodlist__media').forEach(function (list) {
      if (list.children.length || list.hasAttribute('data-grid-fill') || list.nextElementSibling && list.nextElementSibling.classList.contains('empty-state')) return;
      var p = doc.createElement('p'); p.className = 'empty-state'; p.textContent = text.no_results || '';
      if (p.textContent) list.parentNode.insertBefore(p, list.nextSibling);
    });
  }

  function init() {
    scanImages(); legacyLazy(); emptyLists();
    setupDropdowns(); setupLanguage(); setupHistory(); setupAccount();
    tabs('.stui-player__side > .tab', '.tab-content > .item');
    tabs('.play-tab', '.play-content > .play-item');
    setupDescription(); setupFilters(); setupForms(); setupMisc(); setupCaptcha();
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();
})();
/* xvid scale build (2.11, THM 5.8): the header category scroller hides its scrollbar; a fade edge shows where more entries are */
(function () {
  'use strict';
  function update(ul) {
    var max = ul.scrollWidth - ul.clientWidth;
    var x = Math.abs(ul.scrollLeft);
    var rtl = window.getComputedStyle(ul).direction === 'rtl';
    var before = max > 2 && x > 2;
    var after = max > 2 && x < max - 2;
    ul.classList.toggle('jv-fade-l', rtl ? after : before);
    ul.classList.toggle('jv-fade-r', rtl ? before : after);
  }
  function setup() {
    var list = document.querySelectorAll('.stui-header__menu ul');
    Array.prototype.forEach.call(list, function (ul) {
      update(ul);
      ul.addEventListener('scroll', function () { update(ul); }, { passive: true });
      window.addEventListener('resize', function () { update(ul); });
      window.addEventListener('load', function () { update(ul); });
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', setup); } else { setup(); }
})();
