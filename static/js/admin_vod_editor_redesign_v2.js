/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* VOD editor structure v2. Moves original nodes; never clones controls or changes values. */
(function () {
    'use strict';
    var editor = document.querySelector('.admin-vod-info');
    var form = editor && editor.querySelector('form.layui-form-pane');
    var legacy = form && form.querySelector('.layui-tab');
    if (!legacy || editor.classList.contains('studio-vod-editor')) { return; }

    // N18: every visible text comes from window.AX_VOD_LANG (printed by vod/info.html with lang()); English fallback.
    var T = window.AX_VOD_LANG || {};
    function t(key, fallback) { return typeof T[key] === 'string' && T[key] !== '' ? T[key] : fallback; }
    var sections = [
        ['overview', t('overview', 'Overview'), t('overview_help', 'Title, visibility and description')],
        ['metadata', t('metadata', 'Details'), t('metadata_help', 'Code, actors, tags and release information')],
        ['artwork', t('artwork', 'Images'), t('artwork_help', 'Cover, thumbnail, banner and screenshots')],
        ['playback', t('playback', 'Playback'), t('playback_help', 'Player sources, episodes and download links')],
        ['access', t('access', 'Access'), t('access_help', 'Import protection, points and passwords')],
        ['advanced', t('advanced', 'Advanced'), t('advanced_help', 'Statistics, templates and related items')]
    ];
    var groups = {
        // Beginner r3 P3-7: the series fields (vod_isend, vod_total, vod_serial) mean nothing for a single video; they live
        // in Advanced under a "Series" note (see seriesFields below). Stored data and the import mapping are unchanged.
        overview: ['vod_name', 'vod_sub', 'type_id', 'vod_status', 'vod_remarks', 'vod_blurb', 'vod_content'],
        metadata: ['vod_en', 'vod_douban_id', 'vod_douban_score', 'vod_tag', 'uptag', 'vod_actor', 'vod_director', 'vod_writer', 'vod_tv', 'vod_weekday', 'vod_duration', 'vod_pubdate', 'vod_class', 'vod_year', 'vod_area', 'vod_lang', 'vod_version', 'vod_state'],
        artwork: ['vod_pic', 'vod_pic_thumb', 'vod_pic_slide', 'vod_pic_screenshot'],
        access: ['vod_lock', 'vod_copyright', 'vod_points', 'vod_points_play', 'vod_points_down', 'vod_pwd', 'vod_pwd_url', 'vod_pwd_play', 'vod_pwd_play_url', 'vod_pwd_down', 'vod_pwd_down_url']
    };
    var independentLabels = {
        type_id: t('type_id', 'Category'), vod_level: t('vod_level', 'Recommendation level'), vod_status: t('vod_status', 'Visibility'),
        vod_lock: t('vod_lock', 'Protected from import'), vod_isend: t('vod_isend', 'Series status'), vod_copyright: t('vod_copyright', 'Copyright'),
        uptime: t('uptime', 'Update time'), uptag: t('uptag', 'Make tags from the title')
    };
    var wideFields = ['vod_name', 'vod_sub', 'vod_remarks', 'vod_blurb', 'vod_content', 'vod_tag', 'vod_actor', 'vod_class', 'vod_pic_screenshot', 'vod_rel_vod', 'vod_rel_art'];
    var originalControls = Array.prototype.slice.call(legacy.querySelectorAll('input,select,textarea'));
    if (!form.querySelector('[name="vod_name"]') || !form.querySelector('[name="type_id"]') || !form.querySelector('#player_list') || !form.querySelector('#downer_list')) { return; }

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text) { node.textContent = text; }
        return node;
    }
    function groupFor(name) {
        for (var key in groups) {
            if (Object.prototype.hasOwnProperty.call(groups, key) && groups[key].indexOf(name) !== -1) { return key; }
        }
        return 'advanced';
    }
    function fieldName(node) {
        var field = node.querySelector('input[name],select[name],textarea[name]');
        return field ? field.name : '';
    }
    function labelField(cell) {
        var label = cell.querySelector('.layui-form-label');
        var control = cell.querySelector('input[name],select[name],textarea[name]');
        if (!label || !control) { return; }
        if (!control.id) { control.id = 'studio-field-' + control.name.replace(/[^a-z0-9_-]/ig, '-'); }
        label.htmlFor = control.id;
        // V16: labels without a trailing colon.
        if (!label.children.length) { label.textContent = label.textContent.replace(/\s*[:：]\s*$/, ''); }
        if (!control.getAttribute('aria-label') && !control.getAttribute('aria-labelledby')) {
            // Layui renders a proxy for selects; the native control still needs a name.
            control.setAttribute('aria-label', label.textContent.replace(/:\s*$/, '').trim());
        }
    }
    var workspace = make('div', 'studio-vod-workspace');
    var nav = make('div', 'studio-vod-sections');
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', t('sections', 'Video editor sections'));
    var body = make('div', 'studio-vod-panels');
    var panels = {}, buttons = {}, grids = {};
    sections.forEach(function (section) {
        var key = section[0];
        var button = make('button', 'studio-vod-section-link', section[1]);
        button.type = 'button';
        button.id = 'studio-vod-tab-' + key;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', 'studio-vod-panel-' + key);
        button.dataset.vodSection = key;
        nav.appendChild(button);
        buttons[key] = button;
        var panel = make('section', 'studio-vod-panel');
        panel.id = 'studio-vod-panel-' + key;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', button.id);
        panel.tabIndex = 0;
        var heading = make('div', 'studio-vod-panel-heading');
        heading.appendChild(make('h2', '', section[1]));
        heading.appendChild(make('p', '', section[2]));
        panel.appendChild(heading);
        var grid = make('div', 'studio-vod-field-grid');
        panel.appendChild(grid);
        panels[key] = panel;
        grids[key] = grid;
        body.appendChild(panel);
    });
    workspace.appendChild(nav);
    workspace.appendChild(body);
    form.insertBefore(workspace, legacy);

    function place(cell, name, group) {
        cell.classList.add('studio-vod-field');
        if (wideFields.indexOf(name) !== -1) { cell.classList.add('studio-vod-field-wide'); }
        cell.dataset.vodField = name || 'supplement';
        labelField(cell);
        grids[group || groupFor(name)].appendChild(cell);
    }
    // Move playback containers whole. Existing action handlers use parent().parent(),
    // delegated .contents listeners and array order, so their internal tree is sacred.
    var playbackLabelIndex = 0;
    function labelPlayback(list) {
        var names = { 'vod_play_from[]': t('play_from', 'Player'), 'vod_play_server[]': t('server', 'Server'), 'vod_play_note[]': t('note', 'Source note'), 'vod_play_url[]': t('play_url', 'Episodes and playback URLs'), 'vod_down_from[]': t('down_from', 'Download provider'), 'vod_down_server[]': t('server', 'Server'), 'vod_down_note[]': t('note', 'Source note'), 'vod_down_url[]': t('down_url', 'Download URLs') };
        Array.prototype.forEach.call(list.querySelectorAll('input[name],select[name],textarea[name]'), function (control) {
            if (!names[control.name] || control.parentElement.querySelector('.studio-vod-playback-label')) { return; }
            var label = make('label', 'studio-vod-playback-label', names[control.name]);
            if (!control.id) { control.id = 'studio-playback-field-' + (++playbackLabelIndex); }
            label.htmlFor = control.id;
            control.setAttribute('aria-label', names[control.name]);
            control.parentElement.insertBefore(label, control);
        });
    }
    ['player_list', 'downer_list'].forEach(function (id) {
        var list = form.querySelector('#' + id);
        var addRow = list.nextElementSibling;
        var group = make('div', 'studio-vod-playback-group');
        group.appendChild(make('h3', '', id === 'player_list' ? t('player_group', 'Player sources') : t('downer_group', 'Download sources')));
        group.appendChild(list);
        if (addRow && addRow.querySelector('.j-player-add,.j-downer-add')) { group.appendChild(addRow); }
        grids.playback.appendChild(group);
        labelPlayback(list);
        if (window.MutationObserver) {
            new MutationObserver(function () { labelPlayback(list); }).observe(list, { childList: true, subtree: true });
        }
    });

    Array.prototype.slice.call(legacy.querySelectorAll('.layui-tab-item > .layui-form-item')).forEach(function (row) {
        var name = fieldName(row);
        // Upload callbacks locate their input through the original two ancestors.
        // Screenshot button and gallery also stay as the exact original rows.
        if (row.querySelector('.layui-upload,.screenshot') || name === 'vod_pic_screenshot') {
            place(row, name, 'artwork');
            row.classList.add('studio-vod-field-wide', 'studio-vod-upload-row');
            return;
        }
        var children = Array.prototype.slice.call(row.children);
        var cell = null;
        var pendingLabel = null;
        children.forEach(function (child) {
            if (child.classList.contains('layui-form-label')) {
                pendingLabel = child;
                return;
            }
            var childName = fieldName(child);
            // Server-rendered pairs already own their original label and control.
            if (childName && child.classList.contains('studio-field-pair')) {
                child.classList.add('layui-form-item');
                place(child, childName);
                return;
            }
            if (childName) {
                cell = make('div', 'layui-form-item');
                var label = pendingLabel;
                if (!label || independentLabels[childName]) {
                    label = make('label', 'layui-form-label', independentLabels[childName] || childName.replace(/^vod_/, '').replace(/_/g, ' '));
                }
                cell.appendChild(label);
                pendingLabel = null;
                cell.appendChild(child);
                place(cell, childName);
            } else if (cell) {
                // Keep lookup buttons and .vod_*_label suggestions with their field.
                cell.appendChild(child);
            } else {
                // The screenshot upload button is handled above; retain any unknown UI.
                grids.advanced.appendChild(child);
            }
        });
        if (!children.length) { grids.advanced.appendChild(row); }
    });

    // Arrange the first screen around the publishing decision, irrespective of the
    // old template's Douban/parameters-first ordering.
    groups.overview.forEach(function (name) {
        var field = grids.overview.querySelector('[data-vod-field="' + name + '"]');
        if (field) { grids.overview.appendChild(field); }
    });
    // Beginner r3 P3-7: group the series fields at the end of Advanced, after a short heading + help line.
    var seriesFields = ['vod_isend', 'vod_total', 'vod_serial'];
    var seriesCells = seriesFields.map(function (name) { return grids.advanced.querySelector('[data-vod-field="' + name + '"]'); }).filter(Boolean);
    if (seriesCells.length) {
        var seriesNote = make('div', 'studio-vod-field studio-vod-field-wide studio-vod-series-note');
        seriesNote.dataset.vodField = 'series-note';
        seriesNote.appendChild(make('h3', '', t('series_group', 'Series')));
        var seriesHelp = make('p', '', t('series_help', 'Only for series. Imports fill these automatically.'));
        seriesHelp.id = 'studio-vod-series-help';
        seriesNote.appendChild(seriesHelp);
        grids.advanced.appendChild(seriesNote);
        seriesCells.forEach(function (cell) {
            grids.advanced.appendChild(cell);
            var control = cell.querySelector('input[name],select[name],textarea[name]');
            if (control) { control.setAttribute('aria-describedby', seriesHelp.id); }
        });
    }
    originalControls.forEach(function (control) {
        if (legacy.contains(control)) {
            // A future plugin field with an unfamiliar wrapper remains accessible.
            var row = control.closest('.layui-form-item') || control.parentElement;
            place(row, fieldName(row), 'advanced');
        }
    });
    legacy.hidden = true;
    legacy.setAttribute('aria-hidden', 'true');
    editor.classList.add('studio-vod-editor');

    function activate(key, moveFocus) {
        sections.forEach(function (section) {
            var active = section[0] === key;
            panels[section[0]].hidden = !active;
            buttons[section[0]].setAttribute('aria-selected', String(active));
            buttons[section[0]].tabIndex = active ? 0 : -1;
        });
        if (moveFocus) { buttons[key].focus(); }
    }
    nav.addEventListener('click', function (event) {
        var button = event.target.closest('[data-vod-section]');
        if (!button) { return; }
        activate(button.dataset.vodSection, false);
        workspace.scrollIntoView({ block: 'start', behavior: 'auto' });
    });
    nav.addEventListener('keydown', function (event) {
        var button = event.target.closest('[data-vod-section]');
        if (!button) { return; }
        var index = sections.map(function (section) { return section[0]; }).indexOf(button.dataset.vodSection);
        var next = index;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { next = (index + 1) % sections.length; }
        else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { next = (index + sections.length - 1) % sections.length; }
        else if (event.key === 'Home') { next = 0; }
        else if (event.key === 'End') { next = sections.length - 1; }
        else { return; }
        event.preventDefault();
        activate(sections[next][0], true);
    });
    // Reveal hidden sections before browser validation focuses an invalid control.
    form.addEventListener('invalid', function (event) {
        var panel = event.target.closest('.studio-vod-panel');
        if (panel) { activate(panel.id.replace('studio-vod-panel-', ''), false); }
    }, true);
    activate('overview', false);
}());
