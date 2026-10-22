/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS site runtime (SEO-ADMIN-BRIEF F.2, F.3): age gate overlay, cookie notice, release of code held for consent.
   Loaded with defer only when the gate, the notice, the legal row or held code is on the page. Nothing is sent to the server. */
(function (w, d) {
  'use strict';
  var root = d.documentElement;
  var released = false;

  function cookie(name) {
    var m = d.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? m[1] : null;
  }
  function setCookie(name, value, days, path) {
    d.cookie = name + '=' + value + '; Max-Age=' + Math.round(days * 86400) + '; Path=' + (path || '/') + '; SameSite=Lax' +
      (w.location.protocol === 'https:' ? '; Secure' : '');
  }
  function store(value) {
    try {
      if (value === undefined) { return w.localStorage.getItem('jv.consent'); }
      w.localStorage.setItem('jv.consent', value);
    } catch (e) { return null; }
    return value;
  }

  /* Held code: <template data-jv-hold> content goes into the page; scripts are recreated so they run, an external
     script without async is awaited before the next node (keeps "library, then init" pairs working). */
  function insertAll(queue) {
    while (queue.length) {
      var item = queue.shift(), node = item[0], parent = item[1], ref = item[2];
      if (!parent) { continue; }
      if (node.nodeName === 'SCRIPT') {
        var s = d.createElement('script');
        for (var i = 0; i < node.attributes.length; i++) { s.setAttribute(node.attributes[i].name, node.attributes[i].value); }
        if (node.src && !node.hasAttribute('async')) {
          s.async = false;
          s.onload = s.onerror = function () { insertAll(queue); };
          parent.insertBefore(s, ref);
          return;
        }
        if (!node.src) { s.text = node.text; }
        parent.insertBefore(s, ref);
      } else {
        parent.insertBefore(d.importNode(node, true), ref);
      }
    }
  }
  function release() {
    if (released) { return; }
    released = true;
    var list = d.querySelectorAll('template[data-jv-hold]'), queue = [];
    for (var i = 0; i < list.length; i++) {
      var t = list[i], nodes = t.content ? t.content.childNodes : t.childNodes;
      for (var j = 0; j < nodes.length; j++) { queue.push([nodes[j], t.parentNode, t]); }
      t.removeAttribute('data-jv-hold');
    }
    insertAll(queue);
  }
  var chained = false;
  function tellAds() {
    try {
      if (!w.JCX || typeof w.JCX.consent !== 'function') { return; }
      w.JCX.consent(true);
      /* jcx.js resets its flag when its config arrives; tell it again once that config is applied. */
      if (!chained && w.JCX.ready && typeof w.JCX.ready.then === 'function') {
        chained = true;
        w.JCX.ready.then(function () { try { w.JCX.consent(true); } catch (e) {} });
      }
    } catch (e) {}
  }
  function grant() { release(); tellAds(); }

  function accepted() {
    var c = cookie('jv_consent');
    if (c === '1') { return true; }
    if (c === '0') { return false; }
    return store() === '1';
  }

  /* CF16: JCX.consent is an in-page flag of jcx.js, so a stored "yes" is passed on at DOMContentLoaded and again at load. */
  function onReady(fn) {
    if (d.readyState === 'complete') { fn(); return; }
    d.addEventListener('DOMContentLoaded', fn);
    w.addEventListener('load', fn);
  }

  var bar = d.getElementById('jv-cookie');
  var path = (bar && bar.getAttribute('data-path')) || '/';
  if (accepted()) {
    if (cookie('jv_consent') !== '1') { setCookie('jv_consent', '1', 180, path); }
    onReady(grant);
  } else if (bar && cookie('jv_consent') !== '0') {
    bar.hidden = false;
  }
  if (bar) {
    bar.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('[data-jv-consent]') : null;
      if (!b) { return; }
      var v = b.getAttribute('data-jv-consent') === '1' ? '1' : '0';
      store(v);
      setCookie('jv_consent', v, 180, path);
      bar.hidden = true;
      if (v === '1') { grant(); }
    });
  }

  /* Age gate (F.2): the early head script added html.jv-age (no jv_age cookie, not a crawler). */
  var gate = d.getElementById('jv-age');
  if (gate) {
    var enter = gate.querySelector('[data-jv-age]');
    var logo = gate.querySelector('.jv-age-logo');
    if (logo) {
      var hideLogo = function () { logo.style.display = 'none'; };
      logo.addEventListener('error', hideLogo);
      if (logo.complete && !logo.naturalWidth) { hideLogo(); }
    }
    var open = function () { return /(^|\s)jv-age(\s|$)/.test(root.className); };
    if (open() && enter) { try { enter.focus(); } catch (e) {} }
    gate.addEventListener('keydown', function (e) {
      if (!open()) { return; }
      if (e.key === 'Escape') { e.preventDefault(); return; }
      if (e.key !== 'Tab') { return; }
      var f = gate.querySelectorAll('a[href],button');
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && d.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    d.addEventListener('focusin', function (e) {
      if (open() && enter && !gate.contains(e.target)) { enter.focus(); }
    });
    if (enter) {
      enter.addEventListener('click', function () {
        setCookie('jv_age', '1', parseInt(gate.getAttribute('data-days'), 10) || 30, gate.getAttribute('data-path') || '/');
        root.className = root.className.replace(/(^|\s)jv-age(?=\s|$)/g, ' ').replace(/^\s+|\s+$/g, '');
        gate.setAttribute('hidden', '');
      });
    }
  }

  /* For a consent tool of the owner's choice: JV.consent(true) releases the held code and tells the ads. */
  w.JV = w.JV || {};
  w.JV.consent = function (yes) {
    if (!yes) { return; }
    store('1');
    setCookie('jv_consent', '1', 180, path);
    if (bar) { bar.hidden = true; }
    grant();
  };
})(window, document);
