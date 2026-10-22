/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Crimson theme behaviour. Plain JavaScript, no third-party code, no remote calls. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var base = (window.maccms && window.maccms.path) || '';
  var tpl = (window.maccms && window.maccms.templatePath) || '/';
  var placeholder = tpl + 'assets/img/poster.svg';

  function msg(key, fallback) {
    var m = window.TemplateMessages || {};
    return m[key] || fallback || key;
  }
  function toast(text, kind) {
    if (window.TemplateUI && window.TemplateUI.message) window.TemplateUI.message(text, { kind: kind || 'info' });
  }
  function cookie(name) {
    var parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length < 2) return '';
    try { return decodeURIComponent(parts.pop().split(';').shift() || ''); } catch (e) { return ''; }
  }
  function loggedIn() { var id = cookie('user_id'); return id !== '' && id !== '0'; }
  function each(list, fn) { Array.prototype.forEach.call(list, fn); }
  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(window.localStorage.getItem(key) || 'null');
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { return null; }
    return null;
  }

  /* 1. Dropdowns (<details>): one open at a time, close on outside click and Escape. */
  function closeDetails(except) {
    each(document.querySelectorAll('details[data-ct-drop][open]'), function (d) { if (d !== except) d.removeAttribute('open'); });
  }
  document.addEventListener('click', function (e) {
    var inside = e.target.closest ? e.target.closest('details[data-ct-drop]') : null;
    closeDetails(inside);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('details[data-ct-drop][open]');
    if (open) { open.removeAttribute('open'); var s = open.querySelector('summary'); if (s) s.focus(); }
  });
  each(document.querySelectorAll('details[data-ct-drop]'), function (d) {
    d.addEventListener('toggle', function () { if (d.open) closeDetails(d); });
  });
  var lang = root.getAttribute('lang') || 'en';
  each(document.querySelectorAll('.ct-lang-menu a[hreflang]'), function (a) {
    if (a.getAttribute('hreflang') === lang) a.setAttribute('aria-current', 'true');
  });

  /* 2. Tab strip: keep the active tab in view; sliding red underline that follows the pointer. */
  var tabs = document.querySelector('.ct-tabs');
  if (tabs) {
    var list = tabs.querySelector('.ct-tabs-list');
    var active = tabs.querySelector('.ct-tabs-list a[aria-current]');
    if (list && active) {
      var li = active.parentNode;
      var target = li.offsetLeft - (list.clientWidth - li.offsetWidth) / 2;
      if (Math.abs(target) > 4) list.scrollLeft = target;
    }
    if (list && !reduce && finePointer) {
      var ink = document.createElement('span');
      ink.className = 'ct-tabs-ink';
      ink.setAttribute('aria-hidden', 'true');
      list.appendChild(ink);
      var pad = function (a) { return parseFloat(window.getComputedStyle(a).paddingLeft) || 0; };
      var moveTo = function (a) {
        if (!a) { ink.style.opacity = '0'; return; }
        var p = pad(a);
        ink.style.width = Math.max(0, a.offsetWidth - p * 2) + 'px';
        ink.style.transform = 'translateX(' + (a.offsetLeft + p) + 'px)';
        ink.style.opacity = '';
      };
      tabs.classList.add('has-ink');
      moveTo(active);
      if (!active) ink.style.opacity = '0';
      list.addEventListener('mouseover', function (e) { var a = e.target.closest('a'); if (a && list.contains(a)) moveTo(a); });
      list.addEventListener('mouseleave', function () { moveTo(active); if (!active) ink.style.opacity = '0'; });
      window.addEventListener('resize', function () { moveTo(active); });
    }
    /* Mouse users get scroll arrows while the strip overflows (touch users swipe, keyboard focus scrolls);
       every pointer gets edge fades only on the side where tabs are hidden (physical sides, RTL-safe). */
    var prevArrow = null, nextArrow = null;
    if (list && finePointer) {
      /* the bar's glyphs come from the shared auto-icon sprite (lucide); fall back to the theme sprite */
      var spriteUse = document.querySelector('.ct-bar use.ai-g');
      var sprite = spriteUse ? (spriteUse.getAttribute('href') || '').split('#')[0] : tpl + 'assets/img/ct-icons.svg';
      var chevId = spriteUse ? ['ai-chevron-left', 'ai-chevron-right'] : ['chev-l', 'chev-r'];
      var makeArrow = function (dir) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ct-tabs-arrow ' + (dir < 0 ? 'ct-tabs-prev' : 'ct-tabs-next');
        b.tabIndex = -1;
        b.setAttribute('aria-hidden', 'true');
        b.innerHTML = '<svg class="ct-ico" aria-hidden="true"><use href="' + sprite + '#' + chevId[dir < 0 ? 0 : 1] + '"></use></svg>';
        b.addEventListener('click', function () {
          var step = Math.max(180, Math.round(list.clientWidth * 0.6)) * dir * (root.dir === 'rtl' ? -1 : 1);
          list.scrollBy({ left: step, behavior: reduce ? 'auto' : 'smooth' });
        });
        list.parentNode.appendChild(b);
        return b;
      };
      prevArrow = makeArrow(-1); nextArrow = makeArrow(1);
    }
    if (list) {
      var syncTabs = function () {
        var max = list.scrollWidth - list.clientWidth, pos = Math.abs(list.scrollLeft), rtl = root.dir === 'rtl';
        var hidStart = max > 4 && pos > 4, hidEnd = max > 4 && pos < max - 4;
        var l = rtl ? hidEnd : hidStart, r = rtl ? hidStart : hidEnd;
        list.setAttribute('data-fade', (l ? 'l' : '') + (r ? 'r' : '') || 'none');
        if (prevArrow) { prevArrow.classList.toggle('is-on', hidStart); nextArrow.classList.toggle('is-on', hidEnd); }
      };
      list.addEventListener('scroll', syncTabs, { passive: true });
      window.addEventListener('resize', syncTabs);
      syncTabs();
    }
    /* Re-measure the strip once the web font has swapped in: crimson.js runs while the fallback font is still
       laid out, so the active-tab centring, the sliding ink and the arrows/fades are computed on the wrong widths. */
    if (list) {
      var ctTouched = false;
      ['pointerdown', 'wheel', 'touchstart'].forEach(function (t) { list.addEventListener(t, function () { ctTouched = true; }, { passive: true }); });
      var relayout = function () {
        if (active && !ctTouched) {
          var li2 = active.parentNode, t2 = li2.offsetLeft - (list.clientWidth - li2.offsetWidth) / 2;
          if (Math.abs(t2) > 4) list.scrollLeft = t2;
        }
        if (typeof moveTo === 'function') moveTo(active);
        if (typeof syncTabs === 'function') syncTabs();
      };
      if (document.fonts) {
        if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', relayout);
        if (document.fonts.ready) document.fonts.ready.then(relayout);
      }
      window.addEventListener('load', relayout);
      if ('ResizeObserver' in window && active) new ResizeObserver(relayout).observe(active);
    }
  }

  /* 3. Thumbnails: skeleton until loaded, theme placeholder on error (also for late images). */
  var thumbSel = '.ct-thumb img, .ct-slide img, .ct-cover img, .ct-hub-tile img';
  function markLoaded(img) { var box = img.parentNode; if (box && box.classList) box.classList.add('is-loaded'); }
  function fallback(img) {
    if (img.dataset.ctFallback === '1') return;
    img.dataset.ctFallback = '1';
    img.classList.add('is-fallback');
    if (img.parentNode && img.parentNode.classList) img.parentNode.classList.add('is-broken');
    img.src = placeholder;
  }
  document.addEventListener('load', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.matches(thumbSel)) markLoaded(t); }, true);
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.matches(thumbSel)) fallback(t); }, true);
  function scanImages(scope) {
    each((scope || document).querySelectorAll(thumbSel), function (img) {
      if (!img.complete) return;
      if (img.naturalWidth > 0) markLoaded(img); else if (img.getAttribute('src')) fallback(img);
    });
  }
  scanImages();

  /* 4. Card spotlight (desktop pointers only). */
  if (finePointer && !reduce) {
    document.addEventListener('pointermove', function (e) {
      var box = e.target.closest ? e.target.closest('.ct-thumb') : null;
      if (!box) return;
      var r = box.getBoundingClientRect();
      box.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      box.style.setProperty('--my', (e.clientY - r.top) + 'px');
    }, { passive: true });
  }

  function refill(grid) { if (window.TemplateGridFill && grid) window.TemplateGridFill.fill(grid); }

  /* 5. Shuffle: the visible group of a home section swaps with the next group of 10 kept in the
        section's inert <template data-ct-pool> (not rendered, no images loaded until shown). */
  function shuffleGroups(grid) {
    if (grid.ctGroups) return grid.ctGroups;
    var first = [], groups = [first];
    each(grid.children, function (c) { if (c.tagName !== 'TEMPLATE') first.push(c); });
    var tpl = grid.querySelector('template[data-ct-pool]');
    if (tpl && tpl.content) {
      var pool = Array.prototype.slice.call(tpl.content.children);
      for (var i = 0; i < pool.length; i += 10) groups.push(pool.slice(i, i + 10));
    }
    grid.ctGroups = groups; grid.ctCur = 0;
    return groups;
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ct-shuffle]') : null;
    if (!btn) return;
    var grid = document.getElementById(btn.getAttribute('data-ct-shuffle'));
    if (!grid) return;
    var groups = shuffleGroups(grid);
    if (groups.length < 2) return;
    var next = (grid.ctCur + 1) % groups.length, frag = document.createDocumentFragment();
    groups[grid.ctCur].forEach(function (c) { if (c.parentNode === grid) grid.removeChild(c); });
    groups[next].forEach(function (c) { c.classList.add('is-swap'); frag.appendChild(c); });
    grid.insertBefore(frag, grid.querySelector('template'));
    grid.ctCur = next;
    grid.setAttribute('data-ct-show', String(next + 1));
    refill(grid);
    scanImages(grid);
    btn.classList.remove('is-spin'); void btn.offsetWidth; btn.classList.add('is-spin');
  });

  /* 6. Banner: dots, arrows, autoplay (paused on hover, focus, hidden tab and reduced motion). */
  each(document.querySelectorAll('[data-ct-banner]'), function (banner) {
    var track = banner.querySelector('.ct-banner-track');
    var slides = track ? track.querySelectorAll('.ct-slide') : [];
    if (!track || slides.length < 2) { if (slides[0]) slides[0].classList.add('is-active'); return; }
    var dotsBox = document.createElement('div');
    dotsBox.className = 'ct-dots';
    var dots = [], index = 0, timer = 0, paused = false;
    function perView() { var w = slides[0].getBoundingClientRect().width || 1; return Math.max(1, Math.round((track.clientWidth + 10) / (w + 10))); }
    function pages() { return Math.max(1, slides.length - perView() + 1); }
    function buildDots() {
      dotsBox.innerHTML = ''; dots = [];
      /* Under 1024px the banner is swiped (no arrows) and the dots only show the position: no tap or Tab targets. */
      var decor = !!(window.matchMedia && window.matchMedia('(max-width:1023px)').matches);
      if (decor) dotsBox.setAttribute('aria-hidden', 'true'); else dotsBox.removeAttribute('aria-hidden');
      for (var i = 0; i < pages(); i++) {
        var d = document.createElement('button');
        d.type = 'button'; d.className = 'ct-dot';
        if (decor) d.tabIndex = -1;
        d.setAttribute('aria-label', String(i + 1) + ' / ' + pages());
        d.addEventListener('click', go.bind(null, i));
        dotsBox.appendChild(d); dots.push(d);
      }
      mark();
    }
    function mark() {
      each(slides, function (s, i) { s.classList.toggle('is-active', i === index); });
      dots.forEach(function (d, i) { d.setAttribute('aria-current', i === index ? 'true' : 'false'); });
    }
    function go(i) {
      var n = pages();
      index = (i + n) % n;
      var s = slides[index];
      /* Rect-based target: correct in LTR (scrollLeft 0..+max) and RTL (0..-max) alike. */
      var tr = track.getBoundingClientRect(), sr = s.getBoundingClientRect();
      var left = track.scrollLeft + (root.dir === 'rtl' ? sr.right - tr.right : sr.left - tr.left);
      track.scrollTo({ left: left, behavior: reduce ? 'auto' : 'smooth' });
      mark();
    }
    var syncTimer = 0;
    track.addEventListener('scroll', function () {
      window.clearTimeout(syncTimer);
      syncTimer = window.setTimeout(function () {
        var w = slides[0].getBoundingClientRect().width + 10;
        var i = Math.round(Math.abs(track.scrollLeft) / w);
        if (i !== index && i < pages()) { index = i; mark(); }
      }, 90);
    }, { passive: true });
    function tick() { if (!paused && !document.hidden) go(index + 1); }
    function start() { if (!reduce) { window.clearInterval(timer); timer = window.setInterval(tick, 5500); } }
    banner.addEventListener('mouseenter', function () { paused = true; });
    banner.addEventListener('mouseleave', function () { paused = false; });
    banner.addEventListener('focusin', function () { paused = true; });
    banner.addEventListener('focusout', function () { paused = false; });
    track.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    track.addEventListener('touchend', function () { window.setTimeout(function () { paused = false; }, 4000); }, { passive: true });
    each(banner.querySelectorAll('[data-ct-step]'), function (b) {
      b.addEventListener('click', function () { go(index + parseInt(b.getAttribute('data-ct-step'), 10)); });
    });
    banner.appendChild(dotsBox);
    buildDots();
    window.addEventListener('resize', function () { buildDots(); });
    start();
  });

  /* 7. Load more: append the next page of a list, keep the numbered pager crawlable. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ct-more]') : null;
    if (!btn || btn.classList.contains('is-busy')) return;
    var grid = document.querySelector('[data-ct-list]');
    var pager = document.querySelector('.ct-pager');
    var next = pager ? pager.querySelector('a[rel="next"]') : null;
    if (!grid || !next || !window.fetch || !window.DOMParser) return;
    e.preventDefault();
    btn.classList.add('is-busy');
    btn.setAttribute('aria-busy', 'true');
    window.fetch(next.href, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.text();
    }).then(function (html) {
      var doc = new window.DOMParser().parseFromString(html, 'text/html');
      var newGrid = doc.querySelector('[data-ct-list]');
      var cards = newGrid ? newGrid.querySelectorAll('.ct-card') : [];
      var frag = document.createDocumentFragment();
      each(cards, function (c) { frag.appendChild(document.importNode(c, true)); });
      grid.appendChild(frag);
      var newPager = doc.querySelector('.ct-pager');
      if (newPager && pager) pager.parentNode.replaceChild(document.importNode(newPager, true), pager);
      var more = doc.querySelector('.ct-pager a[rel="next"]');
      if (!more || !cards.length) {
        var wrap = btn.parentNode;
        btn.remove();
        var end = document.createElement('p');
        end.className = 'ct-nomore';
        end.textContent = msg('crimson.no_more', 'No more videos');
        if (wrap) wrap.appendChild(end);
      }
      refill(grid);
      scanImages(grid);
    }).catch(function () {
      toast(msg('network_error', 'Network error'), 'error');
    }).then(function () {
      btn.classList.remove('is-busy');
      btn.removeAttribute('aria-busy');
    });
  });

  /* 8. Favourites: POST through secure-forms.js (adds the session token); guests go to login. */
  function favourite(btn) {
    var id = btn.getAttribute('data-id');
    if (!id) return;
    if (!loggedIn()) {
      toast(msg('crimson.fav_login', 'Log in to save favorites.'));
      window.setTimeout(function () { window.location.href = (window.maccms && maccms.template_urls && maccms.template_urls.user_login) || (base + '/user/login/'); }, 1100);
      return;
    }
    if (!window.jQuery || !window.maccms || !maccms.template_urls) return;
    btn.classList.add('is-busy');
    window.jQuery.ajax({
      url: maccms.template_urls.user_ajax_ulog, type: 'post', dataType: 'json',
      data: { ac: 'set', mid: btn.getAttribute('data-mid') || '1', id: id, type: '2' }
    }).done(function (r) {
      if (r && r.code === 1) {
        btn.classList.add('is-on');
        btn.setAttribute('aria-pressed', 'true');
        toast(msg('crimson.fav_added', 'Added to favorites'), 'success');
      } else {
        toast((r && r.msg) || msg('crimson.fav_failed', 'Could not save this favorite.'), 'error');
      }
    }).fail(function () {
      toast(msg('crimson.fav_failed', 'Could not save this favorite.'), 'error');
    }).always(function () { btn.classList.remove('is-busy'); });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ct-fav]') : null;
    if (!btn) return;
    e.preventDefault();
    favourite(btn);
  });

  /* 9. Share: native share sheet on phones, otherwise copy the link. */
  function copyText(text, input) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    }
    return new Promise(function (resolve) {
      var ok = false;
      if (input) { input.focus(); input.select(); try { ok = document.execCommand('copy'); } catch (e) { ok = false; } }
      resolve(ok);
    });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ct-share]') : null;
    if (btn) {
      var url = btn.getAttribute('data-url') || window.location.href;
      var box = document.getElementById(btn.getAttribute('aria-controls') || '');
      if (navigator.share && !finePointer) {
        navigator.share({ title: document.title, url: url }).catch(function () {});
        return;
      }
      if (box) { box.classList.add('is-open'); btn.setAttribute('aria-expanded', 'true'); }
      copyText(url, box ? box.querySelector('input') : null).then(function (ok) {
        toast(ok ? msg('ui.link_copied', 'Link copied') : msg('ui.select_and_copy_the_address', 'Select and copy the address'), ok ? 'success' : 'info');
      });
      return;
    }
    var copy = e.target.closest ? e.target.closest('[data-ct-copy]') : null;
    if (copy) {
      var input = document.getElementById(copy.getAttribute('data-ct-copy'));
      if (!input) return;
      copyText(input.value, input).then(function (ok) {
        toast(ok ? msg('ui.link_copied', 'Link copied') : msg('ui.select_and_copy_the_address', 'Select and copy the address'), ok ? 'success' : 'info');
      });
    }
  });

  /* 10. Long descriptions: clamp with a toggle. */
  each(document.querySelectorAll('[data-ct-desc]'), function (box) {
    var p = box.querySelector('p');
    var btn = box.querySelector('.ct-desc-toggle');
    if (!p || !btn) return;
    box.classList.add('is-clamped');
    if (p.scrollHeight <= p.clientHeight + 2) { box.classList.remove('is-clamped'); btn.hidden = true; return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var open = box.classList.toggle('is-clamped') === false;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.textContent = open ? btn.getAttribute('data-less') : btn.getAttribute('data-more');
    });
  });

  /* 11. Back to top. */
  var top = document.querySelector('.ct-totop');
  if (top) {
    var onScroll = function () { top.classList.toggle('is-on', window.scrollY > 640); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });
  }

  /* 12. Search history (this browser only). */
  var HKEY = 'crimson.searchHistory';
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[role="search"]')) return;
    var input = form.querySelector('input[name="wd"]');
    var q = input ? input.value.replace(/\s+/g, ' ').trim() : '';
    if (!q) { e.preventDefault(); if (input) input.focus(); return; }
    var list = store(HKEY) || [];
    if (!Array.isArray(list)) list = [];
    list = [q].concat(list.filter(function (v) { return typeof v === 'string' && v.toLowerCase() !== q.toLowerCase(); })).slice(0, 12);
    store(HKEY, list);
  });
  var historyBox = document.getElementById('ct-history');
  if (historyBox) {
    var searchUrl = historyBox.getAttribute('data-search') || (base + '/search/');
    var histIco = document.getElementById('ct-history-ico');
    var render = function () {
      var items = store(HKEY) || [];
      historyBox.innerHTML = '';
      if (!Array.isArray(items) || !items.length) {
        var none = document.createElement('p');
        none.className = 'ct-tip';
        none.textContent = historyBox.getAttribute('data-empty') || '';
        historyBox.appendChild(none);
        var clr0 = document.getElementById('ct-history-clear'); if (clr0) clr0.hidden = true;
        return;
      }
      items.forEach(function (q) {
        if (typeof q !== 'string') return;
        var a = document.createElement('a');
        a.className = 'ct-chip';
        a.href = searchUrl + (searchUrl.indexOf('?') === -1 ? '?' : '&') + 'wd=' + encodeURIComponent(q);
        a.textContent = q;
        if (histIco && histIco.content.firstElementChild) a.insertBefore(histIco.content.firstElementChild.cloneNode(true), a.firstChild);
        historyBox.appendChild(a);
      });
    };
    render();
    var clr = document.getElementById('ct-history-clear');
    if (clr) clr.addEventListener('click', function () { store(HKEY, []); render(); toast(msg('crimson.history_cleared', 'Search history cleared')); });
  }

  /* 13. Watch history for signed-in members (MacCMS ulog type 4, token added by secure-forms.js). */
  var playLog = document.querySelector('[data-ct-playlog]');
  if (playLog && loggedIn() && window.jQuery && window.maccms && maccms.template_urls) {
    window.setTimeout(function () {
      window.jQuery.ajax({
        url: maccms.template_urls.user_ajax_ulog, type: 'post', dataType: 'json',
        data: { ac: 'set', mid: '1', id: playLog.getAttribute('data-id'), sid: playLog.getAttribute('data-sid') || '1', nid: playLog.getAttribute('data-nid') || '1', type: '4' }
      });
    }, 1500);
  }

  /* 14. Member-only shortcuts: signed-in members skip the guest gate pages. */
  var gate = document.querySelector('[data-ct-gate]');
  if (gate && loggedIn()) window.location.replace(gate.getAttribute('data-ct-gate'));
  if (loggedIn()) {
    each(document.querySelectorAll('[data-ct-member-href]'), function (a) { a.setAttribute('href', a.getAttribute('data-ct-member-href')); });
  }

  /* 15. Search pills: the input spans the whole pill, so its end padding must clear the text submit button. */
  var sbForms = document.querySelectorAll('.ct-psearch, .ct-searchbig');
  function sizeSearch() {
    each(sbForms, function (f) { var b = f.querySelector('button'); if (b && b.offsetWidth) f.style.setProperty('--ct-sbw', b.offsetWidth + 'px'); });
  }
  if (sbForms.length) {
    sizeSearch();
    window.addEventListener('resize', sizeSearch);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(sizeSearch);
  }

  /* 16. Horizontal strips (tabs, chip rows, banner, performer row): keyboard focus brings the item fully into view, clear of the
     edge fades (scroll-padding-inline in the CSS). The browser only scrolls items that are completely hidden. */
  each(document.querySelectorAll('.ct-tabs-list, .ct-chips--scroll, .ct-banner-track, .ct-people'), function (strip) {
    strip.addEventListener('focusin', function (e) {
      var t = e.target;
      if (t && t !== strip && t.scrollIntoView) t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });
  });

  /* 17. Account captcha (login, register, find password): no image request on page view. The first focus or
     press inside the form loads it; a click or Enter/Space on the image loads a new one (also reached by the
     pages' $('#verify_img').trigger('click') after a failed submit, because jQuery calls the native click()). */
  function captchaLoad(img) {
    img.setAttribute('data-on', '1');
    img.src = img.getAttribute('data-captcha-src').split('?')[0] + '?' + Date.now();
  }
  function captchaWake(e) {
    var t = e.target, form = t && t.closest ? t.closest('form') : null;
    var img = form ? form.querySelector('img[data-captcha-src]:not([data-on])') : null;
    if (img && t !== img) captchaLoad(img);
  }
  /* Account button: home.js replaces its text with the member name after login, which drops the auto-icon;
     put the same glyph back once (one observer, disconnected after the first change). */
  var loginBtn = document.querySelector('.ct-login');
  var loginIco = loginBtn ? loginBtn.querySelector('.ai') : null;
  if (loginIco && window.MutationObserver) {
    var loginKeep = loginIco.cloneNode(true);
    var loginObs = new MutationObserver(function () {
      if (!loginBtn.querySelector('.ai')) { loginBtn.insertBefore(loginKeep, loginBtn.firstChild); loginObs.disconnect(); }
    });
    loginObs.observe(loginBtn, { childList: true });
  }

  document.addEventListener('focusin', captchaWake);
  document.addEventListener('pointerdown', captchaWake);
  document.addEventListener('click', function (e) {
    var img = e.target && e.target.closest ? e.target.closest('img[data-captcha-src]') : null;
    if (img) { e.preventDefault(); captchaLoad(img); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var img = e.target && e.target.matches && e.target.matches('img[data-captcha-src]') ? e.target : null;
    if (img) { e.preventDefault(); captchaLoad(img); }
  });
})(window, document);
