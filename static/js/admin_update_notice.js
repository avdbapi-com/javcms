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
 * Admin shell: "update available" notice (JavCMS 1.2.1). A few seconds after the shell has loaded it asks update/notice;
 * the server checks GitHub at most every 12 hours and only while "Check for updates automatically" is on (otherwise it
 * just reports the last check). Nothing is installed without the founder admin. Only the founder sees the Updates item,
 * so other admins never run this request.
 * The notice shows on the Updates item, on the header of its menu group (visible while the group is folded) and on the
 * to-do bell of the top bar (the Overview lists it under "Needs your attention"; the server counts it there already when
 * it knew about the release when the shell was built: data-update="1").
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var url = script ? script.getAttribute('data-url') : '';
  var item = document.querySelector('#navBar .admin-nav-item[data-id="n116"]');
  if (!url || !item || !window.fetch) { return; }
  var cfg = window.AX_SHELL || {};
  function badge(host, text, title, inline) {
    var old = host.querySelector(':scope > .studio-badge[data-upd]');
    if (old) { old.parentNode.removeChild(old); }
    if (!text) { return; }
    var b = document.createElement('span');
    b.className = 'studio-badge';
    b.setAttribute('data-upd', '1');
    b.setAttribute('aria-hidden', 'true');
    b.textContent = text;
    b.title = title;
    if (inline) {
      // a group header lays its children out in a row: the badge sits before the chevron
      b.style.position = 'static';
      b.style.marginLeft = 'auto';
      var chev = host.querySelector('.studio-group__chev');
      host.insertBefore(b, chev);
    } else {
      host.appendChild(b);
    }
  }
  function apply(d) {
    var label = d && d.available ? (d.label || d.latest || '') : '';
    badge(item, label ? '↑' : '', label, false);
    var base = item.getAttribute('data-title') || '';
    if (label) { item.setAttribute('aria-label', base + ' (' + label + ')'); } else if (base) { item.setAttribute('aria-label', base); }
    var group = item.closest('.studio-group');
    var head = group ? group.querySelector('.studio-group__head') : null;
    if (head) {
      badge(head, label ? '↑' : '', label, true);
      var gl = (head.querySelector('.studio-group__label') || head).textContent;
      head.setAttribute('aria-label', label ? gl + ' (' + label + ')' : gl);
    }
    var bell = document.getElementById('shellBell');
    if (bell && label && bell.getAttribute('data-update') !== '1') {
      var n = (parseInt(bell.getAttribute('data-count') || '0', 10) || 0) + 1;
      bell.setAttribute('data-count', String(n));
      bell.setAttribute('data-update', '1');
      var old = bell.querySelector('.studio-badge');
      if (!old) {
        old = document.createElement('span');
        old.className = 'studio-badge';
        old.setAttribute('aria-hidden', 'true');
        bell.appendChild(old);
      }
      old.textContent = n > 99 ? '99+' : String(n);
      var al = (bell.getAttribute('aria-label') || '').replace(/\s*\(\d+\)\s*$/, '');
      bell.setAttribute('aria-label', al + ' (' + n + ')');
      bell.title = label;
    }
  }
  setTimeout(function () {
    var body = new URLSearchParams();
    body.append('__token__', cfg.csrf || '');
    fetch(url, {method: 'POST', credentials: 'same-origin', body: body,
      headers: {'X-CSRF-Token': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.code === 1) { apply(j.data); } })
      .catch(function () { /* the notice is optional */ });
  }, 4000);
})();
