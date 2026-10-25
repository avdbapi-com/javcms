/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*! Honey Nectar (Modern) fanned card stack | original code (JavCMS Community License 1.0) for JavCMS; Swiper 14 (MIT, vendored) cards effect
 * Swipe, drag, arrow keys, the prev/next buttons or a click on a peeking card send the front card to the back.
 * Autoplay (6 s) only on capable desktops with motion allowed; it pauses on hover, focus, off-screen and in hidden tabs,
 * and the pause button toggles it. The copy panel (title, meta, buttons) follows the front card. */
const W = window, D = document, ROOT = D.documentElement;
const VENDOR = new URL('../../../../static/vendor/fx/', import.meta.url).href;

export function init(stack, opts) {
  const swEl = stack.querySelector('.nc-stack-sw');
  const slides = Array.prototype.slice.call(stack.querySelectorAll('.nc-slide'));
  if (!swEl || slides.length < 2) return;
  const reduced = !!opts.reduced, low = !!opts.low;
  const fine = W.matchMedia && W.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const autoOk = !reduced && !low && fine;
  const rtl = (ROOT.getAttribute('dir') || '') === 'rtl';
  const btnPrev = stack.querySelector('[data-nc-stack-prev]'), btnNext = stack.querySelector('[data-nc-stack-next]');
  const btnPause = stack.querySelector('[data-nc-stack-pause]'), count = stack.querySelector('[data-nc-stack-count]');
  const copy = D.querySelector('[data-nc-copy]'), cta = D.querySelector('[data-nc-cta]');
  const label = (k, d) => stack.getAttribute('data-label-' + k) || d;

  return import(VENDOR + 'swiper@14.2.0/swiper-lite.esm.min.js').then((S) => {
    ROOT.classList.add('nc-swiper');
    let userPaused = false, hover = false, focus = false, visible = true;
    const sw = new S.Swiper(swEl, {
      modules: [S.EffectCards, S.Keyboard, S.A11y, S.Autoplay],
      effect: 'cards', grabCursor: true, rewind: true, speed: reduced ? 0 : 620,
      cardsEffect: { slideShadows: false, rotate: true, perSlideRotate: 3, perSlideOffset: 9 },
      keyboard: { enabled: true, onlyInViewport: true },
      a11y: { enabled: true, prevSlideMessage: label('prev', 'Previous'), nextSlideMessage: label('next', 'Next'), slideLabelMessage: '{{index}} / {{slidesLength}}', slideRole: 'group' },
      autoplay: autoOk ? { delay: 6000, disableOnInteraction: false, pauseOnMouseEnter: false } : false,
      slideToClickedSlide: false
    });
    if (!autoOk && sw.autoplay && sw.autoplay.running) sw.autoplay.stop();

    function meta(s) {
      const li = [], esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
      const ico = (id) => '<svg class="nc-i" aria-hidden="true"><use href="' + (W.NC_TPL || '/') + 'assets/icons.svg?v=20261012c#' + id + '"></use></svg>';
      const code = s.getAttribute('data-code'), dur = s.getAttribute('data-dur'), hits = s.getAttribute('data-hits'), score = s.getAttribute('data-score');
      if (code) li.push('<li class="nc-hm-code nc-ltr">' + esc(code) + '</li>');
      if (dur) li.push('<li>' + ico('clock') + '<span class="nc-ltr">' + esc(dur) + '</span></li>');
      const views = (copy && copy.querySelector('[data-nc-f="meta"] .nc-sr')) ? copy.querySelector('[data-nc-f="meta"] .nc-sr').textContent : '';
      li.push('<li>' + ico('eye') + '<span class="nc-ltr">' + esc(hits || '0') + '</span><span class="nc-sr">' + esc(views) + '</span></li>');
      if (score) li.push('<li>' + ico('star') + '<span class="nc-ltr">' + esc(score) + '</span></li>');
      return li.join('');
    }
    function sync() {
      const i = sw.realIndex, s = slides[i];
      slides.forEach((el, k) => { const a = el.querySelector('.nc-slide-card'); if (a) { if (k === i) a.removeAttribute('tabindex'); else a.setAttribute('tabindex', '-1'); } });
      if (count) count.textContent = (i + 1) + ' / ' + slides.length;
      if (!s || !copy) return;
      const title = copy.querySelector('[data-nc-f="title"]'), m = copy.querySelector('[data-nc-f="meta"]');
      if (title) {
        title.textContent = s.getAttribute('data-title') || '';
        const h = title.parentNode;
        if (h && ROOT.classList.contains('nc-melt') && !reduced) { h.classList.remove('is-melted'); void h.offsetWidth; requestAnimationFrame(() => h.classList.add('is-melted')); }
      }
      if (m) m.innerHTML = meta(s);
      const w = D.querySelector('[data-nc-f="watch"]'), d = D.querySelector('[data-nc-f="detail"]');
      if (w) w.setAttribute('href', s.getAttribute('data-watch') || '#');
      if (d) d.setAttribute('href', s.getAttribute('data-detail') || '#');
    }
    sw.on('slideChange', sync);

    /* a click on a peeking (not front) card brings it forward instead of navigating */
    slides.forEach((s, k) => {
      const a = s.querySelector('.nc-slide-card');
      if (a) a.addEventListener('click', (e) => { if (k !== sw.realIndex) { e.preventDefault(); sw.slideTo(k); } });
    });
    if (btnPrev) btnPrev.addEventListener('click', () => sw.slidePrev());
    if (btnNext) btnNext.addEventListener('click', () => sw.slideNext());

    function setAuto() {
      if (!autoOk || !sw.autoplay) return;
      const run = !userPaused && !hover && !focus && visible && !D.hidden;
      if (run && !sw.autoplay.running) sw.autoplay.start();
      else if (!run && sw.autoplay.running) sw.autoplay.stop();
    }
    if (btnPause) {
      if (autoOk) {
        btnPause.hidden = false;
        btnPause.addEventListener('click', () => {
          userPaused = !userPaused;
          btnPause.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
          btnPause.setAttribute('aria-label', btnPause.getAttribute(userPaused ? 'data-label-play' : 'data-label-pause') || '');
          setAuto();
        });
      } else btnPause.hidden = true;
    }
    if (autoOk) {
      const zone = stack.parentNode || stack;
      zone.addEventListener('mouseenter', () => { hover = true; setAuto(); });
      zone.addEventListener('mouseleave', () => { hover = false; setAuto(); });
      zone.addEventListener('focusin', () => { focus = true; setAuto(); });
      zone.addEventListener('focusout', () => { setTimeout(() => { focus = zone.contains(D.activeElement); setAuto(); }, 0); });
      D.addEventListener('visibilitychange', setAuto);
      if ('IntersectionObserver' in W) new IntersectionObserver((es) => { visible = es[0].isIntersecting; setAuto(); }, { threshold: 0.25 }).observe(stack);
      setAuto();
    }
    sync();
    stack.setAttribute('data-nc-ready', '');
    return sw;
  });
}
