/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* English defaults for the bundled Layui 2.9.13 admin controls. */
(function () {
    'use strict';
    if (!window.layui || layui.v !== '2.9.13' || layui.adminEnglish) { return; }

    layui.define(['form', 'layer', 'laydate', 'laypage', 'upload', 'table'], function (exports) {
        var $ = layui.jquery;
        var layer = layui.layer;
        var form = layui.form;
        // Words come from window.AX_LANG (public/head.html prints the admin/ux/kit/* keys in the admin's language);
        // the English text is only the fallback (REVIEW-admin-visual-r3 R3-1).
        function ax(key, fallback) { var l = window.AX_LANG || {}; return (typeof l[key] === 'string' && l[key] !== '') ? l[key] : fallback; }
        var adminLang = String(document.documentElement.lang || 'en').toLowerCase();
        var messages = {
            required: ax('field_required', 'This field is required.'),
            phone: 'Enter an 11-digit mobile number starting with 1.',
            email: ax('valid_email', 'Enter a valid email address.'),
            url: ax('valid_url', 'Enter a valid URL.'),
            number: ax('valid_number', 'Enter a number.'),
            date: ax('valid_date', 'Enter a valid date.'),
            identity: 'Enter a valid ID card number.'
        };

        // Keep each bundled validation rule and its acceptance criteria intact.
        Object.keys(messages).forEach(function (name) {
            var validate = form.config.verify[name];
            form.config.verify[name] = function () {
                var result = validate.apply(this, arguments);
                return result ? messages[name] : result;
            };
        });
        // laydate 2.9.13 ships only 'cn' (simplified Chinese) and 'en'.
        layui.laydate.set({lang: adminLang === 'zh-cn' ? 'cn' : 'en'});
        layui.upload.set({text: {
            'cross-domain': 'Uploading to another domain is not supported.',
            'data-format-error': 'The upload server returned an invalid response.',
            'check-error': 'This file type is not supported.',
            'error': ax('upload_failed', 'Upload failed. Try again.'),
            'limit-number': function (options, count) {
                return 'Select at most ' + options.number + (Number(options.number) === 1 ? ' file' : ' files') + '. Selected: ' + count + '.';
            },
            'limit-size': function (options, size) {
                return 'Maximum file size: ' + size + '.';
            }
        }});
        function tableError(config, message) {
            $(config.elem).next('.layui-table-view').find('.layui-table-main .layui-none').text(message);
        }
        layui.table.set({text: {none: ax('no_data', 'No data')}, done: function (response) {
            var mapping = this.response;
            if (this.url && mapping && response[mapping.statusName] != mapping.statusCode && !response[mapping.msgName]) {
                tableError(this, 'Invalid response: expected "' + mapping.statusName + '": ' + mapping.statusCode + '.');
            }
        }, error: function (request, status) {
            tableError(this, 'Request failed: ' + status + '.');
        }, defaultToolbar: [
            {name: 'filter', title: 'Choose columns'},
            {name: 'exports', title: 'Export'},
            {name: 'print', title: 'Print'}
        ]});

        // These bundled methods do not expose global text settings.
        var renderPage = layui.laypage.render;
        layui.laypage.render = function (options) {
            var total = ax('pager_total', 'Total: {n}').split('{n}');
            return renderPage.call(this, $.extend({
                prev: ax('pager_prev', 'Previous'), next: ax('pager_next', 'Next'),
                limits: window.adminExtendedPageSizes === true ? [10, 20, 30, 40, 50, 100, 200, 500, 1000] : [10, 20, 30, 40, 50],
                countText: [total[0], total.length > 1 ? total.slice(1).join('') : ''],
                skipText: [ax('pager_go_to', 'Go to page') + ' ', '', ax('pager_go', 'Go')],
                limitTemplet: function (limit) { return ax('pager_per_page', '{n} per page').replace('{n}', limit); }
            }, options));
        };
        var okLabel = ax('ok', 'OK'), cancelLabel = ax('cancel', 'Cancel');
        layer.config({title: ax('dialog_title', 'Information')});
        var open = layer.open;
        layer.open = function (options) {
            options = $.extend({}, options);
            // Add an OK button only to default message dialogs (type 0).
            if (!options.type && !('btn' in options)) { options.btn = [okLabel]; }
            return open.call(this, options);
        };
        var confirm = layer.confirm;
        layer.confirm = function (content, options, yes, cancel) {
            if (typeof options === 'function') {
                cancel = yes;
                yes = options;
                options = {};
            }
            return confirm.call(this, content, $.extend({btn: [okLabel, cancelLabel]}, options), yes, cancel);
        };
        var prompt = layer.prompt;
        layer.prompt = function (options, yes) {
            if (typeof options === 'function') { yes = options; options = {}; }
            return prompt.call(this, $.extend({btn: [okLabel, cancelLabel]}, options), yes);
        };

        // Only generated control labels lack public text options in 2.9.13.
        // Never translate table cells, select option values, input values or HTML content.
        function each(root, selector, callback) {
            if (root.nodeType !== 1 || root.closest('[contenteditable="true"]')) { return; }
            if (root.matches(selector)) { callback(root); }
            root.querySelectorAll(selector).forEach(function (node) {
                if (!node.closest('[contenteditable="true"]')) { callback(node); }
            });
        }
        function text(node, before, after) {
            if (node.childElementCount === 0 && node.textContent === before) { node.textContent = after; }
        }
        function generatedLabels(root) {
            each(root, '.layui-form-select .layui-select-none', function (node) {
                text(node, '\u65e0\u5339\u914d\u9879', ax('no_matches', 'No matches'));
            });
            each(root, '.layui-form-select', function (node) {
                var select = node.previousElementSibling;
                if (!select || select.tagName !== 'SELECT') { return; }
                var input = node.querySelector('.layui-select-title input');
                if (input && input.getAttribute('placeholder') === '\u8bf7\u9009\u62e9') {
                    input.setAttribute('placeholder', ax('select_option', 'Select an option'));
                }
                node.querySelectorAll('.layui-select-tips').forEach(function (tip) {
                    text(tip, '\u8bf7\u9009\u62e9', ax('select_option', 'Select an option'));
                });
                if (!select.options.length) {
                    node.querySelectorAll('dd.layui-disabled').forEach(function (tip) {
                        text(tip, '\u6ca1\u6709\u9009\u9879', ax('no_options', 'No options'));
                    });
                }
            });
            each(root, '.layui-laypage-first, .layui-laypage-last, .layui-table-sort-asc, .layui-table-sort-desc', function (node) {
                var titles = {'\u9996\u9875': ax('first_page', 'First page'), '\u5c3e\u9875': ax('last_page', 'Last page'), '\u5347\u5e8f': 'Sort ascending', '\u964d\u5e8f': 'Sort descending'};
                var replacement = titles[node.getAttribute('title')];
                if (replacement) { node.setAttribute('title', replacement); }
            });
            each(root, '.layui-table-tool-self > [lay-event]', function (node) {
                var titles = {'\u7b5b\u9009\u5217': 'Choose columns', '\u5bfc\u51fa': 'Export', '\u6253\u5370': 'Print'};
                var replacement = titles[node.getAttribute('title')];
                if (replacement) { node.setAttribute('title', replacement); }
            });
            each(root, '.layui-table-tool-panel > li[data-type="csv"]', function (node) {
                text(node, '\u5bfc\u51fa CSV \u6587\u4ef6', 'Export as CSV');
            });
            // Old layer dialog buttons are <a> without href: make them Tab stops (the kit focus ring covers [tabindex]).
            each(root, '.layui-layer-btn > a', function (node) {
                if (!node.hasAttribute('tabindex')) { node.setAttribute('tabindex', '0'); node.setAttribute('role', 'button'); }
            });
            each(root, '.layui-layer-title', function (node) {
                text(node, '\u63d0\u793a', ax('notice', 'Notice'));
            });
            each(root, '.layui-layer-tips .layui-layer-content', function (node) {
                // The tip arrow is an element; translate only the text beside it.
                Array.prototype.forEach.call(node.childNodes, function (child) {
                    if (child.nodeType === 3 && child.nodeValue === '\u5f53\u524d\u8868\u683c\u65e0\u6570\u636e') {
                        child.nodeValue = 'No data to export.';
                    }
                });
            });
            each(root, '.layui-upload-choose', function (node) {
                var match = /^(\d+)\u4e2a\u6587\u4ef6$/.exec(node.textContent);
                var input = node.previousElementSibling;
                if (match && input && input.type === 'file' && input.files.length > 1) {
                    node.textContent = match[1] + ' files';
                }
            });
        }
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) { generatedLabels(node); }
                });
            });
        });
        observer.observe(document.documentElement, {childList: true, subtree: true});
        // Enter / Space on a focused old dialog button presses it.
        document.addEventListener('keydown', function (event) {
            var target = event.target;
            if ((event.key === 'Enter' || event.key === ' ') && target && target.matches && target.matches('.layui-layer-btn > a[role="button"]')) {
                event.preventDefault();
                target.click();
            }
        });
        generatedLabels(document.documentElement);
        exports('adminEnglish', {version: '2.9.13'});
    });
}());
