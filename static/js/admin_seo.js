/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* SEO / site settings pages (SEO-ADMIN-BRIEF A.1-A.7, E.1-E.4): Site identity cards, icon / share upload, Google
   previews, page tabs, checklist browser checks, Languages, Legal & age, Sitemap & robots.txt, IndexNow, URL warning.
   Kit only (AxUX); strings come from <script type="application/json" id="seo-strings"> (plain text). */
(function () {
    'use strict';
    var S = {};
    try { S = JSON.parse((document.getElementById('seo-strings') || {}).textContent || '{}'); } catch (e) { S = {}; }
    function t(key) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(S[key] || '').replace(/%s/g, function () { var v = args[i++]; return v === undefined ? '' : String(v); });
    }
    function kit(key, v) { return window.AxUX && AxUX.t ? AxUX.t(key, v) : ''; }
    function one(sel, root) { return (root || document).querySelector(sel); }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function dirty() { if (window.AxUX) { AxUX.markDirty(); } }
    function toast(msg, kind) { if (window.AxUX && msg) { AxUX.toast(msg, kind || 'ok'); } }
    function chars(s) { return Array.from ? Array.from(String(s)).length : String(s).length; }
    function setToken(v) { if (v) { all('input[name="__token__"]').forEach(function (i) { i.value = v; i.defaultValue = v; }); } }
    function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
    function layuiRender(type) { try { if (window.layui && layui.form) { layui.form.render(type); } } catch (e) { /* layui not ready */ } }
    function post(url, data, retried) {
        var body = data instanceof FormData ? data : new URLSearchParams(data);
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.data && res.data.__token__) { setToken(res.data.__token__); }
                // An old form token (the page was opened before another settings page): send once more with the fresh one.
                if (res && Number(res.code) !== 1 && res.data && res.data.retry && !retried) {
                    if (data instanceof FormData) { data.set('__token__', token()); } else { data.__token__ = token(); }
                    return post(url, data, true);
                }
                return res || {};
            });
    }
    function token() { var i = one('input[name="__token__"]'); return i ? i.value : ''; }
    var shell = null;
    try { if (window.parent !== window && window.parent.AdminShell) { shell = window.parent.AdminShell; } } catch (e) { shell = null; }

    // ---- links that open another admin page as a shell tab; #hash links switch the page tab
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[data-tab], a[data-ax-tab-link], a[data-seo-check-fix]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        var hash = a.hash ? a.hash.slice(1) : '';
        if (hash && a.pathname === location.pathname && one('[data-ax-tabpanel="' + hash + '"]')) { e.preventDefault(); showTab(hash, true); return; }
        var tab = a.getAttribute('data-tab');
        if (shell && tab) { e.preventDefault(); shell.open(tab, { url: a.href }); }
    });

    // ---- page tabs (.ax-tabs[data-ax-tabs]): one form, panels shown / hidden, the hash remembers the tab
    function showTab(name, focus) {
        var panel = one('[data-ax-tabpanel="' + name + '"]');
        if (!panel) { return false; }
        all('[data-ax-tabpanel]').forEach(function (p) { p.hidden = p !== panel; });
        all('[data-ax-tab]').forEach(function (a) {
            var on = a.getAttribute('data-ax-tab') === name;
            a.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
        });
        try { history.replaceState(null, '', '#' + name); } catch (e) { /* file: */ }
        if (focus) { var f = panel.querySelector('input:not([type=hidden]),textarea,select,button'); if (f) { f.focus({ preventScroll: true }); } }
        return true;
    }
    all('[data-ax-tab]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); showTab(a.getAttribute('data-ax-tab'), false); });
    });
    if (one('[data-ax-tabs]')) {
        var start = location.hash.slice(1);
        if (!start || !showTab(start, false)) { var first = one('[data-ax-tab]'); if (first) { showTab(first.getAttribute('data-ax-tab'), false); } }
        // a #hash link without data-tab (or a typed hash) still switches the tab (SEO-fix F2)
        window.addEventListener('hashchange', function () { var h = location.hash.slice(1); if (h) { showTab(h, true); } });
    }

    // ---- character counters
    all('[data-seo-count]').forEach(function (el) {
        var max = +el.getAttribute('data-seo-count'), amber = +el.getAttribute('data-seo-amber') || 0;
        var green = (el.getAttribute('data-seo-green') || '').split('-');
        var out = document.getElementById(el.id + '-count');
        var upd = function () {
            var n = chars(el.value);
            if (out) {
                out.textContent = t('counter', n, max);
                out.classList.toggle('is-amber', (amber > 0 && n > amber) || n > max);
                out.classList.toggle('is-green', green.length === 2 && n >= +green[0] && n <= +green[1]);
            }
            if (el.id === 'site-name') { var box = one('[data-seo-long]'); if (box) { box.hidden = n <= 30; } }
        };
        el.addEventListener('input', upd);
        upd();
    });

    // ---- "Move to Home page title" (A.1)
    var move = one('[data-seo-move]');
    if (move) {
        move.addEventListener('click', function () {
            var name = one('#site-name'), home = one('#site-home-title');
            if (!name || !home) { return; }
            home.value = Array.from(name.value.trim()).slice(0, 70).join('');
            fire(home);
            name.focus();
            name.select();
            dirty();
        });
    }

    // ---- Google previews: Site identity (live from the inputs) and Titles tab (AJAX, TemplateSeo::preview())
    function cut(s, n) {
        var a = Array.from(String(s).replace(/\s+/g, ' ').trim());
        if (a.length <= n) { return a.join(''); }
        var c = a.slice(0, n - 1).join('');
        return (c.replace(/\s+\S*$/, '') || c) + ' …';
    }
    var serp = one('[data-seo-serp]');
    if (serp) {
        var ti = document.getElementById(serp.getAttribute('data-title-for'));
        var fb = document.getElementById(serp.getAttribute('data-fallback-for'));
        var de = document.getElementById(serp.getAttribute('data-desc-for'));
        var updSerp = function () {
            one('[data-serp-title]', serp).textContent = cut((ti && ti.value.trim()) || (fb && fb.value.trim()) || '', 60);
            one('[data-serp-desc]', serp).textContent = cut(de ? de.value : '', 160);
            var nm = one('[data-serp-name]', serp);
            if (nm && fb) { nm.textContent = fb.value.trim(); }
        };
        [ti, fb, de].forEach(function (el) { if (el) { el.addEventListener('input', updSerp); } });
        updSerp();
    }
    all('[data-seo-prev] .ax-serp__title, [data-seo-prev] .ax-serp__desc').forEach(function (el) {
        el.textContent = cut(el.textContent, el.classList.contains('ax-serp__title') ? 60 : 160);
    });
    var prevBox = one('[data-seo-preview-url]');
    var prevTimer = null;
    function refreshPreviews() {
        if (!prevBox) { return; }
        clearTimeout(prevTimer);
        prevTimer = setTimeout(function () {
            var q = new URLSearchParams();
            all('[data-seo-pv]').forEach(function (el) {
                if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) { return; }
                q.append(el.getAttribute('data-seo-pv'), el.value);
            });
            var url = prevBox.getAttribute('data-seo-preview-url');
            fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'preview=1&' + q.toString(), { credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); }).then(function (res) {
                    var d = res && res.data || {};
                    Object.keys(d).forEach(function (type) {
                        var box = one('[data-seo-prev="' + type + '"]');
                        if (!box) { return; }
                        one('.ax-serp__title', box).textContent = cut(d[type].title || '', 60);
                        one('.ax-serp__desc', box).textContent = cut(d[type].description || '', 160);
                    });
                }).catch(function () { /* preview only */ });
        }, 300);
    }
    all('[data-seo-pv]').forEach(function (el) { el.addEventListener('input', refreshPreviews); el.addEventListener('change', refreshPreviews); });

    // ---- placeholder chips: insert the placeholder into their field at the cursor
    function insertAt(field, text) {
        var s = field.selectionStart, e = field.selectionEnd;
        if (typeof s !== 'number') { field.value += text; } else { field.value = field.value.slice(0, s) + text + field.value.slice(e); field.selectionStart = field.selectionEnd = s + text.length; }
        field.focus();
        fire(field);
        dirty();
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-seo-insert]');
        if (!b) { return; }
        var field = document.getElementById(b.getAttribute('data-for'));
        if (field && !field.readOnly) { insertAt(field, b.getAttribute('data-seo-insert')); }
    });

    // ---- copy buttons
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-seo-copy]');
        if (!b) { return; }
        var src = document.getElementById(b.getAttribute('data-seo-copy'));
        var text = src ? (src.value || src.textContent) : '';
        var done = function () { toast(t('copied'), 'ok'); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
        document.body.removeChild(ta);
    });

    // ---- Site identity: icon and share picture upload (system/siteicon), colour, remove
    var iconBox = one('[data-seo-icon]');
    function iconImgs(tab, phone) {
        all('[data-seo-icon-img="tab"]').forEach(function (i) { i.src = tab; });
        all('[data-seo-icon-img="phone"]').forEach(function (i) { i.src = phone; });
        var s = one('[data-seo-serp-icon]'); if (s) { s.src = tab; }
    }
    function iconMode(value) {
        all('input[name="site[site_icon_mode]"]').forEach(function (r) { r.checked = r.value === value; });
        layuiRender('radio');
    }
    function status(kind, text) { var s = one('[data-seo-status="' + kind + '"]'); if (s) { s.textContent = text || ''; } }
    function upload(kind, file) {
        var url = iconBox ? iconBox.getAttribute('data-upload-url') : '';
        if (!url || !file) { return; }
        var fd = new FormData();
        fd.append('file', file);
        fd.append('kind', kind);
        fd.append('__token__', token());
        var color = one('[data-seo-color-text]');
        if (color) { fd.append('theme_color', color.value); }
        status(kind, kit('loading'));
        post(url, fd).then(function (res) {
            var d = res.data || {};
            if (Number(res.code) !== 1) { status(kind, res.msg); toast(res.msg, 'error'); return; }
            if (kind === 'icon') {
                one('[data-seo-icon-dir]').value = d.path || '';
                iconImgs(d.previews[32] || d.previews['32'], d.previews[180] || d.previews['180']);
                iconMode('custom');
                one('[data-seo-remove="icon"]').hidden = false;
            } else {
                one('[data-seo-share-path]').value = d.path || '';
                one('[data-seo-share-w]').value = d.w || 0;
                one('[data-seo-share-h]').value = d.h || 0;
                var img = one('[data-seo-share-img]'); img.src = d.url; img.hidden = false;
                one('[data-seo-remove="share"]').hidden = false;
            }
            status(kind, res.msg + (d.warn ? ' ' + d.warn : ''));
            toast(res.msg, d.warn ? 'warn' : 'ok');
            if (d.warn) { toast(d.warn, 'warn'); }
            dirty();
        }).catch(function () { status(kind, kit('request_failed')); toast(kit('request_failed'), 'error'); });
    }
    all('[data-seo-pick]').forEach(function (b) {
        var kind = b.getAttribute('data-seo-pick');
        var input = one('[data-seo-file="' + kind + '"]');
        b.addEventListener('click', function () { if (input) { input.value = ''; input.click(); } });
        if (input) { input.addEventListener('change', function () { if (input.files && input.files[0]) { upload(kind, input.files[0]); } }); }
    });
    all('[data-seo-remove]').forEach(function (b) {
        b.addEventListener('click', function () {
            var kind = b.getAttribute('data-seo-remove');
            if (kind === 'icon') {
                one('[data-seo-icon-dir]').value = '';
                iconImgs(iconBox.getAttribute('data-default-tab'), iconBox.getAttribute('data-default-phone'));
                iconMode('default');
                status('icon', t('icon_removed'));
            } else {
                one('[data-seo-share-path]').value = '';
                one('[data-seo-share-w]').value = 0;
                one('[data-seo-share-h]').value = 0;
                one('[data-seo-share-img]').hidden = true;
                status('share', '');
            }
            b.hidden = true;
            dirty();
        });
    });
    var pick = one('[data-seo-color-pick]'), text = one('[data-seo-color-text]');
    if (pick && text) {
        // An empty brand colour shows no swatch (a native colour input cannot be empty and would look black).
        var start = one('[data-seo-color-start]'), clear = one('[data-seo-color-clear]');
        var colorState = function () {
            var empty = text.value.trim() === '';
            pick.hidden = empty;
            if (start) { start.hidden = !empty; }
            if (clear) { clear.hidden = empty; }
        };
        pick.addEventListener('input', function () { text.value = pick.value; colorState(); dirty(); });
        text.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(text.value)) { pick.value = text.value; } colorState(); });
        if (clear) { clear.addEventListener('click', function () { text.value = ''; colorState(); dirty(); text.focus(); }); }
        if (start) {
            start.addEventListener('click', function () {
                pick.hidden = false;
                try { if (pick.showPicker) { pick.showPicker(); } else { pick.click(); } } catch (err) { pick.click(); }
                if (text.value.trim() === '') { start.hidden = true; }
                pick.focus();
            });
        }
    }
    // N5: the name a phone shows under the home-screen icon (SiteIcons::shortName(): at most 12 characters, cut on a word).
    var shortOut = one('[data-seo-short-name]'), nameIn = one('#site-name');
    if (shortOut && nameIn) {
        var shortName = function (v) {
            var a = Array.from(String(v).trim());
            if (!a.length) { return 'JavCMS'; }
            if (a.length <= 12) { return a.join(''); }
            var cut = a.slice(0, 13).join(''), sp = cut.lastIndexOf(' ');
            var s = sp > 0 ? cut.slice(0, sp) : a.slice(0, 12).join('');
            return s.replace(/[ \t\-|,.:;]+$/, '');
        };
        nameIn.addEventListener('input', function () { shortOut.textContent = shortName(nameIn.value.replace(/\s+/g, ' ')); });
    }

    // ---- layui events (radios / switches / selects are layui skins)
    function onLayui(fn) { if (window.layui) { layui.use(['form'], function () { fn(layui.form); }); } }
    onLayui(function (form) {
        form.on('radio(seoIconMode)', function (data) {
            var dir = one('[data-seo-icon-dir]');
            if (data.value === 'custom' && dir && !dir.value) { var input = one('[data-seo-file="icon"]'); if (input) { input.click(); } }
            dirty();
        });
        form.on('radio(seoPv)', refreshPreviews);
        form.on('select(seoPv)', refreshPreviews);
        // A.5.2 / CF02: "Hide the site" needs the typed word HIDE.
        form.on('switch(seoHide)', function (data) {
            var confirmInput = one('input[name="hide_confirm"]');
            if (!data.elem.checked) { if (confirmInput) { confirmInput.value = ''; } return; }
            if (data.elem.getAttribute('data-was') === '1') { return; }
            data.elem.checked = false;
            form.render('checkbox');
            AxUX.confirm({ title: t('hide_site_confirm_title'), message: t('hide_site_confirm'), typed: 'HIDE', confirmLabel: t('hide_site_confirm_ok'), danger: true })
                .then(function (yes) {
                    if (!yes) { return; }
                    data.elem.checked = true;
                    if (confirmInput) { confirmInput.value = 'HIDE'; }
                    form.render('checkbox');
                    dirty();
                });
        });
        // Languages: the default language stays offered and indexed; home rows follow the offered list.
        form.on('checkbox(seoLang)', function (data) {
            var def = one('[name="lang_default"]');
            if (def && data.elem.value === def.value && !data.elem.checked) { data.elem.checked = true; toast(one('[data-seo-need-default]') ? one('[data-seo-need-default]').textContent : '', 'warn'); }
            // r7 repair (function r6 P3-1): ticking a language again also ticks its "Show in Google" box (unticking the
            // language had cleared it), so a re-tick does not silently drop the language from Google.
            if (data.elem.name === 'lang_offered[]' && data.elem.checked) {
                var idxBox = one('input[name="lang_indexed[]"][value="' + data.elem.value + '"]');
                if (idxBox) { idxBox.checked = true; }
            }
            langSync();
            dirty();
        });
    });

    // ---- Languages page
    function langSync() {
        var def = one('[name="lang_default"]');
        if (!def) { return; }
        var shown = 0;
        all('input[name="lang_offered[]"]').forEach(function (o) {
            if (o.value === def.value) { o.checked = true; }
            var idx = one('input[name="lang_indexed[]"][value="' + o.value + '"]');
            if (idx) {
                if (o.value === def.value) { idx.checked = true; }
                if (!o.checked) { idx.checked = false; }
                idx.disabled = !o.checked;
            }
            var row = one('[data-seo-home="' + o.value + '"]');
            if (row) { row.hidden = !o.checked || o.value === def.value; if (!row.hidden) { shown++; } }
        });
        var empty = one('[data-seo-home-empty]');
        if (empty) { empty.hidden = shown > 0; }
        var table = one('[data-seo-home-table]');
        if (table) { table.hidden = shown === 0; }
        layuiRender('checkbox');
    }
    if (one('[name="lang_default"]')) {
        one('[name="lang_default"]').addEventListener('change', function () { langSync(); dirty(); });
        setTimeout(langSync, 0);
    }

    // ---- Legal & age: editors, insert chips, restore our template
    all('[data-seo-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = document.getElementById('legal-edit-' + b.getAttribute('data-seo-edit'));
            if (!d) { return; }
            d.open = true;
            var ta = d.querySelector('textarea');
            try { d.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { d.scrollIntoView(); }
            if (ta) { ta.focus({ preventScroll: true }); }
        });
    });
    all('[data-seo-restore]').forEach(function (b) {
        b.addEventListener('click', function () {
            var slug = b.getAttribute('data-seo-restore');
            AxUX.confirm({ title: t('legal_restore'), message: t('legal_restore_confirm'), confirmLabel: t('legal_restore'), danger: false }).then(function (yes) {
                if (!yes) { return; }
                var body = one('#legal-body-' + slug), title = one('#legal-title-' + slug), tpl = one('#legal-tpl-' + slug), flag = one('#legal-restore-' + slug);
                if (body && tpl) { body.value = tpl.value; }
                if (title) { title.value = ''; }
                if (flag) { flag.value = '1'; }
                all('[data-seo-chip="' + slug + '"]').forEach(function (c) { c.className = 'ax-chip ax-chip--warn'; c.textContent = t('legal_tpl_chip'); });
                toast(t('legal_restored'), 'ok');
                dirty();
            });
        });
    });
    all('[id^="legal-body-"], [id^="legal-title-"]').forEach(function (el) {
        el.addEventListener('input', function () {
            var slug = el.id.replace(/^legal-(body|title)-/, '');
            var flag = one('#legal-restore-' + slug);
            if (flag) { flag.value = '0'; }
        });
    });

    // ---- after a save (admin_common.js posts the form with jQuery): refresh what the answer tells
    var lastSubmit = null, lastSubmitAt = 0;
    window.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[lay-submit]');
        if (b && e.isTrusted) { b.seoRetried = false; lastSubmit = b; lastSubmitAt = Date.now(); }
    }, true);
    if (window.jQuery) {
        jQuery(document).ajaxSuccess(function (ev, xhr, settings, res) {
            if (res && res.data && res.data.__token__) { setToken(res.data.__token__); }
            // The form token is one per session: a page opened earlier in another shell tab holds an old one. The answer
            // brings a fresh token, so the same Save is sent once more by itself (never twice in a row).
            if (res && Number(res.code) !== 1 && res.data && res.data.retry && lastSubmit && !lastSubmit.seoRetried && Date.now() - lastSubmitAt < 30000) {
                var again = lastSubmit;
                again.seoRetried = true;
                setTimeout(function () { again.click(); }, 50);
                return;
            }
            if (res && Number(res.code) === 1 && lastSubmit) { lastSubmit.seoRetried = false; }
            if (res && Number(res.code) !== 1 && res.data && res.data.need_confirm === 'HIDE') {
                var sw = one('input[name="seo[hide_site]"][type=checkbox]'); if (sw) { sw.checked = false; layuiRender('checkbox'); }
            }
            if (!res || Number(res.code) !== 1 || !res.data) { return; }
            if (res.data.own) {
                Object.keys(res.data.own).forEach(function (slug) {
                    var own = Number(res.data.own[slug]) === 1;
                    all('[data-seo-chip="' + slug + '"]').forEach(function (c) { c.className = 'ax-chip ' + (own ? 'ax-chip--ok' : 'ax-chip--warn'); c.textContent = t(own ? 'legal_own_chip' : 'legal_tpl_chip'); });
                    var flag = one('#legal-restore-' + slug); if (flag) { flag.value = '0'; }
                });
            }
            if (res.data.hidden !== undefined) {
                all('[data-seo-hidden-notice]').forEach(function (n) { n.hidden = Number(res.data.hidden) !== 1; });
                var sw = one('input[name="seo[hide_site]"][type=checkbox]');
                if (sw) { sw.setAttribute('data-was', Number(res.data.hidden) === 1 ? '1' : '0'); }
                var c = one('input[name="hide_confirm"]'); if (c) { c.value = ''; }
            }
        });
    }

    // ---- Search titles: own titles table (add / remove rows)
    var rowsBody = one('[data-seo-custom-rows]');
    var rowTpl = one('#seo-custom-row');
    var rowN = rowsBody ? rowsBody.children.length + 1000 : 0;
    function customEmpty() { var e = one('[data-seo-custom-empty]'); if (e && rowsBody) { e.hidden = rowsBody.children.length > 0; } }
    var addBtn = one('[data-seo-custom-add]');
    if (addBtn && rowsBody && rowTpl) {
        addBtn.addEventListener('click', function () {
            rowN++;
            var html = rowTpl.innerHTML.replace(/__i__/g, String(rowN));
            var tmp = document.createElement('tbody');
            tmp.innerHTML = html;
            var tr = tmp.firstElementChild;
            rowsBody.appendChild(tr);
            layuiRender('select');
            var f = tr.querySelector('input[type=text]'); if (f) { f.focus(); }
            customEmpty();
            dirty();
        });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-seo-custom-remove]');
        if (!b) { return; }
        var tr = b.closest('tr'); if (tr) { tr.parentNode.removeChild(tr); }
        customEmpty();
        dirty();
    });

    // ---- checklist: the two browser checks (same-origin fetch, no server outbound call)
    function checkRow(id, ok) {
        var li = one('[data-seo-check="' + id + '"]');
        if (!li) { return; }
        var chip = one('.ax-chip', li), txt = one('[data-seo-check-text]', li), fix = one('[data-seo-check-fix]', li);
        chip.className = 'ax-chip ' + (ok ? 'ax-chip--ok' : 'ax-chip--warn');
        chip.textContent = t(ok ? 'chip_ok' : 'chip_warn');
        txt.textContent = li.getAttribute(ok ? 'data-ok-text' : 'data-warn-text');
        if (fix) { fix.hidden = ok; }
        li.setAttribute('data-ok', ok ? '1' : '0');
        var prog = one('[data-seo-check-progress]');
        if (prog) {
            var lis = all('[data-seo-check]');
            prog.textContent = t('check_progress', lis.filter(function (l) { return l.getAttribute('data-ok') === '1'; }).length, lis.length);
        }
    }
    var sm = one('[data-seo-check="sitemap"]');
    if (sm && sm.getAttribute('data-url')) {
        fetch(sm.getAttribute('data-url'), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (t) { checkRow('sitemap', /<loc>/.test(t)); }, function () { checkRow('sitemap', false); }); // SEO-fix: an empty index is not OK
    } else if (sm) { checkRow('sitemap', false); }
    var gz = one('[data-seo-check="gzip"]');
    if (gz && gz.getAttribute('data-url')) {
        fetch(gz.getAttribute('data-url'), { credentials: 'omit', cache: 'no-store' }).then(function (r) {
            var enc = String(r.headers.get('content-encoding') || '').toLowerCase();
            checkRow('gzip', enc.indexOf('gzip') >= 0 || enc.indexOf('br') >= 0 || enc.indexOf('zstd') >= 0);
        }, function () { checkRow('gzip', false); });
    }

    // ---- Sitemap & robots.txt: preview, custom rules, typed BLOCK
    var robotsForm = one('[data-seo-robots]');
    function robotsMode() { var r = one('input[name="robots_mode"]:checked'); return r ? r.value : 'recommended'; }
    function robotsPreview() {
        if (!robotsForm) { return; }
        var custom = one('[name="robots_custom"]');
        var box = one('[data-seo-custom-box]'); if (box) { box.hidden = robotsMode() !== 'custom'; }
        var url = robotsForm.getAttribute('data-preview-url');
        if (!url) { return; }
        var q = new URLSearchParams({ preview: '1', mode: robotsMode(), custom: custom ? custom.value : '' });
        fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + q.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); }).then(function (res) {
                var pre = one('[data-seo-robots-preview]');
                if (pre && res && res.data && typeof res.data.text === 'string') { pre.textContent = res.data.text; }
                var w = one('[data-seo-robots-warn]'); if (w && res && res.data) { w.hidden = !res.data.blocks_all; }
            }).catch(function () { /* preview only */ });
    }
    if (robotsForm) {
        all('input[name="robots_mode"]', robotsForm).forEach(function (r) { r.addEventListener('change', function () { robotsPreview(); dirty(); }); });
        var rc = one('[name="robots_custom"]', robotsForm);
        var rcTimer = null;
        if (rc) { rc.addEventListener('input', function () { clearTimeout(rcTimer); rcTimer = setTimeout(robotsPreview, 400); }); }
        var reset = one('[data-seo-robots-reset]', robotsForm);
        if (reset) { reset.addEventListener('click', function () { var r = one('input[name="robots_mode"][value="recommended"]', robotsForm); if (r) { r.checked = true; } robotsPreview(); dirty(); }); }
        var box0 = one('[data-seo-custom-box]'); if (box0) { box0.hidden = robotsMode() !== 'custom'; }
    }
    // Same rule as SeoSettings::robotsCustom(): "/", "/*" or "/*$" for every crawler or a big search engine.
    var ROBOTS_ENGINES = ['*', 'googlebot', 'bingbot', 'msnbot', 'yandex', 'yandexbot', 'duckduckbot', 'slurp', 'applebot', 'naverbot', 'yeti'];
    function blocksAll(text) {
        var agents = [], inRules = false, block = false;
        String(text).split(/\r?\n/).forEach(function (line) {
            var m = /^\s*(user-agent|allow|disallow)\s*:\s*([^#]*)/i.exec(line);
            if (!m) { return; }
            var f = m[1].toLowerCase(), v = m[2].trim();
            if (f === 'user-agent') { if (inRules) { agents = []; inRules = false; } agents.push(v.toLowerCase()); return; }
            inRules = true;
            if (f === 'disallow' && /^\/\*{0,8}\$?$/.test(v) && v !== '/$' && agents.some(function (a) { return ROBOTS_ENGINES.indexOf(a) >= 0; })) { block = true; }
        });
        return block;
    }
    function robotsWarn() {
        var w = one('[data-seo-robots-warn]'), rcw = one('[name="robots_custom"]');
        if (w && rcw) { w.hidden = !(robotsMode() === 'custom' && blocksAll(rcw.value)); }
    }
    if (robotsForm) {
        robotsWarn();
        all('input[name="robots_mode"]', robotsForm).forEach(function (r) { r.addEventListener('change', robotsWarn); });
        var rcw0 = one('[name="robots_custom"]', robotsForm);
        if (rcw0) { rcw0.addEventListener('input', robotsWarn); }
    }
    // Typed confirmations before a layui submit: robots BLOCK (E.2); URL change (E.4).
    window.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[lay-submit]');
        if (!btn) { return; }
        var form = btn.closest('form');
        if (form && form.hasAttribute('data-seo-robots') && robotsMode() === 'custom') {
            var bc = one('input[name="block_confirm"]', form), rcv = one('[name="robots_custom"]', form);
            if (bc && bc.value !== 'BLOCK' && rcv && blocksAll(rcv.value)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                AxUX.confirm({ title: t('robots_block_title'), message: t('robots_block_confirm'), typed: 'BLOCK', danger: true }).then(function (yes) {
                    if (yes) { bc.value = 'BLOCK'; btn.click(); }
                });
            }
        }
    }, true);

    // ---- IndexNow buttons (make/indexnow, POST + token) and the key file check
    all('[data-seo-indexnow]').forEach(function (b) {
        b.addEventListener('click', function () {
            var act = b.getAttribute('data-seo-indexnow'), url = b.getAttribute('data-url');
            var go = function () {
                b.disabled = true;
                post(url, { 'do': act, '__token__': token() }).then(function (res) {
                    toast(res.msg, Number(res.code) === 1 ? 'ok' : 'error');
                    if (Number(res.code) === 1) { setTimeout(function () { location.reload(); }, 900); } else { b.disabled = false; }
                }).catch(function () { b.disabled = false; toast(kit('request_failed'), 'error'); });
            };
            if (act === 'newkey') {
                AxUX.confirm({ title: t('indexnow_newkey'), message: t('indexnow_newkey_confirm'), confirmLabel: t('indexnow_newkey'), danger: false }).then(function (yes) { if (yes) { go(); } });
            } else { go(); }
        });
    });
    var keyChip = one('[data-seo-keyfile]');
    if (keyChip && keyChip.getAttribute('data-url')) {
        fetch(keyChip.getAttribute('data-url'), { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : ''; }, function () { return ''; }).then(function (body) {
            var ok = body.trim() === keyChip.getAttribute('data-key');
            keyChip.className = 'ax-chip ' + (ok ? 'ax-chip--ok' : 'ax-chip--warn');
            keyChip.textContent = ok ? t('indexnow_key_ok') : t('indexnow_key_missing', keyChip.getAttribute('data-url'));
        });
    }

    // ---- E.4: changing the URL preset of an indexed site asks first
    if (one('[data-seo-url-warn]')) {
        var urlOk = false;
        window.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-url-apply]');
            if (!b || urlOk || b.disabled) { return; }
            e.preventDefault();
            e.stopImmediatePropagation();
            AxUX.confirm({ message: t('url_change_confirm'), confirmLabel: t('url_change_ok'), danger: false }).then(function (yes) {
                if (yes) { urlOk = true; b.click(); setTimeout(function () { urlOk = false; }, 0); }
            });
        }, true);
    }
}());
