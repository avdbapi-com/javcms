/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS shared behaviour (identical in javcms and javcms-modern). Vanilla JS, no dependencies.
   Thumbnail load state + fallback, card spotlight, cover tilt (CSS 3D, replaces the three.js tilt),
   dropdowns that close on outside click / Escape, share panel, mobile search toggle, translated player title,
   captcha image on demand (comment + guestbook forms). */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  var fine = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : { matches: false };
  var tplPath = (window.maccms && window.maccms.templatePath) || '';
  var placeholder = tplPath + 'ksassets/image/poster-placeholder.svg';

  /* ---- thumbnails: skeleton until loaded, theme placeholder on error ---- */
  function frameOf(img) { return img.closest ? img.closest('.jc-thumb,.jc-cover') : null; }
  function loaded(img) { var f = frameOf(img); if (f) f.classList.add('is-loaded'); }
  function fail(img) {
    if (img.getAttribute('data-jc-fallback')) { loaded(img); return; }
    img.setAttribute('data-jc-fallback', '1');
    if (img.classList.contains('jc-cover-bg')) { img.style.visibility = 'hidden'; loaded(img); return; }
    img.src = placeholder;
  }
  document.addEventListener('load', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && frameOf(t)) loaded(t); }, true);
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && frameOf(t)) fail(t); }, true);
  function scanImages(scope) {
    var list = (scope || document).querySelectorAll('.jc-thumb>img,.jc-cover img');
    for (var i = 0; i < list.length; i++) {
      var img = list[i], src = img.getAttribute('src') || '';
      if (!src || src === '/' || /\/$/.test(src)) { fail(img); continue; }
      if (img.complete) { if (img.naturalWidth) loaded(img); else if (img.getAttribute('loading') !== 'lazy') fail(img); }
    }
  }

  /* ---- pointer effects (desktop only, never with reduced motion) ---- */
  var raf = 0, pending = null;
  function spot(e) {
    if (reduce.matches || !fine.matches) return;
    var link = e.target && e.target.closest ? e.target.closest('.jc-card-link,.jc-cover') : null;
    if (!link) return;
    pending = { el: link, x: e.clientX, y: e.clientY };
    if (!raf) raf = window.requestAnimationFrame(applySpot);
  }
  function applySpot() {
    raf = 0; if (!pending) return;
    var el = pending.el, frame = el.classList.contains('jc-cover') ? el : el.querySelector('.jc-thumb');
    if (!frame) return;
    var r = frame.getBoundingClientRect(); if (!r.width || !r.height) return;
    var x = Math.max(0, Math.min(r.width, pending.x - r.left)), y = Math.max(0, Math.min(r.height, pending.y - r.top));
    frame.style.setProperty('--jc-x', x + 'px'); frame.style.setProperty('--jc-y', y + 'px');
    if (frame.hasAttribute('data-jc-tilt')) {
      var rx = ((y / r.height) - 0.5) * -9, ry = ((x / r.width) - 0.5) * 11;
      frame.style.transform = 'perspective(900px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg) scale(1.015)';
    }
  }
  document.addEventListener('pointermove', spot, { passive: true });
  document.addEventListener('pointerout', function (e) {
    var cover = e.target && e.target.closest ? e.target.closest('[data-jc-tilt]') : null;
    if (cover && !cover.contains(e.relatedTarget)) cover.style.transform = '';
  }, { passive: true });

  /* ---- dropdowns / disclosures: one open at a time, close outside or on Escape ---- */
  var DROPS = 'details.jc-drop,details.language-menu,details.gallery-history,details.gallery-qr';
  function closeDrops(except) {
    var open = document.querySelectorAll(DROPS.replace(/,/g, '[open],') + '[open]');
    for (var i = 0; i < open.length; i++) if (open[i] !== except && !open[i].contains(except)) open[i].removeAttribute('open');
  }
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (d && d.matches && d.matches(DROPS) && d.open) closeDrops(d);
  }, true);
  document.addEventListener('click', function (e) {
    var inside = e.target && e.target.closest ? e.target.closest(DROPS) : null;
    closeDrops(inside);
  });
  /* on touch screens a tap on the page that only closes an open dropdown is swallowed (never opens a video by accident) */
  var touchLike = window.matchMedia ? window.matchMedia('(hover: none), (max-width: 767px)') : { matches: false };
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!touchLike.matches || !e.detail || !t || !t.closest || t.closest(DROPS)) return;
    if (!document.querySelector(DROPS.replace(/,/g, '[open],') + '[open]')) return;
    if (t.closest('.header,.gallery-header,#gallery-navigation,.tui-dialog,.mac_pop')) return;
    e.preventDefault(); e.stopPropagation();
    closeDrops(null);
  }, true);

  /* ---- classic header search (opened by classic.js): a tap or click outside closes it through the same button, so
     aria-expanded stays in sync; a tap on the page under it is swallowed on touch screens ---- */
  document.addEventListener('click', function (e) {
    var h = document.querySelector('.header.search-active'), t = e.target;
    if (!h || !t || !t.closest || t.closest('.header .search,.header .btn-search')) return;
    if (touchLike.matches && e.detail && !t.closest('.header')) { e.preventDefault(); e.stopPropagation(); }
    var a = document.activeElement, b = h.querySelector('.btn-search');
    if (a && a !== document.body && h.contains(a) && a.blur) a.blur();
    if (b) b.click(); else h.classList.remove('search-active');
  }, true);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector(DROPS.replace(/,/g, '[open],') + '[open]');
    if (open) { open.removeAttribute('open'); var s = open.querySelector('summary'); if (s) s.focus(); }
    var header = document.querySelector('.is-search-open');
    if (header) {
      header.classList.remove('is-search-open');
      var st = header.querySelector('[data-jc-search-toggle]');
      if (st) { st.setAttribute('aria-expanded', 'false'); if (st.offsetParent !== null) st.focus(); }
    }
  });

  /* ---- captcha on demand: the comment and guestbook forms carry input.mac_verify, so no captcha image is requested
     on page load. MAC.Verify adds the image when the code field gets focus; here it is added as soon as the visitor
     starts using the form (first focus anywhere inside it), so the code is ready when they reach the field. ---- */
  document.addEventListener('focusin', function (e) {
    var t = e.target, form = t && t.closest ? t.closest('.comment_form,.gbook_form') : null;
    if (!form) return;
    var input = form.querySelector('input.mac_verify');
    var mac = window.MAC;
    if (!input || !mac || !mac.Verify || typeof mac.Verify.Show !== 'function') return;
    input.classList.remove('mac_verify');
    var next = input.nextElementSibling;
    if (!(next && next.matches && next.matches('img.mac_verify_img'))) input.insertAdjacentHTML('afterend', mac.Verify.Show());
  });

  /* ---- captcha on demand, account pages (login, register, password recovery) and the login modal (sweep item 5): the code
     image starts as a local placeholder and carries data-verify-src. The real image is requested on the first focus inside
     its form (so it is ready when the visitor reaches the field) or on a click on it; later clicks refresh it, which is also
     what the pages' own "$('#verify_img').click()" after a failed attempt does. ---- */
  function jcVerify(img, fresh) {
    var base = img.getAttribute('data-verify-src');
    if (!base) return;
    if (!img.hasAttribute('data-verify-loaded')) { img.setAttribute('data-verify-loaded', '1'); img.src = base; return; }
    if (fresh) img.src = base + (base.indexOf('?') < 0 ? '?' : '&') + 'r=' + Date.now();
  }
  document.addEventListener('focusin', function (e) {
    var form = e.target && e.target.closest ? e.target.closest('form') : null;
    var img = form ? form.querySelector('img[data-verify-src]:not([data-verify-loaded])') : null;
    if (img) jcVerify(img, false);
  });
  document.addEventListener('click', function (e) {
    var img = e.target && e.target.closest ? e.target.closest('img[data-verify-src]') : null;
    if (!img) return;
    /* the modal's img.mac_verify_img already got a new src from MAC.Verify's own click handler */
    if (img.classList.contains('mac_verify_img')) { img.setAttribute('data-verify-loaded', '1'); return; }
    jcVerify(img, true);
  });

  /* ---- keyboard: a category chip that gets keyboard focus while its scrolling strip cuts it scrolls fully into view, focus
     ring included (the strip has a 6px scroll-padding). Browsers leave a partly visible focused item where it is. ---- */
  document.addEventListener('focusin', function (e) {
    var a = e.target, s = a && a.closest ? a.closest('.list-link') : null;
    if (!s || !a.scrollIntoView || s.scrollWidth <= s.clientWidth + 1) return;
    try { if (!a.matches(':focus-visible')) return; } catch (err) { return; }
    var q = a.getBoundingClientRect(), r = s.getBoundingClientRect();
    if (q.left < r.left + 6 || q.right > r.right - 6) { try { a.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (err) { } }
  });

  /* ---- mobile menus: a tap outside the open panel closes it and is swallowed (never opens a card by accident) ---- */
  function openMenu() {
    var t = document.querySelector('.classic-menu-toggle[aria-expanded="true"]');
    if (t && window.innerWidth < 1300) return { toggle: t, panel: document.querySelector('.wrapped .sidebar') };
    t = document.querySelector('.menu-toggle[aria-expanded="true"]');
    var nav = document.getElementById('gallery-navigation');
    if (t && nav && nav.classList.contains('is-open') && t.offsetParent !== null) return { toggle: t, panel: nav };
    return null;
  }
  document.addEventListener('click', function (e) {
    var m = openMenu(), t = e.target;
    if (!m || !m.panel || !t || !t.closest) return;
    if (m.panel.contains(t) || m.toggle.contains(t) || t.closest('.header,.gallery-header,.tui-dialog,.mac_pop')) return;
    e.preventDefault(); e.stopPropagation();
    m.toggle.click();
  }, true);
  /* classic drawer: Escape closes it (static/template-ui/classic.js) and hides the focused link, which dropped focus to <body>;
     focus returns to the burger instead (capture phase: runs while the drawer is still open) */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var t = document.querySelector('.classic-menu-toggle[aria-expanded="true"]'), sb = document.querySelector('.wrapped .sidebar');
    if (t && sb && sb.contains(document.activeElement)) setTimeout(function () { t.focus(); }, 0);
  }, true);
  /* classic drawer below 1300px: dim the page with the original .shadow layer while it is open */
  function syncShadow() {
    var w = document.querySelector('.wrapped'), sh = document.querySelector('body>.shadow');
    if (!w || !sh) return;
    sh.classList.toggle('show', w.classList.contains('open') && window.innerWidth < 1300 && !!document.querySelector('.classic-menu-toggle'));
  }

  function init() {
    root.classList.add('jc-ready');
    var wrapped = document.querySelector('.wrapped');
    if (wrapped && window.MutationObserver && document.querySelector('.classic-menu-toggle')) {
      new MutationObserver(syncShadow).observe(wrapped, { attributes: true, attributeFilter: ['class'] });
      window.addEventListener('resize', syncShadow);
    }
    scanImages(document);

    /* share panel on watch/detail */
    var shareBtns = document.querySelectorAll('[data-jc-share]');
    for (var i = 0; i < shareBtns.length; i++) (function (btn) {
      var panel = document.getElementById(btn.getAttribute('aria-controls'));
      if (!panel) return;
      var input = panel.querySelector('input'); if (input && !input.value) input.value = window.location.href.split('#')[0];
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', String(open));
        panel.classList.toggle('is-open', open);
        if (open && input) { input.focus(); input.select(); }
      });
    })(shareBtns[i]);

    /* mobile search toggle */
    var searchBtns = document.querySelectorAll('[data-jc-search-toggle]');
    for (var j = 0; j < searchBtns.length; j++) (function (btn) {
      var host = btn.closest('header') || document.body;
      btn.addEventListener('click', function () {
        var open = !host.classList.contains('is-search-open');
        host.classList.toggle('is-search-open', open);
        btn.setAttribute('aria-expanded', String(open));
        if (open) { var q = host.querySelector('input[name="wd"]'); if (q) q.focus(); }
      });
    })(searchBtns[j]);

    /* password reveal (auto-icon eye / eye-off): one button per password field inside .jc-field, from the footer template */
    var pwTpl = document.getElementById('jc-pw-tpl');
    if (pwTpl && pwTpl.content) {
      var pws = document.querySelectorAll('.jc-field>input[type="password"]');
      for (var q = 0; q < pws.length; q++) (function (input) {
        var b = pwTpl.content.firstElementChild.cloneNode(true);
        input.parentNode.classList.add('has-pw');
        input.parentNode.appendChild(b);
        b.addEventListener('click', function () {
          var show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          b.setAttribute('aria-pressed', String(show));
          b.setAttribute('aria-label', b.getAttribute(show ? 'data-hide' : 'data-show'));
        });
      })(pws[q]);
    }

    /* back to top: shown after one screen of scrolling (one passive listener, rAF-throttled, class toggle only) */
    var topBtn = document.querySelector('[data-jc-top]');
    if (topBtn) {
      var topRaf = 0;
      var syncTop = function () { topRaf = 0; topBtn.classList.toggle('is-shown', (window.pageYOffset || 0) > window.innerHeight * 0.9); };
      window.addEventListener('scroll', function () { if (!topRaf) topRaf = window.requestAnimationFrame(syncTop); }, { passive: true });
      topBtn.addEventListener('click', function (e) {
        e.preventDefault();
        try { window.scrollTo({ top: 0, behavior: reduce.matches ? 'auto' : 'smooth' }); } catch (err) { window.scrollTo(0, 0); }
        var skip = document.getElementById('main-content');
        if (skip) { skip.setAttribute('tabindex', '-1'); skip.focus({ preventScroll: true }); }
      });
      syncTop();
    }

    /* translated title for the provider iframe (the adapter writes an English title) */
    var players = document.querySelectorAll('.jc-player[data-frame-title]');
    for (var k = 0; k < players.length; k++) (function (box) {
      var title = box.getAttribute('data-frame-title');
      function fix() { var f = box.querySelector('iframe'); if (f && f.getAttribute('title') !== title) f.setAttribute('title', title); }
      fix();
      if (window.MutationObserver) new MutationObserver(fix).observe(box, { childList: true, subtree: true });
    })(players[k]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window, document);
