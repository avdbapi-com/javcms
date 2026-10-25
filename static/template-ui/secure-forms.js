/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* JAVCMS native account forms. Session tokens are fetched immediately before protected writes. */
(function (window, $) {
    'use strict';
    if (!$ || !$.ajax || !window.maccms || $.ajax.javcmsSecureForms) return;
    var routes = window.maccms.template_urls || {}, original = $.ajax;
    var forms = ['info', 'bind', 'buy', 'upgrade', 'cash'];
    var writes = ['unbind', 'bindmsg', 'ulog_del', 'plog_del', 'cash_del', 'ajax_buy_popedom'];
    var queue = $.Deferred().resolve().promise();
    var active = true, hidden = false, requests = [];
    function cancelRequests() {
        active = false;
        requests.slice().forEach(function (request) { request.abort(); });
    }
    function resumeUserAction(event) {
        // A canceled navigation may keep this document alive without another pageshow.
        if (!hidden && event.isTrusted) active = true;
    }
    if (typeof window.addEventListener === 'function') {
        window.addEventListener('beforeunload', cancelRequests);
        window.addEventListener('pagehide', function () {
            hidden = true;
            cancelRequests();
        });
        window.addEventListener('pageshow', function () { hidden = false; active = true; });
        window.addEventListener('pointerdown', resumeUserAction, true);
        window.addEventListener('keydown', resumeUserAction, true);
    }
    function key(value) {
        try {
            var url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password) return '';
            return url.pathname.replace(/\.html\/?$/, '').replace(/\/+$/, '') + '|' + (url.searchParams.get('s') || '').replace(/\/+$/, '');
        } catch (error) { return ''; }
    }
    var keys = {};
    forms.concat(writes, ['ajax_ulog']).forEach(function (action) {
        var value = routes['user_' + action];
        if (typeof value === 'string' && key(value)) keys[key(value)] = action;
    });
    $.ajax = function (url, options) {
        var settings = typeof url === 'string' ? $.extend({}, options || {}, {url:url}) : $.extend({}, url || {});
        var action = keys[key(settings.url)], method = String(settings.type || settings.method || 'GET').toUpperCase();
        if (!action) return original.apply(this, arguments);
        var encoded = typeof settings.data === 'string' ? settings.data : $.param(settings.data || {});
        var fields = new URLSearchParams(encoded), urlFields;
        try { urlFields = new URL(settings.url, window.location.href).searchParams; } catch (error) {}
        var historyWrite = action === 'ajax_ulog' && (fields.get('ac') || (urlFields && urlFields.get('ac'))) === 'set';
        if (!action || (!historyWrite && writes.indexOf(action) < 0 && !(forms.indexOf(action) >= 0 && method === 'POST'))) return original.apply(this, arguments);
        var pending = $.Deferred(), cancelled = false, tokenRequest, writeRequest;
        function fail(xhr, status, error) {
            if (pending.state() !== 'pending') return;
            pending.rejectWith(settings.context || settings, [xhr, status, error]);
            if (settings.error) settings.error.call(settings.context || settings, xhr, status, error);
            if (settings.complete) settings.complete.call(settings.context || settings, xhr, status);
        }
        var promise = pending.promise({abort:function () {
            if (pending.state() !== 'pending') return this;
            cancelled = true;
            if (tokenRequest) tokenRequest.abort();
            if (writeRequest) writeRequest.abort();
            fail(null, 'abort', 'abort');
            return this;
        }});
        requests.push(promise);
        pending.always(function () {
            var index = requests.indexOf(promise);
            if (index >= 0) requests.splice(index, 1);
        });
        var run = function () {
            if (!active) promise.abort();
            if (cancelled) return pending.promise();
            if (typeof routes.user_token !== 'string' || !key(routes.user_token)) {
                fail(null, 'error', 'Missing native token route');
                return pending.promise();
            }
            tokenRequest = original({url:routes.user_token, type:'GET', dataType:'json', cache:false});
            tokenRequest.done(function (result) {
                if (!active) promise.abort();
                if (cancelled) return;
                if (!result || result.code !== 1 || typeof result.__token__ !== 'string' || !result.__token__) {
                    fail(tokenRequest, 'error', 'Invalid session token');
                    return;
                }
                fields.delete('__token__'); fields.set('__token__', result.__token__);
                settings.type = 'POST'; settings.method = 'POST'; settings.data = fields.toString();
                writeRequest = original(settings);
                writeRequest.done(function () { pending.resolveWith(this, arguments); });
                writeRequest.fail(function () { pending.rejectWith(this, arguments); });
            });
            tokenRequest.fail(function (xhr, status, error) {
                fail(xhr, status, error);
            });
            return pending.promise();
        };
        queue = queue.then(run, run);
        return promise;
    };
    $.ajax.javcmsSecureForms = true;
})(window, window.jQuery);
