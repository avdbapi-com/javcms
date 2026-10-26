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
    var root = document.getElementById('admin-pay-settings');
    if (!root || root.dataset.payBound) return;
    root.dataset.payBound = '1';
    var status = root.querySelector('[data-pay-status]');
    root.addEventListener('reset', function () {
        window.setTimeout(function () { status.textContent = ''; if (window.layui && window.layui.form) window.layui.form.render(); }, 0);
    });
    if (window.layui) window.layui.use(['element','form'], function () {
        var element = window.layui.element;
        element.on('tab(tb1)', function () {
            if (window.jQuery && window.jQuery.cookie) window.jQuery.cookie('configpay_tab', this.getAttribute('lay-id'));
        });
        var saved = window.jQuery && window.jQuery.cookie && window.jQuery.cookie('configpay_tab');
        if (saved && Array.prototype.some.call(root.querySelectorAll('[lay-id]'), function (item) { return item.getAttribute('lay-id') === saved; })) element.tabChange('tb1', saved);
    });
    if (!window.jQuery) return;
    window.jQuery(document).on('ajaxSuccess.paySettings', function (event, xhr, options, data) {
        if (!options || String(options.type || '').toUpperCase() !== 'POST') return;
        var url;
        try { url = new URL(options.url || '', location.href); } catch (error) { return; }
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        var token = data && (data.__token__ || (data.data && data.data.__token__));
        var input = root.querySelector('[name="__token__"]');
        if (input && typeof token === 'string' && token) input.value = input.defaultValue = token;
        if (data && data.code === 0 && typeof data.msg === 'string') status.textContent = data.msg;
    });
}());
