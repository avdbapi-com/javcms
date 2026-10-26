/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* Project-owned color preference. Dark is applied synchronously before styles. */
(function (window, document) {
  'use strict';
  var root = document.documentElement, key = 'javcms.colorMode', current = 'dark';
  function valid(value) { return value === 'light' || value === 'dark'; }
  try { var saved = window.localStorage.getItem(key); if (valid(saved)) current = saved; } catch (error) {}
  function apply(value) {
    current = valid(value) ? value : 'dark';
    root.setAttribute('data-color-mode', current);
    root.style.colorScheme = current;
    var controls = document.querySelectorAll('[data-color-toggle]');
    for (var i = 0; i < controls.length; i++) {
      var button = controls[i], label = button.getAttribute(current === 'dark' ? 'data-light-label' : 'data-dark-label');
      button.setAttribute('aria-label', label); button.setAttribute('title', label);
      var text = button.querySelector('[data-color-label]'); if (text) text.textContent = label;
    }
  }
  apply(current);
  document.addEventListener('DOMContentLoaded', function () { apply(current); });
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest || !target.closest('[data-color-toggle]')) return;
    apply(current === 'dark' ? 'light' : 'dark');
    try { window.localStorage.setItem(key, current); } catch (error) {}
  });
  window.addEventListener('storage', function (event) {
    try { if ((event.key === key || event.key === null) && event.storageArea === window.localStorage) apply(event.newValue); } catch (error) {}
  });
})(window, document);
