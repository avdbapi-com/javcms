/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
(function () {
    'use strict';

    // Sitemap page (make/opt): Copy the sitemap address and "Rebuild sitemap" (AJAX to make/map, result line in the page).
    function init() {
        var status = document.getElementById('admin-sitemap-status');
        var copy = document.getElementById('admin-sitemap-copy');
        var rebuild = document.getElementById('admin-sitemap-rebuild');
        if (!status || (copy && copy.dataset.sitemapBound)) return;

        function say(text, kind) {
            status.textContent = text || '';
            if (window.AxUX && text) { window.AxUX.toast(text, kind || 'ok'); }
        }

        if (copy) {
            copy.dataset.sitemapBound = '1';
            var input = document.getElementById(copy.getAttribute('data-copy-target'));
            var fallbackCopy = function () {
                var focused = document.activeElement;
                input.focus();
                input.select();
                input.setSelectionRange(0, input.value.length);
                var copied = false;
                try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
                if (focused && focused.focus) focused.focus();
                return copied;
            };
            copy.addEventListener('click', function () {
                if (copy.disabled || !input || !input.value.trim()) return;
                var finish = function (ok) { say(ok ? copy.dataset.success : copy.dataset.failure, ok ? 'ok' : 'error'); };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(function () { finish(true); }, function () { finish(fallbackCopy()); });
                } else {
                    finish(fallbackCopy());
                }
            });
        }

        if (rebuild) {
            var busy = false;
            rebuild.addEventListener('click', function () {
                if (busy || rebuild.disabled) return;
                busy = true;
                rebuild.disabled = true;
                rebuild.setAttribute('aria-busy', 'true');
                status.textContent = rebuild.dataset.loading || '';
                var done = function () { busy = false; rebuild.disabled = false; rebuild.removeAttribute('aria-busy'); };
                window.jQuery.ajax({
                    url: rebuild.dataset.url,
                    type: 'POST',
                    dataType: 'json',
                    data: { rebuild: 1, __token__: rebuild.dataset.token || '' },
                    timeout: 120000
                }).done(function (res) {
                    say(res && res.msg ? res.msg : rebuild.dataset.failure, res && res.code == 1 ? 'ok' : 'error');
                }).fail(function () {
                    say(rebuild.dataset.failure, 'error');
                }).always(done);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
