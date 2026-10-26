/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* JavCMS Ads manager pages (ADS-BRIEF 4, 12.b). Uses only the admin kit: .ax-* classes and the AxUX helpers
   (confirm, toast, t, format, markClean, menu). The small style block below uses --ax-* tokens only (no admin CSS file).
   Every write is a POST with the session token __token__ (refreshed from each answer). Text from the server is put
   in the page with textContent, never innerHTML. */
(function (w, d) {
  'use strict';
  var cfgEl = d.getElementById('jxCfg');
  if (!cfgEl || !w.AxUX) return;
  var C = JSON.parse(cfgEl.textContent || '{}'), L = C.lang || {}, TOKEN = C.token || '';

  /* ------------------------------------------------------------ style (tokens only) */
  var CSS = '.jx-tabs{margin:0 0 16px}.jx-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(176px,1fr));gap:12px}'
    + '.jx-card{display:flex;flex-direction:column;align-items:flex-start;gap:6px;text-align:start;padding:14px;border:1px solid var(--ax-line);border-radius:var(--ax-r-lg);background:var(--ax-raised);color:var(--ax-text);cursor:pointer;font:inherit}'
    + '.jx-card:hover{border-color:var(--ax-field)}.jx-card[aria-checked=true]{border-color:var(--ax-accent);box-shadow:inset 0 0 0 1px var(--ax-accent);background:var(--ax-accent-soft)}'
    + '.jx-card__pic{width:64px;height:44px;color:var(--ax-accent)}.jx-card__t{font-weight:600}.jx-card__h,.jx-preset__h{font-size:12.5px;color:var(--ax-muted)}'
    + '.jx-more{margin-top:12px}.jx-more>summary{cursor:pointer;color:var(--ax-accent);padding:6px 0}.jx-notweb{margin:8px 0 0 18px;list-style:disc;color:var(--ax-muted)}'
    + '.jx-radios,.jx-checks-inline{display:flex;flex-wrap:wrap;gap:6px 18px;margin:4px 0}.jx-check{display:inline-flex;align-items:center;gap:6px;min-height:32px;cursor:pointer}'
    + '.jx-row{display:flex;flex-wrap:wrap;gap:0 16px}.jx-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px}'
    + '.jx-preset{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;padding:10px;border:1px solid var(--ax-line);border-radius:var(--ax-r);cursor:pointer}.jx-preset input{grid-row:span 2;margin-top:3px}'
    + '.jx-preset--link,.jx-preset:not(:has(input)){display:block}.jx-preset--link .jx-preset__t,.jx-preset--link .jx-preset__h{display:block}.jx-preset--link .jx-preset__t{font-weight:600}.jx-preset--link .jx-preset__h{margin-top:4px}'
    + '.jx-card__aka{display:block;margin-top:4px;font-size:12px;color:var(--ax-faint)}.jx-aka{margin-top:2px;font-size:12.5px;color:var(--ax-faint)}.jx-nowrap{white-space:nowrap}'
    + '.jx-preset.is-on{border-color:var(--ax-accent)}.jx-adult{margin:4px 0;font-weight:600}.jx-adult.is-yes,.jx-shape.is-ok,.is-good{color:var(--ax-ok)}.jx-adult.is-warn,.jx-shape.is-odd,.jx-density.is-warn{color:var(--ax-warn)}'
    + '.jx-code{font-family:var(--ax-mono);font-size:12.5px}.jx-up__row,.jx-btns,.jx-pickbar,.jx-launch{display:flex;flex-wrap:wrap;align-items:center;gap:8px}'
    + '.jx-up__img{max-width:120px;max-height:60px;border:1px solid var(--ax-line);border-radius:var(--ax-r-sm)}.jx-pickbar{align-items:end;margin:8px 0}.jx-pickbar>label,.jx-launch__opts label{display:grid;gap:4px;font-size:12.5px}'
    + '.jx-pickwrap,.jx-frame__box{position:relative;overflow:hidden;border:1px solid var(--ax-field);border-radius:var(--ax-r-lg);background:var(--ax-bg)}.jx-pickwrap{height:560px}'
    + '.jx-pickframe,.jx-frame__box iframe{position:absolute;left:0;top:0;border:0;transform-origin:0 0;background:#fff}.jx-pickmsg{position:absolute;inset:0;display:grid;place-items:center;margin:0;color:var(--ax-muted)}'
    + '.jx-picked{font-weight:600;margin:8px 0}.jx-frames{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}.jx-frame{margin:0}.jx-frame figcaption{font-size:12.5px;color:var(--ax-muted);margin-bottom:4px}'
    + '.jx-summary{font-size:15px;margin:12px 0}.jx-steps,.jx-checks{list-style:none;margin:8px 0;padding:0}.jx-step{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--ax-line)}'
    + '.jx-step__text{flex:1;min-width:200px}.jx-step__text p{margin:2px 0 0}.jx-step.is-done .jx-step__state{color:var(--ax-ok)}.jx-head-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}'
    + '.jx-checks li{position:relative;padding:5px 0 5px 20px}.jx-checks li:before{content:"";position:absolute;left:4px;top:12px;width:8px;height:8px;border-radius:50%;background:var(--ax-faint)}'
    + '.jx-checks .is-ok:before{background:var(--ax-ok)}.jx-checks .is-warn:before{background:var(--ax-warn)}.jx-checks .is-bad:before{background:var(--ax-danger)}'
    + '.jx-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}.jx-tile{padding:12px;border:1px solid var(--ax-line);border-radius:var(--ax-r);background:var(--ax-raised)}.jx-tile span{display:block;font-size:12.5px;color:var(--ax-muted)}.jx-tile b{font-size:22px}'
    + '.jx-bars{display:flex;gap:6px;align-items:flex-end;height:120px;margin:16px 0}.jx-bar{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%}'
    + '.jx-bar i{width:100%;max-width:36px;min-height:2px;background:var(--ax-accent);border-radius:4px 4px 0 0}.jx-bar span{font-size:11px;color:var(--ax-faint);white-space:nowrap}'
    + '.jx-thumb img{object-fit:cover;border-radius:var(--ax-r-sm)}.jx-cardlist{margin:0 0 8px 18px}.jx-cardlist li{padding:8px 0;border-bottom:1px solid var(--ax-line)}.jx-launch{gap:4px}.jx-launch__opts{display:grid;gap:8px;padding:8px 12px}'
    + '.jx-scroll{max-height:160px;overflow:auto}.jx-h3{font-size:15px;margin:16px 0 8px}.jx-vtest{max-width:360px;margin-top:8px}.jx-btnrow{display:flex;gap:8px;margin-bottom:6px}'
    + '.jx-vcards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}tr.is-hit{outline:2px solid var(--ax-accent)}'
    + 'body.admin-studio .jx-head-row h2,.jx-head-row h2,body.admin-studio .jx-h3{margin:0;color:var(--ax-text);font:650 15px/1.3 var(--ax-font)}.jx-stepsec h2:focus{outline:none}'
    + '.jx-card__risk{white-space:normal;height:auto;max-width:100%;text-align:start}body.admin-studio .jx-stepsec>details>summary{font-weight:600}'
    + '.jx-head-row .ax-chip,.ax-table .ax-chip{white-space:normal;height:auto;max-width:100%}.jx-ed .ax-savebar{flex-wrap:wrap}.jx-ed .ax-savebar__end{flex-wrap:wrap;justify-content:flex-end;gap:8px}'
    + '.jx-table tr[hidden]{display:none!important}.jx-morebtn{margin:12px 0}.jx-switch.is-on,body .ax-row-actions .ax-btn.jx-switch.is-on{color:var(--ax-ok);border-color:var(--ax-ok);background:var(--ax-ok-soft)}body .ax-row-actions .jx-switch{margin-inline-start:6px}.ax-table td .ax-chip{overflow-wrap:normal;word-break:keep-all}.jx-noview{margin:0 0 12px}.jx-pickbar .jx-seg{display:flex;flex-wrap:wrap;gap:4px 14px}.jx-switch .ax-ic{margin-inline-end:4px}'
    + '.jx-page .ax-table th.ax-num{text-align:right}.jx-root+.jx-root{margin-top:24px;padding-top:16px;border-top:1px solid var(--ax-line)}.jx-root .jx-btns{margin:8px 0}'
    + '.jx-tabs.jx-fade{-webkit-mask-image:linear-gradient(90deg,#000 80%,transparent);mask-image:linear-gradient(90deg,#000 80%,transparent)}'
    + '@media (max-width:767px){.jx-pickwrap{height:460px}.jx-cards{grid-template-columns:1fr 1fr}.jx-vcards{grid-template-columns:1fr}}';
  var st = d.createElement('style');
  st.textContent = CSS;
  d.head.appendChild(st);

  /* ------------------------------------------------------------ helpers */
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return [].slice.call((r || d).querySelectorAll(s)); }
  function t(k) { var s = L[k] != null ? String(L[k]) : k, a = [].slice.call(arguments, 1), i = 0; return s.replace(/%s/g, function () { var v = a[i++]; return v == null ? '' : String(v); }); }
  function tn(k, n) { return t(k).replace('{n}', String(n)); }
  function E(tag, cls, attrs, text) {
    var e = d.createElement(tag), k;
    if (cls) e.className = cls;
    if (attrs) for (k in attrs) if (attrs[k] != null) e.setAttribute(k, attrs[k]);
    if (text != null) e.textContent = text;
    return e;
  }
  function toast(msg, kind) { if (msg) AxUX.toast(msg, kind || 'ok'); }
  function post(act, data, file) {
    var fd = new FormData(), k;
    data = data || {};
    for (k in data) if (data[k] != null) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : String(data[k]));
    if (file) fd.append('file', file);
    fd.append('__token__', TOKEN);
    return fetch(C.urls[act], { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.__token__) TOKEN = j.__token__; return j || {}; })
      .catch(function () { return { code: 0, msg: AxUX.t('request_failed') }; });
  }
  function done(j, reload) {
    toast(j.msg || (j.code == 1 ? '' : AxUX.t('request_failed')), j.code == 1 ? (j.build_failed ? 'warn' : 'ok') : (/bulk_examples$/.test(j.key || '') ? 'info' : 'error'));
    if (j.auto_on_text) setTimeout(function () { toast(j.auto_on_text, 'info'); }, 900);
    if (j.code == 1 && reload) setTimeout(function () { location.reload(); }, 700);
    return j;
  }
  function vis(el) { return !el.closest('[data-g][hidden]'); }
  function show(el, on) { if (el) el.hidden = !on; }

  /* Confirm steps shared by switch-on actions: Chrome-risk confirm (typed ON for U09 / U10 without own cap) and conflicts. */
  function riskConfirm(typed) {
    return AxUX.confirm({ title: t('chrome_risk'), message: t('chrome_risk_confirm') + (typed ? ' ' + t('type_on', 'ON') : ''), typed: typed ? 'ON' : undefined,
      confirmLabel: t('save_on'), count: 1, danger: true });
  }
  function conflictConfirm(j) {
    var txt = (j.conflicts || []).map(function (c) { return c.text + (c.items && c.items.length ? ' (' + c.items.map(function (x) { return x[1]; }).join(', ') + ')' : ''); }).join(' ');
    return AxUX.confirm({ title: t('conflict_ok'), message: txt || j.msg, confirmLabel: t('conflict_ok'), count: 1, danger: true });
  }
  /* Run a switch-on request; answer need_risk / need_confirm by asking, then resend once with the flag. */
  function withChecks(send, typedRisk) {
    return send({}).then(function step(j) {
      if (j.need_risk) return riskConfirm(typedRisk).then(function (y) { return y ? send({ risk_ok: 1, item_risk_ok: 1 }).then(step) : j; });
      if (j.need_confirm) return conflictConfirm(j).then(function (y) { return y ? send({ confirm_conflict: 1 }).then(step) : j; });
      return j;
    });
  }

  /* ------------------------------------------------------------ tab bar: fade while more tabs wait to the right (phones) */
  (function () {
    var n = $('.jx-tabs');
    if (!n) return;
    var cur = $('[aria-current="page"]', n);
    if (cur && cur.offsetLeft + cur.offsetWidth > n.clientWidth) n.scrollLeft = cur.offsetLeft - 16;
    function upd() { n.classList.toggle('jx-fade', n.scrollWidth - n.clientWidth - n.scrollLeft > 2); }
    n.addEventListener('scroll', upd, { passive: true });
    w.addEventListener('resize', upd);
    upd();
  })();

  /* ------------------------------------------------------------ preview launcher (4.7) */
  function preview(btn) {
    var box = btn.closest('.jx-launch') || d, opt = function (n) { var e = $('[data-jx-pv="' + n + '"]', box.closest ? box : d) || $('[data-jx-pv="' + n + '"]'); return e; };
    var phone = btn.getAttribute('data-phone') === '1' || (opt('phone') && opt('phone').checked);
    var win = w.open('about:blank', phone ? 'jcxphone' : '_blank', phone ? 'width=390,height=844' : '');
    if (!win) { toast(t('preview_blocked'), 'warn'); return; }
    try { win.document.title = t('preview_opening'); win.document.body.textContent = t('preview_opening'); } catch (e) {}
    var data = { scope: btn.getAttribute('data-scope') || 'ex', theme: opt('theme') ? (opt('theme').value || '-') : '-', page: btn.getAttribute('data-page') || (opt('page') ? opt('page').value : '') };
    if (!btn.closest('.jx-launch')) data.page = btn.getAttribute('data-page') || '';
    if (btn.getAttribute('data-chk')) data.chk = btn.getAttribute('data-chk');
    post('preview', data).then(function (j) {
      if (j.code == 1 && j.url) { win.location.href = alHash(j.url); }
      else { try { win.close(); } catch (e) {} done(j); }
    });
  }
  /* R4-11 / R6-04: every preview page opens in the admin's language (the panel reads #al=; jcx/preview also sets jcxal). */
  function alHash(u) { return C.al ? u + (u.indexOf('#') > -1 ? '&' : '#') + 'al=' + encodeURIComponent(C.al) : u; }

  /* ------------------------------------------------------------ global actions */
  d.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-jx-act]');
    if (!b) return;
    var a = b.getAttribute('data-jx-act');
    if (a === 'preview') { ev.preventDefault(); AxUX.closeMenus && AxUX.closeMenus(); preview(b); }
    else if (a === 'examples') {
      var op = b.getAttribute('data-op'), go = function () { b.disabled = true; post('examples', { op: op }).then(function (j) { b.disabled = false; done(j, true); }); };
      if (op === 'remove') AxUX.confirm({ title: b.textContent.trim(), message: t('remove_examples') + '?', count: 1, confirmLabel: b.textContent.trim() }).then(function (y) { if (y) go(); });
      else go();
    }
    else if (a === 'import-old') { post('save', { op: 'import_old' }).then(function (j) { done(j, true); }); }
    else if (a === 'health') { health(); }
  });

  /* ------------------------------------------------------------ Overview: health card, browser half (4.10, CF16) */
  function health() {
    if (!C.health) return;
    var rt = $('[data-h="runtime"]'), cf = $('[data-h="cfg"]');
    if (!rt) return;
    rt.className = cf.className = 'is-wait';
    rt.textContent = cf.textContent = t('h_checking');
    if (C.health.on != null && +C.health.on === 0) { rt.className = 'is-info'; rt.textContent = t('h_runtime_off'); }
    else
    fetch(C.base, { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (h) {
      var ok = h.indexOf('id="jcx-h"') > -1;
      rt.className = ok ? 'is-ok' : 'is-warn'; rt.textContent = t(ok ? 'h_runtime_ok' : 'h_runtime');
    }).catch(function () { rt.className = 'is-warn'; rt.textContent = t('h_runtime'); });
    fetch(C.health.cfg, { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      var ok = j && +j.crev === +C.health.crev;
      cf.className = ok ? 'is-ok' : 'is-warn'; cf.textContent = t(ok ? 'h_cfg_ok' : 'h_cfg_stale');
    }).catch(function () { cf.className = 'is-warn'; cf.textContent = t('h_cfg_stale'); });
  }

  /* ------------------------------------------------------------ My ads */
  function initLists() {
    var all = $('[data-jx-all]'), cnt = $('#jxSel');
    function ids() { return $$('.checkbox-ids:checked').map(function (c) { return c.value; }); }
    function sync() { var n = ids().length; if (cnt) cnt.textContent = n ? AxUX.t('selected', { n: n }) : ''; }
    if (all) all.addEventListener('change', function () { $$('.checkbox-ids').forEach(function (c) { if (!c.closest('tr').hidden) c.checked = all.checked; }); sync(); });
    /* R4r2-13: 25 cards at a time (the full list was 48,000 px tall on phones) */
    var rows = $$('.jx-table tbody tr'), PAGE = 25, shown = PAGE, more = null;
    function paging() {
      rows.forEach(function (r, i) { r.hidden = i >= shown; });
      var left = rows.length - shown;
      more.hidden = left <= 0;
      more.textContent = t('show_more', Math.min(PAGE, Math.max(left, 0)), Math.max(left, 0));
    }
    if (rows.length > PAGE) {
      more = E('button', 'ax-btn jx-morebtn', { type: 'button' });
      more.addEventListener('click', function () { shown += PAGE; paging(); });
      var wrap = $('.jx-table').closest('.ax-table-wrap');
      wrap.parentNode.insertBefore(more, wrap.nextSibling);
      paging();
    }
    d.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('checkbox-ids')) sync(); });
    d.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-jx-act]');
      if (!b) return;
      var a = b.getAttribute('data-jx-act'), tr = b.closest('tr'), id = tr ? tr.getAttribute('data-id') : '', name = tr ? tr.getAttribute('data-name') : '';
      if (a === 'switch') {
        var on = b.getAttribute('aria-checked') !== 'true';
        var unit = tr.getAttribute('data-unit') || '';
        withChecks(function (x) { return post('status', Object.assign({ ids: id, status: on ? 1 : 0 }, x)); }, unit === 'U09' || unit === 'U10').then(function (j) { done(j, j.code == 1); });
      } else if (a === 'duplicate') {
        post('save', { op: 'duplicate', id: id }).then(function (j) { done(j); if (j.code == 1 && j.url) location.href = j.url + (j.url.indexOf('?') > -1 ? '&' : '?') + 'step=2'; });
      } else if (a === 'del') {
        AxUX.confirm({ title: t('bulk_delete'), message: t('delete_confirm', name), count: 1, confirmLabel: AxUX.t('confirm_ok') }).then(function (y) {
          if (y) post('del', { ids: id }).then(function (j) { done(j, true); });
        });
      } else if (a === 'bulk') {
        var op = b.getAttribute('data-op'), list = ids();
        if (!list.length) { toast(AxUX.t('select_first'), 'warn'); return; }
        if (op === 'delete') {
          AxUX.confirm({ title: t('bulk_delete'), message: tn('bulk_delete_confirm', list.length), count: list.length }).then(function (y) {
            if (y) post('bulk', { op: 'delete', ids: list.join(',') }).then(function (j) { done(j, true); });
          });
        } else {
          withChecks(function (x) { return post('bulk', Object.assign({ op: op, ids: list.join(',') }, x)); }, false).then(function (j) { done(j, j.code == 1); });
        }
      }
    });
    sync();
  }

  /* ------------------------------------------------------------ the editor (4.3, 4.4) */
  var WHEN = ['U03', 'U04', 'U06', 'U07', 'U08', 'U09', 'U10', 'U11'];
  var PAGE_OF = { P02: 'home', P06: 'home', P03: 'play', P04: 'play', P11: 'play', P16: 'play', P03d: 'detail', P05: 'list', P12: 'search', P14: 'list' };
  var SLOTS = ['P01', 'P02', 'P03', 'P03d', 'P04', 'P05', 'P06', 'P07', 'P12', 'P13', 'P14', 'P16'];
  var NET_RE = { exoclick: /magsrv\.com|pemsrv\.com|data-zoneid|ad_idzone|adprovider/i, juicyads: /juicyads/i, trafficstars: /trafficstars|tsyndicate|data-ts-/i,
    trafficjunky: /trafficjunky/i, hilltopads: /hilltop/i, clickadu: /clickadu/i, ezmob: /ezmob|qualiclicks/i, popcash: /popcash/i, adsterra: /atoptions|invoke\.js|adsterra|highperformance/i,
    richads: /richads/i, monetag: /monetag|propeller|data-zone/i };

  function initEditor() {
    var F = $('#jxEd'), S = { card: '', kind: 'own', preset: '', place: '', step: 1, id: 0, draftId: 0, draft: false, framePlaces: null, riskOk: 0, bumper: 0, w: 0, h: 0, wm: 0, hm: 0, saved: false };
    var it = C.item;
    /* R6-15: Overview "Which ad formats can my site show?" opens the not-possible list */
    var nwl = $('#jxNotWeb');
    if (nwl && w.location.hash === '#jxNotWeb') { nwl.open = true; setTimeout(function () { nwl.scrollIntoView({ block: 'center' }); }, 60); }

    function cardDef() { return C.cards[S.card] || { unit: 'U01', kind: 'image' }; }
    function g(name) { return $$('[data-g="' + name + '"]', F); }
    function setG(name, on) { g(name).forEach(function (e) { e.hidden = !on; }); }
    function radio(name) { var r = $('input[name="' + name + '"]:checked', F); return r ? r.value : ''; }
    function setRadio(name, v) { $$('input[name="' + name + '"]', F).forEach(function (r) { r.checked = String(r.value) === String(v); }); }
    var KS = ['sticky', 'corner', 'full', 'overlay', 'rails', 'push'];
    function net() { return KS.indexOf(S.card) > -1 && S.kind === 'net'; }
    function vwhat() { return radio('jxVWhat') || 'file'; }

    /* unit / kind / place that the current choices mean */
    function unitKind() {
      var c = S.card, def = cardDef(), u = def.unit, k = def.kind, p = S.preset, pl = S.place;
      if (net()) k = 'code';
      if (c === 'preroll') k = vwhat() === 'vast' ? 'vast' : 'video';
      if (c === 'code') {
        k = 'code'; u = 'U01';
        if (p === 'multi') { u = 'U10'; pl = 'P15h'; }
        else if (p === 'ipp' || p === 'inter') pl = 'P15b';
        else if (p === 'verify') pl = 'P15h';
        else if (p === 'native') u = pl === 'P05' ? 'U12' : 'U13';
        else if (p === 'exo') { var zt = ($('[data-jx-zone="type"]', F) || {}).value; if (zt === 'sticky') { u = 'U03'; pl = 'P09'; } else if (zt === 'fullpage' || zt === 'ipp') pl = 'P15b'; else if (zt === 'native' && pl !== 'P05') u = 'U13'; }
        else if (p === 'any') { var wh = radio('jxWhere'); if (wh === 'P15h' || wh === 'P15b') pl = wh; }
      }
      if (c === 'pop') pl = ['exoclick', 'popcash', 'juicyads'].indexOf(($('#jxNet') || {}).value) > -1 ? 'P15b' : 'P15h';
      var al = allowed(u, k);
      if (al.length === 1) pl = al[0];
      return { unit: u, kind: k, place: pl };
    }
    function allowed(u, k) {
      if (S.card === 'code') {
        if (S.preset === 'native') return ['P03', 'P03d', 'P05', 'P06', 'P07', 'P13', 'P16'];
        if (u === 'U03') return ['P09'];
        if (u === 'U10') return ['P15h'];
        return (C.allowed.code || []).filter(function (p) { return SLOTS.indexOf(p) > -1 || p === 'P15h' || p === 'P15b'; });
      }
      return C.allowed[S.card] || [];
    }
    function mode() {
      var uk = unitKind();
      if (WHEN.indexOf(uk.unit) > -1) return 'when';
      if (uk.place === 'P15h' || uk.place === 'P15b') return 'when';
      if (allowed(uk.unit, uk.kind).length <= 1 && uk.unit !== 'U12') return 'skip';
      return 'where';
    }
    function risky() {
      var uk = unitKind(), dev = +radio('jxDev') || 3, trig = radio('jxTrig');
      return uk.unit === 'U09' || uk.unit === 'U10' || (uk.unit === 'U08' && ((dev & 2) || trig === 'load' || trig === 'welcome'));
    }
    function typedRisk() { var uk = unitKind(); return uk.unit === 'U09' || (uk.unit === 'U10' && radio('jxOften') !== 'own'); }

    /* ---- visibility of every group ---- */
    function refresh() {
      var c = S.card, p = S.preset, n = net(), uk = unitKind();
      var imgCards = ['image', 'sticky', 'corner', 'full', 'overlay', 'rails', 'masthead', 'expand'];
      setG('src', !!c);
      setG('kindsw', KS.indexOf(c) > -1);
      var codeLike = c === 'code' || c === 'pop' || c === 'webpush' || n;
      setG('preset', codeLike);
      setG('presetlist', c === 'code');
      setG('presetnote', c === 'code' && !!p);
      setG('rootlink', p === 'adstxt' || p === 'verify');
      setG('network', (c === 'code' && ['verify', 'adstxt'].indexOf(p) < 0) || c === 'pop' || c === 'webpush' || n);
      setG('exo', c === 'code' && p === 'exo');
      setG('code', codeLike && p !== 'adstxt');
      setG('codewhere', c === 'code' && p === 'any');
      setG('whereplace', c === 'code' && p === 'any');
      setG('codem', codeLike && ['verify', 'multi', 'push'].indexOf(p) < 0 && c !== 'pop' && c !== 'webpush' && uk.place !== 'P15h' && uk.place !== 'P15b');
      setG('sw', c === 'webpush' || p === 'multi');
      setG('img', !n && imgCards.indexOf(c) > -1);
      setG('imgm', ['image', 'sticky', 'masthead', 'expand', 'corner'].indexOf(c) > -1);
      var src = ($('[data-m="src"]', g('img')[0] || F) || {}).value || '';
      setG('still', /\.gif$/i.test(src));
      setG('expand', c === 'expand');
      setG('wall', c === 'masthead');
      setG('push', c === 'push' && !n);
      setG('native', c === 'feed');
      setG('cards', c === 'widget');
      setG('video', c === 'preroll' || c === 'outstream');
      setG('vwhat', c === 'preroll');
      setG('vfile', c === 'outstream' || (c === 'preroll' && vwhat() === 'file'));
      setG('vvast', c === 'preroll' && vwhat() === 'vast');
      setG('vpre', c === 'preroll');
      setG('vout', c === 'outstream');
      setG('zip', c === 'html5');
      setG('sponsored', c === 'sponsored');
      setG('linkunit', c === 'link');
      setG('url', !!c && !codeLike && c !== 'sponsored');
      setG('alt', !n && (imgCards.indexOf(c) > -1 || c === 'feed' || c === 'push'));
      var slotCode = codeLike && uk.place !== 'P15h' && uk.place !== 'P15b' && c !== 'pop' && c !== 'webpush';
      setG('size', slotCode || c === 'html5');
      setG('sizem', slotCode);
      var m = mode();
      setG('where', m === 'where');
      setG('when', m === 'when');
      setG('fixed', m === 'skip');
      setG('feedopts', uk.unit === 'U12' || (uk.unit === 'U16' && uk.place === 'P05'));
      setG('codemode', uk.kind === 'code' || uk.kind === 'html');
      // When-step parts per unit
      var trigs = { U06: ['delay', 'scroll'], U07: ['delay', 'scroll'], U08: ['link', 'load', 'welcome'], U09: ['exit', 'delay', 'scroll'] }[uk.unit] || [];
      setG('trig', trigs.length > 0);
      $$('[data-trig]', F).forEach(function (l) { l.hidden = trigs.indexOf(l.getAttribute('data-trig')) < 0; });
      if (trigs.length && trigs.indexOf(radio('jxTrig')) < 0) setRadio('jxTrig', trigs[0]);
      var tr = radio('jxTrig');
      setG('delay', ['U06', 'U07', 'U09'].indexOf(uk.unit) > -1 && tr !== 'exit');
      setG('scroll', tr === 'scroll' && trigs.length > 0);
      setG('u09', uk.unit === 'U09');
      setG('closemem', uk.unit === 'U03');
      var isNet = uk.kind === 'code' && (uk.unit === 'U10' || uk.unit === 'U11' || uk.place === 'P15h' || uk.place === 'P15b' || n);
      var layer = ['U06', 'U07', 'U08', 'U09'].indexOf(uk.unit) > -1;
      setG('often', isNet || layer);
      setG('oftennet', isNet);
      setG('oftendef', layer && !isNet);
      if (isNet && !radio('jxOften')) setRadio('jxOften', 'net');
      if (layer && !isNet && (!radio('jxOften') || radio('jxOften') === 'net')) setRadio('jxOften', 'def');
      setG('oftenown', radio('jxOften') === 'own');
      var cap = C.safe_cap[uk.unit];
      var od = $('[data-jx-oftendef]', F);
      if (od && cap) od.textContent = t('often_default', t('often_custom', cap[0], cap[1]) + (cap[2] ? ', ' + t('often_gap', cap[2]) : ''));
      var fx = $('[data-jx-fixed]', F);
      if (fx) fx.textContent = t('place_fixed', C.pl[uk.place] || uk.place || '—');
      var nm = $('[data-jx-cardname]', F), cb = c ? $('.jx-card[data-card="' + c + '"] .jx-card__t', F) : null;
      if (nm) nm.textContent = cb ? cb.textContent : '';
      var cmp = $('[data-jx-compress]', F); if (cmp) cmp.hidden = !C.compress;
      $$('.jx-preset', F).forEach(function (l) { l.classList.toggle('is-on', !!$('input:checked', l)); });
      var ph = $('[data-jx-ph]', F), pr = p ? $('#jxPh-' + p) : null;
      if (ph) ph.textContent = pr ? pr.textContent : '';
      adultLine();
      share();
    }

    /* ---- cards ---- */
    function setCard(c, keep) {
      S.card = c;
      $$('.jx-card', F).forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-card') === c ? 'true' : 'false'); b.tabIndex = b.getAttribute('data-card') === c ? 0 : -1; });
      if (!keep) {
        S.preset = c === 'pop' ? 'pop' : (c === 'webpush' ? 'push' : (c === 'code' ? (S.preset && C.cards.code ? S.preset : 'any') : ''));
        if (c === 'code' && ['pop', 'push', 'vast', 'link', 'sticky', 'house', ''].indexOf(S.preset) > -1) S.preset = 'any';
        setRadio('jxPreset', S.preset);
        if (c === 'push') S.kind = 'own';
        if (c === 'full' || S.preset === 'inter') setRadio('jxDev', '1');
        var uk = unitKind();
        S.place = uk.place || '';
        if (c === 'corner') setDelay(20);
        if (c === 'push') setDelay(15);
      }
      refresh();
      err();
    }
    function setDelay(v) { var e = $('#jxDelay'); if (e && !e.value) e.value = v; }
    $$('.jx-card', F).forEach(function (b) {
      b.addEventListener('click', function () { setCard(b.getAttribute('data-card')); var s = $('[data-g="src"]', F); if (s && w.innerWidth < 768) s.scrollIntoView({ block: 'start' }); });
      b.addEventListener('keydown', function (e) {
        if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].indexOf(e.key) < 0) return;
        var list = $$('.jx-card', b.parentNode), i = list.indexOf(b) + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1);
        i = (i + list.length) % list.length; list[i].focus(); setCard(list[i].getAttribute('data-card')); e.preventDefault();
      });
    });

    /* ---- presets, network, code checks ---- */
    $$('input[name="jxPreset"]', F).forEach(function (r) {
      r.addEventListener('change', function () {
        var p = r.value, to = r.getAttribute('data-card');
        S.preset = p;
        if (p === 'sticky') { setCard('sticky', true); S.kind = 'net'; setRadio('jxKind', 'net'); S.preset = 'sticky'; }
        else if (p === 'vast') { setCard('preroll', true); setRadio('jxVWhat', 'vast'); }
        else if (p === 'link' || p === 'house') { setCard(to, true); }
        else if (p === 'pop' || p === 'push') { setCard(to, true); S.preset = p; }
        if (p === 'inter') setRadio('jxDev', '1');
        if (p === 'verify') setRadio('jxWhere', 'P15h');
        S.place = unitKind().place || S.place;
        refresh();
      });
    });
    $$('input[name="jxKind"],input[name="jxVWhat"],input[name="jxWhere"],input[name="jxTrig"],input[name="jxOften"],input[name="jxDev"]', F).forEach(function (r) {
      r.addEventListener('change', function () { if (r.name === 'jxKind') S.kind = r.value; if (r.name === 'jxWhere' && r.value !== 'place') S.place = r.value; if (r.name === 'jxWhere' && r.value === 'place' && (S.place === 'P15h' || S.place === 'P15b')) S.place = ''; refresh(); summary(); });
    });
    function adultLine() {
      var s = $('#jxNet'), a = $('#jxNetA');
      if (!s || !a) return;
      var o = s.options[s.selectedIndex], ad = o ? o.getAttribute('data-adult') : '';
      a.className = 'jx-adult' + (ad === 'yes' ? ' is-yes' : (ad ? ' is-warn' : ''));
      a.textContent = s.value === 'monetag' ? t('net_monetag_no') : (ad ? t('net_adult_' + ad) : '');
      shape();
    }
    var netSel = $('#jxNet');
    if (netSel) netSel.addEventListener('change', function () { refresh(); });
    function shape() {
      var code = ($('#jxCode') || {}).value || '', out = $('#jxShape'), nv = (netSel || {}).value, re = NET_RE[nv];
      if (!out) return;
      out.className = 'jx-shape';
      out.textContent = '';
      if (code.trim() && re) {
        var ok = re.test(code), name = C.nets[nv] ? C.nets[nv].name : nv;
        out.className = 'jx-shape ' + (ok ? 'is-ok' : 'is-odd');
        out.textContent = ok ? t('code_looks_ok', name) : t('code_looks_odd', name);
      }
      var dw = /document\s*\.\s*write/i.test(code + ' ' + (($('#jxCodeM') || {}).value || ''));
      var n = $('[data-jx-docwrite]', F); if (n) n.hidden = !dw;
      if (dw) setRadio('jxCmode', '1');
      var sz = detectSize(code);
      if (sz && !S.w) setSize(sz[0], sz[1]);
    }
    ['#jxCode', '#jxCodeM'].forEach(function (s) { var e = $(s, F); if (e) e.addEventListener('input', shape); });
    function detectSize(code) {
      var m = code.match(/data-ts-width\s*=\s*["']?(\d{2,4})[\s\S]{0,80}?data-ts-height\s*=\s*["']?(\d{2,4})/i)
        || code.match(/['"]?width['"]?\s*[:=]\s*["']?(\d{2,4})[\s\S]{0,80}?['"]?height['"]?\s*[:=]\s*["']?(\d{2,4})/i);
      if (m) return [+m[1], +m[2]];
      m = code.match(/['"]?height['"]?\s*[:=]\s*["']?(\d{2,4})[\s\S]{0,80}?['"]?width['"]?\s*[:=]\s*["']?(\d{2,4})/i);
      return m ? [+m[2], +m[1]] : null;
    }
    /* ExoClick zone builder (preset 7): we write the code */
    $$('[data-jx-zone]', F).forEach(function (e) {
      e.addEventListener('input', exo); e.addEventListener('change', exo);
    });
    function exo() {
      var id = ($('[data-jx-zone="id"]', F).value || '').replace(/\D/g, ''), cl = ($('[data-jx-zone="class"]', F).value || '').replace(/[^A-Za-z0-9_-]/g, '');
      if (netSel) netSel.value = 'exoclick';
      var code = id && cl ? '<script async type="application/javascript" src="https://a.magsrv.com/ad-provider.js"></script> <ins class="' + cl + '" data-zoneid="' + id + '"></ins> <script>(AdProvider = window.AdProvider || []).push({"serve": {}});</script>' : '';
      $('#jxCode').value = code;
      S.place = unitKind().place || S.place;
      refresh();
    }

    /* ---- sizes ---- */
    var sizeSel = $('[data-jx-size-sel]', F);
    function setSize(wv, hv) {
      S.w = +wv || 0; S.h = +hv || 0;
      var fw = $('[data-f="item_w"]', F), fh = $('[data-f="item_h"]', F);
      if (fw) fw.value = S.w || ''; if (fh) fh.value = S.h || '';
      if (sizeSel) {
        var v = S.w + 'x' + S.h, has = $$('option', sizeSel).some(function (o) { return o.value === v; });
        sizeSel.value = S.w ? (has ? v : 'custom') : '';
        $('[data-jx-custom]', F).hidden = sizeSel.value !== 'custom';
      }
      var sf = $('[data-jx-size]', F); if (sf) sf.textContent = S.w ? t('size_detected', S.w + ' × ' + S.h) : '';
    }
    if (sizeSel) sizeSel.addEventListener('change', function () {
      var v = sizeSel.value;
      $('[data-jx-custom]', F).hidden = v !== 'custom';
      if (v && v !== 'custom') { var p = v.split('x'); setSize(p[0], p[1]); }
    });
    $$('[data-f="item_w"],[data-f="item_h"]', F).forEach(function (e) { e.addEventListener('input', function () { S.w = +$('[data-f="item_w"]', F).value || 0; S.h = +$('[data-f="item_h"]', F).value || 0; }); });

    /* ---- uploads (11.1 checks on the server) ---- */
    function upBox(box, path, url, name) {
      var hid = $('input[data-m]', box), img = $('.jx-up__img', box), nm = $('.jx-up__name', box), rm = $('[data-jx-act="unfile"]', box);
      hid.value = path || '';
      nm.textContent = name || (path ? path.split('/').pop() : '');
      img.hidden = !(url && /\.(jpe?g|png|gif|webp)$/i.test(path || ''));
      if (!img.hidden) img.src = url;
      rm.hidden = !path;
    }
    function uploadFile(box, file) {
      var m = box.getAttribute('data-up'), ex = $('.jx-err', box), nm = $('.jx-up__name', box);
      if (ex) ex.hidden = true;
      // same limits as the server (11.1): images 2 MB, video 20 MB, zip 1 MB; checked here so a big file is not sent at all
      var lim = m === 'zip' ? 1048576 : (m === 'vsrc' ? 20971520 : 2097152);
      if (file.size > lim) { showErr('item_media.' + m, t('err_size'), box); return; }
      nm.textContent = t('uploading');
      var go = function () {
        post('upload', { item_id: S.id || '' }, file).then(function (j) {
          if (j.code != 1) { upBox(box, '', '', ''); showErr($('.jx-err', box) ? 'item_media.' + m : '', j.msg, box); return; }
          if (m === 'zip') { upBox(box, 'zip', '', file.name); S.zip = 1; return; }
          upBox(box, j.path, j.url, file.name);
          if (m === 'src' && j.size && j.size[0]) setSize(j.size[0], j.size[1]);
          if (m === 'srcm' && j.size && j.size[0]) { S.wm = j.size[0]; S.hm = j.size[1]; var a = $('[data-f="item_wm"]', F), b = $('[data-f="item_hm"]', F); if (a) a.value = S.wm; if (b) b.value = S.hm; }
          refresh();
        });
      };
      if (m === 'zip' && !S.id) { saveDraft().then(function (ok) { if (ok) go(); }); } else go();
    }
    function bindUp(box) {
      var inp = $('.jx-up__file', box);
      $('[data-jx-act="choose"]', box).addEventListener('click', function () { inp.click(); });
      inp.addEventListener('change', function () { if (inp.files && inp.files[0]) uploadFile(box, inp.files[0]); inp.value = ''; });
      $('[data-jx-act="unfile"]', box).addEventListener('click', function () { upBox(box, '', '', ''); refresh(); });
    }
    $$('.jx-up', F).forEach(bindUp);

    /* ---- widget cards (U13) ---- */
    var cardList = $('[data-jx-cards]', F), cardTpl = null;
    function addCard(c) {
      c = c || {};
      var li = E('li'), n = cardList.children.length + 1;
      li.appendChild(E('strong', null, null, t('card_n', n)));
      var up = E('div', 'ax-field jx-up', { 'data-up': 'card' });
      var row = E('div', 'jx-up__row'), fi = E('input', 'jx-up__file', { type: 'file', hidden: '', accept: 'image/jpeg,image/png,image/gif,image/webp', 'aria-label': t('card_n', n) });
      row.appendChild(fi);
      row.appendChild(E('button', 'ax-btn ax-btn--sm', { type: 'button', 'data-jx-act': 'choose' }, L.choose_file || '…'));
      row.appendChild(E('span', 'jx-up__name', { 'aria-live': 'polite' }));
      var im = E('img', 'jx-up__img', { alt: '' }); im.hidden = true; row.appendChild(im);
      var rb = E('button', 'ax-btn ax-btn--sm ax-btn--ghost', { type: 'button', 'data-jx-act': 'unfile' }, t('remove_file')); rb.hidden = true; row.appendChild(rb);
      row.appendChild(E('input', null, { type: 'hidden', 'data-m': 'card' }));
      up.appendChild(row);
      li.appendChild(up);
      [['title', t('title_f'), 120], ['url', t('link'), 500], ['brand', L.brand || 'Brand', 60]].forEach(function (f) {
        var i = E('input', 'layui-input', { type: f[0] === 'url' ? 'url' : 'text', 'data-card-f': f[0], maxlength: f[2], placeholder: f[0] === 'url' ? 'https://' : f[1], 'aria-label': f[1] + ' ' + n });
        i.value = c[f[0]] || '';
        li.appendChild(i);
      });
      var del = E('button', 'ax-btn ax-btn--sm ax-btn--ghost', { type: 'button' }, t('remove_file'));
      del.addEventListener('click', function () { li.remove(); });
      li.appendChild(del);
      cardList.appendChild(li);
      bindUp(up);
      if (c.img) upBox(up, c.img, C.base + c.img.replace(/^\//, ''), '');
    }
    var cAdd = $('[data-jx-act="card-add"]', F);
    if (cAdd) cAdd.addEventListener('click', function () { if (cardList.children.length < 10) addCard(); });

    /* ---- VAST test in the admin's browser (11.2: no server request) ---- */
    var vt = $('[data-jx-act="vast-test"]', F);
    if (vt) vt.addEventListener('click', function () {
      var urls = (($('[data-f="media.vast"]', F) || {}).value || '').split(/\s+/).filter(Boolean), out = $('[data-jx-vtest]', F), vid = $('.jx-vtest', F);
      out.textContent = t('h_checking'); vid.hidden = true;
      var tries = 0;
      function load(u) {
        if (!u || tries++ > 5) return Promise.reject();
        return fetch(u, { credentials: 'omit' }).then(function (r) { return r.text(); }).then(function (x) {
          var doc = new DOMParser().parseFromString(x, 'text/xml'), mf = [].slice.call(doc.getElementsByTagName('MediaFile'));
          var pick = mf.filter(function (m) { return /mp4|webm/i.test(m.getAttribute('type') || m.textContent); })[0] || mf[0];
          if (pick) return pick.textContent.trim();
          var wr = doc.getElementsByTagName('VASTAdTagURI')[0];
          return wr ? load(wr.textContent.trim()) : Promise.reject();
        });
      }
      (function next(i) {
        if (i >= urls.length) { out.textContent = t('v_test_fail'); return; }
        load(urls[i]).then(function (src) { out.textContent = t('v_test_ok', src); vid.src = src; vid.hidden = false; vid.play().catch(function () {}); }, function () { tries = 0; next(i + 1); });
      })(0);
    });
    /* R4-18: bumper is an on / off choice (6 s, cannot be skipped) */
    var bump = $('[data-jx-bumper]', F);
    if (bump) bump.addEventListener('change', function () { S.bumper = bump.checked ? 1 : 0; if (bump.checked) setRadio('jxSkip', '0'); });
    $$('input[name="jxSkip"]', F).forEach(function (r) { r.addEventListener('change', function () { if (bump && r.value !== '0' && r.checked) { bump.checked = false; S.bumper = 0; } }); });

    /* ---- step 2: visual place picker (4.4) ---- */
    var pf = $('[data-jx-pickframe]', F), pwrap = $('[data-jx-pickwrap]', F), pmsg = $('[data-jx-pickmsg]', F), pageSel = $('[data-jx-pick="page"]', F), pTimer = 0, pvReady = null;
    function ensurePreviewCookie(scope) {
      if (!C.can.preview) return Promise.resolve(false);
      return post('preview', { scope: scope }).then(function (j) { return j.code == 1; });
    }
    function suggestPlace() {
      var uk = unitKind(), al = allowed(uk.unit, uk.kind), s = S.w + 'x' + S.h, pick = '';
      var bySize = { '300x250': 'P03', '336x280': 'P03', '728x90': 'P01', '970x90': 'P01', '980x90': 'P06', '468x60': 'P07', '970x250': 'P02', '160x600': 'P04', '120x600': 'P04', '300x600': 'P04', '320x50': 'P01', '300x50': 'P01', '320x100': 'P07', '300x100': 'P07', '250x250': 'P05', '180x150': 'P12' };
      var byUnit = { U12: 'P05', U13: 'P03d', U16: 'P03', U18: 'P02', U19: 'P07', U05: 'P01', U17: 'P04' };
      pick = bySize[s] || byUnit[uk.unit] || 'P03';
      if (al.indexOf(pick) < 0) pick = al.filter(function (p) { return SLOTS.indexOf(p) > -1; })[0] || al[0] || '';
      return pick;
    }
    function framePos() {
      if (!pf || !pwrap) return;
      var dev = radio('jxPickDev') === '2', fw = dev ? 390 : 1280, W = pwrap.clientWidth || 800, H = pwrap.clientHeight || 560, sc = Math.min(1, W / fw);
      pf.style.width = fw + 'px'; pf.style.height = Math.round(H / sc) + 'px'; pf.style.transform = 'scale(' + sc + ')';
      pf.style.left = Math.max(0, Math.round((W - fw * sc) / 2)) + 'px';
    }
    function loadPicker() {
      if (!pf) return;
      var page = pageSel.value || 'home', url = (C.samples[page] || C.samples.home || C.base);
      var uk = unitKind(), dev = radio('jxPickDev') === '2', wv = dev ? (S.wm || S.w) : S.w, hv = dev ? (S.hm || S.h) : S.h;
      pmsg.hidden = false; pmsg.textContent = t('picker_loading') || '…';
      S.framePlaces = null;
      framePos();
      (pvReady || (pvReady = ensurePreviewCookie(S.id ? 'item-' + S.id : 'onoff'))).then(function () {
        pf.src = url + '#jcx-pick=' + encodeURIComponent(C.nonce) + '&w=' + (wv || 0) + '&h=' + (hv || 0) + '&al=' + encodeURIComponent(C.al);
      });
      clearTimeout(pTimer);
      pTimer = setTimeout(function () { if (!S.framePlaces) { pmsg.hidden = false; pmsg.textContent = t('picker_failed'); var dl = $('[data-jx-picklist]', F); if (dl) dl.open = true; } }, 5000);
      placeList();
    }
    if (pageSel) pageSel.addEventListener('change', loadPicker);
    $$('input[name="jxPickDev"]', F).forEach(function (r) { r.addEventListener('change', loadPicker); });
    if (pf) pf.addEventListener('load', function () { setTimeout(density, 600); });
    w.addEventListener('resize', function () { framePos(); frames(true); });
    w.addEventListener('message', function (e) {
      if (e.origin !== location.origin || !e.data || e.data.n !== C.nonce) return;
      if (e.data.jcx === 'picker') { S.framePlaces = e.data.places || []; pmsg.hidden = true; placeList(); density(); }
      if (e.data.jcx === 'pick') {
        var uk = unitKind();
        if (allowed(uk.unit, uk.kind).indexOf(e.data.place) < 0) { showErr('item_place', t('err_place')); return; }
        setPlace(e.data.place);
      }
    });
    function setPlace(p) {
      S.place = p;
      setRadio('jxPlace', p);
      var pk = $('[data-jx-picked]', F); if (pk) pk.textContent = p ? t('picked', C.pl[p] || p) : t('pick_none');
      err('item_place');
      refresh();
      density();
      summary();
    }
    function placeList() {
      var uk = unitKind(), al = allowed(uk.unit, uk.kind), page = pageSel ? pageSel.value : 'home';
      $$('[data-place]', F).forEach(function (lab) {
        var p = lab.getAttribute('data-place'), inp = $('input', lab), why = $('[data-why]', lab), ok = al.indexOf(p) > -1, txt = '';
        lab.hidden = !ok || p === 'P15h' || p === 'P15b' || p === 'P11';
        inp.disabled = false;
        if (ok && S.framePlaces && SLOTS.indexOf(p) > -1 && S.framePlaces.indexOf(p) < 0) {
          var on = (C.place_pages[p] || []).indexOf(page) > -1;
          txt = on ? (p === 'P04' ? t('no_sidebar') : t('pick_other_page', '')) : t('pick_other_page', (C.pt[PAGE_OF[p] || 'home'] || ''));
          if (on && p !== 'P04') txt = t('err_place');
        }
        why.textContent = txt ? '· ' + txt : '';
      });
    }
    $$('input[name="jxPlace"]', F).forEach(function (r) { r.addEventListener('change', function () { setPlace(r.value); }); });
    function density() {
      var out = $('[data-jx-density]', F);
      if (!out || !pf) return;
      var dev = radio('jxPickDev') === '2', doc = null;
      try { doc = pf.contentDocument; } catch (e) {}
      var H = doc && doc.documentElement ? Math.max(doc.documentElement.scrollHeight, 1) : 3000, sum = 0, n = 0;
      if (doc) $$('.jcx-s', doc).forEach(function (b) { var r = b.getBoundingClientRect(); if (r.height > 0) { sum += r.height; n++; } });
      if (S.place && SLOTS.indexOf(S.place) > -1) { sum += (dev ? (S.hm || S.h || 250) : (S.h || 90)) + 20; n++; }
      var pct = Math.round(100 * sum / H), lim = dev ? 30 : 50, warn = dev ? 25 : 40;
      out.className = 'jx-density' + (pct >= warn ? ' is-warn' : '');
      var devT = t(dev ? 'phones' : 'computers').toLowerCase();
      out.textContent = (n === 1 && L.density_1 ? t('density_1', devT, pct, lim) : t('density', devT, n, pct, lim)) + (pct >= warn ? ' ' + t('density_warn') : '');
    }

    /* ---- step 3: frames, summary, draft ---- */
    function pageForItem() {
      var uk = unitKind();
      if (uk.unit === 'U15') return 'play';
      if (uk.unit === 'U14') return 'detail';
      return PAGE_OF[uk.place] || 'home';
    }
    function frames(posOnly) {
      $$('.jx-frame__box', F).forEach(function (box) {
        var fw = +box.getAttribute('data-w'), fh = +box.getAttribute('data-h'), ifr = $('iframe', box), phone = fw < 500;
        var avail = (F.clientWidth || 900) - 40, tw = phone ? Math.min(260, avail) : Math.max(280, Math.min(avail - 290, 900));
        if (!phone && avail < 600) tw = avail;
        var sc = tw / fw;
        box.style.width = Math.round(tw) + 'px'; box.style.height = Math.round(fh * sc) + 'px';
        ifr.style.width = fw + 'px'; ifr.style.height = fh + 'px'; ifr.style.transform = 'scale(' + sc + ')';
        if (!posOnly && pvId()) {
          var su = C.samples[pageForItem()] || C.samples.home;
          ifr.src = alHash(su + (su.indexOf('?') > -1 ? '&' : '?') + 'jxv=' + Date.now());
          ifr.onload = function () { showLayer(ifr, 0); settle(ifr, 0); };
        }
      });
    }
    /* R4r2-11: in each step-3 frame fold the preview panel (it covered a quarter of the computer frame) and scroll the
       frame to the ad's own slot (an ad under the player sits below the fold of a 1280 x 800 frame). Retries while the
       page builds its panel and fills the slot. */
    function settle(ifr, n) {
      setTimeout(function () {
        try {
          var doc = ifr.contentDocument, win = ifr.contentWindow, pan = $('.jcx-pp', doc), hd = pan && $('.jcx-ph', pan), p = unitKind().place, el = null;
          if (hd && !pan.classList.contains('jcx-ppc')) hd.click();
          if (SLOTS.indexOf(p) > -1) el = $$('[data-jcx="' + p + '"]', doc).filter(function (x) { return x.getBoundingClientRect().height > 0; })[0] || null;
          if (el) win.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + win.pageYOffset - 80));
          if ((!pan || (SLOTS.indexOf(p) > -1 && !el)) && n < 12) settle(ifr, n + 1);
        } catch (e) {}
      }, 600);
    }
    /* the row step 3 previews: the draft copy of an ad that exists (R5-01), else the ad / new draft itself */
    function pvId() { return S.draftId || S.id; }
    function showLayer(ifr, n) {
      var uk = unitKind(), id = String(pvId());
      if (['U03', 'U04', 'U06', 'U07', 'U08', 'U09'].indexOf(uk.unit) < 0 || n > 10) return;
      setTimeout(function () {
        try {
          var doc = ifr.contentDocument, b = $('.jcx-pp button[data-jcx-id="' + id + '"]', doc)
            || $$('.jcx-pp button', doc).filter(function (x) { return x.textContent.indexOf('#' + id) > -1; })[0];
          if (!b) { showLayer(ifr, n + 1); return; }
          var fw = ifr.contentWindow;
          ['pointerdown', 'keydown'].forEach(function (t) { try { fw.dispatchEvent(new fw.Event(t)); } catch (er) {} });
          b.click();
          var pan = $('.jcx-pp', doc); if (pan) { pan.style.display = 'none'; ifr.setAttribute('data-jx-layer', id); }
        } catch (e) {}
      }, 700);
    }
    function summary() {
      var out = $('[data-jx-summary]', F);
      if (!out) return;
      var uk = unitKind(), dev = +radio('jxDev') || 3, pm = radio('jxPgMode'), pages = [];
      if (pm === 'pick') $$('[data-jx-pages] input:checked', F).forEach(function (c) { pages.push(C.pt[c.value] || c.value); });
      var devT = dev === 1 ? t('computers') : (dev === 2 ? t('phones') : t('both'));
      var where = uk.place && SLOTS.indexOf(uk.place) > -1 ? t('sum_place', C.pl[uk.place] || uk.place) : t('sum_layer', t('u_' + uk.unit));
      var of = radio('jxOften'), oft = '';
      if (of === 'net') oft = t('often_network') + '.';
      else if (of === 'def' && C.safe_cap[uk.unit]) oft = t('often_custom', C.safe_cap[uk.unit][0], C.safe_cap[uk.unit][1]) + '.';
      else if (of === 'own') oft = t('often_custom', $('#jxCapN').value || 1, $('#jxCapH').value || 24) + '.';
      if (uk.unit === 'U15') { var vo = $('[data-jx-voften]', F); oft = vo ? vo.options[vo.selectedIndex].textContent + '.' : ''; }
      out.textContent = t('summary', devT.toLowerCase(), (pages.length ? pages.join(', ') : t('pages_all')).toLowerCase(), where, oft);
      var rn = $('[data-jx-risknote]', F); if (rn) rn.hidden = !risky();
      var nv = $('[data-jx-noview]', F); if (nv) nv.hidden = !(uk.unit === 'U10' || uk.unit === 'U11' || (uk.kind === 'code' && (uk.place === 'P15h' || uk.place === 'P15b')));
      var on = $('[data-jx-act="save-on"]', F), off = $('[data-jx-act="save-off"]', F);
      if (S.step === 3) {
        on.classList.toggle('ax-btn--primary', !risky()); off.classList.toggle('ax-btn--primary', risky());
      }
    }
    $$('[data-jx-pages] input,[name="jxPgMode"]', F).forEach(function (e) { e.addEventListener('change', function () { $('[data-jx-pages]', F).hidden = radio('jxPgMode') !== 'pick'; summary(); }); });
    function share() {
      var r = $('#jxShare'), o = $('[data-jx-shareout]', F);
      if (!r || !o) return;
      var others = +((C.place_weights || {})[S.place || unitKind().place] || 0), wv = +r.value || 10;
      o.textContent = t('share_pct', Math.round(100 * wv / (wv + others)));
    }
    var shareR = $('#jxShare');
    if (shareR) shareR.addEventListener('input', share);

    /* ---- collect the item (only fields of visible groups) ---- */
    function siteTime(v) {
      if (!v) return 0;
      var p = v.split(/[-T:]/).map(Number);
      return Math.round((Date.UTC(p[0], p[1] - 1, p[2], p[3] || 0, p[4] || 0) / 1000) - (C.tz || 0));
    }
    function fromSiteTime(u) {
      if (!+u) return '';
      var dt = new Date((+u + (C.tz || 0)) * 1000), z = function (n) { return (n < 10 ? '0' : '') + n; };
      return dt.getUTCFullYear() + '-' + z(dt.getUTCMonth() + 1) + '-' + z(dt.getUTCDate()) + 'T' + z(dt.getUTCHours()) + ':' + z(dt.getUTCMinutes());
    }
    function collect() {
      var uk = unitKind(), r = { item_unit: uk.unit, item_kind: uk.kind, item_place: S.place || uk.place || '', item_preset: S.preset || (S.card === 'image' ? '' : ''), media: {}, opts: {} };
      if (uk.place && (uk.place === 'P15h' || uk.place === 'P15b' || allowed(uk.unit, uk.kind).length === 1 || ['P09', 'P10', 'P11'].indexOf(uk.place) > -1)) r.item_place = uk.place;
      if (uk.unit === 'U14') r.item_place = '';
      $$('[data-f]', F).forEach(function (e) {
        if (!vis(e)) return;
        var f = e.getAttribute('data-f');
        if (e.type === 'radio' && !e.checked) return;
        var v = e.type === 'checkbox' ? (e.checked ? 1 : 0) : e.value;
        if (f.indexOf('media.') === 0) { r.media[f.slice(6)] = v; return; }
        if (f.indexOf('opts.') === 0) { if (v !== '') r.opts[f.slice(5)] = v; return; }
        if (f.indexOf('root.') === 0) return;
        r[f] = v;
      });
      $$('[data-m]', F).forEach(function (e) { if (vis(e) && e.value && e.getAttribute('data-m') !== 'card') r.media[e.getAttribute('data-m')] = e.value; });
      $$('[data-o]', F).forEach(function (e) {
        if (!vis(e)) return;
        if (e.type === 'radio' && !e.checked) return;
        r.opts[e.getAttribute('data-o')] = e.type === 'checkbox' ? (e.checked ? 1 : 0) : e.value;
      });
      var m = r.media, o = r.opts;
      if (m.vsrc) { m.src = m.vsrc; } else if (m.vurl) { m.src = m.vurl; }
      delete m.vsrc; delete m.vurl;
      if (typeof m.vast === 'string') { m.vast = m.vast.split(/\s+/).filter(Boolean).slice(0, 5); if (!m.vast.length) delete m.vast; }
      if (m.zip) delete m.zip;
      if (m.expand_img) { o.expand_img = m.expand_img; delete m.expand_img; }
      if (m.wallpaper) { o.wallpaper = m.wallpaper; delete m.wallpaper; }
      if (S.card === 'widget') {
        m.cards = $$('li', cardList).map(function (li) {
          return { img: ($('[data-m="card"]', li) || {}).value || '', title: $('[data-card-f="title"]', li).value, url: $('[data-card-f="url"]', li).value, brand: $('[data-card-f="brand"]', li).value };
        }).filter(function (c) { return c.img || c.title || c.url; });
      }
      if (S.card === 'outstream') {
        var bts = [];
        $$('.jx-btnrow', F).forEach(function (row) { var l = $('[data-btn="label"]', row).value.trim(), u = $('[data-btn="url"]', row).value.trim(); if (l && u) bts.push({ label: l, url: u }); });
        if (bts.length) o.buttons = bts;
      }
      if (S.card === 'preroll') {
        o.roll = (it && it.item_opts && it.item_opts.roll) || 'pre';
        if (S.bumper) { o.bumper = 1; o.skip = 0; o.max_s = 6; }
        // How often (UD5): "Every video" (0) is the default; 'keep' (an own stored limit) sends no limit field, so the
        // server keeps all 4 (P2-03)
        var vo = $('[data-jx-voften]', F);
        if (!vo || vo.value !== 'keep') { r.item_cap_n = 0; r.item_cap_h = 0; r.item_cap_gap = vo ? +vo.value : 0; r.item_skip_first = 0; }
      }
      if (o.trigger === 'welcome') { o.trigger = 'load'; o.welcome = 1; }
      if (uk.unit === 'U08' && o.trigger !== 'load') delete o.welcome;
      if (o.target_id) o.target_id = +o.target_id;
      ['skip', 'every', 'max', 'scroll_pct', 'vertical'].forEach(function (k) { if (o[k] !== undefined && o[k] !== '') o[k] = +o[k]; });
      if (S.card === 'outstream' && o.vertical === 0) delete o.vertical;
      var of = radio('jxOften'), ofG = $('[data-g="often"]', F);
      if (ofG && !ofG.hidden && of !== 'own') { r.item_cap_n = 0; r.item_cap_h = 0; r.item_cap_gap = 0; }
      r.item_pages = radio('jxPgMode') === 'pick' ? $$('[data-jx-pages] input:checked', F).map(function (c) { return c.value; }).reduce(function (a, v) { a.push(v); if (v === 'art') a.push('artlist'); return a; }, []).join(',') : '';
      var days = 0; $$('[data-jx-days] input', F).forEach(function (c) { if (c.checked) days |= 1 << (+c.value - 1); });
      r.item_days = $('[data-jx-days]', F) ? days : 127; // R1-15: no day ticked is refused, never "every day"
      var hf = $('[data-jx-hours="from"]', F).value, ht = $('[data-jx-hours="to"]', F).value, z = function (n) { n = +n; return (n < 10 ? '0' : '') + n; };
      r.item_hours = hf !== '' && ht !== '' ? z(hf) + '-' + z(ht) : '';
      $$('[data-jx-date]', F).forEach(function (e) { r[e.getAttribute('data-jx-date')] = siteTime(e.value); });
      $$('[data-jx-csv]', F).forEach(function (box) { r[box.getAttribute('data-jx-csv')] = $$('input:checked', box).map(function (c) { return c.value; }).join(','); });
      if (S.w) r.item_w = S.w; if (S.h) r.item_h = S.h;
      if (!vis($('[data-f="item_wm"]', F) || F) && S.wm) { r.item_wm = S.wm; r.item_hm = S.hm; }
      if (r.item_preset === '' && S.card === 'image' && S.preset === 'house') r.item_preset = 'house';
      if (S.riskOk) r.item_risk_ok = 1;
      if (S.id) r.item_id = S.id;
      r.item_media = r.media; r.item_opts = r.opts; delete r.media; delete r.opts;
      return r;
    }

    /* ---- errors next to the field (10.2 texts) ---- */
    function err(field) { $$('.jx-err', F).forEach(function (e) { if (!field || e.getAttribute('data-err') === field) { e.hidden = true; e.textContent = ''; } }); }
    function showErr(field, msg, scope) {
      var el = $$('.jx-err', scope || F).filter(function (e) { return e.getAttribute('data-err') === field && vis(e); })[0];
      if (!el && field && field.indexOf('item_media.') === 0) el = $$('.jx-err', F).filter(function (e) { return e.getAttribute('data-err').indexOf('item_media.') === 0 && vis(e); })[0];
      if (!el) { toast(msg, 'error'); status(msg); return; }
      el.textContent = msg; el.hidden = false;
      var sec = el.closest('[data-step]');
      if (sec && +sec.getAttribute('data-step') !== S.step) setStep(+sec.getAttribute('data-step'));
      var md = el.closest('details'); if (md) md.open = true;
      var fld = el.parentNode.querySelector('input:not([type=hidden]),textarea,select,button');
      if (fld) { fld.focus({ preventScroll: true }); }
      el.scrollIntoView({ block: 'center' });
      status(msg);
    }
    function status(msg) { var s = $('[data-jx-status]', F); if (s) s.textContent = msg || ''; }

    /* ---- validation before Next (same texts as the server) ---- */
    var URL_RE = /^https?:\/\/[^\/?#\s]+/i;
    function check1() {
      err();
      if (!S.card) { showErr('card', t('choose_card')); return false; }
      var r = collect(), m = r.item_media || {}, c = S.card;
      if (S.preset === 'adstxt') { showErr('card', t('ph_adstxt') || t('err_kind')); return false; }
      if (vis($('[data-g="url"]', F) || F) && !$('[data-g="url"]', F).hidden) {
        var needUrl = ['image', 'feed', 'link', 'push', 'corner', 'full', 'overlay', 'rails', 'masthead', 'expand', 'sticky'].indexOf(c) > -1 && !net();
        if ((r.item_url || needUrl) && !URL_RE.test(r.item_url || '')) { showErr('item_url', t('err_url')); return false; }
      }
      if (!$('[data-g="img"]', F).hidden && !m.src && !m.srcm) { showErr('item_media.src', t('err_need_file')); return false; }
      if (!$('[data-g="alt"]', F).hidden && ['image', 'sticky', 'corner', 'full', 'overlay', 'rails', 'masthead', 'expand'].indexOf(c) > -1 && !net() && !(r.item_alt || '').trim()) { showErr('item_alt', t('err_alt')); return false; }
      if (!$('[data-g="code"]', F).hidden && !(r.item_code || '').trim() && !(r.item_code_m || '').trim()) { showErr('item_code', t('err_need_code')); return false; }
      if (c === 'feed' && !m.src) { showErr('item_media.src', t('err_need_file')); return false; }
      if (c === 'link' && !(r.item_text || '').trim()) { showErr('item_text', t('err_need_text')); return false; }
      if (c === 'preroll' || c === 'outstream') { if (!m.src && !(m.vast && m.vast.length)) { showErr(vwhat() === 'vast' ? 'media.vast' : 'item_media.vsrc', t('err_need_video')); return false; } }
      if (c === 'widget' && ((m.cards || []).filter(function (x) { return x.img && x.title && URL_RE.test(x.url); }).length < 2)) { showErr('item_media.cards', t('err_need_cards')); return false; }
      if (c === 'sponsored' && !(r.item_opts || {}).target_id) { showErr('opts.target_id', t('err_need_target')); return false; }
      return true;
    }
    function check2() {
      err();
      if (mode() === 'where' && (!S.place || SLOTS.indexOf(S.place) < 0 && S.place !== 'P08')) { showErr('item_place', t('choose_place')); return false; }
      return true;
    }

    /* ---- steps ---- */
    function setStep(n) {
      if (n === 2 && mode() === 'skip') n = S.step === 3 ? 1 : 3;
      S.step = n;
      $$('[data-step]', F).forEach(function (s) { s.hidden = +s.getAttribute('data-step') !== n || (s.getAttribute('data-g') === 'src' && !S.card); });
      var lbl = $('[data-jx-steplbl]'); if (lbl) lbl.textContent = t('step', n);
      var s2 = $('[data-jx-s2t]', F); if (s2) s2.textContent = t(mode() === 'where' ? 'step_where' : 'step_when');
      $('[data-jx-act="back"]', F).hidden = n === 1;
      $('[data-jx-act="next"]', F).hidden = n === 3;
      $('[data-jx-act="save-off"]', F).hidden = n !== 3;
      $('[data-jx-act="save-on"]', F).hidden = n !== 3;
      refresh();
      if (n === 2 && mode() === 'where') {
        if (!S.place || SLOTS.indexOf(S.place) < 0) S.place = suggestPlace();
        pageSel.value = PAGE_OF[S.place] || 'home';
        setPlace(S.place);
        loadPicker();
      }
      if (n === 3) { summary(); frames(true); saveDraft().then(function (ok) { if (ok) ensurePreviewCookie('item-' + pvId()).then(function () { pvReady = null; frames(false); }); }); }
      var h = $('[data-step="' + n + '"]:not([hidden]) h2', F); if (h) { h.tabIndex = -1; h.focus({ preventScroll: true }); }
      // R4-17: show the new step at the top of the screen (on phones the page head and tabs would push it down).
      var sec = n > 1 ? $('[data-step="' + n + '"]:not([hidden])', F) : null;
      if (sec && sec.scrollIntoView) sec.scrollIntoView({ block: 'start' }); else w.scrollTo(0, 0);
    }
    /* Step 3 preview (R5-01): an ad that exists is never saved here; the server keeps a separate draft copy (draftId) that only
       the preview shows. A new ad is saved as an off draft row that becomes the ad on "Save". */
    function saveDraft() {
      var data = collect(); data.draft = 1;
      if (S.draftId) data.draft_id = S.draftId;
      return post('save', data).then(function (j) {
        if (j.code == 1) {
          if (j.copy == 1) { S.draftId = +j.draft_id; }
          else {
            if (!S.id) S.draft = true;
            S.id = +j.id;
            try { history.replaceState(null, '', C.urls.info + (C.urls.info.indexOf('?') > -1 ? '&' : '?') + 'id=' + j.id); } catch (e) {}
          }
          status(j.msg || t('draft_saved'));
          if (j.warn && j.warn.length) warns(j.warn);
          return true;
        }
        showErr(j.field, j.msg);
        return false;
      });
    }
    /* Drops the preview copy (and an unsaved new draft on Cancel); keepalive so it also works while the page closes. */
    function dropDraft(alsoNew) {
      var ids = [];
      if (S.draftId) ids.push(S.draftId);
      if (alsoNew && S.draft && S.id && !S.saved) ids.push(S.id);
      S.draftId = 0;
      if (!ids.length) return Promise.resolve();
      var fd = new FormData();
      fd.append('op', 'drop_draft'); fd.append('id', ids.join(',')); fd.append('__token__', TOKEN);
      return fetch(C.urls.save, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (j) { if (j && j.__token__) TOKEN = j.__token__; }).catch(function () {});
    }
    w.addEventListener('pagehide', function () { if (S.draftId) dropDraft(false); });
    function warns(list) {
      var box = $('[data-jx-warns]', F), body = $('[data-jx-warns-body]', F);
      body.textContent = '';
      (list || []).forEach(function (x) { body.appendChild(E('p', null, null, x.text)); });
      box.hidden = !(list && list.length);
    }
    function save(on) {
      err();
      if (!collect().item_days) { showErr('item_days', t('err_days')); return; }
      var send = function (x) { var data = collect(); data.switch_on = on ? 1 : 0; if (S.draftId) data.drop_draft = S.draftId; return post('save', Object.assign(data, x || {})); };
      var go = function () {
        withChecks(send, typedRisk()).then(function (j) {
          if (j.code == 1) {
            S.id = j.id; S.draft = false; S.saved = true; S.draftId = 0;
            AxUX.markClean();
            done(j);
            status(j.msg);
            if (j.warn && j.warn.length) warns(j.warn);
            var see = $('[data-jx-act="see"]', F); see.hidden = false; see.focus();
            $('[data-jx-act="cancel"]', F).hidden = true;
            var cf = $('[data-jx-conflict]', F); if (cf) cf.hidden = true;
          } else if (j.conflicts) {
            var cb = $('[data-jx-conflict-body]', F); cb.textContent = '';
            j.conflicts.forEach(function (c) { var p = E('p', null, null, c.text + ' '); (c.items || []).forEach(function (x) { var a = E('a', null, { href: C.urls.info + (C.urls.info.indexOf('?') > -1 ? '&' : '?') + 'id=' + x[0] }, x[1]); p.appendChild(a); p.appendChild(d.createTextNode(' ')); }); cb.appendChild(p); });
            $('[data-jx-conflict]', F).hidden = false;
          } else if (!j.need_risk) { showErr(j.field, j.msg); }
        });
      };
      if (on && risky() && !S.riskOk) riskConfirm(typedRisk()).then(function (y) { if (y) { S.riskOk = 1; go(); } });
      else go();
    }

    F.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-jx-act]');
      if (!b) return;
      var a = b.getAttribute('data-jx-act');
      if (a === 'next') { if (S.step === 1 && check1()) setStep(2); else if (S.step === 2 && check2()) setStep(3); }
      else if (a === 'back') { setStep(S.step === 3 && mode() !== 'skip' ? 2 : 1); }
      else if (a === 'save-off') save(false);
      else if (a === 'save-on') save(true);
      else if (a === 'frames') frames(false);
      else if (a === 'see') { var x = E('button', null, { 'data-scope': 'item-' + S.id }); preview(x); }
      else if (a === 'cancel') {
        AxUX.markClean();
        // R5-01: Cancel leaves the ad exactly as it was: only the preview copy / the unsaved new draft go, nothing is rebuilt.
        dropDraft(true).then(function () { location.href = C.urls.lists; });
      }
    });
    F.addEventListener('input', function (e) { if (e.target.closest('[data-step="3"]')) summary(); });
    F.addEventListener('change', function (e) { if (e.target.closest('[data-step="3"]')) summary(); });

    /* ---- fill from an existing item ---- */
    function fill(r) {
      S.id = +r.item_id; S.place = r.item_place; S.preset = r.item_preset || '';
      if (r.item_ex_code === 'DRF') S.draft = true; // a new ad's draft row (page reloaded before the first save)
      S.kind = (r.item_kind === 'code' || r.item_kind === 'html') ? 'net' : 'own';
      setRadio('jxKind', S.kind);
      setCard(C.card, true);
      setRadio('jxPreset', S.preset);
      if (netSel) netSel.value = r.item_network || '';
      var m = r.item_media || {}, o = r.item_opts || {};
      $$('[data-f]', F).forEach(function (e) {
        var f = e.getAttribute('data-f');
        if (f.indexOf('opts.') === 0) { if (o[f.slice(5)] != null) e.value = o[f.slice(5)]; return; }
        if (f === 'media.vast') { e.value = (m.vast || []).join('\n'); return; }
        if (f === 'media.vurl') { if (m.src && /^https?:/i.test(m.src)) e.value = m.src; return; }
        if (r[f] == null) return;
        if (e.type === 'radio') e.checked = String(e.value) === String(r[f]);
        else if (e.type === 'checkbox') e.checked = +r[f] === 1;
        else if (f !== 'item_w' && f !== 'item_h') e.value = r[f];
      });
      setSize(r.item_w, r.item_h); S.wm = +r.item_wm; S.hm = +r.item_hm;
      var a2 = $('[data-f="item_wm"]', F), b2 = $('[data-f="item_hm"]', F); if (a2 && S.wm) a2.value = S.wm; if (b2 && S.hm) b2.value = S.hm;
      $$('.jx-up', F).forEach(function (box) {
        var k = box.getAttribute('data-up'), p = k === 'vsrc' ? (m.src && !/^https?:/i.test(m.src) ? m.src : '') : (k === 'expand_img' || k === 'wallpaper' ? o[k] : m[k]);
        if (k === 'zip' && C.cards[C.card] && C.card === 'html5' && r.item_id) { upBox(box, 'zip', '', 'index.html'); return; }
        if (p && typeof p === 'string') upBox(box, p, /^https?:/i.test(p) ? p : C.base + p.replace(/^\//, ''), '');
      });
      (m.cards || []).forEach(addCard);
      if (m.vast && m.vast.length) setRadio('jxVWhat', 'vast');
      ['skip', 'start', 'layout', 'close_mem'].forEach(function (k) { if (o[k] != null) $$('[data-o="' + k + '"]', F).forEach(function (e) { e.checked = String(e.value) === String(o[k]); }); });
      if (o.trigger) setRadio('jxTrig', o.welcome ? 'welcome' : o.trigger);
      $$('[data-o="every"],[data-o="max"],[data-o="scroll_pct"]', F).forEach(function (e) { if (o[e.getAttribute('data-o')] != null) e.value = o[e.getAttribute('data-o')]; });
      var vc = $('[data-o="vertical"]', F); if (vc) vc.checked = !!o.vertical;
      (o.buttons || []).forEach(function (b, i) { var row = $$('.jx-btnrow', F)[i]; if (row) { $('[data-btn="label"]', row).value = b.label; $('[data-btn="url"]', row).value = b.url; } });
      if (o.bumper) { S.bumper = 1; if (bump) bump.checked = true; }
      var vo = $('[data-jx-voften]', F); if (vo && r.item_unit === 'U15') oftenKeep(vo, r, ['0', '30', '60', '1440']);
      if (+r.item_cap_n > 0) setRadio('jxOften', 'own');
      if (r.item_pages) { setRadio('jxPgMode', 'pick'); $('[data-jx-pages]', F).hidden = false; $$('[data-jx-pages] input', F).forEach(function (c) { c.checked = r.item_pages.split(',').indexOf(c.value) > -1; }); }
      $$('[data-jx-days] input', F).forEach(function (c) { c.checked = !!(+r.item_days & (1 << (+c.value - 1))); });
      if (r.item_hours) { var hp = r.item_hours.split('-'); $('[data-jx-hours="from"]', F).value = +hp[0]; $('[data-jx-hours="to"]', F).value = +hp[1]; }
      $$('[data-jx-date]', F).forEach(function (e) { e.value = fromSiteTime(r[e.getAttribute('data-jx-date')]); });
      $$('[data-jx-csv]', F).forEach(function (box) { var v = String(r[box.getAttribute('data-jx-csv')] || '').split(','); $$('input', box).forEach(function (c) { c.checked = v.indexOf(c.value) > -1; }); });
      if (r.item_place === 'P15h' || r.item_place === 'P15b') setRadio('jxWhere', r.item_place); else if (S.preset === 'any') setRadio('jxWhere', 'place');
      S.riskOk = +r.item_risk_ok;
      if (o.target_id) { var ti = $('[data-f="opts.target_id"]', F); if (ti) ti.value = o.target_id; }
      refresh();
    }

    if (it) fill(it);
    else if (C.card) {
      setCard(C.card);
      if (C.preset) { setRadio('jxPreset', C.preset); var pr = $('input[name="jxPreset"][value="' + C.preset + '"]', F); if (pr) pr.dispatchEvent(new Event('change')); }
      if (C.preset === 'house') S.preset = 'house';
    } else refresh();
    if (C.place) S.place = C.place;
    if (w.innerWidth < 768) setRadio('jxPickDev', '2');
    var q = /[?&]step=(\d)/.exec(location.search);
    setStep(q ? Math.min(3, Math.max(1, +q[1])) : 1);
    AxUX.markClean();
  }

  /* ------------------------------------------------------------ Places tab */
  function initPlaces() {
    d.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-jx-act]');
      if (!b) return;
      var a = b.getAttribute('data-jx-act'), tr = b.closest('tr');
      if (a === 'plswitch') {
        var on = b.getAttribute('aria-checked') !== 'true';
        post('status', { pl: tr.getAttribute('data-pl'), status: on ? 1 : 0 }).then(function (j) { done(j, j.code == 1); });
      } else if (a === 'plsave') {
        var f = b.closest('form'), data = { pl: f.getAttribute('data-pl') };
        $$('input[name]', f).forEach(function (i) { data[i.name] = i.value; });
        post('places', data).then(function (j) { done(j, j.code == 1); if (j.warn && j.warn.length) toast(j.warn[0].text, 'warn'); });
      } else if (a === 'ancdel') {
        post('anchor', { del: b.getAttribute('data-id') }).then(function (j) { done(j, j.code == 1); });
      } else if (a === 'ancsave') {
        var fa = b.closest('form'), dt = {}, er = $('.jx-err', fa);
        $$('[name]', fa).forEach(function (i) { dt[i.name] = i.value; });
        er.hidden = true;
        post('anchor', dt).then(function (j) { if (j.code != 1) { er.textContent = j.msg; er.hidden = false; return; } done(j, true); });
      }
    });
    var pf = $('[data-jx-pickframe]'), wrap = $('[data-jx-pickwrap]'), sel = $('[data-jx-pick="page"]');
    var pdev = $$('input[name="jxPlDev"]');
    if (w.innerWidth < 640) pdev.forEach(function (r) { r.checked = r.value === '2'; });
    function fwide() { var r = pdev.filter(function (x) { return x.checked; })[0]; return r && r.value === '2' ? 390 : 1280; }
    function pos() { if (!pf) return; var fw = fwide(), W = wrap.clientWidth || 800, sc = Math.min(1, W / fw); pf.style.width = fw + 'px'; pf.style.height = Math.round((wrap.clientHeight || 560) / sc) + 'px'; pf.style.transform = 'scale(' + sc + ')'; pf.style.left = Math.max(0, Math.round((W - fw * sc) / 2)) + 'px'; }
    function load() {
      if (!pf) return;
      pos();
      post('preview', { scope: 'onoff' }).then(function () { pf.src = (C.samples[sel.value] || C.base) + '#jcx-pick=' + encodeURIComponent(C.nonce) + '&w=0&h=0&al=' + encodeURIComponent(C.al); });
    }
    pdev.forEach(function (r) { r.addEventListener('change', load); });
    if (sel) { sel.addEventListener('change', load); load(); }
    w.addEventListener('resize', pos);
    w.addEventListener('message', function (e) {
      if (e.origin !== location.origin || !e.data || e.data.n !== C.nonce) return;
      if (e.data.jcx === 'picker') {
        var m = $('[data-jx-pickmsg]'); if (m) m.hidden = true;
        /* R4r2-15: on this page a box only finds its row, so it carries the place name and its count, not "Put it here" */
        try {
          $$('.jcx-pk[data-jcx]', pf.contentDocument).forEach(function (b, i) {
            var p = b.getAttribute('data-jcx'), n = +((C.pl_on || {})[p] || 0);
            b.textContent = (i + 1) + '. ' + (C.pl[p] || p) + ' · ' + t('places_pk_on', n);
          });
        } catch (er) {}
      }
      if (e.data.jcx === 'pick') {
        $$('tr.is-hit').forEach(function (r) { r.classList.remove('is-hit'); });
        var row = $('tr[data-pl="' + e.data.place + '"]');
        if (row) { row.classList.add('is-hit'); row.scrollIntoView({ block: 'center' }); }
      }
    });
  }

  /* ------------------------------------------------------------ Video ads tab (UD4: pre-roll and rewarded pre-roll only) */
  /* How often of a video ad (UD5): a stored limit that is one of the 4 choices selects it (none = "Every video"); any
     other (N times per H hours, another gap, not on the first page) gets a "Current: ..." choice (value keep) that
     saves the limit unchanged (P2-03: it was shown as 30 min and saved as 30 min) */
  function oftenKeep(sel, r, vals) {
    var g = +r.item_cap_gap || 0, n = +r.item_cap_n || 0, i = [0, 30, 60, 1440].indexOf(g), k = $('option[value="keep"]', sel), p = [];
    if (k) k.remove();
    if (i > -1 && !n && !+r.item_skip_first) { sel.value = vals[i]; return; }
    if (n) p.push(t('often_custom', n, +r.item_cap_h || 24));
    if (g) p.push(t('often_gap', g));
    if (+r.item_skip_first) p.push(t('often_first'));
    k = E('option', '', { value: 'keep' }, t('often_keep', p.join(', ')));
    sel.insertBefore(k, sel.firstChild);
    sel.value = 'keep';
  }
  function initVideo() {
    $$('form[data-vcard]').forEach(function (f) {
      var card = f.getAttribute('data-vcard'), data = (C.vcards || {})[card];
      function radio(n) { var r = $('input[name="' + n + '"]:checked', f); return r ? r.value : ''; }
      function sync() {
        var vast = radio('what_' + card) === 'vast';
        $$('[data-v="file"]', f).forEach(function (e) { e.hidden = vast; });
        $$('[data-v="vast"]', f).forEach(function (e) { e.hidden = !vast; });
        var pod = $('[name="pod"]', f); $$('[data-v="pod"]', f).forEach(function (e) { e.hidden = !(pod && pod.checked); });
      }
      if (data) {
        var m = data.item_media || {}, o = data.item_opts || {};
        $('[name="item_id"]', f).value = data.item_id;
        $('[name="on"]', f).checked = +data.item_status === 1;
        if (m.vast && m.vast.length) { $$('input[name="what_' + card + '"]', f).forEach(function (r) { r.checked = r.value === 'vast'; }); $('[name="vast"]', f).value = m.vast.join('\n'); }
        if (m.src) $('[name="src"]', f).value = m.src;
        var u = $('[name="url"]', f); if (u) u.value = data.item_url || '';
        $$('[name="skip"]', f).forEach(function (r) { r.checked = String(r.value) === String(o.skip != null ? o.skip : 5); });
        $$('[name="start"]', f).forEach(function (r) { r.checked = r.value === (o.start || 'click'); });
        var of = $('[name="often"]', f); if (of) oftenKeep(of, data, ['every', '30m', '1h', 'day']);
        var rh = $('[name="reward_h"]', f); if (rh && o.reward_h) rh.value = o.reward_h;
        var rt = $('[name="reward_text"]', f); if (rt) rt.value = data.item_text || '';
        var bu = $('[name="bumper"]', f); if (bu) bu.checked = !!o.bumper;
        if (m.pod && m.pod[1]) { $('[name="pod"]', f).checked = true; $('[name="pod_src"]', f).value = m.pod[1].src; }
      } else if (card === 'pre' && C.old_url) { $('[name="src"]', f).value = ''; }
      f.addEventListener('change', sync);
      sync();
      $$('[data-jx-act="vchoose"]', f).forEach(function (b) {
        var inp = b.parentNode.querySelector('input[type=file]'), target = $('[name="' + b.getAttribute('data-target') + '"]', f);
        b.addEventListener('click', function () { inp.click(); });
        inp.addEventListener('change', function () {
          if (!inp.files[0]) return;
          if (inp.files[0].size > 20971520) { toast(t('err_size'), 'error'); inp.value = ''; return; }
          b.disabled = true;
          post('upload', {}, inp.files[0]).then(function (j) { b.disabled = false; inp.value = ''; if (j.code == 1) { target.value = j.path; toast(j.msg); } else toast(j.msg, 'error'); });
        });
      });
      $('[data-jx-act="vsave"]', f).addEventListener('click', function () {
        var dt = { card: card }, er = $('.jx-err', f);
        $$('[name]', f).forEach(function (i) {
          if (i.type === 'file') return;
          if (i.type === 'radio') { if (i.checked) dt[i.name.replace('_' + card, '')] = i.value; return; }
          dt[i.name] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value;
        });
        er.hidden = true;
        post('video', dt).then(function (j) {
          if (j.code != 1) { er.textContent = j.msg; er.hidden = false; return; }
          done(j, true);
        });
      });
    });
    var ou = $('[data-jx-act="old-use"]');
    if (ou) ou.addEventListener('click', function () { var s = $('form[data-vcard="pre"] [name="src"]'); s.value = C.old_url; s.focus(); });
  }

  /* ------------------------------------------------------------ Network & files tab */
  function initNetwork() {
    d.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-jx-act]');
      if (!b) return;
      var a = b.getAttribute('data-jx-act');
      if (a === 'rootsave' || a === 'rootdel') {
        var f = b.closest('form'), er = $('.jx-err', f), dt = {};
        if (a === 'rootdel') {
          AxUX.confirm({ title: b.textContent.trim(), message: b.textContent.trim() + '?', count: 1, confirmLabel: b.textContent.trim() }).then(function (y) { if (y) post('root', { del: b.getAttribute('data-id') }).then(function (j) { done(j, j.code == 1); }); });
          return;
        }
        $$('[name]', f).forEach(function (i) { dt[i.name] = i.value; });
        er.hidden = true;
        post('root', dt).then(function (j) {
          if (j.code != 1) { er.textContent = j.msg; er.hidden = false; return; }
          if (j.state === 'notwritable') {
            var blob = new Blob([dt.rf_body || ''], { type: 'text/plain' }), lk = E('a', 'ax-btn ax-btn--sm', { href: URL.createObjectURL(blob), download: j.name || 'ads.txt' }, t('root_download'));
            er.textContent = j.msg + ' '; er.appendChild(lk); er.hidden = false; return;
          }
          done(j, true);
        });
      } else if (a === 'move-tj') {
        post('save', { op: 'move_tj' }).then(function (j) { done(j); if (j.code == 1 && j.url) setTimeout(function () { location.href = j.url; }, 800); });
      } else if (a === 'nswitch') {
        var tr = b.closest('tr'), on = b.getAttribute('aria-checked') !== 'true';
        withChecks(function (x) { return post('status', Object.assign({ ids: tr.getAttribute('data-id'), status: on ? 1 : 0 }, x)); }, tr.getAttribute('data-unit') === 'U10').then(function (j) { done(j, j.code == 1); });
      }
    });
  }

  /* ------------------------------------------------------------ Settings tab */
  function initSettings() {
    var f = $('#jxSet');
    if (!f) return;
    function send(extra) {
      var dt = {};
      $$('[name]', f).forEach(function (i) {
        if (i.type === 'checkbox' && i.name !== 'vip[]') dt[i.name] = i.checked ? 1 : 0;
        else if (i.type !== 'checkbox') dt[i.name] = i.value;
      });
      dt.vip = $$('input[name="vip[]"]:checked', f).map(function (c) { return c.value; }).join(',');
      return post('settings', Object.assign(dt, extra || {}));
    }
    $('[data-jx-act="setsave"]', f).addEventListener('click', function () {
      var safe = $('[name="safe"]', f);
      if (safe && !safe.checked && C.safe) {
        AxUX.confirm({ title: L.set_safe_off ? t('chrome_risk') : '', message: t('set_safe_off', 'I UNDERSTAND'), typed: 'I UNDERSTAND', count: 1 }).then(function (y) {
          if (y) send({ typed: 'I UNDERSTAND' }).then(function (j) { done(j, j.code == 1); }); else safe.checked = true;
        });
        return;
      }
      send().then(function (j) { done(j, j.code == 1); AxUX.markClean(); });
    });
    d.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-jx-act]');
      if (!b) return;
      var a = b.getAttribute('data-jx-act');
      if (a === 'reset') AxUX.confirm({ title: b.textContent.trim(), message: t('reset_stats_confirm'), count: 1, confirmLabel: b.textContent.trim() }).then(function (y) { if (y) post('reset').then(function (j) { done(j, true); }); });
      if (a === 'removeall') AxUX.confirm({ title: b.textContent.trim(), message: t('remove_all_confirm', 'DELETE'), typed: 'DELETE', count: 1, confirmLabel: b.textContent.trim() }).then(function (y) {
        if (y) post('removeall', { typed: 'DELETE' }).then(function (j) { done(j); if (j.code == 1) setTimeout(function () { location.href = j.url || C.urls.index; }, 900); });
      });
    });
  }

  var page = ($('[data-jx-page]') || {}).getAttribute ? $('[data-jx-page]').getAttribute('data-jx-page') : '';
  if (page === 'index') health();
  if (page === 'lists') initLists();
  if (page === 'info') initEditor();
  if (page === 'places') initPlaces();
  if (page === 'video') initVideo();
  if (page === 'network') initNetwork();
  if (page === 'settings') initSettings();
})(window, document);
