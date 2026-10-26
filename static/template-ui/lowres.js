/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*!
 * JavCMS shared display helper (xvid scale build, brief 2.11 rule 2; THM 5.2). Source-agnostic, no server cost.
 * A theme marks a large picture frame (hero, detail cover, slider slide) with the attribute data-lowres; the frame holds
 * an <img>. When the picture would be shown more than 1.5x its natural width, the frame gets the class is-lowres:
 * lowres.css then draws the same picture blurred behind it and shows the real picture contained at <= 1.5x.
 * <img data-lowres> on the picture itself works too (its parent element is the frame).
 */
(function () {
  'use strict';
  var RATIO = 1.5;
  var doc = document;

  function frameOf(img) {
    if (!img || !img.closest) { return null; }
    var frame = img.closest('[data-lowres]');
    if (frame === img) { frame = img.parentElement; }
    return frame;
  }

  function cssUrl(src) {
    return 'url("' + String(src).replace(/["\\\r\n]/g, function (c) { return encodeURIComponent(c); }) + '")';
  }

  function check(img) {
    var frame = frameOf(img);
    if (!frame) { return; }
    var nw = img.naturalWidth || 0;
    var fw = frame.clientWidth || 0;
    if (!nw || !fw) { return; }
    if (nw * RATIO < fw) {
      frame.style.setProperty('--jv-lowres-w', Math.round(nw * RATIO) + 'px');
      var src = img.currentSrc || img.src;
      if (src) { frame.style.setProperty('--jv-lowres-bg', cssUrl(src)); }
      if (window.getComputedStyle && getComputedStyle(frame).position === 'static') { frame.style.position = 'relative'; }
      if (!frame.classList.contains('is-lowres')) { frame.classList.add('is-lowres'); }
    } else if (frame.classList.contains('is-lowres')) {
      frame.classList.remove('is-lowres');
    }
  }

  // naturalWidth alone decides (a lazy picture already in the memory cache has a size but complete === false).
  // slider slides are often display:none (width 0) when their picture loads: a frame that changes size (a slide shown,
  // a layout change) is checked again
  var observer = window.ResizeObserver ? new ResizeObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      // add only: a frame sized by its picture shrinks when is-lowres applies, which must not undo it
      if (entries[i].target.classList.contains('is-lowres')) { continue; }
      var imgs = entries[i].target.querySelectorAll('img');
      for (var j = 0; j < imgs.length; j++) {
        if (imgs[j].naturalWidth && frameOf(imgs[j]) === entries[i].target) { check(imgs[j]); }
      }
    }
  }) : null;
  var watched = [];
  // a slide that scrolls or fades into view is checked when it becomes visible (its picture may have loaded while hidden)
  var seen = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      var img = entries[i].target;
      var fr = frameOf(img);
      if (entries[i].isIntersecting && img.naturalWidth && fr && !fr.classList.contains('is-lowres')) { check(img); }
    }
  }) : null;

  // a slider that rewrites its slides' class attribute drops is-lowres: put it back when it still applies
  var classWatch = window.MutationObserver ? new MutationObserver(function (records) {
    for (var i = 0; i < records.length; i++) {
      var fr = records[i].target;
      if (fr.classList.contains('is-lowres')) { continue; }
      var imgs = fr.querySelectorAll('img');
      for (var j = 0; j < imgs.length; j++) {
        if (imgs[j].naturalWidth && frameOf(imgs[j]) === fr) { check(imgs[j]); }
      }
    }
  }) : null;

  function scan() {
    var list = doc.querySelectorAll('[data-lowres] img, img[data-lowres]');
    for (var i = 0; i < list.length; i++) {
      var frame = frameOf(list[i]);
      if (observer && frame && watched.indexOf(frame) < 0) { watched.push(frame); observer.observe(frame); if (seen) { seen.observe(list[i]); } if (classWatch) { classWatch.observe(frame, { attributes: true, attributeFilter: ['class'] }); } }
      if (list[i].naturalWidth) { check(list[i]); }
    }
  }

  // every later picture load (lazy images, slider slides added by script) reaches this capture listener
  doc.addEventListener('load', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && frameOf(t)) { check(t); }
  }, true);

  var timer = 0;
  window.addEventListener('resize', function () {
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(scan, 150);
  });

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
  // sliders clone their slides after load (the clone of a cached picture may never fire load): look again a little later
  window.addEventListener('load', function () { scan(); setTimeout(scan, 1000); setTimeout(scan, 3000); });
  window.JvLowres = { check: check, scan: scan, ratio: RATIO };
})();
