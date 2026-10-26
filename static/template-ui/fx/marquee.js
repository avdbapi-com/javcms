/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - marquee / ticker | JavCMS Community License 1.0 | original code
 * <div data-fx="marquee" data-fx-speed="50" [data-fx-reverse] [data-fx-axis="y"] [data-fx-pause="none"]> items... </div>
 * One child element (e.g. a <ul>) is duplicated as a whole; several children are wrapped in a group first.
 * Clones are aria-hidden + inert. Pauses on hover/focus and off-screen; RTL runs the other way; reduced motion = scrollable row.
 * Vars: --fx-mq-gap (space between copies, default = the list's own gap or 24px), --fx-mq-fade (edge fade). */
const D = document;

function strip(clone) {
  clone.setAttribute('aria-hidden', 'true');
  clone.setAttribute('inert', '');
  clone.setAttribute('data-fx-clone', '');
  clone.removeAttribute('id');
  const all = clone.querySelectorAll('[id],a,button,input,select,textarea,[tabindex]');
  for (let i = 0; i < all.length; i++) {
    all[i].removeAttribute('id');
    if (all[i].matches('a,button,input,select,textarea,[tabindex]')) all[i].setAttribute('tabindex', '-1');
  }
  return clone;
}

function setup(el, ctx) {
  if (el.querySelector(':scope > .fx-mq-track')) return;
  const axis = ctx.attr(el, 'axis', 'x') === 'y' ? 'y' : 'x';
  const speed = Math.max(5, ctx.num(el, 'speed', 50));
  const track = D.createElement('div');
  track.className = 'fx-mq-track';
  const kids = el.children;
  let group;
  if (kids.length === 1) {
    group = kids[0];
  } else {
    group = D.createElement('div');
    group.className = 'fx-mq-group';
    group.style.display = 'flex';
    group.style.flexDirection = axis === 'y' ? 'column' : 'row';
    group.style.alignItems = axis === 'y' ? 'stretch' : 'center';
    const cs = getComputedStyle(el);
    group.style.gap = cs.display.indexOf('flex') > -1 || cs.display.indexOf('grid') > -1 ? cs.gap : '';
    while (el.firstChild) group.appendChild(el.firstChild);
  }
  if (!ctx.css(el, '--fx-mq-gap', '')) {
    const g = getComputedStyle(group)[axis === 'y' ? 'rowGap' : 'columnGap'];
    track.style.setProperty('--fx-mq-gap', g && g !== 'normal' && parseFloat(g) ? g : '24px');
  }
  el.appendChild(track);
  track.appendChild(group);
  const clones = [];

  function layout() {
    const size = axis === 'y' ? group.getBoundingClientRect().height : group.getBoundingClientRect().width;
    const box = axis === 'y' ? el.clientHeight : el.clientWidth;
    if (!size) return;
    const need = Math.max(1, Math.ceil(box / size));
    while (clones.length < need) { const c = strip(group.cloneNode(true)); clones.push(c); track.appendChild(c); }
    while (clones.length > need) clones.pop().remove();
    track.style.setProperty('--fx-mq-w', size.toFixed(2) + 'px');
    track.style.setProperty('--fx-mq-dur', (size / speed).toFixed(2) + 's');
  }
  layout();
  el.setAttribute('data-fx-mq', 'run');
  ctx.status('marquee', el, 'active');
  if ('ResizeObserver' in window) {
    let t = 0, last = 0;
    new ResizeObserver(function () {
      const w = axis === 'y' ? el.clientHeight : el.clientWidth;
      if (Math.abs(w - last) < 2) return;
      last = w;
      clearTimeout(t); t = setTimeout(layout, 120);
    }).observe(el);
  }
  if (D.fonts && D.fonts.ready) D.fonts.ready.then(layout);
}

export function init(items, ctx) {
  for (let i = 0; i < items.length; i++) setup(items[i].el, ctx);
}
