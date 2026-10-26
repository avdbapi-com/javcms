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
 * Full rows only. A card grid marked with data-grid-fill never shows a short last row:
 * after layout, cards that would sit in an incomplete final row are hidden, so a row of
 * 5 columns shows 5, 10, 15... cards and a 4-column row shows 4, 8, 12... When every card
 * fits in one row (fewer cards than columns) nothing is hidden. Works with CSS grid or
 * flex-wrap layouts, re-runs on resize and when cards are added (AJAX paging, filters).
 * Opt out per card with data-grid-keep. Theme CSS decides the column count per breakpoint.
 */
(function (window, document) {
  'use strict';
  var HIDDEN = 'data-grid-orphan';

  function cards(grid) {
    var list = [];
    for (var i = 0; i < grid.children.length; i++) {
      var el = grid.children[i];
      if (el.nodeType === 1 && el.tagName !== 'SCRIPT' && el.tagName !== 'TEMPLATE' && !el.hasAttribute('data-grid-keep')) list.push(el);
    }
    return list;
  }

  function columnCount(grid, items) {
    var style = window.getComputedStyle(grid);
    if (style.display.indexOf('grid') !== -1) {
      var tracks = style.gridTemplateColumns.split(' ').filter(function (t) { return t && t !== 'none'; });
      if (tracks.length) return tracks.length;
    }
    // Flex-wrap or block layouts: count cards sharing the first card's top edge.
    var top = null, count = 0;
    for (var i = 0; i < items.length; i++) {
      if (items[i].offsetParent === null && !items[i].hasAttribute(HIDDEN)) continue;
      var y = items[i].getBoundingClientRect().top;
      if (top === null) top = y;
      if (Math.abs(y - top) < 2) count++; else break;
    }
    return Math.max(1, count);
  }

  function fill(grid) {
    var items = cards(grid);
    for (var i = 0; i < items.length; i++) {
      if (items[i].hasAttribute(HIDDEN)) { items[i].removeAttribute(HIDDEN); items[i].style.display = ''; }
    }
    var visible = items.filter(function (el) { return el.offsetParent !== null || window.getComputedStyle(el).display !== 'none'; });
    var cols = columnCount(grid, visible);
    if (cols < 2 || visible.length <= cols) return;
    var keep = visible.length - (visible.length % cols);
    for (var j = keep; j < visible.length; j++) {
      visible[j].setAttribute(HIDDEN, '');
      visible[j].style.display = 'none';
    }
  }

  function run() {
    var grids = document.querySelectorAll('[data-grid-fill]');
    for (var i = 0; i < grids.length; i++) fill(grids[i]);
  }

  var timer = 0;
  function schedule() { window.clearTimeout(timer); timer = window.setTimeout(run, 80); }

  window.TemplateGridFill = { run: run, fill: fill };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  window.addEventListener('load', run);
  window.addEventListener('resize', schedule);
  if (window.MutationObserver) {
    new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        var t = records[i].target;
        if (t.nodeType === 1 && (t.hasAttribute('data-grid-fill') || (t.closest && t.closest('[data-grid-fill]')))) { schedule(); return; }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})(window, document);
