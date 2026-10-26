/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! Crimson Ink (Modern) - behaviour layer | JavCMS theme | original code (JavCMS Community License 1.0)
 * Dropdowns, phone search + panel-grid menu (focus trap, inert, Escape), live search suggestions, thumbnail fallback,
 * favourites + impact frame, share/copy, description clamp, search/watch history, captcha on demand, brand fit,
 * theme-colour swap, featured panel swap with the ink-reveal mask, SFX pop trigger. No dependencies (jQuery only for MAC posts). */
(function (W, D) {
  'use strict';
  var R = D.documentElement;
  var reduce = false;
  try { reduce = W.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { reduce = false; }
  var M = W.TemplateMessages || {};
  function msg(k, d) { return M[k] || d || k; }
  function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }
  function toast(text, kind) { if (W.TemplateUI && W.TemplateUI.message) W.TemplateUI.message(text, kind ? { kind: kind } : undefined); }
  function cookie(n) { var m = D.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function loggedIn() { var id = cookie('user_id'); return id !== '' && id !== '0'; }
  function store(k, v) { try { if (v === undefined) return JSON.parse(W.localStorage.getItem(k) || 'null'); W.localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } return v; }
  function urls() { return (W.maccms && W.maccms.template_urls) || {}; }
  /* first measurement passes (brand fit, SFX wait) run when the main thread is idle, not inside the parse-end task */
  function idle(fn) { if (W.requestIdleCallback) W.requestIdleCallback(fn, { timeout: 1200 }); else W.setTimeout(fn, 0); }

  /* 1. theme-colour meta follows the colour mode; the owner's colour (SiteHead meta with data-jv-head) wins in both modes */
  var tc = D.querySelector('meta[name="theme-color"][data-jv-head]') ? null : D.querySelector('meta[name="theme-color"]');
  function syncTc() { if (tc) tc.setAttribute('content', R.getAttribute('data-color-mode') === 'light' ? '#f7efe0' : '#0f0a0b'); }
  syncTc();
  if ('MutationObserver' in W) new MutationObserver(syncTc).observe(R, { attributes: true, attributeFilter: ['data-color-mode'] });

  /* 2. dropdowns: one open at a time, outside click and Escape close */
  function closeDrops(except) { each(D.querySelectorAll('details[data-ci-drop][open]'), function (d) { if (d !== except) d.removeAttribute('open'); }); }
  each(D.querySelectorAll('details[data-ci-drop]'), function (d) { d.addEventListener('toggle', function () { if (d.open) closeDrops(d); }); });
  D.addEventListener('click', function (e) { var t = e.target; if (!t.closest || !t.closest('details[data-ci-drop]')) closeDrops(null); });
  D.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = D.querySelector('details[data-ci-drop][open]');
    if (open) { open.removeAttribute('open'); var s = open.querySelector('summary'); if (s) s.focus(); }
  });

  /* 3. phone search sheet */
  var sbtn = D.querySelector('.ci-sbtn'), psheet = D.getElementById('ci-psearch');
  if (sbtn && psheet) {
    sbtn.addEventListener('click', function () {
      var open = psheet.hasAttribute('hidden');
      if (open) psheet.removeAttribute('hidden'); else psheet.setAttribute('hidden', '');
      sbtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var i = psheet.querySelector('input'); if (i) i.focus(); }
    });
    psheet.addEventListener('keydown', function (e) { if (e.key === 'Escape') { psheet.setAttribute('hidden', ''); sbtn.setAttribute('aria-expanded', 'false'); sbtn.focus(); } });
  }

  /* 4. full-screen panel-grid menu: focus trap, inert page, Escape, focus returns */
  var menu = D.getElementById('ci-menu'), mbtn = D.querySelector('.ci-menu-btn');
  function siblings() { var out = []; each(D.body.children, function (c) { if (c !== menu && c.tagName !== 'SCRIPT') out.push(c); }); return out; }
  function setMenu(open) {
    if (!menu || !mbtn) return;
    if (open) {
      menu.removeAttribute('hidden'); R.classList.add('ci-menu-open'); mbtn.setAttribute('aria-expanded', 'true');
      each(siblings(), function (c) { c.setAttribute('inert', ''); c.setAttribute('aria-hidden', 'true'); });
      var first = menu.querySelector('[aria-current="page"]') || menu.querySelector('a, button');
      if (first) first.focus();
      if (!reduce && menu.animate) {
        each(menu.querySelectorAll('.ci-pg'), function (p, i) { p.animate([{ transform: 'translate(-14px, 18px) skewX(-8deg)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 260, delay: Math.min(i, 12) * 28, easing: 'cubic-bezier(.34,1.56,.64,1)', fill: 'backwards' }); });
      }
    } else {
      menu.setAttribute('hidden', ''); R.classList.remove('ci-menu-open'); mbtn.setAttribute('aria-expanded', 'false');
      each(siblings(), function (c) { c.removeAttribute('inert'); c.removeAttribute('aria-hidden'); });
      mbtn.focus();
    }
  }
  if (menu && mbtn) {
    D.body.appendChild(menu);
    mbtn.addEventListener('click', function () { setMenu(menu.hasAttribute('hidden')); });
    menu.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-ci-close]')) setMenu(false); });
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); setMenu(false); return; }
      if (e.key !== 'Tab') return;
      /* rendered stops only; links inside a closed <details> (the language list) are not focusable, its summary is */
      var f = Array.prototype.filter.call(menu.querySelectorAll('a[href], button, summary, input'), function (n) { var d = n.closest('details:not([open])'); return n.getClientRects().length > 0 && !(d && n !== d.querySelector(':scope > summary')); });
      if (!f.length) return;
      var a = f[0], z = f[f.length - 1];
      if (e.shiftKey && D.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && D.activeElement === z) { e.preventDefault(); a.focus(); }
    });
    /* Escape closes the open menu wherever focus is */
    D.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hasAttribute('hidden')) { e.preventDefault(); setMenu(false); } });
    W.addEventListener('resize', function () { if (!menu.hasAttribute('hidden') && W.innerWidth >= 1280) setMenu(false); });
  }

  /* 5. live search suggestions (ajax/suggest, debounced, max 1 request per 300 ms) */
  function decode(s) { try { return new DOMParser().parseFromString(String(s), 'text/html').documentElement.textContent || ''; } catch (e) { return String(s); } }
  each(D.querySelectorAll('form[data-ci-suggest]'), function (form) {
    var input = form.querySelector('input[name="wd"]'), list = form.querySelector('.ci-suggest');
    if (!input || !list || !urls().suggest) return;
    var timer = 0, last = '', seq = 0, idx = -1;
    function close() { list.setAttribute('hidden', ''); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); idx = -1; }
    function items() { return list.querySelectorAll('a'); }
    function mark(i) { var a = items(); each(a, function (x, k) { x.setAttribute('aria-selected', k === i ? 'true' : 'false'); }); idx = i; if (a[i]) input.setAttribute('aria-activedescendant', a[i].id); }
    function render(rows, q) {
      list.textContent = '';
      if (!rows.length) { var li0 = D.createElement('li'); li0.className = 'ci-sg-none'; li0.textContent = msg('ink.suggest_none', 'No suggestions'); list.appendChild(li0); }
      rows.slice(0, 8).forEach(function (r, k) {
        var name = decode(r.name || ''); if (!name) return;
        var li = D.createElement('li'), a = D.createElement('a');
        a.id = list.id + '-o' + k; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false'); a.tabIndex = -1;
        a.href = (urls().search || '/search/') + (String(urls().search || '').indexOf('?') >= 0 ? '&' : '?') + 'wd=' + encodeURIComponent(name);
        a.textContent = name; li.appendChild(a); list.appendChild(li);
      });
      list.removeAttribute('hidden'); input.setAttribute('aria-expanded', 'true'); idx = -1;
    }
    input.addEventListener('input', function () {
      var q = input.value.trim(); W.clearTimeout(timer);
      if (q.length < 2) { close(); return; }
      timer = W.setTimeout(function () {
        if (q === last) return; last = q; var my = ++seq;
        var u = urls().suggest; u += (u.indexOf('?') >= 0 ? '&' : '?') + 'mid=1&limit=8&wd=' + encodeURIComponent(q);
        W.fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
          if (my !== seq || input.value.trim() !== q) return;
          render(j && j.list && j.list.length ? j.list : [], q);
        }).catch(function () { close(); });
      }, 320);
    });
    input.addEventListener('keydown', function (e) {
      var a = items();
      if (list.hasAttribute('hidden') || !a.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); mark(idx + 1 >= a.length ? 0 : idx + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark(idx <= 0 ? a.length - 1 : idx - 1); }
      else if (e.key === 'Enter' && idx >= 0 && a[idx]) { e.preventDefault(); W.location.href = a[idx].href; }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
    });
    D.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
    form.addEventListener('focusout', function () { W.setTimeout(function () { if (!form.contains(D.activeElement)) close(); }, 120); });
  });

  /* 6. thumbnails: a failed poster hides and the designed placeholder (CSS, follows the colour mode) shows */
  function broken(img) { if (img && img.classList) img.classList.add('is-broken'); }
  D.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.closest && t.closest('.ci-thumb')) broken(t); }, true);
  each(D.querySelectorAll('.ci-thumb img'), function (img) { if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) broken(img); });

  /* 7. favourites (MAC ulog type 2 via secure-forms.js) + impact frame (C6) */
  function shake(btn) {
    if (reduce) return;
    var card = btn.closest('.ci-watch, .ci-detail, .ci-card') || btn;
    card.classList.remove('ci-impact'); void card.offsetWidth; card.classList.add('ci-impact');
    W.setTimeout(function () { card.classList.remove('ci-impact'); }, 400);
  }
  function impact(btn) {
    if (reduce) return;
    var r = btn.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
    var boom = D.createElement('span'); boom.className = 'ci-boom'; boom.style.left = x + 'px'; boom.style.top = y + 'px';
    var bal = D.createElement('span'); bal.className = 'ci-boom-b'; bal.textContent = '♥!'; bal.style.left = x + 'px'; bal.style.top = y + 'px';
    boom.setAttribute('aria-hidden', 'true'); bal.setAttribute('aria-hidden', 'true');
    D.body.appendChild(boom); D.body.appendChild(bal);
    W.setTimeout(function () { boom.remove(); bal.remove(); }, 950);
  }
  D.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ci-fav]') : null;
    if (!btn) return;
    e.preventDefault();
    shake(btn);
    if (!loggedIn()) { toast(msg('ink.fav_login', 'Log in to save favorites.')); W.setTimeout(function () { W.location.href = urls().user_login || '/user/login/'; }, 1100); return; }
    if (!W.jQuery || !urls().user_ajax_ulog || btn.classList.contains('is-busy')) return;
    btn.classList.add('is-busy');
    W.jQuery.ajax({ url: urls().user_ajax_ulog, type: 'post', dataType: 'json', data: { ac: 'set', mid: btn.getAttribute('data-mid') || '1', id: btn.getAttribute('data-id'), type: '2' } })
      .done(function (r) { if (r && r.code === 1) { btn.setAttribute('aria-pressed', 'true'); impact(btn); toast(msg('ink.fav_added', 'Added to favorites'), 'success'); } else { toast((r && r.msg) || msg('ink.fav_failed', 'Could not save this favorite.'), 'error'); } })
      .fail(function () { toast(msg('ink.fav_failed', 'Could not save this favorite.'), 'error'); })
      .always(function () { btn.classList.remove('is-busy'); });
  });

  /* 8. share (native sheet on touch) and copy link */
  function copyText(text, input) {
    if (navigator.clipboard && W.isSecureContext) return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    return new Promise(function (res) { try { if (input) { input.focus(); input.select(); } res(D.execCommand('copy')); } catch (e) { res(false); } });
  }
  D.addEventListener('click', function (e) {
    var sh = e.target.closest ? e.target.closest('[data-ci-share]') : null;
    if (sh) {
      var box = D.getElementById(sh.getAttribute('aria-controls') || ''), url = sh.getAttribute('data-url') || W.location.href;
      if (navigator.share && W.matchMedia('(pointer: coarse)').matches) { navigator.share({ title: D.title, url: url }).catch(function () {}); return; }
      if (box) { var open = !box.classList.contains('is-open'); box.classList.toggle('is-open', open); sh.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open) { var i = box.querySelector('input'); if (i) i.select(); } }
      return;
    }
    var cp = e.target.closest ? e.target.closest('[data-ci-copy]') : null;
    if (cp) {
      var inp = D.getElementById(cp.getAttribute('data-ci-copy'));
      if (inp) copyText(inp.value, inp).then(function (ok) { toast(ok ? msg('ui.link_copied', 'Link copied') : msg('ui.select_and_copy_the_address', 'Select and copy the address'), ok ? 'success' : ''); });
    }
  });

  /* 9. description clamp */
  each(D.querySelectorAll('[data-ci-desc]'), function (box) {
    var p = box.querySelector('p'), btn = box.querySelector('.ci-desc-toggle');
    if (!p || !btn) return;
    box.classList.add('is-clamped');
    if (p.scrollHeight - p.clientHeight < 8) { box.classList.remove('is-clamped'); return; }
    btn.hidden = false;
    btn.addEventListener('click', function () { var open = box.classList.toggle('is-clamped') === false; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.textContent = btn.getAttribute(open ? 'data-less' : 'data-more'); });
  });

  /* 10. recent searches (search centre) */
  var HKEY = 'ci.searches';
  D.addEventListener('submit', function (e) {
    var f = e.target, q = f && f.querySelector ? f.querySelector('input[name="wd"]') : null;
    if (!q || !q.value.trim()) return;
    var v = q.value.trim(), list = store(HKEY) || [];
    list = [v].concat(list.filter(function (x) { return typeof x === 'string' && x.toLowerCase() !== v.toLowerCase(); })).slice(0, 12);
    store(HKEY, list);
  });
  var hist = D.getElementById('ci-history');
  if (hist) {
    var renderHist = function () {
      var list = store(HKEY) || []; hist.textContent = '';
      if (!list.length) { var p = D.createElement('p'); p.className = 'ci-tip'; p.textContent = hist.getAttribute('data-empty') || ''; hist.appendChild(p); return; }
      list.forEach(function (q) { var a = D.createElement('a'); a.className = 'ci-chip'; var s = hist.getAttribute('data-search') || '/search/'; a.href = s + (s.indexOf('?') >= 0 ? '&' : '?') + 'wd=' + encodeURIComponent(q); a.textContent = q; hist.appendChild(a); });
    };
    renderHist();
    var clr = D.getElementById('ci-history-clear');
    if (clr) clr.addEventListener('click', function () { store(HKEY, []); renderHist(); toast(msg('ink.history_cleared', 'Search history cleared')); });
  }

  /* 11. watch history for members (ulog type 4) and member-only shortcuts */
  var pl = D.querySelector('[data-ci-playlog]');
  if (pl && loggedIn() && W.jQuery && urls().user_ajax_ulog) {
    W.setTimeout(function () { W.jQuery.ajax({ url: urls().user_ajax_ulog, type: 'post', dataType: 'json', data: { ac: 'set', mid: '1', id: pl.getAttribute('data-id'), sid: pl.getAttribute('data-sid') || '1', nid: pl.getAttribute('data-nid') || '1', type: '4' } }); }, 1500);
  }
  var gate = D.querySelector('[data-ci-gate]');
  if (gate && loggedIn()) W.location.replace(gate.getAttribute('data-ci-gate'));
  if (loggedIn()) each(D.querySelectorAll('[data-ci-member-href]'), function (a) { a.setAttribute('href', a.getAttribute('data-ci-member-href')); });

  /* 12. account captcha: no image request on page view; first focus/press in the form loads it */
  function capLoad(img) { img.setAttribute('data-on', '1'); img.src = img.getAttribute('data-captcha-src').split('?')[0] + '?' + Date.now(); }
  function capWake(e) { var t = e.target, f = t && t.closest ? t.closest('form') : null, img = f ? f.querySelector('img[data-captcha-src]:not([data-on])') : null; if (img && t !== img) capLoad(img); }
  D.addEventListener('focusin', capWake);
  D.addEventListener('pointerdown', capWake);
  D.addEventListener('click', function (e) { var img = e.target && e.target.closest ? e.target.closest('img[data-captcha-src]') : null; if (img) { e.preventDefault(); capLoad(img); } });
  D.addEventListener('keydown', function (e) { if (e.key !== 'Enter' && e.key !== ' ') return; var t = e.target; if (t && t.matches && t.matches('img[data-captcha-src]')) { e.preventDefault(); capLoad(t); } });

  /* 13. brand fit: tracking to 0, then 1 px steps down to 13 px, then ellipsis (CSS) */
  function fit() {
    each(D.querySelectorAll('.ci-wm-t'), function (t) {
      if (!t.style.fontSize && !t.style.letterSpacing && t.scrollWidth <= t.clientWidth + 1) return;
      t.style.letterSpacing = ''; t.style.fontSize = '';
      if (t.scrollWidth <= t.clientWidth + 1) return;
      t.style.letterSpacing = '0';
      var fs = parseFloat(W.getComputedStyle(t).fontSize) || 23;
      while (t.scrollWidth > t.clientWidth + 1 && fs > 13) { fs -= 1; t.style.fontSize = fs + 'px'; }
    });
  }
  /* The first measurement waits until the page has loaded and settled (CSS ellipsis covers a long name until then):
     reading scrollWidth while the load is still restyling the page forced a whole-page style + layout pass, a phone
     long task. After that, a ResizeObserver on the root (viewport width only, so the font-size steps cannot
     re-trigger it) refits on rotation/resize, and a late web font refits too. */
  var fitOn = false;
  function fitFirst() { W.setTimeout(function () { idle(function () { fitOn = true; fit(); }); }, 500); }
  if (D.readyState === 'complete') fitFirst(); else W.addEventListener('load', fitFirst);
  if (W.ResizeObserver) {
    var fw = -1;
    new W.ResizeObserver(function (en) { var w = Math.round(en[0].contentRect.width); if (w === fw) return; fw = w; if (fitOn) fit(); }).observe(R);
  } else {
    var ft = 0; W.addEventListener('resize', function () { if (!fitOn) return; W.clearTimeout(ft); ft = W.setTimeout(fit, 120); });
  }
  /* 'loadingdone' instead of document.fonts.ready, whose getter forces a whole-page style recalc while the page settles */
  if (D.fonts && D.fonts.addEventListener) D.fonts.addEventListener('loadingdone', function () { if (fitOn) idle(fit); });

  /* 14. featured spread: a mini panel slams into the big panel (ink-reveal mask) */
  var big = D.querySelector('[data-ci-fimg]');
  if (big) {
    var bigImg = function () { return big.querySelector('img'); };
    var title = D.querySelector('[data-ci-ft]'), meta = D.querySelector('[data-ci-fm]');
    D.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-ci-swap]') : null;
      if (!b) return;
      var cur = { t: title ? title.textContent : '', m: meta ? meta.textContent : '', u: (D.querySelector('a[data-ci-fu]') || {}).getAttribute ? D.querySelector('a[data-ci-fu]').getAttribute('href') : '', p: bigImg() ? bigImg().getAttribute('src') : '' };
      var nxt = { t: b.getAttribute('data-t') || '', m: b.getAttribute('data-m') || '', u: b.getAttribute('data-u') || '', p: b.getAttribute('data-p') || '' };
      var img = bigImg();
      if (nxt.p) { if (!img) { img = D.createElement('img'); img.width = 800; img.height = 450; img.decoding = 'async'; big.insertBefore(img, big.firstChild); } img.classList.remove('is-broken'); img.src = nxt.p; img.alt = nxt.t; }
      else if (img) { img.remove(); }
      if (title) title.textContent = nxt.t;
      if (meta) meta.textContent = nxt.m;
      each(D.querySelectorAll('a[data-ci-fu]'), function (a) { a.setAttribute('href', nxt.u); });
      b.setAttribute('data-t', cur.t); b.setAttribute('data-m', cur.m); b.setAttribute('data-u', cur.u); b.setAttribute('data-p', cur.p);
      var mi = b.querySelector('img');
      if (mi && cur.p) { mi.classList.remove('is-broken'); mi.src = cur.p; }
      var lbl = b.getAttribute('aria-label') || ''; if (lbl.indexOf(nxt.t) >= 0) b.setAttribute('aria-label', lbl.split(nxt.t).join(cur.t));
      var mt = b.querySelector('.ci-mini-t > span') || b.querySelector('.ci-mini-t'); if (mt) mt.textContent = cur.t;
      if (!reduce && R.classList.contains('ci-ink-ok')) { big.classList.remove('ci-inkmask', 'is-again'); void big.offsetWidth; big.classList.add('ci-inkmask', 'is-again'); }
      if (!reduce && big.parentNode && big.parentNode.animate) big.parentNode.animate([{ transform: 'translate(-10px, 8px) skewX(-3deg)' }, { transform: 'none' }], { duration: 240, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    });
  }

  /* 15. SFX letters: headings below the fold wait and pop when they come into view */
  if (!reduce && 'IntersectionObserver' in W && R.classList.contains('ci-js')) {
    /* the observer's first report says where each heading is (no getBoundingClientRect, so no forced layout):
       headings below the viewport wait; the rest are left alone. A waiting heading pops when it scrolls in. */
    var io = new IntersectionObserver(function (ents) {
      var vh = W.innerHeight || 800;
      ents.forEach(function (en) {
        var h = en.target;
        if (en.isIntersecting) { if (h.classList.contains('is-wait')) h.classList.remove('is-wait'); io.unobserve(h); }
        /* zero height = inside a content-visibility chapter that is not rendered yet, which is always off-screen: wait too */
        else if (!h.classList.contains('is-wait')) { var r = en.boundingClientRect; if (r.top > vh || r.height === 0) h.classList.add('is-wait'); else io.unobserve(h); }
      });
    }, { rootMargin: '0px 0px -12% 0px' });
    each(D.querySelectorAll('.ci-sfx[data-ci-sfx]:not(.is-whole)'), function (h) { io.observe(h); });
  }

  /* 16. horizontal strips: keyboard focus brings the item into view */
  each(D.querySelectorAll('.ci-chips--scroll, .ci-sorts, .ci-sp-minis, .ci-rail-vp, .ci-umenu-chips'), function (s) {
    s.addEventListener('focusin', function (e) { var t = e.target; if (t && t !== s && t.scrollIntoView) t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); });
  });
})(window, document);
