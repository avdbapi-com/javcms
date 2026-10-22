/* JavCMS example X19: a harmless local script that fills its 300 x 250 box the way an ad network's code does
 * (here: a creative of the fictional advertiser Cobaltnode). No network requests, no images, no cookies, no storage.
 * The click URL comes from data-url on the script tag ({home} in the example), else the site root. */
(function () {
  'use strict';
  var s = document.currentScript;
  if (!s || !s.parentNode) return;
  var href = s.getAttribute('data-url') || '';
  if (!/^https?:\/\//i.test(href)) { try { href = new URL('../../../', s.src).href; } catch (e) { href = '/'; } }
  var FONT = "system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
  function el(tag, css, text) {
    var e = document.createElement(tag);
    if (css) e.style.cssText = css;
    if (text) e.textContent = text;
    return e;
  }
  function art(css, markup) { var e = el('span', css); e.innerHTML = markup; return e; }
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var box = el('a', 'box-sizing:border-box;position:relative;display:block;width:300px;max-width:100%;height:250px;margin:0 auto;padding:0;overflow:hidden;' +
    'border:0;border-radius:4px;background:linear-gradient(100deg,#06143a 0%,#0c2a7a 55%,#1a4cc4 100%);color:#fff;text-decoration:none;text-align:start;' +
    'font:500 12px/1.3 ' + FONT + ';letter-spacing:0;text-transform:none;isolation:isolate');
  box.href = href; box.target = '_blank'; box.rel = 'sponsored noopener';
  box.setAttribute('aria-label', 'Cobaltnode: stream from 120 edge cities. Unmetered video CDN, flat $9 a month. Start free');
  // faint grid + hairline
  box.appendChild(el('span', 'position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.06) 1px,transparent 1px);background-size:20px 20px;' +
    '-webkit-mask-image:linear-gradient(100deg,transparent 30%,#000 80%);mask-image:linear-gradient(100deg,transparent 30%,#000 80%)'));
  box.appendChild(el('span', 'position:absolute;inset:0;border-radius:4px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.14);pointer-events:none;z-index:3'));

  // edge map: a few cyan nodes linked to the server stack
  var nodes = [[210, 58], [262, 44], [284, 92], [232, 118], [180, 96], [272, 148]];
  var lines = nodes.map(function (n) { return '<path d="M234 170L' + n[0] + ' ' + n[1] + '" stroke="#5ee7ff" stroke-opacity=".28" stroke-width="1"/>'; }).join('');
  var dots = nodes.map(function (n) { return '<circle cx="' + n[0] + '" cy="' + n[1] + '" r="2.6" fill="#5ee7ff"/><circle cx="' + n[0] + '" cy="' + n[1] + '" r="6" fill="#5ee7ff" fill-opacity=".18"/>'; }).join('');
  var map = art('position:absolute;inset:0;display:block;pointer-events:none', '<svg viewBox="0 0 300 250" width="300" height="250" aria-hidden="true" focusable="false" style="display:block;width:100%;height:100%">' + lines + dots + '</svg>');
  box.appendChild(map);
  // isometric server blades (the kit's "servers" hero, ids prefixed)
  box.appendChild(art('position:absolute;right:-6px;bottom:22px;width:150px;height:150px;display:block;pointer-events:none',
    '<svg viewBox="0 0 100 100" width="150" height="150" aria-hidden="true" focusable="false" style="display:block;width:100%;height:100%"><defs>' +
    '<linearGradient id="cbn19t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#b8f4ff"/><stop offset=".45" stop-color="#4aa8ff"/><stop offset="1" stop-color="#1f55d6"/></linearGradient></defs>' +
    '<ellipse cx="50" cy="90" rx="36" ry="6" fill="#5ee7ff" opacity=".22"/>' +
    [0, 1, 2].map(function (i) {
      var y = 70 - i * 17;
      return '<polygon points="10,' + y + ' 50,' + (y + 14) + ' 50,' + (y + 23) + ' 10,' + (y + 9) + '" fill="#0c2f86"/>' +
        '<polygon points="50,' + (y + 14) + ' 90,' + y + ' 90,' + (y + 9) + ' 50,' + (y + 23) + '" fill="#1550cc"/>' +
        '<polygon points="10,' + y + ' 50,' + (y - 14) + ' 90,' + y + ' 50,' + (y + 14) + '" fill="url(#cbn19t)" opacity="' + (i === 2 ? 1 : 0.9) + '"/>' +
        '<path d="M56 ' + (y + 15.5) + 'l28-10" stroke="#5ee7ff" stroke-width="1.6" stroke-linecap="round" opacity=".9"/>' +
        '<circle cx="18" cy="' + (y + 6) + '" r="1.4" fill="#5ee7ff"/><circle cx="23" cy="' + (y + 7.8) + '" r="1.4" fill="#9ff3ff"/>';
    }).join('') + '</svg>'));

  // copy column
  var col = el('span', 'position:absolute;left:16px;top:14px;bottom:16px;width:178px;display:flex;flex-direction:column;align-items:flex-start;z-index:2');
  var lock = el('span', 'display:flex;align-items:center;gap:7px;line-height:1');
  lock.appendChild(art('display:block;width:22px;height:22px;flex:none', '<svg viewBox="0 0 48 48" width="22" height="22" aria-hidden="true" focusable="false" style="display:block"><path d="M24 3l19 11v20L24 45 5 34V14z" fill="#1846b8"/><path d="M24 3l19 11-19 11L5 14z" fill="#5ee7ff"/><path d="M24 25v20L5 34V14z" fill="#0d2c7a"/><circle cx="24" cy="14" r="3.5" fill="#fff"/></svg>'));
  lock.appendChild(el('span', 'font:700 15px/1 ' + FONT + ';letter-spacing:-.01em;color:#fff;white-space:nowrap', 'Cobaltnode'));
  col.appendChild(lock);
  col.appendChild(el('span', 'display:inline-block;margin-top:16px;padding:5px 8px;border-radius:6px;background:rgba(94,231,255,.14);color:#9ff3ff;font:800 9.5px/1 ' + FONT + ';letter-spacing:.14em;text-transform:uppercase;white-space:nowrap', 'Video CDN'));
  col.appendChild(el('span', 'display:block;margin-top:9px;font:700 23px/1.06 ' + FONT + ';letter-spacing:-.02em;color:#fff', 'Stream from 120 edge cities.'));
  col.appendChild(el('span', 'display:block;margin-top:8px;font:500 12px/1.3 ' + FONT + ';color:#b4c8ff', 'Unmetered video CDN, flat $9/mo.'));
  var cta = el('span', 'display:inline-flex;align-items:center;gap:6px;margin-top:auto;height:34px;padding:0 15px 0 17px;border-radius:999px;background:#5ee7ff;color:#04203a;font:800 13px/1 ' + FONT + ';white-space:nowrap;box-shadow:0 6px 16px -6px rgba(0,0,0,.5)', 'Start free');
  cta.appendChild(art('display:block;width:13px;height:13px', '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true" focusable="false" style="display:block"><path d="M3 8h9M8.5 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'));
  col.appendChild(cta);
  box.appendChild(col);
  s.parentNode.insertBefore(box, s);

  // a slow pulse on the edge nodes (Web Animations API; never with reduced motion)
  if (!reduce && map.animate) {
    [].forEach.call(map.querySelectorAll('circle[r="6"]'), function (c, i) {
      try { c.animate([{ opacity: 0.2 }, { opacity: 1 }, { opacity: 0.2 }], { duration: 2400, delay: i * 400, iterations: 4, easing: 'ease-in-out' }); } catch (e) { }
    });
  }
})();
