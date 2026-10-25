/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* Light effects for the JAV themes: scroll reveal, card spotlight, poster tilt, magnetic buttons, split-text heading.
   Pure CSS transforms driven by a few pointer listeners; everything is skipped with prefers-reduced-motion. */
(function () {
  'use strict';
  var doc = document;
  if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* split-text: [data-split] headings fade in word by word (first paint already has the text) */
  function split() {
    Array.prototype.forEach.call(doc.querySelectorAll('[data-split]'), function (el) {
      if (el.dataset.splitDone) return; el.dataset.splitDone = '1';
      var words = el.textContent.trim().split(/\s+/), frag = doc.createDocumentFragment();
      el.setAttribute('aria-label', el.textContent.trim());
      words.forEach(function (w, i) {
        var s = doc.createElement('span'); s.className = 'split-word'; s.setAttribute('aria-hidden', 'true');
        s.style.setProperty('--w', i); s.textContent = w; frag.appendChild(s);
        if (i < words.length - 1) frag.appendChild(doc.createTextNode(' '));
      });
      el.textContent = ''; el.appendChild(frag); el.classList.add('is-split');
    });
  }

  /* reveal: sections below the fold fade up once the visitor starts scrolling */
  function reveal() {
    if (!('IntersectionObserver' in window)) return;
    var armed = false;
    function arm() {
      if (armed) return; armed = true;
      window.removeEventListener('scroll', arm);
      var vh = window.innerHeight;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.remove('is-pending'); io.unobserve(en.target); } });
      }, { rootMargin: '0px 0px -8% 0px' });
      Array.prototype.forEach.call(doc.querySelectorAll('.reveal'), function (el) {
        if (el.getBoundingClientRect().top > vh * 1.05) { el.classList.add('is-pending'); io.observe(el); }
      });
    }
    window.addEventListener('scroll', arm, { passive: true });
  }

  /* spotlight: one delegated listener moves a soft glow under the pointer on cards */
  function spotlight() {
    var frame = 0, last = null;
    doc.addEventListener('pointermove', function (e) {
      last = e;
      if (frame) return;
      frame = requestAnimationFrame(function () {
        frame = 0;
        var card = last.target.closest && last.target.closest('.stui-vodlist__box');
        if (!card) return;
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', (last.clientX - r.left) + 'px');
        card.style.setProperty('--my', (last.clientY - r.top) + 'px');
      });
    }, { passive: true });
  }

  /* tilt: CSS 3D rotation of the detail poster (replaces the three.js depth effect) */
  function tilt() {
    Array.prototype.forEach.call(doc.querySelectorAll('[data-tilt]'), function (el) {
      var frame = 0;
      el.addEventListener('pointermove', function (e) {
        if (frame) return;
        frame = requestAnimationFrame(function () {
          frame = 0;
          var r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
          el.classList.add('is-tilting');
          el.style.setProperty('--ry', (x * 9).toFixed(2) + 'deg');
          el.style.setProperty('--rx', (-y * 7).toFixed(2) + 'deg');
        });
      });
      el.addEventListener('pointerleave', function () {
        el.classList.remove('is-tilting'); el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg');
      });
    });
  }

  /* magnet: buttons lean a few pixels toward the pointer */
  function magnet() {
    Array.prototype.forEach.call(doc.querySelectorAll('[data-magnet]'), function (el) {
      el.addEventListener('pointermove', function (e) {
        var r = el.getBoundingClientRect();
        el.style.setProperty('--mgx', ((e.clientX - r.left - r.width / 2) * 0.18).toFixed(1) + 'px');
        el.style.setProperty('--mgy', ((e.clientY - r.top - r.height / 2) * 0.28).toFixed(1) + 'px');
      });
      el.addEventListener('pointerleave', function () { el.style.setProperty('--mgx', '0px'); el.style.setProperty('--mgy', '0px'); });
    });
  }

  /* count: hero stats count up once (text is final on first paint; tabular figures, so no layout shift).
     Skipped on low-power devices (same flag as the auto-icon loader / FX kit). */
  function count() {
    var root = doc.documentElement;
    if (root.getAttribute('data-ai-power') === 'low' || root.getAttribute('data-fx-power') === 'low') return;
    Array.prototype.forEach.call(doc.querySelectorAll('[data-jm-count]'), function (el) {
      var raw = el.textContent.trim(), end = parseInt(raw.replace(/[^0-9]/g, ''), 10);
      if (!end || end < 10 || !/^[0-9.,\s]+$/.test(raw)) return;
      var t0 = 0, dur = 900;
      el.style.minWidth = el.getBoundingClientRect().width + 'px';
      function step(t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / dur), v = Math.round(end * (1 - Math.pow(1 - k, 3)));
        el.textContent = k < 1 ? String(v) : raw;
        if (k < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }

  function init() {
    split(); reveal(); count();
    if (finePointer) { spotlight(); tilt(); magnet(); }
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();
})();
