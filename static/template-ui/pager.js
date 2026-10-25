/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*
 * JavCMS shared pager (NOLIMIT D1, lane T): progressive enhancement of the "Go to page" form that
 * AvdbLanguage::pagerMoreHtml() prints under the theme's pager.
 * Without JavaScript the form still works (GET ?page=N on the list's first page) and the jump links
 * (-1000 ... +1000) reach any page in a few clicks. With JavaScript the form opens the theme's own page
 * address: the PAGELINK pattern in data-pattern (same rule as mac_url_page: page 1 drops "<sp>PAGELINK").
 * Typed digits may be Arabic-Indic, Persian, Devanagari, Thai or full-width; separators are ignored.
 */
(function () {
  'use strict';

  var ZEROS = [0x0660, 0x06F0, 0x0966, 0x0E50, 0xFF10];

  function digits(text) {
    var out = '';
    var s = String(text || '');
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i);
      if (c >= 48 && c <= 57) { out += s.charAt(i); continue; }
      for (var z = 0; z < ZEROS.length; z++) {
        if (c >= ZEROS[z] && c <= ZEROS[z] + 9) { out += String(c - ZEROS[z]); break; }
      }
    }
    return out;
  }

  function build(pattern, sp, n) {
    var url = pattern.split(sp + 'PAGELINK').join(n > 1 ? sp + n : '');
    return url.split('PAGELINK').join(String(n));
  }

  function sameSite(url) {
    if (/^\/(?![\/\\])/.test(url)) { return true; }
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  function init(form) {
    if (form.getAttribute('data-pg-ready') === '1') { return; }
    var pattern = form.getAttribute('data-pattern') || '';
    var sp = form.getAttribute('data-sp') || '';
    var max = parseInt(form.getAttribute('data-max'), 10) || 1;
    var input = form.querySelector('input[name="page"]');
    if (!input || pattern.indexOf('PAGELINK') < 0 || !sameSite(pattern)) { return; }
    form.setAttribute('data-pg-ready', '1');
    // text + inputmode keeps the numeric keypad and accepts the visitor's own digits
    input.setAttribute('type', 'text');
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'off');
    input.removeAttribute('step');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var n = parseInt(digits(input.value), 10);
      if (!(n >= 1)) {
        input.value = '';
        input.focus();
        return;
      }
      if (n > max) { n = max; }
      var target = build(pattern, sp, n);
      if (sameSite(target)) { window.location.assign(target); }
    });
  }

  function run() {
    var forms = document.querySelectorAll('form.jv-pg-go[data-pattern]');
    for (var i = 0; i < forms.length; i++) { init(forms[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
}());
