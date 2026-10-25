/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
(function () {
    'use strict';
    function init() {
        var root = document.getElementById('admin-connect-settings');
        if (!root || root.dataset.connectBound) return;
        root.dataset.connectBound = '1';
        root.addEventListener('reset', function () {
            window.setTimeout(function () {
                if (window.layui && window.layui.form) window.layui.form.render();
            }, 0);
        });
        if (!window.jQuery) return;
        window.jQuery(document).on('ajaxSuccess.connectSettings', function (event, xhr, options, data) {
            if (!options || !/post/i.test(options.type || '') || !/\/system\/configconnect(?:\.|\/|\?|$)/i.test(options.url || '')) return;
            var token = data && (data.__token__ || (data.data && data.data.__token__));
            if (typeof token !== 'string' || !token) return;
            root.querySelectorAll('[name="__token__"]').forEach(function (input) {
                input.value = token;
                input.defaultValue = token;
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
