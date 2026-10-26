/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* Original project progressive UI. Plain text by default; no HTML string parser. */
(function (window, document) {
    'use strict';
    var sequence = 0, notices, active = null, queue = [], entries = new Map();
    var configured = {};
    function text(key, fallback) {
        var messages = configured.messages || window.TemplateMessages || {};
        return typeof messages[key] === 'string' ? messages[key] : fallback;
    }
    function element(tag, className, value) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (value !== undefined) node.textContent = String(value);
        return node;
    }
    function dismissButton(label, callback) {
        var button = element('button', 'tui-dismiss', '×');
        button.type = 'button'; button.setAttribute('aria-label', label);
        button.addEventListener('click', callback);
        return button;
    }
    function getNotices() {
        if (!notices || !notices.isConnected) {
            notices = element('div', 'tui-notices');
            notices.setAttribute('aria-label', text('template_ui_notifications', 'Notifications'));
            (active && active.root ? active.root : document.body).appendChild(notices);
        }
        return notices;
    }
    function close(id, accepted) {
        var entry = entries.get(id);
        if (!entry) {
            var waiting = queue.findIndex(function (job) { return job.id === id; });
            if (waiting >= 0) queue.splice(waiting, 1)[0].resolve(false);
            return;
        }
        entries.delete(id);
        entry.close(accepted === true);
    }
    function message(value, options) {
        options = options || {};
        var id = ++sequence, node = element('div', 'tui-notice');
        if (options.kind === 'error') node.classList.add('tui-notice-error');
        // Only the text, never the close button, is included in the live announcement.
        var content = element('div', 'tui-notice-text');
        content.setAttribute('role', options.kind === 'error' ? 'alert' : 'status');
        content.setAttribute('aria-atomic', 'true');
        var spinner;
        if (options.loading) {
            spinner = element('span', 'tui-spinner'); spinner.setAttribute('aria-hidden', 'true');
            node.appendChild(spinner);
        }
        node.appendChild(content);
        node.appendChild(dismissButton(text('template_ui_dismiss', 'Dismiss notification'), function () { close(id); }));
        getNotices().appendChild(node);
        // Insert after the status/alert region exists, so assistive technology observes the update.
        content.textContent = String(value == null ? '' : value);
        var delay = options.duration === 0 ? 0 : Math.max(5000, Number(options.duration) || 7000);
        var remaining = delay, timer, started, hovered = false, focused = false;
        function stop() { if (timer) { clearTimeout(timer); remaining -= Date.now() - started; timer = null; } }
        function resume() { if (delay && !hovered && !focused) { started = Date.now(); timer = setTimeout(function () { close(id); }, Math.max(0, remaining)); } }
        node.addEventListener('mouseenter', function () { hovered = true; stop(); });
        node.addEventListener('mouseleave', function () { hovered = false; resume(); });
        node.addEventListener('focusin', function () { focused = true; stop(); });
        node.addEventListener('focusout', function (event) { if (!node.contains(event.relatedTarget)) { focused = false; resume(); } });
        entries.set(id, {type: 'message', close: function () { stop(); node.remove(); if (typeof options.onClose === 'function') options.onClose(id); }});
        resume();
        return id;
    }
    function load(value) { return message(value || text('template_ui_working', 'Working...'), {loading: true, duration: 0}); }
    function focusable(root) {
        return Array.prototype.filter.call(root.querySelectorAll('button,a[href],input,select,textarea,[tabindex]'), function (node) {
            return node.tabIndex >= 0 && !node.disabled && !node.hidden && node.getClientRects().length > 0;
        });
    }
    function reviewedNode(node) {
        if (!(node instanceof window.Element) || node.ownerDocument !== document || /^(SCRIPT|IFRAME|OBJECT|EMBED|STYLE|LINK)$/.test(node.tagName) || node.querySelector('script,iframe,object,embed,style,link')) return false;
        var all = [node].concat(Array.from(node.querySelectorAll('*')));
        return all.every(function (item) {
            return Array.from(item.attributes).every(function (attribute) {
                if (/^on/i.test(attribute.name) || attribute.name === 'srcdoc') return false;
                if (!/^(href|src|action|formaction)$/i.test(attribute.name)) return true;
                if (attribute.value.charAt(0) === '#') return true;
                try { var url = new URL(attribute.value, location.href); return /^https?:$/.test(url.protocol) && url.origin === location.origin; } catch (error) { return false; }
            });
        });
    }
    function mount(job) {
        var options = job.options, previous = document.activeElement;
        var native = !options.forceFallback && typeof window.HTMLDialogElement !== 'undefined' && typeof document.createElement('dialog').showModal === 'function';
        var root = element(native ? 'dialog' : 'div', 'tui-dialog'), overlay;
        root.id = 'tui-dialog-' + job.id; root.tabIndex = -1; job.root = root;
        root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true');
        var header = element('div', 'tui-heading');
        var heading = element('h2', 'tui-title', options.title || text('template_ui_confirm_title', 'Confirm action'));
        heading.id = root.id + '-title'; root.setAttribute('aria-labelledby', heading.id);
        header.appendChild(heading);
        header.appendChild(dismissButton(text('template_ui_close', 'Close'), function () { close(job.id); }));
        var body = element('div', 'tui-body', options.message || '');
        body.id = root.id + '-body';
        if (!options.node) root.setAttribute('aria-describedby', body.id);
        var restoreNode;
        if (options.node) {
            // A caller must supply an already-reviewed local DOM fragment, never server/user HTML.
            if (!reviewedNode(options.node)) {
                throw new TypeError('Dialog content must be a reviewed same-document DOM element without executable embeds');
            }
            restoreNode = document.createComment('template-ui-dialog-origin');
            if (options.node.parentNode) options.node.parentNode.insertBefore(restoreNode, options.node);
            body.appendChild(options.node);
        }
        var actions = element('div', 'tui-actions');
        var cancel = element('button', 'tui-button', options.cancelText || text('template_ui_cancel', 'Cancel'));
        cancel.type = 'button'; cancel.addEventListener('click', function () { close(job.id); });
        actions.appendChild(cancel);
        var accept = element('button', 'tui-button tui-button-primary', options.confirmText || text('template_ui_confirm', 'Confirm'));
        if (options.destructive) accept.classList.add('tui-button-danger');
        accept.type = 'button'; accept.addEventListener('click', function () { close(job.id, true); });
        if (options.confirm !== false) actions.appendChild(accept);
        root.appendChild(header); root.appendChild(body); root.appendChild(actions);
        if (native) document.body.appendChild(root);
        else { overlay = element('div', 'tui-overlay'); overlay.appendChild(root); document.body.appendChild(overlay); }
        var host = overlay || root, hiddenSiblings = [];
        Array.prototype.forEach.call(document.body.children, function (node) {
            if (node === host || node.tagName === 'SCRIPT' || node.tagName === 'STYLE') return;
            hiddenSiblings.push({node: node, inert: node.inert, aria: node.getAttribute('aria-hidden')});
            node.inert = true; node.setAttribute('aria-hidden', 'true');
        });
        if (notices && notices.isConnected) {
            notices.inert = false; notices.removeAttribute('aria-hidden'); root.appendChild(notices);
        }
        var oldOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        function keys(event) {
            if (event.key === 'Escape') { event.preventDefault(); close(job.id); return; }
            if (event.key !== 'Tab') return;
            var nodes = focusable(root), first = nodes[0], last = nodes[nodes.length - 1];
            if (!nodes.length) { event.preventDefault(); root.focus(); }
            else if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
        function containFocus(event) { if (!root.contains(event.target)) cancel.focus(); }
        document.addEventListener('keydown', keys, true);
        document.addEventListener('focusin', containFocus, true);
        root.addEventListener('cancel', function (event) { event.preventDefault(); close(job.id); });
        entries.set(job.id, {type: 'dialog', close: function (accepted) {
            document.removeEventListener('keydown', keys, true); document.removeEventListener('focusin', containFocus, true);
            if (native && root.open) root.close();
            if (restoreNode && restoreNode.parentNode) { restoreNode.parentNode.insertBefore(options.node, restoreNode); restoreNode.remove(); }
            if (notices && root.contains(notices)) document.body.appendChild(notices);
            host.remove();
            hiddenSiblings.forEach(function (saved) { saved.node.inert = saved.inert; if (saved.aria === null) saved.node.removeAttribute('aria-hidden'); else saved.node.setAttribute('aria-hidden', saved.aria); });
            document.body.style.overflow = oldOverflow;
            active = null;
            if (previous && previous.isConnected && typeof previous.focus === 'function') previous.focus();
            job.resolve(accepted);
            next();
        }});
        if (native) root.showModal();
        // The safe cancellation action receives initial focus, especially for destructive actions.
        cancel.focus();
    }
    function next() {
        if (active || !queue.length) return;
        active = queue.shift();
        try { mount(active); } catch (error) { var failed = active; active = null; failed.reject(error); next(); }
    }
    function dialog(options) {
        if (!document.body) return Promise.reject(new Error('Load TemplateUI after the document body exists'));
        var job = {id: ++sequence, options: options || {}};
        var result = new Promise(function (resolve, reject) { job.resolve = resolve; job.reject = reject; });
        result.id = job.id;
        queue.push(job); next();
        return result;
    }
    function confirm(value, options) {
        var settings = Object.assign({}, options || {}, {message: String(value == null ? '' : value), confirm: true});
        return dialog(settings);
    }
    function installLayerAdapter() {
        // Audited staged templates use only msg; refuse to overwrite an existing vendor object.
        if (window.layer) return false;
        window.layer = {
            msg: function (value, options, callback) {
                if (typeof options === 'function') { callback = options; options = {}; }
                options = options || {};
                return message(value, {duration: options.time === 0 ? 0 : options.time, onClose: callback, kind: options.icon === 2 ? 'error' : 'info'});
            },
            close: close,
            closeAll: function (type) { if (!type || type === 'message') Array.from(entries.keys()).forEach(function (id) { if (entries.get(id).type === 'message') close(id); }); }
        };
        return true;
    }
    window.TemplateUI = {message: message, load: load, close: close, confirm: confirm, dialog: dialog, text: text,
        configure: function (options) { configured = options || {}; }, installLayerAdapter: installLayerAdapter};
})(window, document);
