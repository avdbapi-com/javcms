/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Onyx theme behaviour (vanilla JS, no dependencies). Rewritten from scratch for the MDblack look:
   drawers, priority nav, coverflow banner, tabs, copy link, search suggestions, member state, TOP button, card spotlight. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  root.classList.add('js');
  var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var canHover = !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var base = (window.maccms && window.maccms.path) || '';
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function txt(key, fallback) {
    var a = window.OnyxText || {}, b = window.TemplateMessages || {};
    return a[key] || b[key] || fallback || key;
  }
  function toast(message) { if (window.TemplateUI && window.TemplateUI.message) window.TemplateUI.message(message); }

  /* 1. Thumbnails: loaded state for the skeleton, and a neutral fallback for broken posters (also for late images). */
  var placeholder = base + '/static/template-ui/poster-placeholder.png';
  function frameOf(img) { return img.closest ? img.closest('.vcard-thumb,.banner-slide,.dt-cover') : null; }
  function loaded(img) { var f = frameOf(img); if (f) f.classList.add('is-loaded'); }
  function fallback(img) {
    if (img.getAttribute('data-fallback') === '1') { loaded(img); return; }
    if (!frameOf(img) && !img.hasAttribute('data-fallback-img')) return;
    img.setAttribute('data-fallback', '1');
    img.src = placeholder;
  }
  document.addEventListener('load', function (e) { var t = e.target; if (t && t.tagName === 'IMG') loaded(t); }, true);
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG') fallback(t); }, true);
  $$('.vcard-thumb img,.banner-slide img,.dt-cover img,img[data-fallback-img]').forEach(function (img) {
    if (!img.complete) return;
    if (img.naturalWidth > 0) loaded(img); else fallback(img);
  });

  /* 2. Dropdowns built on <details class="menu-pop">: one open at a time, close outside / on Escape. */
  $$('details.menu-pop').forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      $$('details.menu-pop[open]').forEach(function (o) { if (o !== d && !o.contains(d)) o.removeAttribute('open'); });
    });
  });
  document.addEventListener('click', function (e) {
    $$('details.menu-pop[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  /* 3. Slide-out drawers (menu on the start side, account on the end side), as in the original mobile layout. */
  var scrim = $('.scrim');
  function setExpanded(id, value) { $$('[aria-controls="' + id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', value); }); }
  function closeDrawers() {
    $$('.drawer.is-open').forEach(function (d) { d.classList.remove('is-open'); d.setAttribute('aria-hidden', 'true'); setExpanded(d.id, 'false'); });
    if (scrim) scrim.classList.remove('is-on');
    root.classList.remove('no-scroll');
  }
  function openDrawer(id) {
    var d = document.getElementById(id);
    if (!d) return;
    closeDrawers();
    d.classList.add('is-open');
    d.setAttribute('aria-hidden', 'false');
    setExpanded(id, 'true');
    if (scrim) scrim.classList.add('is-on');
    root.classList.add('no-scroll');
    var first = d.querySelector('button,a[href],input');
    if (first) window.setTimeout(function () { first.focus(); }, 60);
  }
  $$('[data-drawer]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('aria-controls'), d = document.getElementById(id);
      if (d && d.classList.contains('is-open')) closeDrawers(); else openDrawer(id);
    });
  });
  $$('[data-drawer-close]').forEach(function (b) { b.addEventListener('click', closeDrawers); });
  if (scrim) scrim.addEventListener('click', closeDrawers);

  /* 4. Mobile search row. */
  var header = $('.hd'), searchBtn = $('.hd-m-search');
  function closeSearch() { if (header) header.classList.remove('is-search-open'); if (searchBtn) searchBtn.setAttribute('aria-expanded', 'false'); }
  if (searchBtn && header) {
    searchBtn.addEventListener('click', function () {
      var on = !header.classList.contains('is-search-open');
      header.classList.toggle('is-search-open', on);
      searchBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) { var input = $('.hd-search input', header); if (input) input.focus(); }
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $$('details.menu-pop[open]').forEach(function (d) { d.removeAttribute('open'); });
    closeDrawers();
    closeSearch();
  });

  /* 5. Priority navigation: categories that do not fit the 60px bar move into "More". */
  var menu = $('.hd-menu'), more = $('.hd-more');
  function fitNav() {
    if (!menu || !more) return;
    var items = $$(':scope > li', menu), extra = $$('.menu-panel a', more);
    items.forEach(function (li) { li.hidden = false; });
    extra.forEach(function (a) { a.hidden = true; });
    if (!menu.offsetParent) return;
    more.hidden = false;
    var nav = menu.parentNode, mcs = window.getComputedStyle(more), avail = nav.clientWidth - more.offsetWidth - (parseFloat(mcs.marginInlineStart) || 0) - 6, used = 0, any = false;
    items.forEach(function (li, i) {
      var cs = window.getComputedStyle(li);
      used += li.offsetWidth + (parseFloat(cs.marginInlineStart) || 0);
      var hide = any || used > avail;
      if (hide) { li.hidden = true; any = true; if (extra[i]) extra[i].hidden = false; }
    });
    more.hidden = !any;
    if (!any) more.removeAttribute('open');
    more.classList.toggle('has-current', !!menu.querySelector(':scope > li[hidden] [aria-current]'));
  }
  var navTimer = 0;
  function queueFit() { window.clearTimeout(navTimer); navTimer = window.setTimeout(fitNav, 60); }
  fitNav();
  window.addEventListener('load', fitNav);
  window.addEventListener('resize', queueFit);

  /* 6. Member state from the login cookies (pages are cached, so this is done on the client). */
  function cookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].indexOf('=');
      if (parts[i].slice(0, p) === name) { try { return decodeURIComponent(parts[i].slice(p + 1).replace(/\+/g, ' ')); } catch (err) { return parts[i].slice(p + 1); } }
    }
    return '';
  }
  var userId = cookie('user_id');
  if (userId && userId !== '0' && userId !== 'deleted') {
    $$('.hd-guest').forEach(function (el) { el.hidden = true; });
    $$('.hd-member').forEach(function (el) { el.hidden = false; });
    var name = cookie('user_name');
    $$('[data-user-name]').forEach(function (el) { el.textContent = name || txt('ui.member_center', 'Member center'); });
    $$('.hd-m-user').forEach(function (el) { el.classList.add('is-member'); });
  }
  $$('[data-logout]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); if (window.MAC && window.MAC.User) window.MAC.User.Logout(); });
  });

  /* 7. Viewing history (same cookie as the MAC runtime). */
  var entry = $('[data-history-entry]');
  if (entry && window.MAC && window.MAC.History && window.MAC.History.Set) {
    try { window.MAC.History.Set(entry.getAttribute('data-name'), entry.getAttribute('data-link'), entry.getAttribute('data-pic')); } catch (err) {}
  }
  function renderHistory() {
    var box = $('[data-history-list]');
    if (!box) return;
    var rows = [];
    try { rows = (window.MAC && window.MAC.History) ? window.MAC.History.Read() : []; } catch (err) { rows = []; }
    box.textContent = '';
    if (!rows.length) {
      var p = document.createElement('p'); p.className = 'history-empty'; p.textContent = txt('history_empty', 'No browsing history'); box.appendChild(p);
      return;
    }
    rows.forEach(function (row) { var a = document.createElement('a'); a.href = row.link; a.textContent = row.name; box.appendChild(a); });
  }
  renderHistory();
  $$('[data-history-clear]').forEach(function (b) {
    b.addEventListener('click', function () { if (window.MAC && window.MAC.History) window.MAC.History.Clear(); renderHistory(); });
  });

  /* 8. Round TOP button. */
  var topBtn = $('.to-top');
  if (topBtn) {
    var topTick = 0;
    var onScroll = function () {
      var y = window.scrollY || window.pageYOffset, max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      topBtn.classList.toggle('is-on', y > 480);
      topBtn.style.setProperty('--p', Math.min(1, y / max).toFixed(3));
    };
    window.addEventListener('scroll', function () { if (!topTick) { topTick = window.requestAnimationFrame(function () { topTick = 0; onScroll(); }); } }, { passive: true });
    onScroll();
    topBtn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
  }

  /* 9. Spotlight glow that follows the pointer on card thumbnails, and magnetic buttons. */
  if (canHover) {
    document.addEventListener('pointermove', function (e) {
      var t = e.target && e.target.closest ? e.target.closest('.vcard-thumb') : null;
      if (!t) return;
      var r = t.getBoundingClientRect();
      t.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      t.style.setProperty('--my', (e.clientY - r.top) + 'px');
    }, { passive: true });
    if (!reduce) {
      $$('.magnet').forEach(function (b) {
        b.addEventListener('pointermove', function (e) {
          var r = b.getBoundingClientRect();
          b.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * 0.22).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * 0.3).toFixed(1) + 'px)';
        });
        b.addEventListener('pointerleave', function () { b.style.transform = ''; });
      });
    }
  }

  /* 10. Coverflow banner (the original used a Swiper coverflow; this is a small replacement). */
  $$('[data-banner]').forEach(function (el) {
    var slides = $$('.banner-slide', el), dotBox = $('.banner-dots', el), n = slides.length, cur = 0, timer = 0, startX = null, moved = false;
    if (!n) { el.hidden = true; return; }
    if (dotBox && !dotBox.children.length && n > 1) {
      slides.forEach(function (s, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', (el.getAttribute('data-slide-label') || 'Slide') + ' ' + (i + 1));
        dotBox.appendChild(b);
      });
    }
    var dots = dotBox ? $$('button', dotBox) : [];
    var rtl = root.getAttribute('dir') === 'rtl';
    function layout() {
      var small = window.innerWidth < 768;
      slides.forEach(function (s, i) {
        var d = i - cur;
        if (d > n / 2) d -= n;
        if (d < -n / 2) d += n;
        var ad = Math.abs(d), dir = rtl ? -1 : 1;
        var x = d * (small ? 80 : 66) * dir, rot = -d * (small ? 28 : 36) * dir, sc = ad ? (small ? 0.84 : 0.8) : 1;
        s.style.transform = 'translateX(' + x + '%) rotateY(' + rot + 'deg) scale(' + sc + ')';
        s.style.zIndex = String(10 - ad);
        s.style.opacity = ad > 1 ? '0' : '1';
        s.style.pointerEvents = ad > 1 ? 'none' : '';
        s.classList.toggle('is-active', d === 0);
        s.setAttribute('aria-hidden', d === 0 ? 'false' : 'true');
        s.tabIndex = d === 0 ? 0 : -1;
      });
      dots.forEach(function (b, i) { b.setAttribute('aria-current', i === cur ? 'true' : 'false'); });
    }
    function go(i) { cur = (i % n + n) % n; layout(); }
    function stop() { window.clearInterval(timer); timer = 0; }
    function play() { stop(); if (!reduce && n > 1 && !document.hidden) timer = window.setInterval(function () { go(cur + 1); }, 5000); }
    var prev = $('.banner-prev', el), next = $('.banner-next', el);
    if (prev) prev.addEventListener('click', function () { go(cur - 1); play(); });
    if (next) next.addEventListener('click', function () { go(cur + 1); play(); });
    dots.forEach(function (b, i) { b.addEventListener('click', function () { go(i); play(); }); });
    slides.forEach(function (s, i) {
      s.addEventListener('click', function (e) {
        if (moved || i !== cur) { e.preventDefault(); if (!moved) { go(i); play(); } }
      });
    });
    el.addEventListener('pointerdown', function (e) { startX = e.clientX; moved = false; });
    el.addEventListener('pointermove', function (e) { if (startX !== null && Math.abs(e.clientX - startX) > 12) moved = true; });
    el.addEventListener('pointerup', function (e) {
      if (startX === null) return;
      var dx = e.clientX - startX; startX = null;
      if (Math.abs(dx) > 40) { go(cur + ((dx < 0) !== rtl ? 1 : -1)); play(); }
      window.setTimeout(function () { moved = false; }, 0);
    });
    el.addEventListener('pointercancel', function () { startX = null; moved = false; });
    el.addEventListener('mouseenter', stop);
    el.addEventListener('mouseleave', play);
    el.addEventListener('focusin', stop);
    el.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { go(cur + (rtl ? 1 : -1)); }
      else if (e.key === 'ArrowRight') { go(cur + (rtl ? -1 : 1)); }
    });
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else play(); });
    window.addEventListener('resize', layout);
    el.classList.add('is-ready');
    layout();
    play();
  });

  /* 11. Tabs (watch page: about/share/report, related/comments). */
  var commentsLoaded = false;
  function loadComments() {
    if (commentsLoaded || !window.MAC || !window.MAC.Comment || !$('.mac_comment')) return;
    commentsLoaded = true;
    window.MAC.Comment.Show(1);
  }
  $$('[role="tablist"]').forEach(function (list) {
    var tabs = $$('[role="tab"]', list);
    function select(t, focus) {
      tabs.forEach(function (o) {
        var on = o === t, pane = document.getElementById(o.getAttribute('aria-controls'));
        o.setAttribute('aria-selected', on ? 'true' : 'false');
        o.tabIndex = on ? 0 : -1;
        if (pane) pane.hidden = !on;
      });
      if (focus) t.focus();
      if (t.hasAttribute('data-comments')) loadComments();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(t, false); });
      t.addEventListener('keydown', function (e) {
        var k = e.key, rtl = root.getAttribute('dir') === 'rtl', step = 0;
        if (k === 'ArrowRight') step = rtl ? -1 : 1; else if (k === 'ArrowLeft') step = rtl ? 1 : -1; else return;
        e.preventDefault();
        select(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
  });
  $$('[data-open-tab]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var t = document.getElementById(a.getAttribute('data-open-tab'));
      if (!t) return;
      e.preventDefault();
      t.click();
      t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    });
  });
  if (/#comments$/.test(location.hash)) { var ct = document.getElementById('tab-comments'); if (ct) ct.click(); }

  /* 12. Copy link. */
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = document.getElementById(b.getAttribute('data-copy')), value = input ? input.value : location.href;
      function done() { toast(txt('ui.link_copied', 'Link copied')); }
      function legacy() { if (!input) return; input.focus(); input.select(); try { if (document.execCommand('copy')) done(); } catch (err) {} }
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(done, legacy); else legacy();
    });
  });

  /* 13. Long descriptions collapse to four lines with a toggle. */
  $$('[data-clamp]').forEach(function (box) {
    var body = $('.desc-body', box), btn = $('.desc-toggle', box);
    if (!body || !btn) return;
    body.classList.add('is-clamped');
    if (body.scrollHeight <= body.clientHeight + 4) { body.classList.remove('is-clamped'); return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var open = body.classList.toggle('is-clamped') === false;
      btn.textContent = btn.getAttribute(open ? 'data-less' : 'data-more');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* 14. Page jump box: Enter submits through the MAC runtime (MAC.PageGo). */
  var pageInput = document.getElementById('page');
  if (pageInput) {
    pageInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      var go = $('.mac_page_go');
      if (go) go.click();
    });
  }

  /* 15. Live search suggestions (debounced; the endpoint allows 60 requests a minute). */
  $$('form.hd-search').forEach(function (form) {
    var input = $('input[name="wd"]', form), box = null, timer = 0, last = '', active = -1;
    if (!input || !window.fetch) return;
    function hide() { if (box) { box.hidden = true; box.textContent = ''; } active = -1; input.setAttribute('aria-expanded', 'false'); }
    function decode(s) { try { return new DOMParser().parseFromString(String(s), 'text/html').documentElement.textContent; } catch (err) { return String(s); } }
    function render(list) {
      if (!list.length) { hide(); return; }
      if (!box) { box = document.createElement('div'); box.className = 'suggest'; box.id = 'suggest-' + Math.random().toString(36).slice(2, 8); box.setAttribute('role', 'listbox'); form.appendChild(box); input.setAttribute('aria-controls', box.id); }
      box.textContent = '';
      list.slice(0, 8).forEach(function (row) {
        var name = decode(row.name || row.en || ''), a = document.createElement('a');
        if (!name) return;
        var code = /^[A-Z0-9]+(?:-[A-Z0-9]+)+/i.exec(name);
        a.href = form.getAttribute('action') + (form.getAttribute('action').indexOf('?') > -1 ? '&' : '?') + 'wd=' + encodeURIComponent(code ? code[0] : name);
        a.textContent = name;
        a.setAttribute('role', 'option');
        box.appendChild(a);
      });
      box.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { hide(); return; }
      timer = window.setTimeout(function () {
        if (q === last) return;
        last = q;
        fetch(base + '/index.php/ajax/suggest?mid=1&limit=8&wd=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (input.value.trim() === q) render(d && Number(d.code) === 1 && Array.isArray(d.list) ? d.list : []); })
          .catch(hide);
      }, 320);
    });
    input.addEventListener('keydown', function (e) {
      if (!box || box.hidden) return;
      var links = $$('a', box);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach(function (a, i) { a.classList.toggle('is-active', i === active); });
      } else if (e.key === 'Enter' && active > -1 && links[active]) {
        e.preventDefault();
        location.href = links[active].href;
      }
    });
    input.addEventListener('blur', function () { window.setTimeout(hide, 180); });
  });

  /* 16. Keep the active category visible in the mobile strip. */
  var activeCat = $('.cat-strip [aria-current]');
  if (activeCat) {
    var strip = activeCat.closest('.cat-strip');
    if (strip && strip.scrollWidth > strip.clientWidth) {
      var r1 = activeCat.getBoundingClientRect(), r2 = strip.getBoundingClientRect();
      strip.scrollLeft += (r1.left + r1.width / 2) - (r2.left + r2.width / 2);
    }
  }

  /* 17. Notice ticker: hold each title, then slide up to the next one; a copy of the first title closes the loop. */
  var tickList = $('.notice-list');
  if (tickList && tickList.children.length > 1 && !reduce) {
    var tickRoll = tickList.parentNode, tickCount = tickList.children.length, tickIdx = 0, tickPaused = false;
    var tickCopy = tickList.children[0].cloneNode(true);
    tickCopy.setAttribute('aria-hidden', 'true');
    $$('a', tickCopy).forEach(function (a) { a.setAttribute('tabindex', '-1'); });
    tickList.appendChild(tickCopy);
    var tickTo = function (i, animate) {
      tickList.style.transition = animate ? '' : 'none';
      tickList.style.transform = i ? 'translateY(' + (-i * tickList.children[0].offsetHeight) + 'px)' : '';
    };
    ['mouseenter', 'focusin'].forEach(function (ev) { tickRoll.addEventListener(ev, function () { tickPaused = true; }); });
    ['mouseleave', 'focusout'].forEach(function (ev) { tickRoll.addEventListener(ev, function () { tickPaused = false; }); });
    tickList.addEventListener('transitionend', function () { if (tickIdx >= tickCount) { tickIdx = 0; tickTo(0, false); } });
    window.setInterval(function () {
      if (tickPaused || document.hidden) return;
      if (tickIdx >= tickCount) { tickIdx = 0; tickTo(0, false); void tickList.offsetHeight; }
      tickIdx++;
      tickTo(tickIdx, true);
    }, 3200);
    window.addEventListener('resize', function () { if (tickIdx >= tickCount) tickIdx = 0; tickTo(tickIdx, false); });
  }

  /* 20. Horizontal strips (mobile categories, account tabs): keep the focused item and its ring fully in view. */
  $$('.cat-strip,.uc-tabs').forEach(function (strip) {
    strip.addEventListener('focusin', function (e) { if (e.target && e.target.scrollIntoView) e.target.scrollIntoView({ block: 'nearest', inline: 'nearest' }); });
  });

  /* 19. Account pages: the captcha image is requested only when the visitor reaches the field. */
  function verifyLoad(e) {
    var row = e.target && e.target.closest ? e.target.closest('.verify-row') : null, img = row ? row.querySelector('img[data-src]') : null;
    if (img && img.hidden) { img.hidden = false; img.src = img.getAttribute('data-src').split('?')[0] + '?' + Date.now(); }
  }
  document.addEventListener('focusin', verifyLoad);
  document.addEventListener('pointerdown', verifyLoad);

  /* 18. Narrow desktop header (1024-1279px): a shorter search placeholder, so it is never cut. */
  $$('.hd-search input[data-short-placeholder]').forEach(function (input) {
    if (!window.matchMedia) return;
    var full = input.getAttribute('placeholder') || '', short = input.getAttribute('data-short-placeholder') || full;
    var mq = window.matchMedia('(min-width: 1024px) and (max-width: 1279px)');
    var apply = function () { input.setAttribute('placeholder', mq.matches ? short : full); };
    apply();
    if (mq.addEventListener) mq.addEventListener('change', apply); else if (mq.addListener) mq.addListener(apply);
  });

  /* 19. View count: count up in 6 stepped frames (onyx steps() grammar, about 0.4 s) AFTER MAC.Hits writes the live number.
     Skipped under reduced motion, in low power and for numbers below 10; tabular digits in CSS keep the width stable. */
  $$('.stat-row .mac_hits, .views-box .mac_hits').forEach(function (el) {
    if (!window.MutationObserver) return;
    var mo = new MutationObserver(function () {
      mo.disconnect();
      var to = parseInt(el.textContent, 10) || 0;
      if (reduce || root.getAttribute('data-ai-power') === 'low' || root.getAttribute('data-fx-power') === 'low' || to < 10) return;
      var i = 0, n = 6; el.style.display = 'inline-block'; el.style.minWidth = String(to).length + 'ch'; el.style.textAlign = 'end'; el.textContent = '0';
      (function tick() { i++; el.textContent = String(Math.round(to * i / n)); if (i < n) window.setTimeout(tick, 70); })();
    });
    mo.observe(el, { childList: true, characterData: true, subtree: true });
    window.setTimeout(function () { mo.disconnect(); }, 4000);
  });
})(window, document);
