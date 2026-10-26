/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* JavCMS admin shell: menu, search, tabs, account menu, lock screen (ADMIN-IA-BRIEF.md sections 1.2, 4.1-4.6).
   Needs: layui (element, layer), AxUX (static/js/admin_ux.js), window.AX_SHELL, AX_SHELL_LANG, AX_SHELL_INDEX (index.html).
   Public: window.AdminShell.open(id, {url, title}) opens or activates a tab; child pages may also post
   {ax:'open', tab:'n96', url:'...', title:'...'} to the shell (same origin only). */
(function () {
    'use strict';
    var shell = document.getElementById('adminShell');
    if (!shell || !window.layui) return;
    var CFG = window.AX_SHELL || {};
    var L = window.AX_SHELL_LANG || {};
    var INDEX = window.AX_SHELL_INDEX || { items: [], retired: [] };
    var OVERVIEW = '111';
    var TAB_LIMIT = CFG.tabLimit || 12;
    var nav = document.getElementById('switchNav');
    var navBar = document.getElementById('navBar');
    var toggle = document.getElementById('foldSwitch');
    var tabsUl = document.getElementById('B_history');
    var search = document.getElementById('studioNavSearch');
    var results = document.getElementById('studioSearchResults');
    var searchStatus = document.getElementById('studioSearchStatus');
    var lockBox = document.getElementById('shellLock');
    var mobile = window.matchMedia('(max-width: 767px)');
    var GROUPS_KEY = 'javcms.admin.groups';

    function t(k, v) {
        var s = Object.prototype.hasOwnProperty.call(L, k) ? L[k] : k;
        return window.AxUX ? window.AxUX.format(s, v) : String(s).replace('%s', v);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, kind) { if (window.AxUX) window.AxUX.toast(msg, kind || 'ok'); }
    function setCookie(name, value, days) {
        var exp = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + exp + '; path=/; SameSite=Lax';
    }
    function store(key, value) {
        try {
            if (value === undefined) return JSON.parse(window.localStorage.getItem(key) || 'null');
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) { return null; }
        return null;
    }
    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: body,
            // The session admin token goes with every shell POST (Clear cache, Lock screen: security r3 P3-2).
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': CFG.csrf || '' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.locked) showLock();
            return res;
        });
    }
    function sprite(name) {
        return '<svg class="ax-ic" aria-hidden="true" focusable="false"><use href="' + esc(window.AX_ICONS || '') + '#' + esc(name) + '"/></svg>';
    }
    function links() { return Array.prototype.slice.call(navBar.querySelectorAll('.admin-nav-item')); }
    function linkFor(id) { return navBar.querySelector('.admin-nav-item[data-id="' + id + '"]'); }

    /* ------------------------------------------------------------ tabs (4.3) */
    var element, layer;
    function frameOf(id) { return shell.querySelector('iframe[lay-id="' + id + '"]'); }
    function tabOf(id) { return tabsUl.querySelector('li[lay-id="' + id + '"]'); }
    function activeId() { var li = tabsUl.querySelector('li.layui-this'); return li ? li.getAttribute('lay-id') : OVERVIEW; }
    function frameDirty(id) {
        var f = frameOf(id);
        try { return !!(f && f.contentWindow && f.contentWindow.AxUX && f.contentWindow.AxUX.isDirty()); } catch (e) { return false; }
    }
    function markFrameClean(id) {
        var f = frameOf(id);
        try { if (f && f.contentWindow && f.contentWindow.AxUX) f.contentWindow.AxUX.markClean(); } catch (e) { /* cross-page */ }
    }
    function askLeave() {
        if (!window.AxUX) return Promise.resolve(window.confirm(t('unsaved')));
        return window.AxUX.confirm({ title: t('confirm_title'), message: t('unsaved'), confirmLabel: t('leave'), cancelLabel: t('stay'), danger: true, count: 0 });
    }
    function guard(ids) {
        var dirty = ids.filter(frameDirty);
        if (!dirty.length) return Promise.resolve(true);
        return askLeave().then(function (yes) { if (yes) dirty.forEach(markFrameClean); return yes; });
    }
    function tabCount() { return tabsUl.querySelectorAll('li[lay-id]:not([lay-id="' + OVERVIEW + '"])').length; }
    function addTab(id, url, title) {
        element.tabAdd('macTab', {
            title: esc(title),
            content: '<iframe lay-id="' + esc(id) + '" src="' + esc(url) + '" title="' + esc(title) + '" class="x-iframe"></iframe>',
            id: id
        });
        decorateTabs();
    }
    function closeTabs(ids) {
        ids = ids.filter(function (id) { return id && id !== OVERVIEW && tabOf(id); });
        return guard(ids).then(function (ok) {
            if (!ok) return false;
            ids.forEach(function (id) { element.tabDelete('macTab', id); });
            syncActive();
            return true;
        });
    }
    function otherIds(keep) {
        return Array.prototype.map.call(tabsUl.querySelectorAll('li[lay-id]'), function (li) { return li.getAttribute('lay-id'); })
            .filter(function (id) { return id !== OVERVIEW && id !== keep; });
    }
    function openTab(id, url, title) {
        if (!id || !url) return;
        if (tabOf(id)) {
            // R12 / B3: an open tab is only activated, never reloaded. A different address (e.g. a chip link from the
            // Overview) navigates that tab, after asking when it has unsaved work.
            element.tabChange('macTab', id);
            var f = frameOf(id);
            var same = false;
            try { same = !f || new URL(url, location.href).href === new URL(f.getAttribute('src'), location.href).href; } catch (e) { same = true; }
            if (!same) {
                guard([id]).then(function (ok) { if (ok) { f.setAttribute('src', url); } });
            }
            closeDrawerIfPhone();
            return;
        }
        if (tabCount() >= TAB_LIMIT) {
            // B4 / O10: the limit counts every tab except the Overview; offer a way out.
            var ask = window.AxUX ? window.AxUX.confirm({ title: t('tabs'), message: t('tab_limit', TAB_LIMIT),
                confirmLabel: t('tab_close_others'), cancelLabel: t('cancel'), danger: false, count: 0 }) : Promise.resolve(false);
            ask.then(function (yes) {
                if (!yes) return;
                closeTabs(otherIds(activeId() === OVERVIEW ? '' : activeId())).then(function (ok) { if (ok && tabCount() < TAB_LIMIT) openTab(id, url, title); });
            });
            return;
        }
        addTab(id, url, title);
        element.tabChange('macTab', id);
        closeDrawerIfPhone();
    }
    function reloadTab(id) {
        guard([id]).then(function (ok) {
            if (!ok) return;
            var f = frameOf(id);
            try { f.contentWindow.location.reload(); } catch (e) { f.setAttribute('src', f.getAttribute('src')); }
        });
    }
    // Close buttons and the Overview tab: sprite x, labels, no close on the Overview.
    function decorateTabs() {
        Array.prototype.forEach.call(tabsUl.querySelectorAll('li[lay-id]'), function (li) {
            var close = li.querySelector('.layui-tab-close');
            if (!close) return;
            if (li.getAttribute('lay-id') === OVERVIEW) { close.remove(); return; }
            if (!close.querySelector('svg')) {
                close.textContent = '';
                close.insertAdjacentHTML('beforeend', sprite('ai-x'));
            }
            var title = li.textContent.trim();
            close.setAttribute('role', 'button');
            close.setAttribute('aria-label', t('tab_close') + ': ' + title);
            close.setAttribute('title', t('tab_close'));
        });
    }
    // Unsaved-tab guard on the tab's x (runs before layui's own handler).
    window.addEventListener('click', function (e) {
        var close = e.target.closest && e.target.closest('#B_history .layui-tab-close');
        if (!close) return;
        var li = close.closest('li');
        var id = li && li.getAttribute('lay-id');
        if (!id || !frameDirty(id)) return;
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
        closeTabs([id]);
    }, true);

    /* ------------------------------------------------------------ active item + breadcrumb (R7, 4.1) */
    var sectionEl = document.getElementById('studioSection');
    var pageEl = document.getElementById('studioPage');
    function syncActive() {
        var id = activeId();
        var li = tabOf(id);
        var link = linkFor(id);
        var title = li ? li.textContent.trim() : '';
        links().forEach(function (a) {
            var on = a === link;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        var group = link ? (link.getAttribute('data-group') || '') : '';
        sectionEl.textContent = group;
        sectionEl.parentNode.classList.toggle('has-section', !!group);
        pageEl.textContent = link ? link.getAttribute('data-title') : title;
        if (link) openGroupOf(link, false);
        Array.prototype.forEach.call(tabsUl.querySelectorAll('li'), function (tab) {
            var cur = tab === li;
            tab.tabIndex = cur ? 0 : -1;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', String(cur));
        });
        if (li && typeof li.scrollIntoView === 'function') li.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    /* ------------------------------------------------------------ groups: accordion (R6) */
    var groupState = store(GROUPS_KEY);
    var firstVisit = !groupState || typeof groupState !== 'object';
    if (firstVisit) groupState = { '2': true, '4': true, '9': true };
    function setGroup(sec, open, save) {
        var head = sec.querySelector('.studio-group__head');
        var body = sec.querySelector('.studio-group__body');
        head.setAttribute('aria-expanded', String(open));
        body.hidden = !open;
        sec.classList.toggle('is-open', open);
        if (save) { groupState[sec.getAttribute('data-group-key')] = open; store(GROUPS_KEY, groupState); }
    }
    function openGroupOf(link, save) {
        var sec = link.closest('.studio-group');
        if (sec && !sec.classList.contains('is-open')) setGroup(sec, true, save);
    }
    Array.prototype.forEach.call(navBar.querySelectorAll('.studio-group'), function (sec) {
        setGroup(sec, !!groupState[sec.getAttribute('data-group-key')], false);
        sec.querySelector('.studio-group__head').addEventListener('click', function () {
            if (shell.classList.contains('is-collapsed') && !mobile.matches) { toggleFlyout(sec); return; }
            setGroup(sec, !sec.classList.contains('is-open'), true);
        });
    });

    /* ------------------------------------------------------------ collapsed rail + flyouts (4.2, desktop) */
    var flyout = null;
    function closeFlyout() { if (flyout) { flyout.classList.remove('is-flyout'); flyout = null; } }
    function placeFlyout(sec) {
        var head = sec.querySelector('.studio-group__head');
        var body = sec.querySelector('.studio-group__body');
        var r = head.getBoundingClientRect();
        var maxTop = window.innerHeight - Math.min(body.scrollHeight, window.innerHeight - 80) - 16;
        body.style.top = Math.max(72, Math.min(r.top, maxTop)) + 'px';
        body.style.left = (nav.getBoundingClientRect().right + 4) + 'px';
    }
    function openFlyout(sec) {
        if (flyout === sec) return;
        closeFlyout();
        flyout = sec;
        sec.classList.add('is-flyout');
        placeFlyout(sec);
    }
    function toggleFlyout(sec) { if (flyout === sec) closeFlyout(); else openFlyout(sec); }
    Array.prototype.forEach.call(navBar.querySelectorAll('.studio-group'), function (sec) {
        sec.addEventListener('mouseenter', function () { if (shell.classList.contains('is-collapsed') && !mobile.matches) openFlyout(sec); });
        sec.addEventListener('mouseleave', function () { if (flyout === sec && !sec.contains(document.activeElement)) closeFlyout(); });
        sec.addEventListener('focusin', function () { if (shell.classList.contains('is-collapsed') && !mobile.matches) openFlyout(sec); });
        sec.addEventListener('focusout', function (e) { if (flyout === sec && !sec.contains(e.relatedTarget)) closeFlyout(); });
    });
    function setCollapsed(on) {
        shell.classList.toggle('is-collapsed', on);
        toggle.setAttribute('aria-expanded', String(!on));
        setCookie('javcms_nav_collapsed', on ? '1' : '0', 365);
        closeFlyout();
    }

    /* ------------------------------------------------------------ phone drawer (4.2) */
    function drawer(open, restoreFocus) {
        shell.classList.toggle('studio-drawer-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        ['switchBody'].forEach(function (id) { document.getElementById(id).inert = open; });
        shell.querySelector('.studio-header').inert = open;
        shell.querySelector('.studio-footer').inert = open;
        if (open) search.focus();
        else if (restoreFocus) toggle.focus();
    }
    function closeDrawerIfPhone() { if (mobile.matches && shell.classList.contains('studio-drawer-open')) drawer(false, true); }
    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        if (mobile.matches) drawer(!shell.classList.contains('studio-drawer-open'), true);
        else setCollapsed(!shell.classList.contains('is-collapsed'));
    });
    shell.querySelector('.studio-drawer-close').addEventListener('click', function () { drawer(false, true); });
    shell.querySelector('.site-mobile-shade').addEventListener('click', function () { drawer(false, true); });
    function syncViewport() {
        drawer(false, false);
        toggle.setAttribute('aria-expanded', String(mobile.matches ? false : !shell.classList.contains('is-collapsed')));
        closeFlyout();
    }
    if (mobile.addEventListener) mobile.addEventListener('change', syncViewport); else mobile.addListener(syncViewport);

    /* ------------------------------------------------------------ menu clicks (R12) */
    navBar.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a.admin-nav-item');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        try { window.localStorage.setItem('adminNavTag', a.getAttribute('href')); } catch (err) { /* convenience */ }
        closeFlyout();
        openTab(a.getAttribute('data-id'), a.getAttribute('href'), a.getAttribute('data-title'));
    });
    shell.querySelector('.studio-header [data-shell-overview]').addEventListener('click', function (e) {
        e.preventDefault(); element.tabChange('macTab', OVERVIEW);
    });

    /* ------------------------------------------------------------ advanced switch (R8, R9) + compact (R3) */
    var advSection = document.getElementById('navAdvanced');
    var advBtn = shell.querySelector('.studio-adv-switch');
    function setAdvanced(on) {
        if (!advSection) return;
        advSection.hidden = !on;
        setCookie('javcms_adv_' + (CFG.adminId || 0), on ? '1' : '0', 365);
        if (advBtn) {
            advBtn.setAttribute('aria-checked', String(on));
            advBtn.querySelector('.studio-adv-switch__text').textContent = on ? advBtn.getAttribute('data-on-text') : advBtn.getAttribute('data-off-text');
        }
        Array.prototype.forEach.call(shell.querySelectorAll('[data-shell-act="advanced"]'), function (b) { b.setAttribute('aria-checked', String(on)); });
        if (on) { setGroup(advSection, true, true); advSection.scrollIntoView({ block: 'nearest' }); }
    }
    if (advBtn) advBtn.addEventListener('click', function () { setAdvanced(advBtn.getAttribute('aria-checked') !== 'true'); });
    function setCompact(on) {
        shell.classList.toggle('is-compact', on);
        setCookie('javcms_compact', on ? '1' : '0', 365);
        Array.prototype.forEach.call(shell.querySelectorAll('[data-shell-act="compact"]'), function (b) { b.setAttribute('aria-checked', String(on)); });
    }

    /* ------------------------------------------------------------ clear cache (F4: never reloads anything) */
    var clearBtn = document.getElementById('shellClearCache');
    // Busy state via aria-disabled, not the disabled property: a disabled button drops keyboard focus,
    // so a second Enter / Space did nothing (repair-shell hardening round).
    var clearBusy = false;
    function clearCache() {
        if (clearBusy) return;
        clearBusy = true;
        if (clearBtn) clearBtn.setAttribute('aria-disabled', 'true');
        post(CFG.clearUrl, {}).then(function (res) {
            if (res && Number(res.code) === 1) toast(t('cache_cleared'), 'ok');
            else if (!res || !res.locked) toast((res && res.msg) || t('cache_failed'), 'error');
        }).catch(function () { toast(t('cache_failed'), 'error'); }).then(function () {
            clearBusy = false;
            if (clearBtn) clearBtn.removeAttribute('aria-disabled');
        });
    }
    if (clearBtn) clearBtn.addEventListener('click', clearCache);

    /* ------------------------------------------------------------ lock screen (4.6) */
    var lockForm = lockBox ? lockBox.querySelector('form') : null;
    var lockErr = lockBox ? lockBox.querySelector('.studio-lock__error') : null;
    var lockPwd = document.getElementById('shellLockPwd');
    function setInert(on) {
        ['.studio-header', '#switchNav', '#switchBody', '.studio-footer'].forEach(function (sel) {
            var el = shell.querySelector(sel); if (el) el.inert = on;
        });
    }
    function showLock() {
        if (!lockBox || !lockBox.hidden) return;
        if (window.AxUX) window.AxUX.closeMenus();
        drawer(false, false);
        lockBox.hidden = false;
        setInert(true);
        lockErr.textContent = '';
        lockPwd.value = '';
        setTimeout(function () { lockPwd.focus(); }, 30);
    }
    function hideLock() { lockBox.hidden = true; setInert(false); lockPwd.value = ''; }
    function lockNow() {
        post(CFG.unlockUrl, { 'do': 'lock' }).then(function (res) {
            if (res && Number(res.code) === 1) showLock(); else toast((res && res.msg) || t('request_failed'), 'error');
        }).catch(function () { toast(t('request_failed'), 'error'); });
    }
    if (lockForm) {
        lockForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var pwd = lockPwd.value;
            if (!pwd) { lockPwd.focus(); return; }
            post(CFG.unlockUrl, { password: pwd }).then(function (res) {
                if (res && Number(res.code) === 1) hideLock();
                else if (res && res.logout && res.url) { lockErr.textContent = res.msg || ''; setTimeout(function () { window.location.href = res.url; }, 1500); }
                else { lockErr.textContent = (res && res.msg) || t('lock_wrong'); lockPwd.select(); }
            }).catch(function () { lockErr.textContent = t('request_failed'); });
        });
        lockBox.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;
            var f = Array.prototype.filter.call(lockBox.querySelectorAll('input,button,a[href]'), function (el) { return el.getClientRects().length; });
            if (!f.length) return;
            if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        });
    }

    /* ------------------------------------------------------------ account menu (4.5) */
    function openAccount() { openTab('n1006', CFG.accountUrl, L.account_tab || ''); }
    function setLanguage(code, name) {
        var ask = window.AxUX ? window.AxUX.confirm({ title: t('language'), message: t('language_reload', name), confirmLabel: name,
            danger: false, count: 0 }) : Promise.resolve(window.confirm(t('language_reload', name)));
        ask.then(function (yes) {
            if (!yes) return;
            var dirty = otherIds('').filter(frameDirty);
            (dirty.length ? guard(dirty) : Promise.resolve(true)).then(function (ok) {
                if (!ok) return;
                setCookie('javcms_admin_lang', code, 365);
                window.location.reload();
            });
        });
    }
    shell.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-shell-act],[data-shell-lang],[data-tab-act]');
        if (!b || !shell.contains(b)) return;
        var act = b.getAttribute('data-shell-act');
        if (b.hasAttribute('data-shell-lang')) { if (b.getAttribute('aria-checked') !== 'true') setLanguage(b.getAttribute('data-shell-lang'), b.textContent.trim()); return; }
        var tab = b.getAttribute('data-tab-act');
        if (tab === 'reload') { reloadTab(activeId()); return; }
        if (tab === 'others') { closeTabs(otherIds(activeId())); return; }
        if (tab === 'all') { closeTabs(otherIds('')); return; }
        if (act === 'account') openAccount();
        else if (act === 'lock') lockNow();
        else if (act === 'advanced') setAdvanced(!(advSection && !advSection.hidden));
        else if (act === 'compact') setCompact(!shell.classList.contains('is-compact'));
    });

    /* ------------------------------------------------------------ bell (4.1) */
    var bell = document.getElementById('shellBell');
    if (bell) bell.addEventListener('click', function () {
        element.tabChange('macTab', OVERVIEW);
        var f = frameOf(OVERVIEW);
        try {
            var target = f.contentWindow.document.getElementById('attention');
            if (target) { target.scrollIntoView({ block: 'start' }); target.focus({ preventScroll: true }); }
        } catch (e) { /* not loaded yet */ }
    });

    /* ------------------------------------------------------------ search (4.2, F5, N24) */
    function norm(s) {
        s = String(s || '').toLowerCase();
        try { s = s.normalize('NFD').replace(/\p{M}/gu, ''); } catch (e) { s = s.replace(/[\u0300-\u036f]/g, ''); }
        return s.replace(/\u0111/g, 'd');
    }
    var HAN = /[\u3400-\u9fff\uf900-\ufaff]/;
    function words(s) { return norm(s).split(/[^0-9a-z\u00c0-\uffff]+/).filter(Boolean); }
    // Every query word must match its OWN word of the text (exact first, then as a prefix): "add ad" no longer
    // matches "Add video" twice through the one word "add".
    function matchAll(qWords, text) {
        if (!text) return false;
        var w = words(text);
        var flat = norm(text);
        var used = [];
        var order = qWords.slice().sort(function (a, b) { return b.length - a.length; });
        return order.every(function (q) {
            if (HAN.test(q)) return flat.indexOf(q) !== -1;
            var k = -1;
            for (var j = 0; j < w.length; j++) { if (!used[j] && w[j] === q) { k = j; break; } }
            if (k < 0) { for (var m = 0; m < w.length; m++) { if (!used[m] && w[m].indexOf(q) === 0) { k = m; break; } } }
            if (k < 0) return false;
            used[k] = true;
            return true;
        });
    }
    function fullText(item) { return [item.label, item.kw, item.kw_en, item.label_en, item.help].join(' '); }
    function score(item, q, qWords) {
        if (qWords.length > 1 && norm(item.label).indexOf(q) === 0) return 4;
        if (matchAll(qWords, item.label)) return 3;
        if (matchAll(qWords, [item.label, item.kw, item.kw_en, item.label_en].join(' '))) return 2;
        if (matchAll(qWords, fullText(item))) return 1;
        return 0;
    }
    // Beginner r7 P2-2: verbs and small words a beginner adds ("change theme", "hide a category", "đổi giao diện")
    // never decide the match on their own. They are dropped from the words that must match (when a word is left)
    // and only add a small bonus to pages that also contain them.
    var FILLER = ['change', 'edit', 'add', 'new', 'create', 'make', 'set', 'setup', 'hide', 'show', 'turn', 'upload', 'how', 'to',
        'my', 'the', 'a', 'an', 'of', 'for', 'on', 'off', 'in', 'doi', 'sua', 'them', 'tao', 'an', 'hien', 'bat', 'tat', 'cach', 'cua',
        'toi', 'cho', 'cai'];
    function rankItems(q, qWords) {
        var core = qWords.filter(function (w) { return FILLER.indexOf(w) === -1; });
        if (!core.length) core = qWords;
        var list = [];
        (INDEX.items || []).forEach(function (it, i) {
            var s = score(it, q, core);
            if (!s) return;
            var all = words(fullText(it));
            if (it.act) {
                // Account actions (Sign out, Lock...) match by their name or a whole keyword, never by a prefix of
                // a keyword ("logo" must not find "logout"), and rank below pages.
                if (s < 3 && !core.every(function (w) { return HAN.test(w) || all.indexOf(w) !== -1; })) return;
                s -= 0.3;
            }
            // The first word of a page's own keywords is its main subject ("videos" for All videos, "settings" for
            // settings pages): "video" lists All videos before Video player.
            var kwHead = words(it.kw || '')[0] || '';
            if (kwHead && core.some(function (w) { return kwHead.indexOf(w) === 0; })) s += 0.1;
            if (core.length < qWords.length) {
                if (matchAll(qWords, it.label)) s += 0.5;
                else if (matchAll(qWords, fullText(it))) s += 0.25;
            }
            if (qWords.length > 1) qWords.forEach(function (w) { if (all.indexOf(w) !== -1) s += 0.05; });
            if (it.adv) s -= 0.5;
            list.push({ it: it, s: s, i: i });
        });
        if (!list.length) {
            // Nothing matched every word: show the pages that match the most of the words, below any full match.
            (INDEX.items || []).forEach(function (it, i) {
                var n = 0;
                core.forEach(function (w) { if (matchAll([w], fullText(it))) n++; });
                if (n) list.push({ it: it, s: 0.5 * n / core.length - (it.adv ? 0.1 : 0), i: i });
            });
        }
        // Equal scores: main pages before Advanced pages and before the account actions (Sign out, Lock).
        list.sort(function (a, b) { return b.s - a.s || (a.it.adv | 0) - (b.it.adv | 0) || (a.it.act ? 1 : 0) - (b.it.act ? 1 : 0) || a.i - b.i; });
        return list;
    }
    var found = [];
    var sel = -1;
    function runSearch() {
        var raw = search.value.trim();
        var q = norm(raw);
        var qWords = words(raw);
        if (!q || !qWords.length) { closeResults(); return; }
        var list = rankItems(q, qWords).slice(0, 8);
        (INDEX.retired || []).forEach(function (r) {
            if (list.length < 8 && matchAll(qWords, [r.kw, r.kw_en].join(' '))) list.push({ it: r, s: 0, i: 999 });
        });
        found = list.map(function (x) { return x.it; });
        renderResults();
    }
    function renderResults() {
        results.innerHTML = '';
        if (!found.length) {
            results.insertAdjacentHTML('beforeend', '<p class="studio-results__none">' + esc(t('search_none')) + '</p>');
            sel = -1;
        } else {
            found.forEach(function (it, i) {
                var meta = it.retired ? '' : esc(it.group || '');
                var html = '<div class="studio-result' + (it.retired ? ' studio-result--note' : '') + '" role="option" id="sr-' + i + '" data-i="' + i + '" aria-selected="false">' +
                    sprite(it.retired ? 'ai-info' : (it.icon || 'ai-circle')) +
                    '<span class="studio-result__text"><span class="studio-result__label">' + esc(it.label) +
                    (it.adv ? ' <span class="studio-result__badge">' + esc(t('badge_advanced')) + '</span>' : '') + '</span>' +
                    (it.help ? '<span class="studio-result__help">' + esc(it.help) + '</span>' : '') +
                    (meta ? '<span class="studio-result__group">' + meta + '</span>' : '') + '</span></div>';
                results.insertAdjacentHTML('beforeend', html);
            });
            sel = 0;
        }
        results.hidden = false;
        navBar.hidden = true;
        search.setAttribute('aria-expanded', 'true');
        searchStatus.textContent = found.length ? found.length + '' : t('search_none');
        highlight();
    }
    function highlight() {
        Array.prototype.forEach.call(results.querySelectorAll('.studio-result'), function (row, i) {
            row.setAttribute('aria-selected', String(i === sel));
            if (i === sel) { search.setAttribute('aria-activedescendant', row.id); row.scrollIntoView({ block: 'nearest' }); }
        });
        if (sel < 0) search.removeAttribute('aria-activedescendant');
    }
    function closeResults() {
        results.hidden = true;
        results.innerHTML = '';
        navBar.hidden = false;
        found = []; sel = -1;
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant');
        searchStatus.textContent = '';
    }
    function choose(it) {
        if (!it) return;
        if (it.act) {
            search.value = ''; closeResults();
            if (it.act === 'account') openAccount();
            else if (it.act === 'clear') clearCache();
            else if (it.act === 'lock') lockNow();
            else if (it.act === 'view') window.open((window.ROOT_PATH || '') + '/', '_blank', 'noopener');
            else if (it.act === 'logout') window.location.href = CFG.logoutUrl;
            else if (it.act === 'advanced') setAdvanced(true);
            else if (it.act === 'language') { var acc = shell.querySelector('.studio-account > button'); if (acc) acc.click(); }
            return;
        }
        if (it.retired) {
            var target = it.id ? linkFor(it.id) : null;
            if (target) { search.value = ''; closeResults(); openTab(target.getAttribute('data-id'), target.getAttribute('href'), target.getAttribute('data-title')); }
            return;
        }
        search.value = ''; closeResults();
        openTab(it.id, it.url, it.tab || it.label);
    }
    search.addEventListener('input', runSearch);
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && found.length) { e.preventDefault(); sel = (sel + 1) % found.length; highlight(); }
        else if (e.key === 'ArrowUp' && found.length) { e.preventDefault(); sel = (sel - 1 + found.length) % found.length; highlight(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (found.length) choose(found[sel < 0 ? 0 : sel]); }
        else if (e.key === 'Escape') {
            e.preventDefault(); e.stopPropagation();
            if (search.value) { search.value = ''; closeResults(); } else { search.blur(); if (mobile.matches) drawer(false, true); }
        }
    });
    results.addEventListener('click', function (e) {
        var row = e.target.closest && e.target.closest('.studio-result');
        if (row) choose(found[Number(row.getAttribute('data-i'))]);
    });
    function focusSearch() {
        if (mobile.matches) drawer(true, false);
        else { if (shell.classList.contains('is-collapsed')) setCollapsed(false); search.focus(); search.select(); }
    }
    document.getElementById('shellSearchBtn').addEventListener('click', focusSearch);

    /* ------------------------------------------------------------ keyboard + closing (4.4) */
    document.addEventListener('keydown', function (e) {
        var typing = e.target.matches && e.target.matches('input,textarea,select,[contenteditable="true"]');
        if (lockBox && !lockBox.hidden) return;
        if ((e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) || ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K'))) {
            e.preventDefault(); focusSearch(); return;
        }
        if (e.key === 'Escape') {
            closeFlyout();
            if (shell.classList.contains('studio-drawer-open')) drawer(false, true);
        }
        if (e.key === 'Tab' && shell.classList.contains('studio-drawer-open')) {
            var focusable = Array.prototype.filter.call(nav.querySelectorAll('button,input,a[href]'), function (el) { return el.getClientRects().length && !el.closest('[hidden]'); });
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('pointerdown', function (e) { if (flyout && !flyout.contains(e.target)) closeFlyout(); }, true);
    window.addEventListener('blur', closeFlyout);
    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !e.data || typeof e.data !== 'object') return;
        var d = e.data;
        if (d.ax === 'close-menus') { closeFlyout(); }
        else if (d.ax === 'escape') { closeFlyout(); if (shell.classList.contains('studio-drawer-open')) drawer(false, true); }
        else if (d.ax === 'locked') { showLock(); }
        else if (d.ax === 'open' && typeof d.tab === 'string') { api.open(d.tab, { url: d.url, title: d.title }); }
    });

    /* ------------------------------------------------------------ public API */
    var api = {
        open: function (id, opts) {
            opts = opts || {};
            var link = linkFor(id);
            var url = opts.url || (link ? link.getAttribute('href') : (id === 'n1006' ? CFG.accountUrl : ''));
            var title = opts.title || (link ? link.getAttribute('data-title') : (id === 'n1006' ? (L.account_tab || '') : ''));
            if (id === OVERVIEW) { element.tabChange('macTab', OVERVIEW); return; }
            if (url && typeof url === 'string' && /^(\/|https?:\/\/)/.test(url)) {
                try { if (new URL(url, location.href).origin !== location.origin) return; } catch (err) { return; }
                openTab(id, url, title);
            }
        },
        isLocked: function () { return lockBox && !lockBox.hidden; },
        lock: showLock
    };
    window.AdminShell = api;

    /* ------------------------------------------------------------ start */
    layui.use(['element', 'layer'], function () {
        element = layui.element;
        layer = layui.layer;
        element.on('tab(macTab)', function () { syncActive(); });
        new MutationObserver(function () { decorateTabs(); syncActive(); }).observe(tabsUl, { childList: true });
        decorateTabs();
        syncActive();
        syncViewport();
        tabsUl.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('li, .layui-tab-close')) { e.preventDefault(); e.stopPropagation(); e.target.click(); }
            if (e.key === 'Delete' && e.target.matches('li')) { var id = e.target.getAttribute('lay-id'); if (id !== OVERVIEW) closeTabs([id]); }
            if (e.target.matches('li') && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) !== -1) {
                e.preventDefault();
                var tabs = Array.prototype.slice.call(tabsUl.querySelectorAll('li'));
                var i = tabs.indexOf(e.target);
                var n = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                tabs[n].click(); tabs[n].focus();
            }
        });
        function step(dir) {
            var cur = tabsUl.querySelector('li.layui-this');
            var next = cur && (dir > 0 ? cur.nextElementSibling : cur.previousElementSibling);
            if (next) { element.tabChange('macTab', next.getAttribute('lay-id')); }
        }
        document.getElementById('page-prev').addEventListener('click', function (e) { e.preventDefault(); step(-1); });
        document.getElementById('page-next').addEventListener('click', function (e) { e.preventDefault(); step(1); });
        // "New cache data" notice of the old shell, every 30 s.
        setInterval(function () {
            if (document.hidden || (lockBox && !lockBox.hidden)) return;
            fetch(CFG.checkCacheUrl, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (txt) {
                    if (txt === 'haved' && L.cache_data) toast(L.cache_data, 'info');
                    else if (txt.charAt(0) === '{') { try { if (JSON.parse(txt).locked) showLock(); } catch (err) { /* ignore */ } }
                }).catch(function () { /* offline */ });
        }, 30000);
    });
}());
