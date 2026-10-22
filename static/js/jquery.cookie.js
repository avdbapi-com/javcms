/*!
 * jQuery.cookie(name[, value[, options]]): read, write or delete one cookie.
 * Readable rewrite (JavCMS, 2026) of the classic plugin that used to ship packed with eval();
 * same API and results:
 *   $.cookie('name')                         -> the decoded value, or null when the cookie is absent
 *   $.cookie('name', 'value', {expires: 7, path: '/', domain: 'example.com', secure: true})
 *   $.cookie('name', null)                   -> deletes the cookie
 * options.expires is a number of days or a Date object; without it the cookie lasts for the session.
 */
(function ($) {
    'use strict';
    if (!$) {
        return;
    }

    $.cookie = function (name, value, options) {
        if (typeof value !== 'undefined') {
            options = $.extend({}, options || {});
            if (value === null) {
                value = '';
                options.expires = -1;
            }
            var expires = '';
            if (options.expires && (typeof options.expires === 'number' || options.expires.toUTCString)) {
                var date;
                if (typeof options.expires === 'number') {
                    date = new Date();
                    date.setTime(date.getTime() + (options.expires * 24 * 60 * 60 * 1000));
                } else {
                    date = options.expires;
                }
                expires = '; expires=' + date.toUTCString();
            }
            var path = options.path ? '; path=' + options.path : '';
            var domain = options.domain ? '; domain=' + options.domain : '';
            var secure = options.secure ? '; secure' : '';
            document.cookie = [name, '=', encodeURIComponent(value), expires, path, domain, secure].join('');
            return undefined;
        }

        var cookieValue = null;
        if (document.cookie && document.cookie !== '') {
            var cookies = document.cookie.split(';');
            for (var i = 0; i < cookies.length; i++) {
                var cookie = String(cookies[i]).replace(/^\s+|\s+$/g, '');
                if (cookie.substring(0, name.length + 1) === (name + '=')) {
                    var raw = cookie.substring(name.length + 1);
                    try {
                        cookieValue = decodeURIComponent(raw);
                    } catch (error) {
                        cookieValue = raw;
                    }
                    break;
                }
            }
        }
        return cookieValue;
    };
})(window.jQuery);
