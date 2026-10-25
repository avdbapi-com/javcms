/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* Overview page (ADMIN-IA-BRIEF.md 4.7): tab links, "Show all", checklist hide / show / done, Refresh.
   Links with data-tab open as shell tabs through window.parent.AdminShell (plain links when opened alone). */
(function () {
    'use strict';
    var root = document.querySelector('.ov');
    if (!root) return;
    var tokenEl = document.getElementById('ovToken');
    var url = document.getElementById('ovUrl').value;
    var shell = null;
    try { if (window.parent !== window && window.parent.AdminShell) shell = window.parent.AdminShell; } catch (e) { shell = null; }

    root.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[data-tab]');
        if (!a || !shell || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var tab = a.getAttribute('data-tab');
        if (!tab) return;
        e.preventDefault();
        shell.open(tab, { url: a.href });
    });

    var more = root.querySelector('[data-ov-more]');
    if (more) more.addEventListener('click', function () {
        Array.prototype.forEach.call(root.querySelectorAll('[data-more]'), function (row) { row.hidden = false; });
        more.hidden = true;
        var first = root.querySelector('.ov-attention [data-more] a, .ov-attention [data-more] button');
        if (first) first.focus();
    });

    function send(data, retried) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        body.append('__ux_token__', tokenEl.value);
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.locked) { try { window.parent.postMessage({ ax: 'locked' }, location.origin); } catch (e) { /* alone */ } return res; }
                if (res && res.data && res.data.__ux_token__) tokenEl.value = res.data.__ux_token__;
                // The token slot is shared with "My account": one silent retry with the fresh token.
                if (res && Number(res.code) !== 1 && res.data && res.data.retry && !retried) return send(data, true);
                return res;
            });
    }

    var gs = root.querySelector('[data-gs]');
    var gsShow = root.querySelector('[data-gs-show]');
    root.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-gs-act]');
        if (!b) return;
        var act = b.getAttribute('data-gs-act');
        var data = { 'do': act };
        if (b.hasAttribute('data-step')) data.step = b.getAttribute('data-step');
        b.disabled = true;
        send(data, false).then(function (res) {
            b.disabled = false;
            if (!res || Number(res.code) !== 1) {
                if (window.AxUX && !(res && res.locked)) window.AxUX.toast((res && res.msg) || window.AxUX.t('request_failed'), 'error');
                return;
            }
            if (act === 'gs_hide') { gs.hidden = true; gsShow.hidden = false; gsShow.querySelector('button').focus(); }
            else if (act === 'gs_show') { gs.hidden = false; gsShow.hidden = true; var h = gs.querySelector('button'); if (h) h.focus(); }
            else { window.location.reload(); }
        }).catch(function () {
            b.disabled = false;
            if (window.AxUX) window.AxUX.toast(window.AxUX.t('request_failed'), 'error');
        });
    });
}());
