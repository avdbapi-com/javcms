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
 * Ember theme behaviour (vanilla JS, no dependencies).
 * Image fallback + skeleton, mobile drawer, dropdowns, home tabs (replaces SuperSlide),
 * expand bar, copy buttons, back to top, watch history, avatar upload,
 * and two light pointer effects (card spotlight, magnet pills).
 */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var tpl = (window.maccms && window.maccms.templatePath) || '/';
  var POSTER = tpl + 'assets/img/poster.svg';
  var PORTRAIT = tpl + 'assets/img/portrait.svg';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

  function msg(key, fallback) {
    var m = window.TemplateMessages || {};
    return m[key] || fallback || key;
  }
  function toast(text, kind) {
    if (window.TemplateUI && typeof window.TemplateUI.message === 'function') window.TemplateUI.message(text, { kind: kind || 'info' });
  }
  function closestThumb(img) { return img.closest ? img.closest('.e-thumb,.e-cover') : null; }

  /* ---- images: skeleton off on load, neutral placeholder on error (also for late/AJAX images) ---- */
  function fallback(img) {
    if (img.dataset.eFallback === '1') return;
    img.dataset.eFallback = '1';
    img.removeAttribute('srcset');
    img.src = img.classList.contains('face') || img.classList.contains('e-portrait') ? PORTRAIT : POSTER;
  }
  document.addEventListener('load', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG') return;
    var box = closestThumb(img);
    if (box) box.classList.add('is-loaded');
  }, true);
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG') return;
    if (closestThumb(img) || img.classList.contains('face') || img.classList.contains('e-img')) fallback(img);
  }, true);

  function initImages() {
    var list = document.querySelectorAll('.e-thumb img,.e-cover img,img.face,img.e-img');
    for (var i = 0; i < list.length; i++) {
      var img = list[i], src = img.getAttribute('src') || '';
      if (src === '' || src === '/' || /\/nopic\.gif(?:[?#]|$)/i.test(src)) { fallback(img); continue; }
      if (img.complete) {
        if (img.naturalWidth > 0) { var box = closestThumb(img); if (box) box.classList.add('is-loaded'); }
        else if (img.getAttribute('loading') !== 'lazy') fallback(img);
      }
    }
  }

  /* ---- drawer (.lm_fl) ---- */
  function drawerToggles() { return document.querySelectorAll('[data-e-drawer]'); }
  function setDrawer(open) {
    var drawer = document.getElementById('e-drawer');
    if (!drawer) return;
    drawer.hidden = !open;
    var t = drawerToggles();
    for (var i = 0; i < t.length; i++) t[i].setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('e-drawer-open', open);
  }
  function drawerOpen() { var d = document.getElementById('e-drawer'); return !!(d && !d.hidden); }

  /* ---- dropdown <details> (language menu) ---- */
  function closeDetails(except) {
    var open = document.querySelectorAll('details.e-lang[open], details.e-more-menu[open]');
    for (var i = 0; i < open.length; i++) if (open[i] !== except) open[i].open = false;
  }

  /* ---- home tabs (SuperSlide replacement): real links, switched in place ---- */
  function selectTab(tab, focus) {
    var list = tab.closest('[data-e-tabs]');
    if (!list) return;
    var tabs = list.querySelectorAll('[role="tab"]');
    for (var i = 0; i < tabs.length; i++) {
      var on = tabs[i] === tab;
      tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      tabs[i].setAttribute('tabindex', on ? '0' : '-1');
      var panel = document.getElementById(tabs[i].getAttribute('aria-controls'));
      if (panel) panel.hidden = !on;
    }
    var more = document.getElementById(list.getAttribute('data-e-tabs'));
    if (more && tab.getAttribute('href')) {
      more.setAttribute('href', tab.getAttribute('href'));
      var label = more.querySelector('span');
      if (label && tab.getAttribute('data-more')) label.textContent = tab.getAttribute('data-more');
    }
    if (focus) tab.focus();
    if (window.TemplateGridFill) window.TemplateGridFill.run();
  }

  /* ---- expand bar (.gd_bq .g_d) ---- */
  function initExpand() {
    var bars = document.querySelectorAll('[data-e-expand]');
    for (var i = 0; i < bars.length; i++) {
      var target = document.getElementById(bars[i].getAttribute('aria-controls'));
      if (!target) continue;
      if (target.scrollHeight <= target.clientHeight + 6) { target.classList.add('is-short'); bars[i].hidden = true; }
    }
  }

  /* ---- copy ---- */
  function copyText(text) {
    function done() { toast(msg('ui.link_copied', 'Link copied'), 'success'); }
    function legacy() {
      var area = document.createElement('textarea');
      area.value = text; area.setAttribute('readonly', ''); area.style.position = 'fixed'; area.style.opacity = '0';
      document.body.appendChild(area); area.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(area);
      if (ok) done(); else toast(msg('ui.select_and_copy_the_address', 'Select and copy the address'), 'error');
    }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, legacy); else legacy();
  }

  /* ---- favourites (user/ajax_ulog?ac=set&type=2; secure-forms.js adds the token) ---- */
  function favorite(button) {
    var urls = (window.maccms && window.maccms.template_urls) || {};
    var logged = window.MAC && window.MAC.User && String(window.MAC.User.IsLogin) === '1';
    if (!logged) { if (urls.user_login) window.location.href = urls.user_login; return; }
    if (!window.jQuery || !urls.user_ajax_ulog || button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    window.jQuery.ajax({ url: urls.user_ajax_ulog, type: 'GET', dataType: 'json', cache: false,
      data: { ac: 'set', mid: button.getAttribute('data-mid') || 1, id: button.getAttribute('data-id'), type: 2 } })
      .done(function (r) {
        toast(r && r.msg ? String(r.msg) : msg('favorites', 'Favorites'), r && Number(r.code) === 1 ? 'success' : 'error');
        if (r && Number(r.code) === 1) { button.classList.add('is-saved'); button.setAttribute('aria-pressed', 'true'); }
      })
      .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); })
      .always(function () { button.removeAttribute('aria-busy'); });
  }

  /* ---- avatar upload (user/info) ---- */
  function initAvatar() {
    var forms = document.querySelectorAll('.gallery-avatar-form');
    Array.prototype.forEach.call(forms, function (form) {
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
            if (result && typeof result.__token__ === 'string') document.querySelectorAll('input[name="__token__"]').forEach(function (t) { t.value = result.__token__; });
            if (Number(result && result.code) !== 1) { report(result && typeof result.msg === 'string' ? result.msg : msg('ui.connection_failed_try_again'), 'error'); return; }
            report(msg('avatar_updated'), 'success');
            window.location.reload();
          })
          .catch(function () { report(msg('ui.connection_failed_try_again'), 'error'); })
          .then(function () { if (button) button.disabled = false; form.removeAttribute('aria-busy'); });
      });
    });
  }

  /* ---- pointer effects ---- */
  function initPointer() {
    if (!fine || reduce) return;
    var raf = 0, last = null;
    document.addEventListener('pointermove', function (e) {
      last = e;
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        var t = last.target;
        if (!t || !t.closest) return;
        var thumb = t.closest('.e-thumb,.e-cover');
        if (thumb) {
          var r = thumb.getBoundingClientRect();
          thumb.style.setProperty('--mx', (last.clientX - r.left) + 'px');
          thumb.style.setProperty('--my', (last.clientY - r.top) + 'px');
        }
        var mag = t.closest('.e-magnet');
        if (mag) {
          var b = mag.getBoundingClientRect();
          var dx = (last.clientX - (b.left + b.width / 2)) / b.width, dy = (last.clientY - (b.top + b.height / 2)) / b.height;
          mag.style.transform = 'translate(' + (dx * 8).toFixed(1) + 'px,' + (dy * 6).toFixed(1) + 'px)';
        }
      });
    }, { passive: true });
    document.addEventListener('pointerout', function (e) {
      var mag = e.target && e.target.closest ? e.target.closest('.e-magnet') : null;
      if (mag && !mag.contains(e.relatedTarget)) mag.style.transform = '';
    }, { passive: true });
  }

  /* ---- main menu: categories that do not fit move into the "More" dropdown (>= 768px) ---- */
  function fitMenu() {
    var nav = document.querySelector('.e-menu'), ul = nav && nav.querySelector('ul');
    var more = ul && ul.querySelector('.e-menu-more'), list = more && more.querySelector('.e-more-list');
    if (!list) return;
    var det = more.querySelector('details');
    if (det) det.open = false;
    while (list.firstElementChild) ul.insertBefore(list.firstElementChild, more);
    more.hidden = true; more.classList.remove('has-current'); nav.classList.remove('is-fitted');
    if ((window.matchMedia && window.matchMedia('(max-width: 767px)').matches) || ul.scrollWidth <= nav.clientWidth + 1) return;
    nav.classList.add('is-fitted'); more.hidden = false;
    var items = Array.prototype.filter.call(ul.children, function (li) { return li !== more && !li.hasAttribute('data-e-pin') && li.offsetWidth > 0; });
    for (var i = items.length - 1; i >= 0 && ul.scrollWidth > nav.clientWidth + 1; i--) list.insertBefore(items[i], list.firstChild);
    if (list.querySelector('[aria-current="page"]')) more.classList.add('has-current');
  }
  var fitTimer = 0, fitWidth = 0;
  window.addEventListener('resize', function () {
    if (window.innerWidth === fitWidth) return;
    clearTimeout(fitTimer);
    fitTimer = setTimeout(function () { fitWidth = window.innerWidth; fitMenu(); }, 120);
  });

  /* ---- keyboard focus inside the scrolling strips (phone menu, tabs, sort pills): the focused item is scrolled
     clear of the 22-28 px edge fade, so its whole focus ring is visible (Chrome leaves half-visible items in place) ---- */
  document.addEventListener('focusin', function (e) {
    var t = e.target, strip = t && t.closest ? t.closest('.e-menu, .e-tabs, .e-sorts') : null;
    if (!strip || strip.scrollWidth <= strip.clientWidth + 1) return;
    try { if (!t.matches(':focus-visible')) return; } catch (err) {}
    var s = strip.getBoundingClientRect(), r = t.getBoundingClientRect(), pad = 32, dx = 0;
    if (r.right > s.right - pad) dx = r.right - (s.right - pad);
    else if (r.left < s.left + pad) dx = r.left - (s.left + pad);
    if (dx) strip.scrollLeft += dx;
  });

  /* ---- captcha (contract 2.9): the image is requested only when the visitor reaches the field (focus on its
     input, or a click / Enter on the box), never on page view; until then the box shows a refresh icon (CSS) ---- */
  function loadVerify(img, fresh) {
    var src = img && img.getAttribute('data-src');
    if (!src || (!fresh && img.hasAttribute('data-loaded'))) return;
    img.setAttribute('data-loaded', '');
    img.src = src + (src.indexOf('?') < 0 ? '?' : '&') + 'r=' + Date.now();
  }
  document.addEventListener('focusin', function (e) {
    var t = e.target, field = t && t.tagName === 'INPUT' && t.closest ? t.closest('.verification-field') : null;
    if (field) loadVerify(field.querySelector('img.e-verify'), false);
  });

  /* ---- events ---- */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var code = t.closest('img.e-verify');
    if (code) {
      /* home.js already reloads .mac_verify_img on click */
      if (code.classList.contains('mac_verify_img')) code.setAttribute('data-loaded', ''); else loadVerify(code, true);
      return;
    }
    var toggle = t.closest('[data-e-drawer]');
    if (toggle) { e.preventDefault(); setDrawer(!drawerOpen()); return; }
    if (drawerOpen() && !t.closest('#e-drawer')) setDrawer(false);
    var lang = t.closest('details.e-lang, details.e-more-menu');
    closeDetails(lang);
    var tab = t.closest('[data-e-tabs] [role="tab"]');
    if (tab && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
      if (tab.getAttribute('aria-selected') !== 'true') { e.preventDefault(); selectTab(tab, false); }
      else e.preventDefault();
      return;
    }
    var bar = t.closest('[data-e-expand]');
    if (bar) {
      var target = document.getElementById(bar.getAttribute('aria-controls'));
      var open = bar.getAttribute('aria-expanded') !== 'true';
      bar.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (target) target.classList.toggle('is-open', open);
      return;
    }
    var copy = t.closest('[data-e-copy]');
    if (copy) { e.preventDefault(); copyText(copy.getAttribute('data-e-copy') || location.href); return; }
    var copyTarget = t.closest('[data-copy-target]');
    if (copyTarget) {
      var input = document.getElementById(copyTarget.getAttribute('data-copy-target'));
      if (input) { e.preventDefault(); copyText(input.value || input.textContent || ''); }
      return;
    }
    var fav = t.closest('[data-e-fav]');
    if (fav) { e.preventDefault(); favorite(fav); return; }
    var reveal = t.closest('[data-e-reveal]');
    if (reveal) {
      var pwd = document.getElementById(reveal.getAttribute('data-e-reveal'));
      if (pwd) {
        var shown = pwd.type === 'password';
        pwd.type = shown ? 'text' : 'password';
        reveal.setAttribute('aria-pressed', shown ? 'true' : 'false');
        var srl = reveal.querySelector('.sr-only');
        if (srl) srl.textContent = shown ? (reveal.getAttribute('data-hide') || srl.textContent) : (reveal.getAttribute('data-show') || srl.textContent);
      }
      return;
    }
    var top = t.closest('[data-e-top]');
    if (top) { e.preventDefault(); window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (drawerOpen()) { setDrawer(false); var b = document.querySelector('.e-burger'); if (b && b.offsetParent) b.focus(); }
      var open = document.querySelector('details.e-lang[open], details.e-more-menu[open]');
      if (open) { open.open = false; var s = open.querySelector('summary'); if (s) s.focus(); }
      return;
    }
    var tab = e.target && e.target.closest ? e.target.closest('[data-e-tabs] [role="tab"]') : null;
    if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
    var tabs = Array.prototype.slice.call(tab.closest('[data-e-tabs]').querySelectorAll('[role="tab"]'));
    var step = (e.key === 'ArrowRight') === (document.dir !== 'rtl' && root.dir !== 'rtl') ? 1 : -1;
    var next = tabs[(tabs.indexOf(tab) + step + tabs.length) % tabs.length];
    e.preventDefault();
    selectTab(next, true);
  });
  window.addEventListener('resize', function () { if (drawerOpen() && window.innerWidth > 767 && !document.querySelector('.e-burger[aria-expanded="true"]')) setDrawer(false); });

  /* RTL pages: an English (untranslated) title inside a 2-line clamp gets dir="ltr", so its "..." ends the
     sentence instead of eating its first letters. Titles with any RTL letter keep the page direction. */
  function markLtrTitles() {
    if ((root.getAttribute('dir') || document.body.getAttribute('dir')) !== 'rtl') return;
    var rtl = /[\u0590-\u08ff\ufb1d-\ufdff\ufe70-\ufeff]/;
    Array.prototype.forEach.call(document.querySelectorAll('.e-card h3'), function (el) {
      var t = el.textContent;
      if (/[A-Za-z]/.test(t) && !rtl.test(t)) el.setAttribute('dir', 'ltr');
    });
  }

  function ready() {
    fitWidth = window.innerWidth;
    fitMenu();
    initImages();
    initExpand();
    initAvatar();
    initPointer();
    markLtrTitles();
    /* password reveal buttons are hidden until JS can run them */
    Array.prototype.forEach.call(document.querySelectorAll('[data-e-reveal]'), function (b) { b.hidden = false; });
    var entry = document.querySelector('.gallery-history-entry');
    if (entry && window.MAC && window.MAC.History && typeof window.MAC.History.Set === 'function') {
      try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (err) {}
    }
    var current = document.querySelector('.e-lang-list a[hreflang="' + (root.getAttribute('lang') || 'en') + '"]');
    if (current) current.setAttribute('aria-current', 'true');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})(window, document);
