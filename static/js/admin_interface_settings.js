/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
(function () {
    'use strict';
    var form = document.querySelector('[data-interface-form]');
    if (!form || form.dataset.interfaceBound) return;
    form.dataset.interfaceBound = '1';
    var status = form.querySelector('[data-interface-status]');
    var strings = window.adminInterfaceStrings || {};
    form.addEventListener('click', function (event) {
        var button = event.target.closest('[data-interface-append]');
        if (!button || !form.contains(button)) return;
        var card = button.closest('[data-interface-map]');
        var local = card.querySelector('[data-interface-category]').value;
        var remoteInput = card.querySelector('[data-interface-remote]');
        var remote = remoteInput.value.trim();
        if (!local || !remote || /[\r\n]/.test(remote) || /=/.test(local)) {
            status.textContent = strings.required || ''; remoteInput.focus(); return;
        }
        var area = card.querySelector('textarea');
        area.value = area.value.replace(/\r\n|\r/g, '\n').replace(/\n*$/, '') + (area.value.trim() ? '\n' : '') + local + '=' + remote + '\n';
        area.dispatchEvent(new Event('input', {bubbles:true}));
        remoteInput.value = ''; status.textContent = strings.added || ''; area.focus();
    });
    form.addEventListener('reset', function () {
        setTimeout(function () { status.textContent = ''; if (window.layui && layui.form) layui.form.render(); }, 0);
    });
    // Existing shared formSubmit owns the request. Observe only this route's response.
    if (window.jQuery) jQuery(document).ajaxComplete(function (event, xhr, options) {
        if (!options || String(options.type || '').toUpperCase() !== 'POST') return;
        var url;
        try { url = new URL(options.url || '', location.href); } catch (error) { return; }
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        var response = xhr.responseJSON;
        if (!response) { try { response = JSON.parse(xhr.responseText); } catch (error) { return; } }
        var token = form.querySelector('[name="__token__"]');
        var fresh = response.__token__ || (response.data && response.data.__token__);
        if (token && typeof fresh === 'string' && fresh) token.value = token.defaultValue = fresh;
        if (response.code === 0 && typeof response.msg === 'string') status.textContent = response.msg;
    });
}());
