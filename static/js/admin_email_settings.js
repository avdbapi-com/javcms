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
    function init() {
        var form = document.getElementById('admin-email-form');
        var button = document.getElementById('admin-email-test');
        var status = document.getElementById('admin-email-test-status');
        if (!form || !button || !status || button.dataset.emailBound) return;
        button.dataset.emailBound = '1';
        var pending = false;
        var label = button.textContent;

        button.addEventListener('click', function () {
            if (pending) return;
            var recipient = form.querySelector('#test');
            if (!recipient || !recipient.value.trim() || !recipient.checkValidity()) {
                status.textContent = button.dataset.invalid;
                if (recipient) recipient.focus();
                return;
            }
            pending = true;
            var submitButtons = Array.prototype.slice.call(form.querySelectorAll('[type="submit"]'));
            var previousDisabled = submitButtons.map(function (control) { return control.disabled; });
            submitButtons.forEach(function (control) { control.disabled = true; });
            button.disabled = true;
            button.textContent = button.dataset.loading;
            status.textContent = button.dataset.loading;
            form.setAttribute('aria-busy', 'true');

            window.jQuery.ajax({
                url: button.dataset.url,
                type: 'POST',
                dataType: 'json',
                data: window.jQuery(form).serialize(),
                timeout: 30000,
                success: function (response) {
                    var token = response && (response.__token__ || (response.data && response.data.__token__));
                    var tokenInput = form.querySelector('input[name="__token__"]');
                    if (token && tokenInput) {
                        tokenInput.value = token;
                        tokenInput.defaultValue = token;
                    }
                    status.textContent = response && typeof response.msg === 'string' ? response.msg : button.dataset.error;
                },
                error: function () { status.textContent = button.dataset.error; },
                complete: function () {
                    pending = false;
                    button.disabled = false;
                    button.textContent = label;
                    form.removeAttribute('aria-busy');
                    submitButtons.forEach(function (control, index) { control.disabled = previousDisabled[index]; });
                }
            });
        });

        form.addEventListener('submit', function (event) {
            if (pending) {
                event.preventDefault();
                event.stopImmediatePropagation();
                status.textContent = button.dataset.loading;
            }
        }, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
