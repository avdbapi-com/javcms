/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - page transitions (View Transitions API) | JavCMS Community License 1.0 | original code
 * theme-reveal : data-fx="theme-reveal" on the [data-color-toggle] button (or on <html> for every toggle): the new
 *                colour mode grows as a circle from the click point (same-document view transition).
 * vt           : data-fx="vt" on <html> + <link rel="stylesheet" href=".../fx/fx-vt.css"> on every page:
 *                cross-document transitions (Chrome/Edge 126+, Safari 18.2+); back/forward navigations get the
 *                "back" type so fx-vt.css can slide the other way. Everything else navigates normally (no-op fallback).
 * FX.transition(update) (in fx.js) wraps any same-document DOM change. Reduced motion: no transitions. */
const D = document, ROOT = D.documentElement;
let bypass = false, installed = false;

function themeReveal(ctx) {
  if (installed) return;
  installed = true;
  D.addEventListener('click', function (e) {
    if (bypass || ctx.env.reduced || !D.startViewTransition) return;
    const btn = e.target && e.target.closest ? e.target.closest('[data-color-toggle]') : null;
    if (!btn) return;
    if (!btn.matches('[data-fx~="theme-reveal"]') && !ROOT.matches('[data-fx~="theme-reveal"]')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const r = btn.getBoundingClientRect();
    const x = e.clientX || r.left + r.width / 2, y = e.clientY || r.top + r.height / 2;
    const R = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
    ROOT.style.setProperty('--fx-vt-x', x.toFixed(0) + 'px');
    ROOT.style.setProperty('--fx-vt-y', y.toFixed(0) + 'px');
    ROOT.style.setProperty('--fx-vt-r', Math.ceil(R) + 'px');
    ROOT.setAttribute('data-fx-vt', 'theme');
    let vt;
    try {
      vt = D.startViewTransition(function () { bypass = true; try { btn.click(); } finally { bypass = false; } });
    } catch (err) {
      bypass = true; try { btn.click(); } finally { bypass = false; }
      ROOT.removeAttribute('data-fx-vt');
      return;
    }
    const clean = function () { ROOT.removeAttribute('data-fx-vt'); };
    vt.finished.then(clean, clean);
  }, true);
}

function crossDocument(ctx) {
  /* Direction-aware types for fx-vt.css. pagereveal/pageswap only exist where cross-document VT exists. */
  window.addEventListener('pagereveal', function (e) {
    if (!e.viewTransition) return;
    if (ctx.env.reduced) { e.viewTransition.skipTransition(); return; }
    try {
      const nav = window.navigation, act = nav && nav.activation;
      if (act && act.navigationType === 'traverse' && act.from && act.entry && act.from.index > act.entry.index) e.viewTransition.types.add('back');
      else e.viewTransition.types.add('forward');
    } catch (err) { /* types unsupported: default animation */ }
  });
  window.addEventListener('pageswap', function (e) {
    if (!e.viewTransition) return;
    try {
      const a = e.activation;
      if (a && a.navigationType === 'traverse' && a.from && a.entry && a.from.index > a.entry.index) e.viewTransition.types.add('back');
      else e.viewTransition.types.add('forward');
    } catch (err) { /* ignore */ }
  });
}

export function init(items, ctx) {
  for (let i = 0; i < items.length; i++) {
    if (items[i].name === 'theme-reveal') themeReveal(ctx);
    else if (items[i].name === 'vt') crossDocument(ctx);
    ctx.status(items[i].name, items[i].el, D.startViewTransition ? 'active' : 'static', D.startViewTransition ? '' : 'no-view-transitions');
  }
}
