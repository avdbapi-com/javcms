/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*! JavCMS FX kit - text effects | JavCMS Community License 1.0 | original code
 * split    : data-fx="split" data-fx-by="words|chars|lines" data-fx-style="rise|blur|mask|flip|scale|fade|wave" data-fx-trigger="view|load" [data-fx-restore]
 * scramble : data-fx="scramble" data-fx-chars="auto|blocks|binary|symbols|<custom>" data-fx-duration="1.2" data-fx-trigger="view|hover|load"
 * rotate   : data-fx="rotate" data-fx-words="faster|hotter|newer" data-fx-interval="2600" data-fx-style="slide|blur|flip"
 * type     : data-fx="type" data-fx-words="one|two|three" data-fx-speed="55"
 * count    : data-fx="count" data-fx-to="523000" data-fx-from="0" data-fx-decimals="0" data-fx-duration="1.8" data-fx-notation="standard|compact"
 * glitch   : data-fx="glitch" data-fx-trigger="auto|hover"
 * Screen readers always get the real text (visually hidden copy); animated copies are aria-hidden. */
const D = document;
const COMPLEX = /[\u0590-\u08ff\u0900-\u0dff\u0e00-\u0eff\u1000-\u109f\u1780-\u17ff\ufb1d-\ufdff\ufe70-\ufeff]/;
const INTERACTIVE = 'a,button,input,select,textarea,[tabindex],[contenteditable]';
const RTL_CH = /[\u0590-\u08ff\ufb1d-\ufdff\ufe70-\ufeff]/;
const LTR_CH = /[A-Za-z\u00c0-\u024f\u0370-\u052f\u0900-\u0dff\u0e00-\u0eff\u1e00-\u1fff\u3040-\u30ff\u3400-\u9fff\uac00-\ud7af]/;
/* Direction of the first strong character ('' when the text has none, e.g. digits only). */
function strongDir(text) {
  for (let i = 0; i < text.length; i++) {
    const ch = text.charAt(i);
    if (RTL_CH.test(ch)) return 'rtl';
    if (LTR_CH.test(ch)) return 'ltr';
  }
  return '';
}

function span(cls, text) {
  const s = D.createElement('span');
  if (cls) s.className = cls;
  if (text != null) s.textContent = text;
  return s;
}
let segmenter = null;
/* Intl.Segmenter is only needed beyond Latin (emoji, combining marks, CJK...); its first use costs ~20 ms on a slow phone. */
function simple(text) {
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    if (c > 0x24f || (c >= 0x300 && c <= 0x36f)) return false;
  }
  return true;
}
function graphemes(text) {
  if (simple(text)) return Array.from(text);
  if (segmenter === null) {
    try { segmenter = Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : false; } catch (e) { segmenter = false; }
  }
  if (segmenter) { const out = []; const it = segmenter.segment(text); for (const s of it) out.push(s.segment); return out; }
  return Array.from(text);
}
function textNodes(root) {
  const out = [], walker = D.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode: function (n) {
      const p = n.parentNode;
      if (!p || p.closest('script,style,svg,textarea,.fx-sr,.fx-gl')) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  let n;
  while ((n = walker.nextNode())) out.push(n);
  return out;
}
/* Wrap children in an aria-hidden layer next to a visually hidden copy of the text (only if nothing focusable inside). */
function accessibleLayer(el) {
  if (el.querySelector(INTERACTIVE)) return el;
  const sr = span('fx-sr', el.textContent.replace(/\s+/g, ' ').trim());
  const vis = span('fx-vis');
  vis.setAttribute('aria-hidden', 'true');
  while (el.firstChild) vis.appendChild(el.firstChild);
  el.appendChild(sr);
  el.appendChild(vis);
  return vis;
}
function unlayer(el) {
  const vis = el.querySelector(':scope > .fx-vis'), sr = el.querySelector(':scope > .fx-sr');
  if (!vis) return;
  while (vis.firstChild) el.insertBefore(vis.firstChild, vis);
  vis.remove();
  if (sr) sr.remove();
}
function inViewport(el) {
  const r = el.getBoundingClientRect(), vh = window.innerHeight || D.documentElement.clientHeight;
  return (r.width || r.height) && r.bottom > 0 && r.top < vh;
}
function whenSeen(el, ctx, fn, margin) {
  const trig = ctx.attr(el, 'trigger', 'view');
  if (trig === 'load') { fn(); return; }
  let fired = false;
  const stop = ctx.inView(el, function (v) { if (v && !fired) { fired = true; stop(); fn(); } }, margin || '0px 0px -10% 0px');
}

/* Inline-block pieces are neutral for the bidi algorithm, so a Latin run inside an RTL heading (or the reverse)
   would be laid out backwards. Runs whose direction differs from the element are wrapped in an inline embedding
   (direction + unicode-bidi: embed, not an isolate, so the run still joins neighbouring text of the same direction). */
function isolateRuns(frag, base) {
  const kids = Array.prototype.slice.call(frag.childNodes);
  let run = null, runDir = '', last = base;
  const flush = function () {
    if (run && run.length && runDir && runDir !== base) {
      while (run.length && run[run.length - 1].nodeType === 3) run.pop();
      if (run.length) {
        const wrap = span('fx-run');
        wrap.style.direction = runDir;
        wrap.style.unicodeBidi = 'embed';
        frag.insertBefore(wrap, run[0]);
        for (let k = 0; k < run.length; k++) wrap.appendChild(run[k]);
      }
    }
    run = null; runDir = '';
  };
  for (let k = 0; k < kids.length; k++) {
    const n = kids[k];
    if (n.nodeType === 3) { if (run) run.push(n); continue; }
    const d = n.__fxDir || last;
    last = d;
    if (d !== runDir) { flush(); run = [n]; runDir = d; } else run.push(n);
  }
  flush();
}

/* ------------------------------------------------------------ split */
function split(el, ctx, visibleAtStart) {
  if (el.hasAttribute('data-fx-split')) return;
  if (el.getAttribute('data-fx-in') === 'late' || (!ctx.pre && visibleAtStart)) {
    el.setAttribute('data-fx-in', 'static');
    ctx.status('split', el, 'static', 'in-view-at-start');
    return;
  }
  let by = ctx.attr(el, 'by', 'words');
  const txt = el.textContent;
  if (by === 'chars' && COMPLEX.test(txt)) by = 'words';
  const root = by === 'chars' ? accessibleLayer(el) : el;
  const pieces = [], restore = [];
  const base = ctx.rtl(el) ? 'rtl' : 'ltr';
  let i = 0;
  const nodes = textNodes(root);
  for (let n = 0; n < nodes.length; n++) {
    const tn = nodes[n], parts = tn.data.split(/(\s+)/);
    if (!tn.data.trim()) continue;
    const frag = D.createDocumentFragment(), made = [];
    for (let p = 0; p < parts.length; p++) {
      const part = parts[p];
      if (!part) continue;
      if (/^\s+$/.test(part)) { const t = D.createTextNode(part); frag.appendChild(t); made.push(t); continue; }
      const w = span('fx-w');
      if (by === 'chars') {
        const gs = graphemes(part);
        for (let g = 0; g < gs.length; g++) { const c = span('fx-p', gs[g]); c.style.setProperty('--i', i++); w.appendChild(c); pieces.push(c); }
      } else {
        const c = span('fx-p', part); c.style.setProperty('--i', i++); w.appendChild(c); pieces.push(c);
      }
      w.__fxDir = strongDir(part);
      frag.appendChild(w); made.push(w);
    }
    isolateRuns(frag, base);
    restore.push({ orig: tn, made: Array.prototype.slice.call(frag.childNodes) });
    tn.parentNode.replaceChild(frag, tn);
  }
  if (by === 'lines' && pieces.length) {
    const tops = pieces.map(function (p) { return p.offsetTop; });
    let line = -1, last = null;
    for (let k = 0; k < pieces.length; k++) {
      if (last === null || Math.abs(tops[k] - last) > 2) { line++; last = tops[k]; }
      pieces[k].style.setProperty('--i', line);
    }
    el.style.setProperty('--fx-split-stagger', ctx.attr(el, 'stagger', '120ms'));
  }
  el.setAttribute('data-fx-split', 'wait');
  el.setAttribute('data-fx-in', 'split');
  ctx.status('split', el, 'active', by);
  whenSeen(el, ctx, function () {
    el.setAttribute('data-fx-split', 'run');
    const cs = getComputedStyle(pieces[pieces.length - 1] || el);
    const dur = (parseFloat(cs.animationDuration) || 0.9) * 1000 + (parseFloat(cs.animationDelay) || 0) * 1000;
    setTimeout(function () {
      el.setAttribute('data-fx-split', 'done');
      /* Keep the split DOM by default: restoring the text would paint it again and create a late LCP entry.
         data-fx-restore puts the original text nodes back (e.g. for long paragraphs). */
      if (!el.hasAttribute('data-fx-restore')) return;
      for (let r = 0; r < restore.length; r++) {
        const rec = restore[r], first = rec.made[0];
        if (!first || !first.parentNode) continue;
        first.parentNode.insertBefore(rec.orig, first);
        for (let m = 0; m < rec.made.length; m++) rec.made[m].remove();
      }
      if (root !== el) unlayer(el);
    }, Math.min(dur + 120, 8000));
  });
}

/* ------------------------------------------------------------ scramble / decrypt */
const SETS = {
  blocks: '\u2591\u2592\u2593\u2588\u2580\u2584',
  binary: '01',
  symbols: '!<>-_\\/[]{}=+*^?#%&@$',
  upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
  lower: 'abcdefghijklmnopqrstuvwxyz',
  digit: '0123456789'
};
function pick(set) { return set.charAt((Math.random() * set.length) | 0); }
function scramble(el, ctx) {
  if (COMPLEX.test(el.textContent) || ctx.rtl(el)) { ctx.status('scramble', el, 'static', 'script'); return; }
  const mode = ctx.attr(el, 'chars', 'auto');
  const custom = SETS[mode] || (mode !== 'auto' ? mode : '');
  const dur = ctx.num(el, 'duration', 1.2) * 1000;
  let running = false;
  function run() {
    if (running) return;
    running = true;
    const r = el.getBoundingClientRect();
    el.style.minWidth = r.width + 'px';
    const root = accessibleLayer(el);
    const nodes = textNodes(root);
    const orig = nodes.map(function (n) { return n.data; });
    let total = 0;
    for (let k = 0; k < orig.length; k++) total += orig[k].length;
    const reveal = [];
    let idx = 0;
    for (let k = 0; k < orig.length; k++) {
      const arr = [];
      for (let c = 0; c < orig[k].length; c++, idx++) arr.push((idx / Math.max(1, total)) * dur * 0.75 + Math.random() * dur * 0.25);
      reveal.push(arr);
    }
    const t0 = performance.now();
    let lastUpd = 0;
    ctx.tick(function (t) {
      const el2 = t - t0;
      if (t - lastUpd < 45 && el2 < dur) return true;
      lastUpd = t;
      for (let k = 0; k < nodes.length; k++) {
        const s = orig[k];
        let out = '';
        for (let c = 0; c < s.length; c++) {
          const ch = s.charAt(c);
          if (el2 >= reveal[k][c] || /\s/.test(ch)) { out += ch; continue; }
          if (custom) out += pick(custom);
          else if (/[A-Z]/.test(ch)) out += pick(SETS.upper);
          else if (/[a-z]/.test(ch)) out += pick(SETS.lower);
          else if (/[0-9]/.test(ch)) out += pick(SETS.digit);
          else out += pick(SETS.symbols);
        }
        nodes[k].data = out;
      }
      if (el2 >= dur) {
        for (let k = 0; k < nodes.length; k++) nodes[k].data = orig[k];
        if (root !== el) unlayer(el);
        el.style.minWidth = '';
        running = false;
        return false;
      }
      return true;
    });
  }
  ctx.status('scramble', el, 'active');
  const trig = ctx.attr(el, 'trigger', 'view');
  if (trig === 'hover') {
    el.addEventListener('pointerenter', run);
    el.addEventListener('focus', run, true);
  } else whenSeen(el, ctx, run, '0px 0px -5% 0px');
}

/* ------------------------------------------------------------ rotate / type: shared cycling */
function wordsOf(el, ctx) {
  const first = el.textContent.replace(/\s+/g, ' ').trim();
  const list = ctx.attr(el, 'words', '').split('|').map(function (s) { return s.trim(); }).filter(Boolean);
  if (first && list.indexOf(first) < 0) list.unshift(first);
  return list;
}
function cycler(el, ctx, step, delayFor) {
  let visible = true, hovered = false, timer = 0, running = false;
  function can() { return visible && !hovered && !D.hidden; }
  function schedule(ms) { clearTimeout(timer); timer = setTimeout(function () { if (can()) { step(); schedule(delayFor()); } else running = false; }, ms); }
  function resume() { if (!running && can()) { running = true; schedule(delayFor()); } }
  ctx.inView(el, function (v) { visible = v; if (v) resume(); }, '0px');
  ctx.onVisible(function (v) { if (v) resume(); });
  if (ctx.bool(el, 'pause', true)) {
    el.addEventListener('pointerenter', function () { hovered = true; });
    el.addEventListener('pointerleave', function () { hovered = false; resume(); });
    el.addEventListener('focusin', function () { hovered = true; });
    el.addEventListener('focusout', function () { hovered = false; resume(); });
  }
}

function embedDir(el, words, ctx) {
  const wd = strongDir(words.join(' '));
  if (wd && wd !== (ctx.rtl(el) ? 'rtl' : 'ltr')) { el.style.direction = wd; el.style.unicodeBidi = 'embed'; }
}

function rotate(el, ctx) {
  const words = wordsOf(el, ctx);
  if (words.length < 2) return;
  el.textContent = '';
  el.appendChild(span('fx-sr', words.join(', ')));
  const stack = span('fx-rot');
  stack.setAttribute('aria-hidden', 'true');
  embedDir(el, words, ctx);
  const spans = words.map(function (w, k) { const s = span('fx-rot-w', w); if (!k) s.setAttribute('data-on', ''); stack.appendChild(s); return s; });
  el.appendChild(stack);
  let cur = 0;
  const interval = ctx.num(el, 'interval', 2600);
  ctx.status('rotate', el, 'active');
  cycler(el, ctx, function () {
    const prev = spans[cur];
    cur = (cur + 1) % spans.length;
    prev.removeAttribute('data-on');
    prev.setAttribute('data-out', '');
    spans[cur].removeAttribute('data-out');
    spans[cur].setAttribute('data-on', '');
    setTimeout(function () { prev.removeAttribute('data-out'); }, 700);
  }, function () { return interval; });
}

function type(el, ctx) {
  const words = wordsOf(el, ctx);
  if (!words.length) return;
  el.textContent = '';
  el.appendChild(span('fx-sr', words.join(', ')));
  const box = span('fx-type');
  box.setAttribute('aria-hidden', 'true');
  embedDir(el, words, ctx);
  words.forEach(function (w) { box.appendChild(span('fx-type-size', w)); });
  const live = span('fx-type-live'), txt = D.createTextNode(words[0]), caret = span('fx-caret');
  live.appendChild(txt); live.appendChild(caret); box.appendChild(live);
  el.appendChild(box);
  const speed = ctx.num(el, 'speed', 55), hold = ctx.num(el, 'hold', 1700);
  let w = 0, g = graphemes(words[0]), pos = g.length, phase = 'hold';
  ctx.status('type', el, 'active');
  if (words.length < 2) return;
  cycler(el, ctx, function () {
    if (phase === 'hold') phase = 'del';
    if (phase === 'del') {
      pos--;
      if (pos <= 0) { pos = 0; w = (w + 1) % words.length; g = graphemes(words[w]); phase = 'type'; }
    } else if (phase === 'type') {
      pos++;
      if (pos >= g.length) { pos = g.length; phase = 'hold'; }
    }
    txt.data = g.slice(0, pos).join('');
  }, function () {
    return phase === 'hold' ? hold : phase === 'del' ? speed * 0.5 : speed;
  });
}

/* ------------------------------------------------------------ count-up */
const fmts = {};
function numberFormat(lang, dec, notation) {
  const key = lang + '|' + dec + '|' + notation;
  if (!fmts[key]) {
    try { fmts[key] = new Intl.NumberFormat(lang || undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec, notation: notation }); }
    catch (e) { fmts[key] = new Intl.NumberFormat(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }); }
  }
  return fmts[key];
}
function count(el, ctx, visibleAtStart, rect) {
  const finalText = el.textContent;
  let to = ctx.num(el, 'to', NaN), decDefault = 0;
  if (!isFinite(to)) to = parseFloat(finalText.replace(/[^\d]/g, '')) || 0;
  else decDefault = (String(to).split('.')[1] || '').length;
  if (el.getAttribute('data-fx-in') === 'late' || (!ctx.pre && visibleAtStart)) { el.setAttribute('data-fx-in', 'done'); ctx.status('count', el, 'static', 'in-view-at-start'); return; }
  const from = ctx.num(el, 'from', 0);
  const dec = ctx.num(el, 'decimals', decDefault);
  const dur = ctx.num(el, 'duration', 1.8) * 1000;
  const prefix = ctx.attr(el, 'prefix', ''), suffix = ctx.attr(el, 'suffix', '');
  const notation = ctx.attr(el, 'notation', 'standard'), lang = D.documentElement.lang;
  /* the first Intl.NumberFormat initialises ICU (~20 ms on a slow phone): build it at idle time, show the start
     value with plain digits meanwhile (it is a small integer, usually 0) */
  let fmt = fmts[lang + '|' + dec + '|' + notation] || null;
  if (!fmt) ctx.idle(function () { fmt = numberFormat(lang, dec, notation); }, 600);
  const show = function (v) { return prefix + (fmt ? fmt.format(v) : (dec ? v.toFixed(dec) : String(Math.round(v)))) + suffix; };
  const w = rect ? rect.width : el.getBoundingClientRect().width;
  const orig = Array.prototype.slice.call(el.childNodes);
  el.style.minWidth = Math.ceil(w) + 'px';
  el.textContent = '';
  const sr = span('fx-sr', finalText.trim()), vis = span('fx-vis', show(from));
  vis.setAttribute('aria-hidden', 'true');
  el.appendChild(sr); el.appendChild(vis);
  el.setAttribute('data-fx-in', 'count');
  ctx.status('count', el, 'active');
  whenSeen(el, ctx, function () {
    if (!fmt) fmt = numberFormat(lang, dec, notation);
    const t0 = performance.now();
    let last = '';
    ctx.tick(function (t) {
      const p = Math.min(1, (t - t0) / dur);
      const e = p === 1 ? 1 : 1 - Math.pow(2, -10 * p);
      const s = show(from + (to - from) * e);
      if (s !== last) { vis.textContent = s; last = s; }
      if (p >= 1) {
        el.textContent = '';
        for (let k = 0; k < orig.length; k++) el.appendChild(orig[k]);
        el.style.minWidth = '';
        el.setAttribute('data-fx-in', 'done');
        return false;
      }
      return true;
    });
  }, '0px 0px -5% 0px');
}

/* ------------------------------------------------------------ glitch */
function glitch(el, ctx) {
  if (el.querySelector('.fx-gl')) return;
  const t = el.textContent;
  for (let k = 1; k <= 2; k++) {
    const g = span('fx-gl fx-gl' + k, t);
    g.setAttribute('aria-hidden', 'true');
    el.appendChild(g);
  }
  ctx.status('glitch', el, 'active');
}

const FN = { split: split, scramble: scramble, rotate: rotate, type: type, count: count, glitch: glitch };
const LAZY = { split: 1, count: 1 };
function safe(it, ctx, visible, rect) {
  const f = FN[it.name];
  if (!f) return;
  try { f(it.el, ctx, visible, rect); } catch (e) {
    it.el.setAttribute('data-fx-in', 'error');
    ctx.status(it.name, it.el, 'error', String(e && e.message || e).slice(0, 120));
  }
}
export function init(items, ctx) {
  ctx.pre = D.documentElement.classList.contains('fx-js');
  /* one read phase for all elements, then writes: no forced layout per heading */
  const vh = window.innerHeight || D.documentElement.clientHeight;
  const rects = items.map(function (it) { return LAZY[it.name] ? it.el.getBoundingClientRect() : null; });
  const vis = rects.map(function (r) { return !!(r && (r.width || r.height) && r.bottom > 0 && r.top < vh); });
  for (let i = 0; i < items.length; i++) {
    const it = items[i];
    if (LAZY[it.name] && !vis[i]) {
      /* below the fold: prepared only when it comes near the viewport (spreads the work on long pages) */
      let started = false;
      const stop = ctx.inView(it.el, function (v, e) { if (v && !started) { started = true; stop(); safe(it, ctx, false, e && e.boundingClientRect); } }, '30% 0px');
    } else safe(it, ctx, vis[i], rects[i]);
  }
}
