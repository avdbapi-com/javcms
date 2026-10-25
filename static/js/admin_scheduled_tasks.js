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
        var root = document.getElementById('admin-scheduled-list');
        if (!root || root.dataset.taskBound) return;
        root.dataset.taskBound = '1';
        var busy = false;
        var result = document.getElementById('admin-task-status');
        var token = root.querySelector('input[name="__token__"]');
        function refresh(value) {
            if (value && token) { token.value = value; token.defaultValue = value; }
        }
        function message(value) { result.textContent = typeof value === 'string' ? value : root.dataset.error; }
        function post(url, data, callback, html) {
            if (busy) return false;
            busy = true;
            root.setAttribute('aria-busy', 'true');
            data.__token__ = token.value;
            window.jQuery.ajax({
                url: url, type: 'POST', data: data, dataType: html ? 'html' : 'json',
                success: function (response, status, xhr) {
                    refresh(xhr.getResponseHeader('X-Maccms-Token'));
                    if (!html) {
                        refresh(response.__token__ || (response.data && response.data.__token__));
                        message(response.msg);
                    }
                    callback(response, true);
                },
                error: function (xhr) {
                    refresh(xhr.getResponseHeader('X-Maccms-Token'));
                    message(root.dataset.error);
                    callback(null, false);
                },
                complete: function () { busy = false; root.removeAttribute('aria-busy'); }
            });
            return true;
        }
        window.layui.use(['form', 'layer'], function () {
            var form = window.layui.form;
            var layer = window.layui.layer;
            form.on('switch(taskStatus)', function (data) {
                var input = data.elem;
                var next = input.checked;
                if (busy) { input.checked = !next; form.render('checkbox'); return; }
                post(root.dataset.fieldUrl, {col: 'status', ids: [input.dataset.taskName], val: next ? '1' : '0'}, function (response, ok) {
                    if (!ok || response.code !== 1) { input.checked = !next; form.render('checkbox'); return; }
                    var button = input.closest('tr').querySelector('[data-task-test]');
                    if (button) button.dataset.enabled = next ? '1' : '0';
                });
            });
            root.addEventListener('click', function (event) {
                var button = event.target.closest('[data-task-delete],[data-task-bulk],[data-task-test]');
                if (!button || !root.contains(button) || busy) return;
                if (button.hasAttribute('data-task-delete')) {
                    // xss-fix-B (function r7 P3-2): the kit dialog names the task it deletes; the name is text, never HTML.
                    var taskName = button.dataset.taskDelete || '';
                    var doDelete = function () {
                        post(root.dataset.deleteUrl, {ids: taskName}, function (response, ok) {
                            if (ok && response.code === 1) button.closest('tr').remove();
                        });
                    };
                    if (window.AxUX && typeof window.AxUX.confirm === 'function') {
                        window.AxUX.confirm({
                            title: root.dataset.delTitle || root.dataset.confirm,
                            message: String(root.dataset.delMsg || root.dataset.confirm).split('%s').join(taskName),
                            confirmLabel: root.dataset.delOk || undefined,
                            count: 1,
                            danger: true
                        }).then(function (yes) { if (yes) doDelete(); });
                    } else {
                        layer.confirm(root.dataset.confirm, function (index) { layer.close(index); doDelete(); });
                    }
                } else if (button.hasAttribute('data-task-bulk')) {
                    var selected = Array.prototype.slice.call(root.querySelectorAll('input[name="ids[]"]:checked'));
                    if (!selected.length) { message(root.dataset.select); return; }
                    var value = button.dataset.taskBulk;
                    post(root.dataset.fieldUrl, {col: 'status', ids: selected.map(function (n) { return n.value; }), val: value}, function (response, ok) {
                        if (!ok || response.code !== 1) return;
                        selected.forEach(function (n) {
                            var row = n.closest('tr');
                            row.querySelector('input[lay-filter="taskStatus"]').checked = value === '1';
                            row.querySelector('[data-task-test]').dataset.enabled = value;
                        });
                        form.render('checkbox');
                    });
                } else {
                    if (button.dataset.enabled !== '1') { message(root.dataset.blocked); return; }
                    var popup = window.open('', '_blank');
                    if (!popup) { message(root.dataset.popup); return; }
                    message(root.dataset.testing);
                    post(root.dataset.testUrl, {enforce: '1', name: button.dataset.taskTest}, function (response, ok) {
                        if (!ok) { popup.close(); return; }
                        // Existing task handlers return their own same-origin progress/continuation HTML.
                        popup.document.open(); popup.document.write(response); popup.document.close();
                        message('');
                    }, true);
                }
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
