/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* Neon theme behaviour (vanilla, no dependencies). Replaces the obfuscated runtime of the source pack:
   image fallbacks, account state from the login cookies, language menu, featured slider, category "shuffle",
   search history, share/report/copy, go-to-top, card spotlight and entrance effects. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var cfg = window.NeonConfig || {};
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var placeholder = cfg.placeholder || '';
  var T = function (key, fallback) { var m = window.TemplateMessages || {}; return m[key] || (window.NeonText || {})[key] || fallback || key; };
  var toast = function (text, kind) {
    if (window.TemplateUI && typeof window.TemplateUI.message === 'function') { window.TemplateUI.message(text, { kind: kind || 'info' }); }
  };
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
    del: function (k) { try { window.localStorage.removeItem(k); } catch (e) { /* storage blocked */ } }
  };
  var cookie = function (name) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    if (!m) { return ''; }
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  };
  root.classList.add('nx-js');

  /* 1. thumbnails: skeleton until loaded, neutral placeholder on error (also for late AJAX images) */
  function markReady(img) { var box = img.closest && img.closest('.nx-thumb'); if (box) { box.classList.add('is-ready'); } }
  document.addEventListener('load', function (e) { var t = e.target; if (t && t.tagName === 'IMG') { markReady(t); } }, true);
  /* the fallback swap itself is registered inline in public/include.html (before any image can fail) */
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.getAttribute('data-nx-failed')) { markReady(t); } }, true);
  function scanImages(scope) {
    var list = (scope || document).querySelectorAll('.nx-thumb img');
    for (var i = 0; i < list.length; i++) { if (list[i].complete && list[i].naturalWidth > 0) { markReady(list[i]); } }
  }

  /* 2. account state (pages are cached for everyone, so this is resolved on the client) */
  function account() {
    var uid = cookie('user_id');
    if (!uid) { return; }
    var name = cookie('user_name');
    var links = document.querySelectorAll('[data-nx-auth-href]');
    for (var i = 0; i < links.length; i++) { links[i].setAttribute('href', links[i].getAttribute('data-nx-auth-href')); }
    var names = document.querySelectorAll('[data-nx-account-name]');
    for (var j = 0; j < names.length && name; j++) { names[j].textContent = name; }
    root.classList.add('nx-logged');
  }

  /* 3. menus: close details menus on outside click / Escape, mark the current language */
  function menus() {
    var current = (root.getAttribute('lang') || '').toLowerCase();
    var langLinks = document.querySelectorAll('.nx-lang-menu a[hreflang]');
    for (var i = 0; i < langLinks.length; i++) {
      if ((langLinks[i].getAttribute('hreflang') || '').toLowerCase() === current) { langLinks[i].setAttribute('aria-current', 'true'); }
    }
    document.addEventListener('click', function (e) {
      var open = document.querySelectorAll('details.nx-lang[open], details.nx-catmore[open]');
      for (var k = 0; k < open.length; k++) { if (!open[k].contains(e.target)) { open[k].removeAttribute('open'); } }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      var open = document.querySelectorAll('details.nx-lang[open], details.nx-catmore[open]');
      for (var k = 0; k < open.length; k++) { open[k].removeAttribute('open'); var s = open[k].querySelector('summary'); if (s) { s.focus(); } }
    });
  }

  /* 4. featured slider (scroll-snap track + dots + arrows + gentle autoplay) */
  function slider(track) {
    var slides = track.children;
    if (!slides.length) { return; }
    var box = track.parentNode;
    var dotsBox = box.querySelector('[data-nx-dots]');
    var rtl = window.getComputedStyle(track).direction === 'rtl';
    var dots = [];
    var step = function () { var s = slides[0]; var gap = parseFloat(window.getComputedStyle(track).columnGap) || 0; return s.getBoundingClientRect().width + gap; };
    var perView = function () { return Math.max(1, Math.round(track.clientWidth / step())); };
    var pages = function () { return Math.max(1, slides.length - perView() + 1); };
    var index = function () { return Math.min(pages() - 1, Math.round(Math.abs(track.scrollLeft) / step())); };
    var go = function (i) {
      var n = pages(); i = (i + n) % n;
      track.scrollTo({ left: (rtl ? -1 : 1) * i * step(), behavior: reduceMotion ? 'auto' : 'smooth' });
    };
    var paint = function () {
      var i = index();
      for (var d = 0; d < dots.length; d++) { if (d === i) { dots[d].setAttribute('aria-current', 'true'); } else { dots[d].removeAttribute('aria-current'); } }
      for (var s = 0; s < slides.length; s++) { slides[s].classList.toggle('is-active', s >= i && s < i + perView()); }
    };
    var buildDots = function () {
      if (!dotsBox) { return; }
      dotsBox.textContent = '';
      dots = [];
      var n = pages();
      if (n < 2) { return; }
      /* phones swipe the track: the dots are a position indicator there (no 7 px tap targets) */
      var passive = !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
      if (passive) { dotsBox.setAttribute('aria-hidden', 'true'); } else { dotsBox.removeAttribute('aria-hidden'); }
      for (var d = 0; d < n; d++) {
        var b = document.createElement('button');
        b.type = 'button';
        if (passive) { b.tabIndex = -1; }
        b.setAttribute('aria-label', String(d + 1) + ' / ' + n);
        b.addEventListener('click', (function (k) { return function () { go(k); restart(); }; })(d));
        dotsBox.appendChild(b);
        dots.push(b);
      }
    };
    var timer = 0;
    var stop = function () { window.clearInterval(timer); timer = 0; };
    var restart = function () {
      stop();
      if (reduceMotion || pages() < 2) { return; }
      timer = window.setInterval(function () { if (!document.hidden) { go(index() + 1); } }, 5200);
    };
    var prev = box.querySelector('[data-nx-prev]');
    var next = box.querySelector('[data-nx-next]');
    if (prev) { prev.addEventListener('click', function () { go(index() - 1); restart(); }); }
    if (next) { next.addEventListener('click', function () { go(index() + 1); restart(); }); }
    var ticking = false;
    track.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(function () { ticking = false; paint(); }); } }, { passive: true });
    box.addEventListener('mouseenter', stop);
    box.addEventListener('mouseleave', restart);
    box.addEventListener('focusin', stop);
    box.addEventListener('focusout', restart);
    var resizeTimer = 0;
    window.addEventListener('resize', function () { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(function () { buildDots(); paint(); }, 150); });
    buildDots(); paint(); restart();
  }

  /* 5. "Shuffle" (original change-batch): load another slice of the category list and swap the cards */
  function shuffle(btn) {
    var target = document.getElementById(btn.getAttribute('aria-controls') || '');
    var url = btn.getAttribute('data-url') || '';
    if (!target || !url || btn.classList.contains('is-busy') || !window.fetch || !window.DOMParser) { return; }
    var per = target.children.length || 10;
    var batch = (parseInt(btn.getAttribute('data-batch') || '1', 10) || 1) + 1;
    if (batch > 10) { batch = 1; }
    var perPage = 20;
    var page = Math.floor(((batch - 1) * per) / perPage) + 1;
    var offset = ((batch - 1) * per) % perPage;
    btn.classList.add('is-busy');
    target.classList.add('is-loading');
    window.fetch(url.replace('PAGELINK', String(page)), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); })
      .then(function (html) {
        var doc = new window.DOMParser().parseFromString(html, 'text/html');
        var list = doc.querySelector('[data-nx-list]');
        var cards = list ? list.querySelectorAll('.nx-card') : [];
        var picked = Array.prototype.slice.call(cards, offset, offset + per);
        if (!picked.length) { throw new Error('empty'); }
        target.textContent = '';
        for (var i = 0; i < picked.length; i++) {
          var card = document.importNode(picked[i], true);
          card.style.setProperty('--i', String(i));
          target.appendChild(card);
        }
        target.classList.remove('nx-enter'); void target.offsetWidth; target.classList.add('nx-enter');
        btn.setAttribute('data-batch', String(batch));
        if (window.TemplateGridFill) { window.TemplateGridFill.run(); }
      })
      .catch(function (err) {
        if (err && err.message === 'empty') { btn.setAttribute('data-batch', '0'); return; }
        toast(T('ui.connection_failed_try_again', 'Connection failed, try again'), 'error');
      })
      .then(function () { btn.classList.remove('is-busy'); target.classList.remove('is-loading'); });
  }

  /* 6. search history (per-viewer convenience, localStorage only) */
  var HKEY = 'neon.searchHistory';
  function historyList() { try { var v = JSON.parse(store.get(HKEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function remember(q) {
    q = String(q || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    if (!q) { return; }
    var list = historyList().filter(function (x) { return x !== q; });
    list.unshift(q);
    store.set(HKEY, JSON.stringify(list.slice(0, 12)));
  }
  function renderHistory() {
    var boxes = document.querySelectorAll('[data-nx-history]');
    var list = historyList();
    for (var i = 0; i < boxes.length; i++) {
      var box = boxes[i];
      var ul = box.querySelector('ul');
      var base = box.getAttribute('data-search-url') || '';
      if (!ul) { continue; }
      ul.textContent = '';
      var ico = box.querySelector('template[data-nx-history-ico]');
      for (var j = 0; j < list.length; j++) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        var label = document.createElement('span');
        a.href = base + (base.indexOf('?') > -1 ? '&' : '?') + 'wd=' + encodeURIComponent(list[j]);
        if (ico && ico.content) { a.appendChild(document.importNode(ico.content, true)); }
        label.textContent = list[j];
        a.appendChild(label);
        li.appendChild(a);
        ul.appendChild(li);
      }
      box.hidden = list.length === 0;
    }
  }

  /* 7. share / copy / report / description toggle */
  function copyText(text) {
    var done = function () { toast(T('ui.link_copied', 'Link copied'), 'success'); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () { window.prompt(T('copy_manually', 'Copy this link'), text); }); return; }
    window.prompt(T('copy_manually', 'Copy this link'), text);
  }
  function actions() {
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-nx-share],[data-nx-report],[data-nx-shuffle],[data-nx-clear-history],[data-nx-more],[data-nx-top]') : null;
      if (!el) { return; }
      if (el.hasAttribute('data-nx-share')) {
        e.preventDefault();
        var url = el.getAttribute('data-url') || window.location.href;
        var title = el.getAttribute('data-title') || document.title;
        var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        if (navigator.share && coarse) { navigator.share({ title: title, url: url }).catch(function () { /* dismissed */ }); } else { copyText(url); }
      } else if (el.hasAttribute('data-nx-report')) {
        e.preventDefault();
        var id = el.getAttribute('data-id') || '';
        var name = (el.getAttribute('data-name') || '') + ' - ' + window.location.href;
        if (window.MAC && window.MAC.Gbook && typeof window.MAC.Gbook.Report === 'function') { window.MAC.Gbook.Report(name, id); }
        else if (el.getAttribute('href')) { window.location.href = el.getAttribute('href'); }
      } else if (el.hasAttribute('data-nx-shuffle')) {
        e.preventDefault();
        shuffle(el);
      } else if (el.hasAttribute('data-nx-clear-history')) {
        store.del(HKEY);
        renderHistory();
      } else if (el.hasAttribute('data-nx-more')) {
        var box = document.getElementById(el.getAttribute('aria-controls') || '');
        if (box) { var open = box.classList.toggle('is-clamped') === false; el.setAttribute('aria-expanded', open ? 'true' : 'false'); el.textContent = open ? el.getAttribute('data-less') : el.getAttribute('data-more'); }
      } else if (el.hasAttribute('data-nx-top')) {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
      }
    });
    var forms = document.querySelectorAll('form[data-nx-search]');
    for (var i = 0; i < forms.length; i++) {
      forms[i].addEventListener('submit', function (ev) {
        var input = this.querySelector('input[name="wd"]');
        var q = input ? input.value.trim() : '';
        if (!q) { ev.preventDefault(); if (input) { input.focus(); } return; }
        remember(q);
      });
    }
    var desc = document.querySelectorAll('[data-nx-clamp]');
    for (var d = 0; d < desc.length; d++) {
      var body = desc[d];
      var btn = document.querySelector('[data-nx-more][aria-controls="' + body.id + '"]');
      if (btn && body.scrollHeight > body.clientHeight + 24) { body.classList.add('is-clamped'); btn.hidden = false; btn.textContent = btn.getAttribute('data-more'); }
      else if (btn) { body.classList.remove('is-clamped'); btn.hidden = true; }
    }
  }

  /* 8. header shadow + go-to-top */
  function scrollers() {
    var header = document.querySelector('.nx-header');
    var top = document.querySelector('.nx-gotop');
    var ticking = false;
    var update = function () {
      ticking = false;
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      if (header) { header.classList.toggle('is-scrolled', y > 8); }
      if (top) { top.classList.toggle('is-on', y > 640); }
    };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }, { passive: true });
    update();
    /* keyboard focus inside a horizontal row (filters, category bar, label tabs, sources, user menu strip): show the whole item and its ring */
    document.addEventListener('focusin', function (e) {
      var t = e.target;
      var row = t && t.closest ? t.closest('.nx-frow, .nx-catbar-in, .nx-tabs, .nx-sources, .nx-umenu.is-strip') : null;
      if (!row || row.scrollWidth <= row.clientWidth + 1 || !row.scrollBy) { return; }
      var a = row.getBoundingClientRect();
      var r = t.getBoundingClientRect();
      var pad = 12;
      var dx = r.left < a.left + pad ? r.left - a.left - pad : (r.right > a.right - pad ? r.right - a.right + pad : 0);
      if (dx) { row.scrollBy({ left: dx, behavior: 'auto' }); }
    });
  }

  /* 9. card spotlight (desktop pointer only) + entrance stagger for the first grid in view */
  function effects() {
    if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      var raf = 0, last = null;
      document.addEventListener('pointermove', function (e) {
        last = e;
        if (raf) { return; }
        raf = window.requestAnimationFrame(function () {
          raf = 0;
          var t = last.target && last.target.closest ? last.target.closest('.nx-thumb') : null;
          if (!t) { return; }
          var r = t.getBoundingClientRect();
          t.style.setProperty('--mx', (last.clientX - r.left) + 'px');
          t.style.setProperty('--my', (last.clientY - r.top) + 'px');
        });
      }, { passive: true });
    }
    if (reduceMotion) { return; }
    var grids = document.querySelectorAll('.nx-grid');
    var vh = window.innerHeight || 800;
    for (var g = 0; g < grids.length; g++) {
      var r = grids[g].getBoundingClientRect();
      if (r.top > vh) { continue; }
      var cards = grids[g].children;
      for (var c = 0; c < cards.length && c < 12; c++) { cards[c].style.setProperty('--i', String(c)); }
      grids[g].classList.add('nx-enter');
    }
  }

  /* 10. small helpers: back links, favourites link (MAC.Ulog handles the click), active pill in view */
  function helpers() {
    document.addEventListener('click', function (e) {
      if (!e.target.closest) { return; }
      var fav = e.target.closest('a.mac_ulog');
      if (fav) { e.preventDefault(); return; }
      var back = e.target.closest('[data-nx-back]');
      if (!back) { return; }
      try {
        if (document.referrer && new URL(document.referrer).origin === window.location.origin && window.history.length > 1) { e.preventDefault(); window.history.back(); }
      } catch (err) { /* follow the link */ }
    }, true);
    var rtl = (root.getAttribute('dir') || '').toLowerCase() === 'rtl';
    var wheel = function (e) {
      var el = e.currentTarget;
      if (e.ctrlKey || el.scrollWidth <= el.clientWidth + 2 || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) { return; }
      var before = el.scrollLeft;
      el.scrollLeft += (rtl ? -1 : 1) * e.deltaY;
      if (el.scrollLeft !== before) { e.preventDefault(); }
    };
    var rows = document.querySelectorAll('.nx-tabs, .nx-frow, .nx-catbar-in, .nx-sources, .nx-umenu.is-strip');
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      row.addEventListener('wheel', wheel, { passive: false });
      var on = row.querySelector('[aria-current="page"], .is-on');
      if (!on || row.scrollWidth <= row.clientWidth + 2) { continue; }
      var rr = row.getBoundingClientRect(), ir = on.getBoundingClientRect();
      row.scrollLeft += (ir.left + ir.width / 2) - (rr.left + rr.width / 2);
    }
  }

  /* 10b. category bar: links that do not fit move into a "More" menu (one clean row in every language) */
  function catbarMore() {
    var row = document.querySelector('.nx-catbar-in');
    var more = row ? row.querySelector('.nx-catmore') : null;
    if (!row || !more) { return; }
    var menu = more.querySelector('.nx-catmore-menu');
    var items = [];
    for (var c = 0; c < row.children.length; c++) { if (row.children[c] !== more) { items.push(row.children[c]); } }
    var rtl = (root.getAttribute('dir') || '').toLowerCase() === 'rtl';
    var layout = function () {
      more.removeAttribute('open');
      more.hidden = true;
      more.classList.remove('is-current');
      menu.textContent = '';
      row.classList.remove('is-fit');
      for (var i = 0; i < items.length; i++) { items[i].hidden = false; }
      if (!row.offsetParent || window.getComputedStyle(row.parentNode).display === 'none') { return; }
      if (row.scrollWidth <= row.clientWidth + 1) { row.classList.add('is-fit'); return; }
      more.hidden = false;
      var box = row.getBoundingClientRect();
      var pad = parseFloat(window.getComputedStyle(row).paddingInlineEnd || window.getComputedStyle(row).paddingRight) || 0;
      var limit = more.getBoundingClientRect().width + pad + 4;
      var cut = -1;
      for (var j = 0; j < items.length; j++) {
        var r = items[j].getBoundingClientRect();
        if (rtl ? (r.left < box.left + limit) : (r.right > box.right - limit)) { cut = j; break; }
      }
      if (cut < 1) { more.hidden = true; return; }
      for (var k = cut; k < items.length; k++) {
        var el = items[k];
        el.hidden = true;
        if (el.tagName !== 'A') { continue; }
        var copy = el.cloneNode(true);
        copy.hidden = false;
        copy.removeAttribute('hidden');
        menu.appendChild(copy);
        if (el.getAttribute('aria-current') === 'page') { more.classList.add('is-current'); }
      }
      if (items[cut - 1] && items[cut - 1].tagName !== 'A') { items[cut - 1].hidden = true; }
      row.classList.add('is-fit');
    };
    var timer = 0;
    layout();
    window.addEventListener('resize', function () { window.clearTimeout(timer); timer = window.setTimeout(layout, 120); });
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(layout); }
  }

  /* 10c. category bar: one pink neon bar glides to the hovered / focused link and back to the current page
     (fine pointer only; layout is read on hover, never on scroll; without JS the per-link underline stays) */
  function catbarGlide() {
    var row = document.querySelector('.nx-catbar-in');
    if (!row || reduceMotion || !window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) { return; }
    var bar = document.createElement('span');
    bar.className = 'nx-glide';
    bar.setAttribute('aria-hidden', 'true');
    row.appendChild(bar);
    row.classList.add('has-glide');
    var place = function (el) {
      if (!el || el.hidden || !el.offsetWidth) { bar.classList.remove('is-on'); return; }
      var w = Math.round(el.offsetWidth * 0.6);
      bar.style.width = w + 'px';
      bar.style.transform = 'translateX(' + Math.round(el.offsetLeft + (el.offsetWidth - w) / 2) + 'px)';
      bar.classList.add('is-on');
    };
    var home = function () {
      var links = row.children, cur = null;
      for (var i = 0; i < links.length; i++) { if (links[i].tagName === 'A' && !links[i].hidden && links[i].getAttribute('aria-current') === 'page') { cur = links[i]; break; } }
      place(cur);
    };
    var pick = function (e) { var a = e.target && e.target.closest ? e.target.closest('a') : null; if (a && a.parentNode === row) { place(a); } };
    row.addEventListener('pointerover', pick);
    row.addEventListener('focusin', pick);
    row.addEventListener('pointerleave', home);
    row.addEventListener('focusout', home);
    home();
    window.requestAnimationFrame(function () { bar.classList.add('is-ready'); });
    var timer = 0;
    window.addEventListener('resize', function () { window.clearTimeout(timer); timer = window.setTimeout(home, 160); });
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(home); }
  }

  /* 11. admin logo tone: a dark single-colour logo with a transparent background turns white on the pink header
     (the result is cached per logo URL; the inline head script applies the cached class before first paint) */
  function lin(v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function logoTone() {
    var KEY = 'neon.logoMono';
    var img = document.querySelector('.nx-header .nx-brand img[data-nx-logo]');
    if (!img) { root.classList.remove('nx-logo-mono'); store.del(KEY); return; }
    function measure() {
      if (img.getAttribute('data-nx-failed')) { root.classList.remove('nx-logo-mono'); store.del(KEY); return; }
      var src = img.currentSrc || img.src, mono = false;
      if (store.get(KEY) === src) { root.classList.add('nx-logo-mono'); return; }
      try {
        var c = document.createElement('canvas'); c.width = 96; c.height = 32;
        var g = c.getContext('2d'); g.drawImage(img, 0, 0, 96, 32);
        var d = g.getImageData(0, 0, 96, 32).data, sum = 0, n = 0, clear = 0;
        for (var i = 0; i < d.length; i += 4) {
          if (d[i + 3] < 128) { clear++; continue; }
          sum += 0.2126 * lin(d[i]) + 0.7152 * lin(d[i + 1]) + 0.0722 * lin(d[i + 2]); n++;
        }
        mono = n > 40 && clear > (d.length / 4) * 0.15 && sum / n < 0.2;
      } catch (e) { mono = false; /* cross-origin logo: keep it as it is */ }
      root.classList.toggle('nx-logo-mono', mono);
      if (mono) { store.set(KEY, src); } else { store.del(KEY); }
    }
    if (img.complete && img.naturalWidth) { measure(); } else { img.addEventListener('load', measure); img.addEventListener('error', measure); }
  }

  /* 12. phones: the header search pill is narrow, so it shows the short placeholder ("Search") */
  function searchHint() {
    var input = document.querySelector('.nx-header input[data-nx-ph-short]');
    if (!input || !window.matchMedia) { return; }
    var long = input.getAttribute('placeholder'), mq = window.matchMedia('(max-width: 479px)');
    var apply = function () { input.setAttribute('placeholder', mq.matches ? input.getAttribute('data-nx-ph-short') : long); };
    apply();
    if (mq.addEventListener) { mq.addEventListener('change', apply); } else if (mq.addListener) { mq.addListener(apply); }
  }

  function boot() {
    logoTone();
    searchHint();
    catbarMore();
    catbarGlide();
    helpers();
    account();
    menus();
    scanImages();
    var tracks = document.querySelectorAll('[data-nx-slider]');
    for (var i = 0; i < tracks.length; i++) { slider(tracks[i]); }
    renderHistory();
    actions();
    scrollers();
    effects();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
