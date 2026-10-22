/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - skeleton shimmer helper | JavCMS Community License 1.0 | original code
 * <div class="thumb" data-fx="skeleton" [data-fx-fade]><img loading="lazy" src="..." alt=""></div>
 * The shimmer (CSS ::before, transform-only, below the image) runs until the image loads or fails; then
 * data-fx-loaded="ok|error" is set (and the image fades in when data-fx-fade is present).
 * Placeholder blocks without an image: class="fx-skel" (+ fx-skel-line / fx-skel-circle). */
const D = document;
let installed = false;

function mark(host, state) {
  if (host.getAttribute('data-fx-loaded') !== state) host.setAttribute('data-fx-loaded', state);
}
function check(host) {
  const img = host.querySelector('img');
  if (!img) return;
  if (img.complete) {
    if (img.naturalWidth) mark(host, 'ok');
    else if (img.getAttribute('src')) mark(host, 'error');
  }
}
function onEvent(e) {
  const t = e.target;
  if (!t || t.tagName !== 'IMG') return;
  const host = t.closest('[data-fx~="skeleton"]');
  if (host) mark(host, e.type === 'error' ? 'error' : 'ok');
}

export function init(items, ctx) {
  if (!installed) {
    installed = true;
    D.addEventListener('load', onEvent, true);
    D.addEventListener('error', onEvent, true);
  }
  for (let i = 0; i < items.length; i++) check(items[i].el);
  ctx.status('skeleton', null, 'active', items.length + ' hosts');
}
