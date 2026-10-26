/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/*! JavCMS FX kit - reveal / stagger | JavCMS Community License 1.0 | original code
 * data-fx="reveal"  : the element fades/slides in when it enters the viewport (once; data-fx-repeat to replay)
 * data-fx="stagger" : its children reveal one after another (AJAX-added children are handled too)
 * data-fx-style     : up (default) | down | start | end | fade | scale | zoom | blur | clip | flip  (combinable: "blur scale")
 * CSS vars          : --fx-reveal-dist (28px) --fx-reveal-dur (.9s) --fx-reveal-ease --fx-stagger (70ms on the group) */
const D = document;

export function init(items, ctx) {
  const pre = D.documentElement.classList.contains('fx-js');
  let batch = [], raf = 0;

  function queue(t) {
    batch.push(t);
    if (!raf) raf = requestAnimationFrame(function () { raf = 0; const b = batch; batch = []; play(b); });
  }

  function play(list) {
    list.sort(function (a, b) {
      if (a.el === b.el) return 0;
      return a.el.compareDocumentPosition(b.el) & 4 ? -1 : 1;
    });
    const steps = new Map();
    for (let i = 0; i < list.length; i++) {
      const t = list[i], el = t.el;
      const key = t.group || D;
      const n = steps.get(key) || 0;
      steps.set(key, n + 1);
      const stagger = t.group ? groupStagger(t.group) : 90;
      el.style.setProperty('--fx-delay', (Math.min(n, 12) * stagger) + 'ms');
      el.removeAttribute('data-fx-wait');
      el.setAttribute('data-fx-in', 'run');
      finish(el, t);
    }
  }

  const staggerCache = new WeakMap();
  function groupStagger(g) {
    let v = staggerCache.get(g);
    if (v == null) {
      const raw = ctx.css(g, '--fx-stagger', '70ms');
      v = parseFloat(raw) * (/[^m]s\s*$/.test(raw) ? 1000 : 1);
      if (!isFinite(v)) v = 70;
      staggerCache.set(g, v);
    }
    return v;
  }

  function finish(el, t) {
    let doneFlag = false;
    const end = function (e) {
      if (e && (e.target !== el || String(e.animationName).indexOf('fx-reveal') !== 0)) return;
      if (doneFlag) return;
      doneFlag = true;
      el.removeEventListener('animationend', end);
      el.setAttribute('data-fx-in', 'done');
      el.style.removeProperty('--fx-delay');
      if (t.repeat) watch(t);
    };
    el.addEventListener('animationend', end);
    setTimeout(end, 3200);
  }

  function watch(t) {
    const el = t.el;
    let seen = el.getAttribute('data-fx-in') === 'done';
    const stop = ctx.inView(el, function (v) {
      if (v && !seen) { seen = true; if (!t.repeat) stop(); queue(t); }
      else if (!v && seen && t.repeat && el.getAttribute('data-fx-in') === 'done') {
        seen = false;
        el.removeAttribute('data-fx-in');
        if (!pre) el.setAttribute('data-fx-wait', '');
      }
    }, '0px 0px -8% 0px');
  }

  function classify(targets) {
    const vh = window.innerHeight || D.documentElement.clientHeight;
    const rects = targets.map(function (t) { return t.el.getBoundingClientRect(); });
    const now = [];
    for (let i = 0; i < targets.length; i++) {
      const t = targets[i], el = t.el, r = rects[i];
      if (el.hasAttribute('data-fx-in')) continue; // late / static / already done
      const visible = (r.width || r.height) && r.bottom > 0 && r.top < vh;
      if (visible) {
        if (pre) now.push(t);
        else { el.setAttribute('data-fx-in', 'done'); if (t.repeat) watch(t); }
      } else {
        if (!pre) el.setAttribute('data-fx-wait', '');
        watch(t);
      }
    }
    if (now.length) requestAnimationFrame(function () { play(now); });
  }

  const targets = [];
  for (let i = 0; i < items.length; i++) {
    const el = items[i].el;
    const repeat = el.hasAttribute('data-fx-repeat');
    if (items[i].name === 'stagger') {
      el.setAttribute('data-fx-in', 'group');
      for (let c = 0; c < el.children.length; c++) targets.push({ el: el.children[c], group: el, repeat: repeat });
      if ('MutationObserver' in window) {
        new MutationObserver(function (recs) {
          const added = [];
          for (let r = 0; r < recs.length; r++) {
            for (let k = 0; k < recs[r].addedNodes.length; k++) {
              const n = recs[r].addedNodes[k];
              if (n.nodeType === 1 && n.parentNode === el && !n.hasAttribute('data-fx-in')) added.push({ el: n, group: el, repeat: repeat });
            }
          }
          if (added.length) classify(added);
        }).observe(el, { childList: true });
      }
    } else {
      targets.push({ el: el, group: null, repeat: repeat });
    }
    ctx.status(items[i].name, el, 'active');
  }
  classify(targets);
}
